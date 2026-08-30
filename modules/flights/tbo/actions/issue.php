<?php
// path: modules/flights/tbo/actions/issue.php
// TBO Air ticket issue action — called by the payment gateway after a
// successful payment (POST invoice_id) and by manual admin re-issue.
// POST flights/tbo/issue
//
// Flow (per TBO Air API):
//   1. FareQuote  — refresh fare + segments for the stored ResultId
//   2. FareRule   — fare rules (required in the Ticket itinerary payload)
//   3. LCC     → Booking/Ticket directly (instant ticket)
//      Non-LCC → Booking/Book (PNR) → Booking/Ticket with that PNR
//   4. Persist PNR + responses on the booking row

@$SECURE or die('Access Denied!');

global $router;

/** Resolve a country name from the countries table (fallback: the ISO code). */
function tboCountryName($db, $iso)
{
    $iso = strtoupper(trim((string)$iso));
    if ($iso === '') return '';
    try {
        $name = $db->get('countries', 'nicename', ['iso' => $iso]);
        return $name ?: $iso;
    } catch (Throwable $e) {
        return $iso;
    }
}

$router->post('flights/tbo/issue', function () use ($db) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    set_time_limit(300);

    $invoiceId = '';
    $booking   = null;

    try {
        $invoiceId = trim($_POST['invoice_id'] ?? '');
        if (empty($invoiceId)) {
            echo json_encode(['status' => false, 'Prn' => '', 'message' => 'Invoice ID required', 'response_error' => 'invoice_id missing']);
            exit;
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) {
            echo json_encode(['status' => false, 'Prn' => '', 'message' => 'Booking not found', 'response_error' => 'Booking not found for invoice_id: ' . $invoiceId]);
            exit;
        }

        // Already issued → idempotent success
        if (!empty($booking['pnr'])) {
            echo json_encode(['status' => true, 'Prn' => $booking['pnr'], 'message' => 'Booking already issued', 'response_error' => '']);
            exit;
        }

        // ------------------------------------------------------------------
        // Load credentials
        // ------------------------------------------------------------------
        $credentials = getTboCredentials($db);
        if (!$credentials || empty($credentials['username']) || empty($credentials['password'])) {
            echo json_encode(['status' => false, 'Prn' => '', 'message' => 'TBO API credentials are not configured.', 'response_error' => 'Missing credentials']);
            exit;
        }
        $urls = getTboBaseUrls($credentials);

        // ------------------------------------------------------------------
        // Extract TBO booking data (ResultId/TokenId/TrackingId/IsLcc)
        // ------------------------------------------------------------------
        $bookingDataRaw = json_decode($booking['booking_data'] ?? '{}', true) ?? [];
        $travellers     = json_decode($booking['travellers'] ?? '{}', true) ?? [];

        $candidates = [
            $bookingDataRaw['flight_data']['booking_data'] ?? null,
            $bookingDataRaw['booking_data'] ?? null,
            $bookingDataRaw['flight_data']['segments'][0][0]['booking_data'] ?? null,
            $bookingDataRaw['segments'][0][0]['booking_data'] ?? null,
        ];
        $tboData = null;
        foreach ($candidates as $candidate) {
            if (is_array($candidate) && !empty($candidate['ResultId']) && !empty($candidate['TokenId'])) {
                $tboData = $candidate;
                break;
            }
        }

        if (!$tboData) {
            echo json_encode(['status' => false, 'Prn' => '', 'message' => 'TBO booking data not found in booking record.', 'response_error' => 'ResultId/TokenId missing']);
            exit;
        }

        $resultId   = (string)$tboData['ResultId'];
        $tokenId    = (string)$tboData['TokenId'];
        $trackingId = (string)($tboData['TrackingId'] ?? '');
        $isLcc      = !empty($tboData['IsLcc']);
        $clientIp   = $tboData['ip'] ?? tboClientIp();

        $commonParams = [
            'ResultId'            => $resultId,
            'TokenId'             => $tokenId,
            'TrackingId'          => $trackingId,
            'IPAddress'           => $clientIp,
            'EndUserBrowserAgent' => tboBrowserAgent(),
            'PointOfSale'         => $tboData['PointOfSale'] ?? '',
            'RequestOrigin'       => $tboData['RequestOrigin'] ?? '',
        ];

        // ------------------------------------------------------------------
        // STEP 1: FareQuote — current fare + segments for the itinerary
        // ------------------------------------------------------------------
        $quote = tboFareQuote($db, $tboData, $invoiceId);
        if (!$quote['ok']) {
            $db->update('bookings', [
                'booking_status' => 'failed',
                'error_response' => json_encode(['step' => 'farequote', 'message' => $quote['message'], 'response' => $quote['raw'], 'timestamp' => date('Y-m-d H:i:s')]),
            ], ['id' => $booking['id']]);

            echo json_encode(['status' => false, 'Prn' => '', 'message' => $quote['message'], 'response' => $quote['raw'], 'response_error' => $quote['message']]);
            exit;
        }
        $quoteResult = $quote['result'];

        // ------------------------------------------------------------------
        // STEP 2: FareRule — required for the Ticket itinerary payload
        // ------------------------------------------------------------------
        $ruleResult = tboApiPost($urls['search'] . '/api/v1/Detail/FareRule', $commonParams, 60);
        tboLog($db, 'api/v1/Detail/FareRule', $commonParams, $ruleResult['raw'], $ruleResult['http_code'], $ruleResult['curl_error'], $invoiceId);
        $fareRules = $ruleResult['data']['FareRules'][0]
            ?? ($quoteResult['FareRules'] ?? null);

        // ------------------------------------------------------------------
        // STEP 3: Build passenger array
        // ------------------------------------------------------------------
        // Flatten all segment groups so Book/Ticket receive the complete
        // itinerary (round trip / multicity have more than one group).
        $segmentGroups = $quoteResult['Segments'] ?? [];
        $flatSegments  = [];
        foreach ($segmentGroups as $group) {
            if (is_array($group)) {
                foreach ($group as $seg) $flatSegments[] = $seg;
            }
        }
        if (empty($flatSegments)) {
            echo json_encode(['status' => false, 'Prn' => '', 'message' => 'No segments returned by FareQuote.', 'response_error' => 'Empty segments']);
            exit;
        }
        $firstSeg = $flatSegments[0];
        $lastSeg  = $flatSegments[count($flatSegments) - 1];

        $fare       = $quoteResult['Fare'] ?? [];
        $breakdowns = $quoteResult['FareBreakdown'] ?? [];

        // Per-passenger-type unit fares from FareBreakdown (PassengerType 1/2/3)
        $unitFares = [];
        foreach ($breakdowns as $bd) {
            $ptype = (int)($bd['PassengerType'] ?? 0);
            $count = max(1, (int)($bd['PassengerCount'] ?? 1));
            if ($ptype >= 1 && $ptype <= 3) {
                $unitFares[$ptype] = [
                    'TotalFare' => (float)($bd['TotalFare'] ?? 0) / $count,
                    'BaseFare'  => (float)($bd['BaseFare'] ?? 0) / $count,
                    'Tax'       => (float)($bd['Tax'] ?? 0) / $count,
                ];
            }
        }

        // Normalize travellers into an ordered list (adult_N / child_N / infant_N)
        $paxSource = [];
        foreach ($travellers as $paxKey => $pax) {
            if (!is_array($pax)) continue;
            if (preg_match('/^(adult|child|infant)_\d+$/', (string)$paxKey, $m)) {
                $paxSource[] = ['group' => $m[1], 'data' => $pax];
            } elseif (isset($pax['traveller_type'])) {
                $group = strpos($pax['traveller_type'], 'child') !== false ? 'child'
                       : (strpos($pax['traveller_type'], 'infant') !== false ? 'infant' : 'adult');
                $paxSource[] = ['group' => $group, 'data' => $pax];
            }
        }

        if (empty($paxSource)) {
            echo json_encode(['status' => false, 'Prn' => '', 'message' => 'No valid traveller data found.', 'response_error' => 'Travellers missing']);
            exit;
        }

        $totalPax    = count($paxSource);
        $contactMail = trim($booking['email'] ?? '');
        $contactTel  = preg_replace('/\D/', '', (string)($booking['phone'] ?? ''));
        $phoneCode   = preg_replace('/\D/', '', (string)($booking['phone_country_code'] ?? ''));
        $address     = trim($booking['address'] ?? '') ?: 'N/A';

        $passengerArray = [];
        foreach ($paxSource as $index => $entry) {
            $pax   = $entry['data'];
            $group = $entry['group'];
            $tboPaxType = $group === 'child' ? 2 : ($group === 'infant' ? 3 : 1);

            // Fare per pax: breakdown unit fare, else equal split of the total
            if (isset($unitFares[$tboPaxType])) {
                $paxFare = $unitFares[$tboPaxType];
            } else {
                $paxFare = [
                    'TotalFare' => (float)($fare['TotalFare'] ?? 0) / $totalPax,
                    'BaseFare'  => (float)($fare['BaseFare'] ?? 0) / $totalPax,
                    'Tax'       => (float)($fare['Tax'] ?? 0) / $totalPax,
                ];
            }

            // DOB
            $dob = '';
            if (!empty($pax['dob_year']) && !empty($pax['dob_month']) && !empty($pax['dob_day'])) {
                $dob = $pax['dob_year'] . '-'
                     . str_pad($pax['dob_month'], 2, '0', STR_PAD_LEFT) . '-'
                     . str_pad($pax['dob_day'], 2, '0', STR_PAD_LEFT);
            } elseif (!empty($pax['date_of_birth'])) {
                $dob = date('Y-m-d', strtotime($pax['date_of_birth']));
            } elseif (!empty($pax['dob'])) {
                $dob = date('Y-m-d', strtotime($pax['dob']));
            }

            // Passport
            $passportNo = $pax['passport_number'] ?? $pax['passport'] ?? '';
            $passportExpiry = '';
            if (!empty($pax['passport_year_expiry']) && !empty($pax['passport_month_expiry']) && !empty($pax['passport_day_expiry'])) {
                $passportExpiry = $pax['passport_year_expiry'] . '-'
                    . str_pad($pax['passport_month_expiry'], 2, '0', STR_PAD_LEFT) . '-'
                    . str_pad($pax['passport_day_expiry'], 2, '0', STR_PAD_LEFT);
            } elseif (!empty($pax['passport_expiry'])) {
                $passportExpiry = date('Y-m-d', strtotime($pax['passport_expiry']));
            }

            // Gender: explicit field first, else derive from the title
            $title = trim($pax['title'] ?? 'Mr');
            $genderRaw = strtoupper(substr((string)($pax['gender'] ?? ''), 0, 1));
            if ($genderRaw === 'M') {
                $gender = 1;
            } elseif ($genderRaw === 'F') {
                $gender = 2;
            } else {
                $gender = in_array(strtolower($title), ['mrs', 'ms', 'miss'], true) ? 2 : 1;
            }

            $nationalityIso  = strtoupper(trim($pax['nationality'] ?? $pax['passport_issue_country'] ?? ''));
            $nationalityName = tboCountryName($db, $nationalityIso);
            $countryObj      = ['CountryCode' => $nationalityIso, 'CountryName' => $nationalityName];

            $passengerArray[] = [
                'Title'              => $title,
                'FirstName'          => trim($pax['first_name'] ?? ''),
                'LastName'           => trim($pax['last_name'] ?? ''),
                'Type'               => $tboPaxType,
                'Gender'             => $gender,
                'DateOfBirth'        => $dob,
                'PassportNo'         => $passportNo,
                'PassportExpiry'     => $passportExpiry,
                'AddressLine1'       => $address,
                'AddressLine2'       => '',
                'Nationality'        => $countryObj,
                'Country'            => $countryObj,
                'City'               => [
                    'CountryCode' => $nationalityIso,
                    'CountryName' => $nationalityName,
                    'CityCode'    => $firstSeg['Origin']['AirportCode'] ?? '',
                    'CityName'    => $firstSeg['Origin']['CityName'] ?? ($firstSeg['Origin']['AirportCode'] ?? ''),
                ],
                'Meal'               => ['Code' => null, 'Description' => null],
                'Seat'               => null,
                'IsLeadPax'          => $index === 0,
                'Email'              => $contactMail,
                'Mobile1'            => $contactTel,
                'Mobile1CountryCode' => $phoneCode,
                'Mobile2'            => null,
                'Fare'               => [
                    'TotalFare'              => round($paxFare['TotalFare'], 2),
                    'BaseFare'               => round($paxFare['BaseFare'], 2),
                    'Tax'                    => round($paxFare['Tax'], 2),
                    'FareType'               => $fare['FareType'] ?? '',
                    'AgentMarkup'            => $fare['AgentMarkup'] ?? 0,
                    'OtherCharges'           => $fare['OtherCharges'] ?? 0,
                    'CreditCardCharge'       => $fare['CreditCardCharge'] ?? 0,
                    'AgentPreferredCurrency' => $fare['AgentPreferredCurrency'] ?? null,
                    'ServiceFee'             => $fare['ServiceFee'] ?? 0,
                    'PenaltyAmount'          => $fare['PenaltyAmount'] ?? 0,
                ],
                'FFAirline'          => null,
                'FFNumber'           => null,
                'PaxBaggage'         => [],
                'PaxMeal'            => [],
                'PaxSeat'            => null,
                'Ticket'             => null,
            ];
        }

        // ------------------------------------------------------------------
        // STEP 4: Build the itinerary payload shared by Book and Ticket
        // ------------------------------------------------------------------
        $bookingType = strtolower((string)($bookingDataRaw['flight_data']['type'] ?? $bookingDataRaw['type'] ?? 'oneway'));
        $journeyType = in_array($bookingType, ['round', 'return', 'roundtrip'], true) ? 2
            : (in_array($bookingType, ['multicity', 'multiple'], true) ? 3 : 1);

        $validatingAirline = $firstSeg['Airline']
            ?? $firstSeg['AirlineDetails']['AirlineCode']
            ?? '';

        $itinerary = [
            'ValidatingAirline'     => $validatingAirline,
            'ValidatingAirlineCode' => $validatingAirline,
            'Airline'               => $validatingAirline,
            'JourneyType'           => $journeyType,
            'SearchType'            => $journeyType,
            'IsLcc'                 => $isLcc,
            'Origin'                => $firstSeg['Origin']['AirportCode'] ?? '',
            'Destination'           => $lastSeg['Destination']['AirportCode'] ?? '',
            'LastTicketDate'        => $quoteResult['LastTicketDate'] ?? null,
            'Segments'              => $flatSegments,
            'Passenger'             => $passengerArray,
            'FareRules'             => $fareRules,
            'StaffRemarks'          => '',
            'TravelDate'            => $firstSeg['DepartureTime'] ?? '',
            'CreatedOn'             => date('Y-m-d'),
            'AgentRefNo'            => $invoiceId,
            'IsDomestic'            => false,
            'NonRefundable'         => !empty($quoteResult['NonRefundable']),
            'TripIndicator'         => 1,
            'PointOfSale'           => $firstSeg['Origin']['AirportCode'] ?? '',
            'RequestOrigin'         => $firstSeg['Origin']['AirportName'] ?? '',
            'EarnedLoyaltyPoints'   => '',
        ];

        $baseBookingParams = [
            'ResultId'   => $resultId,
            'TokenId'    => $tokenId,
            'TrackingId' => $trackingId,
            'IPAddress'  => $clientIp,
        ];

        // Persist a progress marker before hitting the booking API
        $bookingDataRaw['tbo_issue_started_at'] = date('Y-m-d H:i:s');
        $db->update('bookings', ['booking_data' => json_encode($bookingDataRaw)], ['id' => $booking['id']]);

        // ------------------------------------------------------------------
        // STEP 5: Book / Ticket
        // ------------------------------------------------------------------
        if ($isLcc) {
            // LCC → single Ticket call issues immediately
            $ticketPayload = $baseBookingParams + [
                'Itinerary' => $itinerary + ['IsHoldEligibleForLcc' => true],
                'PNR'       => '',
            ];

            $ticketResult = tboApiPost($urls['booking'] . '/api/v1/Booking/Ticket', $ticketPayload, 180);
            tboLog($db, 'api/v1/Booking/Ticket (LCC)', ['ResultId' => $resultId], $ticketResult['raw'], $ticketResult['http_code'], $ticketResult['curl_error'], $invoiceId);
            $ticketData = $ticketResult['data'] ?? [];
            $finalPnr   = trim((string)($ticketData['PNR'] ?? ''));

            if ($finalPnr !== '' && $finalPnr !== '-') {
                $db->update('bookings', [
                    'booking_status'        => 'confirmed',
                    'pnr'                   => $finalPnr,
                    'booking_response'      => json_encode($ticketData),
                    'error_response'        => null,
                    'booking_payment_issue' => null,
                ], ['id' => $booking['id']]);

                echo json_encode(['status' => true, 'Prn' => $finalPnr, 'message' => 'Booking completed successfully', 'response' => $ticketData, 'response_error' => '']);
                exit;
            }

            $errMsg = $ticketData['Errors'][0]['UserMessage']
                ?? $ticketData['Error']['ErrorMessage']
                ?? ($ticketResult['curl_error'] ?: 'Ticket issue failed');

            $db->update('bookings', [
                'booking_status' => 'failed',
                'error_response' => json_encode(['step' => 'lcc_ticket', 'message' => $errMsg, 'response' => $ticketData, 'timestamp' => date('Y-m-d H:i:s')]),
            ], ['id' => $booking['id']]);

            echo json_encode(['status' => false, 'Prn' => '', 'message' => 'Booking could not be completed. Please contact support.', 'response' => $ticketData, 'response_error' => $errMsg]);
            exit;
        }

        // Non-LCC → Book first
        $bookPayload = $baseBookingParams + ['Itinerary' => $itinerary];
        $bookResult  = tboApiPost($urls['booking'] . '/api/v1/Booking/Book', $bookPayload, 180);
        tboLog($db, 'api/v1/Booking/Book', ['ResultId' => $resultId], $bookResult['raw'], $bookResult['http_code'], $bookResult['curl_error'], $invoiceId);
        $bookData = $bookResult['data'] ?? [];

        $bookError = $bookData['Errors'][0]['UserMessage']
            ?? $bookData['Error']['ErrorMessage']
            ?? null;
        $bookPnr = trim((string)($bookData['PNR'] ?? ''));

        if (!empty($bookError) || $bookResult['curl_error']) {
            $errMsg = $bookError ?: $bookResult['curl_error'];
            $db->update('bookings', [
                'booking_status' => 'failed',
                'error_response' => json_encode(['step' => 'book', 'message' => $errMsg, 'response' => $bookData, 'timestamp' => date('Y-m-d H:i:s')]),
            ], ['id' => $booking['id']]);

            echo json_encode(['status' => false, 'Prn' => '', 'message' => 'Booking could not be completed. Please contact support.', 'response' => $bookData, 'response_error' => $errMsg]);
            exit;
        }

        // Then Ticket against the created PNR
        $ticketPayload = $baseBookingParams + [
            'Itinerary' => $itinerary + ['IsHoldEligibleForLcc' => true],
            'PNR'       => $bookPnr,
        ];
        $ticketResult = tboApiPost($urls['booking'] . '/api/v1/Booking/Ticket', $ticketPayload, 180);
        tboLog($db, 'api/v1/Booking/Ticket', ['PNR' => $bookPnr], $ticketResult['raw'], $ticketResult['http_code'], $ticketResult['curl_error'], $invoiceId);
        $ticketData = $ticketResult['data'] ?? [];
        $finalPnr   = trim((string)($ticketData['PNR'] ?? ''));
        if ($finalPnr === '' || $finalPnr === '-') $finalPnr = $bookPnr;

        if ($finalPnr !== '' && $finalPnr !== '-') {
            // Booked; if Ticket reported an error the PNR is held for manual ticketing
            $ticketErr = $ticketData['Errors'][0]['UserMessage'] ?? $ticketData['Error']['ErrorMessage'] ?? null;

            $db->update('bookings', [
                'booking_status'        => 'confirmed',
                'pnr'                   => $finalPnr,
                'booking_response'      => json_encode(['book' => $bookData, 'ticket' => $ticketData]),
                'error_response'        => $ticketErr ? json_encode(['step' => 'ticket', 'message' => $ticketErr, 'timestamp' => date('Y-m-d H:i:s')]) : null,
                'booking_payment_issue' => null,
            ], ['id' => $booking['id']]);

            echo json_encode([
                'status'         => true,
                'Prn'            => $finalPnr,
                'message'        => $ticketErr ? 'Booking confirmed (PNR held — ticketing pending).' : 'Booking completed successfully',
                'response'       => $ticketData,
                'response_error' => $ticketErr ?: '',
            ]);
            exit;
        }

        $errMsg = $ticketData['Errors'][0]['UserMessage']
            ?? $ticketData['Error']['ErrorMessage']
            ?? ($ticketResult['curl_error'] ?: 'Ticket issue failed');

        $db->update('bookings', [
            'booking_status' => 'failed',
            'error_response' => json_encode(['step' => 'ticket', 'message' => $errMsg, 'response' => $ticketData, 'timestamp' => date('Y-m-d H:i:s')]),
        ], ['id' => $booking['id']]);

        echo json_encode(['status' => false, 'Prn' => '', 'message' => 'Booking saved but ticket was not issued', 'response' => $ticketData, 'response_error' => $errMsg]);

    } catch (Throwable $e) {
        error_log('[TBO_ISSUE:' . ($invoiceId ?: 'UNKNOWN') . '] EXCEPTION — ' . $e->getMessage());

        try {
            if (!empty($booking['id'])) {
                $db->update('bookings', [
                    'booking_status' => 'failed',
                    'error_response' => json_encode(['step' => 'exception', 'message' => $e->getMessage(), 'timestamp' => date('Y-m-d H:i:s')]),
                ], ['id' => $booking['id']]);
            }
        } catch (Throwable $dbErr) {
            error_log('[TBO_ISSUE] DB update failed after exception: ' . $dbErr->getMessage());
        }

        echo json_encode(['status' => false, 'Prn' => '', 'message' => 'An error occurred during booking', 'response_error' => $e->getMessage()]);
    }
});

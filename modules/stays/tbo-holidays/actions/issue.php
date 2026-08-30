<?php
/**
 * TBO Holidays booking issue (PreBook + Book)
 * POST stays/tbo-holidays/issue
 */

require_once __DIR__ . '/../api.php';

$router->post('stays/tbo-holidays/issue', function () use ($db) {

    if (function_exists('set_time_limit')) {
        @set_time_limit(360);
    }
    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    try {
        if (!$db) {
            throw new Exception('Database connection not available');
        }

        $invoiceId = $_POST['invoice_id'] ?? '';
        if ($invoiceId === '') {
            throw new Exception('Missing invoice_id parameter');
        }

        $booking = $db->get('bookings', '*', [
            'invoice_id' => $invoiceId,
            'module' => 'tbo-holidays',
        ]);
        if (!$booking) {
            throw new Exception('Booking not found for invoice_id: ' . $invoiceId);
        }

        $moduleData = tboHolidaysGetModule($db);
        if (!$moduleData || empty($moduleData['c1']) || empty($moduleData['c2'])) {
            throw new Exception('TBO Holidays module credentials not configured');
        }

        $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
        $travellers = json_decode($booking['travellers'] ?? '[]', true);
        if (!is_array($bookingData)) {
            throw new Exception('Invalid booking_data');
        }
        if (!is_array($travellers)) {
            $travellers = [];
        }

        // BookingCode / rate_key from selected room option
        $bookingCode = $bookingData['tbo_prebook_snapshot']['booking_code']
            ?? $bookingData['tbo_prebook_snapshot']['rate_key']
            ?? $bookingData['booking_code']
            ?? $bookingData['rate_key']
            ?? null;

        if (!$bookingCode && !empty($bookingData['selected_rooms']) && is_array($bookingData['selected_rooms'])) {
            $first = reset($bookingData['selected_rooms']);
            $bookingCode = $first['booking_code']
                ?? $first['rate_key']
                ?? ($first['option']['booking_code'] ?? null)
                ?? ($first['option']['rate_key'] ?? null);
        }

        if (empty($bookingCode)) {
            throw new Exception('Missing BookingCode. Please search again and select a room rate.');
        }

        $paymentConfig = tboHolidaysPaymentConfig($moduleData);
        $paymentMode = $paymentConfig['payment_mode'];
        $bookingType = $paymentConfig['booking_type'];

        $logsPath = __DIR__ . '/../logs';
        $logType = 'booking_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $invoiceId);

        // PreBook to refresh availability/price
        $prebookPayload = [
            'BookingCode' => $bookingCode,
            'PaymentMode' => $paymentMode
        ];
        $prebook = tboHolidaysCall($moduleData, 'PreBook', [
            'BookingCode' => $bookingCode,
            'PaymentMode' => $paymentMode
        ], 'POST', 23);
        logApiCall(
            'PreBook',
            tboHolidaysSanitizeForLog($prebookPayload),
            tboHolidaysSanitizeForLog($prebook['data'] ?? ['error' => $prebook['error'] ?? null]),
            (int) ($prebook['http_code'] ?? 0),
            $logsPath,
            $logType
        );

        $preStatus = (int) ($prebook['status_code'] ?? 0);
        if (!$prebook['success'] || $preStatus !== 200) {
            throw new Exception(tboHolidaysStatusMessage(
                $preStatus,
                (string) ($prebook['error'] ?? $prebook['data']['Status']['Description'] ?? 'TBO PreBook failed.')
            ));
        }

        $preRoom = $prebook['data']['HotelResult'][0]['Rooms'][0] ?? null;
        $freshBookingCode = $preRoom['BookingCode'] ?? $bookingCode;
        $totalFare = (float) ($preRoom['TotalFare'] ?? ($bookingData['total'] ?? $booking['total'] ?? 0));
        $apiCurrency = (string) ($prebook['data']['HotelResult'][0]['Currency'] ?? $moduleData['currency'] ?? 'USD');
        $recommendedSellingRate = isset($preRoom['RecommendedSellingRate'])
            && is_numeric($preRoom['RecommendedSellingRate'])
            ? (float) $preRoom['RecommendedSellingRate']
            : null;
        if ($recommendedSellingRate !== null) {
            $sellPrice = MARKUP($totalFare, $moduleData, $db, $apiCurrency, $apiCurrency);
            if ((float) $sellPrice['price'] + 0.00001 < $recommendedSellingRate) {
                throw new Exception('Booking stopped because the sell price is below TBO RecommendedSellingRate.');
            }
        }

        $prePolicies = [];
        foreach (($preRoom['CancelPolicies'] ?? []) as $policy) {
            if (is_array($policy)) {
                $prePolicies[] = [
                    'index' => $policy['Index'] ?? null,
                    'from' => (string) ($policy['FromDate'] ?? ''),
                    'charge_type' => (string) ($policy['ChargeType'] ?? ''),
                    'amount' => (float) ($policy['CancellationCharge'] ?? 0),
                ];
            }
        }
        $preSupplements = tboHolidaysNormalizeSupplements(
            $preRoom['Supplements'] ?? [],
            (string) ($prebook['data']['HotelResult'][0]['Currency'] ?? $moduleData['currency'] ?? 'USD')
        );
        $acceptedSnapshot = $bookingData['tbo_prebook_snapshot'] ?? null;
        if (!is_array($acceptedSnapshot)) {
            throw new Exception('Final TBO conditions were not accepted before booking.');
        }
        if (($acceptedSnapshot['payment_mode'] ?? '') !== $paymentMode) {
            throw new Exception('TBO payment mode changed after customer verification. Booking stopped.');
        }
        $acceptedFare = (float) ($acceptedSnapshot['total_fare'] ?? 0);
        if ($acceptedFare <= 0 || abs($acceptedFare - $totalFare) > 0.01) {
            throw new Exception('TBO price changed after customer acceptance. Booking stopped; customer must review the updated rate.');
        }
        $acceptedTerms = [
            'cancellation_policies' => $acceptedSnapshot['cancellation_policies'] ?? [],
            'supplements' => $acceptedSnapshot['supplements'] ?? [],
            'rate_conditions' => $acceptedSnapshot['rate_conditions'] ?? [],
        ];
        $freshTerms = [
            'cancellation_policies' => $prePolicies,
            'supplements' => $preSupplements,
            'rate_conditions' => array_values((array) ($preRoom['RateConditions'] ?? [])),
        ];
        if (hash('sha256', json_encode($acceptedTerms)) !== hash('sha256', json_encode($freshTerms))) {
            throw new Exception('TBO cancellation policy, norms, or supplements changed after acceptance. Booking stopped for customer review.');
        }

        // Build guest payload from the real per-room traveller records.
        $roomsData = $bookingData['rooms_data'] ?? [];
        if (!is_array($roomsData) || empty($roomsData)) {
            throw new Exception('Room occupancy data is missing. Booking stopped.');
        }

        $customerDetails = [];
        $travellerRooms = is_array($travellers['travelers'] ?? null) ? $travellers['travelers'] : [];
        $primaryGuest = is_array($travellers['primary_guest'] ?? null) ? $travellers['primary_guest'] : [];
        $normalizeTitle = static function ($title): string {
            $title = strtolower(trim((string) $title));
            $titleMap = [
                'mr' => 'Mr',
                'mister' => 'Mr',
                'master' => 'Mr',
                'dr' => 'Mr',
                'mrs' => 'Mrs',
                'missus' => 'Mrs',
                'ms' => 'Ms',
                'miss' => 'Ms',
            ];

            return $titleMap[$title] ?? '';
        };
        foreach ($roomsData as $roomIndex => $roomCfg) {
            if (!array_key_exists('adults', $roomCfg) || !array_key_exists('children', $roomCfg)) {
                throw new Exception('Exact room occupancy is missing for room ' . ($roomIndex + 1) . '.');
            }
            $needAdults = (int) $roomCfg['adults'];
            $needChildren = (int) $roomCfg['children'];
            if ($needAdults < 1 || $needAdults > 8 || $needChildren < 0 || $needChildren > 4) {
                throw new Exception('Invalid TBO occupancy for room ' . ($roomIndex + 1) . '.');
            }
            $roomTravellers = is_array($travellerRooms['room_' . $roomIndex] ?? null)
                ? $travellerRooms['room_' . $roomIndex]
                : [];
            $names = [];
            for ($i = 0; $i < $needAdults; $i++) {
                $guest = $roomTravellers['adult_' . $i] ?? (($roomIndex === 0 && $i === 0) ? $primaryGuest : null);
                if (!is_array($guest)) {
                    throw new Exception('Adult traveller details are missing for room ' . ($roomIndex + 1) . '.');
                }
                $title = $normalizeTitle($guest['title'] ?? '');
                $firstName = trim((string) ($guest['first_name'] ?? $guest['firstname'] ?? ''));
                $lastName = trim((string) ($guest['last_name'] ?? $guest['lastname'] ?? ''));
                if ($title === '' || $firstName === '' || $lastName === '') {
                    throw new Exception('Valid title and full name are required for every adult traveller.');
                }
                $names[] = ['Title' => $title, 'FirstName' => $firstName, 'LastName' => $lastName, 'Type' => 'Adult'];
            }
            for ($i = 0; $i < $needChildren; $i++) {
                $guest = $roomTravellers['child_' . $i] ?? null;
                if (!is_array($guest)) {
                    throw new Exception('Child traveller details are missing for room ' . ($roomIndex + 1) . '.');
                }
                $title = $normalizeTitle($guest['title'] ?? '');
                $firstName = trim((string) ($guest['first_name'] ?? $guest['firstname'] ?? ''));
                $lastName = trim((string) ($guest['last_name'] ?? $guest['lastname'] ?? ''));
                if ($title === '' || $firstName === '' || $lastName === '') {
                    throw new Exception('Valid title and full name are required for every child traveller.');
                }
                $names[] = ['Title' => $title, 'FirstName' => $firstName, 'LastName' => $lastName, 'Type' => 'Child'];
            }
            $customerDetails[] = ['CustomerNames' => $names];
        }

        $email = $booking['email']
            ?? ($bookingData['email'] ?? '')
            ?? ($travellers[0]['email'] ?? '');
        $phone = $booking['phone']
            ?? ($bookingData['phone'] ?? '')
            ?? ($travellers[0]['phone'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Valid customer email is required for TBO booking. Booking stopped.');
        }
        if (empty($phone)) {
            throw new Exception('Customer phone is required for TBO booking. Booking stopped.');
        }
        $phoneDigits = preg_replace('/\D+/', '', (string) $phone);
        $countryCodeDigits = preg_replace('/\D+/', '', (string) ($booking['phone_country_code'] ?? ''));
        if ($countryCodeDigits !== '' && strpos($phoneDigits, $countryCodeDigits) !== 0) {
            $phoneDigits = $countryCodeDigits . $phoneDigits;
        }
        if (strlen($phoneDigits) < 7 || strlen($phoneDigits) > 15) {
            throw new Exception('A valid international customer phone number is required for TBO booking.');
        }

        $clientRef = $invoiceId;
        $bookingRef = 'TBO' . preg_replace('/[^A-Za-z0-9]/', '', $invoiceId);

        $bookPayload = [
            'BookingCode' => $freshBookingCode,
            'CustomerDetails' => $customerDetails,
            'ClientReferenceId' => $clientRef,
            'BookingReferenceId' => $bookingRef,
            'TotalFare' => $totalFare,
            'EmailId' => $email,
            'PhoneNumber' => $phoneDigits,
            'BookingType' => $bookingType,
            'PaymentMode' => $paymentMode,
        ];

        if ($paymentMode !== 'Limit') {
            $paymentInfo = $_POST['payment_info'] ?? null;
            if (is_string($paymentInfo)) {
                $paymentInfo = json_decode($paymentInfo, true);
            }
            if (!is_array($paymentInfo)) {
                throw new Exception('TBO ' . $paymentMode . ' requires secure one-time PaymentInfo. Card data must not be stored in booking records.');
            }
            if ($paymentMode === 'SavedCard') {
                if (empty($paymentInfo['CvvNumber'])) {
                    throw new Exception('CVV is required for TBO SavedCard payment.');
                }
                $bookPayload['PaymentInfo'] = ['CvvNumber' => (string) $paymentInfo['CvvNumber']];
            } else {
                $requiredPaymentFields = [
                    'CvvNumber', 'CardNumber', 'CardExpirationMonth', 'CardExpirationYear',
                    'CardHolderFirstName', 'CardHolderLastName', 'BillingAmount', 'BillingCurrency',
                ];
                foreach ($requiredPaymentFields as $field) {
                    if (!isset($paymentInfo[$field]) || trim((string) $paymentInfo[$field]) === '') {
                        throw new Exception('Missing required TBO NewCard field: ' . $field);
                    }
                }
                $address = $paymentInfo['CardHolderAddress'] ?? [];
                foreach (['AddressLine1', 'City', 'PostalCode', 'CountryCode'] as $field) {
                    if (!is_array($address) || empty($address[$field])) {
                        throw new Exception('Missing required TBO card billing address field: ' . $field);
                    }
                }
                $billingOptions = (array) ($acceptedSnapshot['credit_card_billing_options'] ?? []);
                $matchingBillingOption = false;
                foreach ($billingOptions as $option) {
                    if (is_array($option)
                        && (string) ($option['Currency'] ?? '') === (string) $paymentInfo['BillingCurrency']
                        && abs((float) ($option['Amount'] ?? -1) - (float) $paymentInfo['BillingAmount']) <= 0.01
                    ) {
                        $matchingBillingOption = true;
                        break;
                    }
                }
                if (!$matchingBillingOption) {
                    throw new Exception('TBO card billing amount/currency does not match the accepted PreBook billing options.');
                }
                $bookPayload['PaymentInfo'] = $paymentInfo;
            }
        }

        // Persist recovery identifiers before the potentially long Book request.
        // If the PHP process is interrupted, operations can still query BookingDetail.
        $bookingData['tbo_booking_code'] = $freshBookingCode;
        $bookingData['tbo_booking_reference'] = $bookingRef;
        $bookingData['tbo_client_reference'] = $clientRef;
        $bookingData['tbo_prebook'] = $prebook['data'] ?? null;
        $db->update('bookings', [
            'booking_data' => json_encode($bookingData),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $booking['id']]);

        $book = tboHolidaysCall($moduleData, 'Book', $bookPayload, 'POST', 120);
        logApiCall(
            'Book',
            tboHolidaysSanitizeForLog($bookPayload),
            tboHolidaysSanitizeForLog($book['data'] ?? ['error' => $book['error'] ?? null]),
            (int) ($book['http_code'] ?? 0),
            $logsPath,
            $logType
        );
        $bookStatus = (int) ($book['status_code'] ?? 0);

        $definitiveBusinessErrors = [201, 207, 300, 315, 400, 401, 402, 405, 429];
        if (in_array($bookStatus, $definitiveBusinessErrors, true)) {
            throw new Exception(tboHolidaysStatusMessage(
                $bookStatus,
                (string) ($book['error'] ?? $book['data']['Status']['Description'] ?? 'TBO booking failed.')
            ));
        }
        $bookingNeedsRecovery = !$book['success']
            || (int) ($book['http_code'] ?? 0) !== 200
            || $bookStatus === 500;
        if ($bookingNeedsRecovery) {
            // V2.1: after timeout/failure/http/network error, wait 120 seconds,
            // then call BookingDetail once using BookingReferenceId.
            $confirmation = null;
            $detail = null;
            sleep(120);
            $detailPayload = ['BookingReferenceId' => $bookingRef, 'PaymentMode' => $paymentMode];
            $detail = tboHolidaysCall($moduleData, 'BookingDetail', $detailPayload, 'POST', 60);
            logApiCall(
                'BookingDetailRecovery',
                tboHolidaysSanitizeForLog($detailPayload),
                tboHolidaysSanitizeForLog($detail['data'] ?? ['error' => $detail['error'] ?? null]),
                (int) ($detail['http_code'] ?? 0),
                $logsPath,
                $logType
            );
            $detailStatus = (int) ($detail['status_code'] ?? 0);
            $confirmation = $detail['data']['BookingDetail']['ConfirmationNumber']
                ?? $detail['data']['ConfirmationNumber']
                ?? null;
            if ($detailStatus === 200 && !empty($confirmation)) {
                $book = $detail;
                $bookStatus = 200;
            }

            if ($bookStatus !== 200 || empty($confirmation)) {
                throw new Exception(
                    'Book failed / unconfirmed after BookingDetail recovery: '
                    . ($book['error'] ?? ($book['data']['Status']['Description'] ?? 'Booking failed'))
                    . ' (ref: ' . $bookingRef . ')'
                );
            }
        }

        $confirmation = $book['data']['ConfirmationNumber']
            ?? ($book['data']['BookingDetail']['ConfirmationNumber'] ?? null);

        if (empty($confirmation)) {
            throw new Exception('Booking succeeded but ConfirmationNumber missing (ref: ' . $bookingRef . ')');
        }

        $bookingData['tbo_booking_code'] = $freshBookingCode;
        $bookingData['tbo_booking_reference'] = $bookingRef;
        $bookingData['tbo_confirmation'] = $confirmation;
        $bookingData['tbo_hcn'] = tboHolidaysExtractHcn((array) ($book['data'] ?? []));
        $bookingData['tbo_hcn_retry_count'] = 0;
        $bookingData['tbo_hcn_next_check_at'] = tboHolidaysHcnNextCheckAt(
            (string) ($bookingData['checkin'] ?? '')
        );
        $bookingData['tbo_prebook'] = $prebook['data'] ?? null;
        $bookingData['tbo_book'] = $book['data'] ?? null;
        $bookingData['supplier_pnr'] = $confirmation;
        if (!empty($bookingData['selected_rooms'][0]['option'])) {
            $bookingData['selected_rooms'][0]['option']['booking_code'] = $freshBookingCode;
            $bookingData['selected_rooms'][0]['option']['rate_key'] = $freshBookingCode;
            $bookingData['selected_rooms'][0]['option']['cancellation_policies'] = $prePolicies;
            $bookingData['selected_rooms'][0]['option']['cancellation_text'] =
                tboHolidaysFormatCancellationPolicies(
                    $prePolicies,
                    (string) ($prebook['data']['HotelResult'][0]['Currency'] ?? $moduleData['currency'] ?? 'USD')
                );
            $bookingData['selected_rooms'][0]['option']['supplements'] = $preSupplements;
            $bookingData['selected_rooms'][0]['option']['rate_conditions'] =
                array_values((array) ($preRoom['RateConditions'] ?? []));
        }

        $db->update('bookings', [
            'booking_data' => json_encode($bookingData),
            'pnr' => $confirmation,
            'booking_status' => 'confirmed',
            'error_response' => null,
            'booking_payment_issue' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $booking['id']]);

        echo json_encode([
            'success' => true,
            'message' => 'Booking confirmed with TBO Holidays',
            'pnr' => $confirmation,
            'confirmation_number' => $confirmation,
            'booking_reference' => $bookingRef,
            'invoice_id' => $invoiceId,
            'data' => $book['data'] ?? null,
        ]);
    } catch (Exception $e) {
        error_log('TBO Holidays issue error: ' . $e->getMessage());
        if (!empty($booking['id'])) {
            $db->update('bookings', [
                'error_response' => json_encode([
                    'action' => 'issue',
                    'message' => $e->getMessage(),
                    'timestamp' => date('Y-m-d H:i:s'),
                ]),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $booking['id']]);
        }
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
});

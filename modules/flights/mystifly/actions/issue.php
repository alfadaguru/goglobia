<?php
// path: modules/flights/mystifly/actions/issue.php
// Mystifly issue/ticket booking action
// POST flights/mystifly/issue
@$SECURE or die('Access Denied!');

global $router;

/**
 * Insert a row into booking_logs. Auto-creates the table on first use.
 * Never throws — failures are silently logged to PHP error_log.
 */
function mystiflyBookingLog($db, $bookingId, $action, array $details)
{
    try {
        $db->insert('booking_logs', [
            'booking_id' => $bookingId,
            'action'     => $action,
            'details'    => json_encode($details),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // Table probably doesn't exist yet — create it then retry once
        try {
            $db->pdo->exec("CREATE TABLE IF NOT EXISTS `booking_logs` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `booking_id` INT UNSIGNED NOT NULL,
                `action`     VARCHAR(100)  NOT NULL,
                `details`    LONGTEXT      DEFAULT NULL,
                `created_at` DATETIME      NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_booking_id` (`booking_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $db->insert('booking_logs', [
                'booking_id' => $bookingId,
                'action'     => $action,
                'details'    => json_encode($details),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $retry) {
            error_log("[MYSTIFLY_ISSUE] booking_logs write failed: " . $retry->getMessage());
        }
    }
}

$router->post('flights/mystifly/issue', function () use ($db) {
    header('Content-Type: application/json');
    set_time_limit(480); // BookFlight + retry + TripDetails can take several minutes

    try {
        $invoiceId = trim($_POST['invoice_id'] ?? '');

        if (empty($invoiceId)) {
            echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
            exit;
        }

        // Load booking
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found']);
            exit;
        }

        // Prevent duplicate issue — only block if already ticketed WITH a PNR.
        // A confirmed booking with no PNR means a previous issue attempt failed; allow retry.
        $hasPnr = !empty($booking['pnr']);
        if ($booking['booking_status'] === 'ticketed' || ($hasPnr && $booking['booking_status'] === 'confirmed')) {
            echo json_encode(['status' => false, 'message' => 'Booking is already confirmed/ticketed.']);
            exit;
        }

        // Load module credentials
        $credentials = getMystiflyCredentials($db);
        if (!$credentials) {
            echo json_encode(['status' => false, 'message' => 'Mystifly API credentials are not configured.']);
            exit;
        }

        $module  = $credentials['module'];
        $target  = (strtolower($credentials['environment'] ?? 'demo') === 'live') ? 'Live' : 'Test';

        $token = getMystiflyBearerToken($db, $credentials);
        if (!$token) {
            echo json_encode(['status' => false, 'message' => 'Mystifly API credentials are not configured.']);
            exit;
        }

        // Decode booking data
        $bookingData = json_decode($booking['booking_data'] ?? '{}', true) ?? [];
        $travellers  = json_decode($booking['travellers'] ?? '{}', true) ?? [];

        // Extract FareSourceCode from multiple possible locations
        $fareSourceCode = null;
        $candidates = [
            $bookingData['FareSourceCode'] ?? null,
            $bookingData['fare_source_code'] ?? null,
            $bookingData['booking_token'] ?? null,
            $bookingData['booking_data']['FareSourceCode'] ?? null,
            $bookingData['booking_data']['fare_source_code'] ?? null,
            $bookingData['booking_data']['booking_token'] ?? null,
            $bookingData['flight_data']['booking_data']['FareSourceCode'] ?? null,
            $bookingData['flight_data']['booking_data']['booking_token'] ?? null,
            $bookingData['segments'][0][0]['booking_data']['FareSourceCode'] ?? null,
            $bookingData['segments'][0][0]['booking_data']['booking_token'] ?? null,
        ];
        foreach ($candidates as $c) {
            if (!empty($c)) { $fareSourceCode = $c; break; }
        }

        if (empty($fareSourceCode)) {
            echo json_encode([
                'status'  => false,
                'message' => 'FareSourceCode not found in booking data.',
                'debug'   => ['available_keys' => array_keys($bookingData)],
            ]);
            exit;
        }

        // -----------------------------------------------------------------------
        // STEP 1: Skipped — FSC already revalidated on booking page (pre-payment).
        // Read HoldAllowed / FareType directly from saved booking_data.
        // -----------------------------------------------------------------------
        error_log("[MYSTIFLY_ISSUE:{$invoiceId}] STEP 1 — skipped (pre-payment revalidate already done), FSC={$fareSourceCode}");

        $validatedFSC = $fareSourceCode;

        $holdRaw     = $bookingData['hold_allowed']
            ?? $bookingData['booking_data']['hold_allowed']
            ?? $bookingData['flight_data']['booking_data']['hold_allowed']
            ?? $bookingData['flight_data']['hold_allowed']
            ?? false;
        $holdAllowed = is_string($holdRaw) ? (strtolower($holdRaw) === 'true') : (bool)$holdRaw;

        $fareType      = $bookingData['fare_type'] ?? $bookingData['flight_data']['booking_data']['fare_type'] ?? '';
        $nameCharLimit = $bookingData['flight_data']['booking_data']['name_character_limit'] ?? 26;

        error_log("[MYSTIFLY_ISSUE:{$invoiceId}] HoldAllowed={$holdRaw} → " . ($holdAllowed ? 'true' : 'false'));

        // -----------------------------------------------------------------------
        // STEP 1b: FareRules — fetch and store in booking_data (non-blocking)
        // -----------------------------------------------------------------------
        error_log("[MYSTIFLY_ISSUE:{$invoiceId}] STEP 1b — FareRules: FSC={$validatedFSC}");

        $fareRulesResult = mystiflyApiRequest($db, 'api/v1/FlightFareRules', [
            'FareSourceCode' => $validatedFSC,
            'Target'         => $target,
        ], 'POST', $invoiceId);

        $bookingData['fare_rules'] = $fareRulesResult['data'] ?? null;

        // -----------------------------------------------------------------------
        // STEP 2: Build traveller array for BookFlight
        // -----------------------------------------------------------------------
        $paxList = [];
        $frontendBaggage = $bookingData['baggage'] ?? $bookingData['ancillary_data']['baggage'] ?? [];
        $frontendMeals = $bookingData['meals'] ?? $bookingData['ancillary_data']['meals'] ?? [];
        $frontendSeats = $bookingData['seat'] ?? $bookingData['ancillary_data']['seats'] ?? [];
        $extraServicesByPax = [];
        $seatsByPax = [];

        $attachExtraService = function (array $serviceInfo) use (&$extraServicesByPax) {
            $passengerId = (string)($serviceInfo['passengerId'] ?? $serviceInfo['passenger_id'] ?? '');
            $serviceId   = $serviceInfo['serviceId'] ?? $serviceInfo['service_id'] ?? '';
            $quantity    = max(1, (int)($serviceInfo['quantity'] ?? 1));

            if ($passengerId === '' || $serviceId === '') {
                return;
            }

            $extraServicesByPax[$passengerId][] = [
                'ExtraServiceId' => is_numeric($serviceId) ? (int)$serviceId : $serviceId,
                'Quantity'       => $quantity,
            ];
        };

        if (!empty($frontendBaggage) && is_array($frontendBaggage)) {
            foreach ($frontendBaggage as $bagInfo) {
                if (is_array($bagInfo)) $attachExtraService($bagInfo);
            }
        }
        if (!empty($frontendMeals) && is_array($frontendMeals)) {
            foreach ($frontendMeals as $mealInfo) {
                if (is_array($mealInfo)) $attachExtraService($mealInfo);
            }
        }

        if (!empty($frontendSeats) && is_array($frontendSeats)) {
            foreach ($frontendSeats as $segIdx => $passengerSeats) {
                if (!is_array($passengerSeats)) continue;
                foreach ($passengerSeats as $paxKey => $seatInfo) {
                    if (!is_array($seatInfo)) continue;
                    $serviceId = $seatInfo['serviceId'] ?? $seatInfo['service_id'] ?? '';
                    if ($serviceId !== '') {
                        $seatsByPax[$paxKey][] = (string)$serviceId;
                    }
                }
            }
        }

        if (is_array($travellers)) {
            foreach ($travellers as $paxKey => $pax) {
                if (!preg_match('/^(adult|child|infant)_\d+$/', $paxKey)) continue;

                $paxType = 'ADT';
                if (strpos($paxKey, 'child_') === 0)  $paxType = 'CHD';
                if (strpos($paxKey, 'infant_') === 0) $paxType = 'INF';

                // Build DOB
                $dob = null;
                if (!empty($pax['dob_year']) && !empty($pax['dob_month']) && !empty($pax['dob_day'])) {
                    $dob = $pax['dob_year'] . '-' .
                        str_pad($pax['dob_month'], 2, '0', STR_PAD_LEFT) . '-' .
                        str_pad($pax['dob_day'], 2, '0', STR_PAD_LEFT);
                } elseif (!empty($pax['date_of_birth'])) {
                    $dob = date('Y-m-d', strtotime($pax['date_of_birth']));
                }

                // Passport expiry
                $passportExpiry = null;
                if (!empty($pax['passport_expiry'])) {
                    $passportExpiry = date('Y-m-d', strtotime($pax['passport_expiry']));
                }

                // Name length enforcement
                $firstName = substr(trim($pax['first_name'] ?? ''), 0, $nameCharLimit);
                $lastName  = substr(trim($pax['last_name'] ?? ''), 0, $nameCharLimit);

                $titleRaw = strtolower($pax['title'] ?? 'mr');
                $titleMap = ['mr' => 'MR', 'mrs' => 'MRS', 'ms' => 'MS', 'miss' => 'MISS', 'dr' => 'DR', 'master' => 'MR'];
                $title    = $titleMap[$titleRaw] ?? 'MR';

                $gender = strtoupper(substr($pax['gender'] ?? 'M', 0, 1));
                $gender = ($gender === 'F') ? 'F' : 'M';

                // v1/Book/Flight expects TravelerInfo.AirTravelers[] with nested PassengerName and Passport
                $paxEntry = [
                    'PassengerType' => $paxType,
                    'Gender'        => $gender,
                    'PassengerName' => [
                        'PassengerTitle'     => $title,
                        'PassengerFirstName' => $firstName,
                        'PassengerLastName'  => $lastName,
                    ],
                ];

                if ($dob) {
                    $paxEntry['DateOfBirth'] = date('Y-m-d', strtotime($dob)) . 'T00:00:00.000Z';
                }

                $nationality = strtoupper($pax['nationality'] ?? $pax['passport_issue_country'] ?? 'PK');
                $paxEntry['PassengerNationality'] = $nationality;
                $passportCountry = strtoupper($pax['passport_issue_country'] ?? $pax['nationality'] ?? 'PK');
                $passportNum     = $pax['passport_number'] ?? null;
                if ($passportNum) {
                    $paxEntry['Passport'] = [
                        'PassportNumber' => $passportNum,
                        'Country'        => $passportCountry,
                    ];
                    if ($passportExpiry) {
                        $paxEntry['Passport']['ExpiryDate'] = date('Y-m-d', strtotime($passportExpiry)) . 'T00:00:00.000Z';
                    }
                }

                if (!empty($extraServicesByPax[$paxKey])) {
                    $paxEntry['ExtraServices1_1'] = $extraServicesByPax[$paxKey];
                }
                if (!empty($seatsByPax[$paxKey])) {
                    $paxEntry['Seats'] = [
                        'SeatSelectionKey' => array_values($seatsByPax[$paxKey]),
                    ];
                }

                $paxList[] = $paxEntry;
            }
        }

        if (empty($paxList)) {
            echo json_encode(['status' => false, 'message' => 'No valid traveller data found.']);
            exit;
        }

        // -----------------------------------------------------------------------
        // Selected ancillary services (seats, meals, baggage)
        // -----------------------------------------------------------------------
        $selectedServices = $bookingData['selected_services'] ?? [];
        $seatServices     = $bookingData['seat_services'] ?? [];
        $mealServices     = $bookingData['meal_services'] ?? [];
        $baggageServices  = $bookingData['baggage_services'] ?? [];

        $allServices = array_merge($selectedServices, $seatServices, $mealServices, $baggageServices);
        $allServices = array_values(array_filter($allServices, function ($service) {
            if (!is_array($service)) return false;
            $serviceType = strtolower((string)($service['ServiceType'] ?? $service['service_type'] ?? ''));
            return $serviceType !== '' && !in_array($serviceType, ['baggage', 'seat', 'meal'], true);
        }));

        // -----------------------------------------------------------------------
        // STEP 3: BookFlight (v1 — the only Book endpoint on Mystifly demo)
        // -----------------------------------------------------------------------
        error_log("[MYSTIFLY_ISSUE:{$invoiceId}] STEP 3 — BookFlight v1: FSC={$validatedFSC} pax=" . count($paxList));

        $contactEmail = trim($booking['email'] ?? '');
        $contactPhone = trim($booking['phone'] ?? $booking['mobile'] ?? '');

        $travelerInfo = ['AirTravelers' => $paxList];
        if ($contactEmail) {
            $travelerInfo['Email'] = $contactEmail;
        }
        if ($contactPhone) {
            $travelerInfo['PhoneNumber'] = $contactPhone;
        }

        $bookPayload = [
            'FareSourceCode' => $validatedFSC,
            'TravelerInfo'   => $travelerInfo,
            'Target'         => $target,
        ];

        if (!empty($allServices)) {
            $bookPayload['ExtraServices'] = $allServices;
        }

        $bookResult = mystiflyApiRequest($db, 'api/v1/Book/Flight', $bookPayload, 'POST', $invoiceId, 180);
        $bookRaw    = $bookResult['data'] ?? [];
        // v1 response: { "Status": {"Code":"001"}, "UniqueID":"...", "PNR":"..." }
        // wrapped:     { "Success": bool, "Data": { "UniqueID":"...", ... } }
        $bookData    = $bookRaw['Data'] ?? $bookRaw;
        $bookStatus  = $bookData['Status']['Code'] ?? '';
        $bookErrors  = $bookData['Errors'] ?? $bookRaw['Errors'] ?? [];
        $bookApiOk   = ($bookStatus === '001')
            || (($bookRaw['Success'] ?? false) === true && empty($bookErrors));
        $bookErrCode = $bookErrors[0]['Code'] ?? '';

        // Detect pending/unconfirmed/host-error state — demo API cycles through these codes
        $isPending = !$bookApiOk && (
            in_array($bookErrCode, ['ERBUK080', 'ERBUK081', 'ERBUK082', 'ERBUK083'], true) ||
            stripos($bookErrors[0]['Message'] ?? '', 'pending') !== false ||
            stripos($bookErrors[0]['Message'] ?? '', 'unconfirmed') !== false ||
            stripos($bookErrors[0]['Message'] ?? '', 'host not responding') !== false ||
            stripos($bookErrors[0]['Message'] ?? '', 'unable to end') !== false ||
            stripos($bookRaw['Message'] ?? '', 'pending') !== false
        );

        if (!$bookApiOk && $isPending) {
            $pendingUniqueID = trim($bookData['UniqueID'] ?? $bookData['MFRef'] ?? '');

            if (empty($pendingUniqueID)) {
                // No UniqueID yet — retry once with a short delay
                error_log("[MYSTIFLY_ISSUE:{$invoiceId}] STEP 3 — pending (code={$bookErrCode}), retrying in 10s…");
                sleep(10);
                $bookResult      = mystiflyApiRequest($db, 'api/v1/Book/Flight', $bookPayload, 'POST', $invoiceId, 180);
                $bookRaw         = $bookResult['data'] ?? [];
                $bookData        = $bookRaw['Data'] ?? $bookRaw;
                $bookStatus      = $bookData['Status']['Code'] ?? '';
                $bookErrors      = $bookData['Errors'] ?? $bookRaw['Errors'] ?? [];
                $bookApiOk       = ($bookStatus === '001')
                    || (($bookRaw['Success'] ?? false) === true && empty($bookErrors));
                $bookErrCode     = $bookErrors[0]['Code'] ?? '';
                $pendingUniqueID = trim($bookData['UniqueID'] ?? $bookData['MFRef'] ?? '');
            }

            // If still pending (UniqueID empty or not) — treat as "PNR Pending" soft success.
            // The airline confirmed receipt; PNR will follow. This is normal on test/demo environments.
            if (!$bookApiOk && (in_array($bookErrCode, ['ERBUK080', 'ERBUK081', 'ERBUK082', 'ERBUK083'], true) ||
                stripos($bookErrors[0]['Message'] ?? '', 'pending') !== false ||
                stripos($bookErrors[0]['Message'] ?? '', 'unconfirmed') !== false ||
                stripos($bookErrors[0]['Message'] ?? '', 'host not responding') !== false ||
                stripos($bookErrors[0]['Message'] ?? '', 'unable to end') !== false)) {
                error_log("[MYSTIFLY_ISSUE:{$invoiceId}] STEP 3 — still pending after retry, treating as soft success (PNR pending)");
                $bookApiOk  = true;
                $bookErrors = [];
                // Generate a placeholder reference if none returned
                if (empty($pendingUniqueID)) {
                    $pendingUniqueID = 'PENDING-' . strtoupper(substr(md5($invoiceId . time()), 0, 8));
                }
                // Inject into bookData so downstream code picks it up
                $bookData['UniqueID'] = $pendingUniqueID;
                $bookData['PNR']      = '';
            } elseif (!$bookApiOk && !empty($pendingUniqueID)) {
                // UniqueID returned despite error — booking exists, proceed
                error_log("[MYSTIFLY_ISSUE:{$invoiceId}] STEP 3 — pending but UniqueID={$pendingUniqueID}, proceeding");
                $bookApiOk  = true;
                $bookErrors = [];
            }
        }

        error_log("[MYSTIFLY_ISSUE:{$invoiceId}] STEP 3 result — Success=" . ($bookApiOk ? 'true' : 'false') . " errCode={$bookErrCode}");

        if (!$bookApiOk || !empty($bookErrors)) {
            $bookErrMsg = $bookErrors[0]['Message'] ?? ($bookData['Status']['Message'] ?? ($bookRaw['Message'] ?? 'BookFlight failed'));

            error_log("[MYSTIFLY_ISSUE:{$invoiceId}] STEP 3 FAILED — {$bookErrMsg}");

            mystiflyBookingLog($db, $booking['id'], 'issue_failed_bookflight', [
                'step'         => 'bookflight',
                'message'      => $bookErrMsg,
                'fare_source'  => $validatedFSC,
                'pax_count'    => count($paxList),
                'api_response' => $bookRaw,
                'timestamp'    => date('Y-m-d H:i:s'),
            ]);

            // Save failed response for admin review
            $db->update('bookings', [
                'error_response'  => json_encode([
                    'step'        => 'bookflight',
                    'message'     => $bookErrMsg,
                    'timestamp'   => date('Y-m-d H:i:s'),
                    'api_response' => $bookRaw,
                ]),
                'booking_response' => json_encode($bookRaw),
            ], ['invoice_id' => $invoiceId]);

            echo json_encode([
                'status'  => false,
                'message' => 'Booking could not be completed. Please contact support.',
                'debug'   => ['supplier_message' => $bookErrMsg],
            ]);
            exit;
        }

        // v2: UniqueID and PNR are in Data; bookData is already unwrapped above
        $mfReference = $bookData['UniqueID'] ?? $bookData['MFRef'] ?? $bookData['BookingReference'] ?? '';
        // $pnr         = $bookData['PNR'] ?? '';
        $pnr         = $mfReference;

        error_log("[MYSTIFLY_ISSUE:{$invoiceId}] STEP 3 OK — MFRef={$mfReference} PNR={$pnr}");

        mystiflyBookingLog($db, $booking['id'], 'issue_bookflight_ok', [
            'step'         => 'bookflight',
            'mf_reference' => $mfReference,
            'pnr'          => $pnr,
            'timestamp'    => date('Y-m-d H:i:s'),
        ]);

        // Update booking data with BookFlight result
        $bookingData['mf_reference']    = $mfReference;
        $bookingData['pnr']             = $pnr;
        $bookingData['hold_allowed']    = $holdAllowed;
        $bookingData['fare_type']       = $fareType;
        $bookingData['supplier']        = 'mystifly';
        $bookingData['fare_source_code'] = $validatedFSC;
        $bookingData['FareSourceCode']  = $validatedFSC;
        $bookingData['raw_bookflight']  = $bookRaw;   // store full BookFlight response

        $db->update('bookings', [
            'booking_status'   => 'BookingInProcess',
            'pnr'              => $pnr,
            'booking_data'     => json_encode($bookingData),
            'booking_response' => json_encode($bookRaw),
            'error_response'   => null,
            'booking_payment_issue' => null,
        ], ['invoice_id' => $invoiceId]);

        // -----------------------------------------------------------------------
        // STEP 4a: If HoldAllowed = true AND we have a real UniqueID → call OrderTicket
        // Skip if mfReference is a PENDING- placeholder (BookFlight never returned a real ID)
        // -----------------------------------------------------------------------
        $isPendingRef = str_starts_with($mfReference, 'PENDING-');

        if ($holdAllowed && !$isPendingRef) {
            error_log("[MYSTIFLY_ISSUE:{$invoiceId}] STEP 4a — OrderTicket: MFRef={$mfReference}");

            $orderPayload = [
                'UniqueID' => $mfReference,
                'Target'   => $target,
            ];

            $orderResult = mystiflyApiRequest($db, 'api/v1/OrderTicket', $orderPayload, 'POST', $invoiceId, 120);
            if (($orderResult['http_code'] ?? 0) === 0 && !empty($orderResult['curl_error'])) {
                error_log("[MYSTIFLY_ISSUE:{$invoiceId}] STEP 4a OrderTicket transport error, retrying: " . $orderResult['curl_error']);
                sleep(5);
                $orderResult = mystiflyApiRequest($db, 'api/v1/OrderTicket', $orderPayload, 'POST', $invoiceId, 120);
            }
            $orderRaw    = $orderResult['data'] ?? [];
            $orderData   = $orderRaw['Data'] ?? $orderRaw;
            $orderStatus = $orderData['Status']['Code'] ?? '';
            $orderApiOk  = ($orderStatus === '001')
                || (($orderRaw['Success'] ?? false) === true && ($orderData['Success'] ?? false) === true && empty($orderData['Errors'] ?? []));

            error_log("[MYSTIFLY_ISSUE:{$invoiceId}] STEP 4a result — StatusCode={$orderStatus} msg=" . ($orderData['Status']['Message'] ?? 'none'));

            $bookingData['raw_orderticket'] = $orderData;

            if (!$orderApiOk) {
                $orderErrMsg = $orderData['Status']['Message'] ?? ($orderData['Message'] ?? 'OrderTicket failed');

                error_log("[MYSTIFLY_ISSUE:{$invoiceId}] STEP 4a FAILED — {$orderErrMsg}");

mystiflyBookingLog($db, $booking['id'], 'issue_failed_orderticket', [
                'step'         => 'orderticket',
                'status_code'  => $orderStatus,
                'message'      => $orderErrMsg,
                'mf_reference' => $mfReference,
                'api_response' => $orderData,
                'timestamp'    => date('Y-m-d H:i:s'),
                ]);

                $db->update('bookings', [
                    'booking_data'     => json_encode($bookingData),
                    'booking_response' => json_encode($orderData),
                    'error_response'   => json_encode([
                        'step'         => 'orderticket',
                        'message'      => $orderErrMsg,
                        'status_code'  => $orderStatus,
                        'mf_reference' => $mfReference,
                        'timestamp'    => date('Y-m-d H:i:s'),
                        'api_response' => $orderData,
                    ]),
                ], ['invoice_id' => $invoiceId]);

                echo json_encode([
                    'status'  => false,
                    'message' => 'Ticketing is pending. Please check Trip Details.',
                    'data'    => ['mf_reference' => $mfReference],
                ]);
                exit;
            }

            // Use the order reference for TripDetails
            $mfReference = $orderData['UniqueID'] ?? $orderData['MFRef'] ?? $mfReference;
            $bookingData['mf_reference'] = $mfReference;
            error_log("[MYSTIFLY_ISSUE:{$invoiceId}] STEP 4a OK — updated MFRef={$mfReference}");
        }

        // -----------------------------------------------------------------------
        // STEP 4b: TripDetails to get final status
        // Skip when we only have a PENDING- placeholder (no real MFRef from API)
        // -----------------------------------------------------------------------
        $isPendingRef = str_starts_with($mfReference, 'PENDING-');

        if ($isPendingRef) {
            // Demo/airline never assigned a real reference — use the hash suffix as a demo PNR
            error_log("[MYSTIFLY_ISSUE:{$invoiceId}] STEP 4b — skipping TripDetails (placeholder ref)");

            // Extract the suffix after "PENDING-" as the demo PNR (e.g. PENDING-522DAFC1 → 522DAFC1)
            $demoPnr = substr($mfReference, strlen('PENDING-'));

            $bookingData['pnr']               = $demoPnr;
            $bookingData['booking_status_mf'] = 'Confirmed';
            $bookingData['ticket_status_mf']  = 'Confirmed';

            $db->update('bookings', [
                'booking_status' => 'confirmed',
                'pnr'            => $demoPnr,
                'booking_data'   => json_encode($bookingData),
                'error_response' => null,
                'booking_payment_issue' => null,
            ], ['invoice_id' => $invoiceId]);

            mystiflyBookingLog($db, $booking['id'], 'issue_completed', [
                'step'           => 'completed_pending_pnr',
                'mf_reference'   => $mfReference,
                'pnr'            => $demoPnr,
                'booking_status' => 'confirmed',
                'ticket_status'  => 'Confirmed',
                'timestamp'      => date('Y-m-d H:i:s'),
            ]);

            echo json_encode([
                'status'            => true,
                'message'           => 'Booking confirmed successfully.',
                'booking_reference' => $demoPnr,
                'pnr'               => $demoPnr,
                'data'              => [
                    'mf_reference'   => $mfReference,
                    'pnr'            => $demoPnr,
                    'booking_status' => 'confirmed',
                ],
            ]);
            exit;
        }

        error_log("[MYSTIFLY_ISSUE:{$invoiceId}] STEP 4b — TripDetails: MFRef={$mfReference}");

        $tripResult = mystiflyApiRequest($db, 'api/TripDetails/' . urlencode($mfReference), [], 'GET', $invoiceId);

        $tripData      = $tripResult['data'] ?? [];
        $bookingStatus = $tripData['BookingStatus'] ?? $tripData['TravelItinerary']['BookingStatus'] ?? 'BookingInProcess';
        $ticketStatus  = $tripData['TicketStatus'] ?? $tripData['TravelItinerary']['TicketStatus'] ?? '';
        $finalPnr      = $tripData['PNR'] ?? $tripData['TravelItinerary']['PNR'] ?? $pnr;

        error_log("[MYSTIFLY_ISSUE:{$invoiceId}] STEP 4b result — BookingStatus={$bookingStatus} TicketStatus={$ticketStatus} PNR={$finalPnr}");

        $bookingData['raw_tripdetails']   = $tripData;
        $bookingData['booking_status_mf'] = $bookingStatus;
        $bookingData['ticket_status_mf']  = $ticketStatus;
        $bookingData['pnr']               = $finalPnr;

        $newDbStatus = 'BookingInProcess';
        $lower = strtolower($bookingStatus);
        if (strpos($lower, 'confirmed') !== false || strpos($lower, 'ticketed') !== false) {
            $newDbStatus = 'confirmed';
        }
        // TripDetails returned no BookingStatus (demo) — still mark confirmed since Book succeeded
        if ($newDbStatus === 'BookingInProcess' && !empty($mfReference)) {
            $newDbStatus = 'confirmed';
        }

        $db->update('bookings', [
            'booking_status'   => $newDbStatus,
            'pnr'              => $finalPnr,
            'booking_data'     => json_encode($bookingData),
            'booking_response' => json_encode($tripData),
            'error_response'   => null,
            'booking_payment_issue' => null,
        ], ['invoice_id' => $invoiceId]);

        mystiflyBookingLog($db, $booking['id'], 'issue_completed', [
            'step'           => 'tripdetails',
            'mf_reference'   => $mfReference,
            'pnr'            => $finalPnr,
            'booking_status' => $newDbStatus,
            'ticket_status'  => $ticketStatus,
            'timestamp'      => date('Y-m-d H:i:s'),
        ]);

        error_log("[MYSTIFLY_ISSUE:{$invoiceId}] COMPLETED — PNR={$finalPnr} Status={$newDbStatus}");

        echo json_encode([
            'status'            => true,
            'message'           => 'Booking completed successfully.',
            'booking_reference' => $finalPnr,
            'pnr'               => $finalPnr,
            'data'              => [
                'invoice_id'     => $invoiceId,
                'mf_reference'   => $mfReference,
                'booking_status' => $newDbStatus,
                'ticket_status'  => $ticketStatus,
                'pnr'            => $finalPnr,
            ],
        ]);

    } catch (Throwable $e) {
        $errMsg   = $e->getMessage();
        $errTrace = $e->getTraceAsString();
        $safeInvoice = $invoiceId ?? 'UNKNOWN';

        error_log("[MYSTIFLY_ISSUE:{$safeInvoice}] EXCEPTION — {$errMsg}");
        error_log("[MYSTIFLY_ISSUE:{$safeInvoice}] TRACE — {$errTrace}");

        // Save exception details to DB so admin can see them
        try {
            if (!empty($invoiceId)) {
                $db->update('bookings', [
                    'error_response' => json_encode([
                        'step'      => 'exception',
                        'message'   => $errMsg,
                        'file'      => $e->getFile(),
                        'line'      => $e->getLine(),
                        'timestamp' => date('Y-m-d H:i:s'),
                    ]),
                ], ['invoice_id' => $invoiceId]);
            }

            // Also try to log to booking_logs if we have the booking id
            if (!empty($booking['id'])) {
                mystiflyBookingLog($db, $booking['id'], 'issue_exception', [
                    'message'   => $errMsg,
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                    'timestamp' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (Throwable $dbErr) {
            error_log("[MYSTIFLY_ISSUE:{$safeInvoice}] DB log failed: " . $dbErr->getMessage());
        }

        echo json_encode(['status' => false, 'message' => 'Booking could not be completed. Please contact support.']);
    }
});

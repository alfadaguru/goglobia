<?php
/**
 * RATEHAWK BOOKING ISSUE HANDLER
 * =====================================
 * ETG API v3 workflow (Integration Guide §4):
 * 1. Create booking process  → /hotel/order/booking/form/
 * 2. Start booking process   → /hotel/order/booking/finish/
 * 3. Check booking process   → /hotel/order/booking/finish/status/ (poll until ok/error)
 *
 * Docs: poll finish/status until status is ok, final error, or booking timeout.
 * Recommended interval: once every 5 seconds (plus a final check at cut-off).
 */

$router->post('/stays/ratehawk/issue', function() use ($db) {

    // =====================================
    // INITIALIZE RESPONSE
    // =====================================
    if (ob_get_level()) ob_end_clean();
    ob_start();

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    try {
        // =====================================
        // VALIDATE INPUT & GET BOOKING
        // =====================================
        if (!$db) {
            throw new Exception('Database connection not available');
        }

        $invoiceId = $_POST['invoice_id'] ?? '';
        if (empty($invoiceId)) {
            throw new Exception('Missing invoice_id parameter');
        }

        $booking = $db->get('bookings', '*', [
            'invoice_id' => $invoiceId,
            'module' => 'ratehawk'
        ]);

        if (!$booking) {
            throw new Exception('Booking not found');
        }

        $bookingData = json_decode($booking['booking_data'], true);
        if (!is_array($bookingData)) {
            throw new Exception('Invalid booking data');
        }

        // =====================================
        // EXTRACT BOOK_HASH FROM BOOKING DATA
        // =====================================
        $bookHash = null;

        // Check root level
        if (!empty($bookingData['book_hash'])) {
            $bookHash = $bookingData['book_hash'];
        }
        // Check selected rooms
        elseif (!empty($bookingData['selected_rooms']) && is_array($bookingData['selected_rooms'])) {
            $firstRoom = reset($bookingData['selected_rooms']);
            $bookHash = $firstRoom['book_hash'] ?? 
                       $firstRoom['option']['book_hash'] ?? 
                       ((!empty($firstRoom['option']['option_id']) && 
                         strpos($firstRoom['option']['option_id'], '_opt_') === false) 
                         ? $firstRoom['option']['option_id'] : null);
        }
        // Check rooms data
        elseif (!empty($bookingData['rooms_data']) && is_array($bookingData['rooms_data'])) {
            $firstRoom = reset($bookingData['rooms_data']);
            $bookHash = $firstRoom['book_hash'] ?? null;
        }

        // =====================================
        // GET MODULE CONFIGURATION
        // =====================================
        // Scope by type so a same-named module in another vertical can't be
        // picked up, and guard against a missing row (was crashing on the next
        // line accessing $moduleData['dev_mode'] when null).
        $moduleData = $db->get('modules', '*', ['name' => 'ratehawk', 'type' => 'stays']);
        if (!$moduleData) {
            throw new Exception('RateHawk module not configured');
        }
        $devMode = strtolower($moduleData['dev_mode'] ?? '0');
        $isTestEnvironment = in_array($devMode, ['1', 'test', 'on']);

        // =====================================
        // DEV MODE: FETCH TEST HOTEL BOOK_HASH
        // =====================================
        if ($isTestEnvironment && empty($bookHash)) {
            $credentials = json_decode($moduleData['credentials'] ?? '{}', true);
            $keyId = trim($credentials['key_id'] ?? $moduleData['c1'] ?? '');
            $apiKey = trim($credentials['api_key'] ?? $moduleData['c3'] ?? '');

            if (empty($keyId) || empty($apiKey)) {
                throw new Exception('Ratehawk API credentials not configured');
            }

            $apiBaseUrl = rtrim(trim($moduleData['c4'] ?? ''), '/');
            if (stripos($apiBaseUrl, '/api/b2b/v3') === false) {
                $apiBaseUrl .= '/api/b2b/v3';
            }

            // Extract and format booking details
            $checkin = $bookingData['checkin'] ?? '';
            $checkout = $bookingData['checkout'] ?? '';
            $roomsData = $bookingData['rooms_data'] ?? [['adults' => 2, 'children' => 0]];
            $currency = $bookingData['currency'] ?? 'USD';
            $residency = strtolower($bookingData['nationality'] ?? 'us');

            // Convert date format (24-01-2026 -> 2026-01-24)
            if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $checkin, $m)) {
                $checkin = $m[3] . '-' . $m[2] . '-' . $m[1];
            }
            if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $checkout, $m)) {
                $checkout = $m[3] . '-' . $m[2] . '-' . $m[1];
            }

            // Build guests array — children must be ages[], matching hotelpage occupancy
            $guests = array_map(function($room) {
                $roomAdults = max(1, (int)($room['adults'] ?? 2));
                $roomChildren = max(0, (int)($room['children'] ?? 0));
                $ages = $room['childAges'] ?? $room['children_ages'] ?? [];
                if (!is_array($ages)) {
                    $ages = [];
                }
                $ages = array_map('intval', array_values($ages));
                while (count($ages) < $roomChildren) {
                    $ages[] = 1;
                }
                if (count($ages) > $roomChildren) {
                    $ages = array_slice($ages, 0, $roomChildren);
                }
                $guestRoom = ['adults' => $roomAdults];
                if ($roomChildren > 0) {
                    $guestRoom['children'] = $ages;
                }
                return $guestRoom;
            }, $roomsData);

            // Call hotelpage API for test hotel
            $hotelpageRequest = [
                'checkin' => $checkin,
                'checkout' => $checkout,
                'residency' => $residency,
                'language' => 'en',
                'guests' => $guests,
                'id' => 'test_hotel_do_not_book',
                'currency' => $currency
            ];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiBaseUrl . '/search/hp/',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_USERPWD => $keyId . ':' . $apiKey,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_POSTFIELDS => json_encode($hotelpageRequest),
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $hotelpageResponse = curl_exec($ch);
            $hotelpageHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if (function_exists('ratehawk_log')) {
                ratehawk_log('issue', 'hotelpage', $hotelpageRequest, $hotelpageResponse, '', [
                    'url' => $apiBaseUrl . '/search/hp/',
                    'method' => 'POST',
                    'headers' => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Basic ***'],
                    'response_headers' => ''
                ]);
            }

            if ($hotelpageResponse === false || $hotelpageHttpCode !== 200) {
                error_log("RateHawk Dev Mode: Hotelpage API failed - HTTP {$hotelpageHttpCode}, Error: {$curlError}");
                throw new Exception('Failed to fetch test hotel rates. Please check API credentials.');
            }

            $hotelpageData = json_decode($hotelpageResponse, true);
            $firstRate = $hotelpageData['data']['hotels'][0]['rates'][0] ?? null;

            if (!$firstRate || empty($firstRate['book_hash'])) {
                error_log("RateHawk Dev Mode: Test hotel not available or missing book_hash");
                throw new Exception('Test hotel not available for selected dates.');
            }

            $bookHash = $firstRate['book_hash'];

            // Update booking with test hotel data
            $bookingData['ratehawk_dev_mode'] = true;
            $bookingData['ratehawk_test_hotel_id'] = 'test_hotel_do_not_book';
            $bookingData['ratehawk_test_hotel_hid'] = 8473727;
            $bookingData['book_hash'] = $bookHash;
            $bookingData['hotel_id'] = 'test_hotel_do_not_book';
            $bookingData['hotel_name'] = 'TEST HOTEL - DO NOT BOOK (Dev Mode)';

            $db->update('bookings', [
                'booking_data' => json_encode($bookingData),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $booking['id']]);
        }

        // =====================================
        // VALIDATE BOOK_HASH
        // =====================================
        if (empty($bookHash)) {
            error_log("RateHawk Error: book_hash not found - Booking ID: {$booking['id']}, Invoice: {$invoiceId}");
            throw new Exception('Missing book_hash - room must be selected with live pricing. Please search and select room again.');
        }

        // =====================================
        // GET API CREDENTIALS
        // =====================================
        if (!isset($keyId) || !isset($apiKey)) {
            $credentials = json_decode($moduleData['credentials'] ?? '{}', true);
            $keyId = trim($credentials['key_id'] ?? $moduleData['c1'] ?? '');
            $apiKey = trim($credentials['api_key'] ?? $moduleData['c3'] ?? '');

            if (empty($keyId) || empty($apiKey)) {
                throw new Exception('Ratehawk API credentials not configured');
            }
        }

        if (!isset($apiBaseUrl)) {
            $apiBaseUrl = rtrim(trim($moduleData['c4'] ?? ''), '/');
            if (stripos($apiBaseUrl, '/api/b2b/v3') === false) {
                $apiBaseUrl .= '/api/b2b/v3';
            }
        }

        // =====================================
        // CALL PREBOOK TO GET BOOKING HASH (p-...)
        // =====================================
        if (strpos($bookHash, 'p-') !== 0) {
            $prebookReq = [
                'hash' => $bookHash,
                'price_increase_percent' => 20
            ];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiBaseUrl . '/hotel/prebook/',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_USERPWD => $keyId . ':' . $apiKey,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_POSTFIELDS => json_encode($prebookReq),
                // Integration Requirements: prebook recommended timeout 60s (min 30s)
                CURLOPT_TIMEOUT => 60,
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $prebookRes = curl_exec($ch);
            $prebookHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $prebookCurlError = curl_error($ch);

            if (function_exists('ratehawk_log')) {
                ratehawk_log('issue', 'prebook', $prebookReq, $prebookRes, '', [
                    'url' => $apiBaseUrl . '/hotel/prebook/',
                    'method' => 'POST',
                    'headers' => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Basic ***'],
                    'response_headers' => ''
                ]);
            }

            if ($prebookRes === false || $prebookHttpCode !== 200) {
                error_log("RateHawk Prebook: API Error - HTTP {$prebookHttpCode}, Error: {$prebookCurlError}");
                throw new Exception('Prebook failed. Selected rate is no longer available. Please search again.');
            }

            $prebookData = json_decode($prebookRes, true);
            if (!isset($prebookData['status']) || $prebookData['status'] !== 'ok' || empty($prebookData['data']['hotels'][0]['rates'][0]['book_hash'])) {
                $err = $prebookData['error'] ?? 'unknown_error';
                error_log("RateHawk Prebook: API Error status - " . json_encode($prebookData));
                throw new Exception("Prebook failed (Error: {$err}). Please select another rate.");
            }

            // Update book_hash to the new p- hash returned by prebook
            $bookHash = $prebookData['data']['hotels'][0]['rates'][0]['book_hash'];
        }

        // =====================================
        // CREATE BOOKING FORM
        // =====================================
        // Resume: if finish already started, only poll Check booking process (don't create a new order)
        $existingPartnerOrderId = $bookingData['ratehawk_partner_order_id'] ?? '';
        $resumeStatusOnly = !empty($existingPartnerOrderId)
            && empty($bookingData['ratehawk_confirmed_at'])
            && (
                !empty($bookingData['ratehawk_finish_submitted_at'])
                || !empty($booking['pnr'])
            );

        if ($resumeStatusOnly) {
            $partnerOrderId = $existingPartnerOrderId;
            $orderId = $bookingData['ratehawk_order_id'] ?? null;
            $itemId = $bookingData['ratehawk_item_id'] ?? null;
        } else {
        // Prefer a public IPv4 — localhost ::1 can be rejected by some RateHawk validations
        $userIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (strpos($userIp, ',') !== false) {
            $userIp = trim(explode(',', $userIp)[0]);
        }
        if ($userIp === '::1' || $userIp === '127.0.0.1' || !filter_var($userIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $userIp = '8.8.8.8';
        }

        // =====================================
        // CREATE BOOKING FORM (retry ≤10 with new partner_order_id)
        // Docs: duplicate_reservation / double_booking_form / unknown / timeout / 5xx
        // =====================================
        $formRetryable = ['duplicate_reservation', 'double_booking_form', 'unknown', 'timeout'];
        $formOk = false;
        $apiResponse = null;
        $partnerOrderId = '';
        $maxFormAttempts = 10;

        for ($formAttempt = 1; $formAttempt <= $maxFormAttempts; $formAttempt++) {
            $partnerOrderId = 'RH_' . $booking['id'] . '_' . uniqid();

            $bookingFormRequest = [
                'partner_order_id' => $partnerOrderId,
                'book_hash' => $bookHash,
                'language' => 'en',
                'user_ip' => $userIp
            ];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiBaseUrl . '/hotel/order/booking/form/',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_USERPWD => $keyId . ':' . $apiKey,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_POSTFIELDS => json_encode($bookingFormRequest),
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if (function_exists('ratehawk_log')) {
                ratehawk_log('issue', 'booking_form', $bookingFormRequest, $response, (string) $formAttempt, [
                    'url' => $apiBaseUrl . '/hotel/order/booking/form/',
                    'method' => 'POST',
                    'headers' => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Basic ***'],
                    'response_headers' => ''
                ]);
            }

            if ($response === false) {
                // Treat connectivity as retryable (similar to 5xx)
                error_log("RateHawk Booking Form: cURL Error attempt {$formAttempt} - {$curlError}");
                if ($formAttempt < $maxFormAttempts) {
                    usleep(300000);
                    continue;
                }
                throw new Exception('Failed to connect to booking API: ' . $curlError);
            }

            $apiResponse = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("RateHawk Booking Form: Invalid JSON - HTTP {$httpCode}");
                if ($formAttempt < $maxFormAttempts && $httpCode >= 500) {
                    continue;
                }
                throw new Exception('Received invalid response from booking API');
            }

            if ($httpCode === 200 && ($apiResponse['status'] ?? '') === 'ok') {
                $formOk = true;
                break;
            }

            $errorCode = $apiResponse['error'] ?? 'unknown_error';
            $isRetryable = in_array($errorCode, $formRetryable, true) || $httpCode >= 500;

            error_log("RateHawk Booking Form: API Error attempt {$formAttempt} - {$errorCode}, HTTP {$httpCode}");

            if ($isRetryable && $formAttempt < $maxFormAttempts) {
                usleep(300000);
                continue;
            }

            $db->update('bookings', [
                'error_response' => json_encode($apiResponse),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $booking['id']]);

            $errorMessages = [
                'contract_mismatch' => 'Booking contract mismatch. Please try searching again.',
                'double_booking_form' => 'Booking already in progress. Please wait or start a new booking.',
                'duplicate_reservation' => 'This booking has already been completed. Please start a new booking.',
                'hotel_not_found' => 'Hotel not found. Please search again.',
                'reservation_is_not_allowed' => 'Booking is not allowed for this account. Please contact support.',
                'rate_not_found' => 'Selected rate is no longer available. Please search again.',
                'sandbox_restriction' => 'Test bookings require test environment. Please contact support.',
                'invalid_params' => 'Invalid booking parameters. Please try again or contact support.',
            ];

            $errorMessage = $errorMessages[$errorCode] ?? 'Booking service temporarily unavailable. Please try again.';
            throw new Exception($errorMessage . ' (Error: ' . $errorCode . ')');
        }

        if (!$formOk || !is_array($apiResponse)) {
            throw new Exception('Create booking process failed after ' . $maxFormAttempts . ' attempts. Please search again.');
        }

        // =====================================
        // EXTRACT BOOKING FORM DATA
        // =====================================
        $bookingFormData = $apiResponse['data'] ?? [];
        $orderId = $bookingFormData['order_id'] ?? null;
        $itemId = $bookingFormData['item_id'] ?? null;
        $paymentTypes = $bookingFormData['payment_types'] ?? [];

        if (empty($orderId) || empty($itemId)) {
            error_log("RateHawk Booking Form: Missing order_id or item_id");
            throw new Exception('Invalid booking form response');
        }

        // Update booking with order details (partner ref only — real PNR after finish/status)
        $bookingData['ratehawk_order_id'] = $orderId;
        $bookingData['ratehawk_item_id'] = $itemId;
        $bookingData['ratehawk_partner_order_id'] = $partnerOrderId;
        $bookingData['ratehawk_payment_types'] = $paymentTypes;
        $bookingData['ratehawk_book_hash'] = $bookHash;
        $bookingData['ratehawk_booking_form_created'] = date('Y-m-d H:i:s');

        $db->update('bookings', [
            'booking_status' => 'pending',
            'booking_data' => json_encode($bookingData),
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $booking['id']]);

        // =====================================
        // FINISH BOOKING
        // =====================================
        $travellers = json_decode($booking['travellers'], true) ?? [];
        $primaryGuest = $travellers['primary_guest'] ?? [];
        $travelers = $travellers['travelers'] ?? [];

        // Build phone as +{dial_code}{local_number} exactly from submitted booking fields.
        // Do NOT assume local number already contains the dial code (e.g. 34... + dial 34).
        $normalizePhone = function ($countryCode, $phone) use ($db) {
            $phoneRaw = trim((string)$phone);
            if ($phoneRaw === '') {
                return null;
            }

            $dial = '';
            if (function_exists('getPhoneCode')) {
                $dial = preg_replace('/\D+/', '', (string)getPhoneCode($countryCode, $db));
            } else {
                $dial = preg_replace('/\D+/', '', (string)$countryCode);
                if ($dial === '' && preg_match('/^[A-Za-z]{2}$/', trim((string)$countryCode))) {
                    $row = $db->get('countries', 'phonecode', ['iso' => strtoupper(trim((string)$countryCode))]);
                    $dial = preg_replace('/\D+/', '', (string)($row ?? ''));
                }
            }

            // Full international already provided
            if (strpos($phoneRaw, '+') === 0) {
                $digits = preg_replace('/\D+/', '', $phoneRaw);
                if (strlen($digits) >= 8 && strlen($digits) <= 15) {
                    return '+' . $digits;
                }
            }

            $local = preg_replace('/\D+/', '', $phoneRaw);
            $local = ltrim($local, '0');
            if ($local === '') {
                return null;
            }

            $digits = ($dial !== '') ? ($dial . $local) : $local;
            if (strlen($digits) < 8 || strlen($digits) > 15) {
                return null;
            }
            return '+' . $digits;
        };

        $sanitizeName = function ($name, $fallback = 'Guest') {
            $name = preg_replace('/[^a-zA-Z\-\s]/', '', (string)$name);
            $name = trim(preg_replace('/\s+/', ' ', $name));
            if ($name === '') {
                return $fallback;
            }
            // RateHawk prefers simple latin name tokens
            $parts = preg_split('/\s+/', $name);
            return $parts[0] ?: $fallback;
        };

        // Prefer booking.phone_country_code (saved via getPhoneCode on submit),
        // then traveller country/phone country fields.
        $rawCountry = $booking['phone_country_code']
            ?? $primaryGuest['country_code']
            ?? $primaryGuest['phone_country_code']
            ?? '';
        $rawPhone = $primaryGuest['phone'] ?? $booking['phone'] ?? '';
        $normalizedPhone = $normalizePhone($rawCountry, $rawPhone);

        // Build rooms array with guests.
        // RateHawk counts anyone without is_child=true as an adult → must mark children.
        $roomsDataForAges = $bookingData['rooms_data'] ?? $_SESSION['hotel_rooms_data'] ?? [];
        if (!is_array($roomsDataForAges)) {
            $roomsDataForAges = [];
        }
        $roomsDataForAges = array_values($roomsDataForAges);

        $roomsForBooking = [];
        $roomIndex = 0;
        foreach ($travelers as $roomKey => $roomTravelers) {
            if (!is_array($roomTravelers)) {
                continue;
            }

            $adultGuests = [];
            $childGuests = [];
            $roomAges = [];
            if (isset($roomsDataForAges[$roomIndex]['childAges']) && is_array($roomsDataForAges[$roomIndex]['childAges'])) {
                $roomAges = array_map('intval', array_values($roomsDataForAges[$roomIndex]['childAges']));
            }
            $childOrdinal = 0;

            foreach ($roomTravelers as $guestKey => $traveler) {
                if (!is_array($traveler) || empty($traveler['first_name']) || empty($traveler['last_name'])) {
                    continue;
                }

                $guest = [
                    'first_name' => $sanitizeName($traveler['first_name'], 'Guest'),
                    'last_name' => $sanitizeName($traveler['last_name'], 'Traveler')
                ];

                $keyIsChild = is_string($guestKey) && strpos($guestKey, 'child_') === 0;
                $flagIsChild = !empty($traveler['is_child']) || (($traveler['type'] ?? '') === 'child');

                if ($keyIsChild || $flagIsChild) {
                    $age = isset($traveler['age']) ? (int)$traveler['age'] : null;
                    if ($age === null || $age < 0 || $age > 17) {
                        $age = $roomAges[$childOrdinal] ?? 1;
                    }
                    $guest['is_child'] = true;
                    $guest['age'] = max(0, min(17, (int)$age));
                    $childGuests[] = $guest;
                    $childOrdinal++;
                } else {
                    $adultGuests[] = $guest;
                }
            }

            $guests = array_merge($adultGuests, $childGuests);
            if (!empty($guests)) {
                $roomsForBooking[] = ['guests' => $guests];
            }
            $roomIndex++;
        }

        // Fallback if no guests found
        if (empty($roomsForBooking)) {
            $roomsForBooking[] = [
                'guests' => [[
                    'first_name' => $sanitizeName($primaryGuest['first_name'] ?? $booking['first_name'] ?? '', 'Guest'),
                    'last_name' => $sanitizeName($primaryGuest['last_name'] ?? $booking['last_name'] ?? '', 'Traveler')
                ]]
            ];
        }

        $selectedPaymentType = $paymentTypes[0] ?? [
            'type' => 'deposit',
            'amount' => $bookingData['total_amount'] ?? '0.00',
            'currency_code' => $bookingData['currency'] ?? 'USD'
        ];

        // amount_sell_b2b2c must match payment currency/amount scale (not display EGP markup)
        $sellAmount = (string)($selectedPaymentType['amount'] ?? '0');

        // POST-PAYMENT PRICE RECONCILIATION: RateHawk prebook runs with
        // price_increase_percent=20, so the supplier may return a net rate up to
        // 20% higher than at search. Compare that live amount to what the customer
        // paid BEFORE finishing the booking; abort + flag if it rose beyond
        // tolerance instead of silently committing the higher rate.
        if (function_exists('reconcilePostPaymentPrice')) {
            $priceCheck = reconcilePostPaymentPrice(
                $db,
                $booking,
                (float) $sellAmount,
                (string) ($selectedPaymentType['currency_code'] ?? ($bookingData['currency'] ?? 'USD'))
            );
            if (empty($priceCheck['ok'])) {
                while (ob_get_level()) { ob_end_clean(); }
                echo json_encode([
                    'success' => false,
                    'status'  => false,
                    'message' => 'Booking held for review: ' . $priceCheck['reason'],
                    'price_review' => $priceCheck,
                    'invoice_id' => $invoice_id ?? ($booking['invoice_id'] ?? ''),
                ], JSON_UNESCAPED_SLASHES);
                exit;
            }
        }

        $userPayload = [
            'email' => $primaryGuest['email'] ?? $booking['email'],
            'comment' => $booking['special_requests'] ?? ''
        ];
        $supplierPayload = [
            'first_name_original' => $sanitizeName($primaryGuest['first_name'] ?? $booking['first_name'] ?? '', 'Guest'),
            'last_name_original' => $sanitizeName($primaryGuest['last_name'] ?? $booking['last_name'] ?? '', 'Traveler'),
            'email' => $primaryGuest['email'] ?? $booking['email']
        ];
        if ($normalizedPhone) {
            $userPayload['phone'] = $normalizedPhone;
            $supplierPayload['phone'] = $normalizedPhone;
        }

        $finishBookingRequest = [
            'user' => $userPayload,
            'supplier_data' => $supplierPayload,
            'partner' => [
                'partner_order_id' => $partnerOrderId,
                'comment' => 'Booking via PHP Travel System',
                'amount_sell_b2b2c' => $sellAmount
            ],
            'language' => 'en',
            'rooms' => $roomsForBooking,
            'payment_type' => [
                'type' => $selectedPaymentType['type'] ?? 'deposit',
                'amount' => (string)($selectedPaymentType['amount'] ?? '0'),
                'currency_code' => $selectedPaymentType['currency_code'] ?? 'USD',
            ]
        ];

        // Keep optional flags RateHawk returned on payment_type when present
        if (isset($selectedPaymentType['is_need_credit_card_data'])) {
            $finishBookingRequest['payment_type']['is_need_credit_card_data'] = $selectedPaymentType['is_need_credit_card_data'];
        }
        if (isset($selectedPaymentType['is_need_cvc'])) {
            $finishBookingRequest['payment_type']['is_need_cvc'] = $selectedPaymentType['is_need_cvc'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiBaseUrl . '/hotel/order/booking/finish/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERPWD => $keyId . ':' . $apiKey,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode($finishBookingRequest),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $finishResponse = curl_exec($ch);
        $finishHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (function_exists('ratehawk_log')) {
            ratehawk_log('issue', 'booking_finish', $finishBookingRequest, $finishResponse, '', [
                'url' => $apiBaseUrl . '/hotel/order/booking/finish/',
                'method' => 'POST',
                'headers' => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Basic ***'],
                'response_headers' => ''
            ]);
        }

        if ($finishResponse === false) {
            error_log("RateHawk Finish Booking: cURL failed");
            // Docs: timeout/unknown/5xx → still proceed to Check booking process
        } else {
            $finishApiResponse = json_decode($finishResponse, true) ?: [];
            $finishStatus = $finishApiResponse['status'] ?? '';
            $finishError = $finishApiResponse['error'] ?? '';
            $finishTransient = in_array($finishError, ['timeout', 'unknown'], true)
                || $finishHttpCode >= 500
                || $finishHttpCode === 0;

            if ($finishHttpCode === 200 && $finishStatus === 'ok') {
                // proceed to poll
            } elseif ($finishTransient || $finishStatus === 'ok') {
                // Docs: ok OR timeout/unknown/5xx → Proceed to Check Booking Process
                error_log("RateHawk Finish Booking: transient/ok proceed to status poll - HTTP {$finishHttpCode}, error={$finishError}");
            } else {
                $validationError = $finishApiResponse['debug']['validation_error'] ?? '';
                $errorDetail = $validationError !== '' ? "{$finishError}: {$validationError}" : ($finishError ?: 'unknown');
                error_log("RateHawk Finish Booking: Error - {$errorDetail}");

                $db->update('bookings', [
                    'booking_status' => 'pending',
                    'error_response' => json_encode([
                        'response_error' => 'Finish booking failed: ' . $errorDetail,
                        'error' => $finishError,
                        'message' => 'Finish booking failed: ' . $errorDetail,
                        'validation_error' => $validationError,
                        'partner_order_id' => $partnerOrderId,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]),
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id' => $booking['id']]);

                throw new Exception('Finish booking failed: ' . $errorDetail);
            }
        }

        $bookingData['ratehawk_finish_submitted_at'] = date('Y-m-d H:i:s');
        $db->update('bookings', [
            'booking_status' => 'pending',
            'pnr' => $partnerOrderId,
            'booking_data' => json_encode($bookingData),
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $booking['id']]);

        } // end else create form + finish

        // =====================================
        // CHECK BOOKING PROCESS (poll finish/status)
        // ETG: recommended once every 5s until ok / final error / booking cut-off.
        // Always send one final status request in the last second before cut-off.
        // =====================================
        @set_time_limit(0);
        $pollIntervalSec = 5;
        $bookingTimeoutSec = 600; // 10 minutes — adjust to agreed ETG booking cut-off
        $pollStartedAt = time();
        $attempt = 0;
        $bookingConfirmed = false;
        $actualPnr = $partnerOrderId;
        $bookingStatus = 'pending';
        $lastPercent = 0;
        $finalErrors = [
            'soldout', 'provider', 'book_limit', 'block', 'charge', '3ds',
            'not_allowed', 'order_not_found', 'booking_finish_did_not_succeed',
            'decoding_json', 'endpoint_exceeded_limit', 'endpoint_not_active',
            'endpoint_not_found', 'incorrect_credentials', 'invalid_auth_header',
            'invalid_params', 'lock', 'no_auth_header', 'not_allowed_host',
            'overdue_debt', 'unexpected_method'
        ];

        while (!$bookingConfirmed) {
            $elapsed = time() - $pollStartedAt;
            if ($elapsed >= $bookingTimeoutSec) {
                break;
            }

            if ($attempt > 0) {
                $remaining = $bookingTimeoutSec - $elapsed;
                // Sleep 5s, but wake 1s before cut-off for the mandatory final check
                $sleepFor = min($pollIntervalSec, max(1, $remaining - 1));
                sleep($sleepFor);
                if ((time() - $pollStartedAt) >= $bookingTimeoutSec) {
                    break;
                }
            }
            $attempt++;

            $statusRequest = ['partner_order_id' => $partnerOrderId];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $apiBaseUrl . '/hotel/order/booking/finish/status/',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_USERPWD => $keyId . ':' . $apiKey,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_POSTFIELDS => json_encode($statusRequest),
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true
            ]);

            $statusResponse = curl_exec($ch);
            $statusHttpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            // Log every Check booking process poll
            if (function_exists('ratehawk_log')) {
                ratehawk_log('issue', 'booking_status', $statusRequest, $statusResponse, (string) $attempt, [
                    'url' => $apiBaseUrl . '/hotel/order/booking/finish/status/',
                    'method' => 'POST',
                    'headers' => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Basic ***'],
                    'response_headers' => ''
                ]);
            }

            // Docs: processing / timeout / unknown / 5xx → keep polling
            if ($statusResponse === false || $statusHttpCode >= 500 || $statusHttpCode === 0) {
                continue;
            }

            $statusApiResponse = json_decode($statusResponse, true);
            if (!is_array($statusApiResponse)) {
                continue;
            }

            $status = $statusApiResponse['status'] ?? 'error';
            $statusData = is_array($statusApiResponse['data'] ?? null) ? $statusApiResponse['data'] : [];
            $lastPercent = (int) ($statusData['percent'] ?? $lastPercent);
            $error = $statusApiResponse['error'] ?? '';

            if ($status === 'ok') {
                $bookingConfirmed = true;
                $bookingStatus = 'confirmed';
                $actualPnr = $statusData['reference']
                    ?? $statusData['hotel_confirmation_code']
                    ?? $statusData['booking_id']
                    ?? $partnerOrderId;
                break;
            }

            if ($status === 'processing') {
                continue;
            }

            if ($status === '3ds') {
                error_log('RateHawk Booking Status: 3D Secure required');
                throw new Exception('3D Secure payment required. Please contact support.');
            }

            if ($status === 'error' || $error !== '') {
                if (in_array($error, ['timeout', 'unknown'], true)) {
                    continue; // transient — keep polling
                }
                if (in_array($error, $finalErrors, true)) {
                    error_log("RateHawk Booking Status: final error - {$error}");
                    throw new Exception('Booking failed: ' . $error);
                }
                error_log("RateHawk Booking Status: Error - {$error}");
                continue;
            }
        }

        // =====================================
        // UPDATE BOOKING — confirmed OR timeout failure
        // =====================================
        $bookingData['ratehawk_partner_order_id'] = $partnerOrderId;
        $bookingData['ratehawk_order_id'] = $orderId ?? ($bookingData['ratehawk_order_id'] ?? null);
        $bookingData['ratehawk_item_id'] = $itemId ?? ($bookingData['ratehawk_item_id'] ?? null);
        $bookingData['ratehawk_last_status_percent'] = $lastPercent;
        $bookingData['ratehawk_last_status_check'] = date('Y-m-d H:i:s');

        if (!$bookingConfirmed) {
            $db->update('bookings', [
                'booking_status' => 'pending',
                'pnr' => $partnerOrderId,
                'booking_data' => json_encode($bookingData),
                'error_response' => json_encode([
                    'response_error' => 'Booking status timeout after ' . $bookingTimeoutSec . 's without confirmation',
                    'error' => 'booking_timeout',
                    'message' => 'Booking cut-off reached without ok status from RateHawk',
                    'partner_order_id' => $partnerOrderId,
                    'percent' => $lastPercent,
                    'attempts' => $attempt,
                    'timestamp' => date('Y-m-d H:i:s')
                ]),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $booking['id']]);

            throw new Exception(
                'Booking confirmation timed out after ' . $bookingTimeoutSec
                . ' seconds (RateHawk cut-off window). Last progress: ' . $lastPercent . '%.'
            );
        }

        $bookingData['ratehawk_confirmed_at'] = date('Y-m-d H:i:s');
        $db->update('bookings', [
            'booking_status' => 'confirmed',
            'pnr' => $actualPnr,
            'booking_data' => json_encode($bookingData),
            'error_response' => null,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $booking['id']]);

        $message = 'Booking completed successfully';

        // =====================================
        // RETURN SUCCESS RESPONSE
        // (Format matches Hotelbeds for payment-gateway.php compatibility)
        // =====================================
        ob_end_clean();
        echo json_encode([
            'status' => true,
            'Prn' => $actualPnr,
            'booking_reference' => $actualPnr,
            'reference' => $actualPnr,
            'booking_status' => 'confirmed',
            'success' => true,
            'message' => $message,
            'response_error' => '',
            'invoice_id' => $invoiceId,
            'data' => [
                'booking_id' => $booking['id'],
                'invoice_id' => $invoiceId,
                'order_id' => $orderId ?? null,
                'partner_order_id' => $partnerOrderId,
                'pnr' => $actualPnr,
                'status' => 'confirmed',
                'confirmed' => true,
                'percent' => $lastPercent,
                'item_id' => $itemId ?? null
            ]
        ]);

    } catch (Exception $e) {
        // Save error to database
        if (isset($booking) && isset($booking['id'])) {
            $db->update('bookings', [
                'error_response' => json_encode([
                    'response_error' => $e->getMessage(),
                    'error' => 'booking_error',
                    'message' => $e->getMessage(),
                    'timestamp' => date('Y-m-d H:i:s')
                ]),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $booking['id']]);
        }

        // Log error
        error_log("RateHawk Booking Error: " . $e->getMessage() . " (Invoice: " . ($invoiceId ?? 'N/A') . ")");
        
        ob_end_clean();
        http_response_code(400);
        echo json_encode([
            'status' => false,
            'Prn' => '',
            'booking_reference' => '',
            'reference' => '',
            'success' => false,
            'error' => 'booking_error',
            'message' => $e->getMessage(),
            'response_error' => $e->getMessage()
        ]);
    }

    exit;
});

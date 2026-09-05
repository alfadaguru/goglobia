<?php

/*
 * Seeru Flights - Complete Booking & Issue Flow
 * Steps:
 *   1. Load credentials + determine base URL (sandbox vs production via dev_mode)
 *   2. Validate fare  → POST /flights/booking/fare
 *   3. Save booking   → POST /flights/booking/save
 *   4. Issue ticket   → POST /flights/order/issue
 *   5. Return PNR + Update DB
 */

$router->post('flights/seeru/issue', function() use ($db) {
    try {
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');

        @mkdir(__DIR__ . "/../logs/", 0777, true);

        // ─── Parse Request ────────────────────────────────────────────────────────
        $raw_input  = file_get_contents('php://input');
        $json_data  = json_decode($raw_input, true);

        if (!empty($_POST)) {
            $request_data = $_POST;
        } elseif (is_array($json_data)) {
            $request_data = $json_data;
        } else {
            $request_data = $_REQUEST;
        }

        // @file_put_contents(__DIR__ . "/../logs/_ISSUE_REQUEST.log",
//             date('Y-m-d H:i:s') . "\nRAW: " . $raw_input .
//             "\nFinal: " . print_r($request_data, true) . "\n\n",
//             FILE_APPEND
//         );

        // ─── Load Booking Data ────────────────────────────────────────────────────
        $invoice_id = '';
        $booking_id = null;

        if (!empty($request_data['invoice_id'])) {

            $invoice_id = $request_data['invoice_id'];
            $booking    = $db->get("bookings", "*", ["invoice_id" => $invoice_id]);

            if (!$booking) {
                echo json_encode(['status' => false, 'message' => 'Booking not found for invoice_id: ' . $invoice_id]);
                exit;
            }

            // ─── Check if already issued ──────────────────────────────────────────
            if (!empty($booking['pnr'])) {
                echo json_encode([
                    'status'  => true,
                    'Prn'     => $booking['pnr'],
                    'message' => 'Booking already issued',
                    'invoice_id' => $invoice_id
                ]);
                exit;
            }

            // @file_put_contents(__DIR__ . "/../logs/_ISSUE_BOOKING_FROM_DB.log",
//                 date('Y-m-d H:i:s') . "\n" . print_r($booking, true) . "\n\n", FILE_APPEND);

            $booking_data_raw = json_decode($booking['booking_data'], true);
            $travellers       = json_decode($booking['travellers'], true);
            $guest            = [];

            if ($travellers) {
                foreach ($travellers as $key => $pax) {
                    $guest[] = [
                        'traveller_type'         => strpos($key, 'adult')  !== false ? 'adults'
                                                  : (strpos($key, 'child') !== false ? 'child' : 'infant'),
                        'first_name'             => $pax['first_name'] ?? '',
                        'last_name'              => $pax['last_name']  ?? '',
                        'dob_day'                => !empty($pax['dob_day'])   ? $pax['dob_day']   : '01',
                        'dob_month'              => !empty($pax['dob_month']) ? $pax['dob_month'] : '01',
                        'dob_year'               => !empty($pax['dob_year'])  ? $pax['dob_year']  : '1990',
                        'passport'               => $pax['passport'] ?? '',
                        'passport_day_expiry'    => !empty($pax['passport_day_expiry'])   ? $pax['passport_day_expiry']   : '01',
                        'passport_month_expiry'  => !empty($pax['passport_month_expiry']) ? $pax['passport_month_expiry'] : '12',
                        'passport_year_expiry'   => !empty($pax['passport_year_expiry'])  ? $pax['passport_year_expiry']  : '2030',
                        'nationality'            => $pax['nationality'] ?? ''
                    ];
                }
            }

            $user_id = $booking['user_id'] ?? null;
            $user    = $user_id
                     ? ($db->get("users", ["first_name", "last_name", "email", "phone"], ["id" => $user_id]) ?: [])
                     : [
                         'first_name' => $booking['first_name'] ?? '',
                         'last_name'  => $booking['last_name']  ?? '',
                         'email'      => $booking['email']      ?? '',
                         'phone'      => $booking['phone']      ?? ''
                       ];

            $booking_id = $booking['id'];

        } else {
            // Direct payload flow
            if (empty($request_data['booking_data'])) {
                echo json_encode(['status' => false, 'message' => 'Either invoice_id or booking_data is required']);
                exit;
            }
            if (empty($request_data['guest'])) {
                echo json_encode(['status' => false, 'message' => 'guest parameter is required']);
                exit;
            }
            if (empty($request_data['user_data'])) {
                echo json_encode(['status' => false, 'message' => 'user_data parameter is required']);
                exit;
            }

            $guest            = is_string($request_data['guest'])        ? json_decode($request_data['guest'], true)        : $request_data['guest'];
            $user             = is_string($request_data['user_data'])    ? json_decode($request_data['user_data'], true)    : $request_data['user_data'];
            $booking_data_raw = is_string($request_data['booking_data']) ? json_decode($request_data['booking_data'], true) : $request_data['booking_data'];
            $booking_id       = null;
        }

        // ─── Extract Flight Payload ───────────────────────────────────────────────
        $payload = $booking_data_raw['flight_data']['booking_data']['flight'] ?? null;

        if (!$payload) {
            echo json_encode(['status' => false, 'message' => 'Invalid booking_data structure - flight data not found']);
            exit;
        }

        // ─── Load Credentials + Determine Base URL ────────────────────────────────
        $module = $db->get('modules', ['c1', 'c2', 'dev_mode'], [
            'name' => 'seeru',
            'type' => 'flights'
        ]);

        if (!$module) {
            // @file_put_contents(__DIR__ . "/../logs/_ISSUE_ERROR.log",
//                date('Y-m-d H:i:s') . " - Seeru credentials not found in database\n\n", FILE_APPEND);
            echo json_encode(['status' => false, 'message' => 'Seeru module not configured']);
            exit;
        }

        $api_key       = $module['c1'] ?? '';
        $refresh_token = $module['c2'] ?? '';
        $dev_mode      = (int)($module['dev_mode'] ?? 1);

        // Dynamic base URL: dev_mode = 1 → sandbox, dev_mode = 0 → production
        $base_url = ($dev_mode === 1)
                  ? 'https://sandbox-api.seeru.travel/v1/'
                  : 'https://live-api.seeru.travel/v1/';

        // // @file_put_contents(__DIR__ . "/../logs/_ISSUE_CREDENTIALS.log",
//             date('Y-m-d H:i:s') .
//             "\nMode: " . ($dev_mode === 1 ? 'SANDBOX' : 'PRODUCTION') .
//             "\nBase URL: " . $base_url .
//             "\nAPI Key: " . substr($api_key, 0, 20) . "... (len:" . strlen($api_key) . ")" .
//             "\nToken: "   . substr($refresh_token, 0, 50) . "...\n\n",
//             FILE_APPEND
//         );

        if (empty($api_key) || empty($refresh_token)) {
            echo json_encode(['status' => false, 'message' => 'Seeru API credentials missing or incomplete']);
            exit;
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
            'User-Agent: V10-Travel-System/1.0'
        ];

        // ─── Helper: Execute cURL ─────────────────────────────────────────────────
        $seeru_post = function(string $url, array $body, array $headers, string $log_prefix) use (&$invoice_id) {
            // Add Expect: header (empty) to disable 100-continue behavior
            $headers[] = 'Expect:';

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($body),
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
            ]);

            $apiResFull = curl_exec($ch);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headerStr = substr($apiResFull, 0, $headerSize);
            $response = substr($apiResFull, $headerSize);

            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_err  = curl_errno($ch) ? curl_error($ch) : null;
            curl_close($ch);

            // Log using the seeru_log utility
            if (function_exists('seeru_log')) {
                $actionName = strtolower(ltrim($log_prefix, '_'));
                seeru_log('booking', $actionName, $body, $response, $invoice_id, [
                    'url' => $url,
                    'method' => 'POST',
                    'headers' => $headers,
                    'response_headers' => $headerStr
                ]);
            }

            return [
                'response'  => $response,
                'http_code' => $http_code,
                'curl_err'  => $curl_err,
                'data'      => json_decode($response, true)
            ];
        };

        // ─── STEP 1: Validate Fare ────────────────────────────────────────────────
        $fare_result = $seeru_post(
            $base_url . 'flights/booking/fare',
            ['booking' => $payload],
            $headers,
            '_FARE'
        );

        if ($fare_result['curl_err']) {
            // ─── Update DB: fare request connection error ─────────────────────────
            if ($booking_id) {
                $db->update('bookings', [
                    'booking_status' => 'pending',
                    'error_response' => json_encode([
                        'error'   => 'Fare validation cURL error',
                        'message' => $fare_result['curl_err']
                    ])
                ], ['id' => $booking_id]);
            }

            echo json_encode([
                'status'         => false,
                'Prn'            => '',
                'message'        => 'Fare validation request failed',
                'response_error' => $fare_result['curl_err']
            ]);
            exit;
        }

        $fare_data = $fare_result['data'];
        // Accept: 'ok', 'price_increased', 'price_decreased' — all have a valid booking object.
        // Only block if Seeru returns no booking object at all (truly unavailable fare).
        $valid_fare_statuses = ['ok', 'price_increased', 'price_decreased'];
        if (!is_array($fare_data) || !isset($fare_data['booking']) || !in_array($fare_data['status'] ?? '', $valid_fare_statuses)) {
            // ─── Update DB: fare validation failed ───────────────────────
            if ($booking_id) {
                $db->update('bookings', [
                    'booking_status' => 'pending',
                    'error_response' => json_encode([
                        'error'    => 'Fare validation failed',
                        'response' => $fare_data
                    ])
                ], ['id' => $booking_id]);
            }

            $apiMessage = is_array($fare_data) ? ($fare_data['message'] ?? null) : null;
            if (empty($apiMessage) && is_string($fare_result['response']) && strlen(trim($fare_result['response'])) < 200) {
                $apiMessage = trim($fare_result['response']);
            }

            echo json_encode([
                'status'         => false,
                'Prn'            => '',
                'message'        => $apiMessage ?: 'The fare has changed. Please search again and rebook.',
                'response'       => $fare_data,
                'response_error' => 'Fare validation failed'
            ]);
            exit;
        }

        // ✅ IMPORTANT: Use the updated booking object returned by fare check.
        $confirmed_booking = $fare_data['booking'] ?? $payload;

        // POST-PAYMENT PRICE RECONCILIATION (§8.1(2) fix): Seeru already RE-VALIDATES
        // the fare here (POST /flights/booking/fare) and can return
        // price_increased/price_decreased. Instead of booking blindly at the new
        // fare, compare the live grandTotal to what the customer paid and abort +
        // flag if it rose beyond tolerance. Only runs for a real paid booking
        // ($booking is unset on the raw booking_data test path).
        if (isset($booking) && is_array($booking) && function_exists('reconcilePostPaymentPrice')) {
            $seeruLiveTotal = (float) ($confirmed_booking['price']['grandTotal']
                ?? $confirmed_booking['price']['total'] ?? 0);
            $seeruCurrency  = strtoupper((string) ($confirmed_booking['price']['currency']
                ?? ($booking['currency_markup'] ?? 'USD')));
            if ($seeruLiveTotal > 0) {
                $seeruPriceCheck = reconcilePostPaymentPrice($db, $booking, $seeruLiveTotal, $seeruCurrency);
                if (empty($seeruPriceCheck['ok'])) {
                    echo json_encode([
                        'status'  => false,
                        'message' => 'Booking held for review: ' . $seeruPriceCheck['reason'],
                        'price_review' => $seeruPriceCheck,
                    ], JSON_UNESCAPED_SLASHES);
                    exit;
                }
            }
        }

        // ─── STEP 2: Get Access Token ─────────────────────────────────────────────
        $access_token = $api_key;

        // Headers for booking operations (save + issue)
        $booking_headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $access_token,
            'x-api-key: ' . $api_key,
            'User-Agent: V10-Travel-System/1.0'
        ];

        // ─── STEP 3: Build Passengers ─────────────────────────────────────────────
        $passengers = [];
        foreach ($guest as $pax) {
            $type = is_array($pax) ? ($pax['traveller_type'] ?? '') : ($pax->traveller_type ?? '');

            if (in_array($type, ['adults', 'adult'])) {
                $pax_type = 'ADT';
            } elseif (in_array($type, ['child', 'children'])) {
                $pax_type = 'CHD';
            } elseif (in_array($type, ['infant', 'infants'])) {
                $pax_type = 'INF';
            } else {
                $pax_type = 'ADT';
            }

            $get = fn($field) => is_array($pax) ? ($pax[$field] ?? '') : ($pax->$field ?? '');

            $passengers[] = [
                "pax_id"           => "",
                "type"             => $pax_type,
                "first_name"       => $get('first_name'),
                "last_name"        => $get('last_name'),
                "gender"           => "M",
                "birth_date"       => $get('dob_year')           . '-'
                                    . str_pad($get('dob_month'),          2, '0', STR_PAD_LEFT) . '-'
                                    . str_pad($get('dob_day'),            2, '0', STR_PAD_LEFT),
                "document_type"    => "PP",
                "document_number"  => $get('passport'),
                "document_expiry"  => $get('passport_year_expiry')  . '-'
                                    . str_pad($get('passport_month_expiry'), 2, '0', STR_PAD_LEFT) . '-'
                                    . str_pad($get('passport_day_expiry'),   2, '0', STR_PAD_LEFT),
                "document_country" => "",
                "nationality"      => $get('nationality')
            ];
        }

        // ─── STEP 4: Save Booking ─────────────────────────────────────────────────
        $save_result = $seeru_post(
            $base_url . 'flights/booking/save',
            [
                'booking'    => $confirmed_booking,
                'passengers' => $passengers,
                'contact'    => [
                    'full_name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
                    'email'     => $user['email'] ?? '',
                    'mobile'    => $user['phone'] ?? ''
                ]
            ],
            $booking_headers,
            '_SAVE'
        );
        
        if ($save_result['curl_err']) {
            // ─── Update DB: save request connection error ─────────────────────────
            if ($booking_id) {
                $db->update('bookings', [
                    'booking_status' => 'pending',
                    'error_response' => json_encode([
                        'error'   => 'Booking save cURL error',
                        'message' => $save_result['curl_err']
                    ])
                ], ['id' => $booking_id]);
            }

            echo json_encode([
                'status'         => false,
                'Prn'            => '',
                'message'        => 'Booking save request failed',
                'response_error' => $save_result['curl_err']
            ]);
            exit;
        }

        $save_data = $save_result['data'];
        if (!isset($save_data['order_id'])) {
            // ─── Update DB: save failed - no order_id ────────────────────────────
            if ($booking_id) {
                $db->update('bookings', [
                    'booking_status' => 'pending',
                    'error_response' => json_encode([
                        'error'    => 'Booking save failed - no order_id returned',
                        'response' => $save_data
                    ])
                ], ['id' => $booking_id]);
            }

            echo json_encode([
                'status'         => false,
                'Prn'            => '',
                'message'        => 'Booking save failed - no order_id returned',
                'response'       => $save_data,
                'response_error' => 'No order_id in response'
            ]);
            exit;
        }

        $order_id       = $save_data['order_id'];
        $reservation_no = $save_data['reservation_no'] ?? '';

        // ─── STEP 5: Issue Ticket ─────────────────────────────────────────────────
        $issue_result = $seeru_post(
            $base_url . 'flights/order/issue',
            ['order_id' => $order_id],
            $booking_headers,
            '_ISSUE'
        );

        $issue_data = $issue_result['data'];
        
        // ─── Final Response ───────────────────────────────────────────────────────
        if (isset($issue_data['status']) && $issue_data['status'] === 'success') {

            $final_pnr = $issue_data['pnr'] ?? $reservation_no;

            // // @file_put_contents(__DIR__ . "/../logs/_ISSUE_SUCCESS.log",
//                date('Y-m-d H:i:s') . " - PNR: {$final_pnr} | Order: {$order_id} | Mode: " .
//                ($dev_mode === 1 ? 'SANDBOX' : 'PRODUCTION') . "\n\n",
//                FILE_APPEND
//            );

            // ─── Update DB: booking confirmed ────────────────────────────────────
            if ($booking_id) {
                $issue_response = array_merge(is_array($issue_data) ? $issue_data : [], [
                    'order_id' => $order_id,
                    'pnr'      => $final_pnr,
                ]);

                $db->update('bookings', [
                    'booking_status'   => 'confirmed',
                    'pnr'              => $final_pnr,
                    'booking_response' => json_encode($issue_response),
                    'error_response'   => null
                ], ['id' => $booking_id]);
            }

            echo json_encode([
                'status'         => true,
                'message'        => 'Booking completed successfully',
                'Prn'            => $final_pnr,
                'ticket_status'  => $issue_data['status'] ?? '',
                'mode'           => $dev_mode === 1 ? 'sandbox' : 'production',
                'response'       => $issue_data,
                'response_error' => ''
            ]);

        } else {

            // // @file_put_contents(__DIR__ . "/../logs/_ISSUE_FAILED.log",
//                date('Y-m-d H:i:s') . " - HTTP " . $issue_result['http_code'] .
//                " - " . $issue_result['response'] . "\n\n",
//                FILE_APPEND
//            );

            // ─── Update DB: booking saved but ticket issue failed ─────────────────
            if ($booking_id) {
                $db->update('bookings', [
                    'booking_status' => 'pending',
                    'error_response' => json_encode([
                        'error'    => 'Ticket issue failed',
                        'order_id' => $order_id,
                        'response' => $issue_data
                    ])
                ], ['id' => $booking_id]);
            }

            echo json_encode([
                'status'         => false,
                'Prn'            => $reservation_no,
                'message'        => 'Booking saved but ticket was not issued',
                'order_id'       => $order_id,
                'response'       => $issue_data,
                'response_error' => $issue_data['message'] ?? 'Ticket issue failed'
            ]);
        }

    } catch (Exception $e) {
        // // @file_put_contents(__DIR__ . "/../logs/_ISSUE_EXCEPTION.log",
//            date('Y-m-d H:i:s') . " - " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n",
//            FILE_APPEND
//        );

        // ─── Update DB: unhandled exception ──────────────────────────────────────
        if (!empty($booking_id) && isset($db)) {
            try {
                $db->update('bookings', [
                    'booking_status' => 'pending',
                    'error_response' => json_encode([
                        'error'   => 'Exception during booking',
                        'message' => $e->getMessage()
                    ])
                ], ['id' => $booking_id]);
            } catch (Exception $db_err) {
                // // @file_put_contents(__DIR__ . "/../logs/_ISSUE_DB_ERROR.log",
//                    date('Y-m-d H:i:s') . " - Failed to update DB after exception: " . $db_err->getMessage() . "\n\n",
//                    FILE_APPEND
//                );
            }
        }

        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');
        echo json_encode([
            'status'         => false,
            'message'        => 'An error occurred during booking',
            'error'          => $e->getMessage(),
            'Prn'            => '',
            'response_error' => $e->getMessage()
        ]);
    }
});
<?php

/**
 * Payment Gateway Library
 * Simple and easy payment processing for all gateways
 * Usage: Just call process_payment($invoiceId) and handle_payment_callback()
 */

/**
 * TRIGGER NOTIFICATION FOR BOOKING EVENTS
 * @param string $event - Event name (booking.issued, booking.issue_failed, etc.)
 * @param array $data - Event data
 * @return bool - Success status
 */
function triggerNotification($event, $data = [])
{
    global $db;

    try {
        // ============================================================
        // LOG NOTIFICATION TO DATABASE
        // ============================================================
        $db->insert('notifications', [
            'event' => $event,
            'data' => json_encode($data),
            'user_id' => $data['user_id'] ?? null,
            'booking_id' => $data['booking_id'] ?? null,
            'invoice_id' => $data['invoice_id'] ?? null,
            'read_status' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return true;

    } catch (Exception $e) {
        // ============================================================
        // NOTIFICATION FAILED - DO NOT STOP EXECUTION
        // ============================================================
        error_log("NOTIFICATION ERROR [{$event}]: " . $e->getMessage() . " | Invoice: " . ($data['invoice_id'] ?? 'N/A'));
        return false;
    }
}

/**
 * Process payment for an invoice
 * @param string $invoiceId - Invoice ID to process payment for
 * @return array - Payment processing result
 */
function process_payment($invoiceId)
{
    global $db, $SECURE;

    try {
        // Get booking details
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);

        if (!$booking) {
            return ['success' => false, 'message' => 'Invoice not found'];
        }

        // Check if already paid
        if ($booking['payment_status'] === 'paid') {
            return ['success' => false, 'message' => 'Payment already completed'];
        }

        // Get payment gateway
        $gatewayId = get_booking_gateway_id($booking);
        if (!$gatewayId) {
            return ['success' => false, 'message' => 'No payment gateway assigned'];
        }

        $gateway = $db->get('payment_gateways', '*', [
            'id' => $gatewayId,
            'status' => 1
        ]);

        if (!$gateway) {
            return ['success' => false, 'message' => 'Payment gateway not available'];
        }

        // Create payment token
        $token = create_payment_token($booking, $gateway);

        // Load and process gateway
        $gatewayName = strtolower($gateway['name']);
        $gatewayFile = __DIR__ . '/../views/payment-gateways/' . $gatewayName . '.php';

        if (!file_exists($gatewayFile)) {
            return ['success' => false, 'message' => 'Payment method not supported'];
        }

        // Start output buffering to capture gateway HTML
        ob_start();

        // Construct proper invoice URL based on module type
        $moduleType = $booking['module_type'] ?? 'flights';
        $invoiceBaseUrl = root . 'invoice/';

        // Map module types to their invoice URL patterns
        switch ($moduleType) {
            case 'tours':
                $invoiceBaseUrl = root . 'invoice/tours/';
                break;
            case 'flights':
                $invoiceBaseUrl = root . 'invoice/flights/';
                break;
            case 'stays':
                $invoiceBaseUrl = root . 'invoice/stays/';
                break;
            case 'cars':
                $invoiceBaseUrl = root . 'invoice/cars/';
                break;
            case 'esim':
                $invoiceBaseUrl = root . 'invoice/esim/';
                break;
            case 'rail':
                $invoiceBaseUrl = root . 'invoice/rail/';
                break;
            case 'ferries':
                $invoiceBaseUrl = root . 'invoice/ferries/';
                break;
            case 'bus':
                $invoiceBaseUrl = root . 'invoice/bus/';
                break;
            case 'ai_trip':
                $invoiceBaseUrl = root . 'invoice/ai_trip/';
                break;
            default:
                $invoiceBaseUrl = root . 'invoice/';
                break;
        }

        // Set payment data for gateway (new format)
        $callbackBase = $invoiceBaseUrl . $booking['invoice_id'] . '?token=' . urlencode($token) . '&payment_status=';
        $paymentData = [
            'booking' => $booking,
            'gateway' => $gateway,
            'token' => $token,
            'success_url' => $callbackBase . 'success',
            'cancel_url' => $callbackBase . 'cancel',
            'failure_url' => $callbackBase . 'failure'
        ];

        // Create legacy POST format for backward compatibility
        $legacyPayload = [
            'booking_ref_no' => $booking['ref'] ?? $booking['invoice_id'],
            'invoice_id' => $booking['invoice_id'],
            'client_email' => $booking['email'],
            'price' => $booking['price_markup'],
            'currency' => $booking['currency_markup'],
            'invoice_url' => $invoiceBaseUrl . $booking['invoice_id']
        ];
        $_POST['payload'] = base64_encode(json_encode((object) $legacyPayload));
        $_POST['success_url'] = $paymentData['success_url'];
        $_POST['cancel_url'] = $paymentData['cancel_url'];
        $_POST['failure_url'] = $paymentData['failure_url'];

        // Include gateway file
        include $gatewayFile;

        $gatewayHtml = ob_get_clean();

        return [
            'success' => true,
            'gateway_name' => $gateway['name'],
            'html' => $gatewayHtml,
            'token' => $token
        ];

    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Handle payment success/failure callbacks
 * @param string $token - Payment token
 * @param string $action - 'success', 'cancel', or 'failure'
 * @param array $data - Additional data from gateway
 * @return array - Processing result
 */
function handle_payment_callback($token, $action, $data = [])
{
    global $db, $SECURE;

    try {

        // ============================================================
        // VERIFY PAYMENT TOKEN
        // ============================================================
        if (!verify_payment_token($token)) {
            return [
                'success' => false,
                'message' => 'Invalid or expired token'
            ];
        }

        // Get token data
        $tokenData = get_token_data($token);

        // ============================================================
        // SERVER-SIDE PAYMENT VERIFICATION  (SECURITY-CRITICAL)
        // The browser return URL (?payment_status=success&token=...) is
        // attacker-controllable: a buyer holds their own token and can craft the
        // success URL. Therefore a 'success' CLAIM is NEVER trusted on its own.
        //
        // Every 'success' claim is resolved through verify_gateway_payment(),
        // which returns one of: 'success' (gateway API confirmed payment),
        // 'cancel', 'failure', or 'pending' (could not confirm — e.g. a gateway
        // that only confirms via webhook, or an API that was unreachable).
        // We prefer the gateway name from the trusted token over the request.
        // ============================================================
        $gatewayName = strtolower(
            $tokenData['gateway_name']
            ?? $data['gateway']
            ?? $data['gateway_data']['gateway']
            ?? ''
        );
        if ($action === 'success') {
            $verifiedAction = verify_gateway_payment($gatewayName, $data, $tokenData, $db);
            if ($verifiedAction !== 'success') {
                error_log("PAYMENT VERIFICATION OVERRIDE | Invoice: {$tokenData['invoice_id']} | Gateway: {$gatewayName} | Claimed: success | Actual: {$verifiedAction}");
                $action = $verifiedAction;
            }
        }

        // ============================================================
        // PENDING — verification could not confirm payment (unverifiable
        // gateway awaiting its webhook, or a verification error). Do NOT mark
        // the booking paid; record it as pending so an admin / the gateway
        // webhook can finalise it. This closes the "self-confirm without
        // paying" bypass without falsely failing a payment that may still land.
        // ============================================================
        if ($action === 'pending') {
            $db->update('bookings', [
                'payment_status' => 'pending',
            ], ['invoice_id' => $tokenData['invoice_id']]);
            // Keep the token so a later webhook / retry can still finalise it.
            return [
                'success' => false,
                'pending' => true,
                'message' => 'Payment is pending confirmation. Your booking will be confirmed once the payment is verified.',
                'booking' => $db->get('bookings', '*', ['invoice_id' => $tokenData['invoice_id']]),
            ];
        }

        // ============================================================
        // HANDLE SUCCESS PAYMENT
        // ============================================================
        if ($action === 'success' ) {

            // ============================================================
            // CHECK IF AUTO-ISSUE IS ENABLED IN SETTINGS
            // ============================================================
            $autoIssueEnabled = false;
            $settingsRecord = $db->get('settings', '*', ['id' => 1]);

            if ($settingsRecord && isset($settingsRecord['booking_payment_issue'])) {
                $autoIssueEnabled = ($settingsRecord['booking_payment_issue'] == 1);
            }

            $booking = $db->get('bookings', '*', ['invoice_id' => $tokenData['invoice_id']]);

            if (!$booking) {
                throw new Exception("Booking not found for invoice: {$tokenData['invoice_id']}");
            }

            // ============================================================
            // IDEMPOTENCY GUARD
            // If the booking is already paid (e.g. an asynchronous gateway
            // webhook finalized it before the buyer's browser returned), do not
            // run the issue flow again — just acknowledge success.
            // ============================================================
            if (($booking['payment_status'] ?? '') === 'paid') {
                clear_payment_token($token);
                return [
                    'success' => true,
                    'message' => 'Payment already completed',
                    'booking' => $booking
                ];
            }

            // ============================================================
            // VERIFY DEV_MODE CONSISTENCY: MODULE vs PAYMENT GATEWAY
            // Both must be in the same mode (0=live, 1=test) before auto-issuing
            // NOTE: Even on mismatch, we still record payment as paid (money was collected)
            //       We only skip auto-issue to prevent test/live booking conflicts
            // ============================================================
            $module = strtolower($booking['module'] ?? '');
            $bookingGatewayId = $booking['payment_gateway'] ?? null;  // FK id stored in bookings
            $devModeMismatch = false;
            $devModeMessage = '';

            if ($module && $bookingGatewayId) {

                $moduleRecord  = $db->get('modules', ['dev_mode', 'name'], ['name' => $module]);
                $gatewayRecord = $db->get('payment_gateways', ['dev_mode', 'name'], ['id' => $bookingGatewayId]);

                if ($moduleRecord && $gatewayRecord) {

                    $moduleDevMode  = (int) $moduleRecord['dev_mode'];
                    $gatewayDevMode = (int) $gatewayRecord['dev_mode'];
                    
                    if ($moduleDevMode !== $gatewayDevMode) {

                        $moduleMode  = $moduleDevMode  ? 'TEST' : 'LIVE';
                        $gatewayMode = $gatewayDevMode ? 'TEST' : 'LIVE';

                        $devModeMismatch = true;
                        $devModeMessage = "Configuration mismatch: Module '{$module}' is in {$moduleMode} mode but payment gateway '{$gatewayRecord['name']}' is in {$gatewayMode} mode. Auto-issue skipped.";

                        error_log("DEV_MODE MISMATCH | Invoice: {$tokenData['invoice_id']} | {$devModeMessage}");

                        // Still update payment status - money was already collected
                        $db->update('bookings', [
                            'payment_status' => 'paid',
                            'booking_status' => 'confirmed',
                            'transaction_id' => $data['transaction_id'] ?? null,
                            'paid_at' => date('Y-m-d H:i:s')
                        ], [
                            'invoice_id' => $tokenData['invoice_id']
                        ]);

                        // Record the transaction
                        record_transaction($tokenData, $data['transaction_id'] ?? null, 'success', $data);

                        // Rail: still place supplier order even when dev_mode blocks auto-issue curl
                        if (($booking['module_type'] ?? '') === 'rail') {
                            require_once dirname(__DIR__, 2) . '/modules/rail/train/search.php';
                            _train_issue_rail_after_payment($db, $tokenData['invoice_id']);
                        }

                        // Clear token
                        clear_payment_token($token);

                        return [
                            'success' => true,
                            'message' => $devModeMessage,
                            'warning' => $devModeMessage,
                            'booking' => $db->get('bookings', '*', ['invoice_id' => $tokenData['invoice_id']])
                        ];
                    }
                }
            }

            // ============================================================
            // INITIALIZE BOOKING API VARIABLES
            // ============================================================
            $bookingApiSuccess = false;
            $bookingReference = null;
            $bookingApiError = null;
            $bookingApiResponse = null;

            // ============================================================
            // PROCESS BOOKING API ONLY IF AUTO-ISSUE IS ENABLED
            // ============================================================
            if ($autoIssueEnabled) {

                $moduleType = $booking['module_type'] ?? '';

                // ============================================================
                // CHECK IF MODULE HAS CUSTOM BOOKING ENDPOINT
                // Exclusions: flight, visas (no auto-issue endpoints)
                // Tours: no PNR / supplier issue required — payment confirms booking
                // ai_trip packages: per-item PNRs via modules/ai_trip/ai_trip/issue
                // Cars modules (cartrawler, etc.) ARE auto-issued via their issue.php
                // ============================================================
                if ($module
                    && !in_array(strtolower($module), ['flight', 'visas'], true)
                    && !in_array(strtolower((string)$moduleType), ['tours', 'tour'], true)
                ) {

                    try {

                        // ============================================================
                        // BUILD API URL FOR MODULE BOOKING ENDPOINT
                        // Standard module API pattern: root . 'modules/' . $moduleType . '/' . $module . '/issue'
                        // ============================================================
                        $modulePath = $module;
                        
                        $apiUrl = root . 'modules/' . $moduleType . '/' . $modulePath . '/issue';

                        error_log("CALLING BOOKING API: {$apiUrl}");

                        // ============================================================
                        // PREPARE POST DATA
                        // ============================================================
                        $postData = [
                            'invoice_id' => $tokenData['invoice_id'],
                        ];

                        // ============================================================
                        // EXECUTE cURL REQUEST TO MODULE BOOKING API
                        // ============================================================
                        $ch = curl_init($apiUrl);

                        // TBO: PreBook 23s + Book 120s + mandatory 120s recovery wait
                        // + BookingDetail network time. Keep the caller above that full SLA.
                        $isTboHolidays = strtolower((string) $module) === 'tbo-holidays';
                        $issueTimeout = $isTboHolidays ? 380 : 300;

                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST           => true,
                            CURLOPT_POSTFIELDS     => http_build_query($postData),
                            CURLOPT_HTTPHEADER     => [
                                'Content-Type: application/x-www-form-urlencoded'
                            ],
                            CURLOPT_TIMEOUT        => $issueTimeout,
                            CURLOPT_CONNECTTIMEOUT => 10,
                            CURLOPT_SSL_VERIFYPEER => $isTboHolidays,
                            CURLOPT_SSL_VERIFYHOST => $isTboHolidays ? 2 : 0
                        ]);

                        $response  = curl_exec($ch);
                        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $curlError = curl_error($ch);
                        curl_close($ch);


                        // ============================================================
                        // CHECK FOR cURL ERRORS
                        // ============================================================
                        if ($curlError) {
                            throw new Exception("cURL Error connecting to {$apiUrl}: " . $curlError);
                        }

                        // ============================================================
                        // CHECK HTTP STATUS CODE
                        // ============================================================
                        if ($httpCode !== 200) {
                            throw new Exception("Booking API returned HTTP {$httpCode}. URL: {$apiUrl}. Response: " . substr($response, 0, 500));
                        }

                        // ============================================================
                        // CLEAN RESPONSE - REMOVE BOM AND WHITESPACE
                        // ============================================================
                        $response = trim($response);

                        if (substr($response, 0, 3) === "\xEF\xBB\xBF") {
                            $response = substr($response, 3);
                        }

                        if (substr($response, 0, 2) === "\xFE\xFF") {
                            $response = substr($response, 2);
                        }

                        if (substr($response, 0, 2) === "\xFF\xFE") {
                            $response = substr($response, 2);
                        }

                        $response = str_replace("\xEF\xBB\xBF", '', $response);
                        $response = trim($response);

                        // ============================================================
                        // DECODE JSON RESPONSE
                        // ============================================================
                        $bookingApiResponse = json_decode($response, true);
                        $jsonError = json_last_error();

                        if ($jsonError !== JSON_ERROR_NONE) {
                            $jsonErrorMsg = json_last_error_msg();
                            throw new Exception("JSON decode error: {$jsonErrorMsg}. Invoice: {$tokenData['invoice_id']}. Response: " . substr($response, 0, 500));
                        }

                        if (empty($bookingApiResponse)) {
                            throw new Exception("Empty API response after decoding. Invoice: {$tokenData['invoice_id']}. Raw: " . substr($response, 0, 500));
                        }

                        // ============================================================
                        // HANDLE API RESPONSE
                        // Supported formats include legacy `status`/`Prn` and
                        // newer module endpoints returning `success`/`pnr`.
                        // ============================================================
                        $moduleIssueSucceeded =
                            (!empty($bookingApiResponse['status']) && $bookingApiResponse['status'] === true)
                            || (!empty($bookingApiResponse['success']) && $bookingApiResponse['success'] === true);

                        if ($moduleIssueSucceeded) {

                            // ============================================================
                            // BOOKING API SUCCESS - EXTRACT PNR
                            // ============================================================
                            $bookingApiSuccess = true;
                            $bookingReference  = $bookingApiResponse['Prn']
                                ?? $bookingApiResponse['pnr']
                                ?? $bookingApiResponse['confirmation_number']
                                ?? $bookingApiResponse['booking_reference']
                                ?? $bookingApiResponse['reference']
                                ?? null;

                            // AI trip package: rebuild comma-separated PNRs from per-item issue results
                            if ((empty($bookingReference) || !is_string($bookingReference))
                                && strtolower((string)($moduleType ?? '')) === 'ai_trip'
                                && !empty($bookingApiResponse['items'])
                                && is_array($bookingApiResponse['items'])) {
                                $joined = [];
                                foreach ($bookingApiResponse['items'] as $issuedItem) {
                                    if (!is_array($issuedItem)) {
                                        continue;
                                    }
                                    $itemPnr = trim((string)($issuedItem['pnr'] ?? ''));
                                    if ($itemPnr === '') {
                                        continue;
                                    }
                                    $mod = strtolower((string)($issuedItem['module'] ?? ''));
                                    $labelMap = [
                                        'flights' => 'FLIGHT',
                                        'stays' => 'HOTEL',
                                        'tours' => 'TOUR',
                                        'cars' => 'CAR',
                                    ];
                                    $label = $labelMap[$mod] ?? strtoupper($mod !== '' ? $mod : 'ITEM');
                                    $joined[] = $label . ':' . $itemPnr;
                                }
                                if (count($joined)) {
                                    $bookingReference = implode(', ', array_unique($joined));
                                }
                            }

                            // ============================================================
                            // SAVE SUCCESSFUL BOOKING RESPONSE TO DATABASE
                            // CRITICAL: If PNR exists, booking_status MUST be 'confirmed' by default.
                            // Rail issue.php also returns 'confirmed' once the supplier order is placed;
                            // seat assignment is tracked separately via orderResultData polling.
                            // Do not wipe an existing package PNR with null.
                            // ============================================================
                            $moduleBookingStatus = $bookingApiResponse['booking_status'] ?? 'confirmed';

                            $issueUpdate = [
                                'booking_status' => $moduleBookingStatus,
                                'error_response' => null,
                                'booking_payment_issue' => null
                            ];
                            if (!empty($bookingReference)) {
                                $issueUpdate['pnr'] = $bookingReference;
                            }
                            // Rail issue.php already stores the supplier order response (with
                            // journey schema) — don't overwrite it with this wrapper payload.
                            if (($moduleType ?? '') !== 'rail') {
                                $issueUpdate['booking_response'] = json_encode($bookingApiResponse);
                            }

                            $db->update('bookings', $issueUpdate, [
                                'invoice_id' => $tokenData['invoice_id']
                            ]);

                            // ============================================================
                            // VERIFY UPDATE - ENFORCE CONFIRMED STATUS IF PNR EXISTS
                            // This is a safeguard to ensure booking_status is NEVER blank when PNR is set
                            // ============================================================
                            if (!empty($bookingReference)) {
                                $verifyBooking = $db->get('bookings', ['booking_status', 'pnr'], ['invoice_id' => $tokenData['invoice_id']]);

                                if ($verifyBooking && empty($verifyBooking['booking_status'])) {
                                    // Force confirmed status if somehow blank
                                    $db->update('bookings', ['booking_status' => 'confirmed'], ['invoice_id' => $tokenData['invoice_id']]);
                                    error_log("BOOKING STATUS CORRECTED | Invoice: {$tokenData['invoice_id']} | Status was blank, forced to confirmed");
                                }
                            }

                            // ============================================================
                            // TRIGGER SUCCESS NOTIFICATION (NON-BLOCKING)
                            // ============================================================
                            triggerNotification('booking.issued', [
                                'invoice_id' => $tokenData['invoice_id'],
                                'pnr' => $bookingReference,
                                'module' => $module,
                                'booking_id' => $booking['id'] ?? null,
                                'user_id' => $booking['user_id'] ?? null,
                                'customer_email' => $booking['email'] ?? '',
                                'customer_name' => ($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''),
                                'amount' => $tokenData['amount'] ?? 0,
                                'currency' => $tokenData['currency'] ?? 'USD'
                            ]);

                        } else {

                            // ============================================================
                            // BOOKING API FAILED - EXTRACT ERROR MESSAGE
                            // ============================================================
                            $issuedPnr = trim((string)(
                                $bookingApiResponse['Prn']
                                ?? $bookingApiResponse['pnr']
                                ?? $bookingApiResponse['booking_reference']
                                ?? ''
                            ));

                            // Rail: supplier order may succeed (PNR set) while seat poll reports fail_msg.
                            if ($issuedPnr !== '' && ($moduleType ?? '') === 'rail') {
                                $bookingApiSuccess = true;
                                $bookingReference = $issuedPnr;
                                $db->update('bookings', [
                                    'pnr'              => $issuedPnr,
                                    'booking_status'   => $bookingApiResponse['booking_status'] ?? 'confirmed',
                                    'error_response'   => null,
                                    'booking_payment_issue' => null,
                                ], ['invoice_id' => $tokenData['invoice_id']]);
                            } else {
                            $bookingApiError = $bookingApiResponse['response_error']
                                ?? $bookingApiResponse['error']
                                ?? $bookingApiResponse['message']
                                ?? json_encode($bookingApiResponse);

                            error_log("BOOKING FAILED | Module: {$module} | Invoice: {$tokenData['invoice_id']} | Error: {$bookingApiError}");

                            // ============================================================
                            // SAVE ERROR RESPONSE TO DATABASE
                            // ============================================================
                            $db->update('bookings', [
                                'error_response' => json_encode($bookingApiResponse),
                                'booking_status' => 'pending',
                                'booking_payment_issue' => $bookingApiError
                            ], [
                                'invoice_id' => $tokenData['invoice_id']
                            ]);

                            // ============================================================
                            // TRIGGER FAILURE NOTIFICATION (NON-BLOCKING)
                            // ============================================================
                            triggerNotification('booking.issue_failed', [
                                'invoice_id' => $tokenData['invoice_id'],
                                'error' => $bookingApiError,
                                'module' => $module,
                                'booking_id' => $booking['id'] ?? null,
                                'user_id' => $booking['user_id'] ?? null,
                                'customer_email' => $booking['email'] ?? '',
                                'amount' => $tokenData['amount'] ?? 0,
                                'currency' => $tokenData['currency'] ?? 'USD'
                            ]);
                            }
                        }

                    } catch (Exception $e) {

                        // ============================================================
                        // BOOKING API EXCEPTION - LOG AND SAVE ERROR
                        // ============================================================
                        $bookingApiError = $e->getMessage();

                        error_log("BOOKING API EXCEPTION | Invoice: {$tokenData['invoice_id']} | Module: {$module} | Error: {$bookingApiError}");

                        // ============================================================
                        // SAVE EXCEPTION TO DATABASE
                        // ============================================================
                        $db->update('bookings', [
                            'error_response' => json_encode([
                                'error' => $bookingApiError,
                                'timestamp' => date('Y-m-d H:i:s'),
                                'exception_type' => get_class($e),
                                'module' => $module,
                                'module_type' => $moduleType
                            ]),
                            'booking_status' => 'pending',
                            'booking_payment_issue' => $bookingApiError
                        ], [
                            'invoice_id' => $tokenData['invoice_id']
                        ]);

                        // ============================================================
                        // TRIGGER EXCEPTION NOTIFICATION (NON-BLOCKING)
                        // ============================================================
                        triggerNotification('booking.issue_exception', [
                            'invoice_id' => $tokenData['invoice_id'],
                            'error' => $bookingApiError,
                            'module' => $module ?? 'unknown',
                            'booking_id' => $booking['id'] ?? null,
                            'user_id' => $booking['user_id'] ?? null,
                            'customer_email' => $booking['email'] ?? ''
                        ]);
                    }

                } else {

                    // ============================================================
                    // MODULE WITHOUT CUSTOM BOOKING ENDPOINT
                    // Just update payment status, admin will issue manually
                    // ============================================================
                    error_log("MANUAL ISSUE REQUIRED - Module: {$module} (no custom booking endpoint)");

                    $db->update('bookings', [
                        'booking_status' => 'pending'
                    ], [
                        'invoice_id' => $tokenData['invoice_id']
                    ]);
                }

            } else {

                // ============================================================
                // AUTO-ISSUE DISABLED - PAYMENT ONLY
                // Admin will manually issue bookings after receiving payment
                // ============================================================
                error_log("AUTO-ISSUE DISABLED - Payment logged, manual issue required");

                $db->update('bookings', [
                    'booking_status' => 'pending'
                ], [
                    'invoice_id' => $tokenData['invoice_id']
                ]);

                // TRIGGER PAYMENT RECEIVED NOTIFICATION (Admin needs to issue)
                $booking = $db->get('bookings', '*', ['invoice_id' => $tokenData['invoice_id']]);

                triggerNotification('booking.payment_received', [
                    'invoice_id' => $tokenData['invoice_id'],
                    'booking_id' => $booking['id'] ?? null,
                    'module' => $booking['module'] ?? 'unknown',
                    'user_id' => $booking['user_id'] ?? null,
                    'customer_email' => $booking['email'] ?? '',
                    'customer_name' => ($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''),
                    'amount' => $tokenData['amount'] ?? 0,
                    'currency' => $tokenData['currency'] ?? 'USD',
                    'message' => 'Payment received. Manual issue required by admin.'
                ]);
            }

            // ============================================================
            // UPDATE PAYMENT STATUS
            // ============================================================
            $paidUpdate = [
                'payment_status' => 'paid',
                'transaction_id' => $data['transaction_id'] ?? null,
                'paid_at' => date('Y-m-d H:i:s'),
            ];

            $postIssueBooking = $db->get('bookings', ['booking_status', 'module_type', 'pnr'], [
                'invoice_id' => $tokenData['invoice_id']
            ]);
            // Rail: PNR issued = confirmed booking; seat assignment is polled separately on invoice.
            $keepExistingConfirmed = ($postIssueBooking['module_type'] ?? '') === 'rail'
                && !empty($postIssueBooking['pnr'])
                && ($postIssueBooking['booking_status'] ?? '') === 'confirmed';
            $tboIssueFailed = strtolower((string) ($booking['module'] ?? '')) === 'tbo-holidays'
                && !$bookingApiSuccess;

            if ($tboIssueFailed) {
                // Payment succeeded, but no supplier confirmation exists yet.
                $paidUpdate['booking_status'] = 'pending';
            } elseif (!$keepExistingConfirmed) {
                $paidUpdate['booking_status'] = 'confirmed';
            }

            $db->update('bookings', $paidUpdate, [
                'invoice_id' => $tokenData['invoice_id']
            ]);

            // ============================================================
            // RAIL: ISSUE AFTER PAYMENT IS MARKED PAID
            // ============================================================
            if (($booking['module_type'] ?? '') === 'rail') {
                require_once dirname(__DIR__, 2) . '/modules/rail/train/search.php';
                $railIssue = _train_issue_rail_after_payment($db, $tokenData['invoice_id']);
                if (!empty($railIssue['status']) || !empty($railIssue['pnr'])) {
                    $bookingApiSuccess = true;
                    $bookingReference = $railIssue['pnr'] ?? $railIssue['Prn'] ?? null;
                } elseif (!empty($railIssue['message'])) {
                    error_log('RAIL DIRECT ISSUE FAILED | Invoice: ' . $tokenData['invoice_id'] . ' | ' . $railIssue['message']);
                }
            }

            // ============================================================
            // AUTO-ISSUE LOGIC IS HANDLED ABOVE (Lines 210-458)
            // This section previously had duplicate auto-issue code - removed to avoid conflicts
            // ============================================================

            // Record transaction ONLY for successful payments
            $transactionRecord = record_transaction(
                $tokenData,
                $data['transaction_id'] ?? null,
                'success',
                $data
            );

            $bookingData = $db->get(
                'bookings',
                '*',
                ['invoice_id' => $tokenData['invoice_id']]
            );

            // Trigger payment completed webhook (module-specific)
            $rawModule = $tokenData['module'] ?? 'stays';
            // Standardize module mapping for webhooks
            $webhookModule = strtolower($rawModule);
            if ($webhookModule === 'hotels') { $webhookModule = 'stays'; }
            if ($webhookModule === 'amadeus') { $webhookModule = 'flights'; }
            if ($webhookModule === 'cartrawler') { $webhookModule = 'cars'; }
            if ($webhookModule === 'cars') { $webhookModule = 'cars'; }
            if ($webhookModule === 'kikoto') { $webhookModule = 'ferries'; }
            // Prefer module_type over module name for webhook routing
            $moduleTypeForWebhook = strtolower($tokenData['module_type'] ?? '');
            if ($moduleTypeForWebhook && file_exists(__DIR__ . '/../webhooks/' . $moduleTypeForWebhook . '/payment.php')) {
                $webhookModule = $moduleTypeForWebhook;
            }
            
            $webhookName = $webhookModule . '/payment';
            $eventLabel = $webhookModule . '.payment.completed';
            
            triggerWebhook($webhookName, $eventLabel, [
                'invoice_id' => $tokenData['invoice_id'],
                'booking_id' => $bookingData['id'] ?? null,
                'transaction_id' => $data['transaction_id'] ?? null,
                'amount' => $tokenData['amount'] ?? 0,
                'currency' => $tokenData['currency'] ?? 'USD',
                'payment_gateway' => $data['gateway'] ?? '',
                'user_id' => $bookingData['user_id'] ?? null,
                'customer_email' => $bookingData['email'] ?? '',
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            // Clear token
            clear_payment_token($token);

            // Construct proper invoice URL based on module type
            $moduleType = $tokenData['module_type'] ?? 'flights';
            $invoiceUrl = root . 'invoice/';

            switch ($moduleType) {
                case 'tours':
                    $invoiceUrl = root . 'invoice/tours/';
                    break;
                case 'flights':
                    $invoiceUrl = root . 'invoice/flights/';
                    break;
                case 'stays':
                    $invoiceUrl = root . 'invoice/stays/';
                    break;
                case 'cars':
                    $invoiceUrl = root . 'invoice/cars/';
                    break;
                case 'visa':
                    $invoiceUrl = root . 'invoice/visa/';
                    break;
                case 'esim':
                    $invoiceUrl = root . 'invoice/esim/';
                    break;
                case 'ferries':
                    $invoiceUrl = root . 'invoice/ferries/';
                    break;
                case 'rail':
                    $invoiceUrl = root . 'invoice/rail/';
                    break;
                case 'ai_trip':
                    $invoiceUrl = root . 'invoice/ai_trip/';
                    break;
            }

            return [
                'success'       => true,
                'message'       => 'Payment successful',
                'redirect_url'  => $invoiceUrl . $tokenData['invoice_id'],
                'booking'       => $bookingData,
                'transaction'   => $transactionRecord
            ];
        }

        // ============================================================
        // HANDLE FAILED / CANCELLED PAYMENT
        // ============================================================

        // Clear token immediately to prevent reuse/tampering
        clear_payment_token($token);

        $bookingData = $db->get(
            'bookings',
            '*',
            ['invoice_id' => $tokenData['invoice_id']]
        );

        // Trigger payment failed/cancelled webhook
        $module = $tokenData['module'] ?? 'stays';
        $webhookName = $module . '/payment';
        $eventName = $action === 'cancelled'
            ? $module . '.payment.cancelled'
            : $module . '.payment.failed';

        triggerWebhook($webhookName, $eventName, [
            'invoice_id' => $tokenData['invoice_id'],
            'booking_id' => $bookingData['id'] ?? null,
            'amount' => $tokenData['amount'] ?? 0,
            'currency' => $tokenData['currency'] ?? 'USD',
            'payment_gateway' => $data['gateway'] ?? '',
            'action' => $action,
            'user_id' => $bookingData['user_id'] ?? null,
            'customer_email' => $bookingData['email'] ?? '',
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        // Construct proper invoice URL based on module type
        $moduleType = $tokenData['module_type'] ?? 'flights';
        $invoiceUrl = root . 'invoice/';

        switch ($moduleType) {
            case 'tours':
                $invoiceUrl = root . 'invoice/tours/';
                break;
            case 'flights':
                $invoiceUrl = root . 'invoice/flights/';
                break;
            case 'stays':
                $invoiceUrl = root . 'invoice/stays/';
                break;
            case 'cars':
                $invoiceUrl = root . 'invoice/cars/';
                break;
            case 'visa':
                $invoiceUrl = root . 'invoice/visa/';
                break;
            case 'esim':
                $invoiceUrl = root . 'invoice/esim/';
                break;
            case 'ai_trip':
                $invoiceUrl = root . 'invoice/ai_trip/';
                break;
        }

        return [
            'success'      => false,
            'message'      => 'Payment ' . $action,
            'redirect_url' => $invoiceUrl . $tokenData['invoice_id'],
            'booking'      => $bookingData,
            'transaction' => null
        ];

    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Get booking gateway ID from multiple possible fields
 */
function get_booking_gateway_id($booking)
{
    $possibleFields = ['payment_gateway_id', 'gateway_id', 'payment_method_id', 'payment_gateway'];

    foreach ($possibleFields as $field) {
        if (isset($booking[$field]) && !empty($booking[$field])) {
            return $booking[$field];
        }
    }

    return null;
}

/**
 * Create secure payment token
 */
function create_payment_token($booking, $gateway)
{
    $tokenData = [
        'invoice_id' => $booking['invoice_id'],
        'booking_id' => $booking['id'],
        'amount' => $booking['price_markup'],
        'currency' => $booking['currency_markup'],
        'gateway_id' => $gateway['id'],
        'gateway_name' => $gateway['name'],
        'client_email' => $booking['email'],
        'module' => $booking['module'] ?? $booking['module_type'] ?? 'stays',
        'module_type' => $booking['module_type'] ?? 'stays',
        'timestamp' => time(),
        'expires' => time() + (30 * 60) // 30 minutes
    ];

    // Create token
    $token = 'pay_' . uniqid() . '_' . time();

    // Store in session
    $_SESSION['payment_tokens'][$token] = $tokenData;

    return $token;
}

/**
 * Verify payment token
 */
function verify_payment_token($token)
{
    if (!isset($_SESSION['payment_tokens'][$token])) {
        return false;
    }

    $tokenData = $_SESSION['payment_tokens'][$token];

    // Check expiration
    if (time() > $tokenData['expires']) {
        unset($_SESSION['payment_tokens'][$token]);
        return false;
    }

    return true;
}

/**
 * Get token data
 */
function get_token_data($token)
{
    return $_SESSION['payment_tokens'][$token] ?? null;
}

/**
 * Record transaction in database
 */
function record_transaction($tokenData, $transactionId, $status, $gatewayData = [], $errorMessage = '')
{
    global $db;

    // Determine transaction type based on gateway
    $gatewayId = $tokenData['gateway_id'] ?? null;
    $transactionType = null;

    if ($gatewayId) {
        $gateway = $db->get('payment_gateways', ['type', 'name'], ['id' => $gatewayId]);
        if ($gateway && $gateway['type'] === 'internal_wallet') {
            $transactionType = 'debit';

            // Additional handling for credits: add debit record to credits table
            if (stripos($gateway['name'], 'credit') !== false) {
                $userId = $tokenData['user_id'] ?? ($_SESSION['user_id'] ?? null);
                if ($userId) {
                    $db->insert('credits', [
                        'user_id' => $userId,
                        'type' => 'debit',
                        'credits' => $tokenData['amount'],
                        'currency' => $tokenData['currency'],
                        'description' => 'Payment for Invoice ' . $tokenData['invoice_id'] . ' via Credits',
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                }
            }
        }
    }

    $db->insert('transactions', [
        'invoice_id' => $tokenData['invoice_id'],
        // 'booking_id' => $tokenData['booking_id'],
        'amount' => $tokenData['amount'],
        'currency' => $tokenData['currency'],
        'gateway_id' => $tokenData['gateway_id'],
        // 'gateway_name' => $tokenData['gateway_name'],
        'trx_id' => $transactionId,
        'type' => $transactionType,
        'status' => $status,
        'description' => 'Payment for Invoice ' . $tokenData['invoice_id'], // Invoice ID in description
        'gateway_response' => json_encode($gatewayData),
        'error_message' => $errorMessage,
        'client_email' => $tokenData['client_email'],
        'user_id' => $tokenData['user_id'] ?? ($_SESSION['user_id'] ?? null),
        'created_by' => $tokenData['user_id'] ?? ($_SESSION['user_id'] ?? null),
        'created_at' => date('Y-m-d H:i:s')
    ]);

    $insertId = $db->id();
    if ($insertId) {
        return $db->get('transactions', '*', ['id' => $insertId]);
    }

    return null;
}

/**
 * Clear payment token
 */
function clear_payment_token($token)
{
    unset($_SESSION['payment_tokens'][$token]);
}

/**
 * Log payment transaction to database
 * Creates secure hash and stores all payment data
 * @return array ['success' => bool, 'hash' => string, 'message' => string]
 */
function log_payment_transaction($booking, $gateway, $token)
{
    global $db;

    // Get user IP address
    $userIp = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'unknown';

    // Check attempt limit (max 3 attempts per IP + invoice)
    $attemptCount = $db->count('logs_transactions', [
        'invoice_id' => $booking['invoice_id'],
        'ip' => $userIp
    ]);

    if ($attemptCount >= 3) {
        return [
            'success' => false,
            'message' => 'Payment attempt limit reached. You have tried more than 3 times. Please contact us for assistance.',
            'attempts' => $attemptCount
        ];
    }

    // Generate secure unique hash (32 characters)
    $hash = bin2hex(random_bytes(16));

    // Prepare transaction data
    $transactionData = [
        'invoice_id' => $booking['invoice_id'],
        'booking_id' => $booking['id'] ?? null,
        'amount' => $booking['price_markup'],
        'currency' => $booking['currency_markup'],
        'gateway_id' => $gateway['id'],
        'gateway_name' => $gateway['name'],
        'customer_email' => $booking['email'],
        'customer_name' => ($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''),
        'module_type' => $booking['module_type'] ?? 'stays',
        'module' => $booking['module'] ?? 'stays',
        'token' => $token,
        'user_id' => $booking['user_id'] ?? null,
        'booking_data' => $booking,
        'gateway_config' => [
            'id' => $gateway['id'],
            'name' => $gateway['name'],
            'logo' => $gateway['logo'] ?? null
        ],
        'created_at' => date('Y-m-d H:i:s'),
        'ip_address' => $userIp
    ];

    // Insert into logs_transactions
    $inserted = $db->insert('logs_transactions', [
        'invoice_id' => $booking['invoice_id'],
        'hash' => $hash,
        'data' => json_encode($transactionData),
        'ip' => $userIp,
        'created_at' => date('Y-m-d H:i:s')
    ]);

    if ($inserted) {
        return [
            'success' => true,
            'hash' => $hash,
            'message' => 'Transaction logged successfully',
            'attempts' => $attemptCount + 1
        ];
    }

    return [
        'success' => false,
        'message' => 'Failed to log transaction'
    ];
}

/**
 * Get payment transaction by hash
 */
function get_payment_transaction($hash)
{
    global $db;

    $transaction = $db->get('logs_transactions', '*', ['hash' => $hash]);

    if (!$transaction) {
        return null;
    }

    // Decode JSON data
    $transaction['data'] = json_decode($transaction['data'], true);

    return $transaction;
}

/**
 * Server-side payment verification for gateways with single callback URL.
 * Calls the gateway API to confirm whether the payment actually succeeded.
 *
 * @param string $gatewayName - Gateway name (paystack, cashfree, stripe, etc.)
 * @param array $data - Callback data (contains gateway_data with GET params)
 * @param array $tokenData - Payment token data
 * @param object $db - Database instance
 * @return string - Verified action: 'success', 'cancel', or 'failure'
 */
function verify_gateway_payment($gatewayName, $data, $tokenData, $db)
{
    $gatewayData = $data['gateway_data'] ?? $data ?? [];

    try {
        switch ($gatewayName) {

            // ============================================================
            // PAYSTACK VERIFICATION
            // GET https://api.paystack.co/transaction/verify/{reference}
            // ============================================================
            case 'paystack':
                $reference = $gatewayData['trxref'] ?? $gatewayData['reference'] ?? $gatewayData['transaction_id'] ?? '';
                if (empty($reference)) {
                    error_log("PAYSTACK VERIFY: No reference found in callback data");
                    return 'failure';
                }

                $gatewayId = $tokenData['gateway_id'] ?? null;
                if (!$gatewayId) return 'failure';

                $gateway = $db->get('payment_gateways', '*', ['id' => $gatewayId]);
                if (!$gateway) return 'failure';

                $secretKey = $gateway['c1'] ?? '';
                if (empty($secretKey)) {
                    error_log("PAYSTACK VERIFY: Secret key not configured");
                    return 'failure';
                }

                $ch = curl_init('https://api.paystack.co/transaction/verify/' . urlencode($reference));
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => ["Authorization: Bearer {$secretKey}"],
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => true
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200) {
                    error_log("PAYSTACK VERIFY: HTTP {$httpCode} for reference {$reference}");
                    return 'failure';
                }

                $result = json_decode($response, true);
                $status = $result['data']['status'] ?? '';

                if ($status === 'success') {
                    // Store verified transaction ID
                    $data['transaction_id'] = $result['data']['reference'] ?? $reference;
                    return 'success';
                } elseif ($status === 'abandoned' || $status === 'cancelled') {
                    return 'cancel';
                } else {
                    error_log("PAYSTACK VERIFY: Payment status is '{$status}' for reference {$reference}");
                    return 'failure';
                }

            // ============================================================
            // CASHFREE VERIFICATION
            // GET https://api.cashfree.com/pg/orders/{order_id}
            // ============================================================
            case 'cashfree':
                $orderId = $gatewayData['transaction_id'] ?? $gatewayData['order_id'] ?? '';
                if (empty($orderId)) {
                    error_log("CASHFREE VERIFY: No order_id found in callback data");
                    return 'failure';
                }

                $gatewayId = $tokenData['gateway_id'] ?? null;
                if (!$gatewayId) return 'failure';

                $gateway = $db->get('payment_gateways', '*', ['id' => $gatewayId]);
                if (!$gateway) return 'failure';

                $appId = $gateway['c1'] ?? '';
                $secretKey = $gateway['c2'] ?? '';
                if (empty($appId) || empty($secretKey)) {
                    error_log("CASHFREE VERIFY: Credentials not configured");
                    return 'failure';
                }

                $isDevMode = !empty($gateway['dev_mode']);
                $baseUrl = $isDevMode
                    ? 'https://sandbox.cashfree.com/pg'
                    : 'https://api.cashfree.com/pg';

                $ch = curl_init($baseUrl . '/orders/' . urlencode($orderId));
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'x-api-version: 2023-08-01',
                        'x-client-id: ' . $appId,
                        'x-client-secret: ' . $secretKey,
                    ],
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => true
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200) {
                    error_log("CASHFREE VERIFY: HTTP {$httpCode} for order {$orderId}");
                    return 'failure';
                }

                $result = json_decode($response, true);
                $orderStatus = $result['order_status'] ?? '';

                if ($orderStatus === 'PAID') {
                    $data['transaction_id'] = $result['cf_order_id'] ?? $orderId;
                    return 'success';
                } elseif (in_array($orderStatus, ['EXPIRED', 'CANCELLED', 'VOID'])) {
                    return 'cancel';
                } else {
                    // ACTIVE means payment not completed yet
                    error_log("CASHFREE VERIFY: Order status is '{$orderStatus}' for order {$orderId}");
                    return 'failure';
                }

            // ============================================================
            // STRIPE VERIFICATION
            // GET session status from Stripe API
            // ============================================================
            case 'stripe':
                // SECURITY: Stripe CAN be verified via the Checkout Session API.
                // Never trust the browser redirect. If we cannot verify (no
                // session id / no key / API unreachable), return 'pending'
                // (booking not marked paid) instead of the old 'success'.
                $sessionId = $gatewayData['session_id'] ?? $gatewayData['transaction_id'] ?? '';
                if (empty($sessionId)) {
                    return 'pending';
                }

                $gatewayId = $tokenData['gateway_id'] ?? null;
                if (!$gatewayId) return 'pending';

                $gateway = $db->get('payment_gateways', '*', ['id' => $gatewayId]);
                if (!$gateway) return 'pending';

                $secretKey = $gateway['c2'] ?? '';
                if (empty($secretKey)) return 'pending';

                $ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . urlencode($sessionId));
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_USERPWD => $secretKey . ':',
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => true
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200) return 'pending'; // could not verify — do NOT confirm

                $session = json_decode($response, true);
                $paymentStatus = $session['payment_status'] ?? '';

                if ($paymentStatus === 'paid') {
                    return 'success';
                } elseif ($paymentStatus === 'unpaid') {
                    return 'cancel';
                } else {
                    return 'failure';
                }

            // ============================================================
            // ADYEN VERIFICATION (Pay by Link)
            // GET /paymentLinks/{id} — status 'completed' means paid.
            // ============================================================
            case 'adyen':
                // SECURITY: Adyen is confirmed via the paymentLinks API and/or
                // the signed webhook (see paymentAdyenRoutes.php). Never trust
                // the browser redirect: if we cannot verify here, return
                // 'pending' — the signed webhook will finalise it.
                $linkId    = $tokenData['adyen_link_id'] ?? ($gatewayData['adyen_link'] ?? '');
                $gatewayId = $tokenData['gateway_id'] ?? null;
                if (empty($linkId) || !$gatewayId) {
                    return 'pending';
                }

                $gateway = $db->get('payment_gateways', '*', ['id' => $gatewayId]);
                if (!$gateway) {
                    return 'pending';
                }

                require_once __DIR__ . '/adyen.php';
                $cfg = adyen_config($gateway);
                if ($cfg['api_key'] === '') {
                    return 'pending';
                }

                $resp = adyen_api_request($cfg, 'GET', 'paymentLinks/' . rawurlencode($linkId));
                if ($resp['http_code'] !== 200 || !is_array($resp['json'])) {
                    error_log("ADYEN VERIFY | link {$linkId} | HTTP {$resp['http_code']}");
                    return 'failure';
                }

                $status = strtolower((string) ($resp['json']['status'] ?? ''));
                if ($status === 'completed') {
                    return 'success';
                }
                if (in_array($status, ['expired', 'cancelled', 'canceled'], true)) {
                    return 'cancel';
                }
                // 'active' / 'paymentPending' — not paid yet; don't confirm.
                return 'cancel';

            // ============================================================
            // XMONEY (UTRUST) VERIFICATION
            // ============================================================
            case 'xmoney':
                // SECURITY: xMoney confirms via its webhook (app/webhooks/xmoney.php),
                // not the browser redirect. Do NOT confirm on the return URL —
                // return 'pending' and let the signed webhook finalise it.
                return 'pending';

            // ============================================================
            // OTHER GATEWAYS (PayPal, Flutterwave, M-Pesa, Coinsbuy, Fawaterak…)
            // SECURITY: the browser return URL is attacker-controllable, so it
            // must NOT mark a booking paid. These gateways confirm via their own
            // webhook / server callback; until that arrives the booking stays
            // 'pending'. (Previously this returned 'success', which allowed a
            // buyer to self-confirm without paying.)
            // ============================================================
            default:
                return 'pending';
        }

    } catch (Exception $e) {
        error_log("PAYMENT VERIFICATION ERROR | Gateway: {$gatewayName} | Error: " . $e->getMessage());
        // On verification failure, default to failure to be safe
        return 'failure';
    }
}

/**
 * Get gateway configuration helper
 */
function get_gateway_config($gateway)
{
    return [
        'api_key' => $gateway['c1'] ?? '',
        'secret_key' => $gateway['c2'] ?? '',
        'username' => $gateway['c3'] ?? '',
        'password' => $gateway['c4'] ?? '',
        'additional_1' => $gateway['c5'] ?? '',
        'additional_2' => $gateway['c6'] ?? '',
        'dev_mode' => $gateway['dev_mode'] ?? 0,
        'environment' => $gateway['env'] ?? 'test'
    ];
}

/**
 * Format amount for different gateways
 */
function format_gateway_amount($amount, $inCents = true)
{
    if ($inCents) {
        return intval($amount * 100); // For Stripe, PayPal
    }
    return floatval($amount); // For other gateways
}

/**
 * Render payment button helper
 */
function render_payment_button($gateway, $booking, $onclick = '', $customText = '')
{
    $config = get_gateway_config($gateway);
    $amount = number_format($booking['price_markup'], 2);
    $currency = $booking['currency_markup'];
    $buttonText = $customText ?: 'Pay Now ' . $currency . ' ' . $amount;

    $html = '<div class="payment-container" style="text-align: center; padding: 20px;">';

    // Show test credentials if in dev mode
    if ($config['dev_mode']) {
        $html .= '<div style="background: #f0f0f0; padding: 10px; margin-bottom: 20px; border-radius: 5px;">';
        $html .= '<p><strong>Test Mode - Use These Credentials:</strong></p>';
        if ($config['username']) {
            $html .= '<p>Email: ' . $config['username'] . '</p>';
        }
        if ($config['password']) {
            $html .= '<p>Password: ' . $config['password'] . '</p>';
        }
        $html .= '</div>';
    }

    $html .= '<button type="button" onclick="' . $onclick . '" ';
    $html .= 'style="background: #5469d4; color: white; padding: 15px 30px; border: none; border-radius: 5px; font-size: 16px; cursor: pointer;">';
    $html .= $buttonText;
    $html .= '</button>';
    $html .= '</div>';

    return $html;
}

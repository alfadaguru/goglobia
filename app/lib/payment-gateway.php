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

        // PAYMENT RULE server-side guard (docs/MONEY-WALLET-AUDIT.md §A.3): an
        // AGENT may pay ONLY from their wallet (an internal_wallet gateway). A
        // customer may use wallet or any gateway; a guest may use any non-wallet
        // gateway. This cannot be bypassed by POSTing a card gateway_id because
        // it is enforced here, not just in the chooser UI.
        if (function_exists('payment_gateway_allowed_for_actor')
            && !payment_gateway_allowed_for_actor($db, $gateway)) {
            if (function_exists('payment_actor_is_agent') && payment_actor_is_agent($db)) {
                return ['success' => false, 'message' => 'Agents can only pay from their wallet. Please top up your wallet and pay from it.'];
            }
            return ['success' => false, 'message' => 'This payment method is not available for your account.'];
        }

        // CURRENCY ROUTING server-side guard (step 2): the external gateway must be
        // valid for the booking's currency — NGN -> Paystack, else -> Stripe. This
        // cannot be bypassed by POSTing a mismatched gateway_id because it is
        // enforced here, not just in the chooser UI. Wallet gateways pass (they
        // are currency-agnostic). Uses the BOOKING currency (the amount actually
        // charged), not the session, so it is correct even in async contexts.
        if (function_exists('payment_gateway_allowed_for_currency')) {
            $payCurrency = strtoupper(trim((string) ($booking['currency_markup'] ?? '')));
            if ($payCurrency !== '' && !payment_gateway_allowed_for_currency($db, $gateway, $payCurrency)) {
                return ['success' => false, 'message' => 'This payment method does not support ' . $payCurrency . ' payments. Please choose the payment method for your currency.'];
            }
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

        // Create legacy POST format for backward compatibility. Charge the amount
        // DUE NOW (deposit/installment-aware for umrah), not always the full total.
        $chargeNow = payment_amount_due($booking, $db);
        $legacyPayload = [
            'booking_ref_no' => $booking['ref'] ?? $booking['invoice_id'],
            'invoice_id' => $booking['invoice_id'],
            'client_email' => $booking['email'],
            'price' => $chargeNow,
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

                        // Umrah: settle installment + price-lock even on dev-mode mismatch (money collected).
                        if (($booking['module_type'] ?? '') === 'umrah' && function_exists('umrah_settle_payment')) {
                            umrah_settle_payment($db, (string) $tokenData['invoice_id'],
                                (float) ($tokenData['amount'] ?? ($booking['price_markup'] ?? 0)),
                                (string) ($tokenData['currency'] ?? 'NGN'),
                                (string) ($data['transaction_id'] ?? ''));
                        }

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
                        // Finding E: sign this server-side loopback so the module's
                        // supplier_action_guard accepts it (anonymous callers can't).
                        $__internalToken = function_exists('supplier_internal_token')
                            ? supplier_internal_token((string) $tokenData['invoice_id'])
                            : '';
                        $postData['_internal_token'] = $__internalToken;

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
            // UMRAH: settle the installment + price-lock on cleared payment.
            // Idempotent (keyed on invoice+txn). Confirms the umrah_booking,
            // marks the installment paid, consumes the inventory hold and locks
            // the price. Runs only for umrah bookings; no-op otherwise.
            // ============================================================
            if (($booking['module_type'] ?? '') === 'umrah' && function_exists('umrah_settle_payment')) {
                umrah_settle_payment(
                    $db,
                    (string) $tokenData['invoice_id'],
                    (float) ($tokenData['amount'] ?? ($booking['price_markup'] ?? 0)),
                    (string) ($tokenData['currency'] ?? ($booking['currency_markup'] ?? 'NGN')),
                    (string) ($data['transaction_id'] ?? '')
                );
            }

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
 * The amount to CHARGE NOW for a booking (audit M7 / deposit collection).
 * For most modules this is the full contract price (bookings.price_markup). For
 * umrah bookings on an installment plan it is the NEXT pending/overdue
 * installment (the deposit first, then the balance) — so the deposit schedule is
 * actually collected instead of always charging 100% upfront. Falls back to the
 * full price_markup whenever no pending installment is found or on any error.
 */
function payment_amount_due($booking, $db)
{
    $full = (float) ($booking['price_markup'] ?? 0);
    $module = strtolower((string) ($booking['module'] ?? $booking['module_type'] ?? ''));
    if ($module !== 'umrah') { return $full; }
    try {
        $ub = $db->get('umrah_bookings', ['id'], ['invoice_id' => $booking['invoice_id']]);
        if (!$ub) { return $full; }
        $next = $db->get('umrah_installments', ['amount'], [
            'umrah_booking_id' => (int) $ub['id'],
            'status' => ['pending', 'overdue'],
            'ORDER' => ['seq' => 'ASC'],
        ]);
        if ($next && (float) $next['amount'] > 0) {
            return round((float) $next['amount'], 2);
        }
    } catch (\Throwable $e) {
        error_log('payment_amount_due: ' . $e->getMessage());
    }
    return $full;
}

/**
 * Create secure payment token
 */
function create_payment_token($booking, $gateway)
{
    global $db;
    // Charge the amount DUE NOW (deposit/installment aware), not always the full
    // total. The settlement engine allocates this across installments (audit M7).
    $chargeNow = function_exists('payment_amount_due') ? payment_amount_due($booking, $db) : ((float) $booking['price_markup']);

    $tokenData = [
        'invoice_id' => $booking['invoice_id'],
        'booking_id' => $booking['id'],
        'amount' => $chargeNow,
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

    // Durable per-session record that THIS visitor created/owns this invoice.
    // Used by enforceInvoiceAccess() so a guest can still view their own invoice
    // after payment (once the short-lived payment token is cleared), while
    // strangers cannot enumerate other people's invoices (IDOR fix, C3).
    if (!empty($booking['invoice_id'])) {
        if (!isset($_SESSION['owned_invoices']) || !is_array($_SESSION['owned_invoices'])) {
            $_SESSION['owned_invoices'] = [];
        }
        if (!in_array($booking['invoice_id'], $_SESSION['owned_invoices'], true)) {
            $_SESSION['owned_invoices'][] = $booking['invoice_id'];
        }
    }

    // SPINE — born a money_transactions row for this GATEWAY payment (audit
    // §A.1/§A.2: ONE money record + a journey for EVERY movement, not only wallet
    // ones). Born 'pending' here, advanced to 'sent' at gateway handoff and to
    // 'success'/'failed' in record_transaction(). Idempotent per (invoice,gateway)
    // so re-issuing a token reuses the open transaction. No wallet is touched — an
    // external card/bank payment funds the booking directly, it does not move a
    // wallet balance, so there is no wallet_ledger row (correct by design).
    if (!empty($booking['invoice_id']) && function_exists('txn_create')) {
        try {
            $idem = 'GWPAY-' . $booking['invoice_id'] . '-' . ($gateway['id'] ?? 'gw');
            txn_create($db, [
                'user_id'         => (string) ($booking['user_id'] ?? ''),
                'direction'       => 'debit',
                'reason'          => 'booking_payment',
                'amount'          => $chargeNow,
                'currency'        => $booking['currency_markup'],
                'method'          => 'gateway',
                'gateway_id'      => (string) ($gateway['id'] ?? ''),
                'invoice_id'      => $booking['invoice_id'],
                'idempotency_key' => $idem,
                'description'     => 'Gateway payment for invoice ' . $booking['invoice_id'] . ' via ' . ($gateway['name'] ?? 'gateway'),
            ]);
            // Record the handoff step in the journey (best-effort).
            $mt = $db->get('money_transactions', ['id','status'], ['idempotency_key' => $idem]);
            if ($mt && $mt['status'] === 'pending' && function_exists('txn_advance')) {
                txn_advance($db, (int) $mt['id'], 'sent', 'sent to ' . ($gateway['name'] ?? 'gateway'));
            }
        } catch (\Throwable $e) { error_log('create_payment_token spine: ' . $e->getMessage()); }
    }

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

            // NOTE (audit money-integrity): the actual wallet debit for an
            // internal_wallet payment is now owned by the SPINE — the Credits /
            // Wallet gateway views call wallet_spend(), which writes the
            // money_transactions + wallet_ledger rows and mirrors the legacy
            // `credits` ledger for agents. This function used to ALSO insert a
            // `credits` debit here, which double-charged once the views were moved
            // onto the spine. That direct insert is removed on purpose; do not
            // re-add it. This function only records the legacy `transactions`
            // audit row below.
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

    // SPINE RESOLUTION (audit §A.2): advance the gateway money_transactions row
    // born in create_payment_token() to its final state so every card/bank payment
    // has a full journey (pending -> sent -> success|failed), not just the legacy
    // `transactions` row. No wallet movement (external payment). Idempotent: skip
    // if already resolved, so a re-fired callback never re-advances or duplicates.
    if (function_exists('txn_advance') && !empty($tokenData['invoice_id'])) {
        try {
            $gwId = (string) ($tokenData['gateway_id'] ?? 'gw');
            $idem = 'GWPAY-' . $tokenData['invoice_id'] . '-' . ($gwId !== '' ? $gwId : 'gw');
            $mt = $db->get('money_transactions', ['id', 'status'], ['idempotency_key' => $idem]);
            if ($mt && in_array($mt['status'], ['pending', 'sent'], true)) {
                $toStatus = $status === 'success' ? 'success' : ($status === 'cancel' ? 'cancelled' : 'failed');
                $note = $status === 'success'
                    ? 'gateway confirmed (' . ($transactionId ?: 'n/a') . ')'
                    : ('gateway ' . $status . ($errorMessage ? ': ' . mb_substr((string) $errorMessage, 0, 180) : ''));
                txn_advance($db, (int) $mt['id'], $toStatus, $note, $gatewayData,
                    $transactionId ? ['provider_trx_id' => $transactionId] : []);
            }
        } catch (\Throwable $e) { error_log('record_transaction spine resolve: ' . $e->getMessage()); }
    }

    // LOYALTY EARN (docs/MONEY-WALLET-AUDIT.md §C.4 step 5): on a SUCCESSFUL
    // payment, award points to the payer per the admin-set rate (customer vs
    // agent). Idempotent per invoice (keyed inside loyalty_earn_for_payment), so
    // a re-fired callback never double-earns. Never blocks the payment on error.
    if ($status === 'success' && function_exists('loyalty_earn_for_payment')) {
        $earnInv  = (string) ($tokenData['invoice_id'] ?? '');
        $earnAmt  = (float) ($tokenData['amount'] ?? 0);
        // Resolve the payer robustly. The standard gateway token does NOT carry
        // user_id (create_payment_token omits it), so fall back to the session,
        // then — for async/webhook contexts with no session (e.g. a re-fired
        // callback) — to the booking row itself via invoice_id. This guarantees
        // the right user earns regardless of how the success arrives.
        $earnUser = (string) ($tokenData['user_id'] ?? ($_SESSION['user_id'] ?? ''));
        if ($earnUser === '' && $earnInv !== '') {
            $earnUser = (string) ($db->get('bookings', 'user_id', ['invoice_id' => $earnInv]) ?: '');
        }
        if ($earnUser !== '' && $earnAmt > 0 && $earnInv !== '') {
            try { loyalty_earn_for_payment($db, $earnUser, $earnAmt, $earnInv); }
            catch (\Throwable $e) { error_log('loyalty earn: ' . $e->getMessage()); }
        }
    }

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
/**
 * SECURITY (audit P1): reconcile a gateway-reported paid amount + currency
 * against the expected charge from the trusted server-side token. Prevents
 * underpayment and cross-transaction confirmation (paying a cheap transaction
 * to confirm an expensive booking). All amounts are normalised to a float in
 * MAJOR units before comparison; a small epsilon absorbs rounding.
 *
 * @param float  $paidMajor      amount the gateway says was paid, in MAJOR units
 * @param string $paidCurrency   currency the gateway says was paid in
 * @param array  $tokenData      the trusted token (amount = price_markup, currency)
 * @return bool  true when the paid amount+currency match the expected charge
 */
function payment_amount_matches($paidMajor, $paidCurrency, $tokenData): bool
{
    $expected = (float) ($tokenData['amount'] ?? 0);
    $expectedCur = strtoupper(trim((string) ($tokenData['currency'] ?? '')));
    $paidCur = strtoupper(trim((string) $paidCurrency));

    // Currency must match when both are known.
    if ($expectedCur !== '' && $paidCur !== '' && $expectedCur !== $paidCur) {
        error_log("PAYMENT VERIFY: currency mismatch expected {$expectedCur} got {$paidCur}");
        return false;
    }
    // Paid amount must be at least the expected amount (allow overpay, block underpay).
    if ($expected > 0 && ($paidMajor + 0.01) < $expected) {
        error_log("PAYMENT VERIFY: amount mismatch expected {$expected} got {$paidMajor}");
        return false;
    }
    return true;
}

function verify_gateway_payment($gatewayName, &$data, $tokenData, $db)
{
    // $data is by REFERENCE (audit P5) so the gateway-VERIFIED transaction_id
    // (set in the success branches) is persisted by the caller instead of the
    // attacker-supplied $_GET value — required for correct refunds/reconciliation.
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
                    // SECURITY (P1): the verified transaction must be for THIS
                    // booking's expected amount + currency. Paystack amount is in
                    // kobo/minor units. Also bind the reference to this invoice
                    // (Paystack refs are 'PSK-{invoice_id}-{time}').
                    $paidMajor = ((float) ($result['data']['amount'] ?? 0)) / 100;
                    $paidCur   = $result['data']['currency'] ?? '';
                    if (!payment_amount_matches($paidMajor, $paidCur, $tokenData)) {
                        error_log("PAYSTACK VERIFY: amount/currency mismatch for ref {$reference}");
                        return 'failure';
                    }
                    $invId = (string) ($tokenData['invoice_id'] ?? '');
                    $refInv = $result['data']['reference'] ?? $reference;
                    if ($invId !== '' && strpos((string) $refInv, 'PSK-' . $invId . '-') !== 0
                        && strpos((string) $reference, 'PSK-' . $invId . '-') !== 0) {
                        error_log("PAYSTACK VERIFY: reference {$refInv} not bound to invoice {$invId}");
                        return 'failure';
                    }
                    // Persist the gateway-verified reference (P5, by-ref).
                    $data['transaction_id'] = $refInv;
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
                    // SECURITY (P1): amount/currency must match; order id is
                    // 'CF-{invoice_id}-{time}' so bind it to this invoice.
                    $paidMajor = (float) ($result['order_amount'] ?? 0);
                    $paidCur   = $result['order_currency'] ?? '';
                    if (!payment_amount_matches($paidMajor, $paidCur, $tokenData)) {
                        error_log("CASHFREE VERIFY: amount/currency mismatch for order {$orderId}");
                        return 'failure';
                    }
                    $invId = (string) ($tokenData['invoice_id'] ?? '');
                    if ($invId !== '' && strpos((string) $orderId, 'CF-' . $invId . '-') !== 0) {
                        error_log("CASHFREE VERIFY: order {$orderId} not bound to invoice {$invId}");
                        return 'failure';
                    }
                    // Persist the gateway-verified reference (P5, by-ref).
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
                    // SECURITY (P1): amount_total is in minor units; bind the
                    // session to this invoice via client_reference_id/metadata.
                    $paidMajor = ((float) ($session['amount_total'] ?? 0)) / 100;
                    $paidCur   = $session['currency'] ?? '';
                    if (!payment_amount_matches($paidMajor, $paidCur, $tokenData)) {
                        error_log("STRIPE VERIFY: amount/currency mismatch for session {$sessionId}");
                        return 'failure';
                    }
                    $invId = (string) ($tokenData['invoice_id'] ?? '');
                    $sessInv = (string) ($session['client_reference_id'] ?? ($session['metadata']['invoice_id'] ?? ''));
                    if ($invId !== '' && $sessInv !== $invId) {
                        error_log("STRIPE VERIFY: session {$sessionId} not bound to invoice {$invId} (got {$sessInv})");
                        return 'failure';
                    }
                    // Persist the gateway-verified reference: the PaymentIntent id
                    // (needed for Stripe refunds) if present, else the session id.
                    $data['transaction_id'] = $session['payment_intent'] ?? $sessionId;
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
            // PAYPAL VERIFICATION (Orders v2) — audit P86
            // GET /v2/checkout/orders/{id} with an OAuth token (client id c1 +
            // secret c2 from the DB). status COMPLETED + amount match = paid.
            // ============================================================
            case 'paypal':
                $orderId = $gatewayData['transaction_id'] ?? $gatewayData['order_id'] ?? '';
                $gatewayId = $tokenData['gateway_id'] ?? null;
                if (empty($orderId) || !$gatewayId) { return 'pending'; }
                $gateway = $db->get('payment_gateways', '*', ['id' => $gatewayId]);
                if (!$gateway) { return 'pending'; }
                $clientId = $gateway['c1'] ?? '';
                $secret   = $gateway['c2'] ?? '';
                if ($clientId === '' || $secret === '') { return 'pending'; }
                $base = !empty($gateway['dev_mode']) ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

                // OAuth token.
                $ch = curl_init($base . '/v1/oauth2/token');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_USERPWD => $clientId . ':' . $secret,
                    CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
                    CURLOPT_HTTPHEADER => ['Accept: application/json'],
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $tokResp = curl_exec($ch); $tokCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
                if ($tokCode !== 200) { error_log("PAYPAL VERIFY: oauth HTTP {$tokCode}"); return 'pending'; }
                $access = json_decode($tokResp, true)['access_token'] ?? '';
                if ($access === '') { return 'pending'; }

                // Fetch the order.
                $ch = curl_init($base . '/v2/checkout/orders/' . urlencode($orderId));
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access, 'Content-Type: application/json'],
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $ordResp = curl_exec($ch); $ordCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
                if ($ordCode !== 200) { error_log("PAYPAL VERIFY: order HTTP {$ordCode}"); return 'pending'; }
                $order = json_decode($ordResp, true);
                $ostatus = strtoupper((string) ($order['status'] ?? ''));
                if ($ostatus === 'COMPLETED' || $ostatus === 'APPROVED') {
                    $pu = $order['purchase_units'][0]['amount'] ?? [];
                    $paidMajor = (float) ($pu['value'] ?? 0);
                    $paidCur   = $pu['currency_code'] ?? '';
                    if (!payment_amount_matches($paidMajor, $paidCur, $tokenData)) {
                        error_log("PAYPAL VERIFY: amount/currency mismatch order {$orderId}");
                        return 'failure';
                    }
                    $data['transaction_id'] = $order['id'] ?? $orderId;
                    return 'success';
                }
                if (in_array($ostatus, ['VOIDED', 'CANCELLED'], true)) { return 'cancel'; }
                return 'pending';

            // ============================================================
            // FLUTTERWAVE VERIFICATION (v3) — audit P86
            // GET /v3/transactions/{id}/verify with secret key (c2 from DB).
            // status successful + amount + tx_ref bound to invoice = paid.
            // ============================================================
            case 'flutterwave':
                $txId  = $gatewayData['transaction_id'] ?? '';
                $txRef = $gatewayData['tx_ref'] ?? '';
                $gatewayId = $tokenData['gateway_id'] ?? null;
                if (empty($txId) || !$gatewayId) { return 'pending'; }
                $gateway = $db->get('payment_gateways', '*', ['id' => $gatewayId]);
                if (!$gateway) { return 'pending'; }
                $secret = $gateway['c2'] ?? $gateway['c1'] ?? '';
                if ($secret === '') { return 'pending'; }

                $ch = curl_init('https://api.flutterwave.com/v3/transactions/' . urlencode($txId) . '/verify');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $secret],
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $fResp = curl_exec($ch); $fCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
                if ($fCode !== 200) { error_log("FLUTTERWAVE VERIFY: HTTP {$fCode}"); return 'pending'; }
                $fj = json_decode($fResp, true);
                $fdata = $fj['data'] ?? [];
                $fstatus = strtolower((string) ($fdata['status'] ?? ''));
                if ($fstatus === 'successful') {
                    $paidMajor = (float) ($fdata['amount'] ?? 0);
                    $paidCur   = $fdata['currency'] ?? '';
                    if (!payment_amount_matches($paidMajor, $paidCur, $tokenData)) {
                        error_log("FLUTTERWAVE VERIFY: amount/currency mismatch txn {$txId}");
                        return 'failure';
                    }
                    // Bind tx_ref to this invoice (FLW-{invoice_id}-{time}).
                    $invId = (string) ($tokenData['invoice_id'] ?? '');
                    $seenRef = (string) ($fdata['tx_ref'] ?? $txRef);
                    if ($invId !== '' && strpos($seenRef, 'FLW-' . $invId . '-') !== 0) {
                        error_log("FLUTTERWAVE VERIFY: tx_ref {$seenRef} not bound to invoice {$invId}");
                        return 'failure';
                    }
                    $data['transaction_id'] = (string) ($fdata['id'] ?? $txId);
                    return 'success';
                }
                if (in_array($fstatus, ['cancelled', 'failed'], true)) { return 'cancel'; }
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
    // NOTE (audit): the generic label names (api_key/secret_key/username/password)
    // do NOT reflect the per-gateway meaning of c1..c6 — each gateway view reads
    // the specific c-columns it needs (see the per-gateway views). The extra
    // aliases below expose c3..c6 under the names some views expect (e.g. M-Pesa)
    // so every gateway is configurable purely from the DB row, no hardcoding.
    return [
        'api_key' => $gateway['c1'] ?? '',
        'secret_key' => $gateway['c2'] ?? '',
        'username' => $gateway['c3'] ?? '',
        'password' => $gateway['c4'] ?? '',
        'additional_1' => $gateway['c5'] ?? '',
        'additional_2' => $gateway['c6'] ?? '',
        // M-Pesa (audit P87): map the required fields onto DB columns so it can
        // actually be configured (c3=provider code, c4=market, c5=api url,
        // c6=country code). Only set when non-empty so the view's own defaults
        // (?? 'TZN' etc.) still apply. Harmless for other gateways.
        'service_provider_code' => ($gateway['c3'] ?? '') !== '' ? $gateway['c3'] : null,
        'market' => ($gateway['c4'] ?? '') !== '' ? $gateway['c4'] : null,
        'api_url' => ($gateway['c5'] ?? '') !== '' ? $gateway['c5'] : null,
        'country_code' => ($gateway['c6'] ?? '') !== '' ? $gateway['c6'] : null,
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

// ============================================================================
// SHARED GATEWAY REFUND
// ----------------------------------------------------------------------------
// Reverses the customer's charge via the payment gateway. Closes the platform-
// wide gap (docs/MODULES.md §8.1(1)) where provider refund.php files only
// flipped payment_status='refunded' in the DB and never returned money.
//
// Mirrors verify_gateway_payment()'s per-gateway switch. Real refund API calls
// are implemented for the gateways that ship real API code (Paystack, Stripe).
// Every other gateway returns ['status'=>'unsupported'] — HONEST: the caller
// must not report the customer as refunded when no money actually moved.
//
// Amount defaults to the amount charged (bookings.price_markup). Callers should
// guard on payment_status='refunded' for idempotency before calling.
//
// Returns:
//   ['status'=>'refunded','reference'=>..,'amount'=>..,'gateway'=>..]  success
//   ['status'=>'failed','message'=>..,'gateway'=>..]                   API said no
//   ['status'=>'unsupported','message'=>..,'gateway'=>..]              no refund API
// ============================================================================
if (!function_exists('refund_gateway_payment')) {
    function refund_gateway_payment($db, $booking, $amount = null, $reason = 'Booking cancelled')
    {
        $gatewayName = strtolower(trim((string) ($booking['payment_gateway'] ?? '')));
        $txnRef      = trim((string) ($booking['transaction_id'] ?? ''));
        $paidAmount  = (float) ($booking['price_markup'] ?? 0);
        $currency    = strtoupper(trim((string) ($booking['currency_markup'] ?? 'USD')));
        $refundAmt   = ($amount !== null) ? (float) $amount : $paidAmount;

        $gateway = $db->get('payment_gateways', '*', ['name[~]' => $gatewayName]);
        if (!$gateway) {
            return ['status' => 'unsupported', 'message' => "Gateway '{$gatewayName}' not found/configured", 'gateway' => $gatewayName];
        }
        if ($refundAmt <= 0) {
            return ['status' => 'failed', 'message' => 'Refund amount is zero', 'gateway' => $gatewayName];
        }

        try {
            switch ($gatewayName) {

                // ---- PAYSTACK: POST https://api.paystack.co/refund ----
                case 'paystack':
                    if ($txnRef === '') {
                        return ['status' => 'failed', 'message' => 'No transaction reference to refund', 'gateway' => 'paystack'];
                    }
                    $secret = trim((string) ($gateway['c1'] ?? ''));
                    if ($secret === '') {
                        return ['status' => 'unsupported', 'message' => 'Paystack secret key not configured', 'gateway' => 'paystack'];
                    }
                    $minor = (int) round($refundAmt * 100); // kobo/cents
                    $ch = curl_init('https://api.paystack.co/refund');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => http_build_query([
                            'transaction'   => $txnRef,
                            'amount'        => $minor,
                            'currency'      => $currency,
                            'merchant_note' => $reason,
                        ]),
                        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$secret}"],
                        CURLOPT_TIMEOUT => 30,
                        CURLOPT_CONNECTTIMEOUT => 15,
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                    ]);
                    $resp = curl_exec($ch);
                    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    $j = json_decode((string) $resp, true);
                    if ($code >= 200 && $code < 300 && !empty($j['status'])) {
                        return ['status' => 'refunded', 'reference' => $j['data']['id'] ?? $txnRef, 'amount' => $refundAmt, 'gateway' => 'paystack'];
                    }
                    return ['status' => 'failed', 'message' => $j['message'] ?? "Paystack refund HTTP {$code}", 'gateway' => 'paystack'];

                // ---- STRIPE: POST https://api.stripe.com/v1/refunds ----
                case 'stripe':
                    $secret = trim((string) ($gateway['c2'] ?? ''));
                    if ($secret === '') {
                        return ['status' => 'unsupported', 'message' => 'Stripe secret key not configured', 'gateway' => 'stripe'];
                    }
                    if ($txnRef === '') {
                        return ['status' => 'failed', 'message' => 'No Stripe reference to refund', 'gateway' => 'stripe'];
                    }
                    $paymentIntent = $txnRef;
                    if (stripos($txnRef, 'cs_') === 0) { // Checkout Session → resolve PaymentIntent
                        $chs = curl_init('https://api.stripe.com/v1/checkout/sessions/' . urlencode($txnRef));
                        curl_setopt_array($chs, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_USERPWD => $secret . ':',
                            CURLOPT_TIMEOUT => 30,
                            CURLOPT_SSL_VERIFYPEER => true,
                            CURLOPT_SSL_VERIFYHOST => 2,
                        ]);
                        $sResp = curl_exec($chs);
                        curl_close($chs);
                        $sJson = json_decode((string) $sResp, true);
                        $paymentIntent = $sJson['payment_intent'] ?? '';
                        if ($paymentIntent === '') {
                            return ['status' => 'failed', 'message' => 'Could not resolve Stripe PaymentIntent from session', 'gateway' => 'stripe'];
                        }
                    }
                    $minor = (int) round($refundAmt * 100);
                    $ch = curl_init('https://api.stripe.com/v1/refunds');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_USERPWD => $secret . ':',
                        CURLOPT_POSTFIELDS => http_build_query(['payment_intent' => $paymentIntent, 'amount' => $minor]),
                        CURLOPT_TIMEOUT => 30,
                        CURLOPT_CONNECTTIMEOUT => 15,
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                    ]);
                    $resp = curl_exec($ch);
                    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    $j = json_decode((string) $resp, true);
                    if ($code >= 200 && $code < 300 && !empty($j['id'])) {
                        return ['status' => 'refunded', 'reference' => $j['id'], 'amount' => $refundAmt, 'gateway' => 'stripe'];
                    }
                    return ['status' => 'failed', 'message' => $j['error']['message'] ?? "Stripe refund HTTP {$code}", 'gateway' => 'stripe'];

                // ---- Internal ledger gateways: reversal is an in-app wallet credit ----
                case 'wallet':
                case 'wallet balance':
                case 'wallet_balance':
                case 'credits':
                    // Internal wallet payment → reverse it through the SPINE
                    // (audit money-integrity). wallet_refund() credits the CORRECT
                    // store for the user's kind — a CUSTOMER's money lives in
                    // users.balance (mirrored from wallets), an AGENT's in the
                    // `credits` ledger — writes a money_transactions + journey +
                    // wallet_ledger record, and is idempotent per invoice so a
                    // re-fired refund never double-credits. The OLD code posted a
                    // raw `credits` row for BOTH kinds, so a customer's refund went
                    // to a ledger they never spend from — they never got the money.
                    $walletUserId = (string) ($booking['user_id'] ?? '');
                    $invoiceRef   = (string) ($booking['invoice_id'] ?? '');
                    if ($walletUserId === '') {
                        return ['status' => 'failed', 'message' => 'No wallet owner on booking', 'gateway' => $gatewayName];
                    }
                    if (!function_exists('wallet_refund')) {
                        $walletLib = __DIR__ . '/wallet.php';
                        if (file_exists($walletLib)) { require_once $walletLib; }
                    }
                    if (!function_exists('wallet_refund')) {
                        return ['status' => 'failed', 'message' => 'Wallet engine unavailable', 'gateway' => $gatewayName];
                    }
                    $rf = wallet_refund($db, $walletUserId, (float) $refundAmt, (string) $currency, [
                        'reason'          => 'refund',
                        'invoice_id'      => $invoiceRef,
                        'ref_type'        => 'invoice',
                        'ref_id'          => $invoiceRef,
                        'idempotency_key' => 'GWREFUND-' . $invoiceRef,
                        'note'            => 'Refund ' . $invoiceRef . ' — ' . $reason,
                    ]);
                    if (empty($rf['ok'])) {
                        return ['status' => 'failed', 'message' => $rf['message'] ?? 'Wallet refund failed', 'gateway' => $gatewayName];
                    }
                    return ['status' => 'refunded', 'reference' => 'WALLET-REFUND-' . $invoiceRef, 'amount' => $refundAmt, 'gateway' => $gatewayName, 'already' => !empty($rf['already'])];

                // ---- No refund API implemented yet: be honest ----
                default:
                    return ['status' => 'unsupported', 'message' => "Automated refund is not implemented for gateway '{$gatewayName}'. Process it manually in the gateway dashboard.", 'gateway' => $gatewayName];
            }
        } catch (\Throwable $e) {
            error_log('refund_gateway_payment error (' . $gatewayName . '): ' . $e->getMessage());
            return ['status' => 'failed', 'message' => $e->getMessage(), 'gateway' => $gatewayName];
        }
    }
}

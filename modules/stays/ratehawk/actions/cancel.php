<?php
/**
 * RATEHAWK BOOKING CANCELLATION HANDLER
 * =====================================
 * ETG API v3 Post-Booking — Cancel booking
 * Endpoint: POST /hotel/order/cancel/
 *
 * Flow:
 * 1. Cancel booking (/hotel/order/cancel/)
 * 2. Retrieve bookings (/hotel/order/info/) — AFTER cancel to verify
 *    cancellation / modifications are in place
 *
 * Docs: status=ok on cancel means cancelled.
 * order/info is for post-booking details — NOT booking success confirmation.
 */

$router->post('/stays/ratehawk/cancel', function () use ($db) {

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
            'module' => 'ratehawk'
        ]);

        if (!$booking) {
            throw new Exception('Booking not found');
        }

        if (empty($booking['pnr'])) {
            throw new Exception('Booking has not been issued yet. Cannot cancel.');
        }

        if (in_array($booking['booking_status'], ['cancelled', 'voided'], true)) {
            throw new Exception('Booking is already cancelled');
        }

        $bookingData = json_decode($booking['booking_data'], true);
        if (!is_array($bookingData)) {
            throw new Exception('Invalid booking data');
        }

        $moduleData = $db->get('modules', '*', [
            'name' => 'ratehawk',
            'type' => 'stays'
        ]);

        if (!$moduleData) {
            throw new Exception('RateHawk module not configured');
        }

        $credentials = json_decode($moduleData['credentials'] ?? '{}', true);
        $keyId = trim($credentials['key_id'] ?? $moduleData['c1'] ?? '');
        $apiKey = trim($credentials['api_key'] ?? $moduleData['c3'] ?? '');

        if ($keyId === '' || $apiKey === '') {
            throw new Exception('RateHawk API credentials not configured');
        }

        $apiBaseUrl = rtrim(trim($moduleData['c4'] ?? ''), '/');
        if (stripos($apiBaseUrl, '/api/b2b/v3') === false) {
            $apiBaseUrl .= '/api/b2b/v3';
        }

        $partnerOrderId = $bookingData['ratehawk_partner_order_id']
            ?? $bookingData['partner_order_id']
            ?? $booking['pnr'];

        if (empty($partnerOrderId)) {
            throw new Exception('Partner order ID not found. Cannot cancel booking.');
        }

        // =====================================
        // STEP 1: CANCEL via /hotel/order/cancel/
        // =====================================
        $cancelRequest = [
            'partner_order_id' => $partnerOrderId
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiBaseUrl . '/hotel/order/cancel/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERPWD => $keyId . ':' . $apiKey,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($cancelRequest),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if (function_exists('ratehawk_log')) {
            ratehawk_log('cancel', 'cancel', $cancelRequest, $response, '', [
                'url' => $apiBaseUrl . '/hotel/order/cancel/',
                'method' => 'POST',
                'headers' => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Basic ***'],
                'response_headers' => ''
            ]);
        }

        if ($response === false) {
            error_log("RateHawk Cancel: cURL Error - {$curlError}");
            throw new Exception('Failed to connect to cancellation API: ' . $curlError);
        }

        $apiResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("RateHawk Cancel: Invalid JSON - HTTP {$httpCode}");
            throw new Exception('Received invalid response from cancellation API');
        }

        if ($httpCode !== 200 || ($apiResponse['status'] ?? '') !== 'ok') {
            $errorCode = $apiResponse['error'] ?? 'unknown_error';

            $db->update('bookings', [
                'error_response' => json_encode([
                    'error' => $errorCode,
                    'message' => 'Cancellation failed',
                    'response' => $apiResponse,
                    'timestamp' => date('Y-m-d H:i:s')
                ]),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $booking['id']]);

            error_log("RateHawk Cancel: API Error - {$errorCode}, HTTP {$httpCode}");

            $errorMessages = [
                'order_not_found' => 'Booking not found in supplier system (or not completed/rejected).',
                'order_not_cancellable' => 'This booking cannot be cancelled (stay started or non-refundable without permission).',
                'order_already_cancelled' => 'Booking has already been cancelled.',
                'sandbox_restriction' => 'Sandbox cannot cancel this hotel. Use the correct environment.',
                'lock' => 'Cancellation is locked due to duplicate requests. Wait and try again.',
                'unknown' => 'Cancellation failed due to an unknown supplier error.',
            ];

            $errorMessage = $errorMessages[$errorCode] ?? 'Cancellation failed. Please try again or contact support.';
            throw new Exception($errorMessage . ' (Error: ' . $errorCode . ')');
        }

        $cancelData = is_array($apiResponse['data'] ?? null) ? $apiResponse['data'] : [];

        // =====================================
        // STEP 2: VERIFY via /hotel/order/info/ (AFTER cancel)
        // Docs: wait briefly — order sync is async
        // =====================================
        sleep(8);

        // ETG requires ordering + pagination; partner_order_ids goes under search
        $infoRequest = [
            'ordering' => [
                'ordering_type' => 'desc',
                'ordering_by' => 'created_at',
            ],
            'pagination' => [
                'page_size' => '1',
                'page_number' => '1',
            ],
            'search' => [
                'partner_order_ids' => [(string) $partnerOrderId],
            ],
            'language' => 'en',
        ];

        $chInfo = curl_init();
        curl_setopt_array($chInfo, [
            CURLOPT_URL => $apiBaseUrl . '/hotel/order/info/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERPWD => $keyId . ':' . $apiKey,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($infoRequest),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $infoResponseRaw = curl_exec($chInfo);
        $infoHttpCode = (int) curl_getinfo($chInfo, CURLINFO_HTTP_CODE);
        $infoCurlError = curl_error($chInfo);

        if (function_exists('ratehawk_log')) {
            ratehawk_log('cancel', 'order_info', $infoRequest, $infoResponseRaw, '', [
                'url' => $apiBaseUrl . '/hotel/order/info/',
                'method' => 'POST',
                'headers' => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Basic ***'],
                'response_headers' => '',
            ]);
        }

        $orderInfo = null;
        $infoVerified = false;
        $infoError = null;

        if ($infoResponseRaw === false) {
            $infoError = 'Failed to connect to order info API: ' . $infoCurlError;
        } else {
            $infoResponse = json_decode($infoResponseRaw, true);
            if (!is_array($infoResponse)) {
                $infoError = 'Received invalid response from order info API';
            } elseif ($infoHttpCode !== 200 || ($infoResponse['status'] ?? '') !== 'ok') {
                $infoError = 'Order info failed (Error: ' . ($infoResponse['error'] ?? 'unknown_error') . ')';
            } else {
                $orders = $infoResponse['data']['orders'] ?? [];
                if (!is_array($orders) || empty($orders) || !is_array($orders[0] ?? null)) {
                    $infoError = 'Order info not available yet after cancel (ETG sync delay)';
                } else {
                    $orderInfo = $orders[0];
                    $infoVerified = true;
                }
            }
        }

        if ($infoVerified && $orderInfo) {
            $bookingData['ratehawk_order_info'] = $orderInfo;
            $bookingData['ratehawk_order_info_verified_at'] = date('Y-m-d H:i:s');
            $bookingData['ratehawk_supplier_status'] = $orderInfo['status'] ?? null;
            $bookingData['ratehawk_is_cancellable'] = !empty($orderInfo['is_cancellable']);

            $hcn = $orderInfo['hotel_data']['order_id'] ?? $orderInfo['hotel_order_id'] ?? null;
            if (!empty($hcn)) {
                $bookingData['ratehawk_hotel_confirmation'] = $hcn;
            }
            unset($bookingData['ratehawk_order_info_pending'], $bookingData['ratehawk_order_info_last_error']);
        } else {
            $bookingData['ratehawk_order_info_pending'] = true;
            $bookingData['ratehawk_order_info_last_error'] = $infoError
                ?? 'Order info not ready yet after cancel';
        }

        $db->update('bookings', [
            'booking_status' => 'cancelled',
            'cancellation_status' => 1,
            'booking_data' => json_encode($bookingData),
            'cancellation_response' => json_encode([
                'status' => 'cancelled',
                'confirmed' => true,
                'partner_order_id' => $partnerOrderId,
                'cancel_api' => $cancelData,
                'order_info_verified' => $infoVerified,
                'order_info' => $orderInfo,
                'cancelled_at' => date('Y-m-d H:i:s')
            ]),
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $booking['id']]);

        $message = $infoVerified
            ? 'Booking cancelled successfully. Order info verified (status: ' . ($orderInfo['status'] ?? 'n/a') . ').'
            : 'Booking cancelled successfully.';

        ob_end_clean();
        echo json_encode([
            'status' => true,
            'success' => true,
            'message' => $message,
            'data' => [
                'booking_id' => $booking['id'],
                'invoice_id' => $invoiceId,
                'partner_order_id' => $partnerOrderId,
                'cancelled_at' => date('Y-m-d H:i:s'),
                'status' => 'cancelled',
                'confirmed' => true,
                'order_info_verified' => $infoVerified,
                'supplier_status' => $orderInfo['status'] ?? null,
                'amount_payable' => $cancelData['amount_payable'] ?? ($orderInfo['amount_payable'] ?? null),
                'amount_refunded' => $cancelData['amount_refunded'] ?? ($orderInfo['amount_refunded'] ?? null),
                'order_info' => $orderInfo,
            ]
        ]);
    } catch (Exception $e) {
        if (isset($booking) && isset($booking['id'])) {
            $db->update('bookings', [
                'error_response' => json_encode([
                    'error' => 'cancellation_exception',
                    'message' => $e->getMessage(),
                    'timestamp' => date('Y-m-d H:i:s')
                ]),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $booking['id']]);
        }

        error_log('RateHawk Cancel Error: ' . $e->getMessage() . ' (Invoice: ' . ($invoiceId ?? 'N/A') . ')');

        ob_end_clean();
        http_response_code(400);
        echo json_encode([
            'status' => false,
            'success' => false,
            'error' => 'cancellation_error',
            'message' => $e->getMessage()
        ]);
    }

    exit;
});

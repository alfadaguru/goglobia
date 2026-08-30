<?php
/**
 * Void Ratehawk Booking
 * Endpoint: POST /modules/stays/ratehawk/void
 * Note: Ratehawk uses same cancel API for void operations
 */

$router->post('/stays/ratehawk/void', function() use ($db) {
    try {
        // Get invoice_id from request
        $input = json_decode(file_get_contents('php://input'), true);
        $invoiceId = $input['invoice_id'] ?? '';

        if (empty($invoiceId)) {
            echo json_encode([
                'success' => false,
                'message' => 'Missing invoice_id'
            ]);
            exit;
        }

        // Get booking from database
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);

        if (!$booking) {
            echo json_encode([
                'success' => false,
                'message' => 'Booking not found'
            ]);
            exit;
        }

        // Get Ratehawk credentials
        $module = $db->get('modules', '*', [
            'name' => 'ratehawk',
            'type' => 'stays'
        ]);

        if (!$module) {
            echo json_encode([
                'success' => false,
                'message' => 'Ratehawk module not configured'
            ]);
            exit;
        }

        // Check if dev_mode is enabled (test = 1, production = 0)
        $devMode = strtolower($module['dev_mode'] ?? '0');
        $isTestEnvironment = ($devMode === '1' || $devMode === 'test' || $devMode === 'on');

        // Read credentials (JSON or c1/c2/c3)
        $credentials = json_decode($module['credentials'] ?? '{}', true);
        $keyId = trim($credentials['key_id'] ?? $module['c1'] ?? '');
        $apiKey = trim($credentials['api_key'] ?? $module['c3'] ?? '');


        if (empty($keyId) || empty($apiKey)) {
            echo json_encode([
                'success' => false,
                'message' => 'Ratehawk credentials not configured'
            ]);
            exit;
        }

        // Get partner_order_id from booking_data (Issue stores ratehawk_partner_order_id)
        $bookingData = json_decode($booking['booking_data'], true);
        if (!is_array($bookingData)) {
            $bookingData = [];
        }
        $partnerOrderId = $bookingData['ratehawk_partner_order_id']
            ?? $bookingData['partner_order_id']
            ?? $booking['pnr']
            ?? '';

        if ($partnerOrderId === '') {
            echo json_encode([
                'success' => false,
                'message' => 'Partner order ID not found'
            ]);
            exit;
        }

        $apiBaseUrl = rtrim(trim($module['c4'] ?? ''), '/');
        if (stripos($apiBaseUrl, '/api/b2b/v3') === false) {
            $apiBaseUrl .= '/api/b2b/v3';
        }
        $voidRequest = [
            'partner_order_id' => $partnerOrderId
        ];

        error_log("RATEHAWK VOID: Voiding booking {$partnerOrderId}");

        // Official Cancel booking endpoint (same as cancel.php)
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
            CURLOPT_POSTFIELDS => json_encode($voidRequest),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if (function_exists('ratehawk_log')) {
            ratehawk_log('void', 'cancel', $voidRequest, $response, '', [
                'url' => $apiBaseUrl . '/hotel/order/cancel/',
                'method' => 'POST',
                'headers' => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Basic ***'],
                'response_headers' => ''
            ]);
        }

        if ($curlError) {
            error_log("RATEHAWK VOID: Curl error - {$curlError}");
            echo json_encode([
                'success' => false,
                'message' => 'Connection error: ' . $curlError
            ]);
            exit;
        }

        $apiResponse = json_decode($response, true);
        error_log("RATEHAWK VOID: API response - " . json_encode($apiResponse));

        // Check response — cancel API returns status=ok when cancelled
        if ($httpCode === 200 && ($apiResponse['status'] ?? '') === 'ok') {
            $db->update('bookings', [
                'booking_status' => 'voided',
                'cancellation_status' => 1,
                'cancellation_response' => json_encode([
                    'status' => 'voided',
                    'confirmed' => true,
                    'partner_order_id' => $partnerOrderId,
                    'cancel_api' => $apiResponse['data'] ?? null,
                    'voided_at' => date('Y-m-d H:i:s')
                ]),
                'error_response' => null,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $booking['id']]);

            echo json_encode([
                'success' => true,
                'status' => true,
                'message' => 'Booking voided successfully',
                'data' => $apiResponse['data'] ?? null
            ]);
        } else {
            $db->update('bookings', [
                'error_response' => json_encode($apiResponse),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $booking['id']]);

            echo json_encode([
                'success' => false,
                'status' => false,
                'message' => 'Void failed: ' . ($apiResponse['error'] ?? 'Unknown error'),
                'error' => $apiResponse['error'] ?? 'Unknown error',
                'data' => $apiResponse
            ]);
        }

    } catch (Exception $e) {
        error_log("RATEHAWK VOID: Exception - " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Exception: ' . $e->getMessage()
        ]);
    }
});
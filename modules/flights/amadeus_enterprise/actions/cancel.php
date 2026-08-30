<?php

// ============================================================================
// AMADEUS ENTERPRISE — CANCEL FLIGHT ORDER
// ============================================================================
//
// ENDPOINT: POST /flights/amadeus_enterprise/cancel
//
// Admin Cancel Booking: DELETE /v1/booking/flight-orders/{orderId}
// (same Amadeus API as void — Flight Orders has no separate cancel vs void)
//
// Optional POST reason=... is stored in cancellation_response notes.
//
// ============================================================================

@$SECURE or die('Access Denied!');

$router->post('flights/amadeus_enterprise/cancel', function () use ($db) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    $invoiceId = '';
    $supportLogText = '';

    try {
        @set_time_limit(180);

        $invoiceId = trim($_POST['invoice_id'] ?? '');
        $reason = trim((string)($_POST['reason'] ?? 'Cancelled by admin'));
        if ($invoiceId === '') {
            throw new Exception('Invoice ID required');
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) {
            throw new Exception('Booking not found');
        }

        if (in_array((string)$booking['booking_status'], ['cancelled', 'voided'], true)) {
            echo json_encode([
                'status' => true,
                'message' => 'Booking already cancelled',
                'invoice_id' => $invoiceId,
                'booking_status' => $booking['booking_status'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Local-only when never issued / not confirmed.
        if (empty($booking['pnr']) || in_array((string)$booking['booking_status'], ['failed', 'pending'], true)) {
            $db->update('bookings', [
                'booking_status' => 'cancelled',
                'cancellation_request' => 1,
                'cancellation_status' => 1,
                'cancellation_response' => json_encode([
                    'cancelled_at' => date('Y-m-d H:i:s'),
                    'reason' => $reason,
                    'mode' => 'local',
                    'note' => 'No confirmed airline order',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ], ['invoice_id' => $invoiceId]);

            echo json_encode([
                'status' => true,
                'message' => 'Booking cancelled locally. No confirmed airline order.',
                'invoice_id' => $invoiceId,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $moduleRows = $db->select('modules', '*', [
            'name' => 'amadeus_enterprise',
            'type' => 'flights',
            'active' => '1',
            'status' => '1',
            'ORDER' => ['id' => 'DESC'],
        ]);
        if (empty($moduleRows)) {
            $moduleRows = $db->select('modules', '*', [
                'name' => 'amadeus_enterprise',
                'type' => 'flights',
                'ORDER' => ['id' => 'DESC'],
            ]);
        }
        $module = !empty($moduleRows) ? $moduleRows[0] : null;
        if (!$module) {
            throw new Exception('Amadeus Enterprise module not configured');
        }

        $clientId = trim((string)($module['c1'] ?? ''));
        $clientSecret = trim((string)($module['c2'] ?? ''));
        if ($clientId === '' || $clientSecret === '') {
            throw new Exception('Amadeus Enterprise API credentials not configured');
        }

        $envRaw = strtolower(trim((string)($module['env'] ?? $module['mode'] ?? '')));
        $isProduction = in_array($envRaw, ['pro', 'production', 'live'], true)
            || ($envRaw === '' && (int)($module['dev_mode'] ?? 0) === 0);

        $endpointCandidates = $isProduction
            ? [
                ['v1' => 'https://travel.api.amadeus.com/v1/', 'env' => 'production'],
                ['v1' => 'https://api.amadeus.com/v1/', 'env' => 'production_legacy'],
            ]
            : [
                ['v1' => 'https://test.travel.api.amadeus.com/v1/', 'env' => 'test'],
                ['v1' => 'https://test.api.amadeus.com/v1/', 'env' => 'test_self_service'],
            ];

        $orderId = null;
        $bookingResponse = json_decode((string)($booking['booking_response'] ?? ''), true);
        if (is_array($bookingResponse)) {
            $orderId = $bookingResponse['data']['id']
                ?? $bookingResponse['booking_details']['order_id']
                ?? $bookingResponse['order_id']
                ?? null;
        }

        // No order id → mark cancelled locally (still honour admin cancel).
        if (empty($orderId)) {
            $db->update('bookings', [
                'booking_status' => 'cancelled',
                'cancellation_request' => 1,
                'cancellation_status' => 1,
                'cancellation_response' => json_encode([
                    'cancelled_at' => date('Y-m-d H:i:s'),
                    'reason' => $reason,
                    'mode' => 'local',
                    'note' => 'No Amadeus order id',
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ], ['invoice_id' => $invoiceId]);

            echo json_encode([
                'status' => true,
                'message' => 'Booking cancelled locally. No Amadeus order ID found.',
                'invoice_id' => $invoiceId,
                'pnr' => $booking['pnr'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $tokenData = null;
        $tokenHttpCode = 0;
        $tokenResponse = null;
        $endPointV1 = $endpointCandidates[0]['v1'];
        $selectedEnv = $endpointCandidates[0]['env'];

        foreach ($endpointCandidates as $endpoint) {
            $tokenCurl = curl_init();
            curl_setopt_array($tokenCurl, [
                CURLOPT_URL => $endpoint['v1'] . 'security/oauth2/token',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => 'grant_type=client_credentials&client_id=' . urlencode($clientId) . '&client_secret=' . urlencode($clientSecret),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_TIMEOUT => 30,
            ]);
            $tokenResponse = curl_exec($tokenCurl);
            $tokenHttpCode = (int)curl_getinfo($tokenCurl, CURLINFO_HTTP_CODE);
            curl_close($tokenCurl);

            $decoded = json_decode((string)$tokenResponse, true) ?: [];
            if ($tokenHttpCode === 200 && !empty($decoded['access_token'])) {
                $tokenData = $decoded;
                $endPointV1 = $endpoint['v1'];
                $selectedEnv = $endpoint['env'];
                break;
            }
        }

        if (empty($tokenData['access_token'])) {
            throw new Exception('Failed to get OAuth token from Amadeus API');
        }

        $cancelUrl = $endPointV1 . 'booking/flight-orders/' . rawurlencode((string)$orderId);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $cancelUrl,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $tokenData['access_token'],
                'Content-Type: application/json',
                'Accept: application/vnd.amadeus+json',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $responseData = json_decode((string)$response, true);
        $errorCode = (string)($responseData['errors'][0]['code'] ?? '');
        $errorDetail = (string)($responseData['errors'][0]['detail'] ?? $responseData['errors'][0]['title'] ?? '');

        $supportLogText = "STEP: cancel\n"
            . "Environment: {$selectedEnv}\n"
            . "Reason: {$reason}\n"
            . "REQUEST:\nDELETE {$cancelUrl}\n\n"
            . "RESPONSE:\nHTTP {$httpCode}\n"
            . (is_string($response) ? $response : json_encode($responseData));

        if (in_array($httpCode, [200, 204], true) || $errorCode === '1797') {
            $db->update('bookings', [
                'booking_status' => 'cancelled',
                'cancellation_request' => 1,
                'cancellation_status' => 1,
                'cancellation_response' => json_encode([
                    'cancelled_at' => date('Y-m-d H:i:s'),
                    'reason' => $reason,
                    'mode' => $errorCode === '1797' ? 'local_not_found' : 'amadeus',
                    'order_id' => $orderId,
                    'http_code' => $httpCode,
                    'error_code' => $errorCode !== '' ? $errorCode : null,
                    'supplier_response' => $responseData,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'error_response' => null,
            ], ['invoice_id' => $invoiceId]);

            // Notify listeners (best-effort).
            if (function_exists('triggerWebhook')) {
                try {
                    triggerWebhook('flights/booking', 'flights.cancellation.processed', [
                        'invoice_id' => $invoiceId,
                        'pnr' => $booking['pnr'],
                        'order_id' => $orderId,
                        'reason' => $reason,
                        'cancelled_at' => date('Y-m-d H:i:s'),
                    ]);
                } catch (Throwable $e) {
                    // ignore webhook failures
                }
            }

            echo json_encode([
                'status' => true,
                'message' => $errorCode === '1797'
                    ? 'Booking cancelled locally. Order was not found in airline system.'
                    : 'Flight booking cancelled successfully',
                'invoice_id' => $invoiceId,
                'order_id' => $orderId,
                'pnr' => $booking['pnr'],
                'http_code' => $httpCode,
                'response' => $responseData,
                'debug_log' => $supportLogText,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $fullError = $curlError !== ''
            ? 'Connection Error: ' . $curlError
            : ('Amadeus ' . ($errorCode !== '' ? $errorCode . ': ' : '') . ($errorDetail !== '' ? $errorDetail : "HTTP {$httpCode}"));

        $db->update('bookings', [
            'error_response' => json_encode([
                'step' => 'cancel',
                'code' => $errorCode,
                'message' => $fullError,
                'http_code' => $httpCode,
                'response' => $responseData,
                'support_log' => $supportLogText,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ], ['invoice_id' => $invoiceId]);

        throw new Exception($fullError);
    } catch (Throwable $e) {
        echo json_encode([
            'status' => false,
            'message' => $e->getMessage(),
            'invoice_id' => $invoiceId,
            'debug_log' => $supportLogText,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
});

<?php

// ============================================================================
// AMADEUS ENTERPRISE — REFUND REQUEST
// ============================================================================
//
// ENDPOINT: POST /flights/amadeus_enterprise/refund
//
// Amadeus Flight Create Orders has no separate refund API.
// Flow:
//   1. If order still active → DELETE /v1/booking/flight-orders/{orderId}
//   2. Mark payment_status=refunded, booking_status=cancelled locally
// Payment gateway refund (Stripe/PayPal/etc.) remains a separate admin step.
//
// ============================================================================

@$SECURE or die('Access Denied!');

$router->post('flights/amadeus_enterprise/refund', function () use ($db) {
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
        if ($invoiceId === '') {
            throw new Exception('Invoice ID required');
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) {
            throw new Exception('Booking not found');
        }

        if ((string)($booking['payment_status'] ?? '') === 'refunded'
            && in_array((string)$booking['booking_status'], ['cancelled', 'voided'], true)
        ) {
            echo json_encode([
                'status' => true,
                'message' => 'Booking already refunded',
                'invoice_id' => $invoiceId,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        $supplierCancelled = in_array((string)$booking['booking_status'], ['cancelled', 'voided'], true);
        $orderCancelNote = 'Order already cancelled / not active';
        $orderId = null;
        $httpCode = null;

        // Cancel airline order first when still active and we have an order id.
        if (!$supplierCancelled && !empty($booking['pnr'])) {
            $bookingResponse = json_decode((string)($booking['booking_response'] ?? ''), true);
            if (is_array($bookingResponse)) {
                $orderId = $bookingResponse['data']['id']
                    ?? $bookingResponse['booking_details']['order_id']
                    ?? $bookingResponse['order_id']
                    ?? null;
            }

            if (!empty($orderId)) {
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

                $tokenData = null;
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

                $supportLogText = "STEP: refund\n"
                    . "Environment: {$selectedEnv}\n"
                    . "REQUEST:\nDELETE {$cancelUrl}\n\n"
                    . "RESPONSE:\nHTTP {$httpCode}\n"
                    . (is_string($response) ? $response : json_encode($responseData));

                if (!in_array($httpCode, [200, 204], true) && $errorCode !== '1797') {
                    $fullError = $curlError !== ''
                        ? 'Connection Error: ' . $curlError
                        : ('Amadeus ' . ($errorCode !== '' ? $errorCode . ': ' : '') . ($errorDetail !== '' ? $errorDetail : "HTTP {$httpCode}"));

                    $db->update('bookings', [
                        'error_response' => json_encode([
                            'step' => 'refund',
                            'code' => $errorCode,
                            'message' => $fullError,
                            'http_code' => $httpCode,
                            'response' => $responseData,
                            'support_log' => $supportLogText,
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ], ['invoice_id' => $invoiceId]);

                    throw new Exception($fullError);
                }

                $orderCancelNote = $errorCode === '1797'
                    ? 'Order not found in Amadeus (1797) — treated as cancelled'
                    : 'Order cancelled via Amadeus DELETE';
                $supplierCancelled = true;
            } else {
                $orderCancelNote = 'No Amadeus order id — local refund only';
            }
        }

        $db->update('bookings', [
            'payment_status' => 'refunded',
            'booking_status' => 'cancelled',
            'cancellation_request' => 1,
            'cancellation_status' => 1,
            'cancellation_response' => json_encode([
                'refunded_at' => date('Y-m-d H:i:s'),
                'mode' => 'amadeus_enterprise_refund',
                'order_id' => $orderId,
                'http_code' => $httpCode,
                'note' => $orderCancelNote,
                'payment_note' => 'Marked refunded in system. Process gateway refund separately if needed.',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'error_response' => null,
        ], ['invoice_id' => $invoiceId]);

        if (function_exists('triggerWebhook')) {
            try {
                triggerWebhook('flights/booking', 'flights.refund.processed', [
                    'invoice_id' => $invoiceId,
                    'pnr' => $booking['pnr'] ?? '',
                    'order_id' => $orderId,
                    'refunded_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (Throwable $e) {
                // ignore
            }
        }

        echo json_encode([
            'status' => true,
            'message' => 'Refund recorded. Airline order cancelled where applicable. Process payment-gateway refund separately if required.',
            'invoice_id' => $invoiceId,
            'order_id' => $orderId,
            'pnr' => $booking['pnr'] ?? '',
            'payment_status' => 'refunded',
            'booking_status' => 'cancelled',
            'debug_log' => $supportLogText,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
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

<?php
/**
 * Refund Ratehawk Booking
 * ETG docs: Cancel booking → POST /hotel/order/cancel/
 * Response includes amount_payable / amount_refunded / amount_sell.
 */

$router->post('/stays/ratehawk/refund', function () use ($db) {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = [];
        }
        $invoiceId = $input['invoice_id'] ?? ($_POST['invoice_id'] ?? '');

        if ($invoiceId === '') {
            echo json_encode([
                'success' => false,
                'status' => false,
                'message' => 'Missing invoice_id'
            ]);
            exit;
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);

        if (!$booking) {
            echo json_encode([
                'success' => false,
                'status' => false,
                'message' => 'Booking not found'
            ]);
            exit;
        }

        $module = $db->get('modules', '*', [
            'name' => 'ratehawk',
            'type' => 'stays'
        ]);

        if (!$module) {
            echo json_encode([
                'success' => false,
                'status' => false,
                'message' => 'Ratehawk module not configured'
            ]);
            exit;
        }

        $credentials = json_decode($module['credentials'] ?? '{}', true);
        $keyId = trim($credentials['key_id'] ?? $module['c1'] ?? '');
        $apiKey = trim($credentials['api_key'] ?? $module['c3'] ?? '');

        if ($keyId === '' || $apiKey === '') {
            echo json_encode([
                'success' => false,
                'status' => false,
                'message' => 'Ratehawk credentials not configured'
            ]);
            exit;
        }

        $bookingData = json_decode($booking['booking_data'] ?? '', true);
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
                'status' => false,
                'message' => 'Partner order ID not found'
            ]);
            exit;
        }

        $apiBaseUrl = rtrim(trim($module['c4'] ?? ''), '/');
        if (stripos($apiBaseUrl, '/api/b2b/v3') === false) {
            $apiBaseUrl .= '/api/b2b/v3';
        }

        $refundRequest = [
            'partner_order_id' => $partnerOrderId
        ];

        error_log("RATEHAWK REFUND: Cancelling/refunding {$partnerOrderId}");

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
            CURLOPT_POSTFIELDS => json_encode($refundRequest),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $refundResponse = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if (function_exists('ratehawk_log')) {
            ratehawk_log('refund', 'cancel', $refundRequest, $refundResponse, '', [
                'url' => $apiBaseUrl . '/hotel/order/cancel/',
                'method' => 'POST',
                'headers' => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Basic ***'],
                'response_headers' => ''
            ]);
        }

        if ($curlError) {
            echo json_encode([
                'success' => false,
                'status' => false,
                'message' => 'Refund connection error: ' . $curlError
            ]);
            exit;
        }

        $apiResponse = json_decode($refundResponse, true);
        if (!is_array($apiResponse)) {
            $apiResponse = [];
        }

        if ($httpCode === 200 && ($apiResponse['status'] ?? '') === 'ok') {
            $cancelData = is_array($apiResponse['data'] ?? null) ? $apiResponse['data'] : [];
            $refundAmount = $cancelData['amount_refunded']['amount'] ?? null;
            $payableAmount = $cancelData['amount_payable']['amount'] ?? null;

            // The RateHawk supplier cancellation succeeded above. Now REVERSE THE
            // CUSTOMER'S CHARGE via the payment gateway (was DB-flip only). Refund
            // the customer the amount RateHawk actually refunds; fall back to the
            // full paid amount if the supplier didn't return a figure.
            require_once dirname(__DIR__, 4) . '/app/lib/payment-gateway.php';
            $customerRefund = ($refundAmount !== null && (float) $refundAmount > 0)
                ? (float) $refundAmount
                : null; // null → refund_gateway_payment uses the full paid amount
            $refund = function_exists('refund_gateway_payment')
                ? refund_gateway_payment($db, $booking, $customerRefund, 'RateHawk booking cancellation')
                : ['status' => 'unsupported', 'message' => 'Refund function unavailable', 'gateway' => ''];
            $gatewayRefunded = ($refund['status'] === 'refunded');

            $db->update('bookings', [
                'booking_status' => 'cancelled',
                // Only mark 'refunded' if the card refund actually went through.
                'payment_status' => $gatewayRefunded ? 'refunded' : ($booking['payment_status'] ?? 'paid'),
                'cancellation_status' => 1,
                'cancellation_response' => json_encode([
                    'status' => $gatewayRefunded ? 'refunded' : 'cancelled_gateway_refund_pending',
                    'confirmed' => true,
                    'partner_order_id' => $partnerOrderId,
                    'cancel_api' => $cancelData,
                    'gateway_refund' => $refund,
                    'refunded_at' => date('Y-m-d H:i:s')
                ]),
                'error_response' => null,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $booking['id']]);

            echo json_encode([
                'success' => true,
                'status' => true,
                'message' => 'Booking refunded successfully',
                'data' => [
                    'partner_order_id' => $partnerOrderId,
                    'amount_refunded' => $refundAmount,
                    'amount_payable' => $payableAmount,
                    'cancel_api' => $cancelData
                ]
            ]);
        } else {
            $db->update('bookings', [
                'error_response' => json_encode($apiResponse),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $booking['id']]);

            echo json_encode([
                'success' => false,
                'status' => false,
                'message' => 'Refund failed: ' . ($apiResponse['error'] ?? 'Unknown error'),
                'error' => $apiResponse['error'] ?? 'Unknown error',
                'data' => $apiResponse
            ]);
        }
    } catch (Exception $e) {
        error_log('RATEHAWK REFUND: Exception - ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'status' => false,
            'message' => 'Exception: ' . $e->getMessage()
        ]);
    }
});

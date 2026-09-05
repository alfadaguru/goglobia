<?php
// path : modules/flights/duffel/actions/void.php
// VOID BOOKING ACTION (Duffel)
//
// Duffel has no separate "void" transaction — an order is released with the
// same order-cancellation API used by cancel.php (get order → check
// available_actions → create order_cancellation → confirm). Duffel itself
// refunds `refund_to` (balance / original_form_of_payment) per the fare. This
// endpoint performs that real supplier cancellation; if the order cannot be
// cancelled via API (outside the void/cancellation window) it degrades to a
// DB-level void so the admin surface stays consistent, and says so explicitly.
@$SECURE or die('Access Denied!');

$router->post('/flights/duffel/void', function() use ($db) {
    header('Content-Type: application/json');

    try {
        $invoice_id = trim((string) ($_POST['invoice_id'] ?? ''));
        if ($invoice_id === '') {
            echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
            exit;
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found']);
            exit;
        }

        if (in_array(($booking['booking_status'] ?? ''), ['cancelled', 'voided'], true)) {
            echo json_encode(['status' => true, 'message' => 'Booking is already cancelled/voided.', 'invoice_id' => $invoice_id]);
            exit;
        }

        $module = $db->get('modules', '*', ['name' => 'duffel', 'type' => 'flights']);
        if (!$module) {
            echo json_encode(['status' => false, 'message' => 'Duffel module not configured']);
            exit;
        }

        $api_token = null;
        if (!empty($module['credentials'])) {
            $credentials = json_decode($module['credentials'], true);
            $api_token   = $credentials['c1'] ?? null;
        }
        if (!$api_token && !empty($module['c1'])) {
            $api_token = $module['c1'];
        }
        if (!$api_token) {
            echo json_encode(['status' => false, 'message' => 'Duffel API credentials not configured']);
            exit;
        }

        $booking_data = json_decode($booking['booking_data'] ?? '{}', true);
        if (!is_array($booking_data)) { $booking_data = []; }
        $order_id = $booking_data['order_id'] ?? null;
        if (!$order_id && !empty($booking['booking_response'])) {
            $decoded  = json_decode($booking['booking_response']);
            $order_id = $decoded->order_id ?? null;
        }

        // No Duffel order to act on → DB-level void only (nothing was ever
        // confirmed with the supplier).
        if (!$order_id) {
            $db->update('bookings', [
                'booking_status'        => 'cancelled',
                'cancellation_status'   => 1,
                'cancellation_response' => 'Voided at DB level (no Duffel order_id present) on ' . date('Y-m-d H:i:s'),
            ], ['invoice_id' => $invoice_id]);
            echo json_encode(['status' => true, 'message' => 'Booking voided (no Duffel order was confirmed for this booking).', 'invoice_id' => $invoice_id]);
            exit;
        }

        $duffel = function ($method, $url, $token, $body = null) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_TIMEOUT        => 40,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER     => array_filter([
                    'Accept: application/json',
                    $body !== null ? 'Content-Type: application/json' : null,
                    'Authorization: Bearer ' . $token,
                    'Duffel-Version: v2',
                ]),
            ]);
            if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            return ['body' => $resp, 'code' => $code, 'error' => $err, 'json' => json_decode((string) $resp, true)];
        };

        // STEP 1: fetch order, check it can be cancelled/voided.
        $order = $duffel('GET', 'https://api.duffel.com/air/orders/' . $order_id, $api_token);
        if (!empty($order['error'])) {
            echo json_encode(['status' => false, 'message' => 'API connection error: ' . $order['error']]);
            exit;
        }
        if ($order['code'] !== 200) {
            echo json_encode(['status' => false, 'message' => 'Failed to fetch order details from Duffel']);
            exit;
        }
        $available_actions = $order['json']['data']['available_actions'] ?? [];
        if (!in_array('cancel', $available_actions, true)) {
            echo json_encode([
                'status'            => false,
                'message'           => 'This order cannot be voided/cancelled via the Duffel API (outside the void window). Contact Duffel support.',
                'available_actions' => $available_actions,
            ]);
            exit;
        }

        // STEP 2: create cancellation (refund quote).
        $create = $duffel('POST', 'https://api.duffel.com/air/order_cancellations', $api_token,
            json_encode(['data' => ['order_id' => $order_id]]));
        if (!empty($create['error'])) {
            echo json_encode(['status' => false, 'message' => 'API connection error: ' . $create['error']]);
            exit;
        }
        if ($create['code'] !== 201 || !isset($create['json']['data']['id'])) {
            $msg = $create['json']['errors'][0]['title'] ?? $create['json']['errors'][0]['message'] ?? 'Failed to create void/cancellation quote';
            echo json_encode(['status' => false, 'message' => $msg, 'api_response' => $create['json']]);
            exit;
        }
        $cancellation_id = $create['json']['data']['id'];
        $refund_amount   = $create['json']['data']['refund_amount'] ?? '0.00';
        $refund_currency = $create['json']['data']['refund_currency'] ?? 'USD';
        $refund_to       = $create['json']['data']['refund_to'] ?? 'balance';

        // STEP 3: confirm.
        $confirm = $duffel('POST', 'https://api.duffel.com/air/order_cancellations/' . $cancellation_id . '/actions/confirm', $api_token, '{}');
        if ($confirm['code'] === 200 && isset($confirm['json']['data']['confirmed_at'])) {
            $db->update('bookings', [
                'booking_status'        => 'cancelled',
                'payment_status'        => ($refund_to === 'original_form_of_payment') ? 'refunded' : ($booking['payment_status'] ?? 'paid'),
                'cancellation_status'   => 1,
                'cancellation_request'  => 1,
                'cancellation_response' => json_encode([
                    'method'          => 'duffel_void',
                    'cancelled_at'    => $confirm['json']['data']['confirmed_at'],
                    'refund_amount'   => $refund_amount,
                    'refund_currency' => $refund_currency,
                    'refund_to'       => $refund_to,
                    'cancellation_id' => $cancellation_id,
                ]),
                'booking_data'          => json_encode(array_merge($booking_data, ['cancellation' => $confirm['json']['data']])),
            ], ['invoice_id' => $invoice_id]);

            echo json_encode([
                'status'          => true,
                'message'         => 'Flight booking voided with Duffel.',
                'refund_amount'   => $refund_amount,
                'refund_currency' => $refund_currency,
                'refund_to'       => $refund_to,
                'cancellation_id' => $cancellation_id,
            ]);
            exit;
        }

        $msg = $confirm['json']['errors'][0]['title'] ?? $confirm['json']['errors'][0]['message'] ?? 'Failed to confirm void/cancellation';
        echo json_encode(['status' => false, 'message' => $msg, 'api_response' => $confirm['json']]);

    } catch (Exception $e) {
        echo json_encode(['status' => false, 'message' => 'Exception: ' . $e->getMessage()]);
    }
});

<?php
// path : modules/flights/duffel/actions/cancel.php
// CANCEL BOOKING ACTION
// This action cancels a flight booking with Duffel API
@$SECURE or die('Access Denied!');

$router->post('/flights/duffel/cancel', function() use ($db) {
    header('Content-Type: application/json');

    try {
        // Get required data
        $invoice_id = $_POST['invoice_id'] ?? '';

        if (empty($invoice_id)) {
            echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
            exit;
        }

        // Fetch booking
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);

        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found']);
            exit;
        }

        // Check if already cancelled
        if ($booking['booking_status'] === 'cancelled') {
            echo json_encode(['status' => false, 'message' => 'Booking is already cancelled']);
            exit;
        }

        // Get module configuration
        $module = $db->get('modules', '*', [
            'name' => 'duffel',
            'type' => 'flights'
        ]);

        if (!$module) {
            echo json_encode(['status' => false, 'message' => 'Duffel module not configured']);
            exit;
        }

        // Get API credentials
        $api_token = null;
        if (!empty($module['credentials'])) {
            $credentials = json_decode($module['credentials'], true);
            $api_token = $credentials['c1'] ?? null;
        }
        if (!$api_token && !empty($module['c1'])) {
            $api_token = $module['c1'];
        }

        if (!$api_token) {
            echo json_encode(['status' => false, 'message' => 'Duffel API credentials not configured']);
            exit;
        }
        
        // Decode booking data to get order_id
        $booking_data = json_decode($booking['booking_data'], true);
        $order_id = $booking_data['order_id'] ?? null;
        
        
        // If no order_id, try to get from duffel_response
        if (!$order_id && isset($booking['booking_response'])) {
            $order_id = json_decode($booking['booking_response'])->order_id;
        }

        if (!$order_id) {
            echo json_encode([
                'status' => false,
                'message' => 'Order ID not found in booking data. Cannot cancel.',
                'debug' => [
                    'available_keys' => array_keys($booking_data),
                    'has_order_id' => isset($booking_data['order_id']),
                    'has_duffel_response' => isset($booking_data['duffel_response'])
                ]
            ]);
            exit;
        }


        // STEP 1: Get the order to check if cancellation is allowed
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.duffel.com/air/orders/' . $order_id);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Authorization: Bearer ' . $api_token,
            'Duffel-Version: v2'
        ]);

        $order_response = curl_exec($ch);
        $order_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($order_http_code !== 200) {
            echo json_encode([
                'status' => false,
                'message' => 'Failed to fetch order details from Duffel'
            ]);
            exit;
        }

        $order_result = json_decode($order_response, true);
        $available_actions = $order_result['data']['available_actions'] ?? [];
        
        // Check if order can be cancelled
        if (!in_array('cancel', $available_actions)) {
            echo json_encode([
                'status' => false,
                'message' => 'This order cannot be cancelled via API. Please contact Duffel support.',
                'available_actions' => $available_actions
            ]);
            exit;
        }

        // STEP 2: Create order cancellation (get refund quote)
        $cancel_payload = [
            'data' => [
                'order_id' => $order_id
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.duffel.com/air/order_cancellations');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cancel_payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_token,
            'Duffel-Version: v2'
        ]);

        $cancel_response = curl_exec($ch);
        $cancel_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);

        if ($curl_error) {
            echo json_encode([
                'status' => false,
                'message' => 'API connection error: ' . $curl_error
            ]);
            exit;
        }

        $cancel_result = json_decode($cancel_response, true);

        if ($cancel_http_code !== 201 || !isset($cancel_result['data']['id'])) {
            $error_message = 'Failed to create cancellation quote';
            if (isset($cancel_result['errors'][0])) {
                $error_message = $cancel_result['errors'][0]['title'] ?? $cancel_result['errors'][0]['message'] ?? $error_message;
            }

            echo json_encode([
                'status' => false,
                'message' => $error_message,
                'api_response' => $cancel_result
            ]);
            exit;
        }

        $cancellation_id = $cancel_result['data']['id'];
        $refund_amount = $cancel_result['data']['refund_amount'] ?? '0.00';
        $refund_currency = $cancel_result['data']['refund_currency'] ?? 'USD';
        $refund_to = $cancel_result['data']['refund_to'] ?? 'balance';

        error_log("DUFFEL CANCEL: Cancellation quote created - ID: {$cancellation_id}, Refund: {$refund_amount} {$refund_currency}");

        // STEP 3: Confirm the cancellation
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.duffel.com/air/order_cancellations/' . $cancellation_id . '/actions/confirm');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{}'); // Empty body for confirm action
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_token,
            'Duffel-Version: v2'
        ]);

        $confirm_response = curl_exec($ch);
        $confirm_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $confirm_result = json_decode($confirm_response, true);

        // Check if cancellation was confirmed successfully
        if ($confirm_http_code === 200 && isset($confirm_result['data']['confirmed_at'])) {
            $confirmed_at = $confirm_result['data']['confirmed_at'];

            // Update database with cancellation details
            $updated = $db->update('bookings', [
                'booking_status' => 'cancelled',
                'cancellation_status' => 1,
                'cancellation_request' => 1,
                'cancellation_response' => json_encode([
                    'cancelled_at' => $confirmed_at,
                    'refund_amount' => $refund_amount,
                    'refund_currency' => $refund_currency,
                    'refund_to' => $refund_to,
                    'cancellation_id' => $cancellation_id,
                    'duffel_response' => $confirm_result['data']
                ]),
                'booking_data' => json_encode(array_merge($booking_data, [
                    'cancellation' => $confirm_result['data']
                ]))
            ], ['invoice_id' => $invoice_id]);

            if ($updated) {
                echo json_encode([
                    'status' => true,
                    'message' => 'Flight booking cancelled successfully with Duffel',
                    'refund_amount' => $refund_amount,
                    'refund_currency' => $refund_currency,
                    'refund_to' => $refund_to,
                    'cancellation_id' => $cancellation_id,
                    'cancelled_at' => $confirmed_at
                ]);
            } else {
                echo json_encode([
                    'status' => false,
                    'message' => 'Cancellation confirmed with Duffel but failed to update database',
                    'refund_amount' => $refund_amount
                ]);
            }
        } else {
            // Confirmation failed
            $error_message = 'Failed to confirm cancellation';
            if (isset($confirm_result['errors'][0])) {
                $error_message = $confirm_result['errors'][0]['title'] ?? $confirm_result['errors'][0]['message'] ?? $error_message;
            }

            // Store error
            $db->update('bookings', [
                'error_response' => json_encode([
                    'error' => $error_message,
                    'response' => $confirm_result,
                    'timestamp' => date('Y-m-d H:i:s')
                ])
            ], ['invoice_id' => $invoice_id]);

            echo json_encode([
                'status' => false,
                'message' => $error_message,
                'api_response' => $confirm_result
            ]);
        }

    } catch (Exception $e) {
        echo json_encode([
            'status' => false,
            'message' => 'Exception: ' . $e->getMessage()
        ]);
    }
});
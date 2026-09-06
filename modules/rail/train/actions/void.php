<?php
/**
 * TRAIN (RAIL) BOOKING VOID HANDLER
 * =====================================
 * A train order has no separate "void" transaction distinct from cancellation —
 * the supplier only exposes POST /ticket/orderCancel. This endpoint exists so
 * the admin lifecycle surface is uniform with flight modules and performs that
 * same supplier cancellation (invoice-based, using cus_main_order_id), mirroring
 * actions/cancel.php.
 *
 * Registered on the Modules API Gateway ($router) via index.php.
 * Reached at POST /modules/rail/train/void.
 */

$router->post('/rail/train/void', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $invoiceId = $_POST['invoice_id'] ?? '';
        if ($invoiceId === '') {
            echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
            exit;
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'rail']);
        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found']);
            exit;
        }

        if (empty($booking['pnr'])) {
            echo json_encode(['status' => false, 'message' => 'Booking has not been issued with the supplier yet, nothing to void']);
            exit;
        }

        if (in_array(($booking['booking_status'] ?? ''), ['cancelled', 'voided'], true)) {
            echo json_encode(['status' => true, 'message' => 'Booking is already cancelled/voided']);
            exit;
        }

        $journeyType = _train_booking_journey_type($booking);
        $gate = _train_assert_online_action($journeyType, 'cancel');
        if (!$gate['allowed']) {
            echo json_encode(['status' => false, 'message' => $gate['message']]);
            exit;
        }

        $orderInput     = json_decode($booking['booking_data'] ?? '', true);
        $cusMainOrderId = is_array($orderInput) ? ($orderInput['cus_main_order_id'] ?? '') : '';
        if ($cusMainOrderId === '') {
            echo json_encode(['status' => false, 'message' => 'cus_main_order_id missing from booking data, cannot void']);
            exit;
        }

        $res = _train_request('/ticket/orderCancel', ['cus_main_order_id' => $cusMainOrderId], ['db' => $db]);

        if (!empty($res['ok'])) {
            $db->update('bookings', [
                'booking_status'        => 'cancelled',
                'cancellation_status'   => 1,
                'cancellation_response' => json_encode($res['data']),
                'updated_at'            => date('Y-m-d H:i:s'),
            ], ['invoice_id' => $invoiceId]);

            echo json_encode(['status' => true, 'message' => 'Train order voided (cancelled) with supplier successfully.']);
        } else {
            $errPayload = is_array($res['data'] ?? null) ? _train_enrich_supplier_payload($res['data']) : [];
            $errMsg = _train_api_error_message(
                $errPayload['code'] ?? $errPayload['error_code'] ?? 0,
                (string)($errPayload['msg'] ?? $res['error'] ?? 'Train order void request failed')
            );
            if ($errMsg === '' || !empty($errPayload['msg_en'])) {
                $errMsg = (string)($errPayload['msg_en'] ?? $errMsg);
            }
            $errPayload['response_error'] = $errMsg;

            $db->update('bookings', [
                'error_response' => json_encode($errPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at'     => date('Y-m-d H:i:s'),
            ], ['invoice_id' => $invoiceId]);

            echo json_encode(['status' => false, 'message' => $errMsg, 'response_error' => $errMsg]);
        }
    } catch (Throwable $e) {
        echo json_encode(['status' => false, 'message' => $e->getMessage()]);
    }
});

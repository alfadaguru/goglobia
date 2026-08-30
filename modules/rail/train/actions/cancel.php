<?php
/**
 * TRAIN (RAIL) BOOKING CANCEL HANDLER
 * =====================================
 * Cancels an issued train order with the supplier API (POST /ticket/orderCancel).
 *
 * Registered on the Modules API Gateway ($router) via modules/rail/train/index.php's
 * `include "actions/cancel.php";`. Reached at POST /modules/rail/train/cancel by the
 * admin "Cancel Booking" button (app/views/admin/bookings/edit.php, handleAction()).
 */

$router->post('/rail/train/cancel', function () use ($db) {
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
            echo json_encode(['status' => false, 'message' => 'Booking has not been issued with the supplier yet, nothing to cancel']);
            exit;
        }

        if ($booking['booking_status'] === 'cancelled') {
            echo json_encode(['status' => true, 'message' => 'Booking is already cancelled']);
            exit;
        }

        $journeyType = _train_booking_journey_type($booking);
        $gate = _train_assert_online_action($journeyType, 'cancel');
        if (!$gate['allowed']) {
            echo json_encode(['status' => false, 'message' => $gate['message']]);
            exit;
        }

        // The supplier's cancel endpoint keys off the customer's own order reference
        // (cus_main_order_id), which was generated client-side and stored in booking_data
        // when the order was originally placed with the supplier at issue time.
        $orderInput     = json_decode($booking['booking_data'] ?? '', true);
        $cusMainOrderId = is_array($orderInput) ? ($orderInput['cus_main_order_id'] ?? '') : '';

        if ($cusMainOrderId === '') {
            echo json_encode(['status' => false, 'message' => 'cus_main_order_id missing from booking data, cannot cancel']);
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

            echo json_encode([
                'status'  => true,
                'message' => 'Train order cancelled with supplier successfully.',
            ]);

        } else {
            $errPayload = is_array($res['data'] ?? null)
                ? _train_enrich_supplier_payload($res['data'])
                : [];
            $errMsg = _train_api_error_message(
                $errPayload['code'] ?? $errPayload['error_code'] ?? 0,
                (string)($errPayload['msg'] ?? $res['error'] ?? 'Train order cancellation request failed')
            );
            if ($errMsg === '' || !empty($errPayload['msg_en'])) {
                $errMsg = (string)($errPayload['msg_en'] ?? $errMsg);
            }
            $errPayload['response_error'] = $errMsg;

            $db->update('bookings', [
                'error_response' => json_encode($errPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at'     => date('Y-m-d H:i:s'),
            ], ['invoice_id' => $invoiceId]);

            echo json_encode([
                'status'         => false,
                'message'        => $errMsg,
                'response_error' => $errMsg,
            ]);
        }

    } catch (Throwable $e) {
        echo json_encode(['status' => false, 'message' => $e->getMessage()]);
    }
});

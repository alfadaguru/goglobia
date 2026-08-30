<?php
/**
 * TRAIN (RAIL) BOOKING REFUND HANDLER
 * =====================================
 * Submits a refund request to the supplier API (POST /ticket/orderRefund).
 *
 * Registered on the Modules API Gateway ($router) via modules/rail/train/index.php's
 * `include "actions/refund.php";`. Reached at POST /modules/rail/train/refund by the
 * admin "Refund Request" button (app/views/admin/bookings/edit.php, handleAction()).
 *
 * This only SUBMITS the refund request to the supplier. The actual refund
 * confirmation (payment_status -> refunded, booking_status -> cancelled) is handled
 * asynchronously by the existing polling/webhook endpoints: /rail/refundResultData
 * and /rail/offlinePush (see app/routes/rail/bookingRoutes.php).
 */

$router->post('/rail/train/refund', function () use ($db) {
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
            echo json_encode(['status' => false, 'message' => 'Booking has not been issued with the supplier yet, nothing to refund']);
            exit;
        }

        $journeyType = _train_booking_journey_type($booking);
        $gate = _train_assert_online_action($journeyType, 'refund');
        if (!$gate['allowed']) {
            echo json_encode(['status' => false, 'message' => $gate['message']]);
            exit;
        }

        $orderInput = json_decode($booking['booking_data'] ?? '', true);

        // cus_order_id / end_datetime come from the original journey leg sent at issue time.
        $journeyLeg  = (is_array($orderInput) && !empty($orderInput['journey'][0])) ? $orderInput['journey'][0] : [];
        $cusOrderId  = $journeyLeg['cus_order_id'] ?? '';
        $endDatetime = $journeyLeg['end_datetime'] ?? 0;

        if ($cusOrderId === '') {
            echo json_encode(['status' => false, 'message' => 'cus_order_id missing from booking data, cannot request refund']);
            exit;
        }

        $passengersSource = (is_array($orderInput) && !empty($orderInput['passengers'])) ? $orderInput['passengers'] : [];

        if (empty($passengersSource)) {
            echo json_encode(['status' => false, 'message' => 'No passengers found in booking data, cannot request refund']);
            exit;
        }

        $remark = trim((string)($_POST['remark'] ?? 'Refund requested via admin panel'));

        $passengers = array_map(function ($p) use ($remark) {
            return [
                'passenger_first_name' => $p['passenger_first_name'] ?? '',
                'passenger_last_name'  => $p['passenger_last_name']  ?? '',
                'passenger_type'       => $p['passenger_type']       ?? 1,
                'passenger_card_type'  => $p['passenger_card_type']  ?? '',
                'passenger_card_no'    => $p['passenger_card_no']    ?? '',
                'cus_remark'           => $remark,
            ];
        }, $passengersSource);

        // Unique client-side reference for this refund request, per the API's cus_* convention.
        $cusRefundId = 'REFUND' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)) . time();

        $refundPayload = [
            'cus_order_id'  => $cusOrderId,
            'cus_change_id' => trim((string)($_POST['cus_change_id'] ?? '')),
            'cus_refund_id' => $cusRefundId,
            'passengers'    => $passengers,
            'end_datetime'  => $endDatetime,
            'call_back_url' => rtrim(root, '/') . '/ticket/offlinePush',
        ];

        $res = _train_request('/ticket/orderRefund', $refundPayload, ['db' => $db]);

        if (!empty($res['ok'])) {

            // The supplier's own refund_id (not cus_refund_id) is what /ticket/refundResultData
            // expects later, so keep it at a predictable top-level key for easy retrieval.
            $refundId = $res['data']['data']['refund_id'] ?? null;

            $db->update('bookings', [
                'cancellation_request'   => 1,
                'cancellation_response'  => json_encode([
                    'cus_refund_id'      => $cusRefundId,
                    'refund_id'          => $refundId,
                    'supplier_response'  => $res['data'],
                ]),
                'updated_at'             => date('Y-m-d H:i:s'),
            ], ['invoice_id' => $invoiceId]);

            echo json_encode([
                'status'        => true,
                'cus_refund_id' => $cusRefundId,
                'refund_id'     => $refundId,
                'message'       => 'Refund request submitted to supplier successfully. Awaiting confirmation.',
            ]);

        } else {
            $errPayload = is_array($res['data'] ?? null)
                ? _train_enrich_supplier_payload($res['data'])
                : [];
            $errMsg = _train_api_error_message(
                $errPayload['code'] ?? $errPayload['error_code'] ?? 0,
                (string)($errPayload['msg'] ?? $res['error'] ?? 'Refund request failed')
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

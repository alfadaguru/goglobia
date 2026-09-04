<?php
// ============================================================================
// TRAVELPORT HOTEL BOOKING - REFUND REQUEST
// ============================================================================
// ENDPOINT: POST /stays/travelport/actions/refund
// PURPOSE: Request refund for cancelled booking
// ============================================================================

$router->post('stays/travelport/actions/refund', function() use ($db) {

    @ob_end_clean();
    header('Content-Type: application/json');

    try {
        // ============================================================================
        // EXTRACT REQUEST PARAMETERS
        // ============================================================================
        $input = json_decode(file_get_contents('php://input'), true);

        $bookingId = $input['booking_id'] ?? '';
        $amount = $input['amount'] ?? 0;
        $reason = $input['reason'] ?? 'Customer request';

        if (empty($bookingId)) {
            echo json_encode([
                'success' => false,
                'message' => 'Missing booking ID'
            ]);
            exit;
        }

        // ============================================================================
        // GET BOOKING FROM DATABASE
        // ============================================================================
        $booking = $db->get('bookings', '*', ['id' => $bookingId]);

        if (!$booking) {
            echo json_encode([
                'success' => false,
                'message' => 'Booking not found'
            ]);
            exit;
        }

        if ($booking['status'] !== 'cancelled') {
            echo json_encode([
                'success' => false,
                'message' => 'Booking must be cancelled before refund'
            ]);
            exit;
        }

        // ============================================================================
        // PROCESS REFUND
        // Previously wrote to non-existent columns (refund_amount/refund_status/…)
        // and a booking_logs table — so it did nothing. Now reverse the customer's
        // charge via the payment gateway and record on real columns.
        // ============================================================================
        $refundAmount = ($amount > 0) ? $amount : (float) ($booking['price_markup'] ?? 0);

        require_once dirname(__DIR__, 4) . '/app/lib/payment-gateway.php';
        $stpGwRefund = function_exists('refund_gateway_payment')
            ? refund_gateway_payment($db, $booking, ($refundAmount > 0 ? $refundAmount : null), $reason ?: 'Travelport hotel refund')
            : ['status' => 'unsupported', 'message' => 'Refund function unavailable', 'gateway' => ''];
        $stpGatewayRefunded = ($stpGwRefund['status'] === 'refunded');

        $db->update('bookings', [
            'payment_status'        => $stpGatewayRefunded ? 'refunded' : ($booking['payment_status'] ?? 'paid'),
            'cancellation_status'   => 1,
            'cancellation_response' => json_encode([
                'refund_amount'  => $refundAmount,
                'reason'         => $reason,
                'gateway_refund' => $stpGwRefund,
                'requested_at'   => date('Y-m-d H:i:s'),
            ]),
        ], ['id' => $bookingId]);

        echo json_encode([
            'success' => true,
            'message' => 'Refund request submitted',
            'data' => [
                'booking_id' => $bookingId,
                'refund_amount' => $refundAmount,
                'status' => 'pending'
            ]
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Refund request failed: ' . $e->getMessage()
        ]);
    }

    exit;
});

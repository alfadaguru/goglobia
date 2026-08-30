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
        // ============================================================================
        $refundAmount = $amount > 0 ? $amount : $booking['total_amount'];

        // Update booking with refund information
        $db->update('bookings', [
            'refund_amount' => $refundAmount,
            'refund_status' => 'pending',
            'refund_requested_at' => date('Y-m-d H:i:s'),
            'refund_reason' => $reason
        ], [
            'id' => $bookingId
        ]);

        // Log refund request
        $db->insert('booking_logs', [
            'booking_id' => $bookingId,
            'action' => 'refund_requested',
            'details' => json_encode([
                'amount' => $refundAmount,
                'reason' => $reason
            ]),
            'created_at' => date('Y-m-d H:i:s')
        ]);

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

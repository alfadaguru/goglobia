<?php
// ============================================================================
// TRAVELPORT HOTEL BOOKING - VOID RESERVATION
// ============================================================================
// ENDPOINT: POST /stays/travelport/actions/void
// PURPOSE: Void/delete booking before confirmation
// ============================================================================

$router->post('stays/travelport/actions/void', function() use ($db) {

    @ob_end_clean();
    header('Content-Type: application/json');

    try {
        // ============================================================================
        // EXTRACT REQUEST PARAMETERS
        // ============================================================================
        $input = json_decode(file_get_contents('php://input'), true);

        $bookingId = $input['booking_id'] ?? '';

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

        // Only allow void for pending bookings
        if (!in_array($booking['status'], ['pending', 'processing'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Only pending bookings can be voided'
            ]);
            exit;
        }

        // ============================================================================
        // VOID BOOKING
        // ============================================================================
        $db->update('bookings', [
            'status' => 'voided',
            'voided_at' => date('Y-m-d H:i:s')
        ], [
            'id' => $bookingId
        ]);

        // Log void action
        $db->insert('booking_logs', [
            'booking_id' => $bookingId,
            'action' => 'voided',
            'details' => json_encode(['timestamp' => date('Y-m-d H:i:s')]),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Booking voided successfully',
            'booking_id' => $bookingId
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Void failed: ' . $e->getMessage()
        ]);
    }

    exit;
});

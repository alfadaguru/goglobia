<?php
// ============================================================================
// TRAVELPORT HOTEL BOOKING - VOID RESERVATION
// ENDPOINT: POST /stays/travelport/void
// PURPOSE: Void a hotel booking before confirmation. For a hotel there is no
// separate supplier "void" distinct from cancel; a not-yet-confirmed booking is
// released at DB level, a confirmed one should go through cancel. Uses the same
// invoice_id + booking_status ENUM (confirmed|pending|cancelled) convention as
// every other stays module.
// ============================================================================
@$SECURE or die('Access Denied!');

$router->post('stays/travelport/void', function () use ($db) {
    @ob_end_clean();
    header('Content-Type: application/json');

    try {
        $invoice_id = trim((string) ($_POST['invoice_id'] ?? ''));
        if ($invoice_id === '') {
            $input = json_decode(file_get_contents('php://input'), true);
            $invoice_id = trim((string) ($input['invoice_id'] ?? ''));
        }
        if ($invoice_id === '') {
            echo json_encode(['status' => false, 'success' => false, 'message' => 'Invoice ID required']);
            exit;
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        if (!$booking) {
            echo json_encode(['status' => false, 'success' => false, 'message' => 'Booking not found']);
            exit;
        }

        if (in_array(($booking['booking_status'] ?? ''), ['cancelled', 'voided'], true)) {
            echo json_encode(['status' => true, 'success' => true, 'message' => 'Booking is already cancelled/voided.', 'invoice_id' => $invoice_id]);
            exit;
        }

        // A confirmed hotel booking must be cancelled with the supplier, not voided.
        if (($booking['booking_status'] ?? '') === 'confirmed' && !empty($booking['pnr'])) {
            echo json_encode([
                'status'  => false,
                'success' => false,
                'message' => 'This booking is confirmed with the supplier; use Cancel (stays/travelport/cancel) instead of Void.',
            ]);
            exit;
        }

        $db->update('bookings', [
            'booking_status'        => 'cancelled',
            'cancellation_status'   => 1,
            'cancellation_response' => 'Hotel booking voided (pre-confirmation) on ' . date('Y-m-d H:i:s'),
        ], ['invoice_id' => $invoice_id]);

        echo json_encode(['status' => true, 'success' => true, 'message' => 'Booking voided successfully', 'invoice_id' => $invoice_id]);
    } catch (Exception $e) {
        echo json_encode(['status' => false, 'success' => false, 'message' => 'Void failed: ' . $e->getMessage()]);
    }
    exit;
});

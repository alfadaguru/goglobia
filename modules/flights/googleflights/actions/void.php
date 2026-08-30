<?php
// ============================================================================
// GOOGLE FLIGHTS — Void Booking (local only, no API)
// ============================================================================

$router->post('flights/googleflights/void', function () use ($db) {

    if (ob_get_level()) ob_end_clean();
    ob_start();

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');

    $invoice_id = '';

    try {
        if (!$db) throw new Exception('Database connection not available');

        $invoice_id = trim($_POST['invoice_id'] ?? '');
        if (empty($invoice_id)) throw new Exception('Missing invoice_id');

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        if (!$booking) throw new Exception('Booking not found: ' . $invoice_id);

        if (in_array($booking['booking_status'], ['cancelled', 'voided'])) {
            ob_clean();
            echo json_encode(['status' => true, 'message' => 'Booking already voided/cancelled', 'invoice_id' => $invoice_id]);
            exit;
        }

        $db->update('bookings', ['booking_status' => 'voided'], ['invoice_id' => $invoice_id]);

        ob_clean();
        echo json_encode([
            'status'     => true,
            'message'    => 'Booking voided locally. Google Flights does not provide a void API — please cancel directly with the airline.',
            'invoice_id' => $invoice_id,
        ]);

    } catch (Exception $e) {
        ob_clean();
        echo json_encode(['status' => false, 'message' => $e->getMessage(), 'invoice_id' => $invoice_id]);
    }
    exit;
});

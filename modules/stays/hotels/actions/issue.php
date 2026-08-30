<?php
// path : modules/stays/hotels/actions/issue.php
// ISSUE BOOKING ACTION
// This action issues/confirms a booking with supplier
@$SECURE or die('Access Denied!');

$router->post('stays/hotels/issue', function() use ($db) {
    header('Content-Type: application/json');

    // Get required data
    $invoice_id = $_POST['invoice_id'] ?? '';
    $module_type = $_POST['module_type'] ?? 'hotels';

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

    // GENERATE PNR (Passenger Name Record)
    $pnr = 'PNR' . strtoupper(substr(md5($invoice_id . time()), 0, 6));

    // UPDATE DATABASE
    $updated = $db->update('bookings', [
        'pnr' => $pnr,
        'booking_status' => 'confirmed'
    ], ['invoice_id' => $invoice_id]);

    if ($updated) {
        echo json_encode([
            'status' => true,
            'Prn' => $pnr,                      // payment-gateway.php expects 'Prn'
            'booking_reference' => $pnr,
            'reference' => $pnr,
            'message' => 'Booking issued successfully',
            'pnr' => $pnr,                      // keep for backward compatibility
            'response_error' => ''
        ]);
    } else {
        echo json_encode([
            'status' => false,
            'Prn' => '',
            'message' => 'Failed to update booking',
            'response_error' => 'Failed to update booking'
        ]);
    }
});
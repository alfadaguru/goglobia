<?php
// path : modules/stays/hotels/actions/void.php
// VOID BOOKING ACTION
// This action voids a booking
@$SECURE or die('Access Denied!');

$router->post('/stays/hotels/void', function() use ($db) {
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

// UPDATE DATABASE
$updated = $db->update('bookings', [
    'booking_status' => 'cancelled',
    'cancellation_status' => 1,
    'cancellation_response' => 'Booking voided by admin on ' . date('Y-m-d H:i:s')
], ['invoice_id' => $invoice_id]);

    if ($updated) {
        echo json_encode(['status' => true, 'message' => 'Booking voided successfully']);
    } else {
        echo json_encode(['status' => false, 'message' => 'Failed to void booking']);
    }
});
<?php
// path : modules/stays/hotels/actions/cancel.php
// CANCEL BOOKING ACTION
// This action cancels a booking
@$SECURE or die('Access Denied!');

$router->post('/stays/hotels/cancel', function() use ($db) {
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
    'cancellation_request' => 1,
    'cancellation_response' => 'Booking cancelled by admin on ' . date('Y-m-d H:i:s')
], ['invoice_id' => $invoice_id]);

    if ($updated) {
        echo json_encode(['status' => true, 'message' => 'Booking cancelled successfully']);
    } else {
        echo json_encode(['status' => false, 'message' => 'Failed to cancel booking']);
    }
});
<?php
// path : modules/stays/hotels/actions/refund.php
// REFUND REQUEST ACTION
// This action processes refund requests
@$SECURE or die('Access Denied!');

$router->post('/stays/hotels/refund', function() use ($db) {
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
    'payment_status' => 'refunded',
    'booking_status' => 'cancelled',
    'cancellation_status' => 1,
    'cancellation_response' => 'Refund requested by admin on ' . date('Y-m-d H:i:s')
], ['invoice_id' => $invoice_id]);

    if ($updated) {
        echo json_encode(['status' => true, 'message' => 'Refund request processed successfully']);
    } else {
        echo json_encode(['status' => false, 'message' => 'Failed to process refund request']);
    }
});
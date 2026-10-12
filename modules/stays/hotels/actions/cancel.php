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
        // OWN-INVENTORY cleanup: free the availability hold/reservation so the night can
        // be resold, and void the supplier's (not-yet-paid-out) earning — neither of
        // which this action did before, so a cancelled booking used to permanently lose
        // the room AND still pay the supplier. Idempotent; no-op for API suppliers.
        if (!function_exists('stays_on_cancelled')) {
            $invLib = dirname(__DIR__, 4) . '/app/lib/stays_inventory.php';
            if (is_file($invLib)) { require_once $invLib; }
        }
        if (function_exists('stays_on_cancelled')) {
            stays_on_cancelled($db, (string) $invoice_id);
        }
        echo json_encode(['status' => true, 'message' => 'Booking cancelled successfully']);
    } else {
        echo json_encode(['status' => false, 'message' => 'Failed to cancel booking']);
    }
});
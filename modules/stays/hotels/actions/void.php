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
        // RELEASE THE AVAILABILITY HOLD + VOID THE SUPPLIER EARNING (audit HIGH).
        // cancel.php and refund.php call stays_on_cancelled() to free the consumed
        // room-night hold and void the unpaid earning; void.php did NOT, so voiding a
        // paid own-inventory stay left the room-night PERMANENTLY reserved (lost
        // inventory forever) and the earning un-voided. Mirror cancel.php. Idempotent;
        // no-op for integration suppliers.
        if (!function_exists('stays_on_cancelled')) {
            $invLib = dirname(__DIR__, 4) . '/app/lib/stays_inventory.php';
            if (is_file($invLib)) { require_once $invLib; }
        }
        if (function_exists('stays_on_cancelled')) {
            stays_on_cancelled($db, (string) $invoice_id);
        }
        echo json_encode(['status' => true, 'message' => 'Booking voided successfully']);
    } else {
        echo json_encode(['status' => false, 'message' => 'Failed to void booking']);
    }
});
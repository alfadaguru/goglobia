<?php
// path : modules/flights/duffel/actions/refund.php
// REFUND REQUEST ACTION
// This action processes flight refund requests
@$SECURE or die('Access Denied!');

$router->post('/flights/duffel/refund', function() use ($db) {
    header('Content-Type: application/json');

    // Get required data
    $invoice_id = $_POST['invoice_id'] ?? '';
    $module_type = $_POST['module_type'] ?? 'flights';

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

// Idempotency: don't refund twice.
if (($booking['payment_status'] ?? '') === 'refunded') {
    echo json_encode(['status' => true, 'message' => 'Booking is already refunded.', 'payment_status' => 'refunded']);
    exit;
}

// ACTUALLY REVERSE THE CUSTOMER'S CHARGE via the payment gateway (closes the
// DB-flip-only gap). refund_gateway_payment() returns refunded|failed|unsupported.
require_once dirname(__DIR__, 4) . '/app/lib/payment-gateway.php';
$refund = function_exists('refund_gateway_payment')
    ? refund_gateway_payment($db, $booking, null, 'Duffel flight refund')
    : ['status' => 'unsupported', 'message' => 'Refund function unavailable', 'gateway' => ''];

if ($refund['status'] === 'refunded') {
    // Money actually moved → mark refunded/cancelled.
    $db->update('bookings', [
        'payment_status'        => 'refunded',
        'booking_status'        => 'cancelled',
        'cancellation_status'   => 1,
        'cancellation_response' => 'Gateway refund ' . ($refund['reference'] ?? '') . ' on ' . date('Y-m-d H:i:s'),
    ], ['invoice_id' => $invoice_id]);
    echo json_encode([
        'status' => true,
        'message' => 'Refund completed via ' . ($refund['gateway'] ?? 'gateway') . ' (ref ' . ($refund['reference'] ?? '') . ').',
        'gateway_refund' => true,
    ]);
    exit;
}

// Gateway refund NOT completed (unsupported/failed). Do NOT claim "refunded" —
// cancel the booking and flag that the money-back is a manual step.
$db->update('bookings', [
    'booking_status'        => 'cancelled',
    'cancellation_status'   => 1,
    'cancellation_response' => 'Cancelled; gateway refund NOT automated (' . ($refund['message'] ?? 'unsupported') . '). Process the customer refund manually.',
], ['invoice_id' => $invoice_id]);

echo json_encode([
    'status' => true,
    'message' => 'Booking cancelled. Automated card refund was not possible (' . ($refund['message'] ?? 'unsupported') . '). Please refund the customer manually in the payment gateway.',
    'gateway_refund' => false,
    'gateway' => $refund['gateway'] ?? '',
]);
});

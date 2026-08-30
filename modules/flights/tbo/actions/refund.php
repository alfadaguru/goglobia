<?php
// path: modules/flights/tbo/actions/refund.php
// TBO Air refund action — refunds are handled through TBO's change-request
// desk, not via this API integration.
// POST flights/tbo/refund

@$SECURE or die('Access Denied!');

global $router;

$router->post('flights/tbo/refund', function () use ($db) {
    header('Content-Type: application/json');

    $invoiceId = trim($_POST['invoice_id'] ?? '');
    if (empty($invoiceId)) {
        echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
        exit;
    }

    $booking = $db->get('bookings', ['pnr'], ['invoice_id' => $invoiceId]);
    if (!$booking) {
        echo json_encode(['status' => false, 'message' => 'Booking not found']);
        exit;
    }

    echo json_encode([
        'status'  => false,
        'message' => 'Refunds for TBO bookings are processed through the TBO change-request desk. Please contact the operations team with PNR ' . ($booking['pnr'] ?: 'N/A') . '.',
        'data'    => ['invoice_id' => $invoiceId, 'manual_processing' => true],
    ]);
});

<?php
// path: modules/flights/tbo/actions/void.php
// TBO Air void action — voids are handled through TBO's change-request desk,
// not via this API integration.
// POST flights/tbo/void

@$SECURE or die('Access Denied!');

global $router;

$router->post('flights/tbo/void', function () use ($db) {
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
        'message' => 'Ticket void for TBO bookings is processed through the TBO change-request desk. Please contact the operations team with PNR ' . ($booking['pnr'] ?: 'N/A') . '.',
        'data'    => ['invoice_id' => $invoiceId, 'manual_processing' => true],
    ]);
});

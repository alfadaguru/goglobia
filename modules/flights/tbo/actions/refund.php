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

    $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
    if (!$booking) {
        echo json_encode(['status' => false, 'message' => 'Booking not found']);
        exit;
    }

    // TBO Air has no programmatic supplier refund (that runs through TBO's
    // change-request desk). But we CAN still return the customer's money via the
    // payment gateway — attempt that here instead of doing nothing.
    require_once dirname(__DIR__, 4) . '/app/lib/payment-gateway.php';
    $tboGwRefund = function_exists('refund_gateway_payment')
        ? refund_gateway_payment($db, $booking, null, 'TBO Air flight refund')
        : ['status' => 'unsupported', 'message' => 'Refund function unavailable', 'gateway' => ''];
    $tboGatewayRefunded = ($tboGwRefund['status'] === 'refunded');

    if ($tboGatewayRefunded) {
        $db->update('bookings', [
            'payment_status'        => 'refunded',
            'cancellation_response' => 'Gateway refund ' . ($tboGwRefund['reference'] ?? '') . ' on ' . date('Y-m-d H:i:s') . '. TBO airline refund via change-request desk.',
        ], ['invoice_id' => $invoiceId]);
        echo json_encode([
            'status'  => true,
            'message' => 'Customer refund completed via ' . ($tboGwRefund['gateway'] ?? 'gateway') . '. Note: the TBO airline-side refund is still processed through the TBO change-request desk (PNR ' . ($booking['pnr'] ?: 'N/A') . ').',
            'data'    => ['invoice_id' => $invoiceId, 'gateway_refund' => true, 'manual_processing' => true],
        ]);
        exit;
    }

    echo json_encode([
        'status'  => false,
        'message' => 'Automated card refund was not possible (' . ($tboGwRefund['message'] ?? 'unsupported') . '). TBO airline refunds are processed through the TBO change-request desk — contact operations with PNR ' . ($booking['pnr'] ?: 'N/A') . '.',
        'data'    => ['invoice_id' => $invoiceId, 'gateway_refund' => false, 'manual_processing' => true],
    ]);
});

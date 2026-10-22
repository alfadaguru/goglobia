<?php
// path : modules/flights/adivaha/actions/refund.php
// REFUND REQUEST ACTION — Adivaha B2C Flights
//
// Adivaha performs any SUPPLIER-side refund MANUALLY (spec L1189-1206: the
// cancellation charge/refund are always null, refund_mode "manual"). So this
// endpoint does the only automated thing we CAN do: reverse the CUSTOMER'S
// charge on OUR payment gateway (Paystack / wallet / etc.) via the shared
// refund_gateway_payment() — identical to every other flight provider's refund.
// When the gateway cannot refund automatically, we cancel the booking and flag
// the money-back as a manual step rather than falsely claiming "refunded".
@$SECURE or die('Access Denied!');

$router->post('/flights/adivaha/refund', function () use ($db) {
    header('Content-Type: application/json');

    $invoice_id = $_POST['invoice_id'] ?? '';
    if (empty($invoice_id)) {
        echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
        exit;
    }

    if (function_exists('supplier_action_guard') && !supplier_action_guard($invoice_id)) { return; }

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

    // Reverse the customer's charge on our gateway.
    require_once dirname(__DIR__, 4) . '/app/lib/payment-gateway.php';
    $refund = function_exists('refund_gateway_payment')
        ? refund_gateway_payment($db, $booking, null, 'Adivaha flight refund')
        : ['status' => 'unsupported', 'message' => 'Refund function unavailable', 'gateway' => ''];

    if (($refund['status'] ?? '') === 'refunded') {
        $db->update('bookings', [
            'payment_status'        => 'refunded',
            'booking_status'        => 'cancelled',
            'cancellation_status'   => 1,
            'cancellation_response' => 'Gateway refund ' . ($refund['reference'] ?? '') . ' on ' . date('Y-m-d H:i:s'),
        ], ['invoice_id' => $invoice_id]);
        echo json_encode([
            'status'         => true,
            'message'        => 'Refund completed via ' . ($refund['gateway'] ?? 'gateway') . ' (ref ' . ($refund['reference'] ?? '') . ').',
            'gateway_refund' => true,
        ]);
        exit;
    }

    // Gateway refund not completed — cancel + flag manual.
    $db->update('bookings', [
        'booking_status'        => 'cancelled',
        'cancellation_status'   => 1,
        'cancellation_response' => 'Cancelled; gateway refund NOT automated (' . ($refund['message'] ?? 'unsupported') . '). Process the customer refund manually. Adivaha supplier refund (if any) is handled manually by their team.',
    ], ['invoice_id' => $invoice_id]);

    echo json_encode([
        'status'         => true,
        'message'        => 'Booking cancelled. Automated card refund was not possible (' . ($refund['message'] ?? 'unsupported') . '). Please refund the customer manually in the payment gateway. Any Adivaha supplier refund is handled manually.',
        'gateway_refund' => false,
        'gateway'        => $refund['gateway'] ?? '',
    ]);
});

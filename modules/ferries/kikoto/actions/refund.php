<?php
// Route: POST ferries/kikoto/refund
// Kikoto has no programmatic refund endpoint — supplier-side release + any
// cancellation fee is handled by /bookings/{locator}/cancel (see cancel.php).
// The most automation possible here is to reverse the CUSTOMER's card charge via
// the payment gateway (real for Paystack/Stripe, 'unsupported' otherwise →
// manual) and record it. booking_status ENUM is confirmed|pending|cancelled —
// the money state lives in payment_status. Cancel the sailings first
// (ferries/kikoto/cancel) so the supplier side is released before refunding.

global $router;

$router->post('ferries/kikoto/refund', function () use ($db) {
    @set_time_limit(30);
    header('Content-Type: application/json; charset=utf-8');

    try {
        $invoiceId    = trim((string)($_POST['invoice_id'] ?? ''));
        $refundReason = trim((string)($_POST['refund_reason'] ?? 'Kikoto ferry refund'));
        if ($invoiceId === '') {
            echo json_encode(['status' => false, 'message' => 'invoice_id is required']);
            return;
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'ferries']);
        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found']);
            return;
        }

        if (($booking['payment_status'] ?? '') === 'refunded') {
            echo json_encode(['status' => true, 'message' => 'Booking already marked as refunded.', 'invoice_id' => $invoiceId]);
            return;
        }

        require_once dirname(__DIR__, 4) . '/app/lib/payment-gateway.php';
        $gwRefund = function_exists('refund_gateway_payment')
            ? refund_gateway_payment($db, $booking, null, $refundReason)
            : ['status' => 'unsupported', 'message' => 'Refund function unavailable', 'gateway' => ''];
        $gatewayRefunded = ($gwRefund['status'] === 'refunded');

        $db->update('bookings', [
            'booking_status'        => 'cancelled',
            'payment_status'        => $gatewayRefunded ? 'refunded' : ($booking['payment_status'] ?? 'paid'),
            'cancellation_status'   => 1,
            'cancellation_request'  => 1,
            'cancellation_response' => json_encode([
                'supplier'       => 'kikoto',
                'method'         => 'gateway_refund_plus_manual',
                'gateway_refund' => $gwRefund,
                'requested_at'   => date('Y-m-d H:i:s'),
                'reason'         => $refundReason,
            ]),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['invoice_id' => $invoiceId]);

        echo json_encode([
            'status'         => true,
            'invoice_id'     => $invoiceId,
            'gateway_refund' => $gatewayRefunded,
            'message'        => $gatewayRefunded
                ? ('Card refunded via ' . ($gwRefund['gateway'] ?? 'gateway') . '. Ensure the sailings were cancelled via ferries/kikoto/cancel first.')
                : ('Automated card refund not possible (' . ($gwRefund['message'] ?? 'unsupported') . '). Refund the customer manually per the fare cancellation policy.'),
            'note'           => 'Kikoto has no refund API; cancel the sailings via ferries/kikoto/cancel, then the customer refund follows the cancellation policy.',
        ], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['status' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
});

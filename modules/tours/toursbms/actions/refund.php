<?php
// ============================================================================
// ToursBMS — REFUND BOOKING. POST /modules/tours/toursbms/refund
//
// SUPPLIER REALITY: ToursBMS booking is local (no supplier refund API), so the
// maximum automation is to reverse the CUSTOMER's card charge via the payment
// gateway (real for Paystack/Stripe, 'unsupported' otherwise → manual) and mark
// the booking. booking_status ENUM is confirmed|pending|cancelled — money state
// lives in payment_status.
// ============================================================================
@$SECURE or die('Access Denied!');

$router->post('tours/toursbms/refund', function () use ($db) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    if (empty($_SESSION['admin_logged_in']) && strtolower($_SESSION['user_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['status' => false, 'success' => false, 'message' => 'Unauthorized']); exit;
    }

    try {
        $invoiceId    = trim((string) ($_POST['invoice_id'] ?? ''));
        $refundReason = trim((string) ($_POST['refund_reason'] ?? 'ToursBMS tour refund'));
        if ($invoiceId === '') throw new Exception('Invoice ID is required');

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'tours']);
        if (!$booking) throw new Exception('Tours booking not found');

        if (($booking['payment_status'] ?? '') === 'refunded') {
            echo json_encode(['status' => true, 'success' => true, 'message' => 'Booking already marked as refunded.']);
            exit;
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
                'supplier'       => 'toursbms',
                'method'         => 'gateway_refund_plus_manual',
                'gateway_refund' => $gwRefund,
                'requested_at'   => date('Y-m-d H:i:s'),
                'reason'         => $refundReason,
            ]),
        ], ['invoice_id' => $invoiceId]);

        echo json_encode([
            'status'         => true,
            'success'        => true,
            'gateway_refund' => $gatewayRefunded,
            'message'        => $gatewayRefunded
                ? ('Card refunded via ' . ($gwRefund['gateway'] ?? 'gateway') . '.')
                : ('Automated card refund not possible (' . ($gwRefund['message'] ?? 'unsupported') . '). Refund the customer manually.'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    } catch (\Throwable $e) {
        echo json_encode(['status' => false, 'success' => false,
            'message' => $e->getMessage(), 'response_error' => $e->getMessage()]);
        exit;
    }
});

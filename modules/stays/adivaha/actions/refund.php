<?php
// path: modules/stays/adivaha/actions/refund.php
// REFUND REQUEST ACTION — Adivaha B2C Hotels
// Adivaha performs any SUPPLIER-side refund MANUALLY. This endpoint reverses the
// CUSTOMER's charge on OUR gateway (Paystack/wallet/etc.) via the shared
// refund_gateway_payment(); when the gateway cannot refund automatically it
// cancels + flags manual (never falsely claims "refunded"). Wrapped in try/catch
// so a throw after money moves cannot leave the status unwritten (double-refund
// risk) — it flags for review instead.
@$SECURE or die('Access Denied!');

$router->post('/stays/adivaha/refund', function () use ($db) {
    header('Content-Type: application/json');

    $invoice_id = $_POST['invoice_id'] ?? '';
    if (empty($invoice_id)) {
        echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
        exit;
    }
    if (function_exists('supplier_action_guard') && !supplier_action_guard($invoice_id)) { return; }

    try {
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found']);
            exit;
        }
        if (($booking['payment_status'] ?? '') === 'refunded') {
            echo json_encode(['status' => true, 'message' => 'Booking is already refunded.', 'payment_status' => 'refunded']);
            exit;
        }

        require_once dirname(__DIR__, 4) . '/app/lib/payment-gateway.php';
        $refund = function_exists('refund_gateway_payment')
            ? refund_gateway_payment($db, $booking, null, 'Adivaha hotel refund')
            : ['status' => 'unsupported', 'message' => 'Refund function unavailable', 'gateway' => ''];

        if (($refund['status'] ?? '') === 'refunded') {
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

        $db->update('bookings', [
            'booking_status'        => 'cancelled',
            'cancellation_status'   => 1,
            'cancellation_response' => 'Cancelled; gateway refund NOT automated (' . ($refund['message'] ?? 'unsupported') . '). Process the customer refund manually. Any Adivaha supplier refund is handled manually.',
        ], ['invoice_id' => $invoice_id]);

        echo json_encode([
            'status' => true,
            'message' => 'Booking cancelled. Automated card refund was not possible (' . ($refund['message'] ?? 'unsupported') . '). Please refund the customer manually. Any Adivaha supplier refund is handled manually.',
            'gateway_refund' => false,
            'gateway' => $refund['gateway'] ?? '',
        ]);
    } catch (Throwable $e) {
        error_log('ADIVAHA HOTEL REFUND ERROR (' . $invoice_id . '): ' . $e->getMessage());
        try {
            $db->update('bookings', [
                'booking_payment_issue' => 'Refund threw an error (' . substr($e->getMessage(), 0, 160) . '). Verify at the gateway before any retry — money may already have moved.',
            ], ['invoice_id' => $invoice_id]);
        } catch (Throwable $e2) { /* ignore */ }
        echo json_encode(['status' => false, 'message' => 'Refund could not be completed cleanly — flagged for manual review. Check the payment gateway before retrying.']);
    }
});

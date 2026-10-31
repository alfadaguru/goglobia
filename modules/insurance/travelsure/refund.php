<?php
// TRAVELSURE refund — POST modules/insurance/travelsure/refund
// Mirrors the per-supplier refund.php actions: cancel the policy at the provider,
// then return the gateway money via refund_gateway_payment (the central, now
// idempotency-guarded refund helper). STUB: no provider → reports unsupported so
// the operator refunds/cancels manually (never claims a fake provider refund).
@$SECURE or die('Access Denied!');

global $router;

$router->post('insurance/travelsure/refund', function () use ($db) {
    header('Content-Type: application/json; charset=utf-8');
    while (ob_get_level()) { ob_end_clean(); }

    try {
        $invoiceId = trim((string) ($_POST['invoice_id'] ?? ''));
        if ($invoiceId === '') { throw new Exception('Invoice ID is required'); }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'insurance']);
        if (!$booking) { throw new Exception('Insurance booking not found'); }

        $cfg = _travelsure_cfg($db);

        // STUB: no live provider to cancel the policy with. We still return the
        // customer's money through the central gateway refund (that part is real
        // and idempotent), then flag the policy cancellation for manual handling.
        $supplierCancel = ['status' => 'unsupported', 'message' => 'Insurance supplier not wired — cancel the policy manually with the provider.'];
        if (function_exists('travelsure_is_configured') && travelsure_is_configured($cfg)) {
            // LIVE: $supplierCancel = _travelsure_request($db,'POST','/policy/cancel',['json'=>['policy'=>$booking['pnr']]]);
            $supplierCancel = ['status' => 'unsupported', 'message' => 'Supplier client not wired.'];
        }

        $gwRefund = ['status' => 'skipped'];
        if (($booking['payment_status'] ?? '') === 'paid') {
            require_once dirname(__DIR__, 3) . '/app/lib/payment-gateway.php';
            $gwRefund = function_exists('refund_gateway_payment')
                ? refund_gateway_payment($db, $booking, null, 'Travel insurance refund')
                : ['status' => 'unsupported', 'message' => 'Refund function unavailable'];
        }

        echo json_encode([
            'status'          => in_array(($gwRefund['status'] ?? ''), ['refunded', 'skipped'], true),
            'gateway_refund'  => $gwRefund,
            'supplier_cancel' => $supplierCancel,
            'message'         => ($gwRefund['status'] ?? '') === 'refunded'
                ? 'Payment refunded. Cancel the policy with the provider if one was issued.'
                : 'Processed. ' . ($gwRefund['message'] ?? ''),
        ], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['status' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
    }
    exit;
});

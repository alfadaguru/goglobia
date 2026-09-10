<?php
// v10/app/webhooks/xmoney.php
// xMoney/Utrust Webhook Handler
//
// SECURITY (audit P2): this endpoint marks bookings paid, so it MUST:
//   - bootstrap the app so $db exists (it is reachable as a direct file);
//   - FAIL CLOSED when no signing secret is configured (never trust unsigned);
//   - use hash_equals for the HMAC compare (no timing leak);
//   - reconcile the notified amount/currency against the booking before paying.
header('Content-Type: application/json');

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_UTRUST_SIGNATURE'] ?? '';

// Bootstrap the application (defines $db, loads config + helpers). Without this
// the file could be hit as a naked script with a null $db.
$__bootstrap = __DIR__ . '/../../config.php';
if (is_file($__bootstrap)) { require_once $__bootstrap; }
require_once __DIR__ . '/../../app/lib/payment-gateway.php';
// config.php defines $db (Medoo) in this file scope after require. Fall back to
// the global if a future bootstrap sets it there instead.
if (!isset($db) || !$db) { $db = $GLOBALS['db'] ?? null; }
if (!$db) {
    http_response_code(500);
    exit(json_encode(['error' => 'Unavailable']));
}

$event = json_decode($payload, true);
if (!$event || !isset($event['data'])) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid payload']));
}

$invoiceId = (string) ($event['data']['attributes']['reference'] ?? '');
$status    = (string) ($event['data']['attributes']['state'] ?? ''); // 'SUCCESS','CANCELLED'
if ($invoiceId === '') {
    http_response_code(400);
    exit(json_encode(['error' => 'Missing reference']));
}

// --- Signature verification (FAIL CLOSED) --------------------------------
$gateway = $db->get('payment_gateways', '*', ['name' => 'xMoney']);
$config  = get_gateway_config($gateway);
$secret  = (string) ($config['secret_key'] ?? '');
if ($secret === '') {
    // No secret configured — we cannot authenticate the caller, so refuse.
    error_log('xMoney webhook: no signing secret configured; rejecting notification.');
    http_response_code(401);
    exit(json_encode(['error' => 'Webhook not configured']));
}
$expectedSignature = hash_hmac('sha256', $payload, $secret);
if (!hash_equals($expectedSignature, (string) $signature)) {
    http_response_code(401);
    exit(json_encode(['error' => 'Invalid signature']));
}

// --- Load the booking + reconcile amount/currency ------------------------
$booking = $db->get('bookings', ['invoice_id', 'price_markup', 'currency_markup', 'payment_status'], ['invoice_id' => $invoiceId]);
if (!$booking) {
    http_response_code(404);
    exit(json_encode(['error' => 'Unknown invoice']));
}

$action = 'failure';
if ($status === 'SUCCESS') { $action = 'success'; }
elseif ($status === 'CANCELLED') { $action = 'cancel'; }

if ($action === 'success') {
    // Idempotency: if already paid, no-op.
    if (($booking['payment_status'] ?? '') === 'paid') {
        echo json_encode(['status' => 'already_paid']);
        exit;
    }
    // SECURITY: verify the notified amount/currency matches the booking.
    $attrs   = $event['data']['attributes'] ?? [];
    $paidAmt = (float) ($attrs['amount']['total'] ?? $attrs['amount'] ?? 0);
    $paidCur = strtoupper(trim((string) ($attrs['currency'] ?? ($attrs['amount']['currency'] ?? ''))));
    $expAmt  = (float) ($booking['price_markup'] ?? 0);
    $expCur  = strtoupper(trim((string) ($booking['currency_markup'] ?? '')));
    if ($paidCur !== '' && $expCur !== '' && $paidCur !== $expCur) {
        error_log("xMoney webhook: currency mismatch inv {$invoiceId} exp {$expCur} got {$paidCur}");
        http_response_code(422);
        exit(json_encode(['error' => 'Currency mismatch']));
    }
    if ($expAmt > 0 && $paidAmt > 0 && ($paidAmt + 0.01) < $expAmt) {
        error_log("xMoney webhook: underpayment inv {$invoiceId} exp {$expAmt} got {$paidAmt}");
        http_response_code(422);
        exit(json_encode(['error' => 'Amount mismatch']));
    }

    $db->update('bookings', [
        'payment_status' => 'paid',
        'booking_status' => 'confirmed',
        'transaction_id' => $event['data']['id'] ?? null,
        'paid_at'        => date('Y-m-d H:i:s'),
    ], ['invoice_id' => $invoiceId]);

    error_log("xMoney Webhook: Invoice {$invoiceId} marked as PAID.");
}

echo json_encode(['status' => 'processed']);

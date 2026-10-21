<?php
// app/routes/crons/giaPaymentsRoutes.php
// GIA per-booking payment worker. Walks PENDING GIA payments and:
//   - sends up to 5 payment reminders spread across the 20-minute window;
//   - when the window lapses unpaid, marks the payment expired + cancels the
//     still-unpaid booking (freeing seats/inventory);
//   - late money (arriving after cancellation) is auto-refunded on the webhook.
// Schedule every minute: GET /gia_payments_sweep
@$SECURE or die('Access Denied!');

$router->get('/gia_payments_sweep', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    require_once 'app/lib/ai/gia_booking_payment.php';
    $res = function_exists('gia_bp_sweep') ? gia_bp_sweep($db) : ['ok' => false];
    echo json_encode([
        'status'   => !empty($res['ok']),
        'reminded' => (int) ($res['reminded'] ?? 0),
        'expired'  => (int) ($res['expired'] ?? 0),
        'ran_at'   => date('Y-m-d H:i:s'),
    ]);
});

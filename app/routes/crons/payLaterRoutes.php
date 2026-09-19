<?php
// app/routes/crons/payLaterRoutes.php
// Pay-Later engine worker: send reminder emails per scope schedule, and at the
// deadline auto-cancel (release inventory + notify) or flag overdue per the
// scope's policy. Schedule via cron every few minutes: GET /pay_later_sweep
@$SECURE or die('Access Denied!');

$router->get('/pay_later_sweep', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    $r = function_exists('pay_later_sweep') ? pay_later_sweep($db) : ['reminded' => 0, 'cancelled' => 0, 'flagged' => 0];
    // Generic PaySmallSmall installment reminders (non-umrah) share this cron.
    $inst = function_exists('installments_reminder_sweep') ? installments_reminder_sweep($db) : 0;
    echo json_encode([
        'status'    => true,
        'reminded'  => $r['reminded'] ?? 0,
        'cancelled' => $r['cancelled'] ?? 0,
        'flagged'   => $r['flagged'] ?? 0,
        'installment_reminders' => $inst,
        'ran_at'    => date('Y-m-d H:i:s'),
    ]);
});

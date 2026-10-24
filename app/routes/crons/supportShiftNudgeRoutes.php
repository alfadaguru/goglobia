<?php
// app/routes/crons/supportShiftNudgeRoutes.php
// GIA shift-nudge worker: for every support shift TODAY whose start_time has passed
// and whose staff is NOT clocked in, WhatsApp a nudge (via SENDWHATSAPP) at shift
// start then every 15 min until clocked in, hard-capped at 3 nudges. Clocking in
// short-circuits immediately. State-guarded + idempotent (nudge_count/last_nudge_at).
// Schedule via cron every few minutes: GET /support_shift_nudge
//
// Delegates entirely to support_shift_nudge_due() in app/lib/support/shifts.php.
@$SECURE or die('Access Denied!');

$router->get('/support_shift_nudge', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    $nudged = 0; $skipped = 0;
    if (function_exists('support_shift_nudge_due')) {
        $r = support_shift_nudge_due($db);
        $nudged  = (int) ($r['nudged'] ?? 0);
        $skipped = (int) ($r['skipped'] ?? 0);
    }
    echo json_encode([
        'status'  => true,
        'nudged'  => $nudged,
        'skipped' => $skipped,
        'ran_at'  => date('Y-m-d H:i:s'),
    ]);
});

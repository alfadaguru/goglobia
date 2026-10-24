<?php
// app/routes/crons/referralReleaseRoutes.php
// Referral reward release worker: once a HOLD reward's refund-window has passed,
// credit the referrer's wallet (or reverse it if the booking was refunded) and
// notify. Schedule via cron (e.g. hourly): GET /referral_release_sweep
@$SECURE or die('Access Denied!');

$router->get('/referral_release_sweep', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    $r = function_exists('referral_release_sweep')
        ? referral_release_sweep($db)
        : ['released' => 0, 'reversed' => 0, 'skipped' => 0];
    echo json_encode([
        'status'   => true,
        'released' => $r['released'] ?? 0,
        'reversed' => $r['reversed'] ?? 0,
        'skipped'  => $r['skipped'] ?? 0,
        'ran_at'   => date('Y-m-d H:i:s'),
    ]);
});

<?php
// app/routes/crons/umrahHoldsRoutes.php
// Umrah redesign (docs/UMRAH-PHASE1-BUILD-PLAN.md §2) — hold-expiry worker.
// Expire past-deadline inventory holds so their seats return to the pool.
// Schedule via cron every few minutes: GET /umrah_expire_holds
@$SECURE or die('Access Denied!');

$router->get('/umrah_expire_holds', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    $expired = function_exists('umrah_hold_expire_sweep') ? umrah_hold_expire_sweep($db) : 0;
    echo json_encode(['status' => true, 'expired' => $expired, 'ran_at' => date('Y-m-d H:i:s')]);
});

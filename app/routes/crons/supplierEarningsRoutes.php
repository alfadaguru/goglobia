<?php
// app/routes/crons/supplierEarningsRoutes.php
// Supplier earnings clearance worker: move PENDING earnings to AVAILABLE once their
// clearance window (booking checkout + admin-configured hold_days, default 1) has
// passed, so suppliers can withdraw them. State-guarded + idempotent (no double
// release). Schedule via cron every few minutes / hourly: GET /supplier_release_earnings
//
// Reuses the existing, logged wrapper supplier_settlement_run_release() (records a
// supplier_release_runs row for the ops audit trail); falls back to the base
// supplier_earning_release_due() if the wrapper isn't present.
@$SECURE or die('Access Denied!');

$router->get('/supplier_release_earnings', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    $released = 0; $logged = false;
    if (function_exists('supplier_settlement_run_release')) {
        $r = supplier_settlement_run_release($db, 500, 'cron');
        $released = (int) ($r['released'] ?? 0);
        $logged   = !empty($r['logged']);
    } elseif (function_exists('supplier_earning_release_due')) {
        $released = (int) supplier_earning_release_due($db, 500);
    }
    echo json_encode([
        'status'   => true,
        'released' => $released,
        'logged'   => $logged,
        'ran_at'   => date('Y-m-d H:i:s'),
    ]);
});

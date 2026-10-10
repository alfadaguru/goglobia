<?php
// FILE: app/routes/admin/supplierPayoutsRoutes.php
// Admin payout queue (Phase 1 inc S19) — the ONLY place a supplier payout is
// approved and (if the kill-switch is on) SENT. ADMIN-only + CSRF on every write.
//
// The actual transfer, earnings debit, idempotency, and kill-switch all live in
// app/lib/supplier_payouts.php::supplier_payout_approve_and_send(). This route is the
// thin, authorized UI over it.

@$SECURE or die('Access Denied!');

// ----------------------------------------------------------------------------
// GET /admin/supplier-payouts — the payout queue
// ----------------------------------------------------------------------------
$router->get(admin . '/supplier-payouts', function () use ($SECURE, $db) {
    ADMIN_AUTH();

    $stateFilter = strtolower(trim((string) ($_GET['state'] ?? '')));
    $allowed = ['requested', 'approved', 'processing', 'paid', 'failed', 'rejected', 'cancelled'];
    $where = [];
    if (in_array($stateFilter, $allowed, true)) { $where['state'] = $stateFilter; }
    $where['ORDER'] = ['id' => 'DESC'];
    $where['LIMIT'] = 200;

    $payouts = $db->select('supplier_payouts',
        ['id', 'owner_user_id', 'currency', 'amount', 'state', 'account_name', 'account_number',
         'bank_code', 'reference', 'failure_reason', 'requested_at', 'paid_at'],
        $where) ?: [];

    // Owner display names (best-effort).
    $names = [];
    foreach ($payouts as $p) {
        $oid = (string) $p['owner_user_id'];
        if (!isset($names[$oid])) {
            $u = $db->get('users', ['first_name', 'last_name', 'title', 'email'], ['user_id' => $oid]);
            $names[$oid] = $u ? trim(($u['title'] ?? '') ?: (($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) . ' · ' . ($u['email'] ?? '') : $oid;
        }
    }
    $payoutsLive = function_exists('supplier_payouts_live') ? supplier_payouts_live($db) : false;

    $title = 'Supplier Payouts'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "admin/suppliers/payouts.php";
    require_once views . "includes/footer.php";
});

// ----------------------------------------------------------------------------
// POST /admin/supplier-payouts/approve — approve + (if live) send
// ----------------------------------------------------------------------------
$router->post(admin . '/supplier-payouts/approve', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::guard();
    $id = (int) ($_POST['id'] ?? 0);
    $back = root . admin . '/supplier-payouts';
    if ($id <= 0 || !function_exists('supplier_payout_approve_and_send')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invalid payout.'];
        header('Location: ' . $back); exit;
    }
    $res = supplier_payout_approve_and_send($db, $id);
    $_SESSION['message'] = [
        'type' => !empty($res['ok']) ? 'success' : 'error',
        'text' => $res['message'] ?? 'Done.',
    ];
    header('Location: ' . $back); exit;
});

// ----------------------------------------------------------------------------
// POST /admin/supplier-payouts/reject — reject + release the reservation
// ----------------------------------------------------------------------------
$router->post(admin . '/supplier-payouts/reject', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::guard();
    $id = (int) ($_POST['id'] ?? 0);
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $back = root . admin . '/supplier-payouts';
    if ($id <= 0 || !function_exists('supplier_payout_reject')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invalid payout.'];
        header('Location: ' . $back); exit;
    }
    $ok = supplier_payout_reject($db, $id, $reason);
    $_SESSION['message'] = [
        'type' => $ok ? 'success' : 'error',
        'text' => $ok ? 'Payout rejected and funds released.' : 'Could not reject (already paid/processing?).',
    ];
    header('Location: ' . $back); exit;
});

// ----------------------------------------------------------------------------
// POST /admin/supplier-payouts/resolve — resolve a stuck 'processing' payout
// (crash/timeout between the processing flip and the transfer response). Verifies
// with Paystack by reference and finalises to paid or failed. (Fix 2 / HIGH-3.)
// ----------------------------------------------------------------------------
$router->post(admin . '/supplier-payouts/resolve', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::guard();
    $id = (int) ($_POST['id'] ?? 0);
    $back = root . admin . '/supplier-payouts';
    if ($id <= 0 || !function_exists('supplier_payout_resolve_stuck')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invalid payout.'];
        header('Location: ' . $back); exit;
    }
    $res = supplier_payout_resolve_stuck($db, $id);
    $_SESSION['message'] = [
        'type' => !empty($res['ok']) ? 'success' : 'error',
        'text' => $res['message'] ?? 'Done.',
    ];
    header('Location: ' . $back); exit;
});

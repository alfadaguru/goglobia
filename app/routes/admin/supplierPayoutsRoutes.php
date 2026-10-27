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
    admin_require($db, 'supplier_payouts', 'view');

    $stateFilter = strtolower(trim((string) ($_GET['state'] ?? '')));
    $allowed = ['requested', 'approved', 'processing', 'awaiting_otp', 'paid', 'failed', 'rejected', 'cancelled'];
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

// NOTE: the duplicate POST /admin/supplier-payouts/toggle-live route (and its
// one-click form on the payouts list page) was removed — the master kill-switch
// (settings.supplier_payouts_live) is controlled only from POST
// /admin/supplier-payout-settings below (single source of truth). The payouts
// list page shows a read-only LIVE/DISABLED status pill.

// ----------------------------------------------------------------------------
// POST /admin/supplier-payouts/approve — approve + (if live) send
// ----------------------------------------------------------------------------
$router->post(admin . '/supplier-payouts/approve', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'supplier_payouts', 'edit');
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
// POST /admin/supplier-payouts/mark-paid — confirm a MANUAL (out-of-band) payout
// Only for a NON-NGN payout sitting in 'approved': there is no automated foreign
// disbursement rail (Paystack is NGN-only), so the operator pays it manually
// (bank/Wise/etc.) then confirms here. Finalising debits the reserved earnings
// 'available'->'paid' via the currency-agnostic supplier_payout_finalize_transfer.
// Hard guard: NGN payouts must NOT be marked paid this way — they MUST go through
// the real Paystack transfer (the approve/resolve path), so a manual mark can never
// bypass an actual NGN bank transfer.
// ----------------------------------------------------------------------------
$router->post(admin . '/supplier-payouts/mark-paid', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'supplier_payouts', 'edit');
    CSRF::guard();
    $id = (int) ($_POST['id'] ?? 0);
    $note = trim((string) ($_POST['note'] ?? ''));
    $back = root . admin . '/supplier-payouts';
    if ($id <= 0 || !function_exists('supplier_payout_finalize_transfer')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invalid payout.'];
        header('Location: ' . $back); exit;
    }
    $p = $db->get('supplier_payouts', ['state', 'currency'], ['id' => $id]);
    if (!$p) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Payout not found.'];
        header('Location: ' . $back); exit;
    }
    if (strtoupper((string) $p['currency']) === 'NGN') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'NGN payouts must be sent via the Paystack transfer (Approve/Resolve), not marked paid manually.'];
        header('Location: ' . $back); exit;
    }
    if ((string) $p['state'] !== 'approved') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Only an approved (recorded) payout can be marked manually paid (state: ' . $p['state'] . ').'];
        header('Location: ' . $back); exit;
    }
    $res = supplier_payout_finalize_transfer($db, $id, 'success', '', 'manual ' . strtoupper((string) $p['currency']) . ' disbursement' . ($note !== '' ? ': ' . $note : ''));
    $_SESSION['message'] = [
        'type' => !empty($res['ok']) ? 'success' : 'error',
        'text' => !empty($res['ok']) ? 'Marked paid (manual disbursement recorded).' : ($res['message'] ?? 'Could not mark paid.'),
    ];
    header('Location: ' . $back); exit;
});

// ----------------------------------------------------------------------------
// POST /admin/supplier-payouts/reject — reject + release the reservation
// ----------------------------------------------------------------------------
$router->post(admin . '/supplier-payouts/reject', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'supplier_payouts', 'edit');
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
    admin_require($db, 'supplier_payouts', 'edit');
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

// ----------------------------------------------------------------------------
// POST /admin/supplier-payouts/otp — submit the Paystack OTP to finalise a transfer
// parked in 'awaiting_otp' (account has per-transfer OTP enabled).
// ----------------------------------------------------------------------------
$router->post(admin . '/supplier-payouts/otp', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'supplier_payouts', 'edit');
    CSRF::guard();
    $id = (int) ($_POST['id'] ?? 0);
    $otp = trim((string) ($_POST['otp'] ?? ''));
    $back = root . admin . '/supplier-payouts';
    if ($id <= 0 || !function_exists('supplier_payout_submit_otp')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invalid payout.'];
        header('Location: ' . $back); exit;
    }
    $res = supplier_payout_submit_otp($db, $id, $otp);
    $_SESSION['message'] = [
        'type' => !empty($res['ok']) ? 'success' : 'error',
        'text' => $res['message'] ?? 'Done.',
    ];
    header('Location: ' . $back); exit;
});

// ----------------------------------------------------------------------------
// POST /admin/supplier-payouts/resend-otp — ask Paystack to resend the transfer OTP.
// ----------------------------------------------------------------------------
$router->post(admin . '/supplier-payouts/resend-otp', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'supplier_payouts', 'edit');
    CSRF::guard();
    $id = (int) ($_POST['id'] ?? 0);
    $back = root . admin . '/supplier-payouts';
    if ($id <= 0 || !function_exists('supplier_payout_resend_otp')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invalid payout.'];
        header('Location: ' . $back); exit;
    }
    $res = supplier_payout_resend_otp($db, $id);
    $_SESSION['message'] = [
        'type' => !empty($res['ok']) ? 'success' : 'error',
        'text' => $res['message'] ?? 'Done.',
    ];
    header('Location: ' . $back); exit;
});

// ----------------------------------------------------------------------------
// GET /admin/supplier-payout-settings — payout rules + enable kill-switch
// ----------------------------------------------------------------------------
$router->get(admin . '/supplier-payout-settings', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'supplier_payouts', 'view');
    if (function_exists('supplier_payouts_ext_ensure_schema')) { supplier_payouts_ext_ensure_schema($db); }

    $settings  = function_exists('supplier_payout_settings') ? supplier_payout_settings($db) : ['enabled' => false, 'methods' => ['bank_transfer'], 'currencies' => []];
    $defaults  = function_exists('supplier_payout_settings_defaults') ? supplier_payout_settings_defaults() : [];
    // Currencies offered for configuration: those already configured + the wired ones.
    $countries = function_exists('supplier_payout_countries') ? supplier_payout_countries() : [];

    $title = 'Payout settings'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "admin/suppliers/payout-settings.php";
    require_once views . "includes/footer.php";
});

// ----------------------------------------------------------------------------
// POST /admin/supplier-payout-settings — save payout rules + toggle
// ----------------------------------------------------------------------------
$router->post(admin . '/supplier-payout-settings', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'supplier_payouts', 'edit');
    CSRF::guard();
    $back = root . admin . '/supplier-payout-settings';
    if (!function_exists('supplier_payout_settings_save')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Payout settings are unavailable.'];
        header('Location: ' . $back); exit;
    }
    // Form posts a per-currency grid under rules[CUR][key]; enable is a checkbox.
    $rules = (isset($_POST['rules']) && is_array($_POST['rules'])) ? $_POST['rules'] : [];
    $methods = (isset($_POST['methods']) && is_array($_POST['methods'])) ? $_POST['methods'] : ['bank_transfer'];
    $res = supplier_payout_settings_save($db, [
        'enabled'    => !empty($_POST['enabled']),
        'methods'    => $methods,
        'currencies' => $rules,
    ]);
    $_SESSION['message'] = [
        'type' => !empty($res['ok']) ? 'success' : 'error',
        'text' => $res['message'] ?? 'Done.',
    ];
    header('Location: ' . $back); exit;
});

<?php
// FILE: app/routes/users/supplierSettlementExtRoutes.php
// GOGLOBIA EARNINGS & SETTLEMENT — deepen routes (PMS catalogue §2 #40). The OWNER-side
// surface for the settlement gaps built in app/lib/supplier_settlement_ext.php, layered
// on the existing payout UI (supplier/payouts) and GL finance (supplier/finance).
//
// Scope split (deliberate):
//   - Destination verify, statement, marketplace-fee report, release-run log VIEW:
//       OWNER action (payouts/finance are owner-only, mirrors supplierPayoutsRoutes).
//   - Chargeback APPLY and release-due RUN are sensitive money/ops actions → ADMIN only.
//       (A supplier must never be able to run their own clawback or force a release.)
//
// AUTH: every handler SUPPLIER_OR_STAFF_AUTH($db). Every POST additionally CSRF::guard()
// + an authorization gate (supplier_can(...) for owner reads; supplier_is_admin() for the
// admin-only money/ops writes). No client amount is ever trusted — the lib derives all
// money from server-stored earnings. Routes only pass invoice ids / windows.

@$SECURE or die('Access Denied!');

if (!function_exists('_settlement_ext_owner')) {
    /** Resolve the acting OWNER user_id (owner or staff→parent), or null if not a
     *  supplier context. Admin resolves to '' (handled per-route). */
    function _settlement_ext_owner($db): ?string
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null) { return null; }
        if (!empty($ctx['is_admin'])) { return ''; } // admin: no single owner
        return (string) $ctx['owner'];
    }
}

if (!function_exists('_settlement_ext_window')) {
    /** Validated [from,to] 'Y-m-d' window from the query, default = current month. */
    function _settlement_ext_window(): array
    {
        $from = (string) ($_GET['from'] ?? '');
        $to   = (string) ($_GET['to'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-m-01'); }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to = date('Y-m-d'); }
        return [$from, $to];
    }
}

// ----------------------------------------------------------------------------
// GET /supplier/settlement — statement + marketplace fee + destination + runs
// ----------------------------------------------------------------------------
$router->get('/supplier/settlement', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _settlement_ext_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    // Owner-only screen (finance). Staff without owner context are bounced to dashboard.
    if ($owner === '') {
        // Admin viewing: no single owner — send to the admin payout queue instead.
        header('Location: ' . root . 'admin/supplier-payouts'); exit;
    }
    $ctx = supplier_acting_context($db);
    if (empty($ctx['is_owner'])) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Only the supplier account owner can view settlement.'];
        header('Location: ' . root . 'supplier/dashboard'); exit;
    }

    [$from, $to] = _settlement_ext_window();
    // Currency defaults to the owner's main available currency (first key) or NGN.
    $available = function_exists('supplier_payable_available') ? supplier_payable_available($db, $owner) : [];
    $currency  = strtoupper((string) ($_GET['currency'] ?? ''));
    if ($currency === '') { $currency = $available ? (string) array_key_first($available) : 'NGN'; }

    $statement   = function_exists('supplier_settlement_statement') ? supplier_settlement_statement($db, $owner, $currency, $from, $to) : [];
    $feeReport   = function_exists('supplier_marketplace_fee_report') ? supplier_marketplace_fee_report($db, $owner, $from, $to) : [];
    $destination = function_exists('supplier_payout_destination') ? supplier_payout_destination($db, $owner) : [];
    $chargebacks = function_exists('supplier_chargebacks_for_owner') ? supplier_chargebacks_for_owner($db, $owner, 20) : [];
    $summary     = function_exists('supplier_earning_summary') ? supplier_earning_summary($db, $owner) : [];
    $currencies  = array_keys($available) ?: [$currency];

    $title = 'Settlement'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/settlement-ext.php";
    require_once views . "includes/footer.php";
});

// ----------------------------------------------------------------------------
// GET /supplier/settlement/statement.csv — CSV export of the period statement
// ----------------------------------------------------------------------------
$router->get('/supplier/settlement/statement.csv', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _settlement_ext_owner($db);
    if ($owner === null || $owner === '') { header('Location: ' . root . 'login'); exit; }
    $ctx = supplier_acting_context($db);
    if (empty($ctx['is_owner'])) { header('Location: ' . root . 'supplier/dashboard'); exit; }

    [$from, $to] = _settlement_ext_window();
    $currency = strtoupper((string) ($_GET['currency'] ?? 'NGN')) ?: 'NGN';
    $stmt = function_exists('supplier_settlement_statement') ? supplier_settlement_statement($db, $owner, $currency, $from, $to) : ['lines' => []];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="settlement_' . $currency . '_' . $from . '_' . $to . '.csv"');
    $fh = fopen('php://output', 'w');
    fputcsv($fh, ['Date', 'Type', 'Reference', 'Amount (' . $currency . ')', 'Note']);
    foreach (($stmt['lines'] ?? []) as $ln) {
        fputcsv($fh, [$ln['date'] ?? '', $ln['type'] ?? '', $ln['reference'] ?? '',
                      number_format((float) ($ln['amount'] ?? 0), 2, '.', ''), $ln['note'] ?? '']);
    }
    fputcsv($fh, []);
    fputcsv($fh, ['Released', number_format((float) ($stmt['released'] ?? 0), 2, '.', '')]);
    fputcsv($fh, ['Paid out', number_format((float) ($stmt['paid_out'] ?? 0), 2, '.', '')]);
    fputcsv($fh, ['Charged back', number_format((float) ($stmt['charged_back'] ?? 0), 2, '.', '')]);
    fputcsv($fh, ['Platform commission', number_format((float) ($stmt['commission'] ?? 0), 2, '.', '')]);
    fclose($fh);
    exit;
});

// ----------------------------------------------------------------------------
// POST /supplier/settlement/verify-destination — resolve bank account (DB-GATED)
//   Owner action. The lib HONESTLY reports 'not connected' if no gateway is live.
// ----------------------------------------------------------------------------
$router->post('/supplier/settlement/verify-destination', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _settlement_ext_owner($db);
    $ctx = supplier_acting_context($db);
    $back = root . 'supplier/settlement';
    if ($owner === null || $owner === '' || empty($ctx['is_owner'])) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Only the account owner can verify payout details.'];
        header('Location: ' . root . 'supplier/dashboard'); exit;
    }
    if (!function_exists('supplier_payout_verify_destination')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Verification is unavailable.'];
        header('Location: ' . $back); exit;
    }
    $res = supplier_payout_verify_destination($db, $owner);
    // 'connected'=false is informational, not a failure of the user's input.
    $type = !empty($res['ok']) ? 'success' : (isset($res['connected']) && $res['connected'] === false ? 'info' : 'error');
    $_SESSION['message'] = ['type' => $type, 'text' => $res['message'] ?? 'Verification attempted.'];
    header('Location: ' . $back); exit;
});

// ----------------------------------------------------------------------------
// POST /supplier/settlement/chargeback — ADMIN-ONLY clawback of a booking earning
//   Sensitive money action: a supplier can never run their own clawback. Amount is
//   derived from the server-stored earning inside the lib — never from the client.
// ----------------------------------------------------------------------------
$router->post('/supplier/settlement/chargeback', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $back = root . 'supplier/settlement';
    if (!supplier_is_admin()) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Only an administrator can apply a chargeback.'];
        header('Location: ' . root . 'supplier/dashboard'); exit;
    }
    $invoiceId = trim((string) ($_POST['invoice_id'] ?? ''));
    $reason    = (string) ($_POST['reason'] ?? '');
    if ($invoiceId === '') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'An invoice id is required.'];
        header('Location: ' . $back); exit;
    }
    if (!function_exists('supplier_chargeback_apply')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Chargebacks are unavailable.'];
        header('Location: ' . $back); exit;
    }
    $res = supplier_chargeback_apply($db, $invoiceId, $reason, (string) ($_SESSION['user_id'] ?? ''));
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error',
                            'text' => $res['message'] ?? 'Chargeback attempted.'];
    if (!empty($res['ok']) && function_exists('emit_event')) {
        emit_event($db, 'settlement.chargeback', ['invoice_id' => $invoiceId, 'mode' => $res['mode'] ?? '', 'amount' => $res['amount'] ?? 0],
            'supplier_chargeback', $res['chargeback_id'] ?? null, !empty($res['org_id']) ? (int) $res['org_id'] : null);
    }
    header('Location: ' . $back); exit;
});

// ----------------------------------------------------------------------------
// POST /supplier/settlement/run-release — ADMIN-ONLY: run the release-due sweep now
//   Thin wrapper over the existing release cron; logs the run. Ops convenience so an
//   operator can release cleared earnings without waiting for the scheduled cron.
// ----------------------------------------------------------------------------
$router->post('/supplier/settlement/run-release', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $back = root . 'supplier/settlement';
    if (!supplier_is_admin()) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Only an administrator can run the release sweep.'];
        header('Location: ' . root . 'supplier/dashboard'); exit;
    }
    if (!function_exists('supplier_settlement_run_release')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Release runner is unavailable.'];
        header('Location: ' . $back); exit;
    }
    $res = supplier_settlement_run_release($db, 500, 'manual');
    $_SESSION['message'] = ['type' => 'success',
        'text' => 'Release sweep complete — ' . (int) ($res['released'] ?? 0) . ' earning(s) moved to available.'];
    header('Location: ' . $back); exit;
});

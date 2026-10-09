<?php
// FILE: app/routes/users/supplierPayoutsRoutes.php
// Supplier payout UI (Phase 1 inc S19) — the OWNER side only (save bank details +
// request a payout + see payout history). The actual money is sent only by an ADMIN
// approving (app/routes/admin/supplierPayoutsRoutes.php) and only when the
// settings.supplier_payouts_live kill-switch is on.
//
// SECURITY: owner-only (SUPPLIER_AUTH — not staff; payouts are an owner action).
// Every write is CSRF-guarded. Amount is re-validated server-side against the owner's
// unreserved available balance inside supplier_payout_request() (locked). The supplier
// can NEVER move money — only request; approval + transfer are admin + kill-switch.

@$SECURE or die('Access Denied!');

if (!function_exists('_supplier_payouts_owner')) {
    function _supplier_payouts_owner($db): ?string
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { return null; }
        // Payouts are an OWNER action — a staff member (non-owner) cannot request.
        if (empty($ctx['is_owner'])) { return null; }
        return (string) $ctx['owner'];
    }
}

// ----------------------------------------------------------------------------
// GET /supplier/payouts — bank details + available balance + request + history
// ----------------------------------------------------------------------------
$router->get('/supplier/payouts', function () use ($SECURE, $db) {
    SUPPLIER_AUTH();
    $owner = _supplier_payouts_owner($db);
    if ($owner === null) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Only the supplier account owner can manage payouts.'];
        header('Location: ' . root . 'supplier/dashboard'); exit;
    }

    $user = $db->get('users', ['payout_bank_code', 'payout_account_number', 'payout_account_name'], ['user_id' => $owner]);
    $available = function_exists('supplier_payable_available') ? supplier_payable_available($db, $owner) : [];
    $summary   = function_exists('supplier_earning_summary') ? supplier_earning_summary($db, $owner) : [];
    $payouts   = $db->select('supplier_payouts',
        ['id', 'currency', 'amount', 'state', 'reference', 'failure_reason', 'requested_at', 'paid_at'],
        ['owner_user_id' => $owner, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 50]) ?: [];
    $payoutsLive = function_exists('supplier_payouts_live') ? supplier_payouts_live($db) : false;

    $title = 'Payouts'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/payouts.php";
    require_once views . "includes/footer.php";
});

// ----------------------------------------------------------------------------
// POST /supplier/payouts/bank — save bank details
// ----------------------------------------------------------------------------
$router->post('/supplier/payouts/bank', function () use ($SECURE, $db) {
    SUPPLIER_AUTH();
    CSRF::guard();
    $owner = _supplier_payouts_owner($db);
    if ($owner === null) { header('Location: ' . root . 'supplier/dashboard'); exit; }

    $bankCode = preg_replace('/[^0-9]/', '', (string) ($_POST['bank_code'] ?? ''));
    $acctNo   = preg_replace('/[^0-9]/', '', (string) ($_POST['account_number'] ?? ''));
    $acctName = trim((string) ($_POST['account_name'] ?? ''));
    $back = root . 'supplier/payouts';

    if ($bankCode === '' || $acctNo === '') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Bank code and account number are required.'];
        header('Location: ' . $back); exit;
    }
    try {
        // Changing bank details invalidates any cached Paystack recipient_code.
        $db->update('users', [
            'payout_bank_code'      => $bankCode,
            'payout_account_number' => $acctNo,
            'payout_account_name'   => $acctName !== '' ? $acctName : null,
            'payout_recipient_code' => null,
        ], ['user_id' => $owner]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Bank details saved.'];
    } catch (\Throwable $e) {
        error_log('supplier payout bank save: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not save bank details.'];
    }
    header('Location: ' . $back); exit;
});

// ----------------------------------------------------------------------------
// POST /supplier/payouts/request — request a payout from available balance
// ----------------------------------------------------------------------------
$router->post('/supplier/payouts/request', function () use ($SECURE, $db) {
    SUPPLIER_AUTH();
    CSRF::guard();
    $owner = _supplier_payouts_owner($db);
    if ($owner === null) { header('Location: ' . root . 'supplier/dashboard'); exit; }
    $back = root . 'supplier/payouts';

    $amount   = round((float) ($_POST['amount'] ?? 0), 2);
    $currency = strtoupper(trim((string) ($_POST['currency'] ?? 'NGN')));

    if (!function_exists('supplier_payout_request')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Payouts are unavailable.'];
        header('Location: ' . $back); exit;
    }
    // The request helper re-validates the amount against the LOCKED available balance;
    // the client amount is only an upper request, never trusted as the truth.
    $res = supplier_payout_request($db, $owner, $amount, $currency);
    $_SESSION['message'] = [
        'type' => !empty($res['ok']) ? 'success' : 'error',
        'text' => $res['message'] ?? (!empty($res['ok']) ? 'Payout requested.' : 'Could not request payout.'),
    ];
    if (!empty($res['ok']) && function_exists('emit_event')) {
        emit_event($db, 'payout.requested', ['payout_id' => $res['payout_id'] ?? 0, 'amount' => $amount, 'currency' => $currency],
            'supplier_payout', $res['payout_id'] ?? null);
    }
    header('Location: ' . $back); exit;
});

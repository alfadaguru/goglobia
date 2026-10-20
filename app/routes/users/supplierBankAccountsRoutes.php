<?php
// FILE: app/routes/users/supplierBankAccountsRoutes.php
// Supplier bank-account management — multi-account, multi-country (NG/Paystack live).
// OWNER-side surface over app/lib/supplier_payouts_ext.php. Payouts are an owner action,
// so this mirrors supplierPayoutsRoutes auth: SUPPLIER_AUTH() + owner-only (not staff),
// CSRF on every write. No money moves here — this only manages the destination accounts
// a payout can later be sent to. Account names are ALWAYS Paystack-resolved (the lib
// refuses to save an unverifiable account).

@$SECURE or die('Access Denied!');

if (!function_exists('_supplier_bank_owner')) {
    /** The acting OWNER user_id, or null if the context isn't a supplier owner.
     *  Staff (non-owner) and admin cannot manage payout destinations. */
    function _supplier_bank_owner($db): ?string
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { return null; }
        if (empty($ctx['is_owner'])) { return null; }
        return (string) $ctx['owner'];
    }
}

// ----------------------------------------------------------------------------
// GET /supplier/bank-accounts — list accounts + add form
// ----------------------------------------------------------------------------
// Bank-account management is now INLINE on the Payouts hub (/supplier/payouts).
// This page route redirects there; the JSON + action endpoints below (banks/resolve/
// add/default/remove) remain and are what the hub's add-account form calls.
$router->get('/supplier/bank-accounts', function () use ($SECURE, $db) {
    SUPPLIER_AUTH();
    header('Location: ' . root . 'supplier/payouts'); exit;
});

// ----------------------------------------------------------------------------
// GET /supplier/bank-accounts/banks?country=NG — JSON bank list (for the dropdown)
// ----------------------------------------------------------------------------
$router->get('/supplier/bank-accounts/banks', function () use ($SECURE, $db) {
    SUPPLIER_AUTH();
    $owner = _supplier_bank_owner($db);
    header('Content-Type: application/json');
    if ($owner === null) { echo json_encode(['ok' => false, 'message' => 'Not allowed']); exit; }
    $country = strtoupper(trim((string) ($_GET['country'] ?? 'NG'))) ?: 'NG';
    $res = function_exists('supplier_payout_bank_list')
        ? supplier_payout_bank_list($db, $country)
        : ['ok' => false, 'message' => 'Unavailable'];
    echo json_encode($res); exit;
});

// ----------------------------------------------------------------------------
// POST /supplier/bank-accounts/resolve — JSON account-name resolve (before save)
// ----------------------------------------------------------------------------
$router->post('/supplier/bank-accounts/resolve', function () use ($SECURE, $db) {
    SUPPLIER_AUTH();
    header('Content-Type: application/json');
    $owner = _supplier_bank_owner($db);
    if ($owner === null) { echo json_encode(['ok' => false, 'message' => 'Not allowed']); exit; }
    if (!CSRF::validateToken(CSRF::tokenFromRequest())) {
        echo json_encode(['ok' => false, 'message' => 'Invalid request token.']); exit;
    }
    // Rate-limit: /resolve calls Paystack's account lookup, which could be abused to
    // enumerate account-number -> holder-name. Cap at 20 lookups per rolling 60s per
    // session. Self-contained session-window counter (no external infra).
    $nowTs = time();
    $win = $_SESSION['ba_resolve_win'] ?? ['start' => $nowTs, 'count' => 0];
    if (!is_array($win) || ($nowTs - (int) ($win['start'] ?? 0)) >= 60) {
        $win = ['start' => $nowTs, 'count' => 0];
    }
    if ((int) $win['count'] >= 20) {
        echo json_encode(['ok' => false, 'message' => 'Too many lookups. Please wait a minute and try again.']); exit;
    }
    $win['count'] = (int) $win['count'] + 1;
    $_SESSION['ba_resolve_win'] = $win;

    $bankCode = (string) ($_POST['bank_code'] ?? '');
    $acctNo   = (string) ($_POST['account_number'] ?? '');
    $res = function_exists('supplier_payout_resolve_account')
        ? supplier_payout_resolve_account($db, $bankCode, $acctNo)
        : ['ok' => false, 'message' => 'Unavailable'];
    echo json_encode($res); exit;
});

// ----------------------------------------------------------------------------
// POST /supplier/bank-accounts/add — add + verify an account
// ----------------------------------------------------------------------------
$router->post('/supplier/bank-accounts/add', function () use ($SECURE, $db) {
    SUPPLIER_AUTH();
    CSRF::guard();
    $owner = _supplier_bank_owner($db);
    $back = root . 'supplier/bank-accounts';
    if ($owner === null) { header('Location: ' . root . 'supplier/dashboard'); exit; }

    $res = function_exists('supplier_bank_account_add')
        ? supplier_bank_account_add($db, $owner, [
            'country'        => $_POST['country'] ?? 'NG',
            'method'         => $_POST['method'] ?? 'bank_transfer',
            'bank_code'      => $_POST['bank_code'] ?? '',
            'bank_name'      => $_POST['bank_name'] ?? '',
            'account_number' => $_POST['account_number'] ?? '',
            'make_default'   => !empty($_POST['make_default']),
          ])
        : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = [
        'type' => !empty($res['ok']) ? 'success' : 'error',
        'text' => $res['message'] ?? 'Done.',
    ];
    header('Location: ' . $back); exit;
});

// ----------------------------------------------------------------------------
// POST /supplier/bank-accounts/default — set an account as default
// ----------------------------------------------------------------------------
$router->post('/supplier/bank-accounts/default', function () use ($SECURE, $db) {
    SUPPLIER_AUTH();
    CSRF::guard();
    $owner = _supplier_bank_owner($db);
    $back = root . 'supplier/bank-accounts';
    if ($owner === null) { header('Location: ' . root . 'supplier/dashboard'); exit; }
    $id = (int) ($_POST['id'] ?? 0);
    $ok = function_exists('supplier_bank_account_set_default') && supplier_bank_account_set_default($db, $owner, $id);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error',
        'text' => $ok ? 'Default account updated.' : 'Could not update the default account.'];
    header('Location: ' . $back); exit;
});

// ----------------------------------------------------------------------------
// POST /supplier/bank-accounts/remove — soft-remove an account
// ----------------------------------------------------------------------------
$router->post('/supplier/bank-accounts/remove', function () use ($SECURE, $db) {
    SUPPLIER_AUTH();
    CSRF::guard();
    $owner = _supplier_bank_owner($db);
    $back = root . 'supplier/bank-accounts';
    if ($owner === null) { header('Location: ' . root . 'supplier/dashboard'); exit; }
    $id = (int) ($_POST['id'] ?? 0);
    $ok = function_exists('supplier_bank_account_remove') && supplier_bank_account_remove($db, $owner, $id);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error',
        'text' => $ok ? 'Account removed.' : 'Could not remove the account.'];
    header('Location: ' . $back); exit;
});

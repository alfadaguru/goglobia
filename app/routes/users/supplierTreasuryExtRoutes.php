<?php
// FILE: app/routes/users/supplierTreasuryExtRoutes.php
// CASH, BANK & TREASURY (PMS module #37) routes — the internal cash/bank register UI.
// Surfaces app/lib/supplier_treasury_ext.php: treasury accounts, cash movements, cash
// transfers, petty cash, internal reconciliation, cash-position + a simple forecast.
//
// AUTH model: this is WHOLE-BUSINESS money (not property-scoped), so — exactly like
// supplierFinanceRoutes.php (#34) — it is OWNER-ONLY. Staff access would need a finance
// permission the role catalogue marks DORMANT (supplier_permissions_catalogue()['finance']
// active=false), so we do not grant staff here. Every POST runs SUPPLIER_OR_STAFF_AUTH +
// CSRF::guard() and then re-asserts owner-only. No client amount is trusted: the lib
// re-derives every GL line and posts balanced entries only.

@$SECURE or die('Access Denied!');

if (!function_exists('_treasury_ctx_or_deny')) {
    /** Resolve the acting owner + org for treasury, or redirect/deny. Returns
     *  [$owner, $orgId] on success. Owner-only (whole-business money). */
    function _treasury_ctx_or_deny($db): array
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { header('Location: ' . root . 'login'); exit; }
        if (empty($ctx['is_owner'])) { _supplier_stays_deny('Only the account owner can manage treasury.'); }
        $owner = (string) $ctx['owner'];
        $orgId = function_exists('supplier_org_for_owner') ? supplier_org_for_owner($db, $owner) : 0;
        if ($orgId <= 0 && function_exists('supplier_org_ensure')) { $orgId = supplier_org_ensure($db, $owner); }
        return [$owner, (int) $orgId];
    }
}

// ---- DASHBOARD: accounts + cash position + forecast + bank-feed status ----------
$router->get('/supplier/treasury', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    [$owner, $orgId] = _treasury_ctx_or_deny($db);

    $position   = ($orgId > 0 && function_exists('treasury_cash_position')) ? treasury_cash_position($db, $orgId) : ['accounts' => [], 'by_currency' => [], 'by_kind' => [], 'total_cleared' => [], 'as_of' => date('Y-m-d H:i:s')];
    $forecast   = ($orgId > 0 && function_exists('treasury_cash_forecast')) ? treasury_cash_forecast($db, $orgId) : ['opening' => [], 'ar_in' => 0, 'ap_out' => 0, 'projected' => [], 'drivers' => [], 'note' => ''];
    $bankImport = function_exists('treasury_bank_import_status') ? treasury_bank_import_status($db, $orgId) : ['connected' => false, 'message' => '', 'manual_entry' => true];
    $accounts   = $position['accounts'] ?? [];
    $kinds      = treasury_account_kinds();

    // Optional focused account for the register + reconciliation panels.
    $accountId  = (int) ($_GET['account'] ?? 0);
    $movements  = []; $recon = null; $focus = null;
    if ($accountId > 0) {
        $focus = function_exists('treasury_account_get') ? treasury_account_get($db, $orgId, $accountId) : null;
        if ($focus) {
            $movements = function_exists('treasury_movements_list') ? treasury_movements_list($db, $orgId, $accountId) : [];
            $stmt = isset($_GET['statement']) && $_GET['statement'] !== '' ? (float) $_GET['statement'] : null;
            $recon = function_exists('treasury_reconciliation_summary') ? treasury_reconciliation_summary($db, $orgId, $accountId, $stmt) : null;
        }
    }

    $title = 'Cash & treasury'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/treasury-ext.php";
    require_once views . "includes/footer.php";
});

// ---- CREATE a treasury account (bank / cash / petty-cash) -----------------------
$router->post('/supplier/treasury/account/create', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$owner, $orgId] = _treasury_ctx_or_deny($db);
    $res = ($orgId > 0 && function_exists('treasury_account_create'))
        ? treasury_account_create($db, $orgId, (string) ($_POST['name'] ?? ''), [
            'kind'                 => (string) ($_POST['kind'] ?? 'bank'),
            'currency'             => (string) ($_POST['currency'] ?? 'USD'),
            'bank_name'            => (string) ($_POST['bank_name'] ?? ''),
            'account_number_last4' => (string) ($_POST['account_number_last4'] ?? ''),
            'opening_balance'      => (float) ($_POST['opening_balance'] ?? 0),
          ])
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => !empty($res['ok']) ? 'Account saved.' : (string) ($res['message'] ?? 'Could not save.')];
    header('Location: ' . root . 'supplier/treasury'); exit;
});

// ---- RECORD a cash movement (deposit / withdrawal / petty expense) --------------
$router->post('/supplier/treasury/movement', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$owner, $orgId] = _treasury_ctx_or_deny($db);
    $accountId = (int) ($_POST['account_id'] ?? 0);
    $type = (string) ($_POST['type'] ?? '');
    $res = ($orgId > 0 && function_exists('treasury_record_movement'))
        ? treasury_record_movement($db, $orgId, $accountId, $type, (float) ($_POST['amount'] ?? 0), [
            'description'  => (string) ($_POST['description'] ?? ''),
            'counter_code' => (string) ($_POST['counter_code'] ?? ''),
          ])
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => !empty($res['ok']) ? 'Movement recorded.' : (string) ($res['message'] ?? 'Could not record.')];
    header('Location: ' . root . 'supplier/treasury?account=' . $accountId); exit;
});

// ---- CASH TRANSFER between two accounts -----------------------------------------
$router->post('/supplier/treasury/transfer', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$owner, $orgId] = _treasury_ctx_or_deny($db);
    $res = ($orgId > 0 && function_exists('treasury_transfer'))
        ? treasury_transfer($db, $orgId, (int) ($_POST['from_id'] ?? 0), (int) ($_POST['to_id'] ?? 0), (float) ($_POST['amount'] ?? 0), [
            'description' => (string) ($_POST['description'] ?? ''),
          ])
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => !empty($res['ok']) ? 'Transfer posted.' : (string) ($res['message'] ?? 'Could not transfer.')];
    header('Location: ' . root . 'supplier/treasury'); exit;
});

// ---- PETTY-CASH top-up (transfer from a bank/cash account) ----------------------
$router->post('/supplier/treasury/petty/topup', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$owner, $orgId] = _treasury_ctx_or_deny($db);
    $res = ($orgId > 0 && function_exists('treasury_petty_topup'))
        ? treasury_petty_topup($db, $orgId, (int) ($_POST['from_id'] ?? 0), (int) ($_POST['petty_id'] ?? 0), (float) ($_POST['amount'] ?? 0), [
            'description' => (string) ($_POST['description'] ?? ''),
          ])
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => !empty($res['ok']) ? 'Petty-cash topped up.' : (string) ($res['message'] ?? 'Could not top up.')];
    header('Location: ' . root . 'supplier/treasury'); exit;
});

// ---- RECONCILE: toggle a register movement as cleared ---------------------------
$router->post('/supplier/treasury/reconcile', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$owner, $orgId] = _treasury_ctx_or_deny($db);
    $movementId = (int) ($_POST['movement_id'] ?? 0);
    $accountId  = (int) ($_POST['account_id'] ?? 0);
    $cleared = !empty($_POST['cleared']);
    $ok = ($orgId > 0 && function_exists('treasury_reconcile_toggle'))
        ? treasury_reconcile_toggle($db, $orgId, $movementId, $cleared)
        : false;
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Reconciliation updated.' : 'Could not update.'];
    header('Location: ' . root . 'supplier/treasury?account=' . $accountId); exit;
});

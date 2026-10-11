<?php
// FILE: app/routes/users/supplierTrustExtRoutes.php
// MODULE 50 DEEPEN — Trust & client-money: structured bonds/deposits, management-fee
// postings, three-way trust reconciliation, client-fund audit. Owner-only (org-scoped
// client money), same gate as finance/corporate/trust. Reuses _corp_org() from
// supplierCorporateRoutes.php (loaded alongside) with a guarded fallback so load-order
// can't fatal. Every POST: SUPPLIER_OR_STAFF_AUTH + CSRF::guard() + supplier_can finance.

@$SECURE or die('Access Denied!');

if (!function_exists('_trust_ext_org')) {
    function _trust_ext_org($db): int
    {
        if (function_exists('_corp_org')) { return (int) _corp_org($db); }
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin']) || empty($ctx['is_owner'])) { return 0; }
        return function_exists('supplier_org_for_owner') ? (int) supplier_org_for_owner($db, (string) $ctx['owner']) : 0;
    }
}

// ---- TRUST-EXT DASHBOARD (read) ------------------------------------------------
$router->get('/supplier/trust-ext', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $orgId = _trust_ext_org($db);
    if ($orgId <= 0) { _supplier_stays_deny('Only the account owner can manage client-money trust.'); }

    $balances = function_exists('trust_balances') ? trust_balances($db, $orgId) : ['parties' => [], 'totals' => [], 'total' => 0];
    $bonds    = function_exists('trust_bond_list') ? trust_bond_list($db, $orgId) : [];
    $recon    = function_exists('trust_reconciliation') ? trust_reconciliation($db, $orgId) : ['rows' => [], 'any_shortfall' => false];
    $audit    = function_exists('trust_audit_trail') ? trust_audit_trail($db, $orgId, 80) : [];
    $feed     = function_exists('trust_bank_feed_status') ? trust_bank_feed_status($db, $orgId) : ['connected' => false, 'message' => ''];

    $title = 'Client-money trust'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/trust-ext.php";
    require_once views . "includes/footer.php";
});

// ---- CREATE a structured bond/deposit ------------------------------------------
$router->post('/supplier/trust-ext/bond/create', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can($db, 'finance', 'edit')) { _supplier_stays_deny('Not permitted.'); }
    $orgId = _trust_ext_org($db); $back = root . 'supplier/trust-ext';
    if ($orgId <= 0) { _supplier_stays_deny('Not authorised.'); }
    $r = function_exists('trust_bond_create') ? trust_bond_create($db, $orgId, $_POST) : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => !empty($r['ok']) ? 'success' : 'error', 'text' => (string) ($r['message'] ?? '')];
    header('Location: ' . $back); exit;
});

// ---- RELEASE (refund / forfeit) a bond  [MONEY] --------------------------------
$router->post('/supplier/trust-ext/bond/release', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can($db, 'finance', 'edit')) { _supplier_stays_deny('Not permitted.'); }
    $orgId = _trust_ext_org($db); $back = root . 'supplier/trust-ext';
    if ($orgId <= 0) { _supplier_stays_deny('Not authorised.'); }
    $r = function_exists('trust_bond_release')
        ? trust_bond_release($db, $orgId, (int) ($_POST['bond_id'] ?? 0), (float) ($_POST['amount'] ?? 0), (string) ($_POST['mode'] ?? 'refund'))
        : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => !empty($r['ok']) ? 'success' : 'error', 'text' => (string) ($r['message'] ?? '')];
    header('Location: ' . $back); exit;
});

// ---- CHARGE a management fee out of held trust  [MONEY] ------------------------
$router->post('/supplier/trust-ext/fee', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can($db, 'finance', 'edit')) { _supplier_stays_deny('Not permitted.'); }
    $orgId = _trust_ext_org($db); $back = root . 'supplier/trust-ext';
    if ($orgId <= 0) { _supplier_stays_deny('Not authorised.'); }
    $r = function_exists('trust_management_fee_charge') ? trust_management_fee_charge(
        $db, $orgId, (string) ($_POST['party'] ?? ''), (float) ($_POST['amount'] ?? 0),
        ['currency' => (string) ($_POST['currency'] ?? 'USD'), 'memo' => (string) ($_POST['memo'] ?? 'Management fee'), 'reference' => (string) ($_POST['reference'] ?? '')]
    ) : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => !empty($r['ok']) ? 'success' : 'error', 'text' => (string) ($r['message'] ?? '')];
    header('Location: ' . $back); exit;
});

// ---- RECORD the trust-bank statement balance (reconciliation "bank side") ------
$router->post('/supplier/trust-ext/bank', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can($db, 'finance', 'edit')) { _supplier_stays_deny('Not permitted.'); }
    $orgId = _trust_ext_org($db); $back = root . 'supplier/trust-ext';
    if ($orgId <= 0) { _supplier_stays_deny('Not authorised.'); }
    $r = function_exists('trust_bank_record') ? trust_bank_record(
        $db, $orgId, (string) ($_POST['currency'] ?? 'USD'), (float) ($_POST['balance'] ?? 0),
        (string) ($_POST['as_of'] ?? ''), (string) ($_POST['note'] ?? '')
    ) : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => !empty($r['ok']) ? 'success' : 'error', 'text' => (string) ($r['message'] ?? '')];
    header('Location: ' . $back); exit;
});

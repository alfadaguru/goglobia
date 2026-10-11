<?php
// FILE: app/routes/users/supplierCorporateRoutes.php
// Corporate/agent accounts (#15) + Trust/client-money ledger (#50). Owner-only (org).

@$SECURE or die('Access Denied!');

if (!function_exists('_corp_org')) {
    function _corp_org($db): int
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin']) || empty($ctx['is_owner'])) { return 0; }
        return function_exists('supplier_org_for_owner') ? (int) supplier_org_for_owner($db, (string) $ctx['owner']) : 0;
    }
}

// ---- CORPORATE / AGENTS (#15) --------------------------------------------------
$router->get('/supplier/corporate', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $orgId = _corp_org($db);
    if ($orgId <= 0) { _supplier_stays_deny('Only the account owner can manage corporate accounts.'); }
    $accounts = function_exists('corp_list') ? corp_list($db, $orgId) : [];
    $title = 'Corporate & agents'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/corporate.php";
    require_once views . "includes/footer.php";
});
$router->post('/supplier/corporate/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _corp_org($db); $back = root . 'supplier/corporate';
    if ($orgId <= 0) { _supplier_stays_deny('Not authorised.'); }
    $id = function_exists('corp_add') ? corp_add($db, $orgId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Account added.' : 'Could not add (name required).'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/corporate/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _corp_org($db); $back = root . 'supplier/corporate';
    if ($orgId <= 0) { _supplier_stays_deny('Not authorised.'); }
    $ok = function_exists('corp_set_status') && corp_set_status($db, $orgId, (int) ($_POST['id'] ?? 0), strtolower(trim((string) ($_POST['status'] ?? ''))));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});

// ---- TRUST / CLIENT MONEY (#50) ------------------------------------------------
$router->get('/supplier/trust', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $orgId = _corp_org($db);
    if ($orgId <= 0) { _supplier_stays_deny('Only the account owner can view the trust ledger.'); }
    $balances = function_exists('trust_balances') ? trust_balances($db, $orgId) : ['parties' => [], 'total' => 0];
    $entries = function_exists('trust_list') ? trust_list($db, $orgId) : [];
    $title = 'Trust / client money'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/trust.php";
    require_once views . "includes/footer.php";
});
$router->post('/supplier/trust/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _corp_org($db); $back = root . 'supplier/trust';
    if ($orgId <= 0) { _supplier_stays_deny('Not authorised.'); }
    $id = function_exists('trust_add') ? trust_add($db, $orgId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Entry recorded.' : 'Could not record (party + non-zero amount required).'];
    header('Location: ' . $back); exit;
});

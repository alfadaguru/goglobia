<?php
// FILE: app/routes/users/supplierOwnersRoutes.php
// Property owners + management agreements + owner statements (Phase 1 inc S26).
// OWNER-ONLY (SUPPLIER_AUTH) — owner accounting is an owner-level financial area,
// like payouts. All writes CSRF-guarded + org-scoped. Reuses stays owner/deny helpers.

@$SECURE or die('Access Denied!');

if (!function_exists('_owners_org')) {
    /** The acting supplier-owner's org id (0 if not a resolvable owner). */
    function _owners_org($db): int
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin']) || empty($ctx['is_owner'])) { return 0; }
        return function_exists('supplier_org_for_owner') ? supplier_org_for_owner($db, (string) $ctx['owner']) : 0;
    }
}

// GET /supplier/owners — owners, agreements, statement tool, recent statements
$router->get('/supplier/owners', function () use ($SECURE, $db) {
    SUPPLIER_AUTH();
    $ownerUserId = (($c = supplier_acting_context($db)) && empty($c['is_admin']) && !empty($c['is_owner'])) ? (string) $c['owner'] : '';
    if ($ownerUserId === '') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Only the supplier account owner can manage property owners.'];
        header('Location: ' . root . 'supplier/dashboard'); exit;
    }
    $orgId = _owners_org($db);

    $owners = $orgId > 0 ? ($db->select('stays_owners', ['id', 'name', 'email', 'phone', 'status'], ['org_id' => $orgId, 'ORDER' => ['name' => 'ASC']]) ?: []) : [];
    // Properties of this org + their active agreement (owner) for the linker.
    $properties = [];
    try { $properties = $db->select('stays', ['id', 'name'], ['user_id' => $ownerUserId, 'ORDER' => ['id' => 'DESC']]) ?: []; } catch (\Throwable $e) {}
    $agreements = [];
    if ($orgId > 0) {
        try {
            foreach ($db->select('stays_management_agreements',
                ['stay_id', 'owner_id', 'manager_commission_pct', 'fixed_fee'],
                ['org_id' => $orgId, 'active' => 1]) ?: [] as $a) { $agreements[(int) $a['stay_id']] = $a; }
        } catch (\Throwable $e) {}
    }
    $statements = $orgId > 0 ? ($db->select('stays_owner_statements',
        ['id', 'stay_id', 'owner_id', 'period_from', 'period_to', 'currency', 'gross', 'owner_payout', 'status', 'created_at'],
        ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 50]) ?: []) : [];
    $ownerNames = [];
    foreach ($owners as $o) { $ownerNames[(int) $o['id']] = $o['name']; }
    $propNames = [];
    foreach ($properties as $p) { $propNames[(int) $p['id']] = $p['name']; }

    $title = 'Property owners'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/owners.php";
    require_once views . "includes/footer.php";
});

// POST /supplier/owners/create — add an owner
$router->post('/supplier/owners/create', function () use ($SECURE, $db) {
    SUPPLIER_AUTH(); CSRF::guard();
    $orgId = _owners_org($db);
    $back = root . 'supplier/owners';
    if ($orgId <= 0) { _supplier_stays_deny('Only the owner can manage property owners.'); }
    $id = function_exists('owner_create') ? owner_create($db, $orgId, [
        'name' => $_POST['name'] ?? '', 'email' => $_POST['email'] ?? '', 'phone' => $_POST['phone'] ?? '',
        'bank_code' => $_POST['bank_code'] ?? '', 'account_number' => $_POST['account_number'] ?? '', 'account_name' => $_POST['account_name'] ?? '',
    ]) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Owner added.' : 'Could not add owner (name required?).'];
    header('Location: ' . $back); exit;
});

// POST /supplier/owners/agreement — link a property to an owner
$router->post('/supplier/owners/agreement', function () use ($SECURE, $db) {
    SUPPLIER_AUTH(); CSRF::guard();
    $orgId = _owners_org($db);
    $back = root . 'supplier/owners';
    if ($orgId <= 0) { _supplier_stays_deny('Only the owner can set agreements.'); }
    $ok = function_exists('owner_agreement_set') && owner_agreement_set($db, $orgId,
        (int) ($_POST['stay_id'] ?? 0), (int) ($_POST['owner_id'] ?? 0),
        (float) ($_POST['manager_commission_pct'] ?? 0), (float) ($_POST['fixed_fee'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Agreement saved.' : 'Could not save (own property + owner required).'];
    header('Location: ' . $back); exit;
});

// POST /supplier/owners/expense — record an owner expense against a property
$router->post('/supplier/owners/expense', function () use ($SECURE, $db) {
    SUPPLIER_AUTH(); CSRF::guard();
    $orgId = _owners_org($db);
    $back = root . 'supplier/owners';
    if ($orgId <= 0) { _supplier_stays_deny('Only the owner can record expenses.'); }
    $ok = function_exists('owner_expense_add') && owner_expense_add($db, $orgId,
        (int) ($_POST['stay_id'] ?? 0), (float) ($_POST['amount'] ?? 0),
        (string) ($_POST['description'] ?? ''), (string) ($_POST['expense_date'] ?? ''), (string) ($_POST['currency'] ?? 'USD'));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Expense recorded.' : 'Could not record expense.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/owners/statement — generate a statement for a property/period
$router->post('/supplier/owners/statement', function () use ($SECURE, $db) {
    SUPPLIER_AUTH(); CSRF::guard();
    $orgId = _owners_org($db);
    $back = root . 'supplier/owners';
    if ($orgId <= 0) { _supplier_stays_deny('Only the owner can generate statements.'); }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $from = (string) ($_POST['period_from'] ?? '');
    $to = (string) ($_POST['period_to'] ?? '');
    // Defense in depth: the property must belong to this org.
    if (!$db->has('stays', ['id' => $stayId, 'org_id' => $orgId])) { _supplier_stays_deny('Not your property.'); }
    $res = function_exists('owner_statement_generate') ? owner_statement_generate($db, $orgId, $stayId, $from, $to) : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => !empty($res['ok']) ? 'Owner statement generated.' : ($res['message'] ?? 'Could not generate statement.')];
    header('Location: ' . $back); exit;
});

<?php
// FILE: app/routes/users/supplierArExtRoutes.php
// MODULE 35 DEEPEN — Accounts receivable (city ledger): ageing, statements, payment
// allocation (receipts), write-offs, collections. Org-scoped money. Gated on the
// per-area 'ar' key (its own grantable permission) — read needs ar:view, writes ar:edit.
// (Previously inherited _corp_org's 'corporate' gate; now AR is a first-class area so a
// staffer granted only AR can open it — permission redesign Step 7 fix.)

@$SECURE or die('Access Denied!');

if (!function_exists('_ar_ext_org')) {
    /** Resolve the acting org for AR, gated on the 'ar' area key. 0 = denied. */
    function _ar_ext_org($db, string $action = 'view'): int
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { return 0; }
        if (!supplier_can_orgwide($db, 'ar', $action === 'edit' ? 'edit' : 'view')) { return 0; }
        return function_exists('supplier_org_for_owner') ? (int) supplier_org_for_owner($db, (string) $ctx['owner']) : 0;
    }
}

// ---- AR DASHBOARD: ageing + collections (read) --------------------------------
$router->get('/supplier/ar', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $orgId = _ar_ext_org($db);
    if ($orgId <= 0) { _supplier_stays_deny('You are not authorised to manage receivables.'); }

    $ageing      = function_exists('ar_ext_ageing') ? ar_ext_ageing($db, $orgId) : ['by_currency' => [], 'grand_total' => [], 'folios' => []];
    $accounts    = function_exists('corp_list') ? corp_list($db, $orgId) : [];
    $collections = function_exists('ar_ext_collection_list') ? ar_ext_collection_list($db, $orgId, 0, 60) : [];
    $statement   = null;
    $acctId = (int) ($_GET['account'] ?? 0);
    if ($acctId > 0 && function_exists('ar_ext_statement')) {
        $statement = ar_ext_statement($db, $orgId, $acctId);
    }

    $title = 'Accounts receivable'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/ar-ext.php";
    require_once views . "includes/footer.php";
});

// ---- ASSIGN a folio to a city-ledger account ----------------------------------
$router->post('/supplier/ar/assign', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can_orgwide($db, 'ar', 'edit')) { _supplier_stays_deny('Not permitted.'); }
    $orgId = _ar_ext_org($db, 'edit'); $back = root . 'supplier/ar';
    if ($orgId <= 0) { _supplier_stays_deny('Not authorised.'); }
    $ok = function_exists('ar_ext_assign_account')
        && ar_ext_assign_account($db, $orgId, (int) ($_POST['folio_id'] ?? 0), (int) ($_POST['account_id'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Receivable assigned.' : 'Could not assign (check folio/account).'];
    header('Location: ' . $back); exit;
});

// ---- RECEIVE a payment against a folio (MONEY) --------------------------------
$router->post('/supplier/ar/receive', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can_orgwide($db, 'ar', 'edit')) { _supplier_stays_deny('Not permitted.'); }
    $orgId = _ar_ext_org($db, 'edit'); $back = root . 'supplier/ar';
    if ($orgId <= 0) { _supplier_stays_deny('Not authorised.'); }
    $r = function_exists('ar_ext_receive_payment')
        ? ar_ext_receive_payment($db, $orgId, (int) ($_POST['folio_id'] ?? 0), (float) ($_POST['amount'] ?? 0), [
            'method'    => (string) ($_POST['method'] ?? 'cash'),
            'reference' => (string) ($_POST['reference'] ?? ''),
        ])
        : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => !empty($r['ok']) ? 'success' : 'error',
        'text' => !empty($r['ok']) ? ('Receipt applied: ' . number_format((float) ($r['applied'] ?? 0), 2)) : (string) ($r['message'] ?? 'Could not apply.')];
    header('Location: ' . $back); exit;
});

// ---- WRITE OFF a folio balance as bad debt (MONEY) ----------------------------
$router->post('/supplier/ar/writeoff', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can_orgwide($db, 'ar', 'edit')) { _supplier_stays_deny('Not permitted.'); }
    $orgId = _ar_ext_org($db, 'edit'); $back = root . 'supplier/ar';
    if ($orgId <= 0) { _supplier_stays_deny('Not authorised.'); }
    $r = function_exists('ar_ext_write_off')
        ? ar_ext_write_off($db, $orgId, (int) ($_POST['folio_id'] ?? 0), (float) ($_POST['amount'] ?? 0), (string) ($_POST['reason'] ?? ''))
        : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => !empty($r['ok']) ? 'success' : 'error',
        'text' => !empty($r['ok']) ? ('Written off: ' . number_format((float) ($r['written_off'] ?? 0), 2)) : (string) ($r['message'] ?? 'Could not write off.')];
    header('Location: ' . $back); exit;
});

// ---- LOG a collections activity -----------------------------------------------
$router->post('/supplier/ar/collection', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can_orgwide($db, 'ar', 'edit')) { _supplier_stays_deny('Not permitted.'); }
    $orgId = _ar_ext_org($db, 'edit'); $acctId = (int) ($_POST['account_id'] ?? 0);
    $back = root . 'supplier/ar' . ($acctId > 0 ? '?account=' . $acctId : '');
    if ($orgId <= 0) { _supplier_stays_deny('Not authorised.'); }
    $id = function_exists('ar_ext_collection_add') ? ar_ext_collection_add($db, $orgId, $acctId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Collection logged.' : 'Could not log (account required).'];
    header('Location: ' . $back); exit;
});

// ---- SEND a statement reminder (HONEST STUB — delivery not connected) ---------
$router->post('/supplier/ar/remind', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can_orgwide($db, 'ar', 'edit')) { _supplier_stays_deny('Not permitted.'); }
    $orgId = _ar_ext_org($db, 'edit'); $acctId = (int) ($_POST['account_id'] ?? 0);
    $back = root . 'supplier/ar' . ($acctId > 0 ? '?account=' . $acctId : '');
    if ($orgId <= 0) { _supplier_stays_deny('Not authorised.'); }
    $r = function_exists('ar_ext_send_reminder') ? ar_ext_send_reminder($db, $orgId, $acctId) : ['connected' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => 'info', 'text' => (string) ($r['message'] ?? 'Reminder not connected.')];
    header('Location: ' . $back); exit;
});

<?php
// FILE: app/routes/users/supplierOwnersExtRoutes.php
// PROPERTY OWNER & LANDLORD — EXTENSION routes (PMS #48 deepening). Adds the gaps on
// top of supplierOwnersRoutes.php: owner KYC/verification, co-ownership mapping,
// owner documents, owner-usage blocks, owner running balances + portfolio report,
// owner communications, and the (honest) owner-portal status.
//
// AUTH MODEL: owner accounting is a FINANCE area. Every handler requires
// SUPPLIER_OR_STAFF_AUTH($db) and gates on supplier_can($db,'finance',<action>) so
// the owner (unrestricted) OR a staff member granted finance access can use it.
// Every POST also CSRF::guard()s and re-scopes to the resolved org. Property-level
// writes additionally pass $stayId to supplier_can for the IDOR/ownership guard.
// Helpers: owner_* in app/lib/supplier_owners_ext.php. Reuses the stays deny helper.

@$SECURE or die('Access Denied!');

if (!function_exists('_owners_ext_org')) {
    /** Resolve the acting supplier's org id for owner-accounting (0 = not resolvable). */
    function _owners_ext_org($db): int
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null) { return 0; }
        if (!empty($ctx['is_admin'])) { return 0; } // admin has no single org here
        $owner = (string) ($ctx['owner'] ?? '');
        if ($owner === '' || !function_exists('supplier_org_for_owner')) { return 0; }
        return supplier_org_for_owner($db, $owner);
    }
}

// ----------------------------------------------------------------------------
// GET /supplier/owners/manage — the extension hub: owners + KYC, co-ownership per
// property, documents, usage blocks, balances, portfolio, communications.
// ----------------------------------------------------------------------------
$router->get('/supplier/owners/manage', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    if (!supplier_can($db, 'finance', 'view')) { _supplier_stays_deny('You are not authorised to view owner accounting.'); }
    $orgId = _owners_ext_org($db);
    if ($orgId <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'No supplier organisation resolved for owner accounting.'];
        header('Location: ' . root . 'supplier/dashboard'); exit;
    }

    $owners = $db->select('stays_owners',
        ['id', 'name', 'email', 'phone', 'status', 'kyc_status', 'national_id', 'verified_at'],
        ['org_id' => $orgId, 'ORDER' => ['name' => 'ASC']]) ?: [];

    // Properties of this org (for the co-ownership / usage pickers).
    $properties = [];
    try { $properties = $db->select('stays', ['id', 'name'], ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC']]) ?: []; }
    catch (\Throwable $e) {}
    $propNames = [];
    foreach ($properties as $p) { $propNames[(int) $p['id']] = (string) $p['name']; }
    $ownerNames = [];
    foreach ($owners as $o) { $ownerNames[(int) $o['id']] = (string) $o['name']; }

    // Selected owner (for documents / comms / portfolio detail).
    $selOwner = (int) ($_GET['owner_id'] ?? 0);
    if ($selOwner > 0 && !isset($ownerNames[$selOwner])) { $selOwner = 0; } // not in org → ignore
    // Selected property (for co-ownership editor).
    $selStay = (int) ($_GET['stay_id'] ?? 0);
    if ($selStay > 0 && !isset($propNames[$selStay])) { $selStay = 0; }

    $balances   = function_exists('owner_balances_all') ? owner_balances_all($db, $orgId) : [];
    $coowners   = ($selStay > 0 && function_exists('owner_coowners_list')) ? owner_coowners_list($db, $orgId, $selStay) : [];
    $documents  = ($selOwner > 0 && function_exists('owner_documents_list')) ? owner_documents_list($db, $orgId, $selOwner) : [];
    $comms      = ($selOwner > 0 && function_exists('owner_comms_list')) ? owner_comms_list($db, $orgId, $selOwner) : [];
    $portfolio  = ($selOwner > 0 && function_exists('owner_portfolio')) ? owner_portfolio($db, $orgId, $selOwner) : ['properties' => [], 'balance' => []];
    $usage      = function_exists('owner_usage_list') ? owner_usage_list($db, $orgId) : [];
    $portal     = ($selOwner > 0 && function_exists('owner_portal_status')) ? owner_portal_status($db, $orgId, $selOwner) : null;
    $kycStatuses = function_exists('owner_kyc_statuses') ? owner_kyc_statuses() : [];
    $docTypes    = function_exists('owner_document_types') ? owner_document_types() : [];

    $title = 'Owner accounting'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/owners-ext.php";
    require_once views . "includes/footer.php";
});

// POST /supplier/owners/kyc — set an owner's KYC/verification status
$router->post('/supplier/owners/kyc', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can($db, 'finance', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $orgId = _owners_ext_org($db);
    $back = root . 'supplier/owners/manage';
    if ($orgId <= 0) { _supplier_stays_deny('No organisation.'); }
    $res = function_exists('owner_kyc_set')
        ? owner_kyc_set($db, $orgId, (int) ($_POST['owner_id'] ?? 0), (string) ($_POST['status'] ?? ''), (string) ($_POST['national_id'] ?? ''))
        : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not update KYC.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/owners/coowner — add/update a co-ownership share for a property
$router->post('/supplier/owners/coowner', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _owners_ext_org($db);
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    // Property-scoped permission (IDOR guard): must own/have scope on this property.
    if (!supplier_can($db, 'finance', 'edit', $stayId)) { _supplier_stays_deny('Not authorised for this property.'); }
    $back = root . 'supplier/owners/manage?stay_id=' . $stayId;
    if ($orgId <= 0) { _supplier_stays_deny('No organisation.'); }
    $res = function_exists('owner_coowner_set')
        ? owner_coowner_set($db, $orgId, $stayId, (int) ($_POST['owner_id'] ?? 0), (float) ($_POST['share_pct'] ?? 0))
        : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not save share.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/owners/coowner/remove — remove a co-ownership share
$router->post('/supplier/owners/coowner/remove', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can($db, 'finance', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $orgId = _owners_ext_org($db);
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/owners/manage' . ($stayId > 0 ? ('?stay_id=' . $stayId) : '');
    if ($orgId <= 0) { _supplier_stays_deny('No organisation.'); }
    $ok = function_exists('owner_coowner_remove') && owner_coowner_remove($db, $orgId, (int) ($_POST['row_id'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Co-owner removed.' : 'Could not remove.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/owners/document — record an owner document (metadata)
$router->post('/supplier/owners/document', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can($db, 'finance', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $orgId = _owners_ext_org($db);
    $ownerId = (int) ($_POST['owner_id'] ?? 0);
    $back = root . 'supplier/owners/manage?owner_id=' . $ownerId;
    if ($orgId <= 0) { _supplier_stays_deny('No organisation.'); }
    $res = function_exists('owner_document_add')
        ? owner_document_add($db, $orgId, $ownerId, [
            'title' => $_POST['title'] ?? '', 'doc_type' => $_POST['doc_type'] ?? 'other',
            'reference' => $_POST['reference'] ?? '', 'expires_on' => $_POST['expires_on'] ?? '', 'notes' => $_POST['notes'] ?? '',
          ])
        : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not record document.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/owners/document/remove — remove an owner document
$router->post('/supplier/owners/document/remove', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can($db, 'finance', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $orgId = _owners_ext_org($db);
    $ownerId = (int) ($_POST['owner_id'] ?? 0);
    $back = root . 'supplier/owners/manage' . ($ownerId > 0 ? ('?owner_id=' . $ownerId) : '');
    if ($orgId <= 0) { _supplier_stays_deny('No organisation.'); }
    $ok = function_exists('owner_document_remove') && owner_document_remove($db, $orgId, (int) ($_POST['doc_id'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Document removed.' : 'Could not remove.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/owners/usage — record an owner-usage block for a property
$router->post('/supplier/owners/usage', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _owners_ext_org($db);
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    if (!supplier_can($db, 'finance', 'edit', $stayId)) { _supplier_stays_deny('Not authorised for this property.'); }
    $back = root . 'supplier/owners/manage?stay_id=' . $stayId;
    if ($orgId <= 0) { _supplier_stays_deny('No organisation.'); }
    $res = function_exists('owner_usage_add')
        ? owner_usage_add($db, $orgId, $stayId, (int) ($_POST['owner_id'] ?? 0),
            (string) ($_POST['date_from'] ?? ''), (string) ($_POST['date_to'] ?? ''), (string) ($_POST['note'] ?? ''))
        : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not save owner usage.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/owners/usage/cancel — cancel an owner-usage block
$router->post('/supplier/owners/usage/cancel', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can($db, 'finance', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $orgId = _owners_ext_org($db);
    $back = root . 'supplier/owners/manage';
    if ($orgId <= 0) { _supplier_stays_deny('No organisation.'); }
    $ok = function_exists('owner_usage_cancel') && owner_usage_cancel($db, $orgId, (int) ($_POST['usage_id'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Owner block cancelled.' : 'Could not cancel (already cancelled?).'];
    header('Location: ' . $back); exit;
});

// POST /supplier/owners/comm — log (and optionally email) an owner communication
$router->post('/supplier/owners/comm', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    if (!supplier_can($db, 'finance', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $orgId = _owners_ext_org($db);
    $ownerId = (int) ($_POST['owner_id'] ?? 0);
    $back = root . 'supplier/owners/manage?owner_id=' . $ownerId;
    if ($orgId <= 0) { _supplier_stays_deny('No organisation.'); }
    $res = function_exists('owner_comm_log')
        ? owner_comm_log($db, $orgId, $ownerId, (string) ($_POST['channel'] ?? 'note'),
            (string) ($_POST['subject'] ?? ''), (string) ($_POST['body'] ?? ''))
        : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Could not log communication.'];
    header('Location: ' . $back); exit;
});

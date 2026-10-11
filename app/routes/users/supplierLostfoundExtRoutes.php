<?php
// FILE: app/routes/users/supplierLostfoundExtRoutes.php
// MODULE 29 (Lost & found → 360) extension routes: detail/reservation, claim
// verification, return/shipping, disposal policy + disposal action, audit trail.
// Base register (add/status/list) stays in supplierFacilitiesRoutes.php — untouched.
// Owner/staff gated; property-scoped via supplier_can('rooms', …) (operational data).
// Per-item POSTs resolve the owning stay FROM THE ITEM (never trust client stay_id) and
// re-check supplier_can on that resolved stay — IDOR guard. No money is posted anywhere.

@$SECURE or die('Access Denied!');

if (!function_exists('_lf_ext_item_guard')) {
    /**
     * Resolve + authorize a per-item action. Returns the item's owning stay_id on success,
     * or exits via _supplier_stays_deny on any failure. $need is the required action.
     */
    function _lf_ext_item_guard($db, int $itemId, string $need = 'edit'): int
    {
        $stayId = function_exists('lostfound_item_stay_id') ? lostfound_item_stay_id($db, $itemId) : 0;
        if ($stayId <= 0) { _supplier_stays_deny('Item not found.'); }
        if (!supplier_can($db, 'rooms', $need, $stayId)) { _supplier_stays_deny('Not your property.'); }
        return $stayId;
    }
}

// ---- EXTENDED REGISTER (GET) ---------------------------------------------------
// Rich view: items + detail map + due-for-disposal report + the org policy form.
$router->get('/supplier/lost-found/manage', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rooms', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $items = function_exists('lostfound_list') ? lostfound_list($db, $stayIds) : [];
    $detailMap = function_exists('lostfound_detail_map') ? lostfound_detail_map($db, array_map(fn($i) => (int) $i['id'], $items)) : [];

    // Org + policy + due report. Org comes from the first owned stay (one org per owner here).
    $orgId = 0;
    if (!empty($stayIds) && function_exists('supplier_property_org')) { $orgId = (int) supplier_property_org($db, (int) $stayIds[0]); }
    $policy = function_exists('lostfound_policy_get') ? lostfound_policy_get($db, $orgId) : ['retention_days' => 90, 'disposal_method' => ''];
    $dueItems = function_exists('lostfound_due_for_disposal') ? lostfound_due_for_disposal($db, $stayIds, $orgId) : [];

    $propMap = []; $propList = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; $propList[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }
    $canEdit = supplier_can($db, 'rooms', 'edit');
    $title = 'Lost & found — manage'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/lost-found-ext.php";
    require_once views . "includes/footer.php";
});

// ---- AUDIT TRAIL (GET JSON) ----------------------------------------------------
$router->get('/supplier/lost-found/audit/([0-9]+)', function ($itemId) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $itemId = (int) $itemId;
    $stayId = function_exists('lostfound_item_stay_id') ? lostfound_item_stay_id($db, $itemId) : 0;
    header('Content-Type: application/json');
    if ($stayId <= 0 || !supplier_can($db, 'rooms', 'view', $stayId)) { echo json_encode(['ok' => false, 'trail' => []]); exit; }
    $trail = function_exists('lostfound_audit_trail') ? lostfound_audit_trail($db, $itemId) : [];
    echo json_encode(['ok' => true, 'trail' => $trail]); exit;
});

// ---- SAVE DETAIL / RESERVATION ASSOCIATION (POST) ------------------------------
$router->post('/supplier/lost-found/detail', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $back = root . 'supplier/lost-found/manage';
    $itemId = (int) ($_POST['id'] ?? 0);
    $stayId = _lf_ext_item_guard($db, $itemId, 'edit');
    $ok = function_exists('lostfound_detail_save') && lostfound_detail_save($db, $itemId, $stayId, $_POST);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Details saved.' : 'Could not save details.'];
    header('Location: ' . $back); exit;
});

// ---- CLAIM VERIFICATION (POST) -------------------------------------------------
$router->post('/supplier/lost-found/claim', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $back = root . 'supplier/lost-found/manage';
    $itemId = (int) ($_POST['id'] ?? 0);
    $stayId = _lf_ext_item_guard($db, $itemId, 'edit');
    $ok = function_exists('lostfound_claim') && lostfound_claim($db, $itemId, $stayId, $_POST);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Claim verified and recorded.' : 'Could not record claim (claimant name + proof of ownership required).'];
    header('Location: ' . $back); exit;
});

// ---- RETURN / SHIPPING (POST) --------------------------------------------------
$router->post('/supplier/lost-found/return', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $back = root . 'supplier/lost-found/manage';
    $itemId = (int) ($_POST['id'] ?? 0);
    $stayId = _lf_ext_item_guard($db, $itemId, 'edit');
    $ok = function_exists('lostfound_return') && lostfound_return($db, $itemId, $stayId, $_POST);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Return recorded.' : 'Could not record return (choose a method; a carrier is required when shipping).'];
    header('Location: ' . $back); exit;
});

// ---- DISPOSE PER POLICY (POST) -------------------------------------------------
$router->post('/supplier/lost-found/dispose', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $back = root . 'supplier/lost-found/manage';
    $itemId = (int) ($_POST['id'] ?? 0);
    $stayId = _lf_ext_item_guard($db, $itemId, 'edit');
    $ok = function_exists('lostfound_dispose') && lostfound_dispose($db, $itemId, $stayId, $_POST);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Item disposed.' : 'Could not dispose item.'];
    header('Location: ' . $back); exit;
});

// ---- SAVE DISPOSAL POLICY (POST, org-scoped) -----------------------------------
$router->post('/supplier/lost-found/policy', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $back = root . 'supplier/lost-found/manage';
    // Policy is org-wide → require edit on at least one owned property; derive org from it.
    $stayIds = supplier_owned_stay_ids($db, $owner);
    if (empty($stayIds) || !supplier_can($db, 'rooms', 'edit', (int) $stayIds[0])) { _supplier_stays_deny('Not authorised.'); }
    $orgId = function_exists('supplier_property_org') ? (int) supplier_property_org($db, (int) $stayIds[0]) : 0;
    $ok = $orgId > 0 && function_exists('lostfound_policy_save') && lostfound_policy_save($db, $orgId, $_POST);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Disposal policy saved.' : 'Could not save policy.'];
    header('Location: ' . $back); exit;
});

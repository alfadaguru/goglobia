<?php
// FILE: app/routes/users/supplierAssetsExtRoutes.php
// Fixed assets & equipment — DEEPENED actions (#28): tagging, warranty, depreciation,
// transfers, repair/service history, disposal, and an asset report.
// Owner/staff gated; property-scoped via supplier_can('rooms', …) (operational data),
// matching the base supplier/assets screens in supplierFacilitiesRoutes.php.
// All state-changing routes: SUPPLIER_OR_STAFF_AUTH + CSRF::guard + supplier_can(...,$stayId).

@$SECURE or die('Access Denied!');

if (!function_exists('_asset_ext_owner_ctx')) {
    /** Resolve owner + authorized stay set, or redirect/deny. Returns [owner,string $stayIds[]]. */
    function _asset_ext_owner_ctx($db): array
    {
        $owner = _supplier_stays_owner($db);
        if ($owner === null) { header('Location: ' . root . 'login'); exit; }
        if (!supplier_can($db, 'rooms', 'view')) { _supplier_stays_deny('Not authorised.'); }
        return [$owner, supplier_owned_stay_ids($db, $owner)];
    }
}

// ---- ASSET DETAIL (history + depreciation + warranty) --------------------------
$router->get('/supplier/assets/view/([0-9]+)', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    [$owner, $stayIds] = _asset_ext_owner_ctx($db);
    $id = (int) $id;
    $asset = null;
    if (!empty($stayIds)) {
        try { $asset = $db->get('stays_assets', '*', ['AND' => ['id' => $id, 'stay_id' => $stayIds]]); } catch (\Throwable $e) {}
    }
    if (!$asset) { _supplier_stays_deny('Asset not found.'); }
    $stayId = (int) $asset['stay_id'];
    $events = function_exists('asset_events') ? asset_events($db, $id, $stayId) : [];
    $schedule = function_exists('asset_depreciation_list') ? asset_depreciation_list($db, $id, $stayId) : [];
    $bookValue = function_exists('asset_book_value') ? asset_book_value($db, $asset) : (float) ($asset['purchase_cost'] ?? 0);
    $propMap = []; $propList = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; $propList[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }
    $canEdit = supplier_can($db, 'rooms', 'edit');
    $view = 'detail';
    $title = 'Asset'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/assets-ext.php";
    require_once views . "includes/footer.php";
});

// ---- ASSET REPORT --------------------------------------------------------------
$router->get('/supplier/assets/report', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    [$owner, $stayIds] = _asset_ext_owner_ctx($db);
    $report = function_exists('asset_report') ? asset_report($db, $stayIds) : [];
    $propMap = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }
    $canEdit = supplier_can($db, 'rooms', 'edit');
    $view = 'report';
    $title = 'Asset report'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/assets-ext.php";
    require_once views . "includes/footer.php";
});

// ---- TAGGING -------------------------------------------------------------------
$router->post('/supplier/assets/tag', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $id = (int) ($_POST['id'] ?? 0); $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/assets/view/' . $id;
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('asset_set_tag') && asset_set_tag($db, $id, $stayId, (string) ($_POST['asset_tag'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Tag assigned.' : 'Could not assign tag (empty or already in use).'];
    header('Location: ' . $back); exit;
});

// ---- WARRANTY ------------------------------------------------------------------
$router->post('/supplier/assets/warranty', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $id = (int) ($_POST['id'] ?? 0); $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/assets/view/' . $id;
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('asset_set_warranty') && asset_set_warranty($db, $id, $stayId, $_POST);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Warranty saved.' : 'Could not save warranty.'];
    header('Location: ' . $back); exit;
});

// ---- DEPRECIATION (generate straight-line schedule) ----------------------------
$router->post('/supplier/assets/depreciation', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $id = (int) ($_POST['id'] ?? 0); $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/assets/view/' . $id;
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $n = function_exists('asset_depreciation_set') ? asset_depreciation_set($db, $id, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $n > 0 ? 'success' : 'error', 'text' => $n > 0 ? ('Depreciation schedule generated (' . $n . ' periods).') : 'Could not generate (needs purchase cost + life years).'];
    header('Location: ' . $back); exit;
});

// ---- TRANSFER ------------------------------------------------------------------
$router->post('/supplier/assets/transfer', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $id = (int) ($_POST['id'] ?? 0);
    $fromStayId = (int) ($_POST['stay_id'] ?? 0);
    $toStayId = (int) ($_POST['to_stay_id'] ?? 0);
    $back = root . 'supplier/assets/view/' . $id;
    // Authorize BOTH the source and (if moving property) the destination property.
    if (!supplier_can($db, 'rooms', 'edit', $fromStayId)) { _supplier_stays_deny('Not your property.'); }
    if ($toStayId > 0 && $toStayId !== $fromStayId && !supplier_can($db, 'rooms', 'edit', $toStayId)) { _supplier_stays_deny('Destination not your property.'); }
    $ownedStayIds = supplier_owned_stay_ids($db, $owner);
    $ok = function_exists('asset_transfer') && asset_transfer($db, $id, $fromStayId, $toStayId, (string) ($_POST['to_location'] ?? ''), $ownedStayIds);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Asset transferred.' : 'Could not transfer (pick a different property/location).'];
    // after a property move the detail URL still resolves by id across owned stays
    header('Location: ' . $back); exit;
});

// ---- REPAIR / SERVICE HISTORY EVENT --------------------------------------------
$router->post('/supplier/assets/event', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $id = (int) ($_POST['id'] ?? 0); $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/assets/view/' . $id;
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $type = strtolower(trim((string) ($_POST['event_type'] ?? 'repair')));
    $cost = ($_POST['cost'] ?? '') !== '' ? (float) $_POST['cost'] : null;
    $eid = function_exists('asset_event_add') ? asset_event_add($db, $id, $stayId, $type, (string) ($_POST['detail'] ?? ''), $cost) : 0;
    $_SESSION['message'] = ['type' => $eid > 0 ? 'success' : 'error', 'text' => $eid > 0 ? 'History entry added.' : 'Could not add entry.'];
    header('Location: ' . $back); exit;
});

// ---- DISPOSAL ------------------------------------------------------------------
$router->post('/supplier/assets/dispose', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $id = (int) ($_POST['id'] ?? 0); $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/assets/view/' . $id;
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('asset_dispose') && asset_dispose($db, $id, $stayId, $_POST);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Asset disposed.' : 'Could not dispose (already disposed?).'];
    header('Location: ' . $back); exit;
});

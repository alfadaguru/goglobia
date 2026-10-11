<?php
// FILE: app/routes/users/supplierFacilitiesRoutes.php
// Lost & Found (#29) + Fixed Assets (#28) — per-property facility registers.
// Owner/staff gated; property-scoped via supplier_can('rooms', …) (operational data).

@$SECURE or die('Access Denied!');

// ---- LOST & FOUND --------------------------------------------------------------
$router->get('/supplier/lost-found', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rooms', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $items = function_exists('lostfound_list') ? lostfound_list($db, $stayIds) : [];
    $propMap = []; $propList = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; $propList[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }
    $canEdit = supplier_can($db, 'rooms', 'edit');
    $title = 'Lost & found'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/lost-found.php";
    require_once views . "includes/footer.php";
});

$router->post('/supplier/lost-found/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/lost-found';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('lostfound_add') ? lostfound_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Item logged.' : 'Could not log the item (name + property required).'];
    header('Location: ' . $back); exit;
});

$router->post('/supplier/lost-found/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/lost-found';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('lostfound_set_status') && lostfound_set_status($db, (int) ($_POST['id'] ?? 0), $stayId, strtolower(trim((string) ($_POST['status'] ?? ''))));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});

// ---- FIXED ASSETS --------------------------------------------------------------
$router->get('/supplier/assets', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rooms', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $assets = function_exists('asset_list') ? asset_list($db, $stayIds) : [];
    $propMap = []; $propList = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; $propList[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }
    $canEdit = supplier_can($db, 'rooms', 'edit');
    $title = 'Assets'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/assets.php";
    require_once views . "includes/footer.php";
});

$router->post('/supplier/assets/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/assets';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('asset_add') ? asset_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Asset registered.' : 'Could not register (name + property required).'];
    header('Location: ' . $back); exit;
});

$router->post('/supplier/assets/condition', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/assets';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('asset_set_condition') && asset_set_condition($db, (int) ($_POST['id'] ?? 0), $stayId, strtolower(trim((string) ($_POST['condition'] ?? ''))));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});

// ---- LAUNDRY (#25) -------------------------------------------------------------
$router->get('/supplier/laundry', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rooms', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $items = function_exists('laundry_list') ? laundry_list($db, $stayIds) : [];
    $propMap = []; $propList = [];
    if (!empty($stayIds)) { try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; $propList[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {} }
    $canEdit = supplier_can($db, 'rooms', 'edit');
    $title = 'Laundry & linen'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/laundry.php";
    require_once views . "includes/footer.php";
});
$router->post('/supplier/laundry/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/laundry';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('laundry_add') ? laundry_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Laundry logged.' : 'Could not log (item + property required).'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/laundry/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/laundry';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('laundry_set_status') && laundry_set_status($db, (int) ($_POST['id'] ?? 0), $stayId, strtolower(trim((string) ($_POST['status'] ?? ''))));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});

// ---- MINIBAR (#26) -------------------------------------------------------------
$router->get('/supplier/minibar', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rooms', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $items = function_exists('minibar_list') ? minibar_list($db, $stayIds) : [];
    $propMap = []; $propList = [];
    if (!empty($stayIds)) { try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; $propList[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {} }
    $canEdit = supplier_can($db, 'rooms', 'edit');
    $title = 'Minibar'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/minibar.php";
    require_once views . "includes/footer.php";
});
$router->post('/supplier/minibar/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/minibar';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('minibar_add') ? minibar_add($db, $stayId, (string) ($_POST['name'] ?? ''), (float) ($_POST['price'] ?? 0)) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Item added.' : 'Could not add (name + property required).'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/minibar/toggle', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/minibar';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('minibar_set_active') && minibar_set_active($db, (int) ($_POST['id'] ?? 0), $stayId, (string) ($_POST['active'] ?? '0') === '1');
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});

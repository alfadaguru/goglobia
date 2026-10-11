<?php
// FILE: app/routes/users/supplierLeaseRoutes.php
// Lease & long-stay (#49) + Utilities/meter readings (#51). Owner/staff gated,
// property-scoped via supplier_can('hotels', …) (property-management data).

@$SECURE or die('Access Denied!');

// ---- LEASES (#49) --------------------------------------------------------------
$router->get('/supplier/leases', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'hotels', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $leases = function_exists('lease_list') ? lease_list($db, $stayIds) : [];
    $propMap = []; $propList = [];
    if (!empty($stayIds)) { try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; $propList[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {} }
    $canEdit = supplier_can($db, 'hotels', 'edit');
    $title = 'Leases'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/leases.php";
    require_once views . "includes/footer.php";
});
$router->post('/supplier/leases/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/leases';
    if (!supplier_can($db, 'hotels', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('lease_add') ? lease_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Lease created.' : 'Could not create (tenant + property required).'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/leases/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/leases';
    if (!supplier_can($db, 'hotels', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('lease_set_status') && lease_set_status($db, (int) ($_POST['id'] ?? 0), $stayId, strtolower(trim((string) ($_POST['status'] ?? ''))));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});

// ---- UTILITIES (#51) -----------------------------------------------------------
$router->get('/supplier/utilities', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'hotels', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $readings = function_exists('utility_list') ? utility_list($db, $stayIds) : [];
    $propMap = []; $propList = [];
    if (!empty($stayIds)) { try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; $propList[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {} }
    $canEdit = supplier_can($db, 'hotels', 'edit');
    $title = 'Utilities'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/utilities.php";
    require_once views . "includes/footer.php";
});
$router->post('/supplier/utilities/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/utilities';
    if (!supplier_can($db, 'hotels', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('utility_add') ? utility_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Reading logged.' : 'Could not log (property + valid date required).'];
    header('Location: ' . $back); exit;
});

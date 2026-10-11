<?php
// FILE: app/routes/users/supplierSafetyRoutes.php
// Packages (#17) + Safety & compliance registers (incidents/visitors/documents,
// #52-55). Owner/staff gated, property-scoped.

@$SECURE or die('Access Denied!');

if (!function_exists('_safety_props')) {
    function _safety_props($db, string $owner): array
    {
        $map = [];
        try { foreach ($db->select('stays', ['id', 'name'], ['user_id' => $owner]) ?: [] as $p) { $map[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
        return $map;
    }
}

// ---- PACKAGES (#17) ------------------------------------------------------------
$router->get('/supplier/packages', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rates', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $packages = function_exists('package_list') ? package_list($db, $stayIds) : [];
    $propMap = _safety_props($db, $owner); $propList = $propMap;
    $canEdit = supplier_can($db, 'rates', 'edit');
    $title = 'Packages'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/packages.php";
    require_once views . "includes/footer.php";
});
$router->post('/supplier/packages/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/packages';
    if (!supplier_can($db, 'rates', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('package_add') ? package_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Package added.' : 'Could not add (name + property required).'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/packages/toggle', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/packages';
    if (!supplier_can($db, 'rates', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('package_set_active') && package_set_active($db, (int) ($_POST['id'] ?? 0), $stayId, (string) ($_POST['active'] ?? '0') === '1');
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});

// ---- SAFETY & COMPLIANCE (#52-55): incidents + visitors + documents ------------
$router->get('/supplier/safety', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rooms', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $incidents = function_exists('incident_list') ? incident_list($db, $stayIds) : [];
    $visitors = function_exists('visitor_list') ? visitor_list($db, $stayIds) : [];
    $documents = function_exists('document_list') ? document_list($db, $stayIds) : [];
    $propMap = _safety_props($db, $owner); $propList = $propMap;
    $canEdit = supplier_can($db, 'rooms', 'edit');
    $title = 'Safety & compliance'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/safety.php";
    require_once views . "includes/footer.php";
});
$router->post('/supplier/safety/incident', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/safety';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('incident_add') ? incident_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Incident logged.' : 'Could not log (summary + property required).'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/safety/incident/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/safety';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('incident_set_status') && incident_set_status($db, (int) ($_POST['id'] ?? 0), $stayId, strtolower(trim((string) ($_POST['status'] ?? ''))));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/safety/visitor', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/safety';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('visitor_add') ? visitor_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Visitor checked in.' : 'Could not log (name + property required).'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/safety/visitor/checkout', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/safety';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('visitor_checkout') && visitor_checkout($db, (int) ($_POST['id'] ?? 0), $stayId);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Checked out.' : 'Could not check out.'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/safety/document', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/safety';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('document_add') ? document_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Document recorded.' : 'Could not record (title + property required).'];
    header('Location: ' . $back); exit;
});

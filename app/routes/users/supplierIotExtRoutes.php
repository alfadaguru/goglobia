<?php
// FILE: app/routes/users/supplierIotExtRoutes.php
// MODULE 53 — Smart building & IoT. Internal device registry, manual meter readings,
// and internal automation rules (threshold -> notify / open maintenance work order /
// record setpoint note). Live telemetry is an HONEST STUB (iot_live_status reports
// not-connected). Owner/staff gated; property-scoped via supplier_can('rooms', …)
// to match the maintenance/engineering screens. Lib: supplier_iot_ext.php.
// Every POST: SUPPLIER_OR_STAFF_AUTH + CSRF::guard + supplier_can(...,$stayId).

@$SECURE or die('Access Denied!');

if (!function_exists('_iot_ext_ctx')) {
    /** View guard: auth + rooms:view. Returns [owner, int[] $stayIds]. */
    function _iot_ext_ctx($db): array
    {
        SUPPLIER_OR_STAFF_AUTH($db);
        $owner = _supplier_stays_owner($db);
        if ($owner === null) { header('Location: ' . root . 'login'); exit; }
        if (!supplier_can($db, 'rooms', 'view')) { _supplier_stays_deny('Not authorised.'); }
        return [$owner, supplier_owned_stay_ids($db, $owner)];
    }
}

if (!function_exists('_iot_ext_mutate_ctx')) {
    /** POST guard: auth + CSRF + rooms:edit on the named property. Returns [owner, orgId]. */
    function _iot_ext_mutate_ctx($db, int $stayId): array
    {
        SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
        $owner = _supplier_stays_owner($db);
        if ($owner === null) { header('Location: ' . root . 'login'); exit; }
        if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
        $orgId = function_exists('supplier_property_org') ? (int) supplier_property_org($db, $stayId) : 0;
        return [$owner, $orgId];
    }
}

if (!function_exists('_iot_ext_back')) {
    function _iot_ext_back(int $stayFilter): string
    {
        return root . 'supplier/iot' . ($stayFilter > 0 ? '?stay_id=' . $stayFilter : '');
    }
}

// ---- LIST / DASHBOARD ----------------------------------------------------------
$router->get('/supplier/iot', function () use ($SECURE, $db) {
    [$owner, $stayIds] = _iot_ext_ctx($db);
    $stayFilter = (int) ($_GET['stay_id'] ?? 0);
    if ($stayFilter > 0 && !in_array($stayFilter, $stayIds, true)) { $stayFilter = 0; }
    $scope = $stayFilter > 0 ? [$stayFilter] : ($stayIds ?: [0]);

    $devices = []; $rules = []; $physRooms = [];
    try {
        _iot_ext_ensure_schema($db);
        $devices = $db->select('stays_iot_devices', '*', ['stay_id' => $scope, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 500]) ?: [];
        $rules   = $db->select('stays_iot_rules', '*', ['stay_id' => $scope, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 500]) ?: [];
        foreach ($scope as $sid) {
            if ($sid > 0) { $physRooms[$sid] = $db->select('stays_physical_rooms', ['id', 'room_number'], ['stay_id' => $sid, 'active' => 1]) ?: []; }
        }
    } catch (\Throwable $e) { error_log('iot ext list: ' . $e->getMessage()); }

    $dash = function_exists('iot_dashboard') ? iot_dashboard($db, $scope) : [];
    // Live status is a stub; show per-scope connection truth (always not-connected).
    $live = function_exists('iot_live_status') ? iot_live_status($db, $stayFilter) : ['connected' => false, 'message' => ''];

    $devMap = [];
    foreach ($devices as $d) { $devMap[(int) $d['id']] = (string) $d['name']; }

    $propMap = [];
    if (!empty($stayIds)) { try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {} }
    $canEdit = supplier_can($db, 'rooms', 'edit');

    $view = 'list';
    $title = 'Smart building & IoT'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/iot-ext.php";
    require_once views . "includes/footer.php";
});

// ---- DEVICE DETAIL (readings + rules + reading form) ---------------------------
$router->get('/supplier/iot/device/([0-9]+)', function ($id) use ($SECURE, $db) {
    [$owner, $stayIds] = _iot_ext_ctx($db);
    $device = function_exists('iot_device_get') ? iot_device_get($db, (int) $id, $stayIds) : null;
    if (!$device) { _supplier_stays_deny('Device not found.'); }
    $stayId = (int) $device['stay_id'];
    $readings = function_exists('iot_device_readings') ? iot_device_readings($db, (int) $id, $stayId, 100) : [];
    $rules = [];
    try { $rules = $db->select('stays_iot_rules', '*', ['device_id' => (int) $id, 'stay_id' => $stayId, 'ORDER' => ['id' => 'DESC']]) ?: []; } catch (\Throwable $e) {}
    $runs = [];
    try { $runs = $db->select('stays_iot_rule_runs', '*', ['device_id' => (int) $id, 'stay_id' => $stayId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 30]) ?: []; } catch (\Throwable $e) {}
    $propMap = [];
    try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    $live = function_exists('iot_live_status') ? iot_live_status($db, $stayId) : ['connected' => false, 'message' => ''];
    $canEdit = supplier_can($db, 'rooms', 'edit');

    $view = 'detail';
    $title = 'IoT device'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/iot-ext.php";
    require_once views . "includes/footer.php";
});

// ---- ADD DEVICE ----------------------------------------------------------------
$router->post('/supplier/iot/device', function () use ($SECURE, $db) {
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    [$owner, $orgId] = _iot_ext_mutate_ctx($db, $stayId);
    $ok = function_exists('iot_device_add') && iot_device_add($db, $stayId, $orgId, $_POST);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Device registered.' : 'Could not register device (name + type required).'];
    header('Location: ' . _iot_ext_back($stayId)); exit;
});

// ---- TOGGLE DEVICE ACTIVE ------------------------------------------------------
$router->post('/supplier/iot/device/toggle', function () use ($SECURE, $db) {
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    [$owner, $orgId] = _iot_ext_mutate_ctx($db, $stayId);
    $ok = function_exists('iot_device_toggle') && iot_device_toggle($db, (int) ($_POST['id'] ?? 0), $stayId);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Device updated.' : 'Could not update device.'];
    header('Location: ' . _iot_ext_back($stayId)); exit;
});

// ---- LOG READING ---------------------------------------------------------------
$router->post('/supplier/iot/reading', function () use ($SECURE, $db) {
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    [$owner, $orgId] = _iot_ext_mutate_ctx($db, $stayId);
    $deviceId = (int) ($_POST['device_id'] ?? 0);
    $newId = function_exists('iot_reading_log') ? iot_reading_log($db, $deviceId, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $newId > 0 ? 'success' : 'error', 'text' => $newId > 0 ? 'Reading recorded (rules evaluated).' : 'Could not record reading (numeric value required).'];
    header('Location: ' . root . 'supplier/iot/device/' . $deviceId); exit;
});

// ---- ADD RULE ------------------------------------------------------------------
$router->post('/supplier/iot/rule', function () use ($SECURE, $db) {
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    [$owner, $orgId] = _iot_ext_mutate_ctx($db, $stayId);
    $ok = function_exists('iot_rule_add') && iot_rule_add($db, $stayId, $orgId, $_POST);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Automation rule saved.' : 'Could not save rule (device, name, operator, threshold required).'];
    $deviceId = (int) ($_POST['device_id'] ?? 0);
    header('Location: ' . ($deviceId > 0 ? root . 'supplier/iot/device/' . $deviceId : _iot_ext_back($stayId))); exit;
});

// ---- TOGGLE RULE ACTIVE --------------------------------------------------------
$router->post('/supplier/iot/rule/toggle', function () use ($SECURE, $db) {
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    [$owner, $orgId] = _iot_ext_mutate_ctx($db, $stayId);
    $ok = function_exists('iot_rule_toggle') && iot_rule_toggle($db, (int) ($_POST['id'] ?? 0), $stayId);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Rule updated.' : 'Could not update rule.'];
    $deviceId = (int) ($_POST['device_id'] ?? 0);
    header('Location: ' . ($deviceId > 0 ? root . 'supplier/iot/device/' . $deviceId : _iot_ext_back($stayId))); exit;
});

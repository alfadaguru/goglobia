<?php
// FILE: app/routes/users/supplierMaintExtRoutes.php
// MODULE 27 (maintenance & engineering → 360) extension: fixed-asset register, contractors,
// preventive-maintenance schedules, repair costing, SLA + analytics — on top of the base
// supplier_maintenance.php (work orders, OOO/OOS). Owner/staff gated. supplier_maint_ext.php.
// (Rebuilt by the lead after a case-insensitive-FS filename collision removed the original.)

@$SECURE or die('Access Denied!');

$router->get('/supplier/maintenance/engineering', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rooms', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $stayFilter = (int) ($_GET['stay_id'] ?? 0);
    if ($stayFilter > 0 && !in_array($stayFilter, $stayIds, true)) { $stayFilter = 0; }
    $scope = $stayFilter > 0 ? [$stayFilter] : ($stayIds ?: [0]);

    // Run any due preventive-maintenance schedules (self-healing, idempotent).
    if (function_exists('maint_schedules_run_due')) { try { maint_schedules_run_due($db, $scope); } catch (\Throwable $e) {} }

    $assets = []; $contractors = []; $schedules = []; $openOrders = []; $costLines = []; $physRooms = [];
    try {
        $assets      = $db->select('stays_maint_assets', '*', ['stay_id' => $scope, 'ORDER' => ['id' => 'DESC']]) ?: [];
        $contractors = $db->select('stays_maint_contractors', '*', ['stay_id' => $scope, 'ORDER' => ['name' => 'ASC']]) ?: [];
        $schedules   = $db->select('stays_maint_schedules', '*', ['stay_id' => $scope, 'ORDER' => ['id' => 'DESC']]) ?: [];
        $openOrders  = $db->select('stays_work_orders', '*', ['stay_id' => $scope, 'status' => ['open', 'in_progress'], 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 200]) ?: [];
        foreach ($openOrders as &$__wo) { if (function_exists('maint_wo_enrich')) { try { maint_wo_enrich($db, (int) $__wo['id'], (int) $__wo['stay_id']); } catch (\Throwable $e) {} } }
        unset($__wo);
        foreach ($openOrders as $wo) {
            $costLines[(int) $wo['id']] = $db->select('stays_maint_wo_costs', '*', ['wo_id' => (int) $wo['id'], 'ORDER' => ['id' => 'ASC']]) ?: [];
        }
        foreach ($scope as $sid) {
            if ($sid > 0) { $physRooms[$sid] = $db->select('stays_physical_rooms', ['id', 'room_number'], ['stay_id' => $sid, 'active' => 1]) ?: []; }
        }
    } catch (\Throwable $e) { error_log('maint ext list: ' . $e->getMessage()); }
    $analytics = function_exists('maint_analytics') ? maint_analytics($db, $scope) : [];
    $propMap = [];
    if (!empty($stayIds)) { try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {} }
    $canEdit = supplier_can($db, 'rooms', 'edit');

    $title = 'Maintenance & engineering'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/maintenance-ext.php";
    require_once views . "includes/footer.php";
});

if (!function_exists('_maint_ext_ctx')) {
    /** Shared POST guard: auth + CSRF + reservations/rooms edit on the property. */
    function _maint_ext_ctx($db, int $stayId): bool
    {
        SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
        $owner = _supplier_stays_owner($db);
        if ($owner === null) { header('Location: ' . root . 'login'); exit; }
        if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
        return true;
    }
}
$__back = function ($stayId) { return root . 'supplier/maintenance/engineering' . ($stayId > 0 ? '?stay_id=' . $stayId : ''); };

$router->post('/supplier/maintenance/engineering/asset', function () use ($SECURE, $db, $__back) {
    $stayId = (int) ($_POST['stay_id'] ?? 0); _maint_ext_ctx($db, $stayId);
    $id = function_exists('maint_asset_create') ? maint_asset_create($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Asset added.' : 'Could not add (name required).'];
    header('Location: ' . $__back($stayId)); exit;
});
$router->post('/supplier/maintenance/engineering/asset/status', function () use ($SECURE, $db, $__back) {
    $stayId = (int) ($_POST['stay_id'] ?? 0); _maint_ext_ctx($db, $stayId);
    $ok = function_exists('maint_asset_set_status') && maint_asset_set_status($db, (int) ($_POST['asset_id'] ?? 0), $stayId, strtolower((string) ($_POST['status'] ?? '')));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Asset updated.' : 'Could not update.'];
    header('Location: ' . $__back($stayId)); exit;
});
$router->post('/supplier/maintenance/engineering/contractor', function () use ($SECURE, $db, $__back) {
    $stayId = (int) ($_POST['stay_id'] ?? 0); _maint_ext_ctx($db, $stayId);
    $id = function_exists('maint_contractor_create') ? maint_contractor_create($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Contractor added.' : 'Could not add.'];
    header('Location: ' . $__back($stayId)); exit;
});
$router->post('/supplier/maintenance/engineering/contractor/toggle', function () use ($SECURE, $db, $__back) {
    $stayId = (int) ($_POST['stay_id'] ?? 0); _maint_ext_ctx($db, $stayId);
    $ok = function_exists('maint_contractor_set_active') && maint_contractor_set_active($db, (int) ($_POST['id'] ?? 0), $stayId, (string) ($_POST['active'] ?? '1') === '1');
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Contractor updated.' : 'Could not update.'];
    header('Location: ' . $__back($stayId)); exit;
});
$router->post('/supplier/maintenance/engineering/cost', function () use ($SECURE, $db, $__back) {
    $stayId = (int) ($_POST['stay_id'] ?? 0); _maint_ext_ctx($db, $stayId);
    $ok = function_exists('maint_wo_cost_add') && maint_wo_cost_add($db, (int) ($_POST['wo_id'] ?? 0), $stayId, $_POST);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Cost recorded.' : 'Could not record.'];
    header('Location: ' . $__back($stayId)); exit;
});
$router->post('/supplier/maintenance/engineering/cost/delete', function () use ($SECURE, $db, $__back) {
    $stayId = (int) ($_POST['stay_id'] ?? 0); _maint_ext_ctx($db, $stayId);
    $ok = function_exists('maint_wo_cost_delete') && maint_wo_cost_delete($db, (int) ($_POST['cost_id'] ?? 0), $stayId);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Cost removed.' : 'Could not remove.'];
    header('Location: ' . $__back($stayId)); exit;
});
$router->post('/supplier/maintenance/engineering/wo/associate', function () use ($SECURE, $db, $__back) {
    $stayId = (int) ($_POST['stay_id'] ?? 0); _maint_ext_ctx($db, $stayId);
    $ok = function_exists('maint_wo_enrich') && maint_wo_enrich($db, (int) ($_POST['wo_id'] ?? 0), $stayId, $_POST);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Work order updated.' : 'Could not update.'];
    header('Location: ' . $__back($stayId)); exit;
});
$router->post('/supplier/maintenance/engineering/schedule', function () use ($SECURE, $db, $__back) {
    $stayId = (int) ($_POST['stay_id'] ?? 0); _maint_ext_ctx($db, $stayId);
    $id = function_exists('maint_schedule_create') ? maint_schedule_create($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Schedule added.' : 'Could not add.'];
    header('Location: ' . $__back($stayId)); exit;
});
$router->post('/supplier/maintenance/engineering/schedule/toggle', function () use ($SECURE, $db, $__back) {
    $stayId = (int) ($_POST['stay_id'] ?? 0); _maint_ext_ctx($db, $stayId);
    $ok = function_exists('maint_schedule_set_active') && maint_schedule_set_active($db, (int) ($_POST['id'] ?? 0), $stayId, (string) ($_POST['active'] ?? '1') === '1');
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Schedule updated.' : 'Could not update.'];
    header('Location: ' . $__back($stayId)); exit;
});

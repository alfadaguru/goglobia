<?php
// FILE: app/routes/users/supplierHrRoutes.php
// HR / workforce (#44): employee register + leave. Owner-only (org-level workforce).

@$SECURE or die('Access Denied!');

if (!function_exists('_hr_org')) {
    function _hr_org($db): int
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin']) || empty($ctx['is_owner'])) { return 0; }
        return function_exists('supplier_org_for_owner') ? (int) supplier_org_for_owner($db, (string) $ctx['owner']) : 0;
    }
}

$router->get('/supplier/hr', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $orgId = _hr_org($db);
    if ($orgId <= 0) { _supplier_stays_deny('Only the account owner can manage HR.'); }
    $owner = _supplier_stays_owner($db);
    $employees = function_exists('hr_employee_list') ? hr_employee_list($db, $orgId) : [];
    $leave = function_exists('hr_leave_list') ? hr_leave_list($db, $orgId) : [];
    $empNames = []; foreach ($employees as $e) { $empNames[(int) $e['id']] = $e['name']; }
    $propList = [];
    if ($owner !== null) { try { foreach ($db->select('stays', ['id', 'name'], ['user_id' => $owner]) ?: [] as $p) { $propList[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {} }
    $title = 'HR & workforce'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/hr.php";
    require_once views . "includes/footer.php";
});

$router->post('/supplier/hr/employee', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _hr_org($db); $back = root . 'supplier/hr';
    if ($orgId <= 0) { _supplier_stays_deny('Only the account owner can manage HR.'); }
    $id = function_exists('hr_employee_add') ? hr_employee_add($db, $orgId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Employee added.' : 'Could not add (name required).'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/hr/employee/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _hr_org($db); $back = root . 'supplier/hr';
    if ($orgId <= 0) { _supplier_stays_deny('Only the account owner can manage HR.'); }
    $ok = function_exists('hr_employee_set_status') && hr_employee_set_status($db, $orgId, (int) ($_POST['id'] ?? 0), strtolower(trim((string) ($_POST['status'] ?? ''))));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/hr/leave', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _hr_org($db); $back = root . 'supplier/hr';
    if ($orgId <= 0) { _supplier_stays_deny('Only the account owner can manage HR.'); }
    $id = function_exists('hr_leave_add') ? hr_leave_add($db, $orgId, (int) ($_POST['employee_id'] ?? 0), $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Leave requested.' : 'Could not record leave (employee + valid dates required).'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/hr/leave/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _hr_org($db); $back = root . 'supplier/hr';
    if ($orgId <= 0) { _supplier_stays_deny('Only the account owner can manage HR.'); }
    $ok = function_exists('hr_leave_set_status') && hr_leave_set_status($db, $orgId, (int) ($_POST['id'] ?? 0), strtolower(trim((string) ($_POST['status'] ?? ''))));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});

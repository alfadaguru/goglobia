<?php
// FILE: app/routes/users/supplierHrExtRoutes.php
// MODULE 44 (HR & workforce) extension: departments/positions, shift scheduling,
// attendance clock in/out, timesheets, task assignment, performance KPIs, training
// records, payroll run + GL accrual + CSV export — on top of the base
// supplier_hr.php (employees + leave). Owner-level/org-scoped. supplier_hr_ext.php.
//
// Gate model MATCHES the base HR routes: _hr_ext_org() resolves the org and
// requires is_owner (returns 0 otherwise). Every POST additionally runs
// SUPPLIER_OR_STAFF_AUTH + CSRF::guard + supplier_can($db,'stays','edit') — the
// owner is unrestricted on 'stays' so this is a correct belt-and-braces check, not
// a widening. HR has no dedicated capability key (dormant), so we do NOT invent one.

@$SECURE or die('Access Denied!');

if (!function_exists('_hr_ext_org')) {
    /** Resolve the acting owner's org id; 0 unless the caller is the account owner. */
    function _hr_ext_org($db): int
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin']) || empty($ctx['is_owner'])) { return 0; }
        return function_exists('supplier_org_for_owner') ? (int) supplier_org_for_owner($db, (string) $ctx['owner']) : 0;
    }
}

if (!function_exists('_hr_ext_post_ctx')) {
    /** Shared POST guard: auth + CSRF + owner-org + supplier_can('stays','edit'). Returns org id (>0). */
    function _hr_ext_post_ctx($db): int
    {
        SUPPLIER_OR_STAFF_AUTH($db);
        CSRF::guard();
        $orgId = _hr_ext_org($db);
        if ($orgId <= 0 || !supplier_can($db, 'stays', 'edit')) {
            _supplier_stays_deny('Only the account owner can manage HR.');
        }
        return $orgId;
    }
}

// ---------------------------------------------------------------------------
// PAGE
// ---------------------------------------------------------------------------
$router->get('/supplier/hr/workforce', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $orgId = _hr_ext_org($db);
    if ($orgId <= 0 || !supplier_can($db, 'stays', 'view')) { _supplier_stays_deny('Only the account owner can manage HR.'); }
    $owner = _supplier_stays_owner($db);

    $employees   = function_exists('hr_employee_list') ? hr_employee_list($db, $orgId) : [];
    $departments = function_exists('hrx_departments') ? hrx_departments($db, $orgId) : [];
    $positions   = function_exists('hrx_positions') ? hrx_positions($db, $orgId) : [];
    $shifts      = function_exists('hrx_shifts') ? hrx_shifts($db, $orgId) : [];
    $attendance  = function_exists('hrx_attendance') ? hrx_attendance($db, $orgId) : [];
    $timesheets  = function_exists('hrx_timesheets') ? hrx_timesheets($db, $orgId) : [];
    $tasks       = function_exists('hrx_tasks') ? hrx_tasks($db, $orgId) : [];
    $kpis        = function_exists('hrx_kpis') ? hrx_kpis($db, $orgId) : [];
    $training    = function_exists('hrx_training') ? hrx_training($db, $orgId) : [];
    $payruns     = function_exists('hrx_payruns') ? hrx_payruns($db, $orgId) : [];
    $analytics   = function_exists('hrx_analytics') ? hrx_analytics($db, $orgId) : [];
    $device      = function_exists('hrx_device_status') ? hrx_device_status($db, $orgId) : ['connected' => false, 'message' => ''];

    $empNames = []; foreach ($employees as $e) { $empNames[(int) $e['id']] = $e['name']; }
    $deptNames = []; foreach ($departments as $d) { $deptNames[(int) $d['id']] = $d['name']; }
    $propList = [];
    if ($owner !== null) { try { foreach ($db->select('stays', ['id', 'name'], ['user_id' => $owner]) ?: [] as $p) { $propList[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {} }

    $title = 'Workforce'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/hr-ext.php";
    require_once views . "includes/footer.php";
});

$__hrBack = root . 'supplier/hr/workforce';

// ---------------------------------------------------------------------------
// DEPARTMENTS & POSITIONS
// ---------------------------------------------------------------------------
$router->post('/supplier/hr/workforce/department', function () use ($SECURE, $db, $__hrBack) {
    $orgId = _hr_ext_post_ctx($db);
    $id = function_exists('hrx_department_add') ? hrx_department_add($db, $orgId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Department added.' : 'Could not add (name required).'];
    header('Location: ' . $__hrBack); exit;
});
$router->post('/supplier/hr/workforce/position', function () use ($SECURE, $db, $__hrBack) {
    $orgId = _hr_ext_post_ctx($db);
    $id = function_exists('hrx_position_add') ? hrx_position_add($db, $orgId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Position added.' : 'Could not add (title required).'];
    header('Location: ' . $__hrBack); exit;
});

// ---------------------------------------------------------------------------
// SHIFTS
// ---------------------------------------------------------------------------
$router->post('/supplier/hr/workforce/shift', function () use ($SECURE, $db, $__hrBack) {
    $orgId = _hr_ext_post_ctx($db);
    $id = function_exists('hrx_shift_add') ? hrx_shift_add($db, $orgId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Shift scheduled.' : 'Could not schedule (employee, date, times required; one shift per employee per day).'];
    header('Location: ' . $__hrBack); exit;
});
$router->post('/supplier/hr/workforce/shift/delete', function () use ($SECURE, $db, $__hrBack) {
    $orgId = _hr_ext_post_ctx($db);
    $ok = function_exists('hrx_shift_delete') && hrx_shift_delete($db, $orgId, (int) ($_POST['id'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Shift removed.' : 'Could not remove.'];
    header('Location: ' . $__hrBack); exit;
});

// ---------------------------------------------------------------------------
// ATTENDANCE (clock in / out)
// ---------------------------------------------------------------------------
$router->post('/supplier/hr/workforce/clock-in', function () use ($SECURE, $db, $__hrBack) {
    $orgId = _hr_ext_post_ctx($db);
    $id = function_exists('hrx_clock_in') ? hrx_clock_in($db, $orgId, (int) ($_POST['employee_id'] ?? 0)) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Clocked in.' : 'Could not clock in (already clocked in?).'];
    header('Location: ' . $__hrBack); exit;
});
$router->post('/supplier/hr/workforce/clock-out', function () use ($SECURE, $db, $__hrBack) {
    $orgId = _hr_ext_post_ctx($db);
    $ok = function_exists('hrx_clock_out') && hrx_clock_out($db, $orgId, (int) ($_POST['employee_id'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Clocked out.' : 'Could not clock out (no open entry?).'];
    header('Location: ' . $__hrBack); exit;
});

// ---------------------------------------------------------------------------
// TIMESHEETS
// ---------------------------------------------------------------------------
$router->post('/supplier/hr/workforce/timesheet', function () use ($SECURE, $db, $__hrBack) {
    $orgId = _hr_ext_post_ctx($db);
    $id = function_exists('hrx_timesheet_upsert') ? hrx_timesheet_upsert($db, $orgId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Timesheet saved.' : 'Could not save (employee + valid period required; already in a payrun?).'];
    header('Location: ' . $__hrBack); exit;
});
$router->post('/supplier/hr/workforce/timesheet/fill', function () use ($SECURE, $db, $__hrBack) {
    $orgId = _hr_ext_post_ctx($db);
    $ok = function_exists('hrx_timesheet_fill_from_attendance') && hrx_timesheet_fill_from_attendance($db, $orgId, (int) ($_POST['id'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Hours filled from attendance.' : 'Could not fill (draft only).'];
    header('Location: ' . $__hrBack); exit;
});
$router->post('/supplier/hr/workforce/timesheet/status', function () use ($SECURE, $db, $__hrBack) {
    $orgId = _hr_ext_post_ctx($db);
    $ok = function_exists('hrx_timesheet_set_status') && hrx_timesheet_set_status($db, $orgId, (int) ($_POST['id'] ?? 0), strtolower(trim((string) ($_POST['status'] ?? ''))));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Timesheet updated.' : 'Could not update (locked into a payrun?).'];
    header('Location: ' . $__hrBack); exit;
});

// ---------------------------------------------------------------------------
// TASKS
// ---------------------------------------------------------------------------
$router->post('/supplier/hr/workforce/task', function () use ($SECURE, $db, $__hrBack) {
    $orgId = _hr_ext_post_ctx($db);
    $id = function_exists('hrx_task_add') ? hrx_task_add($db, $orgId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Task assigned.' : 'Could not assign (employee + title required).'];
    header('Location: ' . $__hrBack); exit;
});
$router->post('/supplier/hr/workforce/task/status', function () use ($SECURE, $db, $__hrBack) {
    $orgId = _hr_ext_post_ctx($db);
    $ok = function_exists('hrx_task_set_status') && hrx_task_set_status($db, $orgId, (int) ($_POST['id'] ?? 0), strtolower(trim((string) ($_POST['status'] ?? ''))));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Task updated.' : 'Could not update.'];
    header('Location: ' . $__hrBack); exit;
});

// ---------------------------------------------------------------------------
// PERFORMANCE KPIs
// ---------------------------------------------------------------------------
$router->post('/supplier/hr/workforce/kpi', function () use ($SECURE, $db, $__hrBack) {
    $orgId = _hr_ext_post_ctx($db);
    $id = function_exists('hrx_kpi_add') ? hrx_kpi_add($db, $orgId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Review recorded.' : 'Could not record (employee + period required).'];
    header('Location: ' . $__hrBack); exit;
});

// ---------------------------------------------------------------------------
// TRAINING
// ---------------------------------------------------------------------------
$router->post('/supplier/hr/workforce/training', function () use ($SECURE, $db, $__hrBack) {
    $orgId = _hr_ext_post_ctx($db);
    $id = function_exists('hrx_training_add') ? hrx_training_add($db, $orgId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Training recorded.' : 'Could not record (employee + course required).'];
    header('Location: ' . $__hrBack); exit;
});

// ---------------------------------------------------------------------------
// PAYROLL (MONEY — balanced GL accrual; CSV export is a file only, no disbursement)
// ---------------------------------------------------------------------------
$router->post('/supplier/hr/workforce/payrun', function () use ($SECURE, $db, $__hrBack) {
    $orgId = _hr_ext_post_ctx($db);
    $id = function_exists('hrx_payrun_create') ? hrx_payrun_create($db, $orgId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Draft payrun built from approved timesheets.' : 'Could not build (no approved, unpaid timesheets in that period?).'];
    header('Location: ' . $__hrBack); exit;
});
$router->post('/supplier/hr/workforce/payrun/post', function () use ($SECURE, $db, $__hrBack) {
    $orgId = _hr_ext_post_ctx($db);
    $res = function_exists('hrx_payrun_post') ? hrx_payrun_post($db, $orgId, (int) ($_POST['id'] ?? 0)) : ['ok' => false, 'message' => 'Unavailable'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => (string) ($res['message'] ?? '')];
    header('Location: ' . $__hrBack); exit;
});
$router->post('/supplier/hr/workforce/payrun/export', function () use ($SECURE, $db, $__hrBack) {
    $orgId = _hr_ext_post_ctx($db);
    $res = function_exists('hrx_payrun_export_csv') ? hrx_payrun_export_csv($db, $orgId, (int) ($_POST['id'] ?? 0)) : ['ok' => false];
    if (!empty($res['ok'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', (string) $res['filename']) . '"');
        header('X-Content-Type-Options: nosniff');
        echo $res['csv'];
        exit;
    }
    $_SESSION['message'] = ['type' => 'error', 'text' => (string) ($res['message'] ?? 'Could not export.')];
    header('Location: ' . $__hrBack); exit;
});

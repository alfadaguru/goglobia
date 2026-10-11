<?php
// FILE: app/routes/users/supplierHkExtRoutes.php
// Housekeeping EXTENSION routes (PMS module 24 deepening): cleaning-task board,
// auto-generation, task lifecycle (assign/start/done/DND/refused), checklist,
// supervisor inspection, and the productivity report.
//
// Owner/staff scoped via supplier_can('rooms', …, $stayId). Reuses the stays
// owner/deny helpers from supplierStaysRoutes.php (loaded earlier in _routes.php)
// and the hk_* helpers in app/lib/supplier_hk_ext.php. No money/gateway here —
// pure operational overlay (see the lib header).

@$SECURE or die('Access Denied!');

// ----------------------------------------------------------------------------
// GET /supplier/housekeeping/tasks — the cleaning-task board for ONE property +
// business date. Auto-generates the day's tasks on view (idempotent).
// ----------------------------------------------------------------------------
$router->get('/supplier/housekeeping/tasks', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rooms', 'view')) { _supplier_stays_deny('You are not authorised to view housekeeping.'); }

    $stayIds = supplier_owned_stay_ids($db, $owner);
    $stayFilter = (int) ($_GET['stay_id'] ?? 0);
    if ($stayFilter > 0 && !in_array($stayFilter, $stayIds, true)) { $stayFilter = 0; } // not owned → ignore
    // A property must be chosen for the task board (tasks are per-property/day).
    if ($stayFilter === 0 && count($stayIds) === 1) { $stayFilter = (int) $stayIds[0]; }

    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date'] ?? '')) ? (string) $_GET['date'] : date('Y-m-d');
    $canEdit = supplier_can($db, 'rooms', 'edit', $stayFilter > 0 ? $stayFilter : null);

    // Auto-generate the day's tasks on view when a single property is in scope and the
    // viewer can edit (so a read-only role doesn't trigger writes).
    $genMsg = null;
    if ($stayFilter > 0 && $canEdit && function_exists('hk_generate_tasks')) {
        $g = hk_generate_tasks($db, $stayFilter, $date);
        if (($g['created'] ?? 0) > 0) { $genMsg = $g['created'] . ' new task(s) generated for ' . htmlspecialchars($date) . '.'; }
    }

    $tasks = ($stayFilter > 0 && function_exists('hk_tasks_for_board')) ? hk_tasks_for_board($db, $stayFilter, $date) : [];

    // Physical rooms for the manual "add task" picker.
    $physRooms = [];
    if ($stayFilter > 0) {
        try { $physRooms = $db->select('stays_physical_rooms', ['id', 'room_number'],
            ['stay_id' => $stayFilter, 'active' => 1, 'ORDER' => ['room_number' => 'ASC']]) ?: []; } catch (\Throwable $e) {}
    }

    $propMap = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }

    $title = 'Housekeeping tasks'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/housekeeping-ext.php";
    require_once views . "includes/footer.php";
});

// ----------------------------------------------------------------------------
// GET /supplier/housekeeping/productivity — attendant productivity report
// ----------------------------------------------------------------------------
$router->get('/supplier/housekeeping/productivity', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rooms', 'view')) { _supplier_stays_deny('Not authorised.'); }

    $stayIds = supplier_owned_stay_ids($db, $owner);
    $stayFilter = (int) ($_GET['stay_id'] ?? 0);
    if ($stayFilter > 0 && !in_array($stayFilter, $stayIds, true)) { $stayFilter = 0; }
    if ($stayFilter === 0 && count($stayIds) === 1) { $stayFilter = (int) $stayIds[0]; }

    $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : date('Y-m-d');
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : date('Y-m-d', strtotime('-6 days'));

    $report = ($stayFilter > 0 && function_exists('hk_productivity_report')) ? hk_productivity_report($db, $stayFilter, $from, $to) : null;

    $propMap = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }
    $reportMode = true; // view uses this to render the report instead of the board

    $title = 'Housekeeping productivity'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/housekeeping-ext.php";
    require_once views . "includes/footer.php";
});

// ----------------------------------------------------------------------------
// POST /supplier/housekeeping/tasks/generate — force (re)generate the day's tasks
// ----------------------------------------------------------------------------
$router->post('/supplier/housekeeping/tasks/generate', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['date'] ?? '')) ? (string) $_POST['date'] : date('Y-m-d');
    $back = root . 'supplier/housekeeping/tasks?stay_id=' . $stayId . '&date=' . rawurlencode($date);
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }

    $g = function_exists('hk_generate_tasks') ? hk_generate_tasks($db, $stayId, $date) : ['created' => 0];
    $_SESSION['message'] = ['type' => 'success', 'text' => ($g['created'] ?? 0) . ' task(s) generated, ' . ($g['skipped'] ?? 0) . ' already present.'];
    header('Location: ' . $back); exit;
});

// ----------------------------------------------------------------------------
// POST /supplier/housekeeping/tasks/add — manually add one task (deep clean, turndown, …)
// ----------------------------------------------------------------------------
$router->post('/supplier/housekeeping/tasks/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['date'] ?? '')) ? (string) $_POST['date'] : date('Y-m-d');
    $back = root . 'supplier/housekeeping/tasks?stay_id=' . $stayId . '&date=' . rawurlencode($date);
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }

    $id = function_exists('hk_task_create') ? hk_task_create($db, $stayId, [
        'physical_room_id' => (int) ($_POST['physical_room_id'] ?? 0),
        'task_type'        => (string) ($_POST['task_type'] ?? ''),
        'business_date'    => $date,
        'priority'         => (string) ($_POST['priority'] ?? 'normal'),
        'attendant'        => (string) ($_POST['attendant'] ?? ''),
    ]) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Task added.' : 'Could not add the task (room + type required).'];
    header('Location: ' . $back); exit;
});

// ----------------------------------------------------------------------------
// POST /supplier/housekeeping/tasks/assign — set the attendant on a task
// ----------------------------------------------------------------------------
$router->post('/supplier/housekeeping/tasks/assign', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $taskId = (int) ($_POST['task_id'] ?? 0);
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['date'] ?? '')) ? (string) $_POST['date'] : date('Y-m-d');
    $back = root . 'supplier/housekeeping/tasks?stay_id=' . $stayId . '&date=' . rawurlencode($date);
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }

    $ok = function_exists('hk_task_assign') && hk_task_assign($db, $taskId, $stayId, (string) ($_POST['attendant'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Attendant updated.' : 'Could not assign.'];
    header('Location: ' . $back); exit;
});

// ----------------------------------------------------------------------------
// POST /supplier/housekeeping/tasks/status — advance a task (start/done/DND/refused/reopen)
// ----------------------------------------------------------------------------
$router->post('/supplier/housekeeping/tasks/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $taskId = (int) ($_POST['task_id'] ?? 0);
    $to = strtolower(trim((string) ($_POST['to'] ?? '')));
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['date'] ?? '')) ? (string) $_POST['date'] : date('Y-m-d');
    $back = root . 'supplier/housekeeping/tasks?stay_id=' . $stayId . '&date=' . rawurlencode($date);
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }

    $ok = function_exists('hk_task_set_status') && hk_task_set_status($db, $taskId, $stayId, $to, (string) ($_SESSION['user_id'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Task updated.' : 'Could not update that task (invalid transition?).'];
    header('Location: ' . $back); exit;
});

// ----------------------------------------------------------------------------
// POST /supplier/housekeeping/tasks/checklist — toggle one checklist item
// ----------------------------------------------------------------------------
$router->post('/supplier/housekeeping/tasks/checklist', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $taskId = (int) ($_POST['task_id'] ?? 0);
    $itemKey = (string) ($_POST['item_key'] ?? '');
    $done = !empty($_POST['done']);
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['date'] ?? '')) ? (string) $_POST['date'] : date('Y-m-d');
    $back = root . 'supplier/housekeeping/tasks?stay_id=' . $stayId . '&date=' . rawurlencode($date);
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }

    $ok = function_exists('hk_task_checklist_toggle') && hk_task_checklist_toggle($db, $taskId, $stayId, $itemKey, $done);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Checklist updated.' : 'Could not update the checklist.'];
    header('Location: ' . $back); exit;
});

// ----------------------------------------------------------------------------
// POST /supplier/housekeeping/tasks/inspect — supervisor inspection (pass/fail)
// ----------------------------------------------------------------------------
$router->post('/supplier/housekeeping/tasks/inspect', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $taskId = (int) ($_POST['task_id'] ?? 0);
    $pass = ((string) ($_POST['result'] ?? '')) === 'pass';
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['date'] ?? '')) ? (string) $_POST['date'] : date('Y-m-d');
    $back = root . 'supplier/housekeeping/tasks?stay_id=' . $stayId . '&date=' . rawurlencode($date);
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }

    $ok = function_exists('hk_task_inspect') && hk_task_inspect($db, $taskId, $stayId, $pass,
        (string) ($_SESSION['user_id'] ?? ''), (string) ($_POST['fail_reason'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error',
        'text' => $ok ? ($pass ? 'Inspection passed; room marked inspected.' : 'Inspection failed; task reopened for rework.') : 'Could not inspect (task must be marked done first).'];
    header('Location: ' . $back); exit;
});

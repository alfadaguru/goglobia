<?php
// FILE: app/routes/users/supplierWorkflowExtRoutes.php
// MODULE 46 (Task & workflow management → 360) routes: the internal task board,
// templates, recurring tasks, WHEN/IF/THEN automation rules, approvals, analytics,
// and the (DB-GATED) cron tick that runs recurrences + the rule engine.
// Base event bus (emit_event/audit_log) stays in supplier_events.php — untouched.
//
// AUTH: owner/staff gated. Tasks are ORG-scoped operational data, so view/write are
// gated on supplier_can('reservations', 'view'|'edit') (the owner-wide operational
// permission; owner is unrestricted). Per-task POSTs resolve the owning org FROM THE
// TASK (never trust client org/owner) and re-check the acting owner — IDOR guard.
// Approvals additionally require the 'finance' edit permission (sign-off authority).
// NO money is posted anywhere in this module.

@$SECURE or die('Access Denied!');

// ---------------------------------------------------------------------------
// Internal: resolve the acting owner's org once (one org per owner here). Returns
// [owner, orgId] or exits via _supplier_stays_deny. Admin has no single org → we
// resolve their org from the first owned stay if present, else deny (board is org-bound).
// ---------------------------------------------------------------------------
if (!function_exists('_wf_ext_owner_org')) {
    function _wf_ext_owner_org($db): array
    {
        $owner = _supplier_stays_owner($db);
        if ($owner === null) { header('Location: ' . root . 'login'); exit; }
        $orgId = function_exists('supplier_org_for_owner') ? (int) supplier_org_for_owner($db, $owner) : 0;
        if ($orgId <= 0) {
            // Fall back to an owned property's org (and ensure it is stamped).
            $stayIds = supplier_owned_stay_ids($db, $owner);
            if (!empty($stayIds) && function_exists('supplier_property_org_ensure')) {
                $orgId = (int) supplier_property_org_ensure($db, (int) $stayIds[0]);
            }
        }
        if ($orgId <= 0) { _supplier_stays_deny('No organisation found for your account yet.'); }
        return [$owner, $orgId];
    }
}

// ---------------------------------------------------------------------------
// Internal: resolve + authorize a per-task action. Returns [owner, orgId] on success,
// or exits. Confirms the task belongs to the acting owner's org (IDOR guard).
// ---------------------------------------------------------------------------
if (!function_exists('_wf_ext_task_guard')) {
    function _wf_ext_task_guard($db, int $taskId, string $need = 'edit'): array
    {
        [$owner, $orgId] = _wf_ext_owner_org($db);
        if (!supplier_can($db, 'reservations', $need)) { _supplier_stays_deny('Not authorised.'); }
        $info = function_exists('task_owner_org') ? task_owner_org($db, $taskId) : null;
        if ($info === null || (int) $info['org_id'] !== $orgId || (string) $info['owner_user_id'] !== $owner) {
            _supplier_stays_deny('Task not found.');
        }
        return [$owner, $orgId];
    }
}

// =================== BOARD (GET) ============================================
$router->get('/supplier/workflow', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    [$owner, $orgId] = _wf_ext_owner_org($db);
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }

    // Filters (all optional, all validated inside task_list).
    $filters = [
        'status'      => $_GET['status']   ?? '',
        'priority'    => $_GET['priority'] ?? '',
        'category'    => $_GET['category'] ?? '',
        'assignee_id' => $_GET['assignee'] ?? '',
        'overdue'     => !empty($_GET['overdue']),
    ];
    $tasks      = function_exists('task_list') ? task_list($db, $orgId, $filters) : [];
    $analytics  = function_exists('task_analytics') ? task_analytics($db, $orgId) : [];
    $templates  = function_exists('task_template_list') ? task_template_list($db, $orgId) : [];
    $recurrences= function_exists('task_recurrence_list') ? task_recurrence_list($db, $orgId) : [];
    $rules      = function_exists('workflow_rule_list') ? workflow_rule_list($db, $orgId) : [];
    $schedStatus= function_exists('workflow_scheduler_status') ? workflow_scheduler_status($db, $orgId) : ['connected' => false, 'message' => ''];
    $eventCatalogue = function_exists('platform_event_catalogue') ? platform_event_catalogue() : [];

    // Property + assignee pickers (owner + active staff + owned properties).
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $propList = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propList[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }
    $staffList = []; // id => label
    try {
        $me = $db->get('users', ['first_name', 'last_name'], ['user_id' => $owner]);
        $staffList[$owner] = trim((string) ($me['first_name'] ?? '') . ' ' . (string) ($me['last_name'] ?? '')) ?: 'Owner';
        foreach ($db->select('supplier_staff', ['staff_user_id'], ['owner_user_id' => $owner, 'status' => 'active']) ?: [] as $s) {
            $sid = (string) $s['staff_user_id'];
            if ($sid === '' || isset($staffList[$sid])) { continue; }
            $u = $db->get('users', ['first_name', 'last_name', 'email'], ['user_id' => $sid]);
            $staffList[$sid] = $u ? (trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? '')) ?: (string) ($u['email'] ?? $sid)) : $sid;
        }
    } catch (\Throwable $e) { error_log('workflow pickers: ' . $e->getMessage()); }

    $canEdit    = supplier_can($db, 'reservations', 'edit');
    $canApprove = supplier_can($db, 'finance', 'edit');
    $title = 'Task & workflow'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/workflow-ext.php";
    require_once views . "includes/footer.php";
});

// =================== TASK ACTIVITY TRAIL (GET JSON) =========================
$router->get('/supplier/workflow/task/([0-9]+)/trail', function ($taskId) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    [$owner, $orgId] = _wf_ext_owner_org($db);
    $taskId = (int) $taskId;
    header('Content-Type: application/json');
    if (!supplier_can($db, 'reservations', 'view')) { echo json_encode(['ok' => false, 'trail' => []]); exit; }
    $info = function_exists('task_owner_org') ? task_owner_org($db, $taskId) : null;
    if ($info === null || (int) $info['org_id'] !== $orgId) { echo json_encode(['ok' => false, 'trail' => []]); exit; }
    $trail = function_exists('task_activity_trail') ? task_activity_trail($db, $taskId) : [];
    echo json_encode(['ok' => true, 'trail' => $trail]); exit;
});

// =================== CREATE TASK (POST) =====================================
$router->post('/supplier/workflow/task', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$owner, $orgId] = _wf_ext_owner_org($db);
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $back = root . 'supplier/workflow';
    $id = function_exists('task_create') ? task_create($db, $orgId, $owner, $_POST, 'manual') : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Task created.' : 'Could not create task (a title is required).'];
    header('Location: ' . $back); exit;
});

// =================== EDIT TASK (POST) =======================================
$router->post('/supplier/workflow/task/edit', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $taskId = (int) ($_POST['id'] ?? 0);
    [$owner, $orgId] = _wf_ext_task_guard($db, $taskId, 'edit');
    $back = root . 'supplier/workflow';
    $ok = function_exists('task_update_fields') && task_update_fields($db, $taskId, $owner, $_POST);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Task updated.' : 'Could not update task (closed tasks are read-only).'];
    header('Location: ' . $back); exit;
});

// =================== SET STATUS (POST) ======================================
$router->post('/supplier/workflow/task/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $taskId = (int) ($_POST['id'] ?? 0);
    [$owner, $orgId] = _wf_ext_task_guard($db, $taskId, 'edit');
    $back = root . 'supplier/workflow';
    $status = (string) ($_POST['status'] ?? '');
    $ok = function_exists('task_set_status') && task_set_status($db, $taskId, $owner, $status);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Status updated.' : 'Could not change status (tasks needing approval must be approved before completion).'];
    header('Location: ' . $back); exit;
});

// =================== COMMENT (POST) =========================================
$router->post('/supplier/workflow/task/comment', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $taskId = (int) ($_POST['id'] ?? 0);
    [$owner, $orgId] = _wf_ext_task_guard($db, $taskId, 'edit');
    $back = root . 'supplier/workflow';
    $body = trim((string) ($_POST['body'] ?? ''));
    $ok = $body !== '' && function_exists('task_activity') && task_activity($db, $taskId, 'comment', $body);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Comment added.' : 'Enter a comment.'];
    header('Location: ' . $back); exit;
});

// =================== APPROVAL DECISION (POST) ===============================
// Approvals require a distinct sign-off permission (finance.edit), not just task edit.
$router->post('/supplier/workflow/task/approval', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $taskId = (int) ($_POST['id'] ?? 0);
    [$owner, $orgId] = _wf_ext_task_guard($db, $taskId, 'edit');
    if (!supplier_can($db, 'finance', 'edit')) { _supplier_stays_deny('You do not have approval authority.'); }
    $back = root . 'supplier/workflow';
    $decision = (string) ($_POST['decision'] ?? '');
    $ok = function_exists('task_set_approval') && task_set_approval($db, $taskId, $owner, $decision, (string) ($_POST['note'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Approval recorded.' : 'Could not record approval (task may not require approval or is already decided).'];
    header('Location: ' . $back); exit;
});

// =================== TEMPLATES ==============================================
$router->post('/supplier/workflow/template', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$owner, $orgId] = _wf_ext_owner_org($db);
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $back = root . 'supplier/workflow';
    $id = function_exists('task_template_save') ? task_template_save($db, $orgId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Template saved.' : 'Could not save template (name + title required).'];
    header('Location: ' . $back); exit;
});

$router->post('/supplier/workflow/template/delete', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$owner, $orgId] = _wf_ext_owner_org($db);
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $back = root . 'supplier/workflow';
    $ok = function_exists('task_template_delete') && task_template_delete($db, $orgId, (int) ($_POST['id'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Template deleted.' : 'Could not delete template.'];
    header('Location: ' . $back); exit;
});

// Spawn a task from a template now.
$router->post('/supplier/workflow/template/spawn', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$owner, $orgId] = _wf_ext_owner_org($db);
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $back = root . 'supplier/workflow';
    $id = function_exists('task_from_template')
        ? task_from_template($db, $orgId, $owner, (int) ($_POST['template_id'] ?? 0), [
            'stay_id'     => (int) ($_POST['stay_id'] ?? 0),
            'assignee_id' => (string) ($_POST['assignee_id'] ?? ''),
          ])
        : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Task created from template.' : 'Could not create task from template.'];
    header('Location: ' . $back); exit;
});

// =================== RECURRENCES ============================================
$router->post('/supplier/workflow/recurrence', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$owner, $orgId] = _wf_ext_owner_org($db);
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $back = root . 'supplier/workflow';
    $id = function_exists('task_recurrence_save') ? task_recurrence_save($db, $orgId, $owner, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Recurring schedule saved.' : 'Could not save schedule (pick a template and a cadence).'];
    header('Location: ' . $back); exit;
});

$router->post('/supplier/workflow/recurrence/toggle', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$owner, $orgId] = _wf_ext_owner_org($db);
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $back = root . 'supplier/workflow';
    $ok = function_exists('task_recurrence_set_active')
        && task_recurrence_set_active($db, $orgId, (int) ($_POST['id'] ?? 0), !empty($_POST['active']));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Schedule updated.' : 'Could not update schedule.'];
    header('Location: ' . $back); exit;
});

// =================== AUTOMATION RULES =======================================
$router->post('/supplier/workflow/rule', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$owner, $orgId] = _wf_ext_owner_org($db);
    // Authoring automations is a privileged action — require edit on BOTH ops + finance
    // scopes (so a limited-scope staffer can't wire rules that create approval tasks).
    if (!supplier_can($db, 'reservations', 'edit') || !supplier_can($db, 'finance', 'edit')) {
        _supplier_stays_deny('Authoring automations requires operations + finance edit rights.');
    }
    $back = root . 'supplier/workflow';
    $id = function_exists('workflow_rule_save') ? workflow_rule_save($db, $orgId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Automation rule saved.' : 'Could not save rule (name + a valid event like "payment.received" required).'];
    header('Location: ' . $back); exit;
});

$router->post('/supplier/workflow/rule/toggle', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$owner, $orgId] = _wf_ext_owner_org($db);
    if (!supplier_can($db, 'reservations', 'edit') || !supplier_can($db, 'finance', 'edit')) {
        _supplier_stays_deny('Not authorised.');
    }
    $back = root . 'supplier/workflow';
    $ok = function_exists('workflow_rule_set_active')
        && workflow_rule_set_active($db, $orgId, (int) ($_POST['id'] ?? 0), !empty($_POST['active']));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Rule updated.' : 'Could not update rule.'];
    header('Location: ' . $back); exit;
});

// =================== MANUAL TICK (POST) — run recurrences + rules now ========
// DB-GATED: the proper driver is a cron hitting /crons/workflow-tick. This button lets an
// owner run one pass by hand (bounded by the same idempotent cursor). Org-scoped.
$router->post('/supplier/workflow/tick', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$owner, $orgId] = _wf_ext_owner_org($db);
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }
    $back = root . 'supplier/workflow';
    $res = function_exists('workflow_tick') ? workflow_tick($db, $orgId) : ['recurring_tasks_created' => 0, 'rule_actions_fired' => 0];
    $_SESSION['message'] = ['type' => 'success', 'text' => 'Ran one automation pass — ' . (int) $res['recurring_tasks_created'] . ' recurring task(s), ' . (int) $res['rule_actions_fired'] . ' rule action(s).'];
    header('Location: ' . $back); exit;
});

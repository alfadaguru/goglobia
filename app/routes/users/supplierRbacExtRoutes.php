<?php
// FILE: app/routes/users/supplierRbacExtRoutes.php
// MODULE 45 — Users, roles & permissions (DEEPEN) routes.
//
// Deepens the shipped RBAC spine (supplierRolesRoutes / supplierStaffRoutes) with the
// four genuine gaps built in app/lib/supplier_rbac_ext.php:
//   • departments (taxonomy + assigning staff to a department),
//   • sensitive-action step-up re-auth (password confirm, short TTL),
//   • an owner-facing STAFF AUDIT LOG read screen,
//   • an approval ESCALATION queue (record + decide; moves no money).
//
// Security model (house rules): every handler gates with SUPPLIER_OR_STAFF_AUTH($db)
// (area entry) then supplier_can($db,'staff',<action>) (the staff-admin permission —
// owners always pass; staff pass only if their role grants it). Every POST adds
// CSRF::guard(). Every org-scoped read/write is bound to the ACTING owner so one
// supplier can never see or touch another's roles/departments/audit/approvals.

@$SECURE or die('Access Denied!');

if (!function_exists('_supplier_rbac_deny')) {
    function _supplier_rbac_deny(string $to = 'supplier/rbac', string $msg = 'You are not authorised to do that.'): void
    {
        $_SESSION['message'] = ['type' => 'error', 'text' => $msg];
        header('Location: ' . root . $to);
        exit;
    }
}

if (!function_exists('_supplier_rbac_redirect')) {
    function _supplier_rbac_redirect(string $type, string $text, string $to = 'supplier/rbac'): void
    {
        $_SESSION['message'] = ['type' => $type, 'text' => $text];
        header('Location: ' . root . $to);
        exit;
    }
}

// ============================================================================
// DASHBOARD — GET /supplier/rbac
// Departments, step-up status, approval queue, and recent staff audit in one view.
// ============================================================================
$router->get('/supplier/rbac', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    supplier_rbac_ext_ensure_schema($db);
    $owner = _supplier_rbac_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'staff', 'view')) { _supplier_rbac_deny('supplier/dashboard'); }

    $tab = $_GET['tab'] ?? 'departments';
    $tab = in_array($tab, ['departments', 'approvals', 'audit', 'security'], true) ? $tab : 'departments';

    $departments = supplier_departments_for_owner($db, $owner);
    $deptSeeds   = supplier_department_seeds();

    // Staff + role names for the department-assignment table.
    $staff = $db->select('supplier_staff',
        ['id', 'invited_email', 'staff_user_id', 'role_id', 'department_id', 'status'],
        ['owner_user_id' => $owner, 'ORDER' => ['id' => 'DESC']]) ?: [];
    $roleNames = [];
    foreach ($db->select('supplier_roles', ['id', 'name'], ['owner_user_id' => $owner]) ?: [] as $r) {
        $roleNames[(int) $r['id']] = $r['name'] ?? '';
    }
    $deptNames = [0 => '—'];
    foreach ($departments as $d) { $deptNames[(int) $d['id']] = $d['name'] ?? ''; }

    $approvals     = supplier_approval_requests_for_owner($db, $owner, null, 100);
    $approvalKeys  = function_exists('supplier_approval_limit_keys') ? supplier_approval_limit_keys() : [];
    $auditRows     = supplier_audit_feed($db, $owner, ['limit' => 150, 'event' => $_GET['event'] ?? '']);
    $reauthMethods = supplier_reauth_methods();
    $reauthOk      = supplier_reauth_ok();

    $title = 'Access control';
    require_once views . "includes/header.php";
    require_once views . "supplier/rbac-ext.php";
    require_once views . "includes/footer.php";
});

// ============================================================================
// DEPARTMENTS — POST /supplier/rbac/departments/save
// ============================================================================
$router->post('/supplier/rbac/departments/save', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    supplier_rbac_ext_ensure_schema($db);
    $owner = _supplier_rbac_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $id = (int) ($_POST['id'] ?? 0);
    if (!supplier_can($db, 'staff', $id ? 'edit' : 'add')) {
        _supplier_rbac_deny('supplier/rbac?tab=departments');
    }
    $name   = trim($_POST['name'] ?? '');
    $code   = trim($_POST['code'] ?? '');
    $active = !empty($_POST['active']);
    if ($name === '') { _supplier_rbac_redirect('error', 'Department name is required.', 'supplier/rbac?tab=departments'); }

    $newId = supplier_department_save($db, $owner, $id, $name, $code, $active);
    if ($newId <= 0) { _supplier_rbac_redirect('error', 'Could not save department.', 'supplier/rbac?tab=departments'); }
    supplier_audit_stamp($db, $owner, $id ? 'department.updated' : 'department.created', 'department', $newId, ['name' => $name]);
    _supplier_rbac_redirect('success', 'Department saved.', 'supplier/rbac?tab=departments');
});

// ============================================================================
// DEPARTMENTS — POST /supplier/rbac/staff/department  (assign a staff member)
// ============================================================================
$router->post('/supplier/rbac/staff/department', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    supplier_rbac_ext_ensure_schema($db);
    $owner = _supplier_rbac_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'staff', 'edit')) { _supplier_rbac_deny('supplier/rbac?tab=departments'); }

    $staffId = (int) ($_POST['staff_id'] ?? 0);
    $deptId  = (int) ($_POST['department_id'] ?? 0);
    if (!supplier_staff_set_department($db, $owner, $staffId, $deptId)) {
        _supplier_rbac_redirect('error', 'Could not assign department.', 'supplier/rbac?tab=departments');
    }
    supplier_audit_stamp($db, $owner, 'staff.department_set', 'supplier_staff', $staffId, ['department_id' => $deptId]);
    _supplier_rbac_redirect('success', 'Department assignment updated.', 'supplier/rbac?tab=departments');
});

// ============================================================================
// STEP-UP RE-AUTH — POST /supplier/rbac/reauth  (confirm password → stamp window)
// A sensitive handler elsewhere calls supplier_reauth_ok(); this endpoint is where
// the actor proves their password. Only a password step-up is live (local verify);
// stronger factors are DB-GATED (supplier_reauth_methods()).
// ============================================================================
$router->post('/supplier/rbac/reauth', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    supplier_rbac_ext_ensure_schema($db);
    $owner = _supplier_rbac_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    // No supplier_can gate: re-auth is a self-identity proof, available to any staff.

    $pw = (string) ($_POST['password'] ?? '');
    $to = trim($_POST['return'] ?? '');
    // Only allow same-site relative returns (open-redirect guard).
    $dest = (strlen($to) > 0 && $to[0] === '/' && strpos($to, '//') !== 0)
        ? ltrim($to, '/') : 'supplier/rbac?tab=security';

    if (supplier_reauth_confirm($db, $pw)) {
        supplier_audit_stamp($db, $owner, 'reauth.confirmed', 'user', $_SESSION['user_id'] ?? null, []);
        _supplier_rbac_redirect('success', 'Identity confirmed — you can continue for the next few minutes.', $dest);
    }
    supplier_audit_stamp($db, $owner, 'reauth.failed', 'user', $_SESSION['user_id'] ?? null, []);
    _supplier_rbac_redirect('error', 'Password did not match.', 'supplier/rbac?tab=security');
});

// ============================================================================
// APPROVAL QUEUE — POST /supplier/rbac/approvals/request
// Honest escalation: staff ask an approver to approve a value their role cannot.
// Moves NO money. Only records a request when the actor genuinely CANNOT self-approve.
// ============================================================================
$router->post('/supplier/rbac/approvals/request', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    supplier_rbac_ext_ensure_schema($db);
    $owner = _supplier_rbac_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    // Any staff in the area may REQUEST an escalation; deciding is gated below.

    $key    = trim($_POST['limit_key'] ?? '');
    $amount = (float) ($_POST['amount'] ?? 0);
    $stayId = ($_POST['stay_id'] ?? '') !== '' ? (int) $_POST['stay_id'] : null;
    $reason = trim($_POST['reason'] ?? '');
    $keys   = function_exists('supplier_approval_limit_keys') ? supplier_approval_limit_keys() : [];
    if ($key === '' || !isset($keys[$key])) {
        _supplier_rbac_redirect('error', 'Choose a valid approval type.', 'supplier/rbac?tab=approvals');
    }
    if ($amount <= 0) { _supplier_rbac_redirect('error', 'Enter an amount greater than zero.', 'supplier/rbac?tab=approvals'); }

    // If the requester can already self-approve, no escalation is needed.
    if (function_exists('supplier_can_approve') && supplier_can_approve($db, $key, $amount, $stayId)) {
        _supplier_rbac_redirect('success', 'Your role already permits this — no escalation needed.', 'supplier/rbac?tab=approvals');
    }
    $id = supplier_approval_request_create($db, $owner, $key, $amount, $stayId, 'manual', null, $reason);
    if ($id <= 0) { _supplier_rbac_redirect('error', 'Could not file the request.', 'supplier/rbac?tab=approvals'); }
    _supplier_rbac_redirect('success', 'Escalation filed for approval.', 'supplier/rbac?tab=approvals');
});

// ============================================================================
// APPROVAL QUEUE — POST /supplier/rbac/approvals/decide
// ============================================================================
$router->post('/supplier/rbac/approvals/decide', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    supplier_rbac_ext_ensure_schema($db);
    $owner = _supplier_rbac_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    // Deciding is a staff-admin action AND requires the decider hold the limit
    // (re-checked inside supplier_approval_request_decide via supplier_can_approve).
    if (!supplier_can($db, 'staff', 'edit')) { _supplier_rbac_deny('supplier/rbac?tab=approvals'); }

    $id       = (int) ($_POST['id'] ?? 0);
    $decision = trim($_POST['decision'] ?? '');
    $note     = trim($_POST['note'] ?? '');
    $res = supplier_approval_request_decide($db, $owner, $id, $decision, $note);
    if (!$res['ok']) {
        _supplier_rbac_redirect('error', $res['error'] ?? 'Could not record decision.', 'supplier/rbac?tab=approvals');
    }
    _supplier_rbac_redirect('success', 'Decision recorded.', 'supplier/rbac?tab=approvals');
});

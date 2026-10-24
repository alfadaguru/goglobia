<?php
// app/routes/admin/supportStaffRoutes.php
// Employee RBAC consolidation — EMPLOYEES admin (S5, folded into S3). An employee
// IS a users row with role='employee' + a role_id (users_roles) + optional
// shift_group / nudge_phone. There is no separate staff table anymore.
// Design-of-record: docs/support/EMPLOYEE_RBAC_CONSOLIDATION.md.
//
// Every handler is ADMIN_AUTH() + admin_can($db,'employees','edit'), CSRF on POST.
// "Remove" demotes the user back to a customer (role/role_id cleared) — it never
// deletes the underlying users row.
@$SECURE or die('Access Denie'.'d!');

// ================================ LIST (employees = users.role in admin/employee)
$router->get(admin.'/support/staff', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    ensureAdminRbacSchema($db);
    if (!admin_can($db, 'employees', 'edit')) { http_response_code(403); die('Access Denied!'); }
    $title = 'Employees'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/staff.php";
    require_once views."includes/footer.php";
});

// ================================ CREATE (form)
$router->get(admin.'/support/staff/create', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    ensureAdminRbacSchema($db);
    if (!admin_can($db, 'employees', 'edit')) { http_response_code(403); die('Access Denied!'); }
    $title = 'Add Employee'; $description = ''; $header = true; $footer = true;
    $_GET['uid'] = '';
    require_once views."includes/header.php";
    require_once "app/views/admin/support/staff-manage.php";
    require_once views."includes/footer.php";
});

// ================================ EDIT (form) — keyed on users.user_id
$router->get(admin.'/support/staff/edit/([^/]+)', function ($uid) use ($SECURE, $db) {
    ADMIN_AUTH();
    ensureAdminRbacSchema($db);
    if (!admin_can($db, 'employees', 'edit')) { http_response_code(403); die('Access Denied!'); }
    $title = 'Edit Employee'; $description = ''; $header = true; $footer = true;
    $_GET['uid'] = (string) $uid;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/staff-manage.php";
    require_once views."includes/footer.php";
});

// ================================ SAVE (promote/update an employee)
$router->post(admin.'/support/staff/save', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::verifyRequest();
    ensureAdminRbacSchema($db);
    if (!admin_can($db, 'employees', 'edit')) { http_response_code(403); die('Access Denied!'); }

    $userId  = trim((string) ($_POST['user_id'] ?? ''));
    $roleId  = (int) ($_POST['role_id'] ?? 0);
    $shift   = trim((string) ($_POST['shift_group'] ?? ''));
    $nudge   = trim((string) ($_POST['nudge_phone'] ?? ''));

    try {
        // The target user is mandatory and must be a real users row.
        if ($userId === '') { throw new Exception('Select a user to make an employee.'); }
        $user = $db->get('users', ['user_id', 'role'], ['user_id' => $userId]);
        if (!$user) { throw new Exception('That user could not be found.'); }

        // Role is required — an employee with no role has no access and would be
        // bounced at the admin gate.
        if ($roleId <= 0 || !$db->count('users_roles', ['id' => $roleId])) {
            throw new Exception('Pick a role for this employee.');
        }

        // Never demote an existing admin via this screen (would strip their own
        // access); admins are managed in Users. Promote everyone else to employee.
        $newRole = (($user['role'] ?? '') === 'admin') ? 'admin' : 'employee';

        $db->update('users', [
            'role'        => $newRole,
            'role_id'     => $roleId,
            'shift_group' => $shift !== '' ? $shift : null,
            'nudge_phone' => $nudge !== '' ? $nudge : null,
        ], ['user_id' => $userId]);

        $_SESSION['message'] = ['type' => 'success', 'text' => 'Employee saved.'];
        redirect(root . admin . '/support/staff');
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/support/staff');
    }
});

// ================================ REMOVE (demote to customer — keeps the user)
$router->post(admin.'/support/staff/delete', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::verifyRequest();
    ensureAdminRbacSchema($db);
    if (!admin_can($db, 'employees', 'edit')) { http_response_code(403); die('Access Denied!'); }

    $userId = trim((string) ($_POST['user_id'] ?? ''));
    try {
        if ($userId === '') { throw new Exception('Invalid employee.'); }
        $user = $db->get('users', ['user_id', 'role'], ['user_id' => $userId]);
        if (!$user) { throw new Exception('That user could not be found.'); }
        // Never demote an admin from here.
        if (($user['role'] ?? '') === 'admin') {
            throw new Exception('Admins cannot be removed here — manage them in Users.');
        }
        // Demote: back to a customer, clear the role link + support fields. The
        // users row itself is untouched otherwise.
        $db->update('users', [
            'role'        => 'customer',
            'role_id'     => null,
            'shift_group' => null,
            'nudge_phone' => null,
        ], ['user_id' => $userId]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Employee removed (demoted to customer).'];
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
    }
    redirect(root . admin . '/support/staff');
});

<?php
// app/routes/admin/employeesRoutes.php
// ============================================================================
// EMPLOYEES — the ONE dedicated section for GoGlobia's OWN internal staff.
// (NOT travel-agency staff, NOT supplier staff — those are separate B2B tenants.)
//
// An employee IS a `users` row with role='employee' + a role_id (users_roles)
// + optional shift_group / nudge_phone. There is no separate staff table.
// Design-of-record: docs/support/EMPLOYEE_RBAC_CONSOLIDATION.md + the IA
// refactor that moved staff/roles/attendance/shifts/targets/activity OUT of the
// Support module into this single Employees section.
//
// All backend data + engine functions are REUSED unchanged:
//   - RBAC:        app/lib/admin_rbac.php (admin_can / admin_require / catalogue)
//   - attendance:  app/lib/support/attendance.php (support_clock_in/out, etc.)
//   - shifts:      app/lib/support/shifts.php
//   - targets:     app/lib/support/targets.php
// This file is only the HTTP + view layer, now under /admin/employees/*.
// Every handler: ADMIN_AUTH() + a granular admin_can/admin_require gate; CSRF on POST.
@$SECURE or die('Access Denie'.'d!');

// ================================ EMPLOYEES DASHBOARD (section home)
$router->get(admin.'/employees', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureAdminRbacSchema')) { ensureAdminRbacSchema($db); }
    // Any staffer who can see employees OR roles OR attendance lands here; the
    // view hides tiles the current role can't access (admin_can per tile).
    $title = 'Employees'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/employees/home.php";
    require_once views."includes/footer.php";
});

// ================================ ACTIVITY LOG
$router->get(admin.'/employees/activity', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'employees', 'view');
    if (function_exists('ensureAdminActivitySchema')) { ensureAdminActivitySchema($db); }

    // Optional per-employee filter + simple pagination.
    $filterUser = trim((string) ($_GET['user_id'] ?? ''));
    $page  = max(1, (int) ($_GET['page'] ?? 1));
    $per   = 50;
    $where = [];
    if ($filterUser !== '') { $where['user_id'] = $filterUser; }

    $countWhere = $where;
    $total = 0;
    $rows  = [];
    try {
        $total = (int) $db->count('admin_activity_log', $countWhere ?: '*');
        $sel = $where;
        $sel['ORDER'] = ['id' => 'DESC'];
        $sel['LIMIT'] = [($page - 1) * $per, $per];
        $rows = $db->select('admin_activity_log', '*', $sel) ?: [];
    } catch (\Throwable $e) {
        error_log('[employees] activity log read: ' . $e->getMessage());
    }

    // Employee dropdown for the filter (users with role admin/employee).
    $employees = [];
    try {
        $employees = $db->select('users', ['user_id', 'first_name', 'last_name'],
            ['role' => ['admin', 'employee'], 'ORDER' => ['first_name' => 'ASC']]) ?: [];
    } catch (\Throwable $e) {
        error_log('[employees] activity employee list: ' . $e->getMessage());
    }

    $pages = (int) max(1, ceil($total / $per));

    $title = 'Employee Activity'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/employees/activity.php";
    require_once views."includes/footer.php";
});

// ===================== STAFF (employees list + add/edit/remove) (moved from app/routes/admin/supportStaffRoutes.php) =====================

// ================================ LIST (employees = users.role in admin/employee)
$router->get(admin . '/employees/staff', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    ensureAdminRbacSchema($db);
    if (!admin_can($db, 'employees', 'edit')) { http_response_code(403); die('Access Denied!'); }
    $title = 'Employees'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/employees/staff.php";
    require_once views."includes/footer.php";
});

// ================================ CREATE (form)
$router->get(admin . '/employees/staff/create', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    ensureAdminRbacSchema($db);
    if (!admin_can($db, 'employees', 'edit')) { http_response_code(403); die('Access Denied!'); }
    $title = 'Add Employee'; $description = ''; $header = true; $footer = true;
    $_GET['uid'] = '';
    require_once views."includes/header.php";
    require_once "app/views/admin/employees/staff-manage.php";
    require_once views."includes/footer.php";
});

// ================================ EDIT (form) — keyed on users.user_id
$router->get(admin . '/employees/staff/edit/([^/]+)', function ($uid) use ($SECURE, $db) {
    ADMIN_AUTH();
    ensureAdminRbacSchema($db);
    if (!admin_can($db, 'employees', 'edit')) { http_response_code(403); die('Access Denied!'); }
    $title = 'Edit Employee'; $description = ''; $header = true; $footer = true;
    $_GET['uid'] = (string) $uid;
    require_once views."includes/header.php";
    require_once "app/views/admin/employees/staff-manage.php";
    require_once views."includes/footer.php";
});

// ================================ SAVE (promote/update an employee)
$router->post(admin . '/employees/staff/save', function () use ($SECURE, $db) {
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
        redirect(root . admin . '/employees/staff');
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/employees/staff');
    }
});

// ================================ REMOVE (demote to customer — keeps the user)
$router->post(admin . '/employees/staff/delete', function () use ($SECURE, $db) {
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
    redirect(root . admin . '/employees/staff');
});

// ===================== ROLES (granular company RBAC on users_roles) (moved from app/routes/admin/supportRolesRoutes.php) =====================

// --- shared gate for the roles screens --------------------------------------
// ADMIN_AUTH() first (admin/employee session), then the granular
// users_roles.edit permission. A full admin is all-allow (admin_can).
$supportRolesGate = function ($db) {
    ADMIN_AUTH();
    ensureAdminRbacSchema($db);
    if (!admin_can($db, 'users_roles', 'edit')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'You do not have permission to manage roles.'];
        redirect(root . admin . '/employees');
        exit;
    }
};

// ================================ LIST
$router->get(admin . '/employees/roles', function () use ($SECURE, $db, $supportRolesGate) {
    $supportRolesGate($db);
    $title = 'Roles'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/employees/roles.php";
    require_once views."includes/footer.php";
});

// ================================ CREATE (form)
$router->get(admin . '/employees/roles/create', function () use ($SECURE, $db, $supportRolesGate) {
    $supportRolesGate($db);
    $title = 'New Role'; $description = ''; $header = true; $footer = true;
    $_GET['id'] = 0;
    require_once views."includes/header.php";
    require_once "app/views/admin/employees/roles-manage.php";
    require_once views."includes/footer.php";
});

// ================================ EDIT (form)
$router->get(admin . '/employees/roles/edit/([0-9]+)', function ($id) use ($SECURE, $db, $supportRolesGate) {
    $supportRolesGate($db);
    $title = 'Edit Role'; $description = ''; $header = true; $footer = true;
    $_GET['id'] = (int) $id;
    require_once views."includes/header.php";
    require_once "app/views/admin/employees/roles-manage.php";
    require_once views."includes/footer.php";
});

// ================================ SAVE (create/update)
$router->post(admin . '/employees/roles/save', function () use ($SECURE, $db, $supportRolesGate) {
    $supportRolesGate($db);
    CSRF::verifyRequest();

    $id   = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));

    if ($name === '') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Role name is required.'];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/employees/roles');
        return;
    }

    // Build the permissions blob from the posted area x action grid, whitelisted
    // against the authoritative catalogue so a client can't inject arbitrary
    // area/action keys. A granted action is stored as '' (presence = granted).
    $catalogue = admin_area_catalogue();
    $postedPerms = (array) ($_POST['permissions'] ?? []);
    $perms = [];
    foreach ($catalogue as $area => $actions) {
        $postedArea = isset($postedPerms[$area]) && is_array($postedPerms[$area]) ? $postedPerms[$area] : [];
        foreach ($actions as $action) {
            if (isset($postedArea[$action])) {
                $perms[$area][$action] = '';
            }
        }
    }

    $now = date('Y-m-d H:i:s');
    try {
        if ($id > 0) {
            $existing = $db->get('users_roles', '*', ['id' => $id]);
            if (!$existing) { throw new Exception('Role not found.'); }

            // The Admin role (base_type/type_name 'admin') is always-all — its
            // perms are force-repaired at boot, so never let this form shrink it.
            $isAdminRole = (strtolower((string) ($existing['base_type'] ?? '')) === 'admin')
                || (strtolower((string) ($existing['type_name'] ?? '')) === 'admin');
            if ($isAdminRole) {
                throw new Exception('The Admin role is all-access and cannot be edited.');
            }

            $db->update('users_roles', [
                'type_name'   => $name,
                'permissions' => json_encode($perms),
                'updated_at'  => $now,
            ], ['id' => $id]);
            $msg = 'Role updated.';
        } else {
            // New custom role: slug derived from the name (unique). kind=custom.
            $slug = function_exists('slugify') ? slugify($name) : strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));
            $slug = trim((string) $slug, '_-');
            if ($slug === '') { $slug = 'role_' . substr(md5($name . microtime()), 0, 8); }
            $base = $slug; $n = 2;
            while ((int) $db->count('users_roles', ['slug' => $slug]) > 0) {
                $slug = $base . '_' . $n; $n++;
            }
            $db->insert('users_roles', [
                'type_name'   => $name,
                'slug'        => $slug,
                'base_type'   => 'employee',
                'kind'        => 'custom',
                'status'      => 1,
                'permissions' => json_encode($perms),
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            $id  = (int) $db->id();
            $msg = 'Role created.';
        }
        $_SESSION['message'] = ['type' => 'success', 'text' => $msg];
        redirect(root . admin . '/employees/roles/edit/' . $id);
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/employees/roles');
    }
});

// ================================ DELETE (custom roles only)
$router->post(admin . '/employees/roles/delete', function () use ($SECURE, $db, $supportRolesGate) {
    $supportRolesGate($db);
    CSRF::verifyRequest();

    $id = (int) ($_POST['id'] ?? 0);
    try {
        if ($id <= 0) { throw new Exception('Invalid role.'); }
        $role = $db->get('users_roles', '*', ['id' => $id]);
        if (!$role) { throw new Exception('Role not found.'); }

        // System roles (kind='system', incl. the legacy seed rows 1-5) and the
        // Admin role can never be deleted.
        $isSystem = (strtolower((string) ($role['kind'] ?? '')) === 'system') || ((int) $id <= 5);
        if ($isSystem) {
            throw new Exception('System roles cannot be deleted.');
        }
        // A role still assigned to any employee cannot be deleted — reassign first.
        $inUse = (int) $db->count('users', ['role_id' => $id]);
        if ($inUse > 0) {
            throw new Exception('This role is assigned to ' . $inUse . ' employee' . ($inUse === 1 ? '' : 's') . '. Reassign them before deleting.');
        }
        $db->delete('users_roles', ['id' => $id]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Role deleted.'];
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
    }
    redirect(root . admin . '/employees/roles');
});

// ===================== ATTENDANCE + clock + heartbeat (moved from app/routes/admin/supportAttendanceRoutes.php) =====================

// ================================ ATTENDANCE PAGE (header/page/footer triple)
// cap attendance.view_all shows the whole team; a staffer who only has
// attendance.view_self sees just their own rows (scoped below in the view).
$router->get(admin . '/employees/attendance', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    require_once dirname(__DIR__, 2) . '/lib/support/attendance.php';
    ensureSupportAttendanceSchema($db);

    $viewAll  = admin_can($db, 'support_attendance', 'view_all');
    $viewSelf = admin_can($db, 'support_attendance', 'view');
    if (!$viewAll && !$viewSelf) { http_response_code(403); die('Access Denied!'); }

    // Staff key is now users.user_id (VARCHAR) — see admin_staff_id() (S3).
    $staffId = admin_staff_id($db);

    // Date window for the per-staff daily table. Default = today only.
    $from = (string) ($_GET['from'] ?? date('Y-m-d'));
    $to   = (string) ($_GET['to']   ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-m-d'); }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to   = date('Y-m-d'); }

    // "Who is online now" — team board. A self-only staffer does not get the
    // team roster; they only ever see themselves.
    $online = $viewAll ? support_who_online($db) : [];

    // Build the per-staff attendance report for the window. Staff are now the
    // employees themselves (users with role admin/employee + an active role);
    // the staff key is users.user_id. view_all → every employee; view_self →
    // only the current user.
    $reportStaff = [];
    try {
        if ($viewAll) {
            $rows = $db->query(
                "SELECT u.user_id AS id,
                        NULL AS display_name,
                        u.first_name AS first_name,
                        u.last_name  AS last_name
                 FROM `users` u
                 WHERE u.role IN ('admin','employee') AND u.status = 'active'
                 ORDER BY u.first_name ASC, u.last_name ASC"
            )->fetchAll(\PDO::FETCH_ASSOC);
            if (is_array($rows)) { $reportStaff = $rows; }
        } elseif ($staffId !== '') {
            // Self-scope: resolve the current user's display identity.
            $me = $db->query(
                "SELECT u.user_id AS id,
                        NULL AS display_name,
                        u.first_name AS first_name,
                        u.last_name  AS last_name
                 FROM `users` u
                 WHERE u.user_id = :id",
                [':id' => $staffId]
            )->fetch(\PDO::FETCH_ASSOC);
            if ($me) { $reportStaff = [$me]; }
            else {
                $reportStaff = [[
                    'id'           => $staffId,
                    'display_name' => null,
                    'first_name'   => null,
                    'last_name'    => null,
                ]];
            }
        }
    } catch (\Throwable $e) {
        error_log('[support] attendance report staff list: ' . $e->getMessage());
    }

    // For each staffer in scope, compute their daily rows across the window and a
    // human display name (display_name → users name → "Staff #id").
    $report = [];
    foreach ($reportStaff as $rs) {
        $sid  = (string) $rs['id'];
        $name = trim((string) ($rs['display_name'] ?? ''));
        if ($name === '') {
            $name = trim(((string) ($rs['first_name'] ?? '')) . ' ' . ((string) ($rs['last_name'] ?? '')));
        }
        if ($name === '') { $name = 'Staff ' . $sid; }

        $days = support_attendance_range($db, $sid, $from, $to);

        $totalSeconds = 0;
        foreach ($days as $d) { $totalSeconds += (int) ($d['worked_seconds'] ?? 0); }

        $report[] = [
            'staff_id'      => $sid,
            'display_name'  => $name,
            'is_me'         => ($sid === $staffId),
            'days'          => $days,
            'total_seconds' => $totalSeconds,
        ];
    }

    // Current user's live clock state for the clock-in/out buttons.
    $myDay      = $staffId !== '' ? support_attendance_day($db, $staffId, date('Y-m-d')) : null;
    $amClockedIn = (bool) ($myDay['open'] ?? false);

    $title = 'Attendance'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/employees/attendance.php";
    require_once views."includes/footer.php";
});

// ================================ CLOCK (JSON POST) — mode in {in,out}.
// Any support staffer may clock THEMSELVES in/out. CSRF::guard() for JSON.
$router->post(admin . '/employees/clock', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (class_exists('CSRF')) { CSRF::guard(); }
    require_once dirname(__DIR__, 2) . '/lib/support/attendance.php';
    ensureSupportAttendanceSchema($db);
    header('Content-Type: application/json');

    if (!admin_is_staff($db)) { http_response_code(403); echo json_encode(['ok' => false, 'message' => 'Forbidden']); return; }

    $staffId = admin_staff_id($db);
    if ($staffId === '') { echo json_encode(['ok' => false, 'message' => 'No staff identity.']); return; }

    $mode = (string) ($_POST['mode'] ?? '');
    if ($mode === 'in') {
        $res = support_clock_in($db, $staffId);
    } elseif ($mode === 'out') {
        $res = support_clock_out($db, $staffId);
    } else {
        echo json_encode(['ok' => false, 'message' => 'Invalid mode.']); return;
    }

    echo json_encode($res);
});

// ================================ HEARTBEAT (JSON POST) — presence beat.
// Any support staffer; posted every ~45s by the open support tab while visible.
// Presence ONLY — never accrues worked time (clock-in/out owns that).
$router->post(admin . '/employees/heartbeat', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (class_exists('CSRF')) { CSRF::guard(); }
    require_once dirname(__DIR__, 2) . '/lib/support/attendance.php';
    ensureSupportAttendanceSchema($db);
    header('Content-Type: application/json');

    if (!admin_is_staff($db)) { http_response_code(403); echo json_encode(['ok' => false, 'message' => 'Forbidden']); return; }

    $staffId = admin_staff_id($db);
    if ($staffId === '') { echo json_encode(['ok' => false, 'message' => 'No staff identity.']); return; }

    $sessionId = (string) ($_POST['session_id'] ?? '');

    $res = support_heartbeat($db, $staffId, $sessionId, 'support');
    echo json_encode($res);
});

// ===================== SHIFTS roster (moved from app/routes/admin/supportShiftsRoutes.php) =====================

require_once __DIR__ . '/../../lib/support/shifts.php'; // roster + upsert + schema

// ================================ ROSTER (list for a date)
// GET ?date=YYYY-MM-DD (default today). support_shifts_for() returns each shift
// on that day joined to staff/users with clocked-in + nudge bookkeeping.
$router->get(admin . '/employees/shifts', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    ensureSupportAttendanceSchema($db);
    if (!admin_can($db, 'support_shifts', 'view')) { http_response_code(403); die('Access Denied!'); }
    $title = 'Shift Roster'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/employees/shifts.php";
    require_once views."includes/footer.php";
});

// ================================ CREATE (form)
$router->get(admin . '/employees/shifts/create', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    ensureSupportAttendanceSchema($db);
    if (!admin_can($db, 'support_shifts', 'edit')) { http_response_code(403); die('Access Denied!'); }
    $title = 'Assign Shift'; $description = ''; $header = true; $footer = true;
    $_GET['id'] = 0;
    require_once views."includes/header.php";
    require_once "app/views/admin/employees/shifts-manage.php";
    require_once views."includes/footer.php";
});

// ================================ EDIT (form)
$router->get(admin . '/employees/shifts/edit/([0-9]+)', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    ensureSupportAttendanceSchema($db);
    if (!admin_can($db, 'support_shifts', 'edit')) { http_response_code(403); die('Access Denied!'); }
    $title = 'Edit Shift'; $description = ''; $header = true; $footer = true;
    $_GET['id'] = (int) $id;
    require_once views."includes/header.php";
    require_once "app/views/admin/employees/shifts-manage.php";
    require_once views."includes/footer.php";
});

// ================================ SAVE (upsert — one shift per staff+date)
$router->post(admin . '/employees/shifts/save', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::verifyRequest();
    ensureSupportAttendanceSchema($db);
    if (!admin_can($db, 'support_shifts', 'edit')) { http_response_code(403); die('Access Denied!'); }

    $staffId = trim((string) ($_POST['staff_id'] ?? '')); // = users.user_id (S3)
    $date    = trim((string) ($_POST['shift_date'] ?? ''));
    $start   = trim((string) ($_POST['start_time'] ?? ''));
    $end     = trim((string) ($_POST['end_time'] ?? ''));

    try {
        // The staff member must be a real employee (users row, role admin/employee).
        if ($staffId === '' || !$db->count('users', ['user_id' => $staffId, 'role' => ['admin', 'employee']])) {
            throw new Exception('Pick a staff member for this shift.');
        }

        // support_shift_upsert validates the date + normalises the times and
        // keys one shift row per (staff, date).
        $res = support_shift_upsert($db, $staffId, $date, $start, $end);
        if (empty($res['ok'])) {
            throw new Exception($res['message'] ?? 'Could not save the shift.');
        }

        $_SESSION['message'] = ['type' => 'success', 'text' => 'Shift saved.'];
        redirect(root . admin . '/employees/shifts?date=' . urlencode(date('Y-m-d', strtotime($date))));
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/employees/shifts');
    }
});

// ================================ DELETE (removes a single shift row)
$router->post(admin . '/employees/shifts/delete', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::verifyRequest();
    ensureSupportAttendanceSchema($db);
    if (!admin_can($db, 'support_shifts', 'edit')) { http_response_code(403); die('Access Denied!'); }

    $id   = (int) ($_POST['id'] ?? 0);
    $date = trim((string) ($_POST['date'] ?? ''));
    try {
        if ($id > 0) {
            $db->delete('support_shifts', ['id' => $id]);
        }
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Shift removed.'];
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
    }
    $back = root . admin . '/employees/shifts';
    if ($date !== '' && strtotime($date) !== false) {
        $back .= '?date=' . urlencode(date('Y-m-d', strtotime($date)));
    }
    redirect($back);
});

// ===================== TARGETS / performance (moved from app/routes/admin/supportTargetsRoutes.php) =====================

require_once __DIR__ . '/../../lib/support/targets.php';
// RBAC (admin_can) lives in app/lib/admin_rbac.php, loaded globally in config.php.

// Products a target can scope to ('' / 'any' = all services). One source of truth
// for the form options AND the save-time whitelist (no drift).
if (!function_exists('support_target_products')) {
    function support_target_products(): array
    {
        return ['flights', 'stays', 'umrah', 'tours', 'cars', 'visa', 'esim', 'ferries', 'rail', 'bus'];
    }
}

// ================================ LIST
$router->get(admin . '/employees/targets', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureSupportTargetSchema')) { ensureSupportTargetSchema($db); }
    if (!admin_can($db, 'support_targets', 'view')) {
        header('Location: ' . root . admin . '/employees');
        exit;
    }
    $title = 'Support Targets'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once "app/views/admin/employees/targets.php";
    require_once views . "includes/footer.php";
});

// ================================ CREATE / EDIT (form)
$router->get(admin . '/employees/targets/create', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureSupportTargetSchema')) { ensureSupportTargetSchema($db); }
    if (!admin_can($db, 'support_targets', 'edit')) { header('Location: ' . root . admin . '/employees/targets'); exit; }
    $title = 'New Target'; $description = ''; $header = true; $footer = true;
    $_GET['id'] = 0;
    require_once views . "includes/header.php";
    require_once "app/views/admin/employees/targets-manage.php";
    require_once views . "includes/footer.php";
});

$router->get(admin . '/employees/targets/edit/([0-9]+)', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureSupportTargetSchema')) { ensureSupportTargetSchema($db); }
    if (!admin_can($db, 'support_targets', 'edit')) { header('Location: ' . root . admin . '/employees/targets'); exit; }
    $title = 'Edit Target'; $description = ''; $header = true; $footer = true;
    $_GET['id'] = (int) $id;
    require_once views . "includes/header.php";
    require_once "app/views/admin/employees/targets-manage.php";
    require_once views . "includes/footer.php";
});

// ================================ SAVE
$router->post(admin . '/employees/targets/save', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (class_exists('CSRF')) { CSRF::verifyRequest(); }
    if (function_exists('ensureSupportTargetSchema')) { ensureSupportTargetSchema($db); }
    if (!admin_can($db, 'support_targets', 'edit')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Not permitted.'];
        header('Location: ' . root . admin . '/employees/targets'); exit;
    }

    $id      = (int) ($_POST['id'] ?? 0);
    $scope   = in_array($_POST['scope'] ?? '', ['staff', 'gia', 'team'], true) ? $_POST['scope'] : 'staff';
    $metric  = in_array($_POST['metric'] ?? '', ['held', 'paid', 'revenue'], true) ? $_POST['metric'] : 'held';
    $period  = in_array($_POST['period'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $_POST['period'] : 'monthly';
    // staff_id is a users.user_id (VARCHAR) after S3; only kept for the 'staff' scope.
    $staffId = $scope === 'staff' ? (trim((string) ($_POST['staff_id'] ?? '')) ?: null) : null;
    $productIn = trim((string) ($_POST['product'] ?? ''));
    $product = ($productIn === '' || $productIn === 'any' || in_array($productIn, support_target_products(), true)) ? ($productIn === 'any' ? '' : $productIn) : '';
    $targetValue = max(0, (int) ($_POST['target_value'] ?? 0));
    $startsAt = trim((string) ($_POST['starts_at'] ?? ''));
    $endsAt   = trim((string) ($_POST['ends_at'] ?? ''));
    $label    = trim((string) ($_POST['label'] ?? ''));
    $active   = !empty($_POST['active']) ? 1 : 0;

    if ($scope === 'staff' && $staffId <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Pick a staff member for a per-agent target.'];
        header('Location: ' . root . admin . '/employees/targets'); exit;
    }
    if ($targetValue <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Target value must be greater than zero.'];
        header('Location: ' . root . admin . '/employees/targets'); exit;
    }

    $row = [
        'scope'        => $scope,
        'staff_id'     => $staffId,
        'metric'       => $metric,
        'product'      => $product !== '' ? $product : null,
        'period'       => $period,
        'target_value' => $targetValue,
        'starts_at'    => ($startsAt !== '' && strtotime($startsAt) !== false) ? date('Y-m-d', strtotime($startsAt)) : null,
        'ends_at'      => ($endsAt !== '' && strtotime($endsAt) !== false) ? date('Y-m-d', strtotime($endsAt)) : null,
        'label'        => $label !== '' ? $label : null,
        'active'       => $active,
    ];

    try {
        if ($id > 0) {
            $db->update('support_targets', $row, ['id' => $id]);
        } else {
            $row['created_by'] = (string) ($_SESSION['user_id'] ?? '');
            $db->insert('support_targets', $row);
        }
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Target saved.'];
    } catch (\Throwable $e) {
        error_log('[support] target save: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not save the target.'];
    }
    header('Location: ' . root . admin . '/employees/targets');
    exit;
});

// ================================ DELETE
$router->post(admin . '/employees/targets/delete', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (class_exists('CSRF')) { CSRF::verifyRequest(); }
    if (!admin_can($db, 'support_targets', 'edit')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Not permitted.'];
        header('Location: ' . root . admin . '/employees/targets'); exit;
    }
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        try { $db->delete('support_targets', ['id' => $id]); }
        catch (\Throwable $e) { error_log('[support] target delete: ' . $e->getMessage()); }
    }
    $_SESSION['message'] = ['type' => 'success', 'text' => 'Target removed.'];
    header('Location: ' . root . admin . '/employees/targets');
    exit;
});

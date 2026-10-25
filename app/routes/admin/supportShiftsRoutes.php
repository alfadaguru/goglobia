<?php
// app/routes/admin/supportShiftsRoutes.php
// Customer Support module — Phase 3: shift ROSTER admin screens. Assign/edit a
// shift (staff + date + start/end) and see the roster for a given day with the
// clocked-in badge + GIA nudge_count. Design-of-record:
// docs/support/CUSTOMER_SUPPORT_MODULE_SPEC.md (§2 data model, §3 routes, §7
// LOCKED decisions). Shape mirrors app/routes/admin/campaignsRoutes.php /
// supportStaffRoutes.php; every handler is ADMIN_AUTH() + the
// admin_can($db,'support_shifts','edit') capability gate, CSRF on POST.
//
// The data layer lives in app/lib/support/shifts.php (support_shifts_for,
// support_shift_upsert) + the self-healing schema in attendance.php
// (ensureSupportAttendanceSchema, support_shifts table). The nudge engine the
// cron fires is support_shift_nudge_due in the same lib.
@$SECURE or die('Access Denied!');

require_once __DIR__ . '/../../lib/support/shifts.php'; // roster + upsert + schema

// ================================ ROSTER (list for a date)
// GET ?date=YYYY-MM-DD (default today). support_shifts_for() returns each shift
// on that day joined to staff/users with clocked-in + nudge bookkeeping.
$router->get(admin.'/support/shifts', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    ensureSupportAttendanceSchema($db);
    if (!admin_can($db, 'support_shifts', 'view')) { http_response_code(403); die('Access Denied!'); }
    $title = 'Shift Roster'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/shifts.php";
    require_once views."includes/footer.php";
});

// ================================ CREATE (form)
$router->get(admin.'/support/shifts/create', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    ensureSupportAttendanceSchema($db);
    if (!admin_can($db, 'support_shifts', 'edit')) { http_response_code(403); die('Access Denied!'); }
    $title = 'Assign Shift'; $description = ''; $header = true; $footer = true;
    $_GET['id'] = 0;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/shifts-manage.php";
    require_once views."includes/footer.php";
});

// ================================ EDIT (form)
$router->get(admin.'/support/shifts/edit/([0-9]+)', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    ensureSupportAttendanceSchema($db);
    if (!admin_can($db, 'support_shifts', 'edit')) { http_response_code(403); die('Access Denied!'); }
    $title = 'Edit Shift'; $description = ''; $header = true; $footer = true;
    $_GET['id'] = (int) $id;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/shifts-manage.php";
    require_once views."includes/footer.php";
});

// ================================ SAVE (upsert — one shift per staff+date)
$router->post(admin.'/support/shifts/save', function () use ($SECURE, $db) {
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
        redirect(root . admin . '/support/shifts?date=' . urlencode(date('Y-m-d', strtotime($date))));
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/support/shifts');
    }
});

// ================================ DELETE (removes a single shift row)
$router->post(admin.'/support/shifts/delete', function () use ($SECURE, $db) {
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
    $back = root . admin . '/support/shifts';
    if ($date !== '' && strtotime($date) !== false) {
        $back .= '?date=' . urlencode(date('Y-m-d', strtotime($date)));
    }
    redirect($back);
});

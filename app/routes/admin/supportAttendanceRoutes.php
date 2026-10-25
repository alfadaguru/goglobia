<?php
// app/routes/admin/supportAttendanceRoutes.php
// Customer Support module — Phase 3: attendance HTTP layer. The thin routes over
// the attendance/presence engine in app/lib/support/attendance.php.
// Design-of-record: docs/support/CUSTOMER_SUPPORT_MODULE_SPEC.md §7.
//
// The engine (schema + clock/heartbeat/presence/report helpers) lives in
// app/lib/support/attendance.php — this file is the thin HTTP layer over it.
// rbac.php (support_can / support_current_staff / support_is_staff) is loaded in
// config.php; attendance.php is NOT, so every handler require_once's it then
// calls ensureSupportAttendanceSchema($db) (self-healing, idempotent).
//
// Shape mirrors supportDeskRoutes.php: ADMIN_AUTH() first, then an admin_can()
// capability gate, CSRF::guard() on the JSON POSTs. JSON denials return a 403
// {ok:false} body so the UI can surface them rather than die('Access Denied!').
@$SECURE or die('Access Denied!');

// ================================ ATTENDANCE PAGE (header/page/footer triple)
// cap attendance.view_all shows the whole team; a staffer who only has
// attendance.view_self sees just their own rows (scoped below in the view).
$router->get(admin.'/support/attendance', function () use ($SECURE, $db) {
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
    require_once "app/views/admin/support/attendance.php";
    require_once views."includes/footer.php";
});

// ================================ CLOCK (JSON POST) — mode in {in,out}.
// Any support staffer may clock THEMSELVES in/out. CSRF::guard() for JSON.
$router->post(admin.'/support/clock', function () use ($SECURE, $db) {
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
$router->post(admin.'/support/heartbeat', function () use ($SECURE, $db) {
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

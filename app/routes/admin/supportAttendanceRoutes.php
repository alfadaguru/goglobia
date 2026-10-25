<?php
// app/routes/admin/supportAttendanceRoutes.php
// LEGACY SHIM. The attendance PAGE moved to the Employees section
// (/admin/employees/attendance, app/routes/admin/employeesRoutes.php). The page
// GET 302-redirects there. The clock/heartbeat JSON POST endpoints are kept
// FUNCTIONAL here as working aliases (a fetch() POST does not reliably follow a
// 302, so any already-open tab still posting to the old paths keeps working).
// The engine (app/lib/support/attendance.php) is shared/unchanged.
@$SECURE or die('Access Denied!');

// ---- PAGE: redirect old /support/attendance -> /employees/attendance --------
$router->get(admin.'/support/attendance', function () use ($SECURE, $db) {
    $qs = !empty($_GET) ? ('?' . http_build_query($_GET)) : '';
    redirect(root . admin . '/employees/attendance' . $qs);
});

// ---- CLOCK (JSON POST) — kept functional (alias of /employees/clock) --------
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

// ---- HEARTBEAT (JSON POST) — kept functional (alias of /employees/heartbeat) -
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

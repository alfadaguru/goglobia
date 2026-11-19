<?php
// FILE: app/routes/users/notificationsRoutes.php
// Customer Notifications Centre — the in-app "bell" inbox. GET /notifications
// renders the list; POST endpoints mark items read. Store + helpers live in
// app/lib/notifications_centre.php. All scoped to the session user.

@$SECURE or die('Access Denied!');

// ---- GET /notifications : the page ----
$router->get('/notifications', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['login_error'] = 'required';
        header('Location: ' . root . 'login');
        exit;
    }
    CHECK_AGENT($db);
    $user_id = $_SESSION['user_id'];
    $user    = $db->get('users', '*', ['user_id' => $user_id]);

    $perPage = 20;
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $offset  = ($page - 1) * $perPage;

    $data = function_exists('notifications_list')
        ? notifications_list($db, (string) $user_id, $perPage, $offset)
        : ['rows' => [], 'total' => 0, 'unread' => 0];

    $dashboardData = [
        'total_bookings' => (int) $db->count('bookings', ['user_id' => $user_id]),
        'member_since'   => $user['created_at'] ?? 'N/A',
        'currency'       => strtoupper((string) ($user['currency'] ?? 'USD')),
        'is_agent'       => strtolower((string) ($user['role'] ?? '')) === 'agent',
    ];
    $notifData = [
        'rows'        => $data['rows'],
        'unread'      => (int) $data['unread'],
        'total'       => (int) $data['total'],
        'page'        => $page,
        'total_pages' => max(1, (int) ceil($data['total'] / $perPage)),
    ];

    $title       = 'Notifications';
    $description = 'Your alerts and updates';
    $header      = true;
    $footer      = true;

    require_once views . 'includes/header.php';
    require_once views . 'auth/notifications.php';
    require_once views . 'includes/footer.php';
});

// ---- POST /notifications/read : mark one or all read (JSON, CSRF-guarded) ----
$router->post('/notifications/read', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        http_response_code(401); echo json_encode(['ok' => false, 'message' => 'Login required']); exit;
    }
    $input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
    if (!class_exists('CSRF') || !CSRF::validateToken($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        http_response_code(403); echo json_encode(['ok' => false, 'message' => 'Invalid security token']); exit;
    }
    $uid = (string) $_SESSION['user_id'];
    $id  = (int) ($input['id'] ?? 0); // 0 = mark all
    if (function_exists('notifications_mark_read')) { notifications_mark_read($db, $uid, $id); }
    $unread = function_exists('notifications_unread_count') ? notifications_unread_count($db, $uid) : 0;
    echo json_encode(['ok' => true, 'unread' => $unread]);
    exit;
});

// ---- GET /notifications/unread-count : header-bell polling (JSON, login only).
// Deliberately NOT under /api/* (that surface is API-key-gated + lazy-loaded); this
// is a session-scoped web endpoint loaded with the other customer routes. ----
$router->get('/notifications/unread-count', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        echo json_encode(['unread' => 0]); exit;
    }
    $unread = function_exists('notifications_unread_count') ? notifications_unread_count($db, (string) $_SESSION['user_id']) : 0;
    echo json_encode(['unread' => $unread]);
    exit;
});

<?php
// FILE: app/routes/users/priceAlertsRoutes.php
// Customer Price Alerts — watch a route/stay/etc. for a price drop. GET
// /price-alerts renders the list + an "add watch" form; POST endpoints
// create/delete. The cron worker (price_alerts_sweep) lives in the lib and is
// exposed at /price_alerts_sweep for the scheduler (see docs/CRON.md).

@$SECURE or die('Access Denied!');

require_once 'app/lib/price_alerts.php';

// ---- GET /price-alerts : the page ----
$router->get('/price-alerts', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['login_error'] = 'required';
        header('Location: ' . root . 'login');
        exit;
    }
    CHECK_AGENT($db);
    $user_id = $_SESSION['user_id'];
    $user    = $db->get('users', '*', ['user_id' => $user_id]);

    $alerts = function_exists('price_alerts_list') ? price_alerts_list($db, (string) $user_id) : [];

    // Modules the customer can set a watch for (only enabled ones).
    $enabled = $db->select('modules', 'type', ['status' => 1]) ?: [];
    $enabledMap = array_flip($enabled);
    $watchable = [];
    foreach (['flights' => 'Flights', 'stays' => 'Stays', 'tours' => 'Tours', 'cars' => 'Cars',
              'bus' => 'Bus', 'ferries' => 'Ferries', 'rail' => 'Rail', 'cruises' => 'Cruises',
              'esim' => 'eSIM', 'visa' => 'Visa', 'umrah' => 'Umrah'] as $k => $lbl) {
        if (isset($enabledMap[$k])) { $watchable[$k] = $lbl; }
    }

    $dashboardData = [
        'total_bookings' => (int) $db->count('bookings', ['user_id' => $user_id]),
        'member_since'   => $user['created_at'] ?? 'N/A',
        'currency'       => strtoupper((string) ($user['currency'] ?? 'USD')),
        'is_agent'       => strtolower((string) ($user['role'] ?? '')) === 'agent',
    ];
    $alertsData = [
        'alerts'    => $alerts,
        'watchable' => $watchable,
        'currency'  => strtoupper((string) ($_SESSION['app_currency'] ?? ($user['currency'] ?? 'USD'))),
    ];

    $title       = 'Price Alerts';
    $description = 'Watch prices and get notified when they drop';
    $header      = true;
    $footer      = true;

    require_once views . 'includes/header.php';
    require_once views . 'auth/price-alerts.php';
    require_once views . 'includes/footer.php';
});

// ---- POST /price-alerts/create (JSON, CSRF-guarded) ----
$router->post('/price-alerts/create', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        http_response_code(401); echo json_encode(['ok' => false, 'message' => 'Login required']); exit;
    }
    $input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
    if (!class_exists('CSRF') || !CSRF::validateToken($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        http_response_code(403); echo json_encode(['ok' => false, 'message' => 'Invalid security token']); exit;
    }
    $uid = (string) $_SESSION['user_id'];
    $id  = function_exists('price_alert_create') ? price_alert_create($db, $uid, [
        'module'         => $input['module'] ?? '',
        'label'          => $input['label'] ?? '',
        'baseline_price' => $input['baseline_price'] ?? 0,
        'target_price'   => $input['target_price'] ?? '',
        'currency'       => $input['currency'] ?? 'USD',
        'params'         => $input['params'] ?? null,
    ]) : 0;
    if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'Could not create the alert. Check the price and that the module is available.']); exit; }
    echo json_encode(['ok' => true, 'alerts' => price_alerts_list($db, $uid)]);
    exit;
});

// ---- POST /price-alerts/delete (JSON, CSRF-guarded) ----
$router->post('/price-alerts/delete', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        http_response_code(401); echo json_encode(['ok' => false, 'message' => 'Login required']); exit;
    }
    $input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
    if (!class_exists('CSRF') || !CSRF::validateToken($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        http_response_code(403); echo json_encode(['ok' => false, 'message' => 'Invalid security token']); exit;
    }
    $uid = (string) $_SESSION['user_id'];
    $ok  = function_exists('price_alert_delete') ? price_alert_delete($db, $uid, (int) ($input['id'] ?? 0)) : false;
    echo json_encode(['ok' => (bool) $ok, 'alerts' => price_alerts_list($db, $uid)]);
    exit;
});

// ---- GET /price_alerts_sweep : cron worker (idempotent, best-effort 200) ----
// Public like the other cron endpoints (no session; triggered by the scheduler).
// See docs/CRON.md. Does no harm if hit by anyone — it only re-checks prices.
$router->get('/price_alerts_sweep', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    $res = function_exists('price_alerts_sweep') ? price_alerts_sweep($db) : ['ok' => false];
    echo json_encode(array_merge(['status' => true, 'ran_at' => date('Y-m-d H:i:s')], (array) $res));
    exit;
});

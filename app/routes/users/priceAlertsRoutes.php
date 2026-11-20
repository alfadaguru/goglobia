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
    // Only modules the sweep can actually RE-PRICE are offered — otherwise a watch
    // would be saved but never fire (a dead watch). Wired = own-inventory price that
    // can drop: flights, stays, tours, cars, cruises, umrah. Deliberately excluded:
    // bus/ferries/rail (no own inventory — supplier-only) and visa/esim (fixed
    // supplier/fee price — a "drop" is meaningless). Add here as more get wired.
    foreach (['flights' => 'Flights', 'stays' => 'Stays', 'tours' => 'Tours',
              'cars' => 'Cars', 'cruises' => 'Cruises', 'umrah' => 'Umrah'] as $k => $lbl) {
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

// ---- GET /price-alerts/airport-suggest?q= : local airport lookup for the flight
// watch picker. Returns {id, code, label} from flights_airports — the SAME local
// ids flights.from_airport_id/to_airport_id use, so a watch created here can be
// re-priced by the sweep. Login-only; read-only. ----
$router->get('/price-alerts/airport-suggest', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) { echo json_encode([]); exit; }
    $q = trim((string) ($_GET['q'] ?? ''));
    if (strlen($q) < 2) { echo json_encode([]); exit; }
    try {
        // Medoo [~] won't auto-wrap % when the needle has _ etc. (known gotcha), so
        // wrap the wildcards ourselves and match code / city / airport name.
        $like = '%' . $q . '%';
        $rows = $db->select('flights_airports', ['id', 'code', 'city', 'airport'], [
            'OR' => [
                'code[~]'    => $like,
                'city[~]'    => $like,
                'airport[~]' => $like,
            ],
            'status' => '1',
            'ORDER'  => ['code' => 'ASC'],
            'LIMIT'  => 12,
        ]) ?: [];
        echo json_encode(array_map(static function ($r) {
            return [
                'id'    => (int) $r['id'],
                'code'  => (string) $r['code'],
                'label' => trim(((string) $r['code']) . ' — ' . ((string) $r['city']) . ' (' . ((string) $r['airport']) . ')'),
            ];
        }, $rows));
    } catch (\Throwable $e) {
        error_log('PRICE_ALERTS_AIRPORT_SUGGEST: ' . $e->getMessage());
        echo json_encode([]);
    }
    exit;
});

// ---- GET /price-alerts/stay-suggest?q= : local stay (hotel) lookup for the stays
// watch picker. Returns {id, label} from `stays`, limited to publicly-listable
// properties (status=1 + approved) — the same ids the own-stays search prices. The
// resolver re-prices by this stay_id. Login-only; read-only. ----
$router->get('/price-alerts/stay-suggest', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) { echo json_encode([]); exit; }
    $q = trim((string) ($_GET['q'] ?? ''));
    if (strlen($q) < 2) { echo json_encode([]); exit; }
    try {
        $like = '%' . $q . '%'; // self-wrap % (Medoo [~] gotcha)
        $rows = $db->select('stays', ['id', 'name', 'location'], [
            'OR' => [
                'name[~]'     => $like,
                'location[~]' => $like,
            ],
            'status'         => '1',
            'listing_status' => 'approved',
            'ORDER'          => ['name' => 'ASC'],
            'LIMIT'          => 12,
        ]) ?: [];
        echo json_encode(array_map(static function ($r) {
            $name = (string) $r['name'];
            $loc  = trim((string) ($r['location'] ?? ''));
            return ['id' => (int) $r['id'], 'label' => $loc !== '' ? ($name . ' — ' . $loc) : $name];
        }, $rows));
    } catch (\Throwable $e) {
        error_log('PRICE_ALERTS_STAY_SUGGEST: ' . $e->getMessage());
        echo json_encode([]);
    }
    exit;
});

// ---- GET /price-alerts/item-suggest?module=&q= : generic own-inventory item
// picker for tours / cars / cruises / umrah watches. Returns {id, label, params}
// where params carries whatever the resolver needs for that module. Login-only. ----
$router->get('/price-alerts/item-suggest', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) { echo json_encode([]); exit; }
    $module = strtolower(trim((string) ($_GET['module'] ?? '')));
    $q = trim((string) ($_GET['q'] ?? ''));
    if (strlen($q) < 2) { echo json_encode([]); exit; }
    $like = '%' . $q . '%'; // self-wrap % (Medoo [~] gotcha)
    $out = [];
    try {
        if ($module === 'tours') {
            $rows = $db->select('tours', ['id', 'name'], ['name[~]' => $like, 'status' => 1, 'ORDER' => ['name' => 'ASC'], 'LIMIT' => 12]) ?: [];
            foreach ($rows as $r) { $out[] = ['id' => (int) $r['id'], 'label' => (string) $r['name'], 'params' => ['tour_id' => (int) $r['id']]]; }
        } elseif ($module === 'cars') {
            $rows = $db->select('cars', ['id', 'name', 'brand', 'model'], ['OR' => ['name[~]' => $like, 'brand[~]' => $like, 'model[~]' => $like], 'status' => 1, 'ORDER' => ['name' => 'ASC'], 'LIMIT' => 12]) ?: [];
            foreach ($rows as $r) {
                $lbl = trim(implode(' ', array_filter([(string) ($r['brand'] ?? ''), (string) ($r['model'] ?? ''), (string) ($r['name'] ?? '')])));
                $out[] = ['id' => (int) $r['id'], 'label' => $lbl ?: ('Car #' . (int) $r['id']), 'params' => ['car_id' => (int) $r['id']]];
            }
        } elseif ($module === 'cruises') {
            $rows = $db->select('cruises', ['id', 'name'], ['name[~]' => $like, 'status' => 1, 'ORDER' => ['name' => 'ASC'], 'LIMIT' => 12]) ?: [];
            foreach ($rows as $r) { $out[] = ['id' => (int) $r['id'], 'label' => (string) $r['name'], 'params' => ['cruise_id' => (int) $r['id']]]; }
        } elseif ($module === 'umrah') {
            // Match on the parent departure's origin city; label each bookable tier.
            $deps = $db->select('umrah_departures', ['id', 'origin_city', 'departure_date'], ['origin_city[~]' => $like, 'status' => 'published', 'ORDER' => ['departure_date' => 'ASC'], 'LIMIT' => 8]) ?: [];
            if (!$deps) { $deps = $db->select('umrah_departures', ['id', 'origin_city', 'departure_date'], ['origin_city[~]' => $like, 'ORDER' => ['departure_date' => 'ASC'], 'LIMIT' => 8]) ?: []; }
            foreach ($deps as $d) {
                $tiers = $db->select('umrah_departure_tiers', ['id', 'tier_id'], ['departure_id' => (int) $d['id'], 'status' => 'active', 'LIMIT' => 10]) ?: [];
                foreach ($tiers as $t) {
                    $lbl = trim(((string) ($d['origin_city'] ?? 'Umrah')) . ' — ' . date('d M Y', strtotime((string) ($d['departure_date'] ?? 'now'))) . ' (tier ' . (int) $t['tier_id'] . ')');
                    $out[] = ['id' => (int) $t['id'], 'label' => $lbl, 'params' => ['tier_id' => (int) $t['id']]];
                }
            }
        }
        echo json_encode(array_slice($out, 0, 20));
    } catch (\Throwable $e) {
        error_log('PRICE_ALERTS_ITEM_SUGGEST: ' . $e->getMessage());
        echo json_encode([]);
    }
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

// ---- GET /price_alerts_supplier_sweep : THROTTLED real-supplier (Adivaha) flight
// re-price. Separate cron, run LESS often (hourly) — each watch is an external
// search. Capped per run so it can't hammer the supplier or hang. Builds the
// absolute site base from the inbound request for its server-to-server call.
// See docs/CRON.md. ----
$router->get('/price_alerts_supplier_sweep', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    // Absolute base URL for the internal search call: prefer APP_URL (correct in
    // prod), fall back to the inbound scheme+host + the app's root path.
    $base = '';
    $env = function_exists('getenv') ? (getenv('APP_URL') ?: '') : '';
    if (defined('root') && is_string(root) && strpos((string) root, 'http') === 0) {
        $base = (string) root;
    } elseif ($env !== '') {
        $base = $env;
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $base   = $scheme . '://' . $host . (defined('root') ? (string) root : '/');
    }
    // Allow the scheduler to tune the per-run cap: ?max=10 (bounded in the lib).
    $max = max(1, min(50, (int) ($_GET['max'] ?? 15)));
    $res = function_exists('price_alerts_supplier_sweep')
        ? price_alerts_supplier_sweep($db, $base, $max)
        : ['ok' => false];
    echo json_encode(array_merge(['status' => true, 'ran_at' => date('Y-m-d H:i:s')], (array) $res));
    exit;
});

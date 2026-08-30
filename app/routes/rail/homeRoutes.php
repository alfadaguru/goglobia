<?php
@$SECURE or die('Access Denied!');

// 1. SEARCH INDEX LANDING PAGE
$router->get('/rail', function () use ($SECURE, $db) {
    $title = 'Train Tickets Booking | ' . $GLOBALS['app']['home_title'];
    $description = "Search and book train tickets online at the best rates.";
    require_once views . "includes/header.php";
    require_once views . "modules/rail/index.php";
    require_once views . "includes/footer.php";
});

// 2. SUGGEST STATIONS FALLBACK
$router->post('/rail-location-suggestion', function () use ($db, $SECURE) {
    header('Content-Type: application/json; charset=utf-8');
    require_once dirname(__DIR__, 3) . '/modules/rail/train/stations.php';
    require_once dirname(__DIR__, 3) . '/modules/rail/train/search.php';

    $q = trim((string)($_POST['query'] ?? ''));
    $journeyType = (int)($_POST['journey_type'] ?? 3);
    if (!in_array($journeyType, [1, 2, 3], true)) {
        $journeyType = 3;
    }

    $result = _train_search_stations($db, $q, $journeyType, 30);

    $mapped = [];
    foreach ($result['stations'] as $s) {
        if ((int)$s['journey_type'] !== $journeyType) {
            continue;
        }
        $mapped[] = [
            'code'         => $s['code'],
            'name'         => _train_station_english_only($s['name']),
            'city'         => _train_station_english_only($s['city']),
            'country'      => $s['country'],
            'type'         => (int)$s['journey_type'],
            'journey_type' => (int)$s['journey_type'],
        ];
    }

    echo json_encode([
        'journey_type'       => $journeyType,
        'journey_type_label' => _train_journey_type_label($journeyType, true),
        'stations'           => $mapped,
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// 3. PUBLIC SEARCH SUGGESTION API — handled by app/routes/api/rail/searchRoutes.php
// (registered when request path starts with /api/). Legacy POST fallback below.

// 4. ADMIN STATION IMPORT ROUTE (legacy — prefer module settings tab)
$router->route(['GET', 'POST'], admin . '/rail/import-stations', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    header('Content-Type: application/json; charset=utf-8');
    require_once dirname(__DIR__, 3) . '/modules/rail/train/stations.php';
    try {
        echo json_encode(_train_import_stations($db));
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
});

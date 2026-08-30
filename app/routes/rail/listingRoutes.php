<?php
@$SECURE or die('Access Denied!');

require_once dirname(__DIR__, 3) . '/modules/rail/train/search.php';

$railListingHandler = function (
    string $from,
    string $to,
    string $date,
    int $journeyType,
    int $adultsCount,
    int $childrenCount,
    int $infantsCount,
    string $metricsRaw = '0'
) use ($SECURE, $db) {
    $adultsCount = max(1, min(10, $adultsCount));
    $childrenCount = max(0, min(10, $childrenCount));
    $infantsCount = max(0, min(10, $infantsCount));
    $metrics = _train_parse_passenger_metrics_bundle($metricsRaw, $childrenCount, $infantsCount, $journeyType);

    $_SESSION['rail_search'] = [
        'from' => $from,
        'to' => $to,
        'date' => $date,
        'journey_type' => $journeyType,
        'adults' => $adultsCount,
        'children' => $childrenCount,
        'infants' => $infantsCount,
        'child_ages' => $metrics['child_ages'],
        'infant_ages' => $metrics['infant_ages'],
    ];

    $_SESSION['rail_origin']       = $from;
    $_SESSION['rail_destination']    = $to;
    $_SESSION['rail_date']         = $date;
    $_SESSION['rail_journey_type'] = $journeyType;
    $_SESSION['rail_adults']       = $adultsCount;
    $_SESSION['rail_children']     = $childrenCount;
    $_SESSION['rail_infants']      = $infantsCount;
    $_SESSION['rail_child_ages']   = $metrics['child_ages'];
    $_SESSION['rail_infant_ages']  = $metrics['infant_ages'];

    if (!function_exists('_train_station_label')) {
        require_once dirname(__DIR__, 3) . '/modules/rail/train/stations.php';
    }
    $_SESSION['rail_origin_name']      = _train_station_label($db, $from);
    $_SESSION['rail_destination_name'] = _train_station_label($db, $to);

    $title = 'Train Schedules Search | ' . $GLOBALS['app']['home_title'];
    require_once views . 'includes/header.php';
    require_once views . 'modules/rail/listing/rail.php';
    require_once views . 'includes/footer.php';
};

// New URL: .../{adults}/{children}/{infants}/{metrics?}
$router->get('/rail/search/([^/]+)/([^/]+)/([^/]+)/([^/]+)/(\d+)/(\d+)/(\d+)(?:/([^/]+))?', function (
    $from, $to, $date, $journeyType, $adults, $children, $infants, $metricsRaw = '0'
) use ($railListingHandler) {
    $railListingHandler(
        $from,
        $to,
        $date,
        (int)$journeyType,
        (int)$adults,
        (int)$children,
        (int)$infants,
        (string)$metricsRaw
    );
});

// Legacy URL: .../{adults}/{children}/{metrics?} (infants = 0)
$router->get('/rail/search/([^/]+)/([^/]+)/([^/]+)/([^/]+)/(\d+)/(\d+)(?:/([^/]+))?', function (
    $from, $to, $date, $journeyType, $adults, $children, $metricsRaw = '0'
) use ($railListingHandler) {
    $railListingHandler(
        $from,
        $to,
        $date,
        (int)$journeyType,
        (int)$adults,
        (int)$children,
        0,
        (string)$metricsRaw
    );
});

// 2. SEARCH SCHEDULES API
$trainQueryHandler = function () use ($SECURE, $db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        $prep = _train_prepare_train_query_input($input);
        if ($prep !== null && empty($prep['valid'])) {
            http_response_code(200);
            echo json_encode([
                'code'   => 400,
                'msg'    => $prep['message'],
                'msg_en' => $prep['message'],
                'data'   => null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        // Read-only search call — safe to retry on transient connection failures,
        // unlike order/cancel/refund which must never be retried automatically.
        $res = _train_request('/ticket/trainQuery', $input, ['db' => $db, 'retries' => 2]);
        if (!$res['ok']) {
            http_response_code($res['status'] ?: 200);
            echo _train_supplier_json_response($res);
            return;
        }

        $trainRows = _train_extract_train_query_rows($res['data'] ?? []);
        if ($trainRows === []) {
            http_response_code($res['status'] ?: 200);
            echo json_encode(['code' => 200, 'msg' => '', 'data' => ['data' => []]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $displayCurrency = $_SESSION['app_currency'] ?? 'USD';
        $data = is_array($res['data'] ?? null) ? $res['data'] : ['code' => 200, 'data' => ['data' => $trainRows]];

        // Ensure downstream markup loop always receives a list at data.data (listing JS shape).
        if (!isset($data['data']) || !is_array($data['data'])) {
            $data['data'] = ['data' => $trainRows];
        } elseif (!array_is_list($data['data']['data'] ?? null)) {
            $data['data']['data'] = $trainRows;
        }

        $selectedSeatClasses = [];
        if (!empty($input['seat_classes']) && is_array($input['seat_classes'])) {
            $selectedSeatClasses = array_map(static fn($c) => strtoupper(trim((string)$c)), $input['seat_classes']);
        } elseif (!empty($input['seat_classes']) && is_string($input['seat_classes'])) {
            $selectedSeatClasses = array_map('strtoupper', array_filter(explode(',', $input['seat_classes'])));
        }

        $priceMin = isset($input['price_min']) ? (float)$input['price_min'] : 0;
        $priceMax = isset($input['price_max']) ? (float)$input['price_max'] : PHP_FLOAT_MAX;
        $journeyType = (int)($input['journey_type'] ?? 1);
        $billablePassengers = _train_billable_passenger_count(
            $journeyType,
            max(1, (int)($input['adults'] ?? 1)),
            max(0, (int)($input['children'] ?? 0)),
            max(0, (int)($input['infants'] ?? 0))
        );

        foreach ($data['data']['data'] as &$train) {
            $train['journey_type'] = $journeyType;
            $train['billable_passengers'] = $billablePassengers;
            if (!empty($train['seats'])) {
                if ($selectedSeatClasses !== []) {
                    $train['seats'] = _train_filter_train_seats($train['seats'], $selectedSeatClasses, $priceMin, $priceMax);
                }
                foreach ($train['seats'] as &$seat) {
                    if (is_array($seat)) {
                        _train_apply_seat_price_markup($seat, $db, $displayCurrency);
                        $supplierUnit = (float)($seat['supplier_order_price']
                            ?? $seat['original_price']
                            ?? $seat['minPrice']
                            ?? $seat['price']
                            ?? 0);
                        $seat['price_total'] = round((float)($seat['price'] ?? 0) * $billablePassengers, 2);
                        $seat['price_total_limit'] = round($supplierUnit * $billablePassengers, 2);
                    }
                }
                unset($seat);
                _train_enrich_seat_labels($train['seats']);
            }
        }
        unset($train);

        if ($selectedSeatClasses !== []) {
            $data['data']['data'] = array_values(array_filter(
                $data['data']['data'],
                static fn($train) => !empty($train['seats'])
            ));
        }

        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
    }
};
$router->post('/rail/trainQuery', $trainQueryHandler);
$router->post('/ticket/trainQuery', $trainQueryHandler);

// 3. INTERMEDIATE STOPS API
$trainWayQueryHandler = function () use ($SECURE, $db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $res   = _train_request('/ticket/trainWayQuery', $input, ['db' => $db, 'retries' => 2]);
        http_response_code($res['status'] ?: 200);
        echo $res['raw'];
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['code' => 500, 'msg' => $e->getMessage(), 'data' => null]);
    }
};
$router->post('/rail/trainWayQuery', $trainWayQueryHandler);
$router->post('/ticket/trainWayQuery', $trainWayQueryHandler);

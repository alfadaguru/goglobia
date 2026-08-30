<?php
// ============================================================================
// RAIL API — SEARCH ROUTES
// ============================================================================
// GET|POST /api/rail/stations  — station autocomplete (from rail_stations table)
// POST     /api/rail/search     — train schedules + seat prices (supplier trainQuery)
// POST     /api/rail/stops       — intermediate stops (supplier trainWayQuery)
// ============================================================================

@$SECURE or die('Access Denied!');

require_once dirname(__DIR__, 4) . '/modules/rail/train/api.php';
require_once dirname(__DIR__, 4) . '/modules/rail/train/stations.php';

// ----------------------------------------------------------------------------
// STATIONS
// Body/query: query (optional), journey_type (default 3), limit (default 30)
// ----------------------------------------------------------------------------
$stationsHandler = function () use ($db) {
    try {
        $input = [];
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
        }

        $query = trim((string)($input['query'] ?? $input['q'] ?? $_GET['query'] ?? $_GET['q'] ?? ''));
        $journeyType = (int)($input['journey_type'] ?? $_GET['journey_type'] ?? 3);
        $limit = max(1, min(100, (int)($input['limit'] ?? $_GET['limit'] ?? 30)));

        $result = _train_search_stations($db, $query, $journeyType, $limit);

        _train_respond(true, 'OK', [
            'journey_type'       => $result['journey_type'],
            'searched_all_types' => $result['searched_all_types'],
            'count'              => count($result['stations']),
            'stations'           => $result['stations'],
        ]);
    } catch (Throwable $e) {
        _train_respond(false, $e->getMessage(), null, 500);
    }
};

$router->get('/api/rail/stations', $stationsHandler);
$router->post('/api/rail/stations', $stationsHandler);

// ----------------------------------------------------------------------------
// SEARCH — trainQuery
// Body: { from_station_code, to_station_code, from_date, journey_type, currency?,
//         seat_classes?, price_min?, price_max? }
// from_date: Unix timestamp (seconds) OR YYYY-MM-DD string
// seat_classes: array or comma-separated string of seat_class codes to keep (default: all)
// ----------------------------------------------------------------------------
$router->post('/api/rail/search', function () use ($db) {
    @set_time_limit(60);
    try {
        if (!_train_is_module_ready($db)) {
            _train_respond(false, 'Rail module is not configured or disabled.', null, 503);
        }

        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        $fromCode = trim((string)($input['from_station_code'] ?? ''));
        $toCode   = trim((string)($input['to_station_code'] ?? ''));
        $journeyType = (int)($input['journey_type'] ?? 3);

        if ($fromCode === '' || $toCode === '') {
            _train_respond(false, 'from_station_code and to_station_code are required.', null, 422);
        }

        $fromDate = $input['from_date'] ?? null;
        if ($fromDate === null || $fromDate === '') {
            _train_respond(false, 'from_date is required (Unix timestamp or YYYY-MM-DD).', null, 422);
        }
        $fromDate = _train_inquiry_timestamp_from_date($fromDate, $journeyType);
        if ($fromDate <= 0) {
            _train_respond(false, 'Invalid from_date.', null, 422);
        }

        $payload = [
            'from_station_code' => $fromCode,
            'to_station_code'   => $toCode,
            'from_date'         => $fromDate,
            'journey_type'      => $journeyType,
        ];

        $adults = max(0, (int)($input['adults'] ?? 1));
        $childrenCount = max(0, (int)($input['children'] ?? 0));
        $infantCount = max(0, (int)($input['infants'] ?? 0));
        $metrics = _train_parse_passenger_metrics_bundle(
            $input['passenger_metrics'] ?? $input['child_ages'] ?? $input['child_age'] ?? [],
            $childrenCount,
            $infantCount,
            $journeyType
        );
        $payload['adults'] = $adults;
        $payload['children'] = $childrenCount;
        $payload['infants'] = $infantCount;
        _train_apply_train_query_passengers($payload, $adults, $metrics['child_ages'], $metrics['infant_ages']);

        $res = _train_request('/ticket/trainQuery', $payload, ['db' => $db, 'retries' => 2]);
        if (!$res['ok']) {
            $decoded = is_array($res['data']) ? $res['data'] : [];
            _train_respond(
                false,
                _train_api_error_message($decoded['code'] ?? 0, (string)($decoded['msg'] ?? $res['error'] ?? 'Search failed')),
                $decoded,
                $res['status'] ?: 502
            );
        }

        $selectedSeatClasses = [];
        if (!empty($input['seat_classes']) && is_array($input['seat_classes'])) {
            $selectedSeatClasses = array_map(static fn($c) => strtoupper(trim((string)$c)), $input['seat_classes']);
        } elseif (!empty($input['seat_classes']) && is_string($input['seat_classes'])) {
            $selectedSeatClasses = array_map('strtoupper', array_filter(explode(',', $input['seat_classes'])));
        }
        $priceMin = isset($input['price_min']) ? (float)$input['price_min'] : 0;
        $priceMax = isset($input['price_max']) ? (float)$input['price_max'] : PHP_FLOAT_MAX;

        $billablePassengers = _train_billable_passenger_count($journeyType, max(1, $adults), $childrenCount, $infantCount);

        $currency = _train_target_currency($input);
        $data = $res['data'];
        if (is_array($data)) {
            _train_apply_search_markup(
                $data,
                $db,
                $currency,
                $journeyType,
                $billablePassengers,
                $selectedSeatClasses,
                $priceMin,
                $priceMax
            );
        }

        _train_respond(true, 'OK', [
            'search'       => array_merge($payload, [
                'adults'       => $adults,
                'children'     => $childrenCount,
                'infants'      => $infantCount,
                'child_ages'   => $metrics['child_ages'],
                'infant_ages'  => $metrics['infant_ages'],
            ]),
            'currency'            => $currency,
            'billable_passengers' => $billablePassengers,
            'trains'              => $data['data']['data'] ?? [],
            'seat_classes'        => _train_seat_classes_for_api(),
            'supplier'            => $data,
        ]);
    } catch (Throwable $e) {
        _train_respond(false, $e->getMessage(), null, 500);
    }
});

// ----------------------------------------------------------------------------
// STOPS — trainWayQuery (intermediate stations for a train)
// Body: supplier trainWayQuery payload (train_no, from_station_code, etc.)
// ----------------------------------------------------------------------------
$router->post('/api/rail/stops', function () use ($db) {
    try {
        if (!_train_is_module_ready($db)) {
            _train_respond(false, 'Rail module is not configured or disabled.', null, 503);
        }

        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        if (empty($input)) {
            _train_respond(false, 'Request body is required.', null, 422);
        }

        $res = _train_request('/ticket/trainWayQuery', $input, ['db' => $db, 'retries' => 2]);
        if (!$res['ok']) {
            $decoded = is_array($res['data']) ? $res['data'] : null;
            _train_respond(false, (string)($decoded['msg'] ?? $res['error'] ?? 'Could not fetch stops'), $decoded, $res['status'] ?: 502);
        }

        _train_respond(true, 'OK', $res['data']['data'] ?? $res['data']);
    } catch (Throwable $e) {
        _train_respond(false, $e->getMessage(), null, 500);
    }
});

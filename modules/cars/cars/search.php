<?php
// ============================================================================
// CARS SEARCH API ENDPOINT (LOCAL)
// ============================================================================
//
// PURPOSE:
// Search local car database with dynamic B2B/B2C markup application
// and currency conversion.
//
// ENDPOINT: POST cars/{subFolder}/search
//
// ============================================================================

$router->post('cars/cars/search', function() use ($db) {
    // search_guard_v2: session lock release + configurable timeouts for long supplier requests
    @set_time_limit(30);
    $connectTimeout = defined('SUPPLIER_CONNECT_TIMEOUT') ? SUPPLIER_CONNECT_TIMEOUT : 10;
    $requestTimeout = defined('SUPPLIER_REQUEST_TIMEOUT') ? SUPPLIER_REQUEST_TIMEOUT : 30;
    $searchSessionData = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $searchSessionData = $_SESSION;
        session_write_close();
 if (defined('DEBUG_SEARCH_GUARD') && DEBUG_SEARCH_GUARD === true) {
    error_log('search_guard: session lock released');
}
    }

    if (connection_aborted()) {
 if (defined('DEBUG_SEARCH_GUARD') && DEBUG_SEARCH_GUARD === true) {
    error_log('search_guard: request aborted early');
}
        exit;
    }
    // ========================================
    // CLEAN OUTPUT BUFFER
    // ========================================
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    // ========================================
    // INITIALIZATION - Extract search parameters
    // ========================================

    // Handle JSON Input
    if (empty($_POST)) {
        $json = file_get_contents('php://input');
        if (!empty($json)) {
            $_POST = json_decode($json, true) ?? [];
        }
    }


    $pickup_location = $_POST['pickup_location'] ?? '';
    $dropoff_location = $_POST['dropoff_location'] ?? '';
    $service_type = $_POST['service_type'] ?? 'transfer';
    $pickup_date = $_POST['pickup_date'] ?? '';
    $return_date = $_POST['return_date'] ?? '';
    $car_type = $_POST['car_type'] ?? 'any';
    $currency = $_POST['currency'] ?? 'USD';

    $page = max(1, (int)($_POST['page'] ?? 1));
    $per_page = min(100, max(1, (int)($_POST['per_page'] ?? 25)));
    $supplierRawResponse = null;

    // AI Trip + website listings send DD-MM-YYYY; DateTime prefers Y-m-d / d/m/Y.
    $normalizeCarDate = static function ($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $value, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        return $value;
    };
    $pickup_date = $normalizeCarDate($pickup_date);
    $return_date = $normalizeCarDate($return_date);

    try {

    $sessionCurrency = isset($searchSessionData['app_currency']) ? $searchSessionData['app_currency'] : $currency;

    // ========================================
    // CALCULATE RENTAL DAYS
    // ========================================
    $rental_days = 1;
    if (!empty($pickup_date) && !empty($return_date)) {
        try {
            $date1 = new DateTime($pickup_date);
            $date2 = new DateTime($return_date);
            $interval = $date1->diff($date2);
            $rental_days = (int)$interval->days;
            if ($rental_days < 1) $rental_days = 1;
        } catch (Exception $e) {
            $rental_days = 1;
        }
    }

    // ========================================
    // QUERY LOCAL CARS THROUGH ROUTES
    // ========================================
    // ========================================
    // QUERY LOCAL CARS THROUGH ROUTES
    // ========================================
    $where = [
        'status' => 1,
        'service_type' => $service_type
    ];

    // Optional car type filtering at DB level
    if ($car_type !== 'any') {
        $carTypeId = $db->get('cars_settings', 'id', [
            'setting_type' => 'car_type',
            'setting_label' => $car_type
        ]);
        if ($carTypeId) $where['car_type_id'] = $carTypeId;
    }

    $all_cars = $db->select('cars', '*', $where);

    // ========================================
    // RESOLVE LOCATION IDS
    // ========================================
    $resolveLocationIds = static function ($db, string $location): array {
        $location = trim($location);
        if ($location === '') {
            return [];
        }
        $ids = [];
        $candidates = [$location];
        // AI / rental UX often sends "Barcelona Airport" while local routes may use
        // the city location id — also try the bare city name.
        if (preg_match('/^(.+?)\s+Airport$/i', $location, $m)) {
            $candidates[] = trim($m[1]);
        }
        foreach ($candidates as $term) {
            if ($term === '') {
                continue;
            }
            $airports = $db->select('flights_airports', 'id', [
                'OR' => [
                    'airport[~]' => $term,
                    'city[~]' => $term,
                    'code[~]' => $term,
                ],
            ]);
            if (!empty($airports)) {
                $ids = array_merge($ids, $airports);
            }
            $cities = $db->select('locations', 'id', [
                'OR' => [
                    'city[~]' => $term,
                    'country[~]' => $term,
                ],
            ]);
            if (!empty($cities)) {
                foreach ($cities as $cid) {
                    $ids[] = 'city_' . $cid;
                }
            }
        }
        return array_values(array_unique($ids));
    };

    $pickup_ids = $resolveLocationIds($db, (string) $pickup_location);
    $dropoff_ids = $resolveLocationIds($db, (string) $dropoff_location);

    // ========================================
    // PROCESS RESULTS (Filter by JSON Routes)
    // ========================================
    $module = $db->get('modules', '*', ['name' => 'cars', 'type' => 'cars']);
    if (!$module) {
        $module = ['markup_b2b' => 0, 'markup_b2c' => 0, 'markup_type_b2b' => 'percentage', 'markup_type_b2c' => 'percentage', 'currency' => 'USD'];
    }

    // Real car_type/amenity labels, so listing-page filters (car type, air
    // conditioning, unlimited mileage) have something meaningful to match
    // against instead of a hardcoded placeholder.
    $carTypeLabels = [];
    foreach ($db->select('cars_settings', ['id', 'setting_label'], ['setting_type' => 'car_type']) as $row) {
        $carTypeLabels[$row['id']] = $row['setting_label'];
    }
    $amenityLabels = [];
    foreach ($db->select('cars_settings', ['id', 'setting_label'], ['setting_type' => 'amenity']) as $row) {
        $amenityLabels[$row['id']] = $row['setting_label'];
    }
    $unlimitedMileageAmenityId = array_search('Unlimited Mileage', $amenityLabels);
    $airConditioningAmenityId = array_search('Air Conditioning', $amenityLabels);

    $results = [];
    foreach ($all_cars as $car) {
        $routes = json_decode($car['routes'] ?? '[]', true);
        if (!is_array($routes)) continue;

        $matching_route = null;
        foreach ($routes as $route) {
            $pickup_match = empty($pickup_location) || in_array($route['from_location_id'], $pickup_ids);
            $dropoff_match = empty($dropoff_location) || in_array($route['to_location_id'], $dropoff_ids);

            if ($pickup_match && $dropoff_match) {
                $matching_route = $route;
                break;
            }
        }

        if (!$matching_route) continue;

        $basePrice = (float)($matching_route['price'] ?? 0);
        $routeCurrency = $matching_route['currency'] ?: 'USD';

        if ($basePrice <= 0) continue;

        // Apply markup and currency conversion
        if (function_exists('MARKUP')) {
            $price_markup = MARKUP($basePrice, $module, $db, $routeCurrency, $sessionCurrency);
        } else {
            $price_markup = ['price' => $basePrice, 'currency' => $sessionCurrency];
        }

        $price_per_day = $price_markup['price'] / $rental_days;
        $actualPrice = round((float)($price_markup['converted_base_price'] ?? $basePrice), 2);
        $actualPerDay = round($actualPrice / max(1, $rental_days), 2);
        $markupAmount = round((float)($price_markup['markup'] ?? max(0, $price_markup['price'] - $actualPrice)), 2);

        // Parse images
        $carImg = root . 'uploads/no_img.jpg';
        if (!empty($car['img'])) {
            $imgData = json_decode($car['img'], true);
            if (is_array($imgData) && isset($imgData[0]['url'])) {
                $carImg = root . '/' . ltrim($imgData[0]['url'], '/');
            } else {
                $carImg = root . '/' . ltrim($car['img'], '/');
            }
        }

        $carAmenityIds = json_decode($car['amenities'] ?? '[]', true);
        if (!is_array($carAmenityIds)) {
            $carAmenityIds = [];
        }

        $results[] = [
            'vehicle_id' => $car['id'],
            'reference_id' => $car['id'],
            'name' => $car['name'],
            'category' => $carTypeLabels[$car['car_type_id']] ?? 'Local Car',
            'img' => $carImg,
            'image' => $carImg,
            'vendor' => 'Local Provider',
            'vendor_code' => 'LOCAL',
            'transmission' => $car['transmission'] ?? 'Automatic',
            'fuel_type' => $car['fuel_type'] ?? 'Petrol',
            'passengers' => $car['passengers'] ?? 4,
            'baggage' => $car['baggage'] ?? 2,
            'doors' => $car['doors'] ?? 4,
            'air_conditioning' => $airConditioningAmenityId !== false && in_array($airConditioningAmenityId, $carAmenityIds),
            'unlimited_mileage' => $unlimitedMileageAmenityId !== false && in_array($unlimitedMileageAmenityId, $carAmenityIds),
            'display_price' => round($price_markup['price'], 2),
            'display_price_per_day' => round($price_per_day, 2),
            'actual_price' => $actualPrice,
            'actual_price_per_day' => $actualPerDay,
            'actual_price_details' => $price_markup,
            'markup_amount' => $markupAmount,
            'currency' => $sessionCurrency,
            'rental_days' => $rental_days,
            'supplier' => 'cars',
            'supplier_name' => 'Cars',
            'supplier_id' => $car['id'],
            'color' => '#2563eb'
        ];
    }

    // ========================================
    // SORT AND PAGINATE
    // ========================================
    usort($results, function($a, $b) {
        return $a['display_price'] <=> $b['display_price'];
    });

    $totalResults = count($results);
    $totalPages = ceil($totalResults / $per_page);
    $offset = ($page - 1) * $per_page;
    $paginated = array_slice($results, $offset, $per_page);

    // ========================================
    // JSON RESPONSE
    // ========================================
    ob_end_clean();
    header('Content-Type: application/json');
    header('X-Total-Results: ' . $totalResults);
    header('X-Total-Pages: ' . $totalPages);


    echo json_encode(['status' => 'success', 'data' => $paginated]);
    exit;
    } catch (Exception $e) {
        ob_end_clean();
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage(),
            'supplier_error' => [
                'supplier' => 'cars',
                'message' => $e->getMessage()
            ],
            'raw_response' => $supplierRawResponse,
            'data' => []
        ]);
        exit;
    }
});
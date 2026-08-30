<?php
// ============================================================================
// FILE: app/routes/stays/destination-suggestion.php
// ============================================================================

// ========================================================= hotels-destination-suggestion
// Searches both locations (cities) AND hotels (stays table + API module tables)
$router->post('/hotels-destination-suggestion', function () use ($SECURE, $db) {
    while (ob_get_level()) {
        ob_end_clean();
    }

    // CORS
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (str_starts_with($origin, 'http://localhost') || str_starts_with($origin, 'http://127.0.0.1')) {
        header('Access-Control-Allow-Origin: ' . $origin);
    } else {
        header('Access-Control-Allow-Origin: *');
    }
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    $query = isset($_POST['query']) ? trim($_POST['query']) : '';

    if (empty($query) || strlen($query) < 2) {
        echo json_encode([]);
        exit(0);
    }

    $hotelResults    = [];
    $locationResults = [];

    // ============================================================================
    // API MODULE TABLE MAPPING
    // Har module ke liye table aur columns define karo
    // ============================================================================
    $apiModuleTables = [
        'hotelbeds' => [
            'table'    => 'hotelbeds_hotels',
            'id_col'   => 'hotel_code',
            'name_col' => 'name',
            'city_col' => 'city',
        ],
        // Future modules yahan add ho sakte hain:
        // 'juniper' => [
        //     'table'    => 'juniper_hotels',
        //     'id_col'   => 'hotel_id',
        //     'name_col' => 'hotel_name',
        //     'city_col' => 'city_name',
        // ],
    ];

    // ============================================================================
    // SEARCH 1: HOTELS FROM STAYS TABLE (local hotels)
    // ============================================================================
    try {
        $hotels = $db->select(
            'stays',
            ['id', 'name', 'location'],
            [
                'name[~]' => '%' . $query . '%',
                'ORDER'   => ['name' => 'ASC'],
                'LIMIT'   => 10
            ]
        );

        if ($hotels && is_array($hotels)) {
            foreach ($hotels as $hotel) {
                $hotelResults[] = [
                    'id'          => $hotel['id'],
                    'type'        => 'hotel',
                    'lc'          => 'hotel',
                    'name'        => $hotel['name'],
                    'airportname' => $hotel['name'],
                    'cityname'    => $hotel['location'] ?? '',
                    'countryname' => '',
                    'supplier'    => 'hotels',
                    'is_hotel'    => true
                ];
            }
        }
    } catch (Exception $e) {
        error_log('Stays hotels search error: ' . $e->getMessage());
    }

    // ============================================================================
    // SEARCH 2: LOCATIONS (CITIES)
    // ============================================================================
    try {
        $locations = $db->select(
            'locations',
            ['id', 'country', 'country_code', 'city', 'latitude', 'longitude'],
            [
                'OR' => [
                    'city[~]'    => '%' . $query . '%',
                    'country[~]' => '%' . $query . '%'
                ],
                'status' => 1,
                'ORDER'  => [
                    'country' => 'ASC',
                    'city'    => 'ASC'
                ],
                'LIMIT'  => 10
            ]
        );

        if ($locations && is_array($locations)) {
            foreach ($locations as $location) {
                $locationResults[] = [
                    'id'          => $location['id'],
                    'type'        => 'location',
                    'lc'          => 'city',
                    'name'        => $location['city'],
                    'airportname' => $location['city'],
                    'cityname'    => $location['city'],
                    'countryname' => $location['country'],
                    'countrycode' => $location['country_code'],
                    'is_hotel'    => false
                ];
            }
        }
    } catch (Exception $e) {
        error_log('Locations search error: ' . $e->getMessage());
    }

    // ============================================================================
    // SEARCH 3: HOTELS FROM ACTIVE API MODULES
    // Active modules modules table se Medoo ke through fetch hote hain
    // ============================================================================
    try {
        $activeModules = $db->select(
            'modules',
            ['name', 'host', 'database', 'username', 'password'],
            [
                'name'   => array_keys($apiModuleTables),
                'type'   => 'stays',
                'status' => 1
            ]
        );

        if ($activeModules && is_array($activeModules)) {
            foreach ($activeModules as $module) {
                $moduleName = $module['name'] ?? '';

                // Sirf wo modules jinke liye table mapping defined hai
                if (!isset($apiModuleTables[$moduleName])) {
                    continue;
                }

                if (empty($module['host']) || empty($module['database']) || empty($module['username'])) {
                    error_log(ucfirst($moduleName) . ' search skipped: database configuration is incomplete');
                    continue;
                }

                $tableConfig = $apiModuleTables[$moduleName];
                $table       = $tableConfig['table'];
                $idCol       = $tableConfig['id_col'];
                $nameCol     = $tableConfig['name_col'];
                $cityCol     = $tableConfig['city_col'];

                try {
                    // Short connect timeout — without this, a slow/unreachable Hotelbeds
                    // DB host blocks autocomplete for ~30s (default MySQL TCP wait).
                    $dsn = sprintf(
                        'mysql:host=%s;dbname=%s;charset=utf8mb4',
                        $module['host'],
                        $module['database']
                    );
                    $modulePdo = new \PDO($dsn, $module['username'], $module['password'] ?? '', [
                        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                        \PDO::ATTR_TIMEOUT => 3,
                    ]);
                    $moduleDb = new \Medoo\Medoo([
                        'type' => 'mysql',
                        'pdo' => $modulePdo,
                    ]);

                    $moduleHotels = $moduleDb->select(
                        $table,
                        [$idCol, $nameCol, $cityCol],
                        [
                            $nameCol . '[~]' => $query . '%',
                            'ORDER'          => [$nameCol => 'ASC'],
                            'LIMIT'          => 10
                        ]
                    );

                    if ($moduleHotels && is_array($moduleHotels)) {
                        foreach ($moduleHotels as $hotel) {
                            $hotelResults[] = [
                                'id'          => $hotel[$idCol],
                                'type'        => 'hotel',
                                'lc'          => 'hotel',
                                'name'        => $hotel[$nameCol],
                                'airportname' => $hotel[$nameCol],
                                'cityname'    => $hotel[$cityCol] ?? '',
                                'countryname' => '',
                                'supplier'    => $moduleName,
                                'is_hotel'    => true
                            ];
                        }
                    }
                } catch (Exception $moduleEx) {
                    error_log('Module DB search error [' . $moduleName . ']: ' . $moduleEx->getMessage());
                }
            }
        }
    } catch (Exception $e) {
        error_log('Active modules fetch error: ' . $e->getMessage());
    }

    // ============================================================================
    // MERGE RESULTS: CITIES FIRST, THEN HOTELS
    // ============================================================================
    $formattedResults = array_merge($locationResults, $hotelResults);

    // ============================================================================
    // WEBHOOK: Destination Searched
    // ============================================================================
    triggerWebhook('stays/destination-suggestion', 'stays.destination.searched', [
        'query'         => $query,
        'results_count' => count($formattedResults),
        'hotels_found'  => count($hotelResults),
        'cities_found'  => count($locationResults),
        'timestamp'     => date('Y-m-d H:i:s'),
        'user_id'       => $_SESSION['user_id'] ?? null,
        'session_id'    => session_id()
    ]);

    echo json_encode($formattedResults);
    exit(0);
});


// ========================================================= stays/destination-context
// Remembers the country of the destination the guest picked from the suggestions.
//
// City names are not unique worldwide — Bali is an island in Indonesia and a town
// in Rajasthan, Syracuse is in both Italy and the USA — so the supplier lookup
// needs to know which one was meant. Keeping it in the session rather than in the
// URL leaves the SEO path untouched (/stays/bali/25-11-2026/...).
//
// The country is stored against the destination slug it was chosen for, so it can
// never leak into a later search for a different city.
$router->post('/stays/destination-context', function () use ($SECURE, $db) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $destination = strtolower(trim((string) ($input['destination'] ?? '')));
    $country = strtoupper(trim((string) ($input['country'] ?? '')));

    if ($destination === '' || !preg_match('/^[A-Z]{2,3}$/', $country)) {
        // Nothing usable — forget any previous pick so it cannot be misapplied.
        unset($_SESSION['hotel_destination_country'], $_SESSION['hotel_destination_country_for']);
        echo json_encode(['success' => true, 'stored' => false]);
        exit;
    }

    $_SESSION['hotel_destination_country'] = $country;
    $_SESSION['hotel_destination_country_for'] = $destination;

    echo json_encode(['success' => true, 'stored' => true]);
    exit;
});

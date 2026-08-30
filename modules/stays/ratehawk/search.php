<?php
// ============================================================================
// RATEHAWK HOTEL SEARCH - LIVE API INTEGRATION
// ============================================================================
// ENDPOINT: POST /stays/ratehawk/search
// Fast path:
//   - Sandbox: skip invalid region SERP, parallel hotel-ID batches (one wave)
//   - Production: region SERP when possible, else parallel hotel-ID batches
//   - File cache so page 2+ is instant
// ============================================================================

$router->post('stays/ratehawk/search', function () use ($db) {
    @set_time_limit(45);
    $connectTimeout = 5;
    $requestTimeout = 12; // hard cap per request — never wait 25s × N
    $searchSessionData = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $searchSessionData = $_SESSION;
        session_write_close();
    }

    if (connection_aborted()) {
        exit;
    }

    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    header('Content-Type: application/json');

    $ratehawkApiPost = function ($url, $requestBody, $authString, $connectTimeout, $requestTimeout, $logAction) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestBody),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Basic ' . $authString
            ],
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (function_exists('ratehawk_log')) {
            ratehawk_log('search', $logAction, $requestBody, $response, '', [
                'url' => $url,
                'method' => 'POST',
                'headers' => [
                    'Content-Type: application/json',
                    'Authorization: Basic ***'
                ],
                'response_headers' => ''
            ]);
        }

        return [$response, $httpCode, $curlError];
    };

    // Run several hotel-ID SERP calls in parallel (one wave)
    $ratehawkApiPostMulti = function ($url, array $requests, $authString, $connectTimeout, $requestTimeout) {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($requests as $idx => $requestBody) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($requestBody),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Basic ' . $authString
                ],
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_TIMEOUT => $requestTimeout,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$idx] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $idx => $ch) {
            $response = curl_multi_getcontent($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if (function_exists('ratehawk_log')) {
                ratehawk_log('search', 'serp_hotels', $requests[$idx], $response, '', [
                    'url' => $url,
                    'method' => 'POST',
                    'headers' => [
                        'Content-Type: application/json',
                        'Authorization: Basic ***'
                    ],
                    'response_headers' => ''
                ]);
            }

            $results[$idx] = [$response, $httpCode, $curlError];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        return $results;
    };

    $parseRatehawkError = function ($httpCode, $apiResponse) {
        $error = is_array($apiResponse) ? ($apiResponse['error'] ?? null) : null;
        $errorMsg = 'Unknown error';
        if (is_string($error) && $error !== '') {
            $errorMsg = $error;
        } elseif (is_array($error)) {
            $errorMsg = $error['message'] ?? json_encode($error);
        }
        if (is_array($apiResponse) && !empty($apiResponse['debug']['validation_error'])) {
            $errorMsg .= ': ' . $apiResponse['debug']['validation_error'];
        }
        if ($httpCode !== 200 && $errorMsg === 'Unknown error') {
            return 'API returned HTTP ' . $httpCode;
        }
        return 'API Error: ' . $errorMsg;
    };

    $formatHotels = function ($hotels, $ratehawkDb, $module, $db, $moduleCurrency, $sessionCurrency, $hotel_name, $nights = 1) {
        $formatted = [];
        $nights = max(1, (int) $nights);
        $apiHotelIds = array_values(array_filter(array_column($hotels, 'id')));
        $hotelDetailsMap = [];

        if (!empty($apiHotelIds)) {
            foreach (array_chunk($apiHotelIds, 500) as $idChunk) {
                $hotelRows = $ratehawkDb->select('ratehawk_hotels', [
                    'hotel_id', 'name', 'address', 'city', 'country',
                    'star_rating', 'rating', 'latitude', 'longitude', 'images'
                ], [
                    'hotel_id' => $idChunk
                ]);
                foreach ($hotelRows as $row) {
                    $hotelDetailsMap[$row['hotel_id']] = $row;
                }
            }
        }

        foreach ($hotels as $hotel) {
            $rates = $hotel['rates'] ?? [];
            if (empty($rates)) {
                continue;
            }

            $hotelId = $hotel['id'] ?? '';
            $hotelDetails = $hotelDetailsMap[$hotelId] ?? null;
            if (!$hotelDetails) {
                continue;
            }

            if ($hotel_name !== '') {
                $haystack = strtolower(($hotelDetails['name'] ?? '') . ' ' . $hotelId);
                if (strpos($haystack, strtolower($hotel_name)) === false) {
                    continue;
                }
            }

            $cheapestRate = $rates[0];
            $paymentType = $cheapestRate['payment_options']['payment_types'][0] ?? [];
            $basePrice = (float)($paymentType['show_amount'] ?? $paymentType['amount'] ?? 0);
            $apiCurrency = strtoupper((string)(
                $paymentType['show_currency_code']
                ?? $paymentType['currency_code']
                ?? $moduleCurrency
            ));

            if ($basePrice <= 0) {
                continue;
            }

            $images = [];
            if (!empty($hotelDetails['images'])) {
                $imagesArray = is_array($hotelDetails['images'])
                    ? $hotelDetails['images']
                    : json_decode($hotelDetails['images'], true);
                if (is_array($imagesArray)) {
                    $images = array_map(function ($url) {
                        return str_replace('{size}', '640x400', $url);
                    }, array_slice($imagesArray, 0, 3));
                }
            }

            $location = array_filter([
                $hotelDetails['city'] ?? '',
                $hotelDetails['country'] ?? ''
            ]);

            $finalPrice = $basePrice;
            try {
                $markupResult = MARKUP($basePrice, $module, $db, $apiCurrency, $sessionCurrency);
                if (isset($markupResult['price']) && $markupResult['price'] > 0) {
                    $finalPrice = $markupResult['price'];
                }
            } catch (Exception $e) {
                // keep base
            }

            // show_amount is stay total — match rooms.php (price_per_night = total ÷ nights)
            $pricePerNight = $nights > 1 ? round($finalPrice / $nights, 2) : round($finalPrice, 2);

            $formatted[] = [
                'id' => $hotelId,
                'name' => $hotelDetails['name'] ?? 'Hotel',
                'stars' => $hotelDetails['star_rating'] ?? 0,
                'star_rating' => $hotelDetails['star_rating'] ?? 0,
                'rating' => $hotelDetails['rating'] ?? 0,
                'address' => $hotelDetails['address'] ?? '',
                'city' => $hotelDetails['city'] ?? '',
                'country' => $hotelDetails['country'] ?? '',
                'location' => implode(', ', $location),
                'latitude' => $hotelDetails['latitude'] ?? null,
                'longitude' => $hotelDetails['longitude'] ?? null,
                'images' => $images,
                'image' => !empty($images) ? $images[0] : null,
                'price' => round($finalPrice, 2),
                'price_per_night' => $pricePerNight,
                'display_price' => round($finalPrice, 2),
                'display_price_per_night' => $pricePerNight,
                'original_price' => round($basePrice, 2),
                'currency' => $sessionCurrency,
                'original_currency' => $apiCurrency,
                'rates_count' => count($rates),
                'meal' => $cheapestRate['meal'] ?? 'nomeal',
                'has_available_rooms' => true,
                'supplier' => 'RATEHAWK',
                'original_id' => $hotelId,
                'hotel_id' => $hotelId,
                'nights' => $nights,
            ];
        }

        return $formatted;
    };

    try {
        $destination = $_POST['destination'] ?? $_POST['city'] ?? '';
        $checkin = $_POST['checkin'] ?? '';
        $checkout = $_POST['checkout'] ?? '';
        $rooms = (int)($_POST['rooms'] ?? 1);
        $adults = (int)($_POST['adults'] ?? 2);
        $children = (int)($_POST['children'] ?? 0);
        $childAges = $_POST['child_ages'] ?? [];
        $nationality = $_POST['nationality'] ?? $_POST['residency'] ?? 'US';
        $currency = $_POST['currency'] ?? $searchSessionData['app_currency'] ?? 'USD';
        $sessionCurrency = isset($searchSessionData['app_currency']) ? $searchSessionData['app_currency'] : $currency;
        $page = max(1, (int)($_POST['page'] ?? 1));
        $perPage = min(50, max(1, (int)($_POST['per_page'] ?? 20)));
        $hotel_name = trim($_POST['hotel_name'] ?? '');

        // Prefer per-room config from UI (rooms_data) — flat adults/children are totals only
        $roomsDataRaw = $_POST['rooms_data'] ?? '';
        if (is_string($roomsDataRaw) && $roomsDataRaw !== '') {
            $decodedRooms = json_decode($roomsDataRaw, true);
        } elseif (is_array($roomsDataRaw)) {
            $decodedRooms = $roomsDataRaw;
        } else {
            $decodedRooms = null;
        }
        if (!is_array($decodedRooms) || empty($decodedRooms)) {
            $decodedRooms = $_SESSION['hotel_rooms_data'] ?? null;
        }
        if (!is_array($decodedRooms) || empty($decodedRooms)) {
            $decodedRooms = [];
            for ($i = 0; $i < max(1, $rooms); $i++) {
                $decodedRooms[] = [
                    'adults' => max(1, $adults),
                    'children' => max(0, $children),
                    'childAges' => is_array($childAges) ? array_map('intval', $childAges) : []
                ];
            }
        }

        if (empty($destination) || empty($checkin) || empty($checkout)) {
            throw new Exception('Missing required parameters: destination, checkin, checkout');
        }

        $checkinFormatted = $checkin;
        $checkoutFormatted = $checkout;
        if (strpos($checkin, '-') !== false) {
            $parts = explode('-', $checkin);
            if (count($parts) === 3 && strlen($parts[0]) <= 2) {
                $checkinFormatted = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
            }
        }
        if (strpos($checkout, '-') !== false) {
            $parts = explode('-', $checkout);
            if (count($parts) === 3 && strlen($parts[0]) <= 2) {
                $checkoutFormatted = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
            }
        }

        $stayNights = 1;
        try {
            $inDt = new DateTime($checkinFormatted);
            $outDt = new DateTime($checkoutFormatted);
            $stayNights = max(1, (int) $inDt->diff($outDt)->days);
        } catch (Exception $e) {
            $stayNights = 1;
        }

        $module = $db->get('modules', '*', [
            'name' => 'ratehawk',
            'type' => 'stays'
        ]);

        if (!$module || empty($module['c1']) || empty($module['c3'])) {
            throw new Exception('RateHawk module not configured properly');
        }

        $keyId = trim($module['c1']);
        $apiKey = trim($module['c3']);
        $baseUrl = rtrim(trim($module['c4'] ?? ''), '/');
        $moduleCurrency = !empty($module['currency']) ? $module['currency'] : 'USD';

        if (substr($baseUrl, -8) !== '/v3') {
            if (stripos($baseUrl, '/api/b2b/v3') === false) {
                $baseUrl .= '/api/b2b/v3';
            }
        }

        $isSandbox = (stripos($baseUrl, 'sandbox') !== false);
        // Sandbox region SERP only allows these IDs
        $sandboxRegionWhitelist = [2011, 2395, 2734, 6053839];

        $ratehawkDb = new \Medoo\Medoo([
            'type'     => 'mysql',
            'host'     => $module['host'] ?? 'localhost',
            'database' => $module['database'],
            'username' => $module['username'],
            'password' => $module['password'],
            'charset'  => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        if (is_numeric($destination)) {
            $regionId = (int)$destination;
        } else {
            $hotel = $ratehawkDb->get('ratehawk_hotels', 'region_id', [
                'city[~]' => $destination . '%',
                'LIMIT' => 1
            ]);
            if (!$hotel) {
                echo json_encode([
                    'status' => 'error',
                    'message' => "No hotels found for destination: $destination",
                    'results' => [],
                    'has_more' => false
                ]);
                exit;
            }
            $regionId = (int)$hotel;
        }

        // RateHawk guests[] = one object per room; children = array of ages
        $guests = [];
        $adults = 0;
        $children = 0;
        foreach ($decodedRooms as $room) {
            $roomAdults = max(1, (int)($room['adults'] ?? 2));
            $roomChildren = max(0, (int)($room['children'] ?? 0));
            $ages = $room['childAges'] ?? $room['children_ages'] ?? $room['child_ages'] ?? [];
            if (!is_array($ages)) {
                $ages = [];
            }
            $ages = array_map('intval', array_values($ages));
            while (count($ages) < $roomChildren) {
                $ages[] = 1;
            }
            if (count($ages) > $roomChildren) {
                $ages = array_slice($ages, 0, $roomChildren);
            }

            $guestRoom = ['adults' => $roomAdults];
            if ($roomChildren > 0) {
                $guestRoom['children'] = $ages;
            }
            $guests[] = $guestRoom;
            $adults += $roomAdults;
            $children += $roomChildren;
        }
        $rooms = max(1, count($guests));

        $authString = base64_encode($keyId . ':' . $apiKey);

        $cacheKey = hash('sha256', json_encode([
            'region_id' => $regionId,
            'checkin' => $checkinFormatted,
            'checkout' => $checkoutFormatted,
            'guests' => $guests,
            'nationality' => strtolower($nationality),
            'module_currency' => $moduleCurrency,
            'session_currency' => $sessionCurrency,
            'hotel_name' => strtolower($hotel_name),
            'v' => 3, // rooms_data / per-room children ages
        ]));

        $cacheDir = __DIR__ . '/cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        $cacheFile = $cacheDir . '/search_' . $cacheKey . '.json';
        $cacheTtl = 600;

        $allResults = null;
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['results']) && is_array($cached['results'])) {
                $allResults = $cached['results'];
            }
        }

        if ($allResults === null) {
            $allResults = [];
            $source = 'hotels';
            $baseRequest = [
                'checkin' => $checkinFormatted,
                'checkout' => $checkoutFormatted,
                'residency' => strtolower($nationality),
                'language' => 'en',
                'guests' => $guests,
                'currency' => $moduleCurrency,
                'timeout' => 8, // ask RateHawk to respond faster
            ];

            $canUseRegion = !$isSandbox || in_array($regionId, $sandboxRegionWhitelist, true);

            // 1) Region SERP only when allowed (skip Baku/etc on sandbox — saves ~fail+timeout)
            if ($canUseRegion) {
                $regionRequest = $baseRequest + ['region_id' => $regionId];
                [$response, $httpCode, $curlError] = $ratehawkApiPost(
                    $baseUrl . '/search/serp/region/',
                    $regionRequest,
                    $authString,
                    $connectTimeout,
                    $requestTimeout,
                    'serp_region'
                );

                if (!$curlError) {
                    $apiResponse = json_decode((string)$response, true);
                    if ($httpCode === 200 && is_array($apiResponse) && empty($apiResponse['error'])) {
                        $hotels = $apiResponse['data']['hotels'] ?? [];
                        $allResults = $formatHotels($hotels, $ratehawkDb, $module, $db, $moduleCurrency, $sessionCurrency, $hotel_name, $stayNights);
                        $source = 'region';
                    }
                }
            }

            // 2) Parallel hotel-ID wave (ONE wave only — no 6× sequential waits)
            if ($source !== 'region' || empty($allResults)) {
                $hotelFilter = ['region_id' => $regionId];
                if ($hotel_name !== '') {
                    $hotelFilter['OR'] = [
                        'name[~]' => '%' . $hotel_name . '%',
                        'hotel_id' => $hotel_name
                    ];
                }

                $batchSize = 40;
                $parallelBatches = 3; // 120 hotel IDs in ~one round-trip time
                $requests = [];

                for ($batch = 0; $batch < $parallelBatches; $batch++) {
                    $hotelIds = $ratehawkDb->select('ratehawk_hotels', 'hotel_id', array_merge($hotelFilter, [
                        'LIMIT' => [$batch * $batchSize, $batchSize]
                    ]));
                    if (empty($hotelIds)) {
                        break;
                    }
                    $requests[] = $baseRequest + ['ids' => array_values($hotelIds)];
                }

                if (!empty($requests)) {
                    $multiResults = $ratehawkApiPostMulti(
                        $baseUrl . '/search/serp/hotels/',
                        $requests,
                        $authString,
                        $connectTimeout,
                        $requestTimeout
                    );

                    $seenIds = [];
                    $merged = [];
                    foreach ($multiResults as $idx => $tuple) {
                        [$response, $httpCode, $curlError] = $tuple;
                        if ($curlError) {
                            continue;
                        }
                        $apiResponse = json_decode((string)$response, true);
                        if ($httpCode !== 200 || !is_array($apiResponse) || !empty($apiResponse['error'])) {
                            continue;
                        }
                        $batchHotels = $formatHotels(
                            $apiResponse['data']['hotels'] ?? [],
                            $ratehawkDb,
                            $module,
                            $db,
                            $moduleCurrency,
                            $sessionCurrency,
                            $hotel_name,
                            $stayNights
                        );
                        foreach ($batchHotels as $item) {
                            $id = $item['id'] ?? '';
                            if ($id === '' || isset($seenIds[$id])) {
                                continue;
                            }
                            $seenIds[$id] = true;
                            $merged[] = $item;
                        }
                    }

                    if (!empty($merged)) {
                        $allResults = $merged;
                        $source = 'hotels';
                    } elseif ($source !== 'region') {
                        $allResults = [];
                        $source = 'hotels';
                    }
                }
            }

            usort($allResults, function ($a, $b) {
                return ($a['price'] <=> $b['price']);
            });

            @file_put_contents($cacheFile, json_encode([
                'created_at' => time(),
                'results' => $allResults,
                'source' => $source
            ]));
        }

        $totalCount = count($allResults);
        $totalPages = $totalCount > 0 ? (int)ceil($totalCount / $perPage) : 0;
        $offset = ($page - 1) * $perPage;
        $pageResults = array_slice($allResults, $offset, $perPage);
        $hasMore = ($offset + $perPage) < $totalCount;

        header('X-Total-Results: ' . $totalCount);
        header('X-Destination-Total: ' . $totalCount);
        header('X-Grand-Total: ' . $totalCount);
        header('X-Total-Pages: ' . $totalPages);
        header('X-Current-Page: ' . $page);
        header('X-Per-Page: ' . $perPage);
        header('X-Has-More: ' . ($hasMore ? 'true' : 'false'));

        echo json_encode([
            'status' => 'success',
            'results' => array_values($pageResults),
            'total' => $totalCount,
            // Fixed "N stays found" figure: every stay this supplier has for the
            // destination. Never changes between pages of the same search.
            'destination_total' => $totalCount,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'has_more' => $hasMore,
            'search_params' => [
                'checkin' => $checkinFormatted,
                'checkout' => $checkoutFormatted,
                'rooms' => $rooms,
                'adults' => $adults,
                'children' => $children,
                'guests' => $guests,
                'region_id' => $regionId
            ]
        ]);

    } catch (Exception $e) {
        $message = $e->getMessage();
        if (preg_match('/SQLSTATE\[|PDO|1045|Access denied|1049|Unknown database|2002|2003|Connection refused/i', $message)) {
            error_log('RateHawk search DB error: ' . $message);
            $message = 'Database connection failed.';
        }
        header('X-Has-More: false');
        echo json_encode([
            'status' => 'error',
            'message' => $message,
            'results' => [],
            'has_more' => false
        ]);
    }

    exit;
});

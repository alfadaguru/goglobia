<?php
// AMADEUS HOTEL SEARCH - PRODUCTION VERSION

$router->post('stays/amadeus/search', function() use ($db) {
    // search_guard_v2: session lock release + configurable timeouts for long supplier requests
    @set_time_limit(30);
    $timeoutConfig = function_exists('supplier_timeout_config') ? supplier_timeout_config() : [
        'connect' => defined('SUPPLIER_CONNECT_TIMEOUT') ? (int) SUPPLIER_CONNECT_TIMEOUT : 10,
        'request' => defined('SUPPLIER_REQUEST_TIMEOUT') ? (int) SUPPLIER_REQUEST_TIMEOUT : 30,
    ];
    $connectTimeout = $timeoutConfig['connect'];
    $requestTimeout = $timeoutConfig['request'];
    $searchSessionData = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $searchSessionData = $_SESSION;
        session_write_close();
        error_log('search_guard: session lock released');
    }

    if (connection_aborted()) {
        error_log('search_guard: request aborted early');
        exit;
    }

    set_time_limit(45);
    while (ob_get_level()) ob_end_clean();

    $start = microtime(true);
    $logsPath = __DIR__ . '/logs';

    try {
        // Extract parameters
        $destination = trim($_POST['destination'] ?? '');
        $checkin = $_POST['checkin'] ?? '';
        $checkout = $_POST['checkout'] ?? '';
        $adults = (int)($_POST['adults'] ?? 2);
        $currency = $searchSessionData['app_currency'] ?? 'USD';
        $hotel_name = trim($_POST['hotel_name'] ?? '');

        // Format dates (DD-MM-YYYY to YYYY-MM-DD)
        $checkinParts = explode('-', $checkin);
        $checkoutParts = explode('-', $checkout);
        $checkinISO = "{$checkinParts[2]}-{$checkinParts[1]}-{$checkinParts[0]}";
        $checkoutISO = "{$checkoutParts[2]}-{$checkoutParts[1]}-{$checkoutParts[0]}";
        $nights = max(1, (new DateTime($checkinISO))->diff(new DateTime($checkoutISO))->days);

        // Get module config
        $module = $db->get('modules', '*', ['name' => 'amadeus', 'type' => 'stays']);
        if (!$module) throw new Exception('Module not configured');

        $apiKey = $module['c1'];
        $apiSecret = $module['c2'];
        $env = strtolower(trim((string)($module['env'] ?? 'production')));
        $baseUrl = in_array($env, ['live', 'production', 'pro'], true)
            ? 'https://api.amadeus.com'
            : 'https://test.api.amadeus.com';

        // 1. Get OAuth2 token
        $ch = curl_init($baseUrl . '/v1/security/oauth2/token');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => $apiKey,
                'client_secret' => $apiSecret
            ]),
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
        ]);
        $tokenResp = curl_exec($ch);
        $tokenData = json_decode($tokenResp, true);
        if (!isset($tokenData['access_token'])) throw new Exception('Token request failed');
        $token = $tokenData['access_token'];

        // 2. Get city code from flights_airports table
        $cityCode = null;

        // Try exact match first
        $airport = $db->get('flights_airports', 'code', [
            'city' => $destination,
            'LIMIT' => 1
        ]);

        if ($airport) {
            $cityCode = $airport;
        } else {
            // Try case-insensitive LIKE search
            $airport = $db->get('flights_airports', 'code', [
                'city[~]' => $destination,
                'LIMIT' => 1
            ]);

            if ($airport) {
                $cityCode = $airport;
            } else {
                // Try searching in name column as fallback
                $airport = $db->get('flights_airports', 'code', [
                    'name[~]' => $destination,
                    'LIMIT' => 1
                ]);

                if ($airport) {
                    $cityCode = $airport;
                }
            }
        }

        // If still no city code found, return empty result
        if (!$cityCode) {
            header('Content-Type: application/json');
            header('X-Total-Results: 0');
            header('X-Destination-Total: 0');
            header('X-Execution-Time: ' . round(microtime(true) - $start, 2));
            echo json_encode([]);
            return;
        }

        // 3. Get hotel IDs (up to 100 hotels, 50km radius)
        $ch = curl_init($baseUrl . '/v1/reference-data/locations/hotels/by-city?' . http_build_query([
            'cityCode' => $cityCode,
            'radius' => 50,
            'radiusUnit' => 'KM'
        ]));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]
        ]);
        $listResp = curl_exec($ch);
        $listData = json_decode($listResp, true);

        $hotelIds = [];
        foreach (array_slice($listData['data'] ?? [], 0, 100) as $h) {
            if (isset($h['hotelId'])) $hotelIds[] = $h['hotelId'];
        }

        if (!$hotelIds) {
            // Log error - no hotels found
            @mkdir($logsPath, 0755, true);
            @file_put_contents($logsPath . '/Error_NoHotels_' . date('Ymd_His') . '.json',
                json_encode([
                    'destination' => $destination,
                    'cityCode' => $cityCode,
                    'response' => $listData
                ], JSON_PRETTY_PRINT));
            throw new Exception('No hotels found for ' . $destination);
        }

        // 4. Get offers in batches of 20 (API limit)
        $allOffers = [];
        foreach (array_chunk($hotelIds, 20) as $batch) {
            $ch = curl_init($baseUrl . '/v3/shopping/hotel-offers?' . http_build_query([
                'hotelIds' => implode(',', $batch),
                'checkInDate' => $checkinISO,
                'checkOutDate' => $checkoutISO,
                'adults' => $adults,
                'currency' => $currency
            ]));
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $requestTimeout,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]
            ]);
            $offersResp = curl_exec($ch);
            $offersData = json_decode($offersResp, true);

            if (isset($offersData['data'])) {
                $allOffers = array_merge($allOffers, $offersData['data']);
            }
        }

        if (!$allOffers) {
            // Log error - no offers found
            @mkdir($logsPath, 0755, true);
            @file_put_contents($logsPath . '/Error_NoOffers_' . date('Ymd_His') . '.json',
                json_encode([
                    'hotelIds' => $hotelIds,
                    'checkin' => $checkinISO,
                    'checkout' => $checkoutISO,
                    'adults' => $adults
                ], JSON_PRETTY_PRINT));
            throw new Exception('No available offers for selected dates');
        }

        // 5. Format response with hotel images
        $hotels = [];
        foreach ($allOffers as $item) {
            $hotel = $item['hotel'] ?? [];
            $offers = $item['offers'] ?? [];
            if (!$offers) continue;

            $firstOffer = $offers[0];
            $price = floatval($firstOffer['price']['total'] ?? 0);
            $pricePerNight = $price / $nights;

            // Apply markup
            $markedPrice = MARKUP($pricePerNight, $module, $db, $firstOffer['price']['currency'] ?? 'EUR', $currency);
            $totalPrice = $markedPrice['price'] * $nights;

            // Get hotel image from media if available
            $hotelImage = 'https://via.placeholder.com/400x300?text=No+Image';
            $hotelImages = [];

            if (!empty($hotel['media'])) {
                foreach ($hotel['media'] as $media) {
                    if (isset($media['uri'])) {
                        $hotelImages[] = $media['uri'];
                    }
                }
                if ($hotelImages) {
                    $hotelImage = $hotelImages[0];
                }
            }

            $hotels[] = [
                'hotel_id' => $hotel['hotelId'] ?? '',
                'name' => $hotel['name'] ?? 'Hotel',
                'img' => $hotelImage,
                'images' => $hotelImages ?: [$hotelImage],
                'location' => $hotel['address']['cityName'] ?? $destination,
                'address' => $hotel['address']['lines'][0] ?? '',
                'stars' => (int)($hotel['rating'] ?? 3),
                'rating' => (float)($hotel['rating'] ?? 3),
                'latitude' => (float)($hotel['latitude'] ?? 0),
                'longitude' => (float)($hotel['longitude'] ?? 0),
                'display_price' => round($totalPrice, 2),
                'display_price_per_night' => round($markedPrice['price'], 2),
                'actual_price' => round($totalPrice, 2),
                'actual_price_per_night' => round($markedPrice['price'], 2),
                'currency' => $currency,
                'original_currency' => $firstOffer['price']['currency'] ?? 'EUR',
                'amenities' => $hotel['amenities'] ?? [],
                'room_options' => [[
                    'room_name' => $firstOffer['room']['typeEstimated']['category'] ?? 'Standard Room',
                    'min_price_per_night' => round($markedPrice['price'], 2),
                    'options' => [[
                        'board_name' => $firstOffer['boardType'] ?? 'Room Only',
                        'price_per_night' => round($markedPrice['price'], 2),
                        'total_price' => round($totalPrice, 2),
                        'nights' => $nights
                    ]]
                ]],
                'supplier' => 'amadeus',
                'supplier_id' => $module['id'] ?? '',
                'color' => $module['color'] ?? '#0066CC'
            ];
        }

        if (!empty($hotel_name) && !empty($hotels)) {
            $needle = mb_strtolower($hotel_name);
            $hotels = array_values(array_filter($hotels, static function ($h) use ($needle, $hotel_name) {
                $n = mb_strtolower((string)($h['name'] ?? ''));
                $id = (string)($h['hotel_id'] ?? '');
                return ($n !== '' && str_contains($n, $needle)) || $id === $hotel_name;
            }));
        }

        // Return success response
        header('Content-Type: application/json');
        header('X-Total-Results: ' . count($hotels));
        header('X-Destination-Total: ' . count($hotels));
        header('X-Execution-Time: ' . round(microtime(true) - $start, 2));
        echo json_encode($hotels);

    } catch (Exception $e) {
        // Log errors only
        @mkdir($logsPath, 0755, true);
        @file_put_contents($logsPath . '/Error_' . date('Ymd_His') . '.txt',
            "[" . date('Y-m-d H:i:s') . "]\n" .
            "Error: " . $e->getMessage() . "\n" .
            "POST Data: " . json_encode($_POST, JSON_PRETTY_PRINT) . "\n" .
            "File: " . $e->getFile() . "\n" .
            "Line: " . $e->getLine() . "\n" .
            "Stack Trace:\n" . $e->getTraceAsString()
        );

        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'error' => true,
            'message' => $e->getMessage()
        ]);
    }
});

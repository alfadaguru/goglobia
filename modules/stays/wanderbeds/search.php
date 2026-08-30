<?php
/**
 * Wanderbeds hotel search
 * POST stays/wanderbeds/search
 * Hybrid: local content DB for hotel metadata + live Search API for rates.
 * Images come only from Hotel Details — lazy-fetched for available hotels missing local images.
 */

require_once __DIR__ . '/api.php';

$router->post('stays/wanderbeds/search', function () use ($db) {

    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    header('Content-Type: application/json');

    try {
        $destination = trim($_POST['destination'] ?? $_POST['city'] ?? '');
        $destination_code = trim($_POST['destination_code'] ?? '');
        $checkin = $_POST['checkin'] ?? '';
        $checkout = $_POST['checkout'] ?? '';
        $rooms = (int) ($_POST['rooms'] ?? 1);
        $adults = (int) ($_POST['adults'] ?? 2);
        $children = (int) ($_POST['children'] ?? $_POST['childs'] ?? 0);
        $requestedNationality = trim((string) ($_POST['nationality'] ?? ''));
        $currency = $_POST['currency'] ?? ($_SESSION['app_currency'] ?? 'USD');
        $sessionCurrency = $_SESSION['app_currency'] ?? $currency;
        $page = max(1, (int) ($_POST['page'] ?? 1));
        $per_page = min(100, max(1, (int) ($_POST['per_page'] ?? 25)));
        $star_rating = $_POST['star_rating'] ?? 'any';

        $rooms_data = [];
        $rooms_data_json = $_POST['rooms_data'] ?? '[]';
        if (is_array($rooms_data_json)) {
            $rooms_data = $rooms_data_json;
        } else {
            $decoded = json_decode((string) $rooms_data_json, true);
            if (is_array($decoded)) {
                $rooms_data = $decoded;
            }
        }
        $childAges = $_POST['child_ages'] ?? [];
        if (is_string($childAges)) {
            $decodedAges = json_decode($childAges, true);
            $childAges = is_array($decodedAges) ? $decodedAges : [];
        }
        if (!is_array($childAges)) {
            $childAges = [];
        }

        if ($destination === '' || $checkin === '' || $checkout === '') {
            throw new Exception('Missing required parameters: destination, checkin, checkout');
        }

        $module = wanderbedsGetModule($db);
        if (!$module || empty($module['c1']) || empty($module['c2'])) {
            throw new Exception('Wanderbeds module is not configured');
        }
        $nationality = wanderbedsResolveNationality($db, $module, $requestedNationality);
        $moduleCurrency = !empty($module['currency']) ? $module['currency'] : 'USD';
        $nights = wanderbedsNights($checkin, $checkout);
        $checkinApi = wanderbedsParseDate($checkin);
        $checkoutApi = wanderbedsParseDate($checkout);
        $wbRooms = wanderbedsBuildRooms($rooms_data, $rooms, $adults, $children, $childAges);

        $contentDb = wanderbedsContentDb($module);

        $cityIds = [];
        if ($destination_code !== '') {
            $cityIds[] = $destination_code;
        }

        $cityRows = $contentDb->select('wb_cities', ['code', 'name', 'country_code'], [
            'OR' => [
                'name[~]' => $destination,
                'code' => $destination,
            ],
            'LIMIT' => 20,
        ]);
        foreach ($cityRows as $city) {
            if (!in_array($city['code'], $cityIds, true)) {
                $cityIds[] = $city['code'];
            }
        }

        if (empty($cityIds)) {
            $hotelCityIds = $contentDb->select('wb_hotels', 'city_id', [
                'OR' => [
                    'city_name[~]' => $destination,
                    'city_id' => $destination,
                ],
                'GROUP' => 'city_id',
                'LIMIT' => 20,
            ]);
            foreach ($hotelCityIds as $code) {
                if ($code && !in_array($code, $cityIds, true)) {
                    $cityIds[] = $code;
                }
            }
        }

        if (empty($cityIds)) {
            header('X-Total-Results: 0');
            header('X-Destination-Total: 0');
            header('X-Total-Pages: 0');
            header('X-Current-Page: ' . $page);
            header('X-Per-Page: ' . $per_page);
            header('X-Has-More: false');
            echo json_encode([]);
            exit;
        }

        $where = ['city_id' => $cityIds];
        if ($star_rating !== 'any' && $star_rating !== '' && is_numeric($star_rating)) {
            $where['star_rating[>=]'] = (float) $star_rating;
            $where['star_rating[<]'] = (float) $star_rating + 1;
        }

        $totalHotels = (int) $contentDb->count('wb_hotels', $where);
        $totalPages = max(1, (int) ceil($totalHotels / $per_page));
        $offset = ($page - 1) * $per_page;

        $hotels = $contentDb->select('wb_hotels', '*', array_merge($where, [
            'ORDER' => ['star_rating' => 'DESC', 'name' => 'ASC'],
            'LIMIT' => [$offset, $per_page],
        ]));

        if (empty($hotels)) {
            header('X-Total-Results: ' . $totalHotels);
            header('X-Destination-Total: ' . $totalHotels);
            header('X-Total-Pages: ' . $totalPages);
            header('X-Current-Page: ' . $page);
            header('X-Per-Page: ' . $per_page);
            header('X-Has-More: ' . ($page < $totalPages ? 'true' : 'false'));
            echo json_encode([]);
            exit;
        }

        $hotelIds = [];
        $hotelMap = [];
        foreach ($hotels as $h) {
            $id = (string) $h['hotel_id'];
            $hotelIds[] = (int) $id;
            $hotelMap[$id] = $h;
        }

        $batches = array_chunk($hotelIds, 100);
        $apiHotelResults = [];
        $searchToken = null;
        $logsEnabled = ((int) log_setting($db, 'wanderbeds')) === 1;
        $searchLogType = 'search_' . preg_replace('/[^A-Za-z0-9_-]/', '', session_id());

        foreach ($batches as $batchIndex => $batch) {
            $payload = [
                'hotels' => $batch,
                'checkin' => $checkinApi,
                'checkout' => $checkoutApi,
                'rooms' => $wbRooms,
                'nationality' => $nationality,
                'timout' => '20',
                'cheapestonly' => true,
            ];

            $result = wanderbedsCall($module, 'hotel/search', $payload, 'POST', 25);
            if (!empty($result['token'])) {
                $searchToken = $result['token'];
            }

            if ($logsEnabled) {
                logApiCall(
                    'SearchBatch' . ($batchIndex + 1),
                    $payload,
                    $result['data'] ?? ['error' => $result['error'] ?? null],
                    (int) ($result['http_code'] ?? 0),
                    __DIR__ . '/logs',
                    $searchLogType
                );
            }

            if (!$result['success']) {
                error_log('Wanderbeds search error: ' . ($result['error'] ?? 'unknown'));
                continue;
            }

            foreach (($result['data']['hotels'] ?? []) as $hotelResult) {
                $code = (string) ($hotelResult['hotelid'] ?? '');
                if ($code !== '') {
                    $apiHotelResults[$code] = $hotelResult;
                }
            }
        }

        // Prefer local content images; otherwise one batched live Hotel Details fetch.
        // Avoid per-hotel serial live calls here — they can exhaust PHP max_execution_time
        // and return a blank listing page.
        $idsNeedingImages = [];
        foreach ($apiHotelResults as $code => $_api) {
            $local = $hotelMap[$code] ?? null;
            if (!$local || !wanderbedsHotelHasImages($local)) {
                $idsNeedingImages[] = $code;
            }
        }

        $liveDetails = [];
        if (!empty($idsNeedingImages)) {
            // Cap to current page size; smaller batches keep search within request timeout.
            $idsNeedingImages = array_slice(array_values(array_unique($idsNeedingImages)), 0, $per_page);
            try {
                $liveDetails = wanderbedsFetchLiveHotelDetails($module, $idsNeedingImages, $contentDb, 10);
                foreach ($liveDetails as $code => $payload) {
                    if (!empty($payload['row']) && is_array($payload['row'])) {
                        $hotelMap[$code] = $payload['row'];
                    }
                }
            } catch (Exception $liveEx) {
                error_log('Wanderbeds live image fetch: ' . $liveEx->getMessage());
            }
        }

        $formatted = [];
        foreach ($hotelMap as $code => $local) {
            $apiHotel = $apiHotelResults[$code] ?? null;
            if (!$apiHotel) {
                continue;
            }

            $apiRooms = $apiHotel['rooms'] ?? [];
            if (!is_array($apiRooms) || empty($apiRooms)) {
                continue;
            }

            $minTotal = null;
            $apiCurrency = $moduleCurrency;
            $roomOptions = [];
            $productId = (string) ($apiHotel['productid'] ?? '');

            foreach ($apiRooms as $idx => $room) {
                $price = $room['price'] ?? [];
                $totalFare = (float) ($price['total'] ?? $price['baseprice'] ?? 0);
                $apiCurrency = (string) ($price['currency'] ?? $apiCurrency);
                if ($minTotal === null || $totalFare < $minTotal) {
                    $minTotal = $totalFare;
                }

                $meal = wanderbedsMealLabel((array) ($room['meal'] ?? []));
                $roomName = (string) ($room['name'] ?? ($room['roomtype']['name'] ?? 'Room'));
                $marked = MARKUP($totalFare, $module, $db, $apiCurrency, $sessionCurrency);
                $perNightMarked = MARKUP($totalFare / $nights, $module, $db, $apiCurrency, $sessionCurrency);
                $offerId = (string) ($room['offerid'] ?? ($code . '_' . $idx));

                $roomOptions[] = [
                    'id' => $offerId,
                    'name' => $roomName,
                    'room_name' => $roomName,
                    'price' => $marked['price'],
                    'price_per_night' => $perNightMarked['price'],
                    'min_price_per_night' => $perNightMarked['price'],
                    'currency' => $sessionCurrency,
                    'board' => $meal['board_name'],
                    'refundable' => !empty($room['refundable']) ? 1 : 0,
                    'cancellation_free' => wanderbedsIsCancellationFree($room) ? 1 : 0,
                    'rate_key' => $offerId,
                    'offer_id' => $offerId,
                    'product_id' => $productId,
                    'wb_token' => $searchToken,
                    'wb_group' => (int) ($room['group'] ?? 0),
                    'wb_roomindex' => (int) ($room['roomindex'] ?? 0),
                    'options_count' => 1,
                    'max_adults' => (int) ($adults ?: 2),
                    'max_children' => (int) ($children ?: 0),
                    'amenities' => [],
                ];
            }

            if ($minTotal === null) {
                continue;
            }

            $priceDetails = MARKUP($minTotal, $module, $db, $apiCurrency, $sessionCurrency);
            $pricePerNightDetails = MARKUP($minTotal / $nights, $module, $db, $apiCurrency, $sessionCurrency);

            // Local first, then live Hotel Details batch payload if still empty
            $images = wanderbedsNormalizeImages($local['images'] ?? []);
            if (empty($images) && !empty($liveDetails[$code]['images'])) {
                $images = $liveDetails[$code]['images'];
            }

            $facilities = json_decode($local['facilities'] ?? '[]', true);
            if ((!is_array($facilities) || empty($facilities)) && !empty($liveDetails[$code]['facilities'])) {
                $facilities = $liveDetails[$code]['facilities'];
            }
            if (!is_array($facilities)) {
                $facilities = [];
            }
            $amenities = [];
            foreach (array_slice($facilities, 0, 20) as $f) {
                if (is_string($f) && trim($f) !== '') {
                    $amenities[] = ['id' => count($amenities) + 1, 'name' => $f];
                }
            }

            $stars = (float) ($local['star_rating'] ?? $apiHotel['starrating'] ?? 0);
            $img = $images[0] ?? (defined('root') ? root . 'uploads/no_img.jpg' : '');

            $formatted[] = [
                'hotel_id' => $code,
                'name' => $local['name'] ?: ($apiHotel['hotelname'] ?? 'Hotel'),
                'img' => $img,
                'image' => $img,
                'images' => $images,
                'location' => $local['city_name'] ?? ($apiHotel['cityname'] ?? $destination),
                'address' => $local['address'] ?? ($apiHotel['address'] ?? ''),
                'stars' => $stars,
                'rating' => $stars,
                'latitude' => $local['latitude'] ?? ($apiHotel['location']['lat'] ?? null),
                'longitude' => $local['longitude'] ?? ($apiHotel['location']['lon'] ?? null),
                'display_price' => $priceDetails['price'],
                'display_price_per_night' => $pricePerNightDetails['price'],
                'actual_price' => $priceDetails['price'],
                'actual_price_per_night' => $pricePerNightDetails['price'],
                'actual_price_details' => $priceDetails,
                'actual_price_per_night_details' => $pricePerNightDetails,
                'currency' => $sessionCurrency,
                'original_currency' => $apiCurrency,
                'amenities' => $amenities,
                'accommodation_type' => $local['accommodation'] ?? ($apiHotel['accommodation'] ?? 'Hotel'),
                'room_options' => $roomOptions,
                'has_available_rooms' => count($roomOptions) > 0,
                'supplier' => 'wanderbeds',
                'supplier_name' => 'wanderbeds',
                'supplier_id' => (string) ($module['id'] ?? 'wanderbeds'),
                'color' => $module['module_color'] ?? '#1a6b5c',
                'nights' => $nights,
                'checkin' => $checkin,
                'checkout' => $checkout,
                'wb_token' => $searchToken,
                'product_id' => $productId,
            ];
        }

        header('X-Total-Results: ' . $totalHotels);
        header('X-Destination-Total: ' . $totalHotels);
        header('X-Total-Pages: ' . $totalPages);
        header('X-Current-Page: ' . $page);
        header('X-Per-Page: ' . $per_page);
        header('X-Has-More: ' . ($page < $totalPages ? 'true' : 'false'));

        $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $json = json_encode($formatted, $jsonFlags);
        if ($json === false) {
            // Strip invalid UTF-8 that can break json_encode and blank the UI
            array_walk_recursive($formatted, static function (&$value) {
                if (is_string($value)) {
                    $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                }
            });
            $json = json_encode($formatted, $jsonFlags);
        }
        echo $json !== false ? $json : '[]';
    } catch (Exception $e) {
        error_log('Wanderbeds search: ' . $e->getMessage());
        http_response_code(400);
        echo json_encode([
            'status' => false,
            'message' => $e->getMessage(),
            'response' => [],
        ]);
    }
});

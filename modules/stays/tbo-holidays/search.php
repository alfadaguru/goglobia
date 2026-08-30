<?php
/**
 * TBO Holidays hotel search
 * POST stays/tbo-holidays/search
 * Hybrid: local content DB for hotel metadata + live Search API for rates
 */

require_once __DIR__ . '/api.php';

$router->post('stays/tbo-holidays/search', function () use ($db) {

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

        $module = tboHolidaysGetModule($db);
        if (!$module || empty($module['c1']) || empty($module['c2'])) {
            throw new Exception('TBO Holidays module is not configured');
        }
        $nationality = tboHolidaysResolveNationality($db, $module, $requestedNationality);
        $filters = tboHolidaysSearchFilters($_POST);

        $moduleCurrency = !empty($module['currency']) ? $module['currency'] : 'USD';
        $nights = tboHolidaysNights($checkin, $checkout);
        $checkinApi = tboHolidaysParseDate($checkin);
        $checkoutApi = tboHolidaysParseDate($checkout);
        $paxRooms = tboHolidaysBuildPaxRooms($rooms_data, $rooms, $adults, $children, $childAges);

        $contentDb = tboHolidaysContentDb($module);

        // Resolve city codes
        $cityCodes = [];
        if ($destination_code !== '') {
            $cityCodes[] = $destination_code;
        }

        $cityRows = $contentDb->select('tbo_cities', ['code', 'name', 'country_code'], [
            'OR' => [
                'name[~]' => $destination,
                'code' => $destination,
            ],
            'LIMIT' => 20
        ]);
        foreach ($cityRows as $city) {
            if (!in_array($city['code'], $cityCodes, true)) {
                $cityCodes[] = $city['code'];
            }
        }

        // Fallback: hotels whose city_name matches
        if (empty($cityCodes)) {
            $hotelCityCodes = $contentDb->select('tbo_hotels', 'city_code', [
                'city_name[~]' => $destination,
                'GROUP' => 'city_code',
                'LIMIT' => 20
            ]);
            foreach ($hotelCityCodes as $code) {
                if ($code && !in_array($code, $cityCodes, true)) {
                    $cityCodes[] = $code;
                }
            }
        }

        if (empty($cityCodes)) {
            header('X-Total-Results: 0');
            header('X-Destination-Total: 0');
            header('X-Total-Pages: 0');
            header('X-Current-Page: ' . $page);
            header('X-Per-Page: ' . $per_page);
            header('X-Has-More: false');
            echo json_encode([]);
            exit;
        }

        $where = [
            'city_code' => $cityCodes,
        ];
        if ($star_rating !== 'any' && $star_rating !== '' && is_numeric($star_rating)) {
            $where['star_rating[>=]'] = (float) $star_rating;
            $where['star_rating[<]'] = (float) $star_rating + 1;
        }

        $totalHotels = (int) $contentDb->count('tbo_hotels', $where);
        $totalPages = max(1, (int) ceil($totalHotels / $per_page));
        $offset = ($page - 1) * $per_page;

        $hotels = $contentDb->select('tbo_hotels', '*', array_merge($where, [
            'ORDER' => ['star_rating' => 'DESC', 'name' => 'ASC'],
            'LIMIT' => [$offset, $per_page]
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

        // Search API recommends up to 100 hotel codes
        $hotelCodes = array_column($hotels, 'hotel_code');
        $hotelMap = [];
        foreach ($hotels as $h) {
            $hotelMap[(string) $h['hotel_code']] = $h;
        }

        $batches = array_chunk($hotelCodes, 100);
        $apiHotelResults = [];
        $logsEnabled = ((int) log_setting($db, 'tbo-holidays')) === 1;
        $searchLogType = 'search_' . preg_replace('/[^A-Za-z0-9_-]/', '', session_id());

        foreach ($batches as $batchIndex => $batch) {
            $payload = [
                'CheckIn' => $checkinApi,
                'CheckOut' => $checkoutApi,
                'HotelCodes' => implode(',', $batch),
                'GuestNationality' => $nationality,
                'PaxRooms' => $paxRooms,
                'ResponseTime' => 23.0,
                'IsDetailedResponse' => false,
            ];
            if (!empty($filters)) {
                $payload['Filters'] = $filters;
            }

            $result = tboHolidaysCall($module, 'Search', $payload, 'POST', 23);
            $statusCode = (int) ($result['status_code'] ?? 0);
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

            if ($statusCode === 201) {
                continue; // no availability in this batch
            }

            if (!$result['success'] || $statusCode !== 200) {
                error_log('TBO Holidays search error: ' . tboHolidaysStatusMessage(
                    $statusCode,
                    (string) ($result['error'] ?? $result['data']['Status']['Description'] ?? 'unknown')
                ));
                continue;
            }

            foreach (($result['data']['HotelResult'] ?? []) as $hotelResult) {
                $code = (string) ($hotelResult['HotelCode'] ?? '');
                if ($code !== '') {
                    $apiHotelResults[$code] = $hotelResult;
                }
            }
        }

        $formatted = [];
        foreach ($hotelCodes as $code) {
            $code = (string) $code;
            $local = $hotelMap[$code] ?? null;
            if (!$local) {
                continue;
            }

            $apiHotel = $apiHotelResults[$code] ?? null;
            if (!$apiHotel || empty($apiHotel['Rooms'])) {
                continue; // only show available hotels
            }

            $apiCurrency = $apiHotel['Currency'] ?? $moduleCurrency;
            $minTotal = null;
            $roomOptions = [];

            foreach ($apiHotel['Rooms'] as $idx => $room) {
                $totalFare = (float) ($room['TotalFare'] ?? 0);
                if ($minTotal === null || $totalFare < $minTotal) {
                    $minTotal = $totalFare;
                }

                $meal = tboHolidaysMealLabel((string) ($room['MealType'] ?? 'Room_Only'));
                $roomName = is_array($room['Name'] ?? null) ? ($room['Name'][0] ?? 'Room') : ($room['Name'] ?? 'Room');
                $marked = MARKUP($totalFare, $module, $db, $apiCurrency, $sessionCurrency);
                $perNightMarked = MARKUP($totalFare / $nights, $module, $db, $apiCurrency, $sessionCurrency);

                $roomOptions[] = [
                    'id' => $room['BookingCode'] ?? ($code . '_' . $idx),
                    'name' => $roomName,
                    'room_name' => $roomName,
                    'price' => $marked['price'],
                    'price_per_night' => $perNightMarked['price'],
                    'min_price_per_night' => $perNightMarked['price'],
                    'currency' => $sessionCurrency,
                    'board' => $meal['board_name'],
                    'refundable' => !empty($room['IsRefundable']) ? 1 : 0,
                    'rate_key' => $room['BookingCode'] ?? '',
                    'booking_code' => $room['BookingCode'] ?? '',
                    'options_count' => 1,
                    'max_adults' => (int) ($room['AdultCount'] ?? $adults ?? 2),
                    'max_children' => (int) ($room['ChildCount'] ?? $children ?? 0),
                    'amenities' => [],
                ];
            }

            if ($minTotal === null) {
                continue;
            }

            $priceDetails = MARKUP($minTotal, $module, $db, $apiCurrency, $sessionCurrency);
            $pricePerNightDetails = MARKUP($minTotal / $nights, $module, $db, $apiCurrency, $sessionCurrency);

            $images = json_decode($local['images'] ?? '[]', true);
            if (!is_array($images)) {
                $images = [];
            }
            $images = tboHolidaysNormalizeImages(['Images' => $images]);
            $facilities = json_decode($local['facilities'] ?? '[]', true);
            if (!is_array($facilities)) {
                $facilities = [];
            }
            $amenities = [];
            foreach (array_slice($facilities, 0, 20) as $f) {
                if (is_string($f) && trim($f) !== '') {
                    $amenities[] = ['id' => count($amenities) + 1, 'name' => $f];
                }
            }

            $stars = (float) ($local['star_rating'] ?? 0);
            $img = $images[0] ?? (defined('root') ? root . 'uploads/no_img.jpg' : '');

            $formatted[] = [
                'hotel_id' => $code,
                'name' => $local['name'],
                'img' => $img,
                'images' => $images,
                'location' => $local['city_name'] ?? $destination,
                'address' => $local['address'] ?? '',
                'stars' => $stars,
                'rating' => $stars,
                'latitude' => $local['latitude'],
                'longitude' => $local['longitude'],
                'display_price' => $priceDetails['price'],
                'display_price_per_night' => $pricePerNightDetails['price'],
                'actual_price' => $priceDetails['price'],
                'actual_price_per_night' => $pricePerNightDetails['price'],
                'actual_price_details' => $priceDetails,
                'actual_price_per_night_details' => $pricePerNightDetails,
                'currency' => $sessionCurrency,
                'original_currency' => $apiCurrency,
                'amenities' => $amenities,
                'accommodation_type' => 'Hotel',
                'room_options' => $roomOptions,
                'has_available_rooms' => count($roomOptions) > 0,
                'supplier' => 'tbo-holidays',
                'supplier_name' => 'tbo-holidays',
                'supplier_id' => (string) ($module['id'] ?? 'tbo-holidays'),
                'color' => $module['module_color'] ?? '#0b5cab',
                'nights' => $nights,
                'checkin' => $checkin,
                'checkout' => $checkout,
            ];
        }

        header('X-Total-Results: ' . $totalHotels);
        header('X-Destination-Total: ' . $totalHotels);
        header('X-Total-Pages: ' . $totalPages);
        header('X-Current-Page: ' . $page);
        header('X-Per-Page: ' . $per_page);
        header('X-Has-More: ' . ($page < $totalPages ? 'true' : 'false'));

        echo json_encode($formatted);
    } catch (Exception $e) {
        error_log('TBO Holidays search: ' . $e->getMessage());
        http_response_code(400);
        echo json_encode([
            'status' => false,
            'message' => $e->getMessage(),
            'response' => []
        ]);
    }
});

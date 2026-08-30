<?php

/**
 * ============================================================================
 * HOTELBEDS HOTEL ROOMS API ENDPOINT
 * ============================================================================
 *
 * PURPOSE:
 * Fetch real-time room availability and pricing from Hotelbeds Booking API
 * with local database enrichment (room names, images, amenities).
 *
 * ENDPOINT: POST /stays/hotelbeds/rooms
 *
 * ============================================================================
 * REQUEST PARAMETERS
 * ============================================================================
 *
 * hotel_id (string)        - Hotel code from Hotelbeds (e.g., "123456")
 *
 * supplier (string)        - Always "hotelbeds" (for consistency)
 *
 * checkin (string)         - Check-in date in DD-MM-YYYY format
 *                            Required for API pricing call
 *
 * checkout (string)        - Check-out date in DD-MM-YYYY format
 *                            Required for API pricing call
 *
 * nationality (string)     - Guest nationality ISO code (e.g., "US", "GB")
 *                            Used in API pricing call
 *
 * rooms (array)            - Room configuration array
 *                            Format: [{"adults":2,"children":1,"childAges":[5]}]
 *
 * ============================================================================
 * RESPONSE FORMAT
 * ============================================================================
 *
 * {
 *   "success": true,
 *   "data": {
 *     "hotel_id": "123456",
 *     "nights": 3,
 *     "currency": "USD",
 *     "rooms": [
 *       {
 *         "room_id": "DBL.ST",
 *         "room_type_id": "1",
 *         "room_name": "Double Standard",
 *         "room_images": [
 *           "https://example.com/room1.jpg"
 *         ],
 *         "room_main_image": "https://example.com/room1.jpg",
 *         "amenities": [
 *           { "id": 1, "name": "Wi-Fi" },
 *           { "id": 2, "name": "Air Conditioning" }
 *         ],
 *         "max_adults": 2,
 *         "max_children": 1,
 *         "options": [
 *           {
 *             "option_index": 0,
 *             "max_adults": 2,
 *             "max_children": 1,
 *             "price_per_night": 150.00,
 *             "total_price": 450.00,
 *             "base_price": 120.00,
 *             "currency": "USD",
 *             "discount_percentage": 0,
 *             "extra_bed_available": 0,
 *             "extra_bed_charge": 0,
 *             "breakfast_included": 1,
 *             "cancellation_free": 1,
 *             "refundable": 1,
 *             "available_quantity": 5,
 *             "board_id": "BB",
 *             "board_name": "Bed & Breakfast",
 *             "rate_key": "20231215|20231218|W|1|123456|DBL.ST|NRF|BB|1~2~0||N@",
 *             "cancellation_policies": [
 *               {
 *                 "amount": 50.00,
 *                 "from": "2023-12-10T00:00:00"
 *               }
 *             ]
 *           }
 *         ]
 *       }
 *     ]
 *   }
 * }
 *
 * ============================================================================
 * PRICING FLOW (Same as Manual Hotels)
 * ============================================================================
 *
 * STEP 1: CALL HOTELBEDS BOOKING API
 * - Endpoint: POST /hotel-api/1.0/hotels
 * - Payload: {stay: {checkIn, checkOut}, occupancies: [...], hotels: {hotel: [code]}}
 * - Returns: Real-time rates per room with board types
 *
 * STEP 2: EXTRACT BASE PRICE
 * - API returns net price in hotel's currency (e.g., EUR)
 * - Example: rate.net = 120.00 EUR
 *
 * STEP 3: APPLY MARKUP (via MARKUP() function)
 * - Pass: basePrice, module config, hotelCurrency, displayCurrency
 * - MARKUP() does:
 *   a) Apply B2B/B2C markup percentage in original currency
 *      120 EUR + 20% = 144 EUR
 *   b) Convert to user's display currency
 *      144 EUR × 1.10 USD rate = 158.40 USD
 *
 * STEP 4: SPLIT STAY TOTAL INTO PER-NIGHT DISPLAY
 * - API `net` is the full stay total (all nights), not per night
 * - totalPrice = MARKUP(net); pricePerNight = totalPrice ÷ nights
 *
 * ============================================================================
 * DATABASE ENRICHMENT
 * ============================================================================
 *
 * Hotelbeds API returns minimal room info (code, name).
 * We enrich with local database:
 *
 * 1. Room Images: hotelbeds_hotel_rooms.room_images (JSON array)
 * 2. Room Amenities: Join hotelbeds_hotel_rooms with facilities tables
 * 3. Room Type Names: Map room_code to descriptive names
 *
 * ============================================================================
 * DIFFERENCES FROM MANUAL HOTELS ROOMS API
 * ============================================================================
 *
 * 1. ROOM SOURCE:
 *    - Manual: All room data in stays_rooms table with room_options JSON
 *    - Hotelbeds: Basic room metadata in hotelbeds_hotel_rooms,
 *                 pricing from API
 *
 * 2. PRICING SOURCE:
 *    - Manual: Pre-configured prices in room_options JSON
 *    - Hotelbeds: Real-time API call to Hotelbeds Booking API
 *
 * 3. RATE KEY:
 *    - Manual: N/A (simple room ID)
 *    - Hotelbeds: Unique rate_key string required for booking
 *
 * 4. BOARD TYPES:
 *    - Manual: Simple board_id field
 *    - Hotelbeds: API returns board codes (BB, HB, FB, AI) per rate
 *
 * 5. CANCELLATION:
 *    - Manual: Simple refundable/cancellation_free flags
 *    - Hotelbeds: Detailed cancellation policies array with dates/amounts
 *
 * 6. AVAILABILITY:
 *    - Manual: Always available (no real-time check)
 *    - Hotelbeds: API returns actual availability and allotment
 *
 * ============================================================================
 */

$router->post('stays/hotelbeds/rooms', function () use ($db) {

    require_once __DIR__ . '/refundability.php';

    // Suppress errors for clean JSON output
    error_reporting(0);
    ini_set('display_errors', 0);

    // Start output buffering
    ob_start();

    // Set JSON header
    header('Content-Type: application/json');

    try {
        // Parse JSON request body
        $input = json_decode(file_get_contents('php://input'), true);

        $hotelId = $input['hotel_id'] ?? '';
        $supplier = $input['supplier'] ?? 'hotelbeds';
        $checkin = $input['checkin'] ?? '';
        $checkout = $input['checkout'] ?? '';
        $nationality = $input['nationality'] ?? 'US';
        $roomCount = (int) ($input['rooms'] ?? 1);

        // Robust currency detection from input or session
        $currency = 'USD';
        if (!empty($input['currency'])) {
            $currency = $input['currency'];
        } else if (isset($_SESSION) && !empty($_SESSION['app_currency'])) {
            $currency = $_SESSION['app_currency'];
        }

        // Validate required parameters
        if (empty($hotelId)) {
            echo json_encode([
                'success' => false,
                'message' => 'Hotel ID is required'
            ]);
            exit;
        }

        // If dates are missing, return empty rooms array
        if (empty($checkin) || empty($checkout)) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'hotel_id' => $hotelId,
                    'nights' => 1,
                    'currency' => $_SESSION['app_currency'] ?? 'USD',
                    'rooms' => [],
                    'message' => 'Check-in and check-out dates are required for room availability'
                ]
            ]);
            exit;
        }

        // ========================================
        // CALCULATE NUMBER OF NIGHTS + API DATE FORMAT (Y-m-d)
        // ========================================
        $nights = 1;
        $checkin_date = '';
        $checkout_date = '';

        $stayDates = function_exists('hotelbedsParseStayDates')
            ? hotelbedsParseStayDates($checkin, $checkout)
            : null;

        if ($stayDates) {
            $checkin_date = $stayDates['checkin_ymd'];
            $checkout_date = $stayDates['checkout_ymd'];
            $nights = $stayDates['nights'];
            $checkin = $stayDates['checkin_dmY'];
            $checkout = $stayDates['checkout_dmY'];
        }

        if ($checkin_date === '' || $checkout_date === '') {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid check-in or check-out date format',
            ]);
            exit;
        }

        $todayMidnight = new DateTime('today');
        $checkinMidnight = DateTime::createFromFormat('Y-m-d', $checkin_date);
        if ($checkinMidnight instanceof DateTime && $checkinMidnight < $todayMidnight) {
            echo json_encode([
                'success' => false,
                'message' => 'Check-in date cannot be in the past',
            ]);
            exit;
        }

        // Get currency from session
        // nights calculation moved up for robust availability checks

        // ========================================
        // GET HOTELBEDS MODULE CONFIGURATION
        // ========================================
        $module = $db->get('modules', '*', [
            'name' => 'hotelbeds',
            'type' => 'stays'
        ]);

        if (!$module) {
            echo json_encode([
                'success' => false,
                'message' => 'Hotelbeds module not configured'
            ]);
            exit;
        }

        // Extract credentials and settings
        $dbHost = $module['host'] ?? 'localhost';
        $dbName = $module['database'] ?? '';
        $dbUser = $module['username'] ?? 'root';
        $dbPass = $module['password'] ?? '';
        $apiKey = $module['c1'] ?? '';
        $apiSecret = $module['c2'] ?? '';
        // Keep environment selection consistent with the main Hotelbeds search flow.
        $environment = (($module['dev_mode'] ?? '1') == '1') ? 'test' : 'live';
        $moduleCurrency = !empty($module['currency']) ? $module['currency'] : 'EUR';

        // Get mTLS setting (settings.json) — fail closed when enabled but certs missing
        $hotelbedsSettings = function_exists('readHotelbedsSettings')
            ? readHotelbedsSettings()
            : ['use_mtls' => 0];
        $transport = function_exists('hotelbedsResolveBookingTransport')
            ? hotelbedsResolveBookingTransport($module, $hotelbedsSettings)
            : [
                'environment' => $environment,
                'use_mtls' => (($hotelbedsSettings['use_mtls'] ?? 0) == 1),
                'error' => null,
            ];
        $environment = $transport['environment'];
        $useMtls = $transport['use_mtls'];

        if (!empty($transport['error'])) {
            echo json_encode([
                'success' => false,
                'message' => function_exists('hotelbedsUserFacingError')
                    ? hotelbedsUserFacingError($transport['error'])
                    : 'One or more rates are no longer available. Please search again.',
            ]);
            exit;
        }

        if (empty($dbName) || empty($apiKey) || empty($apiSecret)) {
            echo json_encode([
                'success' => false,
                'message' => 'Module configuration incomplete'
            ]);
            exit;
        }

        // ========================================
        // CREATE DATABASE CONNECTION
        // ========================================
        try {
            $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
            $hotelbedsPDO = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);

            $hotelbedsDb = new Medoo\Medoo(['type' => 'mysql', 'pdo' => $hotelbedsPDO]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Database connection failed'
            ]);
            exit;
        }

        // ========================================
        // VERIFY HOTEL EXISTS
        // ========================================
        $hotel = $hotelbedsDb->get('hotelbeds_hotels', ['hotel_code'], [
            'hotel_code' => $hotelId
        ]);

        if (!$hotel) {
            echo json_encode([
                'success' => false,
                'message' => 'Hotel not found'
            ]);
            exit;
        }

        // ========================================
        // PREPARE API REQUEST
        // ========================================
        $isProduction = ($environment === 'live');

        // Determine base URL based on mTLS and environment
        $baseUrl = function_exists('hotelbedsBookingApiBaseUrl')
            ? hotelbedsBookingApiBaseUrl($environment, $useMtls)
            : ($useMtls
                ? ($isProduction ? 'https://api-mtls.hotelbeds.com/hotel-api/1.0' : 'https://api-mtls.test.hotelbeds.com/hotel-api/1.0')
                : ($isProduction ? 'https://api.hotelbeds.com/hotel-api/1.0' : 'https://api.test.hotelbeds.com/hotel-api/1.0'));

        // Dates parsed above as Y-m-d from dd-mm-yyyy (same as search.php)
        $checkinFormatted = $checkin_date;
        $checkoutFormatted = $checkout_date;

        // Build occupancies array (prefer rooms_data from search/detail page)
        $occupancies = [];
        $occupancyValidationError = null;
        $roomsData = $input['rooms_data'] ?? null;
        if (is_string($roomsData)) {
            $decodedRoomsData = json_decode($roomsData, true);
            $roomsData = is_array($decodedRoomsData) ? $decodedRoomsData : [];
        }
        if (!is_array($roomsData)) {
            $roomsData = [];
        }
        if (empty($roomsData) && isset($input['rooms']) && is_array($input['rooms'])) {
            $roomsData = $input['rooms'];
        }

        if (!empty($roomsData)) {
            foreach ($roomsData as $room) {
                $adults = max(1, (int) ($room['adults'] ?? 2));
                $children = max(0, (int) ($room['children'] ?? 0));

                $occupancy = [
                    "rooms" => 1,
                    "adults" => $adults,
                    "children" => $children
                ];

                if ($children > 0) {
                    $childAges = isset($room['childAges']) && is_array($room['childAges'])
                        ? array_values($room['childAges'])
                        : [];

                    if (count($childAges) !== $children) {
                        $occupancyValidationError = 'A valid age is required for every child in each room.';
                        break;
                    }

                    $occupancy['paxes'] = [];
                    foreach ($childAges as $age) {
                        $occupancy['paxes'][] = [
                            "type" => "CH",
                            "age" => max(0, (int) $age)
                        ];
                    }
                }

                $occupancies[] = $occupancy;
            }
        } elseif ($roomCount > 0) {
            for ($roomIndex = 0; $roomIndex < $roomCount; $roomIndex++) {
                $occupancies[] = [
                    "rooms" => 1,
                    "adults" => 2,
                    "children" => 0
                ];
            }
        } else {
            $occupancies[] = [
                "rooms" => 1,
                "adults" => 2,
                "children" => 0
            ];
        }

        if ($occupancyValidationError !== null) {
            echo json_encode([
                'success' => false,
                'message' => $occupancyValidationError,
            ]);
            exit;
        }

        // Build API payload
        $apiPayload = [
            "stay" => [
                "checkIn" => $checkinFormatted,
                "checkOut" => $checkoutFormatted
            ],
            "occupancies" => $occupancies,
            "hotels" => [
                "hotel" => [(int) $hotelId]
            ]
        ];

        if (function_exists('hotelbedsApplyAvailabilityMarketFields')) {
            $apiPayload = hotelbedsApplyAvailabilityMarketFields($apiPayload, $db, $nationality);
        } elseif (!empty($nationality)) {
            $apiPayload['nationality'] = strtoupper(trim((string) $nationality));
        }

        // Log the API request for debugging

        // ========================================
        // MAKE API REQUEST
        // ========================================
        $timestamp = time();
        $signature = hash('sha256', $apiKey . $apiSecret . $timestamp);

        $ch = curl_init();

        if (function_exists('hotelbedsApplyMtlsCurlOptions')) {
            hotelbedsApplyMtlsCurlOptions($ch, $useMtls);
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl . '/hotels',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($apiPayload),
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Api-key: ' . $apiKey,
                'X-Signature: ' . $signature,
                'Accept: application/json',
                'Content-Type: application/json',
                'Accept-Encoding: gzip'
            ]
        ]);

        $apiResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $log_setting = log_setting($db, 'hotelbeds');

        if ($log_setting == '1') {
            $apiResponseDecoded = !empty($apiResponse) ? json_decode($apiResponse, true) : ['error' => 'Empty response'];

            $path = function_exists('hotelbedsLogDir') ? hotelbedsLogDir() : (__DIR__ . '/logs');
            $type = 'Hotelbeds_Details';
            logApiCall('hotelbeds_details', $apiPayload, $apiResponseDecoded, $httpCode, $path, $type);
        }

        // Log API response for debugging

        // ========================================
        // PARSE API RESPONSE
        // ========================================
        $roomsResponse = [];

        if ($httpCode === 200 && !empty($apiResponse)) {
            $apiData = json_decode($apiResponse, true);

            if (!isset($apiData['hotels']['hotels'][0])) {
            } else {
                $apiHotel = $apiData['hotels']['hotels'][0];
                $apiCurrency = $apiHotel['currency'] ?? $moduleCurrency;

                // Group rooms by room code
                $roomsGrouped = [];

                // Availability vs displayed counts — packaging rates are hidden
                // unless allow_packaging_rates is enabled, which is the usual
                // reason a room or board visible in the Hotelbeds extranet
                // (which defaults to packaging=true) is missing here.
                $ratesFromApi = 0;
                $ratesSkippedPackaging = 0;

                if (isset($apiHotel['rooms']) && is_array($apiHotel['rooms'])) {
                    foreach ($apiHotel['rooms'] as $apiRoom) {
                        $roomCode = $apiRoom['code'] ?? '';
                        $roomName = $apiRoom['name'] ?? 'Room';

                        if (empty($roomCode))
                            continue;

                        // Initialize room group
                        if (!isset($roomsGrouped[$roomCode])) {
                            $roomsGrouped[$roomCode] = [
                                'room_code' => $roomCode,
                                'room_name' => $roomName,
                                'rates' => []
                            ];
                        }

                        // Add all rates for this room
                        if (isset($apiRoom['rates']) && is_array($apiRoom['rates'])) {
                            foreach ($apiRoom['rates'] as $rate) {
                                $ratesFromApi++;
                                if (function_exists('hotelbedsShouldSkipPackagingRate') && hotelbedsShouldSkipPackagingRate($rate)) {
                                    $ratesSkippedPackaging++;
                                    continue;
                                }
                                $roomsGrouped[$roomCode]['rates'][] = $rate;
                            }
                        }
                    }
                }

                // ========================================
                // LISTING PRICES: use Availability net only.
                // Batch /checkrates on page load can attach another room's net when
                // Hotelbeds groups batch responses by room code. RECHECK validation
                // runs at book time via checkRatesBookPath() (one rateKey per call).
                // ========================================
                $checkedRatesByKey = [];

                // ========================================
                // BATCH DB ENRICHMENT (avoid N+1 queries)
                // ========================================
                $imageBaseUrl = 'https://photos.hotelbeds.com/giata/bigger/';
                $roomCodes = array_keys($roomsGrouped);

                $roomRecordsByCode = [];
                if (!empty($roomCodes)) {
                    $roomRecords = $hotelbedsDb->select('hotelbeds_hotel_rooms', '*', [
                        'hotel_code' => $hotelId,
                        'room_code' => $roomCodes
                    ]);
                    foreach ($roomRecords as $rec) {
                        $roomRecordsByCode[$rec['room_code']] = $rec;
                    }
                }

                $roomNamesByCode = [];
                if (!empty($roomCodes)) {
                    $roomRefs = $hotelbedsDb->select('hotelbeds_rooms', ['code', 'description'], [
                        'code' => $roomCodes
                    ]);
                    foreach ($roomRefs as $ref) {
                        if (!empty($ref['description'])) {
                            $roomNamesByCode[$ref['code']] = $ref['description'];
                        }
                    }
                }

                $roomImagesByCode = [];
                if (!empty($roomCodes)) {
                    $allImageRecords = $hotelbedsDb->select('hotelbeds_hotel_images', ['room_code', 'image_url'], [
                        'hotel_code' => $hotelId,
                        'room_code' => $roomCodes,
                        'ORDER' => ['image_order' => 'ASC']
                    ]);
                    foreach ($allImageRecords as $imgRec) {
                        $code = $imgRec['room_code'] ?? '';
                        if ($code === '' || empty($imgRec['image_url'])) {
                            continue;
                        }
                        if (strpos($imgRec['image_url'], 'http') === 0) {
                            $roomImagesByCode[$code][] = $imgRec['image_url'];
                        } else {
                            $roomImagesByCode[$code][] = $imageBaseUrl . $imgRec['image_url'];
                        }
                    }
                }

                $allFacilityIds = [];
                foreach ($roomRecordsByCode as $rec) {
                    if (empty($rec['room_facilities'])) {
                        continue;
                    }
                    $roomFacilitiesData = json_decode($rec['room_facilities'], true);
                    if (!is_array($roomFacilitiesData)) {
                        continue;
                    }
                    foreach ($roomFacilitiesData as $fac) {
                        if (!empty($fac['code'])) {
                            $allFacilityIds[(int) $fac['code']] = (int) $fac['code'];
                        }
                    }
                }

                $facilitiesByCode = [];
                if (!empty($allFacilityIds)) {
                    $facilities = $hotelbedsDb->select('hotelbeds_facilities', ['code', 'description'], [
                        'code' => array_values($allFacilityIds)
                    ]);
                    foreach ($facilities as $facility) {
                        if (!empty($facility['description']) && $facility['description'] !== '1') {
                            $facilitiesByCode[$facility['code']] = $facility['description'];
                        }
                    }
                }

                foreach ($roomsGrouped as $roomCode => $roomData) {
                    // Skip if no rates after filtering packaging
                    if (empty($roomData['rates'])) {
                        continue;
                    }

                    $roomRecord = $roomRecordsByCode[$roomCode] ?? null;
                    $roomName = $roomNamesByCode[$roomCode] ?? ($roomData['room_name'] ?? 'Room');

                    $roomImages = $roomImagesByCode[$roomCode] ?? [];
                    if (empty($roomImages)) {
                        $roomImages[] = dirname(root) . '/uploads/no_img.jpg';
                    }

                    $roomAmenities = [];
                    if ($roomRecord && !empty($roomRecord['room_facilities'])) {
                        $roomFacilitiesData = json_decode($roomRecord['room_facilities'], true);
                        if (is_array($roomFacilitiesData)) {
                            $seenFacility = [];
                            foreach ($roomFacilitiesData as $fac) {
                                $facCode = isset($fac['code']) ? (int) $fac['code'] : 0;
                                if ($facCode <= 0 || isset($seenFacility[$facCode]) || !isset($facilitiesByCode[$facCode])) {
                                    continue;
                                }
                                $seenFacility[$facCode] = true;
                                $roomAmenities[] = [
                                    'id' => $facCode,
                                    'name' => $facilitiesByCode[$facCode]
                                ];
                            }
                        }
                    }

                    // Build options from API rates (same format as hotel details)
                    $roomOptions = [];
                    $roomOptionSeen = [];
                    $optionIndex = 0;

                    foreach ($roomData['rates'] as $rate) {
                        $rateKey = $rate['rateKey'] ?? '';
                        $boardCode = strtoupper(trim($rate['boardCode'] ?? ''));
                        $boardName = trim($rate['boardName'] ?? '');

                        if (empty($boardName)) {
                            if (function_exists('hotelbedsLookupBoardName')) {
                                $boardName = hotelbedsLookupBoardName($hotelbedsDb, $boardCode);
                            } else {
                                $boardNameMap = [
                                    'RO' => 'Room Only',
                                    'BB' => 'Bed & Breakfast',
                                    'HB' => 'Half Board',
                                    'FB' => 'Full Board',
                                    'AI' => 'All Inclusive',
                                    'UAI' => 'Ultra All Inclusive',
                                    'CP' => 'Continental Plan',
                                    'MAP' => 'Modified American Plan',
                                    'EP' => 'European Plan'
                                ];
                                $boardName = $boardNameMap[$boardCode] ?? ($boardCode ?: 'Room Only');
                            }
                        }

                        // Apply CheckRate only when we have a refreshed RECHECK result
                        $updatedRate = ($rateKey !== '' && isset($checkedRatesByKey[$rateKey]))
                            ? $checkedRatesByKey[$rateKey]
                            : null;

                        $availabilityNet = floatval($rate['net'] ?? 0);
                        $priceChangedOnRecheck = false;

                        if (is_array($updatedRate)) {
                            $expectedRoomCode = hotelbedsRateKeyRoomCode($rateKey);
                            $updatedRoomCode = hotelbedsRateKeyRoomCode($updatedRate['rateKey'] ?? $rateKey);
                            $expectedBoardCode = hotelbedsRateKeyBoardCode($rateKey);
                            $updatedBoardCode = strtoupper(trim((string) (
                                $updatedRate['boardCode'] ?? hotelbedsRateKeyBoardCode($updatedRate['rateKey'] ?? '')
                            )));
                            if (
                                ($expectedRoomCode !== ''
                                    && $updatedRoomCode !== ''
                                    && $expectedRoomCode !== $updatedRoomCode)
                                || ($expectedBoardCode !== ''
                                    && $updatedBoardCode !== ''
                                    && $expectedBoardCode !== $updatedBoardCode)
                            ) {
                                // Never apply a CheckRate payload from another room/board.
                                $updatedRate = null;
                            }
                        }

                        if (is_array($updatedRate)) {
                            $checkRateNet = floatval($updatedRate['net'] ?? $availabilityNet);
                            if ($availabilityNet > 0 && abs($checkRateNet - $availabilityNet) > 0.01) {
                                $priceChangedOnRecheck = true;
                            }

                            $rate['net'] = $updatedRate['net'] ?? $rate['net'];
                            $rate['sellingRate'] = $updatedRate['sellingRate'] ?? ($rate['sellingRate'] ?? null);
                            $rate['hotelSellingRate'] = $updatedRate['hotelSellingRate'] ?? ($rate['hotelSellingRate'] ?? null);
                            $rate['hotelCurrency'] = $updatedRate['hotelCurrency'] ?? ($rate['hotelCurrency'] ?? null);

                            if (isset($updatedRate['cancellationPolicies'])) {
                                $rate['cancellationPolicies'] = $updatedRate['cancellationPolicies'];
                            }

                            if (isset($updatedRate['rateComments']) && trim((string) $updatedRate['rateComments']) !== '') {
                                $rate['rateComments'] = $updatedRate['rateComments'];
                                if (!empty($updatedRate['rateCommentsId'])) {
                                    $rate['rateCommentsId'] = $updatedRate['rateCommentsId'];
                                }
                            } elseif (!empty($updatedRate['rateCommentsId'])) {
                                $rate['rateCommentsId'] = $updatedRate['rateCommentsId'];
                            }
                            // Decode ID → sentence from imported hotelbeds_rate_comments when needed
                            if (function_exists('hotelbedsEnrichRateCommentsFromImport')) {
                                hotelbedsEnrichRateCommentsFromImport($rate, $hotelbedsDb, $checkin_date, [
                                    'api_key' => $module['c1'] ?? '',
                                    'api_secret' => $module['c2'] ?? '',
                                    'environment' => $environment ?? 'test',
                                ]);
                            }

                            // CheckRate may promote RECHECK -> BOOKABLE
                            if (!empty($updatedRate['rateType'])) {
                                $rate['rateType'] = $updatedRate['rateType'];
                            }
                            if (!empty($updatedRate['rateKey'])) {
                                $rate['rateKey'] = $updatedRate['rateKey'];
                                $rateKey = $updatedRate['rateKey'];
                            }
                        } else {
                            if (function_exists('hotelbedsEnrichRateCommentsFromImport')) {
                                hotelbedsEnrichRateCommentsFromImport($rate, $hotelbedsDb, $checkin_date, [
                                    'api_key' => $module['c1'] ?? '',
                                    'api_secret' => $module['c2'] ?? '',
                                    'environment' => $environment ?? 'test',
                                ]);
                            }
                        }

                        // Now process the updated rate
                        $basePrice = floatval($rate['net'] ?? 0);

                        if ($basePrice <= 0)
                            continue;

                        // Hotelbeds returns prices in the hotel's currency (varies by hotel/account).
                        // Use the actual currency from rate/checkrates/api instead of hard-coding EUR.
                        $rateCurrency = $rate['hotelCurrency'] ?? $apiCurrency ?? $moduleCurrency ?? 'EUR';
                        $rateCurrency = strtoupper(trim((string) $rateCurrency));
                        $requestedCurrency = strtoupper(trim((string) $currency));

                        // Hotelbeds `net` is total for the stay; divide by nights for per-night display.
                        $priceMarkup = MARKUP($basePrice, $module, $db, $rateCurrency, $requestedCurrency);
                        $stayNights = max(1, (int) $nights);
                        $totalPriceUSD = round($priceMarkup['price'], 2);
                        $pricePerNightUSD = round($priceMarkup['price'] / $stayNights, 2);
                        $convertedBasePrice = round($priceMarkup['converted_base_price'], 2);

                        // Get board information
                        $boardCode = strtoupper(trim($rate['boardCode'] ?? ''));
                        $boardName = trim($rate['boardName'] ?? '');

                        if (empty($boardName)) {
                            if (function_exists('hotelbedsLookupBoardName')) {
                                $boardName = hotelbedsLookupBoardName($hotelbedsDb, $boardCode);
                            } else {
                                $boardNameMap = [
                                    'RO' => 'Room Only',
                                    'BB' => 'Bed & Breakfast',
                                    'HB' => 'Half Board',
                                    'FB' => 'Full Board',
                                    'AI' => 'All Inclusive',
                                    'UAI' => 'Ultra All Inclusive',
                                    'CP' => 'Continental Plan',
                                    'MAP' => 'Modified American Plan',
                                    'EP' => 'European Plan'
                                ];
                                $boardName = $boardNameMap[$boardCode] ?? ($boardCode ?: 'Room Only');
                            }
                        }

                        // Extract cancellation policies (now updated from checkRates)
                        $cancellationPolicies = [];
                        $supplierCancellationPolicies = [];
                        if (!empty($rate['cancellationPolicies']) && is_array($rate['cancellationPolicies'])) {
                            foreach ($rate['cancellationPolicies'] as $policy) {
                                $policyFrom = $policy['from'] ?? $policy['dateFrom'] ?? $policy['fromDate'] ?? '';
                                $supplierCancellationPolicies[] = [
                                    'amount' => round((float) ($policy['amount'] ?? $policy['hotelAmount'] ?? 0), 4),
                                    'from' => is_string($policyFrom) ? $policyFrom : '',
                                ];
                                $cancel_amount = MARKUP(floatval($policy['amount'] ?? $policy['hotelAmount'] ?? 0), $module, $db, $rateCurrency, $requestedCurrency);
                                $cancellationPolicies[] = [
                                    'amount' => $cancel_amount['price'],
                                    'from' => is_string($policyFrom) ? $policyFrom : ''
                                ];
                            }
                        }

                        // Determine refundability from cancellation policies + rateClass/NOR-NRF in rateKey
                        $rateType = $rate['rateType'] ?? '';
                        $rateClass = strtoupper(trim((string) ($rate['rateClass'] ?? '')));
                        if ($rateClass === '') {
                            $rateClass = hotelbedsRateKeyRateClass($rateKey);
                        }
                        $refundFlags = hotelbedsResolveRefundabilityFromRate($rate, $supplierCancellationPolicies);
                        $isRefundable = (int) $refundFlags['refundable'];
                        $isCurrentlyFree = (int) $refundFlags['cancellation_free'];

                        // Get excluded taxes (same as hotel details)
                        $excludedTaxes = [];
                        if (!empty($rate['taxes']['taxes'])) {
                            foreach ($rate['taxes']['taxes'] as $tax) {
                                if (isset($tax['included']) && $tax['included'] === false) {
                                    $tax_amount = MARKUP($tax['clientAmount'], $module, $db, $rateCurrency, $requestedCurrency);
                                    $excludedTaxes[] = [
                                        'subType' => $tax['subType'] ?? 'Tax/Fee',
                                        'hotelAmount' => $tax['amount'] ?? '0.00',
                                        'hotelCurrency' => $tax['currency'] ?? '',
                                        'clientAmount' => isset($tax['clientAmount']) ? $tax_amount['price'] : null,
                                        'clientCurrency' => $requestedCurrency
                                    ];
                                }
                            }
                        }

                        // Format cancellation policy text (destination-local deadline — see hotelbedsFormatCancellationText())
                        $cancellationText = hotelbedsFormatCancellationText($cancellationPolicies, $currency);

                        // Get rate comments (already updated from checkRates above)
                        $rateComments = $rate['rateComments'] ?? null;
                        if (is_string($rateComments) && $rateComments !== '' && function_exists('staysConvertCurrencyAmountsInText')) {
                            $rateComments = staysConvertCurrencyAmountsInText(
                                $db,
                                $rateComments,
                                $requestedCurrency ?: ($currency ?? 'USD')
                            );
                        }

                        // Discount if any
                        $discountPercentage = 0;

                        if (isset($rate['offers']) && !empty($rate['offers']) && is_array($rate['offers'])) {
                            $totalDiscount = 0;
                            foreach ($rate['offers'] as $offer) {
                                $amount = floatval($offer['amount'] ?? 0);
                                if ($amount < 0) {
                                    $totalDiscount += abs($amount); // convert negative to positive
                                }
                            }

                            if ($basePrice > 0) {
                                $discountPercentage = round(($totalDiscount / $basePrice) * 100, 2);
                            }
                        }

                        // Content API promotions master — resolve offer/promotion codes to names
                        $promotions = [];
                        $promoSources = [];
                        if (!empty($rate['promotions']) && is_array($rate['promotions'])) {
                            $promoSources = $rate['promotions'];
                        } elseif (!empty($rate['offers']) && is_array($rate['offers'])) {
                            $promoSources = $rate['offers'];
                        }
                        foreach ($promoSources as $promo) {
                            if (!is_array($promo)) {
                                continue;
                            }
                            $promoCode = (string) ($promo['code'] ?? $promo['offerCode'] ?? '');
                            $promoName = trim((string) ($promo['name'] ?? $promo['description'] ?? ''));
                            if ($promoName === '' && $promoCode !== '' && function_exists('hotelbedsLookupPromotionName')) {
                                $promoName = hotelbedsLookupPromotionName($hotelbedsDb, $promoCode, $promoCode);
                            }
                            if ($promoName === '' && $promoCode === '') {
                                continue;
                            }
                            $promotions[] = [
                                'code' => $promoCode,
                                'name' => $promoName !== '' ? $promoName : $promoCode,
                            ];
                        }

                        $isPackagingRate = !empty($rate['packaging'])
                            && ($rate['packaging'] === true
                                || $rate['packaging'] === 'true'
                                || $rate['packaging'] === 1
                                || $rate['packaging'] === '1');

                        $dedupeKey = sha1(
                            trim((string) $rateKey) . '|' .
                            $boardCode . '|' .
                            $boardName . '|' .
                            round($pricePerNightUSD ?? 0, 2) . '|' .
                            round($totalPriceUSD ?? 0, 2) . '|' .
                            intval($rate['adults'] ?? 0) . '|' .
                            intval($rate['children'] ?? 0) . '|' .
                            intval($rate['allotment'] ?? 0) . '|' .
                            ($isRefundable ? 'R' : 'NR') . '|' .
                            ($isCurrentlyFree ? 'CF' : 'NC') . '|' .
                            ($isPackagingRate ? 'PKG' : 'STD')
                        );

                        if (isset($roomOptionSeen[$dedupeKey])) {
                            continue;
                        }

                        $roomOptionSeen[$dedupeKey] = true;

                        $rateOccupancy = hotelbedsRateKeyOccupancy($rate['rateKey'] ?? '');
                        $rateAdults = (int) ($rate['adults'] ?? ($rateOccupancy['adults'] ?? 0));
                        $rateChildren = (int) ($rate['children'] ?? ($rateOccupancy['children'] ?? 0));
                        $rateChildAges = $rateOccupancy['child_ages'] ?? [];
                        $matchingOccupancyIndexes = [];

                        foreach ($roomsData as $occupancyIndex => $requestedRoom) {
                            $requestedAdults = max(1, (int) ($requestedRoom['adults'] ?? 2));
                            $requestedChildren = max(0, (int) ($requestedRoom['children'] ?? 0));
                            $requestedChildAges = array_map(
                                'intval',
                                is_array($requestedRoom['childAges'] ?? null)
                                    ? $requestedRoom['childAges']
                                    : []
                            );
                            sort($requestedChildAges);

                            if ($requestedAdults !== $rateAdults || $requestedChildren !== $rateChildren) {
                                continue;
                            }

                            // A rateKey contains child ages for a child occupancy. Do
                            // not allow a rate quoted for one child's age to be used
                            // for another child of a different age.
                            if (
                                $requestedChildren > 0
                                && !empty($rateChildAges)
                                && $requestedChildAges !== $rateChildAges
                            ) {
                                continue;
                            }

                            $matchingOccupancyIndexes[] = (int) $occupancyIndex;
                        }

                        $roomOptions[] = [
                            'option_index' => $optionIndex++,
                            'id' => $rate['rateKey'],
                            'max_adults' => $rateAdults,
                            'max_children' => $rateChildren,
                            'price_per_night' => round($pricePerNightUSD ?? 0, 2),
                            'total_price' => round($totalPriceUSD ?? 0, 2),
                            'base_price' => round($convertedBasePrice ?? 0, 2),
                            'original_price' => round($convertedBasePrice ?? 0, 2),
                            'currency' => $requestedCurrency ?: 'USD',
                            'base_currency' => $rateCurrency ?: ($apiCurrency ?? 'USD'),
                            'discount_percentage' => intval($discountPercentage),
                            'extra_bed_available' => 0,
                            'extra_bed_charge' => 0,
                            'breakfast_included' => (stripos($boardCode, 'BB') !== false ||
                                stripos($boardCode, 'HB') !== false ||
                                stripos($boardCode, 'FB') !== false ||
                                stripos($boardCode, 'AI') !== false) ? 1 : 0,
                            'cancellation_free' => $isCurrentlyFree,
                            'refundable' => $isRefundable,
                            'available_quantity' => (int) ($rate['allotment'] ?? 1),
                            'board_id' => $boardCode,
                            'board_name' => $boardName,
                            'rate_key' => $rate['rateKey'] ?? '',
                            'rate_type' => $rateType,
                            'rate_class' => $rateClass,
                            'rate_comments' => $rateComments,
                            'rate_comments_id' => $rate['rateCommentsId'] ?? null,
                            'promotions' => $promotions,
                            'cancellation_policies' => $cancellationPolicies,
                            'supplier_cancellation_policies' => $supplierCancellationPolicies,
                            'cancellation_text' => $cancellationText,
                            'excluded_taxes' => $excludedTaxes,
                            'supplier_net' => round($basePrice, 4),
                            'supplier_currency' => $rateCurrency,
                            'availability_net' => round($availabilityNet, 4),
                            'price_changed_on_recheck' => $priceChangedOnRecheck ? 1 : 0,
                            'needs_recheck' => (strtoupper(trim((string) $rateType)) === 'RECHECK') ? 1 : 0,
                            'packaging' => $isPackagingRate ? 1 : 0,
                            'occupancy' => [
                                'adults' => $rateAdults,
                                'children' => $rateChildren,
                                'child_ages' => $rateChildAges,
                            ],
                            'matching_occupancy_indexes' => $matchingOccupancyIndexes,
                        ];
                    }

                    // Only add rooms that have available options
                    if (!empty($roomOptions)) {
                        $roomsResponse[] = [
                            'room_id' => $roomCode,
                            'room_type_id' => $roomCode,
                            'room_name' => $roomName,
                            'room_images' => $roomImages,
                            'room_main_image' => $roomImages[0],
                            'amenities' => $roomAmenities,
                            'max_adults' => !empty($roomOptions) ? max(array_column($roomOptions, 'max_adults')) : 2,
                            'max_children' => !empty($roomOptions) ? max(array_column($roomOptions, 'max_children')) : 0,
                            'options' => $roomOptions
                        ];
                    }
                }
            }
        } else {
            $decodedFail = !empty($apiResponse) ? json_decode($apiResponse, true) : [];
            $apiFailMsg = '';
            $failError = is_array($decodedFail) ? ($decodedFail['error'] ?? null) : null;
            if (is_array($failError)) {
                $apiFailMsg = trim((string) ($failError['message'] ?? $failError['description'] ?? ''));
            } elseif (is_string($failError) && $failError !== '') {
                $apiFailMsg = trim($failError);
            }
            if ($apiFailMsg === '' && (int) $httpCode > 0 && $httpCode !== 200) {
                $apiFailMsg = 'Hotelbeds rooms HTTP ' . (int) $httpCode;
            }
            if ($apiFailMsg !== '') {
                echo json_encode([
                    'success' => false,
                    'message' => function_exists('hotelbedsUserFacingError')
                        ? hotelbedsUserFacingError($apiFailMsg)
                        : 'One or more rates are no longer available. Please search again.',
                ]);
                exit;
            }
        }

        // ========================================
        // FALLBACK: If no rooms from API, try to get basic room info from local database
        // ========================================
        if (empty($roomsResponse)) {
            $localRooms = $hotelbedsDb->select('hotelbeds_hotel_rooms', '*', [
                'hotel_code' => $hotelId
            ]);

            $imageBaseUrl = 'https://photos.hotelbeds.com/giata/bigger/';

            foreach ($localRooms as $room) {
                $roomCode = $room['room_code'] ?? '';
                $roomType = $room['room_type'] ?? '';
                $characteristic = $room['characteristic'] ?? '';

                $maxAdults = intval($room['max_adults'] ?? 2);
                $maxChildren = intval($room['max_children'] ?? 0);

                if (empty($roomCode))
                    continue;

                // Build room name - Priority: reference table > type+characteristic > fallback
                $roomName = '';

                // First: Look up proper room name from reference table
                if (!empty($roomCode)) {
                    $roomRef = $hotelbedsDb->get('hotelbeds_rooms', ['description'], [
                        'code' => $roomCode
                    ]);

                    if ($roomRef && !empty($roomRef['description'])) {
                        $roomName = $roomRef['description'];
                    }
                }

                // Second: Build from type + characteristic if reference lookup failed
                if (empty($roomName)) {
                    $parts = [];
                    if (!empty($roomType)) {
                        $parts[] = ucwords(strtolower($roomType));
                    }
                    if (!empty($characteristic)) {
                        $parts[] = ucwords(strtolower(str_replace('-', ' ', $characteristic)));
                    }
                    $roomName = !empty($parts) ? implode(' - ', $parts) : '';
                }

                if (empty($roomName)) {
                    $roomName = 'Room ' . $roomCode;
                }

                // Extract room-specific facilities
                $roomAmenities = [];
                $facilityIds = [];

                if (!empty($room['room_facilities'])) {
                    $roomFacilitiesData = json_decode($room['room_facilities'], true);
                    if (is_array($roomFacilitiesData)) {
                        foreach ($roomFacilitiesData as $fac) {
                            if (!empty($fac['code'])) {
                                $facilityIds[] = (int) $fac['code'];
                            }
                        }
                    }
                }

                // Add hotel-level room facilities (facilityGroupCode = 60)
                $hotelRoomFacilities = $hotelbedsDb->select('hotelbeds_amenities', ['facility_code'], [
                    'hotel_code' => $hotelId,
                    'facility_group_code' => 60
                ]);

                foreach ($hotelRoomFacilities as $fac) {
                    if (!empty($fac['facility_code'])) {
                        $facilityIds[] = (int) $fac['facility_code'];
                    }
                }

                // Remove duplicates
                $facilityIds = array_unique($facilityIds);

                // Fetch facility descriptions from reference table
                if (!empty($facilityIds)) {
                    $facilities = $hotelbedsDb->select('hotelbeds_facilities', ['code', 'description'], [
                        'code' => $facilityIds
                    ]);

                    foreach ($facilities as $facility) {
                        // Skip amenities with invalid names
                        if (!empty($facility['description']) && $facility['description'] !== '1') {
                            $roomAmenities[] = [
                                'id' => $facility['code'],
                                'name' => $facility['description']
                            ];
                        }
                    }
                }

                // Get room images from hotel_images table (filter by room_code)
                $roomImages = [];
                $roomImageRecords = $hotelbedsDb->select('hotelbeds_hotel_images', ['image_url'], [
                    'hotel_code' => $hotelId,
                    'room_code' => $roomCode,
                    'ORDER' => ['image_order' => 'ASC']
                ]);

                foreach ($roomImageRecords as $imgRec) {
                    if (!empty($imgRec['image_url'])) {
                        // Prepend CDN URL if not already complete
                        if (strpos($imgRec['image_url'], 'http') === 0) {
                            $roomImages[] = $imgRec['image_url'];
                        } else {
                            $roomImages[] = $imageBaseUrl . $imgRec['image_url'];
                        }
                    }
                }

                // Ensure at least one image (no_img.jpg fallback)
                if (empty($roomImages)) {
                    $roomImages[] = dirname(root) . '/uploads/no_img.jpg';
                }

                // NO PRICING - Show empty options array
                $placeholderOptions = [];

                // Add room info without pricing
                $roomsResponse[] = [
                    'room_id' => $roomCode,
                    'room_type_id' => $roomCode,
                    'room_name' => $roomName,
                    'room_type' => $roomType ?: 'Standard',
                    'room_images' => $roomImages,
                    'room_main_image' => $roomImages[0],
                    'amenities' => $roomAmenities,
                    'max_adults' => $maxAdults,
                    'max_children' => $maxChildren,
                    'options' => $placeholderOptions,
                    'no_availability' => true
                ];
            }

            if (!empty($roomsResponse)) {
                // error_log("Hotelbeds: Found " . count($roomsResponse) . " rooms from local database");
                // Debug: Log first room pricing
                if (isset($roomsResponse[0]['options'][0])) {
                    $firstOption = $roomsResponse[0]['options'][0];
                }
            }
        }

        // ========================================
        // BUILD RESPONSE
        // ========================================
        $response = [
            'success' => true,
            'data' => [
                'hotel_id' => $hotelId,
                'nights' => $nights,
                'currency' => $currency,
                'rooms' => $roomsResponse,
                'rate_counts' => [
                    'from_api' => $ratesFromApi ?? 0,
                    'skipped_packaging' => $ratesSkippedPackaging ?? 0,
                    'displayed' => array_sum(array_map(static function ($room) {
                        return count($room['options'] ?? []);
                    }, $roomsResponse)),
                ],
            ]
        ];

        // Add informative message if no rooms found or using fallback data
        if (empty($roomsResponse)) {
            $response['data']['message'] = 'No rooms available. This hotel has no inventory in Hotelbeds for the selected dates. Try different dates or another hotel.';
        } else if (isset($roomsResponse[0]['no_availability'])) {
            $response['data']['message'] = 'Room information available. Contact us for live pricing and availability for these dates.';
            $response['data']['requires_inquiry'] = true;
        }

        // Clear buffer and output JSON
        ob_clean();
        echo json_encode($response);
        ob_end_flush();

    } catch (Exception $e) {
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => function_exists('hotelbedsUserFacingError')
                ? hotelbedsUserFacingError('Server error: ' . $e->getMessage())
                : 'One or more rates are no longer available. Please search again.',
        ]);
        ob_end_flush();
    }

});

/**
 * Single-rate CheckRate (kept for compatibility / booking flows).
 */
function checkRates($parms)
{
    $rateKey = $parms['rateKey'] ?? '';
    if ($rateKey === '') {
        return json_encode(['error' => 'rateKey is required']);
    }

    $batch = checkRatesBatch([
        'c1' => $parms['c1'] ?? '',
        'c2' => $parms['c2'] ?? '',
        'rateKeys' => [$rateKey],
        'env' => $parms['env'] ?? 'test',
        'use_mtls' => $parms['use_mtls'] ?? false
    ]);

    if (!isset($batch[$rateKey])) {
        return json_encode(['error' => 'CheckRate failed']);
    }

    return json_encode([
        'hotel' => [
            'rooms' => [
                [
                    'rates' => [$batch[$rateKey]]
                ]
            ]
        ]
    ]);
}

/**
 * Stable prefix of a Hotelbeds rateKey for matching CheckRate responses when
 * the supplier rewrites the volatile suffix after "@".
 */
function hotelbedsRateKeyMatchSignature(?string $rateKey): string
{
    if ($rateKey === null || trim($rateKey) === '') {
        return '';
    }

    $rateKey = trim($rateKey);
    $atPos = strpos($rateKey, '@');
    if ($atPos !== false) {
        $rateKey = substr($rateKey, 0, $atPos);
    }

    $parts = explode('|', $rateKey);

    return implode('|', array_slice($parts, 0, 10));
}

/**
 * Room code segment from a Hotelbeds rateKey (pipe index 5).
 */
function hotelbedsRateKeyRoomCode(?string $rateKey): string
{
    if ($rateKey === null || trim($rateKey) === '') {
        return '';
    }

    $parts = explode('|', trim($rateKey));

    return trim((string) ($parts[5] ?? ''));
}

/**
 * Extract the occupancy quoted by a Hotelbeds rateKey.
 *
 * The stable rateKey section is `...|board|contract|rooms~adults~children|
 * childAge~childAge|...`. The API also returns adults/children separately,
 * but the rateKey child ages are needed to distinguish otherwise identical
 * requested rooms.
 */
function hotelbedsRateKeyOccupancy(?string $rateKey): array
{
    $occupancy = [
        'rooms' => 0,
        'adults' => 0,
        'children' => 0,
        'child_ages' => [],
    ];

    if ($rateKey === null || trim($rateKey) === '') {
        return $occupancy;
    }

    $parts = explode('|', trim($rateKey));
    $counts = explode('~', (string) ($parts[9] ?? ''));
    if (count($counts) < 3) {
        return $occupancy;
    }

    $occupancy['rooms'] = max(0, (int) $counts[0]);
    $occupancy['adults'] = max(0, (int) $counts[1]);
    $occupancy['children'] = max(0, (int) $counts[2]);

    if ($occupancy['children'] > 0 && !empty($parts[10])) {
        $ages = preg_split('/[~,]/', (string) $parts[10]) ?: [];
        $occupancy['child_ages'] = array_values(array_map('intval', array_filter(
            $ages,
            static function ($age) {
                return $age !== '';
            }
        )));
        sort($occupancy['child_ages']);
    }

    return $occupancy;
}

/**
 * Board code segment from a Hotelbeds rateKey (pipe index 7).
 */
function hotelbedsRateKeyBoardCode(?string $rateKey): string
{
    if ($rateKey === null || trim($rateKey) === '') {
        return '';
    }

    $parts = explode('|', trim($rateKey));

    return strtoupper(trim((string) ($parts[7] ?? '')));
}

/**
 * Room + contract + board tuple for matching CheckRate rows when rateKey suffix changes.
 */
function hotelbedsRateKeyMatchTuple(?string $rateKey): string
{
    if ($rateKey === null || trim($rateKey) === '') {
        return '';
    }

    $rateKey = trim($rateKey);
    $atPos = strpos($rateKey, '@');
    if ($atPos !== false) {
        $rateKey = substr($rateKey, 0, $atPos);
    }

    $parts = explode('|', $rateKey);

    return implode('|', array_slice($parts, 0, 8));
}

/**
 * Map CheckRate hotel payload to requested rateKeys without positional guessing.
 *
 * @param array<int, string> $requestedKeys
 * @return array<string, array>
 */
function hotelbedsMapCheckRateResponses(array $requestedKeys, array $hotelRooms): array
{
    $returnedRatesByKey = [];

    foreach ($hotelRooms as $room) {
        if (empty($room['rates']) || !is_array($room['rates'])) {
            continue;
        }

        foreach ($room['rates'] as $updatedRate) {
            if (!is_array($updatedRate)) {
                continue;
            }

            $returnedKey = trim((string) ($updatedRate['rateKey'] ?? ''));
            if ($returnedKey !== '') {
                $returnedRatesByKey[$returnedKey] = $updatedRate;
            }
        }
    }

    $checkedRatesByKey = [];

    foreach ($requestedKeys as $requestedKey) {
        $requestedKey = trim((string) $requestedKey);
        if ($requestedKey === '') {
            continue;
        }

        if (isset($returnedRatesByKey[$requestedKey])) {
            $checkedRatesByKey[$requestedKey] = $returnedRatesByKey[$requestedKey];
            continue;
        }

        $requestedTuple = hotelbedsRateKeyMatchTuple($requestedKey);
        if ($requestedTuple !== '') {
            foreach ($returnedRatesByKey as $returnedKey => $updatedRate) {
                if (hotelbedsRateKeyMatchTuple($returnedKey) === $requestedTuple) {
                    $checkedRatesByKey[$requestedKey] = $updatedRate;
                    if ($returnedKey !== $requestedKey) {
                        $checkedRatesByKey[$returnedKey] = $updatedRate;
                    }
                    continue 2;
                }
            }
        }

        $requestedSig = hotelbedsRateKeyMatchSignature($requestedKey);
        if ($requestedSig === '') {
            continue;
        }

        foreach ($returnedRatesByKey as $returnedKey => $updatedRate) {
            if (hotelbedsRateKeyMatchSignature($returnedKey) !== $requestedSig) {
                continue;
            }

            $checkedRatesByKey[$requestedKey] = $updatedRate;
            if ($returnedKey !== $requestedKey) {
                $checkedRatesByKey[$returnedKey] = $updatedRate;
            }
            break;
        }
    }

    // One-key CheckRate: if Hotelbeds rewrote the key and prefix matching missed,
    // the single returned rate is the valuation for the requested key.
    if (count($requestedKeys) === 1 && count($returnedRatesByKey) === 1) {
        $onlyRequested = trim((string) $requestedKeys[0]);
        if ($onlyRequested !== '' && empty($checkedRatesByKey[$onlyRequested])) {
            $onlyReturned = array_values($returnedRatesByKey)[0];
            $checkedRatesByKey[$onlyRequested] = $onlyReturned;
        }
    }

    return $checkedRatesByKey;
}

/**
 * Book-path CheckRate — one rateKey per Hotelbeds /checkrates call (guide §90).
 * Use for issue.php and POST /stays/hotelbeds/checkrates. Listing may batch via checkRatesBatch().
 *
 * @return array<string, array> Map of original rateKey => updated rate payload
 */
function checkRatesBookPath($parms, &$errors = null)
{
    if (!is_array($errors)) {
        $errors = [];
    }

    $rateKeys = array_values(array_unique(array_filter($parms['rateKeys'] ?? [])));
    if (empty($rateKeys)) {
        return [];
    }

    $checkedRatesByKey = [];
    foreach ($rateKeys as $rateKey) {
        $partial = checkRatesBatch(array_merge($parms, ['rateKeys' => [$rateKey]]), $errors);
        if (!empty($partial[$rateKey]) && is_array($partial[$rateKey])) {
            $checkedRatesByKey[$rateKey] = $partial[$rateKey];
        }
    }

    return $checkedRatesByKey;
}

/**
 * Batch CheckRate for RECHECK rateKeys on rooms listing (performance).
 * Hotelbeds allows up to 10 rateKeys per call — we chunk and run
 * chunks in parallel via curl_multi so rooms listing stays fast.
 *
 * For book/issue paths use checkRatesBookPath() — one key per call.
 *
 * @return array<string, array> Map of original rateKey => updated rate payload
 */
function hotelbedsNormalizeCheckRateError($httpCode, $curlError, $decoded, $rawBody = '')
{
    $apiError = null;
    if (is_array($decoded) && array_key_exists('error', $decoded)) {
        $apiError = $decoded['error'];
    }

    $message = '';
    if (is_array($apiError)) {
        $message = trim((string) ($apiError['message'] ?? $apiError['description'] ?? ''));
    } elseif (is_string($apiError) && $apiError !== '') {
        $message = trim($apiError);
    } elseif (is_string($curlError) && $curlError !== '') {
        $message = trim($curlError);
    } elseif (is_string($rawBody) && $rawBody !== '' && strlen($rawBody) < 400) {
        $message = trim($rawBody);
    }

    if ($message === '' && (int) $httpCode > 0) {
        $message = 'Hotelbeds CheckRate HTTP ' . (int) $httpCode;
    }

    return [
        'http_code' => (int) $httpCode,
        'curl_error' => (string) $curlError,
        'api_error' => $apiError,
        'message' => $message,
    ];
}

function checkRatesBatch($parms, &$errors = null)
{
    global $db;

    if (!is_array($errors)) {
        $errors = [];
    }

    $apikey = $parms['c1'] ?? '';
    $secret = $parms['c2'] ?? '';
    $useMtls = !empty($parms['use_mtls']);
    $rateKeys = array_values(array_unique(array_filter($parms['rateKeys'] ?? [])));

    if (empty($apikey) || empty($secret) || empty($rateKeys)) {
        return [];
    }

    if ($useMtls) {
        $url = function_exists('hotelbedsBookingApiBaseUrl')
            ? hotelbedsBookingApiBaseUrl(($parms['env'] ?? '') === 'test' ? 'test' : 'live', true) . '/checkrates'
            : ((($parms['env'] ?? '') === 'test')
                ? 'https://api-mtls.test.hotelbeds.com/hotel-api/1.0/checkrates'
                : 'https://api-mtls.hotelbeds.com/hotel-api/1.0/checkrates');
    } else {
        $url = function_exists('hotelbedsBookingApiBaseUrl')
            ? hotelbedsBookingApiBaseUrl(($parms['env'] ?? '') === 'test' ? 'test' : 'live', false) . '/checkrates'
            : ((($parms['env'] ?? '') === 'test')
                ? 'https://api.test.hotelbeds.com/hotel-api/1.0/checkrates'
                : 'https://api.hotelbeds.com/hotel-api/1.0/checkrates');
    }

    $canUseMtls = $useMtls && function_exists('hotelbedsMtlsCertsAvailable')
        ? hotelbedsMtlsCertsAvailable()
        : ($useMtls
            && file_exists(__DIR__ . '/certs/client.pem')
            && file_exists(__DIR__ . '/certs/client.key')
            && file_exists(__DIR__ . '/certs/ca_bundle.crt'));

    // Max 10 rateKeys per Hotelbeds CheckRate request
    $chunks = array_chunk($rateKeys, 10);
    $mh = curl_multi_init();
    $handles = [];
    $chunkPayloads = [];

    foreach ($chunks as $chunkIndex => $chunk) {
        $payload = ['rooms' => []];
        foreach ($chunk as $rateKey) {
            $payload['rooms'][] = ['rateKey' => $rateKey];
        }
        $chunkPayloads[$chunkIndex] = $payload;

        $xSignature = hash('sha256', $apikey . $secret . time());
        $curl = curl_init();

        if (function_exists('hotelbedsApplyMtlsCurlOptions')) {
            hotelbedsApplyMtlsCurlOptions($curl, $canUseMtls);
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Api-key: ' . $apikey,
                'X-Signature: ' . $xSignature,
                'Accept: application/json',
                'Accept-Encoding: gzip',
                'Content-Type: application/json'
            ],
        ]);

        $handles[$chunkIndex] = $curl;
        curl_multi_add_handle($mh, $curl);
    }

    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $status === CURLM_OK);

    $checkedRatesByKey = [];
    $logSetting = function_exists('log_setting') ? log_setting($db, 'hotelbeds') : '0';

    foreach ($handles as $chunkIndex => $curl) {
        $response = curl_multi_getcontent($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        $payload = $chunkPayloads[$chunkIndex] ?? [];

        $apiResponseDecoded = !empty($response) ? json_decode($response, true) : ['error' => 'Empty response'];
        if (!empty($error)) {
            $apiResponseDecoded = ['error' => $error, 'http' => $httpCode];
        }

        if ($logSetting == '1') {
            $path = function_exists('hotelbedsLogDir') ? hotelbedsLogDir() : (__DIR__ . '/logs');
            logApiCall('hotelbeds_checkrate', $payload, $apiResponseDecoded, $httpCode, $path, 'Hotelbeds_CheckRates');
        }

        curl_multi_remove_handle($mh, $curl);
        curl_close($curl);

        if ($error || $httpCode !== 200 || empty($response)) {
            $decodedError = is_array($apiResponseDecoded) ? $apiResponseDecoded : [];
            $chunkError = hotelbedsNormalizeCheckRateError($httpCode, $error, $decodedError, (string) $response);

            // Batch failed — fall back to one-by-one for this chunk so one
            // bad rateKey does not drop the rest (Hotelbeds multi-key caveat).
            foreach (($payload['rooms'] ?? []) as $roomReq) {
                $singleKey = $roomReq['rateKey'] ?? '';
                if ($singleKey === '') {
                    continue;
                }
                $single = checkRatesSingle($apikey, $secret, $singleKey, $url, $canUseMtls);
                if (is_array($single)) {
                    $checkedRatesByKey[$singleKey] = $single;
                    continue;
                }
                $errors[$singleKey] = $chunkError;
            }
            continue;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || empty($decoded['hotel']['rooms']) || !is_array($decoded['hotel']['rooms'])) {
            continue;
        }

        $requestedKeys = [];
        foreach (($payload['rooms'] ?? []) as $roomReq) {
            if (!empty($roomReq['rateKey'])) {
                $requestedKeys[] = $roomReq['rateKey'];
            }
        }

        $mapped = hotelbedsMapCheckRateResponses($requestedKeys, $decoded['hotel']['rooms']);
        foreach ($mapped as $key => $updatedRate) {
            $checkedRatesByKey[$key] = $updatedRate;
        }
    }

    curl_multi_close($mh);

    return $checkedRatesByKey;
}

/**
 * One rateKey CheckRate used as fallback when a batch call fails.
 */
function checkRatesSingle($apikey, $secret, $rateKey, $url, $canUseMtls)
{
    $payload = ['rooms' => [['rateKey' => $rateKey]]];
    $xSignature = hash('sha256', $apikey . $secret . time());
    $curl = curl_init();

    if (function_exists('hotelbedsApplyMtlsCurlOptions')) {
        hotelbedsApplyMtlsCurlOptions($curl, (bool) $canUseMtls);
    }

    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Api-key: ' . $apikey,
            'X-Signature: ' . $xSignature,
            'Accept: application/json',
            'Accept-Encoding: gzip',
            'Content-Type: application/json'
        ],
    ]);

    $response = curl_exec($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($error || $httpCode !== 200 || empty($response)) {
        return null;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || empty($decoded['hotel']['rooms'][0]['rates'][0])) {
        return null;
    }

    return $decoded['hotel']['rooms'][0]['rates'][0];
}

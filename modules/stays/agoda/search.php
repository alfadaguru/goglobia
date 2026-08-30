<?php
// ============================================================================
// AGODA HOTEL SEARCH API ENDPOINT - COMPLETE DOCUMENTATION
// ============================================================================
//
// PURPOSE:
// Search Agoda hotels using real-time API with optional database enrichment,
// dynamic B2B/B2C markup application, and currency conversion.
// Response structure matches Hotelbeds format for consistent frontend handling.
//
// ENDPOINT: POST /stays/agoda/search
//
// ============================================================================
// REQUEST PARAMETERS (matching Hotelbeds search structure)
// ============================================================================
//
// 1. destination (string)         - City/Location name (e.g., "Bangkok", "Singapore")
//                                   Searches agoda_destinations and agoda_hotels.city
//
// 2. destination_code (string)    - Optional: City ID code (e.g., "9186", "4064")
//                                   Agoda uses numeric city IDs instead of airport codes
//
// 3. checkin (string)             - Check-in date in DD-MM-YYYY format
//                                   Frontend: searchParams.checkin
//                                   Converted to YYYY-MM-DD for Agoda API
//
// 4. checkout (string)            - Check-out date in DD-MM-YYYY format
//                                   Frontend: searchParams.checkout
//                                   Converted to YYYY-MM-DD for Agoda API
//
// 5. nationality (string)         - Guest nationality ISO code (e.g., "US", "GB")
//                                   Frontend: searchParams.nationality
//                                   Optional for Agoda API
//
// 6. rooms (integer)              - Number of rooms requested (default: 1)
//                                   Frontend: searchParams.rooms
//
// 7. adults (integer)             - Total adults across all rooms
//                                   Frontend: searchParams.adults
//
// 8. children (integer)           - Total children across all rooms
//                                   Frontend: searchParams.children
//
// 9. rooms_data (JSON string)     - Detailed room configuration array
//                                   Format: [{"adults":2,"children":1,"childAges":[5]}]
//                                   Note: Agoda API uses simplified occupancy structure
//
// 10. currency (string)           - Display currency code (e.g., "USD", "EUR")
//                                   Source: $searchSessionData['app_currency']
//                                   Default: USD
//
// 11. star_rating (string)        - Optional: Filter by star rating (1-5 or "any")
//                                   Passed as minimumStarRating to Agoda API
//
// 12. page (integer)              - Page number for pagination (default: 1)
//                                   Frontend: searchParams.page
//                                   Used for infinite scroll loading
//
// 13. per_page (integer)          - Results per page (default: 25, max: 100)
//                                   Frontend: searchParams.per_page
//                                   Controls how many hotels load at once
//
// 14. price_from (integer)        - Optional: Minimum daily rate filter
//                                   Default: 20
//
// 15. price_to (integer)          - Optional: Maximum daily rate filter
//                                   Default: 10000
//
// ============================================================================
// PRICING & MARKUP LOGIC (Same as Hotelbeds/manual hotel system)
// ============================================================================
//
// STEP 1: BASE PRICE EXTRACTION
// - Prices fetched from Agoda Affiliate API (real-time availability)
// - API returns "dailyRate" (per night price)
// - Currency returned by API (usually hotel's local currency or requested currency)
//
// STEP 2: MARKUP APPLICATION (via MARKUP() function)
// - Function Location: modules/helpers.php (lines 29-118)
// - Module type: 'stays' (retrieves agoda markup from modules table)
// - Fields: markup_b2b, markup_b2c, markup_type_b2b, markup_type_b2c
// - User type: B2B (agents) vs B2C (customers)
//
// MARKUP ORDER (CRITICAL - SAME AS HOTELBEDS):
//   a) Apply markup to base price in ORIGINAL currency
//      Example: $50 + 20% B2C markup = $60
//   b) Convert marked-up price to display currency
//      Example: $60 × 1.10 EUR rate = €66
//
// STEP 3: CURRENCY CONVERSION
// - Exchange rates from `currencies` table
// - Base: USD (rate = 1.0)
// - Formula: (price / fromRate) × toRate
//
// STEP 4: TOTAL PRICE CALCULATION
// - Total = (marked_up_converted_per_night) × nights × rooms
//
// ============================================================================

$router->post('stays/agoda/search', function() use ($db) {
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
    $destination = $_POST['destination'] ?? $_POST['city'] ?? '';
    $destination_code = $_POST['destination_code'] ?? '';

    $page = max(1, (int)($_POST['page'] ?? 1));
    $per_page = min(100, max(1, (int)($_POST['per_page'] ?? 25)));

    $star_rating = $_POST['star_rating'] ?? 'any';
    $checkin = $_POST['checkin'] ?? '';
    $checkout = $_POST['checkout'] ?? '';
    $rooms = (int)($_POST['rooms'] ?? 1);
    $adults = (int)($_POST['adults'] ?? 2);
    $children = (int)($_POST['children'] ?? $_POST['childs'] ?? 0);
    $nationality = $_POST['nationality'] ?? 'US';
    $rooms_data_json = $_POST['rooms_data'] ?? '[]';
    $currency = $_POST['currency'] ?? 'USD';
    $hotel_name = trim($_POST['hotel_name'] ?? '');

    $sessionCurrency = isset($searchSessionData['app_currency']) ? $searchSessionData['app_currency'] : $currency;

    // ========================================
    // PARSE ROOMS DATA
    // ========================================
    $rooms_data = [];
    try {
        $rooms_data = json_decode($rooms_data_json, true);
        if (!is_array($rooms_data)) {
            $rooms_data = [];
        }
    } catch (Exception $e) {
        error_log('Agoda search - Failed to parse rooms_data: ' . $e->getMessage());
        $rooms_data = [];
    }

    // Extract child ages for logging
    $all_child_ages = [];
    foreach ($rooms_data as $room_index => $room) {
        if (isset($room['children']) && $room['children'] > 0 && !empty($room['childAges'])) {
            foreach ($room['childAges'] as $age) {
                $all_child_ages[] = [
                    'room' => $room_index + 1,
                    'age' => (int)$age
                ];
            }
        }
    }

    // ========================================
    // SEARCH REQUEST LOGGING
    // ========================================
    try {
        $user_id = isset($searchSessionData['user_id']) ? $searchSessionData['user_id'] : 'guest';
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['HTTP_CLIENT_IP'] ?? 'unknown'));

        $search_params = [
            'destination' => $destination,
            'destination_code' => $destination_code,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'rooms' => $rooms,
            'adults' => $adults,
            'children' => $children,
            'nationality' => $nationality,
            'rooms_data' => $rooms_data,
            'child_ages' => $all_child_ages,
            'currency' => $sessionCurrency,
            'star_rating' => $star_rating,
            'supplier' => 'agoda'
        ];

        $db->insert('logs_searches', [
            'user_id' => (string)$user_id,
            'module' => 'stays',
            'request' => json_encode($search_params),
            'created_at' => date('Y-m-d H:i:s'),
            'ip' => $user_ip
        ]);
    } catch (Exception $e) {
        error_log('Agoda search logging failed: ' . $e->getMessage());
    }

    // ========================================
    // DATE CALCULATION & FORMAT CONVERSION
    // ========================================
    $number_of_nights = 1;
    $checkin_date = '';
    $checkout_date = '';
    
    if (!empty($checkin) && !empty($checkout)) {
        try {
            $checkin_parts = explode('-', $checkin);
            $checkout_parts = explode('-', $checkout);

            if (count($checkin_parts) === 3 && count($checkout_parts) === 3) {
                $checkin_date = "{$checkin_parts[2]}-{$checkin_parts[1]}-{$checkin_parts[0]}";
                $checkout_date = "{$checkout_parts[2]}-{$checkout_parts[1]}-{$checkout_parts[0]}";
                
                $date1 = new DateTime($checkin_date);
                $date2 = new DateTime($checkout_date);
                $interval = $date1->diff($date2);
                $number_of_nights = (int)$interval->days;

                if ($number_of_nights < 1) {
                    $number_of_nights = 1;
                }
            }
        } catch (Exception $e) {
            $number_of_nights = 1;
        }
    }

    // ========================================
    // AGODA MODULE & DATABASE CONNECTION
    // ========================================
    $module = $db->get('modules', '*', [
        'name' => 'agoda',
        'type' => 'stays'
    ]);

    if (!$module) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }
    
    $dbHost = $module['host'] ?? 'localhost';
    $dbName = $module['database'] ?? '';
    $dbUser = $module['username'] ?? 'root';
    $dbPass = $module['password'] ?? '';
    $apiKey = $module['c1'] ?? '';
    $apiSecret = $module['c2'] ?? '';
    $environment = $module['env'] ?? 'live';

    if (empty($dbName)) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    $moduleCurrency = !empty($module['currency']) ? $module['currency'] : 'USD';

    // Create Agoda database connection
    $agodaDb = null;
    try {
        $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
        $agodaPDO = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);

        $agodaDb = new Medoo\Medoo(['type' => 'mysql', 'pdo' => $agodaPDO]);
    } catch (Exception $e) {
        error_log('Agoda DB Error: ' . $e->getMessage());
    }

    if (!$agodaDb) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    // ========================================
    // DESTINATION LOOKUP (City ID for Agoda)
    // ========================================
    $cityIds = [];

    if (!empty($destination_code)) {
        $cityIds[] = $destination_code;
    }
    
    if (!empty($destination)) {
        $destRecords = $agodaDb->select('agoda_regions', ['region_id'], [
            'OR' => [
                'region_name[~]' => $destination,
                'region_id[~]' => $destination
            ]
        ]);

        foreach ($destRecords as $dest) {
            if (!in_array($dest['region_id'], $cityIds)) {
                $cityIds[] = $dest['region_id'];
            }
        }

        $cityIdFromHotels = $agodaDb->select('agoda_hotels', 'city_id', [
            'city[~]' => $destination,
            'GROUP' => 'city_id'
        ]);
        
        foreach ($cityIdFromHotels as $cityId) {
            if (!in_array($cityId, $cityIds)) {
                $cityIds[] = $cityId;
            }
        }
    }
    
    if (empty($cityIds)) {
        ob_end_clean();
        header('Content-Type: application/json');
        header('X-Total-Results: 0');
        header('X-Destination-Total: 0');
        header('X-Total-Pages: 0');
        header('X-Current-Page: ' . $page);
        header('X-Per-Page: ' . $per_page);
        header('X-Has-More: false');
        echo json_encode([]);
        exit;
    }

    // ========================================
    // GET HOTEL IDs FROM DATABASE
    // ========================================
    $whereConditions = ['city_id' => $cityIds];
    
    if ($star_rating !== 'any') {
        $whereConditions['star_rating'] = (int)$star_rating;
    }

    if (!empty($hotel_name)) {
        $whereConditions['OR'] = [
            'hotel_name[~]' => '%' . $hotel_name . '%',
            'hotel_id' => $hotel_name
        ];
    }

    $allHotelIds = $agodaDb->select('agoda_hotels', 'hotel_id', $whereConditions);

    if (empty($allHotelIds)) {
        ob_end_clean();
        header('Content-Type: application/json');
        header('X-Total-Results: 0');
        header('X-Destination-Total: 0');
        header('X-Total-Pages: 0');
        header('X-Current-Page: ' . $page);
        header('X-Per-Page: ' . $per_page);
        header('X-Has-More: false');
        echo json_encode([]);
        exit;
    }

    // ========================================
    // APPLY PAGINATION TO HOTEL IDs
    // ========================================
    $totalHotels = count($allHotelIds);
    $totalPages = ceil($totalHotels / $per_page);
    $offset = ($page - 1) * $per_page;
    
    $paginatedHotelIds = array_slice($allHotelIds, $offset, $per_page);
    $hasMore = ($page * $per_page) < $totalHotels;

    // ========================================
    // MAKE SINGLE API CALL FOR ALL PAGINATED HOTELS
    // ========================================
    $formattedHotels = [];

    if (!empty($apiKey) && !empty($apiSecret) && !empty($paginatedHotelIds)) {
        
        $baseUrl = 'http://affiliateapi7643.agoda.com/affiliateservice/lt_v1';
        
        $price_from = !empty($_POST['price_from']) ? (int)$_POST['price_from'] : 20;
        $price_to = !empty($_POST['price_to']) ? (int)$_POST['price_to'] : 10000;

        // Single API call with array of hotel IDs
        $apiPayload = [
            "criteria" => [
                "additional" => [
                    "currency" => strtoupper($sessionCurrency),
                    "discountOnly" => false,
                    "language" => "en-us",
                    "occupancy" => [
                        "numberOfAdult" => $adults,
                        "numberOfChildren" => $children
                    ]
                ],
                "checkInDate" => $checkin_date,
                "checkOutDate" => $checkout_date,
                "hotelId" => array_map('intval', $paginatedHotelIds)
            ]
        ];

        $header = $apiKey . ":" . $apiSecret;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($apiPayload),
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $header,
                'Content-Type: application/json',
                'Accept-Encoding: gzip, deflate, br',
                'Connection: keep-alive'
            ]
        ]);

        $apiResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        $log_setting = log_setting($db, 'agoda');
        
        if ($log_setting == '1') {
            $apiResponseDecoded = !empty($apiResponse) ? json_decode($apiResponse, true) : ['error' => 'Empty response'];
            $path = __DIR__ . '/../agoda/logs';
            $type = 'Agoda_Search';
            logApiCall('agoda_search', $apiPayload, $apiResponseDecoded, $httpCode, $path, $type);
        }

        // ========================================
        // PARSE API RESPONSE & ENRICH WITH DB
        // ========================================
        if ($httpCode === 200 && !empty($apiResponse)) {
            $apiData = json_decode($apiResponse, true);

            if (isset($apiData['results']) && is_array($apiData['results'])) {
                foreach ($apiData['results'] as $apiHotel) {
                    $hotelIdFromApi = (string)$apiHotel['hotelId'];
                    $apiCurrency = $apiHotel['currency'] ?? 'USD';
                    
                    $dailyRate = floatval($apiHotel['dailyRate'] ?? 0);

                    if ($dailyRate > 0) {
                        // Apply markup & currency conversion
                        $price_per_night_markup = MARKUP($dailyRate, $module, $db, $apiCurrency, $sessionCurrency);

                        $total_price = $price_per_night_markup['price'] * $number_of_nights * $rooms;

                        $total_price_markup = [
                            'price' => round($total_price, 2),
                            'markup' => $price_per_night_markup['markup'] * $number_of_nights * $rooms,
                            'markup_percentage' => $price_per_night_markup['markup_percentage'],
                            'markup_type' => $price_per_night_markup['markup_type'],
                            'markup_value' => $price_per_night_markup['markup_value'],
                            'base_price' => round($dailyRate * $number_of_nights * $rooms, 2),
                            'converted_base_price' => round($price_per_night_markup['converted_base_price'] * $number_of_nights * $rooms, 2)
                        ];

                        // Get hotel details from database
                        $hotel = $agodaDb->get('agoda_hotels', '*', ['hotel_id' => $hotelIdFromApi]);

                        if (!$hotel) {
                            $hotel = [];
                        }

                        // Hotel basic info (API first, DB fallback)
                        $hotelName = $apiHotel['hotelName'] ?? (!empty($hotel['name']) ? $hotel['name'] : 'Hotel ' . $hotelIdFromApi);
                        $hotelCity = !empty($hotel['city']) ? $hotel['city'] : $destination;
                        $hotelAddress = !empty($hotel['address']) ? $hotel['address'] : '';
                        $stars = floatval($apiHotel['starRating'] ?? (!empty($hotel['star_rating']) ? $hotel['star_rating'] : 0));
                        $latitude = floatval($apiHotel['latitude'] ?? (!empty($hotel['latitude']) ? $hotel['latitude'] : 0));
                        $longitude = floatval($apiHotel['longitude'] ?? (!empty($hotel['longitude']) ? $hotel['longitude'] : 0));
                        $reviewScore = floatval($apiHotel['reviewScore'] ?? 0);
                        $reviewCount = (int)($apiHotel['reviewCount'] ?? 0);
                        $includeBreakfast = !empty($apiHotel['includeBreakfast']);
                        $freeWifi = !empty($apiHotel['freeWifi']);
                        $discountPercentage = (int)($apiHotel['discountPercentage'] ?? 0);

                        // Get hotel images (API primary + DB enrichment)
                        $hotelImages = [];
                        $hotelImage = '';

                        if (!empty($apiHotel['imageURL'])) {
                            $hotelImage = $apiHotel['imageURL'];
                            $hotelImages[] = $apiHotel['imageURL'];
                        }

                        if ($hotel) {
                            $imageRecords = $agodaDb->select('agoda_hotel_images', ['image_url'], [
                                'hotel_id' => $hotelIdFromApi,
                                'ORDER' => ['image_order' => 'ASC'],
                                'LIMIT' => 9
                            ]);

                            foreach ($imageRecords as $img) {
                                if (!in_array($img['image_url'], $hotelImages)) {
                                    $hotelImages[] = $img['image_url'];
                                }
                            }
                        }

                        if (empty($hotelImage) && !empty($hotel['image_url'])) {
                            $hotelImage = $hotel['image_url'];
                            if (!in_array($hotel['image_url'], $hotelImages)) {
                                array_unshift($hotelImages, $hotel['image_url']);
                            }
                        }

                        // Build room images
                        $roomImages = [];
                        if (!empty($apiHotel['imageURL'])) {
                            $roomImages[] = ['url' => $apiHotel['imageURL'], 'default' => true];
                        }

                        if ($hotel) {
                            $roomImageRecords = $agodaDb->select('agoda_hotel_images', ['image_url'], [
                                'hotel_id' => $hotelIdFromApi,
                                'ORDER' => ['image_order' => 'ASC'],
                                'LIMIT' => 4
                            ]);

                            foreach ($roomImageRecords as $img) {
                                $exists = false;
                                foreach ($roomImages as $existing) {
                                    if ($existing['url'] === $img['image_url']) {
                                        $exists = true;
                                        break;
                                    }
                                }
                                if (!$exists) {
                                    $roomImages[] = ['url' => $img['image_url'], 'default' => false];
                                }
                            }
                        }

                        // Get amenities (API + DB)
                        $roomAmenities = [];
                        
                        if ($freeWifi) {
                            $roomAmenities[] = [
                                'id' => 1,
                                'name' => 'Free WiFi',
                                'icon' => 'wifi',
                                'category' => 'internet',
                                'is_free' => 1
                            ];
                        }
                        
                        if ($includeBreakfast) {
                            $roomAmenities[] = [
                                'id' => 2,
                                'name' => 'Breakfast Included',
                                'icon' => 'free_breakfast',
                                'category' => 'dining',
                                'is_free' => 1
                            ];
                        }

                        if ($hotel) {
                            $amenityRecords = $agodaDb->select('agoda_hotel_amenities', [
                                '[>]agoda_amenities' => ['amenity_id' => 'amenity_id']
                            ], [
                                'agoda_amenities.amenity_name',
                                'agoda_amenities.icon',
                                'agoda_amenities.category',
                                'agoda_hotel_amenities.is_free'
                            ], [
                                'agoda_hotel_amenities.hotel_id' => $hotelIdFromApi,
                                'LIMIT' => 8
                            ]);

                            foreach ($amenityRecords as $amenity) {
                                if (!empty($amenity['amenity_name'])) {
                                    $roomAmenities[] = [
                                        'id' => count($roomAmenities) + 1,
                                        'name' => $amenity['amenity_name'],
                                        'icon' => $amenity['icon'] ?? '',
                                        'category' => $amenity['category'] ?? '',
                                        'is_free' => (int)($amenity['is_free'] ?? 0)
                                    ];
                                }
                            }
                        }

                        // Hotel amenities
                        $hotelAmenities = [];
                        if ($hotel) {
                            $hotelAmenityRecords = $agodaDb->select('agoda_hotel_amenities', [
                                '[>]agoda_amenities' => ['amenity_id' => 'amenity_id']
                            ], [
                                'agoda_amenities.amenity_name',
                                'agoda_amenities.icon',
                                'agoda_amenities.category',
                                'agoda_hotel_amenities.is_free',
                                'agoda_amenities.is_popular'
                            ], [
                                'agoda_hotel_amenities.hotel_id' => $hotelIdFromApi,
                                'ORDER' => ['agoda_amenities.is_popular' => 'DESC'],
                                'LIMIT' => 20
                            ]);

                            foreach ($hotelAmenityRecords as $amenity) {
                                if (!empty($amenity['amenity_name'])) {
                                    $hotelAmenities[] = [
                                        'id' => count($hotelAmenities) + 1,
                                        'name' => $amenity['amenity_name'],
                                        'icon' => $amenity['icon'] ?? '',
                                        'category' => $amenity['category'] ?? '',
                                        'is_free' => (int)($amenity['is_free'] ?? 0),
                                        'is_popular' => (int)($amenity['is_popular'] ?? 0)
                                    ];
                                }
                            }
                        }
                        
                        // Build room options
                        $room_options_data = [
                            [
                                'room_name' => 'Standard Room',
                                'room_type_id' => $hotelIdFromApi . '_standard',
                                'room_id' => $hotelIdFromApi . '_standard',
                                'amenities' => $roomAmenities,
                                'room_images' => $roomImages,
                                'room_main_image' => !empty($roomImages) ? $roomImages[0]['url'] : '',
                                'options' => [
                                    [
                                        'option_index' => 0,
                                        'price_per_night' => $price_per_night_markup['price'],
                                        'price_per_night_details' => $price_per_night_markup,
                                        'total_price' => $total_price_markup['price'],
                                        'total_price_details' => $total_price_markup,
                                        'room_id' => $hotelIdFromApi . '_standard',
                                        'rate_key' => $apiHotel['landingURL'] ?? '',
                                        'rate_class' => 'standard',
                                        'rate_type' => 'public',
                                        'board_code' => $includeBreakfast ? 'BB' : 'RO',
                                        'board_name' => $includeBreakfast ? 'Bed & Breakfast' : 'Room Only',
                                        'max_adults' => $adults,
                                        'max_children' => $children,
                                        'breakfast_included' => $includeBreakfast ? 1 : 0,
                                        'refundable' => 1,
                                        'cancellation_free' => 1,
                                        'discount_percentage' => $discountPercentage,
                                        'packaging' => false,
                                        'allotment' => null
                                    ]
                                ],
                                'options_count' => 1,
                                'min_price_per_night' => $price_per_night_markup['price'],
                                'max_adults' => $adults,
                                'max_children' => $children
                            ]
                        ];
                        
                        // Build final response
                        $formattedHotels[] = [
                            'hotel_id' => $hotelIdFromApi,
                            'name' => $hotelName,
                            'img' => $hotelImage,
                            'images' => $hotelImages,
                            'location' => $hotelCity,
                            'address' => $hotelAddress,
                            'stars' => $stars,
                            'rating' => (float)$stars,
                            'review_score' => $reviewScore,
                            'review_count' => $reviewCount,

                            'latitude' => $latitude,
                            'longitude' => $longitude,

                            'display_price' => round($total_price_markup['price'], 2),
                            'display_price_per_night' => round($price_per_night_markup['price'], 2),
                            'actual_price' => round($total_price_markup['price'], 2),
                            'actual_price_per_night' => round($price_per_night_markup['price'], 2),
                            'actual_price_details' => $total_price_markup,
                            'actual_price_per_night_details' => $price_per_night_markup,
                            'currency' => $sessionCurrency,
                            'original_currency' => $apiCurrency,

                            'amenities' => $hotelAmenities,
                            'accommodation_type' => 'Hotel',

                            'room_options' => $room_options_data,
                            'has_available_rooms' => true,

                            'supplier' => 'agoda',
                            'supplier_name' => 'agoda',
                            'supplier_id' => '17',
                            'color' => '#d10a11',

                            'country_code' => !empty($hotel['country_code']) ? $hotel['country_code'] : '',
                            'city_id' => !empty($hotel['city_id']) ? $hotel['city_id'] : '',
                            'chain_code' => !empty($hotel['chain_code']) ? $hotel['chain_code'] : '',

                            'redirect' => $apiHotel['landingURL'] ?? '',

                            'phone' => !empty($hotel['phone']) ? $hotel['phone'] : '',
                            'email' => !empty($hotel['email']) ? $hotel['email'] : '',
                            'website' => !empty($hotel['website']) ? $hotel['website'] : ''
                        ];
                    }
                }
            }
        } else {
            error_log("Agoda API Error - HTTP {$httpCode}: {$curlError}");
            if (!empty($apiResponse)) {
            }
        }
    }
    
    // ========================================
    // CALCULATE PAGINATION & RETURN
    // ========================================
    ob_end_clean();

    header('Content-Type: application/json');
    header('X-Total-Results: ' . $totalHotels);
    header('X-Destination-Total: ' . $totalHotels);
    header('X-Total-Pages: ' . $totalPages);
    header('X-Current-Page: ' . $page);
    header('X-Per-Page: ' . $per_page);
    header('X-Has-More: ' . ($hasMore ? 'true' : 'false'));

    echo json_encode($formattedHotels);
});

<?php
// ============================================================================
// HOTEL SEARCH API ENDPOINT - COMPLETE DOCUMENTATION
// ============================================================================
//
// PURPOSE:
// Main backend endpoint for searching hotels with real-time availability,
// dynamic pricing with B2B/B2C markup, and currency conversion.
//
// ENDPOINT: POST /hotels/search
//
// ============================================================================
// REQUEST PARAMETERS (sent from frontend Alpine.js - stays.php line 301-310)
// ============================================================================
//
// 1. destination (string)         - City/Location name (e.g., "Dubai", "New York")
//                                   Source: $searchSessionData['hotel_destination']
//                                   Frontend: searchParams.destination
//
// 2. destination_code (string)    - City/Airport code (e.g., "DXB", "NYC")
//                                   Source: $searchSessionData['hotel_destination_code']
//                                   Frontend: searchParams.destination_code
//
// 3. checkin (string)             - Check-in date in DD-MM-YYYY format
//                                   Source: $searchSessionData['hotels_checkin_date']
//                                   Default: +3 days from today
//                                   Frontend: searchParams.checkin
//
// 4. checkout (string)            - Check-out date in DD-MM-YYYY format
//                                   Source: $searchSessionData['hotels_checkout_date']
//                                   Default: +4 days from today
//                                   Frontend: searchParams.checkout
//
// 5. nationality (string)         - Guest nationality ISO code (e.g., "US", "GB")
//                                   Source: $searchSessionData['hotel_nationality']
//                                   Frontend: searchParams.nationality
//
// 6. rooms (integer)              - Number of rooms requested (default: 1)
//                                   Source: $searchSessionData['hotel_rooms']
//                                   Frontend: searchParams.rooms
//
// 7. adults (integer)             - Total adults across all rooms
//                                   Calculated from: $searchSessionData['hotel_rooms_data']
//                                   Frontend: searchParams.adults
//
// 8. children (integer)           - Total children across all rooms
//                                   Calculated from: $searchSessionData['hotel_rooms_data']
//                                   Frontend: searchParams.children
//
// 9. rooms_data (JSON string)     - Detailed room configuration array
//                                   Format: [{"adults":2,"children":1,"childAges":[5]}]
//                                   Frontend: JSON.stringify(roomsData)
//
// 10. currency (string)           - Display currency code (e.g., "USD", "SAR")
//                                   Source: $searchSessionData['app_currency']
//                                   Default: USD
//
// 11. star_rating (string)        - Filter by star rating (1-5 or "any")
//                                   Optional parameter for filtering
//
// 12. page (integer)              - Page number for pagination (default: 1)
//                                   Frontend: searchParams.page
//                                   Used for infinite scroll loading
//
// 13. per_page (integer)          - Results per page (default: 25, max: 100)
//                                   Frontend: searchParams.per_page
//                                   Controls how many hotels load at once
//
// ============================================================================
// PRICING & MARKUP LOGIC FLOW
// ============================================================================
//
// STEP 1: BASE PRICE EXTRACTION
// - Base prices stored in `stays_rooms.room_options` JSON field
// - Format: {"price": 50.00, "max_adults": 2, "breakfast_included": 1}
// - Currency stored in `stays.currency` field (defaults to "USD")
//
// STEP 2: MARKUP APPLICATION (via MARKUP() function)
// - Function Location: app/lib/functions.php (lines 1182-1255)
// - Also duplicated in: modules/helpers.php (lines 29-118)
// - Module type: 'stays' (NOT 'hotels' - critical for correct configuration)
// - Markup retrieved from `modules` table where type='stays'
// - Fields used: markup_b2b, markup_b2c, markup_type_b2b, markup_type_b2c
// - User type determines which markup applies (B2B for agents, B2C for customers)
//
// MARKUP CALCULATION ORDER (CRITICAL - DO NOT CHANGE):
//   a) Apply markup to BASE PRICE in ORIGINAL currency first
//      Example: $50 USD + 20% B2C markup = $60 USD
//   b) Then convert marked-up price to display currency
//      Example: $60 USD × 3.75 SAR rate = 225 SAR
//
// STEP 3: CURRENCY CONVERSION
// - Exchange rates from `currencies` table (field: 'rate')
// - Base currency: USD (rate = 1.0)
// - Example rates: SAR = 3.753099, EUR = 0.85
// - Formula: (price / fromRate) × toRate
//
// STEP 4: TOTAL PRICE CALCULATION
// - Per-night price already has markup + conversion applied
// - Total = (marked_up_converted_per_night) × nights × rooms
// - Lines 143-157: Total calculation WITHOUT double conversion
//
// ============================================================================
// DATABASE TABLES USED
// ============================================================================
//
// 1. stays                  - Hotel master data (id, name, location, stars, etc.)
// 2. stays_rooms            - Room types and options with base pricing
// 3. stays_settings         - Room types, amenities, accommodation types
// 4. modules                - Markup configuration (B2B/B2C percentages)
// 5. currencies             - Exchange rates for currency conversion
// 6. logs_searches          - Search request logging (NEW - see below)
//
// ============================================================================
// RESPONSE FORMAT (JSON array of hotel objects)
// ============================================================================
//
// Each hotel object contains:
// - hotel_id, name, location, address, stars, rating
// - display_price              - Final price with markup + currency conversion
// - display_price_per_night    - Per night price with markup + conversion
// - room_options               - Array of available room types with pricing
// - amenities                  - Hotel and room amenities
// - has_available_rooms        - Boolean flag for availability
// - original_db_price          - DEBUG: Base price from database
// - original_db_currency       - DEBUG: Original currency from hotel record
//
// Frontend consumption: stays.php lines 355-356 use display_price fields
//
// ============================================================================
// SEARCH LOGGING - logs_searches table
// ============================================================================
//
// Every search request is logged to `logs_searches` for analytics and debugging:
// - user_id    : Current logged-in user ID or 'guest'
// - module     : 'stays' (identifies which module handled the search)
// - request    : JSON encoded search parameters (all POST data)
// - created_at : Timestamp of search
// - ip         : User's IP address
//
// ============================================================================

$router->post('stays/hotels/search', function() use ($db) {
    // search_guard_v2: session lock release + configurable timeouts for long supplier requests
    @set_time_limit(30);
    $connectTimeout = defined('SUPPLIER_CONNECT_TIMEOUT') ? SUPPLIER_CONNECT_TIMEOUT : 10;
    $requestTimeout = defined('SUPPLIER_REQUEST_TIMEOUT') ? SUPPLIER_REQUEST_TIMEOUT : 30;
    $searchSessionData = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        // Resolve JWT token if passed (for mobile app Agents)
        if (!class_exists('JWT')) {
            require_once dirname(__DIR__, 3) . '/app/lib/jwt.php';
        }
        $allHeaders = function_exists('getallheaders') ? getallheaders() : [];
        $headersLower = [];
        foreach ($allHeaders as $k => $v) {
            $headersLower[strtolower($k)] = $v;
        }
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $hKey = strtolower(str_replace('_', '-', substr($k, 5)));
                $headersLower[$hKey] = $v;
            }
        }
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headersLower['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $authHeader = $headersLower['authorization'] ?? '';
        $token = '';
        if (!empty($authHeader)) {
            if (preg_match('/Bearer\s(\S+)/i', $authHeader, $jwtMatches)) {
                $token = $jwtMatches[1];
            } else {
                $token = trim($authHeader);
            }
        }
        if (empty($token)) {
            $token = $headersLower['token'] ?? $headersLower['jwt'] ?? $headersLower['x-access-token'] ?? '';
        }
        if (empty($token)) {
            $token = $_POST['token'] ?? $_POST['access_token'] ?? $_POST['jwt']
                  ?? $_GET['token'] ?? $_GET['access_token'] ?? $_GET['jwt']
                  ?? '';
        }

        if (!empty($token)) {
            try {
                $tokenData = JWT::verify($token);
                if (!$tokenData && method_exists('JWT', 'decode')) {
                    $tokenData = JWT::decode($token, false);
                }
                if (is_array($tokenData) && !empty($tokenData['user_id'])) {
                    $_SESSION['user_id'] = $tokenData['user_id'];
                    $_SESSION['user_role'] = $tokenData['role'] ?? null;
                }
            } catch (Exception $e) {
                // Silent fail
            }
        }

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
    // INITIALIZATION - Extract and validate search parameters from POST request
    // Parameters come from Alpine.js frontend (stays.php fetchSupplier method lines 301-310)
    // ========================================

    $city = $_POST['destination'] ?? $_POST['city'] ?? '';
    $destination_code = $_POST['destination_code'] ?? '';
    $star_rating = $_POST['star_rating'] ?? 'any';
    $checkin = $_POST['checkin'] ?? '';
    $checkout = $_POST['checkout'] ?? '';
    $rooms = (int)($_POST['rooms'] ?? 1);
    $adults = (int)($_POST['adults'] ?? 2);
    $children = (int)($_POST['children'] ?? 0);
    $nationality = $_POST['nationality'] ?? 'US';
    $rooms_data_json = $_POST['rooms_data'] ?? '[]';
    $currency = $_POST['currency'] ?? 'USD';
    $hotel_name = trim($_POST['hotel_name'] ?? '');

    // ========================================
    // PAGINATION PARAMETERS - Control result set size and offset
    // DEFAULT: 25 hotels per page for optimal performance and user experience
    // MAX: 100 hotels per page to prevent memory issues
    // MIN: 1 hotel per page (edge case handling)
    // ========================================
    $page = max(1, (int)($_POST['page'] ?? 1));
    $per_page = min(100, max(1, (int)($_POST['per_page'] ?? 25)));

    // ========================================
    // MODULE CONFIGURATION - Load manual hotels module data for markup
    // ========================================
    $module = $db->get('modules', '*', [
        'name' => 'hotels',
        'type' => 'stays'
    ]);

    if (!$module) {
        echo json_encode([
            'status' => false,
            'message' => 'Manual hotels module not configured',
            'response' => []
        ]);
        exit;
    }

    // ========================================
    // PARSE ROOMS DATA - Extract detailed room configuration with child ages
    // ========================================
    // rooms_data is a JSON string from frontend (stays-search.php line 410)
    // Structure: [{"adults":2,"children":1,"childAges":[5]}, {"adults":2,"children":2,"childAges":[3,7]}]
    //
    // Frontend Generation (stays-search.php lines 556-587):
    //   - guestsRoomsDropdown() Alpine.js component manages room data
    //   - Each room has: adults (1-8), children (0-6), childAges array (1-17 years)
    //   - Child ages are managed in stays-search.php lines 488-517
    //   - When children count changes, childAges array is auto-adjusted
    //   - Line 587: incrementGuest() adds age with default value 1
    //   - Line 600: decrementGuest() removes last age from array
    //   - Line 505-517: UI renders select dropdowns for each child (1-17 years)
    //
    // DEVELOPER GUIDE - How to access child ages in this search endpoint:
    // 1. Parse the JSON: $rooms_data = json_decode($rooms_data_json, true);
    // 2. Loop through rooms: foreach ($rooms_data as $room) { ... }
    // 3. Access child ages: $room['childAges'] (array of integers 1-17)
    // 4. Example validation:
    //    if ($room['children'] > 0 && !empty($room['childAges'])) {
    //        foreach ($room['childAges'] as $age) {
    //            // Use $age for API calls or validation
    //        }
    //    }
    // ========================================

    $rooms_data = [];
    try {
        $rooms_data = json_decode($rooms_data_json, true);
        if (!is_array($rooms_data)) {
            $rooms_data = [];
        }
    } catch (Exception $e) {
        error_log('Failed to parse rooms_data: ' . $e->getMessage());
        $rooms_data = [];
    }

    // Extract child ages from all rooms for logging/processing
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

    // Session currency takes precedence (user's selected display currency)
    $sessionCurrency = isset($searchSessionData['app_currency']) ? $searchSessionData['app_currency'] : $currency;

    $number_of_nights = 0;

    // ========================================
    // SEARCH REQUEST LOGGING - Log every search to logs_searches table
    // Used for analytics, debugging, and tracking user search patterns
    // ========================================
    try {
        $user_id = isset($searchSessionData['user_id']) ? $searchSessionData['user_id'] : 'guest';
        $user_ip = $_SERVER['REMOTE_ADDR'] ??
                   ($_SERVER['HTTP_X_FORWARDED_FOR'] ??
                   ($_SERVER['HTTP_CLIENT_IP'] ?? 'unknown'));

        // Prepare all search parameters for logging (including child ages breakdown)
        $search_params = [
            'destination' => $city,
            'destination_code' => $destination_code,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'rooms' => $rooms,
            'adults' => $adults,
            'children' => $children,
            'nationality' => $nationality,
            'rooms_data' => $rooms_data,           // Full room configuration array
            'child_ages' => $all_child_ages,       // Extracted child ages for easy access
            'currency' => $sessionCurrency,
            'star_rating' => $star_rating
        ];

        // Insert search log into database (wrapped in try-catch to prevent search failure)
        // Note: request column should be LONGTEXT to store large JSON without truncation
        // Run this if needed: ALTER TABLE logs_searches MODIFY COLUMN request LONGTEXT;
        $db->insert('logs_searches', [
            'user_id' => (string)$user_id,
            'module' => 'stays',
            'request' => json_encode($search_params),
            'created_at' => date('Y-m-d H:i:s'),
            'ip' => $user_ip
        ]);

    } catch (Exception $e) {
        // Silently log errors - don't break the search
        error_log('Search logging failed: ' . $e->getMessage());
    }

    // ========================================
    // DATE CALCULATION - Calculate number of nights from check-in/check-out dates
    // ========================================
    // Input Format: DD-MM-YYYY (e.g., "25-12-2025")
    // Converted to: YYYY-MM-DD for DateTime processing
    // Output: Integer number of nights (minimum 1 night enforced)
    // ========================================
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

    // Ensure minimum 1 night stay
    if ($number_of_nights === 0) {
        $number_of_nights = 1;
    }

    // ========================================
    // DATABASE QUERY - Fetch hotels from stays table with filters
    // ========================================
    // Table: stays (migrated from 'hotels' table)
    // Fields queried: * (all fields including id, name, location, currency, etc.)
    // Filters applied:
    //   - status = 1 (active hotels only)
    //   - location LIKE %city% (if destination provided)
    //   - stars = X (if star_rating filter specified)
    //
    // MedooDB syntax used:
    //   'location[~]' => LIKE '%value%' (fuzzy match)
    //   ['status' => 1] => WHERE status = 1
    // ========================================

    // Build where conditions - if no city specified, get all active hotels
    if (!empty($city)) {
        $whereConditions = ['status' => 1, 'location[~]' => $city];
    } else {
        $whereConditions = ['status' => 1];
    }

    // Add star rating filter if specified (frontend filter can override)
    if ($star_rating != 'any') {
        $whereConditions['stars'] = $star_rating;
    }

    if (!empty($hotel_name)) {
        $whereConditions['OR'] = [
            'name[~]' => '%' . $hotel_name . '%',
            'id' => $hotel_name
        ];
    }

    // ========================================
    // PAGINATION LOGIC - Count total results then fetch paginated subset
    // ========================================
    // STEP 1: Get total count of matching hotels (without LIMIT)
    $totalHotels = $db->count('stays', $whereConditions);
    
    // STEP 2: Calculate pagination metadata
    $totalPages = ceil($totalHotels / $per_page);
    $offset = ($page - 1) * $per_page;
    
    // STEP 3: Add LIMIT clause for pagination
    $whereConditions['LIMIT'] = [$offset, $per_page];
    
    // STEP 4: Execute paginated query - fetch only current page hotels
    $hotels = $db->select('stays', '*', $whereConditions);
    $formattedHotels = [];  // Will store final processed hotel data for JSON response

    // ========================================
    // HOTEL PROCESSING LOOP - Process each hotel with rooms and pricing
    // ========================================
    // For each hotel, we:
    // 1. Fetch all active room types from stays_rooms table
    // 2. Parse room_options JSON (contains base prices and capacity)
    // 3. Apply MARKUP() to each room option price
    // 4. Calculate total price = (marked_up_per_night × nights × rooms)
    // 5. Group rooms by room_type_id with all their pricing options
    // 6. Extract minimum price for display on hotel card
    // 7. Build amenities and images arrays
    // 8. Format final hotel response object
    // ========================================
    foreach ($hotels as $hotel) {
        $min_price_per_night_markup = null;  // Tracks lowest per-night price (with markup)
        $min_total_price_markup = null;      // Tracks lowest total price (with markup)
        $room_options_data = [];             // Will store all room types with options

        // Fetch all active rooms for this hotel from stays_rooms table
        // Each row = one room type (e.g., "Standard Twin", "Deluxe Suite")
        $roomsData = $db->select('stays_rooms', '*', ['stay_id' => $hotel['id'], 'status' => 1]);

        $groupedRoomOptions = [];

        foreach ($roomsData as $room) {
            $roomTypeData = $db->get('stays_settings', 'name', ['id' => $room['room_type_id'], 'setting_type' => 'room_type', 'status' => 1]);
            $room_name = $roomTypeData ? $roomTypeData : 'Room Type ' . $room['room_type_id'];

            $room_images = [];
            if (!empty($room['room_images'])) {
                try {
                    $imagesArray = json_decode($room['room_images'], true);
                    if (is_array($imagesArray)) {
                        $defaultImage = '';
                        foreach ($imagesArray as $image) {
                            if (isset($image['default']) && $image['default'] === true && !empty($image['url'])) {
                                $defaultImage = dirname(root) . $image['url'];
                                break;
                            }
                        }

                        if (empty($defaultImage) && !empty($imagesArray[0]['url'])) {
                            $defaultImage = dirname(root) . $imagesArray[0]['url'];
                        }

                        foreach ($imagesArray as $image) {
                            if (!empty($image['url'])) {
                                $room_images[] = ['url' => dirname(root) . $image['url'], 'default' => $image['default'] ?? false];
                            }
                        }
                    }
                } catch (Exception $e) {}
            }

            $amenities_list = [];
            if (!empty($room['amenities']) && $room['amenities'] != '[]') {
                try {
                    $amenity_ids = json_decode($room['amenities'], true);

                    if ($amenity_ids === null && is_string($room['amenities'])) {
                        $amenities_str = trim($room['amenities'], '[]');
                        $amenity_ids = array_map('intval', array_map('trim', explode(',', $amenities_str)));
                    }

                    if (is_array($amenity_ids) && count($amenity_ids) > 0) {
                        $amenities = $db->select('stays_settings', ['id', 'name'], ['id' => $amenity_ids, 'status' => 1]);
                        foreach ($amenities as $amenity) {
                            $amenities_list[] = ['id' => $amenity['id'], 'name' => $amenity['name']];
                        }
                    }
                } catch (Exception $e) {}
            }

            // ========================================
            // ROOM OPTIONS PROCESSING - Parse and price each room configuration
            // ========================================
            // room_options JSON structure (from stays_rooms table):
            // [
            //   {
            //     "price": 50.00,              // Base price in hotel's currency
            //     "max_adults": 2,             // Maximum adults allowed
            //     "max_children": 1,           // Maximum children allowed
            //     "breakfast_included": 1,     // Boolean flag
            //     "refundable": 1,             // Boolean flag
            //     "cancellation_free": 1,      // Boolean flag
            //     "discount_percentage": 10    // Optional discount
            //   }
            // ]
            // ========================================
            if (!empty($room['room_options'])) {
                $room_options = json_decode($room['room_options'], true);

                if (is_array($room_options)) {
                    foreach ($room_options as $optionIndex => $option) {
                        if (isset($option['price']) && $option['price'] > 0) {
                            $price_per_night_per_room = $option['price'];  // Base price from DB

                            // Ensure hotel currency defaults to USD if not set
                            $hotelCurrency = !empty($hotel['currency']) ? $hotel['currency'] : 'USD';

                            // ========================================
                            // CRITICAL PRICING CALL - MARKUP() function
                            // ========================================
                            // Function: MARKUP($basePrice, $moduleType, $db, $fromCurrency, $toCurrency)
                            // Location: app/lib/functions.php (lines 1182-1255)
                            // Module Type: 'stays' (must match modules.type field)
                            //
                            // What it does:
                            // 1. Retrieves markup from modules table (B2B or B2C based on user role)
                            // 2. Applies markup to base price in ORIGINAL currency
                            //    Example: $50 + 20% = $60 USD
                            // 3. Converts marked-up price to display currency
                            //    Example: $60 USD × 3.75 = 225 SAR
                            //
                            // Returns array with:
                            //   'price'                 => Final price (with markup + conversion)
                            //   'markup'                => Markup amount in display currency
                            //   'markup_percentage'     => Markup percentage applied
                            //   'base_price'            => Original base price
                            //   'converted_base_price'  => Base price converted (no markup)
                            // ========================================
                            $price_per_night_markup = MARKUP($price_per_night_per_room, $module, $db, $hotelCurrency, $sessionCurrency);

                            // ========================================
                            // TOTAL PRICE CALCULATION
                            // ========================================
                            // Formula: (marked_up_per_night) × nights × rooms
                            //
                            // IMPORTANT: Do NOT call MARKUP() again on total!
                            // The per-night price already has markup applied.
                            // We just multiply the converted per-night price.
                            //
                            // Example:
                            //   Base: $50 USD per night
                            //   After MARKUP: 225 SAR per night (with 20% markup + conversion)
                            //   Nights: 3
                            //   Rooms: 2
                            //   Total: 225 × 3 × 2 = 1,350 SAR
                            // ========================================
                            $total_price = $price_per_night_markup['price'] * $number_of_nights * $rooms;

                            // Create total price array structure matching MARKUP() return format
                            $total_price_markup = [
                                'price' => round($total_price, 2),
                                'markup' => $price_per_night_markup['markup'] * $number_of_nights * $rooms,
                                'markup_percentage' => $price_per_night_markup['markup_percentage'],
                                'markup_type' => $price_per_night_markup['markup_type'],
                                'markup_value' => $price_per_night_markup['markup_value'],
                                'base_price' => round($price_per_night_per_room * $number_of_nights * $rooms, 2),
                                'converted_base_price' => round($price_per_night_markup['converted_base_price'] * $number_of_nights * $rooms, 2)
                            ];

                            if (!$min_price_per_night_markup || $price_per_night_markup['price'] < $min_price_per_night_markup['price']) {
                                $min_price_per_night_markup = $price_per_night_markup;
                                $min_total_price_markup = $total_price_markup;
                            }

                            $roomTypeId = $room['room_type_id'];
                            if (!isset($groupedRoomOptions[$roomTypeId])) {
                                $groupedRoomOptions[$roomTypeId] = [
                                    'room_name' => $room_name,
                                    'room_type_id' => $roomTypeId,
                                    'room_id' => $room['id'],
                                    'amenities' => $amenities_list,
                                    'room_images' => $room_images,
                                    'room_main_image' => !empty($room_images) ? $room_images[0]['url'] : '',
                                    'options' => [],
                                    'min_price_per_night' => $price_per_night_markup['price'],
                                    'max_adults' => $option['max_adults'] ?? 2,
                                    'max_children' => $option['max_children'] ?? 0
                                ];
                            }

                            $groupedRoomOptions[$roomTypeId]['options'][] = [
                                'option_index' => $optionIndex,
                                'price_per_night' => $price_per_night_markup['price'],
                                'price_per_night_details' => $price_per_night_markup,
                                'total_price' => $total_price_markup['price'],
                                'total_price_details' => $total_price_markup,
                                'room_id' => $room['id'],
                                'max_adults' => $option['max_adults'] ?? 2,
                                'max_children' => $option['max_children'] ?? 0,
                                'breakfast_included' => $option['breakfast_included'] ?? 0,
                                'refundable' => $option['refundable'] ?? 0,
                                'cancellation_free' => $option['cancellation_free'] ?? 0,
                                'discount_percentage' => $option['discount_percentage'] ?? 0
                            ];

                            if ($price_per_night_markup['price'] < $groupedRoomOptions[$roomTypeId]['min_price_per_night']) {
                                $groupedRoomOptions[$roomTypeId]['min_price_per_night'] = $price_per_night_markup['price'];
                            }

                            if (($option['max_adults'] ?? 2) > $groupedRoomOptions[$roomTypeId]['max_adults']) {
                                $groupedRoomOptions[$roomTypeId]['max_adults'] = $option['max_adults'] ?? 2;
                            }
                            if (($option['max_children'] ?? 0) > $groupedRoomOptions[$roomTypeId]['max_children']) {
                                $groupedRoomOptions[$roomTypeId]['max_children'] = $option['max_children'] ?? 0;
                            }
                        }
                    }
                }
            }
        }

        foreach ($groupedRoomOptions as $roomTypeId => $group) {
            $group['options_count'] = count($group['options']);
            $room_options_data[] = $group;
        }

        // ========================================
        // AVAILABILITY CHECK - Determine if hotel has bookable rooms
        // ========================================
        // Hotels without rooms still display with "No Rooms Available" button
        // This allows visibility of all properties even if temporarily out of stock
        // Frontend displays red button: stays.php line 789
        // ========================================
        $has_available_rooms = count($room_options_data) > 0;

        // Set default pricing if no rooms found (shows $0 on frontend)
        if (!$min_price_per_night_markup) {
            $min_price_per_night_markup = MARKUP(0, $module, $db);
            $min_total_price_markup = MARKUP(0, $module, $db);
        }

        // ========================================
        // GEOLOCATION - Extract latitude/longitude from location_coords
        // ========================================
        $latitude = null;
        $longitude = null;
        if (!empty($hotel['location_coords'])) {
            $coords = explode(',', $hotel['location_coords']);
            if (count($coords) >= 2) {
                $latitude = floatval(trim($coords[0]));
                $longitude = floatval(trim($coords[1]));
            }
        }

        // ========================================
        // IMAGE EXTRACTION - Get ALL hotel images for carousel + default image
        // ========================================
        $hotelImage = '';
        $hotelImages = [];  // Array of all image URLs for carousel

        if (!empty($hotel['img'])) {
            $imageArray = json_decode($hotel['img'], true);

            if (is_array($imageArray)) {
                // Extract all image URLs with full path
                foreach ($imageArray as $image) {
                    if (!empty($image['url'])) {
                        $fullImageUrl = dirname(root) . $image['url'];
                        $hotelImages[] = $fullImageUrl;

                        // Set default image for backward compatibility
                        if (isset($image['default']) && $image['default'] === true && empty($hotelImage)) {
                            $hotelImage = $fullImageUrl;
                        }
                    }
                }

                // If no default image found, use first image
                if (empty($hotelImage) && !empty($hotelImages)) {
                    $hotelImage = $hotelImages[0];
                }
            }
        }

        // ========================================
        // ACCOMMODATION TYPE - Fetch accommodation type name from stay_type ID
        // ========================================
        // Backend saves stay_type as ID (foreign key to stays_settings)
        // Frontend needs the name (e.g., "Hotel", "Resort", "Villa") for filtering
        // Query stays_settings table where setting_type='accommodation' and id=stay_type
        // ========================================
        $accommodationType = 'Hotel'; // Default fallback
        if (!empty($hotel['stay_type']) && is_numeric($hotel['stay_type'])) {
            $accommodationTypeRecord = $db->get('stays_settings', 'name', [
                'id' => (int)$hotel['stay_type'],
                'setting_type' => 'accommodation',
                'status' => 1
            ]);
            if ($accommodationTypeRecord) {
                $accommodationType = $accommodationTypeRecord;
            }
        }

        // ========================================
        // AMENITIES EXTRACTION - Parse amenity_ids JSON and fetch from database
        // Handles multiple formats: JSON array, bracketed string, comma-separated
        // ========================================
        $hotelAmenities = [];
        $amenityIds = [];

        if (!empty($hotel['amenity_ids'])) {
            $decodedIds = json_decode($hotel['amenity_ids'], true);

            if (is_array($decodedIds)) {
                $amenityIds = $decodedIds;
            } else if (is_string($hotel['amenity_ids']) && strpos($hotel['amenity_ids'], '[') !== false) {
                $cleaned = trim($hotel['amenity_ids'], '[]');
                $amenityIds = array_map('intval', array_map('trim', explode(',', $cleaned)));
            } else if (is_string($hotel['amenity_ids']) && !empty($hotel['amenity_ids'])) {
                $amenityIds = array_map('intval', array_map('trim', explode(',', $hotel['amenity_ids'])));
            }

            if (!empty($amenityIds)) {
                $amenityRecords = $db->select('stays_settings', ['id', 'name'], [
                    'id' => $amenityIds,
                    'setting_type' => 'stay_amenity',
                    'status' => 1
                ]);

                foreach ($amenityRecords as $amenity) {
                    $hotelAmenities[] = [
                        'id' => $amenity['id'],
                        'name' => $amenity['name'],
                    ];
                }
            }
        }

        // ========================================
        // RESPONSE FORMATTING - Build standardized hotel response object
        // ========================================
        // This object is consumed by Alpine.js frontend (stays.php)
        // Key fields used:
        //   - display_price: Total price shown on hotel card (line 355)
        //   - display_price_per_night: Per night price (line 356)
        //   - has_available_rooms: Controls button display (line 789)
        //   - room_options: Expandable room details with pricing options
        //
        // PRICING FIELDS EXPLANATION:
        //   display_price          => WITH markup + conversion (USE THIS)
        //   display_price_per_night => WITH markup + conversion (USE THIS)
        //   base_price             => Original DB price without markup
        //   converted_base_price   => Base price converted (no markup)
        //   original_db_price      => Debug field showing raw DB value
        //
        // Currency conversion already applied via MARKUP() function.
        // Frontend displays in user's selected currency ($sessionCurrency).
        // ========================================
        $formattedHotels[] = [
            // Basic hotel information
            'hotel_id' => $hotel['id'],
            'name' => $hotel['name'],
            'img' => $hotelImage,                 // Single default/first image (backward compatibility)
            'images' => $hotelImages,             // ⭐ Array of ALL images for carousel
            'location' => $hotel['location'],
            'address' => $hotel['address'],
            'stars' => (int)$hotel['stars'],      // Integer for filter comparison
            'rating' => (float)$hotel['rating'],

            // Currency fields
            'currency' => $sessionCurrency,             // Display currency (SAR, USD, etc.)
            'original_currency' => $hotel['currency'],  // Hotel's base currency

            // Location coordinates (for maps)
            'latitude' => $latitude,
            'longitude' => $longitude,

            // Pricing fields (ALL include B2C/B2C markup applied)
            'display_price' => $min_total_price_markup['price'],                    // ⭐ Frontend uses this (total stay)
            'display_price_per_night' => $min_price_per_night_markup['price'],      // ⭐ Frontend uses this (per night)
            'actual_price' => $min_total_price_markup['price'],                     // Alias for compatibility
            'actual_price_per_night' => $min_price_per_night_markup['price'],       // Alias for compatibility
            'actual_price_details' => $min_total_price_markup,                      // Full markup breakdown
            'actual_price_per_night_details' => $min_price_per_night_markup,        // Full markup breakdown
            'base_price' => $min_total_price_markup['base_price'] ?? $min_total_price_markup['price'],           // Original DB price
            'base_price_per_night' => $min_price_per_night_markup['base_price'] ?? $min_price_per_night_markup['price'],  // Original DB price
            'original_db_price' => $min_price_per_night_markup['base_price'],       // 🔍 DEBUG: Raw DB value
            'original_db_currency' => !empty($hotel['currency']) ? $hotel['currency'] : 'USD',  // 🔍 DEBUG: Hotel currency

            // Hotel features
            'discount' => $hotel['discount'],
            'refundable' => (bool)$hotel['refundable'],
            'description' => $hotel['desc'],
            'featured' => $hotel['featured'],
            'hotel_order' => $hotel['hotel_order'],

            // Accommodation type (converted from ID to name)
            'stay_type_id' => $hotel['stay_type'],        // Original ID from database
            'accommodation_type' => $accommodationType,    // ⭐ Name for frontend filtering

            // Amenities (hotel-level)
            'amenity_ids' => $amenityIds,
            'amenities' => $hotelAmenities,

            // Policies and contact
            'checkin_time' => $hotel['checkin_time'],
            'checkout_time' => $hotel['checkout_time'],
            'booking_age_requirement' => $hotel['booking_age_requirement'],
            'phone' => $hotel['phone'],
            'website' => $hotel['website'],
            'email' => $hotel['email'],

            // Multilingual support
            'translations' => $hotel['translations'],

            // Metadata
            'created_at' => $hotel['created_at'],
            'updated_at' => $hotel['updated_at'],

            // Room availability and options
            'room_options' => $room_options_data,              // Array of room types with pricing
            'has_available_rooms' => $has_available_rooms,     // Boolean: show "Book Now" vs "No Rooms"
        ];
    }

    // ========================================
    // JSON RESPONSE - Return formatted hotel array with pagination headers
    // ========================================
    // Content-Type header required for proper JSON parsing
    // Frontend receives this in fetchSupplier() method (stays.php line 299)
    // Response is normalized in normalizeHotels() (line 354)
    //
    // PAGINATION HEADERS:
    // - X-Total-Results: Total matching hotels across all pages
    // - X-Total-Pages: Number of pages available
    // - X-Current-Page: Current page number being returned
    // - X-Per-Page: Number of results per page
    // - X-Has-More: Boolean flag indicating if more pages exist
    //
    // Frontend uses these headers for infinite scroll logic
    // ========================================
    $json = json_encode($formattedHotels);
    ob_end_clean();
    
    header('Content-Type: application/json');
    header('X-Total-Results: ' . $totalHotels);
    header('X-Destination-Total: ' . $totalHotels);
    header('X-Total-Pages: ' . $totalPages);
    header('X-Current-Page: ' . $page);
    header('X-Per-Page: ' . $per_page);
    header('X-Has-More: ' . ($page < $totalPages ? 'true' : 'false'));
    
    echo $json;
});
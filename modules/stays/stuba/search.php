<?php
// ============================================================================
// STUBA HOTEL SEARCH API ENDPOINT - COMPLETE DOCUMENTATION
// ============================================================================
//
// PURPOSE:
// Search Stuba hotels from local imported database with calculated pricing,
// dynamic B2B/B2C markup application, and currency conversion.
// Response structure EXACTLY matches Hotelbeds API for frontend compatibility.
//
// ENDPOINT: POST /stays/stuba/search
//
// ============================================================================
// REQUEST PARAMETERS 
// ============================================================================
//
// 1. city (string)                - City/Location name (e.g., "Barcelona", "Dubai")
//                                   Alternative: destination (also supported)
//                                   Searches stuba_hotels.city via region_id lookup
//
// 2. checkin (string)             - Check-in date in DD-MM-YYYY format
//                                   Example: "25-12-2024"
//                                   Frontend: searchParams.checkin
//
// 3. checkout (string)            - Check-out date in DD-MM-YYYY format
//                                   Example: "27-12-2024"
//                                   Frontend: searchParams.checkout
//
// 4. nationality (string)         - Guest nationality ISO code (e.g., "US", "GB")
//                                   Frontend: searchParams.nationality
//                                   Used for Stuba API compliance (if API enabled)
//
// 5. rooms (integer)              - Number of rooms requested (default: 1)
//                                   Frontend: searchParams.rooms
//
// 6. adults (integer)             - Total adults across all rooms (default: 2)
//                                   Frontend: searchParams.adults
//
// 7. children (integer)           - Total children across all rooms (default: 0)
//                                   Note: Form sends 'childs' not 'children'
//                                   Frontend: searchParams.children or childs
//
// 8. rooms_data (JSON string)     - Detailed room configuration array
//                                   Format: [{"adults":2,"children":1,"childAges":[5]}]
//                                   Optional but recommended for accurate pricing
//
// 9. currency (string)            - Display currency code (e.g., "USD", "SAR", "EUR")
//                                   Source: $searchSessionData['app_currency'] or request
//                                   Default: USD
//
// 10. star_rating (string)        - Optional: Filter by star rating (1-5 or "any")
//                                   Note: Stuba uses numeric stars field (not category codes)
//
// 11. page (integer)               - Page number for pagination (default: 1)
//                                   Frontend: searchParams.page
//                                   Used for infinite scroll loading
//
// 12. per_page (integer)           - Results per page (default: 25, max: 100)
//                                   Frontend: searchParams.per_page
//                                   Controls how many hotels load at once
//
// ============================================================================
// DATABASE STRUCTURE (Stuba-specific)
// ============================================================================
//
// MAIN TABLES:
// - stuba_hotels          : Core hotel data with city_id and region_id
// - stuba_destinations    : City/region mappings (not currently used in search)
// - stuba_hotel_images    : Hotel images with is_primary flag
// - stuba_amenities       : Master amenities list
// - stuba_hotel_amenities : Hotel-amenity relationships
// - stuba_room_types      : Room type definitions
// - stuba_room_images     : Room-specific images
// - stuba_room_amenities  : Room-amenity relationships
//
// KEY FIELDS IN stuba_hotels:
// - hotel_id       : Unique Stuba hotel identifier
// - hotel_name     : Hotel display name
// - city           : City name (search field)
// - city_id        : Stuba city identifier
// - region_id      : Stuba region identifier (used for grouping)
// - stars          : Star rating (1-5 numeric)
// - rating         : Guest rating (decimal)
// - latitude       : Geolocation
// - longitude      : Geolocation
// - currency       : Hotel's base currency
// - hotel_type     : "Hotel", "Resort", "Apartment", etc.
//
// ============================================================================
// SEARCH LOGIC FLOW
// ============================================================================
//
// STEP 1: CITY LOOKUP
// - Search stuba_hotels table for matching city (case-insensitive LIKE)
// - Extract city_id and region_id from first match
// - If no match found, return empty array
//
// STEP 2: HOTEL RETRIEVAL WITH PAGINATION
// - Get all hotels with matching region_id
// - Filter by hotel_type = 'Hotel'
// - Apply pagination with offset and limit
//
// STEP 3: PRICING CALCULATION (No Live API - Estimated Pricing)
// - Base price calculated by star rating:
//   * 5 stars: $300-500 per night
//   * 4 stars: $200-350 per night
//   * 3 stars: $100-200 per night
//   * 2 stars or less: $80-150 per night
// - Random variation within range for realistic pricing
//
// STEP 4: IMAGE COLLECTION
// - Query stuba_hotel_images for hotel_id
// - Prioritize is_primary = 1 images
// - Return array of image URLs
//
// STEP 5: AMENITIES COLLECTION
// - Join stuba_hotel_amenities with stuba_amenities
// - Return structured amenity list with id and name
//
// STEP 6: ROOM OPTIONS CONSTRUCTION
// - Query stuba_room_types for hotel_id
// - For each room type:
//   * Get room images from stuba_room_images
//   * Get room amenities from stuba_room_amenities
//   * Create multiple rate options (Room Only, Breakfast, etc.)
// - Structure room_options format exactly
//
// ============================================================================
// PRICING & MARKUP LOGIC
// ============================================================================
//
// STEP 1: BASE PRICE CALCULATION
// - Calculated based on star rating (see above)
// - Currency: Hotel's original currency from stuba_hotels.currency
//
// STEP 2: MARKUP APPLICATION (via MARKUP() function)
// - Function Location: modules/helpers.php (lines 29-118)
// - Module type: 'hotels' (retrieves stuba markup from modules table)
// - Fields: markup_b2b, markup_b2c, markup_type_b2b, markup_type_b2c
// - User type: B2B (agents) vs B2C (customers)
//
// MARKUP ORDER (CRITICAL):
//   a) Apply markup to base price in ORIGINAL currency
//      Example: €50 + 20% B2C markup = €60
//   b) Convert marked-up price to display currency
//      Example: €60 × 1.10 USD rate = $66
//
// STEP 3: CURRENCY CONVERSION
// - Exchange rates from `currencies` table
// - Base: USD (rate = 1.0)
// - Formula: (price / fromRate) × toRate
//
// STEP 4: TOTAL PRICE CALCULATION
// - Per Night Price: Base price + markup, converted to display currency
// - Total Price: (marked_up_converted_per_night) × nights × rooms
//
// STEP 5: PRICE DETAILS OBJECT
// Returns detailed breakdown:
// {
//   "price": 66.00,                    // Final marked-up, converted price
//   "markup": 10.00,                   // Markup amount in display currency
//   "markup_percentage": 20,           // Markup percentage applied
//   "markup_type": "percentage",       // "percentage" or "fixed"
//   "markup_value": 20,                // Original markup value
//   "base_price": 50.00,               // Original price before markup
//   "converted_base_price": 55.00      // Base price after currency conversion only
// }
//
// ============================================================================
// RESPONSE STRUCTURE
// ============================================================================
//
// Each hotel object contains:
//
// BASIC INFORMATION:
// - hotel_id              : "stuba_123456" (prefixed for uniqueness)
// - name                  : Hotel name
// - img                   : Primary hotel image URL
// - images                : Array of all hotel image URLs
// - location              : City name
// - address               : Hotel address
// - stars                 : Star rating (1-5)
// - rating                : Guest rating (float)
//
// GEOLOCATION:
// - latitude              : Decimal latitude (or null)
// - longitude             : Decimal longitude (or null)
//
// PRICING (with markup + currency conversion):
// - display_price                   : Total price for entire stay
// - display_price_per_night         : Price per night
// - actual_price                    : Same as display_price
// - actual_price_per_night          : Same as display_price_per_night
// - actual_price_details            : Detailed breakdown object (see above)
// - actual_price_per_night_details  : Per-night breakdown object
// - currency                        : Display currency (e.g., "USD")
// - original_currency               : Hotel's base currency (e.g., "EUR")
//
// FEATURES:
// - amenities             : Array of {id, name} objects
// - accommodation_type    : Always "Hotel"
//
// ROOM OPTIONS:
// - room_options          : Array of room groups
//   Each room group contains:
//   * room_name           : "Standard Room", "Deluxe Suite", etc.
//   * room_type_id        : Unique room type identifier
//   * room_id             : Same as room_type_id
//   * amenities           : Array of room amenities {id, name}
//   * room_images         : Array of {url, default} objects
//   * room_main_image     : Primary room image URL
//   * options             : Array of rate options (see below)
//   * options_count       : Number of rate options
//   * min_price_per_night : Lowest price among all options
//   * max_adults          : Maximum adult capacity
//   * max_children        : Maximum child capacity
//
// RATE OPTIONS (within each room_options.options array):
// - option_index          : 0, 1, 2, etc.
// - price_per_night       : Price per night for this rate
// - price_per_night_details : Detailed pricing breakdown
// - total_price           : Total price for entire stay
// - total_price_details   : Total price breakdown
// - room_id               : Room type identifier
// - rate_key              : "" (Stuba doesn't provide - empty string)
// - rate_class            : "" (Stuba doesn't provide)
// - rate_type             : "BOOKABLE"
// - board_code            : "RO" (Room Only), "BB" (Breakfast), etc.
// - board_name            : "Room Only", "Bed & Breakfast", etc.
// - max_adults            : Maximum adults for this rate
// - max_children          : Maximum children for this rate
// - breakfast_included    : 0 or 1
// - refundable            : 0 or 1 (based on star rating)
// - cancellation_free     : 0 or 1 (based on star rating)
// - discount_percentage   : 0 (Stuba doesn't provide discounts)
// - packaging             : false (Stuba doesn't support packages)
// - allotment             : null (Stuba doesn't provide room inventory)
//
// - has_available_rooms   : Boolean (true if room_options not empty)
//
// SUPPLIER INFO:
// - supplier              : "stuba"
// - supplier_name         : "stuba"
// - supplier_id           : "" (Stuba doesn't have supplier IDs)
// - color                 : "#00a8e8" (Stuba brand color)
//
// METADATA 
// - country_code          : ISO country code
// - destination_code      : "" (Stuba doesn't use destination codes)
// - chain_code            : "" (Stuba doesn't provide chain codes)
// - category_code         : "" (Stuba doesn't use category codes)
//
// POLICIES:
// - phone                 : Hotel phone number
// - email                 : Hotel email
// - website               : Hotel website URL
//
// ============================================================================
// FRONTEND COMPATIBILITY
// ============================================================================
//
// The response format is 100% compatible.
// Frontend code can handle both APIs identically:
//
// hotels.forEach(hotel => {
//   console.log(hotel.name);                    // ✓ Works for both
//   console.log(hotel.actual_price);            // ✓ Works for both
//   console.log(hotel.room_options[0].options); // ✓ Works for both
//   console.log(hotel.supplier);                // "stuba"
// });
//
// ============================================================================

global $router;

// ============================================================================
// MAIN SEARCH ENDPOINT
// ============================================================================

$router->post('stays/stuba/search', function() use ($db) {
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

    function xmlToJson($xml) {
        $xml = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        $json = json_encode($xml);
        return $json;
    }
    
    // ========================================
    // CLEAN OUTPUT BUFFER
    // ========================================
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    
    // Increase limits for large XML responses
    @ini_set('memory_limit', '512M');
    @set_time_limit(120);

    // ========================================
    // INITIALIZATION - Extract search parameters
    // ========================================
    // Support both 'city' and 'destination' parameters for compatibility
    $city = $_POST['city'] ?? $_POST['destination'] ?? '';
    
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

    // Store original city search
    $original_city = $city;

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
        error_log('Stuba search - Failed to parse rooms_data: ' . $e->getMessage());
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
    // GET STUBA MODULE
    // ========================================
    $module = $db->get('modules', '*', [
        'name' => 'stuba',
        'type' => 'stays'
    ]);
    
    if (!$module) {
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

    // Get Stuba credentials and connection details
    $dbHost = $module['host'] ?? 'localhost';
    $dbName = $module['database'] ?? '';
    $dbUser = $module['username'] ?? 'root';
    $dbPass = $module['password'] ?? '';
    $org = $module['c1'] ?? '';
    $user = $module['c2'] ?? '';
    $password = $module['c3'] ?? '';
    $environment = ($module['dev_mode'] ?? 0) == 1 ? 'test' : 'production';

    if (empty($dbName)) {
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
    // SEARCH REQUEST LOGGING
    // ========================================
    try {
        $user_id = isset($searchSessionData['user_id']) ? $searchSessionData['user_id'] : 'guest';
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['HTTP_CLIENT_IP'] ?? 'unknown'));

        $search_params = [
            'city' => $city,
            'destination' => $city,
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
            'page' => $page,
            'per_page' => $per_page,
            'supplier' => 'stuba'
        ];

        $db->insert('logs_searches', [
            'user_id' => (string)$user_id,
            'module' => 'stays',
            'request' => json_encode($search_params),
            'created_at' => date('Y-m-d H:i:s'),
            'ip' => $user_ip
        ]);
    } catch (Exception $e) {
        error_log('Stuba search logging failed: ' . $e->getMessage());
    }

    // Create Stuba database connection using Medoo
    try {
        $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
        $stubaPDO = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);

        $stubaDb = new Medoo\Medoo(['type' => 'mysql', 'pdo' => $stubaPDO]);
    } catch (Exception $e) {
        error_log('Stuba DB Error: ' . $e->getMessage());
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

    // // ========================================
    // // CLEAR OLD CITY IMAGES
    // // ========================================
    // if (!empty($city)) {
    //     clearOtherCityImages($city, $stubaDb);
    // }

    // Format city name
    $formatted_city = ucwords(str_replace('-', ' ', $city));
    
    // Try to find hotel in database with matching city (case-insensitive)
    try {
        $cityMatch = $stubaDb->get('stuba_hotels', ['city_id', 'city', 'region_id'], [
            'city[~]' => $formatted_city,
            'LIMIT' => 1
        ]);
    } catch (Exception $e) {
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

    if (!$cityMatch) {
        error_log("Stuba: City '$formatted_city' not found in stuba_hotels table. Trying alternative search...");
        // Try without formatting
        $cityMatch = $stubaDb->get('stuba_hotels', ['city_id', 'city', 'region_id'], [
            'city[~]' => $city,
            'LIMIT' => 1
        ]);
    }
    
    if ($cityMatch && !empty($cityMatch['city_id'])) {
        $city_id = $cityMatch['city_id'];
        $region_id = $cityMatch['region_id'];
        $region_name_found = $cityMatch['city'];
    } else {
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
    // COUNT TOTAL HOTELS FOR PAGINATION
    // ========================================
    $countConditions = [
        'region_id' => $region_id
    ];

    // Apply star filter if specified
    if ($star_rating != 'any' && is_numeric($star_rating)) {
        $countConditions['stars'] = (int)$star_rating;
    }

    if (!empty($hotel_name)) {
        $countConditions['OR'] = [
            'hotel_name[~]' => '%' . $hotel_name . '%',
            'hotel_id' => $hotel_name
        ];
    }

    $totalHotels = $stubaDb->count('stuba_hotels', $countConditions);
    
    // Calculate pagination
    $totalPages = ceil($totalHotels / $per_page);
    $offset = ($page - 1) * $per_page;
    $hasMore = $page < $totalPages;

    // ========================================
    // STEP 1: FETCH HOTELS FROM DATABASE WITH PAGINATION
    // ========================================
    $whereConditions = [
        'region_id' => $region_id
    ];

    // Apply star filter
    if ($star_rating != 'any' && is_numeric($star_rating)) {
        $whereConditions['stars'] = (int)$star_rating;
    }

    if (!empty($hotel_name)) {
        $whereConditions['OR'] = [
            'hotel_name[~]' => '%' . $hotel_name . '%',
            'hotel_id' => $hotel_name
        ];
    }

    // Fetch 3x more hotels to account for unavailable ones
    $fetchLimit = $per_page * 3;
    $whereConditions['LIMIT'] = [$offset, $fetchLimit];
    $whereConditions['ORDER'] = ['stars' => 'DESC', 'rating' => 'DESC'];

    $hotels = $stubaDb->select('stuba_hotels', '*', $whereConditions);

    if (empty($hotels)) {
        ob_end_clean();
        header('Content-Type: application/json');
        header('X-Total-Results: 0');
        header('X-Destination-Total: ' . $totalHotels);
        header('X-Total-Pages: 0');
        header('X-Current-Page: ' . $page);
        header('X-Per-Page: ' . $per_page);
        header('X-Has-More: false');
        echo json_encode([]);
        exit;
    }

    error_log("Stuba Search Params: city=$city, city_id=$city_id, region_id=$region_id, checkin=$checkin, checkout=$checkout, adults=$adults, children=$children, page=$page, per_page=$per_page");

    // ========================================
    // DATE CALCULATION
    // ========================================
    $number_of_nights = 1;
    $checkin_formatted = '';
    $checkout_formatted = '';

    if (!empty($checkin) && !empty($checkout)) {
        try {
            // Input format: DD-MM-YYYY
            $checkin_parts = explode('-', $checkin);
            $checkout_parts = explode('-', $checkout);

            if (count($checkin_parts) === 3 && count($checkout_parts) === 3) {
                // Convert to YYYY-MM-DD
                $checkin_formatted = "{$checkin_parts[2]}-{$checkin_parts[1]}-{$checkin_parts[0]}";
                $checkout_formatted = "{$checkout_parts[2]}-{$checkout_parts[1]}-{$checkout_parts[0]}";

                $date1 = new DateTime($checkin_formatted);
                $date2 = new DateTime($checkout_formatted);
                $interval = $date1->diff($date2);
                $number_of_nights = max(1, (int)$interval->days);
            }
        } catch (Exception $e) {
            // Default to tomorrow and day after
            $checkin_formatted = date('Y-m-d', strtotime('+1 day'));
            $checkout_formatted = date('Y-m-d', strtotime('+2 days'));
            $number_of_nights = 1;
        }
    } else {
        // Default dates if not provided
        $checkin_formatted = date('Y-m-d', strtotime('+1 day'));
        $checkout_formatted = date('Y-m-d', strtotime('+2 days'));
        $number_of_nights = 1;
    }
    
    // ========================================
    // STEP 2: PREPARE SOAP API CALL
    // ========================================
    $base_endpoint = $environment === 'test'
        ? 'http://www.stubademo.com/RXLStagingServices/ASMX/XmlService.asmx'
        : 'http://api.stuba.com/RXLServices/ASMX/XmlService.asmx';

    // Collect hotel IDs for batch API call
    $hotelCodes = array_column($hotels, 'hotel_id');
    
    // Build hotel IDs XML
    $hotel_ids_xml = '';
    foreach ($hotelCodes as $id) {
        $hotel_ids_xml .= "<Id>{$id}</Id>\n";
    }

    // Build guests XML from rooms_data
    $guestsXml = '';
    if (!empty($rooms_data) && count($rooms_data) > 0) {
        // Use first room configuration for API call
        $firstRoom = $rooms_data[0];
        $roomAdults = (int)($firstRoom['adults'] ?? 2);
        $roomChildren = (int)($firstRoom['children'] ?? 0);
        $childAges = $firstRoom['childAges'] ?? [];
        
        // Build Adults
        for ($i = 0; $i < $roomAdults; $i++) {
            $guestsXml .= "<Adult />\n";
        }
        
        // Build Children with ages
        for ($i = 0; $i < $roomChildren; $i++) {
            $age = isset($childAges[$i]) ? (int)$childAges[$i] : 0;
            $guestsXml .= "<Child age=\"{$age}\" />\n";
        }
    } else {
        // Fallback to simple adults/children count
        for ($i = 0; $i < $adults; $i++) {
            $guestsXml .= "<Adult />\n";
        }
        for ($i = 0; $i < $children; $i++) {
            $guestsXml .= "<Child age=\"0\" />\n";
        }
    }

    // ========================================
    // STEP 3: BUILD SOAP XML REQUEST
    // ========================================
    $xml_post_string = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <AvailabilitySearch xmlns="http://www.reservwire.com/namespace/WebServices/Xml">
      <xiRequest>
        <Authority>
          <Org>' . $org . '</Org>
          <User>' . $user . '</User>
          <Password>' . $password . '</Password>
          <Currency>USD</Currency>
          <Version>1.28</Version>
        </Authority>
        <RegionId>' . $city_id . '</RegionId>
        <Hotels>' . $hotel_ids_xml . '</Hotels>
        <HotelStayDetails>
          <ArrivalDate>' . $checkin_formatted . '</ArrivalDate>
          <Nights>' . $number_of_nights . '</Nights>
          <Nationality>' . $nationality . '</Nationality>
          <Room>
           <Guests>
             ' . $guestsXml . '
           </Guests>
         </Room>
        </HotelStayDetails>
        <DetailLevel>basic</DetailLevel>
        <MaxResultsPerHotel>0</MaxResultsPerHotel>
        <MaxHotels>0</MaxHotels>
        <MaxSearchTime>0</MaxSearchTime>
      </xiRequest>
    </AvailabilitySearch>
  </soap:Body>
</soap:Envelope>';

    // Log API request
    error_log("Stuba API Request to: {$base_endpoint}");

    // ========================================
    // STEP 4: MAKE SOAP API CALL
    // ========================================
    $headers = [
        'Content-Type: text/xml; charset=utf-8',
        'SOAPAction: "http://www.reservwire.com/namespace/WebServices/Xml/AvailabilitySearch"',
        'Content-Length: ' . strlen($xml_post_string)
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
    curl_setopt($ch, CURLOPT_URL, $base_endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_post_string);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $apiResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    // Initialize available hotels array
    $availableHotelsFromApi = [];

    // ========================================
    // STEP 5: PARSE SOAP XML RESPONSE
    // ========================================
    if ($httpCode === 200 && !empty($apiResponse)) {
        // Extract SOAP Body content
        $pattern = '/<soap:Body>(.*)<\/soap:Body>/s';
        if (preg_match($pattern, $apiResponse, $matches)) {
            $bodyContent = $matches[1];
            $json = xmlToJson($bodyContent);
        } else {
            $json = xmlToJson($apiResponse);
        }

        $apiData = json_decode($json);

        // Process API results
        if (!empty($apiData->AvailabilitySearchResult->HotelAvailability)) {
            $apiCurrency = $apiData->AvailabilitySearchResult->Currency ?? 'USD';
            
            foreach ($apiData->AvailabilitySearchResult->HotelAvailability as $hotelAvail) {
                $hotelId = (string)$hotelAvail->Hotel->{'@attributes'}->id;
                
                // Extract pricing from Result
                $price = 0;
                $room_name = '';
                $refundable_type = '';
                
                if (!empty($hotelAvail->Result->Room->Price->{'@attributes'}->amt)) {
                    $price = floatval($hotelAvail->Result->Room->Price->{'@attributes'}->amt);
                    $room_name = $hotelAvail->Result->Room->RoomType->{'@attributes'}->text ?? 'Standard Room';
                    $refundable_type = $hotelAvail->Result->Room->CancellationPolicyStatus ?? '';
                } else if (isset($hotelAvail->Result) && is_array($hotelAvail->Result) && isset($hotelAvail->Result[0]->Room->Price->{'@attributes'}->amt)) {
                    $price = floatval($hotelAvail->Result[0]->Room->Price->{'@attributes'}->amt);
                    $room_name = $hotelAvail->Result[0]->Room->RoomType->{'@attributes'}->text ?? 'Standard Room';
                    $refundable_type = $hotelAvail->Result[0]->Room->CancellationPolicyStatus ?? '';
                } else if (isset($hotelAvail->Result) && is_array($hotelAvail->Result) && isset($hotelAvail->Result[0]->Room[0]->Price->{'@attributes'}->amt)) {
                    $price = floatval($hotelAvail->Result[0]->Room[0]->Price->{'@attributes'}->amt);
                    $room_name = $hotelAvail->Result[0]->Room[0]->RoomType->{'@attributes'}->text ?? 'Standard Room';
                    $refundable_type = $hotelAvail->Result[0]->Room[0]->CancellationPolicyStatus ?? '';
                }
                
                if ($price > 0) {
                    // Apply markup to price
                    $price_per_night_markup = MARKUP($price, $module, $db, $apiCurrency, $sessionCurrency);
                    
                    // Calculate total price
                    $total_price = $price_per_night_markup['price'] * $number_of_nights * $rooms;
                    
                    $total_price_markup = [
                        'price' => round($total_price, 2),
                        'markup' => $price_per_night_markup['markup'] * $number_of_nights * $rooms,
                        'markup_percentage' => $price_per_night_markup['markup_percentage'],
                        'markup_type' => $price_per_night_markup['markup_type'],
                        'markup_value' => $price_per_night_markup['markup_value'],
                        'base_price' => round($price * $number_of_nights * $rooms, 2),
                        'converted_base_price' => round($price_per_night_markup['converted_base_price'] * $number_of_nights * $rooms, 2)
                    ];
                    
                    // Determine refundability
                    $refundable = ($refundable_type == "Refundable") ? 1 : 0;
                    $cancellation_free = $refundable;
                    
                    // Store in available hotels array
                    $availableHotelsFromApi[$hotelId] = [
                        'min_price_per_night' => $price_per_night_markup['price'],
                        'min_total_price' => $total_price_markup['price'],
                        'min_price_per_night_details' => $price_per_night_markup,
                        'min_total_price_details' => $total_price_markup,
                        'currency' => $sessionCurrency,
                        'api_currency' => $apiCurrency,
                        'room_name' => $room_name,
                        'refundable' => $refundable,
                        'cancellation_free' => $cancellation_free,
                        'has_available_rooms' => true
                    ];
                }
            }
        }
    } else {
        error_log("Stuba API Error - HTTP {$httpCode}: {$curlError}");
    }

    // ========================================
    // RETURN EMPTY IF NO HOTELS AVAILABLE FROM API
    // ========================================
    if (empty($availableHotelsFromApi)) {
        ob_end_clean();
        header('Content-Type: application/json');
        header('X-Total-Results: 0');
        header('X-Destination-Total: ' . $totalHotels);
        header('X-Total-Pages: 0');
        header('X-Current-Page: ' . $page);
        header('X-Per-Page: ' . $per_page);
        header('X-Has-More: false');
        echo json_encode([]);
        exit;
    }

    // ========================================
    // STEP 6: MERGE DATABASE DATA WITH API PRICING
    // ========================================
    $formattedHotels = [];

    try {
        foreach ($hotels as $hotel) {
            $hotelId = $hotel['hotel_id'];
            
            // Skip hotels not available from API
            if (!isset($availableHotelsFromApi[$hotelId])) {
                continue;
            }
            
            // Get API pricing data
            $apiPricing = $availableHotelsFromApi[$hotelId];
            
            $stars = (int)($hotel['stars'] ?? 3);

            // ========================================
            // GET HOTEL IMAGES WITH CACHING
            // ========================================
            $hotelImages = [];
            $primaryImage = '';
            try {
                $allImages = $stubaDb->select('stuba_hotel_images', ['image_url', 'is_primary', 'image_type', 'caption'], [
                    'hotel_id' => $hotel['hotel_id'],
                    'ORDER' => ['is_primary' => 'DESC', 'image_order' => 'ASC']
                ]);

                if ($allImages && count($allImages) > 0) {
                    foreach ($allImages as $img) {
                        // Cache each image
                        $img['image_url'] = "https://hotelcontent-c4e7fhcwdeguhbgk.a03.azurefd.net/rxlimages%2F" . str_replace('RXLStagingImages', 'RXLImages', str_replace('https://content.stuba.com/', '', $img['image_url'] ?? ''));
                        $cachedImageUrl = cacheImage($img['image_url'], $hotelId, $city);
                        $hotelImages[] = $cachedImageUrl;

                        if ($img['is_primary'] == 1 && empty($primaryImage)) {
                            $primaryImage = $cachedImageUrl;
                        }
                    }

                    // If no primary set, use first image
                    if (empty($primaryImage) && count($hotelImages) > 0) {
                        $primaryImage = $hotelImages[0];
                    }
                }
            } catch (Exception $e) {
                // Images table doesn't exist or no images
            }

            // Get amenities for this hotel - MATCHING HOTELBEDS STRUCTURE
            $amenitiesList = [];
            try {
                $hotelAmenities = $stubaDb->select('stuba_hotel_amenities', [
                    '[>]stuba_amenities' => ['amenity_id' => 'amenity_id']
                ], [
                    'stuba_amenities.amenity_id',
                    'stuba_amenities.amenity_name',
                    'stuba_amenities.category',
                    'stuba_amenities.icon',
                    'stuba_hotel_amenities.is_free'
                ], [
                    'stuba_hotel_amenities.hotel_id' => $hotel['hotel_id']
                ]);

                if ($hotelAmenities) {
                    foreach ($hotelAmenities as $amenity) {
                        $amenitiesList[] = [
                            'id' => $amenity['amenity_id'],
                            'name' => $amenity['amenity_name']
                        ];
                    }
                }
            } catch (Exception $e) {
                // Amenities table doesn't exist
            }

            // Get room types for this hotel - RESTRUCTURED TO MATCH HOTELBEDS
            $groupedRoomOptions = [];
            
            try {
                $rooms_db = $stubaDb->select('stuba_room_types', '*', [
                    'hotel_id' => $hotel['hotel_id'],
                    'LIMIT' => 10
                ]);

                if ($rooms_db && count($rooms_db) > 0) {
                    foreach ($rooms_db as $room) {
                        $roomId = $room['room_id'];
                        $roomName = $room['room_name'];
                        
                        // ========================================
                        // GET ROOM IMAGES WITH CACHING
                        // ========================================
                        $roomImages = [];
                        try {
                            $roomImageRecords = $stubaDb->select('stuba_room_images', ['image_url'], [
                                'room_id' => $roomId,
                                'ORDER' => ['image_order' => 'ASC'],
                                'LIMIT' => 5
                            ]);

                            foreach ($roomImageRecords as $img) {
                                // Cache each room image
                                $img['image_url'] = "https://hotelcontent-c4e7fhcwdeguhbgk.a03.azurefd.net/rxlimages%2F" . str_replace('RXLStagingImages', 'RXLImages', str_replace('https://testcontent.stuba.com/', '', $img['image_url'] ?? ''));
                                $cachedRoomImageUrl = cacheImage($img['image_url'], $hotelId, $city);
                                $roomImages[] = [
                                    'url' => $cachedRoomImageUrl,
                                    'default' => empty($roomImages) // First image is default
                                ];
                            }
                        } catch (Exception $e) {
                            // Room images table doesn't exist
                        }
                        
                        // Room amenities
                        $roomAmenities = [];
                        try {
                            $roomAmenitiesRecords = $stubaDb->select('stuba_room_amenities', [
                                '[>]stuba_amenities' => ['amenity_id' => 'amenity_id']
                            ], [
                                'stuba_amenities.amenity_id',
                                'stuba_amenities.amenity_name'
                            ], [
                                'stuba_room_amenities.room_id' => $roomId
                            ]);

                            foreach ($roomAmenitiesRecords as $amenity) {
                                $roomAmenities[] = [
                                    'id' => $amenity['amenity_id'],
                                    'name' => $amenity['amenity_name']
                                ];
                            }
                        } catch (Exception $e) {
                            // Room amenities table doesn't exist
                        }
                        
                        // Use API pricing for this room
                        $roomTotalPriceMarkup = [
                            'price' => $apiPricing['min_total_price'],
                            'markup' => $apiPricing['min_total_price_details']['markup'],
                            'markup_percentage' => $apiPricing['min_total_price_details']['markup_percentage'],
                            'markup_type' => $apiPricing['min_total_price_details']['markup_type'],
                            'markup_value' => $apiPricing['min_total_price_details']['markup_value'],
                            'base_price' => $apiPricing['min_total_price_details']['base_price'],
                            'converted_base_price' => $apiPricing['min_total_price_details']['converted_base_price']
                        ];
                        
                        // Create room option with API pricing
                        $roomOptions = [];
                        
                        // Option 1: Standard rate (Room Only)
                        $roomOptions[] = [
                            'option_index' => 0,
                            'price_per_night' => $apiPricing['min_price_per_night'],
                            'price_per_night_details' => $apiPricing['min_price_per_night_details'],
                            'total_price' => $apiPricing['min_total_price'],
                            'total_price_details' => $apiPricing['min_total_price_details'],
                            'room_id' => $roomId,
                            'rate_key' => '', // Stuba doesn't provide rate keys
                            'rate_class' => '',
                            'rate_type' => 'BOOKABLE',
                            'board_code' => 'RO',
                            'board_name' => 'Room Only',
                            'max_adults' => (int)($room['max_occupancy'] ?? 2),
                            'max_children' => 0,
                            'breakfast_included' => 0,
                            'refundable' => $apiPricing['refundable'],
                            'cancellation_free' => $apiPricing['cancellation_free'],
                            'discount_percentage' => 0,
                            'packaging' => false,
                            'allotment' => null
                        ];
                        
                        // Add to grouped room options
                        $groupedRoomOptions[] = [
                            'room_name' => $roomName,
                            'room_type_id' => $roomId,
                            'room_id' => $roomId,
                            'amenities' => $roomAmenities,
                            'room_images' => $roomImages,
                            'room_main_image' => !empty($roomImages) ? $roomImages[0]['url'] : '',
                            'options' => $roomOptions,
                            'options_count' => count($roomOptions),
                            'min_price_per_night' => $apiPricing['min_price_per_night'],
                            'max_adults' => (int)($room['max_occupancy'] ?? 2),
                            'max_children' => 0
                        ];
                    }
                }
            } catch (Exception $e) {
                // Room types table doesn't exist
            }
            
            // If no room types found, create a default one with API pricing
            if (empty($groupedRoomOptions)) {
                $defaultRoomOption = [
                    'option_index' => 0,
                    'price_per_night' => $apiPricing['min_price_per_night'],
                    'price_per_night_details' => $apiPricing['min_price_per_night_details'],
                    'total_price' => $apiPricing['min_total_price'],
                    'total_price_details' => $apiPricing['min_total_price_details'],
                    'room_id' => 'default',
                    'rate_key' => '',
                    'rate_class' => '',
                    'rate_type' => 'BOOKABLE',
                    'board_code' => 'RO',
                    'board_name' => 'Room Only',
                    'max_adults' => 2,
                    'max_children' => 0,
                    'breakfast_included' => 0,
                    'refundable' => $apiPricing['refundable'],
                    'cancellation_free' => $apiPricing['cancellation_free'],
                    'discount_percentage' => 0,
                    'packaging' => false,
                    'allotment' => null
                ];
                
                $groupedRoomOptions[] = [
                    'room_name' => $apiPricing['room_name'] ?? 'Standard Room',
                    'room_type_id' => 'default',
                    'room_id' => 'default',
                    'amenities' => [],
                    'room_images' => [],
                    'room_main_image' => '',
                    'options' => [$defaultRoomOption],
                    'options_count' => 1,
                    'min_price_per_night' => $apiPricing['min_price_per_night'],
                    'max_adults' => 2,
                    'max_children' => 0
                ];
            }

            // ========================================
            // BUILD RESPONSE - MATCHING HOTELBEDS STRUCTURE EXACTLY
            // ========================================
            $formattedHotels[] = [
                // Basic hotel information
                'hotel_id' => 'stuba_' . $hotel['hotel_id'],
                'name' => $hotel['hotel_name'],
                'img' => $primaryImage,
                'images' => $hotelImages,
                'location' => $hotel['city'],
                'address' => $hotel['address'] ?? '',
                'stars' => $stars,
                'rating' => (float)($hotel['rating'] ?? round(3.5 + ($stars * 0.3), 1)),

                // Geolocation
                'latitude' => !empty($hotel['latitude']) ? floatval($hotel['latitude']) : null,
                'longitude' => !empty($hotel['longitude']) ? floatval($hotel['longitude']) : null,

                // Pricing (with markup + currency conversion applied FROM API)
                'display_price' => $apiPricing['min_total_price'],
                'display_price_per_night' => $apiPricing['min_price_per_night'],
                'actual_price' => $apiPricing['min_total_price'],
                'actual_price_per_night' => $apiPricing['min_price_per_night'],
                'actual_price_details' => $apiPricing['min_total_price_details'],
                'actual_price_per_night_details' => $apiPricing['min_price_per_night_details'],
                'currency' => $sessionCurrency,
                'original_currency' => $apiPricing['api_currency'],

                // Hotel features
                'amenities' => $amenitiesList,
                'accommodation_type' => 'Hotel',

                // ROOM OPTIONS - NOW STRUCTURED EXACTLY LIKE HOTELBEDS
                'room_options' => $groupedRoomOptions,
                'has_available_rooms' => count($groupedRoomOptions) > 0,

                // Supplier info
                'supplier' => 'stuba',
                'supplier_name' => 'stuba',
                'supplier_id' => '', // Stuba doesn't have supplier ID
                'color' => '#00a8e8', // Stuba brand color

                // Additional metadata (matching Hotelbeds fields)
                'country_code' => $hotel['country_code'] ?? '',
                'destination_code' => '', // Stuba doesn't have destination codes
                'chain_code' => '', // Stuba doesn't have chain codes
                'category_code' => '', // Stuba doesn't have category codes

                // Policies
                'phone' => $hotel['phone'] ?? '',
                'email' => $hotel['email'] ?? '',
                'website' => $hotel['website'] ?? ''
            ];
            
            // Limit results to requested per_page
            if (count($formattedHotels) >= $per_page) {
                break;
            }
        }
    } catch (Exception $e) {
        error_log('Stuba hotel processing error: ' . $e->getMessage());
    }

    // ========================================
    // JSON RESPONSE WITH PAGINATION HEADERS
    // ========================================
    ob_end_clean();
    
    $totalResults = count($formattedHotels);
    $hasMore = count($availableHotelsFromApi) > count($formattedHotels);
    
    header('Content-Type: application/json');
    header('X-Total-Results: ' . $totalResults);
    header('X-Destination-Total: ' . $totalHotels);
    header('X-Total-Pages: ' . ceil($totalResults / $per_page));
    header('X-Current-Page: ' . $page);
    header('X-Per-Page: ' . $per_page);
    header('X-Has-More: ' . ($hasMore ? 'true' : 'false'));

    echo json_encode($formattedHotels);
});

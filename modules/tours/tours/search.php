<?php
// ============================================================================
// TOUR SEARCH API ENDPOINT - COMPLETE DOCUMENTATION
// ============================================================================
//
// PURPOSE:
// Main backend endpoint for searching tours with real-time availability,
// dynamic pricing with B2B/B2C markup, and currency conversion.
//
// ENDPOINT: POST /tours/search
//
// ============================================================================
// REQUEST PARAMETERS (sent from frontend Alpine.js)
// ============================================================================
//
// 1. destination (string)         - City/Location name (e.g., "Dubai", "Paris")
//                                   Source: $searchSessionData['tour_destination']
//                                   Frontend: searchParams.destination
//
// 2. start_date (string)          - Tour start date in DD-MM-YYYY format
//                                   Source: $searchSessionData['tour_start_date']
//                                   Default: +3 days from today
//                                   Frontend: searchParams.start_date
//
// 3. duration (string)            - Tour duration filter
//                                   Options: "1", "2-3", "4-7", "8-14", "15+"
//                                   Frontend: searchParams.duration
//
// 4. adults (integer)             - Number of adults (default: 1)
//                                   Source: $searchSessionData['tour_adults']
//                                   Frontend: searchParams.adults
//
// 5. children (integer)           - Number of children (default: 0)
//                                   Source: $searchSessionData['tour_children']
//                                   Frontend: searchParams.children
//
// 6. tour_type (string)           - Tour type ID filter (numeric)
//                                   Source: $searchSessionData['tour_type']
//                                   Frontend: searchParams.tour_type
//
// 7. currency (string)            - Display currency code (e.g., "USD", "SAR")
//                                   Source: $searchSessionData['app_currency']
//                                   Default: USD
//                                   Frontend: searchParams.currency
//
// 8. page (integer)               - Page number for pagination (default: 1)
//                                   Frontend: searchParams.page
//                                   Used for infinite scroll loading
//
// 9. per_page (integer)           - Results per page (default: 25, max: 100)
//                                   Frontend: searchParams.per_page
//                                   Controls how many tours load at once
//
// ============================================================================
// PRICING & MARKUP LOGIC FLOW
// ============================================================================
//
// STEP 1: BASE PRICE EXTRACTION
// - Adult price stored in `tours.adult_price` field
// - Child price stored in `tours.child_price` field
// - Infant price stored in `tours.infant_price` field
// - Currency stored in `tours.currency` field (defaults to "USD")
//
// STEP 2: MARKUP APPLICATION (via MARKUP() function)
// - Function Location: app/lib/functions.php (lines 1182-1255)
// - Module type: 'tours' (must match modules.type field)
// - Markup retrieved from `modules` table where type='tours'
// - Fields used: markup_b2b, markup_b2c, markup_type_b2b, markup_type_b2c
// - User type determines which markup applies (B2B for agents, B2C for customers)
//
// MARKUP CALCULATION ORDER (CRITICAL):
//   a) Apply markup to adult price separately
//   b) Apply markup to child price separately
//   c) Calculate total price = (marked-up adult price × adults) + (marked-up child price × children)
//   d) Per person price = marked-up adult price (based only on adult price)
//
// ============================================================================
// DATABASE TABLES USED
// ============================================================================
//
// 1. tours                  - Tour master data (id, name, location, days, etc.)
// 2. tours_settings         - Tour types, inclusions, exclusions, amenities
// 3. modules                - Markup configuration (B2B/B2C percentages)
// 4. currencies             - Exchange rates for currency conversion
// 5. logs_searches          - Search request logging
//
// ============================================================================
// RESPONSE FORMAT (JSON array of tour objects)
// ============================================================================
//
// Each tour object contains:
// - tour_id, name, location, address, days, nights
// - display_price           - Final price with markup (adult + child separately marked up)
// - display_price_per_person - Per person price (only adult price with markup)
// - tour_type               - Tour type name (e.g., "Adventure", "Cultural")
// - inclusions              - Array of included services
// - exclusions              - Array of excluded services
// - amenities               - Array of tour amenities
// - itinerary               - Day-by-day itinerary array
// - has_available_slots     - Boolean flag for availability
//
// Frontend consumption: tours.php uses display_price and display_price_per_person
//
// ============================================================================

$router->post('tours/tours/search', function() use ($db) {
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
    // INITIALIZATION - Extract and validate search parameters from POST request
    // ========================================
    $destination = $_POST['destination'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
    $duration = $_POST['duration'] ?? '';
    $adults = (int)($_POST['adults'] ?? 1);
    $children = (int)($_POST['children'] ?? 0);
    $tour_type = $_POST['tour_type'] ?? '';
    $currency = $_POST['currency'] ?? 'USD';

    // ========================================
    // PAGINATION PARAMETERS
    // ========================================
    $page = max(1, (int)($_POST['page'] ?? 1));
    $per_page = min(100, max(1, (int)($_POST['per_page'] ?? 25)));
    $supplierRawResponse = null;

    try {
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
                    // Start session if not started to populate session variables for search session data
                    if (session_status() === PHP_SESSION_NONE) {
                        session_start();
                    }
                    $_SESSION['user_id'] = $tokenData['user_id'];
                    $_SESSION['user_role'] = $tokenData['role'] ?? null;
                    $searchSessionData['user_id'] = $tokenData['user_id'];
                    $searchSessionData['user_role'] = $tokenData['role'] ?? null;
                }
            } catch (Exception $e) {
                // Silent fail
            }
        }

    // ========================================
    // MODULE CONFIGURATION
    // ========================================
    $module = $db->get('modules', '*', [
        'name' => 'tours',
        'type' => 'tours'
    ]);

    if (!$module) {
        echo json_encode([
            'status' => false,
            'message' => 'Tours module not configured',
            'response' => []
        ]);
        exit;
    }

    // Session currency takes precedence
    $sessionCurrency = isset($searchSessionData['app_currency']) ? $searchSessionData['app_currency'] : $currency;

    // ========================================
    // SEARCH REQUEST LOGGING
    // ========================================
    try {
        $user_id = isset($searchSessionData['user_id']) ? $searchSessionData['user_id'] : 'guest';
        $user_ip = $_SERVER['REMOTE_ADDR'] ??
                   ($_SERVER['HTTP_X_FORWARDED_FOR'] ??
                   ($_SERVER['HTTP_CLIENT_IP'] ?? 'unknown'));

        $search_params = [
            'destination' => $destination,
            'start_date' => $start_date,
            'duration' => $duration,
            'adults' => $adults,
            'children' => $children,
            'tour_type' => $tour_type,
            'currency' => $sessionCurrency
        ];

        $db->insert('logs_searches', [
            'user_id' => (string)$user_id,
            'module' => 'tours',
            'request' => json_encode($search_params),
            'created_at' => date('Y-m-d H:i:s'),
            'ip' => $user_ip
        ]);

    } catch (Exception $e) {
        error_log('Tour search logging failed: ' . $e->getMessage());
    }

    // ========================================
    // DATABASE QUERY
    // ========================================
    $whereConditions = ['status' => 1];

    if (!empty($destination)) {
        // Professional multi-field search logic: Split search terms and match against name, location, and address
        $searchTerms = array_filter(explode(' ', trim($destination)));
        
        if (!empty($searchTerms)) {
            $whereConditions['OR #search_filter'] = [
                'name[~]' => $searchTerms,
                'location[~]' => $searchTerms,
                'address[~]' => $searchTerms
            ];
        }
    }
    
    // Duration filter
    if (!empty($duration)) {
        if ($duration === '1') {
            $whereConditions['days'] = 1;
        } elseif ($duration === '2-3') {
            $whereConditions['days[>=]'] = 2;
            $whereConditions['days[<=]'] = 3;
        } elseif ($duration === '4-7') {
            $whereConditions['days[>=]'] = 4;
            $whereConditions['days[<=]'] = 7;
        } elseif ($duration === '8-14') {
            $whereConditions['days[>=]'] = 8;
            $whereConditions['days[<=]'] = 14;
        } elseif ($duration === '15+') {
            $whereConditions['days[>=]'] = 15;
        }
    }
    
    // Tour type filter
    if (!empty($tour_type) && is_numeric($tour_type)) {
        $whereConditions['tour_type_id'] = (int)$tour_type;
    }

    // ========================================
    // PAGINATION LOGIC
    // ========================================
    $totalTours = $db->count('tours', $whereConditions);
    $totalPages = ceil($totalTours / $per_page);
    $offset = ($page - 1) * $per_page;
    
    $whereConditions['LIMIT'] = [$offset, $per_page];
    $whereConditions['ORDER'] = ['featured' => 'DESC', 'rating_average' => 'DESC', 'created_at' => 'DESC'];
    
    $tours = $db->select('tours', '*', $whereConditions);
    $formattedTours = [];

    // ========================================
    // TOUR PROCESSING LOOP
    // ========================================
    foreach ($tours as $tour) {
        
        // Extract base prices from tour record
        $adult_price = (float)$tour['adult_price'];
        $child_price = (float)$tour['child_price'];
        
        // Get tour's original currency (default to USD)
        $tourCurrency = !empty($tour['currency']) ? $tour['currency'] : 'USD';
        
        // ========================================
        // CORRECTED PRICING LOGIC
        // ========================================
        // 1. Currency conversion WITHOUT markup for actual price
        $converted_adult_price = CURRENCY_CONVERT($adult_price, $db, $tourCurrency, $sessionCurrency);
        $converted_child_price = CURRENCY_CONVERT($child_price, $db, $tourCurrency, $sessionCurrency);
        
        // 2. Apply markup to adult price separately (WITH currency conversion)
        $marked_up_adult_price = MARKUP($adult_price, $module, $db, $tourCurrency, $sessionCurrency);
        
        // 3. Apply markup to child price separately (WITH currency conversion)
        $marked_up_child_price = MARKUP($child_price, $module, $db, $tourCurrency, $sessionCurrency);
        
        // 4. Calculate total display price (WITH markup + currency conversion)
        $display_price = ($marked_up_adult_price['price'] * $adults) + 
                        ($marked_up_child_price['price'] * $children);
        
        // 5. Per person price - based only on adult price with markup
        $display_price_per_person = $marked_up_adult_price['price'];
        
        // 6. Calculate actual price (WITHOUT markup but WITH currency conversion)
        $actual_price = ($converted_adult_price['price'] * $adults) + 
                        ($converted_child_price['price'] * $children);
        
        // 7. Actual price per person (WITHOUT markup but WITH currency conversion)
        $actual_price_per_person = $converted_adult_price['price'];

        // ========================================
        // IMAGE EXTRACTION
        // ========================================
        $tourImage = '';
        $tourImages = [];

        if (!empty($tour['img'])) {
            $imageArray = json_decode($tour['img'], true);

            if (is_array($imageArray)) {
                foreach ($imageArray as $image) {
                    if (!empty($image['url'])) {
                        $fullImageUrl = dirname(root) . $image['url'];
                        $tourImages[] = $fullImageUrl;

                        if (isset($image['default']) && $image['default'] === true && empty($tourImage)) {
                            $tourImage = $fullImageUrl;
                        }
                    }
                }

                if (empty($tourImage) && !empty($tourImages)) {
                    $tourImage = $tourImages[0];
                }
            }
        }

        // ========================================
        // TOUR TYPE
        // ========================================
        $tourTypeName = 'Tour';
        if (!empty($tour['tour_type_id']) && is_numeric($tour['tour_type_id'])) {
            $tourTypeRecord = $db->get('tours_settings', 'setting_label', [
                'id' => (int)$tour['tour_type_id'],
                'setting_type' => 'tour_type',
                'status' => 1
            ]);
            if ($tourTypeRecord) {
                $tourTypeName = $tourTypeRecord;
            }
        }

        // ========================================
        // INCLUSIONS EXTRACTION
        // ========================================
        $inclusions = [];
        if (!empty($tour['inclusions'])) {
            $inclusionIds = json_decode($tour['inclusions'], true);

            if (is_array($inclusionIds) && !empty($inclusionIds)) {
                $inclusionRecords = $db->select('tours_settings', ['id', 'setting_label', 'icon'], [
                    'id' => $inclusionIds,
                    'setting_type' => 'inclusion',
                    'status' => 1
                ]);

                foreach ($inclusionRecords as $inclusion) {
                    $inclusions[] = [
                        'id' => $inclusion['id'],
                        'name' => $inclusion['setting_label'],
                        'icon' => $inclusion['icon']
                    ];
                }
            }
        }

        // ========================================
        // EXCLUSIONS EXTRACTION
        // ========================================
        $exclusions = [];
        if (!empty($tour['exclusions'])) {
            $exclusionIds = json_decode($tour['exclusions'], true);

            if (is_array($exclusionIds) && !empty($exclusionIds)) {
                $exclusionRecords = $db->select('tours_settings', ['id', 'setting_label', 'icon'], [
                    'id' => $exclusionIds,
                    'setting_type' => 'exclusion',
                    'status' => 1
                ]);

                foreach ($exclusionRecords as $exclusion) {
                    $exclusions[] = [
                        'id' => $exclusion['id'],
                        'name' => $exclusion['setting_label'],
                        'icon' => $exclusion['icon']
                    ];
                }
            }
        }

        // ========================================
        // AMENITIES EXTRACTION
        // ========================================
        $amenities = [];
        if (!empty($tour['amenities'])) {
            $amenityIds = json_decode($tour['amenities'], true);

            if (is_array($amenityIds) && !empty($amenityIds)) {
                $amenityRecords = $db->select('tours_settings', ['id', 'setting_label', 'icon'], [
                    'id' => $amenityIds,
                    'setting_type' => 'amenity',
                    'status' => 1
                ]);

                foreach ($amenityRecords as $amenity) {
                    $amenities[] = [
                        'id' => $amenity['id'],
                        'name' => $amenity['setting_label'],
                        'icon' => $amenity['icon']
                    ];
                }
            }
        }

        // ========================================
        // AVAILABILITY CHECK
        // ========================================
        $has_available_slots = true;
        $max_adults = (int)$tour['max_adults'];
        $max_children = (int)$tour['max_children'];
        
        if ($max_adults > 0 && $adults > $max_adults) {
            $has_available_slots = false;
        }
        
        if ($max_children > 0 && $children > $max_children) {
            $has_available_slots = false;
        }

        // ========================================
        // RESPONSE FORMATTING
        // ========================================
        $formattedTours[] = [
            // Basic tour information
            'tour_id' => $tour['id'],
            'name' => $tour['name'],
            'slug' => $tour['slug'] ?? '',
            'img' => $tourImage,
            'images' => $tourImages,
            'location' => $tour['location'],
            'address' => $tour['address'] ?? '',
            
            // Duration
            'days' => (int)$tour['days'],
            'nights' => (int)$tour['nights'],
            
            // Tour type
            'tour_type_id' => (int)$tour['tour_type_id'],
            'tour_type' => $tourTypeName,
            
            // Ratings
            'stars' => (int)$tour['stars'],
            'rating' => isset($tour['rating_average']) ? (float)$tour['rating_average'] : 0.0,
            
            // Currency fields
            'currency' => $sessionCurrency,
            'original_currency' => $tour['currency'],
            
            // CORRECTED PRICING FIELDS:
            // Display price: WITH markup + currency conversion
            'display_price' => $display_price,
            'display_price_per_person' => $display_price_per_person,
            
            // Actual price: WITHOUT markup but WITH currency conversion
            'actual_price' => $actual_price,
            'actual_price_per_person' => $actual_price_per_person,
            
            // Detailed breakdown
            'actual_price_details' => [
                // With markup details
                'with_markup' => [
                    'adult' => $marked_up_adult_price,
                    'child' => $marked_up_child_price,
                    'calculation' => [
                        'adult_unit_price' => $marked_up_adult_price['price'],
                        'child_unit_price' => $marked_up_child_price['price'],
                        'adults_count' => $adults,
                        'children_count' => $children,
                        'subtotal_adults' => $marked_up_adult_price['price'] * $adults,
                        'subtotal_children' => $marked_up_child_price['price'] * $children,
                        'total' => $display_price
                    ]
                ],
                // Without markup details
                'without_markup' => [
                    'adult' => $converted_adult_price,
                    'child' => $converted_child_price,
                    'calculation' => [
                        'adult_unit_price' => $converted_adult_price['price'],
                        'child_unit_price' => $converted_child_price['price'],
                        'adults_count' => $adults,
                        'children_count' => $children,
                        'subtotal_adults' => $converted_adult_price['price'] * $adults,
                        'subtotal_children' => $converted_child_price['price'] * $children,
                        'total' => $actual_price
                    ]
                ]
            ],
            
            'base_adult_price' => $adult_price,
            'base_child_price' => $child_price,
            'base_infant_price' => isset($tour['infant_price']) ? (float)$tour['infant_price'] : 0.0,
            
            // Capacity and availability
            'max_adults' => $max_adults,
            'max_children' => $max_children,
            'max_infants' => isset($tour['max_infants']) ? (int)$tour['max_infants'] : 0,
            'current_adults' => $adults,
            'current_children' => $children,
            
            // Tour details
            'inclusions' => $inclusions,
            'exclusions' => $exclusions,
            'amenities' => $amenities,
            
            // Supplier information
            'supplier_id' => $tour['supplier_id'] ?? null,
            
            // Availability
            'has_available_slots' => $has_available_slots,
            'available_slots_message' => $has_available_slots ? '' : 'Maximum capacity exceeded',
            
            // Commission and tax
            'commission_fixed' => isset($tour['commission_fixed']) ? (float)$tour['commission_fixed'] : 0.0,
            'commission_percentage' => isset($tour['commission_percentage']) ? (float)$tour['commission_percentage'] : 0.0,
            'tax_fixed' => isset($tour['tax_fixed']) ? (float)$tour['tax_fixed'] : 0.0,
            'tax_percentage' => isset($tour['tax_percentage']) ? (float)$tour['tax_percentage'] : 0.0
        ];
    }

    // ========================================
    // JSON RESPONSE
    // ========================================
    $json = json_encode($formattedTours);
    ob_end_clean();
    
    header('Content-Type: application/json');
    header('X-Total-Results: ' . $totalTours);
    header('X-Total-Pages: ' . $totalPages);
    header('X-Current-Page: ' . $page);
    header('X-Per-Page: ' . $per_page);
    header('X-Has-More: ' . ($page < $totalPages ? 'true' : 'false'));
    
    echo $json;
    } catch (Exception $e) {
        error_log('Tours Search Error: ' . $e->getMessage());
        ob_end_clean();
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode([
            'status' => false,
            'message' => $e->getMessage(),
            'supplier_error' => [
                'supplier' => 'tours',
                'message' => $e->getMessage()
            ],
            'raw_response' => $supplierRawResponse,
            'response' => []
        ]);
    }
});
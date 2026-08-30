<?php
// ============================================================================
// TOUR DETAILS API ENDPOINT - COMPLETE DOCUMENTATION
// ============================================================================
//
// PURPOSE:
// Fetch complete details of a single tour including pricing, inclusions,
// exclusions, amenities, itinerary, and supplier information.
//
// ENDPOINT: POST /tours/tours/details
//
// ============================================================================
// REQUEST PARAMETERS (JSON body)
// ============================================================================
//
// 1. tour_id (integer/string)    - Tour ID from database
//    Required: Yes
//    Source: URL parameter or $_SESSION['tour_detail']['tour_id']
//    Example: 7, 2, 100
//
// 2. departure_date (string)     - Tour start date in DD-MM-YYYY format
//    Required: Yes for pricing calculation
//    Source: URL parameter or $_SESSION['tour_detail']['departure_date']
//    Example: "20-12-2025"
//
// 3. total_adults (integer)      - Number of adults
//    Required: Yes for pricing calculation
//    Priority Order:
//      a) URL travelers string (e.g., "2-2" from /tour/.../2-2)
//      b) $_SESSION['tour_detail']['total_adults']
//      c) Input parameter
//      d) Default: 1
//
// 4. total_children (integer)    - Number of children
//    Required: No
//    Priority Order: Same as adults
//    Default: 0
//
// 5. supplier (string)           - Supplier identifier
//    Required: Yes
//    Source: URL parameter or input
//    Default: "tours"
//
// 6. currency (string)           - Display currency code
//    Required: No
//    Default: USD or session currency
//
// ============================================================================
// TRAVELERS EXTRACTION PRIORITY
// ============================================================================
// CRITICAL: Always use latest travelers from URL, not old session values
// Format: /tour/{slug}/{id}/{supplier}/{date}/{duration}/{travelers}
// Travelers format: "adults-children" or just "adults"
// Example: "2-2" = 2 adults, 2 children
// Example: "3" = 3 adults, 0 children
//
// Extraction logic:
// 1. Parse $_SESSION['tour_detail']['travelers_str'] from URL
// 2. Fallback to $_SESSION['tour_detail']['total_adults/children']
// 3. Fallback to input parameters
// 4. Default to 1 adult, 0 children
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
// STEP 2: DISCOUNT APPLICATION
// - Discount percentage from `tours.discount_percentage`
// - Formula: base_price - (base_price × discount_percentage / 100)
//
// STEP 3: MARKUP APPLICATION (via MARKUP() function)
// - Function Location: app/lib/functions.php (lines 1182-1255)
// - Module type: 'tours' (must match modules.type field)
// - Markup retrieved from `modules` table where type='tours'
// - Fields used: markup_b2b, markup_b2c, markup_type_b2b, markup_type_b2c
// - User type determines which markup applies (B2B for agents, B2C for customers)
//
// STEP 4: CURRENCY CONVERSION (via CURRENCY_CONVERT() function)
// - New function for currency conversion without markup
// - Used for actual_price field
// - Converts base price to session currency
//
// MARKUP CALCULATION ORDER:
//   a) Apply markup to adult base price separately
//   b) Apply markup to child base price separately
//   c) Calculate total display price = (marked-up adult price × adults) + (marked-up child price × children)
//   d) Display price per adult = marked-up adult price
//   e) Display price per child = marked-up child price
//
// ============================================================================
// DATABASE TABLES USED
// ============================================================================
//
// 1. tours                  - Tour master data (id, name, location, days, etc.)
// 2. tours_settings         - Tour types, inclusions, exclusions, amenities
// 3. modules                - Markup configuration (B2B/B2C percentages)
// 4. currencies             - Exchange rates for currency conversion
//
// ============================================================================
// RESPONSE FORMAT (JSON object)
// ============================================================================
//
// Success response contains:
// - success: true
// - data: Complete tour object with all details
//
// Error response contains:
// - success: false
// - message: Error description
// - missing_parameters: Array of missing params (if applicable)
//
// Tour object fields:
// - id, name, location, address, days, nights
// - display_price           - Final total price with markup (sum of all marked-up prices)
// - display_price_per_adult - Per adult price with markup
// - display_price_per_child - Per child price with markup
// - actual_price            - Total price WITHOUT markup but WITH currency conversion
// - actual_price_per_adult  - Per adult price WITHOUT markup but WITH currency conversion
// - tour_type               - Tour type name (e.g., "Adventure", "Cultural")
// - inclusions              - Array of included services
// - exclusions              - Array of excluded services
// - amenities               - Array of tour amenities
// - itinerary               - Day-by-day itinerary array
// - has_available_slots     - Boolean flag for availability
// - base_adult_price        - Base adult price before markup
// - base_child_price        - Base child price before markup
// - current_adults          - Actual adults count used for calculation
// - current_children        - Actual children count used for calculation
//
// Frontend consumption: tour.php uses display_price, display_price_per_adult, and display_price_per_child
//
// ============================================================================

// ============================================================================
// TOUR DETAILS PAGE API
// Purpose: Single tour details with complete pricing and information
// Endpoint: POST /tours/tours/details
// ============================================================================

$router->post('/tours/tours/details', function() use ($db) {
    error_reporting(0);
    ini_set('display_errors', 0);
    ob_start();
    header('Content-Type: application/json');

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
                    $_SESSION['user_id'] = $tokenData['user_id'];
                    $_SESSION['user_role'] = $tokenData['role'] ?? null;
                }
            } catch (Exception $e) {
                // Silent fail
            }
        }
        // --------------------------------------------------------
        // REQUEST DATA HANDLING
        // --------------------------------------------------------
        $input = json_decode(file_get_contents('php://input'), true);

        // Required parameters
        $tourId = $input['tour_id'] ?? '';
        $startDate = $input['departure_date'] ?? ($input['start_date'] ?? '18-12-2025');
        $supplier = $input['supplier'] ?? 'manual';
        
        // Travelers count priority: URL > Session > Input > Default
        $adults = 0;
        $children = 0;
        
        if (isset($_SESSION['tour_detail']['travelers_str'])) {
            $travelersStr = $_SESSION['tour_detail']['travelers_str'];
            if (strpos($travelersStr, '-') !== false) {
                list($adults, $children) = explode('-', $travelersStr);
                $adults = intval($adults);
                $children = intval($children);
            } else {
                $adults = intval($travelersStr);
                $children = 0;
            }
        }
        
        if ($adults === 0) {
            $adults = (int)($input['total_adults'] ?? ($input['adults'] ?? 1));
            $children = (int)($input['total_children'] ?? ($input['children'] ?? 0));
        }
        
        $currency = $input['currency'] ?? 'USD';

        // --------------------------------------------------------
        // INPUT VALIDATION
        // --------------------------------------------------------
        if (empty($tourId) || empty($startDate)) {
            $missing = [];
            if (empty($tourId)) $missing[] = 'tour_id';
            if (empty($startDate)) $missing[] = 'departure_date';

            echo json_encode([
                'success' => false,
                'message' => 'Missing required parameters',
                'missing_parameters' => $missing,
                'received' => [
                    'tour_id' => $tourId,
                    'departure_date' => $startDate,
                    'adults' => $adults,
                    'children' => $children,
                    'currency' => $currency,
                    'supplier' => $supplier,
                    'url_travelers' => $_SESSION['tour_detail']['travelers_str'] ?? 'not set'
                ]
            ]);
            exit;
        }

        if (!isset($db)) {
            echo json_encode([
                'success' => false,
                'message' => 'Database connection not available'
            ]);
            exit;
        }

        // --------------------------------------------------------
        // TOUR MODULE CONFIGURATION
        // --------------------------------------------------------
        $module = $db->get('modules', '*', [
            'name' => 'tours',
            'type' => 'tours'
        ]);

        if (!$module) {
            echo json_encode([
                'success' => false,
                'message' => 'Tours module not configured'
            ]);
            exit;
        }

        // Session currency takes precedence
        $sessionCurrency = isset($_SESSION['app_currency']) ? $_SESSION['app_currency'] : $currency;
        
        // System default currency for base prices
        $systemDefaultCurrency = $db->get('currencies', 'name', ['default' => '1']) ?? 'USD';

        // --------------------------------------------------------
        // FETCH TOUR FROM DATABASE
        // --------------------------------------------------------
        $tour = $db->get('tours', '*', [
            'id' => $tourId,
            'status' => '1'
        ]);

        if (!$tour) {
            echo json_encode([
                'success' => false,
                'message' => 'Tour not found or inactive'
            ]);
            exit;
        }

        // --------------------------------------------------------
        // PRICE CALCULATION WITH MARKUP
        // --------------------------------------------------------
        // Extract base prices from tour record
        $adult_price = (float)$tour['adult_price'];
        $child_price = (float)$tour['child_price'];
        $infant_price = (float)$tour['infant_price'];
        $discount_percentage = (float)$tour['discount_percentage'];
        
        // Calculate base price based on number of travelers
        $base_price = ($adult_price * $adults) + ($child_price * $children);
        
        // Get tour's original currency
        $tourCurrency = !empty($tour['currency']) ? $tour['currency'] : 'USD';
        
        // ========================================
        // CORRECTED PRICING LOGIC
        // ========================================
        // 1. Currency conversion WITHOUT markup for actual price
        $converted_adult_price = CURRENCY_CONVERT($adult_price, $db, $tourCurrency, $sessionCurrency);
        $converted_child_price = CURRENCY_CONVERT($child_price, $db, $tourCurrency, $sessionCurrency);
        
        // 2. Apply markup to adult price separately (WITH currency conversion)
        $marked_up_adult_price = MARKUP($adult_price, $module, $db, $tourCurrency, $sessionCurrency);
        $marked_up_adult_price_base = MARKUP($adult_price, $module, $db, $tourCurrency, $systemDefaultCurrency); // System Default currency markup

        // 3. Apply markup to child price separately (WITH currency conversion)
        $marked_up_child_price = MARKUP($child_price, $module, $db, $tourCurrency, $sessionCurrency);
        $marked_up_child_price_base = MARKUP($child_price, $module, $db, $tourCurrency, $systemDefaultCurrency); // System Default currency markup
        
        // 4. Calculate total display price (WITH markup + currency conversion)
        $display_price = ($marked_up_adult_price['price'] * $adults) + 
                        ($marked_up_child_price['price'] * $children);
        
        // 5. Per person prices with markup
        $display_price_per_adult = $marked_up_adult_price['price'];
        $display_price_per_child = $marked_up_child_price['price'];
        
        // 6. Calculate actual price (WITHOUT markup but WITH currency conversion)
        $actual_price = ($converted_adult_price['price'] * $adults) + 
                        ($converted_child_price['price'] * $children);
        
        // 7. Actual price per person (WITHOUT markup but WITH currency conversion)
        $actual_price_per_adult = $converted_adult_price['price'];
        $actual_price_per_child = $converted_child_price['price'];

        // --------------------------------------------------------
        // IMAGE PROCESSING
        // --------------------------------------------------------
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

        // --------------------------------------------------------
        // TOUR TYPE NAME
        // --------------------------------------------------------
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

        // --------------------------------------------------------
        // INCLUSIONS PROCESSING
        // --------------------------------------------------------
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

        // --------------------------------------------------------
        // EXCLUSIONS PROCESSING
        // --------------------------------------------------------
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

        // --------------------------------------------------------
        // AMENITIES PROCESSING
        // --------------------------------------------------------
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

        // --------------------------------------------------------
        // ITINERARY PROCESSING
        // --------------------------------------------------------
        $itinerary = [];
        if (!empty($tour['itinerary'])) {
            $itineraryData = json_decode($tour['itinerary'], true);
            
            if (is_array($itineraryData)) {
                foreach ($itineraryData as $dayItem) {
                    $processedDay = $dayItem;
                    
                    if (!empty($dayItem['image'])) {
                        $processedDay['image'] = dirname(root) . $dayItem['image'];
                    }
                    
                    if (!empty($dayItem['activities']) && is_array($dayItem['activities'])) {
                        $processedActivities = [];
                        foreach ($dayItem['activities'] as $activity) {
                            $processedActivity = $activity;
                            
                            if (!empty($activity['image'])) {
                                $processedActivity['image'] = dirname(root) . $activity['image'];
                            }
                            
                            if (!empty($activity['images']) && is_array($activity['images'])) {
                                $processedGallery = [];
                                foreach ($activity['images'] as $galleryImage) {
                                    if (!empty($galleryImage['url'])) {
                                        $processedGallery[] = [
                                            'url' => dirname(root) . $galleryImage['url'],
                                            'alt' => $galleryImage['alt'] ?? '',
                                            'default' => $galleryImage['default'] ?? false
                                        ];
                                    } else if (is_string($galleryImage)) {
                                        $processedGallery[] = [
                                            'url' => dirname(root) . $galleryImage,
                                            'alt' => '',
                                            'default' => false
                                        ];
                                    }
                                }
                                $processedActivity['images'] = $processedGallery;
                            }
                            
                            $processedActivities[] = $processedActivity;
                        }
                        $processedDay['activities'] = $processedActivities;
                    }
                    
                    $itinerary[] = $processedDay;
                }
            }
        }

        // --------------------------------------------------------
        // TAGS PROCESSING
        // --------------------------------------------------------
        $tags = [];
        if (!empty($tour['tags'])) {
            $tagsArray = json_decode($tour['tags'], true);
            if (is_array($tagsArray)) {
                $tags = $tagsArray;
            } else if (is_string($tour['tags'])) {
                $tags = array_map('trim', explode(',', $tour['tags']));
            }
        }

        // --------------------------------------------------------
        // AVAILABILITY CHECK
        // --------------------------------------------------------
        $has_available_slots = true;
        $max_adults = (int)$tour['max_adults'];
        $max_children = (int)$tour['max_children'];
        
        $availability_message = '';
        
        if ($max_adults > 0 && $adults > $max_adults) {
            $has_available_slots = false;
            $availability_message .= "Maximum adults: $max_adults. ";
        }
        
        if ($max_children > 0 && $children > $max_children) {
            $has_available_slots = false;
            $availability_message .= "Maximum children: $max_children. ";
        }

        // --------------------------------------------------------
        // PRICE BREAKDOWN DETAILS
        // --------------------------------------------------------
        $price_breakdown = [
            'adults' => [
                'count' => $adults,
                'base_price_per_person' => round($adult_price, 2),
                'converted_price_per_person' => round($converted_adult_price['price'], 2),
                'marked_up_price_per_person' => round($display_price_per_adult, 2),
                'marked_up_price_per_person_base' => round($marked_up_adult_price_base['price'], 2), // Base currency
                'subtotal_base' => round($adult_price * $adults, 2),
                'subtotal_converted' => round($converted_adult_price['price'] * $adults, 2),
                'subtotal_marked_up' => round($display_price_per_adult * $adults, 2),
                'subtotal_marked_up_base' => round($marked_up_adult_price_base['price'] * $adults, 2), // Base currency
                'markup_details' => $marked_up_adult_price,
                'currency_details' => $converted_adult_price
            ],
            'children' => [
                'count' => $children,
                'base_price_per_person' => round($child_price, 2),
                'converted_price_per_person' => round($converted_child_price['price'], 2),
                'marked_up_price_per_person' => round($display_price_per_child, 2),
                'marked_up_price_per_person_base' => round($marked_up_child_price_base['price'], 2), // Base currency
                'subtotal_base' => round($child_price * $children, 2),
                'subtotal_converted' => round($converted_child_price['price'] * $children, 2),
                'subtotal_marked_up' => round($display_price_per_child * $children, 2),
                'subtotal_marked_up_base' => round($marked_up_child_price_base['price'] * $children, 2), // Base currency
                'markup_details' => $marked_up_child_price,
                'currency_details' => $converted_child_price
            ],
            'summary' => [
                'total_base_price' => round(($adult_price * $adults) + ($child_price * $children), 2),
                'total_converted_price' => round($actual_price, 2),
                'total_marked_up_price' => round($display_price, 2),
                'total_marked_up_price_base' => round(($marked_up_adult_price_base['price'] * $adults) + ($marked_up_child_price_base['price'] * $children), 2), // Base currency
                'currency_conversion' => [
                    'from_currency' => $tourCurrency,
                    'to_currency' => $sessionCurrency,
                    'converted' => $converted_adult_price['converted']
                ]
            ]
        ];

        // --------------------------------------------------------
        // BUILD RESPONSE
        // --------------------------------------------------------
        $response = [
            'success' => true,
            'data' => [
                // Basic information
                'id' => (int)$tour['id'],
                'name' => $tour['name'],
                'slug' => $tour['slug'] ?? '',
                'description' => $tour['description'] ?? 'No description available.',
                'short_description' => substr(strip_tags($tour['description'] ?? ''), 0, 150) . '...',
                
                // Location details
                'location' => $tour['location'],
                'address' => $tour['address'] ?? '',
                'latitude' => isset($tour['latitude']) ? (float)$tour['latitude'] : null,
                'longitude' => isset($tour['longitude']) ? (float)$tour['longitude'] : null,
                
                // Duration
                'days' => (int)$tour['days'],
                'nights' => (int)$tour['nights'],
                
                // Tour type
                'tour_type_id' => (int)$tour['tour_type_id'],
                'tour_type' => $tourTypeName,
                
                // Ratings
                'stars' => (int)$tour['stars'],
                'rating_average' => isset($tour['rating_average']) ? (float)$tour['rating_average'] : 0.0,
                'rating_count' => isset($tour['rating_count']) ? (int)$tour['rating_count'] : 0,
                
                // Images
                'image' => $tourImage,
                'images' => $tourImages,
                
                // ========================================
                // CORRECTED PRICING INFORMATION
                // ========================================
                // Display price: WITH markup + currency conversion
                'currency' => $sessionCurrency,
                'original_currency' => $tourCurrency,
                'display_price' => $display_price,
                'display_price_per_adult' => $display_price_per_adult,
                'display_price_per_child' => $display_price_per_child,
                
                // Actual price: WITHOUT markup but WITH currency conversion
                'actual_price' => $actual_price,
                'actual_price_per_adult' => $actual_price_per_adult,
                'actual_price_per_child' => $actual_price_per_child,
                
                'price_breakdown' => $price_breakdown,
                
                // Base prices for calculations
                'base_adult_price' => $adult_price,
                'base_child_price' => $child_price,
                'base_infant_price' => $infant_price,
                'original_base_price' => ($adult_price * $adults) + ($child_price * $children),
                'original_db_currency' => $tourCurrency,
                
                // Capacity
                'max_adults' => $max_adults,
                'max_children' => $max_children,
                'current_adults' => $adults,
                'current_children' => $children,
                
                // Features and services
                'inclusions' => $inclusions,
                'exclusions' => $exclusions,
                'amenities' => $amenities,
                'itinerary' => $itinerary,
                'tags' => $tags,
                
                // Policies
                'cancellation_policy' => $tour['cancellation_policy'] ?? '',
                'terms_conditions' => $tour['terms_conditions'] ?? '',
                'refundable' => (bool)($tour['refundable'] ?? false),
                
                // Availability
                'has_available_slots' => $has_available_slots,
                'availability_message' => $availability_message,
                
                // Supplier information
                'supplier_id' => $tour['supplier_id'] ?? null,
                'supplier' => $supplier,
                'email' => $tour['email'] ?? '',
                'phone' => $tour['phone'] ?? '',
                'website' => $tour['website'] ?? '',
                
                // Commission and tax
                'commission_fixed' => isset($tour['commission_fixed']) ? (float)$tour['commission_fixed'] : 0.0,
                'commission_percentage' => isset($tour['commission_percentage']) ? (float)$tour['commission_percentage'] : 0.0,
                'tax_fixed' => isset($tour['tax_fixed']) ? (float)$tour['tax_fixed'] : 0.0,
                'tax_percentage' => isset($tour['tax_percentage']) ? (float)$tour['tax_percentage'] : 0.0,
                
                // Meta information
                'meta_title' => $tour['meta_title'] ?? '',
                'meta_description' => $tour['meta_description'] ?? '',
                'meta_keywords' => $tour['meta_keywords'] ?? '',
                
                // Timestamps
                'created_at' => $tour['created_at'] ?? '',
                'updated_at' => $tour['updated_at'] ?? '',
                
                // Search parameters
                'search_params' => [
                    'start_date' => $startDate,
                    'adults' => $adults,
                    'children' => $children,
                ]
            ]
        ];

        // Clear buffer and output JSON
        ob_clean();
        echo json_encode($response);
        ob_end_flush();

    } catch (Exception $e) {
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $e->getMessage(),
            'error_details' => $e->getFile() . ':' . $e->getLine()
        ]);
        ob_end_flush();
    }
});
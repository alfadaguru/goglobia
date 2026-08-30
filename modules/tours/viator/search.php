<?php
// ============================================================================
// VIATOR TOUR SEARCH API ENDPOINT - STANDARDIZED RESPONSE FORMAT
// ============================================================================
//
// PURPOSE:
// Search tours via Viator API and return data in standardized format
// matching the local tours module response structure with markup/pricing
//
// ENDPOINT: POST /tours/viator/search
//
// ============================================================================

$router->post('tours/viator/search', function() use ($db) {
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
    
    header('Content-Type: application/json');
    $supplierRawResponse = null;
    
    try {
        // ========================================
        // EXTRACT SEARCH PARAMETERS
        // ========================================
        $destination = $_POST['destination'] ?? '';
        $start_date = $_POST['start_date'] ?? '';
        $duration = $_POST['duration'] ?? '';
        $adults = (int)($_POST['adults'] ?? 1);
        $children = (int)($_POST['children'] ?? 0);
        $tour_type = $_POST['tour_type'] ?? '';
        $currency = $_POST['currency'] ?? 'USD';
        $page = max(1, (int)($_POST['page'] ?? 1));
        $per_page = min(100, max(1, (int)($_POST['per_page'] ?? 25)));
        
        // Price filters
        $price_from = !empty($_POST['price_from']) ? (int)$_POST['price_from'] : 0;
        $price_to = !empty($_POST['price_to']) ? (int)$_POST['price_to'] : 10000;
        
        // Rating filter
        $rating_from = !empty($_POST['rating']) ? (int)$_POST['rating'] : 0;
        $rating_to = !empty($_POST['rating']) ? (int)$_POST['rating'] : 5;
        
        if (empty($destination)) {
            throw new Exception('Destination is required');
        }
        
        // ========================================
        // GET MODULE CONFIGURATION
        // ========================================
        $module = $db->get('modules', '*', [
            'name' => 'viator',
            'type' => 'tours'
        ]);
        
        if (!$module || $module['status'] != 1) {
            throw new Exception('Viator module not configured or disabled');
        }
        
        $api_key = $module['c1'];
        $environment = $module['env'] ?? 'production';
        
        if (empty($api_key) || $api_key === 'test') {
            throw new Exception('Valid Viator API Key required');
        }
        
        // Session currency takes precedence
        $sessionCurrency = isset($searchSessionData['app_currency']) ? $searchSessionData['app_currency'] : $currency;
        
        // ========================================
        // DETERMINE API ENDPOINT
        // ========================================
        if ($environment === 'dev' || $environment === 'test') {
            $base_url = 'https://api.sandbox.viator.com';
        } else {
            $base_url = 'https://api.viator.com';
        }
        
        $search_url = $base_url . '/partner/search/freetext';
        
        // ========================================
        // CONVERT DATE FORMAT
        // ========================================
        if (!empty($start_date)) {
            // Handle DD-MM-YYYY format (convert to YYYY-MM-DD)
            if (strpos($start_date, '-') !== false) {
                $parts = explode('-', $start_date);
                if (count($parts) == 3 && strlen($parts[2]) == 4) {
                    // Format is DD-MM-YYYY
                    $formatted_date = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
                } else {
                    // Try standard strtotime
                    $time = strtotime($start_date);
                    $formatted_date = date('Y-m-d', $time);
                }
            } else {
                $formatted_date = date('Y-m-d', strtotime($start_date));
            }
        } else {
            $formatted_date = date('Y-m-d', strtotime('+3 days'));
        }
        
        
        // ========================================
        // BUILD API REQUEST PAYLOAD
        // ========================================
        // Set default price range if not provided
        if ($price_from == 0 && $price_to == 10000) {
            $price_from = 0;
            $price_to = 1000; // Match working code default
        }
        
        // Set default rating range if not provided
        if ($rating_from == 0 && $rating_to == 5) {
            $rating_from = 0;
            $rating_to = 5;
        }
        
        $search_payload = [
            'searchTerm' => $destination,
            'productFiltering' => [
                'dateRange' => [
                    'from' => $formatted_date
                ],
                'price' => [
                    'from' => $price_from,
                    'to' => $price_to
                ],
                'rating' => [
                    'from' => $rating_from,
                    'to' => $rating_to
                ]
            ],
            'searchTypes' => [[
                'searchType' => 'PRODUCTS',
                'pagination' => [
                    'start' => (($page - 1) * $per_page) + 1,
                    'count' => $per_page
                ]
            ]],
            'currency' => 'USD'
        ];
        
        // Log the request payload
        
        // ========================================
        // MAKE API REQUEST
        // ========================================
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $search_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($search_payload),
            CURLOPT_HTTPHEADER => [
                'exp-api-key: ' . $api_key,
                'Accept: application/json;version=2.0',
                'Accept-Language: en',
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $api_response = curl_exec($ch);
        $supplierRawResponse = $api_response;
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        
        // Log API response for debugging
        
        if ($curl_error) {
            throw new Exception('API Connection Error: ' . $curl_error);
        }
        
        if ($http_code !== 200) {
            $error_data = json_decode($api_response, true);
            $error_msg = isset($error_data['message']) ? $error_data['message'] : 'Unknown error';
            throw new Exception('Viator API returned HTTP ' . $http_code . ': ' . $error_msg);
        }
        
        $viator_data = json_decode($api_response, true);
        
        // Check for API-level errors
        if (isset($viator_data['code']) || isset($viator_data['error'])) {
            $error_msg = $viator_data['message'] ?? $viator_data['error'] ?? 'API Error';
            throw new Exception('Viator API Error: ' . $error_msg);
        }
        
        if (!isset($viator_data['products']['results'])) {
            echo json_encode([
                'status' => true,
                'response' => [],
                'total' => 0,
                'message' => 'No tours found'
            ]);
            exit;
        }
        
        // ========================================
        // PROCESS RESULTS - MAP TO STANDARD FORMAT
        // ========================================
        $formattedTours = [];
        $totalCount = $viator_data['products']['totalCount'] ?? 0;
        
        foreach ($viator_data['products']['results'] as $product) {
            
            // ========================================
            // VALIDATE LOCATION (Prevent unrelated fallback tours)
            // ========================================
            if (!empty($product['productUrl']) && preg_match('#/tours/([^/]+)/#i', $product['productUrl'], $matches)) {
                $tour_location = strtolower(str_replace('-', ' ', urldecode($matches[1])));
                $dest_lower = strtolower(trim($destination));
                $title_lower = strtolower($product['title'] ?? '');
                $is_valid = false;
                
                // 1. Exact location match
                if ($tour_location === $dest_lower) {
                    $is_valid = true;
                }
                // 2. Starts with match (e.g. "Dubai" in "Dubai City")
                else if (strpos($tour_location, $dest_lower) === 0) {
                    $is_valid = true;
                }
                // 3. Contains match in location or title
                else if (strpos($tour_location, $dest_lower) !== false || strpos($title_lower, $dest_lower) !== false) {
                    $is_valid = true;
                }
                
                if (!$is_valid) {
                    continue; // Skip irrelevant tours
                }
            }
            
            // Extract base price (from Viator)
            $base_price = (float)($product['pricing']['summary']['fromPrice'] ?? 0);
            
            // Get product currency
            $productCurrency = $product['pricing']['currency'] ?? 'USD';
            
            // ========================================
            // APPLY MARKUP AND CURRENCY CONVERSION
            // ========================================
            // 1. Convert currency WITHOUT markup (actual price)
            $converted_price = CURRENCY_CONVERT($base_price, $db, $productCurrency, $sessionCurrency);
            
            // 2. Apply markup WITH currency conversion (display price)
            $marked_up_price = MARKUP($base_price, $module, $db, $productCurrency, $sessionCurrency);
            
            // Display price (with markup)
            $display_price = $marked_up_price['price'];
            
            // Actual price (without markup)
            $actual_price = $converted_price['price'];
            
            // ========================================
            // EXTRACT DURATION
            // ========================================
            $minutes = 0;
            if (isset($product['duration']['fixedDurationInMinutes'])) {
                $minutes += (int)$product['duration']['fixedDurationInMinutes'];
            }
            if (isset($product['duration']['variableDurationToMinutes'])) {
                $minutes += (int)$product['duration']['variableDurationToMinutes'];
            }
            
            $hours = floor($minutes / 60);
            $mins = $minutes - ($hours * 60);
            $duration_formatted = $hours . ':' . str_pad($mins, 2, '0', STR_PAD_LEFT);
            
            // Calculate days (for compatibility)
            $days = $hours > 0 ? ceil($hours / 8) : 1; // Assume 8-hour workday
            $nights = max(0, $days - 1);
            
            // ========================================
            // EXTRACT IMAGE - Always get LARGEST variant
            // ========================================
            $tourImage = '';
            $tourImages = [];
            
            if (isset($product['images']) && is_array($product['images'])) {
                foreach ($product['images'] as $image) {
                    if (isset($image['variants']) && is_array($image['variants']) && count($image['variants']) > 0) {
                        // Sort variants by width to get the largest
                        $sortedVariants = $image['variants'];
                        usort($sortedVariants, function($a, $b) {
                            $widthA = isset($a['width']) ? (int)$a['width'] : 0;
                            $widthB = isset($b['width']) ? (int)$b['width'] : 0;
                            return $widthB - $widthA; // Descending order (largest first)
                        });
                        
                        // Get the largest variant (first after sorting)
                        if (isset($sortedVariants[0]['url'])) {
                            $largestUrl = $sortedVariants[0]['url'];
                            $tourImages[] = $largestUrl;
                            if (empty($tourImage)) {
                                $tourImage = $largestUrl;
                            }
                        }
                    }
                }
                
                // Fallback to first image if none found
                if (empty($tourImage) && !empty($tourImages)) {
                    $tourImage = $tourImages[0];
                }
            }
            
            // ========================================
            // EXTRACT RATING
            // ========================================
            $rating = 0;
            if (isset($product['reviews']['combinedAverageRating'])) {
                $rating = round((float)$product['reviews']['combinedAverageRating'], 1);
            }
            
            // ========================================
            // BUILD STANDARDIZED RESPONSE
            // ========================================
            $formattedTours[] = [
                // Basic tour information
                'tour_id' => $product['productCode'],
                'name' => $product['title'] ?? 'Untitled Tour',
                'slug' => strtolower(str_replace(' ', '-', preg_replace('/[^a-zA-Z0-9\s]/', '', $product['title'] ?? ''))),
                'img' => $tourImage,
                'images' => $tourImages,
                'location' => $destination,
                'address' => '',
                
                // Duration
                'days' => $days,
                'nights' => $nights,
                'duration_formatted' => $duration_formatted,
                'duration_minutes' => $minutes,
                
                // Tour type
                'tour_type_id' => 0,
                'tour_type' => 'Tour',
                
                // Ratings
                'stars' => min(5, max(1, (int)ceil($rating))),
                'rating' => $rating,
                
                // Currency fields
                'currency' => $sessionCurrency,
                'original_currency' => $productCurrency,
                
                // PRICING FIELDS (matching local tours format)
                'display_price' => $display_price,
                'display_price_per_person' => $display_price,
                'actual_price' => $actual_price,
                'actual_price_per_person' => $actual_price,
                
                // Detailed breakdown
                'actual_price_details' => [
                    'with_markup' => [
                        'price' => $marked_up_price['price'],
                        'markup_type' => $marked_up_price['markup_type'],
                        'markup_value' => $marked_up_price['markup_value'],
                        'original_price' => $base_price,
                        'original_currency' => $productCurrency
                    ],
                    'without_markup' => [
                        'price' => $converted_price['price'],
                        'original_price' => $base_price,
                        'original_currency' => $productCurrency
                    ]
                ],
                
                'base_adult_price' => $base_price,
                'base_child_price' => $base_price * 0.8, // Viator doesn't separate, assume 80%
                'base_infant_price' => 0,
                
                // Capacity (Viator doesn't provide, use defaults)
                'max_adults' => 50,
                'max_children' => 20,
                'max_infants' => 5,
                'current_adults' => $adults,
                'current_children' => $children,
                
                // Tour details (simplified for Viator)
                'inclusions' => [],
                'exclusions' => [],
                'amenities' => [],
                'description' => $product['description'] ?? '',
                
                // Supplier information
                'supplier' => 'viator',
                'supplier_id' => $module['id'],
                'supplier_product_url' => $product['productUrl'] ?? '',
                
                // Availability
                'has_available_slots' => true,
                'available_slots_message' => '',
                
                // Commission and tax (from module settings)
                'commission_fixed' => 0,
                'commission_percentage' => 0,
                'tax_fixed' => 0,
                'tax_percentage' => 0,
                
                // Viator specific
                'viator_product_code' => $product['productCode'],
                'viator_product_url' => $product['productUrl'] ?? ''
            ];
        }
        
        // ========================================
        // CALCULATE PAGINATION
        // ========================================
        if (empty($formattedTours)) {
            $totalCount = 0;
            $totalPages = 0;
        } else {
            // Keep original totalCount if we didn't filter everything, but ensure it's at least the number of tours we have
            $totalCount = max($totalCount, count($formattedTours));
            $totalPages = ceil($totalCount / $per_page);
        }
        
        // ========================================
        // RETURN RESPONSE
        // ========================================
        header('X-Total-Results: ' . $totalCount);
        header('X-Total-Pages: ' . $totalPages);
        header('X-Current-Page: ' . $page);
        header('X-Per-Page: ' . $per_page);
        header('X-Has-More: ' . ($page < $totalPages ? 'true' : 'false'));
        
        echo json_encode([
            'status' => true,
            'response' => $formattedTours,
            'total' => $totalCount,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $per_page,
                'total_pages' => $totalPages,
                'total_results' => $totalCount,
                'has_more' => $page < $totalPages
            ]
        ]);
        
    } catch (Exception $e) {
        error_log('Viator Search Error: ' . $e->getMessage());
        
        http_response_code(400);
        echo json_encode([
            'status' => false,
            'message' => $e->getMessage(),
            'supplier_error' => [
                'supplier' => 'viator',
                'message' => $e->getMessage()
            ],
            'raw_response' => $supplierRawResponse,
            'response' => []
        ]);
    }
    
    exit;
});

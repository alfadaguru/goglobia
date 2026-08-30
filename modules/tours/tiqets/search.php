<?php
// ============================================================================
// TIQETS TOUR SEARCH API ENDPOINT - STANDARDIZED RESPONSE FORMAT
// ============================================================================
//
// PURPOSE:
// Search Tiqets tours by location with pagination, filtering, and standardized
// response format matching local tours and Viator integration
//
// ENDPOINT: POST /tours/tiqets/search
//
// ============================================================================

$router->post('/tours/tiqets/search', function() use ($db) {
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
    error_reporting(0);
    ini_set('display_errors', 0);
    ob_start();
    header('Content-Type: application/json');
    $supplierRawResponse = [
        'autocomplete' => null,
        'search' => null
    ];

    try {
        // ========================================
        // REQUEST DATA HANDLING - Support both JSON and POST
        // ========================================
        $input = [];
        $rawInput = file_get_contents('php://input');
        
        if (!empty($rawInput)) {
            $jsonInput = json_decode($rawInput, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($jsonInput)) {
                $input = $jsonInput;
            }
        }
        
        // Merge with $_POST (POST takes precedence)
        $input = array_merge($input, $_POST);

        // Required parameters
        $location = $input['destination'] ?? ($input['location'] ?? '');
        $startDate = $input['departure_date'] ?? ($input['start_date'] ?? ($input['date'] ?? ''));
        $adults = (int)($input['total_adults'] ?? ($input['adults'] ?? 1));
        $children = (int)($input['total_children'] ?? ($input['children'] ?? ($input['childs'] ?? 0)));
        $currency = $input['currency'] ?? 'USD';
        $language = $input['language'] ?? 'en';

        // Optional filters
        $priceFrom = isset($input['price_from']) ? (float)$input['price_from'] : null;
        $priceTo = isset($input['price_to']) ? (float)$input['price_to'] : null;
        $rating = isset($input['rating']) ? (int)$input['rating'] : null;

        // Pagination
        $page = max(1, (int)($input['page'] ?? ($input['pagination'] ?? 1)));
        $per_page = min(100, max(1, (int)($input['per_page'] ?? 25)));

        // ========================================
        // INPUT VALIDATION
        // ========================================
        if (empty($location)) {
            echo json_encode([
                'success' => false,
                'message' => 'Missing required parameter: location/destination'
            ]);
            exit;
        }

        if (empty($startDate)) {
            echo json_encode([
                'success' => false,
                'message' => 'Missing required parameter: departure_date/start_date'
            ]);
            exit;
        }

        // ========================================
        // GET MODULE CONFIGURATION
        // ========================================
        $module = $db->get('modules', '*', [
            'name' => 'tiqets',
            'type' => 'tours'
        ]);

        if (!$module || $module['status'] != 1) {
            echo json_encode([
                'success' => false,
                'message' => 'Tiqets module not configured or disabled'
            ]);
            exit;
        }

        $api_key = $module['c1'];
        $environment = $module['env'] ?? 'production';

        if (empty($api_key) || $api_key === 'test') {
            echo json_encode([
                'success' => false,
                'message' => 'Valid Tiqets API Key required'
            ]);
            exit;
        }

        // Session currency takes precedence
        $sessionCurrency = isset($searchSessionData['app_currency']) ? $searchSessionData['app_currency'] : $currency;

        // ========================================
        // DATE PARSING (DD-MM-YYYY to YYYY-MM-DD)
        // ========================================
        $formatted_date = $startDate;
        if (strpos($startDate, '-') !== false) {
            $parts = explode('-', $startDate);
            if (count($parts) === 3 && strlen($parts[0]) <= 2) {
                // DD-MM-YYYY format, convert to YYYY-MM-DD
                $formatted_date = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
            }
        }

        // ========================================
        // STEP 1: GET CITY ID FROM AUTOCOMPLETE
        // ========================================
        $autocomplete_url = 'https://www.tiqets.com/' . $language . '/_autocomplete?q=' . urlencode($location);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt_array($ch, [
            CURLOPT_URL => $autocomplete_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Token ' . $api_key,
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ],
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $autocomplete_response = curl_exec($ch);
        $supplierRawResponse['autocomplete'] = $autocomplete_response;
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);

        if ($curl_error) {
            throw new Exception('Autocomplete API Connection Error: ' . $curl_error);
        }

        if ($http_code !== 200) {
            throw new Exception('Autocomplete API returned HTTP ' . $http_code);
        }

        $autocomplete_data = json_decode($autocomplete_response, true);

        $all_results = $autocomplete_data['results'] ?? [];
        $location_lower = strtolower(trim($location));
        $city_id = null;

        // 1. Exact name match on city-type results
        foreach ($all_results as $r) {
            if (strtolower($r['type'] ?? '') === 'city' && !empty($r['city_id']) && strtolower($r['name'] ?? '') === $location_lower) {
                $city_id = $r['city_id'];
                break;
            }
        }

        // 2. Partial name match on city-type results (city name must start with the search term)
        if (!$city_id) {
            foreach ($all_results as $r) {
                if (strtolower($r['type'] ?? '') !== 'city' || empty($r['city_id'])) continue;
                $n = strtolower($r['name'] ?? '');
                if ($n && strpos($n, $location_lower) === 0) {
                    $city_id = $r['city_id'];
                    break;
                }
            }
        }

        // 3. Any result whose reference contains the searched city (e.g. "Bali, Indonesia")
        if (!$city_id) {
            foreach ($all_results as $r) {
                if (empty($r['city_id'])) continue;
                if (strpos(strtolower($r['reference'] ?? ''), $location_lower) !== false ||
                    strpos(strtolower($r['name'] ?? ''), $location_lower) !== false) {
                    $city_id = $r['city_id'];
                    break;
                }
            }
        }

        // No match — Tiqets has no coverage for this city
        if (!$city_id) {
            echo json_encode(['success' => true, 'status' => 'success', 'results' => [], 'total' => 0, 'page' => $page, 'total_pages' => 0, 'has_more' => false]);
            exit;
        }

        // ========================================
        // STEP 2: SEARCH PRODUCTS BY CITY ID
        // ========================================
        $search_url = 'https://api.tiqets.com/v2/products' .
                     '?lang=' . $language .
                     '&currency=USD'.
                     '&page_size=' . $per_page .
                     '&page=' . $page .
                     '&city_id=' . $city_id;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt_array($ch, [
            CURLOPT_URL => $search_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Token ' . $api_key,
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ],
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $api_response = curl_exec($ch);
        $supplierRawResponse['search'] = $api_response;
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);

        if ($curl_error) {
            throw new Exception('Search API Connection Error: ' . $curl_error);
        }

        if ($http_code !== 200) {
            $error_data = json_decode($api_response, true);
            $error_msg = isset($error_data['message']) ? $error_data['message'] : 'Unknown error';
            throw new Exception('Tiqets API returned HTTP ' . $http_code . ': ' . $error_msg);
        }

        $tiqets_data = json_decode($api_response, true);

        if (!isset($tiqets_data['products']) || empty($tiqets_data['products'])) {
            echo json_encode([
                'success' => true,
                'message' => 'No tours found for this location',
                'tours' => [],
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $per_page,
                    'total_results' => 0,
                    'total_pages' => 0,
                    'has_more' => false
                ]
            ]);
            exit;
        }

        // ========================================
        // EXTRACT PAGINATION INFO
        // ========================================
        $total_results = $tiqets_data['pagination']['total'] ?? 0;
        $total_pages = ceil($total_results / $per_page);
        $has_more = $page < $total_pages;

        // ========================================
        // PROCESS PRODUCTS
        // ========================================
        $tours = [];

        foreach ($tiqets_data['products'] as $product) {
            // Skip if missing essential data
            if (empty($product['title'])) {
                continue;
            }

            // ========================================
            // EXTRACT BASE PRICE
            // ========================================
            $base_price = (float)($product['price'] ?? 0);
            $productCurrency = 'USD';

            // Apply rating filter
            if ($rating !== null) {
                $product_rating = round($product['ratings']['average'] ?? 0);
                if ($product_rating !== $rating) {
                    continue;
                }
            }

            // ========================================
            // APPLY MARKUP AND CURRENCY CONVERSION
            // ========================================
            // Tiqets already returns price in requested currency
            // Just apply markup for display
            $marked_up_price = MARKUP($base_price, $module, $db, $productCurrency, $sessionCurrency);
            $converted_price = CURRENCY_CONVERT($base_price, $db, $productCurrency, $sessionCurrency);

            $display_price = $marked_up_price['price'];
            $actual_price = $converted_price['price'];

            // Apply price filter (on display price)
            if ($priceFrom !== null && $display_price < $priceFrom) {
                continue;
            }
            if ($priceTo !== null && $display_price > $priceTo) {
                continue;
            }

            // ========================================
            // EXTRACT IMAGES - HIGHEST QUALITY
            // ========================================
            $tourImage = '';
            $tourImages = [];

            if (isset($product['images']) && is_array($product['images'])) {
                foreach ($product['images'] as $image) {
                    // Tiqets provides: small, medium, large, xlarge
                    $imageUrl = $image['xlarge'] ?? ($image['large'] ?? ($image['medium'] ?? ''));
                    if (!empty($imageUrl)) {
                        $tourImages[] = $imageUrl;
                        if (empty($tourImage)) {
                            $tourImage = $imageUrl;
                        }
                    }
                }
            }

            // ========================================
            // EXTRACT RATING
            // ========================================
            $rating_value = round($product['ratings']['average'] ?? 0, 1);
            $rating_count = (int)($product['ratings']['total'] ?? 0);
            $stars = min(5, max(1, (int)ceil($rating_value)));

            // ========================================
            // EXTRACT DURATION
            // ========================================
            $duration = $product['duration'] ?? '';
            $minutes = 0;

            // Parse duration string (e.g., "2 hours", "1.5 hours")
            if (!empty($duration)) {
                if (preg_match('/(\d+\.?\d*)\s*(hour|hr)/i', $duration, $matches)) {
                    $minutes = (int)($matches[1] * 60);
                } elseif (preg_match('/(\d+)\s*min/i', $duration, $matches)) {
                    $minutes = (int)$matches[1];
                }
            }

            $hours = floor($minutes / 60);
            $days = $hours > 0 ? ceil($hours / 8) : 1;

            // ========================================
            // EXTRACT LOCATION
            // ========================================
            $tour_location = $product['city_name'] ?? $location;
            $latitude = (float)($product['geolocation']['lat'] ?? 0);
            $longitude = (float)($product['geolocation']['lng'] ?? 0);

            // ========================================
            // BUILD TOUR OBJECT
            // ========================================
            $tours[] = [
                // Basic information
                'id' => (string)$product['id'],
                'name' => $product['title'] ?? 'Untitled Tour',
                'slug' => strtolower(str_replace(' ', '-', preg_replace('/[^a-zA-Z0-9\s]/', '', $product['title'] ?? ''))),
                'description' => $product['summary'] ?? '',
                'short_description' => substr(strip_tags($product['summary'] ?? ''), 0, 150) . '...',

                // Location
                'location' => $tour_location,
                'latitude' => $latitude,
                'longitude' => $longitude,

                // Duration
                'duration' => $duration,
                'days' => $days,

                // Type
                'tour_type_id' => 0,
                'tour_type' => 'Tour',

                // Ratings
                'stars' => $stars,
                'rating_average' => $rating_value,
                'rating_count' => $rating_count,

                // Images
                'image' => $tourImage,
                'images' => $tourImages,

                // Pricing
                'currency' => $sessionCurrency,
                'display_price' => round($display_price, 2),
                'actual_price' => round($actual_price, 2),
                'base_price' => round($base_price, 2),
                'original_currency' => $productCurrency,

                // Supplier
                'supplier' => 'tiqets',
                'supplier_id' => $module['id'],

                // Checkout URL
                'redirect_url' => $product['product_checkout_url'] ?? '',

                // Search params
                'search_date' => $formatted_date,
                'adults' => $adults,
                'children' => $children
            ];
        }

        // ========================================
        // BUILD RESPONSE
        // ========================================
        $response = [
            'success' => true,
            'message' => count($tours) . ' tours found',
            'tours' => $tours,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $per_page,
                'total_results' => $total_results,
                'total_pages' => $total_pages,
                'has_more' => $has_more
            ],
            'filters_applied' => [
                'location' => $location,
                'city_id' => $city_id,
                'date' => $formatted_date,
                'adults' => $adults,
                'children' => $children,
                'currency' => $sessionCurrency,
                'price_from' => $priceFrom,
                'price_to' => $priceTo,
                'rating' => $rating
            ]
        ];

        // Add pagination headers
        header('X-Current-Page: ' . $page);
        header('X-Per-Page: ' . $per_page);
        header('X-Total-Results: ' . $total_results);
        header('X-Total-Pages: ' . $total_pages);
        header('X-Has-More: ' . ($has_more ? 'true' : 'false'));

        ob_clean();
        echo json_encode($response);
        ob_end_flush();

    } catch (Exception $e) {
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $e->getMessage(),
            'supplier_error' => [
                'supplier' => 'tiqets',
                'message' => $e->getMessage()
            ],
            'raw_response' => $supplierRawResponse,
            'tours' => [],
            'pagination' => [
                'current_page' => $page ?? 1,
                'per_page' => $per_page ?? 25,
                'total_results' => 0,
                'total_pages' => 0,
                'has_more' => false
            ]
        ]);
        ob_end_flush();
    }
});

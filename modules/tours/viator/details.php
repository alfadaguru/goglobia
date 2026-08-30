<?php
// ============================================================================
// VIATOR TOUR DETAILS API ENDPOINT - STANDARDIZED RESPONSE FORMAT
// ============================================================================
//
// PURPOSE:
// Fetch complete details of a single Viator tour including pricing, inclusions,
// exclusions, itinerary, and supplier information matching local tours format
//
// ENDPOINT: POST /tours/viator/details
//
// ============================================================================

$router->post('/tours/viator/details', function () use ($db) {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('serialize_precision', -1);
    ob_start();
    header('Content-Type: application/json');

    try {
        // ========================================
        // REQUEST DATA HANDLING
        // ========================================
        $input = json_decode(file_get_contents('php://input'), true);

        // Required parameters
        $tourId = $input['tour_id'] ?? '';
        $startDate = $input['departure_date'] ?? ($input['start_date'] ?? '');
        $supplier = $input['supplier'] ?? 'viator';

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
            $adults = (int) ($input['total_adults'] ?? ($input['adults'] ?? 1));
            $children = (int) ($input['total_children'] ?? ($input['children'] ?? 0));
        }

        $currency = $input['currency'] ?? 'USD';

        // ========================================
        // INPUT VALIDATION
        // ========================================
        if (empty($tourId)) {
            echo json_encode([
                'success' => false,
                'message' => 'Missing required parameter: tour_id',
                'received' => [
                    'tour_id' => $tourId,
                    'departure_date' => $startDate,
                    'adults' => $adults,
                    'children' => $children
                ]
            ]);
            exit;
        }

        // ========================================
        // GET MODULE CONFIGURATION
        // ========================================
        $module = $db->get('modules', '*', [
            'name' => 'viator',
            'type' => 'tours'
        ]);

        if (!$module || $module['status'] != 1) {
            echo json_encode([
                'success' => false,
                'message' => 'Viator module not configured or disabled'
            ]);
            exit;
        }

        $api_key = $module['c1'];
        $environment = $module['env'] ?? 'production';

        if (empty($api_key) || $api_key === 'test') {
            echo json_encode([
                'success' => false,
                'message' => 'Valid Viator API Key required'
            ]);
            exit;
        }

        // Session currency takes precedence
        $sessionCurrency = isset($_SESSION['app_currency']) ? $_SESSION['app_currency'] : $currency;

        // ========================================
        // DETERMINE API ENDPOINT
        // ========================================
        if ($environment === 'dev' || $environment === 'test') {
            $base_url = 'https://api.sandbox.viator.com';
        } else {
            $base_url = 'https://api.viator.com';
        }

        $details_url = $base_url . '/partner/products/' . urlencode($tourId);

        // ========================================
        // MAKE API REQUEST
        // ========================================
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $details_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'exp-api-key: ' . $api_key,
                'Accept: application/json;version=2.0',
                'Accept-Language: en'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $api_response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);

        if ($curl_error) {
            throw new Exception('API Connection Error: ' . $curl_error);
        }

        if ($http_code !== 200) {
            $error_data = json_decode($api_response, true);
            $error_msg = isset($error_data['message']) ? $error_data['message'] : 'Unknown error';
            throw new Exception('Viator API returned HTTP ' . $http_code . ': ' . $error_msg);
        }

        $viator_data = json_decode($api_response, true);

        if (!isset($viator_data['productCode'])) {
            throw new Exception('Invalid API response: Product not found');
        }


        // ========================================
        // DATE PARSING (DD-MM-YYYY to YYYY-MM-DD)
        // ========================================
        $formatted_date = $startDate;
        if (!empty($startDate) && strpos($startDate, '-') !== false) {
            $parts = explode('-', $startDate);
            if (count($parts) === 3 && strlen($parts[0]) <= 2) {
                // DD-MM-YYYY format, convert to YYYY-MM-DD
                $formatted_date = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
            }
        }

        // ========================================
        // EXTRACT BASE PRICE
        // ========================================
        // 1. Try primary details response keys
        $base_price = (float)($viator_data['pricing']['summary']['fromPrice'] ?? 
                             ($viator_data['pricing']['summary']['amount'] ?? 0));
        $productCurrency = $viator_data['pricing']['currency'] ?? 'USD';

        // 2. Fallback: Search API (If price is still 0 in details)
        if ($base_price <= 0) {
            $fallback_url = $base_url . '/partner/search/freetext';
            $fallback_payload = [
                'searchTerm' => $tourId,
                'productFiltering' => ['dateRange' => ['from' => $formatted_date]],
                'searchTypes' => [['searchType' => 'PRODUCTS', 'pagination' => ['start' => 1, 'count' => 1]]],
                'currency' => 'USD'
            ];

            $fch = curl_init();
            curl_setopt_array($fch, [
                CURLOPT_URL => $fallback_url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($fallback_payload),
                CURLOPT_HTTPHEADER => [
                    'exp-api-key: ' . $api_key,
                    'Accept: application/json;version=2.0',
                    'Accept-Language: en',
                    'Content-Type: application/json'
                ],
                CURLOPT_TIMEOUT => 10
            ]);

            $fallback_response = curl_exec($fch);
            curl_close($fch);

            if ($fallback_response) {
                $fallback_data = json_decode($fallback_response, true);
                if (isset($fallback_data['products']['results'][0])) {
                    $res = $fallback_data['products']['results'][0];
                    $base_price = (float)($res['pricing']['summary']['fromPrice'] ?? ($res['pricing']['summary']['amount'] ?? 0));
                    $productCurrency = $res['pricing']['currency'] ?? $productCurrency;
                }
            }
        }

        // Calculate base price per person
        $base_adult_price = (float)$base_price;
        $base_child_price = (float)($base_price * 0.8);

        // ========================================
        // APPLY MARKUP AND CURRENCY CONVERSION
        // ========================================
        // 1. Convert currency WITHOUT markup (actual price)
        $converted_adult_price = CURRENCY_CONVERT($base_adult_price, $db, $productCurrency, $sessionCurrency);
        $converted_child_price = CURRENCY_CONVERT($base_child_price, $db, $productCurrency, $sessionCurrency);

        // 2. Apply markup WITH currency conversion (display price)
        $marked_up_adult_price = MARKUP($base_adult_price, $module, $db, $productCurrency, $sessionCurrency);
        $marked_up_child_price = MARKUP($base_child_price, $module, $db, $productCurrency, $sessionCurrency);

        // 3. Calculate totals
        $display_price = ($marked_up_adult_price['price'] * $adults) +
            ($marked_up_child_price['price'] * $children);

        $actual_price = ($converted_adult_price['price'] * $adults) +
            ($converted_child_price['price'] * $children);

        // Per person prices
        $display_price_per_adult = $marked_up_adult_price['price'];
        $display_price_per_child = $marked_up_child_price['price'];
        $actual_price_per_adult = $converted_adult_price['price'];
        $actual_price_per_child = $converted_child_price['price'];

        // ========================================
        // EXTRACT DURATION
        // ========================================
        $minutes = 0;
        if (isset($viator_data['duration']['fixedDurationInMinutes'])) {
            $minutes += (int) $viator_data['duration']['fixedDurationInMinutes'];
        }
        if (isset($viator_data['duration']['variableDurationToMinutes'])) {
            $minutes += (int) $viator_data['duration']['variableDurationToMinutes'];
        }

        $hours = floor($minutes / 60);
        $days = $hours > 0 ? ceil($hours / 8) : 1;
        $nights = max(0, $days - 1);

        // ========================================
        // EXTRACT IMAGES - HIGHEST QUALITY
        // ========================================
        $tourImage = '';
        $tourImages = [];

        if (isset($viator_data['images']) && is_array($viator_data['images'])) {
            foreach ($viator_data['images'] as $image) {
                if (isset($image['variants']) && is_array($image['variants']) && count($image['variants']) > 0) {
                    // Sort variants by width to get largest
                    $sortedVariants = $image['variants'];
                    usort($sortedVariants, function ($a, $b) {
                        $widthA = isset($a['width']) ? (int) $a['width'] : 0;
                        $widthB = isset($b['width']) ? (int) $b['width'] : 0;
                        return $widthB - $widthA;
                    });

                    if (isset($sortedVariants[0]['url'])) {
                        $largestUrl = $sortedVariants[0]['url'];
                        $tourImages[] = $largestUrl;
                        if (empty($tourImage)) {
                            $tourImage = $largestUrl;
                        }
                    }
                }
            }
        }

        // ========================================
        // EXTRACT RATING
        // ========================================
        $rating = 0;
        $rating_count = 0;
        if (isset($viator_data['reviews']['combinedAverageRating'])) {
            $rating = round((float) $viator_data['reviews']['combinedAverageRating'], 1);
        }
        if (isset($viator_data['reviews']['totalReviews'])) {
            $rating_count = (int) $viator_data['reviews']['totalReviews'];
        }
        $stars = min(5, max(1, (int) ceil($rating)));

        // ========================================
        // EXTRACT INCLUSIONS
        // ========================================
        $inclusions = [];
        if (isset($viator_data['inclusions']) && is_array($viator_data['inclusions'])) {
            foreach ($viator_data['inclusions'] as $inclusion) {
                if (isset($inclusion['otherDescription'])) {
                    $inclusions[] = [
                        'id' => 0,
                        'name' => $inclusion['otherDescription'],
                        'icon' => 'check_circle'
                    ];
                }
            }
        }

        // ========================================
        // EXTRACT EXCLUSIONS
        // ========================================
        $exclusions = [];
        if (isset($viator_data['exclusions']) && is_array($viator_data['exclusions'])) {
            foreach ($viator_data['exclusions'] as $exclusion) {
                if (isset($exclusion['otherDescription'])) {
                    $exclusions[] = [
                        'id' => 0,
                        'name' => $exclusion['otherDescription'],
                        'icon' => 'cancel'
                    ];
                }
            }
        }

        // ========================================
        // EXTRACT ITINERARY
        // ========================================
        $itinerary = [];
        if (isset($viator_data['itinerary']['itineraryItems']) && is_array($viator_data['itinerary']['itineraryItems'])) {
            $dayNumber = 1;
            foreach ($viator_data['itinerary']['itineraryItems'] as $item) {
                $description = $item['description'] ?? '';
                // Truncate title to 47 characters (like "Al Fahidi fort where the Dubai Museum is housed")
                $title = strlen($description) > 47 ? substr(strip_tags($description), 0, 47) . '...' : strip_tags($description);
                if (empty($title)) {
                    $title = 'Day ' . $dayNumber;
                }

                $itinerary[] = [
                    'day' => $dayNumber++,
                    'title' => $title,
                    'description' => $description,
                    'duration' => $item['duration']['fixedDurationInMinutes'] ?? 0,
                    'image' => '',
                    'activities' => []
                ];
            }
        }

        // ========================================
        // EXTRACT LOCATION
        // ========================================
        $location = 'Unknown';
        $address = '';
        $latitude = null;
        $longitude = null;

        if (isset($viator_data['destinations']) && is_array($viator_data['destinations']) && !empty($viator_data['destinations'])) {
            $location = $viator_data['destinations'][0]['destinationName'] ?? 'Unknown';

            // Check if coordinates exist in destination
            if (isset($viator_data['destinations'][0]['latitude']) && isset($viator_data['destinations'][0]['longitude'])) {
                $latitude = (float) $viator_data['destinations'][0]['latitude'];
                $longitude = (float) $viator_data['destinations'][0]['longitude'];
            }
        }
        
        // Fallback: Extract location from productUrl (e.g., https://www.viator.com/tours/Jeddah/...)
        if ($location === 'Unknown' || empty($location)) {
            if (!empty($viator_data['productUrl']) && preg_match('#/tours/([^/]+)/#i', $viator_data['productUrl'], $matches)) {
                $location = ucwords(str_replace('-', ' ', urldecode($matches[1])));
            }
        }

        // Try 1: Extract coordinates from itinerary locations (pointOfInterestLocation)
        if ($latitude === null && isset($viator_data['itinerary']['itineraryItems']) && is_array($viator_data['itinerary']['itineraryItems'])) {
            foreach ($viator_data['itinerary']['itineraryItems'] as $item) {
                // Check pointOfInterestLocation -> location
                if (isset($item['pointOfInterestLocation']['location']['latitude']) && isset($item['pointOfInterestLocation']['location']['longitude'])) {
                    $latitude = (float) $item['pointOfInterestLocation']['location']['latitude'];
                    $longitude = (float) $item['pointOfInterestLocation']['location']['longitude'];
                    if (!empty($item['pointOfInterestLocation']['location']['address'])) {
                        $address = $item['pointOfInterestLocation']['location']['address'];
                    }
                    break;
                }
            }
        }

        // Try 2: Extract from logistics travelerPickup
        if ($latitude === null && isset($viator_data['logistics']['travelerPickup']['locations']) && is_array($viator_data['logistics']['travelerPickup']['locations'])) {
            foreach ($viator_data['logistics']['travelerPickup']['locations'] as $pickupLocation) {
                if (isset($pickupLocation['location']['latitude']) && isset($pickupLocation['location']['longitude'])) {
                    $latitude = (float) $pickupLocation['location']['latitude'];
                    $longitude = (float) $pickupLocation['location']['longitude'];
                    if (!empty($pickupLocation['location']['address'])) {
                        $address = $pickupLocation['location']['address'];
                    }
                    break;
                }
            }
        }

        // Try 3: Extract from logistics start location
        if ($latitude === null && isset($viator_data['logistics']['start'][0]['location']['latitude'])) {
            $latitude = (float) $viator_data['logistics']['start'][0]['location']['latitude'];
            $longitude = (float) $viator_data['logistics']['start'][0]['location']['longitude'];
            if (!empty($viator_data['logistics']['start'][0]['location']['address'])) {
                $address = $viator_data['logistics']['start'][0]['location']['address'];
            }
        }

        // Fallback: Use default coordinates for known destinations
        if ($latitude === null && $location !== 'Unknown') {
            $defaultCoordinates = [
                'Dubai' => ['lat' => 25.2048, 'lng' => 55.2708],
                'Abu Dhabi' => ['lat' => 24.4539, 'lng' => 54.3773],
                'Paris' => ['lat' => 48.8566, 'lng' => 2.3522],
                'London' => ['lat' => 51.5074, 'lng' => -0.1278],
                'New York' => ['lat' => 40.7128, 'lng' => -74.0060],
                'Tokyo' => ['lat' => 35.6762, 'lng' => 139.6503],
                'Singapore' => ['lat' => 1.3521, 'lng' => 103.8198],
                'Bangkok' => ['lat' => 13.7563, 'lng' => 100.5018],
                'Rome' => ['lat' => 41.9028, 'lng' => 12.4964],
                'Barcelona' => ['lat' => 41.3851, 'lng' => 2.1734],
                'Amsterdam' => ['lat' => 52.3676, 'lng' => 4.9041],
                'Istanbul' => ['lat' => 41.0082, 'lng' => 28.9784],
                'Sydney' => ['lat' => -33.8688, 'lng' => 151.2093],
                'Hong Kong' => ['lat' => 22.3193, 'lng' => 114.1694],
                'Los Angeles' => ['lat' => 34.0522, 'lng' => -118.2437],
                'Las Vegas' => ['lat' => 36.1699, 'lng' => -115.1398],
                'Jeddah' => ['lat' => 21.4858, 'lng' => 39.1925],
                'Riyadh' => ['lat' => 24.7136, 'lng' => 46.6753],
                'Mecca' => ['lat' => 21.3891, 'lng' => 39.8579],
                'Medina' => ['lat' => 24.5247, 'lng' => 39.5692],
            ];

            foreach ($defaultCoordinates as $city => $coords) {
                if (stripos($location, $city) !== false) {
                    $latitude = $coords['lat'];
                    $longitude = $coords['lng'];
                    break;
                }
            }
        }

        // ========================================
        // PRICE BREAKDOWN
        // ========================================
        $price_breakdown = [
            'adults' => [
                'count' => $adults,
                'base_price_per_person' => round($base_adult_price, 2),
                'converted_price_per_person' => round($actual_price_per_adult, 2),
                'marked_up_price_per_person' => round($display_price_per_adult, 2),
                'subtotal_base' => round($base_adult_price * $adults, 2),
                'subtotal_converted' => round($actual_price_per_adult * $adults, 2),
                'subtotal_marked_up' => round($display_price_per_adult * $adults, 2),
                'markup_details' => $marked_up_adult_price,
                'currency_details' => $converted_adult_price
            ],
            'children' => [
                'count' => $children,
                'base_price_per_person' => round($base_child_price, 2),
                'converted_price_per_person' => round($actual_price_per_child, 2),
                'marked_up_price_per_person' => round($display_price_per_child, 2),
                'subtotal_base' => round($base_child_price * $children, 2),
                'subtotal_converted' => round($actual_price_per_child * $children, 2),
                'subtotal_marked_up' => round($display_price_per_child * $children, 2),
                'markup_details' => $marked_up_child_price,
                'currency_details' => $converted_child_price
            ],
            'summary' => [
                'total_base_price' => round(($base_adult_price * $adults) + ($base_child_price * $children), 2),
                'total_converted_price' => round($actual_price, 2),
                'total_marked_up_price' => round($display_price, 2),
                'currency_conversion' => [
                    'from_currency' => $productCurrency,
                    'to_currency' => $sessionCurrency,
                    'converted' => $converted_adult_price['converted']
                ]
            ]
        ];

        // ========================================
        // BUILD RESPONSE (MATCHING LOCAL TOURS FORMAT)
        // ========================================
        $response = [
            'success' => true,
            'data' => [
                // Basic information
                'id' => $tourId,
                'name' => $viator_data['title'] ?? 'Untitled Tour',
                'slug' => strtolower(str_replace(' ', '-', preg_replace('/[^a-zA-Z0-9\s]/', '', $viator_data['title'] ?? ''))),
                'description' => $viator_data['description'] ?? 'No description available.',
                'short_description' => substr(strip_tags($viator_data['description'] ?? ''), 0, 150) . '...',

                // Location details
                'location' => $location,
                'address' => $address,
                'latitude' => $latitude,
                'longitude' => $longitude,

                // Duration
                'days' => $days,
                'nights' => $nights,

                // Tour type
                'tour_type_id' => 0,
                'tour_type' => 'Tour',

                // Ratings
                'stars' => $stars,
                'rating_average' => $rating,
                'rating_count' => $rating_count,

                // Images
                'image' => $tourImage,
                'images' => $tourImages,

                // PRICING INFORMATION
                'currency' => $sessionCurrency,
                'original_currency' => $productCurrency,
                'display_price' => $display_price,
                'display_price_per_adult' => $display_price_per_adult,
                'display_price_per_child' => $display_price_per_child,
                'actual_price' => $actual_price,
                'actual_price_per_adult' => $actual_price_per_adult,
                'actual_price_per_child' => $actual_price_per_child,
                'price_breakdown' => $price_breakdown,

                // Base prices
                'base_adult_price' => $base_adult_price,
                'base_child_price' => $base_child_price,
                'base_infant_price' => 0,
                'original_base_price' => ($base_adult_price * $adults) + ($base_child_price * $children),
                'original_db_currency' => $productCurrency,

                // Capacity
                'max_adults' => 50,
                'max_children' => 20,
                'current_adults' => $adults,
                'current_children' => $children,

                // Features and services
                'inclusions' => $inclusions,
                'exclusions' => $exclusions,
                'amenities' => [],
                'itinerary' => $itinerary,
                'tags' => $viator_data['tags'] ?? [],

                // Policies
                'cancellation_policy' => $viator_data['cancellationPolicy']['description'] ?? '',
                'terms_conditions' => '',
                'refundable' => isset($viator_data['cancellationPolicy']['type']) &&
                    $viator_data['cancellationPolicy']['type'] !== 'NON_REFUNDABLE',

                // Availability
                'has_available_slots' => true,
                'availability_message' => '',

                // Supplier information
                'supplier_id' => $module['id'],
                'supplier' => 'viator',
                'email' => '',
                'phone' => '',
                'website' => $viator_data['productUrl'] ?? '',

                // Commission and tax
                'commission_fixed' => 0.0,
                'commission_percentage' => 0.0,
                'tax_fixed' => 0.0,
                'tax_percentage' => 0.0,

                // Meta information
                'meta_title' => $viator_data['title'] ?? '',
                'meta_description' => substr(strip_tags($viator_data['description'] ?? ''), 0, 160),
                'meta_keywords' => '',

                // Timestamps
                'created_at' => '',
                'updated_at' => '',

                // Search parameters
                'search_params' => [
                    'start_date' => $startDate,
                    'adults' => $adults,
                    'children' => $children,
                ],

                'redirect_url' => $viator_data['productUrl'] ?? '',

                // Viator specific
                'viator_product_code' => $viator_data['productCode'],
                'viator_product_url' => $viator_data['productUrl'] ?? ''
            ]
        ];

        ob_clean();
        echo json_encode($response);
        ob_end_flush();

    } catch (Exception $e) {
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $e->getMessage()
        ]);
        ob_end_flush();
    }
});

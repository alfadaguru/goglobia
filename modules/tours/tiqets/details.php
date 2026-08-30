<?php
// ============================================================================
// TIQETS TOUR DETAILS API ENDPOINT - STANDARDIZED RESPONSE FORMAT
// ============================================================================
//
// PURPOSE:
// Fetch complete details of a single Tiqets tour including pricing, inclusions,
// exclusions, itinerary, and supplier information matching local tours format
//
// ENDPOINT: POST /tours/tiqets/details
//
// ============================================================================

$router->post('/tours/tiqets/details', function() use ($db) {
    error_reporting(0);
    ini_set('display_errors', 0);
    ob_start();
    header('Content-Type: application/json');

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
        $tourId = $input['tour_id'] ?? '';
        $startDate = $input['departure_date'] ?? ($input['start_date'] ?? ($input['date'] ?? ''));
        $supplier = $input['supplier'] ?? 'tiqets';

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
            $children = (int)($input['total_children'] ?? ($input['children'] ?? ($input['childs'] ?? 0)));
        }

        $currency = $input['currency'] ?? 'USD';
        $language = $input['language'] ?? 'en';

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
        $sessionCurrency = isset($_SESSION['app_currency']) ? $_SESSION['app_currency'] : $currency;
        
        // System default currency for base prices
        $systemDefaultCurrency = $db->get('currencies', 'name', ['default' => '1']) ?? 'USD';

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
        // MAKE API REQUEST
        // ========================================
        $details_url = 'https://api.tiqets.com/v2/products/' . urlencode($tourId) . '?currency=USD&lang=' . $language;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $details_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Token ' . $api_key,
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
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
            throw new Exception('Tiqets API returned HTTP ' . $http_code . ': ' . $error_msg);
        }

        $tiqets_data = json_decode($api_response, true);

        if (!isset($tiqets_data['product']['id'])) {
            throw new Exception('Invalid API response: Product not found');
        }

        $product = $tiqets_data['product'];

        // ========================================
        // EXTRACT BASE PRICE
        // ========================================
        $base_price = (float)($product['price'] ?? ($product['display_price'] ?? 0));
        $productCurrency = 'USD';

        // Calculate base price per person (Tiqets doesn't separate adult/child by default)
        $base_price_per_person = $base_price;
        $base_adult_price = $base_price_per_person;
        $base_child_price = $base_price_per_person * 0.8; // Assume 80% for children

        // ========================================
        // APPLY MARKUP AND CURRENCY CONVERSION
        // ========================================
        // 1. Convert currency WITHOUT markup (actual price)
        $converted_adult_price = CURRENCY_CONVERT($base_adult_price, $db, $productCurrency, $sessionCurrency);
        $converted_child_price = CURRENCY_CONVERT($base_child_price, $db, $productCurrency, $sessionCurrency);

        // 2. Apply markup WITH currency conversion (display price)
        $marked_up_adult_price = MARKUP($base_adult_price, $module, $db, $productCurrency, $sessionCurrency);
        $marked_up_adult_price_base = MARKUP($base_adult_price, $module, $db, $productCurrency, $systemDefaultCurrency); // System Default currency markup
        
        $marked_up_child_price = MARKUP($base_child_price, $module, $db, $productCurrency, $sessionCurrency);
        $marked_up_child_price_base = MARKUP($base_child_price, $module, $db, $productCurrency, $systemDefaultCurrency); // System Default currency markup

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
        $duration = $product['duration'] ?? '';
        $minutes = 0;

        // Parse duration string (e.g., "2 hours", "1.5 hours")
        if (!empty($duration)) {
            if (preg_match('/(\d+\.?\d*)\s*(hour|hr)/i', $duration, $matches)) {
                $minutes = (int)($matches[1] * 60);
            } elseif (preg_match('/(\d+)\s*min/i', $duration, $matches)) {
                $minutes = (int)$matches[1];
            } elseif (preg_match('/(\d+)\s*day/i', $duration, $matches)) {
                $minutes = (int)($matches[1] * 8 * 60); // Assume 8 hours per day
            }
        }

        $hours = floor($minutes / 60);
        $days = $hours > 0 ? ceil($hours / 8) : 1;
        $nights = max(0, $days - 1);

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
        $rating = 0;
        $rating_count = 0;
        if (isset($product['ratings']['average'])) {
            $rating = round((float)$product['ratings']['average'], 1);
        }
        if (isset($product['ratings']['total'])) {
            $rating_count = (int)$product['ratings']['total'];
        }
        $stars = min(5, max(1, (int)ceil($rating)));

        // ========================================
        // EXTRACT INCLUSIONS
        // ========================================
        $inclusions = [];
        $whatsIncluded = $product['whats_included'] ?? $product['included'] ?? '';
        
        if (!empty($whatsIncluded)) {
            // Check if it's HTML content
            if (strpos($whatsIncluded, '<') !== false) {
                // Parse HTML list items
                $dom = new DOMDocument();
                @$dom->loadHTML('<?xml encoding="UTF-8">' . $whatsIncluded);
                $items = $dom->getElementsByTagName('li');
                if ($items->length > 0) {
                    foreach ($items as $item) {
                        $text = trim($item->textContent);
                        if (!empty($text)) {
                            $inclusions[] = [
                                'id' => 0,
                                'name' => $text,
                                'icon' => 'check_circle'
                            ];
                        }
                    }
                } else {
                    // No list items, use whole content
                    $text = trim(strip_tags($whatsIncluded));
                    if (!empty($text)) {
                        $inclusions[] = [
                            'id' => 0,
                            'name' => $text,
                            'icon' => 'check_circle'
                        ];
                    }
                }
            } else {
                // Plain text - split by newline or bullet points
                $included_items = preg_split('/[\r\n]+|•|\*|-\s/', $whatsIncluded);
                foreach ($included_items as $item) {
                    $item = trim($item);
                    if (!empty($item) && strlen($item) > 2) {
                        $inclusions[] = [
                            'id' => 0,
                            'name' => $item,
                            'icon' => 'check_circle'
                        ];
                    }
                }
            }
        }
        
        // If still empty, add default inclusion
        if (empty($inclusions)) {
            $inclusions[] = [
                'id' => 0,
                'name' => 'Admission ticket',
                'icon' => 'check_circle'
            ];
        }

        // ========================================
        // EXTRACT EXCLUSIONS
        // ========================================
        $exclusions = [];
        $whatsExcluded = $product['whats_excluded'] ?? $product['excluded'] ?? '';
        
        if (!empty($whatsExcluded)) {
            // Check if it's HTML content
            if (strpos($whatsExcluded, '<') !== false) {
                // Parse HTML list items
                $dom = new DOMDocument();
                @$dom->loadHTML('<?xml encoding="UTF-8">' . $whatsExcluded);
                $items = $dom->getElementsByTagName('li');
                if ($items->length > 0) {
                    foreach ($items as $item) {
                        $text = trim($item->textContent);
                        if (!empty($text)) {
                            $exclusions[] = [
                                'id' => 0,
                                'name' => $text,
                                'icon' => 'cancel'
                            ];
                        }
                    }
                } else {
                    // No list items, use whole content
                    $text = trim(strip_tags($whatsExcluded));
                    if (!empty($text)) {
                        $exclusions[] = [
                            'id' => 0,
                            'name' => $text,
                            'icon' => 'cancel'
                        ];
                    }
                }
            } else {
                // Plain text - split by newline or bullet points
                $excluded_items = preg_split('/[\r\n]+|•|\*|-\s/', $whatsExcluded);
                foreach ($excluded_items as $item) {
                    $item = trim($item);
                    if (!empty($item) && strlen($item) > 2) {
                        $exclusions[] = [
                            'id' => 0,
                            'name' => $item,
                            'icon' => 'cancel'
                        ];
                    }
                }
            }
        }
        
        // If still empty, add common exclusions
        if (empty($exclusions)) {
            $exclusions = [
                ['id' => 0, 'name' => 'Food and drinks', 'icon' => 'cancel'],
                ['id' => 0, 'name' => 'Hotel pickup and drop-off', 'icon' => 'cancel'],
                ['id' => 0, 'name' => 'Gratuities', 'icon' => 'cancel']
            ];
        }

        // ========================================
        // EXTRACT LOCATION (MUST BE BEFORE ITINERARY)
        // ========================================
        $location = $product['city_name'] ?? ($product['venue']['name'] ?? 'Unknown');
        $address = $product['venue']['address'] ?? ($product['venue']['name'] ?? '');
        
        // Extract coordinates from multiple possible sources
        $latitude = null;
        $longitude = null;
        
        // Try 1: geolocation object
        if (isset($product['geolocation']['lat']) && isset($product['geolocation']['lng'])) {
            $latitude = (float)$product['geolocation']['lat'];
            $longitude = (float)$product['geolocation']['lng'];
        }
        
        // Try 2: starting_point object
        if ($latitude === null && isset($product['starting_point']['lat']) && isset($product['starting_point']['lng'])) {
            $latitude = (float)$product['starting_point']['lat'];
            $longitude = (float)$product['starting_point']['lng'];
        }
        
        // Fallback: Use default coordinates for known cities if still missing
        if ($latitude === null && $location !== 'Unknown') {
            $defaultCoordinates = [
                'Amsterdam' => ['lat' => 52.3676, 'lng' => 4.9041],
                'Barcelona' => ['lat' => 41.3851, 'lng' => 2.1734],
                'Paris' => ['lat' => 48.8566, 'lng' => 2.3522],
                'Rome' => ['lat' => 41.9028, 'lng' => 12.4964],
                'London' => ['lat' => 51.5074, 'lng' => -0.1278],
                'Berlin' => ['lat' => 52.5200, 'lng' => 13.4050],
                'Madrid' => ['lat' => 40.4168, 'lng' => -3.7038],
                'Vienna' => ['lat' => 48.2082, 'lng' => 16.3738],
                'Prague' => ['lat' => 50.0755, 'lng' => 14.4378],
                'Venice' => ['lat' => 45.4408, 'lng' => 12.3155],
                'Florence' => ['lat' => 43.7696, 'lng' => 11.2558],
                'Milan' => ['lat' => 45.4642, 'lng' => 9.1900],
                'Lisbon' => ['lat' => 38.7223, 'lng' => -9.1393],
                'Athens' => ['lat' => 37.9838, 'lng' => 23.7275],
                'Dubai' => ['lat' => 25.2048, 'lng' => 55.2708],
                'New York' => ['lat' => 40.7128, 'lng' => -74.0060]
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
        // EXTRACT ITINERARY (from description/highlights/description sections)
        // ========================================
        $itinerary = [];
        $dayNumber = 1;
        
        // Try 1: Highlights array
        if (isset($product['highlights']) && is_array($product['highlights']) && !empty($product['highlights'])) {
            foreach ($product['highlights'] as $highlight) {
                if (!empty($highlight)) {
                    $title = strlen($highlight) > 47 ? substr(strip_tags($highlight), 0, 47) . '...' : strip_tags($highlight);
                    $itinerary[] = [
                        'day' => $dayNumber++,
                        'title' => $title,
                        'description' => $highlight,
                        'duration' => 0,
                        'image' => '',
                        'location' => $location,
                        'activities' => []
                    ];
                }
            }
        }
        
        // Try 2: Parse description for sections
        if (empty($itinerary) && !empty($product['description'])) {
            $description = $product['description'];
            
            // Try to extract sections from HTML
            if (strpos($description, '<') !== false) {
                $dom = new DOMDocument();
                @$dom->loadHTML('<?xml encoding="UTF-8">' . $description);
                $paragraphs = $dom->getElementsByTagName('p');
                
                if ($paragraphs->length > 1) {
                    foreach ($paragraphs as $para) {
                        $text = trim($para->textContent);
                        if (!empty($text) && strlen($text) > 20) {
                            $title = strlen($text) > 47 ? substr($text, 0, 47) . '...' : $text;
                            $itinerary[] = [
                                'day' => $dayNumber++,
                                'title' => $title,
                                'description' => $text,
                                'duration' => 0,
                                'image' => '',
                                'location' => $location,
                                'activities' => []
                            ];
                        }
                    }
                }
            }
        }

        // Try 3: Create single day from summary or description
        if (empty($itinerary)) {
            $descText = !empty($product['summary']) ? $product['summary'] : ($product['description'] ?? 'Experience this amazing tour');
            $itinerary[] = [
                'day' => 1,
                'title' => 'Tour Experience',
                'description' => $descText,
                'duration' => $minutes,
                'image' => $tourImage,
                'location' => $location,
                'activities' => []
            ];
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
                'marked_up_price_per_person_base' => round($marked_up_adult_price_base['price'], 2), // Base currency
                'subtotal_base' => round($base_adult_price * $adults, 2),
                'subtotal_converted' => round($actual_price_per_adult * $adults, 2),
                'subtotal_marked_up' => round($display_price_per_adult * $adults, 2),
                'subtotal_marked_up_base' => round($marked_up_adult_price_base['price'] * $adults, 2), // Base currency
                'markup_details' => $marked_up_adult_price,
                'currency_details' => $converted_adult_price
            ],
            'children' => [
                'count' => $children,
                'base_price_per_person' => round($base_child_price, 2),
                'converted_price_per_person' => round($actual_price_per_child, 2),
                'marked_up_price_per_person' => round($display_price_per_child, 2),
                'marked_up_price_per_person_base' => round($marked_up_child_price_base['price'], 2), // Base currency
                'subtotal_base' => round($base_child_price * $children, 2),
                'subtotal_converted' => round($actual_price_per_child * $children, 2),
                'subtotal_marked_up' => round($display_price_per_child * $children, 2),
                'subtotal_marked_up_base' => round($marked_up_child_price_base['price'] * $children, 2), // Base currency
                'markup_details' => $marked_up_child_price,
                'currency_details' => $converted_child_price
            ],
            'summary' => [
                'total_base_price' => round(($base_adult_price * $adults) + ($base_child_price * $children), 2),
                'total_converted_price' => round($actual_price, 2),
                'total_marked_up_price' => round($display_price, 2),
                'total_marked_up_price_base' => round(($marked_up_adult_price_base['price'] * $adults) + ($marked_up_child_price_base['price'] * $children), 2), // Base currency
                'currency_conversion' => [
                    'from_currency' => $productCurrency,
                    'to_currency' => $sessionCurrency,
                    'converted' => $converted_adult_price['converted']
                ]
            ]
        ];

        // ========================================
        // EXTRACT CANCELLATION POLICY
        // ========================================
        $cancellation_policy = $product['cancellation_policy'] ?? '';
        $refundable = stripos($cancellation_policy, 'non-refundable') === false;

        // ========================================
        // BUILD CHECKOUT URL WITH PARAMETERS
        // ========================================
        $checkout_url = $product['product_checkout_url'] ?? '';
        
        // Add date and travelers to checkout URL if available
        if (!empty($checkout_url) && !empty($formatted_date)) {
            $separator = strpos($checkout_url, '?') !== false ? '&' : '?';
            $checkout_url .= $separator . 'date=' . urlencode($formatted_date);
            if ($adults > 0) {
                $checkout_url .= '&adults=' . $adults;
            }
            if ($children > 0) {
                $checkout_url .= '&children=' . $children;
            }
        }

        // ========================================
        // BUILD RESPONSE (MATCHING LOCAL TOURS FORMAT)
        // ========================================
        $response = [
            'success' => true,
            'data' => [
                // Basic information
                'id' => (string)$product['id'],
                'name' => $product['title'] ?? 'Untitled Tour',
                'slug' => strtolower(str_replace(' ', '-', preg_replace('/[^a-zA-Z0-9\s]/', '', $product['title'] ?? ''))),
                'description' => !empty($product['description']) ? $product['description'] : (!empty($product['summary']) ? $product['summary'] : 'No description available.'),
                'short_description' => !empty($product['summary']) ? (strlen($product['summary']) > 150 ? substr(strip_tags($product['summary']), 0, 150) . '...' : strip_tags($product['summary'])) : (strlen($product['description'] ?? '') > 150 ? substr(strip_tags($product['description']), 0, 150) . '...' : strip_tags($product['description'] ?? 'No description available.')),

                // Location details
                'location' => $location,
                'address' => $address,
                'latitude' => ($latitude != 0) ? (float)$latitude : null,
                'longitude' => ($longitude != 0) ? (float)$longitude : null,

                // Duration
                'days' => $days,
                'nights' => $nights,
                'duration' => $duration,

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
                'tags' => [],

                // Policies
                'cancellation_policy' => $cancellation_policy,
                'terms_conditions' => '',
                'refundable' => $refundable,

                // Availability
                'has_available_slots' => true,
                'availability_message' => '',

                // Supplier information
                'supplier_id' => $module['id'],
                'supplier' => 'tiqets',
                'email' => '',
                'phone' => '',
                'website' => $product['product_url'] ?? '',

                // Commission and tax
                'commission_fixed' => 0.0,
                'commission_percentage' => 0.0,
                'tax_fixed' => 0.0,
                'tax_percentage' => 0.0,

                // Meta information
                'meta_title' => $product['title'] ?? '',
                'meta_description' => substr(strip_tags($product['summary'] ?? $product['description'] ?? ''), 0, 160),
                'meta_keywords' => '',

                // Timestamps
                'created_at' => '',
                'updated_at' => '',

                // Search parameters
                'search_params' => [
                    'start_date' => $formatted_date,
                    'adults' => $adults,
                    'children' => $children,
                ],

                // DIRECT CHECKOUT URL WITH PARAMETERS
                'redirect_url' => $checkout_url,

                // Tiqets specific
                'tiqets_product_id' => $product['id'],
                'tiqets_product_url' => $product['product_url'] ?? ''
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

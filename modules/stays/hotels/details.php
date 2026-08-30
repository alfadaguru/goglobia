<?php

$router->post('/stays/hotels/details', function() use ($db) {

    // Suppress errors for clean JSON output
    error_reporting(0);
    ini_set('display_errors', 0);

    // Start output buffering
    ob_start();

    // Set JSON header
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
        // Parse JSON request body
        $input = json_decode(file_get_contents('php://input'), true);

    $hotelId = $input['hotel_id'] ?? '';
    $supplier = $input['supplier'] ?? '';
    $checkin = $input['checkin'] ?? '';
    $checkout = $input['checkout'] ?? '';
    $nationality = $input['nationality'] ?? '';
    $rooms = $input['rooms'] ?? [];

    // Validate required parameters
    if (empty($hotelId) || empty($supplier) || empty($checkin) || empty($checkout)) {
        $missing = [];
        if (empty($hotelId)) $missing[] = 'hotel_id';
        if (empty($supplier)) $missing[] = 'supplier';
        if (empty($checkin)) $missing[] = 'checkin';
        if (empty($checkout)) $missing[] = 'checkout';

        echo json_encode([
            'success' => false,
            'message' => 'Missing required parameters',
            'missing_parameters' => $missing,
            'received' => [
                'hotel_id' => $hotelId,
                'supplier' => $supplier,
                'checkin' => $checkin,
                'checkout' => $checkout,
                'nationality' => $nationality,
                'rooms_count' => count($rooms)
            ]
        ]);
        exit;
    }

    // Ensure database connection exists
    if (!isset($db)) {
        echo json_encode([
            'success' => false,
            'message' => 'Database connection not available'
        ]);
        exit;
    }

    // Fetch hotel from database
    $hotel = $db->get('stays', '*', [
        'id' => $hotelId,
        'status' => '1'
    ]);

    if (!$hotel) {
        echo json_encode([
            'success' => false,
            'message' => 'Hotel not found'
        ]);
        exit;
    }

    // Parse images
    $images = json_decode($hotel['img'] ?? '[]', true);
    if (!is_array($images)) $images = [];

    $hotelImages = [];
    $defaultImage = '';

    foreach ($images as $img) {
        if (!empty($img['url'])) {
            $fullUrl = dirname(root) . $img['url'];
            $hotelImages[] = $fullUrl;
            
            if (isset($img['default']) && $img['default'] === true && empty($defaultImage)) {
                $defaultImage = $fullUrl;
            }
        }
    }

    if (empty($defaultImage) && !empty($hotelImages)) {
        $defaultImage = $hotelImages[0];
    }

    // Parse amenities - handle both JSON array and bracketed string formats
    $amenityIds = [];
    if (!empty($hotel['amenity_ids'])) {
        $decoded = json_decode($hotel['amenity_ids'], true);
        if (is_array($decoded)) {
            $amenityIds = $decoded;
        } else if (is_string($hotel['amenity_ids'])) {
            // Handle format like "[1,30,2,1]"
            $cleaned = trim($hotel['amenity_ids'], '[]');
            $amenityIds = array_map('intval', array_filter(explode(',', $cleaned)));
        }
    }

    $amenities = [];
    if (!empty($amenityIds)) {
        $amenitiesList = $db->select('stays_settings', ['id', 'name'], [
            'id' => $amenityIds,
            'status' => '1',
            'setting_type' => 'stay_amenity'
        ]);
        foreach ($amenitiesList as $amenity) {
            $amenities[] = $amenity['name'];
        }
    }

    // Parse location coordinates
    $latitude = 0;
    $longitude = 0;
    
    if (!empty($hotel['location_coords'])) {
        $coordsString = $hotel['location_coords'];
        
        // Check if it's already in "latitude,longitude" format
        if (strpos($coordsString, ',') !== false) {
            $coordsArray = explode(',', $coordsString);
            if (count($coordsArray) >= 2) {
                $latitude = trim($coordsArray[0]);
                $longitude = trim($coordsArray[1]);
            }
        }
        // If it's a single number (old format), use it as both lat and long
        else if (is_numeric($coordsString)) {
            $latitude = $coordsString;
            $longitude = $coordsString;
        }
    }

    // Fetch hotels module config for markup
    $module = $db->get('modules', '*', [
        'name' => 'hotels',
        'type' => 'stays'
    ]);

    $hotelCurrency = !empty($hotel['currency']) ? $hotel['currency'] : 'USD';
    $targetCurrency = $_POST['currency'] ?? $input['currency'] ?? $_SESSION['app_currency'] ?? 'USD';

    // Fetch rooms for this hotel
    $hotelRooms = $db->select('stays_rooms', '*', [
        'stay_id' => $hotelId,
        'status' => '1'
    ]);

    $roomsResponse = [];
    foreach ($hotelRooms as $room) {
        // Parse room images
        $roomImages = json_decode($room['img'] ?? '[]', true);
        if (!is_array($roomImages)) $roomImages = [];

        $roomImgUrls = [];
        foreach ($roomImages as $img) {
            if (!empty($img['url'])) {
                $roomImgUrls[] = dirname(root) . $img['url'];
            }
        }

        // Parse room amenities
        $roomAmenityIds = json_decode($room['amenity_ids'] ?? '[]', true);
        if (!is_array($roomAmenityIds)) $roomAmenityIds = [];

        $roomAmenities = [];
        if (!empty($roomAmenityIds)) {
            $roomAmenitiesList = $db->select('stays_settings', ['name'], [
                'id' => $roomAmenityIds,
                'status' => '1',
                'setting_type' => 'room_amenity'
            ]);
            foreach ($roomAmenitiesList as $amenity) {
                $roomAmenities[] = $amenity['name'];
            }
        }

        // Build rates for this room
        $rates = [];

        // Standard Rate
        if (!empty($room['price'])) {
            $basePx = floatval($room['price']);
            $mkPx = MARKUP($basePx, $module, $db, $hotelCurrency, $targetCurrency);
            $rates[] = [
                'id' => $room['id'] . '_standard',
                'name' => 'Standard Rate',
                'price' => floatval($mkPx['price']),
                'currency' => $targetCurrency,
                'features' => array_filter([
                    !empty($room['breakfast']) ? 'Breakfast included' : null,
                    !empty($room['refundable']) ? 'Refundable' : 'Non-refundable'
                ])
            ];
        }

        // Refundable Rate (if different price)
        if (!empty($room['refundable']) && !empty($room['refundable_price'])) {
            $basePxRef = floatval($room['refundable_price']);
            $mkPxRef = MARKUP($basePxRef, $module, $db, $hotelCurrency, $targetCurrency);
            $rates[] = [
                'id' => $room['id'] . '_refundable',
                'name' => 'Fully Refundable',
                'price' => floatval($mkPxRef['price']),
                'currency' => $targetCurrency,
                'features' => array_filter([
                    'Refundable',
                    !empty($room['breakfast']) ? 'Breakfast included' : null
                ])
            ];
        }

        $roomsResponse[] = [
            'id' => $room['id'],
            'name' => $room['name'],
            'description' => $room['description'] ?? '',
            'bed_type' => $room['bed_type'] ?? 'Standard bed',
            'max_guests' => intval($room['max_guests'] ?? 2),
            'images' => $roomImgUrls,
            'amenities' => $roomAmenities,
            'rates' => $rates
        ];
    }

    // Build response
    $response = [
        'success' => true,
        'data' => [
            'id' => $hotel['id'],
            'name' => $hotel['name'],
            'image' => $defaultImage,
            'description' => $hotel['desc'] ?? $hotel['description'] ?? 'No description available.',
            'address' => $hotel['address'] ?? '',
            'location' => $hotel['location'] ?? '',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'cancellation_policy' => $hotel['cancellation_policy'] ?? '',
            'privacy_policy' => $hotel['privacy_policy'] ?? '',
            'stars' => intval($hotel['stars'] ?? 3),
            'rating' => floatval($hotel['rating'] ?? 4.0),
            'images' => $hotelImages,
            'amenities' => $amenities,
            'rooms' => $roomsResponse,
            'supplier' => $supplier,
            'checkin' => $checkin,
            'checkout' => $checkout
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
            'message' => 'Server error: ' . $e->getMessage()
        ]);
        ob_end_flush();
    }

});
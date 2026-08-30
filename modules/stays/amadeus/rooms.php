<?php

/**
 * ============================================================================
 * AMADEUS HOTEL ROOMS API ENDPOINT
 * ============================================================================
 *
 * PURPOSE:
 * Fetch real-time room availability and pricing from Amadeus Hotel API
 * with comprehensive room details, policies, and pricing.
 *
 * ENDPOINT: POST /stays/amadeus/rooms
 *
 * ============================================================================
 */

$router->post('stays/amadeus/rooms', function() use ($db) {

    set_time_limit(45);
    while (ob_get_level()) ob_end_clean();

    header('Content-Type: application/json');

    $logsPath = __DIR__ . '/logs';

    try {
        // Parse JSON request body
        $input = json_decode(file_get_contents('php://input'), true);

        $hotelId = $input['hotel_id'] ?? '';
        $supplier = $input['supplier'] ?? 'amadeus';
        $checkin = $input['checkin'] ?? '';
        $checkout = $input['checkout'] ?? '';
        $nationality = $input['nationality'] ?? 'US';
        $rooms = $input['rooms'] ?? [];

        // Validate required parameters
        if (empty($hotelId)) {
            echo json_encode([
                'success' => false,
                'message' => 'Hotel ID is required'
            ]);
            exit;
        }

        // If dates are missing, return empty rooms array
        if (empty($checkin) || empty($checkout)) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'hotel_id' => $hotelId,
                    'nights' => 1,
                    'currency' => $_SESSION['app_currency'] ?? 'USD',
                    'rooms' => [],
                    'message' => 'Check-in and check-out dates are required for room availability'
                ]
            ]);
            exit;
        }

        // ========================================
        // CALCULATE NUMBER OF NIGHTS
        // ========================================
        $nights = 1;
        
        // Convert DD-MM-YYYY to YYYY-MM-DD
        $checkin_parts = explode('-', $checkin);
        $checkout_parts = explode('-', $checkout);

        // DEBUG: Log the conversion
        @file_put_contents(__DIR__ . '/logs/debug_conversion.txt', 
            "Original: checkin=$checkin, checkout=$checkout\n" .
            "Parts: checkin=" . json_encode($checkin_parts) . ", checkout=" . json_encode($checkout_parts) . "\n",
            FILE_APPEND
        );

        if (count($checkin_parts) === 3 && count($checkout_parts) === 3) {
            $checkinISO = $checkin_parts[2] . '-' . $checkin_parts[1] . '-' . $checkin_parts[0];
            $checkoutISO = $checkout_parts[2] . '-' . $checkout_parts[1] . '-' . $checkout_parts[0];
            
            @file_put_contents(__DIR__ . '/logs/debug_conversion.txt', 
                "Converted: checkinISO=$checkinISO, checkoutISO=$checkoutISO\n\n",
                FILE_APPEND
            );
            
            try {
                $date1 = new DateTime($checkinISO);
                $date2 = new DateTime($checkoutISO);
                $interval = $date1->diff($date2);
                $nights = max(1, (int)$interval->days);
            } catch (Exception $e) {
                $nights = 1;
            }
        } else {
            // Fallback if format is unexpected
            $checkinISO = $checkin;
            $checkoutISO = $checkout;
        }

        // Get currency from session
        $currency = $_SESSION['app_currency'] ?? 'USD';

        // Extract adults from rooms array
        $adults = 2; // Default
        if (!empty($rooms) && is_array($rooms)) {
            $adults = (int)($rooms[0]['adults'] ?? 2);
        }

        // ========================================
        // GET MODULE CONFIGURATION
        // ========================================
        $module = $db->get('modules', '*', [
            'name' => 'amadeus',
            'type' => 'stays'
        ]);

        if (!$module) {
            echo json_encode([
                'success' => false,
                'message' => 'Amadeus module not configured'
            ]);
            exit;
        }

        $apiKey = $module['c1'];
        $apiSecret = $module['c2'];
        $baseUrl = ($module['env'] === 'live') ? 'https://api.amadeus.com' : 'https://test.api.amadeus.com';

        // ========================================
        // STEP 1: GET OAUTH2 TOKEN
        // ========================================
        $ch = curl_init($baseUrl . '/v1/security/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => $apiKey,
                'client_secret' => $apiSecret
            ]),
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
        ]);
        $tokenResp = curl_exec($ch);
        $tokenData = json_decode($tokenResp, true);
        
        if (!isset($tokenData['access_token'])) {
            throw new Exception('Token request failed');
        }
        $token = $tokenData['access_token'];

        // ========================================
        // STEP 2: GET HOTEL OFFERS
        // ========================================
        $ch = curl_init($baseUrl . '/v3/shopping/hotel-offers?' . http_build_query([
            'hotelIds' => $hotelId,
            'checkInDate' => $checkinISO,
            'checkOutDate' => $checkoutISO,
            'adults' => $adults,
            'currency' => $currency
        ]));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]
        ]);
        $offersResp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $offersData = json_decode($offersResp, true);

        // Only log on errors - single file
        if ($httpCode !== 200 || !isset($offersData['data'][0])) {
            @mkdir(__DIR__ . '/logs', 0755, true);
            
            // Log to single rooms.json file
            @file_put_contents(__DIR__ . '/logs/rooms.json', 
                json_encode([
                    'timestamp' => date('Y-m-d H:i:s'),
                    'http_code' => $httpCode,
                    'request' => [
                        'hotelId' => $hotelId,
                        'checkInDate' => $checkinISO,
                        'checkOutDate' => $checkoutISO,
                        'adults' => $adults,
                        'currency' => $currency
                    ],
                    'response' => $offersData
                ], JSON_PRETTY_PRINT)
            );
        }

        if (!isset($offersData['data'][0])) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'hotel_id' => $hotelId,
                    'nights' => $nights,
                    'currency' => $currency,
                    'rooms' => []
                ]
            ]);
            exit;
        }

        // ========================================
        // STEP 3: PARSE AND GROUP ROOMS
        // ========================================
        $offers = $offersData['data'][0]['offers'] ?? [];
        $roomsGrouped = [];

        foreach ($offers as $offer) {
            $room = $offer['room'] ?? [];
            $roomType = $room['type'] ?? 'UNKNOWN';
            $roomCategory = $room['typeEstimated']['category'] ?? 'Standard Room';
            $roomDescription = $room['description']['text'] ?? $roomCategory;

            // Use room type as unique identifier
            if (!isset($roomsGrouped[$roomType])) {
                $roomsGrouped[$roomType] = [
                    'room_code' => $roomType,
                    'room_name' => $roomCategory,
                    'room_description' => $roomDescription,
                    'beds' => $room['typeEstimated']['beds'] ?? 1,
                    'bed_type' => $room['typeEstimated']['bedType'] ?? 'DOUBLE',
                    'offers' => []
                ];
            }

            // Add this offer to the room
            $roomsGrouped[$roomType]['offers'][] = $offer;
        }

        // ========================================
        // STEP 4: BUILD RESPONSE WITH PRICING
        // ========================================
        $roomsResponse = [];

        foreach ($roomsGrouped as $roomCode => $roomData) {
            $roomName = $roomData['room_name'];
            $roomDescription = $roomData['room_description'];
            $beds = (int)$roomData['beds'];
            $bedType = $roomData['bed_type'];

            // Determine max occupancy
            $maxAdults = 2;
            $maxChildren = 2;
            if (stripos($bedType, 'SINGLE') !== false) {
                $maxAdults = 1;
                $maxChildren = 0;
            } elseif (stripos($bedType, 'KING') !== false || stripos($bedType, 'QUEEN') !== false) {
                $maxAdults = 2;
            } elseif ($beds >= 3) {
                $maxAdults = $beds;
                $maxChildren = $beds;
            }

            // Room images (Amadeus doesn't provide room-specific images)
            $roomImages = ['https://via.placeholder.com/800x600?text=' . urlencode($roomName)];

            // Room amenities (extract from room description)
            $amenities = [];
            if (!empty($roomDescription)) {
                $commonAmenities = ['Wi-Fi', 'Air Conditioning', 'TV', 'Minibar', 'Safe', 'Bathroom'];
                foreach ($commonAmenities as $amenity) {
                    if (stripos($roomDescription, $amenity) !== false) {
                        $amenities[] = [
                            'id' => crc32($amenity),
                            'name' => $amenity
                        ];
                    }
                }
            }

            // Build options from offers
            $roomOptions = [];
            foreach ($roomData['offers'] as $index => $offer) {
                $price = $offer['price'] ?? [];
                $policies = $offer['policies'] ?? [];
                
                // Base price (total for entire stay)
                $totalBasePrice = floatval($price['total'] ?? 0);
                $basePricePerNight = $totalBasePrice / $nights;
                $offerCurrency = $price['currency'] ?? 'EUR';

                // Apply markup
                $markedPrice = MARKUP($basePricePerNight, $module, $db, $offerCurrency, $currency);
                $pricePerNight = $markedPrice['price'];
                $totalPrice = $pricePerNight * $nights;

                // Board type (meal plan)
                $boardCode = $offer['boardType'] ?? 'ROOM_ONLY';
                $boardName = str_replace('_', ' ', ucwords(strtolower($boardCode)));

                // Cancellation policy
                $cancellationFree = false;
                $refundable = false;
                $cancellationPolicies = [];

                if (!empty($policies['cancellation'])) {
                    $cancelPolicy = $policies['cancellation'];
                    
                    if (isset($cancelPolicy['type'])) {
                        $refundable = ($cancelPolicy['type'] !== 'NON_REFUNDABLE');
                    }

                    if (isset($cancelPolicy['deadline'])) {
                        $deadlineDate = new DateTime($cancelPolicy['deadline']);
                        $now = new DateTime();
                        $cancellationFree = ($deadlineDate > $now);
                        
                        $cancellationPolicies[] = [
                            'amount' => $cancelPolicy['amount'] ?? $totalPrice,
                            'from' => $cancelPolicy['deadline']
                        ];
                    }
                }

                // Payment type
                $paymentType = $policies['paymentType'] ?? 'AT_HOTEL';
                $guaranteeRequired = ($policies['guarantee']['acceptedPayments'] ?? null) !== null;

                $roomOptions[] = [
                    'option_index' => $index,
                    'max_adults' => $maxAdults,
                    'max_children' => $maxChildren,
                    'price_per_night' => round($pricePerNight, 2),
                    'total_price' => round($totalPrice, 2),
                    'base_price' => round($basePricePerNight, 2),
                    'currency' => $currency,
                    'original_currency' => $offerCurrency,
                    'discount_percentage' => 0,
                    'extra_bed_available' => 0,
                    'extra_bed_charge' => 0,
                    'breakfast_included' => (stripos($boardCode, 'BREAKFAST') !== false) ? 1 : 0,
                    'cancellation_free' => $cancellationFree ? 1 : 0,
                    'refundable' => $refundable ? 1 : 0,
                    'available_quantity' => 1,
                    'board_id' => $boardCode,
                    'board_name' => $boardName,
                    'rate_key' => $offer['id'] ?? '',
                    'payment_type' => $paymentType,
                    'guarantee_required' => $guaranteeRequired ? 1 : 0,
                    'cancellation_policies' => $cancellationPolicies,
                    'description' => $offer['description']['text'] ?? ''
                ];
            }

            // Only add rooms that have available options
            if (!empty($roomOptions)) {
                $roomsResponse[] = [
                    'room_id' => $roomCode,
                    'room_type_id' => $roomCode,
                    'room_name' => $roomName,
                    'room_type' => $roomName,
                    'room_images' => $roomImages,
                    'room_main_image' => $roomImages[0],
                    'amenities' => $amenities,
                    'max_adults' => $maxAdults,
                    'max_children' => $maxChildren,
                    'beds' => $beds,
                    'bed_type' => str_replace('_', ' ', ucwords(strtolower($bedType))),
                    'description' => $roomDescription,
                    'options' => $roomOptions
                ];
            }
        }

        // ========================================
        // BUILD RESPONSE
        // ========================================
        $response = [
            'success' => true,
            'data' => [
                'hotel_id' => $hotelId,
                'nights' => $nights,
                'currency' => $currency,
                'rooms' => $roomsResponse
            ]
        ];

        if (empty($roomsResponse)) {
            $response['data']['message'] = 'No rooms available for the selected dates';
        }

        echo json_encode($response);

    } catch (Exception $e) {
        // Log errors only
        @mkdir($logsPath, 0755, true);
        @file_put_contents($logsPath . '/Error_Rooms_' . date('Ymd_His') . '.txt', 
            "[" . date('Y-m-d H:i:s') . "]\n" .
            "Error: " . $e->getMessage() . "\n" .
            "Input: " . json_encode($input ?? [], JSON_PRETTY_PRINT) . "\n" .
            "Stack Trace:\n" . $e->getTraceAsString()
        );
        
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
});

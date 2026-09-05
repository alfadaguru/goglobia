<?php
// ============================================================================
// RATEHAWK HOTEL ROOMS - REAL-TIME AVAILABILITY & PRICING
// ============================================================================
// ENDPOINT: POST /stays/ratehawk/rooms
// PURPOSE: Fetch available rooms with live pricing from RateHawk API
// FALLBACK: Database rooms if API unavailable
// ============================================================================

$router->post('/stays/ratehawk/rooms', function() use ($db) {

    // CLEAN OUTPUT BUFFER
    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json');

    try {
        // ============================================================================
        // EXTRACT REQUEST PARAMETERS
        // ============================================================================
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $hotelId = $input['hotel_id'] ?? '';
        $checkin = trim($input['checkin'] ?? '');
        $checkout = trim($input['checkout'] ?? '');
        $nationality = $input['nationality'] ?? 'US';

        // Prefer rooms_data (per-room adults/children/ages). Frontend sends rooms as a count integer.
        $rooms = $input['rooms_data'] ?? null;
        if (is_string($rooms)) {
            $rooms = json_decode($rooms, true);
        }
        if (!is_array($rooms) || empty($rooms)) {
            $rooms = $input['rooms'] ?? [['adults' => 2, 'children' => 0, 'childAges' => []]];
        }
        // Normalize rooms: if integer/string, convert to array of room objects
        if (!is_array($rooms)) {
            $count = max(1, (int)$rooms);
            $rooms = array_fill(0, $count, ['adults' => 2, 'children' => 0, 'childAges' => []]);
        } elseif (!empty($rooms) && !isset($rooms[0])) {
            // Single room object passed without wrapping array
            $rooms = [$rooms];
        }
        // Ensure each room has normalized occupancy
        $rooms = array_values(array_map(function ($room) {
            if (!is_array($room)) {
                return ['adults' => 2, 'children' => 0, 'childAges' => []];
            }
            $adults = max(1, (int)($room['adults'] ?? 2));
            $children = max(0, (int)($room['children'] ?? 0));
            $ages = $room['childAges'] ?? $room['children_ages'] ?? $room['child_ages'] ?? [];
            if (!is_array($ages)) {
                $ages = [];
            }
            $ages = array_map('intval', array_values($ages));
            while (count($ages) < $children) {
                $ages[] = 1;
            }
            if (count($ages) > $children) {
                $ages = array_slice($ages, 0, $children);
            }
            return [
                'adults' => $adults,
                'children' => $children,
                'childAges' => $ages,
            ];
        }, $rooms));

        $currency = $input['currency'] ?? $_SESSION['app_currency'] ?? 'USD';
        $sessionCurrency = $_SESSION['app_currency'] ?? $currency;

        if (empty($hotelId)) {
            echo json_encode([
                'success' => false,
                'message' => 'Missing required parameter: hotel_id'
            ]);
            exit;
        }

        if (empty($checkin) || empty($checkout)) {
            echo json_encode([
                'success' => false,
                'message' => 'Missing required parameters: checkin and checkout dates'
            ]);
            exit;
        }

        // ============================================================================
        // GET MODULE CONFIGURATION
        // ============================================================================
        $module = $db->get('modules', '*', [
            'name' => 'ratehawk',
            'type' => 'stays'
        ]);

        if (!$module) {
            echo json_encode([
                'success' => false,
                'message' => 'RateHawk module not configured'
            ]);
            exit;
        }

        $moduleCurrency = !empty($module['currency']) ? $module['currency'] : 'USD';

        // ============================================================================
        // CONNECT TO RATEHAWK MODULE DATABASE (modulesratehawk)
        // ============================================================================
        $ratehawkDb = new \Medoo\Medoo([
            'type'     => 'mysql',
            'host'     => $module['host'] ?? 'localhost',
            'database' => $module['database'],
            'username' => $module['username'],
            'password' => $module['password'],
            'charset'  => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        // ============================================================================
        // FETCH HOTEL FROM DATABASE
        // ============================================================================
        $hotel = $ratehawkDb->get('ratehawk_hotels', '*', [
            'hotel_id' => $hotelId
        ]);

        if (!$hotel) {
            echo json_encode([
                'success' => false,
                'message' => 'Hotel not found'
            ]);
            exit;
        }

        // ============================================================================
        // CALCULATE NUMBER OF NIGHTS
        // ============================================================================
        $number_of_nights = 1;
        if (!empty($checkin) && !empty($checkout)) {
            try {
                $checkin_parts = explode('-', $checkin);
                $checkout_parts = explode('-', $checkout);
                if (count($checkin_parts) === 3 && count($checkout_parts) === 3) {
                    $checkin_date = "{$checkin_parts[2]}-{$checkin_parts[1]}-{$checkin_parts[0]}";
                    $checkout_date = "{$checkout_parts[2]}-{$checkout_parts[1]}-{$checkout_parts[0]}";
                    $date1 = new DateTime($checkin_date);
                    $date2 = new DateTime($checkout_date);
                    $number_of_nights = max(1, (int)$date1->diff($date2)->days);
                }
            } catch (Exception $e) {
                $number_of_nights = 1;
            }
        }

        // ============================================================================
        // PREPARE DATABASE ROOMS AS FALLBACK
        // ============================================================================
        $roomsData = is_array($hotel['rooms']) 
            ? $hotel['rooms'] 
            : (json_decode($hotel['rooms'], true) ?? []);

        if (is_array($roomsData)) {
            $roomsData = array_values(array_filter($roomsData, function($room) {
                return is_array($room) && !empty($room);
            }));
        } else {
            $roomsData = [];
        }

        $formattedRooms = [];

        foreach ($roomsData as $idx => $room) {
            $uniqueRoomId = $hotelId . '_room_' . $idx . '_' . uniqid();

            // SECURITY/CORRECTNESS (§16 HIGH): this is the STATIC-DB fallback path
            // (used when the live RateHawk rate call returns nothing). RateHawk is a
            // live-rate supplier — static rooms have NO real price. The old code did
            // `rand(50,300)` and returned it as a bookable price (customers could
            // book at a fabricated amount). NEVER fabricate a price: read a real one
            // from the room row if present, otherwise mark the room price-unavailable
            // (0) so the UI shows "live price on request" instead of a fake bookable
            // rate.
            $realPrice = (float)(
                $room['price'] ?? $room['daily_price'] ?? $room['min_price'] ?? $room['amount'] ?? 0
            );
            $priceUnavailable = ($realPrice <= 0);
            $basePrice = $realPrice; // 0 when unavailable — no random value

            $pricePerNight = MARKUP($basePrice, $module, $db, $moduleCurrency, $sessionCurrency);
            $totalPrice = $pricePerNight['price'] * $number_of_nights;

            $roomAmenities = is_array($room['room_amenities'] ?? null) 
                ? $room['room_amenities'] 
                : (json_decode($room['room_amenities'] ?? '[]', true) ?? []);

            $roomImages = is_array($room['images'] ?? null) 
                ? $room['images'] 
                : (json_decode($room['images'] ?? '[]', true) ?? []);
            
            $processedRoomImages = array_map(function($url) {
                return str_replace('{size}', '1024x768', $url);
            }, $roomImages);

            $formattedRooms[] = [
                'room_id' => $uniqueRoomId,
                'room_type_id' => $uniqueRoomId,
                'room_name' => $room['name'] ?? 'Standard Room',
                'room_type_name' => $room['name_struct']['main_name'] ?? $room['name'] ?? 'Standard',
                'description' => $room['description'] ?? '',
                'size_sqm' => $room['size'] ?? null,
                'max_adults' => $room['rg_ext']['capacity'] ?? 2,
                'max_children' => 2,
                'bed_type' => $room['name_struct']['bedding_type'] ?? 'Double Bed',
                'amenities' => array_map(function($amenity, $index) {
                    return [
                        'id' => $index + 1,
                        'name' => ucwords(str_replace('-', ' ', $amenity))
                    ];
                }, $roomAmenities, array_keys($roomAmenities)),
                'room_images' => $processedRoomImages,
                'room_main_image' => !empty($processedRoomImages) ? $processedRoomImages[0] : null,
                'min_price_per_night' => round($pricePerNight['price'], 2),
                'price_unavailable' => $priceUnavailable,
                'options_count' => 1,
                'options' => [
                    [
                        'option_id' => $hotelId . '_room_' . $idx . '_opt_1',
                        'board_name' => 'Room Only',
                        'board_code' => 'RO',
                        'meal_plan' => 'Room Only',
                        'cancellation_free' => 1,
                        'refundable' => 1,
                        'breakfast_included' => 0,
                        'price_per_night' => round($pricePerNight['price'], 2),
                        'total_price' => round($totalPrice, 2),
                        'price_unavailable' => $priceUnavailable,
                        'note' => $priceUnavailable ? 'Live price on request — not bookable at a listed rate.' : null,
                        'currency' => $sessionCurrency,
                        'adults' => $rooms[0]['adults'] ?? 2,
                        'children' => $rooms[0]['children'] ?? 0,
                        'rate_key' => base64_encode($hotelId . '|room_' . $idx . '|opt_1')
                    ]
                ]
            ];
        }

        // NOTE: previously, when no rooms were formatted from the content DB, a
        // placeholder "Standard Room" was fabricated with rand(50,200) as its
        // price and returned as a BOOKABLE rate — customers could book at a fake
        // price. That fabrication has been removed. Real, bookable pricing is
        // fetched below from the RateHawk prebook API; if that returns nothing,
        // we return no rooms rather than an invented price.

        // ============================================================================
        // CALL RATEHAWK PREBOOK API FOR REAL-TIME PRICING
        // ============================================================================
                $keyId = trim($module['c1'] ?? '');
        $apiKey = trim($module['c3'] ?? '');
        
        if (empty($keyId) || empty($apiKey)) {
            echo json_encode([
                'success' => false,
                'message' => 'RateHawk API credentials not configured'
            ]);
            exit;
        }

        $apiBaseUrl = rtrim(trim($module['c4'] ?? ''), '/');
        if (stripos($apiBaseUrl, '/api/b2b/v3') === false) {
            $apiBaseUrl .= '/api/b2b/v3';
        }

        // CONVERT DATE FORMAT: DD-MM-YYYY → YYYY-MM-DD
        $checkinParts = explode('-', $checkin);
        $checkoutParts = explode('-', $checkout);
        
        if (count($checkinParts) === 3 && count($checkoutParts) === 3) {
            $apiCheckin = (strlen($checkinParts[0]) === 4) 
                ? $checkin 
                : $checkinParts[2] . '-' . $checkinParts[1] . '-' . $checkinParts[0];
            
            $apiCheckout = (strlen($checkoutParts[0]) === 4) 
                ? $checkout 
                : $checkoutParts[2] . '-' . $checkoutParts[1] . '-' . $checkoutParts[0];
        } else {
            $apiCheckin = $checkin;
            $apiCheckout = $checkout;
        }

        // PREPARE API REQUEST — children must be ages[], not a count
        $hotelpageRequest = [
            'id' => (string)$hotelId,
            'checkin' => $apiCheckin,
            'checkout' => $apiCheckout,
            'residency' => strtolower($nationality),
            'language' => 'en',
            'guests' => array_map(function($room) {
                $roomAdults = max(1, (int)($room['adults'] ?? 2));
                $roomChildren = max(0, (int)($room['children'] ?? 0));
                $ages = $room['childAges'] ?? $room['children_ages'] ?? $room['child_ages'] ?? [];
                if (!is_array($ages)) {
                    $ages = [];
                }
                $ages = array_map('intval', array_values($ages));
                while (count($ages) < $roomChildren) {
                    $ages[] = 1;
                }
                if (count($ages) > $roomChildren) {
                    $ages = array_slice($ages, 0, $roomChildren);
                }

                $guestRoom = ['adults' => $roomAdults];
                if ($roomChildren > 0) {
                    $guestRoom['children'] = $ages;
                }
                return $guestRoom;
            }, $rooms),
            'currency' => $moduleCurrency
        ];

        // CALL API
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiBaseUrl . '/search/hp/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERPWD => $keyId . ':' . $apiKey,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($hotelpageRequest),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $hotelpageResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if (function_exists('ratehawk_log')) {
            ratehawk_log('rooms', 'hotelpage', $hotelpageRequest, $hotelpageResponse, '', [
                'url' => $apiBaseUrl . '/search/hp/',
                'method' => 'POST',
                'headers' => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Basic ***'
                ],
                'response_headers' => ''
            ]);
        }


        // ============================================================================
        // PROCESS API RESPONSE OR USE FALLBACK
        // ============================================================================
        $usingLivePricing = false;
        
        if (!$curlError && $httpCode === 200) {
            $apiData = json_decode($hotelpageResponse, true);
            $hotels = $apiData['data']['hotels'] ?? [];
            
            if (!empty($hotels)) {
                $hotelData = $hotels[0];
                $rates = $hotelData['rates'] ?? [];
                
                // Group rates by room name
                $groupedRooms = [];
                foreach ($rates as $rate) {
                    $roomName = $rate['room_name'] ?? 'Standard Room';
                    if (!isset($groupedRooms[$roomName])) {
                        $groupedRooms[$roomName] = [];
                    }
                    $groupedRooms[$roomName][] = $rate;
                }

                $formattedRooms = [];
                $usingLivePricing = true;

                foreach ($groupedRooms as $roomName => $apiRates) {
                    $roomImages = [];
                    
                    foreach ($roomsData as $localRoom) {
                        if (isset($localRoom['name']) && $localRoom['name'] === $roomName) {
                            $imgs = is_array($localRoom['images'] ?? null) 
                                ? $localRoom['images'] 
                                : (json_decode($localRoom['images'] ?? '[]', true) ?? []);
                            $roomImages = array_map(function($url) {
                                return str_replace('{size}', '1024x768', $url);
                            }, $imgs);
                            break;
                        }
                    }

                    $rateOptions = [];
                    $maxAdults = 2;
                    foreach ($apiRates as $rate) {
                        $bookHash = $rate['book_hash'] ?? null;
                        if (empty($bookHash)) continue;

                        $paymentType = $rate['payment_options']['payment_types'][0] ?? [];
                        $basePrice = (float)($paymentType['show_amount'] ?? $paymentType['amount'] ?? 0);
                        if ($basePrice <= 0) continue;

                        $apiCurrency = strtoupper((string)(
                            $paymentType['show_currency_code']
                            ?? $paymentType['currency_code']
                            ?? $moduleCurrency
                        ));

                        $priceData = MARKUP($basePrice, $module, $db, $apiCurrency, $sessionCurrency);
                        $finalPrice = $priceData['price'] ?? $basePrice;
                        $pricePerNight = $finalPrice / $number_of_nights;

                        if (isset($rate['rg_ext']['capacity'])) {
                            $maxAdults = max($maxAdults, (int)$rate['rg_ext']['capacity']);
                        }

                        $rateOptions[] = [
                            'option_id' => $bookHash,
                            'book_hash' => $bookHash,
                            'board_name' => ucfirst(str_replace('_', ' ', $rate['meal'] ?? 'room_only')),
                            'board_code' => strtoupper($rate['meal'] ?? 'RO'),
                            'meal_plan' => ucfirst(str_replace('_', ' ', $rate['meal'] ?? 'Room Only')),
                            'cancellation_free' => !empty($rate['payment_options']['cancellation_penalties']['free_cancellation_before']),
                            'refundable' => empty($rate['payment_options']['cancellation_penalties']['policies']),
                            'breakfast_included' => in_array($rate['meal'] ?? '', ['breakfast', 'half_board', 'full_board', 'all_inclusive']),
                            'price_per_night' => round($pricePerNight, 2),
                            'total_price' => round($finalPrice, 2),
                            'original_price' => round($basePrice, 2),
                            'currency' => $sessionCurrency,
                            'adults' => $rate['adults'] ?? $rooms[0]['adults'] ?? 2,
                            'children' => count($rate['children'] ?? []),
                            'rate_key' => $bookHash
                        ];
                    }

                    if (empty($rateOptions)) continue;

                    $firstRate = $apiRates[0];
                    $roomId = md5($roomName);

                    $formattedRooms[] = [
                        'room_id' => $roomId,
                        'room_type_id' => $roomId,
                        'room_name' => $roomName,
                        'room_type_name' => $firstRate['room_data_trans']['main_name'] ?? $roomName,
                        'description' => $firstRate['room_name_info']['original_rate_name'] ?? '',
                        'size_sqm' => null,
                        'max_adults' => $maxAdults,
                        'max_children' => 2,
                        'bed_type' => $firstRate['room_data_trans']['bedding_type'] ?? 'Standard',
                        'amenities' => array_map(function($amenity, $index) {
                            return [
                                'id' => $index + 1,
                                'name' => ucwords(str_replace('-', ' ', $amenity))
                            ];
                        }, $firstRate['amenities_data'] ?? [], array_keys($firstRate['amenities_data'] ?? [])),
                        'room_images' => $roomImages,
                        'room_main_image' => !empty($roomImages) ? $roomImages[0] : null,
                        'min_price_per_night' => round(min(array_column($rateOptions, 'price_per_night')), 2),
                        'options_count' => count($rateOptions),
                        'options' => $rateOptions
                    ];
                }
            }
        }
        
        if (empty($formattedRooms)) {
            $usingLivePricing = false;
        }

        // ============================================================================
        // BUILD RESPONSE
        // ============================================================================
        echo json_encode([
            'success' => true,
            'data' => [
                'hotel_id' => $hotelId,
                'rooms' => $formattedRooms,
                'checkin' => $checkin,
                'checkout' => $checkout,
                'nights' => $number_of_nights,
                'currency' => $sessionCurrency,
                'live_pricing' => $usingLivePricing
            ]
        ]);

    } catch (\Throwable $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $e->getMessage()
        ]);
    }

    ob_end_flush();
});

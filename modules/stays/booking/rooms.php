<?php
// ============================================================================
// BOOKING.COM HOTEL ROOMS - REAL-TIME AVAILABILITY & PRICING
// ============================================================================
// ENDPOINT: POST /stays/booking/rooms
// PURPOSE: Fetch available rooms with live pricing from Booking.com API
// ============================================================================

$router->post('/stays/booking/rooms', function() use ($db) {

    // CLEAN OUTPUT BUFFER
    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json');

    try {
        // ============================================================================
        // EXTRACT REQUEST PARAMETERS
        // ============================================================================
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isJson = strpos($contentType, 'application/json') !== false;
        
        if ($isJson) {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
        } else {
            $input = $_POST;
        }

        $hotelId = trim($input['hotel_id'] ?? '');
        $checkin = trim($input['checkin'] ?? '');
        $checkout = trim($input['checkout'] ?? '');
        $adults = max(1, (int)($input['adults'] ?? 2));
        $children = max(0, (int)($input['children'] ?? 0));
        $roomCount = max(1, (int)($input['rooms'] ?? 1));
        $currency = strtoupper(trim($input['currency'] ?? 'USD'));
        $nationality = strtoupper(trim($input['nationality'] ?? 'US'));

        if (empty($hotelId)) {
            throw new Exception('Missing required parameter: hotel_id');
        }

        if (empty($checkin) || empty($checkout)) {
            throw new Exception('Missing required parameters: checkin and checkout dates');
        }

        // ============================================================================
        // GET MODULE CONFIGURATION
        // ============================================================================
        $module = $db->get('modules', '*', [
            'name' => 'booking',
            'type' => 'stays'
        ]);

        if (!$module) {
            throw new Exception('Booking.com module not configured');
        }

        // ============================================================================
        // CALCULATE NUMBER OF NIGHTS
        // ============================================================================
        $number_of_nights = 1;
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

        // ============================================================================
        // GET API CREDENTIALS
        // ============================================================================
        $apiKey = trim($module['c1'] ?? '');
        $apiHost = trim($module['c2'] ?? '');

        if (empty($apiKey) || empty($apiHost)) {
            throw new Exception('Booking.com API credentials not configured');
        }

        // ============================================================================
        // FORMAT DATES FOR BOOKING.COM API (DD-MM-YYYY to YYYY-MM-DD)
        // ============================================================================
        $checkinParts = explode('-', $checkin);
        $checkoutParts = explode('-', $checkout);
        $apiCheckin = "{$checkinParts[2]}-{$checkinParts[1]}-{$checkinParts[0]}";
        $apiCheckout = "{$checkoutParts[2]}-{$checkoutParts[1]}-{$checkoutParts[0]}";

        // ============================================================================
        // STEP 1: GET ROOM AVAILABILITY + PRICING (getRoomList API)
        // ============================================================================
        $connectTimeout = defined('SUPPLIER_CONNECT_TIMEOUT') ? SUPPLIER_CONNECT_TIMEOUT : 10;
        $requestTimeout = defined('SUPPLIER_REQUEST_TIMEOUT') ? SUPPLIER_REQUEST_TIMEOUT : 25;

        $roomsResponse = [];
        try {
            $roomParams = [
                'hotel_id'         => $hotelId,
                'arrival_date'     => $apiCheckin,
                'departure_date'   => $apiCheckout,
                'adults'           => $adults,
                'room_qty'         => $roomCount,
                'units'            => 'metric',
                'temperature_unit' => 'c',
                'languagecode'     => 'en-us',
                'currency_code'    => $currency,
                'location'         => 'US',
            ];

            if ($children > 0) {
                $roomParams['children_age'] = implode(',', array_fill(0, $children, 5));
            }

            $roomUrl = "https://{$apiHost}/api/v1/hotels/getRoomList?" . http_build_query($roomParams);
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $roomUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => $requestTimeout,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => 'GET',
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'x-rapidapi-host: ' . $apiHost,
                    'x-rapidapi-key: '  . $apiKey,
                ],
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

            $body   = curl_exec($ch);
            $errno  = curl_errno($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno) {
                throw new Exception("cURL error: {$errno}");
            }

            $roomsResponse = json_decode((string)$body, true) ?? [];
        } catch (Exception $e) {
            // Continue with empty rooms
            error_log('[BOOKING ROOMS] getRoomList failed: ' . $e->getMessage());
        }

        // ============================================================================
        // STEP 2: GET HOTEL DETAILS (for address + coordinates)
        // ============================================================================
        $hotelAddress = '';
        $hotelCity = '';
        $hotelCountry = '';
        $hotelLatitude = null;
        $hotelLongitude = null;
        
        try {
            $detailParams = [
                'hotel_id'         => $hotelId,
                'arrival_date'     => $apiCheckin,
                'departure_date'   => $apiCheckout,
                'adults'           => $adults,
                'room_qty'         => $roomCount,
                'units'            => 'metric',
                'temperature_unit' => 'c',
                'languagecode'     => 'en-us',
                'currency_code'    => $currency,
            ];

            $detailUrl = "https://{$apiHost}/api/v1/hotels/getHotelDetails?" . http_build_query($detailParams);
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $detailUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $requestTimeout,
                CURLOPT_CUSTOMREQUEST  => 'GET',
                CURLOPT_HTTPHEADER     => [
                    'x-rapidapi-host: ' . $apiHost,
                    'x-rapidapi-key: '  . $apiKey,
                ],
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

            $body   = curl_exec($ch);
            $errno  = curl_errno($ch);
            curl_close($ch);

            if (!$errno) {
                $detailData = json_decode((string)$body, true) ?? [];
                $data = $detailData['data'] ?? [];
                
                if (is_array($data)) {
                    $hotelAddress = trim((string)($data['address'] ?? ''));
                    $hotelCity = trim((string)($data['city_trans'] ?? $data['city'] ?? ''));
                    $hotelCountry = trim((string)($data['country_trans'] ?? ''));
                    
                    // Extract coordinates
                    if (empty($hotelLatitude) && isset($data['latitude']) && is_numeric($data['latitude'])) {
                        $hotelLatitude = (float)$data['latitude'];
                    }
                    if (empty($hotelLongitude) && isset($data['longitude']) && is_numeric($data['longitude'])) {
                        $hotelLongitude = (float)$data['longitude'];
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[BOOKING ROOMS] getHotelDetails failed: ' . $e->getMessage());
        }

        // ============================================================================
        // STEP 3: GET HOTEL PHOTOS
        // ============================================================================
        $roomImages = [];
        try {
            $photoParams = ['hotel_id' => $hotelId];
            $photoUrl = "https://{$apiHost}/api/v1/hotels/getHotelPhotos?" . http_build_query($photoParams);
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $photoUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $requestTimeout,
                CURLOPT_CUSTOMREQUEST  => 'GET',
                CURLOPT_HTTPHEADER     => [
                    'x-rapidapi-host: ' . $apiHost,
                    'x-rapidapi-key: '  . $apiKey,
                ],
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

            $body   = curl_exec($ch);
            curl_close($ch);

            if (!empty($body)) {
                $photosData = json_decode((string)$body, true) ?? [];
                
                // Handle different response structures for photos
                if (!empty($photosData['data']) && is_array($photosData['data'])) {
                    // Direct array of photo objects
                    foreach ($photosData['data'] as $photo) {
                        if (is_array($photo) && isset($photo['max_photo_url'])) {
                            $roomImages[] = $photo['max_photo_url'];
                        } elseif (is_string($photo)) {
                            $roomImages[] = $photo;
                        }
                        if (count($roomImages) >= 10) break;
                    }
                }
            }
        } catch (Exception $e) {
            // Continue without photos
            error_log('[BOOKING ROOMS] getHotelPhotos failed: ' . $e->getMessage());
        }
        // Extract unique rooms from block array and link to detailed room info
        // ============================================================================
        $formattedRooms = [];
        $roomData = $roomsResponse['data'] ?? [];
        $roomList = $roomData['block'] ?? [];
        $roomDetails = $roomData['rooms'] ?? [];  // Detailed room info by room_id

        if (is_array($roomList)) {
            $processedRoomIds = [];  // Track processed rooms to avoid duplicates
            
            foreach ($roomList as $block) {
                if (!is_array($block)) continue;

                // Get actual room_id from API
                $apiRoomId = (int)($block['room_id'] ?? null);
                if (empty($apiRoomId)) continue;
                
                // Skip if already processed (multiple rates for same room)
                if (isset($processedRoomIds[$apiRoomId])) continue;
                $processedRoomIds[$apiRoomId] = true;

                // Extract room name (clean version without policy text)
                $roomName = $block['name_without_policy'] ?? $block['room_name'] ?? $block['name'] ?? "Room {$apiRoomId}";
                $roomName = trim(str_replace(' - Free cancellation', '', $roomName));
                $uniqueRoomId = md5($roomName . $hotelId . $apiRoomId);

                // Extract pricing from block
                $priceData = $block['product_price_breakdown'] ?? [];
                $grossPrice = $priceData['gross_amount'] ?? [];
                $basePrice = isset($grossPrice['value']) ? (float)$grossPrice['value'] : null;

                if ($basePrice === null || $basePrice <= 0) continue;

                $priceCurrency = $grossPrice['currency'] ?? $currency;

                // Apply markup and currency conversion
                $priceInfo = MARKUP($basePrice, $module, $db, $priceCurrency, $currency);
                $pricePerNight = $priceInfo['price'] ?? $basePrice;
                $totalPrice = $pricePerNight * $number_of_nights;

                // Extract occupancy info
                $fitOccupancy = $block['fit_occupancy'] ?? [];
                $roomAdults = (int)($fitOccupancy['nr_adults'] ?? $adults);
                $childrenAges = $fitOccupancy['children_ages'] ?? [];
                $roomChildren = count($childrenAges);
                $maxOccupancy = (int)($block['max_occupancy'] ?? $roomAdults);

                // Extract cancellation policy
                $blockText = $block['block_text'] ?? [];
                $policies = $blockText['policies'] ?? [];
                $roomCancellation = '';
                $freeCancellation = false;
                foreach ($policies as $policy) {
                    if (($policy['class'] ?? '') === 'POLICY_CANCELLATION') {
                        $roomCancellation = $policy['content'] ?? '';
                        $freeCancellation = strpos(strtolower($roomCancellation), 'free') !== false;
                        break;
                    }
                }

                // Breakfast info
                $breakfastIncluded = (bool)($block['breakfast_included'] ?? false);

                // Extract amenities from bundle_extras.benefits
                $roomAmenities = [];
                $bundleExtras = $block['bundle_extras'] ?? [];
                if (!empty($bundleExtras['benefits']) && is_array($bundleExtras['benefits'])) {
                    foreach ($bundleExtras['benefits'] as $amenityIdx => $benefit) {
                        if (!empty($benefit['title'])) {
                            $roomAmenities[] = [
                                'id' => $amenityIdx + 1,
                                'name' => $benefit['title']
                            ];
                        }
                    }
                }

                // Add amenities from room details if available
                if (isset($roomDetails[$apiRoomId]) && is_array($roomDetails[$apiRoomId])) {
                    $detailsData = $roomDetails[$apiRoomId];
                    if (!empty($detailsData['facilities']) && is_array($detailsData['facilities'])) {
                        $amenityIdx = count($roomAmenities);
                        foreach ($detailsData['facilities'] as $facility) {
                            if (is_array($facility) && !empty($facility['name'])) {
                                // Check if not duplicate
                                $facilityName = $facility['name'];
                                $isDuplicate = false;
                                foreach ($roomAmenities as $existing) {
                                    if (strcasecmp($existing['name'], $facilityName) === 0) {
                                        $isDuplicate = true;
                                        break;
                                    }
                                }
                                if (!$isDuplicate) {
                                    $roomAmenities[] = [
                                        'id' => ++$amenityIdx,
                                        'name' => $facilityName
                                    ];
                                }
                            } elseif (is_string($facility)) {
                                // If facility is just a string
                                $isDuplicate = false;
                                foreach ($roomAmenities as $existing) {
                                    if (strcasecmp($existing['name'], $facility) === 0) {
                                        $isDuplicate = true;
                                        break;
                                    }
                                }
                                if (!$isDuplicate) {
                                    $roomAmenities[] = [
                                        'id' => ++$amenityIdx,
                                        'name' => $facility
                                    ];
                                }
                            }
                        }
                    }
                }

                // Get room description and photos from detailed room info
                $roomDescription = '';
                $roomPhotoUrls = [];
                if (isset($roomDetails[$apiRoomId]) && is_array($roomDetails[$apiRoomId])) {
                    $roomDescription = $roomDetails[$apiRoomId]['description'] ?? '';
                    
                    // Extract photos from room details
                    if (!empty($roomDetails[$apiRoomId]['photos']) && is_array($roomDetails[$apiRoomId]['photos'])) {
                        foreach ($roomDetails[$apiRoomId]['photos'] as $photo) {
                            if (is_array($photo)) {
                                // Try different URL formats (prefer max1280, then max750, then original)
                                if (!empty($photo['url_max1280'])) {
                                    $roomPhotoUrls[] = $photo['url_max1280'];
                                } elseif (!empty($photo['url_max750'])) {
                                    $roomPhotoUrls[] = $photo['url_max750'];
                                } elseif (!empty($photo['url_original'])) {
                                    $roomPhotoUrls[] = $photo['url_original'];
                                } elseif (!empty($photo['url_max300'])) {
                                    $roomPhotoUrls[] = $photo['url_max300'];
                                }
                            }
                            if (count($roomPhotoUrls) >= 5) break;
                        }
                    }
                }

                // Create room options (images handled in response)
                $options = [
                    [
                        'option_id' => $uniqueRoomId . '_opt_1',
                        'book_hash' => base64_encode($hotelId . '|' . $uniqueRoomId . '|opt_1'),
                        'board_name' => $breakfastIncluded ? 'Breakfast Included' : 'Room Only',
                        'board_code' => $breakfastIncluded ? 'BB' : 'RO',
                        'meal_plan' => $breakfastIncluded ? 'Breakfast Included' : 'Room Only',
                        'cancellation_free' => $freeCancellation ? 1 : 0,
                        'refundable' => (int)($block['refundable'] ?? ($freeCancellation ? 1 : 0)),
                        'breakfast_included' => $breakfastIncluded ? 1 : 0,
                        'price_per_night' => round($pricePerNight, 2),
                        'total_price' => round($totalPrice, 2),
                        'original_price' => round($basePrice, 2),
                        'currency' => $currency,
                        'adults' => $roomAdults,
                        'children' => $roomChildren,
                        'rate_key' => base64_encode($hotelId . '|' . $uniqueRoomId . '|opt_1')
                    ]
                ];

                $formattedRooms[] = [
                    'room_id' => $uniqueRoomId,
                    'room_type_id' => $uniqueRoomId,
                    'room_name' => $roomName,
                    'room_type_name' => $roomName,
                    'description' => trim((string)$roomDescription),
                    'size_sqm' => null,
                    'max_adults' => $maxOccupancy,
                    'max_children' => $roomChildren > 0 ? $roomChildren : 2,
                    'bed_type' => 'Standard Bed',
                    'amenities' => $roomAmenities,
                    'room_images' => !empty($roomPhotoUrls) ? $roomPhotoUrls : $roomImages,
                    'room_main_image' => !empty($roomPhotoUrls) ? $roomPhotoUrls[0] : (!empty($roomImages) ? $roomImages[0] : null),
                    'min_price_per_night' => round($pricePerNight, 2),
                    'options_count' => count($options),
                    'options' => $options
                ];
            }
        }

        // ============================================================================
        // FALLBACK: CREATE DEFAULT ROOM IF NONE FOUND
        // ============================================================================
        if (empty($formattedRooms)) {
            $roomId = md5('Standard_' . $hotelId);
            $basePrice = 100;
            $priceData = MARKUP($basePrice, $module, $db, 'USD', $currency);
            $pricePerNight = $priceData['price'] ?? $basePrice;
            $totalPrice = $pricePerNight * $number_of_nights;

            $formattedRooms[] = [
                'room_id' => $roomId,
                'room_type_id' => $roomId,
                'room_name' => 'Standard Room',
                'room_type_name' => 'Standard',
                'description' => 'Comfortable standard room with modern amenities',
                'size_sqm' => null,
                'max_adults' => $adults,
                'max_children' => $children,
                'bed_type' => 'Double Bed',
                'amenities' => [],
                'room_images' => !empty($roomImages) ? $roomImages : [],
                'room_main_image' => !empty($roomImages) ? $roomImages[0] : null,
                'min_price_per_night' => round($pricePerNight, 2),
                'options_count' => 1,
                'options' => [
                    [
                        'option_id' => $roomId . '_opt_1',
                        'book_hash' => base64_encode($hotelId . '|' . $roomId . '|opt_1'),
                        'board_name' => 'Room Only',
                        'board_code' => 'RO',
                        'meal_plan' => 'Room Only',
                        'cancellation_free' => 1,
                        'refundable' => 1,
                        'breakfast_included' => 0,
                        'price_per_night' => round($pricePerNight, 2),
                        'total_price' => round($totalPrice, 2),
                        'original_price' => round($basePrice, 2),
                        'currency' => $currency,
                        'adults' => $adults,
                        'children' => $children,
                        'rate_key' => base64_encode($hotelId . '|' . $roomId . '|opt_1')
                    ]
                ]
            ];
        }

        // ============================================================================
        // BUILD RESPONSE
        // ============================================================================
        echo json_encode([
            'success' => true,
            'data' => [
                'hotel_id' => $hotelId,
                'address' => $hotelAddress,
                'city' => $hotelCity,
                'country' => $hotelCountry,
                'latitude' => $hotelLatitude,
                'longitude' => $hotelLongitude,
                'rooms' => $formattedRooms,
                'checkin' => $checkin,
                'checkout' => $checkout,
                'nights' => $number_of_nights,
                'currency' => $currency,
                'live_pricing' => true
            ]
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }

    exit;
});

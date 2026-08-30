<?php
// ============================================================================
// BOOKING.COM HOTEL DETAILS - COMPLETE IMPLEMENTATION
// ============================================================================
// ENDPOINT: POST /stays/booking/details
// PURPOSE:  Get full hotel details (name, address, images, amenities) + rooms
// NOTE:     Hotel metadata retrieved from session + supplementary API calls
// ============================================================================

$router->post('stays/booking/details', function() use ($db) {
    @set_time_limit(60);
    $connectTimeout = defined('SUPPLIER_CONNECT_TIMEOUT') ? SUPPLIER_CONNECT_TIMEOUT : 10;
    $requestTimeout = defined('SUPPLIER_REQUEST_TIMEOUT') ? SUPPLIER_REQUEST_TIMEOUT : 25;

    while (ob_get_level()) ob_end_clean();
    ob_start();
    header('Content-Type: application/json');

    try {
        // ====================================================================
        // EXTRACT PARAMETERS (Support both JSON and form data)
        // ====================================================================
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isJson = strpos($contentType, 'application/json') !== false;
        
        if ($isJson) {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
        } else {
            $input = $_POST;
        }
        
        $hotelId  = trim($input['hotel_id'] ?? '');
        $checkin  = trim($input['checkin']  ?? '');
        $checkout = trim($input['checkout'] ?? '');
        $adults   = max(1, (int)($input['adults']   ?? 2));
        $children = max(0, (int)($input['children'] ?? 0));
        $rooms    = max(1, (int)($input['rooms']    ?? 1));
        $currency = strtoupper(trim($input['currency'] ?? 'USD'));
        
        // Optional fields passed from search results
        $passedAddress = trim($input['address'] ?? '');
        $passedCity = trim($input['city'] ?? '');
        $passedCountry = trim($input['country'] ?? '');
        $passedAmenities = $input['amenities'] ?? [];
        $passedRating = (float)($input['rating'] ?? 0);
        $passedStars = (int)($input['stars'] ?? 0);

        if (empty($hotelId) || empty($checkin) || empty($checkout)) {
            throw new Exception('Missing required parameters: hotel_id, checkin, checkout');
        }

        // ====================================================================
        // GET HOTEL METADATA FROM SESSION
        // ====================================================================
        $stayDetail = $_SESSION['stay_detail'] ?? [];
        $hotelName  = trim($stayDetail['hotel_name'] ?? '');
        if (empty($hotelName)) {
            $hotelName = 'Hotel ' . $hotelId;
        }
        $destination = trim($stayDetail['destination'] ?? '');

        // ====================================================================
        // NORMALISE DATE FORMAT: DD-MM-YYYY → YYYY-MM-DD
        // ====================================================================
        $normaliseDate = function(string $date): string {
            if (strpos($date, '-') !== false) {
                $parts = explode('-', $date);
                if (count($parts) === 3 && strlen($parts[0]) <= 2) {
                    return "{$parts[2]}-{$parts[1]}-{$parts[0]}";
                }
            }
            return $date;
        };

        $checkinFormatted  = $normaliseDate($checkin);
        $checkoutFormatted = $normaliseDate($checkout);

        // ====================================================================
        // LOAD MODULE CREDENTIALS
        // ====================================================================
        $module = $db->get('modules', '*', [
            'name' => 'booking',
            'type' => 'stays'
        ]);

        if (!$module || empty($module['c1'])) {
            throw new Exception('Booking.com module not configured');
        }

        $apiKey  = trim($module['c1']);
        $apiHost = trim($module['c2'] ?? 'booking-com15.p.rapidapi.com');
        if (empty($apiHost)) $apiHost = 'booking-com15.p.rapidapi.com';

        $baseUrl = 'https://' . $apiHost;

        // ====================================================================
        // HELPER: RAPIDAPI GET REQUEST
        // ====================================================================
        $rapidGet = function(string $path, array $params) use ($baseUrl, $apiKey, $apiHost, $connectTimeout, $requestTimeout): array {
            $url = $baseUrl . $path . '?' . http_build_query($params);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
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
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $body   = curl_exec($ch);
            $errno  = curl_errno($ch);
            $error  = curl_error($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno) throw new Exception("cURL error #{$errno}: {$error}");
            if ($status < 200 || $status >= 300) {
                throw new Exception("HTTP {$status} from Booking.com API ({$path})");
            }

            $json = json_decode((string)$body, true);
            if (!is_array($json)) throw new Exception("Invalid JSON response from {$path}");
            if (isset($json['status']) && $json['status'] === false) {
                $msg = $json['message'] ?? 'unknown error';
                throw new Exception("API error: " . (is_string($msg) ? $msg : json_encode($msg)));
            }

            return $json;
        };

        // ====================================================================
        // STEP 0: GET FULL HOTEL PROPERTY DETAILS
        // Try to get additional data from supplementary APIs
        // ====================================================================
        $hotelMetadata = [
            'stars' => $passedStars,
            'rating' => $passedRating,
            'rating_word' => '',
            'review_count' => 0,
            'address' => $passedAddress,
            'city' => $passedCity,
            'country' => $passedCountry ?: '',
            'latitude' => null,
            'longitude' => null,
        ];

        // ====================================================================
        // STEP 1A: GET FULL HOTEL DETAILS — street address, city, coordinates
        // getHotelDetails is the only Booking.com endpoint that returns a real
        // street address; searchHotels only returns city + country code.
        // ====================================================================
        try {
            // getHotelDetails REQUIRES arrival_date, departure_date AND adults — omitting them
            // causes the API to return {"arrival_date":"Invalid value"} validation errors.
            $hotelDetailsResp = $rapidGet('/api/v1/hotels/getHotelDetails', [
                'hotel_id'       => $hotelId,
                'arrival_date'   => $checkinFormatted,
                'departure_date' => $checkoutFormatted,
                'adults'         => $adults,
                'room_qty'       => $rooms,
                'languagecode'   => 'en-us',
            ]);
            $hotelDetailsData = $hotelDetailsResp['data'] ?? [];

            // ADDRESS — prefer street address from details over the search-level passed value
            if (!empty($hotelDetailsData['address'])) {
                $hotelMetadata['address'] = trim($hotelDetailsData['address']);
            }
            if (!empty($hotelDetailsData['city'])) {
                $hotelMetadata['city'] = trim($hotelDetailsData['city']);
            }
            if (!empty($hotelDetailsData['countrycode'])) {
                $hotelMetadata['country'] = strtoupper(trim($hotelDetailsData['countrycode']));
            }
            if (isset($hotelDetailsData['latitude'])) {
                $hotelMetadata['latitude'] = (float)$hotelDetailsData['latitude'];
            }
            if (isset($hotelDetailsData['longitude'])) {
                $hotelMetadata['longitude'] = (float)$hotelDetailsData['longitude'];
            }
            if (!empty($hotelDetailsData['review_score'])) {
                $hotelMetadata['rating'] = (float)$hotelDetailsData['review_score'];
            }
            if (!empty($hotelDetailsData['review_score_word'])) {
                $hotelMetadata['rating_word'] = $hotelDetailsData['review_score_word'];
            }
            if (!empty($hotelDetailsData['review_nr'])) {
                $hotelMetadata['review_count'] = (int)$hotelDetailsData['review_nr'];
            }
            if (!empty($hotelDetailsData['accuratePropertyClass'])) {
                $hotelMetadata['stars'] = (int)$hotelDetailsData['accuratePropertyClass'];
            }
            // HOTEL NAME — only override if session name is missing
            if (empty($hotelName) && !empty($hotelDetailsData['hotel_name'])) {
                $hotelName = trim($hotelDetailsData['hotel_name']);
            }
        } catch (Exception $e) {
            error_log(sprintf(
                '[BOOKING DETAILS] getHotelDetails failed - hotel_id: %s, error: %s',
                $hotelId,
                $e->getMessage()
            ));
            // CONTINUE WITH PASSED VALUES FROM SEARCH RESULTS
        }

        // ====================================================================
        // STEP 1B: GET ROOM DETAILS + AVAILABILITY
        // ====================================================================
        $roomParams = [
            'hotel_id'         => $hotelId,
            'arrival_date'     => $checkinFormatted,
            'departure_date'   => $checkoutFormatted,
            'adults'           => $adults,
            'room_qty'         => $rooms,
            'units'            => 'metric',
            'temperature_unit' => 'c',
            'languagecode'     => 'en-us',
            'currency_code'    => $currency,
            'location'         => 'US',
        ];

        if ($children > 0) {
            $roomParams['children_age'] = implode(',', array_fill(0, $children, 5));
        }

        $roomResp = $rapidGet('/api/v1/hotels/getRoomListWithAvailability', $roomParams);
        $roomData = $roomResp['data'] ?? [];
        $blockData = $roomData['block'] ?? [];

        // ====================================================================
        // STEP 2: GET HOTEL PHOTOS
        // ====================================================================
        $photosResp = $rapidGet('/api/v1/hotels/getHotelPhotos', [
            'hotel_id' => $hotelId,
        ]);
        $photosData = $photosResp['data'] ?? [];
        $photoUrls = [];
        
        if (is_array($photosData)) {
            if (isset($photosData['photos']) && is_array($photosData['photos'])) {
                $photoUrls = array_slice($photosData['photos'], 0, 10);
            } else if (is_array($photosData) && !empty($photosData)) {
                // If photos are directly in data array
                foreach ($photosData as $item) {
                    if (is_string($item)) {
                        $photoUrls[] = $item;
                    } else if (is_array($item) && isset($item['url'])) {
                        $photoUrls[] = $item['url'];
                    }
                    if (count($photoUrls) >= 10) break;
                }
            }
        }

        // ====================================================================
        // STEP 3: GET DESCRIPTION & INFO
        // ====================================================================
        $description = '';
        $amenities = [];
        try {
            $descResp = $rapidGet('/api/v1/hotels/getDescriptionAndInfo', [
                'hotel_id'     => $hotelId,
                'languagecode' => 'en-us',
            ]);
            
            $descData = $descResp['data'] ?? [];
            
            // getDescriptionAndInfo returns an ARRAY of description objects
            // Find the best description (type 6 is main description)
            if (is_array($descData)) {
                foreach ($descData as $descItem) {
                    if (is_array($descItem)) {
                        // Prefer main description (type 6)
                        if (($descItem['descriptiontype_id'] ?? 0) == 6 && !empty($descItem['description'])) {
                            $description = $descItem['description'];
                            break;
                        }
                        // Fall back to any description
                        if (empty($description) && !empty($descItem['description'])) {
                            $description = $descItem['description'];
                        }
                    }
                }
            }
            
        } catch (Exception $e) {
            // Continue without description
        }

        // ====================================================================
        // STEP 4: GET HOTEL POLICIES
        // ====================================================================
        $cancellationPolicy = '';
        $checkinInfo = [];
        $checkoutInfo = [];
        try {
            $policiesResp = $rapidGet('/api/v1/hotels/getHotelPolicies', [
                'hotel_id'     => $hotelId,
                'languagecode' => 'en-us',
            ]);
            $policiesData = $policiesResp['data'] ?? [];
            $cancellationPolicy = $policiesData['cancellation'] ?? '';
            $checkinInfo = $policiesData['checkin'] ?? [];
            $checkoutInfo = $policiesData['checkout'] ?? [];
        } catch (Exception $e) {
            error_log('[BOOKING DETAILS] getHotelPolicies failed: ' . $e->getMessage());
            // Continue without policies
        }

        // ====================================================================
        // STEP 5: FORMAT ROOMS FOR RESPONSE
        // ====================================================================
        $formattedRooms = [];

        if (!empty($blockData) && is_array($blockData)) {
            foreach ($blockData as $idx => $block) {
                if (!is_array($block)) continue;

                // Extract room name
                $roomName = $block['name'] ?? $block['name_without_policy'] ?? 'Room ' . ($idx + 1);

                // Extract pricing
                $priceData = $block['product_price_breakdown'] ?? [];
                $grossPrice = $priceData['gross_amount'] ?? [];
                $price = isset($grossPrice['value']) ? (float)$grossPrice['value'] : 0;
                $priceCurrency = $grossPrice['currency'] ?? $currency;

                if ($price <= 0) continue;

                // Extract occupancy info
                $fitOccupancy = $block['fit_occupancy'] ?? [];
                $roomAdults   = (int)($fitOccupancy['nr_adults'] ?? $adults);
                $roomChildren = (int)($fitOccupancy['nr_children'] ?? $children);

                // Extract cancellation policy
                $blockText = $block['block_text'] ?? [];
                $policies = $blockText['policies'] ?? [];
                $roomCancellation = '';
                foreach ($policies as $policy) {
                    if (($policy['class'] ?? '') === 'POLICY_CANCELLATION') {
                        $roomCancellation = $policy['content'] ?? '';
                        break;
                    }
                }

                // Breakfast info
                $breakfastIncluded = (bool)($block['breakfast_included'] ?? false);

                // Extract amenities/features from bundle_extras
                $bundleExtras = $block['bundle_extras'] ?? [];
                $amenities = [];
                if (!empty($bundleExtras['benefits']) && is_array($bundleExtras['benefits'])) {
                    foreach ($bundleExtras['benefits'] as $benefit) {
                        if (!empty($benefit['title'])) {
                            $amenities[] = $benefit['title'];
                        }
                    }
                }

                $formattedRooms[] = [
                    'id'                  => $idx,
                    'name'                => $roomName,
                    'price'               => round($price, 2),
                    'price_per_night'     => round($price, 2),
                    'currency'            => $priceCurrency,
                    'occupancy'           => [
                        'adults'   => $roomAdults,
                        'children' => $roomChildren,
                    ],
                    'breakfast_included'  => $breakfastIncluded,
                    'cancellation_policy' => $roomCancellation,
                    'amenities'           => $amenities,
                    'free_cancellation'   => strpos(strtolower($roomCancellation), 'free') !== false,
                ];
            }
        }

        // ====================================================================
        // STEP 6: BUILD FULL HOTEL DETAILS RESPONSE
        // Combine all data sources into complete hotel object
        // ====================================================================
        $mainImage = !empty($photoUrls) ? $photoUrls[0] : null;
        
        // Use passed amenities if available, otherwise use extracted ones
        if (!empty($passedAmenities) && is_array($passedAmenities)) {
            $amenities = $passedAmenities;
        } else if (empty($amenities) && !empty($formattedRooms)) {
            // Fall back to extracting from rooms
            $amenitySet = [];
            foreach ($formattedRooms as $room) {
                if (!empty($room['amenities']) && is_array($room['amenities'])) {
                    foreach ($room['amenities'] as $amenity) {
                        $amenitySet[$amenity] = true;
                    }
                }
            }
            $amenities = array_keys($amenitySet);
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'id'                  => (string)$hotelId,
                'hotel_id'            => (string)$hotelId,
                'name'                => $hotelName,
                'stars'               => $hotelMetadata['stars'],
                'rating'              => $hotelMetadata['rating'],
                'rating_word'         => $hotelMetadata['rating_word'],
                'review_count'        => $hotelMetadata['review_count'],
                'address'             => $hotelMetadata['address'],
                'city'                => $hotelMetadata['city'],
                'country'             => $hotelMetadata['country'] ?: 'COM',
                'location'            => implode(', ', array_filter([$hotelMetadata['city'], $hotelMetadata['country']])),
                'latitude'            => $hotelMetadata['latitude'],
                'longitude'           => $hotelMetadata['longitude'],
                'images'              => $photoUrls,
                'image'               => $mainImage,
                'description'         => trim((string)$description),
                'amenities'           => $amenities,
                'cancellation_policy' => $cancellationPolicy,
                'checkin'             => $checkinInfo,
                'checkout'            => $checkoutInfo,
                'supplier'            => 'BOOKING',
                'rooms'               => $formattedRooms,
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

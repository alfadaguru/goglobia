<?php

/**
 * ============================================================================
 * AMADEUS HOTEL DETAILS API ENDPOINT
 * ============================================================================
 * 
 * PURPOSE:
 * Fetch comprehensive hotel information from Amadeus Hotel API
 * including images, amenities, room types, and detailed hotel metadata.
 * 
 * ENDPOINT: POST /stays/amadeus/details
 * 
 * ============================================================================
 * REQUEST PARAMETERS
 * ============================================================================
 * 
 * hotel_id (string)        - Amadeus hotel ID (e.g., "BWLON015")
 * 
 * supplier (string)        - Always "amadeus"
 * 
 * checkin (string)         - Check-in date in DD-MM-YYYY format
 * 
 * checkout (string)        - Check-out date in DD-MM-YYYY format
 * 
 * nationality (string)     - Guest nationality ISO code (e.g., "US", "GB")
 * 
 * rooms (array)            - Room configuration array
 *                            Format: [{"adults":2,"children":1,"childAges":[5]}]
 * 
 * ============================================================================
 */

$router->post('/stays/amadeus/details', function() use ($db) {

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
        $nationality = $input['nationality'] ?? '';
        $rooms = $input['rooms'] ?? [];

        // Validate required parameters
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

        // Format dates (DD-MM-YYYY to YYYY-MM-DD)
        $checkinParts = explode('-', $checkin);
        $checkoutParts = explode('-', $checkout);
        $checkinISO = "{$checkinParts[2]}-{$checkinParts[1]}-{$checkinParts[0]}";
        $checkoutISO = "{$checkoutParts[2]}-{$checkoutParts[1]}-{$checkoutParts[0]}";

        // Calculate number of nights
        $nights = max(1, (new DateTime($checkinISO))->diff(new DateTime($checkoutISO))->days);

        // Extract adults from rooms array
        $adults = 2; // Default
        if (!empty($rooms) && is_array($rooms)) {
            $adults = (int)($rooms[0]['adults'] ?? 2);
        }

        // Get module configuration
        $module = $db->get('modules', '*', ['name' => 'amadeus', 'type' => 'stays']);
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
        // STEP 2: GET HOTEL OFFERS (includes hotel details)
        // ========================================
        $ch = curl_init($baseUrl . '/v3/shopping/hotel-offers?' . http_build_query([
            'hotelIds' => $hotelId,
            'checkInDate' => $checkinISO,
            'checkOutDate' => $checkoutISO,
            'adults' => $adults
        ]));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]
        ]);
        $offersResp = curl_exec($ch);
        $offersData = json_decode($offersResp, true);

        if (!isset($offersData['data'][0])) {
            echo json_encode([
                'success' => false,
                'message' => 'Hotel not found or no availability',
                'hotel_id' => $hotelId
            ]);
            exit;
        }

        $hotelData = $offersData['data'][0]['hotel'] ?? [];
        $offers = $offersData['data'][0]['offers'] ?? [];

        // ========================================
        // EXTRACT HOTEL INFORMATION
        // ========================================
        $hotelName = $hotelData['name'] ?? 'Hotel';
        $hotelAddress = $hotelData['address']['lines'][0] ?? '';
        $hotelCity = $hotelData['address']['cityName'] ?? '';
        $hotelCountry = $hotelData['address']['countryCode'] ?? '';
        $latitude = (float)($hotelData['latitude'] ?? 0);
        $longitude = (float)($hotelData['longitude'] ?? 0);
        
        // Build location string
        $location = $hotelCity;
        if ($hotelCountry) {
            $location .= ($location ? ', ' : '') . $hotelCountry;
        }

        // Extract star rating
        $stars = 3; // Default
        if (!empty($hotelData['rating'])) {
            // Amadeus rating can be numeric or text like "3 STARS"
            if (is_numeric($hotelData['rating'])) {
                $stars = max(1, min(5, (int)$hotelData['rating']));
            } else {
                preg_match('/(\d+)/', $hotelData['rating'], $matches);
                if (!empty($matches[1])) {
                    $stars = max(1, min(5, (int)$matches[1]));
                }
            }
        }

        // Default rating (Amadeus doesn't provide guest ratings)
        $rating = ($stars >= 4) ? 4.0 : 3.5;

        // ========================================
        // EXTRACT HOTEL IMAGES
        // ========================================
        $hotelImages = [];
        $defaultImage = 'https://via.placeholder.com/800x600?text=No+Image';
        
        if (!empty($hotelData['media'])) {
            foreach ($hotelData['media'] as $media) {
                if (isset($media['uri'])) {
                    $hotelImages[] = $media['uri'];
                }
            }
        }
        
        if (empty($hotelImages)) {
            $hotelImages[] = $defaultImage;
        }

        // ========================================
        // EXTRACT AMENITIES
        // ========================================
        $amenities = [];
        if (!empty($hotelData['amenities'])) {
            foreach ($hotelData['amenities'] as $amenity) {
                if (is_string($amenity)) {
                    $amenities[] = ucwords(strtolower(str_replace('_', ' ', $amenity)));
                } elseif (isset($amenity['description'])) {
                    $amenities[] = $amenity['description'];
                }
            }
        }
        $amenities = array_values(array_unique($amenities));

        // ========================================
        // EXTRACT DESCRIPTION
        // ========================================
        $description = $hotelData['description']['text'] ?? 
                       "Located in {$location}, this {$stars}-star hotel offers comfortable accommodation with modern amenities.";

        // ========================================
        // CANCELLATION & PRIVACY POLICIES
        // ========================================
        $cancellationPolicy = 'Cancellation policies vary by rate and are shown during booking. Please review the specific cancellation terms for your selected room rate.';
        $privacyPolicy = 'Your personal information is handled in accordance with our privacy policy and GDPR regulations. We do not share your data with third parties without consent.';

        // Check if offers have cancellation info
        if (!empty($offers[0]['policies']['cancellation'])) {
            $cancelPolicy = $offers[0]['policies']['cancellation'];
            if (isset($cancelPolicy['description']['text'])) {
                $cancellationPolicy = $cancelPolicy['description']['text'];
            } elseif (isset($cancelPolicy['type'])) {
                $cancellationPolicy = "Cancellation type: " . $cancelPolicy['type'];
                if (isset($cancelPolicy['deadline'])) {
                    $cancellationPolicy .= ". Free cancellation until: " . $cancelPolicy['deadline'];
                }
            }
        }

        // ========================================
        // BUILD RESPONSE (matching Hotelbeds format)
        // ========================================
        $response = [
            'success' => true,
            'data' => [
                'id' => $hotelId,
                'name' => $hotelName,
                'description' => $description,
                'address' => $hotelAddress,
                'location' => $location,
                'cancellation_policy' => $cancellationPolicy,
                'privacy_policy' => $privacyPolicy,
                'stars' => $stars,
                'rating' => $rating,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'images' => $hotelImages,
                'amenities' => $amenities,
                'rooms' => [], // Rooms are fetched separately via /rooms endpoint
                'supplier' => $supplier,
                'checkin' => $checkin,
                'checkout' => $checkout
            ]
        ];

        echo json_encode($response);

    } catch (Exception $e) {
        // Log errors only
        @mkdir($logsPath, 0755, true);
        @file_put_contents($logsPath . '/Error_Details_' . date('Ymd_His') . '.txt', 
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

<?php

/**
 * ============================================================================
 * HOTELBEDS HOTEL DETAILS API ENDPOINT
 * ============================================================================
 * 
 * PURPOSE:
 * Fetch comprehensive hotel information from Hotelbeds imported database
 * including images, amenities, room types, and detailed hotel metadata.
 * 
 * ENDPOINT: POST /stays/hotelbeds/details
 * 
 * ============================================================================
 * REQUEST PARAMETERS
 * ============================================================================
 * 
 * hotel_id (string|int)    - Hotel code from Hotelbeds (e.g., "123456")
 *                            Note: Can be numeric ID or hotel_code depending on frontend
 * 
 * supplier (string)        - Always "hotelbeds" (used for consistency with manual hotels)
 * 
 * checkin (string)         - Check-in date in DD-MM-YYYY format
 * 
 * checkout (string)        - Check-out date in DD-MM-YYYY format
 * 
 * nationality (string)     - Guest nationality ISO code (e.g., "US", "GB")
 * 
 * rooms (array)            - Room configuration array (optional for details page)
 *                            Format: [{"adults":2,"children":1,"childAges":[5]}]
 * 
 * ============================================================================
 * RESPONSE FORMAT
 * ============================================================================
 * 
 * {
 *   "success": true,
 *   "data": {
 *     "id": "123456",                           // Hotel code
 *     "name": "Hilton Barcelona",               // Hotel name
 *     "description": "Luxury hotel in center",  // Hotel description
 *     "address": "Av. Diagonal 589-591",        // Full address
 *     "location": "Barcelona, Spain",           // City, Country
 *     "accommodation_type": "Hotel",            // Accommodation type name
 *     "cancellation_policy": "...",             // Cancellation terms
 *     "privacy_policy": "...",                  // Privacy policy text
 *     "stars": 5,                               // Star rating (1-5)
 *     "rating": 4.5,                            // Guest rating (0-5)
 *     "latitude": 41.3851,                      // Geo coordinates
 *     "longitude": 2.1734,
 *     "images": [                               // Hotel images array
 *       "https://example.com/image1.jpg",
 *       "https://example.com/image2.jpg"
 *     ],
 *     "amenities": [                            // Hotel facilities/amenities
 *       "Wi-Fi",
 *       "Swimming Pool",
 *       "Restaurant"
 *     ],
 *     "rooms": [],                              // Room types (empty on details page)
 *     "supplier": "hotelbeds",
 *     "checkin": "15-12-2025",
 *     "checkout": "18-12-2025"
 *   }
 * }
 * 
 * ============================================================================
 * DATABASE STRUCTURE (Hotelbeds separate database)
 * ============================================================================
 * 
 * hotelbeds_hotels table:
 * - hotel_code (VARCHAR) - Primary identifier
 * - name (VARCHAR) - Hotel name
 * - description (TEXT) - Hotel description
 * - address (TEXT) - Full address
 * - city (VARCHAR) - City name
 * - country_code (VARCHAR) - ISO country code
 * - category_code (VARCHAR) - Star rating code
 * - accommodation_type (VARCHAR) - Accommodation type code (H, A, AH, etc.)
 * - ranking (INT) - Hotel ranking score
 * - latitude (DECIMAL) - Geo latitude
 * - longitude (DECIMAL) - Geo longitude
 * 
 * hotelbeds_hotel_images table:
 * - hotel_code (VARCHAR) - FK to hotelbeds_hotels
 * - image_url (VARCHAR) - Full image URL
 * - order_num (INT) - Display order
 * 
 * hotelbeds_amenities table:
 * - hotel_code (VARCHAR) - FK to hotelbeds_hotels
 * - facility_code (INT) - FK to hotelbeds_facilities
 * 
 * hotelbeds_facilities table:
 * - code (INT) - Facility ID
 * - name (VARCHAR) - Facility name (e.g., "Wi-Fi", "Pool")
 * 
 * ============================================================================
 * DIFFERENCES FROM MANUAL HOTELS API
 * ============================================================================
 * 
 * 1. DATABASE CONNECTION:
 *    - Manual: Uses main $db connection
 *    - Hotelbeds: Uses separate Hotelbeds database via getHotelbedsDb()
 * 
 * 2. HOTEL IDENTIFIER:
 *    - Manual: Uses numeric `id` from stays table
 *    - Hotelbeds: Uses alphanumeric `hotel_code` from hotelbeds_hotels
 * 
 * 3. IMAGES:
 *    - Manual: JSON stored in stays.img column
 *    - Hotelbeds: Separate hotelbeds_hotel_images table with image_url
 * 
 * 4. AMENITIES:
 *    - Manual: JSON array of IDs in stays.amenity_ids, joined with stays_settings
 *    - Hotelbeds: Separate hotelbeds_amenities table, joined with hotelbeds_facilities
 * 
 * 5. ROOMS:
 *    - Manual: Stored in stays_rooms table with full pricing/options
 *    - Hotelbeds: Basic room types in hotelbeds_hotel_rooms, pricing from API
 * 
 * 6. STAR RATING:
 *    - Manual: Direct stars column (integer)
 *    - Hotelbeds: category_code needs mapping to numeric stars
 * 
 * 7. RATING:
 *    - Manual: Direct rating column (decimal)
 *    - Hotelbeds: Uses ranking score, needs conversion to 0-5 scale
 * 
 * 8. ACCOMMODATION TYPE:
 *    - Manual: Direct property_type column
 *    - Hotelbeds: accommodation_type code needs mapping to readable names
 * 
 * ============================================================================
 */

$router->post('/stays/hotelbeds/details', function() use ($db) {

    // Suppress errors for clean JSON output
    error_reporting(0);
    ini_set('display_errors', 0);

    // Start output buffering
    ob_start();

    // Set JSON header
    header('Content-Type: application/json');

    try {
        // Parse JSON request body
        $input = json_decode(file_get_contents('php://input'), true);

        $hotelId = $input['hotel_id'] ?? '';
        $supplier = $input['supplier'] ?? 'hotelbeds';
        $checkin = $input['checkin'] ?? '';
        $checkout = $input['checkout'] ?? '';
        $nationality = $input['nationality'] ?? '';
        $rooms = $input['rooms'] ?? [];

        // Validate required parameters
        if (empty($hotelId)) {
            echo json_encode([
                'success' => false,
                'message' => 'Missing required parameter: hotel_id',
                'received' => [
                    'hotel_id' => $hotelId,
                    'supplier' => $supplier
                ]
            ]);
            exit;
        }

        // ========================================
        // ACCOMMODATION TYPE MAPPING
        // ========================================
        /**
         * Maps Hotelbeds accommodation type codes to readable names
         * Source: Hotelbeds Content API - Accommodation Types
         */
        function getAccommodationTypeName($code) {
            $types = [
                'H' => 'Hotel',
                'A' => 'Apartment',
                'AH' => 'Aparthotel',
                'AT' => 'Agritourism',
                'BB' => 'Bed and Breakfast',
                'BG' => 'Bungalow',
                'C' => 'Camping',
                'CA' => 'Country House',
                'CH' => 'Chalet',
                'CL' => 'Club',
                'CS' => 'Casino',
                'GH' => 'Guest House',
                'HA' => 'Hostal',
                'HO' => 'Hostel',
                'HR' => 'Hotel Rural',
                'HS' => 'Homestay',
                'M' => 'Motel',
                'P' => 'Pension',
                'R' => 'Resort',
                'TH' => 'Town House',
                'V' => 'Villa',
                'VTV' => 'Vacation Rental',
                'HOTEL' => 'Hotel', // Fallback for full word
                'APARTMENT' => 'Apartment', // Fallback for full word
            ];

            return $types[$code] ?? 'Hotel'; // Default to 'Hotel' if unknown
        }

        // ========================================
        // GET HOTELBEDS DATABASE CONNECTION
        // ========================================
        // Get module configuration from modules table
        $module = $db->get('modules', '*', [
            'name' => 'hotelbeds',
            'type' => 'stays'
        ]);

        if (!$module) {
            echo json_encode([
                'success' => false,
                'message' => 'Hotelbeds module not configured'
            ]);
            exit;
        }

        // Extract database credentials
        $dbHost = $module['host'] ?? 'localhost';
        $dbName = $module['database'] ?? '';
        $dbUser = $module['username'] ?? 'root';
        $dbPass = $module['password'] ?? '';

        if (empty($dbName)) {
            echo json_encode([
                'success' => false,
                'message' => 'Database name not configured'
            ]);
            exit;
        }

        // Create Medoo connection to Hotelbeds database
        try {
            $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
            $hotelbedsPDO = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);

            $hotelbedsDb = new Medoo\Medoo(['type' => 'mysql', 'pdo' => $hotelbedsPDO]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Database connection failed: ' . $e->getMessage()
            ]);
            exit;
        }

        // ========================================
        // FETCH HOTEL FROM DATABASE
        // ========================================
        // Fetch all fields including images TEXT field for fallback
        $hotel = $hotelbedsDb->get('hotelbeds_hotels', '*', [
            'hotel_code' => $hotelId
        ]);

        if (!$hotel) {
            echo json_encode([
                'success' => false,
                'message' => 'Hotel not found',
                'hotel_code' => $hotelId
            ]);
            exit;
        }

        // ========================================
        // FETCH HOTEL IMAGES
        // ========================================
        $hotelImages = [];
        $imageBaseUrl = 'https://photos.hotelbeds.com/giata/bigger/';
        
        // Try to get images from separate images table
        $imageRecords = $hotelbedsDb->select('hotelbeds_hotel_images', ['image_url'], [
            'hotel_code' => $hotelId,
            'ORDER' => ['image_order' => 'ASC']
        ]);

        foreach ($imageRecords as $imgRec) {
            if (!empty($imgRec['image_url'])) {
                // Check if URL is already complete (starts with http)
                if (strpos($imgRec['image_url'], 'http') === 0) {
                    $hotelImages[] = $imgRec['image_url'];
                } else {
                    // Prepend Hotelbeds CDN base URL
                    $hotelImages[] = $imageBaseUrl . $imgRec['image_url'];
                }
            }
        }
        
        // Fallback: If no images in separate table, try images TEXT field
        if (empty($hotelImages) && !empty($hotel['images'])) {
            $imagesData = json_decode($hotel['images'], true);
            if (is_array($imagesData)) {
                foreach ($imagesData as $img) {
                    if (!empty($img['path'])) {
                        // Check if URL is already complete
                        if (strpos($img['path'], 'http') === 0) {
                            $hotelImages[] = $img['path'];
                        } else {
                            $hotelImages[] = $imageBaseUrl . $img['path'];
                        }
                    }
                }
            }
        }

        // ========================================
        // FETCH HOTEL AMENITIES/FACILITIES
        // ========================================
        $amenities = [];
        $amenityRecords = $hotelbedsDb->select('hotelbeds_amenities', [
            '[>]hotelbeds_facilities' => ['facility_code' => 'code']
        ], [
            'hotelbeds_facilities.description'
        ], [
            'hotelbeds_amenities.hotel_code' => $hotelId
        ]);

        foreach ($amenityRecords as $amenity) {
            // Skip amenities with invalid names
            if (!empty($amenity['description']) && $amenity['description'] !== '1') {
                $amenities[] = $amenity['description'];
            }
        }

        // Remove duplicates
        $amenities = array_values(array_unique($amenities));

        // ========================================
        // GET ACCOMMODATION TYPE
        // ========================================
        $accommodationType = 'Hotel'; // Default
        if (!empty($hotel['accommodation_type'])) {
            $accCode = strtoupper(trim((string) $hotel['accommodation_type']));
            // Prefer Content API accommodations table; fall back to hardcoded map
            if (function_exists('hotelbedsLookupAccommodationName')) {
                $fromDb = hotelbedsLookupAccommodationName($hotelbedsDb, $accCode, '');
                $accommodationType = $fromDb !== '' ? $fromDb : getAccommodationTypeName($accCode);
            } else {
                $accommodationType = getAccommodationTypeName($accCode);
            }
        }

        // ========================================
        // CONVERT STAR RATING
        // ========================================
        // Hotelbeds category_code format: "3EST", "4EST", "5EST"
        // Extract numeric star rating
        $stars = 3; // Default
        if (!empty($hotel['category_code'])) {
            preg_match('/(\d+)/', $hotel['category_code'], $matches);
            if (!empty($matches[1])) {
                $stars = max(1, min(5, (int)$matches[1]));
            }
        }

        // ========================================
        // CONVERT RANKING TO RATING
        // ========================================
        // Hotelbeds ranking is typically 0-100 scale
        // Convert to 0-5 rating scale
        $rating = 4.0; // Default
        if (!empty($hotel['ranking'])) {
            $ranking = (int)$hotel['ranking'];
            // Convert 0-100 to 0-5: (ranking / 100) * 5
            $rating = round(($ranking / 100) * 5, 1);
            $rating = max(0, min(5, $rating)); // Clamp between 0-5
        }

        // ========================================
        // BUILD LOCATION + FULL ADDRESS
        // ========================================
        $city = trim((string) ($hotel['city'] ?? ''));
        $postalCode = trim((string) ($hotel['postal_code'] ?? ''));
        $streetAddress = trim((string) ($hotel['address'] ?? ''));
        $countryCode = strtoupper(trim((string) ($hotel['country_code'] ?? '')));
        $countryName = '';
        if ($countryCode !== '') {
            $countryName = (string) ($hotelbedsDb->get('hotelbeds_countries', 'name', [
                'code' => $countryCode
            ]) ?: $countryCode);
        }

        $location = trim(implode(', ', array_filter([$city, $countryName], static function ($part) {
            return $part !== null && trim((string) $part) !== '';
        })));

        $fullAddress = trim(implode(', ', array_filter([
            $streetAddress,
            $postalCode,
            $city,
            $countryName,
        ], static function ($part) {
            return $part !== null && trim((string) $part) !== '';
        })));
        if ($fullAddress === '') {
            $fullAddress = $location;
        }

        // Content API masters (zones, chain, category, segments, issues, terminals, grouped amenities)
        $contentMeta = function_exists('hotelbedsEnrichHotelContentMeta')
            ? hotelbedsEnrichHotelContentMeta($hotelbedsDb, $hotel)
            : [];

        // Prefer zone in location line when available (Booking API parity: zoneName)
        if (!empty($contentMeta['zone_name'])) {
            $locationParts = array_filter([
                $city,
                $contentMeta['zone_name'],
                $contentMeta['destination_name'] ?: null,
                $countryName,
            ], static function ($part) {
                return $part !== null && trim((string) $part) !== '';
            });
            $location = trim(implode(', ', array_unique(array_map('strval', $locationParts))));
        }

        // ========================================
        // FETCH CANCELLATION & PRIVACY POLICIES
        // ========================================
        // Note: Hotelbeds doesn't store these in content API
        // They come from booking API per rate
        // Return generic messages for now
        $cancellationPolicy = 'Cancellation policies vary by rate and are shown during booking. Please review the specific cancellation terms for your selected room rate.';
        $privacyPolicy = 'Your personal information is handled in accordance with our privacy policy and GDPR regulations. We do not share your data with third parties without consent.';

        // ========================================
        // BUILD RESPONSE
        // ========================================
        $response = [
            'success' => true,
            'data' => array_merge([
                'id' => $hotel['hotel_code'],
                'name' => $hotel['name'] ?? 'Hotel',
                'description' => $hotel['description'] ?? 'No description available.',
                'address' => $fullAddress !== '' ? $fullAddress : $streetAddress,
                'street_address' => $streetAddress,
                'postal_code' => $postalCode,
                'city' => $city,
                'country' => $countryName,
                'country_code' => $countryCode,
                'full_address' => $fullAddress,
                'email' => $hotel['email'] ?? '',
                'phone_number' => $hotel['phone_number'] ?? '',
                'location' => $location,
                'accommodation_type' => $accommodationType,
                'cancellation_policy' => $cancellationPolicy,
                'privacy_policy' => $privacyPolicy,
                'stars' => $stars,
                'rating' => $rating,
                'latitude' => !empty($hotel['latitude']) ? floatval($hotel['latitude']) : null,
                'longitude' => !empty($hotel['longitude']) ? floatval($hotel['longitude']) : null,
                'images' => $hotelImages,
                'amenities' => $amenities,
                'rooms' => [], // Rooms are fetched separately via /rooms endpoint
                'supplier' => $supplier,
                'checkin' => $checkin,
                'checkout' => $checkout
            ], $contentMeta)
        ];

        // Prefer grouped amenities names for key amenities when simple list is empty
        if (empty($response['data']['amenities']) && !empty($contentMeta['amenities_detailed'])) {
            $response['data']['amenities'] = array_values(array_unique(array_column($contentMeta['amenities_detailed'], 'name')));
        }

        // Convert paid amenity fees (Content API often EUR) into the guest's display currency.
        // Prefer request body (same as rooms endpoint), then session, then site default.
        $baseCurrencyRow = $db->get('currencies', ['name', 'rate'], ['default' => 1]);
        $baseCurrencyCode = strtoupper(trim((string) ($baseCurrencyRow['name'] ?? 'USD')));
        $requestedCurrency = strtoupper(trim((string) (
            $input['currency']
            ?? $input['display_currency']
            ?? ($_SESSION['app_currency'] ?? '')
        )));
        if ($requestedCurrency === '') {
            $requestedCurrency = $baseCurrencyCode;
        }
        $displayCurrencyRow = $db->get('currencies', ['name', 'rate'], ['name' => $requestedCurrency]) ?: [];
        $displayCurrency = strtoupper(trim((string) ($displayCurrencyRow['name'] ?? $baseCurrencyCode)));
        $displayRate = (float) ($displayCurrencyRow['rate'] ?? ($baseCurrencyRow['rate'] ?? 0));

        if ($displayCurrency !== '' && !empty($response['data']['amenities_detailed'])) {
            foreach ($response['data']['amenities_detailed'] as &$amenityFeeItem) {
                if (empty($amenityFeeItem['paid'])) {
                    continue;
                }
                $feeAmount = $amenityFeeItem['fee_amount'] ?? null;
                $feeCurrency = strtoupper(trim((string) ($amenityFeeItem['fee_currency'] ?? '')));
                if ($feeAmount === null || $feeAmount === '' || !is_numeric($feeAmount) || (float) $feeAmount <= 0) {
                    continue;
                }
                if ($feeCurrency === '' || $feeCurrency === $displayCurrency) {
                    $amenityFeeItem['fee_currency'] = $displayCurrency;
                    $amenityFeeItem['fee_amount'] = round((float) $feeAmount, 2);
                    continue;
                }
                // Match rooms.php: look up rates without status filter so inactive-edge cases still convert
                $fromRateRow = $db->get('currencies', 'rate', ['name' => $feeCurrency]);
                $fromRate = (float) ($fromRateRow ?? 0);
                if ($fromRate > 0 && $displayRate > 0) {
                    $amenityFeeItem['fee_amount'] = round((float) $feeAmount * ($displayRate / $fromRate), 2);
                    $amenityFeeItem['fee_currency'] = $displayCurrency;
                } elseif (function_exists('convertCurrencyAmount')) {
                    try {
                        $amenityFeeItem['fee_amount'] = convertCurrencyAmount($db, (float) $feeAmount, $feeCurrency, $displayCurrency);
                        $amenityFeeItem['fee_currency'] = $displayCurrency;
                    } catch (Throwable $e) {
                        // Keep supplier currency if conversion fails
                    }
                }
            }
            unset($amenityFeeItem);
        }

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
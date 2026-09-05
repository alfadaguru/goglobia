<?php

/**
 * ============================================================================
 * STUBA HOTEL DETAILS API ENDPOINT
 * ============================================================================
 * 
 * PURPOSE:
 * Fetch comprehensive hotel information from Stuba imported database
 * including images, amenities, room types, and detailed hotel metadata.
 * 
 * ENDPOINT: POST /stays/stuba/details
 * 
 * ============================================================================
 * REQUEST PARAMETERS
 * ============================================================================
 * 
 * hotel_id (string|int)    - Hotel ID from Stuba (e.g., "598854278")
 * 
 * supplier (string)        - Always "stuba" (used for consistency)
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
 *     "id": "598854278",                        // Hotel ID
 *     "name": "Jumeirah Beach Hotel",           // Hotel name
 *     "description": "Luxury beachfront hotel", // Hotel description
 *     "address": "Jumeirah Beach Road",         // Full address
 *     "location": "Dubai, United Arab Emirates",// City, Country
 *     "cancellation_policy": "...",             // Cancellation terms
 *     "privacy_policy": "...",                  // Privacy policy text
 *     "stars": 5,                               // Star rating (1-5)
 *     "rating": 5,                              // Guest rating (0-5)
 *     "latitude": 25.1351,                      // Geo coordinates
 *     "longitude": 55.1851,
 *     "images": [                               // Hotel images array (proxied)
 *       "https://yoursite.com/modules/stays/stuba/image-proxy?url=...",
 *       "https://yoursite.com/modules/stays/stuba/image-proxy?url=..."
 *     ],
 *     "amenities": [                            // Hotel facilities/amenities
 *       "Wi-Fi",
 *       "Swimming Pool",
 *       "Restaurant"
 *     ],
 *     "rooms": [],                              // Room types (empty on details page)
 *     "supplier": "stuba",
 *     "checkin": "15-12-2025",
 *     "checkout": "18-12-2025",
 *     "phone": "+971-4-1234567",
 *     "email": "info@hotel.com",
 *     "website": "https://hotel.com"
 *   }
 * }
 * 
 * ============================================================================
 * DATABASE STRUCTURE (Stuba separate database)
 * ============================================================================
 * 
 * stuba_hotels table:
 * - hotel_id (VARCHAR) - Primary identifier
 * - name (VARCHAR) - Hotel name
 * - description (TEXT) - Hotel description
 * - address (TEXT) - Full address
 * - city (VARCHAR) - City name
 * - country_id (INT) - FK to stuba_countries
 * - region_id (INT) - FK to stuba_regions
 * - star_rating (INT) - Star rating (1-5)
 * - rating (DECIMAL) - Guest rating score
 * - latitude (DECIMAL) - Geo latitude
 * - longitude (DECIMAL) - Geo longitude
 * - phone (VARCHAR) - Contact phone
 * - email (VARCHAR) - Contact email
 * - website (VARCHAR) - Hotel website
 * 
 * stuba_hotel_images table:
 * - hotel_id (VARCHAR) - FK to stuba_hotels
 * - image_url (VARCHAR) - Image path (relative or full URL)
 * - is_primary (TINYINT) - Primary image flag
 * - image_order (INT) - Display order
 * - image_type (VARCHAR) - Image type/category
 * - caption (TEXT) - Image caption
 * 
 * stuba_hotel_amenities table:
 * - hotel_id (VARCHAR) - FK to stuba_hotels
 * - amenity_id (INT) - FK to stuba_amenities
 * 
 * stuba_amenities table:
 * - id (INT) - Amenity ID
 * - name (VARCHAR) - Amenity name (e.g., "Wi-Fi", "Pool")
 * - description (TEXT) - Amenity description
 * 
 * stuba_countries table:
 * - id (INT) - Country ID
 * - name (VARCHAR) - Country name
 * - code (VARCHAR) - ISO country code
 * 
 * stuba_regions table:
 * - id (INT) - Region ID
 * - name (VARCHAR) - Region name
 * - country_id (INT) - FK to stuba_countries
 * 
 * ============================================================================
 * IMAGE PROXYING (CORS FIX)
 * ============================================================================
 * 
 * Stuba's content.stuba.com CDN blocks cross-origin requests (ERR_BLOCKED_BY_ORB).
 * Solution: Proxy all Stuba images through /modules/stays/stuba/image-proxy.php
 * 
 * This endpoint:
 * 1. Detects content.stuba.com URLs
 * 2. Wraps them in proxy URL: root + 'modules/stays/stuba/image-proxy?url=' + urlencode(url)
 * 3. Browser requests from YOUR domain (no CORS issues)
 * 4. Your server fetches from Stuba and adds CORS headers
 * 5. Images display successfully
 * 
 * ============================================================================
 */

$router->post('/stays/stuba/details', function() use ($db) {

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
        $supplier = $input['supplier'] ?? 'stuba';
        $checkin = $input['checkin'] ?? '';
        $checkout = $input['checkout'] ?? '';
        $nationality = $input['nationality'] ?? '';
        $rooms = $input['rooms'] ?? [];

        if (strpos($hotelId, 'stuba_') === 0) {
            $hotelId = substr($hotelId, 6); // Remove "stuba_" prefix (6 characters)
        }

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
        // GET STUBA DATABASE CONNECTION
        // ========================================
        // Get module configuration from modules table
        $module = $db->get('modules', '*', [
            'name' => 'stuba',
            'type' => 'stays'
        ]);

        if (!$module) {
            echo json_encode([
                'success' => false,
                'message' => 'Stuba module not configured'
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

        // Create Medoo connection to Stuba database
        try {
            $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
            $stubaPDO = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);

            $stubaDb = new Medoo\Medoo(['type' => 'mysql', 'pdo' => $stubaPDO]);
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
        
        $hotel = $stubaDb->get('stuba_hotels', '*', [
            'hotel_id' => $hotelId
        ]);
        
        if (!$hotel) {
            echo json_encode([
                'success' => false,
                'message' => 'Hotel not found',
                'hotel_id' => $hotelId
            ]);
            exit;
        }

        // ========================================
        // FETCH HOTEL IMAGES (WITH LOCAL CACHING)
        // ========================================
        $hotelImages = [];

        // Get images from separate images table
        $imageRecords = $stubaDb->select('stuba_hotel_images', [
            'image_url',
            'is_primary',
            'image_type',
            'caption'
        ], [
            'hotel_id' => $hotelId,
            'ORDER' => ['is_primary' => 'DESC', 'image_order' => 'ASC']
        ]);

        foreach ($imageRecords as $imgRec) {
            if (!empty($imgRec['image_url'])) {
                // Transform Stuba URL to Azure CDN format (same as search API)
                $imageUrl = "https://hotelcontent-c4e7fhcwdeguhbgk.a03.azurefd.net/rxlimages%2F" . 
                            str_replace('RXLStagingImages', 'RXLImages', 
                            str_replace('https://content.stuba.com/', '', $imgRec['image_url']));
                
                // Cache the image locally and get local URL
                $cachedImageUrl = cacheImage($imageUrl, $hotelId, '');
                $hotelImages[] = $cachedImageUrl;
            }
        }

        // ========================================
        // FETCH HOTEL AMENITIES/FACILITIES
        // ========================================
        $amenities = [];
        
        // Join hotel_amenities with amenities table
        $amenities = [];

        // Join hotel_amenities with amenities table
        $amenityRecords = $stubaDb->select('stuba_hotel_amenities', [
            '[>]stuba_amenities' => ['amenity_id' => 'id']
        ], [
            'stuba_amenities.amenity_name'
        ], [
            'stuba_hotel_amenities.hotel_id' => $hotelId
        ]);

        foreach ($amenityRecords as $amenity) {
            if (!empty($amenity['amenity_name'])) {
                $amenities[] = $amenity['amenity_name'];
            }
        }

        // ========================================
        // FALLBACK: Show common amenities if none found
        // ========================================
        if (count($amenities) < 10) {
            // Fetch top 10 most common hotel amenities
            $commonAmenities = $stubaDb->select('stuba_amenities', [
                'amenity_name'
            ], [
                'LIMIT' => 10,
                'ORDER' => ['id' => 'ASC'] // Or use frequency/popularity if you have that column
            ]);
            
            foreach ($commonAmenities as $common) {
                if (!empty($common['amenity_name']) && !in_array($common['amenity_name'], $amenities)) {
                    $amenities[] = $common['amenity_name'];
                }
                
                // Stop when we have 10 amenities
                if (count($amenities) >= 10) {
                    break;
                }
            }
        }

        // Remove duplicates (just in case)
        $amenities = array_values(array_unique($amenities));

        // ========================================
        // GET STAR RATING
        // ========================================
        // Stuba stores star rating directly as integer (1-5)
        $stars = !empty($hotel['star_rating']) ? max(1, min(5, (int)$hotel['star_rating'])) : 3;

        // ========================================
        // GET GUEST RATING
        // ========================================
        // Stuba stores rating directly (0-5 scale)
        $rating = !empty($hotel['rating']) ? max(0, min(5, (float)$hotel['rating'])) : 0;

        // ========================================
        // BUILD LOCATION STRING
        // ========================================
        $location = '';
        
        // Get city name
        if (!empty($hotel['city'])) {
            $location = $hotel['city'];
        }
        
        // Get country name from stuba_countries table
        if (!empty($hotel['country_id'])) {
            $country = $stubaDb->get('stuba_countries', 'name', [
                'id' => $hotel['country_id']
            ]);
            if ($country) {
                $location .= ($location ? ', ' : '') . $country;
            }
        }
        
        // If location is still empty, use region
        if (empty($location) && !empty($hotel['region_id'])) {
            $region = $stubaDb->get('stuba_regions', 'name', [
                'id' => $hotel['region_id']
            ]);
            if ($region) {
                $location = $region;
            }
        }

        // ========================================
        // FETCH CANCELLATION & PRIVACY POLICIES
        // ========================================
        // Note: Stuba policies come from booking API per rate
        // Return generic messages for details page
        $cancellationPolicy = 'Cancellation policies vary by rate and are shown during booking. Please review the specific cancellation terms for your selected room rate before confirming your reservation.';
        $privacyPolicy = 'Your personal information is handled in accordance with our privacy policy and data protection regulations. We do not share your data with third parties without your explicit consent.';

        // ========================================
        // BUILD RESPONSE
        // ========================================
        $response = [
            'success' => true,
            'data' => [
                'id' => $hotel['hotel_id'],
                'name' => $hotel['hotel_name'] ?? 'Hotel',
                'description' => $hotel['description'] ?? 'No description available.',
                'address' => $hotel['address'] ?? '',
                'location' => $location,
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
                'checkout' => $checkout,
                'phone' => $hotel['phone'] ?? '',
                'email' => $hotel['email'] ?? '',
                'website' => $hotel['website'] ?? ''
            ]
        ];

        // Clear buffer and output JSON
        ob_clean();
        echo json_encode($response);
        ob_end_flush();

    } catch (Exception $e) {
        ob_clean();
        // §16: log detail server-side; never return the stack trace to the client.
        error_log('STUBA DETAILS ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        echo json_encode([
            'success' => false,
            'message' => 'Server error while loading hotel details.'
        ]);
        ob_end_flush();
    }

});
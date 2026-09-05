<?php

$router->post('stays/travelport/search', function() use ($db) {
    // search_guard_v2: session lock release + configurable timeouts for long supplier requests
    @set_time_limit(30);
    $timeoutConfig = function_exists('supplier_timeout_config') ? supplier_timeout_config() : [
        'connect' => defined('SUPPLIER_CONNECT_TIMEOUT') ? (int) SUPPLIER_CONNECT_TIMEOUT : 10,
        'request' => defined('SUPPLIER_REQUEST_TIMEOUT') ? (int) SUPPLIER_REQUEST_TIMEOUT : 30,
    ];
    $connectTimeout = $timeoutConfig['connect'];
    $requestTimeout = $timeoutConfig['request'];
    $searchSessionData = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $searchSessionData = $_SESSION;
        session_write_close();
        error_log('search_guard: session lock released');
    }

    if (connection_aborted()) {
        error_log('search_guard: request aborted early');
        exit;
    }
    @ob_end_clean();
    header('Content-Type: application/json');

    try {
        // Extract parameters
        $destination = $_POST['destination'] ?? $_POST['city'] ?? '';
        $checkin = $_POST['checkin'] ?? '';
        $checkout = $_POST['checkout'] ?? '';
        $rooms = (int)($_POST['rooms'] ?? 1);
        $adults = (int)($_POST['adults'] ?? 2);
        $children = (int)($_POST['children'] ?? 0);
        $nationality = $_POST['nationality'] ?? 'US';
        $currency = $_POST['currency'] ?? $searchSessionData['app_currency'] ?? 'USD';
        $page = (int)($_POST['page'] ?? 1);
        $perPage = (int)($_POST['per_page'] ?? 25);
        $hotel_name = trim($_POST['hotel_name'] ?? '');

        if (empty($destination) || empty($checkin) || empty($checkout)) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Missing required parameters',
                'results' => []
            ]);
            exit;
        }

        // Convert dates
        $checkinObj = DateTime::createFromFormat('d-m-Y', $checkin);
        $checkoutObj = DateTime::createFromFormat('d-m-Y', $checkout);

        if (!$checkinObj || !$checkoutObj) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid date format',
                'results' => []
            ]);
            exit;
        }

        $checkinFormatted = $checkinObj->format('Y-m-d');
        $checkoutFormatted = $checkoutObj->format('Y-m-d');
        $nights = $checkinObj->diff($checkoutObj)->days;

        // Get module configuration
        $module = $db->get('modules', '*', [
            'name' => 'travelport',
            'type' => 'stays'
        ]);

        if (!$module || empty($module['c1']) || empty($module['c2']) || empty($module['c3'])) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Module not configured',
                'results' => []
            ]);
            exit;
        }

        $username = $module['c1'];
        $password = $module['c2'];
        $branchCode = $module['c3'];
        $environment = $module['env'] ?? 'test';

        $baseUrl = ($environment === 'live')
            ? 'https://emea.universal-api.travelport.com/B2BGateway/connect/uAPI/HotelService'
            : 'https://emea.universal-api.pp.travelport.com/B2BGateway/connect/uAPI/HotelService';

        // Get city code
        $searchLocation = '';
        try {
            $airport = $db->get('flights_airports', 'code', [
                'OR' => [
                    'city[~]' => $destination,
                    'code' => strtoupper($destination)
                ],
                'LIMIT' => 1
            ]);
            if ($airport) {
                $searchLocation = $airport;
            }
        } catch (Exception $e) {
            error_log('Travelport: Database lookup failed: ' . $e->getMessage());
        }

        if (empty($searchLocation)) {
            $destUpper = strtoupper(trim($destination));
            if (preg_match('/^[A-Z]{3}$/', $destUpper)) {
                $searchLocation = $destUpper;
            } else {
                if (preg_match('/\(([A-Z]{3})\)/', $destination, $matches)) {
                    $searchLocation = $matches[1];
                } else {
                    $searchLocation = substr($destUpper, 0, 3);
                }
            }
        }

        if (empty($searchLocation)) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid destination',
                'results' => []
            ]);
            exit;
        }

        // ========================================================================
        // HELPER: Get coordinates from database cache
        // ========================================================================
        function getCoordinatesFromCache($db, $hotelId, $hotelChain) {
            try {
                $cached = $db->get('hotel_coordinates', ['latitude', 'longitude'], [
                    'hotel_id' => $hotelId,
                    'hotel_chain' => $hotelChain,
                    'supplier' => 'travelport'
                ]);

                if ($cached && !empty($cached['latitude']) && !empty($cached['longitude'])) {
                    return [
                        'latitude' => (float)$cached['latitude'],
                        'longitude' => (float)$cached['longitude']
                    ];
                }
            } catch (Exception $e) {
                // Table might not exist, ignore
            }

            return null;
        }

        // ========================================================================
        // HELPER: Save coordinates to database cache
        // ========================================================================
        function saveCoordinatesToCache($db, $hotelId, $hotelChain, $latitude, $longitude) {
            try {
                // Create table if not exists
                $db->query("CREATE TABLE IF NOT EXISTS hotel_coordinates (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    hotel_id VARCHAR(50) NOT NULL,
                    hotel_chain VARCHAR(10) NOT NULL,
                    supplier VARCHAR(50) NOT NULL,
                    latitude DECIMAL(10, 8) NOT NULL,
                    longitude DECIMAL(11, 8) NOT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_hotel (hotel_id, hotel_chain, supplier)
                )");

                // Insert or update
                $db->replace('hotel_coordinates', [
                    'hotel_id' => $hotelId,
                    'hotel_chain' => $hotelChain,
                    'supplier' => 'travelport',
                    'latitude' => $latitude,
                    'longitude' => $longitude
                ]);
            } catch (Exception $e) {
                error_log("Failed to cache coordinates: " . $e->getMessage());
            }
        }

        // ========================================================================
        // HELPER: Geocode address using Nominatim (OpenStreetMap)
        // ========================================================================
        function geocodeAddress($address, $city = '', $country = '') {
            $timeoutConfig = function_exists('supplier_timeout_config') ? supplier_timeout_config() : [
                'connect' => defined('SUPPLIER_CONNECT_TIMEOUT') ? (int) SUPPLIER_CONNECT_TIMEOUT : 10,
                'request' => defined('SUPPLIER_REQUEST_TIMEOUT') ? (int) SUPPLIER_REQUEST_TIMEOUT : 30,
            ];
            $connectTimeout = $timeoutConfig['connect'];
            $requestTimeout = $timeoutConfig['request'];

            // Build search query
            $searchParts = [];
            if (!empty($address)) {
                $searchParts[] = $address;
            }
            if (!empty($city)) {
                $searchParts[] = $city;
            }
            if (!empty($country)) {
                $searchParts[] = $country;
            }

            if (empty($searchParts)) {
                return null;
            }

            $searchQuery = implode(', ', $searchParts);

            // Call Nominatim API (free, no API key required)
            $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
                'q' => $searchQuery,
                'format' => 'json',
                'limit' => 1
            ]);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $requestTimeout,
                CURLOPT_USERAGENT => 'PHPTravels/1.0' // Required by Nominatim
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);

                if (!empty($data) && isset($data[0]['lat']) && isset($data[0]['lon'])) {
                    return [
                        'latitude' => (float)$data[0]['lat'],
                        'longitude' => (float)$data[0]['lon']
                    ];
                }
            }

            return null;
        }

        // ========================================================================
        // FUNCTION: Fetch hotel search results
        // ========================================================================
        function fetchTravelportHotels($baseUrl, $username, $password, $branchCode, $searchLocation,
                                       $checkinFormatted, $checkoutFormatted, $adults, $rooms,
                                       $connectTimeout, $requestTimeout,
                                       $perPage = 200, $nextResultReference = null) {

            $soapRequest = '<?xml version="1.0" encoding="UTF-8"?>
        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:hot="http://www.travelport.com/schema/hotel_v52_0" xmlns:com="http://www.travelport.com/schema/common_v52_0">
        <soapenv:Header/>
        <soapenv:Body>
            <hot:HotelSearchAvailabilityReq AuthorizedBy="user" TargetBranch="' . $branchCode . '" TraceId="search-' . time() . '">';

                    if ($nextResultReference) {
                        $soapRequest .= '
                <com:NextResultReference ProviderCode="1G">' . $nextResultReference . '</com:NextResultReference>';
                    }

                    $soapRequest .= '
                <com:BillingPointOfSaleInfo OriginApplication="UAPI"/>
                <hot:HotelSearchLocation>
                    <hot:HotelLocation Location="' . htmlspecialchars((string)$searchLocation, ENT_XML1|ENT_QUOTES, 'UTF-8') . '"/>
                </hot:HotelSearchLocation>
                <hot:HotelSearchModifiers NumberOfAdults="' . $adults . '" NumberOfRooms="' . $rooms . '" MaxResults="' . $perPage . '"/>
                <hot:HotelStay>
                    <hot:CheckinDate>' . $checkinFormatted . '</hot:CheckinDate>
                    <hot:CheckoutDate>' . $checkoutFormatted . '</hot:CheckoutDate>
                </hot:HotelStay>
            </hot:HotelSearchAvailabilityReq>
        </soapenv:Body>
        </soapenv:Envelope>';

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
                    curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $baseUrl,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $soapRequest,
                        CURLOPT_HTTPHEADER => [
                            'Content-Type: text/xml; charset=utf-8',
                            'SOAPAction: ""',
                            'Accept: text/xml'
                        ],
                        CURLOPT_USERPWD => $username . ':' . $password,
                        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                        CURLOPT_TIMEOUT => $requestTimeout,
                        CURLOPT_CONNECTTIMEOUT => $connectTimeout
                    ]);

                    $apiResponse = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlError = curl_error($ch);

                    if ($curlError) {
                        throw new Exception('API request failed: ' . $curlError);
                    }

                    if ($httpCode !== 200) {
                        throw new Exception('API returned HTTP ' . $httpCode);
                    }

                    return $apiResponse;
                }

                // ========================================================================
                // FUNCTION: Fetch hotel details AND media in parallel using curl_multi
                // ========================================================================
                function fetchHotelDetailsAndMediaParallel($hotels, $baseUrl, $username, $password, $branchCode, $checkinFormatted, $checkoutFormatted, $adults, $rooms) {
            $timeoutConfig = function_exists('supplier_timeout_config') ? supplier_timeout_config() : [
                'connect' => defined('SUPPLIER_CONNECT_TIMEOUT') ? (int) SUPPLIER_CONNECT_TIMEOUT : 10,
                'request' => defined('SUPPLIER_REQUEST_TIMEOUT') ? (int) SUPPLIER_REQUEST_TIMEOUT : 30,
            ];
            $connectTimeout = $timeoutConfig['connect'];
            $requestTimeout = $timeoutConfig['request'];

            if (empty($hotels)) {
                return [];
            }

            $mh = curl_multi_init();
            $handles = [];
            $results = [];

            // Create curl handles for each hotel (BOTH DETAILS AND MEDIA)
            foreach ($hotels as $hotel) {
                $hotelCode = $hotel['id'];
                $hotelChain = $hotel['chain'] ?? '';

                // Skip if no chain code
                if (empty($hotelChain)) {
                    error_log("Warning: No chain code for hotel {$hotelCode}");
                    continue;
                }

                // ===== HotelDetailsReq (for rating, description, amenities, coordinates, etc.) =====
                $detailsSoapRequest = '<?xml version="1.0" encoding="UTF-8"?>
        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                        xmlns:hot="http://www.travelport.com/schema/hotel_v52_0"
                        xmlns:com="http://www.travelport.com/schema/common_v52_0">
        <soapenv:Header/>
        <soapenv:Body>
            <hot:HotelDetailsReq AuthorizedBy="user" TargetBranch="' . $branchCode . '" TraceId="details-' . time() . '-' . htmlspecialchars((string)$hotelCode, ENT_XML1|ENT_QUOTES, 'UTF-8') . '">
                <com:BillingPointOfSaleInfo OriginApplication="UAPI"/>
                <hot:HotelProperty HotelCode="' . htmlspecialchars((string)$hotelCode, ENT_XML1|ENT_QUOTES, 'UTF-8') . '" HotelChain="' . htmlspecialchars((string)$hotelChain, ENT_XML1|ENT_QUOTES, 'UTF-8') . '"/>
                <hot:HotelDetailsModifiers RateRuleDetail="None" NumberOfAdults="' . $adults . '" NumberOfRooms="' . $rooms . '">
                    <hot:HotelStay>
                        <hot:CheckinDate>' . $checkinFormatted . '</hot:CheckinDate>
                        <hot:CheckoutDate>' . $checkoutFormatted . '</hot:CheckoutDate>
                    </hot:HotelStay>
                </hot:HotelDetailsModifiers>
            </hot:HotelDetailsReq>
        </soapenv:Body>
        </soapenv:Envelope>';

                $chDetails = curl_init();
                curl_setopt($chDetails, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
                curl_setopt($chDetails, CURLOPT_TIMEOUT, $requestTimeout);
                curl_setopt_array($chDetails, [
                    CURLOPT_URL => $baseUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $detailsSoapRequest,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: text/xml; charset=utf-8',
                        'SOAPAction: ""',
                        'Accept: text/xml'
                    ],
                    CURLOPT_USERPWD => $username . ':' . $password,
                    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_TIMEOUT => $requestTimeout
                ]);

                curl_multi_add_handle($mh, $chDetails);
                $handles[$hotelCode . '_details'] = $chDetails;

                // ===== HotelMediaLinksReq (for images) =====
                $mediaSoapRequest = '<?xml version="1.0" encoding="UTF-8"?>
        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                        xmlns:hot="http://www.travelport.com/schema/hotel_v52_0"
                        xmlns:com="http://www.travelport.com/schema/common_v52_0">
        <soapenv:Header/>
        <soapenv:Body>
            <hot:HotelMediaLinksReq AuthorizedBy="user" TargetBranch="' . $branchCode . '" TraceId="media-' . time() . '-' . htmlspecialchars((string)$hotelCode, ENT_XML1|ENT_QUOTES, 'UTF-8') . '">
                <com:BillingPointOfSaleInfo OriginApplication="UAPI"/>
                <hot:HotelProperty HotelCode="' . htmlspecialchars((string)$hotelCode, ENT_XML1|ENT_QUOTES, 'UTF-8') . '" HotelChain="' . htmlspecialchars((string)$hotelChain, ENT_XML1|ENT_QUOTES, 'UTF-8') . '"/>
            </hot:HotelMediaLinksReq>
        </soapenv:Body>
        </soapenv:Envelope>';

                $chMedia = curl_init();
                curl_setopt($chMedia, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
                curl_setopt($chMedia, CURLOPT_TIMEOUT, $requestTimeout);
                curl_setopt_array($chMedia, [
                    CURLOPT_URL => $baseUrl,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $mediaSoapRequest,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: text/xml; charset=utf-8',
                        'SOAPAction: ""',
                        'Accept: text/xml'
                    ],
                    CURLOPT_USERPWD => $username . ':' . $password,
                    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_TIMEOUT => $requestTimeout
                ]);

                curl_multi_add_handle($mh, $chMedia);
                $handles[$hotelCode . '_media'] = $chMedia;
            }

            // Execute all requests simultaneously
            $running = null;
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh);
            } while ($running > 0);

            // Collect results
            foreach ($handles as $key => $ch) {
                $response = curl_multi_getcontent($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if ($httpCode === 200 && $response) {
                    $results[$key] = $response;
                }

                curl_multi_remove_handle($mh, $ch);
            }

            curl_multi_close($mh);
            return $results;
        }

        // ========================================================================
        // FUNCTION: Parse hotel details XML with enhanced coordinate extraction
        // ========================================================================
        function parseHotelDetails($xmlString, $hotelCode = '') {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($xmlString);

            if ($xml === false) {
                error_log("Failed to parse details XML for {$hotelCode}");
                return null;
            }

            $xml->registerXPathNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
            $xml->registerXPathNamespace('SOAP', 'http://schemas.xmlsoap.org/soap/envelope/');
            $xml->registerXPathNamespace('hot', 'http://www.travelport.com/schema/hotel_v52_0');
            $xml->registerXPathNamespace('hotel', 'http://www.travelport.com/schema/hotel_v52_0');
            $xml->registerXPathNamespace('com', 'http://www.travelport.com/schema/common_v52_0');
            $xml->registerXPathNamespace('common_v52_0', 'http://www.travelport.com/schema/common_v52_0');

            // Check for SOAP Fault
            $fault = $xml->xpath('//SOAP:Fault');
            if (empty($fault)) {
                $fault = $xml->xpath('//soap:Fault');
            }
            if (!empty($fault)) {
                error_log("SOAP Fault in details for {$hotelCode}");
                return null;
            }

            $details = [];

            // Get hotel property
            $hotelProperty = $xml->xpath('//hotel:RequestedHotelDetails/hotel:HotelProperty');
            if (empty($hotelProperty)) {
                $hotelProperty = $xml->xpath('//hot:RequestedHotelDetails/hot:HotelProperty');
            }

            if (!empty($hotelProperty)) {
                $hotel = $hotelProperty[0];

                // Extract rating from HotelRating element
                $ratingElements = $hotel->xpath('.//hotel:HotelRating/hotel:Rating');
                if (empty($ratingElements)) {
                    $ratingElements = $hotel->xpath('.//hot:HotelRating/hot:Rating');
                }
                if (!empty($ratingElements)) {
                    $rating = (int)((string)$ratingElements[0]);
                    if ($rating > 0) {
                        $details['rating'] = $rating;
                        $details['stars'] = $rating;
                        $details['star_rating'] = $rating;
                    }
                }

                // Address
                $addressElements = $hotel->xpath('.//hotel:PropertyAddress');
                if (empty($addressElements)) {
                    $addressElements = $hotel->xpath('.//hot:PropertyAddress');
                }

                if (!empty($addressElements)) {
                    $addrLines = $addressElements[0]->xpath('.//hotel:Address');
                    if (empty($addrLines)) {
                        $addrLines = $addressElements[0]->xpath('.//hot:Address');
                    }

                    if (!empty($addrLines)) {
                        $addressParts = [];
                        foreach ($addrLines as $line) {
                            $addressParts[] = trim((string)$line);
                        }

                        // Extract city and country from last line
                        if (count($addressParts) > 1) {
                            $lastLine = end($addressParts);
                            $parts = explode(' ', $lastLine);

                            if (count($parts) >= 2) {
                                $details['city'] = $parts[0];
                                $details['country'] = $parts[1];
                                $details['address'] = $addressParts[0];
                            } else {
                                $details['address'] = implode(', ', $addressParts);
                            }
                        } else {
                            $details['address'] = implode(', ', $addressParts);
                        }
                    }
                }

                // Phone
                $phoneElements = $hotel->xpath('.//common_v52_0:PhoneNumber[@Type="Business"]');
                if (empty($phoneElements)) {
                    $phoneElements = $hotel->xpath('.//com:PhoneNumber[@Type="Business"]');
                }
                if (!empty($phoneElements)) {
                    $details['phone'] = (string)($phoneElements[0]['Number'] ?? '');
                }
            }

            // ✅ ENHANCED: Try multiple XPath patterns for coordinates
            $latitude = null;
            $longitude = null;

            // Pattern 1: common_v52_0:CoordinateLocation
            $coords = $xml->xpath('//common_v52_0:CoordinateLocation');
            if (empty($coords)) {
                // Pattern 2: com:CoordinateLocation
                $coords = $xml->xpath('//com:CoordinateLocation');
            }
            if (empty($coords)) {
                // Pattern 3: Without namespace (some responses)
                $coords = $xml->xpath('//CoordinateLocation');
            }

            if (!empty($coords)) {
                $lat = (string)($coords[0]['latitude'] ?? '');
                $lon = (string)($coords[0]['longitude'] ?? '');

                if (!empty($lat) && !empty($lon)) {
                    $latitude = (float)$lat;
                    $longitude = (float)$lon;

                } else {
                    error_log("⚠️ CoordinateLocation element found but lat/lon empty for {$hotelCode}");
                }
            } else {
                error_log("⚠️ No CoordinateLocation found in details response for {$hotelCode}");
            }

            if ($latitude && $longitude) {
                $details['latitude'] = $latitude;
                $details['longitude'] = $longitude;
            }

            // Description
            $descElements = $xml->xpath('//hotel:HotelDetailItem[@Name="Description"]//hotel:Text');
            if (empty($descElements)) {
                $descElements = $xml->xpath('//hot:HotelDetailItem[@Name="Description"]//hot:Text');
            }
            if (!empty($descElements)) {
                $details['description'] = (string)$descElements[0];
            }

            // Amenities
            $amenities = [];
            $amenityElements = $xml->xpath('//hotel:Amenity');
            if (empty($amenityElements)) {
                $amenityElements = $xml->xpath('//hot:Amenity');
            }

            foreach ($amenityElements as $amenity) {
                $code = (string)($amenity['Code'] ?? '');
                $desc = (string)($amenity['Description'] ?? $code);
                if (!empty($code) && !empty($desc)) {
                    $amenities[] = [
                        'name' => $desc,
                        'code' => $code,
                    ];
                }
            }

            // Generic amenities if none provided
            if (empty($amenities)) {
                $amenities = [
                    ['name' => 'Free Wi-Fi', 'code' => 'wifi'],
                    ['name' => '24-Hour Front Desk', 'code' => 'frontdesk'],
                    ['name' => 'Room Service', 'code' => 'roomservice'],
                    ['name' => 'Housekeeping', 'code' => 'housekeeping'],
                    ['name' => 'Concierge Services', 'code' => 'concierge'],
                    ['name' => 'Air Conditioning', 'code' => 'ac'],
                    ['name' => 'Non-Smoking Rooms', 'code' => 'nonsmoking'],
                    ['name' => 'Express Check-In/Check-Out', 'code' => 'checkin_checkout']
                ];
            }

            $details['amenities'] = $amenities;

            return $details;
        }

        // ========================================================================
        // FUNCTION: Parse hotel media XML
        // ========================================================================
        function parseHotelMedia($xmlString, $hotelCode = '') {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($xmlString);

            if ($xml === false) {
                error_log("Failed to parse media XML for {$hotelCode}");
                return [];
            }

            $xml->registerXPathNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
            $xml->registerXPathNamespace('hot', 'http://www.travelport.com/schema/hotel_v52_0');
            $xml->registerXPathNamespace('com', 'http://www.travelport.com/schema/common_v52_0');

            // Check for SOAP Fault
            $fault = $xml->xpath('//soap:Fault');
            if (!empty($fault)) {
                error_log("SOAP Fault in media for {$hotelCode}");
                return [];
            }

            $images = [];
            $maxImages = 30;

            // Get only L (Large) size images to avoid duplicates
            $mediaElements = $xml->xpath('//com:MediaItem[@sizeCode="L"]');

            foreach ($mediaElements as $media) {
                // Stop if we've reached the limit
                if (count($images) >= $maxImages) {
                    break;
                }

                $url = (string)($media['url'] ?? '');

                if (!empty($url)) {
                    $images[] = $url;
                }
            }

            return $images;
        }

        // ========================================================================
        // Fetch all search results with pagination
        // ========================================================================
        $allHotels = [];
        $nextResultReference = null;
        $pageNum = 1;
        $maxPages = 5;

        do {

            $apiResponse = fetchTravelportHotels(
                $baseUrl, $username, $password, $branchCode, $searchLocation,
                $checkinFormatted, $checkoutFormatted, $adults, $rooms,
                $connectTimeout, $requestTimeout,
                $perPage, $nextResultReference
            );

            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($apiResponse);

            if ($xml === false) {
                throw new Exception('Invalid XML response');
            }

            $xml->registerXPathNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
            $xml->registerXPathNamespace('hot', 'http://www.travelport.com/schema/hotel_v52_0');
            $xml->registerXPathNamespace('com', 'http://www.travelport.com/schema/common_v52_0');

            $nextResultReference = null;
            $nextResultElements = $xml->xpath('//com:NextResultReference');
            if (!empty($nextResultElements)) {
                $nextResultReference = (string)$nextResultElements[0];
            }

            $searchResults = $xml->xpath('//hot:HotelSearchResult');
            if (empty($searchResults)) {
                break;
            }

            foreach ($searchResults as $result) {
                $result->registerXPathNamespace('hot', 'http://www.travelport.com/schema/hotel_v52_0');
                $result->registerXPathNamespace('com', 'http://www.travelport.com/schema/common_v52_0');

                $hotel = $result->xpath('.//hot:HotelProperty');
                if (empty($hotel)) continue;
                $hotel = $hotel[0];

                $rateInfo = $result->xpath('.//hot:RateInfo');
                if (empty($rateInfo)) continue;
                $rateInfo = $rateInfo[0];

                $hotelCode = (string)$hotel['HotelCode'];
                $hotelChain = (string)$hotel['HotelChain'];
                $hotelName = (string)$hotel['Name'];
                $minAmount = (string)$rateInfo['MinimumAmount'];

                if ($hotel_name !== '') {
                    $needle = mb_strtolower($hotel_name);
                    $nameMatch = str_contains(mb_strtolower($hotelName), $needle);
                    $idMatch = ($hotelCode === $hotel_name);
                    if (!$nameMatch && !$idMatch) {
                        continue;
                    }
                }

                if (!preg_match('/([A-Z]{3})([0-9.]+)/', $minAmount, $matches)) {
                    continue;
                }

                $apiCurrency = $matches[1];
                $basePrice = floatval($matches[2]);

                // Apply markup. Initialise $markupResult FIRST so that if
                // MARKUP() throws, the fields read later (lines ~787-790) still
                // resolve to the base price instead of an undefined key (which
                // rendered as a 0/null price in results).
                $finalPrice = $basePrice;
                $markupResult = ['price' => $basePrice, 'converted_base_price' => $basePrice];
                try {
                    $markupResult = MARKUP($basePrice, $module, $db, $apiCurrency, $currency);
                    if (isset($markupResult['price']) && $markupResult['price'] > 0) {
                        $finalPrice = $markupResult['price'];
                    }
                } catch (Exception $e) {
                    $markupResult = ['price' => $basePrice, 'converted_base_price' => $basePrice];
                }

                // Basic info from search
                $address = '';
                $addressElements = $hotel->xpath('.//hot:PropertyAddress/hot:Address');
                if (!empty($addressElements)) {
                    $address = (string)$addressElements[0];
                }

                $rating = (int)($hotel['HotelRating'] ?? 0);
                if ($rating == 0) {
                    $ratingElements = $hotel->xpath('.//hot:HotelRating');
                    if (!empty($ratingElements)) {
                        $rating = (int)($ratingElements[0]['Rating'] ?? 0);
                    }
                }

                $allHotels[] = [
                    'id' => $hotelCode,
                    'name' => $hotelName,
                    'chain' => $hotelChain,
                    'stars' => $rating,
                    'star_rating' => $rating,
                    'rating' => $rating,
                    'address' => $address,
                    'city' => $searchLocation,
                    'country' => '',
                    'location' => $address ? $address . ', ' . $searchLocation : $searchLocation,
                    'latitude' => null,
                    'longitude' => null,
                    'images' => [],
                    'image' => '',
                    'price' => round($markupResult['price'], 2),
                    'price_per_night' => round($markupResult['price'] / $nights, 2),
                    'base_price' => round($markupResult['converted_base_price'], 2),
                    'original_price' => round($markupResult['converted_base_price'], 2),
                    'currency' => $currency,
                    'has_available_rooms' => true,
                    'supplier' => 'TRAVELPORT',
                    'original_id' => $hotelCode,
                    'hotel_id' => $hotelCode,
                    'description' => '',
                    'amenities' => [],
                    'phone' => ''
                ];
            }

            $pageNum++;

        } while ($nextResultReference && $pageNum <= $maxPages);


        // ========================================================================
        // Apply client-side pagination
        // ========================================================================
        $total = count($allHotels);
        $totalPages = ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;
        $pagedResults = array_slice($allHotels, $offset, $perPage);

        // ========================================================================
        // REAL-TIME: Fetch details AND media in parallel for current page
        // ========================================================================
        $startTime = microtime(true);


        $responses = fetchHotelDetailsAndMediaParallel(
            $pagedResults,
            $baseUrl, $username, $password, $branchCode,
            $checkinFormatted, $checkoutFormatted, $adults, $rooms
        );

        $enrichmentTime = round(microtime(true) - $startTime, 2);

        // Parse and merge details + media + geocode fallback
        $enrichedCount = 0;
        $geocodedCount = 0;
        $cachedCount = 0;

        foreach ($pagedResults as &$hotel) {
            $hotelCode = $hotel['id'];
            $hotelChain = $hotel['chain'];

            // Parse details response
            if (isset($responses[$hotelCode . '_details'])) {
                $details = parseHotelDetails($responses[$hotelCode . '_details'], $hotelCode);

                if ($details) {
                    // Merge all details into hotel
                    if (isset($details['rating']) && $details['rating'] > 0) {
                        $hotel['rating'] = $details['rating'];
                        $hotel['stars'] = $details['rating'];
                        $hotel['star_rating'] = $details['rating'];
                    }

                    if (isset($details['address']) && !empty($details['address'])) {
                        $hotel['address'] = $details['address'];
                    }

                    if (isset($details['city']) && !empty($details['city'])) {
                        $hotel['city'] = $details['city'];
                    }

                    if (isset($details['country']) && !empty($details['country'])) {
                        $hotel['country'] = $details['country'];
                    }

                    // Coordinates from API
                    if (isset($details['latitude']) && isset($details['longitude'])) {
                        $hotel['latitude'] = $details['latitude'];
                        $hotel['longitude'] = $details['longitude'];

                        // Save to cache
                        saveCoordinatesToCache($db, $hotelCode, $hotelChain, $details['latitude'], $details['longitude']);
                    }

                    if (isset($details['description']) && !empty($details['description'])) {
                        $hotel['description'] = $details['description'];
                    }

                    if (isset($details['amenities']) && !empty($details['amenities'])) {
                        $hotel['amenities'] = $details['amenities'];
                    }

                    if (isset($details['phone']) && !empty($details['phone'])) {
                        $hotel['phone'] = $details['phone'];
                    }

                    $enrichedCount++;
                }
            }

            // ✅ FALLBACK 1: Check cached coordinates
            if (empty($hotel['latitude']) || empty($hotel['longitude'])) {
                $cached = getCoordinatesFromCache($db, $hotelCode, $hotelChain);
                if ($cached) {
                    $hotel['latitude'] = $cached['latitude'];
                    $hotel['longitude'] = $cached['longitude'];
                    $cachedCount++;
                }
            }

            // ✅ FALLBACK 2: Geocode using address if still no coordinates
            if ((empty($hotel['latitude']) || empty($hotel['longitude'])) && !empty($hotel['address'])) {

                $geocoded = geocodeAddress($hotel['address'], $hotel['city'], $hotel['country']);

                if ($geocoded) {
                    $hotel['latitude'] = $geocoded['latitude'];
                    $hotel['longitude'] = $geocoded['longitude'];

                    // Save to cache for future
                    saveCoordinatesToCache($db, $hotelCode, $hotelChain, $geocoded['latitude'], $geocoded['longitude']);

                    $geocodedCount++;
                } else {
                }
            }

            // Parse media response
            if (isset($responses[$hotelCode . '_media'])) {
                $images = parseHotelMedia($responses[$hotelCode . '_media'], $hotelCode);

                if (!empty($images)) {
                    $hotel['images'] = $images;
                    $hotel['image'] = $images[0];
                }
            }

            // Fallback if no image
            if (empty($hotel['images'])) {
                $hotel['images'] = ['https://via.placeholder.com/400x300?text=' . urlencode($hotel['name'])];
                $hotel['image'] = $hotel['images'][0];
            }

            // Update location string
            $locationParts = [];
            if (!empty($hotel['address'])) {
                $locationParts[] = $hotel['address'];
            }
            if (!empty($hotel['city'])) {
                $locationParts[] = $hotel['city'];
            }
            if (!empty($hotel['country'])) {
                $locationParts[] = $hotel['country'];
            }

            if (!empty($locationParts)) {
                $hotel['location'] = implode(', ', $locationParts);
            }

            // Fallback description if empty
            if (empty($hotel['description']) && $hotel['rating'] > 0) {
                $hotel['description'] = $hotel['name'] . ' is a ' . $hotel['rating'] . '-star property' .
                    (!empty($hotel['city']) ? ' located in ' . $hotel['city'] : '') . '. ' .
                    'This hotel offers quality accommodations and services for your stay.';
            }
        }
        unset($hotel);


        // ========================================================================
        // Return enriched response
        // ========================================================================
        echo json_encode([
            'status' => 'success',
            'results' => $pagedResults,
            'total' => $total,
            // Fixed "N stays found" figure for the destination (page-independent).
            'destination_total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'enriched_hotels' => $enrichedCount,
            'cached_coordinates' => $cachedCount,
            'geocoded_coordinates' => $geocodedCount,
            'enrichment_time' => $enrichmentTime . 's',
            'search_params' => [
                'checkin' => $checkin,
                'checkout' => $checkout,
                'rooms' => $rooms,
                'adults' => $adults
            ]
        ]);

    } catch (Exception $e) {
        error_log('Travelport Error: ' . $e->getMessage());
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage(),
            'results' => []
        ]);
    }

    exit;
});
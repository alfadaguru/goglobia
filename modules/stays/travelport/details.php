<?php
/**
 * ============================================================================
 * TRAVELPORT HOTEL DETAILS API ENDPOINT WITH GEOCODING FALLBACK
 * ============================================================================
 * 
 * PURPOSE:
 * Fetch comprehensive hotel information from Travelport API
 * including images, description, amenities, and coordinates.
 * Uses geocoding fallback when Travelport doesn't provide coordinates.
 * 
 * ENDPOINT: POST /stays/travelport/details
 * 
 * ============================================================================
 */

$router->post('stays/travelport/details', function() use ($db) {

    @ob_end_clean();
    header('Content-Type: application/json');

    $detailsRawResponse = null;
    $mediaRawResponse = null;

    try {
        // ========================================
        // EXTRACT REQUEST PARAMETERS
        // ========================================
        $input = json_decode(file_get_contents('php://input'), true);

        $hotelId = $input['hotel_id'] ?? '';
        $hotelChain = $input['hotel_chain'] ?? '';
        $supplier = $input['supplier'] ?? 'travelport';
        $checkin = $input['checkin'] ?? '';
        $checkout = $input['checkout'] ?? '';
        $adults = (int)($input['adults'] ?? 2);
        $children = (int)($input['children'] ?? 0);
        $rooms = (int)($input['rooms'] ?? 1);
        $nationality = $input['nationality'] ?? 'US';
        $currency = $input['currency'] ?? $_SESSION['app_currency'] ?? 'USD';

        // Validate required fields
        if (empty($hotelId)) {
            echo json_encode([
                'success' => false,
                'message' => 'Missing required parameter: hotel_id'
            ]);
            exit;
        }

        if (empty($hotelChain)) {
            echo json_encode([
                'success' => false,
                'message' => 'Missing required parameter: hotel_chain'
            ]);
            exit;
        }

        if (empty($checkin) || empty($checkout)) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'hotel_id' => $hotelId,
                    'nights' => 1,
                    'currency' => $currency,
                    'rooms' => [],
                    'message' => 'Check-in and check-out dates are required'
                ]
            ]);
            exit;
        }

        // Convert dates
        $checkinObj = DateTime::createFromFormat('d-m-Y', $checkin);
        $checkoutObj = DateTime::createFromFormat('d-m-Y', $checkout);

        if (!$checkinObj || !$checkoutObj) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid date format. Use d-m-Y'
            ]);
            exit;
        }

        $checkinFormatted = $checkinObj->format('Y-m-d');
        $checkoutFormatted = $checkoutObj->format('Y-m-d');
        $nights = $checkinObj->diff($checkoutObj)->days;

        // ========================================
        // HELPER FUNCTIONS
        // ========================================
        
        // Get coordinates from cache
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

        // Save coordinates to cache
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
                
                // Insert/update only columns that exist in the current schema.
                $columnsStmt = $db->query("SHOW COLUMNS FROM hotel_coordinates");
                $columns = $columnsStmt ? $columnsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
                $availableColumns = [];
                foreach ($columns as $column) {
                    if (!empty($column['Field'])) {
                        $availableColumns[] = $column['Field'];
                    }
                }

                if (empty($availableColumns)) {
                    return;
                }

                $data = [];

                if (in_array('hotel_id', $availableColumns, true)) {
                    $data['hotel_id'] = $hotelId;
                }
                if (in_array('hotel_chain', $availableColumns, true)) {
                    $data['hotel_chain'] = $hotelChain;
                }
                if (in_array('supplier', $availableColumns, true)) {
                    $data['supplier'] = 'travelport';
                }
                if (in_array('latitude', $availableColumns, true)) {
                    $data['latitude'] = $latitude;
                }
                if (in_array('longitude', $availableColumns, true)) {
                    $data['longitude'] = $longitude;
                }

                if (!isset($data['latitude']) || !isset($data['longitude'])) {
                    return;
                }

                $hasUniqueIdentity = isset($data['hotel_id']) && isset($data['hotel_chain']) && isset($data['supplier']);

                if ($hasUniqueIdentity) {
                    $db->replace('hotel_coordinates', $data);
                } else {
                    $db->insert('hotel_coordinates', $data);
                }
                
            } catch (Exception $e) {
                if (defined('DEBUG_SUPPLIER_WARNINGS') && DEBUG_SUPPLIER_WARNINGS === true) {
                    error_log("Failed to cache coordinates: " . $e->getMessage());
                }
            }
        }

        // Geocode address using Nominatim (OpenStreetMap)
        function geocodeAddress($address, $city = '', $country = '') {
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
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
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

        // ========================================
        // GET MODULE CONFIGURATION
        // ========================================
        $module = $db->get('modules', '*', [
            'name' => 'travelport',
            'type' => 'stays'
        ]);

        if (!$module || empty($module['c1']) || empty($module['c2']) || empty($module['c3'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Travelport module not configured'
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

        // ========================================
        // BUILD BASIC HOTEL DETAILS REQUEST
        // ========================================
        $detailsSoapRequest = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" 
                  xmlns:hot="http://www.travelport.com/schema/hotel_v52_0" 
                  xmlns:com="http://www.travelport.com/schema/common_v52_0">
   <soapenv:Header/>
   <soapenv:Body>
      <hot:HotelDetailsReq AuthorizedBy="user" TargetBranch="' . $branchCode . '" TraceId="details-' . time() . '">
         <com:BillingPointOfSaleInfo OriginApplication="UAPI"/>
         <hot:HotelProperty HotelCode="' . htmlspecialchars((string)$hotelId, ENT_XML1|ENT_QUOTES, 'UTF-8') . '" HotelChain="' . htmlspecialchars((string)$hotelChain, ENT_XML1|ENT_QUOTES, 'UTF-8') . '"/>
         <hot:HotelDetailsModifiers RateRuleDetail="Complete" NumberOfAdults="' . $adults . '" NumberOfRooms="' . $rooms . '">
            <hot:HotelStay>
               <hot:CheckinDate>' . $checkinFormatted . '</hot:CheckinDate>
               <hot:CheckoutDate>' . $checkoutFormatted . '</hot:CheckoutDate>
            </hot:HotelStay>
         </hot:HotelDetailsModifiers>
      </hot:HotelDetailsReq>
   </soapenv:Body>
</soapenv:Envelope>';

        // ========================================
        // BUILD MEDIA REQUEST (FOR IMAGES)
        // ========================================
        $mediaSoapRequest = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" 
                  xmlns:hot="http://www.travelport.com/schema/hotel_v52_0" 
                  xmlns:com="http://www.travelport.com/schema/common_v52_0">
   <soapenv:Header/>
   <soapenv:Body>
      <hot:HotelMediaLinksReq AuthorizedBy="user" TargetBranch="' . $branchCode . '" TraceId="media-' . time() . '">
         <com:BillingPointOfSaleInfo OriginApplication="UAPI"/>
         <hot:HotelProperty HotelCode="' . htmlspecialchars((string)$hotelId, ENT_XML1|ENT_QUOTES, 'UTF-8') . '" HotelChain="' . htmlspecialchars((string)$hotelChain, ENT_XML1|ENT_QUOTES, 'UTF-8') . '"/>
      </hot:HotelMediaLinksReq>
   </soapenv:Body>
</soapenv:Envelope>';

        // ========================================
        // EXECUTE BOTH API CALLS IN PARALLEL
        // ========================================
        $mh = curl_multi_init();
        
        // Details request
        $chDetails = curl_init();
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
            CURLOPT_TIMEOUT => 30
        ]);

        // Media request
        $chMedia = curl_init();
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
            CURLOPT_TIMEOUT => 30
        ]);

        curl_multi_add_handle($mh, $chDetails);
        curl_multi_add_handle($mh, $chMedia);

        // Execute parallel requests
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        // Get responses
        $detailsResponse = curl_multi_getcontent($chDetails);
        $mediaResponse = curl_multi_getcontent($chMedia);
        $detailsRawResponse = $detailsResponse;
        $mediaRawResponse = $mediaResponse;
        
        $detailsHttpCode = curl_getinfo($chDetails, CURLINFO_HTTP_CODE);
        $mediaHttpCode = curl_getinfo($chMedia, CURLINFO_HTTP_CODE);

        curl_multi_remove_handle($mh, $chDetails);
        curl_multi_remove_handle($mh, $chMedia);
        curl_multi_close($mh);

        if ($detailsHttpCode !== 200) {
            throw new Exception('Details API returned HTTP ' . $detailsHttpCode);
        }

        if ($mediaHttpCode !== 200 && !empty($mediaResponse)) {
            libxml_use_internal_errors(true);
            $mediaFaultXml = simplexml_load_string($mediaResponse);
            if ($mediaFaultXml !== false) {
                $mediaFaultXml->registerXPathNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
                $mediaFaultXml->registerXPathNamespace('SOAP', 'http://schemas.xmlsoap.org/soap/envelope/');
                $mediaFault = $mediaFaultXml->xpath('//SOAP:Fault');
                if (empty($mediaFault)) {
                    $mediaFault = $mediaFaultXml->xpath('//soap:Fault');
                }
                if (!empty($mediaFault)) {
                    $mediaFaultString = (string)($mediaFault[0]->faultstring ?? 'Unknown media error');
                    throw new Exception('Media API Error: ' . $mediaFaultString);
                }
            }
        }

        // ========================================
        // PARSE DETAILS RESPONSE
        // ========================================
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($detailsResponse);
        
        if ($xml === false) {
            throw new Exception('Invalid XML response');
        }

        // Register namespaces
        $xml->registerXPathNamespace('SOAP', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xml->registerXPathNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xml->registerXPathNamespace('hotel', 'http://www.travelport.com/schema/hotel_v52_0');
        $xml->registerXPathNamespace('hot', 'http://www.travelport.com/schema/hotel_v52_0');
        $xml->registerXPathNamespace('common_v52_0', 'http://www.travelport.com/schema/common_v52_0');
        $xml->registerXPathNamespace('com', 'http://www.travelport.com/schema/common_v52_0');

        // Check for SOAP Fault
        $fault = $xml->xpath('//SOAP:Fault');
        if (empty($fault)) {
            $fault = $xml->xpath('//soap:Fault');
        }
        if (!empty($fault)) {
            $faultString = (string)($fault[0]->faultstring ?? 'Unknown error');
            throw new Exception('API Error: ' . $faultString);
        }

        // Get hotel property
        $hotelProperty = $xml->xpath('//hotel:RequestedHotelDetails/hotel:HotelProperty');
        if (empty($hotelProperty)) {
            $hotelProperty = $xml->xpath('//hot:RequestedHotelDetails/hot:HotelProperty');
        }

        if (empty($hotelProperty)) {
            throw new Exception('Hotel not found');
        }

        $hotel = $hotelProperty[0];

        // Basic info
        $hotelName = (string)($hotel['Name'] ?? '');

        // Extract rating
        $rating = 0;
        $ratingElements = $hotel->xpath('.//hotel:HotelRating/hotel:Rating');
        if (empty($ratingElements)) {
            $ratingElements = $hotel->xpath('.//hot:HotelRating/hot:Rating');
        }
        if (!empty($ratingElements)) {
            $rating = (int)((string)$ratingElements[0]);
        }

        // Address
        $address = '';
        $city = '';
        $countryCode = '';
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
                        $city = $parts[0];
                        $countryCode = $parts[1];
                        $address = $addressParts[0];
                    } else {
                        $address = implode(', ', $addressParts);
                    }
                } else {
                    $address = implode(', ', $addressParts);
                }
            }
        }

        // ========================================
        // COORDINATES WITH 3-TIER FALLBACK
        // ========================================
        $latitude = null;
        $longitude = null;
        $coordinateSource = 'none';

        // TIER 1: Try to get from Travelport API
        $coordElements = $xml->xpath('//common_v52_0:CoordinateLocation');
        if (empty($coordElements)) {
            $coordElements = $xml->xpath('//com:CoordinateLocation');
        }
        if (empty($coordElements)) {
            $coordElements = $xml->xpath('//CoordinateLocation');
        }
        
        if (!empty($coordElements)) {
            $lat = (string)($coordElements[0]['latitude'] ?? '');
            $lon = (string)($coordElements[0]['longitude'] ?? '');
            
            if (!empty($lat) && !empty($lon)) {
                $latitude = (float)$lat;
                $longitude = (float)$lon;
                $coordinateSource = 'travelport_api';
                
                
                // Save to cache
                saveCoordinatesToCache($db, $hotelId, $hotelChain, $latitude, $longitude);
            }
        }

        // TIER 2: Check database cache
        if (empty($latitude) || empty($longitude)) {
            $cached = getCoordinatesFromCache($db, $hotelId, $hotelChain);
            
            if ($cached) {
                $latitude = $cached['latitude'];
                $longitude = $cached['longitude'];
                $coordinateSource = 'database_cache';
                
            }
        }

        // TIER 3: Geocode using address
        if ((empty($latitude) || empty($longitude)) && !empty($address)) {
            
            $geocoded = geocodeAddress($address, $city, $countryCode);
            
            if ($geocoded) {
                $latitude = $geocoded['latitude'];
                $longitude = $geocoded['longitude'];
                $coordinateSource = 'geocoded';
                
                // Save to cache for future
                saveCoordinatesToCache($db, $hotelId, $hotelChain, $latitude, $longitude);
                
            } else {
            }
        }

        // Phone
        $phone = '';
        $phoneElements = $hotel->xpath('.//common_v52_0:PhoneNumber[@Type="Business"]');
        if (empty($phoneElements)) {
            $phoneElements = $hotel->xpath('.//com:PhoneNumber[@Type="Business"]');
        }
        if (!empty($phoneElements)) {
            $phone = (string)($phoneElements[0]['Number'] ?? '');
        }

        // Description
        $description = '';
        $descElements = $xml->xpath('//hotel:HotelDetailItem[@Name="Description"]//hotel:Text');
        if (empty($descElements)) {
            $descElements = $xml->xpath('//hot:HotelDetailItem[@Name="Description"]//hot:Text');
        }
        if (!empty($descElements)) {
            $description = (string)$descElements[0];
        }

        // Fallback description
        if (empty($description)) {
            $description = $hotelName . ' is located in ' . ($city ?: 'the area') . '. ' .
                        'This ' . $rating . '-star property offers quality accommodations and services. ' .
                        'Check availability and rates for your preferred dates.';
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
            if (!empty($code)) {
                $amenities[] = $desc;
            }
        }

        // Generic amenities if none provided
        if (empty($amenities)) {
            $amenities = [
                'Free Wi-Fi',
                '24-Hour Front Desk',
                'Room Service',
                'Housekeeping',
                'Concierge Services',
                'Luggage Storage',
                'Air Conditioning',
                'Daily Housekeeping',
                'Non-Smoking Rooms',
                'Express Check-In/Check-Out',
                'Security',
                'Elevator',
                'Wake-Up Service',
                'Wheelchair Accessible',
                'Family Rooms Available'
            ];
        }

        // Check-in/Check-out times
        $checkInTime = '15:00';
        $checkOutTime = '11:00';

        $checkInElements = $xml->xpath('//hotel:HotelDetailItem[@Name="CheckInTime"]//hotel:Text');
        if (empty($checkInElements)) {
            $checkInElements = $xml->xpath('//hot:HotelDetailItem[@Name="CheckInTime"]//hot:Text');
        }
        if (!empty($checkInElements)) {
            $checkInTime = (string)$checkInElements[0];
        }

        $checkOutElements = $xml->xpath('//hotel:HotelDetailItem[@Name="CheckOutTime"]//hotel:Text');
        if (empty($checkOutElements)) {
            $checkOutElements = $xml->xpath('//hot:HotelDetailItem[@Name="CheckOutTime"]//hot:Text');
        }
        if (!empty($checkOutElements)) {
            $checkOutTime = (string)$checkOutElements[0];
        }

        // ========================================
        // PARSE MEDIA (IMAGES) - LIMIT TO 30
        // ========================================
        $images = [];
        $maxImages = 30;
        
        if ($mediaHttpCode === 200 && $mediaResponse) {
            $mediaXml = simplexml_load_string($mediaResponse);
            if ($mediaXml !== false) {
                $mediaXml->registerXPathNamespace('com', 'http://www.travelport.com/schema/common_v52_0');
                
                // Get only Large (L) size images to avoid duplicates
                $mediaElements = $mediaXml->xpath('//com:MediaItem[@sizeCode="L"]');
                if (empty($mediaElements)) {
                    $mediaElements = $mediaXml->xpath('//common_v52_0:MediaItem[@sizeCode="L"]');
                }
                if (empty($mediaElements)) {
                    $mediaElements = $mediaXml->xpath('//MediaItem[@sizeCode="L"]');
                }
                
                foreach ($mediaElements as $media) {
                    if (count($images) >= $maxImages) {
                        break;
                    }
                    
                    $url = (string)($media['url'] ?? '');
                    if (!empty($url)) {
                        $images[] = $url;
                    }
                }
            }
        }

        // Fallback placeholder if no images
        if (empty($images)) {
            $images = ['https://via.placeholder.com/800x600?text=' . urlencode($hotelName)];
        }

        // Build location string
        $location = $address;
        
        if ($city) {
            $location .= ($location ? ', ' : '') . $city;
        }
        if ($countryCode) {
            $location .= ($location ? ', ' : '') . $countryCode;
        }
        
        // ========================================
        // BUILD RESPONSE
        // ========================================
        $response = [
            'success' => true,
            'data' => [
                'id' => $hotelId,
                'name' => $hotelName,
                'description' => $description,
                'address' => $address,
                'city' => $city,
                'country' => $countryCode,
                'location' => $location,
                'phone' => $phone,
                'email' => '',
                'website' => '',
                'cancellation_policy' => 'Cancellation policies vary by rate and are shown during booking. Please review the specific cancellation terms for your selected room rate before confirming your reservation.',
                'privacy_policy' => 'Your personal information is handled in accordance with our privacy policy and data protection regulations.',
                'stars' => $rating,
                'rating' => (float)$rating,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'coordinate_source' => $coordinateSource, // For debugging
                'images' => $images,
                'amenities' => $amenities,
                'check_in_time' => $checkInTime,
                'check_out_time' => $checkOutTime,
                'rooms' => [], // Rooms fetched separately via /rooms endpoint
                'supplier' => $supplier,
                'checkin' => $checkin,
                'checkout' => $checkout
            ]
        ];

        echo json_encode($response);

    } catch (Exception $e) {
        error_log('Travelport Details Error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'supplier_error' => [
                'supplier' => 'travelport',
                'message' => $e->getMessage()
            ],
            'raw_response' => [
                'details' => $detailsRawResponse,
                'media' => $mediaRawResponse
            ]
        ]);
    }

    exit;
});
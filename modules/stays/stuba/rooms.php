<?php

/**
 * ============================================================================
 * STUBA HOTEL ROOMS API ENDPOINT - OPTIMIZED VERSION
 * ============================================================================
 * 
 * PERFORMANCE OPTIMIZATIONS:
 * 1. Parallel BookingCreate (prepare) calls using curl_multi
 * 2. Intelligent throttling - only validate top N options per room type
 * 3. Configurable validation strategy
 * 4. Reduced timeouts for faster failure handling
 * 
 * Target: < 10 seconds response time
 * ============================================================================
 */

$router->post('stays/stuba/rooms', function() use ($db) {

    function xmlToJson($xml) {
        $xml = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        $json = json_encode($xml);
        return $json;
    }

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
        $nationality = $input['nationality'] ?? 'US';
        $rooms = $input['rooms'] ?? [];
        
        // OPTIMIZATION: Validation strategy
        $validateAll = $input['validate_all'] ?? false; // Set to true to validate all options
        $maxValidationsPerRoom = $input['max_validations'] ?? 3; // Validate only top N cheapest options per room

        // Strip "stuba_" prefix if present
        if (strpos($hotelId, 'stuba_') === 0) {
            $hotelId = substr($hotelId, 6);
        }

        // Validate required parameters
        if (empty($hotelId)) {
            echo json_encode([
                'success' => false,
                'message' => 'Hotel ID is required'
            ]);
            exit;
        }

        if (empty($checkin) || empty($checkout)) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'hotel_id' => $hotelId,
                    'nights' => 1,
                    'currency' => $_SESSION['app_currency'] ?? 'USD',
                    'rooms' => [],
                    'message' => 'Check-in and check-out dates are required'
                ]
            ]);
            exit;
        }

        // ========================================
        // GET STUBA MODULE CONFIGURATION
        // ========================================
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

        // Extract credentials
        $dbHost = $module['host'] ?? 'localhost';
        $dbName = $module['database'] ?? '';
        $dbUser = $module['username'] ?? 'root';
        $dbPass = $module['password'] ?? '';
        
        $org = $module['c1'] ?? '';
        $user = $module['c2'] ?? '';
        $password = $module['c3'] ?? '';
        $environment = ($module['dev_mode'] ?? 0) == 1 ? 'test' : 'production';
        
        $moduleCurrency = 'USD';

        $base_endpoint = $environment === 'test'
        ? 'https://www.stubademo.com/RXLStagingServices/ASMX/XmlService.asmx'
        : 'https://api.stuba.com/RXLServices/ASMX/XmlService.asmx';

        if (empty($dbName) || empty($org) || empty($user) || empty($password)) {
            echo json_encode([
                'success' => false,
                'message' => 'Module configuration incomplete'
            ]);
            exit;
        }

        // ========================================
        // CREATE DATABASE CONNECTION
        // ========================================
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
                'message' => 'Database connection failed'
            ]);
            exit;
        }

        // ========================================
        // CALCULATE NIGHTS
        // ========================================
        $arrival_date = date("Y-m-d", strtotime($checkin));
        $checkout_date = date("Y-m-d", strtotime($checkout));

        $start = new DateTime($arrival_date);
        $end = new DateTime($checkout_date);
        $nights = $start->diff($end)->days;

        // ========================================
        // BUILD GUESTS XML FOR AVAILABILITY
        // ========================================
        $totalAdults = 0;
        $totalChildren = 0;
        $agesArray = [];
        $guestsXml = '';
        
        if (!empty($rooms) && is_array($rooms)) {
            foreach ($rooms as $room) {
                $adults = ($room['adults'] ?? 2);
                $children = ($room['children'] ?? 0);
                
                $totalAdults += $adults;
                $totalChildren += $children;

                for ($i = 0; $i < $adults; $i++) {
                    $guestsXml .= "<Adult />\n";
                }

                if ($children > 0) {
                    $childAges = $room['childAges'] ?? [];
                    for ($i = 0; $i < $children; $i++) {
                        $age = isset($childAges[$i]) && $childAges[$i] !== '' 
                            ? $childAges[$i] 
                            : 0;
                        $guestsXml .= "<Child age=\"{$age}\" />\n";
                        $agesArray[] = $age;
                    }
                }
            }
        } else {
            $totalAdults = 2;
            $guestsXml = "<Adult />\n<Adult />\n";
        }
        
        $agesString = implode(',', $agesArray);

        // ========================================
        // BUILD AVAILABILITY SOAP XML
        // ========================================
        $xml_post_string = '<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
            <soap:Body>
                <AvailabilitySearch xmlns="http://www.reservwire.com/namespace/WebServices/Xml">
                <xiRequest>
                    <Authority>
                    <Org>' . htmlspecialchars((string) $org, ENT_XML1, 'UTF-8') . '</Org>
                    <User>' . htmlspecialchars((string) $user, ENT_XML1, 'UTF-8') . '</User>
                    <Password>' . htmlspecialchars((string) $password, ENT_XML1, 'UTF-8') . '</Password>
                    <Currency>USD</Currency>
                    <Version>1.28</Version>
                    </Authority>
                    <HotelId>' . htmlspecialchars((string) $hotelId, ENT_XML1, 'UTF-8') . '</HotelId>
                    <HotelStayDetails>
                    <ArrivalDate>' . htmlspecialchars((string) $arrival_date, ENT_XML1, 'UTF-8') . '</ArrivalDate>
                    <Nights>' . (int) $nights . '</Nights>
                    <Nationality>' . htmlspecialchars((string) $nationality, ENT_XML1, 'UTF-8') . '</Nationality>
                    <Room>
                    <Guests>
                        ' . $guestsXml . '
                    </Guests>
                    </Room>
                    </HotelStayDetails>
                    <DetailLevel>basic</DetailLevel>
                    <MaxResultsPerHotel>0</MaxResultsPerHotel>
                    <MaxHotels>0</MaxHotels>
                    <MaxSearchTime>0</MaxSearchTime>
                </xiRequest>
                </AvailabilitySearch>
            </soap:Body>
        </soap:Envelope>';

        // ========================================
        // MAKE AVAILABILITY SOAP REQUEST
        // ========================================
        $headers = [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "http://www.reservwire.com/namespace/WebServices/Xml/AvailabilitySearch"',
            'Content-Length: ' . strlen($xml_post_string)
        ];

        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $base_endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_post_string);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_VERBOSE, true);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            echo "cURL Error: " . curl_error($ch);
            exit;
        }

        // ========================================
        // PARSE AVAILABILITY RESPONSE
        // ========================================
        $pattern = '/<soap:Body>(.*)<\/soap:Body>/s';
        if (preg_match($pattern, $response, $matches)) {
            $bodyContent = $matches[1];
            $json = xmlToJson($bodyContent);
        } else {
            $json = xmlToJson($response);
        }

        $hotel_detail = json_decode($json);
        
        if (empty($hotel_detail->AvailabilitySearchResult) || 
            !isset($hotel_detail->AvailabilitySearchResult->HotelAvailability)) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'hotel_id' => $hotelId,
                    'nights' => $nights,
                    'currency' => $_SESSION['app_currency'] ?? 'USD',
                    'rooms' => [],
                    'message' => 'No rooms available for selected dates'
                ]
            ]);
            exit;
        }

        $currency = $_SESSION['app_currency'] ?? 'USD';
        $apiCurrency = (string)$hotel_detail->AvailabilitySearchResult->Currency;
        $hotelQuoteId = (string)$hotel_detail->AvailabilitySearchResult->HotelAvailability->{'@attributes'}->hotelQuoteId;

        // ========================================
        // FETCH HOTEL DATA FROM DATABASE
        // ========================================
        $hotel_data = $stubaDb->get('stuba_hotels', '*', [
            'hotel_id' => $hotelId
        ]);

        if (!$hotel_data) {
            echo json_encode([
                'success' => false,
                'message' => 'Hotel data not found'
            ]);
            exit;
        }

        // ========================================
        // GET ROOM IMAGES (keeping existing logic)
        // ========================================
        $room_keywords = [
            'Suite' => ['suite', 'king suite', 'queen suite', 'executive suite'],
            'Guest_Room' => ['guest room', 'standard room', 'standard', 'classic'],
            'Single_Room' => ['single', 'single room'],
            'Double_Room' => ['double', 'double room', 'double bed'],
            'Twin_Room' => ['twin', 'twin room', 'twin bed'],
            'Family_Room' => ['family', 'family room'],
            'Accessible_Room' => ['accessible', 'handicap', 'disability'],
            'Presidential_Suite' => ['presidential', 'presidential suite', 'royal'],
            'Penthouse' => ['penthouse', 'top floor'],
            'Junior_Suite' => ['junior suite', 'junior'],
            'Studio' => ['studio', 'apartment'],
            'Deluxe' => ['deluxe', 'superior', 'premium'],
            'Economy' => ['economy', 'budget', 'basic']
        ];

        $rooms_photos = [];
        $general_photos = [];
        $used_images = [];
        $default_room_image = dirname(root) . '/uploads/no_img.jpg';

        $roomImageRecords = $stubaDb->select('stuba_hotel_images', [
            'image_url',
            'image_type'
        ], [
            'hotel_id' => $hotelId,
            'ORDER' => ['image_order' => 'ASC']
        ]);

        foreach ($roomImageRecords as $imgRec) {
            if (empty($imgRec['image_url'])) continue;

            $type = $imgRec['image_type'] ?? '';
            
            $imageUrl = "https://hotelcontent-c4e7fhcwdeguhbgk.a03.azurefd.net/rxlimages%2F" . 
                        str_replace('RXLStagingImages', 'RXLImages', 
                        str_replace('https://content.stuba.com/', '', $imgRec['image_url']));
            
            $cachedImageUrl = cacheImage($imageUrl, $hotelId, '');

            $matched = false;
            foreach ($room_keywords as $main_keyword => $variations) {
                foreach ($variations as $variation) {
                    if (stripos($type, $variation) !== false || stripos($imgRec['image_url'], $variation) !== false) {
                        $rooms_photos[] = [
                            'url' => $cachedImageUrl, 
                            'type' => strtolower($type),
                            'keyword' => strtolower($main_keyword),
                            'variations' => $variations
                        ];
                        $matched = true;
                        break 2;
                    }
                }
            }

            if (!$matched) {
                $general_photos[] = $cachedImageUrl;
            }
        }

        // Helper functions (keeping existing)
        function getRoomImage($room_name, $rooms_photos, $general_photos, &$used_images, $default_image) {
            if (empty($room_name)) {
                return getAnyUnusedImage($rooms_photos, $general_photos, $used_images, $default_image);
            }
            
            $room_name_lower = strtolower($room_name);
            
            foreach ($rooms_photos as $photo) {
                if (in_array($photo['url'], $used_images)) continue;
                if (stripos($room_name_lower, $photo['keyword']) !== false) {
                    $used_images[] = $photo['url'];
                    return $photo['url'];
                }
            }
            
            foreach ($rooms_photos as $photo) {
                if (in_array($photo['url'], $used_images)) continue;
                foreach ($photo['variations'] as $variation) {
                    if (stripos($room_name_lower, $variation) !== false) {
                        $used_images[] = $photo['url'];
                        return $photo['url'];
                    }
                }
            }
            
            foreach ($rooms_photos as $photo) {
                if (!in_array($photo['url'], $used_images)) {
                    $used_images[] = $photo['url'];
                    return $photo['url'];
                }
            }
            
            foreach ($general_photos as $photo_url) {
                if (!in_array($photo_url, $used_images)) {
                    $used_images[] = $photo_url;
                    return $photo_url;
                }
            }
            
            return $default_image;
        }

        function getAnyUnusedImage($rooms_photos, $general_photos, &$used_images, $default_image) {
            foreach ($rooms_photos as $photo) {
                if (!in_array($photo['url'], $used_images)) {
                    $used_images[] = $photo['url'];
                    return $photo['url'];
                }
            }
            
            foreach ($general_photos as $photo_url) {
                if (!in_array($photo_url, $used_images)) {
                    $used_images[] = $photo_url;
                    return $photo_url;
                }
            }
            
            return $default_image;
        }

        // ========================================
        // HELPER FUNCTION TO GENERATE DUMMY GUESTS XML
        // ========================================
        function generateDummyGuestsXml($adults, $children, $childAges = []) {
            $guestsXml = '';
            
            for ($i = 0; $i < $adults; $i++) {
                $title = ($i % 2 == 0) ? 'Mr' : 'Mrs';
                $firstName = "Guest" . ($i + 1);
                $lastName = "User";
                $guestsXml .= "            <Adult title=\"{$title}\" first=\"{$firstName}\" last=\"{$lastName}\" />\n";
            }
            
            for ($i = 0; $i < $children; $i++) {
                $age = isset($childAges[$i]) && $childAges[$i] !== '' ? $childAges[$i] : 10;
                $firstName = "Child" . ($i + 1);
                $lastName = "User";
                $guestsXml .= "            <Child age=\"{$age}\" title=\"Mr\" first=\"{$firstName}\" last=\"{$lastName}\" />\n";
            }
            
            return $guestsXml;
        }

        // ========================================
        // FUNCTION TO BUILD PREPARE XML
        // ========================================
        function buildPrepareXml($quoteId, $adultsCount, $childrenCount, $childAges, $org, $user, $password) {
            $guestsXml = generateDummyGuestsXml($adultsCount, $childrenCount, $childAges);
            
            $orgSafe = htmlspecialchars($org, ENT_XML1, 'UTF-8');
            $userSafe = htmlspecialchars($user, ENT_XML1, 'UTF-8');
            $passwordSafe = htmlspecialchars($password, ENT_XML1, 'UTF-8');
            $quoteIdSafe = htmlspecialchars($quoteId, ENT_XML1, 'UTF-8');
            
            return <<<XML
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" 
               xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" 
               xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <soap:Body>
    <BookingCreate xmlns="http://www.reservwire.com/namespace/WebServices/Xml">
      <xiRequest>
        <Authority>
          <Org>{$orgSafe}</Org>
          <User>{$userSafe}</User>
          <Password>{$passwordSafe}</Password>
          <Currency>USD</Currency>
          <Version>1.28</Version>
        </Authority>
        <QuoteId>{$quoteIdSafe}</QuoteId>
        <HotelStayDetails>
          <Room>
           <Guests>
{$guestsXml}
           </Guests>
          </Room>
        </HotelStayDetails>
        <DetailLevel>basic</DetailLevel>
        <CommitLevel>prepare</CommitLevel>
      </xiRequest>
    </BookingCreate>
  </soap:Body>
</soap:Envelope>
XML;
        }

        // ========================================
        // PARALLEL BOOKING PREPARE USING CURL_MULTI
        // ========================================
        function executeParallelBookingPrepare($prepareRequests, $base_endpoint) {
            if (empty($prepareRequests)) {
                return [];
            }
            
            $multiHandle = curl_multi_init();
            $curlHandles = [];
            $results = [];
            
            // Set concurrent connections limit
            curl_multi_setopt($multiHandle, CURLMOPT_MAX_TOTAL_CONNECTIONS, 10);
            
            // Initialize all curl handles
            foreach ($prepareRequests as $index => $request) {
                $ch = curl_init();
                
                $headers = [
                    'Content-Type: text/xml; charset=utf-8',
                    'SOAPAction: "http://www.reservwire.com/namespace/WebServices/Xml/BookingCreate"',
                    'Content-Length: ' . strlen($request['xml'])
                ];
                
                curl_setopt_array($ch, [
                    CURLOPT_URL => $base_endpoint,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15, // Reduced from 30 to 15 seconds
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $request['xml'],
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_VERBOSE => false
                ]);
                
                curl_multi_add_handle($multiHandle, $ch);
                $curlHandles[$index] = [
                    'handle' => $ch,
                    'quote_id' => $request['quote_id']
                ];
            }
            
            // Execute all handles
            $running = null;
            do {
                curl_multi_exec($multiHandle, $running);
                curl_multi_select($multiHandle, 0.1);
            } while ($running > 0);
            
            // Collect results
            foreach ($curlHandles as $index => $handleData) {
                $ch = $handleData['handle'];
                $quoteId = $handleData['quote_id'];
                
                $response = curl_multi_getcontent($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                
                $results[$quoteId] = [
                    'response' => $response,
                    'http_code' => $httpCode,
                    'error' => $error,
                    'quote_id' => $quoteId
                ];
                
                curl_multi_remove_handle($multiHandle, $ch);
            }
            
            curl_multi_close($multiHandle);
            
            return $results;
        }

        // ========================================
        // FUNCTION TO PARSE PREPARE RESPONSE
        // ========================================
        function parsePrepareResponse($prepareDetail, $db, $apiCurrency, $targetCurrency) {
            $bookingResult = $prepareDetail['BookingCreateResult'] ?? [];
            $booking = $bookingResult['Booking'] ?? [];
            $hotelBooking = $booking['HotelBooking'] ?? [];
            $room = $hotelBooking['Room'] ?? [];
            
            $totalSellingPrice = 0;
            if (isset($hotelBooking['TotalSellingPrice']['@attributes']['amt'])) {
                $totalSellingPrice = floatval($hotelBooking['TotalSellingPrice']['@attributes']['amt']);
            }
            
            $markupResult = MARKUP($totalSellingPrice, $module, $db, $apiCurrency, $targetCurrency);
            $totalPriceWithMarkup = $markupResult['price'];
            
            $nightCosts = [];
            if (isset($room['NightCost']) && is_array($room['NightCost'])) {
                foreach ($room['NightCost'] as $nightCost) {
                    $nightNumber = $nightCost['Night'] ?? 0;
                    $nightPrice = floatval($nightCost['SellingPrice']['@attributes']['amt'] ?? 0);
                    
                    $nightMarkupResult = MARKUP($nightPrice, $module, $db, $apiCurrency, $targetCurrency);
                    $nightCosts[] = [
                        'night' => $nightNumber,
                        'price' => $nightMarkupResult['price'],
                        'base_price' => $nightPrice,
                        'currency' => $targetCurrency
                    ];
                }
            }
            
            $cancellationPolicies = [];
            $cancellationText = '';
            $cancellationPolicyStatus = $room['CancellationPolicyStatus'] ?? 'Unknown';
            
            if (isset($room['CanxFees']['Fee'])) {
                $fees = $room['CanxFees']['Fee'];
                
                if (isset($fees['Amount'])) {
                    $fees = [$fees];
                }
                
                foreach ($fees as $fee) {
                    $feeAmount = floatval($fee['Amount']['@attributes']['amt'] ?? 0);
                    $feeFrom = $fee['@attributes']['from'] ?? null;
                    
                    $feeMarkupResult = MARKUP($feeAmount, $module, $db, $apiCurrency, $targetCurrency);
                    $feeAmountWithMarkup = $feeMarkupResult['price'];
                    
                    $policy = [
                        'amount' => $feeAmountWithMarkup,
                        'base_amount' => $feeAmount,
                        'currency' => $targetCurrency,
                        'base_currency' => $apiCurrency
                    ];
                    
                    if ($feeFrom) {
                        try {
                            $dateObj = new DateTime($feeFrom);
                            $formattedDate = $dateObj->format('M d, Y');
                            
                            $policy['from'] = $dateObj->format('Y-m-d');
                            $policy['from_formatted'] = $formattedDate;
                            $policy['text'] = "Cancellation fee of " . number_format($feeAmountWithMarkup, 2) . " " . $targetCurrency . " applies from " . $formattedDate;
                        } catch (Exception $e) {
                            $policy['text'] = "Cancellation fee: " . number_format($feeAmountWithMarkup, 2) . " " . $targetCurrency;
                        }
                    } else {
                        $policy['text'] = "Cancellation fee: " . number_format($feeAmountWithMarkup, 2) . " " . $targetCurrency;
                    }
                    
                    $cancellationPolicies[] = $policy;
                }
            }
            
            if ($cancellationPolicyStatus === 'NonRefundable') {
                if (!empty($cancellationPolicies)) {
                    $cancellationText = $cancellationPolicies[0]['text'];
                } else {
                    $cancellationText = "Non-refundable - No cancellation allowed";
                }
            } elseif ($cancellationPolicyStatus === 'Refundable') {
                if (!empty($cancellationPolicies)) {
                    $cancellationText = "Refundable - " . $cancellationPolicies[0]['text'];
                } else {
                    $cancellationText = "Free cancellation available";
                }
            } else {
                $cancellationText = !empty($cancellationPolicies) ? $cancellationPolicies[0]['text'] : 'Cancellation policy not specified';
            }
            
            $messages = [];
            $generalNotes = [];
            $internalNotes = [];
            
            if (isset($room['Messages']['Message']) && is_array($room['Messages']['Message'])) {
                foreach ($room['Messages']['Message'] as $message) {
                    $messageType = $message['Type'] ?? 'General';
                    $messageText = $message['Text'] ?? '';
                    
                    $cleanText = strip_tags($messageText);
                    $cleanText = preg_replace('/\s+/', ' ', $cleanText);
                    $cleanText = trim($cleanText);
                    
                    $messageData = [
                        'type' => $messageType,
                        'text' => $cleanText,
                        'html' => $messageText
                    ];
                    
                    $messages[] = $messageData;
                    
                    if ($messageType === 'General') {
                        $generalNotes[] = $cleanText;
                    } elseif ($messageType === 'Internal Note') {
                        $internalNotes[] = $cleanText;
                    }
                }
            }
            
            $roomTypeName = $room['RoomType']['@attributes']['text'] ?? '';
            $roomTypeCode = $room['RoomType']['@attributes']['code'] ?? '';
            $mealTypeName = $room['MealType']['@attributes']['text'] ?? 'Room only';
            $mealTypeCode = $room['MealType']['@attributes']['code'] ?? '';
            
            $guests = [];
            if (isset($room['Guests']['Adult'])) {
                $adults = $room['Guests']['Adult'];
                if (!isset($adults[0])) {
                    $adults = [$adults];
                }
                
                foreach ($adults as $adult) {
                    $guests[] = [
                        'type' => 'adult',
                        'id' => $adult['@attributes']['id'] ?? '',
                        'title' => $adult['@attributes']['title'] ?? '',
                        'first_name' => $adult['@attributes']['first'] ?? '',
                        'last_name' => $adult['@attributes']['last'] ?? '',
                        'price' => floatval($adult['Price']['@attributes']['amt'] ?? 0)
                    ];
                }
            }
            
            if (isset($room['Guests']['Child'])) {
                $children = $room['Guests']['Child'];
                if (!isset($children[0])) {
                    $children = [$children];
                }
                
                foreach ($children as $child) {
                    $guests[] = [
                        'type' => 'child',
                        'id' => $child['@attributes']['id'] ?? '',
                        'age' => $child['@attributes']['age'] ?? 0,
                        'title' => $child['@attributes']['title'] ?? '',
                        'first_name' => $child['@attributes']['first'] ?? '',
                        'last_name' => $child['@attributes']['last'] ?? '',
                        'price' => floatval($child['Price']['@attributes']['amt'] ?? 0)
                    ];
                }
            }
            
            return [
                'success' => true,
                'booking_id' => $hotelBooking['Id'] ?? '',
                'status' => $hotelBooking['Status'] ?? '',
                'creation_date' => $hotelBooking['CreationDate'] ?? '',
                'arrival_date' => $hotelBooking['ArrivalDate'] ?? '',
                'nights' => intval($hotelBooking['Nights'] ?? 0),
                'hotel_id' => $hotelBooking['HotelId'] ?? '',
                'hotel_name' => $hotelBooking['HotelName'] ?? '',
                'room_type_name' => $roomTypeName,
                'room_type_code' => $roomTypeCode,
                'meal_type_name' => $mealTypeName,
                'meal_type_code' => $mealTypeCode,
                'total_price' => $totalPriceWithMarkup,
                'base_total_price' => $totalSellingPrice,
                'currency' => $targetCurrency,
                'base_currency' => $apiCurrency,
                'night_costs' => $nightCosts,
                'cancellation_policy_status' => $cancellationPolicyStatus,
                'cancellation_policies' => $cancellationPolicies,
                'cancellation_text' => $cancellationText,
                'refundable' => ($cancellationPolicyStatus === 'Refundable'),
                'messages' => $messages,
                'general_notes' => $generalNotes,
                'internal_notes' => $internalNotes,
                'guests' => $guests,
                'test_mode' => $bookingResult['TestMode'] ?? false,
                'commit_level' => $bookingResult['CommitLevel'] ?? ''
            ];
        }

        // ========================================
        // PROCESS ROOMS WITH INTELLIGENT THROTTLING
        // ========================================
        $roomsResponse = [];
        $room_groups = [];
        $prepareRequests = [];
        $roomOptionMapping = []; // Track which options need validation
        $allRoomOptions = []; // Store all room options with their original data

        // First pass: Group rooms and identify which options to validate
        $optionCounter = 0;
        foreach ($hotel_detail->AvailabilitySearchResult->HotelAvailability->Result as $room) {
            // Extract price
            if (!empty($room->Room->Price->{'@attributes'}->amt)) {
                $basePrice = $room->Room->Price->{'@attributes'}->amt;
            } else if (isset($room->Room) && is_array($room->Room) && isset($room->Room[0]->Price->{'@attributes'}->amt)) {
                $basePrice = $room->Room[0]->Price->{'@attributes'}->amt;
            } else {
                $basePrice = 0;
            }

            // Extract refund status
            if (!empty($room->Room->CancellationPolicyStatus)) {
                $refund = $room->Room->CancellationPolicyStatus;
            } else if (isset($room->Room) && is_array($room->Room) && isset($room->Room[0]->CancellationPolicyStatus)) {
                $refund = $room->Room[0]->CancellationPolicyStatus;
            } else {
                $refund = "";
            }

            // Extract meal type
            $meal_type = "";
            if (!empty($room->Room->MealType->{'@attributes'}->text)) {
                $meal_type = $room->Room->MealType->{'@attributes'}->text;
            } else if (isset($room->Room) && is_array($room->Room) && isset($room->Room[0]->MealType->{'@attributes'}->text)) {
                $meal_type = $room->Room[0]->MealType->{'@attributes'}->text;
            }

            // Extract room name
            if (!empty($room->Room->RoomType->{'@attributes'}->text)) {
                $room_name = $room->Room->RoomType->{'@attributes'}->text;
            } else if (isset($room->Room) && is_array($room->Room) && isset($room->Room[0]->RoomType->{'@attributes'}->text)) {
                $room_name = $room->Room[0]->RoomType->{'@attributes'}->text;
            } else {
                $room_name = "";
            }

            // Extract room ID (quote ID)
            if (!empty($room->{'@attributes'}->id)) {
                $room_id = (string)$room->{'@attributes'}->id;
            } elseif (isset($room->id)) {
                $room_id = (string)$room->id;
            } else {
                $room_id = "";
            }

            $room_key = $room_name ?: $room_id;

            // Initialize room group if not exists
            if (!isset($room_groups[$room_key])) {
                $room_groups[$room_key] = [
                    'room_name' => $room_name,
                    'options' => [],
                    'validation_count' => 0
                ];
            }

            // Store complete option data
            $optionData = [
                'unique_index' => $optionCounter++,
                'room_id' => $room_id,
                'base_price' => floatval($basePrice),
                'refund' => $refund,
                'meal_type' => $meal_type,
                'room_name' => $room_name,
                'room_key' => $room_key
            ];
            
            // Add to group
            $room_groups[$room_key]['options'][] = $optionData;
            
            // Store in lookup array
            $allRoomOptions[$room_id] = $optionData;
        }

        // Second pass: Sort options by price and select which to validate
        foreach ($room_groups as $room_key => &$group) {
            // Sort options by price (cheapest first)
            usort($group['options'], function($a, $b) {
                return $a['base_price'] <=> $b['base_price'];
            });

            // Determine how many to validate
            $validateCount = $validateAll ? count($group['options']) : min($maxValidationsPerRoom, count($group['options']));
            
            // Build prepare requests for selected options
            for ($i = 0; $i < $validateCount; $i++) {
                $option = $group['options'][$i];
                $room_id = $option['room_id'];
                
                $prepareXml = buildPrepareXml(
                    $room_id,
                    $totalAdults,
                    $totalChildren,
                    $agesArray,
                    $org,
                    $user,
                    $password
                );
                
                $prepareRequests[] = [
                    'quote_id' => $room_id,
                    'xml' => $prepareXml
                ];
                
                // Mark this option as needing validation
                $group['options'][$i]['should_validate'] = true;
                $roomOptionMapping[$room_id] = [
                    'room_key' => $room_key,
                    'option_index' => $i
                ];
            }
        }
        unset($group);

        // ========================================
        // EXECUTE PARALLEL PREPARE REQUESTS
        // ========================================
        $prepareResults = [];
        if (!empty($prepareRequests)) {
            $prepareResults = executeParallelBookingPrepare($prepareRequests, $base_endpoint);
        }

        // ========================================
        // PROCESS PREPARE RESULTS
        // ========================================
        $parsedPrepareData = [];
        $prepareStats = [
            'total_requests' => count($prepareRequests),
            'successful' => 0,
            'failed' => 0
        ];
        
        foreach ($prepareResults as $quoteId => $result) {
            if (!empty($result['error']) || $result['http_code'] != 200) {
                $parsedPrepareData[$quoteId] = [
                    'success' => false,
                    'error' => $result['error'] ?: 'HTTP error: ' . $result['http_code'],
                    'http_code' => $result['http_code']
                ];
                $prepareStats['failed']++;
                continue;
            }
            
            $response = $result['response'];
            
            // Check for SOAP faults
            if (stripos($response, '<soap:Fault>') !== false || 
                stripos($response, '<faultstring>') !== false ||
                stripos($response, '<Error') !== false) {
                
                preg_match('/<faultstring>(.*?)<\/faultstring>/s', $response, $faultMatches);
                $errorMsg = strip_tags($faultMatches[1] ?? 'Booking prepare failed');
                
                $parsedPrepareData[$quoteId] = [
                    'success' => false,
                    'error' => $errorMsg,
                    'http_code' => $result['http_code']
                ];
                $prepareStats['failed']++;
                continue;
            }
            
            // Parse successful response
            preg_match('/<soap:Body>(.*?)<\/soap:Body>/s', $response, $matches);
            $bodyContent = $matches[1] ?? $response;
            
            $json = xmlToJson($bodyContent);
            $prepareDetail = json_decode($json, true);
            
            $parsedData = parsePrepareResponse($prepareDetail, $db, $apiCurrency, $currency);
            $parsedData['http_code'] = $result['http_code'];
            
            $parsedPrepareData[$quoteId] = $parsedData;
            $prepareStats['successful']++;
        }

        // ========================================
        // BUILD FINAL RESPONSE
        // ========================================
        $boardMapping = [
            'Room Only' => 'RO',
            'Room only' => 'RO',
            'Breakfast' => 'BB',
            'Half Board' => 'HB',
            'Full Board' => 'FB',
            'All Inclusive' => 'AI'
        ];

        foreach ($room_groups as $room_key => $group) {
            $room_image = getRoomImage($group['room_name'], $rooms_photos, $general_photos, $used_images, $default_room_image);
            
            $finalRoomData = [
                'room_id' => $group['options'][0]['room_id'],
                'room_type_id' => $group['options'][0]['room_id'],
                'room_name' => $group['room_name'],
                'room_images' => [$room_image],
                'room_main_image' => $room_image,
                'amenities' => [],
                'max_adults' => $totalAdults,
                'max_children' => $totalChildren,
                'options' => []
            ];

            foreach ($group['options'] as $index => $option) {
                $room_id = (string)$option['room_id']; // Explicit string conversion
                $basePrice = $option['base_price'];
                $refund = $option['refund'];
                $meal_type = $option['meal_type'];
                $room_name = $option['room_name'];
                
                // Check if we have prepare data for this option - use array_key_exists for explicit check
                $hasPrepareData = false;
                $prepareData = null;
            
                if (array_key_exists($room_id, $parsedPrepareData)) {
                    $prepareData = $parsedPrepareData[$room_id];
                    $hasPrepareData = isset($prepareData['success']) && $prepareData['success'] === true;
                }
                
                if ($hasPrepareData && $prepareData) {
                    // Use validated prepare data
                    $finalPrice = $prepareData['total_price'];
                    $finalBasePrice = $prepareData['base_total_price'];
                    $pricePerNight = $nights > 0 ? ($finalPrice / $nights) : $finalPrice;
                    $cancellationPolicies = $prepareData['cancellation_policies'];
                    $cancellationText = $prepareData['cancellation_text'];
                    $isRefundable = $prepareData['refundable'];
                    $finalMealType = $prepareData['meal_type_name'];
                    $finalRoomName = $prepareData['room_type_name'];
                } else {
                    // Use availability data
                    $markupResult = MARKUP($basePrice, $module, $db, $apiCurrency, $currency);
                    $finalPrice = $markupResult['price'] * $nights;
                    $finalBasePrice = $basePrice;
                    $pricePerNight = $markupResult['price'];
                    $cancellationPolicies = [];
                    $cancellationText = ($refund === 'Refundable') ? 'Free cancellation' : 'Non-refundable';
                    $isRefundable = ($refund === 'Refundable');
                    $finalMealType = $meal_type;
                    $finalRoomName = $room_name;
                }

                $boardCode = $boardMapping[$finalMealType] ?? 'RO';

                $current_option = [
                    'option_index' => $index,
                    'id' => $room_id,
                    'max_adults' => $totalAdults,
                    'max_children' => $totalChildren,
                    'price_per_night' => $pricePerNight,
                    'total_price' => $finalPrice,
                    'base_price' => $finalBasePrice,
                    'currency' => $currency,
                    'base_currency' => $apiCurrency,
                    'discount_percentage' => 0,
                    'extra_bed_available' => 0,
                    'extra_bed_charge' => 0,
                    'breakfast_included' => in_array($finalMealType, ['Breakfast', 'Half Board', 'Full Board', 'All Inclusive']) ? 1 : 0,
                    'cancellation_free' => $isRefundable ? 1 : 0,
                    'refundable' => $isRefundable,
                    'available_quantity' => 1,
                    'board_id' => $boardCode,
                    'board_name' => $finalMealType ?: 'Room Only',
                    'rate_key' => $hotelQuoteId . '_' . $room_id,
                    'rate_type' => 'BOOKABLE',
                    'rate_comments' => '',
                    'cancellation_policies' => $cancellationPolicies,
                    'cancellation_text' => $cancellationText,
                    'excluded_taxes' => [],
                    'room_booked' => false,
                    'booking_data' => [
                        'hotelQuoteId' => $hotelQuoteId,
                        'room_id' => $room_id
                    ]
                ];

                // Add prepare validation data
                if ($hasPrepareData && $prepareData) {
                    
                    $current_option['additional_notes'] = [
                        'all_messages' => $prepareData['messages']
                    ];
                    
                } 

                $finalRoomData['options'][] = $current_option;
            }

            $roomsResponse[] = $finalRoomData;
        }

        // ========================================
        // BUILD FINAL RESPONSE
        // ========================================
        $response = [
            'success' => true,
            'data' => [
                'hotel_id' => $hotelId,
                'nights' => $nights,
                'currency' => $currency,
                'rooms' => $roomsResponse,
            ]
        ];

        if (empty($roomsResponse)) {
            $response['data']['message'] = 'No rooms available for selected dates';
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
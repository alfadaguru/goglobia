<?php
/**
 * ============================================================================
 * TRAVELPORT HOTEL ROOMS API ENDPOINT
 * ============================================================================
 *
 * PURPOSE:
 * Fetch available rooms and rates from Travelport API
 * Returns data in Stuba/Hotelbeds-compatible format
 *
 * ENDPOINT: POST /stays/travelport/rooms
 *
 * ============================================================================
 * REQUEST PARAMETERS
 * ============================================================================
 *
 * hotel_id (string)        - Hotel Code (e.g., "A6126")
 * hotel_chain (string)     - Hotel Chain Code (e.g., "SI") - REQUIRED!
 * checkin (string)         - Check-in date in DD-MM-YYYY format - REQUIRED!
 * checkout (string)        - Check-out date in DD-MM-YYYY format - REQUIRED!
 * adults (int)             - Number of adults (default: 2)
 * children (int)           - Number of children (default: 0)
 * rooms (int)              - Number of rooms (default: 1)
 * nationality (string)     - Guest nationality (default: 'US')
 * currency (string)        - Desired currency (default: USD)
 *
 * ============================================================================
 */

$router->post('stays/travelport/rooms', function() use ($db) {

    @ob_end_clean();
    header('Content-Type: application/json');

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
        // FETCH HOTEL IMAGES (for room images)
        // ========================================
        $hotelImages = [];

        $mediaSoapRequest = '<?xml version="1.0" encoding="UTF-8"?>
        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                        xmlns:hot="http://www.travelport.com/schema/hotel_v52_0"
                        xmlns:com="http://www.travelport.com/schema/common_v52_0">
        <soapenv:Header/>
        <soapenv:Body>
            <hot:HotelMediaLinksReq AuthorizedBy="user" TargetBranch="' . $branchCode . '" TraceId="media-' . time() . '">
                <com:BillingPointOfSaleInfo OriginApplication="UAPI"/>
                <hot:HotelProperty HotelCode="' . $hotelId . '" HotelChain="' . $hotelChain . '"/>
            </hot:HotelMediaLinksReq>
        </soapenv:Body>
        </soapenv:Envelope>';

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
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT => 15
        ]);

        $mediaResponse = curl_exec($chMedia);
        $mediaHttpCode = curl_getinfo($chMedia, CURLINFO_HTTP_CODE);

        // Parse media response
        if ($mediaHttpCode === 200 && $mediaResponse) {
            $mediaXml = simplexml_load_string($mediaResponse);
            if ($mediaXml !== false) {
                $mediaXml->registerXPathNamespace('com', 'http://www.travelport.com/schema/common_v52_0');

                // Get Large (L) size images - limit to 15 for room distribution
                $mediaElements = $mediaXml->xpath('//com:MediaItem[@sizeCode="L"]');

                foreach ($mediaElements as $media) {
                    if (count($hotelImages) >= 15) { // Limit to 15 for rooms
                        break;
                    }

                    $url = (string)($media['url'] ?? '');
                    if (!empty($url)) {
                        $hotelImages[] = $url;
                    }
                }
            }
        }

        // ========================================
        // BUILD HOTEL RATES REQUEST (WITH COMPLETE DETAILS)
        // ========================================
        $ratesSoapRequest = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:hot="http://www.travelport.com/schema/hotel_v52_0"
                  xmlns:com="http://www.travelport.com/schema/common_v52_0">
   <soapenv:Header/>
   <soapenv:Body>
      <hot:HotelDetailsReq AuthorizedBy="user" TargetBranch="' . $branchCode . '" TraceId="rooms-' . time() . '">
         <com:BillingPointOfSaleInfo OriginApplication="UAPI"/>
         <hot:HotelProperty HotelCode="' . $hotelId . '" HotelChain="' . $hotelChain . '"/>
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
        // EXECUTE API CALL
        // ========================================
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $ratesSoapRequest,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: ""',
                'Accept: text/xml'
            ],
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT => 30
        ]);

        $apiResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            throw new Exception('API returned HTTP ' . $httpCode);
        }

        // ========================================
        // PARSE RESPONSE
        // ========================================
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($apiResponse);

        if ($xml === false) {
            throw new Exception('Invalid XML response');
        }

        // Register with BOTH prefixes
        $xml->registerXPathNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xml->registerXPathNamespace('SOAP', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xml->registerXPathNamespace('hotel', 'http://www.travelport.com/schema/hotel_v52_0');
        $xml->registerXPathNamespace('hot', 'http://www.travelport.com/schema/hotel_v52_0');
        $xml->registerXPathNamespace('com', 'http://www.travelport.com/schema/common_v52_0');
        $xml->registerXPathNamespace('common_v52_0', 'http://www.travelport.com/schema/common_v52_0');

        // Check for SOAP Fault
        $fault = $xml->xpath('//SOAP:Fault');
        if (!empty($fault)) {
            $faultString = (string)($fault[0]->faultstring ?? 'Unknown error');
            throw new Exception('API Error: ' . $faultString);
        }

        $roomsData = [];
        $room_groups = [];
        $imageIndex = 0;

        // Remove pricing/policy fragments from raw Travelport room text and keep the actual room label.
        $sanitizeRoomName = function(array $parts, string $fallback = 'Standard Room'): string {
            $segments = [];
            foreach ($parts as $part) {
                $clean = trim(preg_replace('/\s+/', ' ', (string)$part));
                if ($clean !== '') {
                    $segments[] = $clean;
                }
            }

            if (empty($segments)) {
                return $fallback;
            }

            $noisePattern = '/^(full|no\s*changes|non\s*-?\s*refundable|refundable|room\s*only|breakfast(?:\s*included)?|special\s*rate|daily\s*rate|best\s*available\s*rate|rate\s*plan|prepaid)$/i';

            // Drop leading non-room fragments (commonly sent by Travelport as prefixes).
            while (!empty($segments) && preg_match($noisePattern, $segments[0])) {
                array_shift($segments);
            }

            if (empty($segments)) {
                return $fallback;
            }

            // Remove immediate duplicates created by repeated XML text nodes.
            $deduped = [];
            foreach ($segments as $segment) {
                if (empty($deduped) || strcasecmp(end($deduped), $segment) !== 0) {
                    $deduped[] = $segment;
                }
            }

            return implode(' / ', $deduped);
        };

        // FIXED: Use 'hotel:' prefix (matches the response)
        $rateDetails = $xml->xpath('//hotel:HotelRateDetail');

        if (empty($rateDetails)) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'hotel_id' => $hotelId,
                    'nights' => $nights,
                    'currency' => $currency,
                    'rooms' => [],
                    'message' => 'No rooms available for selected dates'
                ]
            ]);
            exit;
        }

        foreach ($rateDetails as $rateDetail) {
            // FIXED: Register namespaces on the element too
            $rateDetail->registerXPathNamespace('hotel', 'http://www.travelport.com/schema/hotel_v52_0');
            $rateDetail->registerXPathNamespace('common_v52_0', 'http://www.travelport.com/schema/common_v52_0');

            // Extract room type - FIXED XPath
            $roomType = 'Standard Room';
            $roomDescElements = $rateDetail->xpath('.//hotel:RoomRateDescription[@Name="Room"]//hotel:Text');
            if (!empty($roomDescElements)) {
                // Build room title from XML text parts and sanitize policy/rate noise.
                $roomTexts = [];
                foreach ($roomDescElements as $textElem) {
                    $roomTexts[] = trim((string)$textElem);
                }
                $roomType = $sanitizeRoomName($roomTexts, $roomType);
            }

            // Rate plan type
            $ratePlanType = (string)($rateDetail['RatePlanType'] ?? 'Standard');

            // Get Base price from HotelRateByDate - FIXED
            $basePrice = 0;
            $apiCurrency = 'AED';

            $rateByDateElements = $rateDetail->xpath('.//hotel:HotelRateByDate');
            if (!empty($rateByDateElements)) {
                $baseRate = (string)($rateByDateElements[0]['Base'] ?? '');
                if (preg_match('/([A-Z]{3})([0-9.]+)/', $baseRate, $matches)) {
                    $apiCurrency = $matches[1];
                    $basePrice = floatval($matches[2]);
                }
            }

            if ($basePrice <= 0) {
                continue; // Skip if no valid price
            }

            // Total rate
            $totalRate = (string)($rateDetail['Total'] ?? '');
            if (preg_match('/([A-Z]{3})([0-9.]+)/', $totalRate, $matches)) {
                $totalPrice = floatval($matches[2]);
            } else {
                $totalPrice = $basePrice * $nights;
            }

            // Apply markup
            $finalPrice = $totalPrice;
            try {
                $markupResult = MARKUP($totalPrice, $module, $db, $apiCurrency, $currency);
                if (isset($markupResult['price']) && $markupResult['price'] > 0) {
                    $finalPrice = $markupResult['price'];
                }
            } catch (Exception $e) {
                // Use base price if markup fails
            }

            // Price per night
            $pricePerNight = $finalPrice / $nights;
            $totalPriceWithMarkup = $finalPrice;

            // Cancellation policy - FIXED
            $cancellationFree = false;
            $cancellationText = 'Non-refundable rate. No refund on cancellation.';

            $cancelElements = $rateDetail->xpath('.//hotel:CancelInfo');
            if (!empty($cancelElements)) {
                $nonRefund = (string)($cancelElements[0]['NonRefundableStayIndicator'] ?? 'unknown');
                if ($nonRefund !== 'true') {
                    $cancellationFree = true;
                    $cancellationText = 'Free cancellation available. Full refund if cancelled before check-in.';
                }
            }

            // Board type from rate description - FIXED
            $boardName = 'Room Only';
            $boardCode = 'RO';

            $rateDescElements = $rateDetail->xpath('.//hotel:RoomRateDescription[@Name="Rate"]//hotel:Text | .//hotel:RoomRateDescription[@Name="Description"]//hotel:Text');
            $rateDescription = '';
            if (!empty($rateDescElements)) {
                $rateTexts = [];
                foreach ($rateDescElements as $textElem) {
                    $rateTexts[] = trim((string)$textElem);
                }
                $rateDescription = implode(' ', $rateTexts);
            }

            if (stripos($rateDescription, 'breakfast') !== false || stripos($roomType, 'breakfast') !== false) {
                $boardName = 'Breakfast';
                $boardCode = 'BB';
            }

            // Guarantee - FIXED
            $guarantee = '';
            $guaranteeElements = $rateDetail->xpath('.//hotel:GuaranteeInfo');
            if (!empty($guaranteeElements)) {
                $guarantee = (string)($guaranteeElements[0]['GuaranteeType'] ?? 'Deposit');
            }

            // Rate key for booking
            $rateKey = $hotelId . '_' . $ratePlanType;

            // Build option
            $current_option = [
                'option_index' => 0,
                'id' => $rateKey,
                'max_adults' => $adults,
                'price_per_night' => round($pricePerNight, 2),
                'total_price' => round($markupResult['price'], 2),
                'base_price' => round($markupResult['converted_base_price'], 2),
                'original_price' => round($markupResult['converted_base_price'], 2),
                'currency' => $currency,
                'base_currency' => $apiCurrency,
                'discount_percentage' => 0,
                'extra_bed_available' => 0,
                'extra_bed_charge' => 0,
                'breakfast_included' => ($boardCode === 'BB') ? 1 : 0,
                'cancellation_free' => $cancellationFree ? 1 : 0,
                'refundable' => $cancellationFree ? 1 : 0,
                'available_quantity' => 1,
                'board_id' => $boardCode,
                'board_name' => $boardName,
                'rate_key' => $rateKey,
                'rate_type' => 'BOOKABLE',
                'rate_comments' => $rateDescription,
                'cancellation_policies' => [],
                'cancellation_text' => $cancellationText,
                'excluded_taxes' => [],
                'room_booked' => false,
                'booking_data' => [
                    'hotel_id' => $hotelId,
                    'hotel_chain' => $hotelChain,
                    'rate_plan' => $ratePlanType,
                    'guarantee' => $guarantee
                ]
            ];

            // Group by room type
            $room_key = $roomType;

            if (!isset($room_groups[$room_key])) {
                // Assign 3 unique images per room type
                $roomImages = [];

                if (!empty($hotelImages)) {
                    // Get 3 consecutive images for this room
                    for ($i = 0; $i < 3; $i++) {
                        if (isset($hotelImages[$imageIndex])) {
                            $roomImages[] = $hotelImages[$imageIndex];
                            $imageIndex++;

                            // Loop back to start if we run out
                            if ($imageIndex >= count($hotelImages)) {
                                $imageIndex = 0;
                            }
                        }
                    }
                }

                // Fallback to placeholder if no images
                if (empty($roomImages)) {
                    $roomImages = ['https://via.placeholder.com/400x300?text=' . urlencode($roomType)];
                }

                $room_groups[$room_key] = [
                    'room_id' => $rateKey,
                    'room_type_id' => $rateKey,
                    'room_name' => $roomType,
                    'room_images' => $roomImages, // ✅ Real hotel images
                    'room_main_image' => $roomImages[0], // ✅ First image as main
                    'amenities' => [],
                    'max_adults' => $adults,
                    'max_children' => $children,
                    'options' => []
                ];
            }

            // Update option index
            $current_option['option_index'] = count($room_groups[$room_key]['options']);
            $room_groups[$room_key]['options'][] = $current_option;
        }

        // Convert to indexed array
        $roomsResponse = array_values($room_groups);

        // ========================================
        // BUILD RESPONSE (Stuba-compatible format)
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
            $response['data']['message'] = 'No rooms available for selected dates';
        }

        echo json_encode($response);

    } catch (Exception $e) {
        error_log('Travelport Rooms Error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
});
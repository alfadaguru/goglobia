<?php
// ============================================================================
// TRAVELPORT HOTEL BOOKING API - MINIMUM REQUIRED DATA ONLY
// ============================================================================
// 
// This implementation uses ONLY the elements marked as REQUIRED in the docs:
// - BookingTraveler (name, email, phone)
// - HotelRateDetail (RatePlanType, Base amount)
// - HotelProperty (HotelChain, HotelCode, HotelLocation)
// - HotelStay (CheckinDate, CheckoutDate)
// - NumberOfAdults
// - NumberOfRooms
// 
// ============================================================================

$router->post('stays/travelport/issue', function() use ($db) {

    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    
    $invoice_id = '';

    try {
        if (!$db) {
            throw new Exception('Database connection not available');
        }

        // ========================================
        // STEP 1: VALIDATE AND FETCH BOOKING
        // ========================================
        $invoice_id = $_POST['invoice_id'] ?? '';

        if (empty($invoice_id)) {
            throw new Exception('Missing invoice_id parameter');
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);

        if (!$booking) {
            throw new Exception('Booking not found');
        }

        // ========================================
        // STEP 2: GET MODULE CREDENTIALS
        // ========================================
        $moduleData = $db->get('modules', '*', [
            'name' => 'travelport',
            'type' => 'stays'
        ]);

        if (!$moduleData) {
            throw new Exception("Travelport module not configured");
        }
        
        $username = $moduleData['c1'] ?? '';
        $password = $moduleData['c2'] ?? '';
        $branchCode = $moduleData['c3'] ?? '';
        $agencyIATA = $moduleData['c4'] ?? '';
        $environment = $moduleData['env'] ?? 'test';

        if (empty($username) || empty($password) || empty($branchCode)) {
            throw new Exception("Travelport credentials incomplete");
        }
        
        if (empty($agencyIATA)) {
            throw new Exception("Agency IATA number required");
        }

        // ========================================
        // STEP 3: PARSE MINIMUM REQUIRED BOOKING DATA
        // ========================================
        $bookingData = json_decode($booking['booking_data'], true);

        if (empty($bookingData)) {
            throw new Exception('Invalid booking data');
        }

        // REQUIRED: Hotel ID, Chain, Location
        $hotelId = $bookingData['hotel_id'] ?? null;
        $hotelName = $bookingData['hotel_name'] ?? null;
        // Location (3-letter city/airport code) must come from the booking, NOT be
        // hardcoded. Previously this was `'DXB' ?? null`, forcing EVERY booking to
        // Dubai. Read the real code the search/booking flow stored.
        $hotelLocation = strtoupper(trim((string) (
            $bookingData['city_code']
            ?? $bookingData['location_code']
            ?? $bookingData['destination']
            ?? $bookingData['city']
            ?? ''
        )));

        if (empty($hotelId)) {
            throw new Exception('Hotel ID is required');
        }

        if (empty($hotelLocation)) {
            throw new Exception('Hotel location (3-letter city code) is required');
        }

        // REQUIRED: Check-in/out dates
        $checkin = $bookingData['checkin'] ?? null;
        $checkout = $bookingData['checkout'] ?? null;
        
        if (empty($checkin) || empty($checkout)) {
            throw new Exception('Check-in and check-out dates required');
        }

        // REQUIRED: Rate Plan and Hotel Chain from first room
        if (empty($bookingData['selected_rooms']) || !is_array($bookingData['selected_rooms'])) {
            throw new Exception('No rooms found in booking');
        }

        $firstRoom = $bookingData['selected_rooms'][0];
        $firstRoomOption = $firstRoom['option'] ?? [];
        
        // REQUIRED: RatePlanType (booking code)
        $ratePlan = $firstRoomOption['booking_data']['rate_plan'] ?? '';
        if (empty($ratePlan)) {
            $roomIdParts = explode('_', $firstRoom['room_id'] ?? '');
            $ratePlan = end($roomIdParts);
        }
        
        if (empty($ratePlan)) {
            throw new Exception('RatePlanType is required');
        }

        // REQUIRED: HotelChain
        $hotelChain = $firstRoomOption['booking_data']['hotel_chain'] ?? 'YR';
        
        // Clean hotel code
        $actualHotelId = preg_replace('/^[A-Z]+/', '', $hotelId);

        // REQUIRED: Number of Adults
        $adultsPerRoom = 2;
        if (!empty($bookingData['rooms_data']) && is_array($bookingData['rooms_data'])) {
            $roomData = $bookingData['rooms_data'][0] ?? [];
            $adultsPerRoom = (int)($roomData['adults'] ?? 2);
        }

        // REQUIRED: Total number of rooms
        $totalRooms = 0;
        foreach ($bookingData['selected_rooms'] as $room) {
            $totalRooms += (int)($room['quantity'] ?? 1);
        }

        // REQUIRED: Base rate (without taxes/fees)
        $basePrice = $firstRoomOption['price_per_night'] ?? null;
        if (empty($basePrice)) {
            throw new Exception('Base rate is required');
        }

        $totalPrice = $firstRoomOption['total_price']  ?? null;
        if (empty($totalPrice)) {
            throw new Exception('Base rate is required');
        }

        $currency = $firstRoomOption['currency'] ?? $firstRoomOption['base_currency'];

        // ========================================
        // STEP 4: PRIMARY GUEST DATA (REQUIRED)
        // ========================================
        $travellersData = json_decode($booking['travellers'], true);
        
        $primaryGuest = [
            'first_name' => $booking['first_name'] ?? 'Guest',
            'last_name' => $booking['last_name'] ?? 'Traveler',
            'email' => $booking['email'] ?? 'guest@example.com',
            'phone' => $booking['phone'] ?? '1234567890'
        ];

        if (!empty($travellersData) && !empty($travellersData['primary_guest'])) {
            $primaryGuest = array_merge($primaryGuest, $travellersData['primary_guest']);
        }

        // REQUIRED: Key attribute for BookingTraveler
        $travelerKey = 'TRAV_' . uniqid();

        // ========================================
        // STEP 5: DETERMINE GUARANTEE TYPE
        // ========================================
        // Note: Guarantee is NOT in minimum required data
        // But we need it for successful booking
        $guaranteeType = 'Guarantee';
        $otherGuaranteeType = 'AGT';
        $otherGuaranteeValue = $agencyIATA;

        // ========================================
        // STEP 6: BUILD MINIMAL SOAP REQUEST
        // ========================================
        $traceId = 'TP_' . date('YmdHis') . '_' . uniqid();
        
        $soapRequest = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" 
                  xmlns:hot="http://www.travelport.com/schema/hotel_v52_0" 
                  xmlns:com="http://www.travelport.com/schema/common_v52_0">
   <soapenv:Header/>
   <soapenv:Body>
      <hot:HotelCreateReservationReq 
            TargetBranch="' . $branchCode . '" 
            AuthorizedBy="Travelport"
            TraceId="' . $traceId . '">
         
         <!-- REQUIRED: Billing Point of Sale -->
         <com:BillingPointOfSaleInfo OriginApplication="UAPI"/>
         
         <!-- REQUIRED: Primary Traveler with Name, Email, Phone -->
         <com:BookingTraveler Key="' . $travelerKey . '" TravelerType="ADT">
            <com:BookingTravelerName 
                First="' . htmlspecialchars($primaryGuest['first_name']) . '"
                Last="' . htmlspecialchars($primaryGuest['last_name']) . '"/>
            <com:Email EmailID="' . htmlspecialchars($primaryGuest['email']) . '"/>
            <com:PhoneNumber Number="' . htmlspecialchars($primaryGuest['phone']) . '"/>
         </com:BookingTraveler>
         
         <!-- REQUIRED: Hotel Property with Chain, Code, Location -->
         <hot:HotelProperty 
            HotelChain="' . $hotelChain . '" 
            HotelCode="' . $actualHotelId . '"
            HotelLocation="' . $hotelLocation . '"
            Name="' . $hotelName . '"/>
         
         <!-- REQUIRED: Hotel Stay Dates -->
         <hot:HotelStay>
            <hot:CheckinDate>' . formatTravelportDate($checkin) . '</hot:CheckinDate>
            <hot:CheckoutDate>' . formatTravelportDate($checkout) . '</hot:CheckoutDate>
         </hot:HotelStay>
         
         <!-- REQUIRED: Number of Adults -->
         <hot:NumberOfAdults>' . $adultsPerRoom . '</hot:NumberOfAdults>
         
         <!-- REQUIRED: Number of Rooms (all identical) -->
         <hot:NumberOfRooms>' . $totalRooms . '</hot:NumberOfRooms>
         
         <!-- REQUIRED: Hotel Rate Detail with RatePlanType and Base -->
         <!-- Base/Total are NUMERIC amounts. Previously these concatenated the
              currency onto the number (e.g. "USD149"), which Travelport rejects;
              the currency travels in the rate context, not inside the amount. -->
         <HotelRateDetail Base="'.number_format((float)$basePrice, 2, '.', '').'" RatePlanType="'.htmlspecialchars($ratePlan).'" Total="'.number_format((float)$totalPrice, 2, '.', '').'"/>
         
      </hot:HotelCreateReservationReq>
   </soapenv:Body>
</soapenv:Envelope>';
        
        // ========================================
        // STEP 7: EXECUTE API CALL
        // ========================================
        $baseUrl = ($environment === 'live')
            ? 'https://emea.universal-api.travelport.com/B2BGateway/connect/uAPI/HotelService'
            : 'https://emea.universal-api.pp.travelport.com/B2BGateway/connect/uAPI/HotelService';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $soapRequest,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: ""',
                'Accept: text/xml',
                'Content-Length: ' . strlen($soapRequest)
            ],
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT => 60
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        // Separate request & response logs

        $logDir = __DIR__ . '../logs/';
        
        // Create folder if not exists
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $requestLogFile  = $logDir . 'travelport_request_log.json';
        $responseLogFile = $logDir . 'travelport_response_log.json';

        // Prepare REQUEST log data
        $requestData = [
            'date'       => date('Y-m-d H:i:s'),
            'http_code'  => $httpCode,
            'request'    => $soapRequest
        ];

        // Prepare RESPONSE log data
        $responseData = [
            'date'        => date('Y-m-d H:i:s'),
            'http_code'   => $httpCode,
            'curl_error'  => $curlError ?: null,
            'response'    => $response
        ];

        // Append JSON (one JSON object per line)
        $result = file_put_contents(
            $requestLogFile,
            json_encode($requestData, JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND
        );
        if ($result === false) {
    die('Failed writing request log: ' . $requestLogFile);
}

        file_put_contents(
            $responseLogFile,
            json_encode($responseData, JSON_UNESCAPED_UNICODE) . PHP_EOL,
            FILE_APPEND
        );
        // NOTE: a leftover `die('here')` used to sit here and aborted the entire
        // booking flow (response parsing, PNR extraction, DB confirm never ran).
        // Removed so the booking actually completes.
        if ($curlError) {
            throw new Exception('Connection Error: ' . $curlError);
        }

        // ========================================
        // STEP 8: PARSE MINIMAL RESPONSE
        // ========================================
        if (empty($response)) {
            throw new Exception('Empty response from Travelport');
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response);
        
        if ($xml === false) {
            throw new Exception('Invalid XML response from Travelport');
        }
        
        $xml->registerXPathNamespace('com', 'http://www.travelport.com/schema/common_v52_0');
        
        // Check for errors
        $errors = $xml->xpath('//com:Error');
        if (!empty($errors)) {
            $errorMsg = (string)($errors[0]['ErrorMessage'] ?? 'Unknown error');
            throw new Exception('Travelport Error: ' . $errorMsg);
        }
        
        // Extract Universal Record Locator (PNR)
        $records = $xml->xpath('//com:UniversalRecord');
        $confirmationNumber = !empty($records) ? (string)$records[0]['LocatorCode'] : '';
        
        if (empty($confirmationNumber)) {
            throw new Exception('No confirmation number received');
        }

        // ========================================
        // STEP 9: UPDATE DATABASE
        // ========================================
        $updateData = [
            'booking_status' => 'confirmed',
            'pnr' => $confirmationNumber,
            'confirmation_number' => $confirmationNumber,
            'supplier_reference' => $confirmationNumber,
            'error_response' => null
        ];

        $db->update('bookings', $updateData, ['invoice_id' => $invoice_id]);

        // ========================================
        // STEP 10: RETURN MINIMAL SUCCESS RESPONSE
        // ========================================
        $successResponse = [
            'status' => true,
            'Prn' => $confirmationNumber,
            'booking_reference' => $confirmationNumber,
            'invoice_id' => $invoice_id
        ];

        ob_clean();
        echo json_encode($successResponse);
        exit;

    } catch (Exception $e) {
        error_log("TRAVELPORT ERROR: " . $e->getMessage());
        
        $errorResponse = [
            'status' => false,
            'error' => $e->getMessage(),
            'invoice_id' => $invoice_id ?? ''
        ];
        
        if (!empty($invoice_id)) {
            try {
                // booking_status ENUM = confirmed|pending|cancelled ('failed'
                // would silently truncate to ''). Keep 'pending' so the booking
                // can be retried, and record the failure in error_response.
                $db->update('bookings', [
                    'booking_status' => 'pending',
                    'error_response' => $e->getMessage()
                ], ['invoice_id' => $invoice_id]);
            } catch (Exception $dbError) {
                // Ignore
            }
        }
        
        ob_clean();
        echo json_encode($errorResponse);
        exit;
    }
});

/**
 * Format date to YYYY-MM-DD
 */
function formatTravelportDate($dateString) {
    $timestamp = strtotime($dateString);
    if ($timestamp === false) {
        $parts = explode('-', $dateString);
        if (count($parts) === 3) {
            if (strlen($parts[2]) == 4) {
                $timestamp = strtotime($parts[2] . '-' . $parts[1] . '-' . $parts[0]);
            } else {
                $timestamp = strtotime($parts[0] . '-' . $parts[1] . '-' . $parts[2]);
            }
        }
    }
    return $timestamp ? date('Y-m-d', $timestamp) : date('Y-m-d');
}
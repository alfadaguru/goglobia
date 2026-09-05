<?php
// ============================================================================
// STUBA HOTEL BOOKING API ENDPOINT - COMPLETE DOCUMENTATION (V12)
// ============================================================================
//
// PURPOSE:
// Process hotel bookings via Stuba API with support for MULTIPLE ROOMS
// in a single booking, proper guest data handling, SOAP XML processing,
// and comprehensive error logging.
//
// ENDPOINT: POST /api/v1/stays/stuba/issue
//
// ============================================================================
// MULTI-ROOM BOOKING SUPPORT
// ============================================================================
//
// Stuba allows booking multiple rooms (even different room types) in a
// single booking request. Each room can have:
// - Different guest configurations (adults/children)
// - Different guest names and ages
// - Unified QuoteId for all rooms
//
// Example: Book 2x Suite + 1x Deluxe + 1x Executive in one transaction
//
// ============================================================================
// REQUEST PARAMETERS
// ============================================================================
//
// 1. invoice_id (string)           - Booking invoice identifier (REQUIRED)
//
// BOOKING DATA STRUCTURE (stored in database):
// - booking_data (JSON string):
//    * hotel_id: Stuba hotel code
//    * hotelQuoteId: Quote ID from availability search (CRITICAL)
//    * selected_rooms: Array of room selections
//      - room_id: Room type identifier
//      - room_name: Room type name
//      - quantity: Number of this room type to book
//      - option: Selected rate option with hotelQuoteId
//    * rooms_data: Room occupancy configuration
//      - adults: Number of adults per room
//      - children: Number of children per room
//      - childAges: Array of child ages
//    * currency: Booking currency code
//    * total_rooms: Total number of rooms
//    * total_adults: Total number of adults
//    * total_children: Total number of children
//    * checkin: Check-in date
//    * checkout: Check-out date
//
// 2. travellers (JSON string):
//    * primary_guest: Primary contact information
//      - title: Mr/Mrs/Ms
//      - first_name: Guest first name
//      - last_name: Guest last name
//    * travelers: Array of all guests by room
//      - adult_0, adult_1, etc.
//      - child_0, child_1, etc.
//
// MODULE CREDENTIALS (from database):
// - c1: Stuba Organization ID
// - c2: Stuba Username
// - c3: Stuba Password
// - env: Environment ('dev' or 'live')
//
// ============================================================================
// BOOKING FLOW - TWO-STEP PROCESS
// ============================================================================
//
// STEP 1: PREPARE (CommitLevel=prepare)
// - Validates booking details
// - Reserves rooms temporarily
// - Returns Booking ID
//
// STEP 2: CONFIRM (CommitLevel=confirm)
// - Finalizes the booking
// - Charges the customer
// - Returns confirmed Booking ID (PNR)
//
// ============================================================================

// ============================================================================
// STUBA HOTEL BOOKING API ENDPOINT
// ============================================================================

$router->post('stays/stuba/issue', function() use ($db) {
    
    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    function xmlToJson($xml) {
        $xml = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        $json = json_encode($xml);
        return $json;
    }

    // ========================================
    // INITIALIZATION
    // ========================================
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    // Enable error logging
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    
    // Initialize invoice_id variable
    $invoice_id = '';

    try {
        // Verify database connection
        if (!$db) {
            throw new Exception('Database connection not available');
        }

        // ========================================
        // STEP 1: VALIDATE AND FETCH BOOKING FROM DATABASE
        // ========================================
        $invoice_id = $_POST['invoice_id'] ?? '';

        if (empty($invoice_id)) {
            throw new Exception('Missing invoice_id parameter in POST data');
        }
        

        // Get booking from database
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);

        if (!$booking) {
            throw new Exception('Booking not found for invoice_id: ' . $invoice_id);
        }

        // ========================================
        // IDEMPOTENCY GUARD — prevent double charges.
        // The gateway auto-issue loopback can fire more than once (retries,
        // duplicate webhooks). Without this, a repeat call re-runs PREPARE +
        // CONFIRM on the Stuba API and books/charges twice. If the booking is
        // already confirmed / already has a supplier reference, return success
        // without re-issuing.
        // ========================================
        if (($booking['booking_status'] ?? '') === 'confirmed' || !empty($booking['pnr'])) {
            echo json_encode([
                'status'  => true,
                'success' => true,
                'message' => 'Booking already issued.',
                'invoice_id' => $invoice_id,
                'pnr' => $booking['pnr'] ?? null,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }


        // ========================================
        // STEP 2: GET MODULE CREDENTIALS
        // ========================================
        $module = $booking['module'] ?? 'stuba';
        $moduleType = $booking['module_type'] ?? 'stays';

        $moduleData = $db->get('modules', '*', [
            'name' => $module,
            'type' => $moduleType
        ]);

        if (!$moduleData) {
            throw new Exception("Module '{$module}' not found in database");
        }
        
        error_log("STUBA ISSUE: Module data retrieved");

        // Extract API credentials
        $org = $moduleData['c1'] ?? '';
        $user = $moduleData['c2'] ?? '';
        $password = $moduleData['c3'] ?? '';
        $environment = ($moduleData['dev_mode'] ?? 0) == 1 ? 'test' : 'production';

        if (empty($org) || empty($user) || empty($password)) {
            throw new Exception("API credentials not configured for module: {$module}");
        }
        

        // ========================================
        // STEP 3: DETERMINE API ENDPOINT
        // ========================================
        $apiUrl = $environment === 'production' 
            ? 'https://api.stuba.com/RXLServices/ASMX/XmlService.asmx'
            : 'https://www.stubademo.com/RXLStagingServices/ASMX/XmlService.asmx';
        
        error_log("STUBA ISSUE: API URL: " . $apiUrl);

        // ========================================
        // STEP 4: PARSE BOOKING DATA
        // ========================================
        
        $bookingData = json_decode($booking['booking_data'], true);
        $travellersData = json_decode($booking['travellers'], true);

        if (empty($bookingData)) {
            throw new Exception('Invalid booking_data in database');
        }
        

        // Extract QuoteId (CRITICAL - this is from hotelQuoteId, NOT room_id)
        $quoteId = '';

        if (!empty($bookingData['selected_rooms'][0]['option']['booking_data']['room_id'])) {
            $quoteId = $bookingData['selected_rooms'][0]['option']['booking_data']['room_id'];
        } elseif (!empty($bookingData['hotelQuoteId'])) {
            $quoteId = $bookingData['hotelQuoteId'];
        }
        
        if (empty($quoteId)) {
            throw new Exception('Missing hotelQuoteId in booking data');
        }
        

        // Get currency
        $currency = $booking['currency_markup'] ?? $bookingData['currency'] ?? 'USD';

        // ========================================
        // STEP 5: PROCESS ROOMS DATA
        // ========================================
        
        $selectedRooms = [];

        // Extract rooms information from selected_rooms
        if (!empty($bookingData['selected_rooms'])) {
            foreach ($bookingData['selected_rooms'] as $index => $room) {
                // Get occupancy from rooms_data or use defaults
                $adults = 2;
                $children = 0;
                $childAges = [];

                // Try to get occupancy from rooms_data
                if (!empty($bookingData['rooms_data'][$index])) {
                    $roomData = $bookingData['rooms_data'][$index];
                    $adults = (int)($roomData['adults'] ?? 2);
                    $children = (int)($roomData['children'] ?? 0);
                    $childAges = $roomData['childAges'] ?? [];
                }

                $selectedRooms[] = [
                    'room_code' => $room['room_id'] ?? '',
                    'room_name' => $room['room_name'] ?? '',
                    'quantity' => (int)($room['quantity'] ?? 1),
                    'adults' => $adults,
                    'children' => $children,
                    'child_ages' => $childAges
                ];
            }
        }

        if (empty($selectedRooms)) {
            throw new Exception('No rooms found in booking data');
        }
        

        // ========================================
        // STEP 6: PROCESS GUEST/TRAVELLER DATA
        // ========================================
        
        $allGuests = [];

        if (!empty($travellersData)) {
            error_log("STUBA ISSUE: Travellers data exists");
            
            // Extract primary guest first
            if (!empty($travellersData['primary_guest'])) {
                $allGuests[] = [
                    'type' => 'adult',
                    'title' => $travellersData['primary_guest']['title'] ?? 'Mr',
                    'first_name' => $travellersData['primary_guest']['first_name'] ?? '',
                    'last_name' => $travellersData['primary_guest']['last_name'] ?? ''
                ];
                error_log("STUBA ISSUE: Primary guest added");
            }

            // Extract other travelers
            if (!empty($travellersData['travelers'])) {
                
                foreach ($travellersData['travelers'] as $roomKey => $roomGuests) {
                    foreach ($roomGuests as $guestKey => $guest) {
                        // Skip if this is the primary guest (already added)
                        if ($guestKey === 'adult_0' && !empty($travellersData['primary_guest'])) {
                            continue;
                        }

                        if (strpos($guestKey, 'adult_') === 0) {
                            $allGuests[] = [
                                'type' => 'adult',
                                'title' => $guest['title'] ?? 'Mr',
                                'first_name' => $guest['first_name'] ?? '',
                                'last_name' => $guest['last_name'] ?? ''
                            ];
                        } elseif (strpos($guestKey, 'child_') === 0) {
                            $allGuests[] = [
                                'type' => 'child',
                                'title' => $guest['title'] ?? 'Mr',
                                'first_name' => $guest['first_name'] ?? '',
                                'last_name' => $guest['last_name'] ?? '',
                                'age' => (int)($guest['age'] ?? 10)
                            ];
                        }
                    }
                }
                
                error_log("STUBA ISSUE: Travelers processed, total guests: " . count($allGuests));
            }
        }

        // Fallback guest if none provided
        if (empty($allGuests)) {
            $allGuests[] = [
                'type' => 'adult',
                'title' => 'Mr',
                'first_name' => $booking['first_name'] ?? 'Guest',
                'last_name' => $booking['last_name'] ?? 'User'
            ];
        }
        

        // ========================================
        // STEP 7: BUILD XML ROOMS STRUCTURE
        // ========================================
        
        $roomsXML = '';
        $guestIndex = 0;
        $totalRooms = 0;

        // Calculate total rooms needed
        foreach ($selectedRooms as $room) {
            $totalRooms += $room['quantity'];
        }
        
        error_log("STUBA ISSUE: Total rooms: $totalRooms");

        // Reusable guests pool
        $reusableGuests = $allGuests;

        foreach ($selectedRooms as $selectedRoom) {
            
            $quantity = $selectedRoom['quantity'];
            $adultsPerRoom = $selectedRoom['adults'];
            $childrenPerRoom = $selectedRoom['children'];
            $childAges = $selectedRoom['child_ages'] ?? [];
            
            // Ensure childAges is always an array
            if (!is_array($childAges)) {
                $childAges = [];
            }

            // Create entry for each quantity of this room type
            for ($q = 0; $q < $quantity; $q++) {
                error_log("STUBA ISSUE: Building room instance $q of $quantity");
                
                $guestsXML = '';

                // Add adults for this room
                error_log("STUBA ISSUE: Adding $adultsPerRoom adults");
                for ($a = 0; $a < $adultsPerRoom; $a++) {
                    // Get guest (reuse if necessary)
                    $guestIdx = $guestIndex % count($reusableGuests);
                    $guest = $reusableGuests[$guestIdx];

                    if ($guest['type'] === 'adult') {
                        $title = htmlspecialchars($guest['title'], ENT_XML1, 'UTF-8');
                        $firstName = htmlspecialchars($guest['first_name'], ENT_XML1, 'UTF-8');
                        $lastName = htmlspecialchars($guest['last_name'], ENT_XML1, 'UTF-8');

                        $guestsXML .= "            <Adult title=\"{$title}\" first=\"{$firstName}\" last=\"{$lastName}\" />\n";
                    } else {
                        // Convert child guest to adult if needed
                        $firstName = htmlspecialchars($guest['first_name'] ?? 'Guest', ENT_XML1, 'UTF-8');
                        $lastName = htmlspecialchars($guest['last_name'] ?? 'User', ENT_XML1, 'UTF-8');
                        $guestsXML .= "            <Adult title=\"Mr\" first=\"{$firstName}\" last=\"{$lastName}\" />\n";
                    }

                    $guestIndex++;
                }
                
                error_log("STUBA ISSUE: Added adults, now adding $childrenPerRoom children");

                // Add children for this room
                for ($c = 0; $c < $childrenPerRoom; $c++) {
                    $childAge = isset($childAges[$c]) ? (int)$childAges[$c] : 10;

                    // Try to get a child guest, or use default
                    $guestIdx = $guestIndex % count($reusableGuests);
                    $guest = $reusableGuests[$guestIdx];

                    $title = htmlspecialchars($guest['title'] ?? 'Mr', ENT_XML1, 'UTF-8');
                    $firstName = htmlspecialchars($guest['first_name'] ?? 'Child', ENT_XML1, 'UTF-8');
                    $lastName = htmlspecialchars($guest['last_name'] ?? 'User', ENT_XML1, 'UTF-8');
                    $age = $guest['type'] === 'child' ? ($guest['age'] ?? $childAge) : $childAge;

                    $guestsXML .= "            <Child age=\"{$age}\" title=\"{$title}\" first=\"{$firstName}\" last=\"{$lastName}\" />\n";

                    $guestIndex++;
                }

                // Add room XML
                error_log("STUBA ISSUE: Adding room to XML");
                
                $roomsXML .= "          <Room>\n           <Guests>\n" . $guestsXML . "           </Guests>\n          </Room>\n";
                
                error_log("STUBA ISSUE: Room added");
            }
        }
        
        error_log("STUBA ISSUE: All rooms processed, building SOAP payload");

        // ========================================
        // STEP 8: SANITIZE CREDENTIALS FOR XML
        // ========================================
        $orgSafe = htmlspecialchars($org, ENT_XML1, 'UTF-8');
        $userSafe = htmlspecialchars($user, ENT_XML1, 'UTF-8');
        $passwordSafe = htmlspecialchars($password, ENT_XML1, 'UTF-8');
        $quoteIdSafe = htmlspecialchars($quoteId, ENT_XML1, 'UTF-8');
        $currencySafe = htmlspecialchars($currency, ENT_XML1, 'UTF-8');

        // PRICE RECONCILIATION: WIRED below — after the PREPARE response is parsed
        // (STEP 11) we read HotelBooking.TotalSellingPrice and reconcile before
        // CONFIRM. See the reconcilePostPaymentPrice() block after $bookingId.
        //
        // ========================================
        // STEP 9: BUILD PREPARE REQUEST (SOAP XML) - v9 Structure
        // ========================================
        $prepareXml = <<<XML
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
          {$roomsXML}
        </HotelStayDetails>
        <DetailLevel>basic</DetailLevel>
        <CommitLevel>prepare</CommitLevel>
      </xiRequest>
    </BookingCreate>
  </soap:Body>
</soap:Envelope>
XML;
        error_log("STUBA ISSUE: Prepare XML created");

        // ========================================
        // STEP 10: EXECUTE PREPARE REQUEST
        // ========================================

        $prepareResponse = sendSoapRequest($prepareXml, $apiUrl);

        error_log("STUBA ISSUE: PREPARE response received");

        // Log for debugging
        $log_setting = log_setting($db, 'stuba');
        if ($log_setting == '1') {
            $path = __DIR__ . '/../logs';
            $type = 'Stuba_Booking_Prepare';
            logApiCall('stuba_booking_prepare', $prepareXml, $prepareResponse, 200, $path, $type);
        }

        // ========================================
        // STEP 11: PROCESS PREPARE RESPONSE
        // ========================================
        
        // Extract SOAP body
        preg_match('/<soap:Body>(.*?)<\/soap:Body>/s', $prepareResponse, $matches);
        $bodyContent = $matches[1] ?? $prepareResponse;
        
        // Check for SOAP fault
        if (stripos($bodyContent, '<soap:Fault>') !== false || stripos($bodyContent, '<faultstring>') !== false) {
            preg_match('/<faultstring>(.*?)<\/faultstring>/s', $bodyContent, $faultMatches);
            $faultMsg = strip_tags($faultMatches[1] ?? 'Unknown SOAP error');
            throw new Exception('Prepare SOAP Fault: ' . $faultMsg);
        }
        
        // Check for error elements
        if (stripos($bodyContent, '<e>') !== false || stripos($bodyContent, '<Error') !== false) {
            preg_match('/<Error[^>]*>(.*?)<\/Error>/s', $bodyContent, $errorMatches);
            throw new Exception('Prepare failed: ' . strip_tags($errorMatches[1] ?? 'Unknown error'));
        }
        
        // Get booking ID from prepare response
        $prepareJson = xmlToJson($bodyContent);
        $bookingDetail = json_decode($prepareJson);
        
        $bookingId = $bookingDetail->BookingCreateResult->Booking->HotelBooking->Id ?? null;
        
        if (!$bookingId) {
            preg_match('/<Id[^>]*>(.*?)<\/Id>/s', $bodyContent, $idMatches);
            $bookingId = trim($idMatches[1] ?? '');
        }
        
        if (!$bookingId) {
            throw new Exception('No Booking ID returned in prepare response');
        }

        error_log("STUBA ISSUE: Booking ID obtained: " . $bookingId);

        // POST-PAYMENT PRICE RECONCILIATION (§8.1(2) fix): the PREPARE response
        // carries the LIVE Stuba price for the quote. Read it the same way
        // rooms.php does (HotelBooking.TotalSellingPrice.@attributes.amt) and
        // compare to what the customer paid BEFORE confirm; abort + flag if it rose
        // beyond tolerance instead of committing at the higher price.
        if (isset($booking) && is_array($booking) && function_exists('reconcilePostPaymentPrice')) {
            $stubaHotelBooking = $bookingDetail->BookingCreateResult->Booking->HotelBooking ?? null;
            $stubaLiveTotal = 0.0;
            if ($stubaHotelBooking) {
                // TotalSellingPrice may decode as ->amt or ->{'@attributes'}->amt
                $tsp = $stubaHotelBooking->TotalSellingPrice ?? null;
                if (is_object($tsp)) {
                    $stubaLiveTotal = (float) (
                        $tsp->amt
                        ?? ($tsp->{'@attributes'}->amt ?? 0)
                    );
                }
            }
            $stubaCurrency = (string) ($currency ?? $booking['currency_markup'] ?? 'USD');
            if ($stubaLiveTotal > 0) {
                $stubaPriceCheck = reconcilePostPaymentPrice($db, $booking, $stubaLiveTotal, $stubaCurrency);
                if (empty($stubaPriceCheck['ok'])) {
                    while (ob_get_level()) { ob_end_clean(); }
                    echo json_encode([
                        'status'  => false,
                        'success' => false,
                        'message' => 'Booking held for review: ' . $stubaPriceCheck['reason'],
                        'price_review' => $stubaPriceCheck,
                    ], JSON_UNESCAPED_SLASHES);
                    return;
                }
            }
        }

        // ========================================
        // STEP 12: BUILD CONFIRM REQUEST (SOAP XML) - v9 Structure
        // ========================================
        error_log("STUBA ISSUE: Building CONFIRM request");
        
        $confirmXml = <<<XML
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
          <Currency>{$currencySafe}</Currency>
          <Version>1.28</Version>
        </Authority>
        <QuoteId>{$quoteId}</QuoteId> 
        <HotelStayDetails>
          {$roomsXML}
        </HotelStayDetails>
        <DetailLevel>basic</DetailLevel>
        <CommitLevel>confirm</CommitLevel>
      </xiRequest>
    </BookingCreate>
  </soap:Body>
</soap:Envelope>
XML;
        error_log("STUBA ISSUE: Confirm XML created");

        // ========================================
        // STEP 13: EXECUTE CONFIRM REQUEST
        // ========================================
        
        $confirmResponse = sendSoapRequest($confirmXml, $apiUrl);
        
        error_log("STUBA ISSUE: CONFIRM response received");

        // Log for debugging
        if ($log_setting == '1') {
            $path = __DIR__ . '/../logs';
            $type = 'Stuba_Booking_Confirm';
            logApiCall('stuba_booking_confirm', $confirmXml, $confirmResponse, 200, $path, $type);
        }

        // ========================================
        // STEP 14: PROCESS CONFIRM RESPONSE & UPDATE DATABASE
        // ========================================
        
        // Extract SOAP body
        preg_match('/<soap:Body>(.*?)<\/soap:Body>/s', $confirmResponse, $matches);
        $confirmBody = $matches[1] ?? $confirmResponse;
        
        // Check for SOAP fault in confirm
        if (stripos($confirmBody, '<soap:Fault>') !== false || stripos($confirmBody, '<faultstring>') !== false) {
            preg_match('/<faultstring>(.*?)<\/faultstring>/s', $confirmBody, $faultMatches);
            $faultMsg = strip_tags($faultMatches[1] ?? 'Unknown SOAP error');
            throw new Exception('Confirm SOAP Fault: ' . $faultMsg);
        }
        
        // Check for error elements
        if (stripos($confirmBody, '<e>') !== false || stripos($confirmBody, '<Error') !== false) {
            preg_match('/<Error[^>]*>(.*?)<\/Error>/s', $confirmBody, $errorMatches);
            throw new Exception('Confirm failed: ' . strip_tags($errorMatches[1] ?? 'Unknown error'));
        }
        
        $confirmJson = xmlToJson($confirmBody);
        $confirmDetail = json_decode($confirmJson);
        
        $finalBookingId = $confirmDetail->BookingCreateResult->Booking->Id ?? $bookingId;
        

        // ========================================
        // STEP 15: UPDATE DATABASE WITH SUCCESS
        // ========================================
        $updateData = [
            'booking_status' => 'confirmed',
            'pnr' => $finalBookingId,
            'booking_response' => $confirmJson,
            'error_response' => null,
        ];

        $db->update('bookings', $updateData, ['invoice_id' => $invoice_id]);
        

        // ========================================
        // STEP 16: BUILD SUCCESS RESPONSE
        // ========================================
        $successResponse = [
            'status' => true,
            'Prn' => $finalBookingId,
            'booking_reference' => $finalBookingId,
            'reference' => $finalBookingId,
            'response' => json_decode($confirmJson, true),
            'response_error' => '',
            'invoice_id' => $invoice_id,
            'total_rooms_booked' => $totalRooms,
            'booking_details' => [
                'quote_id' => $quoteId,
                'total_rooms' => $totalRooms,
                'currency' => $currency
            ]
        ];

        // Clean output buffer and send response
        ob_clean();
        http_response_code(200);
        echo json_encode($successResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Exception $e) {
        // Handle any exceptions thrown during processing
        $errorMessage = $e->getMessage();
        $errorFile = $e->getFile();
        $errorLine = $e->getLine();
        $errorTrace = $e->getTraceAsString();
        
        // Log detailed error information
        error_log("STUBA ISSUE ERROR: " . $errorMessage);
        error_log("File: " . $errorFile . " Line: " . $errorLine);
        error_log("Trace: " . $errorTrace);
        
        $errorResponse = [
            'status' => false,
            'Prn' => '',
            'booking_reference' => '',
            'reference' => '',
            'response' => '',
            'response_error' => $errorMessage,
            'error' => $errorMessage,
            'message' => $errorMessage,
            'invoice_id' => $invoice_id ?? '',
            'http_code' => 500,
            'debug' => [
                'file' => basename($errorFile),
                'line' => $errorLine,
                'trace' => explode("\n", $errorTrace)
            ]
        ];
        
        // Update booking error_response if we have invoice_id. An EXCEPTION during
        // booking means it did NOT succeed — must NOT be marked 'confirmed' (the
        // old code did, masking a failed booking as confirmed). Use 'pending'.
        if (!empty($invoice_id)) {
            try {
                $db->update('bookings', [
                    'booking_status' => 'pending',
                    'error_response' => json_encode($errorResponse)
                ], [
                    'invoice_id' => $invoice_id
                ]);
            } catch (Exception $dbError) {
                // Log database error but don't interrupt response
                error_log("Failed to save error response: " . $dbError->getMessage());
            }
        }
        
        // Clean output buffer and send response
        ob_clean();
        http_response_code(500); // ADD THIS LINE
        echo json_encode($errorResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        // Catch PHP 7+ errors (like TypeError, ArgumentCountError, etc.)
        $errorMessage = $e->getMessage();
        $errorFile = $e->getFile();
        $errorLine = $e->getLine();
        
        error_log("STUBA ISSUE PHP ERROR: " . $errorMessage . " in " . $errorFile . " line " . $errorLine);
        
        $errorResponse = [
            'status' => false,
            'Prn' => '',
            'booking_reference' => '',
            'reference' => '',
            'response' => '',
            'response_error' => 'PHP Error: ' . $errorMessage,
            'error' => 'PHP Error: ' . $errorMessage,
            'message' => 'PHP Error: ' . $errorMessage,
            'invoice_id' => $invoice_id ?? '',
            'http_code' => 500,
            'debug' => [
                'file' => basename($errorFile),
                'line' => $errorLine,
                'type' => get_class($e)
            ]
        ];
        
        ob_clean();
        http_response_code(500); // ADD THIS LINE
        echo json_encode($errorResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
});

// ============================================================================
// HELPER FUNCTIONS - SOAP REQUEST HANDLING
// ============================================================================

/**
 * SEND SOAP REQUEST TO STUBA API (v9 compatible)
 * 
 * @param string $xml SOAP XML payload
 * @param string $url API endpoint URL
 * @return string Response body
 * @throws Exception on cURL error
 */
function sendSoapRequest($xml, $url) {
    $headers = [
        'Content-Type: text/xml; charset=utf-8',
        'SOAPAction: "http://www.reservwire.com/namespace/WebServices/Xml/BookingCreate"',
        'Content-Length: ' . strlen($xml)
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $xml,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_VERBOSE => false
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    
    if ($error) {
        throw new Exception("cURL Error: " . $error);
    }
    
    return $response;
}
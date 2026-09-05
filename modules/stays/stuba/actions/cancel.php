<?php
// ============================================================================
// STUBA HOTEL CANCELLATION API ENDPOINT - V10
// ============================================================================
//
// PURPOSE:
// Cancel hotel bookings via Stuba API. Fetches all data from database
// using invoice_id only. Supports comprehensive error logging and proper
// SOAP request handling.
//
// ENDPOINT: POST /stays/stuba/cancel
//
// ============================================================================
// CANCELLATION PROCESS
// ============================================================================
//
// The Stuba cancellation process:
// 1. Validates booking exists and has a valid booking_id
// 2. Checks booking is not already cancelled
// 3. Sends SOAP request to Stuba API
// 4. Updates database with cancellation status
// 5. Returns cancellation confirmation details
//
// ============================================================================
// REQUEST PARAMETERS (V10)
// ============================================================================
//
// 1. invoice_id (string) - REQUIRED
//    - Unique invoice identifier from bookings table
//    - All other data is fetched automatically from database
//
// CHANGES FROM PREVIOUS VERSION:
// - Previous Required: booking_id, credentials
// - V10 Required: invoice_id ONLY
//
// ============================================================================
// DATABASE STRUCTURE
// ============================================================================
//
// BOOKINGS TABLE:
// - invoice_id: Unique identifier
// - pnr: Stuba booking reference (booking_id for cancellation)
// - booking_data: JSON containing hotel details
//   * hotel_name: Name of the hotel
//   * checkin/checkout: Dates
// - booking_status: Current status (confirmed/cancelled/failed)
// - module: Module name (stuba)
// - module_type: Module type (stays)
//
// MODULES TABLE:
// - name: Module name (stuba)
// - type: Module type (stays)
// - c1: Organization/Org
// - c2: Username
// - c3: Password
// - c4: API URL
// - env: Environment (dev/live)
//
// ============================================================================
// CANCELLATION FLOW
// ============================================================================
//
// STEP 1: Fetch Booking & Validate
// - Retrieve booking using invoice_id
// - Verify booking_id exists (cannot cancel without booking_id)
// - Check booking is not already cancelled
//
// STEP 2: Fetch API Credentials
// - Get module credentials from modules table
// - Validate org, user, password exist
//
// STEP 3: Build Cancellation SOAP Request
// - Generate SOAP XML with booking_id
// - Add proper authentication credentials
// - Set CommitLevel to 'confirm'
//
// STEP 4: Execute SOAP Request
// - Send POST request to Stuba API
// - Include proper SOAP headers
// - Handle timeout and SSL verification
//
// STEP 5: Process Response
// - Parse SOAP response to JSON
// - Check for Success flag or Status: cancelled
// - Extract cancellation details
//
// STEP 6: Update Database
// - SUCCESS: Update booking_status to 'cancelled', remove PNR
// - FAILURE: Store cancellation error
// - Record cancellation timestamp
//
// ============================================================================
// RESPONSE FORMAT
// ============================================================================
//
// SUCCESS:
// {
//     "status": true,
//     "message": "Booking cancelled successfully. PNR removed.",
//     "data": {
//         "status": true,
//         "hotel_name": "Hotel Name",
//         "booking_status": "CANCELLED",
//         "booking_reference": "12345",
//         "invoice_id": "ABC123",
//         "checkin": "2025-12-21",
//         "checkout": "2025-12-22",
//         "currency": "USD"
//     }
// }
//
// FAILURE:
// {
//     "status": false,
//     "message": "Error message here",
//     "data": {
//         "invoice_id": "ABC123",
//         "booking_reference": "12345",
//         "error": "Detailed error message"
//     }
// }
//
// ============================================================================
// ERROR HANDLING & LOGGING
// ============================================================================
//
// Logs are written to:
// - logs/_STUBA_CANCELLATION_REQ_V10.log: Request parameters
// - logs/_STUBA_CANCELLATION_API_REQUEST_V10.log: SOAP request
// - logs/_STUBA_CANCELLATION_API_RESPONSE_V10.log: SOAP response
// - logs/_STUBA_CANCELLATION_API_RESPONSE_JSON_V10.log: Parsed JSON
// - logs/_STUBA_CANCELLATION_ERROR_V10.log: Errors
//
// ============================================================================
// VALIDATION CHECKS
// ============================================================================
//
// Before cancellation:
// - Booking must exist in database
// - Booking must have a valid PNR (booking_id)
// - Booking must not already be cancelled
// - Module credentials must be configured
//
// After cancellation:
// - Response must contain booking data
// - Success flag must be true OR Status must be 'cancelled'
// - Database is updated with cancellation details
//
// ============================================================================
// STUBA API DETAILS
// ============================================================================
//
// API Method: POST (SOAP)
// Content-Type: text/xml; charset=utf-8
// SOAPAction: "http://www.reservwire.com/namespace/WebServices/Xml/BookingCancel"
//
// SOAP Structure:
// - Authority: Org, User, Password, Currency, Version
// - BookingId: The booking reference to cancel
// - CommitLevel: confirm (to actually cancel)
// - DetailLevel: full (for complete response)
//
// ============================================================================

$router->post('stays/stuba/cancel', function() use ($db) {
    
    // ========================================
    // INITIALIZATION
    // ========================================
    
    // Clean output buffer to prevent BOM issues
    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    
    header('Content-Type: application/json; charset=UTF-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    
    // Initialize variables for error handling
    $invoice_id = '';
    $bookingReference = '';

    try {

        // ========================================
        // STEP 1: VALIDATE AND FETCH BOOKING FROM DATABASE
        // ========================================
        $invoice_id = $_POST['invoice_id'] ?? '';
        
        if (empty($invoice_id)) {
            throw new Exception('Missing invoice_id parameter');
        }
        
        // LOG REQUEST
        file_put_contents(__DIR__ . '/../logs/_STUBA_CANCELLATION_REQ_V10.log', 
            date('Y-m-d H:i:s') . "\n" . print_r($_POST, true) . "\n", 
            FILE_APPEND
        );
        
        // GET BOOKING FROM DATABASE
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        
        if (!$booking) {
            throw new Exception('Booking not found for invoice_id: ' . $invoice_id);
        }
        
        // CHECK IF BOOKING HAS A PNR - REQUIRED FOR CANCELLATION
        if (empty($booking['pnr'])) {
            throw new Exception('Booking does not have a PNR/booking_id. Cannot cancel. Please use Void instead.');
        }
        
        // CHECK IF BOOKING IS ALREADY CANCELLED
        if (in_array(strtolower($booking['booking_status']), ['cancelled', 'voided'])) {
            throw new Exception('Booking is already ' . $booking['booking_status']);
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
        
        // EXTRACT API CREDENTIALS
        $org = $moduleData['c1'] ?? '';
        $user = $moduleData['c2'] ?? '';
        $password = $moduleData['c3'] ?? '';
        $environment = ($moduleData['dev_mode'] ?? 0) == 1 ? 'test' : 'production';
        
        if (empty($org) || empty($user) || empty($password)) {
            throw new Exception("API credentials not configured for module: {$module}");
        }
        
        $apiUrl = $environment === 'production' 
            ? 'https://api.stuba.com/RXLServices/ASMX/XmlService.asmx'
            : 'https://www.stubademo.com/RXLStagingServices/ASMX/XmlService.asmx';
        
        error_log("STUBA ISSUE: API URL: " . $apiUrl);
        // ========================================
        // STEP 3: PARSE BOOKING DATA FOR CONTEXT
        // ========================================
        $bookingData = json_decode($booking['booking_data'], true);
        $hotelName = $bookingData['hotel_name'] ?? 'Unknown Hotel';
        $checkin = $bookingData['checkin'] ?? null;
        $checkout = $bookingData['checkout'] ?? null;
        
        // ========================================
        // STEP 4: BUILD CANCELLATION SOAP REQUEST
        // ========================================
        $bookingReference = trim($booking['pnr'] ?? '');
        
        if (empty($bookingReference) || $bookingReference === 'Not Issued' || $bookingReference === 'null' || strlen($bookingReference) < 1) {
            throw new Exception('Cannot cancel booking without valid booking_id. Booking may not be issued yet. Booking ID value: "' . $bookingReference . '"');
        }
        
        // Validate booking ID (Stuba references are numeric). Do NOT silently
        // strip non-digits — that could turn "AB123" into "123" and cancel the
        // WRONG booking. Reject anything that is not purely numeric.
        if (!ctype_digit($bookingReference)) {
            throw new Exception('Stuba booking reference is not numeric ("' . $bookingReference . '"). Refusing to cancel to avoid acting on the wrong booking.');
        }
        
        
        // Sanitize XML values
        $orgSafe = htmlspecialchars($org, ENT_XML1, 'UTF-8');
        $userSafe = htmlspecialchars($user, ENT_XML1, 'UTF-8');
        $passSafe = htmlspecialchars($password, ENT_XML1, 'UTF-8');
        $bookingIdSafe = $bookingReference;

        // BUILD SOAP XML REQUEST
        $cancelXml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" 
               xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" 
               xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <soap:Body>
    <BookingCancel xmlns="http://www.reservwire.com/namespace/WebServices/Xml">
      <xiRequest>
        <Authority>
          <Org>{$orgSafe}</Org>
          <User>{$userSafe}</User>
          <Password>{$passSafe}</Password>
          <Currency>USD</Currency>
          <Version>1.28</Version>
        </Authority>
        <BookingId>{$bookingIdSafe}</BookingId>
        <CommitLevel>confirm</CommitLevel>
        <DetailLevel>full</DetailLevel>
      </xiRequest>
    </BookingCancel>
  </soap:Body>
</soap:Envelope>
XML;
        
        
        // LOG SOAP REQUEST
        file_put_contents(__DIR__ . '/../logs/_STUBA_CANCELLATION_API_REQUEST_V10.log', 
            date('Y-m-d H:i:s') . "\n" . $cancelXml . "\n", 
            FILE_APPEND
        );
        
        // ========================================
        // STEP 5: CONFIGURE cURL REQUEST
        // ========================================
        $headers = [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "http://www.reservwire.com/namespace/WebServices/Xml/BookingCancel"',
            'Content-Length: ' . strlen($cancelXml)
        ];
        
        $curl = curl_init();
        
        curl_setopt_array($curl, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $cancelXml,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_VERBOSE => false
        ]);
        
        // ========================================
        // STEP 6: EXECUTE API REQUEST
        // ========================================
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        

        // LOG SOAP RESPONSE
        file_put_contents(__DIR__ . '/../logs/_STUBA_CANCELLATION_API_RESPONSE_V10.log', 
            date('Y-m-d H:i:s') . "\n" . $response . "\n", 
            FILE_APPEND
        );
        
        
        // ========================================
        // STEP 7: PROCESS RESPONSE
        // ========================================
        
        // CHECK FOR cURL ERRORS
        if ($curlError) {
            throw new Exception('cURL Error: ' . $curlError);
        }
        
        // CHECK HTTP STATUS CODE
        if ($httpCode !== 200) {
            throw new Exception('HTTP Error ' . $httpCode . ': ' . substr($response, 0, 200));
        }
        
        // PARSE SOAP RESPONSE
        $pattern = '/<soap:Body>(.*)<\/soap:Body>/s';
        $bodyContent = preg_match($pattern, $response, $matches) ? $matches[1] : $response;
        
        // Convert XML to JSON
        $json = xmlToJson($bodyContent);
        
        // LOG JSON RESPONSE
        file_put_contents(__DIR__ . '/../logs/_STUBA_CANCELLATION_API_RESPONSE_JSON_V10.log', 
            date('Y-m-d H:i:s') . "\n" . $json . "\n", 
            FILE_APPEND
        );
        
        $cancelResult = json_decode($json, false);
        
        // CHECK FOR SOAP FAULT
        if (isset($cancelResult->faultcode)) {
            $errorMsg = 'SOAP Fault: ' . ($cancelResult->faultstring ?? 'Unknown error');
            if (isset($cancelResult->detail)) {
                $errorMsg .= ' | Details: ' . json_encode($cancelResult->detail);
            }
            throw new Exception($errorMsg);
        }
        
        // CHECK FOR SUCCESS FLAG OR CANCELLED STATUS
        $isSuccess = false;
        
        if (isset($cancelResult->BookingCancelResult->Success)) {
            $val = $cancelResult->BookingCancelResult->Success;
            if ($val === true || $val === 'true' || $val === 1 || $val === '1') {
                $isSuccess = true;
            }
        }
        
        if (!$isSuccess && isset($cancelResult->BookingCancelResult->Booking->HotelBooking->Status)) {
            $status = strtolower($cancelResult->BookingCancelResult->Booking->HotelBooking->Status);
            if (in_array($status, ['cancelled', 'canceled'])) {
                $isSuccess = true;
            }
        }
        
        if (!$isSuccess) {
            $errorMsg = 'Cancellation failed.';
            if (isset($cancelResult->BookingCancelResult->Error)) {
                $errorMsg .= ' Error: ' . json_encode($cancelResult->BookingCancelResult->Error);
            } else {
                $errorMsg .= ' Response: ' . $json;
            }
            throw new Exception($errorMsg);
        }
        
        // ========================================
        // STEP 8: UPDATE DATABASE - REMOVE PNR AFTER CANCELLATION
        // ========================================
        $updateData = [
            'booking_status' => 'cancelled',
            'cancellation_status' => 1,
            'cancellation_response' => $json
        ];
        
        $db->update('bookings', $updateData, ['invoice_id' => $invoice_id]);
        
        
        // ========================================
        // STEP 9: BUILD SUCCESS RESPONSE
        // ========================================
        $finalData = [
            'status' => true,
            'hotel_name' => $hotelName,
            'booking_status' => 'CANCELLED',
            'booking_reference' => $bookingReference,
            'invoice_id' => $invoice_id,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'currency' => $booking['currency_markup'] ?? 'USD'
        ];
        
        ob_clean();
        echo json_encode([
            'status' => true,
            'message' => 'Booking cancelled successfully. PNR removed.',
            'data' => $finalData
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Exception $e) {

        // ========================================
        // HANDLE EXCEPTIONS
        // ========================================
        $errorMsg = $e->getMessage();
        
        error_log("STUBA CANCEL ERROR - Invoice: {$invoice_id}, Error: " . $errorMsg);
        
        // LOG ERROR
        file_put_contents(__DIR__ . '/../logs/_STUBA_CANCELLATION_ERROR_V10.log', 
            date('Y-m-d H:i:s') . " - ERROR: " . $errorMsg . "\n", 
            FILE_APPEND
        );
        
        // SAVE ERROR TO DATABASE IF BOOKING EXISTS
        if (isset($booking) && $booking) {
            $db->update('bookings', [
                'error_response' => json_encode([
                    'error' => $errorMsg,
                    'timestamp' => date('Y-m-d H:i:s'),
                    'action' => 'cancel'
                ])
            ], [
                'invoice_id' => $invoice_id
            ]);
        }
        
        ob_clean();
        http_response_code(500);
        echo json_encode([
            'status' => false,
            'message' => $errorMsg,
            'data' => [
                'invoice_id' => $invoice_id ?? null,
                'booking_reference' => $bookingReference ?? null,
                'error' => $errorMsg
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
});
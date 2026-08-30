<?php
// ============================================================================
// HOTELBEDS HOTEL VOID/CANCELLATION API ENDPOINT - V10
// ============================================================================
//
// PURPOSE:
// Void/cancel hotel bookings via Hotelbeds API and clear PNR from database.
// Fetches all data from database using invoice_id only. Supports mTLS 
// certificate authentication and comprehensive error logging.
//
// ENDPOINT: POST /stays/hotelbeds/void
//
// ============================================================================
// VOID vs CANCEL
// ============================================================================
//
// VOID: Cancels booking AND clears PNR from database (fresh start)
// CANCEL: Cancels booking but keeps PNR for records
//
// Use VOID when:
// - Booking was issued by mistake
// - Need to re-issue with different details
// - Want to remove all traces of booking reference
//
// ============================================================================
// REQUEST PARAMETERS
// ============================================================================
//
// 1. invoice_id (string) - REQUIRED
//    - Unique invoice identifier from bookings table
//    - All other data is fetched automatically from database
//
// ============================================================================
// DATABASE STRUCTURE
// ============================================================================
//
// BOOKINGS TABLE:
// - invoice_id: Unique identifier
// - pnr: Hotelbeds booking reference (will be cleared after void)
// - booking_data: JSON containing hotel details
// - booking_status: Current status (changed to 'voided')
// - module: Module name (hotelbeds)
// - module_type: Module type (stays)
//
// MODULES TABLE:
// - name: Module name (hotelbeds)
// - type: Module type (stays)
// - c1: API Key
// - c2: API Secret
// - env: Environment (dev/live)
// - use_mtls: mTLS enabled (0/1)
//
// ============================================================================
// VOID FLOW
// ============================================================================
//
// STEP 1: Fetch Booking & Validate
// STEP 2: Fetch API Credentials from Modules Table
// STEP 3: Call Hotelbeds Cancellation API
// STEP 4: Update Database - Clear PNR and Set Status to 'voided'
// STEP 5: Return Success Response
//
// ============================================================================

if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

$router->post('stays/hotelbeds/void', function() use ($db) {

    // ========================================
    // INITIALIZATION
    // ========================================
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    try {

        // ========================================
        // STEP 1: VALIDATE AND FETCH BOOKING FROM DATABASE
        // ========================================
        $invoice_id = $_POST['invoice_id'] ?? '';

        if (empty($invoice_id)) {
            throw new Exception('Missing invoice_id parameter');
        }

        // GET BOOKING FROM DATABASE
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);

        if (!$booking) {
            throw new Exception('Booking not found for invoice_id: ' . $invoice_id);
        }

        // CHECK IF BOOKING HAS PNR
        $bookingReference = trim($booking['pnr'] ?? '');

        if (empty($bookingReference) || $bookingReference === 'Not Issued' || $bookingReference === 'null' || strlen($bookingReference) < 3) {
            throw new Exception('Cannot void booking without valid PNR. Booking may not be issued yet. PNR value: "' . $bookingReference . '"');
        }

        // Remove any whitespace or special characters that might cause issues
        $bookingReference = preg_replace('/[^a-zA-Z0-9\-_]/', '', $bookingReference);
        
        if (empty($bookingReference)) {
            throw new Exception('PNR contains only invalid characters. Original PNR: "' . $booking['pnr'] . '"');
        }


        // CHECK IF ALREADY VOIDED/CANCELLED
        if (in_array($booking['booking_status'], ['voided', 'cancelled'])) {
            throw new Exception('Booking is already ' . $booking['booking_status']);
        }

        // ========================================
        // STEP 2: GET MODULE CREDENTIALS
        // ========================================
        $module = $booking['module'] ?? 'hotelbeds';
        $moduleType = $booking['module_type'] ?? 'stays';

        $moduleData = $db->get('modules', '*', [
            'name' => $module,
            'type' => $moduleType
        ]);

        if (!$moduleData) {
            throw new Exception("Module '{$module}' not found in database");
        }

        // EXTRACT API CREDENTIALS
        $apiKey = $moduleData['c1'] ?? '';
        $apiSecret = $moduleData['c2'] ?? '';
        $environment = (($moduleData['dev_mode'] ?? '1') == '0') ? 'live' : 'dev';
        $hotelbedsSettings = function_exists('readHotelbedsSettings')
            ? readHotelbedsSettings()
            : ['use_mtls' => 0];
        $transport = function_exists('hotelbedsResolveBookingTransport')
            ? hotelbedsResolveBookingTransport($moduleData, $hotelbedsSettings)
            : [
                'use_mtls' => (($hotelbedsSettings['use_mtls'] ?? 0) == 1),
                'error' => null,
            ];
        $useMtls = $transport['use_mtls'];

        if (!empty($transport['error'])) {
            throw new Exception($transport['error']);
        }

        if (empty($apiKey) || empty($apiSecret)) {
            throw new Exception("API credentials not configured for module: {$module}");
        }

        // ========================================
        // STEP 3: DETERMINE API ENDPOINT
        // ========================================
        $baseUrl = function_exists('hotelbedsBookingApiBaseUrl')
            ? hotelbedsBookingApiBaseUrl($environment, $useMtls)
            : ($useMtls
                ? ($environment === 'live' ? 'https://api-mtls.hotelbeds.com/hotel-api/1.0' : 'https://api-mtls.test.hotelbeds.com/hotel-api/1.0')
                : ($environment === 'live' ? 'https://api.hotelbeds.com/hotel-api/1.0' : 'https://api.test.hotelbeds.com/hotel-api/1.0'));

        // Use cleaned PNR without additional encoding (already sanitized)
        $cancellationUrl = $baseUrl . '/bookings/' . $bookingReference . '?cancellationFlag=CANCELLATION';


        // ========================================
        // STEP 4: GENERATE X-SIGNATURE
        // ========================================
        $timestamp = time();
        $xSignature = hash('sha256', $apiKey . $apiSecret . $timestamp);

        // ========================================
        // STEP 5: CONFIGURE cURL REQUEST (DELETE METHOD)
        // ========================================
        $curl = curl_init();

        // APPLY mTLS IF ENABLED
        if (function_exists('hotelbedsApplyMtlsCurlOptions')) {
            hotelbedsApplyMtlsCurlOptions($curl, $useMtls);
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $cancellationUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                'Api-key: ' . $apiKey,
                'X-Signature: ' . $xSignature,
                'Accept: application/json',
                'Accept-Encoding: gzip',
                'Content-Type: application/json'
            ]
        ]);

        // ========================================
        // STEP 6: EXECUTE API REQUEST
        // ========================================
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);


        // LOG DETAILED REQUEST/RESPONSE FOR DEBUGGING
        error_log("VOID DETAILED - Invoice: {$invoice_id}");
        error_log("VOID DETAILED - Original PNR from DB: [{$booking['pnr']}]");
        error_log("VOID DETAILED - Cleaned PNR: [{$bookingReference}]");
        error_log("VOID DETAILED - URL: {$cancellationUrl}");
        error_log("VOID DETAILED - HTTP Code: {$httpCode}");

        // LOG API CALL IF LOGGING ENABLED
        $log_setting = log_setting($db, 'hotelbeds');

        if ($log_setting == '1') {
            $apiResponseDecoded = !empty($response) ? json_decode($response, true) : ['error' => 'Empty response'];

            $path = function_exists('hotelbedsLogDir') ? hotelbedsLogDir() : (dirname(__DIR__) . '/logs');
            $type = 'Hotelbeds_Void';
            logApiCall('hotelbeds_void', ['booking_reference' => $bookingReference], $apiResponseDecoded, $httpCode, $path, $type);
        }


        // ========================================
        // STEP 7: PROCESS RESPONSE
        // ========================================
        $responseData = json_decode($response, true);

        // CHECK FOR cURL ERRORS
        if ($curlError) {
            throw new Exception('cURL Error: ' . $curlError);
        }

        // CHECK HTTP STATUS CODE
        if ($httpCode !== 200) {
            $errorMessage = 'HTTP Error ' . $httpCode;
            
            if (is_array($responseData) && isset($responseData['error'])) {
                $errorMessage .= ': ' . ($responseData['error']['message'] ?? json_encode($responseData['error']));
            }
            
            throw new Exception($errorMessage);
        }

        // VALIDATE CANCELLATION SUCCESS
        $bookingStatus = $responseData['booking']['status'] ?? '';

        if ($bookingStatus !== 'CANCELLED') {
            throw new Exception('Cancellation failed. Status returned: ' . ($bookingStatus ?: 'Unknown'));
        }

        // ========================================
        // STEP 8: UPDATE DATABASE - CLEAR PNR AND SET STATUS TO VOIDED
        // ========================================
        $updateData = [
            'booking_status' => 'voided',
            'pnr' => null, // CLEAR PNR
            'booking_response' => json_encode($responseData), // SAVE VOID RESPONSE
            'error_response' => null // CLEAR ANY PREVIOUS ERRORS
        ];

        $db->update('bookings', $updateData, ['invoice_id' => $invoice_id]);


        // ========================================
        // STEP 9: BUILD SUCCESS RESPONSE
        // ========================================
        $successResponse = [
            'status' => true,
            'message' => 'Booking voided successfully. PNR has been cleared from database.',
            'data' => [
                'invoice_id' => $invoice_id,
                'previous_pnr' => $bookingReference,
                'booking_status' => 'voided',
                'cancellation_reference' => $responseData['booking']['reference'] ?? $bookingReference,
                'cancellation_date' => date('Y-m-d H:i:s'),
                'api_status' => $bookingStatus,
                'pnr_cleared' => true
            ],
            'response' => $responseData
        ];

        // CLEAN OUTPUT BUFFER AND SEND RESPONSE
        ob_clean();
        echo json_encode($successResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Exception $e) {

        // ========================================
        // HANDLE EXCEPTIONS
        // ========================================
        error_log("VOID ERROR - Invoice: {$invoice_id}, Error: " . $e->getMessage());

        // SAVE ERROR TO DATABASE IF BOOKING EXISTS
        if (isset($booking) && $booking) {
            $db->update('bookings', [
                'error_response' => json_encode([
                    'error' => $e->getMessage(),
                    'timestamp' => date('Y-m-d H:i:s'),
                    'action' => 'void'
                ])
            ], [
                'invoice_id' => $invoice_id
            ]);
        }

        $errorResponse = [
            'status' => false,
            'message' => $e->getMessage(),
            'data' => [
                'invoice_id' => $invoice_id ?? null,
                'pnr' => $bookingReference ?? null,
                'error' => $e->getMessage()
            ]
        ];

        ob_clean();
        echo json_encode($errorResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
});

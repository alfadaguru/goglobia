<?php
// ============================================================================
// AMADEUS FLIGHT VOID/CANCEL API ENDPOINT
// ============================================================================
//
// PURPOSE:
// Cancel/void a flight booking via Amadeus Flight Order Management API
//
// ENDPOINT: POST /flights/amadeus/void
//
// ============================================================================
// REQUEST PARAMETERS
// ============================================================================
//
// invoice_id (string) - Required - Booking invoice ID from database
//
// ============================================================================
// AMADEUS FLIGHT ORDER DELETE API
// ============================================================================
//
// API Endpoint: DELETE /v1/booking/flight-orders/{orderId}
// Documentation: https://developers.amadeus.com/self-service/category/air/api-doc/flight-order-management
//
// Required:
// - Order ID from the booking
// - OAuth token
//
// Response:
// - 200 OK: Order successfully cancelled
// - Order status and details
//
// ============================================================================

$router->post('flights/amadeus/void', function() use ($db) {

    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

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


        // Check if already cancelled
        if ($booking['booking_status'] === 'cancelled' || $booking['booking_status'] === 'voided') {

            ob_clean();
            echo json_encode([
                'status' => true,
                'message' => 'Booking already cancelled',
                'invoice_id' => $invoice_id,
                'booking_status' => $booking['booking_status']
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Check if PNR exists
        if (empty($booking['pnr'])) {
            // Update status to cancelled locally since it was never issued
            $db->update('bookings', [
                'booking_status' => 'cancelled'
            ], ['invoice_id' => $invoice_id]);
            
            ob_clean();
            echo json_encode([
                'status' => true,
                'message' => 'Booking cancelled locally. No PNR was issued, so no action required with airline.',
                'invoice_id' => $invoice_id,
                'note' => 'This booking was never successfully issued with the airline.'
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Check if booking was actually successful
        if ($booking['booking_status'] === 'failed' || $booking['booking_status'] === 'pending') {
            // Update to cancelled locally
            $db->update('bookings', [
                'booking_status' => 'cancelled'
            ], ['invoice_id' => $invoice_id]);
            
            ob_clean();
            echo json_encode([
                'status' => true,
                'message' => 'Booking cancelled. This booking was not confirmed with the airline.',
                'invoice_id' => $invoice_id,
                'previous_status' => $booking['booking_status']
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ========================================
        // STEP 2: GET MODULE CREDENTIALS
        // ========================================
        $module = $booking['module'] ?? 'amadeus';
        $moduleType = $booking['module_type'] ?? 'flights';

        $moduleData = $db->get('modules', '*', [
            'name' => $module,
            'type' => $moduleType
        ]);

        if (!$moduleData) {
            throw new Exception('Module configuration not found');
        }

        error_log("AMADEUS VOID: Module data retrieved");

        $clientId = $moduleData['c1'] ?? '';
        $clientSecret = $moduleData['c2'] ?? '';
        $environment = $moduleData['env'] ?? 'test';

        if (empty($clientId) || empty($clientSecret)) {
            throw new Exception('Amadeus API credentials not configured');
        }


        // Set API endpoints based on environment
        if ($environment === 'production') {
            $end_pointv1 = 'https://api.amadeus.com/v1/';
        } else {
            $end_pointv1 = 'https://test.api.amadeus.com/v1/';
        }

        // ========================================
        // STEP 3: GET OAUTH TOKEN
        // ========================================
        error_log("AMADEUS VOID: Requesting OAuth token");

        $tokenCurl = curl_init();
        curl_setopt($tokenCurl, CURLOPT_URL, $end_pointv1 . 'security/oauth2/token');
        curl_setopt($tokenCurl, CURLOPT_POST, true);
        curl_setopt($tokenCurl, CURLOPT_POSTFIELDS, "grant_type=client_credentials&client_id=" . $clientId . "&client_secret=" . $clientSecret);
        curl_setopt($tokenCurl, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
        curl_setopt($tokenCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($tokenCurl, CURLOPT_TIMEOUT, 30);
        $token = curl_exec($tokenCurl);
        $tokenHttpCode = curl_getinfo($tokenCurl, CURLINFO_HTTP_CODE);

        $tokenData = json_decode($token, true);

        if (empty($tokenData) || !isset($tokenData['access_token'])) {
            throw new Exception('Failed to get OAuth token from Amadeus API: ' . ($tokenData['error_description'] ?? 'Unknown error'));
        }

        error_log("AMADEUS VOID: OAuth token obtained");

        // ========================================
        // STEP 4: PARSE BOOKING RESPONSE TO GET ORDER ID
        // ========================================
        error_log("AMADEUS VOID: Extracting order ID from booking response");

        $bookingResponse = $booking['booking_response'] ?? '';
        $orderId = null;

        if (!empty($bookingResponse)) {
            $responseData = json_decode($bookingResponse, true);
            if (isset($responseData['data']['id'])) {
                $orderId = $responseData['data']['id'];
            }
        }

        if (empty($orderId)) {
            // No order ID means booking was never confirmed with Amadeus
            // Cancel locally only
            error_log("AMADEUS VOID: No order ID found, cancelling locally only");
            
            $db->update('bookings', [
                'booking_status' => 'cancelled'
            ], ['invoice_id' => $invoice_id]);
            
            ob_clean();
            echo json_encode([
                'status' => true,
                'message' => 'Booking cancelled locally. No order ID found - booking was not confirmed with airline.',
                'invoice_id' => $invoice_id,
                'pnr' => $booking['pnr'],
                'note' => 'Payment may have been received but airline booking failed.'
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        error_log("AMADEUS VOID: Order ID extracted: " . $orderId);

        // ========================================
        // STEP 5: MAKE API REQUEST TO DELETE FLIGHT ORDER
        // ========================================

        $cancelUrl = $end_pointv1 . 'booking/flight-orders/' . $orderId;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $cancelUrl,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $tokenData['access_token'],
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        error_log("AMADEUS VOID: API response received, HTTP code: $httpCode");

        // ========================================
        // STEP 6: PROCESS RESPONSE
        // ========================================

        $responseData = json_decode($response, true);

        // Success: HTTP 200 or 204
        if ($httpCode === 200 || $httpCode === 204) {
            error_log("AMADEUS VOID: Cancellation successful");

            // Update database
            $updateData = [
                'booking_status' => 'cancelled',
                'void_response' => $response,
                'error_response' => null
            ];

            $db->update('bookings', $updateData, ['invoice_id' => $invoice_id]);


            // Build success response
            $successResponse = [
                'status' => true,
                'message' => 'Flight booking cancelled successfully',
                'invoice_id' => $invoice_id,
                'order_id' => $orderId,
                'pnr' => $booking['pnr'],
                'response' => $responseData
            ];

            ob_clean();
            echo json_encode($successResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;

        } else {
            // FAILURE: Process error
            error_log("AMADEUS VOID: Cancellation failed");

            $errorMessage = '';
            $errorCode = '';

            if (isset($responseData['errors']) && is_array($responseData['errors'])) {
                $firstError = $responseData['errors'][0] ?? [];
                $errorCode = $firstError['code'] ?? 'UNKNOWN';
                $errorMessage = $firstError['detail'] ?? $firstError['title'] ?? 'No error message provided';

                // Special handling for error 1797 - order not found
                // This means the booking was never actually created in Amadeus
                if ($errorCode == 1797 || $errorCode === '1797') {
                    error_log("AMADEUS VOID: Order not found in Amadeus (1797), cancelling locally");
                    
                    $db->update('bookings', [
                        'booking_status' => 'cancelled',
                        'error_response' => json_encode([
                            'note' => 'Order not found in Amadeus system - cancelled locally',
                            'original_error' => $errorMessage
                        ])
                    ], ['invoice_id' => $invoice_id]);
                    
                    ob_clean();
                    echo json_encode([
                        'status' => true,
                        'message' => 'Booking cancelled locally. Order was not found in airline system.',
                        'invoice_id' => $invoice_id,
                        'pnr' => $booking['pnr'],
                        'note' => 'The booking was never confirmed with the airline, so local cancellation only.'
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    exit;
                }

                $fullErrorMessage = "Amadeus API Error [$errorCode]: $errorMessage";

                error_log("AMADEUS VOID: API Error - Code: $errorCode, Message: $errorMessage");
            } elseif ($curlError) {
                $fullErrorMessage = "Connection Error: $curlError";
                error_log("AMADEUS VOID: cURL Error: " . $curlError);
            } elseif ($httpCode >= 400) {
                $fullErrorMessage = "HTTP Error $httpCode: " . substr($response, 0, 200);
                error_log("AMADEUS VOID: HTTP Error: " . $httpCode);
            } else {
                $fullErrorMessage = "Unknown error occurred during cancellation";
            }

            // Update booking with error
            $db->update('bookings', [
                'error_response' => json_encode([
                    'error' => 'Cancellation failed',
                    'code' => $errorCode,
                    'message' => $fullErrorMessage,
                    'http_code' => $httpCode,
                    'response' => $responseData
                ])
            ], ['invoice_id' => $invoice_id]);

            $errorResponse = [
                'status' => false,
                'message' => $fullErrorMessage,
                'error_code' => $errorCode,
                'invoice_id' => $invoice_id,
                'http_code' => $httpCode,
                'response' => $responseData
            ];

            ob_clean();
            echo json_encode($errorResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

    } catch (Exception $e) {
        // Handle exceptions
        $errorMessage = $e->getMessage();
        $errorFile = $e->getFile();
        $errorLine = $e->getLine();

        error_log("AMADEUS VOID ERROR: " . $errorMessage);
        error_log("File: " . $errorFile . " Line: " . $errorLine);

        $errorResponse = [
            'status' => false,
            'message' => $errorMessage,
            'error' => $errorMessage,
            'invoice_id' => $invoice_id ?? '',
            'http_code' => 500,
            'debug' => [
                'file' => basename($errorFile),
                'line' => $errorLine
            ]
        ];

        // Update booking error_response if we have invoice_id
        if (!empty($invoice_id) && isset($db)) {
            try {
                $db->update('bookings', [
                    'error_response' => json_encode($errorResponse)
                ], [
                    'invoice_id' => $invoice_id
                ]);
            } catch (Exception $dbError) {
                error_log("Failed to save error response: " . $dbError->getMessage());
            }
        }

        ob_clean();
        echo json_encode($errorResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
});
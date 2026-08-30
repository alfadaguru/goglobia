<?php
// ============================================================================
// PKFARE FLIGHT VOID/CANCEL API ENDPOINT
// ============================================================================
//
// PURPOSE:
// Cancel/void a flight booking via PKFare OrderCancel API
//
// ENDPOINT: POST /flights/pkfare/void
//
// ============================================================================
// REQUEST PARAMETERS
// ============================================================================
//
// invoice_id (string) - Required - Booking invoice ID from database
//
// ============================================================================
// PKFARE ORDER CANCEL API
// ============================================================================
//
// API Endpoint: POST https://api.pkfare.com/air/api/OrderCancel
// Documentation: PKFare API Documentation
//
// Required:
// - authentication (partnerId + signature)
// - orderNo (booking order number)
//
// Response:
// - status: success/failure
// - Order cancellation confirmation
//
// ============================================================================

$router->post('flights/pkfare/void', function() use ($db) {

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
        $module = $booking['module'] ?? 'pkfare';
        $moduleType = $booking['module_type'] ?? 'flights';

        $moduleData = $db->get('modules', '*', [
            'name' => $module,
            'type' => $moduleType
        ]);

        if (!$moduleData) {
            throw new Exception('Module configuration not found');
        }

        error_log("PKFARE VOID: Module data retrieved");

        $partnerId = $moduleData['c1'] ?? '';
        $apiKey = $moduleData['c2'] ?? '';

        if (empty($partnerId) || empty($apiKey)) {
            throw new Exception('PKFare API credentials not configured');
        }

        // Generate signature
        $signature = md5($partnerId . $apiKey);


        // ========================================
        // STEP 3: PARSE BOOKING RESPONSE TO GET ORDER NUMBER
        // ========================================
        error_log("PKFARE VOID: Extracting order number from booking response");

        $bookingResponse = $booking['booking_response'] ?? '';
        $orderNo = null;

        if (!empty($bookingResponse)) {
            $responseData = json_decode($bookingResponse, true);
            // PKFare order number might be in different fields
            if (isset($responseData['orderNo'])) {
                $orderNo = $responseData['orderNo'];
            } elseif (isset($responseData['data']['orderNo'])) {
                $orderNo = $responseData['data']['orderNo'];
            } elseif (isset($responseData['order']['orderNo'])) {
                $orderNo = $responseData['order']['orderNo'];
            }
        }

        // If no order number in response, try using PNR as fallback
        if (empty($orderNo)) {
            $orderNo = $booking['pnr'];
        }

        if (empty($orderNo)) {
            // No order number means booking was never confirmed with PKFare
            error_log("PKFARE VOID: No order number found, cancelling locally only");
            
            $db->update('bookings', [
                'booking_status' => 'cancelled'
            ], ['invoice_id' => $invoice_id]);
            
            ob_clean();
            echo json_encode([
                'status' => true,
                'message' => 'Booking cancelled locally. No order number found - booking was not confirmed with airline.',
                'invoice_id' => $invoice_id,
                'pnr' => $booking['pnr'],
                'note' => 'Payment may have been received but airline booking failed.'
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        error_log("PKFARE VOID: Order number extracted: " . $orderNo);

        // ========================================
        // STEP 4: BUILD CANCELLATION REQUEST
        // ========================================
        error_log("PKFARE VOID: Building cancellation request payload");

        $cancelPayload = [
            'authentication' => [
                'partnerId' => $partnerId,
                'sign' => $signature
            ],
            'orderNo' => $orderNo
        ];

        $requestJson = json_encode($cancelPayload);

        if ($requestJson === false) {
            throw new Exception('Failed to encode cancellation payload to JSON: ' . json_last_error_msg());
        }

        // ========================================
        // STEP 5: MAKE API REQUEST TO CANCEL ORDER
        // ========================================

        $cancelUrl = 'https://api.pkfare.com/air/api/OrderCancel';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $cancelUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $requestJson,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($requestJson)
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        error_log("PKFARE VOID: API response received, HTTP code: $httpCode");

        // ========================================
        // STEP 6: PROCESS RESPONSE
        // ========================================

        $responseData = json_decode($response, true);

        // Check if cancellation was successful
        // PKFare typically returns {"status":true} or {"success":true} for successful cancellations
        $isSuccess = false;
        
        if ($httpCode === 200) {
            if (isset($responseData['status']) && $responseData['status'] === true) {
                $isSuccess = true;
            } elseif (isset($responseData['success']) && $responseData['success'] === true) {
                $isSuccess = true;
            } elseif (isset($responseData['data']['status']) && $responseData['data']['status'] === 'success') {
                $isSuccess = true;
            }
        }

        if ($isSuccess) {
            error_log("PKFARE VOID: Cancellation successful");

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
                'message' => 'Flight booking cancelled successfully with PKFare',
                'invoice_id' => $invoice_id,
                'order_no' => $orderNo,
                'pnr' => $booking['pnr'],
                'response' => $responseData
            ];

            ob_clean();
            echo json_encode($successResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;

        } else {
            // FAILURE: Process error
            error_log("PKFARE VOID: Cancellation failed");

            $errorMessage = '';
            
            if (isset($responseData['message'])) {
                $errorMessage = $responseData['message'];
            } elseif (isset($responseData['error'])) {
                $errorMessage = $responseData['error'];
            } elseif (isset($responseData['data']['message'])) {
                $errorMessage = $responseData['data']['message'];
            } elseif ($curlError) {
                $errorMessage = "Connection Error: $curlError";
            } elseif ($httpCode >= 400) {
                $errorMessage = "HTTP Error $httpCode: " . substr($response, 0, 200);
            } else {
                $errorMessage = "Cancellation failed. Please contact support.";
            }

            // Special handling for order not found or already cancelled
            if (stripos($errorMessage, 'not found') !== false || 
                stripos($errorMessage, 'already cancelled') !== false ||
                stripos($errorMessage, 'does not exist') !== false) {
                
                
                $db->update('bookings', [
                    'booking_status' => 'cancelled',
                    'error_response' => json_encode([
                        'note' => 'Order not found in PKFare system - cancelled locally',
                        'original_error' => $errorMessage
                    ])
                ], ['invoice_id' => $invoice_id]);
                
                ob_clean();
                echo json_encode([
                    'status' => true,
                    'message' => 'Booking cancelled locally. Order was not found in airline system.',
                    'invoice_id' => $invoice_id,
                    'pnr' => $booking['pnr'],
                    'note' => 'The booking was not found in PKFare system.'
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                exit;
            }

            error_log("PKFARE VOID: Error - " . $errorMessage);

            // Update booking with error
            $db->update('bookings', [
                'error_response' => json_encode([
                    'error' => 'Cancellation failed',
                    'message' => $errorMessage,
                    'http_code' => $httpCode,
                    'response' => $responseData
                ])
            ], ['invoice_id' => $invoice_id]);

            $errorResponse = [
                'status' => false,
                'message' => $errorMessage,
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

        error_log("PKFARE VOID ERROR: " . $errorMessage);
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
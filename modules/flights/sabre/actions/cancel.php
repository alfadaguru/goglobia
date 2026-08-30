<?php
// ============================================================================
// SABRE FLIGHT CANCEL API ENDPOINT
// ============================================================================
//
// PURPOSE:
// Cancel an issued ticket via Sabre void ticket functionality
// This endpoint handles post-ticketing cancellations
//
// ENDPOINT: POST /flights/sabre/cancel
//
// ============================================================================
// REQUEST PARAMETERS
// ============================================================================
//
// invoice_id (string) - Required - Booking invoice ID from database
//
// ============================================================================
// SABRE VOID TICKET API
// ============================================================================
//
// For ticketed reservations, Sabre requires void/refund processing
// This uses the same cancel booking endpoint but with additional
// ticket number validation
//
// ============================================================================

$router->post('flights/sabre/cancel', function() use ($db) {

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

            error_log("SABRE CANCEL: No PNR found, cancelled locally");

            ob_clean();
            echo json_encode([
                'status' => true,
                'message' => 'Booking cancelled locally. No PNR was issued, so no action required with airline.',
                'invoice_id' => $invoice_id,
                'note' => 'This booking was never successfully issued with Sabre.'
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ========================================
        // STEP 2: GET SABRE CREDENTIALS
        // ========================================

        $module = $booking['module'] ?? 'sabre';
        $moduleType = $booking['module_type'] ?? 'flights';

        $moduleData = $db->get('modules', '*', [
            'name' => $module,
            'type' => $moduleType
        ]);

        if (!$moduleData) {
            throw new Exception('Sabre module configuration not found');
        }

        $pcc = $moduleData['c1'] ?? '';
        $epr = $moduleData['c2'] ?? '';
        $domain = $moduleData['c3'] ?? '';
        $password = $moduleData['c4'] ?? '';
        $environment = $moduleData['status'] ?? 'test'; // test or production

        if (empty($pcc) || empty($epr) || empty($password)) {
            throw new Exception('Sabre API credentials not configured properly');
        }


        // ========================================
        // STEP 3: AUTHENTICATE WITH SABRE
        // ========================================
        error_log("SABRE CANCEL: Authenticating with Sabre");

        // Determine API base URL
        $baseUrl = ($environment === 'production')
            ? 'https://api.platform.sabre.com'
            : 'https://api.cert.platform.sabre.com';

        // Prepare credentials for Base64 encoding
        $credentials = "V1:{$epr}:{$pcc}";
        if (!empty($domain)) {
            $credentials .= ":{$domain}";
        }
        $encodedCredentials = base64_encode($credentials . ':' . base64_encode($password));

        // Get OAuth token
        $tokenUrl = $baseUrl . '/v2/auth/token';

        $ch = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . $encodedCredentials,
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');

        $tokenResponse = curl_exec($ch);
        $tokenHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($tokenHttpCode !== 200) {
            throw new Exception('Sabre authentication failed: HTTP ' . $tokenHttpCode);
        }

        $tokenData = json_decode($tokenResponse, true);
        $accessToken = $tokenData['access_token'] ?? null;

        if (empty($accessToken)) {
            throw new Exception('Failed to obtain Sabre access token');
        }

        error_log("SABRE CANCEL: Authentication successful");

        // ========================================
        // STEP 4: CHECK IF TICKET WAS ISSUED
        // ========================================
        $bookingResponse = $booking['booking_response'] ?? '';
        $hasTicketNumber = false;

        if (!empty($bookingResponse)) {
            $responseData = json_decode($bookingResponse, true);
            // Check if ticket numbers exist in response
            if (isset($responseData['airTicket']) ||
                isset($responseData['ticketNumber']) ||
                (isset($responseData['passengers']) && is_array($responseData['passengers']))) {
                $hasTicketNumber = true;
            }
        }

        error_log("SABRE CANCEL: Ticket issued: " . ($hasTicketNumber ? 'yes' : 'no'));

        // ========================================
        // STEP 5: CANCEL BOOKING VIA SABRE API
        // ========================================
        error_log("SABRE CANCEL: Cancelling booking PNR: " . $booking['pnr']);

        // Sabre Cancel Booking API
        $cancelUrl = $baseUrl . '/v1/trip/orders/cancelBooking';

        $cancelPayload = [
            'confirmationId' => $booking['pnr']
        ];


        $ch = curl_init($cancelUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cancelPayload));

        $cancelResponse = curl_exec($ch);
        $cancelHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        error_log("SABRE CANCEL: Cancel response received, HTTP code: " . $cancelHttpCode);

        // Parse response
        $cancelData = json_decode($cancelResponse, true);

        // Check for errors
        if ($cancelHttpCode >= 400) {
            $errorMsg = 'HTTP ' . $cancelHttpCode;

            if (isset($cancelData['errorCode'])) {
                $errorMsg .= ', Error: [' . $cancelData['errorCode'] . '] ' . ($cancelData['message'] ?? 'Unknown error');
            } elseif (isset($cancelData['error'])) {
                $errorMsg .= ', Error: ' . $cancelData['error'];
            }

            // If ticketed, add note about refund process
            if ($hasTicketNumber) {
                $errorMsg .= ' (Note: This booking has been ticketed. Manual refund processing may be required through Sabre Red Workspace)';
            }

            throw new Exception('Sabre cancellation failed: ' . $errorMsg);
        }

        // ========================================
        // STEP 6: UPDATE DATABASE
        // ========================================
        error_log("SABRE CANCEL: Updating database");

        $db->update('bookings', [
            'booking_status' => 'cancelled',
            'cancel_response' => json_encode($cancelData)
        ], [
            'invoice_id' => $invoice_id
        ]);


        // ========================================
        // STEP 7: RETURN SUCCESS RESPONSE
        // ========================================
        ob_clean();

        $successResponse = [
            'status' => true,
            'message' => 'Booking cancelled successfully',
            'pnr' => $booking['pnr'],
            'invoice_id' => $invoice_id,
            'ticketed' => $hasTicketNumber,
            'note' => $hasTicketNumber ? 'Ticket was issued. Refund may need to be processed separately.' : 'PNR cancelled before ticketing.',
            'cancellation_details' => $cancelData
        ];

        echo json_encode($successResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {

        error_log("SABRE CANCEL ERROR: " . $e->getMessage());
        error_log("SABRE CANCEL ERROR TRACE: " . $e->getTraceAsString());

        // Save error to database
        if (isset($invoice_id) && !empty($invoice_id)) {
            $errorDetails = [
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s'),
                'endpoint' => 'flights/sabre/cancel',
                'trace' => $e->getTraceAsString()
            ];


            try {
                $updateResult = $db->update('bookings', [
                    'error_response' => json_encode($errorDetails),
                    'booking_status' => 'cancel_failed'
                ], [
                    'invoice_id' => $invoice_id
                ]);

                if ($updateResult) {
                    $rowsAffected = $updateResult->rowCount();
                } else {
                    $dbError = "";
                    error_log("SABRE CANCEL: Database update failed: " . json_encode($dbError));
                }
            } catch (Exception $dbException) {
                error_log("SABRE CANCEL: Database exception: " . $dbException->getMessage());
            }
        } else {
            error_log("SABRE CANCEL: Cannot save error - invoice_id not set");
        }

        // Return error response
        ob_clean();
        echo json_encode([
            'status' => false,
            'message' => 'Failed to cancel booking',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

});

?>
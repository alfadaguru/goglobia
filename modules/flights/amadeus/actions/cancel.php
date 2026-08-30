<?php
// ============================================================================
// AMADEUS FLIGHT CANCELLATION REQUEST ENDPOINT
// ============================================================================
//
// PURPOSE:
// Handle customer cancellation requests for Amadeus flight bookings
// This is different from void.php which is admin-only immediate cancellation
//
// ENDPOINT: POST /flights/amadeus/cancel
//
// ============================================================================
// REQUEST PARAMETERS
// ============================================================================
//
// invoice_id (string) - Required - Booking invoice ID from database
// reason (string) - Optional - Cancellation reason from customer
//
// ============================================================================
// CANCELLATION REQUEST WORKFLOW
// ============================================================================
//
// 1. Customer requests cancellation from invoice page
// 2. System validates booking can be cancelled
// 3. Creates cancellation request (pending approval)
// 4. Admin reviews and processes via void endpoint
// 5. Customer receives refund if applicable
//
// ============================================================================

$router->post('flights/amadeus/cancel', function() use ($db) {

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
        // STEP 1: VALIDATE AND FETCH BOOKING
        // ========================================
        $invoice_id = $_POST['invoice_id'] ?? '';
        $reason = $_POST['reason'] ?? 'Customer requested cancellation';

        if (empty($invoice_id)) {
            throw new Exception('Missing invoice_id parameter');
        }


        // Get booking from database
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);

        if (!$booking) {
            throw new Exception('Booking not found for invoice_id: ' . $invoice_id);
        }


        // ========================================
        // STEP 2: VALIDATE CANCELLATION ELIGIBILITY
        // ========================================

        // Check if already cancelled
        if ($booking['booking_status'] === 'cancelled' || $booking['booking_status'] === 'voided') {
            ob_clean();
            echo json_encode([
                'status' => false,
                'message' => 'This booking has already been cancelled.',
                'invoice_id' => $invoice_id,
                'booking_status' => $booking['booking_status']
            ]);
            exit;
        }

        // Check if cancellation already requested
        if ($booking['cancellation_request'] === 'pending') {
            ob_clean();
            echo json_encode([
                'status' => false,
                'message' => 'A cancellation request is already pending for this booking.',
                'invoice_id' => $invoice_id
            ]);
            exit;
        }

        // Check if cancellation was already processed
        if ($booking['cancellation_request'] === 'approved' || $booking['cancellation_request'] === 'processed') {
            ob_clean();
            echo json_encode([
                'status' => false,
                'message' => 'This booking cancellation has already been processed.',
                'invoice_id' => $invoice_id
            ]);
            exit;
        }

        // Check booking status - only confirmed bookings can request cancellation
        if ($booking['booking_status'] !== 'confirmed' && $booking['booking_status'] !== 'pending') {
            throw new Exception('Only confirmed or pending bookings can be cancelled.');
        }

        // ========================================
        // STEP 3: CREATE CANCELLATION REQUEST
        // ========================================
        error_log("AMADEUS CANCEL: Creating cancellation request");

        $updateData = [
            'cancellation_request' => 'pending',
            'cancellation_status' => json_encode([
                'requested_at' => date('Y-m-d H:i:s'),
                'reason' => $reason,
                'user_id' => $_SESSION['user_data']['id'] ?? null,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
            ])
        ];

        $result = $db->update('bookings', $updateData, ['invoice_id' => $invoice_id]);

        if ($result === false) {
            throw new Exception('Failed to create cancellation request in database');
        }


        // ========================================
        // STEP 4: SEND NOTIFICATION TO ADMIN
        // ========================================
        
        // Trigger webhook for admin notification
        try {
            triggerWebhook('flights/booking', 'flights.cancellation.requested', [
                'invoice_id' => $invoice_id,
                'pnr' => $booking['pnr'],
                'customer_name' => $booking['first_name'] . ' ' . $booking['last_name'],
                'customer_email' => $booking['email'],
                'reason' => $reason,
                'booking_amount' => $booking['price_markup'],
                'currency' => $booking['currency_markup'],
                'requested_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            error_log("AMADEUS CANCEL: Webhook notification failed: " . $e->getMessage());
        }

        // ========================================
        // STEP 5: RETURN SUCCESS RESPONSE
        // ========================================

        $successResponse = [
            'status' => true,
            'message' => 'Cancellation request submitted successfully. Our team will review and process your request.',
            'invoice_id' => $invoice_id,
            'cancellation_status' => 'pending',
            'note' => 'You will be notified via email once your cancellation request is processed.',
            'refund_info' => 'Refund processing time may vary depending on airline policies and payment method.'
        ];

        ob_clean();
        echo json_encode($successResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Exception $e) {
        // Handle exceptions
        $errorMessage = $e->getMessage();
        $errorFile = $e->getFile();
        $errorLine = $e->getLine();

        error_log("AMADEUS CANCEL ERROR: " . $errorMessage);
        error_log("File: " . $errorFile . " Line: " . $errorLine);

        $errorResponse = [
            'status' => false,
            'message' => $errorMessage,
            'error' => $errorMessage,
            'invoice_id' => $invoice_id ?? '',
            'debug' => [
                'file' => basename($errorFile),
                'line' => $errorLine
            ]
        ];

        ob_clean();
        echo json_encode($errorResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
});
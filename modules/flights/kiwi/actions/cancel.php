<?php
// ============================================================================
// KIWI FLIGHT CANCEL API ENDPOINT
// ============================================================================
//
// PURPOSE:
// Cancel a confirmed Kiwi flight booking
//
// ENDPOINT: POST /flights/kiwi/cancel
//
// ============================================================================
// REQUEST PARAMETERS
// ============================================================================
//
// invoice_id (string) - Required - Booking invoice ID from database
//
// ============================================================================
// KIWI CANCELLATION
// ============================================================================
//
// Kiwi.com bookings are typically non-refundable
// Cancellations must be requested through Kiwi.com support
// This endpoint marks the booking as cancelled in the system
//
// ============================================================================

$router->post('flights/kiwi/cancel', function() use ($db) {

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
            
            error_log("KIWI CANCEL: No PNR found, cancelled locally");
            
            ob_clean();
            echo json_encode([
                'status' => true,
                'message' => 'Booking cancelled locally. No PNR was issued.',
                'invoice_id' => $invoice_id
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ========================================
        // STEP 2: EXTRACT BOOKING DETAILS
        // ========================================
        $pnr = $booking['pnr'];
        $bookingResponse = $booking['booking_response'] ?? '';
        $bookingId = null;

        if (!empty($bookingResponse)) {
            $responseData = json_decode($bookingResponse, true);
            $bookingId = $responseData['booking_id'] ?? $responseData['id'] ?? null;
        }

        error_log("KIWI CANCEL: PNR: " . $pnr . ", Booking ID: " . ($bookingId ?? 'N/A'));

        // ========================================
        // STEP 3: PREPARE CANCELLATION RECORD
        // ========================================
        error_log("KIWI CANCEL: Preparing cancellation record");

        $cancellationDetails = [
            'cancelled_at' => date('Y-m-d H:i:s'),
            'cancelled_by' => 'system',
            'invoice_id' => $invoice_id,
            'pnr' => $pnr,
            'booking_id' => $bookingId,
            'status' => 'cancelled_locally',
            'note' => 'Kiwi.com does not provide cancellation API. To request a refund (if eligible), contact Kiwi.com support with booking ID.',
            'support_contact' => [
                'url' => 'https://www.kiwi.com/en/help/',
                'email' => 'info@kiwi.com'
            ]
        ];

        // ========================================
        // STEP 4: UPDATE DATABASE
        // ========================================
        error_log("KIWI CANCEL: Updating database");

        $db->update('bookings', [
            'booking_status' => 'cancelled',
            'cancel_response' => json_encode($cancellationDetails)
        ], [
            'invoice_id' => $invoice_id
        ]);


        // ========================================
        // STEP 5: RETURN RESPONSE
        // ========================================
        ob_clean();
        
        $successResponse = [
            'status' => true,
            'message' => 'Booking marked as cancelled',
            'invoice_id' => $invoice_id,
            'pnr' => $pnr,
            'booking_id' => $bookingId,
            'important_note' => 'Kiwi.com bookings are typically non-refundable. Check your fare conditions for refund eligibility.',
            'next_steps' => [
                '1. Check the fare rules for refund eligibility',
                '2. If eligible, contact Kiwi.com support at https://www.kiwi.com/en/help/',
                '3. Provide booking ID: ' . ($bookingId ?? $pnr),
                '4. Follow their refund request process'
            ],
            'cancellation_details' => $cancellationDetails
        ];
        
        echo json_encode($successResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        
        error_log("KIWI CANCEL ERROR: " . $e->getMessage());
        error_log("KIWI CANCEL ERROR TRACE: " . $e->getTraceAsString());
        
        // Save error to database
        if (isset($invoice_id) && !empty($invoice_id)) {
            $errorDetails = [
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s'),
                'endpoint' => 'flights/kiwi/cancel',
                'trace' => '[redacted]'
            ];
            
            
            try {
                $updateResult = $db->update('bookings', [
                    'error_response' => json_encode($errorDetails)
                ], [
                    'invoice_id' => $invoice_id
                ]);
                
                if ($updateResult) {
                    $rowsAffected = $updateResult->rowCount();
                } else {
                    error_log("KIWI CANCEL: Database update returned null");
                }
            } catch (Exception $dbException) {
                error_log("KIWI CANCEL: Database exception: " . $dbException->getMessage());
            }
        } else {
            error_log("KIWI CANCEL: Cannot save error - invoice_id not set");
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
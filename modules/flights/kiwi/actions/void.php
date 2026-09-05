<?php
// ============================================================================
// KIWI FLIGHT VOID API ENDPOINT
// ============================================================================
//
// PURPOSE:
// Void/cancel a Kiwi flight booking before ticketing
//
// ENDPOINT: POST /flights/kiwi/void
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
// Kiwi.com typically doesn't allow cancellations through API
// Most bookings are non-refundable or require manual processing
// This endpoint marks the booking as cancelled locally
//
// ============================================================================

$router->post('flights/kiwi/void', function() use ($db) {

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
            
            error_log("KIWI VOID: No PNR found, cancelled locally");
            
            ob_clean();
            echo json_encode([
                'status' => true,
                'message' => 'Booking cancelled locally. No PNR was issued.',
                'invoice_id' => $invoice_id,
                'note' => 'This booking was never confirmed with Kiwi.'
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Check if booking was actually successful
        if ($booking['booking_status'] === 'failed' || $booking['booking_status'] === 'pending') {
            // Update to cancelled locally
            $db->update('bookings', [
                'booking_status' => 'cancelled'
            ], ['invoice_id' => $invoice_id]);
            
            error_log("KIWI VOID: Booking not confirmed, cancelled locally");
            
            ob_clean();
            echo json_encode([
                'status' => true,
                'message' => 'Booking cancelled. This booking was not confirmed.',
                'invoice_id' => $invoice_id,
                'previous_status' => $booking['booking_status']
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ========================================
        // STEP 2: MARK AS CANCELLED
        // ========================================
        error_log("KIWI VOID: Marking booking as cancelled");

        // Note: Kiwi.com typically doesn't allow API cancellations
        // Most tickets are non-refundable or require contacting support
        
        $cancellationNote = [
            'cancelled_at' => date('Y-m-d H:i:s'),
            'cancelled_by' => 'system',
            'note' => 'Kiwi.com bookings typically cannot be cancelled via API. Contact Kiwi.com support for refund eligibility.',
            'pnr' => $booking['pnr'],
            'booking_id' => $booking['booking_response'] ? json_decode($booking['booking_response'], true)['booking_id'] ?? null : null
        ];

        $db->update('bookings', [
            'booking_status' => 'cancelled',
            'void_response' => json_encode($cancellationNote)
        ], [
            'invoice_id' => $invoice_id
        ]);

        error_log("KIWI VOID: Booking marked as cancelled");

        // ========================================
        // STEP 3: RETURN RESPONSE
        // ========================================
        ob_clean();
        
        $successResponse = [
            'status' => true,
            'message' => 'Booking marked as cancelled',
            'pnr' => $booking['pnr'],
            'invoice_id' => $invoice_id,
            'note' => 'Kiwi.com bookings are typically non-refundable. Please check the fare conditions and contact Kiwi.com support if refund is needed.',
            'cancellation_details' => $cancellationNote
        ];
        
        echo json_encode($successResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        
        error_log("KIWI VOID ERROR: " . $e->getMessage());
        error_log("KIWI VOID ERROR TRACE: " . $e->getTraceAsString());
        
        // Save error to database
        if (isset($invoice_id) && !empty($invoice_id)) {
            $errorDetails = [
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s'),
                'endpoint' => 'flights/kiwi/void',
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
                    error_log("KIWI VOID: Database update returned null");
                }
            } catch (Exception $dbException) {
                error_log("KIWI VOID: Database exception: " . $dbException->getMessage());
            }
        } else {
            error_log("KIWI VOID: Cannot save error - invoice_id not set");
        }
        
        // Return error response
        ob_clean();
        echo json_encode([
            'status' => false,
            'message' => 'Failed to void booking',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    
});

?>
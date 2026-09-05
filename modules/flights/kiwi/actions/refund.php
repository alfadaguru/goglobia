<?php
// ============================================================================
// KIWI FLIGHT REFUND API ENDPOINT
// ============================================================================
//
// PURPOSE:
// Process refund request for Kiwi flight booking
// Note: Kiwi.com refunds must be requested through their support system
//
// ENDPOINT: POST /flights/kiwi/refund
//
// ============================================================================
// REQUEST PARAMETERS
// ============================================================================
//
// invoice_id (string) - Required - Booking invoice ID from database
// refund_amount (float) - Optional - Requested refund amount
// refund_reason (string) - Optional - Reason for refund request
//
// ============================================================================
// KIWI REFUND PROCESSING
// ============================================================================
//
// Kiwi.com does not provide refund API
// Refunds must be requested through:
// 1. Kiwi.com customer support portal
// 2. Email to info@kiwi.com
// 3. Their online help center
//
// This endpoint logs the refund request and provides instructions
//
// ============================================================================

$router->post('flights/kiwi/refund', function() use ($db) {

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
        $refundAmount = $_POST['refund_amount'] ?? null;
        $refundReason = $_POST['refund_reason'] ?? 'Customer requested refund';

        if (empty($invoice_id)) {
            throw new Exception('Missing invoice_id parameter in POST data');
        }


        // Get booking from database
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);

        if (!$booking) {
            throw new Exception('Booking not found for invoice_id: ' . $invoice_id);
        }


        // Check if booking is cancelled
        if ($booking['booking_status'] !== 'cancelled' && $booking['booking_status'] !== 'voided') {
            throw new Exception('Booking must be cancelled before processing refund. Current status: ' . $booking['booking_status']);
        }

        // Check if already refunded
        if ($booking['booking_status'] === 'refunded') {

            ob_clean();
            echo json_encode([
                'status' => true,
                'message' => 'Booking already marked as refunded',
                'invoice_id' => $invoice_id,
                'booking_status' => $booking['booking_status']
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ========================================
        // STEP 2: GET BOOKING DETAILS
        // ========================================
        $pnr = $booking['pnr'] ?? '';
        $totalAmount = $booking['final_total'] ?? 0;
        
        // If no refund amount specified, use total booking amount
        if (empty($refundAmount)) {
            $refundAmount = $totalAmount;
        }

        // Extract booking ID from response
        $bookingId = null;
        $bookingResponse = $booking['booking_response'] ?? '';
        
        if (!empty($bookingResponse)) {
            $responseData = json_decode($bookingResponse, true);
            $bookingId = $responseData['booking_id'] ?? $responseData['id'] ?? null;
        }

        error_log("KIWI REFUND: PNR: " . $pnr . ", Booking ID: " . ($bookingId ?? 'N/A') . ", Refund Amount: " . $refundAmount);

        // ========================================
        // STEP 3: CHECK FARE CONDITIONS
        // ========================================
        $fareRules = 'Unknown - Check booking confirmation';
        
        if (!empty($bookingResponse)) {
            $responseData = json_decode($bookingResponse, true);
            // Try to extract fare conditions if available
            if (isset($responseData['conditions'])) {
                $fareRules = json_encode($responseData['conditions']);
            } elseif (isset($responseData['fare_rules'])) {
                $fareRules = json_encode($responseData['fare_rules']);
            }
        }

        // ========================================
        // STEP 4: PREPARE REFUND RECORD
        // ========================================
        $refundDetails = [
            'invoice_id' => $invoice_id,
            'pnr' => $pnr,
            'booking_id' => $bookingId,
            'refund_amount' => $refundAmount,
            'original_amount' => $totalAmount,
            'currency' => $booking['base_currency'] ?? 'USD',
            'refund_reason' => $refundReason,
            'requested_at' => date('Y-m-d H:i:s'),
            'status' => 'pending_manual_processing',
            'fare_rules' => $fareRules,
            'note' => 'Kiwi.com refunds must be requested through their customer support. Most Kiwi.com bookings are non-refundable.',
            'processing_instructions' => [
                'step_1' => 'Visit https://www.kiwi.com/en/help/',
                'step_2' => 'Navigate to "Contact Us" or "Refund Request"',
                'step_3' => 'Provide booking ID: ' . ($bookingId ?? $pnr),
                'step_4' => 'Submit refund reason: ' . $refundReason,
                'step_5' => 'Wait for Kiwi.com support response (typically 7-14 days)',
                'step_6' => 'Update booking status to "refunded" once refund is processed'
            ]
        ];

        error_log("KIWI REFUND: Refund details prepared");

        // ========================================
        // STEP 5: UPDATE DATABASE
        // ========================================

        // Attempt to reverse the CUSTOMER's charge via the payment gateway (Kiwi
        // itself has no programmatic refund — this at least returns the money if
        // the gateway supports it). booking_status ENUM = confirmed|pending|
        // cancelled ('refund_pending' truncates); use 'cancelled'. Store details
        // in cancellation_response (there is no 'refund_response' column).
        require_once dirname(__DIR__, 4) . '/app/lib/payment-gateway.php';
        $kiwiGwRefund = function_exists('refund_gateway_payment')
            ? refund_gateway_payment($db, $booking, null, 'Kiwi flight refund')
            : ['status' => 'unsupported', 'message' => 'Refund function unavailable', 'gateway' => ''];
        $kiwiGatewayRefunded = ($kiwiGwRefund['status'] === 'refunded');
        $refundDetails['gateway_refund'] = $kiwiGwRefund;

        $db->update('bookings', [
            'booking_status' => 'cancelled',
            'payment_status' => $kiwiGatewayRefunded ? 'refunded' : ($booking['payment_status'] ?? 'paid'),
            'cancellation_status' => 1,
            'cancellation_response' => json_encode($refundDetails)
        ], [
            'invoice_id' => $invoice_id
        ]);


        // ========================================
        // STEP 6: RETURN RESPONSE
        // ========================================
        ob_clean();
        
        $successResponse = [
            'status' => true,
            'message' => 'Refund request logged. Manual processing required through Kiwi.com support.',
            'invoice_id' => $invoice_id,
            'pnr' => $pnr,
            'booking_id' => $bookingId,
            'refund_amount' => $refundAmount,
            'currency' => $booking['base_currency'] ?? 'USD',
            'important_notes' => [
                'Most Kiwi.com bookings are non-refundable',
                'Refund eligibility depends on fare conditions',
                'Processing time: 7-14 business days',
                'Refund (if approved) will be processed to original payment method'
            ],
            'next_steps' => $refundDetails['processing_instructions'],
            'support_contact' => [
                'help_center' => 'https://www.kiwi.com/en/help/',
                'email' => 'info@kiwi.com'
            ],
            'refund_details' => $refundDetails
        ];
        
        
        echo json_encode($successResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        
        error_log("KIWI REFUND ERROR: " . $e->getMessage());
        error_log("KIWI REFUND ERROR TRACE: " . $e->getTraceAsString());
        
        // Save error to database
        if (isset($invoice_id) && !empty($invoice_id)) {
            $errorDetails = [
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s'),
                'endpoint' => 'flights/kiwi/refund',
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
                    error_log("KIWI REFUND: Database update returned null");
                }
            } catch (Exception $dbException) {
                error_log("KIWI REFUND: Database exception: " . $dbException->getMessage());
            }
        } else {
            error_log("KIWI REFUND: Cannot save error - invoice_id not set");
        }
        
        // Return error response
        ob_clean();
        echo json_encode([
            'status' => false,
            'message' => 'Failed to process refund request',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    
});

?>
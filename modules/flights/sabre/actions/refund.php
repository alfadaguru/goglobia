<?php
// ============================================================================
// SABRE FLIGHT REFUND API ENDPOINT
// ============================================================================
//
// PURPOSE:
// Process refund for cancelled tickets via Sabre
// Note: Most refunds in Sabre require manual processing through Red Workspace
// This endpoint marks booking for refund and logs the request
//
// ENDPOINT: POST /flights/sabre/refund
//
// ============================================================================
// REQUEST PARAMETERS
// ============================================================================
//
// invoice_id (string) - Required - Booking invoice ID from database
// refund_amount (float) - Optional - Refund amount
// refund_reason (string) - Optional - Reason for refund
//
// ============================================================================
// SABRE REFUND PROCESSING
// ============================================================================
//
// Sabre typically handles refunds through:
// 1. Void within 24 hours (automated)
// 2. Manual refund processing in Red Workspace (after 24 hours)
// 3. ARC/BSP settlement for agency refunds
//
// This endpoint documents the refund request in the database
// Manual processing through Sabre Red Workspace may still be required
//
// ============================================================================

$router->post('flights/sabre/refund', function() use ($db) {

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

        // Check if already refunded. booking_status ENUM has no 'refunded';
        // the refunded state lives in payment_status.
        if (($booking['payment_status'] ?? '') === 'refunded') {

            ob_clean();
            echo json_encode([
                'status' => true,
                'message' => 'Booking already marked as refunded',
                'invoice_id' => $invoice_id,
                'payment_status' => $booking['payment_status']
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

        error_log("SABRE REFUND: PNR: " . $pnr . ", Refund Amount: " . $refundAmount);

        // ========================================
        // STEP 3: GET SABRE CREDENTIALS
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
        $environment = $moduleData['status'] ?? 'test';


        // ========================================
        // STEP 4: CHECK TICKET DETAILS
        // ========================================
        $bookingResponse = $booking['booking_response'] ?? '';
        $ticketNumbers = [];

        if (!empty($bookingResponse)) {
            $responseData = json_decode($bookingResponse, true);

            // Extract ticket numbers if available
            if (isset($responseData['passengers']) && is_array($responseData['passengers'])) {
                foreach ($responseData['passengers'] as $passenger) {
                    if (isset($passenger['ticketNumber'])) {
                        $ticketNumbers[] = $passenger['ticketNumber'];
                    }
                }
            } elseif (isset($responseData['ticketNumber'])) {
                $ticketNumbers[] = $responseData['ticketNumber'];
            }
        }

        error_log("SABRE REFUND: Ticket numbers found: " . count($ticketNumbers));

        // ========================================
        // STEP 5: PREPARE REFUND RECORD
        // ========================================
        $refundDetails = [
            'invoice_id' => $invoice_id,
            'pnr' => $pnr,
            'ticket_numbers' => $ticketNumbers,
            'refund_amount' => $refundAmount,
            'original_amount' => $totalAmount,
            'refund_reason' => $refundReason,
            'requested_at' => date('Y-m-d H:i:s'),
            'pcc' => $pcc,
            'environment' => $environment,
            'status' => 'pending_manual_processing',
            'note' => 'Sabre refunds typically require manual processing through Sabre Red Workspace. Please process this refund manually and update the booking status accordingly.'
        ];

        error_log("SABRE REFUND: Refund details prepared");

        // ========================================
        // STEP 6: REVERSE CUSTOMER CHARGE VIA GATEWAY + UPDATE DATABASE
        // Sabre ticket refunds are settled manually (Red Workspace / ARC-BSP),
        // but we can still return the customer's money now via the payment
        // gateway (real for Paystack/Stripe). Keep booking_status='cancelled'
        // (it must already be cancelled/voided to reach here — do NOT regress it
        // to 'pending') and carry the money state in payment_status.
        // ========================================
        require_once dirname(__DIR__, 4) . '/app/lib/payment-gateway.php';
        $sabreGwRefund = function_exists('refund_gateway_payment')
            ? refund_gateway_payment($db, $booking, ($refundAmount > 0 ? (float) $refundAmount : null), $refundReason)
            : ['status' => 'unsupported', 'message' => 'Refund function unavailable', 'gateway' => ''];
        $sabreGatewayRefunded = ($sabreGwRefund['status'] === 'refunded');
        $refundDetails['gateway_refund'] = $sabreGwRefund;

        $db->update('bookings', [
            'payment_status'  => $sabreGatewayRefunded ? 'refunded' : ($booking['payment_status'] ?? 'paid'),
            'refund_response' => json_encode($refundDetails)
        ], [
            'invoice_id' => $invoice_id
        ]);


        // ========================================
        // STEP 7: RETURN RESPONSE
        // ========================================
        ob_clean();

        $successResponse = [
            'status' => true,
            'message' => 'Refund request logged successfully. Manual processing required through Sabre Red Workspace.',
            'invoice_id' => $invoice_id,
            'pnr' => $pnr,
            'refund_amount' => $refundAmount,
            'ticket_numbers' => $ticketNumbers,
            'next_steps' => [
                '1. Log into Sabre Red Workspace',
                '2. Retrieve PNR: ' . $pnr,
                '3. Process refund through appropriate workflow (VOID if within 24hrs, otherwise process refund)',
                '4. Update booking status to "refunded" once processing is complete',
                '5. Document ticket numbers and refund confirmation in admin panel'
            ],
            'refund_details' => $refundDetails
        ];


        echo json_encode($successResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {

        error_log("SABRE REFUND ERROR: " . $e->getMessage());
        error_log("SABRE REFUND ERROR TRACE: " . $e->getTraceAsString());

        // Save error to database
        if (isset($invoice_id) && !empty($invoice_id)) {
            $errorDetails = [
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s'),
                'endpoint' => 'flights/sabre/refund',
                'trace' => '[redacted]'
            ];


            try {
                // Do NOT regress booking_status here — the booking is already
                // cancelled/voided when refund runs; only record the error.
                $updateResult = $db->update('bookings', [
                    'error_response' => json_encode($errorDetails)
                ], [
                    'invoice_id' => $invoice_id
                ]);

                if ($updateResult) {
                    $rowsAffected = $updateResult->rowCount();
                } else {
                    $dbError = "";
                    error_log("SABRE REFUND: Database update failed: " . json_encode($dbError));
                }
            } catch (Exception $dbException) {
                error_log("SABRE REFUND: Database exception: " . $dbException->getMessage());
            }
        } else {
            error_log("SABRE REFUND: Cannot save error - invoice_id not set");
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
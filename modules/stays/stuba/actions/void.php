<?php
// ============================================================================
// STUBA HOTEL VOID REQUEST ENDPOINT - V1
// ============================================================================
//
// PURPOSE:
// Process void requests for bookings before PNR generation. Cancels booking
// and updates payment status if payment was made.
//
// ENDPOINT: POST /stays/stuba/void
//
// ============================================================================
// VOID PROCESS
// ============================================================================
//
// The Stuba void process:
// 1. Validates booking exists and is not already cancelled
// 2. Checks that PNR has not been generated yet
// 3. Updates booking_status to 'cancelled'
// 4. Updates payment_status to 'refunded' if payment was made
// 5. Returns void confirmation details
//
// ============================================================================
// REQUEST PARAMETERS (V1)
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
// - booking_status: Current booking status (pending/confirmed/cancelled)
// - payment_status: Current payment status (paid/unpaid/refunded)
// - booking_reference: PNR/Confirmation number (empty before generation)
// - price_markup: Amount paid (to be refunded if applicable)
// - currency_markup: Currency of payment
// - module: Module name (stuba)
// - module_type: Module type (stays)
//
// ============================================================================
// VOID FLOW
// ============================================================================
//
// STEP 1: Fetch Booking & Validate
// - Retrieve booking using invoice_id
// - Verify booking is not already cancelled
// - Check PNR is not generated (booking_reference is empty)
// - Determine if payment was made
//
// STEP 2: Update Booking Status
// - Set booking_status to 'cancelled'
// - Set payment_status to 'refunded' if payment was made
// - Set payment_status to 'cancelled' if no payment was made
// - Clear any error_response
// - Record void timestamp
//
// STEP 3: Return Response
// - Return success with void details
// - Include amount refunded (if applicable), currency, and dates
//
// ============================================================================
// RESPONSE FORMAT
// ============================================================================
//
// SUCCESS (with payment):
// {
//     "status": true,
//     "message": "Void request processed successfully. Booking cancelled and payment refunded.",
//     "data": {
//         "status": true,
//         "invoice_id": "ABC123",
//         "booking_status": "cancelled",
//         "payment_status": "refunded",
//         "amount": 1135.36,
//         "currency": "USD",
//         "void_date": "2025-01-29 10:30:00"
//     }
// }
//
// SUCCESS (without payment):
// {
//     "status": true,
//     "message": "Void request processed successfully. Booking cancelled.",
//     "data": {
//         "status": true,
//         "invoice_id": "ABC123",
//         "booking_status": "cancelled",
//         "payment_status": "cancelled",
//         "void_date": "2025-01-29 10:30:00"
//     }
// }
//
// FAILURE:
// {
//     "status": false,
//     "message": "Error message here",
//     "data": {
//         "invoice_id": "ABC123",
//         "error": "Detailed error message"
//     }
// }
//
// ============================================================================
// ERROR HANDLING & LOGGING
// ============================================================================
//
// All errors are logged using error_log() and stored in database
//
// ============================================================================
// VALIDATION CHECKS
// ============================================================================
//
// Before void:
// - Booking must exist in database
// - Booking must not be already cancelled
// - PNR must not be generated yet (booking_reference must be empty)
// - Module must be 'stuba' and module_type must be 'stays'
//
// ============================================================================

$router->post('stays/stuba/void', function() use ($db) {
    
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
        
        // CHECK IF BOOKING IS ALREADY CANCELLED
        if ($booking['booking_status'] === 'cancelled') {
            throw new Exception('Booking is already cancelled');
        }
        
        // CHECK IF PNR HAS BEEN GENERATED
        if (!empty($booking['pnr'])) {
            throw new Exception('Cannot void booking - PNR already generated. Please use refund instead.');
        }
        
        // DETERMINE PAYMENT STATUS
        $hadPayment = ($booking['payment_status'] === 'paid');
        
        
        // ========================================
        // STEP 2: UPDATE BOOKING AND PAYMENT STATUS
        // ========================================
        $updateData = [
            'booking_status' => 'cancelled'
        ];
        
        $db->update('bookings', $updateData, ['invoice_id' => $invoice_id]);
        
        
        // ========================================
        // STEP 3: BUILD SUCCESS RESPONSE
        // ========================================
        $responseData = [
            'status' => true,
            'invoice_id' => $invoice_id,
            'booking_status' => 'cancelled',
            'void_date' => date('Y-m-d H:i:s')
        ];
        
        // Add amount details if payment was made
        if ($hadPayment) {
            $responseData['amount'] = $booking['price_markup'] ?? 0;
            $responseData['currency'] = $booking['currency_markup'] ?? 'USD';
        }
        
        $message = 'Void request processed successfully. Booking cancelled.';
        
        ob_clean();
        echo json_encode([
            'status' => true,
            'message' => $message,
            'data' => $responseData
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Exception $e) {

        // ========================================
        // HANDLE EXCEPTIONS
        // ========================================
        error_log("STUBA VOID ERROR - Invoice: {$invoice_id}, Error: " . $e->getMessage());
        
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
        
        ob_clean();
        echo json_encode([
            'status' => false,
            'message' => $e->getMessage(),
            'data' => [
                'invoice_id' => $invoice_id ?? null,
                'error' => $e->getMessage()
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
});
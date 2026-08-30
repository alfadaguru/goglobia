<?php
// ============================================================================
// STUBA HOTEL REFUND REQUEST ENDPOINT - V10
// ============================================================================
//
// PURPOSE:
// Process refund requests for cancelled bookings. Updates payment status
// to 'refunded' in the database.
//
// ENDPOINT: POST /stays/stuba/refund
//
// ============================================================================
// REFUND PROCESS
// ============================================================================
//
// The Stuba refund process:
// 1. Validates booking exists and is cancelled
// 2. Checks payment was made and not already refunded
// 3. Updates payment_status to 'refunded'
// 4. Returns refund confirmation details
//
// ============================================================================
// REQUEST PARAMETERS (V10)
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
// - booking_status: Must be 'cancelled' for refund
// - payment_status: Current payment status (paid/refunded)
// - price_markup: Amount paid (to be refunded)
// - currency_markup: Currency of payment
// - module: Module name (stuba)
// - module_type: Module type (stays)
//
// ============================================================================
// REFUND FLOW
// ============================================================================
//
// STEP 1: Fetch Booking & Validate
// - Retrieve booking using invoice_id
// - Verify booking is cancelled
// - Check payment_status is 'paid'
// - Ensure not already refunded
//
// STEP 2: Update Payment Status
// - Set payment_status to 'refunded'
// - Clear any error_response
// - Record refund timestamp
//
// STEP 3: Return Response
// - Return success with refund details
// - Include amount, currency, and dates
//
// ============================================================================
// RESPONSE FORMAT
// ============================================================================
//
// SUCCESS:
// {
//     "status": true,
//     "message": "Refund request processed successfully. Payment status updated to refunded.",
//     "data": {
//         "status": true,
//         "invoice_id": "ABC123",
//         "payment_status": "refunded",
//         "booking_status": "cancelled",
//         "amount": 1135.36,
//         "currency": "USD",
//         "refund_date": "2025-01-27 10:30:00"
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
// Before refund:
// - Booking must exist in database
// - Booking must be cancelled
// - Payment status must be 'paid'
// - Must not already be refunded
//
// ============================================================================

$router->post('stays/stuba/refund', function() use ($db) {
    
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
        
        // CHECK IF BOOKING IS CANCELLED
        if ($booking['booking_status'] !== 'cancelled') {
            throw new Exception('Booking must be cancelled before requesting refund');
        }
        
        // CHECK IF PAYMENT WAS MADE
        if ($booking['payment_status'] !== 'paid') {
            throw new Exception('No payment found to refund');
        }
        
        // CHECK IF ALREADY REFUNDED
        if ($booking['payment_status'] === 'refunded') {
            throw new Exception('Booking has already been refunded');
        }
        
        
        // ========================================
        // STEP 2: UPDATE PAYMENT STATUS TO REFUNDED
        // ========================================
        $updateData = [
            'payment_status' => 'refunded',
        ];
        
        $db->update('bookings', $updateData, ['invoice_id' => $invoice_id]);
        
        
        // ========================================
        // STEP 3: BUILD SUCCESS RESPONSE
        // ========================================
        $responseData = [
            'status' => true,
            'invoice_id' => $invoice_id,
            'payment_status' => 'refunded',
            'booking_status' => $booking['booking_status'],
            'amount' => $booking['price_markup'] ?? 0,
            'currency' => $booking['currency_markup'] ?? 'USD',
            'refund_date' => date('Y-m-d H:i:s')
        ];
        
        ob_clean();
        echo json_encode([
            'status' => true,
            'message' => 'Refund request processed successfully. Payment status updated to refunded.',
            'data' => $responseData
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Exception $e) {

        // ========================================
        // HANDLE EXCEPTIONS
        // ========================================
        error_log("STUBA REFUND ERROR - Invoice: {$invoice_id}, Error: " . $e->getMessage());
        
        // SAVE ERROR TO DATABASE IF BOOKING EXISTS
        if (isset($booking) && $booking) {
            $db->update('bookings', [
                'error_response' => json_encode([
                    'error' => $e->getMessage(),
                    'timestamp' => date('Y-m-d H:i:s'),
                    'action' => 'refund'
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
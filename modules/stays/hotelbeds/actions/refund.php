<?php
// ============================================================================
// HOTELBEDS HOTEL REFUND REQUEST ENDPOINT - V10
// ============================================================================
//
// PURPOSE:
// Process refund requests for cancelled bookings. Updates payment status
// to 'refunded' in the database.
//
// ENDPOINT: POST /stays/hotelbeds/refund
//
// ============================================================================

$router->post('stays/hotelbeds/refund', function() use ($db) {
    
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
            'error_response' => null
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
        error_log("REFUND ERROR - Invoice: {$invoice_id}, Error: " . $e->getMessage());
        
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
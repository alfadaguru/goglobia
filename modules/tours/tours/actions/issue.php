<?php
// ============================================================================
// FILE: modules/tours/tours/actions/issue.php
// TOURS ISSUE BOOKING ACTION - Confirms booking and generates PNR
// ============================================================================
//
// PURPOSE:
// Issues/confirms a tour booking with supplier and generates a unique PNR
// (Passenger Name Record) for the booking. This action updates the booking
// status to 'confirmed' and assigns a tracking reference number.
//
// ENDPOINT: POST /tours/tours/issue
//
// ============================================================================
// REQUEST PARAMETERS
// ============================================================================
//
// - invoice_id (string) - REQUIRED: Unique invoice identifier
// - module_type (string) - Optional: Module type (default: 'tours')
//
// ============================================================================
// RESPONSE FORMAT
// ============================================================================
//
// Success Response:
// {
//   "status": true,
//   "message": "Booking issued successfully",
//   "pnr": "PNR4A7F2E",
//   "booking_status": "confirmed"
// }
//
// Error Response:
// {
//   "status": false,
//   "message": "Error description"
// }
//
// ============================================================================
// PNR FORMAT
// ============================================================================
//
// PNR + 6 hexadecimal characters (uppercase)
// Example: PNR4A7F2E, PNRC8D3A1
//
// Generation: MD5 hash of invoice_id + timestamp, first 6 characters
//
// ============================================================================
@$SECURE or die('Access Denied!');

$router->post('tours/tours/issue', function() use ($db) {
    header('Content-Type: application/json');

    try {
        // ============================================================================
        // STEP 1: VALIDATE INPUT
        // ============================================================================
        $invoice_id = $_POST['invoice_id'] ?? '';
        $module_type = $_POST['module_type'] ?? 'tours';

        if (empty($invoice_id)) {
            throw new Exception('Invoice ID is required');
        }

        // ============================================================================
        // STEP 2: FETCH BOOKING FROM DATABASE
        // ============================================================================
        $booking = $db->get('bookings', '*', [
            'invoice_id' => $invoice_id,
            'module_type' => 'tours'
        ]);

        if (!$booking) {
            throw new Exception('Tour booking not found');
        }

        // Check if already issued
        if (!empty($booking['pnr']) && $booking['pnr'] !== 'Not Issued') {
            echo json_encode([
                'status' => true,
                'Prn' => $booking['pnr'],
                'booking_reference' => $booking['pnr'],
                'reference' => $booking['pnr'],
                'message' => 'Booking already issued',
                'pnr' => $booking['pnr'],
                'booking_status' => $booking['booking_status'],
                'already_issued' => true,
                'response_error' => ''
            ]);
            exit;
        }

        // ============================================================================
        // STEP 3: GENERATE UNIQUE PNR
        // ============================================================================
        // Format: PNR + 6 hexadecimal characters (uppercase)
        // Example: PNR4A7F2E
        $pnr = 'PNR' . strtoupper(substr(md5($invoice_id . time()), 0, 6));


        // ============================================================================
        // STEP 4: UPDATE DATABASE
        // ============================================================================
        $updated = $db->update('bookings', [
            'pnr' => $pnr,
            'booking_status' => 'confirmed'
        ], [
            'invoice_id' => $invoice_id
        ]);

        if (!$updated) {
            throw new Exception('Failed to update booking. Database error occurred.');
        }

        // ============================================================================
        // STEP 5: TRIGGER WEBHOOK (IF CONFIGURED)
        // ============================================================================
        if (function_exists('triggerWebhook')) {
            triggerWebhook('tours/booking', 'tours.booking.issued', [
                'invoice_id' => $invoice_id,
                'pnr' => $pnr,
                'booking_id' => $booking['id'] ?? null,
                'user_id' => $booking['user_id'] ?? null,
                'customer_email' => $booking['email'] ?? '',
                'customer_name' => ($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''),
                'tour_name' => json_decode($booking['booking_data'], true)['tour_name'] ?? '',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }

        // ============================================================================
        // STEP 6: RETURN SUCCESS RESPONSE
        // ============================================================================
        echo json_encode([
            'status' => true,
            'Prn' => $pnr,                          // payment-gateway.php expects 'Prn'
            'booking_reference' => $pnr,
            'reference' => $pnr,
            'message' => 'Tour booking issued successfully',
            'pnr' => $pnr,                          // keep for backward compatibility
            'booking_status' => 'confirmed',
            'invoice_id' => $invoice_id,
            'response_error' => ''
        ]);

    } catch (Exception $e) {
        // ============================================================================
        // ERROR HANDLING
        // ============================================================================
        error_log("TOURS ISSUE ERROR - Invoice: {$invoice_id}, Error: " . $e->getMessage());

        http_response_code(400);
        echo json_encode([
            'status' => false,
            'Prn' => '',
            'booking_reference' => '',
            'reference' => '',
            'message' => $e->getMessage(),
            'response_error' => $e->getMessage()
        ]);
    }

    exit;
});
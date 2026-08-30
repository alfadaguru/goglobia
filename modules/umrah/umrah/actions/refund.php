<?php
// ============================================================================
// FILE: modules/umrah/umrah/actions/refund.php
// UMRAH REFUND REQUEST ACTION - Processes refund for an umrah booking
// ============================================================================
@$SECURE or die('Access Denied!');

$router->post('umrah/umrah/refund', function() use ($db) {
    header('Content-Type: application/json');

    try {
        // STEP 1: VALIDATE INPUT
        $invoice_id = $_POST['invoice_id'] ?? '';
        $module_type = $_POST['module_type'] ?? 'umrah';

        if (empty($invoice_id)) {
            throw new Exception('Invoice ID is required');
        }

        // STEP 2: FETCH BOOKING FROM DATABASE
        $booking = $db->get('bookings', '*', [
            'invoice_id' => $invoice_id,
            'module_type' => 'umrah'
        ]);

        if (!$booking) {
            throw new Exception('Umrah booking not found');
        }

        // STEP 3: UPDATE DATABASE
        $updated = $db->update('bookings', [
            'payment_status' => 'refunded',
            'booking_status' => 'cancelled',
            'cancellation_status' => 1,
            'cancellation_response' => 'Refund requested by admin on ' . date('Y-m-d H:i:s')
        ], ['invoice_id' => $invoice_id]);

        if (!$updated) {
            throw new Exception('Failed to process refund. Database error occurred.');
        }

        // STEP 4: TRIGGER WEBHOOK (IF CONFIGURED)
        if (function_exists('triggerWebhook')) {
            triggerWebhook('umrah/booking', 'umrah.booking.refunded', [
                'invoice_id' => $invoice_id,
                'booking_id' => $booking['id'] ?? null,
                'user_id' => $booking['user_id'] ?? null,
                'customer_email' => $booking['email'] ?? '',
                'customer_name' => ($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }

        // STEP 5: RETURN SUCCESS RESPONSE
        echo json_encode([
            'status' => true,
            'message' => 'Umrah refund request processed successfully',
            'invoice_id' => $invoice_id,
            'booking_status' => 'cancelled',
            'payment_status' => 'refunded'
        ]);

    } catch (Exception $e) {
        error_log("UMRAH REFUND ERROR - Invoice: {$invoice_id}, Error: " . $e->getMessage());

        http_response_code(400);
        echo json_encode([
            'status' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
});

<?php
// ============================================================================
// FILE: modules/umrah/umrah/actions/issue.php
// UMRAH ISSUE BOOKING ACTION - Confirms booking and generates PNR
// ============================================================================
@$SECURE or die('Access Denied!');

$router->post('umrah/umrah/issue', function() use ($db) {
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

        // STEP 3: GENERATE UNIQUE PNR
        $pnr = 'PNR' . strtoupper(substr(md5($invoice_id . time()), 0, 6));

        // STEP 4: UPDATE DATABASE
        $updated = $db->update('bookings', [
            'pnr' => $pnr,
            'booking_status' => 'confirmed'
        ], ['invoice_id' => $invoice_id]);

        if (!$updated) {
            throw new Exception('Failed to update booking. Database error occurred.');
        }

        // STEP 5: TRIGGER WEBHOOK (IF CONFIGURED)
        if (function_exists('triggerWebhook')) {
            triggerWebhook('umrah/booking', 'umrah.booking.issued', [
                'invoice_id' => $invoice_id,
                'pnr' => $pnr,
                'booking_id' => $booking['id'] ?? null,
                'user_id' => $booking['user_id'] ?? null,
                'customer_email' => $booking['email'] ?? '',
                'customer_name' => ($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }

        // STEP 6: RETURN SUCCESS RESPONSE
        echo json_encode([
            'status' => true,
            'Prn' => $pnr,
            'booking_reference' => $pnr,
            'reference' => $pnr,
            'message' => 'Umrah booking issued successfully',
            'pnr' => $pnr,
            'booking_status' => 'confirmed',
            'invoice_id' => $invoice_id,
            'response_error' => ''
        ]);

    } catch (Exception $e) {
        error_log("UMRAH ISSUE ERROR - Invoice: {$invoice_id}, Error: " . $e->getMessage());

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

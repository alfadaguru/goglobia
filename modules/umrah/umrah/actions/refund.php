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

        // Already refunded — idempotent no-op.
        if (($booking['payment_status'] ?? '') === 'refunded') {
            echo json_encode(['status' => true, 'message' => 'Umrah booking already refunded', 'invoice_id' => $invoice_id]);
            exit;
        }
        // Only a paid booking can be refunded — umrah is paid on-platform, so a
        // refund MUST return the customer's money, not just flip a status.
        if (($booking['payment_status'] ?? '') !== 'paid') {
            throw new Exception('No payment found to refund');
        }

        // STEP 3: REVERSE THE CUSTOMER'S CHARGE, then set DB state from the ACTUAL
        // result. Previously this flipped payment_status to 'refunded' with NO
        // money movement, so a customer who had paid was told they were refunded
        // while their money was never returned. refund_gateway_payment() credits a
        // wallet payment back through the spine (idempotent per invoice) or issues
        // a Paystack/Stripe card refund. Mirrors hotelbeds/hotels refund actions.
        require_once dirname(__DIR__, 4) . '/app/lib/payment-gateway.php';
        $refund = function_exists('refund_gateway_payment')
            ? refund_gateway_payment($db, $booking, null, 'Umrah booking refund')
            : ['status' => 'unsupported', 'message' => 'Refund function unavailable', 'gateway' => ''];
        $gatewayRefunded = (($refund['status'] ?? '') === 'refunded');

        if ($gatewayRefunded) {
            $updated = $db->update('bookings', [
                'payment_status'        => 'refunded',
                'booking_status'        => 'cancelled',
                'cancellation_status'   => 1,
                'cancellation_response' => 'Gateway refund ' . ($refund['reference'] ?? '') . ' on ' . date('Y-m-d H:i:s'),
                'error_response'        => null,
            ], ['invoice_id' => $invoice_id]);
        } else {
            // Money did NOT move — do NOT mark 'refunded'. Cancel + flag manual.
            $updated = $db->update('bookings', [
                'booking_status'        => 'cancelled',
                'cancellation_status'   => 1,
                'cancellation_response' => 'Gateway refund NOT automated (' . ($refund['message'] ?? 'unsupported') . '). Refund the customer manually.',
            ], ['invoice_id' => $invoice_id]);
        }

        if ($updated === false) {
            throw new Exception('Failed to process refund. Database error occurred.');
        }

        // STEP 4: TRIGGER WEBHOOK (IF CONFIGURED) — only when money was actually returned.
        if ($gatewayRefunded && function_exists('triggerWebhook')) {
            triggerWebhook('umrah/booking', 'umrah.booking.refunded', [
                'invoice_id' => $invoice_id,
                'booking_id' => $booking['id'] ?? null,
                'user_id' => $booking['user_id'] ?? null,
                'customer_email' => $booking['email'] ?? '',
                'customer_name' => ($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? ''),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }

        // STEP 5: RETURN RESPONSE reflecting the ACTUAL outcome.
        echo json_encode([
            'status' => true,
            'message' => $gatewayRefunded
                ? ('Umrah refund completed via ' . ($refund['gateway'] ?? 'gateway') . ' (ref ' . ($refund['reference'] ?? '') . ').')
                : ('Booking cancelled, but the automated refund was not possible (' . ($refund['message'] ?? 'unsupported') . '). Please refund the customer manually.'),
            'invoice_id' => $invoice_id,
            'booking_status' => 'cancelled',
            'payment_status' => $gatewayRefunded ? 'refunded' : ($booking['payment_status'] ?? 'paid'),
            'gateway_refund' => $gatewayRefunded
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

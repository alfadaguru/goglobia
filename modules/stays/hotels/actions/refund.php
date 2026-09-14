<?php
// path : modules/stays/hotels/actions/refund.php
// REFUND REQUEST ACTION
// Processes a refund for a self-operated ("hotels") booking. Because these are
// booked and PAID on-platform, a refund MUST reverse the customer's charge —
// crediting a wallet payment back through the spine, or issuing a Paystack/
// Stripe card refund — before the booking may be marked 'refunded'. Previously
// this action flipped payment_status to 'refunded' with NO money movement, so a
// customer who had paid was told they were refunded while their money was never
// returned. Mirrors modules/stays/hotelbeds/actions/refund.php.
@$SECURE or die('Access Denied!');

$router->post('/stays/hotels/refund', function() use ($db) {
    if (ob_get_level()) { ob_end_clean(); }
    ob_start();
    header('Content-Type: application/json; charset=UTF-8');

    $invoice_id = '';
    try {
        $invoice_id = $_POST['invoice_id'] ?? '';
        if (empty($invoice_id)) {
            throw new Exception('Invoice ID required');
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        if (!$booking) {
            throw new Exception('Booking not found');
        }

        // GUARDS: only a cancelled + paid + not-already-refunded booking can be
        // refunded (same order as hotelbeds).
        if (($booking['payment_status'] ?? '') === 'refunded') {
            ob_clean();
            echo json_encode(['status' => true, 'message' => 'Booking already refunded', 'invoice_id' => $invoice_id]);
            exit;
        }
        if (($booking['booking_status'] ?? '') !== 'cancelled') {
            throw new Exception('Booking must be cancelled before requesting refund');
        }
        if (($booking['payment_status'] ?? '') !== 'paid') {
            throw new Exception('No payment found to refund');
        }

        // REVERSE THE CUSTOMER'S CHARGE, then set DB state from the ACTUAL result.
        // refund_gateway_payment credits a wallet payment back through the spine
        // (idempotent per invoice) or issues a card refund via Paystack/Stripe.
        require_once dirname(__DIR__, 4) . '/app/lib/payment-gateway.php';
        $refund = function_exists('refund_gateway_payment')
            ? refund_gateway_payment($db, $booking, null, 'Hotels booking refund')
            : ['status' => 'unsupported', 'message' => 'Refund function unavailable', 'gateway' => ''];

        $gatewayRefunded = (($refund['status'] ?? '') === 'refunded');

        if ($gatewayRefunded) {
            $db->update('bookings', [
                'payment_status'        => 'refunded',
                'booking_status'        => 'cancelled',
                'cancellation_status'   => 1,
                'cancellation_response' => 'Gateway refund ' . ($refund['reference'] ?? '') . ' on ' . date('Y-m-d H:i:s'),
                'error_response'        => null,
            ], ['invoice_id' => $invoice_id]);
        } else {
            // Money did NOT move — do NOT mark 'refunded'. Flag for manual action.
            $db->update('bookings', [
                'cancellation_status'   => 1,
                'cancellation_response' => 'Gateway refund NOT automated (' . ($refund['message'] ?? 'unsupported') . '). Refund the customer manually.',
            ], ['invoice_id' => $invoice_id]);
        }

        ob_clean();
        echo json_encode([
            'status'  => true,
            'message' => $gatewayRefunded
                ? ('Refund completed via ' . ($refund['gateway'] ?? 'gateway') . ' (ref ' . ($refund['reference'] ?? '') . ').')
                : ('Booking processed, but the automated refund was not possible (' . ($refund['message'] ?? 'unsupported') . '). Please refund the customer manually.'),
            'data' => [
                'invoice_id'     => $invoice_id,
                'payment_status' => $gatewayRefunded ? 'refunded' : ($booking['payment_status'] ?? 'paid'),
                'gateway_refund' => $gatewayRefunded,
                'gateway_message'=> $refund['message'] ?? '',
                'amount'         => $booking['price_markup'] ?? 0,
                'currency'       => $booking['currency_markup'] ?? 'USD',
                'refund_date'    => date('Y-m-d H:i:s'),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Exception $e) {
        error_log("HOTELS REFUND ERROR - Invoice: {$invoice_id}, Error: " . $e->getMessage());
        if (!empty($invoice_id)) {
            $db->update('bookings', [
                'error_response' => json_encode(['error' => $e->getMessage(), 'timestamp' => date('Y-m-d H:i:s'), 'action' => 'refund']),
            ], ['invoice_id' => $invoice_id]);
        }
        ob_clean();
        echo json_encode(['status' => false, 'message' => $e->getMessage(), 'data' => ['invoice_id' => $invoice_id]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
});

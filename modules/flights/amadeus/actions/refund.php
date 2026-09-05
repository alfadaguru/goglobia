<?php
// ============================================================================
// AMADEUS FLIGHT REFUND ENDPOINT
// ============================================================================
//
// ENDPOINT: POST /flights/amadeus/refund
//
// SUPPLIER REALITY (verified against Amadeus Self-Service / Enterprise docs):
//   Amadeus Flight Order Management exposes create / retrieve / delete
//   (void) for an order, but there is NO programmatic *refund* endpoint on
//   the Self-Service tier — a ticket refund is settled through ARC/BSP and
//   is performed by the ticketing agency, not via API. Enterprise refund
//   (Ticket_ProcessETicket / TRFD) requires a signed PNR session that this
//   module is not provisioned for.
//
//   Therefore the maximum automation possible here is:
//     1. reverse the CUSTOMER's card charge via the payment gateway
//        (real refund if the gateway supports it — Paystack/Stripe do), and
//     2. flag the booking for a manual ARC/BSP refund by operations.
//
//   booking_status ENUM is confirmed|pending|cancelled ('refunded' would
//   truncate to ''), so we set booking_status='cancelled' and carry the real
//   money state in payment_status ('refunded' vs untouched).
// ============================================================================

@$SECURE or die('Access Denied!');

$router->post('flights/amadeus/refund', function () use ($db) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');

    try {
        if (!$db) {
            throw new Exception('Database connection not available');
        }

        $invoice_id    = trim((string) ($_POST['invoice_id'] ?? ''));
        $refund_reason = trim((string) ($_POST['refund_reason'] ?? 'Amadeus flight refund'));
        if ($invoice_id === '') {
            echo json_encode(['status' => false, 'message' => 'Missing invoice_id parameter']);
            exit;
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found for invoice_id: ' . $invoice_id]);
            exit;
        }

        // Idempotency: already refunded.
        if (($booking['payment_status'] ?? '') === 'refunded') {
            echo json_encode([
                'status'     => true,
                'message'    => 'Booking already marked as refunded.',
                'invoice_id' => $invoice_id,
            ]);
            exit;
        }

        // Reverse the customer's charge via the gateway (real API for
        // Paystack/Stripe; 'unsupported' otherwise → manual).
        require_once dirname(__DIR__, 4) . '/app/lib/payment-gateway.php';
        $gwRefund = function_exists('refund_gateway_payment')
            ? refund_gateway_payment($db, $booking, null, $refund_reason)
            : ['status' => 'unsupported', 'message' => 'Refund function unavailable', 'gateway' => ''];
        $gatewayRefunded = ($gwRefund['status'] === 'refunded');

        $db->update('bookings', [
            'booking_status'        => 'cancelled',
            'payment_status'        => $gatewayRefunded ? 'refunded' : ($booking['payment_status'] ?? 'paid'),
            'cancellation_status'   => 1,
            'cancellation_response' => json_encode([
                'supplier'       => 'amadeus',
                'method'         => 'gateway_refund_plus_manual_arc_bsp',
                'gateway_refund' => $gwRefund,
                'requested_at'   => date('Y-m-d H:i:s'),
                'reason'         => $refund_reason,
            ]),
        ], ['invoice_id' => $invoice_id]);

        echo json_encode([
            'status'          => true,
            'invoice_id'      => $invoice_id,
            'gateway_refund'  => $gatewayRefunded,
            'message'         => $gatewayRefunded
                ? ('Card refunded via ' . ($gwRefund['gateway'] ?? 'gateway') . '. Complete the ARC/BSP ticket refund with Amadeus manually.')
                : ('Automated card refund not possible (' . ($gwRefund['message'] ?? 'unsupported') . '). Refund the customer and process the ARC/BSP ticket refund manually.'),
            'important_notes' => [
                'Amadeus has no Self-Service refund API — ticket refund is settled via ARC/BSP by the ticketing agency.',
                'Refund eligibility and penalties depend on the fare rules of the issued ticket.',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        error_log('AMADEUS REFUND ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'Failed to process refund request', 'error' => $e->getMessage()]);
    }
});

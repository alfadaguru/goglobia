<?php
// path : modules/cars/cartrawler/refund.php
// REFUND CAR BOOKING ACTION FOR CARTRAWLER
//
// SUPPLIER REALITY: CarTrawler's OTA interface exposes OTA_VehCancelRQ
// (cancellation) but no programmatic refund — a refund follows the supplier's
// cancellation policy and is settled by CarTrawler/the rental partner. The
// most automation possible here is to reverse the CUSTOMER's card charge via
// the payment gateway (real for Paystack/Stripe) and flag the rest for manual
// follow-up. Cancel the reservation first (cars/cartrawler/cancel) so the
// supplier side is released before refunding the customer.
//
// booking_status ENUM is confirmed|pending|cancelled — money state lives in
// payment_status.
@$SECURE or die('Access Denied!');

$router->post('cars/cartrawler/refund', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $invoice_id    = trim((string) ($_POST['invoice_id'] ?? ''));
        $refund_reason = trim((string) ($_POST['refund_reason'] ?? 'CarTrawler car refund'));
        if ($invoice_id === '') {
            echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
            exit;
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found']);
            exit;
        }

        if (($booking['payment_status'] ?? '') === 'refunded') {
            echo json_encode(['status' => true, 'message' => 'Booking already marked as refunded.', 'invoice_id' => $invoice_id]);
            exit;
        }

        require_once dirname(__DIR__, 3) . '/app/lib/payment-gateway.php';
        $gwRefund = function_exists('refund_gateway_payment')
            ? refund_gateway_payment($db, $booking, null, $refund_reason)
            : ['status' => 'unsupported', 'message' => 'Refund function unavailable', 'gateway' => ''];
        $gatewayRefunded = ($gwRefund['status'] === 'refunded');

        $db->update('bookings', [
            'booking_status'        => 'cancelled',
            'payment_status'        => $gatewayRefunded ? 'refunded' : ($booking['payment_status'] ?? 'paid'),
            'cancellation_status'   => 1,
            'cancellation_response' => json_encode([
                'supplier'       => 'cartrawler',
                'method'         => 'gateway_refund_plus_manual',
                'gateway_refund' => $gwRefund,
                'requested_at'   => date('Y-m-d H:i:s'),
                'reason'         => $refund_reason,
            ]),
        ], ['invoice_id' => $invoice_id]);

        echo json_encode([
            'status'         => true,
            'invoice_id'     => $invoice_id,
            'gateway_refund' => $gatewayRefunded,
            'message'        => $gatewayRefunded
                ? ('Card refunded via ' . ($gwRefund['gateway'] ?? 'gateway') . '. Ensure the reservation was cancelled with CarTrawler first.')
                : ('Automated card refund not possible (' . ($gwRefund['message'] ?? 'unsupported') . '). Refund the customer manually per the fare cancellation policy.'),
            'note'           => 'CarTrawler has no refund API; cancel the reservation via cars/cartrawler/cancel, then the customer refund follows the cancellation policy.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        echo json_encode(['status' => false, 'message' => 'Exception: ' . $e->getMessage()]);
    }
});

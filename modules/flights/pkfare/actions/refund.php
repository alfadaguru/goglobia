<?php
// ============================================================================
// PKFARE FLIGHT REFUND
// ============================================================================
// ENDPOINT: POST /flights/pkfare/refund
//
// IMPORTANT (why this file was rewritten):
//   This file previously contained a byte-for-byte COPY of actions/issue.php,
//   which (a) registered a SECOND `flights/pkfare/issue` route — a collision
//   that could override the real issue handler — and (b) meant a "refund"
//   request would actually create an airline booking. Both are fixed here.
//
// PKFare's post-ticketing refund is processed through PKFare's OrderRefund /
// refund-application API + their refund desk (partner-gated; the exact endpoint
// and signature are provided per-partner in PKFare's docs and are NOT public).
// Rather than fabricate an unverified remote call, this handler records the
// customer refund request against the booking (payment_status='refunded' — the
// bookings enum's refund state) and defers the actual money movement to the
// operator/PKFare desk, exactly like the other flight refund modules do. When
// the OrderRefund endpoint/signature are confirmed for this account, add the
// real cURL call here (mirror actions/void.php's OrderCancel auth:
// signature = md5(partnerId . apiKey), base https://api.pkfare.com/air/api/...).
//
// NB: this reverses the DB state only. The customer's card is refunded by the
// operator in the payment gateway — the platform-wide reality documented in
// docs/MODULES.md §8.1(1).
// ============================================================================

$router->post('flights/pkfare/refund', function () use ($db) {

    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);

    $invoice_id = '';

    try {
        if (!$db) {
            throw new Exception('Database connection not available');
        }

        $invoice_id = $_POST['invoice_id'] ?? '';
        if (empty($invoice_id)) {
            throw new Exception('Missing invoice_id parameter in POST data');
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        if (!$booking) {
            throw new Exception('Booking not found for invoice_id: ' . $invoice_id);
        }

        // Idempotency: don't re-refund.
        if (($booking['payment_status'] ?? '') === 'refunded') {
            ob_clean();
            echo json_encode([
                'status'         => true,
                'message'        => 'Booking is already marked refunded.',
                'invoice_id'     => $invoice_id,
                'payment_status' => 'refunded',
            ]);
            return;
        }

        // Reverse the CUSTOMER's charge via the payment gateway. PKFare's airline
        // refund runs through its OrderRefund desk (partner-gated), but the card
        // refund we CAN do here. Only mark 'refunded' if the gateway refund works.
        require_once dirname(__DIR__, 4) . '/app/lib/payment-gateway.php';
        $pkGwRefund = function_exists('refund_gateway_payment')
            ? refund_gateway_payment($db, $booking, null, 'PKFare flight refund')
            : ['status' => 'unsupported', 'message' => 'Refund function unavailable', 'gateway' => ''];
        $pkGatewayRefunded = ($pkGwRefund['status'] === 'refunded');

        // booking_status enum = confirmed|pending|cancelled; payment_status enum =
        // paid|unpaid|refunded. Use those valid values only.
        $db->update('bookings', [
            'payment_status'      => $pkGatewayRefunded ? 'refunded' : ($booking['payment_status'] ?? 'paid'),
            'booking_status'      => 'cancelled',
            'cancellation_status' => 1,
            'cancellation_request'=> 1,
            'cancellation_response'=> json_encode(['gateway_refund' => $pkGwRefund]),
            'error_response'      => json_encode([
                'refund_state' => $pkGatewayRefunded ? 'card_refunded_airline_desk_pending' : 'requested',
                'note'         => 'PKFare refund on ' . date('Y-m-d H:i:s') .
                                  '. Airline refund via PKFare OrderRefund/desk' .
                                  ($pkGatewayRefunded ? '.' : '; customer card refund NOT automated (' . ($pkGwRefund['message'] ?? 'unsupported') . ') — process manually.'),
            ]),
        ], ['invoice_id' => $invoice_id]);

        error_log('PKFARE REFUND: booking ' . $invoice_id . ' gateway_refund=' . $pkGwRefund['status']);

        ob_clean();
        echo json_encode([
            'status'         => true,
            'message'        => $pkGatewayRefunded
                ? ('Customer card refunded via ' . ($pkGwRefund['gateway'] ?? 'gateway') . '. The PKFare airline refund is processed via the PKFare desk.')
                : ('Recorded. Automated card refund not possible (' . ($pkGwRefund['message'] ?? 'unsupported') . '); the airline (PKFare desk) and card refund are processed manually by an operator.'),
            'invoice_id'     => $invoice_id,
            'payment_status' => $pkGatewayRefunded ? 'refunded' : ($booking['payment_status'] ?? 'paid'),
            'gateway_refund' => $pkGatewayRefunded,
        ], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        error_log('PKFARE REFUND ERROR (' . $invoice_id . '): ' . $e->getMessage());
        ob_clean();
        echo json_encode(['status' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
    }
});

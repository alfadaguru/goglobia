<?php
// path: modules/tours/adivaha/actions/refund.php
// Adivaha B2C Excursions — REFUND (POST tours/adivaha/refund).
// Adivaha performs any SUPPLIER-side refund MANUALLY. This endpoint reverses the
// CUSTOMER's charge on OUR gateway (Paystack/wallet/etc.) and cancels the booking.
// Mirrors modules/stays/adivaha/actions/refund.php exactly.

global $router;

$router->post('tours/adivaha/refund', function () use ($db) {

    header('Content-Type: application/json');

    $invoice_id = trim((string) ($_POST['invoice_id'] ?? ''));
    if ($invoice_id === '') { echo json_encode(['status' => false, 'message' => 'Missing invoice_id']); return; }

    if (function_exists('supplier_action_guard') && !supplier_action_guard($invoice_id)) { return; }

    $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
    if (!$booking) { echo json_encode(['status' => false, 'message' => 'Booking not found', 'invoice_id' => $invoice_id]); return; }

    try {
        require_once dirname(__DIR__, 4) . '/app/lib/payment-gateway.php';
        $refund = function_exists('refund_gateway_payment')
            ? refund_gateway_payment($db, $booking, null, 'Adivaha excursion refund')
            : ['status' => 'unsupported', 'message' => 'Refund function unavailable', 'gateway' => ''];

        if (($refund['status'] ?? '') === 'refunded') {
            $db->update('bookings', [
                'payment_status'        => 'refunded',
                'booking_status'        => 'cancelled',
                'cancellation_status'   => 1,
                'cancellation_response' => 'Gateway refund ' . ($refund['reference'] ?? '') . ' on ' . date('Y-m-d H:i:s'),
            ], ['invoice_id' => $invoice_id]);
            echo json_encode([
                'status'  => true,
                'message' => 'Refund completed via ' . ($refund['gateway'] ?? 'gateway') . ' (ref ' . ($refund['reference'] ?? '') . ').',
                'gateway_refund' => true,
            ]);
            return;
        }

        $db->update('bookings', [
            'booking_status'        => 'cancelled',
            'cancellation_status'   => 1,
            'cancellation_response' => 'Cancelled; gateway refund NOT automated (' . ($refund['message'] ?? 'unsupported') . '). Process the customer refund manually. Any Adivaha supplier refund is handled manually.',
        ], ['invoice_id' => $invoice_id]);

        echo json_encode([
            'status'  => true,
            'message' => 'Booking cancelled. Automated card refund was not possible (' . ($refund['message'] ?? 'unsupported') . '). Please refund the customer manually. Any Adivaha supplier refund is handled manually.',
            'gateway_refund' => false,
            'gateway' => $refund['gateway'] ?? '',
        ]);
    } catch (Throwable $e) {
        error_log('ADIVAHA EXCURSION REFUND ERROR (' . $invoice_id . '): ' . $e->getMessage());
        try {
            $db->update('bookings', [
                'booking_payment_issue' => 'Refund threw an error (' . substr($e->getMessage(), 0, 160) . '). Verify at the gateway before any retry — money may already have moved.',
            ], ['invoice_id' => $invoice_id]);
        } catch (Throwable $e2) { /* ignore */ }
        echo json_encode(['status' => false, 'message' => 'Refund could not be completed cleanly — flagged for manual review. Check the payment gateway before retrying.']);
    }
});

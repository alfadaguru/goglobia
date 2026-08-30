<?php
/**
 * TBO Holidays refund
 * Hotel API V2.1 has no separate Refund method — refund is after Cancel.
 * Updates payment_status to refunded (keeps booking_status cancelled/voided).
 *
 * POST stays/tbo-holidays/refund
 */

$router->post('stays/tbo-holidays/refund', function () use ($db) {

    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    header('Content-Type: application/json; charset=UTF-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    $invoiceId = '';

    try {
        $invoiceId = $_POST['invoice_id'] ?? '';
        if ($invoiceId === '') {
            throw new Exception('Missing invoice_id parameter');
        }

        $booking = $db->get('bookings', '*', [
            'invoice_id' => $invoiceId,
            'module' => 'tbo-holidays'
        ]);
        if (!$booking) {
            throw new Exception('Booking not found for invoice_id: ' . $invoiceId);
        }

        if (!in_array($booking['booking_status'], ['cancelled'], true)) {
            throw new Exception('Booking must be cancelled with TBO Holidays before requesting refund');
        }

        if (($booking['payment_status'] ?? '') === 'refunded') {
            throw new Exception('Booking has already been refunded');
        }

        if (($booking['payment_status'] ?? '') !== 'paid') {
            throw new Exception('No payment found to refund');
        }

        $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
        if (!is_array($bookingData)) {
            $bookingData = [];
        }

        $amount = (float) (
            $booking['price_markup']
            ?? $booking['total']
            ?? $bookingData['total_amount']
            ?? $bookingData['display_total']
            ?? 0
        );
        $currency = $booking['currency_markup']
            ?? $booking['currency']
            ?? $bookingData['display_currency']
            ?? $bookingData['currency']
            ?? 'USD';

        // Prefer amounts from TBO Cancel response when present
        $tboCancel = $bookingData['tbo_cancel'] ?? [];
        $tboRefundAmount = $tboCancel['RefundAmount']
            ?? $tboCancel['refund_amount']
            ?? null;
        $tboCancelCharge = $tboCancel['CancellationCharge']
            ?? $tboCancel['cancellation_charge']
            ?? null;

        if ($tboRefundAmount !== null && $tboRefundAmount !== '') {
            $amount = (float) $tboRefundAmount;
        }

        $refundDate = date('Y-m-d H:i:s');
        $bookingData['tbo_refund'] = [
            'refunded_at' => $refundDate,
            'amount' => $amount,
            'currency' => $currency,
            'cancellation_charge' => $tboCancelCharge,
            'confirmation_number' => $booking['pnr'] ?? ($bookingData['tbo_confirmation'] ?? null),
            'note' => 'Payment refund marked after TBO Holidays cancellation (no separate Refund API)',
        ];

        $db->update('bookings', [
            'payment_status' => 'refunded',
            'booking_data' => json_encode($bookingData),
            'error_response' => null,
            'booking_payment_issue' => null,
            'updated_at' => $refundDate,
        ], ['id' => $booking['id']]);

        $responseData = [
            'invoice_id' => $invoiceId,
            'payment_status' => 'refunded',
            'booking_status' => $booking['booking_status'],
            'pnr' => $booking['pnr'] ?? null,
            'amount' => $amount,
            'currency' => $currency,
            'cancellation_charge' => $tboCancelCharge,
            'refund_date' => $refundDate,
        ];

        if (ob_get_level()) {
            ob_clean();
        }
        echo json_encode([
            'success' => true,
            'status' => true,
            'message' => 'Refund request processed successfully. Payment status updated to refunded.',
            'data' => $responseData,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        error_log('TBO Holidays refund error: ' . $e->getMessage());

        if (!empty($invoiceId) && isset($booking) && $booking) {
            $db->update('bookings', [
                'error_response' => json_encode([
                    'error' => $e->getMessage(),
                    'timestamp' => date('Y-m-d H:i:s'),
                    'action' => 'refund',
                ]),
            ], ['id' => $booking['id']]);
        }

        http_response_code(400);
        if (ob_get_level()) {
            ob_clean();
        }
        echo json_encode([
            'success' => false,
            'status' => false,
            'message' => $e->getMessage(),
            'data' => [
                'invoice_id' => $invoiceId ?: null,
                'error' => $e->getMessage(),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
});

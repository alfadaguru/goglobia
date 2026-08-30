<?php
/**
 * Wanderbeds refund
 * No separate Refund API — refund is marked after Cancel.
 * POST stays/wanderbeds/refund
 */

$router->post('stays/wanderbeds/refund', function () use ($db) {

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
            'module' => 'wanderbeds',
        ]);
        if (!$booking) {
            throw new Exception('Booking not found for invoice_id: ' . $invoiceId);
        }

        if (!in_array($booking['booking_status'], ['cancelled'], true)) {
            throw new Exception('Booking must be cancelled with Wanderbeds before requesting refund');
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

        $refundDate = date('Y-m-d H:i:s');
        $bookingData['wb_refund'] = [
            'refunded_at' => $refundDate,
            'amount' => $amount,
            'currency' => $currency,
            'booking_reference' => $bookingData['wb_booking_reference'] ?? $booking['pnr'] ?? null,
            'note' => 'Payment refund marked after Wanderbeds cancellation (no separate Refund API)',
        ];

        $db->update('bookings', [
            'payment_status' => 'refunded',
            'booking_data' => json_encode($bookingData),
            'error_response' => null,
            'booking_payment_issue' => null,
            'updated_at' => $refundDate,
        ], ['id' => $booking['id']]);

        if (ob_get_level()) {
            ob_clean();
        }
        echo json_encode([
            'success' => true,
            'status' => true,
            'message' => 'Refund request processed successfully. Payment status updated to refunded.',
            'data' => [
                'invoice_id' => $invoiceId,
                'payment_status' => 'refunded',
                'booking_status' => $booking['booking_status'],
                'pnr' => $booking['pnr'] ?? null,
                'amount' => $amount,
                'currency' => $currency,
                'refund_date' => $refundDate,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        error_log('Wanderbeds refund error: ' . $e->getMessage());

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

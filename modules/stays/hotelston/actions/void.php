<?php
/**
 * HOTELSTON VOID — cancel booking before supplier PNR is generated
 * ENDPOINT: POST /stays/hotelston/void
 */

$router->post('stays/hotelston/void', function () use ($db) {
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
        if (!$db) {
            throw new Exception('Database connection not available');
        }

        $invoiceId = trim((string)($_POST['invoice_id'] ?? ''));
        if ($invoiceId === '') {
            throw new Exception('Missing invoice_id parameter');
        }

        $booking = $db->get('bookings', '*', [
            'invoice_id' => $invoiceId,
            'module'     => 'hotelston',
        ]);

        if (!$booking) {
            throw new Exception('Booking not found for invoice_id: ' . $invoiceId);
        }

        if ($booking['booking_status'] === 'cancelled') {
            throw new Exception('Booking is already cancelled');
        }

        if (!empty($booking['pnr'])) {
            throw new Exception('Cannot void booking - PNR already generated. Please use cancel instead.');
        }

        $hadPayment = ($booking['payment_status'] === 'paid');

        $db->update('bookings', [
            'booking_status'  => 'cancelled',
            'error_response'  => null,
            'updated_at'      => date('Y-m-d H:i:s'),
        ], ['id' => $booking['id']]);

        $responseData = [
            'status'         => true,
            'invoice_id'     => $invoiceId,
            'booking_status' => 'cancelled',
            'void_date'      => date('Y-m-d H:i:s'),
        ];

        if ($hadPayment) {
            $responseData['amount'] = $booking['price_markup'] ?? 0;
            $responseData['currency'] = $booking['currency_markup'] ?? 'USD';
        }

        ob_clean();
        echo json_encode([
            'status'  => true,
            'success' => true,
            'message' => 'Void request processed successfully. Booking cancelled.',
            'data'    => $responseData,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        error_log('HOTELSTON VOID ERROR - Invoice: ' . $invoiceId . ', Error: ' . $e->getMessage());

        if (isset($booking) && $booking) {
            $db->update('bookings', [
                'error_response' => json_encode([
                    'error'     => $e->getMessage(),
                    'timestamp' => date('Y-m-d H:i:s'),
                    'action'    => 'void',
                ]),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $booking['id']]);
        }

        ob_clean();
        http_response_code(400);
        echo json_encode([
            'status'  => false,
            'success' => false,
            'message' => $e->getMessage(),
            'data'    => [
                'invoice_id' => $invoiceId !== '' ? $invoiceId : null,
                'error'      => $e->getMessage(),
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
});

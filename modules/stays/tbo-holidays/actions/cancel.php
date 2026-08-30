<?php
/**
 * TBO Holidays cancel
 * POST stays/tbo-holidays/cancel
 */

require_once __DIR__ . '/../api.php';

$router->post('stays/tbo-holidays/cancel', function () use ($db) {

    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    header('Content-Type: application/json');

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
            throw new Exception('Booking not found');
        }

        if (empty($booking['pnr'])) {
            throw new Exception('Booking has not been issued yet. Cannot cancel.');
        }
        if (in_array($booking['booking_status'], ['cancelled', 'voided'], true)) {
            throw new Exception('Booking is already cancelled');
        }

        $moduleData = tboHolidaysGetModule($db);
        if (!$moduleData) {
            throw new Exception('TBO Holidays module not configured');
        }

        $cancelPayload = [
            'ConfirmationNumber' => $booking['pnr']
        ];
        $result = tboHolidaysCall($moduleData, 'Cancel', $cancelPayload, 'POST', 60);
        logApiCall(
            'Cancel',
            $cancelPayload,
            tboHolidaysSanitizeForLog($result['data'] ?? ['error' => $result['error'] ?? null]),
            (int) ($result['http_code'] ?? 0),
            __DIR__ . '/../logs',
            'booking_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $invoiceId)
        );

        $status = (int) ($result['status_code'] ?? 0);
        $desc = strtolower((string) ($result['data']['Status']['Description'] ?? $result['error'] ?? ''));
        $alreadyCancelled = ($status === 405 && str_contains($desc, 'already cancelled'));

        if ((!$result['success'] || $status !== 200) && !$alreadyCancelled) {
            throw new Exception(tboHolidaysStatusMessage(
                $status,
                (string) ($result['error'] ?? $result['data']['Status']['Description'] ?? 'Cancel failed')
            ));
        }

        $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
        if (!is_array($bookingData)) {
            $bookingData = [];
        }
        $bookingData['tbo_cancel'] = $result['data'] ?? null;

        $db->update('bookings', [
            'booking_status' => 'cancelled',
            'booking_data' => json_encode($bookingData),
            'error_response' => null,
            'booking_payment_issue' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $booking['id']]);

        echo json_encode([
            'success' => true,
            'message' => 'Booking cancelled successfully',
            'confirmation_number' => $booking['pnr'],
            'data' => $result['data'] ?? null,
        ]);
    } catch (Exception $e) {
        if (!empty($booking['id'])) {
            $db->update('bookings', [
                'error_response' => json_encode([
                    'action' => 'cancel',
                    'message' => $e->getMessage(),
                    'timestamp' => date('Y-m-d H:i:s'),
                ]),
            ], ['id' => $booking['id']]);
        }
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
});

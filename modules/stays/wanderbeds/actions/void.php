<?php
/**
 * Wanderbeds void — maps to Cancel (no separate void API)
 */

require_once __DIR__ . '/../api.php';

$router->post('stays/wanderbeds/void', function () use ($db) {

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
            'module' => 'wanderbeds',
        ]);
        if (!$booking) {
            throw new Exception('Booking not found');
        }
        if (empty($booking['pnr'])) {
            throw new Exception('Booking has not been issued yet. Cannot void.');
        }

        $moduleData = wanderbedsGetModule($db);
        if (!$moduleData) {
            throw new Exception('Wanderbeds module not configured');
        }

        $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
        if (!is_array($bookingData)) {
            $bookingData = [];
        }

        $bookingRef = (string) ($bookingData['wb_booking_reference'] ?? $booking['pnr']);
        $voidPayload = [
            'booking_reference' => $bookingRef,
            'reference' => '',
        ];
        $result = wanderbedsCall($moduleData, 'hotel/cancel', $voidPayload, 'POST', 60);
        logApiCall(
            'VoidAsCancel',
            $voidPayload,
            wanderbedsSanitizeForLog($result['data'] ?? ['error' => $result['error'] ?? null]),
            (int) ($result['http_code'] ?? 0),
            __DIR__ . '/../logs',
            'booking_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $invoiceId)
        );

        $dataSuccess = $result['data']['data']['success'] ?? $result['data']['success'] ?? null;
        $desc = strtolower((string) ($result['error'] ?? ''));
        $alreadyCancelled = str_contains($desc, 'already cancel');

        if (!$result['success'] && !$alreadyCancelled && $dataSuccess !== true) {
            throw new Exception((string) ($result['error'] ?? 'Void/Cancel failed'));
        }

        $bookingData['wb_void'] = [
            'voided_at' => date('Y-m-d H:i:s'),
            'booking_reference' => $bookingRef,
            'data' => $result['data'] ?? null,
        ];

        $db->update('bookings', [
            'booking_status' => 'cancelled',
            'booking_data' => json_encode($bookingData),
            'error_response' => null,
            'booking_payment_issue' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $booking['id']]);

        echo json_encode([
            'success' => true,
            'message' => 'Booking voided (cancelled) successfully',
            'confirmation_number' => $booking['pnr'],
        ]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
});

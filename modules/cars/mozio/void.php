<?php
// modules/cars/mozio/void.php
// VOID BOOKING ACTION FOR MOZIO
// POST modules/cars/mozio/void  (invoice_id)
//
// Mozio has no distinct "void" operation — a transfer reservation is released
// with the same DELETE /v2/reservations/<id>/ used for cancel/refund. This
// endpoint exists for a uniform lifecycle surface and performs that
// cancellation (which also triggers Mozio's own refund per fare policy).
@$SECURE or die('Access Denied!');

require_once __DIR__ . '/lib.php';

if (!function_exists('logApiCall')) {
    require_once __DIR__ . '/../../helpers.php';
}

$router->post('cars/mozio/void', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $invoiceId = trim((string) ($_POST['invoice_id'] ?? ''));
        if ($invoiceId === '') {
            echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
            exit;
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found']);
            exit;
        }

        if (in_array(($booking['booking_status'] ?? ''), ['cancelled', 'voided'], true)) {
            echo json_encode(['status' => true, 'message' => 'Booking is already cancelled/voided.', 'invoice_id' => $invoiceId]);
            exit;
        }

        $cfg = mozioModuleConfig($db);
        if (!$cfg) {
            echo json_encode(['status' => false, 'message' => 'Mozio module not configured']);
            exit;
        }

        $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
        if (!is_array($bookingData)) {
            $bookingData = [];
        }

        $reservationId = trim((string) ($bookingData['mozio']['reservation_id'] ?? ''));
        if ($reservationId === '') {
            echo json_encode(['status' => false, 'message' => 'Mozio reservation ID not found. Nothing to void.']);
            exit;
        }

        $result = mozioApiRequest($cfg, 'DELETE', '/v2/reservations/' . rawurlencode($reservationId) . '/');

        if (function_exists('log_setting') && log_setting($db, 'mozio') == '1') {
            logApiCall(
                'mozio_reservation_void',
                ['reservation_id' => $reservationId, 'invoice_id' => $invoiceId],
                $result['data'] ?? ['error' => $result['curl_error'] ?? 'Empty response'],
                $result['http_code'],
                __DIR__ . '/logs',
                'Mozio_Void'
            );
        }

        if (!empty($result['curl_error'])) {
            echo json_encode(['status' => false, 'message' => 'Network error: ' . $result['curl_error']]);
            exit;
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];

        if (!empty($result['success']) && !empty($data['cancelled'])) {
            $refunded = !empty($data['refunded']);
            $mergedBookingData = array_merge($bookingData, [
                'mozio' => array_merge($bookingData['mozio'] ?? [], [
                    'cancelled'    => true,
                    'refunded'     => $refunded,
                    'cancelled_at' => date('Y-m-d H:i:s'),
                ]),
            ]);

            $db->update('bookings', [
                'booking_status'       => 'cancelled',
                'payment_status'       => $refunded ? 'refunded' : ($booking['payment_status'] ?? 'paid'),
                'cancellation_request' => 1,
                'cancellation_status'  => 1,
                'booking_data'         => json_encode($mergedBookingData),
            ], ['invoice_id' => $invoiceId]);

            echo json_encode(['status' => true, 'message' => 'Reservation voided (cancelled) by Mozio.', 'refunded' => $refunded]);
            exit;
        }

        $errMsg = 'Mozio void/cancellation request failed';
        if (!empty($data['non_field_errors']) && is_array($data['non_field_errors'])) {
            $first  = $data['non_field_errors'][0] ?? [];
            $errMsg = (string) ($first['user_message'] ?? $first['message'] ?? $errMsg);
        } elseif (!empty($data['detail'])) {
            $errMsg = (string) $data['detail'];
        } elseif (!empty($data['message'])) {
            $errMsg = (string) $data['message'];
        }

        echo json_encode(['status' => false, 'message' => $errMsg, 'api_response' => mb_substr($result['body'] ?? '', 0, 1000)]);
    } catch (Throwable $e) {
        echo json_encode(['status' => false, 'message' => 'Exception: ' . $e->getMessage()]);
    }
});

<?php
// modules/cars/mozio/cancel.php
// CANCEL BOOKING ACTION FOR MOZIO
// Called from Admin > Bookings > Edit > "Cancel Booking"
// POST modules/cars/mozio/cancel  (invoice_id, cancel_all=1)
//
// Mozio docs: DELETE /v2/reservations/<reservation_id>/ — synchronous, no
// polling. Success: HTTP 202 { "cancelled": 1, "refunded": 1 }. Not every
// reservation is cancellable (see cancellation_policy captured at search
// time) and this cannot be undone once Mozio accepts it.

@$SECURE or die('Access Denied!');

require_once __DIR__ . '/lib.php';

if (!function_exists('logApiCall')) {
    require_once __DIR__ . '/../../helpers.php';
}

$router->post('cars/mozio/cancel', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $invoiceId = trim((string)($_POST['invoice_id'] ?? ''));
        if ($invoiceId === '') {
            echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
            exit;
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found']);
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

        $reservationId = trim((string)($bookingData['mozio']['reservation_id'] ?? ''));
        if ($reservationId === '') {
            echo json_encode([
                'status'  => false,
                'message' => 'Mozio reservation ID not found. Cannot cancel a booking that was never confirmed with Mozio.',
            ]);
            exit;
        }

        $result = mozioApiRequest($cfg, 'DELETE', '/v2/reservations/' . rawurlencode($reservationId) . '/');

        if (function_exists('log_setting') && log_setting($db, 'mozio') == '1') {
            logApiCall(
                'mozio_reservation_cancel',
                ['reservation_id' => $reservationId, 'invoice_id' => $invoiceId],
                $result['data'] ?? ['error' => $result['curl_error'] ?? 'Empty response'],
                $result['http_code'],
                __DIR__ . '/logs',
                'Mozio_Cancel'
            );
        }

        if (!empty($result['curl_error'])) {
            echo json_encode(['status' => false, 'message' => 'Network error: ' . $result['curl_error']]);
            exit;
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];

        if (!empty($result['success']) && !empty($data['cancelled'])) {
            $mergedBookingData = array_merge($bookingData, [
                'mozio' => array_merge($bookingData['mozio'], [
                    'cancelled'        => true,
                    'refunded'         => !empty($data['refunded']),
                    'cancelled_at'     => date('Y-m-d H:i:s'),
                ]),
            ]);

            $db->update('bookings', [
                'booking_status'        => 'cancelled',
                'cancellation_request'  => 1,
                'cancellation_status'   => 1,
                'booking_data'          => json_encode($mergedBookingData),
            ], ['invoice_id' => $invoiceId]);

            echo json_encode([
                'status'  => true,
                'message' => !empty($data['refunded'])
                    ? 'Booking cancelled and refunded by Mozio.'
                    : 'Booking cancelled by Mozio. No refund was issued (check the fare\'s cancellation policy).',
            ]);
            exit;
        }

        // Mozio's documented cancellation errors (res_already_canceled,
        // too_late_to_cancel, etc.) come back in the same {code, message,
        // user_message} / non_field_errors shape as every other endpoint.
        $errMsg = 'Mozio cancellation request failed';
        if (!empty($data['non_field_errors']) && is_array($data['non_field_errors'])) {
            $first = $data['non_field_errors'][0] ?? [];
            $errMsg = (string)($first['user_message'] ?? $first['message'] ?? $errMsg);
        } elseif (!empty($data['detail'])) {
            $errMsg = (string)$data['detail'];
        } elseif (!empty($data['message'])) {
            $errMsg = (string)$data['message'];
        }

        echo json_encode([
            'status'       => false,
            'message'      => $errMsg,
            'api_response' => mb_substr($result['body'] ?? '', 0, 1000),
        ]);
    } catch (Throwable $e) {
        echo json_encode(['status' => false, 'message' => 'Exception: ' . $e->getMessage()]);
    }
});

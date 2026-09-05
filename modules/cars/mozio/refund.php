<?php
// modules/cars/mozio/refund.php
// REFUND BOOKING ACTION FOR MOZIO
// POST modules/cars/mozio/refund  (invoice_id)
//
// SUPPLIER REALITY: Mozio is the Merchant of Record — it charges the traveller
// and issues any refund itself. There is no separate refund endpoint: the
// documented DELETE /v2/reservations/<id>/ returns {"cancelled":1,"refunded":1}
// and Mozio refunds per the fare's cancellation_policy captured at search time.
//
// Because Mozio (not our payment gateway) took the money, we do NOT call
// refund_gateway_payment here — that would double-refund. We call Mozio's
// cancel/refund DELETE and surface Mozio's own `refunded` flag. If the
// reservation was already cancelled, we report the stored state.
//
// booking_status ENUM is confirmed|pending|cancelled — money state in
// payment_status.
@$SECURE or die('Access Denied!');

require_once __DIR__ . '/lib.php';

if (!function_exists('logApiCall')) {
    require_once __DIR__ . '/../../helpers.php';
}

$router->post('cars/mozio/refund', function () use ($db) {
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

        if (($booking['payment_status'] ?? '') === 'refunded') {
            echo json_encode(['status' => true, 'message' => 'Booking already marked as refunded by Mozio.', 'invoice_id' => $invoiceId]);
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
            echo json_encode([
                'status'  => false,
                'message' => 'Mozio reservation ID not found. Nothing to refund for a booking that was never confirmed with Mozio.',
            ]);
            exit;
        }

        // Already cancelled with Mozio? Report the refund flag we stored.
        if (!empty($bookingData['mozio']['cancelled'])) {
            $wasRefunded = !empty($bookingData['mozio']['refunded']);
            if ($wasRefunded && ($booking['payment_status'] ?? '') !== 'refunded') {
                $db->update('bookings', ['payment_status' => 'refunded'], ['invoice_id' => $invoiceId]);
            }
            echo json_encode([
                'status'  => true,
                'message' => $wasRefunded
                    ? 'Mozio already cancelled and refunded this reservation.'
                    : 'Mozio already cancelled this reservation but issued no refund (per the fare cancellation policy).',
                'refunded' => $wasRefunded,
            ]);
            exit;
        }

        $result = mozioApiRequest($cfg, 'DELETE', '/v2/reservations/' . rawurlencode($reservationId) . '/');

        if (function_exists('log_setting') && log_setting($db, 'mozio') == '1') {
            logApiCall(
                'mozio_reservation_refund',
                ['reservation_id' => $reservationId, 'invoice_id' => $invoiceId],
                $result['data'] ?? ['error' => $result['curl_error'] ?? 'Empty response'],
                $result['http_code'],
                __DIR__ . '/logs',
                'Mozio_Refund'
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

            echo json_encode([
                'status'   => true,
                'refunded' => $refunded,
                'message'  => $refunded
                    ? 'Reservation cancelled and refunded by Mozio.'
                    : 'Reservation cancelled by Mozio, but no refund was issued (check the fare cancellation policy).',
            ]);
            exit;
        }

        $errMsg = 'Mozio refund/cancellation request failed';
        if (!empty($data['non_field_errors']) && is_array($data['non_field_errors'])) {
            $first  = $data['non_field_errors'][0] ?? [];
            $errMsg = (string) ($first['user_message'] ?? $first['message'] ?? $errMsg);
        } elseif (!empty($data['detail'])) {
            $errMsg = (string) $data['detail'];
        } elseif (!empty($data['message'])) {
            $errMsg = (string) $data['message'];
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

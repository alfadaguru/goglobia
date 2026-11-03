<?php
// path: modules/flights/tiqwa/actions/cancel.php
// CANCEL BOOKING ACTION — Tiqwa Flights.
// Tiqwa cancels with POST /v1/flight/{booking_reference} (the booking reference
// returned by /book, e.g. TW-...). Cancelled bookings CANNOT be reissued
// (Flight.v1.json). Tiqwa has no refund endpoint — any customer refund on OUR
// gateway is a separate step (handled by the platform / refund.php).
@$SECURE or die('Access Denied!');

$router->post('flights/tiqwa/cancel', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $invoice_id = $_POST['invoice_id'] ?? '';
        if (empty($invoice_id)) {
            echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
            exit;
        }
        if (function_exists('supplier_action_guard') && !supplier_action_guard($invoice_id)) { return; }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found']);
            exit;
        }
        if (strtolower((string) ($booking['booking_status'] ?? '')) === 'cancelled') {
            echo json_encode(['status' => true, 'message' => 'Booking is already cancelled', 'reference' => $booking['pnr'] ?? '']);
            exit;
        }

        $credentials = getTiqwaCredentials($db);
        if (!$credentials || $credentials['api_token'] === '') {
            echo json_encode(['status' => false, 'message' => 'Tiqwa credentials not configured']);
            exit;
        }
        $token = $credentials['api_token'];
        $base  = $credentials['flight_base'];

        $booking_data = json_decode((string) $booking['booking_data'], true);
        if (!is_array($booking_data)) $booking_data = [];

        // The booking reference to cancel (TW-...). Stored at issue (reserve) time.
        $reference = '';
        foreach ([
            $booking_data['supplier_ref'] ?? '',
            $booking_data['tiqwa_reference'] ?? '',
        ] as $cand) {
            if (trim((string) $cand) !== '') { $reference = (string) $cand; break; }
        }
        if ($reference === '') {
            echo json_encode(['status' => false, 'message' => 'Supplier booking reference not found — cannot cancel at Tiqwa.']);
            exit;
        }

        $url = $base . '/' . rawurlencode($reference);
        $res = tiqwaApiRequest('POST', $url, [], $token, [], 90);
        tiqwaLog('cancel', ['reference' => $reference], $res['raw'], $res['http_code'], $res['curl_error'], $invoice_id);

        $data = is_array($res['data']) ? $res['data'] : [];
        $cancelled = ($res['success'] && (
                !empty($data['success'])
                || strtolower((string) ($data['status'] ?? '')) === 'cancelled'
                || !empty($data['message'])
            ))
            || ($res['http_code'] === 409 && stripos(tiqwaExtractError($res), 'cancel') !== false);

        if (!$cancelled) {
            echo json_encode(['status' => false, 'message' => 'Tiqwa cancel failed: ' . tiqwaExtractError($res), 'http' => $res['http_code']]);
            exit;
        }

        $booking_data['tiqwa_cancel'] = [
            'reference'    => $reference,
            'status'       => $data['status'] ?? 'cancelled',
            'message'      => $data['message'] ?? 'Booking cancelled at Tiqwa.',
            'cancelled_at' => date('c'),
        ];
        $db->update('bookings', [
            'booking_status'        => 'cancelled',
            'cancellation_status'   => 1,
            'cancellation_response' => json_encode($booking_data['tiqwa_cancel']),
            'booking_data'          => json_encode($booking_data),
        ], ['invoice_id' => $invoice_id]);

        echo json_encode([
            'status'    => true,
            'message'   => 'Flight booking cancelled with Tiqwa. Any customer refund is processed separately on the platform.',
            'reference' => $reference,
        ]);
        exit;

    } catch (Throwable $e) {
        error_log('TIQWA CANCEL ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred while cancelling: ' . $e->getMessage()]);
        exit;
    }
});

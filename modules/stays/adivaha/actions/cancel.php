<?php
// path: modules/stays/adivaha/actions/cancel.php
// CANCEL BOOKING ACTION — Adivaha B2C Hotels
// Cancels a hotel booking at the supplier (POST /hotels/cancel, idempotent). Any
// supplier/customer refund is MANUAL (spec: cancel_charge/refund_amount null,
// refund_mode "manual"). The hotel may ACCEPT but not yet PROCESS a cancel — then
// Adivaha returns 200 with cancellation_status "requested" and the booking stays
// live until processed; we reflect that rather than falsely flipping to cancelled.
@$SECURE or die('Access Denied!');

$router->post('/stays/adivaha/cancel', function () use ($db) {
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

        $credentials = getAdivahaHotelCredentials($db);
        if (!$credentials || $credentials['api_key'] === '') {
            echo json_encode(['status' => false, 'message' => 'Adivaha hotels API credentials not configured']);
            exit;
        }
        $bearer = adivahaHBearer($db, $credentials);
        $base   = $credentials['base_url'];

        $booking_data = json_decode((string) $booking['booking_data'], true);
        if (!is_array($booking_data)) $booking_data = [];
        // First non-empty supplier reference.
        $reference = '';
        foreach ([
            $booking_data['supplier_ref'] ?? '',
            $booking_data['adivaha_response']['reference'] ?? '',
            $booking['pnr'] ?? '',
        ] as $cand) {
            if (trim((string) $cand) !== '') { $reference = (string) $cand; break; }
        }
        if ($reference === '') {
            echo json_encode(['status' => false, 'message' => 'Supplier booking reference not found — cannot cancel at Adivaha.']);
            exit;
        }

        $idemKey = 'gg-hcxl-' . substr(hash('sha256', (string) $invoice_id), 0, 26);
        $res = adivahaHApiRequest('POST', $base . '/hotels/cancel', ['reference' => $reference], $bearer, ['Idempotency-Key: ' . $idemKey], 90);
        if ($res['http_code'] === 401 && $credentials['partner_id'] !== '') {
            $bearer = adivahaHBearer($db, $credentials, true);
            $res = adivahaHApiRequest('POST', $base . '/hotels/cancel', ['reference' => $reference], $bearer, ['Idempotency-Key: ' . $idemKey], 90);
        }
        adivahaHLog('cancel', ['reference' => $reference], $res['raw'], $res['http_code'], $res['curl_error'], $invoice_id);

        $data = is_array($res['data']) ? $res['data'] : [];
        $statusV   = strtolower((string) ($data['status'] ?? ''));
        $cxlStatus = strtolower((string) ($data['cancellation_status'] ?? ''));

        // "requested" = hotel accepted but not processed yet → booking stays live.
        if ($res['success'] && $cxlStatus === 'requested') {
            $merged = array_merge($booking_data, ['adivaha_cancel' => $data]);
            $db->update('bookings', [
                'cancellation_request'  => 1,
                'cancellation_response' => json_encode($data),
                'booking_data'          => json_encode($merged),
            ], ['invoice_id' => $invoice_id]);
            echo json_encode([
                'status'  => true,
                'message' => (string) ($data['message'] ?? 'Your cancellation request has been sent to the hotel. We will confirm once it is processed; any refund is handled manually.'),
                'cancellation_status' => 'requested',
                'reference' => $reference,
            ]);
            exit;
        }

        $cancelled = ($res['success'] && (!empty($data['success']) || $statusV === 'cancelled'))
            || ($res['http_code'] === 409 && stripos(adivahaHExtractError($res), 'cancel') !== false);

        if (!$cancelled) {
            echo json_encode(['status' => false, 'message' => 'Adivaha hotel cancel failed: ' . adivahaHExtractError($res), 'http' => $res['http_code']]);
            exit;
        }

        $merged = array_merge($booking_data, ['adivaha_cancel' => [
            'reference' => $reference, 'status' => $data['status'] ?? 'cancelled',
            'refund_mode' => $data['refund_mode'] ?? 'manual',
            'refund_note' => $data['refund_note'] ?? 'Any refund is handled manually after the cancellation is processed.',
            'cancelled_at' => date('c'),
        ]]);
        $db->update('bookings', [
            'booking_status'        => 'cancelled',
            'cancellation_status'   => 1,
            'cancellation_response' => json_encode($merged['adivaha_cancel']),
            'booking_data'          => json_encode($merged),
        ], ['invoice_id' => $invoice_id]);

        echo json_encode([
            'status' => true,
            'message' => 'Hotel booking cancelled with Adivaha. ' . ($data['refund_note'] ?? 'Any refund is processed manually.'),
            'reference' => $reference, 'refund_mode' => $data['refund_mode'] ?? 'manual',
        ]);
        exit;

    } catch (Throwable $e) {
        error_log('ADIVAHA HOTEL CANCEL ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred while cancelling: ' . $e->getMessage()]);
        exit;
    }
});

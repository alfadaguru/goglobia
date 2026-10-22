<?php
// path: modules/stays/adivaha/actions/void.php
// VOID BOOKING ACTION — Adivaha B2C Hotels
// Adivaha has no separate hotel "void" — termination is POST /hotels/cancel. So:
//   • no supplier reference stored (never booked upstream) → DB-level void only;
//   • otherwise → real supplier cancel (same call cancel.php makes).
@$SECURE or die('Access Denied!');

$router->post('/stays/adivaha/void', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $invoice_id = trim((string) ($_POST['invoice_id'] ?? ''));
        if ($invoice_id === '') {
            echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
            exit;
        }
        if (function_exists('supplier_action_guard') && !supplier_action_guard($invoice_id)) { return; }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found']);
            exit;
        }
        if (in_array(strtolower((string) ($booking['booking_status'] ?? '')), ['cancelled', 'voided'], true)) {
            echo json_encode(['status' => true, 'message' => 'Booking is already cancelled/voided.', 'invoice_id' => $invoice_id]);
            exit;
        }

        $booking_data = json_decode((string) ($booking['booking_data'] ?? '{}'), true);
        if (!is_array($booking_data)) $booking_data = [];
        $reference = '';
        foreach ([
            $booking_data['supplier_ref'] ?? '',
            $booking_data['adivaha_response']['reference'] ?? '',
            $booking['pnr'] ?? '',
        ] as $cand) {
            if (trim((string) $cand) !== '') { $reference = (string) $cand; break; }
        }

        // Nothing upstream → DB-level void.
        if ($reference === '') {
            $db->update('bookings', [
                'booking_status'        => 'cancelled',
                'cancellation_status'   => 1,
                'cancellation_response' => 'Voided at DB level (no Adivaha booking reference present) on ' . date('Y-m-d H:i:s'),
            ], ['invoice_id' => $invoice_id]);
            echo json_encode(['status' => true, 'message' => 'Booking voided (no Adivaha hotel booking was confirmed).', 'invoice_id' => $invoice_id]);
            exit;
        }

        $credentials = getAdivahaHotelCredentials($db);
        if (!$credentials || $credentials['api_key'] === '') {
            echo json_encode(['status' => false, 'message' => 'Adivaha hotels API credentials not configured']);
            exit;
        }
        $bearer = adivahaHBearer($db, $credentials);
        $base   = $credentials['base_url'];

        $idemKey = 'gg-hvoid-' . substr(hash('sha256', (string) $invoice_id), 0, 25);
        $res = adivahaHApiRequest('POST', $base . '/hotels/cancel', ['reference' => $reference], $bearer, ['Idempotency-Key: ' . $idemKey], 90);
        if ($res['http_code'] === 401 && $credentials['partner_id'] !== '') {
            $bearer = adivahaHBearer($db, $credentials, true);
            $res = adivahaHApiRequest('POST', $base . '/hotels/cancel', ['reference' => $reference], $bearer, ['Idempotency-Key: ' . $idemKey], 90);
        }
        adivahaHLog('void(cancel)', ['reference' => $reference], $res['raw'], $res['http_code'], $res['curl_error'], $invoice_id);

        $data = is_array($res['data']) ? $res['data'] : [];
        $ok = ($res['success'] && (!empty($data['success']) || strtolower((string) ($data['status'] ?? '')) === 'cancelled'
                || strtolower((string) ($data['cancellation_status'] ?? '')) === 'requested'))
            || ($res['http_code'] === 409 && stripos(adivahaHExtractError($res), 'cancel') !== false);

        if (!$ok) {
            echo json_encode(['status' => false, 'message' => 'Adivaha hotel void/cancel failed: ' . adivahaHExtractError($res) . '. The booking was NOT changed — retry or cancel manually.', 'http' => $res['http_code']]);
            exit;
        }

        $merged = array_merge($booking_data, ['adivaha_void' => [
            'reference' => $reference, 'status' => $data['status'] ?? 'cancelled',
            'cancellation_status' => $data['cancellation_status'] ?? null,
            'voided_at' => date('c'),
        ]]);
        $db->update('bookings', [
            'booking_status'        => 'cancelled',
            'cancellation_status'   => 1,
            'cancellation_response' => json_encode($merged['adivaha_void']),
            'booking_data'          => json_encode($merged),
        ], ['invoice_id' => $invoice_id]);

        echo json_encode(['status' => true, 'message' => 'Hotel booking voided/cancelled with Adivaha.', 'reference' => $reference]);
        exit;

    } catch (Throwable $e) {
        error_log('ADIVAHA HOTEL VOID ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred while voiding: ' . $e->getMessage()]);
        exit;
    }
});

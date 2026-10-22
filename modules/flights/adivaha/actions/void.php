<?php
// path : modules/flights/adivaha/actions/void.php
// VOID BOOKING ACTION — Adivaha B2C Flights
//
// Adivaha has NO separate "void" transaction — the only termination operation is
// POST /flights/cancel (spec L598; no void path exists). So this endpoint:
//   • if the booking was never issued at the supplier (no stored reference) →
//     DB-level void only (nothing to release upstream);
//   • otherwise → performs the real supplier cancel (same call cancel.php makes).
// This mirrors how Duffel's void degrades to its cancellation API, keeping the
// admin surface consistent across providers.
@$SECURE or die('Access Denied!');

$router->post('/flights/adivaha/void', function () use ($db) {
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
        // First NON-EMPTY reference (?? only skips null, not '').
        $reference = '';
        foreach ([
            $booking_data['supplier_ref'] ?? '',
            $booking_data['order_id'] ?? '',
            $booking_data['adivaha_response']['reference'] ?? '',
        ] as $cand) {
            if (trim((string) $cand) !== '') { $reference = (string) $cand; break; }
        }

        // Nothing confirmed upstream → DB-level void only.
        if ($reference === '' && empty($booking['pnr'])) {
            $db->update('bookings', [
                'booking_status'        => 'cancelled',
                'cancellation_status'   => 1,
                'cancellation_response' => 'Voided at DB level (no Adivaha booking reference present) on ' . date('Y-m-d H:i:s'),
            ], ['invoice_id' => $invoice_id]);
            echo json_encode(['status' => true, 'message' => 'Booking voided (no Adivaha booking was confirmed for this booking).', 'invoice_id' => $invoice_id]);
            exit;
        }
        if ($reference === '') $reference = (string) $booking['pnr'];

        $credentials = getAdivahaCredentials($db);
        if (!$credentials || $credentials['api_key'] === '') {
            echo json_encode(['status' => false, 'message' => 'Adivaha API credentials not configured']);
            exit;
        }
        $bearer = adivahaBearer($db, $credentials);
        $base   = $credentials['base_url'];

        $idemKey = 'gg-void-' . substr(hash('sha256', (string) $invoice_id), 0, 27);
        $res = adivahaApiRequest(
            'POST',
            $base . '/flights/cancel',
            ['reference' => $reference],
            $bearer,
            ['Idempotency-Key: ' . $idemKey],
            90
        );
        if ($res['http_code'] === 401 && $credentials['partner_id'] !== '') {
            $bearer = adivahaBearer($db, $credentials, true);
            $res = adivahaApiRequest('POST', $base . '/flights/cancel', ['reference' => $reference], $bearer, ['Idempotency-Key: ' . $idemKey], 90);
        }
        adivahaLog('void(cancel)', ['reference' => $reference], $res['raw'], $res['http_code'], $res['curl_error'], $invoice_id);

        $data = is_array($res['data']) ? $res['data'] : [];
        $ok = ($res['success'] && (!empty($data['success']) || strtolower((string) ($data['status'] ?? '')) === 'cancelled'))
            || ($res['http_code'] === 409 && stripos(adivahaExtractError($res), 'cancel') !== false);

        if (!$ok) {
            echo json_encode([
                'status'  => false,
                'message' => 'Adivaha void/cancel failed: ' . adivahaExtractError($res) . '. The booking was NOT changed — retry or cancel manually.',
                'http'    => $res['http_code'],
            ]);
            exit;
        }

        $merged = array_merge($booking_data, [
            'adivaha_void' => [
                'reference'    => $reference,
                'status'       => $data['status'] ?? 'cancelled',
                'refund_mode'  => $data['refund_mode'] ?? 'manual',
                'refund_note'  => $data['refund_note'] ?? 'Any refund is handled manually after cancellation.',
                'voided_at'    => date('c'),
            ],
        ]);

        $db->update('bookings', [
            'booking_status'        => 'cancelled',
            'cancellation_status'   => 1,
            'cancellation_response' => json_encode($merged['adivaha_void']),
            'booking_data'          => json_encode($merged),
        ], ['invoice_id' => $invoice_id]);

        echo json_encode([
            'status'      => true,
            'message'     => 'Flight booking voided/cancelled with Adivaha. ' . ($data['refund_note'] ?? 'Any supplier refund is processed manually.'),
            'reference'   => $reference,
            'refund_mode' => $data['refund_mode'] ?? 'manual',
        ]);
        exit;

    } catch (Throwable $e) {
        error_log('ADIVAHA VOID ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred while voiding: ' . $e->getMessage()]);
        exit;
    }
});

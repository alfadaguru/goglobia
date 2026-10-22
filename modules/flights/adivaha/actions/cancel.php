<?php
// path : modules/flights/adivaha/actions/cancel.php
// CANCEL BOOKING ACTION — Adivaha B2C Flights
// Cancels a confirmed Adivaha booking (POST /flights/cancel, idempotent). Adivaha
// handles any supplier-side refund MANUALLY (spec L1189-1206: charge/refund are
// always null, refund_mode "manual"), so this endpoint cancels the ticket and
// flags the booking cancelled; the CUSTOMER refund on our gateway is a separate
// step handled by refund.php.
@$SECURE or die('Access Denied!');

$router->post('/flights/adivaha/cancel', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $invoice_id = $_POST['invoice_id'] ?? '';
        if (empty($invoice_id)) {
            echo json_encode(['status' => false, 'message' => 'Invoice ID required']);
            exit;
        }

        // Defense in depth (the central guard in modules/index.php also covers this).
        if (function_exists('supplier_action_guard') && !supplier_action_guard($invoice_id)) { return; }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
        if (!$booking) {
            echo json_encode(['status' => false, 'message' => 'Booking not found']);
            exit;
        }

        // Idempotent: already cancelled → report success.
        if (strtolower((string) ($booking['booking_status'] ?? '')) === 'cancelled') {
            echo json_encode(['status' => true, 'message' => 'Booking is already cancelled', 'reference' => $booking['pnr'] ?? '']);
            exit;
        }

        $credentials = getAdivahaCredentials($db);
        if (!$credentials || $credentials['api_key'] === '') {
            echo json_encode(['status' => false, 'message' => 'Adivaha API credentials not configured']);
            exit;
        }
        $bearer = adivahaBearer($db, $credentials);
        $base   = $credentials['base_url'];

        // Resolve the supplier reference to cancel by. Adivaha's cancel accepts the
        // booking id (BK...) OR the storefront order id; we stored the supplier
        // reference as supplier_ref / order_id at issue time.
        $booking_data = json_decode((string) $booking['booking_data'], true);
        if (!is_array($booking_data)) $booking_data = [];
        // First NON-EMPTY reference (?? only skips null, not '' — a stored empty
        // supplier_ref would otherwise shadow a valid order_id / response ref).
        $reference = '';
        foreach ([
            $booking_data['supplier_ref'] ?? '',
            $booking_data['order_id'] ?? '',
            $booking_data['adivaha_response']['reference'] ?? '',
        ] as $cand) {
            if (trim((string) $cand) !== '') { $reference = (string) $cand; break; }
        }
        if ($reference === '' && !empty($booking['pnr'])) {
            // Last resort — some flows store only the PNR; Adivaha matches on its
            // own booking id, so without a stored reference we cannot proceed.
            $reference = (string) $booking['pnr'];
        }
        if ($reference === '') {
            echo json_encode([
                'status'  => false,
                'message' => 'Supplier booking reference not found — cannot cancel at Adivaha.',
                'debug'   => ['available_keys' => array_keys($booking_data)],
            ]);
            exit;
        }

        // Stable Idempotency-Key so a retried cancel never double-processes.
        $idemKey = 'gg-cxl-' . substr(hash('sha256', (string) $invoice_id), 0, 28);

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
        adivahaLog('cancel', ['reference' => $reference], $res['raw'], $res['http_code'], $res['curl_error'], $invoice_id);

        $data = is_array($res['data']) ? $res['data'] : [];
        $cancelled = ($res['success'] && (!empty($data['success']) || strtolower((string) ($data['status'] ?? '')) === 'cancelled'))
            // A 409 "already cancelled" is also a terminal success for us.
            || ($res['http_code'] === 409 && stripos(adivahaExtractError($res), 'cancel') !== false);

        if (!$cancelled) {
            echo json_encode([
                'status'  => false,
                'message' => 'Adivaha cancel failed: ' . adivahaExtractError($res),
                'http'    => $res['http_code'],
            ]);
            exit;
        }

        // Persist the cancelled state + the supplier's cancel note. Adivaha's
        // refund figures are intentionally null (manual) — we store the note.
        $merged = array_merge($booking_data, [
            'adivaha_cancel' => [
                'reference'   => $reference,
                'status'      => $data['status'] ?? 'cancelled',
                'refund_mode' => $data['refund_mode'] ?? 'manual',
                'refund_note' => $data['refund_note'] ?? 'Any refund is handled manually after cancellation.',
                'cancelled_at' => date('c'),
            ],
        ]);

        $db->update('bookings', [
            'booking_status'        => 'cancelled',
            'cancellation_status'   => 1,
            'cancellation_response' => json_encode($merged['adivaha_cancel']),
            'booking_data'          => json_encode($merged),
        ], ['invoice_id' => $invoice_id]);

        echo json_encode([
            'status'      => true,
            'message'     => 'Flight booking cancelled with Adivaha. ' . ($data['refund_note'] ?? 'Any supplier refund is processed manually.'),
            'reference'   => $reference,
            'refund_mode' => $data['refund_mode'] ?? 'manual',
        ]);
        exit;

    } catch (Throwable $e) {
        error_log('ADIVAHA CANCEL ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred while cancelling: ' . $e->getMessage()]);
        exit;
    }
});

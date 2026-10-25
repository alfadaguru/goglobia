<?php
// path: modules/tours/adivaha/actions/cancel.php
// Adivaha B2C Excursions — CANCEL (POST tours/adivaha/cancel).
// Calls POST /excursions/cancel (idempotent) and flips the booking to cancelled.
// Any refund is handled MANUALLY by ops (owner rule 2026-10-04); the supplier
// leg is reconciled by the operations desk (Adivaha has no live cancel endpoint
// on the activity leg — the API settles the booking + wallet locally on its side).

global $router;

$router->post('tours/adivaha/cancel', function () use ($db) {

    header('Content-Type: application/json');

    $invoice_id = trim((string) ($_POST['invoice_id'] ?? ''));
    if ($invoice_id === '') { echo json_encode(['status' => false, 'message' => 'Missing invoice_id']); return; }

    if (function_exists('supplier_action_guard') && !supplier_action_guard($invoice_id)) { return; }

    $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);
    if (!$booking) { echo json_encode(['status' => false, 'message' => 'Booking not found', 'invoice_id' => $invoice_id]); return; }

    $booking_data = json_decode((string) ($booking['booking_data'] ?? ''), true);
    if (!is_array($booking_data)) $booking_data = [];

    $reference = trim((string) (
        $booking_data['supplier_ref']
        ?? $booking_data['adivaha_response']['reference']
        ?? $booking['pnr']
        ?? ''
    ));
    if ($reference === '') {
        echo json_encode(['status' => false, 'message' => 'No Adivaha booking reference to cancel.', 'invoice_id' => $invoice_id]);
        return;
    }

    $credentials = getAdivahaExcursionCredentials($db);
    if (!$credentials || $credentials['api_key'] === '') { echo json_encode(['status' => false, 'message' => 'Excursions supplier not configured.']); return; }
    $base   = $credentials['base_url'];
    $bearer = adivahaXBearer($db, $credentials);

    $idemKey = 'gg-xcxl-' . substr(hash('sha256', (string) $invoice_id), 0, 26);
    $res = adivahaXApiRequest('POST', $base . '/excursions/cancel', ['reference' => $reference], $bearer, ['Idempotency-Key: ' . $idemKey], 90);
    if ($res['http_code'] === 401 && $credentials['partner_id'] !== '') {
        $bearer = adivahaXBearer($db, $credentials, true);
        $res = adivahaXApiRequest('POST', $base . '/excursions/cancel', ['reference' => $reference], $bearer, ['Idempotency-Key: ' . $idemKey], 90);
    }
    adivahaXLog('cancel', ['reference' => $reference], $res['raw'], $res['http_code'], $res['curl_error'], $invoice_id);

    $data = is_array($res['data']) ? $res['data'] : [];
    $statusV = strtolower((string) ($data['status'] ?? ''));
    // 200 success/cancelled (or an already-cancelled idempotent replay) → cancelled.
    $cancelled = $res['success'] && (!empty($data['success']) || $statusV === 'cancelled');

    if (!$cancelled) {
        echo json_encode(['status' => false, 'message' => 'Adivaha activity cancel failed: ' . adivahaXExtractError($res), 'http' => $res['http_code']]);
        return;
    }

    $merged = array_merge($booking_data, ['adivaha_cancel' => [
        'reference'    => $reference,
        'status'       => $data['status'] ?? 'cancelled',
        'refund_mode'  => $data['refund_mode'] ?? 'manual',
        'refund_note'  => $data['refund_note'] ?? 'Any refund is handled manually after the cancellation is processed.',
        'cancelled_at' => date('c'),
    ]]);
    $db->update('bookings', [
        'booking_status'        => 'cancelled',
        'cancellation_status'   => 1,
        'cancellation_response' => json_encode($merged['adivaha_cancel']),
        'booking_data'          => json_encode($merged),
    ], ['invoice_id' => $invoice_id]);

    echo json_encode([
        'status'      => true,
        'message'     => 'Activity booking cancelled with Adivaha. ' . ($data['refund_note'] ?? 'Any refund is processed manually.'),
        'reference'   => $reference,
        'refund_mode' => $data['refund_mode'] ?? 'manual',
    ]);
});

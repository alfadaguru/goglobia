<?php
// path: modules/tours/adivaha/actions/void.php
// Adivaha B2C Excursions — VOID (POST tours/adivaha/void).
// If no supplier reference was ever stored (never booked upstream) → DB-level void
// only; otherwise → real supplier cancel (same call cancel.php makes).
// Mirrors modules/stays/adivaha/actions/void.php.

global $router;

$router->post('tours/adivaha/void', function () use ($db) {

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
        $db->update('bookings', [
            'booking_status'        => 'cancelled',
            'cancellation_status'   => 1,
            'cancellation_response' => 'Voided at DB level (no Adivaha booking reference present) on ' . date('Y-m-d H:i:s'),
        ], ['invoice_id' => $invoice_id]);
        echo json_encode(['status' => true, 'message' => 'Booking voided (no Adivaha activity booking was confirmed).', 'invoice_id' => $invoice_id]);
        return;
    }

    $credentials = getAdivahaExcursionCredentials($db);
    if (!$credentials || $credentials['api_key'] === '') { echo json_encode(['status' => false, 'message' => 'Excursions supplier not configured.']); return; }
    $base   = $credentials['base_url'];
    $bearer = adivahaXBearer($db, $credentials);

    $idemKey = 'gg-xvoid-' . substr(hash('sha256', (string) $invoice_id), 0, 25);
    $res = adivahaXApiRequest('POST', $base . '/excursions/cancel', ['reference' => $reference], $bearer, ['Idempotency-Key: ' . $idemKey], 90);
    if ($res['http_code'] === 401 && $credentials['partner_id'] !== '') {
        $bearer = adivahaXBearer($db, $credentials, true);
        $res = adivahaXApiRequest('POST', $base . '/excursions/cancel', ['reference' => $reference], $bearer, ['Idempotency-Key: ' . $idemKey], 90);
    }
    adivahaXLog('void', ['reference' => $reference], $res['raw'], $res['http_code'], $res['curl_error'], $invoice_id);

    $data = is_array($res['data']) ? $res['data'] : [];
    $ok = ($res['success'] && (!empty($data['success']) || strtolower((string) ($data['status'] ?? '')) === 'cancelled'))
        || ($res['http_code'] === 409 && stripos(adivahaXExtractError($res), 'cancel') !== false);

    if (!$ok) {
        echo json_encode(['status' => false, 'message' => 'Adivaha activity void/cancel failed: ' . adivahaXExtractError($res) . '. The booking was NOT changed — retry or cancel manually.', 'http' => $res['http_code']]);
        return;
    }

    $merged = array_merge($booking_data, ['adivaha_void' => [
        'reference' => $reference, 'status' => $data['status'] ?? 'cancelled', 'voided_at' => date('c'),
    ]]);
    $db->update('bookings', [
        'booking_status'        => 'cancelled',
        'cancellation_status'   => 1,
        'cancellation_response' => json_encode($merged['adivaha_void']),
        'booking_data'          => json_encode($merged),
    ], ['invoice_id' => $invoice_id]);

    echo json_encode(['status' => true, 'message' => 'Activity booking voided/cancelled with Adivaha.', 'reference' => $reference]);
});

<?php
// path: modules/tours/adivaha/manage.php
// Adivaha B2C Excursions — read-only manage endpoints (ops/admin):
//   • GET  /tours/adivaha/booking-details?reference=...   (admin-guarded read)
//   • POST /tours/adivaha/cancellation-quote               (no mutation)
// These proxy Adivaha /excursions/booking-details and /excursions/cancellation-quote.
// Mirrors modules/stays/adivaha/manage.php exactly, adapted to the excursions API.

global $router;

if (!function_exists('adivahaX_manage_ready')) {
    function adivahaX_manage_ready($db)
    {
        $credentials = getAdivahaExcursionCredentials($db);
        if (!$credentials || $credentials['api_key'] === '') {
            echo json_encode(['status' => false, 'message' => 'Adivaha excursions API credentials not configured']);
            return null;
        }
        return [$credentials, adivahaXBearer($db, $credentials), $credentials['base_url']];
    }
}

// GET booking-details — admin read (guard explicitly: the central guard only fires
// on POST, and verifyApiKey is a no-op when no server key is set, so an unguarded
// GET could leak a booking to an anonymous caller).
$router->get('/tours/adivaha/booking-details', function () use ($db) {
    header('Content-Type: application/json');
    try {
        if (function_exists('supplier_action_guard') && !supplier_action_guard('')) { return; }
        $ready = adivahaX_manage_ready($db);
        if ($ready === null) return;
        [$credentials, $bearer, $base] = $ready;

        $reference = trim((string) ($_GET['reference'] ?? ''));
        if ($reference === '') {
            echo json_encode(['status' => false, 'message' => 'reference is required (BK... or ORD-...).']);
            return;
        }
        $res = adivahaXApiRequest('GET', $base . '/excursions/booking-details?reference=' . urlencode($reference), null, $bearer, [], 30);
        if ($res['http_code'] === 401 && $credentials['partner_id'] !== '') {
            $bearer = adivahaXBearer($db, $credentials, true);
            $res = adivahaXApiRequest('GET', $base . '/excursions/booking-details?reference=' . urlencode($reference), null, $bearer, [], 30);
        }
        adivahaXLog('booking-details', ['reference' => $reference], $res['raw'], $res['http_code'], $res['curl_error']);
        if (!$res['success'] || !is_array($res['data'])) {
            echo json_encode(['status' => false, 'message' => 'Could not load booking: ' . adivahaXExtractError($res)]);
            return;
        }
        echo json_encode(['status' => true, 'data' => $res['data']['data'] ?? $res['data']]);
    } catch (Throwable $e) {
        error_log('ADIVAHA EXCURSION BOOKING-DETAILS ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred loading the booking.']);
    }
});

// POST cancellation-quote — no mutation; charge/refund are always null (manual, per
// owner rule — the Adivaha excursions API returns them null and refunds are handled
// manually by ops).
$router->post('/tours/adivaha/cancellation-quote', function () use ($db) {
    header('Content-Type: application/json');
    try {
        if (function_exists('supplier_action_guard')) {
            $inv = (string) ($_POST['invoice_id'] ?? '');
            if (!supplier_action_guard($inv)) { return; }
        }
        $ready = adivahaX_manage_ready($db);
        if ($ready === null) return;
        [$credentials, $bearer, $base] = $ready;

        // Accept a direct reference, or resolve it from the booking by invoice_id.
        $reference = trim((string) ($_POST['reference'] ?? ''));
        if ($reference === '' && !empty($_POST['invoice_id'])) {
            $b = $db->get('bookings', ['pnr', 'booking_data'], ['invoice_id' => (string) $_POST['invoice_id']]);
            if ($b) {
                $bd = json_decode((string) ($b['booking_data'] ?? ''), true);
                $reference = (string) ($bd['supplier_ref'] ?? ($bd['adivaha_response']['reference'] ?? ($b['pnr'] ?? '')));
            }
        }
        if ($reference === '') {
            echo json_encode(['status' => false, 'message' => 'reference (or a bookable invoice_id) is required.']);
            return;
        }
        $res = adivahaXApiRequest('POST', $base . '/excursions/cancellation-quote', ['reference' => $reference], $bearer, [], 45);
        if ($res['http_code'] === 401 && $credentials['partner_id'] !== '') {
            $bearer = adivahaXBearer($db, $credentials, true);
            $res = adivahaXApiRequest('POST', $base . '/excursions/cancellation-quote', ['reference' => $reference], $bearer, [], 45);
        }
        adivahaXLog('cancellation-quote', ['reference' => $reference], $res['raw'], $res['http_code'], $res['curl_error']);
        if (!$res['success'] || !is_array($res['data'])) {
            echo json_encode(['status' => false, 'message' => 'Could not load cancellation quote: ' . adivahaXExtractError($res)]);
            return;
        }
        echo json_encode(['status' => true, 'data' => $res['data']]);
    } catch (Throwable $e) {
        error_log('ADIVAHA EXCURSION CANCELLATION-QUOTE ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred loading the cancellation quote.']);
    }
});

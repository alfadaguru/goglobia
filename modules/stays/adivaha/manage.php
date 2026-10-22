<?php
// path: modules/stays/adivaha/manage.php
// Adivaha B2C Hotels — read-only manage endpoints:
//   • GET  /stays/adivaha/booking-details?reference=...   (admin-guarded read)
//   • POST /stays/adivaha/cancellation-quote               (no mutation)
// These proxy Adivaha /hotels/booking-details and /hotels/cancellation-quote.

global $router;

if (!function_exists('adivahaH_manage_ready')) {
    function adivahaH_manage_ready($db)
    {
        $credentials = getAdivahaHotelCredentials($db);
        if (!$credentials || $credentials['api_key'] === '') {
            echo json_encode(['status' => false, 'message' => 'Adivaha hotels API credentials not configured']);
            return null;
        }
        return [$credentials, adivahaHBearer($db, $credentials), $credentials['base_url']];
    }
}

// GET booking-details — admin read (guard explicitly: central guard only fires on
// POST, and verifyApiKey is a no-op when no server key is set, so an unguarded GET
// could leak a booking to an anonymous caller).
$router->get('/stays/adivaha/booking-details', function () use ($db) {
    header('Content-Type: application/json');
    try {
        if (function_exists('supplier_action_guard') && !supplier_action_guard('')) { return; }
        $ready = adivahaH_manage_ready($db);
        if ($ready === null) return;
        [$credentials, $bearer, $base] = $ready;

        $reference = trim((string) ($_GET['reference'] ?? ''));
        if ($reference === '') {
            echo json_encode(['status' => false, 'message' => 'reference is required (BK... or ORD-...).']);
            return;
        }
        $res = adivahaHApiRequest('GET', $base . '/hotels/booking-details?reference=' . urlencode($reference), null, $bearer, [], 30);
        adivahaHLog('booking-details', ['reference' => $reference], $res['raw'], $res['http_code'], $res['curl_error']);
        if (!$res['success'] || !is_array($res['data'])) {
            echo json_encode(['status' => false, 'message' => 'Could not load booking: ' . adivahaHExtractError($res)]);
            return;
        }
        echo json_encode(['status' => true, 'data' => $res['data']['data'] ?? $res['data']]);
    } catch (Throwable $e) {
        error_log('ADIVAHA HOTEL BOOKING-DETAILS ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred loading the booking.']);
    }
});

// POST cancellation-quote — no mutation; charge/refund are always null (manual).
$router->post('/stays/adivaha/cancellation-quote', function () use ($db) {
    header('Content-Type: application/json');
    try {
        if (function_exists('supplier_action_guard')) {
            $inv = (string) ($_POST['invoice_id'] ?? '');
            if (!supplier_action_guard($inv)) { return; }
        }
        $ready = adivahaH_manage_ready($db);
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
        $res = adivahaHApiRequest('POST', $base . '/hotels/cancellation-quote', ['reference' => $reference], $bearer, [], 45);
        adivahaHLog('cancellation-quote', ['reference' => $reference], $res['raw'], $res['http_code'], $res['curl_error']);
        if (!$res['success'] || !is_array($res['data'])) {
            echo json_encode(['status' => false, 'message' => 'Could not load cancellation quote: ' . adivahaHExtractError($res)]);
            return;
        }
        echo json_encode(['status' => true, 'data' => $res['data']]);
    } catch (Throwable $e) {
        error_log('ADIVAHA HOTEL CANCELLATION-QUOTE ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred loading the cancellation quote.']);
    }
});

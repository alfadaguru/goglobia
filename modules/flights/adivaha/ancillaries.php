<?php
// path: modules/flights/adivaha/ancillaries.php
// Adivaha B2C Flights — read-only / supporting endpoints (no money moves here):
//   • POST /flights/adivaha/ssr          → seat / meal / baggage catalogue
//   • POST /flights/adivaha/ancillaries  → alias of /ssr (platform naming)
//   • POST /flights/adivaha/fare-rule    → fare rule text for an offer
//   • GET  /flights/adivaha/calendar     → per-day low-fare calendar
//   • GET  /flights/adivaha/series-locations → series (allotments) route map
//   • GET  /flights/adivaha/series-calendar  → series month calendar
//   • GET  /flights/adivaha/booking-details  → retrieve a booking by reference
//
// Each resolves the module credentials/bearer via creds.php helpers and proxies
// the matching Adivaha endpoint, returning a { status, data, ... } envelope.

global $router;

if (!function_exists('adivaha_extras_ready')) {
    /**
     * Resolve [credentials, bearer, base] or emit a JSON error and return null.
     */
    function adivaha_extras_ready($db)
    {
        $credentials = getAdivahaCredentials($db);
        if (!$credentials || $credentials['api_key'] === '') {
            echo json_encode(['status' => false, 'message' => 'Adivaha API credentials not configured']);
            return null;
        }
        return [$credentials, adivahaBearer($db, $credentials), $credentials['base_url']];
    }
}

// ---------------------------------------------------------------------------
// SSR catalogue (seat / meal / baggage) — POST /flights/ssr (spec L291-322)
// Needs trace_id + offer_id (TraceOfferRef). We accept both discrete fields and
// a booking_data JSON blob (same as revalidate).
// ---------------------------------------------------------------------------
$adivahaSsrHandler = function () use ($db) {
    header('Content-Type: application/json');
    try {
        $ready = adivaha_extras_ready($db);
        if ($ready === null) return;
        [$credentials, $bearer, $base] = $ready;

        $offerId = trim((string) ($_POST['offer_id'] ?? $_POST['booking_token'] ?? $_POST['FareSourceCode'] ?? ''));
        $traceId = trim((string) ($_POST['trace_id'] ?? ''));
        if (($offerId === '' || $traceId === '') && !empty($_POST['booking_data'])) {
            $bd = json_decode((string) $_POST['booking_data'], true);
            if (is_array($bd)) {
                if ($offerId === '') $offerId = (string) ($bd['offer_id'] ?? $bd['booking_token'] ?? '');
                if ($traceId === '') $traceId = (string) ($bd['trace_id'] ?? '');
            }
        }
        if ($offerId === '' || $traceId === '') {
            echo json_encode(['status' => false, 'message' => 'trace_id and offer_id are required for SSR.', 'data' => null]);
            return;
        }

        $res = adivahaApiRequest('POST', $base . '/flights/ssr', ['trace_id' => $traceId, 'offer_id' => $offerId], $bearer, [], 45);
        adivahaLog('ssr', ['trace_id' => $traceId, 'offer_id' => $offerId], $res['raw'], $res['http_code'], $res['curl_error']);

        if (!$res['success'] || !is_array($res['data'])) {
            echo json_encode(['status' => false, 'message' => 'Could not load SSR options: ' . adivahaExtractError($res), 'data' => null]);
            return;
        }

        echo json_encode([
            'status'       => !empty($res['data']['ok']) || !empty($res['data']['ssr']),
            'data'         => $res['data']['ssr'] ?? $res['data'],
            'pricing_note' => $res['data']['pricing_note'] ?? '',
            'error'        => $res['data']['error'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('ADIVAHA SSR ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred loading SSR.', 'data' => null]);
    }
};
$router->post('/flights/adivaha/ssr', $adivahaSsrHandler);
$router->post('/flights/adivaha/ancillaries', $adivahaSsrHandler); // platform-naming alias

// ---------------------------------------------------------------------------
// Fare rules — POST /flights/fare-rule (spec L241-265)
// ---------------------------------------------------------------------------
$router->post('/flights/adivaha/fare-rule', function () use ($db) {
    header('Content-Type: application/json');
    try {
        $ready = adivaha_extras_ready($db);
        if ($ready === null) return;
        [$credentials, $bearer, $base] = $ready;

        $offerId = trim((string) ($_POST['offer_id'] ?? $_POST['booking_token'] ?? $_POST['FareSourceCode'] ?? ''));
        $traceId = trim((string) ($_POST['trace_id'] ?? ''));
        if (($offerId === '' || $traceId === '') && !empty($_POST['booking_data'])) {
            $bd = json_decode((string) $_POST['booking_data'], true);
            if (is_array($bd)) {
                if ($offerId === '') $offerId = (string) ($bd['offer_id'] ?? $bd['booking_token'] ?? '');
                if ($traceId === '') $traceId = (string) ($bd['trace_id'] ?? '');
            }
        }
        if ($offerId === '' || $traceId === '') {
            echo json_encode(['status' => false, 'message' => 'trace_id and offer_id are required for fare rules.', 'fare_rule' => null]);
            return;
        }

        $res = adivahaApiRequest('POST', $base . '/flights/fare-rule', ['trace_id' => $traceId, 'offer_id' => $offerId], $bearer, [], 45);
        adivahaLog('fare-rule', ['trace_id' => $traceId, 'offer_id' => $offerId], $res['raw'], $res['http_code'], $res['curl_error']);

        if (!$res['success'] || !is_array($res['data'])) {
            echo json_encode(['status' => false, 'message' => 'Could not load fare rules: ' . adivahaExtractError($res), 'fare_rule' => null]);
            return;
        }
        echo json_encode([
            'status'    => !empty($res['data']['ok']) || !empty($res['data']['fare_rule']),
            'fare_rule' => $res['data']['fare_rule'] ?? null,
            'error'     => $res['data']['error'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('ADIVAHA FARE-RULE ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred loading fare rules.', 'fare_rule' => null]);
    }
});

// ---------------------------------------------------------------------------
// Low-fare calendar — GET /flights/calendar (spec L324-346)
// ---------------------------------------------------------------------------
$router->get('/flights/adivaha/calendar', function () use ($db) {
    header('Content-Type: application/json');
    try {
        $ready = adivaha_extras_ready($db);
        if ($ready === null) return;
        [$credentials, $bearer, $base] = $ready;

        $origin      = strtoupper(trim((string) ($_GET['origin'] ?? '')));
        $destination = strtoupper(trim((string) ($_GET['destination'] ?? '')));
        $month       = trim((string) ($_GET['month'] ?? ''));
        $cabin       = trim((string) ($_GET['cabin'] ?? 'economy'));
        if ($origin === '' || $destination === '' || $month === '') {
            echo json_encode(['status' => false, 'message' => 'origin, destination and month are required.']);
            return;
        }
        $q = http_build_query(['origin' => $origin, 'destination' => $destination, 'month' => $month, 'cabin' => $cabin]);
        $res = adivahaApiRequest('GET', $base . '/flights/calendar?' . $q, null, $bearer, [], 30);
        adivahaLog('calendar', $q, $res['raw'], $res['http_code'], $res['curl_error']);

        if (!$res['success'] || !is_array($res['data'])) {
            echo json_encode(['status' => false, 'message' => 'Could not load calendar: ' . adivahaExtractError($res)]);
            return;
        }
        echo json_encode(['status' => true, 'data' => $res['data']]);
    } catch (Throwable $e) {
        error_log('ADIVAHA CALENDAR ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred loading the calendar.']);
    }
});

// ---------------------------------------------------------------------------
// Series (allotments) route map — GET /flights/series-locations (spec L348-384)
// ---------------------------------------------------------------------------
$router->get('/flights/adivaha/series-locations', function () use ($db) {
    header('Content-Type: application/json');
    try {
        $ready = adivaha_extras_ready($db);
        if ($ready === null) return;
        [$credentials, $bearer, $base] = $ready;

        $role   = trim((string) ($_GET['role'] ?? 'origin'));
        $origin = strtoupper(trim((string) ($_GET['origin'] ?? '')));
        $params = ['role' => $role];
        if ($origin !== '') $params['origin'] = $origin;
        $res = adivahaApiRequest('GET', $base . '/flights/series-locations?' . http_build_query($params), null, $bearer, [], 30);
        adivahaLog('series-locations', $params, $res['raw'], $res['http_code'], $res['curl_error']);

        if (!$res['success'] || !is_array($res['data'])) {
            echo json_encode(['status' => false, 'message' => 'Could not load series locations: ' . adivahaExtractError($res)]);
            return;
        }
        echo json_encode(['status' => true, 'data' => $res['data']]);
    } catch (Throwable $e) {
        error_log('ADIVAHA SERIES-LOCATIONS ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred loading series locations.']);
    }
});

// ---------------------------------------------------------------------------
// Series (allotments) month calendar — GET /flights/series-calendar (spec L386-432)
// ---------------------------------------------------------------------------
$router->get('/flights/adivaha/series-calendar', function () use ($db) {
    header('Content-Type: application/json');
    try {
        $ready = adivaha_extras_ready($db);
        if ($ready === null) return;
        [$credentials, $bearer, $base] = $ready;

        $origin      = strtoupper(trim((string) ($_GET['origin'] ?? '')));
        $destination = strtoupper(trim((string) ($_GET['destination'] ?? '')));
        $month       = trim((string) ($_GET['month'] ?? ''));
        if ($origin === '' || $destination === '' || $month === '') {
            echo json_encode(['status' => false, 'message' => 'origin, destination and month are required.']);
            return;
        }
        $q = http_build_query(['origin' => $origin, 'destination' => $destination, 'month' => $month]);
        $res = adivahaApiRequest('GET', $base . '/flights/series-calendar?' . $q, null, $bearer, [], 30);
        adivahaLog('series-calendar', $q, $res['raw'], $res['http_code'], $res['curl_error']);

        if (!$res['success'] || !is_array($res['data'])) {
            echo json_encode(['status' => false, 'message' => 'Could not load series calendar: ' . adivahaExtractError($res)]);
            return;
        }
        echo json_encode(['status' => true, 'data' => $res['data']]);
    } catch (Throwable $e) {
        error_log('ADIVAHA SERIES-CALENDAR ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred loading the series calendar.']);
    }
});

// ---------------------------------------------------------------------------
// Booking details — GET /flights/booking-details?reference=... (spec L509-571)
// Admin-side retrieval of a persisted Adivaha booking. Guarded (read action).
// ---------------------------------------------------------------------------
$router->get('/flights/adivaha/booking-details', function () use ($db) {
    header('Content-Type: application/json');
    try {
        // This is a READ, but the central guard in modules/index.php only fires on
        // POST/PUT/DELETE and verifyApiKey is a no-op when no server API key is set.
        // So enforce authorization explicitly (admin session / CSRF / loopback) to
        // avoid leaking a booking to an anonymous caller.
        if (function_exists('supplier_action_guard') && !supplier_action_guard('')) { return; }

        $ready = adivaha_extras_ready($db);
        if ($ready === null) return;
        [$credentials, $bearer, $base] = $ready;

        $reference = trim((string) ($_GET['reference'] ?? ''));
        if ($reference === '') {
            echo json_encode(['status' => false, 'message' => 'reference is required (BK... booking id or ORD-... order id).']);
            return;
        }
        $res = adivahaApiRequest('GET', $base . '/flights/booking-details?reference=' . urlencode($reference), null, $bearer, [], 30);
        adivahaLog('booking-details', ['reference' => $reference], $res['raw'], $res['http_code'], $res['curl_error']);

        if (!$res['success'] || !is_array($res['data'])) {
            echo json_encode(['status' => false, 'message' => 'Could not load booking: ' . adivahaExtractError($res)]);
            return;
        }
        echo json_encode(['status' => true, 'data' => $res['data']['data'] ?? $res['data']]);
    } catch (Throwable $e) {
        error_log('ADIVAHA BOOKING-DETAILS ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred loading the booking.']);
    }
});

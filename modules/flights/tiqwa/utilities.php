<?php
// path: modules/flights/tiqwa/utilities.php
// Tiqwa UTILITY + completion routes (consume ALL endpoints — owner decision D).
// These are NOT in the customer price path; they are admin/reference surfaces +
// the async-ticket completion poll.
//
//   GET  flights/tiqwa/airports?keyword=   → Tiqwa GET /v1/airports      (reference)
//   GET  flights/tiqwa/airlines            → Tiqwa GET /v1/airlines      (reference)
//   GET  flights/tiqwa/wallet              → Tiqwa GET /v1/wallet        (API + Ticket balances)
//   GET  flights/tiqwa/markup              → Tiqwa GET /v1/markup        (admin console)
//   POST flights/tiqwa/markup              → Tiqwa POST /v1/markup       (admin console)
//   POST flights/tiqwa/markup/delete       → Tiqwa DELETE /v1/markup/{ref}(admin console)
//   GET  flights/tiqwa/office-id           → Tiqwa GET /v1/office_id     (admin config)
//   POST flights/tiqwa/booking-details     → Tiqwa GET /v1/flight/{ref}  (poll; fill PNR)
//
// IMPORTANT (audit BLOCKER 3): Tiqwa's own markup engine is managed here for
// VISIBILITY/CONTROL only — our platform MARKUP() is the single source of truth
// for customer pricing. The operator should keep Tiqwa-side markups ZEROED so the
// price is not marked up twice; these routes let them see and clear them.
//
// The mutating utility routes (markup create/delete, office_id) are admin-grade
// account config. The central /modules/* guard does NOT gate GET reads; for the
// POST mutations we require an authenticated admin session so a stray caller
// cannot change the Tiqwa account config.

global $router;

if (!function_exists('tiqwa_require_admin_json')) {
    /** Gate a mutating utility route to an authenticated admin; 403 JSON otherwise. */
    function tiqwa_require_admin_json(): bool
    {
        $isAdmin = (isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'admin')
            || !empty($_SESSION['admin_logged_in']);
        if (!$isAdmin) {
            http_response_code(403);
            echo json_encode(['status' => false, 'message' => 'Admin authentication required.']);
            return false;
        }
        return true;
    }
}

// ---- GET airports (reference search) --------------------------------------
$router->get('flights/tiqwa/airports', function () use ($db) {
    header('Content-Type: application/json');
    $c = getTiqwaCredentials($db);
    if (!$c || $c['api_token'] === '') { echo json_encode(['status' => false, 'message' => 'Tiqwa not configured']); return; }
    $kw = trim((string) ($_GET['keyword'] ?? ''));
    $url = $c['util_base'] . '/airports' . ($kw !== '' ? ('?' . http_build_query(['keyword' => $kw])) : '');
    $res = tiqwaApiRequest('GET', $url, null, $c['api_token'], [], 30);
    echo json_encode(['status' => $res['success'], 'data' => $res['data'], 'message' => $res['success'] ? '' : tiqwaExtractError($res)]);
});

// ---- GET airlines (reference) ---------------------------------------------
$router->get('flights/tiqwa/airlines', function () use ($db) {
    header('Content-Type: application/json');
    $c = getTiqwaCredentials($db);
    if (!$c || $c['api_token'] === '') { echo json_encode(['status' => false, 'message' => 'Tiqwa not configured']); return; }
    $res = tiqwaApiRequest('GET', $c['util_base'] . '/airlines', null, $c['api_token'], [], 30);
    echo json_encode(['status' => $res['success'], 'data' => $res['data'], 'message' => $res['success'] ? '' : tiqwaExtractError($res)]);
});

// ---- GET wallet (API + Ticket balances) -----------------------------------
$router->get('flights/tiqwa/wallet', function () use ($db) {
    header('Content-Type: application/json');
    $c = getTiqwaCredentials($db);
    if (!$c || $c['api_token'] === '') { echo json_encode(['status' => false, 'message' => 'Tiqwa not configured']); return; }
    $res = tiqwaApiRequest('GET', $c['util_base'] . '/wallet', null, $c['api_token'], [], 30);
    $w = is_array($res['data']) ? ($res['data']['data'] ?? $res['data']) : [];
    echo json_encode([
        'status' => $res['success'],
        'data'   => $w,
        'api_balance'    => $w['api_balance']    ?? $w['api']    ?? null,
        'ticket_balance' => $w['ticket_balance'] ?? $w['ticket'] ?? null,
        'message' => $res['success'] ? '' : tiqwaExtractError($res),
    ]);
});

// ---- GET markups (admin console) ------------------------------------------
$router->get('flights/tiqwa/markup', function () use ($db) {
    header('Content-Type: application/json');
    $c = getTiqwaCredentials($db);
    if (!$c || $c['api_token'] === '') { echo json_encode(['status' => false, 'message' => 'Tiqwa not configured']); return; }
    $res = tiqwaApiRequest('GET', $c['util_base'] . '/markup', null, $c['api_token'], [], 30);
    echo json_encode([
        'status' => $res['success'], 'data' => $res['data'],
        'note' => 'Platform MARKUP() is the single source of truth; keep Tiqwa-side markups zeroed to avoid double-markup.',
        'message' => $res['success'] ? '' : tiqwaExtractError($res),
    ]);
});

// ---- POST create markup (admin) -------------------------------------------
$router->post('flights/tiqwa/markup', function () use ($db) {
    header('Content-Type: application/json');
    if (!tiqwa_require_admin_json()) { return; }
    $c = getTiqwaCredentials($db);
    if (!$c || $c['api_token'] === '') { echo json_encode(['status' => false, 'message' => 'Tiqwa not configured']); return; }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || empty($input)) { $input = $_POST; }
    $res = tiqwaApiRequest('POST', $c['util_base'] . '/markup', $input, $c['api_token'], [], 30);
    echo json_encode(['status' => $res['success'], 'data' => $res['data'], 'message' => $res['success'] ? 'Markup saved.' : tiqwaExtractError($res)]);
});

// ---- POST delete markup (admin) — DELETE /markup/{reference} ----------------
$router->post('flights/tiqwa/markup/delete', function () use ($db) {
    header('Content-Type: application/json');
    if (!tiqwa_require_admin_json()) { return; }
    $c = getTiqwaCredentials($db);
    if (!$c || $c['api_token'] === '') { echo json_encode(['status' => false, 'message' => 'Tiqwa not configured']); return; }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) { $input = $_POST; }
    $ref = trim((string) ($input['reference'] ?? $_POST['reference'] ?? ''));
    if ($ref === '') { echo json_encode(['status' => false, 'message' => 'markup reference required']); return; }
    $res = tiqwaApiRequest('DELETE', $c['util_base'] . '/markup/' . rawurlencode($ref), null, $c['api_token'], [], 30);
    echo json_encode(['status' => $res['success'], 'message' => $res['success'] ? 'Markup deleted.' : tiqwaExtractError($res)]);
});

// ---- GET office IDs (admin config) ----------------------------------------
$router->get('flights/tiqwa/office-id', function () use ($db) {
    header('Content-Type: application/json');
    $c = getTiqwaCredentials($db);
    if (!$c || $c['api_token'] === '') { echo json_encode(['status' => false, 'message' => 'Tiqwa not configured']); return; }
    $res = tiqwaApiRequest('GET', $c['util_base'] . '/office_id', null, $c['api_token'], [], 30);
    echo json_encode(['status' => $res['success'], 'data' => $res['data'], 'message' => $res['success'] ? '' : tiqwaExtractError($res)]);
});

// ---- POST booking-details poll (fill PNR for a paid-but-async booking) ------
// Shared completion logic: fetch GET /v1/flight/{booking_reference}; when the
// supplier status is 'issued', write the real pnr + ticket numbers and flip the
// booking to 'confirmed'. Idempotent. Used BOTH by this guarded route AND by the
// public webhook receiver (app/routes/flights/tiqwaWebhookRoutes.php) after it
// verifies the event — so the webhook NEVER trusts its payload; this authenticated
// read-back is the authority.
if (!function_exists('tiqwa_complete_from_booking_details')) {
    /**
     * @return array {status, pnr, booking_status, message}
     */
    function tiqwa_complete_from_booking_details($db, string $reference, ?string $invoiceId = null): array
    {
        $c = getTiqwaCredentials($db);
        if (!$c || $c['api_token'] === '') return ['status' => false, 'message' => 'Tiqwa not configured'];
        if ($reference === '') return ['status' => false, 'message' => 'reference required'];

        $res = tiqwaApiRequest('GET', $c['flight_base'] . '/' . rawurlencode($reference), null, $c['api_token'], [], 30);
        tiqwaLog('booking-details', ['reference' => $reference], $res['raw'], $res['http_code'], $res['curl_error'], $invoiceId);
        if (!$res['success'] || !is_array($res['data'])) {
            return ['status' => false, 'message' => 'Booking details unavailable: ' . tiqwaExtractError($res)];
        }
        $d = isset($res['data']['status']) || isset($res['data']['pnr']) ? $res['data'] : ($res['data']['data'] ?? $res['data']);
        $supplierStatus = strtolower((string) ($d['status'] ?? ''));
        $pnr = trim((string) ($d['pnr'] ?? ''));

        // Collect ticket numbers from tickets[].ticket_number (array or string).
        $ticketNumbers = [];
        foreach ((array) ($d['tickets'] ?? []) as $t) {
            if (!is_array($t)) continue;
            foreach ((array) ($t['ticket_number'] ?? []) as $tn) {
                $tn = trim((string) $tn);
                if ($tn !== '') $ticketNumbers[] = $tn;
            }
        }

        // Locate the booking by reference if invoice not supplied.
        $booking = null;
        if ($invoiceId) $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) {
            // Find by stored supplier_ref inside booking_data (LIKE on the reference).
            $booking = $db->get('bookings', '*', ['booking_data[~]' => $reference, 'module' => 'tiqwa']);
        }
        if (!$booking) return ['status' => false, 'message' => 'No local booking matches reference ' . $reference];

        // Idempotent: already has a PNR → nothing to do.
        if (trim((string) ($booking['pnr'] ?? '')) !== '') {
            return ['status' => true, 'pnr' => $booking['pnr'], 'booking_status' => $booking['booking_status'], 'message' => 'Already completed.'];
        }

        $bd = json_decode((string) $booking['booking_data'], true);
        if (!is_array($bd)) $bd = [];
        $bd['tiqwa_tickets'] = $ticketNumbers;
        $bd['tiqwa_details_status'] = $supplierStatus;

        if ($supplierStatus === 'issued' && ($pnr !== '' || !empty($ticketNumbers))) {
            $write = [
                'booking_status' => 'confirmed',
                'booking_data'   => json_encode($bd),
                'error_response' => null,
                'booking_payment_issue' => null,
            ];
            if ($pnr !== '') { $write['pnr'] = $pnr; }
            elseif (!empty($ticketNumbers)) { $write['pnr'] = $ticketNumbers[0]; }
            $db->update('bookings', $write, ['invoice_id' => $booking['invoice_id']]);

            // Notify the customer now that the ticket exists (idempotent).
            if (function_exists('bm_notify_paid')) {
                try { bm_notify_paid($db, (string) $booking['invoice_id']); }
                catch (\Throwable $e) { error_log('tiqwa complete notify: ' . $e->getMessage()); }
            }
            return ['status' => true, 'pnr' => $write['pnr'] ?? '', 'booking_status' => 'confirmed', 'message' => 'Ticket completed.'];
        }

        if ($supplierStatus === 'cancelled') {
            $db->update('bookings', ['booking_status' => 'cancelled', 'booking_data' => json_encode($bd)], ['invoice_id' => $booking['invoice_id']]);
            return ['status' => true, 'booking_status' => 'cancelled', 'message' => 'Booking cancelled at Tiqwa.'];
        }

        // Still reserved/processing — persist the status note, stay pending.
        $db->update('bookings', ['booking_data' => json_encode($bd)], ['invoice_id' => $booking['invoice_id']]);
        return ['status' => true, 'booking_status' => 'pending', 'message' => 'Still awaiting ticketing (status: ' . ($supplierStatus ?: 'unknown') . ').'];
    }
}

$router->post('flights/tiqwa/booking-details', function () use ($db) {
    header('Content-Type: application/json');
    $invoice = trim((string) ($_POST['invoice_id'] ?? ''));
    $reference = trim((string) ($_POST['reference'] ?? ''));
    if ($reference === '' && $invoice !== '') {
        $b = $db->get('bookings', ['booking_data'], ['invoice_id' => $invoice]);
        $bd = $b ? json_decode((string) $b['booking_data'], true) : [];
        if (is_array($bd)) $reference = trim((string) ($bd['supplier_ref'] ?? $bd['tiqwa_reference'] ?? ''));
    }
    if ($reference === '') { echo json_encode(['status' => false, 'message' => 'reference or invoice_id required']); return; }
    echo json_encode(tiqwa_complete_from_booking_details($db, $reference, $invoice !== '' ? $invoice : null));
});

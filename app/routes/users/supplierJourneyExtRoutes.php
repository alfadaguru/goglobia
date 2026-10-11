<?php
// FILE: app/routes/users/supplierJourneyExtRoutes.php
// DIGITAL GUEST JOURNEY (#20) — EXTENSION routes. Two surfaces:
//
//   PUBLIC in-stay portal (no login) — identity is the HMAC-signed journey token:
//     GET  /stay-journey/{token}                 — directory + extras + requests + express checkout
//     POST /stay-journey/{token}/sign            — capture digital signature
//     POST /stay-journey/{token}/request         — raise a service/extra request
//     POST /stay-journey/{token}/express-checkout— signal express checkout (desk finalises)
//
//   OWNER back-office — SUPPLIER_OR_STAFF_AUTH + CSRF::guard + supplier_can('reservations',…):
//     GET  /supplier/journey                      — directory manager + request queue
//     POST /supplier/journey/directory            — add/update a directory item
//     POST /supplier/journey/directory/delete     — delete a directory item
//     POST /supplier/journey/request/status       — ack/done/decline a request (done→folio)
//
// Reuses: _supplier_stays_owner, supplier_owned_stay_ids, supplier_can,
// _supplier_stays_deny (defined in supplierStaysRoutes / supplierReservationsRoutes,
// loaded earlier in _routes.php). Money is folio-only (journey lib → folio_add_item).
// Guest routes NEVER trust a client price; the token is the guest's only credential.

@$SECURE or die('Access Denied!');

if (!function_exists('_journey_flash')) {
    function _journey_flash(bool $ok, string $text): void
    {
        $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $text];
    }
}

// =====================================================================================
// PUBLIC in-stay portal (token-gated; no session required).
// =====================================================================================
$router->get('/stay-journey/([A-Za-z0-9_-]+)', function ($token) use ($SECURE, $db) {
    $invoice = function_exists('journey_token_decode') ? journey_token_decode($token) : '';
    $ctx = ($invoice !== '' && function_exists('_journey_resolve_booking')) ? _journey_resolve_booking($db, $invoice) : null;

    $journeyToken = $token;          // for the form actions
    $property = null; $directory = []; $myRequests = []; $checkin = null;
    if ($ctx !== null) {
        $property = ['id' => $ctx['stay_id'], 'name' => $ctx['name'], 'currency' => $ctx['currency'], 'guest' => $ctx['guest']];
        $directory  = function_exists('journey_directory_list') ? journey_directory_list($db, (int) $ctx['stay_id'], '', true) : [];
        $myRequests = function_exists('journey_requests_for_guest') ? journey_requests_for_guest($db, $invoice) : [];
        // Surface the online-checkin block (so the portal can show signed/not-signed).
        try {
            $b = $db->get('bookings', ['booking_data'], ['invoice_id' => $invoice]);
            $bd = json_decode((string) ($b['booking_data'] ?? ''), true);
            $checkin = is_array($bd['online_checkin'] ?? null) ? $bd['online_checkin'] : null;
        } catch (\Throwable $e) {}
    }
    $title = 'Your stay'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/journey-ext.php";
    require_once views . "includes/footer.php";
});

$router->post('/stay-journey/([A-Za-z0-9_-]+)/sign', function ($token) use ($SECURE, $db) {
    CSRF::guard(); // the portal page mints a CSRF token like every other public form
    $invoice = function_exists('journey_token_decode') ? journey_token_decode($token) : '';
    $back = root . 'stay-journey/' . rawurlencode($token);
    if ($invoice === '' || !function_exists('journey_capture_signature')) {
        _journey_flash(false, 'Invalid link.'); header('Location: ' . $back); exit;
    }
    $res = journey_capture_signature($db, $invoice, (string) ($_POST['signature'] ?? ''), (string) ($_POST['signer_name'] ?? ''));
    _journey_flash(!empty($res['ok']), $res['message'] ?? 'Done.');
    header('Location: ' . $back); exit;
});

$router->post('/stay-journey/([A-Za-z0-9_-]+)/request', function ($token) use ($SECURE, $db) {
    CSRF::guard();
    $invoice = function_exists('journey_token_decode') ? journey_token_decode($token) : '';
    $back = root . 'stay-journey/' . rawurlencode($token);
    if ($invoice === '' || !function_exists('journey_request_create')) {
        _journey_flash(false, 'Invalid link.'); header('Location: ' . $back); exit;
    }
    $res = journey_request_create($db, $invoice, [
        'kind'         => (string) ($_POST['kind'] ?? 'service'),
        'directory_id' => (int) ($_POST['directory_id'] ?? 0),
        'message'      => (string) ($_POST['message'] ?? ''),
        'qty'          => (int) ($_POST['qty'] ?? 1),
    ]);
    _journey_flash(!empty($res['ok']), $res['message'] ?? 'Done.');
    header('Location: ' . $back); exit;
});

$router->post('/stay-journey/([A-Za-z0-9_-]+)/express-checkout', function ($token) use ($SECURE, $db) {
    CSRF::guard();
    $invoice = function_exists('journey_token_decode') ? journey_token_decode($token) : '';
    $back = root . 'stay-journey/' . rawurlencode($token);
    if ($invoice === '' || !function_exists('journey_express_checkout_request')) {
        _journey_flash(false, 'Invalid link.'); header('Location: ' . $back); exit;
    }
    $res = journey_express_checkout_request($db, $invoice);
    _journey_flash(!empty($res['ok']), $res['message'] ?? 'Done.');
    header('Location: ' . $back); exit;
});

// =====================================================================================
// OWNER back-office — directory manager + guest-request queue.
// =====================================================================================
$router->get('/supplier/journey', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }

    $stayIds = supplier_owned_stay_ids($db, $owner);

    // Property drill-in (?stay_id) — must be an owned property.
    $stayFilter = (int) ($_GET['stay_id'] ?? 0);
    $scopeIds = ($stayFilter > 0 && in_array($stayFilter, $stayIds, true)) ? [$stayFilter] : $stayIds;

    $status = strtolower(trim((string) ($_GET['status'] ?? '')));
    if (!in_array($status, ['new', 'ack', 'done', 'declined'], true)) { $status = ''; }

    $requests  = function_exists('journey_requests_for_property') ? journey_requests_for_property($db, $scopeIds, $status) : [];
    // Directory manager is single-property: default to the drilled-in / first owned stay.
    $dirStayId = $stayFilter > 0 ? $stayFilter : (int) ($stayIds[0] ?? 0);
    $directory = ($dirStayId > 0 && function_exists('journey_directory_list')) ? journey_directory_list($db, $dirStayId, '', false) : [];

    $propMap = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }
    $canEdit = supplier_can($db, 'reservations', 'edit');

    $title = 'Guest journey'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/journey-ext.php";
    require_once views . "includes/footer.php";
});

$router->post('/supplier/journey/directory', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/journey' . ($stayId > 0 ? ('?stay_id=' . $stayId) : '');
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _journey_flash(false, 'Not your property.'); header('Location: ' . $back); exit; }

    $res = function_exists('journey_directory_save')
        ? journey_directory_save($db, $stayId, $_POST, (string) ($_SESSION['user_id'] ?? ''))
        : ['ok' => false, 'message' => 'Unavailable.'];
    _journey_flash(!empty($res['ok']), $res['message'] ?? 'Done.');
    header('Location: ' . $back); exit;
});

$router->post('/supplier/journey/directory/delete', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/journey' . ($stayId > 0 ? ('?stay_id=' . $stayId) : '');
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _journey_flash(false, 'Not your property.'); header('Location: ' . $back); exit; }

    $ok = function_exists('journey_directory_delete') && journey_directory_delete($db, $stayId, (int) ($_POST['id'] ?? 0));
    _journey_flash($ok, $ok ? 'Item deleted.' : 'Could not delete the item.');
    header('Location: ' . $back); exit;
});

$router->post('/supplier/journey/request/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $back = root . 'supplier/journey';
    if (!supplier_can($db, 'reservations', 'edit')) { _supplier_stays_deny('Not authorised.'); }

    // The request's stay must be one the owner owns (lib does the final IDOR check).
    $ownedIds = supplier_owned_stay_ids($db, $owner);
    $res = function_exists('journey_request_set_status')
        ? journey_request_set_status($db, (int) ($_POST['request_id'] ?? 0), $ownedIds, (string) ($_POST['to'] ?? ''), (string) ($_SESSION['user_id'] ?? ''))
        : ['ok' => false, 'message' => 'Unavailable.'];
    _journey_flash(!empty($res['ok']), $res['message'] ?? 'Done.');
    header('Location: ' . $back); exit;
});

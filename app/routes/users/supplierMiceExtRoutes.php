<?php
// FILE: app/routes/users/supplierMiceExtRoutes.php
// Events / MICE — EXTENSION routes (docs 01b module 16). Quotations, catering menus,
// equipment, staffing, contracts, the BEO sheet and the event calendar. All scoped by
// supplier_can('reservations', …, $stayId); every POST = SUPPLIER_OR_STAFF_AUTH + CSRF.
// Money is only ever moved by the lib via the event folio (mice_folio_add) — no price is
// ever read from the client.

@$SECURE or die('Access Denied!');

// --- shared guard helper for the POST routes in this file ---------------------------
if (!function_exists('_mice_ext_post_ctx')) {
    /**
     * Auth + CSRF + owner + supplier_can('reservations','edit',$stayId). Returns
     * [$stayId, $back] or exits/redirects. $eventId optional (re-verified by the lib).
     */
    function _mice_ext_post_ctx($db): array
    {
        SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
        $owner = _supplier_stays_owner($db);
        if ($owner === null) { header('Location: ' . root . 'login'); exit; }
        $stayId = (int) ($_POST['stay_id'] ?? 0);
        $eventId = (int) ($_POST['event_id'] ?? 0);
        $frag = ($stayId > 0 ? '?stay_id=' . $stayId : '') . ($eventId > 0 ? (($stayId > 0 ? '&' : '?') . 'event_id=' . $eventId) : '');
        $back = root . 'supplier/events/manage' . $frag;
        if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
        return [$stayId, $back];
    }
}

/* ========================================================================
 * MANAGE SCREEN — one event's quotes / menus / equipment / staffing / contracts
 * GET /supplier/events/manage?stay_id=&event_id=
 * ====================================================================== */
$router->get('/supplier/events/manage', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }

    $stayIds = supplier_owned_stay_ids($db, $owner);
    $stayId  = (int) ($_GET['stay_id'] ?? 0);
    $eventId = (int) ($_GET['event_id'] ?? 0);
    if ($stayId <= 0 || !in_array($stayId, $stayIds, true)) { _supplier_stays_deny('Pick one of your properties.'); }

    $event = function_exists('mice_ext_event_guard') ? mice_ext_event_guard($db, $eventId, $stayId) : null;
    if ($event === null) { $_SESSION['message'] = ['type' => 'error', 'text' => 'Event not found.']; header('Location: ' . root . 'supplier/events?stay_id=' . $stayId); exit; }

    $totals      = function_exists('mice_event_totals') ? mice_event_totals($db, $eventId) : ['charges' => 0, 'payments' => 0, 'balance' => 0];
    $quotes      = function_exists('mice_quotes_for_event') ? mice_quotes_for_event($db, $eventId, $stayId) : [];
    $quoteItems  = []; $quoteTotals = [];
    foreach ($quotes as $q) { $qid = (int) $q['id'];
        $quoteItems[$qid]  = $db->select('stays_event_quote_items', ['type', 'description', 'unit_amount', 'qty', 'amount'], ['quote_id' => $qid, 'ORDER' => ['id' => 'ASC']]) ?: [];
        $quoteTotals[$qid] = function_exists('mice_quote_total') ? mice_quote_total($db, $qid) : 0.0;
    }
    $menus     = function_exists('mice_menus_for_stay') ? mice_menus_for_stay($db, $stayId) : [];
    $equipment = function_exists('mice_equipment_for_stay') ? mice_equipment_for_stay($db, $stayId) : [];
    $assignEquip = function_exists('mice_assignments_for_event') ? mice_assignments_for_event($db, $eventId, $stayId, 'equipment') : [];
    $assignStaff = function_exists('mice_assignments_for_event') ? mice_assignments_for_event($db, $eventId, $stayId, 'staff') : [];
    $contracts = function_exists('mice_contracts_for_event') ? mice_contracts_for_event($db, $eventId, $stayId) : [];
    $canEdit   = supplier_can($db, 'reservations', 'edit', $stayId);

    $title = 'Event — ' . ($event['title'] ?? 'Manage'); $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/mice-ext.php";
    require_once views . "includes/footer.php";
});

/* ========================================================================
 * BEO — printable Banquet Event Order
 * GET /supplier/events/beo?stay_id=&event_id=
 * ====================================================================== */
$router->get('/supplier/events/beo', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $stayId  = (int) ($_GET['stay_id'] ?? 0);
    $eventId = (int) ($_GET['event_id'] ?? 0);
    if ($stayId <= 0 || !in_array($stayId, $stayIds, true)) { _supplier_stays_deny('Pick one of your properties.'); }
    $beo = function_exists('mice_beo_build') ? mice_beo_build($db, $eventId, $stayId) : [];
    if (empty($beo)) { $_SESSION['message'] = ['type' => 'error', 'text' => 'Event not found.']; header('Location: ' . root . 'supplier/events?stay_id=' . $stayId); exit; }

    $title = 'BEO'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/mice-beo.php";
    require_once views . "includes/footer.php";
});

/* ========================================================================
 * CALENDAR — month grid for a property
 * GET /supplier/events/calendar?stay_id=&y=&m=
 * ====================================================================== */
$router->get('/supplier/events/calendar', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $stayId  = (int) ($_GET['stay_id'] ?? 0);
    if ($stayId <= 0 || !in_array($stayId, $stayIds, true)) {
        // default to the first owned property so the link always lands somewhere useful
        $stayId = !empty($stayIds) ? (int) $stayIds[0] : 0;
    }
    if ($stayId <= 0) { _supplier_stays_deny('No properties.'); }
    $y = (int) ($_GET['y'] ?? date('Y'));
    $m = (int) ($_GET['m'] ?? date('n'));
    $cal = function_exists('mice_calendar_month') ? mice_calendar_month($db, $stayId, $y, $m) : [];
    $propName = '';
    try { $p = $db->get('stays', ['name'], ['id' => $stayId]); if ($p) { $propName = (string) $p['name']; } } catch (\Throwable $e) {}

    $title = 'Event calendar'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/mice-calendar.php";
    require_once views . "includes/footer.php";
});

/* ========================================================================
 * QUOTATIONS
 * ====================================================================== */
$router->post('/supplier/events/quote/create', function () use ($SECURE, $db) {
    [$stayId, $back] = _mice_ext_post_ctx($db);
    $id = function_exists('mice_quote_create') ? mice_quote_create($db, (int) ($_POST['event_id'] ?? 0), $stayId,
        ['title' => $_POST['title'] ?? '', 'notes' => $_POST['notes'] ?? '', 'valid_until' => $_POST['valid_until'] ?? '']) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Quotation created.' : 'Could not create quotation.'];
    header('Location: ' . $back); exit;
});

$router->post('/supplier/events/quote/line', function () use ($SECURE, $db) {
    [$stayId, $back] = _mice_ext_post_ctx($db);
    $ok = function_exists('mice_quote_add_line') && mice_quote_add_line($db, (int) ($_POST['quote_id'] ?? 0), $stayId,
        strtolower(trim((string) ($_POST['ltype'] ?? ''))), (float) ($_POST['unit'] ?? 0),
        (int) ($_POST['qty'] ?? 1), (string) ($_POST['description'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Quote line added.' : 'Could not add line (draft quote + valid type/amount).'];
    header('Location: ' . $back); exit;
});

$router->post('/supplier/events/quote/status', function () use ($SECURE, $db) {
    [$stayId, $back] = _mice_ext_post_ctx($db);
    $ok = function_exists('mice_quote_set_status') && mice_quote_set_status($db, (int) ($_POST['quote_id'] ?? 0), $stayId, strtolower(trim((string) ($_POST['to'] ?? ''))));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Quotation updated.' : 'Could not update (invalid transition? event closed?).'];
    header('Location: ' . $back); exit;
});

/* ========================================================================
 * CATERING MENUS
 * ====================================================================== */
$router->post('/supplier/events/menu/create', function () use ($SECURE, $db) {
    [$stayId, $back] = _mice_ext_post_ctx($db);
    $id = function_exists('mice_menu_create') ? mice_menu_create($db, $stayId,
        ['name' => $_POST['name'] ?? '', 'description' => $_POST['description'] ?? '', 'price_per_pax' => $_POST['price_per_pax'] ?? 0]) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Catering package saved.' : 'Could not save package (name + per-pax price required).'];
    header('Location: ' . $back); exit;
});

$router->post('/supplier/events/menu/apply', function () use ($SECURE, $db) {
    [$stayId, $back] = _mice_ext_post_ctx($db);
    $ok = function_exists('mice_menu_apply') && mice_menu_apply($db, (int) ($_POST['event_id'] ?? 0), $stayId,
        (int) ($_POST['menu_id'] ?? 0), (int) ($_POST['pax'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Catering added to event folio.' : 'Could not apply package (event open + pax required).'];
    header('Location: ' . $back); exit;
});

/* ========================================================================
 * EQUIPMENT
 * ====================================================================== */
$router->post('/supplier/events/equipment/create', function () use ($SECURE, $db) {
    [$stayId, $back] = _mice_ext_post_ctx($db);
    $id = function_exists('mice_equipment_create') ? mice_equipment_create($db, $stayId,
        ['name' => $_POST['name'] ?? '', 'unit_price' => $_POST['unit_price'] ?? 0, 'stock_qty' => $_POST['stock_qty'] ?? 0]) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Equipment item saved.' : 'Could not save equipment (name required).'];
    header('Location: ' . $back); exit;
});

$router->post('/supplier/events/equipment/assign', function () use ($SECURE, $db) {
    [$stayId, $back] = _mice_ext_post_ctx($db);
    $ok = function_exists('mice_equipment_assign') && mice_equipment_assign($db, (int) ($_POST['event_id'] ?? 0), $stayId,
        (int) ($_POST['equipment_id'] ?? 0), (int) ($_POST['qty'] ?? 1));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Equipment assigned to event.' : 'Could not assign (event open?).'];
    header('Location: ' . $back); exit;
});

/* ========================================================================
 * STAFFING
 * ====================================================================== */
$router->post('/supplier/events/staff/assign', function () use ($SECURE, $db) {
    [$stayId, $back] = _mice_ext_post_ctx($db);
    $ok = function_exists('mice_staff_assign') && mice_staff_assign($db, (int) ($_POST['event_id'] ?? 0), $stayId, [
        'role' => $_POST['role'] ?? '', 'name' => $_POST['name'] ?? '', 'shift' => $_POST['shift'] ?? '', 'cost' => $_POST['cost'] ?? 0,
    ]);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Staff assigned.' : 'Could not assign staff (role required + event open).'];
    header('Location: ' . $back); exit;
});

/* ========================================================================
 * CONTRACTS
 * ====================================================================== */
$router->post('/supplier/events/contract/create', function () use ($SECURE, $db) {
    [$stayId, $back] = _mice_ext_post_ctx($db);
    $id = function_exists('mice_contract_create') ? mice_contract_create($db, (int) ($_POST['event_id'] ?? 0), $stayId, [
        'quote_id' => $_POST['quote_id'] ?? 0, 'terms' => $_POST['terms'] ?? '',
        'deposit_amount' => $_POST['deposit_amount'] ?? 0, 'deposit_due' => $_POST['deposit_due'] ?? '',
    ]) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Contract drafted.' : 'Could not draft contract.'];
    header('Location: ' . $back); exit;
});

$router->post('/supplier/events/contract/status', function () use ($SECURE, $db) {
    [$stayId, $back] = _mice_ext_post_ctx($db);
    $ok = function_exists('mice_contract_set_status') && mice_contract_set_status($db, (int) ($_POST['contract_id'] ?? 0), $stayId, strtolower(trim((string) ($_POST['to'] ?? ''))));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Contract updated.' : 'Could not update the contract.'];
    header('Location: ' . $back); exit;
});

$router->post('/supplier/events/contract/deposit', function () use ($SECURE, $db) {
    [$stayId, $back] = _mice_ext_post_ctx($db);
    $ok = function_exists('mice_contract_record_deposit') && mice_contract_record_deposit($db, (int) ($_POST['contract_id'] ?? 0), $stayId);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Deposit recorded on the event folio.' : 'Could not record deposit (already recorded? event closed?).'];
    header('Location: ' . $back); exit;
});

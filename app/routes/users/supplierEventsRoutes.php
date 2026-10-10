<?php
// FILE: app/routes/users/supplierEventsRoutes.php
// Events / MICE routes (Phase 1 inc S33). supplier_can('reservations', …, $stayId)-
// scoped. Reuses stays owner/deny helpers. Completion posts to the GL via the lib.

@$SECURE or die('Access Denied!');

// GET /supplier/events — spaces + events across the owner's properties
$router->get('/supplier/events', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }

    $stayIds = supplier_owned_stay_ids($db, $owner);
    $stayFilter = (int) ($_GET['stay_id'] ?? 0);
    if ($stayFilter > 0 && !in_array($stayFilter, $stayIds, true)) { $stayFilter = 0; }

    $spaces = [];
    if (!empty($stayIds)) {
        try { $spaces = $db->select('stays_event_spaces', ['id', 'stay_id', 'name', 'capacity'],
            ['stay_id' => ($stayFilter > 0 ? [$stayFilter] : $stayIds), 'status' => 1, 'ORDER' => ['name' => 'ASC']]) ?: []; } catch (\Throwable $e) {}
    }
    $events = function_exists('mice_events_for_org') ? mice_events_for_org($db, $stayFilter > 0 ? [$stayFilter] : $stayIds) : [];
    // Totals + line items for the open (non-cancelled/completed) events.
    $eventTotals = []; $eventItems = [];
    foreach ($events as $ev) {
        $eid = (int) $ev['id'];
        $eventTotals[$eid] = function_exists('mice_event_totals') ? mice_event_totals($db, $eid) : ['charges'=>0,'payments'=>0,'balance'=>0];
        if (in_array($ev['status'], ['enquiry', 'confirmed'], true)) {
            $eventItems[$eid] = $db->select('stays_event_items', ['type', 'description', 'amount'], ['event_id' => $eid, 'ORDER' => ['id' => 'ASC']]) ?: [];
        }
    }
    $propMap = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }
    $spaceNames = []; foreach ($spaces as $s) { $spaceNames[(int) $s['id']] = $s['name']; }
    $canEdit = supplier_can($db, 'reservations', 'edit');

    $title = 'Events'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/events.php";
    require_once views . "includes/footer.php";
});

// POST space create
$router->post('/supplier/events/space', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/events' . ($stayId > 0 ? '?stay_id=' . $stayId : '');
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('mice_space_create') ? mice_space_create($db, $stayId, ['name' => $_POST['name'] ?? '', 'capacity' => $_POST['capacity'] ?? 0]) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Function space added.' : 'Could not add space (name required?).'];
    header('Location: ' . $back); exit;
});

// POST event create
$router->post('/supplier/events/create', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/events' . ($stayId > 0 ? '?stay_id=' . $stayId : '');
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('mice_event_create') ? mice_event_create($db, $stayId, (int) ($_POST['space_id'] ?? 0), [
        'title' => $_POST['title'] ?? '', 'client_name' => $_POST['client_name'] ?? '', 'client_email' => $_POST['client_email'] ?? '',
        'event_date' => $_POST['event_date'] ?? '', 'pax' => $_POST['pax'] ?? 0, 'currency' => $_POST['currency'] ?? 'USD',
    ]) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Event enquiry created.' : 'Could not create event (space + valid date required).'];
    header('Location: ' . $back); exit;
});

// POST event folio line
$router->post('/supplier/events/line', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/events' . ($stayId > 0 ? '?stay_id=' . $stayId : '');
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('mice_folio_add') && mice_folio_add($db, (int) ($_POST['event_id'] ?? 0), $stayId,
        strtolower(trim((string) ($_POST['ltype'] ?? ''))), (float) ($_POST['amount'] ?? 0), (string) ($_POST['description'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Event line added.' : 'Could not add line (event open + valid type/amount).'];
    header('Location: ' . $back); exit;
});

// POST event status transition
$router->post('/supplier/events/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/events' . ($stayId > 0 ? '?stay_id=' . $stayId : '');
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('mice_event_set_status') && mice_event_set_status($db, (int) ($_POST['event_id'] ?? 0), $stayId, strtolower(trim((string) ($_POST['to'] ?? ''))));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Event updated.' : 'Could not update the event (invalid transition?).'];
    header('Location: ' . $back); exit;
});

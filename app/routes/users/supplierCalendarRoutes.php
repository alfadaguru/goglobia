<?php
// FILE: app/routes/users/supplierCalendarRoutes.php
// RESERVATION CALENDAR / TAPE-CHART (inc S39). Per-property drill-in: a ?stay_id selects
// the property (the drill-in sidebar links here with it set). Shows the real no-oversell
// grid (stays_inventory + stays_holds) and lets the operator set availability / stop-sell
// / min-stay across a date range. Scoping: supplier_can('rooms', …, $stayId).

@$SECURE or die('Access Denied!');

// GET /supplier/calendar — tape-chart for a property
$router->get('/supplier/calendar', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rooms', 'view')) { _supplier_stays_deny('Not authorised.'); }

    $stayIds = supplier_owned_stay_ids($db, $owner);
    $stayId = (int) ($_GET['stay_id'] ?? 0);
    if ($stayId <= 0 || !in_array($stayId, $stayIds, true)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Open a property to view its calendar.'];
        header('Location: ' . root . 'supplier/stays'); exit;
    }
    // Per-property authorisation (fires the stays.user_id === owner IDOR check).
    if (!supplier_can($db, 'rooms', 'view', $stayId)) { _supplier_stays_deny('That property is not yours.'); }

    $stay = $db->get('stays', ['id', 'name', 'currency'], ['id' => $stayId]);

    // Window: ?start=Y-m-d (default today) + ?nights (default 14, clamped 1..60 in the lib).
    $start = (string) ($_GET['start'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) { $start = date('Y-m-d'); }
    $nights = (int) ($_GET['nights'] ?? 14);
    if ($nights <= 0) { $nights = 14; }

    $taxonomy = function_exists('_supplier_room_taxonomy') ? _supplier_room_taxonomy($db) : ['room_type' => [], 'board' => []];
    $grid = function_exists('stays_calendar_grid')
        ? stays_calendar_grid($db, $stayId, $start, $nights, $taxonomy)
        : ['window' => stays_calendar_window($start, $nights), 'rooms' => []];

    $canEdit = supplier_can($db, 'rooms', 'edit', $stayId);

    $title = 'Calendar — ' . ($stay['name'] ?? ''); $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/calendar.php";
    require_once views . "includes/footer.php";
});

// POST /supplier/calendar/set — set availability / stop-sell / min-stay over a range
$router->post('/supplier/calendar/set', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $roomId = (int) ($_POST['room_id'] ?? 0);
    $optionId = (int) ($_POST['option_id'] ?? 0);
    $from = (string) ($_POST['date_from'] ?? '');
    $to   = (string) ($_POST['date_to'] ?? '');
    // Preserve the operator's current view on redirect back.
    $start = (string) ($_POST['start'] ?? $from);
    $nights = (int) ($_POST['nights'] ?? 14);
    $back = root . 'supplier/calendar?stay_id=' . $stayId
        . '&start=' . urlencode(preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? $start : date('Y-m-d'))
        . '&nights=' . ($nights > 0 ? $nights : 14);

    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }

    // Only the fields the operator actually submitted are applied.
    $fields = [];
    if (isset($_POST['available_count']) && $_POST['available_count'] !== '') {
        $fields['available_count'] = (int) $_POST['available_count'];
    }
    if (isset($_POST['closed'])) { $fields['closed'] = ((string) $_POST['closed'] === '1') ? 1 : 0; }
    if (array_key_exists('min_stay', $_POST)) {
        $fields['min_stay'] = ($_POST['min_stay'] === '' ? null : (int) $_POST['min_stay']);
    }

    if (empty($fields)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Nothing to change — set availability, stop-sell, or min-stay.'];
        header('Location: ' . $back); exit;
    }

    $res = function_exists('stays_inventory_set')
        ? stays_inventory_set($db, $stayId, $roomId, $optionId, $from, $to, $fields)
        : ['ok' => false, 'message' => 'Calendar engine unavailable'];

    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    header('Location: ' . $back); exit;
});

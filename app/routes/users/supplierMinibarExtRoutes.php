<?php
// FILE: app/routes/users/supplierMinibarExtRoutes.php
// MINIBAR (#26) deepening routes — room par levels, consumption→folio posting,
// replenishment, physical counts, and the shrinkage report. Owner/staff gated;
// property-scoped via supplier_can('rooms', …) (operational data, same gate the
// base minibar catalogue uses). All money flows through folio_add_item inside
// minibar_post_consumption — the client never supplies a price.

@$SECURE or die('Access Denied!');

// ---- CONTROL PANEL (par levels / movements / shrinkage) ------------------------
$router->get('/supplier/minibar/manage', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rooms', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $items     = function_exists('minibar_list') ? minibar_list($db, $stayIds) : [];
    $pars      = function_exists('minibar_par_list') ? minibar_par_list($db, $stayIds) : [];
    $movements = function_exists('minibar_movements_list') ? minibar_movements_list($db, $stayIds) : [];
    $shrinkage = function_exists('minibar_shrinkage_report') ? minibar_shrinkage_report($db, $stayIds) : [];
    $propMap = []; $propList = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; $propList[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }
    // Item name lookup for the movement/shrinkage tables.
    $itemMap = [];
    foreach ($items as $it) { $itemMap[(int) $it['id']] = (string) $it['name']; }
    $canEdit = supplier_can($db, 'rooms', 'edit');
    $title = 'Minibar operations'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/minibar-ext.php";
    require_once views . "includes/footer.php";
});

// ---- ROOM ASSIGNMENT: set a par level ------------------------------------------
$router->post('/supplier/minibar/par', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/minibar/manage';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('minibar_set_par') && minibar_set_par(
        $db, $stayId, (int) ($_POST['item_id'] ?? 0),
        (string) ($_POST['room_number'] ?? ''), (int) ($_POST['par_qty'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Par level saved.' : 'Could not save (room + item required).'];
    header('Location: ' . $back); exit;
});

// ---- CONSUMPTION: charge consumed items to a guest folio -----------------------
$router->post('/supplier/minibar/consume', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/minibar/manage';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $res = function_exists('minibar_post_consumption')
        ? minibar_post_consumption(
            $db, $stayId, (int) ($_POST['item_id'] ?? 0),
            (string) ($_POST['invoice_id'] ?? ''), (int) ($_POST['qty'] ?? 1),
            (string) ($_POST['room_number'] ?? ''), (int) ($_POST['folio_no'] ?? 1))
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => (string) ($res['message'] ?? '')];
    header('Location: ' . $back); exit;
});

// ---- REPLENISHMENT -------------------------------------------------------------
$router->post('/supplier/minibar/replenish', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/minibar/manage';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('minibar_replenish') && minibar_replenish(
        $db, $stayId, (int) ($_POST['item_id'] ?? 0),
        (string) ($_POST['room_number'] ?? ''), (int) ($_POST['qty'] ?? 1));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Replenishment logged.' : 'Could not log (room + item required).'];
    header('Location: ' . $back); exit;
});

// ---- PHYSICAL COUNT (feeds shrinkage) ------------------------------------------
$router->post('/supplier/minibar/count', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/minibar/manage';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('minibar_record_count') && minibar_record_count(
        $db, $stayId, (int) ($_POST['item_id'] ?? 0),
        (string) ($_POST['room_number'] ?? ''), (int) ($_POST['counted_qty'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Count recorded.' : 'Could not record (room + item required).'];
    header('Location: ' . $back); exit;
});

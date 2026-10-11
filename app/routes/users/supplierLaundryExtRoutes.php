<?php
// FILE: app/routes/users/supplierLaundryExtRoutes.php
// MODULE 25 (Laundry & linen) DEEPENING routes: linen catalogue + par levels, the
// issue/return/lost/damaged stock ledger, external vendor register, guest-laundry →
// folio posting (DB-GATED money spine), and the laundry cost report. Sits alongside the
// base /supplier/laundry (batch lifecycle) in supplierFacilitiesRoutes.php — NO overlap.
// Owner/staff gated; property-scoped via supplier_can('rooms', …) like the rest of the
// facilities registers (laundry is operational room data). Every POST: auth + CSRF +
// supplier_can with the named stay (IDOR stop).

@$SECURE or die('Access Denied!');

// ---- LANDING: catalogue + par + moves + vendors + cost report ------------------
$router->get('/supplier/linen', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rooms', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);

    $items   = function_exists('linen_items_list') ? linen_items_list($db, $stayIds) : [];
    $moves   = function_exists('linen_moves_list') ? linen_moves_list($db, $stayIds) : [];
    $vendors = function_exists('laundry_vendors_list') ? laundry_vendors_list($db, $stayIds) : [];
    $shortfall = function_exists('linen_par_shortfall') ? linen_par_shortfall($db, $stayIds) : [];

    // Cost report window (optional ?from=&to=).
    $from = (string) ($_GET['from'] ?? ''); $to = (string) ($_GET['to'] ?? '');
    $report = function_exists('laundry_cost_report') ? laundry_cost_report($db, $stayIds, $from, $to) : [];

    $propMap = []; $propList = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; $propList[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }
    $itemMap = []; foreach ($items as $it) { $itemMap[(int) $it['id']] = (string) $it['name']; }
    $canEdit = supplier_can($db, 'rooms', 'edit');

    $title = 'Linen & par levels'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/laundry-ext.php";
    require_once views . "includes/footer.php";
});

// ---- CATALOGUE: add / update ---------------------------------------------------
$router->post('/supplier/linen/item/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/linen';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('linen_item_add') ? linen_item_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Linen item added.' : 'Could not add (name + property required).'];
    header('Location: ' . $back); exit;
});

$router->post('/supplier/linen/item/update', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/linen';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('linen_item_update') && linen_item_update($db, (int) ($_POST['id'] ?? 0), $stayId, $_POST);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Item updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});

// ---- STOCK LEDGER: receive / issue / return / lost / damaged -------------------
$router->post('/supplier/linen/move', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/linen';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $type = strtolower(trim((string) ($_POST['move_type'] ?? '')));
    $ok = function_exists('linen_move') && linen_move($db, $stayId, (int) ($_POST['item_id'] ?? 0), $type, (int) ($_POST['qty'] ?? 0), $_POST);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Stock movement recorded.' : 'Could not record (item + type + qty required).'];
    header('Location: ' . $back); exit;
});

// ---- VENDORS: add / toggle -----------------------------------------------------
$router->post('/supplier/linen/vendor/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/linen';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('laundry_vendor_add') ? laundry_vendor_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Vendor added.' : 'Could not add (name + property required).'];
    header('Location: ' . $back); exit;
});

$router->post('/supplier/linen/vendor/toggle', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/linen';
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('laundry_vendor_toggle') && laundry_vendor_toggle($db, (int) ($_POST['id'] ?? 0), $stayId);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Vendor updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});

// ---- GUEST LAUNDRY → FOLIO (DB-GATED money spine) ------------------------------
$router->post('/supplier/laundry/charge', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/laundry';
    // Posting a charge is a money action — require 'edit' on the named property (IDOR stop).
    if (!supplier_can($db, 'rooms', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $res = function_exists('laundry_post_guest_charge')
        ? laundry_post_guest_charge($db, (int) ($_POST['id'] ?? 0), $stayId, (string) ($_POST['invoice_id'] ?? ''))
        : ['ok' => false, 'msg' => 'Engine unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => (string) ($res['msg'] ?? '')];
    header('Location: ' . $back); exit;
});

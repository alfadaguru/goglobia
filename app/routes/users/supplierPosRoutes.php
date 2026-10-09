<?php
// FILE: app/routes/users/supplierPosRoutes.php
// F&B / POS routes (Phase 1 inc S24). Owner/staff scoped via supplier_can('rooms',
// …, $stayId) — POS is part of property operations. Reuses the stays owner/deny
// helpers. All writes CSRF-guarded + ownership re-checked per property.

@$SECURE or die('Access Denied!');

if (!function_exists('_pos_outlet_owned')) {
    /** The outlet row if it belongs to one of the acting owner's properties, else null. */
    function _pos_outlet_owned($db, int $outletId, string $owner): ?array
    {
        if ($outletId <= 0) { return null; }
        try {
            $o = $db->get('stays_outlets', '*', ['id' => $outletId]);
            if (!$o) { return null; }
            if (!supplier_can($db, 'rooms', 'view', (int) $o['stay_id'])) { return null; }
            return $o;
        } catch (\Throwable $e) { error_log('_pos_outlet_owned: ' . $e->getMessage()); return null; }
    }
}

// GET /supplier/pos — outlets list (+ owner's properties to create outlets)
$router->get('/supplier/pos', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rooms', 'view')) { _supplier_stays_deny('Not authorised.'); }

    $stayIds = supplier_owned_stay_ids($db, $owner);
    $outlets = [];
    if (!empty($stayIds)) {
        try { $outlets = $db->select('stays_outlets', ['id', 'stay_id', 'name', 'type', 'active'],
            ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC']]) ?: []; } catch (\Throwable $e) {}
    }
    $propMap = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }
    $canEdit = supplier_can($db, 'rooms', 'edit');

    $title = 'Restaurants & POS'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/pos/outlets.php";
    require_once views . "includes/footer.php";
});

// POST /supplier/pos/outlet — create an outlet
$router->post('/supplier/pos/outlet', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $back = root . 'supplier/pos';
    if (!supplier_can($db, 'rooms', 'add', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $name = trim((string) ($_POST['name'] ?? ''));
    $type = (string) ($_POST['type'] ?? 'restaurant');
    if (!isset(pos_outlet_types()[$type])) { $type = 'restaurant'; }
    if ($name === '') { $_SESSION['message'] = ['type' => 'error', 'text' => 'Outlet name required.']; header('Location: ' . $back); exit; }
    try {
        $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
        $db->insert('stays_outlets', ['org_id' => $orgId > 0 ? $orgId : null, 'stay_id' => $stayId,
            'name' => substr($name, 0, 120), 'type' => $type, 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Outlet created.'];
    } catch (\Throwable $e) { error_log('pos outlet: ' . $e->getMessage()); $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not create outlet.']; }
    header('Location: ' . $back); exit;
});

// GET /supplier/pos/outlet/{id} — menu + live orders for one outlet (the POS screen)
$router->get('/supplier/pos/outlet/([0-9]+)', function ($outletId) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $outlet = _pos_outlet_owned($db, (int) $outletId, $owner);
    if (!$outlet) { _supplier_stays_deny('That outlet is not yours.'); }
    $stayId = (int) $outlet['stay_id'];

    $menu = $db->select('stays_menu_items', ['id', 'category', 'name', 'price', 'active'],
        ['outlet_id' => (int) $outlet['id'], 'ORDER' => ['category' => 'ASC', 'name' => 'ASC']]) ?: [];
    $openOrders = $db->select('pos_orders', ['id', 'table_label', 'status', 'created_at'],
        ['outlet_id' => (int) $outlet['id'], 'status' => 'open', 'ORDER' => ['id' => 'DESC']]) ?: [];
    // Each open order's lines + total.
    $orderLines = []; $orderTotals = [];
    foreach ($openOrders as $o) {
        $oid = (int) $o['id'];
        $orderLines[$oid] = $db->select('pos_order_items', ['name', 'qty', 'unit_price'], ['order_id' => $oid, 'ORDER' => ['id' => 'ASC']]) ?: [];
        $orderTotals[$oid] = function_exists('pos_order_total') ? pos_order_total($db, $oid) : 0.0;
    }
    $checkedIn = function_exists('pos_checked_in_reservations') ? pos_checked_in_reservations($db, $stayId) : [];
    $canEdit = supplier_can($db, 'rooms', 'edit', $stayId);

    $title = htmlspecialchars((string) $outlet['name']) . ' — POS'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/pos/outlet.php";
    require_once views . "includes/footer.php";
});

// POST /supplier/pos/menu-item — add a menu item to an outlet
$router->post('/supplier/pos/menu-item', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $outletId = (int) ($_POST['outlet_id'] ?? 0);
    $outlet = _pos_outlet_owned($db, $outletId, $owner);
    $back = root . 'supplier/pos/outlet/' . $outletId;
    if (!$outlet || !supplier_can($db, 'rooms', 'edit', (int) $outlet['stay_id'])) { _supplier_stays_deny('Not your outlet.'); }
    $name = trim((string) ($_POST['name'] ?? '')); $price = round((float) ($_POST['price'] ?? 0), 2);
    if ($name === '' || $price < 0) { $_SESSION['message'] = ['type' => 'error', 'text' => 'Name and a valid price are required.']; header('Location: ' . $back); exit; }
    try {
        $db->insert('stays_menu_items', ['outlet_id' => $outletId, 'category' => trim((string) ($_POST['category'] ?? '')) ?: null,
            'name' => substr($name, 0, 191), 'price' => $price, 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Menu item added.'];
    } catch (\Throwable $e) { error_log('pos menu-item: ' . $e->getMessage()); $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not add item.']; }
    header('Location: ' . $back); exit;
});

// POST /supplier/pos/order — open a new order
$router->post('/supplier/pos/order', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $outletId = (int) ($_POST['outlet_id'] ?? 0);
    $outlet = _pos_outlet_owned($db, $outletId, $owner);
    $back = root . 'supplier/pos/outlet/' . $outletId;
    if (!$outlet || !supplier_can($db, 'rooms', 'edit', (int) $outlet['stay_id'])) { _supplier_stays_deny('Not your outlet.'); }
    $id = function_exists('pos_order_create') ? pos_order_create($db, (int) $outlet['stay_id'], $outletId, ['table_label' => $_POST['table_label'] ?? '', 'currency' => $_POST['currency'] ?? 'USD']) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Order opened.' : 'Could not open order.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/pos/order/add — add an item to an open order
$router->post('/supplier/pos/order/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $outletId = (int) ($_POST['outlet_id'] ?? 0);
    $outlet = _pos_outlet_owned($db, $outletId, $owner);
    $back = root . 'supplier/pos/outlet/' . $outletId;
    if (!$outlet || !supplier_can($db, 'rooms', 'edit', (int) $outlet['stay_id'])) { _supplier_stays_deny('Not your outlet.'); }
    // Verify the order belongs to this outlet before mutating.
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $ord = $db->get('pos_orders', ['id'], ['id' => $orderId, 'outlet_id' => $outletId]);
    $ok = $ord && function_exists('pos_order_add_item') && pos_order_add_item($db, $orderId, (int) ($_POST['menu_item_id'] ?? 0), (int) ($_POST['qty'] ?? 1));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Item added.' : 'Could not add item.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/pos/order/settle — settle an order (cash | charge-to-room)
$router->post('/supplier/pos/order/settle', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $outletId = (int) ($_POST['outlet_id'] ?? 0);
    $outlet = _pos_outlet_owned($db, $outletId, $owner);
    $back = root . 'supplier/pos/outlet/' . $outletId;
    if (!$outlet || !supplier_can($db, 'rooms', 'edit', (int) $outlet['stay_id'])) { _supplier_stays_deny('Not your outlet.'); }
    // Order must belong to this outlet.
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $ord = $db->get('pos_orders', ['id'], ['id' => $orderId, 'outlet_id' => $outletId]);
    if (!$ord) { _supplier_stays_deny('Order not found for this outlet.'); }

    $tender = strtolower(trim((string) ($_POST['tender'] ?? '')));
    if ($tender === 'cash') {
        $res = pos_order_settle_cash($db, $orderId);
    } elseif ($tender === 'room') {
        $res = pos_order_settle_charge_to_room($db, $orderId, (string) ($_POST['invoice_id'] ?? ''));
    } else {
        $res = ['ok' => false, 'message' => 'Choose a payment method.'];
    }
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    header('Location: ' . $back); exit;
});

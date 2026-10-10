<?php
// FILE: app/routes/users/supplierProcurementRoutes.php
// Procurement (Phase 1 inc S32). OWNER-ONLY (SUPPLIER_AUTH) — purchasing commits the
// property to vendor spend + posts to the GL, so it's an owner financial area (like
// payouts/owners). All writes CSRF-guarded + org-scoped. Reuses stays owner/deny helpers.

@$SECURE or die('Access Denied!');

if (!function_exists('_proc_org')) {
    function _proc_org($db): int
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin']) || empty($ctx['is_owner'])) { return 0; }
        return function_exists('supplier_org_for_owner') ? supplier_org_for_owner($db, (string) $ctx['owner']) : 0;
    }
}

// GET /supplier/procurement — vendors, stock, POs
$router->get('/supplier/procurement', function () use ($SECURE, $db) {
    SUPPLIER_AUTH();
    $orgId = _proc_org($db);
    if ($orgId <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Only the supplier account owner can manage procurement.'];
        header('Location: ' . root . 'supplier/dashboard'); exit;
    }
    $vendors = $db->select('stays_vendors', ['id', 'name', 'email', 'phone'], ['org_id' => $orgId, 'ORDER' => ['name' => 'ASC']]) ?: [];
    $items   = $db->select('stays_stock_items', ['id', 'name', 'unit', 'on_hand', 'reorder_level'], ['org_id' => $orgId, 'ORDER' => ['name' => 'ASC']]) ?: [];
    $pos     = $db->select('stays_purchase_orders', ['id', 'vendor_id', 'reference', 'currency', 'status', 'total', 'created_at'], ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 50]) ?: [];
    $vendorNames = []; foreach ($vendors as $v) { $vendorNames[(int) $v['id']] = $v['name']; }
    // Lines for the open/draft POs (so the owner can build them inline).
    $poLines = [];
    foreach ($pos as $p) { if (in_array($p['status'], ['draft', 'ordered'], true)) { $poLines[(int) $p['id']] = $db->select('stays_po_lines', ['name', 'qty', 'unit_cost'], ['po_id' => (int) $p['id']]) ?: []; } }
    $lowStock = function_exists('proc_low_stock') ? proc_low_stock($db, $orgId) : [];

    $title = 'Procurement'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/procurement.php";
    require_once views . "includes/footer.php";
});

// POST vendor create
$router->post('/supplier/procurement/vendor', function () use ($SECURE, $db) {
    SUPPLIER_AUTH(); CSRF::guard();
    $orgId = _proc_org($db); $back = root . 'supplier/procurement';
    if ($orgId <= 0) { _supplier_stays_deny('Only the owner can manage procurement.'); }
    $id = function_exists('proc_vendor_create') ? proc_vendor_create($db, $orgId, [
        'name' => $_POST['name'] ?? '', 'email' => $_POST['email'] ?? '', 'phone' => $_POST['phone'] ?? '',
        'bank_code' => $_POST['bank_code'] ?? '', 'account_number' => $_POST['account_number'] ?? '',
    ]) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Vendor added.' : 'Could not add vendor (name required?).'];
    header('Location: ' . $back); exit;
});

// POST stock item create
$router->post('/supplier/procurement/item', function () use ($SECURE, $db) {
    SUPPLIER_AUTH(); CSRF::guard();
    $orgId = _proc_org($db); $back = root . 'supplier/procurement';
    if ($orgId <= 0) { _supplier_stays_deny('Only the owner can manage procurement.'); }
    $id = function_exists('proc_item_create') ? proc_item_create($db, $orgId, [
        'name' => $_POST['name'] ?? '', 'unit' => $_POST['unit'] ?? 'unit',
        'on_hand' => $_POST['on_hand'] ?? 0, 'reorder_level' => $_POST['reorder_level'] ?? 0,
    ]) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Stock item added.' : 'Could not add item.'];
    header('Location: ' . $back); exit;
});

// POST PO create (draft)
$router->post('/supplier/procurement/po', function () use ($SECURE, $db) {
    SUPPLIER_AUTH(); CSRF::guard();
    $orgId = _proc_org($db); $back = root . 'supplier/procurement';
    if ($orgId <= 0) { _supplier_stays_deny('Only the owner can manage procurement.'); }
    $id = function_exists('proc_po_create') ? proc_po_create($db, $orgId, (int) ($_POST['vendor_id'] ?? 0), (string) ($_POST['currency'] ?? 'USD')) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Draft purchase order created.' : 'Could not create PO (pick a vendor).'];
    header('Location: ' . $back); exit;
});

// POST PO add line
$router->post('/supplier/procurement/po/line', function () use ($SECURE, $db) {
    SUPPLIER_AUTH(); CSRF::guard();
    $orgId = _proc_org($db); $back = root . 'supplier/procurement';
    if ($orgId <= 0) { _supplier_stays_deny('Only the owner can manage procurement.'); }
    $ok = function_exists('proc_po_add_line') && proc_po_add_line($db, $orgId, (int) ($_POST['po_id'] ?? 0), (int) ($_POST['item_id'] ?? 0), (float) ($_POST['qty'] ?? 0), (float) ($_POST['unit_cost'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Line added.' : 'Could not add line (draft PO + owned item + qty>0).'];
    header('Location: ' . $back); exit;
});

// POST PO transition: order | receive
$router->post('/supplier/procurement/po/transition', function () use ($SECURE, $db) {
    SUPPLIER_AUTH(); CSRF::guard();
    $orgId = _proc_org($db); $back = root . 'supplier/procurement';
    if ($orgId <= 0) { _supplier_stays_deny('Only the owner can manage procurement.'); }
    $poId = (int) ($_POST['po_id'] ?? 0);
    $to = strtolower(trim((string) ($_POST['to'] ?? '')));
    if ($to === 'ordered') {
        $ok = function_exists('proc_po_order') && proc_po_order($db, $orgId, $poId);
        $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Purchase order placed.' : 'Could not place PO (needs at least one line).'];
    } elseif ($to === 'received') {
        $res = function_exists('proc_po_receive') ? proc_po_receive($db, $orgId, $poId) : ['ok' => false, 'message' => 'Unavailable'];
        $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Unknown action.'];
    }
    header('Location: ' . $back); exit;
});

<?php
// FILE: app/routes/users/supplierStoresExtRoutes.php
// STORES & INVENTORY deepening routes (docs 01b module #42) — categories, UOM registry,
// multiple stores, per-store weighted-average valuation, inter-store transfers, batch/
// expiry, physical-count variance, barcode register. Owner/staff gated via
// supplier_can($db,'procurement',…) — the same operational gate the base procurement
// screen uses. All writes CSRF-guarded + org-scoped; no money/GL is posted here
// (transfers are stock-neutral; valuation only records cost — see supplier_stores_ext.php).

@$SECURE or die('Access Denied!');

if (!function_exists('_stores_ext_org')) {
    /** Resolve the acting supplier context to its org id (owner or staff). 0 if none/admin-without-org. */
    function _stores_ext_org($db): int
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { return 0; }
        $owner = (string) ($ctx['owner'] ?? '');
        if ($owner === '' || !function_exists('supplier_org_for_owner')) { return 0; }
        return supplier_org_for_owner($db, $owner);
    }
}

// GET /supplier/stores — the stores & inventory control panel
$router->get('/supplier/stores', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    if (!supplier_can($db, 'procurement', 'view')) { _supplier_stays_deny('Not authorised for procurement.'); }
    $orgId = _stores_ext_org($db);
    if ($orgId <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'No supplier organisation found for this account.'];
        header('Location: ' . root . 'supplier/procurement'); exit;
    }
    if (function_exists('stores_uom_seed_defaults')) { stores_uom_seed_defaults($db, $orgId); }

    $categories = function_exists('stores_category_list') ? stores_category_list($db, $orgId) : [];
    $uoms       = function_exists('stores_uom_list') ? stores_uom_list($db, $orgId) : [];
    $stores     = function_exists('stores_store_list') ? stores_store_list($db, $orgId) : [];
    $matrix     = function_exists('stores_stock_matrix') ? stores_stock_matrix($db, $orgId) : ['rows' => [], 'total_value' => 0.0];
    $transfers  = function_exists('stores_transfer_list') ? stores_transfer_list($db, $orgId) : [];
    $expiring   = function_exists('stores_expiring_batches') ? stores_expiring_batches($db, $orgId, 30) : [];
    $variances  = function_exists('stores_variance_report') ? stores_variance_report($db, $orgId) : [];
    $barcode    = function_exists('stores_barcode_status') ? stores_barcode_status($db, $orgId) : ['connected' => false, 'message' => ''];
    // Base stock items (for the select controls) — read-only here.
    $items = $db->select('stays_stock_items', ['id', 'name', 'unit'], ['org_id' => $orgId, 'status' => 1, 'ORDER' => ['name' => 'ASC']]) ?: [];
    $canEdit = supplier_can($db, 'procurement', 'edit');

    $title = 'Stores & inventory'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/stores-ext.php";
    require_once views . "includes/footer.php";
});

// Small helper to DRY the common write-route preamble. Returns org id or exits/denies.
if (!function_exists('_stores_ext_guard_write')) {
    function _stores_ext_guard_write($db): int
    {
        if (!supplier_can($db, 'procurement', 'edit')) { _supplier_stays_deny('Not authorised to edit stores.'); }
        $orgId = _stores_ext_org($db);
        if ($orgId <= 0) { _supplier_stays_deny('No supplier organisation found.'); }
        return $orgId;
    }
}

// POST category create
$router->post('/supplier/stores/category', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _stores_ext_guard_write($db); $back = root . 'supplier/stores';
    $id = function_exists('stores_category_create') ? stores_category_create($db, $orgId, [
        'name' => $_POST['name'] ?? '', 'notes' => $_POST['notes'] ?? '',
    ]) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Category added.' : 'Could not add category (name required / duplicate).'];
    header('Location: ' . $back); exit;
});

// POST item -> category tag
$router->post('/supplier/stores/item-category', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _stores_ext_guard_write($db); $back = root . 'supplier/stores';
    $ok = function_exists('stores_item_set_category') && stores_item_set_category($db, $orgId, (int) ($_POST['item_id'] ?? 0), (int) ($_POST['category_id'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Item category updated.' : 'Could not update category.'];
    header('Location: ' . $back); exit;
});

// POST UOM create
$router->post('/supplier/stores/uom', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _stores_ext_guard_write($db); $back = root . 'supplier/stores';
    $id = function_exists('stores_uom_create') ? stores_uom_create($db, $orgId, [
        'name' => $_POST['name'] ?? '', 'code' => $_POST['code'] ?? '', 'symbol' => $_POST['symbol'] ?? '',
    ]) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Unit of measure added.' : 'Could not add UOM (name required / duplicate).'];
    header('Location: ' . $back); exit;
});

// POST store create
$router->post('/supplier/stores/store', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _stores_ext_guard_write($db); $back = root . 'supplier/stores';
    $id = function_exists('stores_store_create') ? stores_store_create($db, $orgId, [
        'name' => $_POST['name'] ?? '', 'location' => $_POST['location'] ?? '', 'is_default' => ($_POST['is_default'] ?? '') === '1',
    ]) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Store added.' : 'Could not add store (name required).'];
    header('Location: ' . $back); exit;
});

// POST receive / opening stock into a store (rolls weighted-average cost; NO GL)
$router->post('/supplier/stores/receive', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _stores_ext_guard_write($db); $back = root . 'supplier/stores';
    $res = function_exists('stores_receive_into')
        ? stores_receive_into($db, $orgId, (int) ($_POST['store_id'] ?? 0), (int) ($_POST['item_id'] ?? 0),
            (float) ($_POST['qty'] ?? 0), (float) ($_POST['unit_cost'] ?? 0), (string) ($_POST['reason'] ?? 'opening'))
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => (string) ($res['message'] ?? '')];
    header('Location: ' . $back); exit;
});

// POST inter-store transfer (atomic, stock-neutral)
$router->post('/supplier/stores/transfer', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _stores_ext_guard_write($db); $back = root . 'supplier/stores';
    $res = function_exists('stores_transfer')
        ? stores_transfer($db, $orgId, (int) ($_POST['from_store'] ?? 0), (int) ($_POST['to_store'] ?? 0),
            (int) ($_POST['item_id'] ?? 0), (float) ($_POST['qty'] ?? 0), (string) ($_POST['note'] ?? ''))
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => (string) ($res['message'] ?? '')];
    header('Location: ' . $back); exit;
});

// POST batch / lot create
$router->post('/supplier/stores/batch', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _stores_ext_guard_write($db); $back = root . 'supplier/stores';
    $id = function_exists('stores_batch_create') ? stores_batch_create($db, $orgId, [
        'store_id' => $_POST['store_id'] ?? 0, 'item_id' => $_POST['item_id'] ?? 0,
        'lot_no' => $_POST['lot_no'] ?? '', 'qty' => $_POST['qty'] ?? 0, 'expiry_date' => $_POST['expiry_date'] ?? '',
    ]) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Batch registered.' : 'Could not register batch (store, item, lot, qty>0 required).'];
    header('Location: ' . $back); exit;
});

// POST physical count -> variance
$router->post('/supplier/stores/count', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _stores_ext_guard_write($db); $back = root . 'supplier/stores';
    $res = function_exists('stores_record_count')
        ? stores_record_count($db, $orgId, (int) ($_POST['store_id'] ?? 0), (int) ($_POST['item_id'] ?? 0),
            (float) ($_POST['counted_qty'] ?? 0), (string) ($_POST['note'] ?? ''))
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => (string) ($res['message'] ?? '')];
    header('Location: ' . $back); exit;
});

// POST barcode register on an item (data model; scanning device not connected)
$router->post('/supplier/stores/barcode', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $orgId = _stores_ext_guard_write($db); $back = root . 'supplier/stores';
    $ok = function_exists('stores_item_set_barcode') && stores_item_set_barcode($db, $orgId, (int) ($_POST['item_id'] ?? 0), (string) ($_POST['barcode'] ?? ''));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Barcode saved on item.' : 'Could not save barcode.'];
    header('Location: ' . $back); exit;
});

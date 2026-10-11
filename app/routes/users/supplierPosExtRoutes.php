<?php
// FILE: app/routes/users/supplierPosExtRoutes.php
// F&B / POS — DEEPENING routes (PMS #30). Adjustments (discount/service/tip), split bill,
// POS shifts (open/close + Z-report) and outlet sales reporting. Sits on top of
// supplierPosRoutes.php; reuses its ownership helper _pos_outlet_owned + the stays
// owner/deny helpers. Every POST: SUPPLIER_OR_STAFF_AUTH + CSRF::guard() + supplier_can
// (POS is scoped under the 'rooms' module like the core POS routes). NO client prices.

@$SECURE or die('Access Denied!');

if (!function_exists('_pos_ext_outlet')) {
    /** Resolve + authorise an outlet for the acting owner/staff, or deny. Returns the row. */
    function _pos_ext_outlet($db, int $outletId): array
    {
        $owner = _supplier_stays_owner($db);
        if ($owner === null) { header('Location: ' . root . 'login'); exit; }
        $outlet = function_exists('_pos_outlet_owned') ? _pos_outlet_owned($db, $outletId, $owner) : null;
        if (!$outlet) { _supplier_stays_deny('That outlet is not yours.'); }
        return $outlet;
    }
}

// POST /supplier/pos/order/adjust — add a discount / service charge / tip to an open order
$router->post('/supplier/pos/order/adjust', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $outletId = (int) ($_POST['outlet_id'] ?? 0);
    $outlet = _pos_ext_outlet($db, $outletId);
    $back = root . 'supplier/pos/outlet/' . $outletId;
    if (!supplier_can($db, 'rooms', 'edit', (int) $outlet['stay_id'])) { _supplier_stays_deny('Not your outlet.'); }
    $orderId = (int) ($_POST['order_id'] ?? 0);
    // Order must belong to this outlet (defence in depth; the lib re-checks stay_id too).
    $ord = $db->get('pos_orders', ['id'], ['id' => $orderId, 'outlet_id' => $outletId]);
    if (!$ord) { _supplier_stays_deny('Order not found for this outlet.'); }
    $res = function_exists('pos_order_add_adjustment')
        ? pos_order_add_adjustment($db, $orderId, (int) $outlet['stay_id'],
            (string) ($_POST['kind'] ?? ''), (string) ($_POST['mode'] ?? 'fixed'),
            (float) ($_POST['value'] ?? 0), (string) ($_POST['label'] ?? ''))
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/pos/order/adjust-remove — remove an adjustment
$router->post('/supplier/pos/order/adjust-remove', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $outletId = (int) ($_POST['outlet_id'] ?? 0);
    $outlet = _pos_ext_outlet($db, $outletId);
    $back = root . 'supplier/pos/outlet/' . $outletId;
    if (!supplier_can($db, 'rooms', 'edit', (int) $outlet['stay_id'])) { _supplier_stays_deny('Not your outlet.'); }
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $ord = $db->get('pos_orders', ['id'], ['id' => $orderId, 'outlet_id' => $outletId]);
    if (!$ord) { _supplier_stays_deny('Order not found for this outlet.'); }
    $res = function_exists('pos_order_remove_adjustment')
        ? pos_order_remove_adjustment($db, $orderId, (int) $outlet['stay_id'], (int) ($_POST['adj_id'] ?? 0))
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/pos/order/split — split selected lines into a new open order
$router->post('/supplier/pos/order/split', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $outletId = (int) ($_POST['outlet_id'] ?? 0);
    $outlet = _pos_ext_outlet($db, $outletId);
    $back = root . 'supplier/pos/outlet/' . $outletId;
    if (!supplier_can($db, 'rooms', 'edit', (int) $outlet['stay_id'])) { _supplier_stays_deny('Not your outlet.'); }
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $ord = $db->get('pos_orders', ['id'], ['id' => $orderId, 'outlet_id' => $outletId]);
    if (!$ord) { _supplier_stays_deny('Order not found for this outlet.'); }
    $lineIds = $_POST['line_ids'] ?? [];
    if (!is_array($lineIds)) { $lineIds = array_filter(array_map('trim', explode(',', (string) $lineIds))); }
    $res = function_exists('pos_order_split')
        ? pos_order_split($db, $orderId, (int) $outlet['stay_id'], $lineIds)
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/pos/order/settle-ext — settle with adjustments (discount/service/tip)
$router->post('/supplier/pos/order/settle-ext', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $outletId = (int) ($_POST['outlet_id'] ?? 0);
    $outlet = _pos_ext_outlet($db, $outletId);
    $back = root . 'supplier/pos/outlet/' . $outletId;
    if (!supplier_can($db, 'rooms', 'edit', (int) $outlet['stay_id'])) { _supplier_stays_deny('Not your outlet.'); }
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $ord = $db->get('pos_orders', ['id'], ['id' => $orderId, 'outlet_id' => $outletId]);
    if (!$ord) { _supplier_stays_deny('Order not found for this outlet.'); }
    $tender = strtolower(trim((string) ($_POST['tender'] ?? '')));
    $res = function_exists('pos_order_settle_ext')
        ? pos_order_settle_ext($db, $orderId, (int) $outlet['stay_id'], $tender, (string) ($_POST['invoice_id'] ?? ''))
        : ['ok' => false, 'message' => 'Choose a payment method.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/pos/shift/open — open a cashier shift for an outlet
$router->post('/supplier/pos/shift/open', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $outletId = (int) ($_POST['outlet_id'] ?? 0);
    $outlet = _pos_ext_outlet($db, $outletId);
    $back = root . 'supplier/pos/outlet/' . $outletId;
    if (!supplier_can($db, 'rooms', 'edit', (int) $outlet['stay_id'])) { _supplier_stays_deny('Not your outlet.'); }
    $res = function_exists('pos_shift_open')
        ? pos_shift_open($db, $outletId, (int) $outlet['stay_id'], (float) ($_POST['opening_float'] ?? 0))
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/pos/shift/close — close the open shift (counted drawer cash)
$router->post('/supplier/pos/shift/close', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    CSRF::guard();
    $outletId = (int) ($_POST['outlet_id'] ?? 0);
    $outlet = _pos_ext_outlet($db, $outletId);
    $back = root . 'supplier/pos/outlet/' . $outletId;
    if (!supplier_can($db, 'rooms', 'edit', (int) $outlet['stay_id'])) { _supplier_stays_deny('Not your outlet.'); }
    $res = function_exists('pos_shift_close')
        ? pos_shift_close($db, (int) ($_POST['shift_id'] ?? 0), $outletId, (float) ($_POST['counted_cash'] ?? 0))
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($res['ok']) ? 'success' : 'error', 'text' => $res['message'] ?? 'Done.'];
    header('Location: ' . $back); exit;
});

// GET /supplier/pos/outlet/{id}/report — outlet sales report (read-only)
$router->get('/supplier/pos/outlet/([0-9]+)/report', function ($outletId) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $outlet = _pos_ext_outlet($db, (int) $outletId);
    if (!supplier_can($db, 'rooms', 'view', (int) $outlet['stay_id'])) { _supplier_stays_deny('Not authorised.'); }

    $to   = date('Y-m-d');
    $from = date('Y-m-d', strtotime('-29 days'));
    if (!empty($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['from'])) { $from = (string) $_GET['from']; }
    if (!empty($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['to'])) { $to = (string) $_GET['to']; }

    $report  = function_exists('pos_outlet_sales_report') ? pos_outlet_sales_report($db, (int) $outlet['id'], $from, $to) : ['rows' => [], 'totals' => []];
    $shift   = function_exists('pos_open_shift') ? pos_open_shift($db, (int) $outlet['id']) : null;
    $zreport = ($shift && function_exists('pos_shift_report')) ? pos_shift_report($db, (int) $shift['id']) : null;

    $title = htmlspecialchars((string) $outlet['name']) . ' — POS report'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/pos-ext.php";
    require_once views . "includes/footer.php";
});

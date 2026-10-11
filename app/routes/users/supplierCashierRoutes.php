<?php
// FILE: app/routes/users/supplierCashierRoutes.php
// CASHIER / SHIFT / CASH-DRAWER (PMS Phase 2 — wire the dead cashier engine). The
// cashier_shift_open/close + cash_movement_add functions (supplier_ledger.php) were
// fully built but had NO UI. This exposes them: open a shift, record cash movements,
// close with a counted amount (expected vs actual → over/short).

@$SECURE or die('Access Denied!');

if (!function_exists('_cashier_ctx')) {
    /** ['owner'=>string,'org'=>int] for the acting supplier, or null. */
    function _cashier_ctx($db): ?array
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { return null; }
        $owner = (string) $ctx['owner'];
        $org = function_exists('supplier_org_for_owner') ? supplier_org_for_owner($db, $owner) : 0;
        return ['owner' => $owner, 'org' => (int) $org];
    }
}

// GET /supplier/cashier — the acting user's open shift (if any) + recent closed shifts.
$router->get('/supplier/cashier', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $c = _cashier_ctx($db);
    if ($c === null) { header('Location: ' . root . 'login'); exit; }
    if ($c['org'] <= 0) { _supplier_stays_deny('Cashier is not configured for your account yet.'); }
    $uid = (string) ($_SESSION['user_id'] ?? '');

    $openShift = null; $movements = []; $recent = [];
    try {
        $openShift = $db->get('cashier_shifts', '*', ['org_id' => $c['org'], 'user_id' => $uid, 'status' => 'open']);
        if ($openShift) {
            $movements = $db->select('cash_movements', ['type', 'amount', 'reference', 'memo', 'created_at'],
                ['shift_id' => (int) $openShift['id'], 'ORDER' => ['id' => 'DESC']]) ?: [];
        }
        $recent = $db->select('cashier_shifts', ['id', 'station', 'opening_float', 'closing_counted', 'expected_amount', 'over_short', 'opened_at', 'closed_at'],
            ['org_id' => $c['org'], 'user_id' => $uid, 'status' => 'closed', 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 20]) ?: [];
    } catch (\Throwable $e) { error_log('cashier view: ' . $e->getMessage()); }

    $title = 'Cashier'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/cashier.php";
    require_once views . "includes/footer.php";
});

// POST /supplier/cashier/open — open a shift
$router->post('/supplier/cashier/open', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $c = _cashier_ctx($db);
    if ($c === null) { header('Location: ' . root . 'login'); exit; }
    $back = root . 'supplier/cashier';
    if ($c['org'] <= 0) { _supplier_stays_deny('Cashier not configured.'); }
    $uid = (string) ($_SESSION['user_id'] ?? '');
    $id = function_exists('cashier_shift_open')
        ? cashier_shift_open($db, $c['org'], $uid, (string) ($_POST['station'] ?? 'Front desk'), (float) ($_POST['opening_float'] ?? 0))
        : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Shift opened.' : 'Could not open a shift (one may already be open).'];
    header('Location: ' . $back); exit;
});

// POST /supplier/cashier/movement — record a cash movement on the open shift
$router->post('/supplier/cashier/movement', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $c = _cashier_ctx($db);
    if ($c === null) { header('Location: ' . root . 'login'); exit; }
    $back = root . 'supplier/cashier';
    if ($c['org'] <= 0) { _supplier_stays_deny('Cashier not configured.'); }
    $uid = (string) ($_SESSION['user_id'] ?? '');
    // The movement must target THIS user's own open shift (ownership).
    $shift = $db->get('cashier_shifts', ['id'], ['org_id' => $c['org'], 'user_id' => $uid, 'status' => 'open']);
    if (!$shift) { $_SESSION['message'] = ['type' => 'error', 'text' => 'No open shift.']; header('Location: ' . $back); exit; }
    $ok = function_exists('cash_movement_add') && cash_movement_add($db, (int) $shift['id'],
        strtolower(trim((string) ($_POST['type'] ?? ''))), (float) ($_POST['amount'] ?? 0),
        ['reference' => $_POST['reference'] ?? '', 'memo' => $_POST['memo'] ?? '']);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Movement recorded.' : 'Could not record the movement.'];
    header('Location: ' . $back); exit;
});

// POST /supplier/cashier/close — close the open shift with a counted amount
$router->post('/supplier/cashier/close', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $c = _cashier_ctx($db);
    if ($c === null) { header('Location: ' . root . 'login'); exit; }
    $back = root . 'supplier/cashier';
    if ($c['org'] <= 0) { _supplier_stays_deny('Cashier not configured.'); }
    $uid = (string) ($_SESSION['user_id'] ?? '');
    $shift = $db->get('cashier_shifts', ['id'], ['org_id' => $c['org'], 'user_id' => $uid, 'status' => 'open']);
    if (!$shift) { $_SESSION['message'] = ['type' => 'error', 'text' => 'No open shift.']; header('Location: ' . $back); exit; }
    $res = function_exists('cashier_shift_close') ? cashier_shift_close($db, (int) $shift['id'], (float) ($_POST['counted'] ?? 0)) : ['ok' => false];
    if (!empty($res['ok'])) {
        $os = (float) $res['over_short'];
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Shift closed. Expected ' . number_format((float) $res['expected'], 2) . ', counted ' . number_format((float) $res['actual'], 2) . ' (' . ($os == 0 ? 'balanced' : ($os > 0 ? 'over ' : 'short ') . number_format(abs($os), 2)) . ').'];
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => $res['message'] ?? 'Could not close the shift.'];
    }
    header('Location: ' . $back); exit;
});

<?php
// FILE: app/routes/users/supplierUtilitiesExtRoutes.php
// UTILITIES & PROPERTY SERVICE CHARGES — DEEPENING routes (PMS #51). Builds the gaps
// on top of the base /supplier/utilities (readings): accounts, meters, rates, billing,
// service charges, expense allocation, analytics. Property-scoped via
// supplier_can($db,'hotels',…). Every POST: SUPPLIER_OR_STAFF_AUTH + CSRF::guard() +
// supplier_can(...,'edit',$stayId). Amounts are server-computed; GL only via gl_post().

@$SECURE or die('Access Denied!');

if (!function_exists('_util_ext_ctx')) {
    /** Shared context for the ext screens: owner + owned stay-id set + property map.
     *  Returns [$owner, $stayIds, $propMap, $propList] or redirects/denies. */
    function _util_ext_ctx($db): array
    {
        $owner = _supplier_stays_owner($db);
        if ($owner === null) { header('Location: ' . root . 'login'); exit; }
        if (!supplier_can($db, 'hotels', 'view')) { _supplier_stays_deny('Not authorised.'); }
        $stayIds = supplier_owned_stay_ids($db, $owner);
        $propMap = []; $propList = [];
        if (!empty($stayIds)) {
            try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propMap[(int) $p['id']] = $p['name']; $propList[(int) $p['id']] = $p['name']; } }
            catch (\Throwable $e) {}
        }
        return [$owner, $stayIds, $propMap, $propList];
    }
}

if (!function_exists('_util_ext_require_stay')) {
    /** POST guard: resolve the owned stay set, ensure the submitted stay is editable.
     *  Returns [$stayIds, $stayId] or denies. */
    function _util_ext_require_stay($db): array
    {
        $owner = _supplier_stays_owner($db);
        if ($owner === null) { header('Location: ' . root . 'login'); exit; }
        $stayIds = supplier_owned_stay_ids($db, $owner);
        $stayId = (int) ($_POST['stay_id'] ?? 0);
        if (!supplier_can($db, 'hotels', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
        if (!in_array($stayId, array_map('intval', $stayIds), true)) { _supplier_stays_deny('Not your property.'); }
        return [$stayIds, $stayId];
    }
}

// ---- LANDING (accounts · meters · rates · bills · service charges · analytics) -----
$router->get('/supplier/utilities-ext', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    [$owner, $stayIds, $propMap, $propList] = _util_ext_ctx($db);
    $accounts  = function_exists('util_account_list')   ? util_account_list($db, $stayIds)   : [];
    $meters    = function_exists('util_meter_list')     ? util_meter_list($db, $stayIds)     : [];
    $rates     = function_exists('util_rate_list')      ? util_rate_list($db, $stayIds)      : [];
    $bills     = function_exists('util_bill_list')      ? util_bill_list($db, $stayIds)      : [];
    $charges   = function_exists('service_charge_list') ? service_charge_list($db, $stayIds) : [];
    $analytics = function_exists('util_analytics')      ? util_analytics($db, $stayIds)      : ['by_utility' => [], 'total_amount' => 0.0, 'total_consumption' => 0.0];
    $leases    = function_exists('lease_list')          ? lease_list($db, $stayIds)          : [];
    $canEdit = supplier_can($db, 'hotels', 'edit');
    $title = 'Utilities & service charges'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/utilities-ext.php";
    require_once views . "includes/footer.php";
});

// ---- ACCOUNTS ----------------------------------------------------------------------
$router->post('/supplier/utilities-ext/account/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$stayIds, $stayId] = _util_ext_require_stay($db);
    $id = function_exists('util_account_add') ? util_account_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Utility account added.' : 'Could not add (provider + property required).'];
    header('Location: ' . root . 'supplier/utilities-ext'); exit;
});
$router->post('/supplier/utilities-ext/account/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$stayIds] = _util_ext_require_stay($db);
    $ok = function_exists('util_account_set_status') && util_account_set_status($db, (int) ($_POST['id'] ?? 0), $stayIds, strtolower(trim((string) ($_POST['status'] ?? ''))));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Updated.' : 'Could not update.'];
    header('Location: ' . root . 'supplier/utilities-ext'); exit;
});

// ---- METERS ------------------------------------------------------------------------
$router->post('/supplier/utilities-ext/meter/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$stayIds, $stayId] = _util_ext_require_stay($db);
    $id = function_exists('util_meter_add') ? util_meter_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Meter registered.' : 'Could not register (label + property required).'];
    header('Location: ' . root . 'supplier/utilities-ext'); exit;
});
$router->post('/supplier/utilities-ext/meter/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$stayIds] = _util_ext_require_stay($db);
    $ok = function_exists('util_meter_set_status') && util_meter_set_status($db, (int) ($_POST['id'] ?? 0), $stayIds, strtolower(trim((string) ($_POST['status'] ?? ''))));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Updated.' : 'Could not update.'];
    header('Location: ' . root . 'supplier/utilities-ext'); exit;
});

// ---- RATES / TARIFFS ---------------------------------------------------------------
$router->post('/supplier/utilities-ext/rate/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$stayIds, $stayId] = _util_ext_require_stay($db);
    $id = function_exists('util_rate_add') ? util_rate_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Rate added.' : 'Could not add (valid date + non-negative rate required).'];
    header('Location: ' . root . 'supplier/utilities-ext'); exit;
});

// ---- BILLING -----------------------------------------------------------------------
$router->post('/supplier/utilities-ext/bill/create', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$stayIds, $stayId] = _util_ext_require_stay($db);
    $id = function_exists('util_bill_create') ? util_bill_create($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Draft bill created (amount computed from readings & rates).' : 'Could not create (valid period required).'];
    header('Location: ' . root . 'supplier/utilities-ext'); exit;
});
$router->post('/supplier/utilities-ext/bill/post', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$stayIds] = _util_ext_require_stay($db);
    $r = function_exists('util_bill_post') ? util_bill_post($db, (int) ($_POST['id'] ?? 0), $stayIds) : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($r['ok']) ? 'success' : 'error', 'text' => (string) ($r['message'] ?? 'Error.')];
    header('Location: ' . root . 'supplier/utilities-ext'); exit;
});
$router->post('/supplier/utilities-ext/bill/allocate', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$stayIds] = _util_ext_require_stay($db);
    // Build the shares array from parallel POST arrays. Client supplies weights/leases
    // only — the money split is computed server-side in util_bill_allocate().
    $shares = [];
    $leaseIds = (array) ($_POST['lease_id'] ?? []);
    $weights  = (array) ($_POST['weight'] ?? []);
    $folioIds = (array) ($_POST['folio_id'] ?? []);
    foreach ($leaseIds as $i => $lid) {
        $shares[] = ['lease_id' => (int) $lid, 'weight' => (float) ($weights[$i] ?? 0), 'folio_id' => (int) ($folioIds[$i] ?? 0)];
    }
    $r = function_exists('util_bill_allocate') ? util_bill_allocate($db, (int) ($_POST['bill_id'] ?? 0), $stayIds, $shares) : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($r['ok']) ? 'success' : 'error', 'text' => (string) ($r['message'] ?? 'Error.')];
    header('Location: ' . root . 'supplier/utilities-ext'); exit;
});

// ---- SERVICE CHARGES ---------------------------------------------------------------
$router->post('/supplier/utilities-ext/charge/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$stayIds, $stayId] = _util_ext_require_stay($db);
    $id = function_exists('service_charge_add') ? service_charge_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Service charge defined.' : 'Could not add (name + amount required).'];
    header('Location: ' . root . 'supplier/utilities-ext'); exit;
});
$router->post('/supplier/utilities-ext/charge/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$stayIds] = _util_ext_require_stay($db);
    $ok = function_exists('service_charge_set_status') && service_charge_set_status($db, (int) ($_POST['id'] ?? 0), $stayIds, strtolower(trim((string) ($_POST['status'] ?? ''))));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Updated.' : 'Could not update.'];
    header('Location: ' . root . 'supplier/utilities-ext'); exit;
});
$router->post('/supplier/utilities-ext/charge/post', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$stayIds] = _util_ext_require_stay($db);
    $r = function_exists('service_charge_post')
        ? service_charge_post($db, (int) ($_POST['id'] ?? 0), $stayIds, (string) ($_POST['period'] ?? date('Y-m')), (int) ($_POST['folio_id'] ?? 0))
        : ['ok' => false, 'message' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($r['ok']) ? 'success' : 'error', 'text' => (string) ($r['message'] ?? 'Error.')];
    header('Location: ' . root . 'supplier/utilities-ext'); exit;
});

// ---- PROVIDER PROBE (honest 'not connected' stub) ----------------------------------
$router->post('/supplier/utilities-ext/account/probe', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    [$stayIds] = _util_ext_require_stay($db);
    $acctId = (int) ($_POST['id'] ?? 0);
    // Confirm the account belongs to the owner's property set before probing.
    $owned = function_exists('util_table_exists') && util_table_exists($db, 'stays_utility_accounts')
        ? $db->has('stays_utility_accounts', ['id' => $acctId, 'stay_id' => $stayIds]) : false;
    $r = ($owned && function_exists('util_provider_probe')) ? util_provider_probe($db, $acctId) : ['connected' => false, 'message' => 'Account not found.'];
    $_SESSION['message'] = ['type' => 'info', 'text' => (string) ($r['message'] ?? 'Not connected.')];
    header('Location: ' . root . 'supplier/utilities-ext'); exit;
});

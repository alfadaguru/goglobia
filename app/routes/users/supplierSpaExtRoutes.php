<?php
// FILE: app/routes/users/supplierSpaExtRoutes.php
// Spa, wellness & recreation DEEPENING (#32): therapist/resource roster · appointment
// calendar with double-book conflict detection · memberships (session passes) ·
// charge-to-room (via the shared folio engine). Owner/staff gated; property-scoped via
// supplier_can('reservations', …). Every POST: SUPPLIER_OR_STAFF_AUTH + CSRF::guard +
// supplier_can(...,$stayId). Reuses the folio engine for money — never trusts a client
// price (price is snapshotted from the service row server-side). New files only.
//
// Path base: /supplier/spa (distinct from the base catalogue at
// /supplier/ancillary-services, which stays as the simple price-list/booking screen).

@$SECURE or die('Access Denied!');

if (!function_exists('_spa_ext_owner_or_redirect')) {
    function _spa_ext_owner_or_redirect($db) {
        SUPPLIER_OR_STAFF_AUTH($db);
        $owner = _supplier_stays_owner($db);
        if ($owner === null) { header('Location: ' . root . 'login'); exit; }
        return $owner;
    }
}
if (!function_exists('_spa_ext_props')) {
    function _spa_ext_props($db, string $owner): array {
        $map = [];
        try { foreach ($db->select('stays', ['id', 'name'], ['user_id' => $owner]) ?: [] as $p) { $map[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
        return $map;
    }
}

// ---- WORKSPACE (roster + calendar + memberships) -------------------------------
$router->get('/supplier/spa', function () use ($SECURE, $db) {
    $owner = _spa_ext_owner_or_redirect($db);
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);

    // Date window for the calendar (defaults: today → +13 days).
    $from = trim((string) ($_GET['from'] ?? ''));
    $to   = trim((string) ($_GET['to'] ?? ''));
    if ($from === '' || strtotime($from) === false) { $from = date('Y-m-d'); }
    if ($to === '' || strtotime($to) === false)     { $to = date('Y-m-d', strtotime('+13 days')); }

    $services     = function_exists('service_list') ? service_list($db, $stayIds) : [];
    $resources    = function_exists('spa_resource_list') ? spa_resource_list($db, $stayIds) : [];
    $appointments = function_exists('spa_appointment_list') ? spa_appointment_list($db, $stayIds, $from, $to) : [];
    $memberships  = function_exists('spa_membership_list') ? spa_membership_list($db, $stayIds) : [];

    $propMap  = _spa_ext_props($db, $owner); $propList = $propMap;
    $svcMap   = []; foreach ($services as $s) { $svcMap[(int) $s['id']] = $s; }
    $resMap   = []; foreach ($resources as $r) { $resMap[(int) $r['id']] = $r; }
    $memMap   = []; foreach ($memberships as $m) { $memMap[(int) $m['id']] = $m; }
    $canEdit  = supplier_can($db, 'reservations', 'edit');

    $title = 'Spa & wellness'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/spa-ext.php";
    require_once views . "includes/footer.php";
});

// ---- RESOURCES -----------------------------------------------------------------
$router->post('/supplier/spa/resource/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/spa';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('spa_resource_add') ? spa_resource_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Resource added.' : 'Could not add (name + property required).'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/spa/resource/toggle', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/spa';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('spa_resource_set_active') ? spa_resource_set_active($db, (int) ($_POST['id'] ?? 0), $stayId, (($_POST['active'] ?? '1') === '1')) : false;
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Resource updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});

// ---- APPOINTMENTS (book with conflict detection) -------------------------------
$router->post('/supplier/spa/appointment/book', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/spa';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $res = function_exists('spa_appointment_book')
        ? spa_appointment_book($db, $stayId, (int) ($_POST['service_id'] ?? 0), (int) ($_POST['resource_id'] ?? 0), $_POST)
        : ['ok' => false, 'error' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => $res['ok'] ? 'success' : 'error', 'text' => $res['ok'] ? 'Appointment booked.' : ($res['error'] ?: 'Could not book.')];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/spa/appointment/status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/spa';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('spa_appointment_set_status') ? spa_appointment_set_status($db, (int) ($_POST['id'] ?? 0), $stayId, (string) ($_POST['status'] ?? '')) : false;
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Appointment updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});

// ---- CHARGE-TO-ROOM (folio engine) ---------------------------------------------
$router->post('/supplier/spa/appointment/charge', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/spa';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ctx = function_exists('supplier_acting_context') ? supplier_acting_context($db) : null;
    $actor = ($ctx && !empty($ctx['owner'])) ? substr((string) $ctx['owner'], 0, 60) : 'spa';
    $res = function_exists('spa_appointment_charge_to_room')
        ? spa_appointment_charge_to_room($db, (int) ($_POST['id'] ?? 0), $stayId, $actor)
        : ['ok' => false, 'error' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => $res['ok'] ? 'success' : 'error', 'text' => $res['ok'] ? 'Charged to the guest folio.' : ($res['error'] ?: 'Could not charge.')];
    header('Location: ' . $back); exit;
});

// ---- MEMBERSHIPS ---------------------------------------------------------------
$router->post('/supplier/spa/membership/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/spa';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('spa_membership_add') ? spa_membership_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Membership sold.' : 'Could not add (holder + property required).'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/spa/membership/redeem', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/spa';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $res = function_exists('spa_membership_redeem')
        ? spa_membership_redeem($db, (int) ($_POST['membership_id'] ?? 0), (int) ($_POST['appointment_id'] ?? 0), $stayId)
        : ['ok' => false, 'error' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => $res['ok'] ? 'success' : 'error', 'text' => $res['ok'] ? ('Pass applied. ' . $res['remaining'] . ' session(s) left.') : ($res['error'] ?: 'Could not redeem.')];
    header('Location: ' . $back); exit;
});

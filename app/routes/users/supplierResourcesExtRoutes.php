<?php
// FILE: app/routes/users/supplierResourcesExtRoutes.php
// MODULE 33 (Other hotel services — generic bookable RESOURCES) DEEPENING routes.
// Adds the finite-CAPACITY resource register (shuttle/parking/equipment), the
// capacity-guarded reservation + fulfillment lifecycle, and the guest-folio posting
// (DB-GATED money spine) on top of supplier_resources_ext.php. Sits ALONGSIDE the base
// /supplier/ancillary-services (flat service catalogue, supplierAncillaryRoutes.php) —
// NO path overlap (this owns /supplier/resources).
//
// Owner/staff gated; property-scoped via supplier_can('reservations', …) exactly like
// the ancillary services it extends. Every POST: SUPPLIER_OR_STAFF_AUTH + CSRF::guard()
// + supplier_can with the NAMED stay (IDOR stop). Price is snapshot server-side — the
// client never supplies a charge.

@$SECURE or die('Access Denied!');

if (!function_exists('_res_props')) {
    function _res_props($db, string $owner): array
    {
        $map = [];
        try { foreach ($db->select('stays', ['id', 'name'], ['user_id' => $owner]) ?: [] as $p) { $map[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
        return $map;
    }
}

// ---- LANDING: catalogue + reservations + utilisation ---------------------------
$router->get('/supplier/resources', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);

    $resources = function_exists('resource_list') ? resource_list($db, $stayIds) : [];
    $slots     = function_exists('resource_slot_list') ? resource_slot_list($db, $stayIds) : [];
    $util      = function_exists('resource_utilisation') ? resource_utilisation($db, $stayIds) : [];

    $propMap = _res_props($db, $owner); $propList = $propMap;
    $resMap = []; foreach ($resources as $r) { $resMap[(int) $r['id']] = $r; }
    $kinds = function_exists('resource_kinds') ? resource_kinds() : [];
    $statuses = function_exists('resource_statuses') ? resource_statuses() : [];
    $canEdit = supplier_can($db, 'reservations', 'edit');

    $title = 'Resources'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/resources-ext.php";
    require_once views . "includes/footer.php";
});

// ---- CREATE a resource ---------------------------------------------------------
$router->post('/supplier/resources/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/resources';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('resource_add') ? resource_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Resource added.' : 'Could not add (name + property required).'];
    header('Location: ' . $back); exit;
});

// ---- RETIRE / REACTIVATE a resource --------------------------------------------
$router->post('/supplier/resources/active', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/resources';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $active = (string) ($_POST['active'] ?? '1') === '1';
    $ok = function_exists('resource_set_active') && resource_set_active($db, (int) ($_POST['id'] ?? 0), $stayId, $active);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});

// ---- RESERVE (capacity-guarded) ------------------------------------------------
$router->post('/supplier/resources/reserve', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/resources';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $r = function_exists('resource_reserve')
        ? resource_reserve($db, $stayId, (int) ($_POST['resource_id'] ?? 0), $_POST)
        : ['ok' => false, 'error' => 'Unavailable.'];
    if (!empty($r['ok'])) {
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Reserved (charge ' . number_format((float) ($r['charge'] ?? 0), 2) . ').'];
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => (string) ($r['error'] ?? 'Could not reserve.')];
    }
    header('Location: ' . $back); exit;
});

// ---- ADVANCE fulfillment status ------------------------------------------------
$router->post('/supplier/resources/slot-status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/resources';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('resource_slot_set_status')
        && resource_slot_set_status($db, (int) ($_POST['id'] ?? 0), $stayId, strtolower(trim((string) ($_POST['status'] ?? ''))));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});

// ---- POST reservation charge to the guest FOLIO (DB-GATED money spine) ----------
$router->post('/supplier/resources/post-folio', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/resources';
    // Posting money requires the stronger 'folio' grant on the named property; fall back
    // to reservations-edit if that action is not defined in the role matrix.
    $allowed = supplier_can($db, 'reservations', 'folio', $stayId) || supplier_can($db, 'reservations', 'edit', $stayId);
    if (!$allowed) { _supplier_stays_deny('Not authorised to post charges.'); }
    $r = function_exists('resource_slot_post_folio')
        ? resource_slot_post_folio($db, (int) ($_POST['id'] ?? 0), $stayId)
        : ['ok' => false, 'error' => 'Unavailable.'];
    $_SESSION['message'] = ['type' => !empty($r['ok']) ? 'success' : 'error', 'text' => !empty($r['ok']) ? 'Charge posted to guest folio.' : (string) ($r['error'] ?? 'Could not post.')];
    header('Location: ' . $back); exit;
});

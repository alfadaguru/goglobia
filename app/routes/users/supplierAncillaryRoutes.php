<?php
// FILE: app/routes/users/supplierAncillaryRoutes.php
// Services (spa/wellness/generic #32/#33) + recipes/food-cost (#31). Owner/staff gated,
// property-scoped via supplier_can('reservations', …).

@$SECURE or die('Access Denied!');

if (!function_exists('_anc_props')) {
    function _anc_props($db, string $owner): array
    {
        $map = [];
        try { foreach ($db->select('stays', ['id', 'name'], ['user_id' => $owner]) ?: [] as $p) { $map[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
        return $map;
    }
}

// ---- SERVICES (#32/#33) --------------------------------------------------------
// NOTE: path is /supplier/ancillary-services (NOT /supplier/services) — the bare
// /supplier/services key is owned by the PUBLIC S9 program-landing route in
// supplierServicesRoutes.php. The AppRouter keys routes by path, so sharing the key
// would silently clobber one handler by file-load order. Keep these distinct.
$router->get('/supplier/ancillary-services', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $services = function_exists('service_list') ? service_list($db, $stayIds) : [];
    $bookings = function_exists('service_booking_list') ? service_booking_list($db, $stayIds) : [];
    $propMap = _anc_props($db, $owner); $propList = $propMap;
    $svcNames = []; foreach ($services as $s) { $svcNames[(int) $s['id']] = $s['name']; }
    $canEdit = supplier_can($db, 'reservations', 'edit');
    $title = 'Services'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/services.php";
    require_once views . "includes/footer.php";
});
$router->post('/supplier/ancillary-services/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/ancillary-services';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('service_add') ? service_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Service added.' : 'Could not add (name + property required).'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/ancillary-services/book', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/ancillary-services';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('service_booking_add') ? service_booking_add($db, $stayId, (int) ($_POST['service_id'] ?? 0), $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Service booked.' : 'Could not book.'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/ancillary-services/booking-status', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/ancillary-services';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('service_booking_set_status') && service_booking_set_status($db, (int) ($_POST['id'] ?? 0), $stayId, strtolower(trim((string) ($_POST['status'] ?? ''))));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});

// ---- RECIPES (#31) -------------------------------------------------------------
$router->get('/supplier/recipes', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds = supplier_owned_stay_ids($db, $owner);
    $recipes = function_exists('recipe_list') ? recipe_list($db, $stayIds) : [];
    $propMap = _anc_props($db, $owner); $propList = $propMap;
    $canEdit = supplier_can($db, 'reservations', 'edit');
    $title = 'Recipes & food cost'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/recipes.php";
    require_once views . "includes/footer.php";
});
$router->post('/supplier/recipes/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/recipes';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('recipe_add') ? recipe_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Recipe added.' : 'Could not add (name + property required).'];
    header('Location: ' . $back); exit;
});

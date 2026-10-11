<?php
// FILE: app/routes/users/supplierPackagesExtRoutes.php
// Module 17 — Packages & ancillary revenue (DEEPENED). Owner/staff gated,
// property-scoped. Three new areas on top of base /supplier/packages:
//   - package builder (components)   → supplier_can('rates', ...)
//   - meal plans                     → supplier_can('rates', ...)
//   - post package/meal to a live guest folio (billing) → supplier_can('reservations','edit')
// All POSTs: SUPPLIER_OR_STAFF_AUTH + CSRF::guard + supplier_can(...,$stayId). Money is
// server-priced inside the lib (client price never trusted); folio writes reuse
// folio_add_item via pkg_post_*_to_folio.

@$SECURE or die('Access Denied!');

if (!function_exists('_pkgext_props')) {
    function _pkgext_props($db, string $owner): array
    {
        $map = [];
        try { foreach ($db->select('stays', ['id', 'name'], ['user_id' => $owner]) ?: [] as $p) { $map[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
        return $map;
    }
}

// ---- BUILDER + MEAL PLANS PAGE -------------------------------------------------
$router->get('/supplier/packages/builder', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rates', 'view')) { _supplier_stays_deny('Not authorised.'); }
    $stayIds   = supplier_owned_stay_ids($db, $owner);
    $packages  = function_exists('package_list') ? package_list($db, $stayIds) : [];
    $itemsMap  = function_exists('pkg_items_map') ? pkg_items_map($db, $stayIds) : [];
    $mealPlans = function_exists('meal_plan_list') ? meal_plan_list($db, $stayIds) : [];
    $propMap   = _pkgext_props($db, $owner); $propList = $propMap;
    $mealCodes = function_exists('pkg_meal_plan_codes') ? pkg_meal_plan_codes() : [];
    $itemCats  = function_exists('pkg_item_categories') ? pkg_item_categories() : [];
    $canEdit   = supplier_can($db, 'rates', 'edit');
    $canBill   = supplier_can($db, 'reservations', 'edit');
    $title = 'Package builder & meal plans'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/packages-ext.php";
    require_once views . "includes/footer.php";
});

// ---- PACKAGE COMPONENTS --------------------------------------------------------
$router->post('/supplier/packages/item/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/packages/builder';
    if (!supplier_can($db, 'rates', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('pkg_item_add') ? pkg_item_add($db, (int) ($_POST['package_id'] ?? 0), $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Component added.' : 'Could not add (label + package required).'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/packages/item/delete', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/packages/builder';
    if (!supplier_can($db, 'rates', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('pkg_item_delete') && pkg_item_delete($db, (int) ($_POST['id'] ?? 0), (int) ($_POST['package_id'] ?? 0), $stayId);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Component removed.' : 'Could not remove.'];
    header('Location: ' . $back); exit;
});

// ---- MEAL PLANS ----------------------------------------------------------------
$router->post('/supplier/meal-plans/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/packages/builder';
    if (!supplier_can($db, 'rates', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('meal_plan_add') ? meal_plan_add($db, $stayId, $_POST) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Meal plan added.' : 'Could not add (valid plan code + property; one row per plan).'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/meal-plans/toggle', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/packages/builder';
    if (!supplier_can($db, 'rates', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('meal_plan_set_active') && meal_plan_set_active($db, (int) ($_POST['id'] ?? 0), $stayId, (string) ($_POST['active'] ?? '0') === '1');
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Updated.' : 'Could not update.'];
    header('Location: ' . $back); exit;
});

// ---- POST TO A LIVE GUEST FOLIO (ancillary-revenue capture) --------------------
// Billing a guest is a reservations action; the lib re-verifies the folio's stay is
// in the caller's owned set AND prices the line server-side.
$router->post('/supplier/packages/folio-post', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/packages/builder';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $stayIds  = supplier_owned_stay_ids($db, $owner);
    $invoice  = trim((string) ($_POST['invoice_id'] ?? ''));
    $actor    = (string) ($_SESSION['user_email'] ?? $_SESSION['user_id'] ?? 'desk');
    $kind     = (string) ($_POST['kind'] ?? 'package');
    if ($kind === 'mealplan') {
        $ok = function_exists('pkg_post_mealplan_to_folio')
            && pkg_post_mealplan_to_folio($db, $invoice, (int) ($_POST['meal_plan_id'] ?? 0), (int) ($_POST['nights'] ?? 1), $stayIds, $actor);
    } else {
        $ok = function_exists('pkg_post_package_to_folio')
            && pkg_post_package_to_folio($db, $invoice, (int) ($_POST['package_id'] ?? 0), $stayIds, $actor);
    }
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Posted to folio.' : 'Could not post (open own-inventory folio + active item required).'];
    header('Location: ' . $back); exit;
});

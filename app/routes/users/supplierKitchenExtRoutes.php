<?php
// FILE: app/routes/users/supplierKitchenExtRoutes.php
// KITCHEN / RECIPE / FOOD-COST (#31) deepening routes — ingredient catalogue, BOM,
// requisitions, wastage, actual-vs-theoretical variance, and menu profitability.
// Owner/staff gated; property-scoped via supplier_can('reservations', …) — the same
// gate the base recipe card (supplierAncillaryRoutes.php) already uses, since this is
// the same F&B operational data domain.
//
// MONEY NOTE: this is INTERNAL cost accounting only — no folio/gateway/guest money is
// touched here (see supplier_kitchen_ext.php header). Valuations use the property's own
// purchase costs. POS "dishes sold" is read-only from the existing POS tables.

@$SECURE or die('Access Denied!');

// ---- CONTROL PANEL (ingredients / recipe BOM editor / reports) -----------------
$router->get('/supplier/kitchen', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'reservations', 'view')) { _supplier_stays_deny('Not authorised.'); }
    if (function_exists('ensureKitchenExtSchema')) { ensureKitchenExtSchema($db); }

    $stayIds = supplier_owned_stay_ids($db, $owner);

    // Date window for the reports (default: current month to date).
    $from = isset($_GET['from']) ? preg_replace('/[^0-9\-]/', '', (string) $_GET['from']) : date('Y-m-01');
    $to   = isset($_GET['to'])   ? preg_replace('/[^0-9\-]/', '', (string) $_GET['to'])   : date('Y-m-d');
    if ($from === '' || strlen($from) !== 10) { $from = date('Y-m-01'); }
    if ($to === ''   || strlen($to)   !== 10) { $to   = date('Y-m-d'); }

    $ingredients = function_exists('kitchen_ingredient_list') ? kitchen_ingredient_list($db, $stayIds) : [];
    $recipes     = function_exists('recipe_list') ? recipe_list($db, $stayIds) : [];
    $requisitions = function_exists('kitchen_requisition_list') ? kitchen_requisition_list($db, $stayIds, 100) : [];
    $wastage      = function_exists('kitchen_waste_list') ? kitchen_waste_list($db, $stayIds, 100) : [];
    $variance     = function_exists('kitchen_variance_report') ? kitchen_variance_report($db, $stayIds, $from, $to) : ['rows' => [], 'totals' => []];
    $menu         = function_exists('kitchen_menu_profitability') ? kitchen_menu_profitability($db, $stayIds, $from, $to) : [];

    // A single recipe's BOM is shown when ?recipe=ID is passed and owned.
    $bomRecipe = null; $bomLines = [];
    $rid = (int) ($_GET['recipe'] ?? 0);
    if ($rid > 0) {
        foreach ($recipes as $r) { if ((int) $r['id'] === $rid) { $bomRecipe = $r; break; } }
        if ($bomRecipe && function_exists('kitchen_bom_lines')) { $bomLines = kitchen_bom_lines($db, $rid); }
    }

    // Property name map + per-stay index for dependent selects.
    $propList = []; $ingByStay = []; $recByStay = [];
    if (!empty($stayIds)) {
        try { foreach ($db->select('stays', ['id', 'name'], ['id' => $stayIds]) ?: [] as $p) { $propList[(int) $p['id']] = $p['name']; } } catch (\Throwable $e) {}
    }
    foreach ($ingredients as $i) { $ingByStay[(int) $i['stay_id']][] = $i; }
    foreach ($recipes as $r)     { $recByStay[(int) $r['stay_id']][] = $r; }

    $units   = function_exists('kitchen_units') ? kitchen_units() : [];
    $reasons = function_exists('kitchen_waste_reasons') ? kitchen_waste_reasons() : [];
    $canEdit = supplier_can($db, 'reservations', 'edit');
    $title = 'Kitchen & food cost'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/kitchen-ext.php";
    require_once views . "includes/footer.php";
});

// ---- INGREDIENT: create / update ----------------------------------------------
$router->post('/supplier/kitchen/ingredient', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/kitchen';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('kitchen_ingredient_save')
        ? kitchen_ingredient_save($db, $stayId, $_POST, (int) ($_POST['id'] ?? 0)) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Ingredient saved.' : 'Could not save (name + property required).'];
    header('Location: ' . $back); exit;
});

// ---- BOM: set / clear a recipe ingredient line ---------------------------------
$router->post('/supplier/kitchen/bom', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId   = (int) ($_POST['stay_id'] ?? 0);
    $recipeId = (int) ($_POST['recipe_id'] ?? 0);
    $back = root . 'supplier/kitchen?recipe=' . $recipeId;
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = function_exists('kitchen_bom_set_line') && kitchen_bom_set_line(
        $db, $stayId, $recipeId, (int) ($_POST['ingredient_id'] ?? 0), (float) ($_POST['qty'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Recipe BOM updated.' : 'Could not update (recipe + ingredient must belong to the property).'];
    header('Location: ' . $back); exit;
});

// ---- REQUISITION: issue an ingredient to an outlet -----------------------------
$router->post('/supplier/kitchen/requisition', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/kitchen';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('kitchen_requisition_post') ? kitchen_requisition_post(
        $db, $stayId, (int) ($_POST['ingredient_id'] ?? 0), (float) ($_POST['qty'] ?? 0),
        (string) ($_POST['outlet'] ?? ''), (string) ($_POST['note'] ?? '')) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Requisition issued.' : 'Could not issue (ingredient + positive qty required).'];
    header('Location: ' . $back); exit;
});

// ---- WASTAGE: log spoilage/overportion -----------------------------------------
$router->post('/supplier/kitchen/wastage', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/kitchen';
    if (!supplier_can($db, 'reservations', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $id = function_exists('kitchen_waste_post') ? kitchen_waste_post(
        $db, $stayId, (int) ($_POST['ingredient_id'] ?? 0), (float) ($_POST['qty'] ?? 0),
        (string) ($_POST['reason'] ?? 'other'), (string) ($_POST['note'] ?? '')) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Wastage logged.' : 'Could not log (ingredient + positive qty required).'];
    header('Location: ' . $back); exit;
});

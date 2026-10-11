<?php
// FILE: app/routes/users/supplierGrowthRoutes.php
// Advanced growth: brands (#03, real) + integrations (channel mgr/RMS/AI #12/#14/#57,
// config-only STUBS). Owner-only.

@$SECURE or die('Access Denied!');

if (!function_exists('_growth_ctx')) {
    function _growth_ctx($db): ?array
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin']) || empty($ctx['is_owner'])) { return null; }
        $owner = (string) $ctx['owner'];
        return ['owner' => $owner, 'org' => function_exists('supplier_org_for_owner') ? (int) supplier_org_for_owner($db, $owner) : 0];
    }
}

$router->get('/supplier/growth', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $c = _growth_ctx($db);
    if ($c === null || $c['org'] <= 0) { _supplier_stays_deny('Only the account owner can manage this.'); }
    $brands = function_exists('brand_list') ? brand_list($db, $c['org']) : [];
    // Owner's properties + their brand assignment.
    $properties = [];
    try { $properties = $db->select('stays', ['id', 'name', 'brand_id'], ['user_id' => $c['owner'], 'ORDER' => ['name' => 'ASC']]) ?: []; } catch (\Throwable $e) {}
    $brandNames = []; foreach ($brands as $b) { $brandNames[(int) $b['id']] = $b['name']; }
    // Integration statuses (honest: 'off' | 'stub' | 'connected').
    $integrations = [];
    foreach (integration_catalogue() as $key => $meta) {
        $integrations[$key] = [
            'meta'   => $meta,
            'status' => function_exists('integration_status') ? integration_status($db, $c['org'], $key) : 'off',
            'cfg'    => function_exists('integration_config_get') ? integration_config_get($db, $c['org'], $key) : ['enabled' => false],
        ];
    }
    $title = 'Growth & integrations'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/growth.php";
    require_once views . "includes/footer.php";
});

$router->post('/supplier/growth/brand', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $c = _growth_ctx($db); $back = root . 'supplier/growth';
    if ($c === null || $c['org'] <= 0) { _supplier_stays_deny('Not authorised.'); }
    $id = function_exists('brand_add') ? brand_add($db, $c['org'], (string) ($_POST['name'] ?? '')) : 0;
    $_SESSION['message'] = ['type' => $id > 0 ? 'success' : 'error', 'text' => $id > 0 ? 'Brand added.' : 'Could not add (name required).'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/growth/brand/assign', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $c = _growth_ctx($db); $back = root . 'supplier/growth';
    if ($c === null || $c['org'] <= 0) { _supplier_stays_deny('Not authorised.'); }
    $ok = function_exists('brand_assign_property') && brand_assign_property($db, $c['org'], (int) ($_POST['stay_id'] ?? 0), (int) ($_POST['brand_id'] ?? 0));
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Property updated.' : 'Could not assign.'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/growth/integration', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $c = _growth_ctx($db); $back = root . 'supplier/growth';
    if ($c === null || $c['org'] <= 0) { _supplier_stays_deny('Not authorised.'); }
    $key = strtolower(trim((string) ($_POST['integration'] ?? '')));
    $ok = function_exists('integration_config_save') && integration_config_save($db, $c['org'], $key, isset($_POST['enabled']), ['api_key' => trim((string) ($_POST['api_key'] ?? ''))]);
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Saved. Note: this integration is not yet connected to a live vendor adapter.' : 'Could not save.'];
    header('Location: ' . $back); exit;
});

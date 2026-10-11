<?php
// FILE: app/routes/users/supplierRatesRoutes.php
// MODULE 06 (rates & pricing → 360): manage yield RATE RULES + booking RESTRICTIONS for
// a property. The rules feed stays_recompute_net (server-authoritative pricer); the
// restrictions gate the booking submit. Owner/staff gated + CSRF + supplier_can('rates').

@$SECURE or die('Access Denied!');

$router->get('/supplier/stays/([0-9]+)/rate-rules', function ($id) use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db);
    $owner = _supplier_stays_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'rates', 'view', (int) $id)) { _supplier_stays_deny('That property is not yours.'); }
    $stay = $db->get('stays', ['id', 'name'], ['id' => (int) $id]);
    if (!$stay) { _supplier_stays_deny('Property not found.'); }
    $stayId = (int) $id;
    $rules = [];
    $restrictions = [];
    try { $rules = $db->select('stays_rate_rules', '*', ['stay_id' => $stayId, 'ORDER' => ['priority' => 'DESC', 'id' => 'ASC']]) ?: []; } catch (\Throwable $e) {}
    try { $restrictions = $db->select('stays_rate_restrictions', '*', ['stay_id' => $stayId, 'ORDER' => ['id' => 'DESC']]) ?: []; } catch (\Throwable $e) {}
    $ruleTypes = function_exists('rate_rule_types') ? rate_rule_types() : [];
    $canEdit = supplier_can($db, 'rates', 'edit', $stayId);
    $title = ($stay['name'] ?? 'Property') . ' — Rate rules'; $description = ''; $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/stays/rate-rules.php";
    require_once views . "includes/footer.php";
});

// ---- RATE RULES ---------------------------------------------------------------
$router->post('/supplier/stays/rate-rules/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/stays/' . $stayId . '/rate-rules';
    if (!supplier_can($db, 'rates', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $type = (string) ($_POST['rule_type'] ?? '');
    $name = trim((string) ($_POST['name'] ?? ''));
    $ok = false;
    if ($name !== '' && function_exists('rate_rule_types') && isset(rate_rule_types()[$type])) {
        try {
            $org = function_exists('supplier_property_org') ? (int) supplier_property_org($db, $stayId) : 0;
            $db->insert('stays_rate_rules', [
                'org_id' => $org ?: null, 'stay_id' => $stayId, 'name' => substr($name, 0, 120),
                'rule_type' => $type,
                'scope_room_id' => ($v = (int) ($_POST['scope_room_id'] ?? 0)) > 0 ? $v : null,
                'scope_option_id' => ($v = (int) ($_POST['scope_option_id'] ?? 0)) > 0 ? $v : null,
                'threshold' => $_POST['threshold'] !== '' ? (float) $_POST['threshold'] : null,
                'adjust_mode' => in_array(($_POST['adjust_mode'] ?? 'pct'), ['pct', 'amount'], true) ? $_POST['adjust_mode'] : 'pct',
                'adjust_value' => (float) ($_POST['adjust_value'] ?? 0),
                'weekdays' => trim((string) ($_POST['weekdays'] ?? '')) ?: null,
                'priority' => (int) ($_POST['priority'] ?? 0),
                'active' => 1, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $ok = (int) $db->id() > 0;
        } catch (\Throwable $e) { error_log('rate rule add: ' . $e->getMessage()); }
    }
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Rate rule added.' : 'Could not add (name + type required).'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/stays/rate-rules/toggle', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/stays/' . $stayId . '/rate-rules';
    if (!supplier_can($db, 'rates', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $rid = (int) ($_POST['rule_id'] ?? 0);
    try {
        if ($db->has('stays_rate_rules', ['id' => $rid, 'stay_id' => $stayId])) {
            $cur = $db->get('stays_rate_rules', ['active'], ['id' => $rid]);
            $db->update('stays_rate_rules', ['active' => (int) ($cur['active'] ?? 1) === 1 ? 0 : 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $rid, 'stay_id' => $stayId]);
        }
    } catch (\Throwable $e) { error_log('rate rule toggle: ' . $e->getMessage()); }
    header('Location: ' . $back); exit;
});
$router->post('/supplier/stays/rate-rules/delete', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/stays/' . $stayId . '/rate-rules';
    if (!supplier_can($db, 'rates', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    try { $db->delete('stays_rate_rules', ['id' => (int) ($_POST['rule_id'] ?? 0), 'stay_id' => $stayId]); } catch (\Throwable $e) {}
    $_SESSION['message'] = ['type' => 'success', 'text' => 'Rate rule deleted.'];
    header('Location: ' . $back); exit;
});

// ---- RESTRICTIONS -------------------------------------------------------------
$router->post('/supplier/stays/rate-restrictions/add', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/stays/' . $stayId . '/rate-rules';
    if (!supplier_can($db, 'rates', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    $ok = false;
    try {
        $org = function_exists('supplier_property_org') ? (int) supplier_property_org($db, $stayId) : 0;
        $intOrNull = fn($k) => $_POST[$k] !== '' && (int) $_POST[$k] > 0 ? (int) $_POST[$k] : null;
        $db->insert('stays_rate_restrictions', [
            'org_id' => $org ?: null, 'stay_id' => $stayId,
            'scope_room_id' => $intOrNull('scope_room_id'),
            'date_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['date_from'] ?? '')) ? $_POST['date_from'] : null,
            'date_to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['date_to'] ?? '')) ? $_POST['date_to'] : null,
            'min_stay' => $intOrNull('min_stay'), 'max_stay' => $intOrNull('max_stay'),
            'cta' => isset($_POST['cta']) ? 1 : 0, 'ctd' => isset($_POST['ctd']) ? 1 : 0,
            'min_advance_days' => $intOrNull('min_advance_days'), 'max_advance_days' => $intOrNull('max_advance_days'),
            'active' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $ok = (int) $db->id() > 0;
    } catch (\Throwable $e) { error_log('restriction add: ' . $e->getMessage()); }
    $_SESSION['message'] = ['type' => $ok ? 'success' : 'error', 'text' => $ok ? 'Restriction added.' : 'Could not add.'];
    header('Location: ' . $back); exit;
});
$router->post('/supplier/stays/rate-restrictions/delete', function () use ($SECURE, $db) {
    SUPPLIER_OR_STAFF_AUTH($db); CSRF::guard();
    $owner = _supplier_stays_owner($db); if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $stayId = (int) ($_POST['stay_id'] ?? 0); $back = root . 'supplier/stays/' . $stayId . '/rate-rules';
    if (!supplier_can($db, 'rates', 'edit', $stayId)) { _supplier_stays_deny('Not your property.'); }
    try { $db->delete('stays_rate_restrictions', ['id' => (int) ($_POST['restriction_id'] ?? 0), 'stay_id' => $stayId]); } catch (\Throwable $e) {}
    $_SESSION['message'] = ['type' => 'success', 'text' => 'Restriction deleted.'];
    header('Location: ' . $back); exit;
});

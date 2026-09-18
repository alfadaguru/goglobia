<?php
// app/routes/admin/locationsRoutes.php
@$SECURE or die('Access Denied!');

/*===================================================================
LOCATIONS ROUTES START
===================================================================*/

// ================================ GET / locations - LIST LOCATIONS
$router->get(admin.'/settings/gateways', function () use ($SECURE,$db) {
    ADMIN_AUTH();

    $title = T::gateways_management ?? 'Gateways Management';
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/settings/gateways.php";
    require_once views."includes/footer.php";
});

// Gateway edit page
$router->get(admin.'/settings/gateway/edit/(\d+)', function ($gatewayId) use ($SECURE,$db) {
    ADMIN_AUTH();

    // Get gateway details
    $gateway = $db->get('payment_gateways', '*', ['id' => $gatewayId]);

    if (!$gateway) {
        $_SESSION['message'] = [
            'type' => 'error',
            'key' => 'gateway_not_found',
            'text' => 'Gateway not found'
        ];
        header('Location: ' . root . admin . '/settings/gateways');
        exit;
    }

    // META DATA
    $title = ucfirst($gateway['name']) . ' Gateway Settings';
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/settings/gateway-manage.php";
    require_once views."includes/footer.php";
});

// ===========================================================================
// PER-MODULE / PER-SERVICE PAYMENT SCOPING + PAY-LATER RULES
// ===========================================================================

// Config page: choose a scope (module or a service under it) and configure which
// gateways apply + the Pay-Later rule for it.
$router->get(admin.'/settings/payment-scoping', function () use ($SECURE,$db) {
    ADMIN_AUTH();

    // All module types + their suppliers, from the modules registry.
    $moduleRows = $db->select('modules', ['type', 'name'], ['status' => '1', 'ORDER' => ['type' => 'ASC', 'name' => 'ASC']]) ?: [];
    $moduleTypes = [];
    $suppliersByType = [];
    foreach ($moduleRows as $m) {
        $t = (string) $m['type']; $n = (string) $m['name'];
        if ($t === '') { continue; }
        $moduleTypes[$t] = true;
        // A supplier row is one whose name differs from the module type (the
        // generic "flights/flights" row is the module itself, not a supplier).
        if ($n !== '' && strtolower($n) !== strtolower($t)) { $suppliersByType[$t][] = $n; }
    }
    $moduleTypes = array_keys($moduleTypes);
    sort($moduleTypes);

    $gateways = $db->select('payment_gateways', ['id', 'name', 'display_name', 'type', 'status'], ['ORDER' => ['order' => 'ASC', 'name' => 'ASC']]) ?: [];

    // Current selection (?module=flights&supplier=duffel).
    $selModule   = strtolower(trim((string) ($_GET['module'] ?? '')));
    $selSupplier = strtolower(trim((string) ($_GET['supplier'] ?? '')));
    if (!in_array($selModule, $moduleTypes, true)) { $selModule = $moduleTypes[0] ?? ''; $selSupplier = ''; }

    // Resolve the scope rows currently stored for the selection.
    $scopeType = $selSupplier !== '' ? 'service' : 'module';
    $scopeRows = [];
    if ($selModule !== '') {
        foreach ($db->select('payment_gateway_scopes', ['gateway_id', 'enabled'], [
            'scope_type' => $scopeType, 'module_type' => $selModule, 'supplier' => $selSupplier,
        ]) ?: [] as $r) { $scopeRows[(int) $r['gateway_id']] = (int) $r['enabled']; }
    }
    $hasScopeRows = !empty($scopeRows);

    // Pay-Later rule for the selection (exact row for this scope, if any).
    $plScope = $selModule === '' ? 'global' : $scopeType;
    $plRule = $db->get('pay_later_rules', '*', [
        'scope_type' => $plScope, 'module_type' => ($selModule === '' ? '' : $selModule), 'supplier' => $selSupplier,
    ]);

    $title = 'Payment Scoping & Pay-Later';
    $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/settings/payment-scoping.php";
    require_once views."includes/footer.php";
});

// Save the gateway allow-list for a scope.
$router->post(admin.'/settings/payment-scoping/gateways', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::guard();
    header('Content-Type: application/json');

    $moduleType = strtolower(trim((string) ($_POST['module_type'] ?? '')));
    $supplier   = strtolower(trim((string) ($_POST['supplier'] ?? '')));
    if ($moduleType === '') { echo json_encode(['status' => 'error', 'message' => 'Module is required']); exit; }
    $scopeType = $supplier !== '' ? 'service' : 'module';

    // 'inherit' mode: no explicit rules → delete any rows so the scope inherits.
    $inherit = !empty($_POST['inherit']);
    $db->delete('payment_gateway_scopes', ['scope_type' => $scopeType, 'module_type' => $moduleType, 'supplier' => $supplier]);

    $saved = 0;
    if (!$inherit) {
        // enabled_gateways[] = ids the admin ticked. Everything else is implicitly
        // excluded for this scope (explicit allow-list).
        $enabledIds = array_values(array_unique(array_map('intval', (array) ($_POST['enabled_gateways'] ?? []))));
        $allIds = array_map(fn($g) => (int) $g['id'], $db->select('payment_gateways', ['id']) ?: []);
        foreach ($allIds as $gid) {
            $db->insert('payment_gateway_scopes', [
                'gateway_id' => $gid, 'scope_type' => $scopeType, 'module_type' => $moduleType,
                'supplier' => $supplier, 'enabled' => in_array($gid, $enabledIds, true) ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $saved++;
        }
    }
    echo json_encode(['status' => 'success', 'message' => $inherit ? 'Scope set to inherit.' : "Saved gateway rules for {$moduleType}" . ($supplier !== '' ? "/{$supplier}" : ''), 'inherit' => $inherit]);
    exit;
});

// Save the Pay-Later rule for a scope.
$router->post(admin.'/settings/payment-scoping/pay-later', function () use ($SECURE,$db) {
    ADMIN_AUTH();
    CSRF::guard();
    header('Content-Type: application/json');

    $moduleType = strtolower(trim((string) ($_POST['module_type'] ?? '')));
    $supplier   = strtolower(trim((string) ($_POST['supplier'] ?? '')));
    $scopeType  = $moduleType === '' ? 'global' : ($supplier !== '' ? 'service' : 'module');

    // Sanitize inputs.
    $enabled  = !empty($_POST['enabled']) ? 1 : 0;
    $deadline = max(1, min(8760, (int) ($_POST['deadline_hours'] ?? 72)));   // 1h..1yr
    $policy   = in_array(($_POST['deadline_policy'] ?? ''), ['auto_cancel', 'flag'], true) ? $_POST['deadline_policy'] : 'flag';
    $release  = !empty($_POST['release_inventory']) ? 1 : 0;
    $agents   = !empty($_POST['agents_only']) ? 1 : 0;
    // Normalise the reminder offsets to a sorted, positive, deduped CSV.
    $offsets = array_values(array_unique(array_filter(array_map(
        fn($x) => (int) trim($x), explode(',', (string) ($_POST['reminder_offsets_hours'] ?? ''))
    ), fn($h) => $h > 0 && $h <= 8760)));
    rsort($offsets);
    $offsetsCsv = implode(',', $offsets);
    $minAmount = ($_POST['min_amount'] ?? '') !== '' ? round((float) $_POST['min_amount'], 2) : null;

    $payload = [
        'enabled' => $enabled, 'deadline_hours' => $deadline, 'reminder_offsets_hours' => $offsetsCsv,
        'deadline_policy' => $policy, 'release_inventory' => $release, 'agents_only' => $agents,
        'min_amount' => $minAmount, 'updated_at' => date('Y-m-d H:i:s'),
    ];
    $existing = $db->get('pay_later_rules', 'id', ['scope_type' => $scopeType, 'module_type' => ($moduleType ?: ''), 'supplier' => $supplier]);
    if ($existing) {
        $db->update('pay_later_rules', $payload, ['id' => (int) $existing]);
    } else {
        $db->insert('pay_later_rules', array_merge($payload, ['scope_type' => $scopeType, 'module_type' => ($moduleType ?: ''), 'supplier' => $supplier]));
    }
    echo json_encode(['status' => 'success', 'message' => 'Pay-Later rule saved for ' . ($moduleType === '' ? 'global default' : $moduleType . ($supplier !== '' ? "/{$supplier}" : ''))]);
    exit;
});

// ---------------------------------------------------------------------------
// GATEWAY CREDENTIAL TESTS
// Shared, minimal plumbing used by each gateway's own test route. Every gateway
// registers its OWN test route in its OWN file under app/routes/gateways/{name}.php
// (the same idea as a module's creds.php). There is no central dispatcher.
// ---------------------------------------------------------------------------
if (!function_exists('gw_test_http')) {
    /** Minimal read-only cURL for credential checks. */
    function gw_test_http(string $method, string $url, array $headers = [], $body = null, ?string $basicAuth = null): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($basicAuth !== null) { $opts[CURLOPT_USERPWD] = $basicAuth; }
        if ($body !== null)      { $opts[CURLOPT_POSTFIELDS] = $body; }
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $json = null;
        if (is_string($raw) && $raw !== '') {
            $d = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) { $json = $d; }
        }
        return ['http_code' => $code, 'json' => $json, 'raw' => (string) $raw, 'error' => $err];
    }

    /** Load a gateway row by name and overlay the values currently in the form (test-before-save). */
    function gw_test_gateway($db, string $name): array
    {
        $g = $db->get('payment_gateways', '*', ['name' => $name]) ?: ['name' => $name];
        foreach (['c1', 'c2', 'c3', 'c4', 'c5', 'dev_mode', 'currency'] as $f) {
            if (array_key_exists($f, $_POST)) { $g[$f] = trim((string) $_POST[$f]); }
        }
        return $g;
    }

    /** Emit the terminal JSON response and stop. */
    function gw_test_json(bool $success, string $message, array $steps, int $http = 0): void
    {
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/json');
        echo json_encode(['success' => $success, 'message' => $message, 'steps' => array_values($steps), 'http_code' => $http]);
        exit;
    }

    function gw_test_mask(string $v, int $head = 7): string
    {
        $v = trim($v);
        return $v === '' ? '(empty)' : substr($v, 0, $head) . '…, length ' . strlen($v);
    }
}

// Each gateway registers its own test route from its own file.
foreach (glob(__DIR__ . '/../gateways/*.php') as $gwRouteFile) {
    require_once $gwRouteFile;
}

// Update gateway settings
$router->post(admin.'/settings/gateway/update/(\d+)', function ($gatewayId) use ($SECURE,$db) {
    // Handle AJAX updates for individual fields
    if (isset($_POST['ajax_update'])) {
        error_reporting(0);
        ini_set('display_errors', 0);
        
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_start();

        if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Authentication required']);
            exit;
        }

        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';
        $gatewayId = $_POST['gateway_id'] ?? $gatewayId;

        $response = ['success' => false, 'message' => ''];

        try {
            $gateway = $db->get('payment_gateways', '*', ['id' => $gatewayId]);

            if (in_array($field, ['status', 'dev_mode']) && in_array($value, ['0', '1'])) {
                $updateData = [$field => (int)$value];
                $result = $db->update('payment_gateways', $updateData, ['id' => $gatewayId]);

                if ($result !== false) {
                    $response['success'] = true;
                    $response['message'] = 'Setting updated successfully';
                } else {
                    $response['message'] = 'Failed to update database';
                    $response['previous_value'] = $gateway[$field];
                }
            } else {
                $response['message'] = 'Invalid field or value';
                $response['previous_value'] = $gateway[$field] ?? '';
            }
        } catch (Exception $e) {
            $response['message'] = 'Database error: ' . $e->getMessage();
            $response['previous_value'] = $gateway[$field] ?? '';
        }

        ob_clean();
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    // ADMIN AUTH CHECK for regular requests
    ADMIN_AUTH();

    // Get gateway details first
    $gateway = $db->get('payment_gateways', '*', ['id' => $gatewayId]);

    if (isset($_POST['save_settings'])) {
        if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['message'] = [
                'type' => 'error',
                'key' => 'invalid_csrf',
                'text' => 'Invalid request. Please try again.'
            ];
            header('Location: ' . root . admin . '/settings/gateway/edit/' . $gatewayId);
            exit;
        }

        $updateData = [
            'currency' => $_POST['currency'] ?? 'USD',
            'dev_mode' => isset($_POST['hidden_dev_mode']) ? (int)$_POST['hidden_dev_mode'] : (isset($_POST['dev_mode']) ? 1 : 0),
            'status' => isset($_POST['hidden_status']) ? (int)$_POST['hidden_status'] : $gateway['status'],
            'display_name' => trim((string)($_POST['display_name'] ?? '')),
            'order' => (isset($_POST['order']) && is_numeric($_POST['order'])) ? (int)$_POST['order'] : (int)($gateway['order'] ?? 0)
        ];

        // Add credential fields (c1-c5)
        for ($i = 1; $i <= 5; $i++) {
            $field = 'c' . $i;
            if (isset($_POST[$field])) {
                $updateData[$field] = $_POST[$field];
            }
        }

        // Add note if provided
        if (isset($_POST['note'])) {
            $updateData['note'] = $_POST['note'];
        }

        $result = $db->update('payment_gateways', $updateData, ['id' => $gatewayId]);

        if ($result !== false) {
            $_SESSION['message'] = [
                'type' => 'success',
                'key' => 'gateway_settings_saved',
                'text' => 'Gateway settings updated successfully'
            ];
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'key' => 'error_saving_settings',
                'text' => 'Failed to update gateway settings'
            ];
        }
    }

    // Redirect back to edit page
    header('Location: ' . root . admin . '/settings/gateway/edit/' . $gatewayId);
    exit;
});

// AJAX route for gateway field updates
$router->post(admin.'/settings/gateway/ajax-update', function () use ($db) {

    // Clean output buffer and start fresh
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    // ADMIN AUTH CHECK
    ADMIN_AUTH();
    CSRF::guard();

    // Disable profiler/debug for AJAX requests
    if (defined('PROFILER_ENABLED')) {
        define('PROFILER_ENABLED', false);
    }
    global $PROFILER_ENABLED;
    $PROFILER_ENABLED = false;

    // Set JSON headers
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    header('X-Requested-With: XMLHttpRequest');

    try {
        // Validate request
        if (!isset($_POST['ajax_update']) || !isset($_POST['field']) || !isset($_POST['value']) || !isset($_POST['gateway_id'])) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
            die();
        }

        $field = $_POST['field'];
        $value = $_POST['value'];
        $gatewayId = (int)$_POST['gateway_id'];

        $response = ['success' => false, 'message' => ''];

        // Validate field and value
        if (in_array($field, ['status', 'dev_mode']) && in_array($value, ['0', '1'])) {
            // Get gateway details
            $gateway = $db->get('payment_gateways', '*', ['id' => $gatewayId]);

            if ($gateway) {
                $updateData = [$field => (int)$value];
                $result = $db->update('payment_gateways', $updateData, ['id' => $gatewayId]);
                if ($result !== false) {
                    $response['success'] = true;
                    $response['message'] = 'Setting updated successfully';
                } else {
                    $response['message'] = 'Failed to update database';
                    $response['previous_value'] = $gateway[$field];
                }
            } else {
                $response['message'] = 'Gateway not found';
            }
        } elseif ($field === 'order' && is_numeric($value)) {
            // Inline row-order update from the gateways list.
            $gateway = $db->get('payment_gateways', '*', ['id' => $gatewayId]);
            if ($gateway) {
                $result = $db->update('payment_gateways', ['order' => (int) $value], ['id' => $gatewayId]);
                $response['success'] = ($result !== false);
                $response['message'] = $response['success'] ? 'Order updated successfully' : 'Failed to update database';
            } else {
                $response['message'] = 'Gateway not found';
            }
        } else {
            $response['message'] = 'Invalid field or value';
        }

    } catch (Exception $e) {
        $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
    }

    // Clean any unwanted output
    ob_clean();

    // Output clean JSON
    echo json_encode($response);

    // Force flush and exit
    if (ob_get_level()) {
        ob_end_flush();
    }

    // Force termination to prevent profiler output
    die();

});
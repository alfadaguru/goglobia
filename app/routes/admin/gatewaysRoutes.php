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
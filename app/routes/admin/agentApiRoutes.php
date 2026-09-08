<?php
// app/routes/admin/agentApiRoutes.php
@$SECURE or die('Access Denied!');

// ============================================================================
// AGENT API — admin key management (Phase 1). See docs/AGENT-API.md.
// Admin-only (ADMIN_AUTH) + CSRF. JSON endpoints used by the agent's user-edit
// screen. The full key is returned ONCE on generate and never again.
// Helpers (agent_api_generate_key / revoke) live in app/lib/functions.php.
// ============================================================================

// ADMIN PAGE — manage an agent's API access (keys + service matrix + fees).
$router->get(admin.'/users/api-access/(.+)', function ($user_id) use ($SECURE, $db) {
    ADMIN_AUTH();

    $agent = $db->get('users', ['user_id', 'role', 'first_name', 'last_name', 'email'], ['user_id' => $user_id]);
    // Only meaningful for agents, but show a clear message rather than 404.
    $keys = $db->select('agent_api_keys',
        ['id', 'key_prefix', 'label', 'ip_allowlist', 'status', 'last_used_at', 'created_at', 'revoked_at'],
        ['user_id' => $user_id, 'ORDER' => ['id' => 'DESC']]
    ) ?: [];
    $svcRows = $db->select('agent_api_services', ['service', 'enabled', 'fee_type', 'fee_value'], ['user_id' => $user_id]) ?: [];
    $svcMap = [];
    foreach ($svcRows as $r) { $svcMap[$r['service']] = $r; }

    $title = 'Agent API Access';
    $description = '';
    $header = true; $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/users/api-access.php";
    require_once views."includes/footer.php";
});

// LIST an agent's keys (prefixes only — never the secret) + service matrix.
$router->post(admin.'/users/api-keys/list', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    header('Content-Type: application/json');
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid form submission']); exit;
    }
    $userId = trim($_POST['user_id'] ?? '');
    if ($userId === '') { echo json_encode(['success' => false, 'message' => 'User ID is required']); exit; }

    $keys = $db->select('agent_api_keys',
        ['id', 'key_prefix', 'label', 'ip_allowlist', 'status', 'last_used_at', 'created_at', 'revoked_at'],
        ['user_id' => $userId, 'ORDER' => ['id' => 'DESC']]
    ) ?: [];
    $services = $db->select('agent_api_services',
        ['service', 'enabled', 'fee_type', 'fee_value'],
        ['user_id' => $userId]
    ) ?: [];
    echo json_encode(['success' => true, 'keys' => $keys, 'services' => $services]);
    exit;
});

// GENERATE a new key for an agent. Returns the full key ONCE.
$router->post(admin.'/users/api-keys/generate', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    header('Content-Type: application/json');
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid form submission']); exit;
    }
    $userId = trim($_POST['user_id'] ?? '');
    $label  = trim($_POST['label'] ?? '');
    $ips    = trim($_POST['ip_allowlist'] ?? '');
    if ($userId === '') { echo json_encode(['success' => false, 'message' => 'User ID is required']); exit; }

    if (!function_exists('agent_api_generate_key')) {
        echo json_encode(['success' => false, 'message' => 'Agent API not available']); exit;
    }
    $res = agent_api_generate_key($db, $userId, $label, $ips !== '' ? $ips : null);
    if (empty($res['ok'])) {
        echo json_encode(['success' => false, 'message' => $res['message'] ?? 'Failed to generate key']); exit;
    }
    echo json_encode([
        'success'    => true,
        'message'    => 'API key generated. Copy it now — it will not be shown again.',
        'api_key'    => $res['key'],     // shown ONCE
        'key_prefix' => $res['prefix'],
        'id'         => $res['id'],
    ]);
    exit;
});

// REVOKE a key (constrained to the owning agent for safety).
$router->post(admin.'/users/api-keys/revoke', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    header('Content-Type: application/json');
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid form submission']); exit;
    }
    $userId = trim($_POST['user_id'] ?? '');
    $keyId  = (int) ($_POST['key_id'] ?? 0);
    if ($userId === '' || $keyId <= 0) { echo json_encode(['success' => false, 'message' => 'User ID and key ID are required']); exit; }

    if (!function_exists('agent_api_revoke_key')) {
        echo json_encode(['success' => false, 'message' => 'Agent API not available']); exit;
    }
    $ok = agent_api_revoke_key($db, $keyId, $userId);
    echo json_encode(['success' => (bool) $ok, 'message' => $ok ? 'Key revoked' : 'Failed to revoke key']);
    exit;
});

// SET per-service enablement + fee for an agent (upsert one service row).
$router->post(admin.'/users/api-services/set', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    header('Content-Type: application/json');
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid form submission']); exit;
    }
    $userId  = trim($_POST['user_id'] ?? '');
    $service = strtolower(trim($_POST['service'] ?? ''));
    $enabled = (int) (!empty($_POST['enabled']) && $_POST['enabled'] !== '0');
    $feeType = trim($_POST['fee_type'] ?? 'percentage');
    $feeVal  = (float) ($_POST['fee_value'] ?? 0);

    $validServices = ['flights','stays','cars','tours','visa','umrah','esim','bus','ferries','rail'];
    if ($userId === '' || !in_array($service, $validServices, true)) {
        echo json_encode(['success' => false, 'message' => 'Valid user ID and service are required']); exit;
    }
    if (!in_array($feeType, ['percentage','flat'], true)) { $feeType = 'percentage'; }
    if ($feeVal < 0) { $feeVal = 0; }

    $existing = $db->get('agent_api_services', 'id', ['user_id' => $userId, 'service' => $service]);
    if ($existing) {
        $db->update('agent_api_services', [
            'enabled' => $enabled, 'fee_type' => $feeType, 'fee_value' => $feeVal,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['user_id' => $userId, 'service' => $service]);
    } else {
        $db->insert('agent_api_services', [
            'user_id' => $userId, 'service' => $service, 'enabled' => $enabled,
            'fee_type' => $feeType, 'fee_value' => $feeVal, 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
    echo json_encode(['success' => true, 'message' => 'Service updated']);
    exit;
});

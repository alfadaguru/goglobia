<?php
// app/routes/users/agentApiRoutes.php
@$SECURE or die('Access Denied!');

// ============================================================================
// AGENT API — agent-facing dashboard section + docs (Phase 3).
// See docs/AGENT-API.md. Agent-only. Agents can view/generate/revoke their OWN
// keys, see enabled services + fees + usage, and read the API docs.
// ============================================================================

/** Shared: resolve the logged-in agent or redirect. Returns the users row. */
if (!function_exists('_agent_api_require_agent')) {
    function _agent_api_require_agent($db)
    {
        if (!isset($_SESSION['user_id'])) {
            $_SESSION['error_message'] = T::login_required ?? 'Please log in';
            REDIRECT(root . 'login');
            exit;
        }
        $user = $db->get('users', ['id', 'user_id', 'role', 'first_name', 'last_name', 'email', 'created_at', 'status'], ['user_id' => $_SESSION['user_id']]);
        if (!$user || $user['role'] !== 'agent') {
            $_SESSION['error_message'] = T::access_denied ?? 'Access Denied - Agents Only';
            REDIRECT(root . 'dashboard');
            exit;
        }
        return $user;
    }
}

// ---------------------------------------------------------------------------
// API ACCESS PAGE
// ---------------------------------------------------------------------------
$router->get('api-access', function () use ($SECURE, $db) {
    $user   = _agent_api_require_agent($db);
    $userId = $user['user_id'];

    $dashboardData = [
        'user_name'    => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
        'user_email'   => $user['email'] ?? '',
        'member_since' => $user['created_at'] ?? 'N/A',
        'account_status' => $user['status'] ?? 'active',
        'user_role'    => 'agent',
        'total_bookings' => $db->count('bookings', ['user_id' => $userId]),
    ];

    $keys = $db->select('agent_api_keys',
        ['id', 'key_prefix', 'label', 'ip_allowlist', 'status', 'last_used_at', 'created_at', 'revoked_at'],
        ['user_id' => $userId, 'ORDER' => ['id' => 'DESC']]
    ) ?: [];

    $services = $db->select('agent_api_services',
        ['service', 'enabled', 'fee_type', 'fee_value'],
        ['user_id' => $userId]
    ) ?: [];

    // Wallet balance (credits ledger) + last 30d usage count.
    $walletBalance = function_exists('agent_api_wallet_balance') ? agent_api_wallet_balance($db, $userId) : 0.0;
    $usageCount = (int) $db->count('agent_api_usage', ['user_id' => $userId]);

    // One-time freshly-generated key (flash), shown once then cleared.
    $freshKey = $_SESSION['agent_api_fresh_key'] ?? null;
    unset($_SESSION['agent_api_fresh_key']);

    $agentApiHost = strtolower(trim((string) ($GLOBALS['app']['agent_api_host'] ?? '')));

    $title = 'API Access';
    $description = 'Manage your API keys and integration';

    require_once views . 'includes/header.php';
    require_once views . 'auth/api-access.php';
    require_once views . 'includes/footer.php';
});

// ---------------------------------------------------------------------------
// AGENT self-serve: GENERATE own key
// ---------------------------------------------------------------------------
$router->post('api-access/generate', function () use ($SECURE, $db) {
    $user = _agent_api_require_agent($db);
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid form submission';
        REDIRECT(root . 'api-access'); exit;
    }
    $label = trim($_POST['label'] ?? '');
    $ips   = trim($_POST['ip_allowlist'] ?? '');
    if (function_exists('agent_api_generate_key')) {
        $res = agent_api_generate_key($db, $user['user_id'], $label, $ips !== '' ? $ips : null);
        if (!empty($res['ok'])) {
            // Show the full key exactly once on the next page load.
            $_SESSION['agent_api_fresh_key'] = $res['key'];
            $_SESSION['success_message'] = 'API key generated. Copy it now — it will not be shown again.';
        } else {
            $_SESSION['error_message'] = $res['message'] ?? 'Failed to generate key';
        }
    }
    REDIRECT(root . 'api-access'); exit;
});

// ---------------------------------------------------------------------------
// AGENT self-serve: REVOKE own key
// ---------------------------------------------------------------------------
$router->post('api-access/revoke', function () use ($SECURE, $db) {
    $user = _agent_api_require_agent($db);
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid form submission';
        REDIRECT(root . 'api-access'); exit;
    }
    $keyId = (int) ($_POST['key_id'] ?? 0);
    if ($keyId > 0 && function_exists('agent_api_revoke_key')) {
        // Constrain to the agent's OWN keys.
        agent_api_revoke_key($db, $keyId, $user['user_id']);
        $_SESSION['success_message'] = 'API key revoked';
    }
    REDIRECT(root . 'api-access'); exit;
});

// ---------------------------------------------------------------------------
// API DOCS PAGE
// ---------------------------------------------------------------------------
$router->get('api-docs', function () use ($SECURE, $db) {
    $user = _agent_api_require_agent($db);
    $dashboardData = [
        'user_name'    => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
        'user_email'   => $user['email'] ?? '',
        'member_since' => $user['created_at'] ?? 'N/A',
        'account_status' => $user['status'] ?? 'active',
        'user_role'    => 'agent',
        'total_bookings' => $db->count('bookings', ['user_id' => $user['user_id']]),
    ];
    $agentApiHost = strtolower(trim((string) ($GLOBALS['app']['agent_api_host'] ?? ''))) ?: 'api.goglobia.com';
    $services = $db->select('agent_api_services', ['service', 'enabled'], ['user_id' => $user['user_id'], 'enabled' => 1]) ?: [];

    $title = 'API Documentation';
    $description = 'Goglobia Agent API reference';

    require_once views . 'includes/header.php';
    require_once views . 'auth/api-docs.php';
    require_once views . 'includes/footer.php';
});

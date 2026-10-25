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

    // PAID-SERVICE access stage (request → sandbox → certify → live). The view
    // shows a request form when not yet granted, and only offers key generation
    // for the environment the agency is currently cleared for.
    $apiAccess = function_exists('agent_api_access_get') ? agent_api_access_get($db, $userId) : ['status' => 'none'];
    $apiAccessStatus = (string) ($apiAccess['status'] ?? 'none');
    $apiCanMint = function_exists('agent_api_can_mint') ? agent_api_can_mint($db, $userId) : '';

    $title = 'API Access';
    $description = 'Manage your API keys and integration';

    require_once views . 'agent/layout-top.php';
    require_once views . 'auth/api-access.php';
    require_once views . 'agent/layout-bottom.php';
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

    // GATE: an agency may only mint keys for the environment it is cleared for.
    // '' = not yet granted (must request/await admin) → refuse. 'sandbox' after
    // admin grants sandbox; 'live' only after admin certifies + grants live.
    $canMint = function_exists('agent_api_can_mint') ? agent_api_can_mint($db, $user['user_id']) : '';
    if ($canMint === '') {
        $_SESSION['error_message'] = 'API access is not active yet. Request access and wait for approval.';
        REDIRECT(root . 'api-access'); exit;
    }
    // The agent may ask for a sandbox or live key; clamp to what they're allowed.
    // (live-cleared agencies may mint either; sandbox-cleared may mint sandbox only.)
    $reqEnv = ($_POST['env'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox';
    if ($reqEnv === 'live' && $canMint !== 'live') {
        $_SESSION['error_message'] = 'Live keys require live certification. You can create sandbox keys for now.';
        REDIRECT(root . 'api-access'); exit;
    }
    if (function_exists('agent_api_generate_key')) {
        $res = agent_api_generate_key($db, $user['user_id'], $label, $ips !== '' ? $ips : null, $reqEnv);
        if (!empty($res['ok'])) {
            // Show the full key exactly once on the next page load.
            $_SESSION['agent_api_fresh_key'] = $res['key'];
            $_SESSION['success_message'] = strtoupper($reqEnv) . ' API key generated. Copy it now — it will not be shown again.';
            if (function_exists('agent_audit_log')) { agent_audit_log($db, $user['user_id'], $user['user_id'], 'api.key.generate', $reqEnv); }
        } else {
            $_SESSION['error_message'] = $res['message'] ?? 'Failed to generate key';
        }
    }
    REDIRECT(root . 'api-access'); exit;
});

// ---------------------------------------------------------------------------
// AGENT: REQUEST API access (starts the paid-service flow — not instant).
// ---------------------------------------------------------------------------
$router->post('api-access/request', function () use ($SECURE, $db) {
    $user = _agent_api_require_agent($db);
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid form submission'; REDIRECT(root . 'api-access'); exit;
    }
    $owner = (string) $user['user_id'];
    $cur = function_exists('agent_api_access_status') ? agent_api_access_status($db, $owner) : 'none';
    // Only allow a fresh request from 'none' or 'rejected' (don't let it reset an
    // already-granted sandbox/live/certifying state).
    if (!in_array($cur, ['none', 'rejected'], true)) {
        $_SESSION['error_message'] = 'An access request is already in progress or granted.';
        REDIRECT(root . 'api-access'); exit;
    }
    try {
        agent_api_access_get($db, $owner); // ensure a row exists
        $db->update('agent_api_access', [
            'status'        => 'requested',
            'company_name'  => substr(trim((string)($_POST['company_name'] ?? '')), 0, 255) ?: null,
            'contact_email' => substr(trim((string)($_POST['contact_email'] ?? ($user['email'] ?? ''))), 0, 255) ?: null,
            'contact_phone' => substr(trim((string)($_POST['contact_phone'] ?? '')), 0, 40) ?: null,
            'use_case'      => substr(trim((string)($_POST['use_case'] ?? '')), 0, 2000) ?: null,
            'expected_volume' => substr(trim((string)($_POST['expected_volume'] ?? '')), 0, 120) ?: null,
            'requested_at'  => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ], ['owner_user_id' => $owner]);
        if (function_exists('agent_audit_log')) { agent_audit_log($db, $owner, $owner, 'api.access.request', ''); }
        $_SESSION['success_message'] = 'API access requested. Our team will reach out to you — watch your email.';
    } catch (\Throwable $e) { error_log('api-access/request: ' . $e->getMessage()); $_SESSION['error_message'] = 'Could not submit your request.'; }
    REDIRECT(root . 'api-access'); exit;
});

// ---------------------------------------------------------------------------
// AGENT: request LIVE certification (agency says sandbox integration is ready).
// ---------------------------------------------------------------------------
$router->post('api-access/request-live', function () use ($SECURE, $db) {
    $user = _agent_api_require_agent($db);
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid form submission'; REDIRECT(root . 'api-access'); exit;
    }
    $owner = (string) $user['user_id'];
    $cur = function_exists('agent_api_access_status') ? agent_api_access_status($db, $owner) : 'none';
    if ($cur !== 'sandbox') {
        $_SESSION['error_message'] = 'You can request live certification once your sandbox access is active.';
        REDIRECT(root . 'api-access'); exit;
    }
    try {
        $db->update('agent_api_access',
            ['status' => 'certifying', 'certify_requested_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
            ['owner_user_id' => $owner]);
        if (function_exists('agent_audit_log')) { agent_audit_log($db, $owner, $owner, 'api.access.request_live', ''); }
        $_SESSION['success_message'] = 'Live certification requested. We will review your sandbox integration and get back to you.';
    } catch (\Throwable $e) { error_log('api-access/request-live: ' . $e->getMessage()); $_SESSION['error_message'] = 'Could not submit your request.'; }
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

    require_once views . 'agent/layout-top.php';
    require_once views . 'auth/api-docs.php';
    require_once views . 'agent/layout-bottom.php';
});

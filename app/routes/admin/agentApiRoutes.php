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
    admin_require($db, 'agent_api', 'view');

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
    admin_require($db, 'agent_api', 'view');
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
    admin_require($db, 'agent_api', 'edit');
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
    admin_require($db, 'agent_api', 'edit');
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
    admin_require($db, 'agent_api', 'edit');
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

// ============================================================================
// AGENT API — PAID-ACCESS APPROVAL QUEUE (request → sandbox → certify → live).
//   GET  /admin/agent-api                 → queue of access requests + states
//   POST /admin/agent-api/act             → advance/reject/suspend one agency
// Admin advances each stage; the agent can then mint sandbox/live keys per
// agent_api_can_mint(). "We reach out to them" is manual (email); this tracks
// the gate so nobody gets live access without an explicit admin step.
// ============================================================================
$router->get(admin.'/agent-api', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'agent_api', 'view');

    $rows = [];
    try {
        $rows = $db->select('agent_api_access', '*', ['ORDER' => ['updated_at' => 'DESC', 'id' => 'DESC']]) ?: [];
    } catch (\Throwable $e) { error_log('admin agent-api list: ' . $e->getMessage()); }
    // Attach the agency name + agent email for each row.
    foreach ($rows as &$r) {
        $r['agent'] = null;
        try {
            $u = $db->get('users', ['first_name','last_name','email'], ['user_id' => $r['owner_user_id']]);
            if ($u) { $r['agent'] = trim(($u['first_name']??'').' '.($u['last_name']??'')) ?: ($u['email'] ?? $r['owner_user_id']); $r['agent_email'] = $u['email'] ?? ''; }
            $ag = $db->get('agencies', ['agency_name'], ['user_id' => $r['owner_user_id']]);
            $r['agency_name'] = $ag['agency_name'] ?? '';
            $r['sandbox_keys'] = (int) $db->count('agent_api_keys', ['user_id' => $r['owner_user_id'], 'env' => 'sandbox', 'status' => 'active']);
            $r['live_keys']    = (int) $db->count('agent_api_keys', ['user_id' => $r['owner_user_id'], 'env' => 'live', 'status' => 'active']);
        } catch (\Throwable $e) {}
    }
    unset($r);

    $title = 'Agent API Access';
    require_once views."includes/header.php";
    require_once views."admin/agent-api/index.php";
    require_once views."includes/footer.php";
});

$router->post(admin.'/agent-api/act', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'agent_api', 'edit');
    CSRF::guard();

    $owner  = (string) ($_POST['owner_user_id'] ?? '');
    $action = (string) ($_POST['action'] ?? '');
    $note   = substr(trim((string) ($_POST['admin_note'] ?? '')), 0, 2000);
    $back   = root . admin . '/agent-api';
    if ($owner === '') { $_SESSION['message'] = ['type'=>'error','text'=>'No agency specified.']; header('Location: '.$back); exit; }

    $cur = $db->get('agent_api_access', ['status'], ['owner_user_id' => $owner]);
    $curStatus = (string) ($cur['status'] ?? 'none');

    // Valid transitions (admin-driven). Each target + the states it may come from.
    $map = [
        'grant_sandbox' => ['to' => 'sandbox',   'from' => ['requested','certifying','suspended'], 'stamp' => 'sandbox_granted_at', 'msg' => 'Sandbox access granted.'],
        'grant_live'    => ['to' => 'live',       'from' => ['certifying','sandbox','suspended'],   'stamp' => 'live_granted_at',    'msg' => 'Live access granted.'],
        'reject'        => ['to' => 'rejected',   'from' => ['requested','certifying'],             'stamp' => null,                 'msg' => 'Access request rejected.'],
        'suspend'       => ['to' => 'suspended',  'from' => ['sandbox','certifying','live'],        'stamp' => null,                 'msg' => 'Access suspended.'],
    ];
    if (!isset($map[$action])) { $_SESSION['message'] = ['type'=>'error','text'=>'Unknown action.']; header('Location: '.$back); exit; }
    $t = $map[$action];
    if (!in_array($curStatus, $t['from'], true)) {
        $_SESSION['message'] = ['type'=>'error','text'=>"Cannot {$action} from state '{$curStatus}'."];
        header('Location: '.$back); exit;
    }

    $upd = ['status' => $t['to'], 'admin_note' => $note ?: null, 'reviewed_by' => (string)($_SESSION['user_id'] ?? ''), 'updated_at' => date('Y-m-d H:i:s')];
    if ($t['stamp']) { $upd[$t['stamp']] = date('Y-m-d H:i:s'); }

    try {
        $db->update('agent_api_access', $upd, ['owner_user_id' => $owner]);
        // On suspend: revoke the agency's live keys as a safety measure (sandbox
        // keys can stay — they hit no real money).
        if ($action === 'suspend') {
            try { $db->update('agent_api_keys', ['status'=>'revoked','revoked_at'=>date('Y-m-d H:i:s')], ['user_id'=>$owner, 'env'=>'live', 'status'=>'active']); } catch (\Throwable $e) {}
        }
        // Notify the agent (best-effort).
        if (function_exists('SENDEMAIL')) {
            try {
                $u = $db->get('users', ['email','first_name','last_name'], ['user_id'=>$owner]);
                if ($u && !empty($u['email'])) {
                    $brand = $GLOBALS['app']['business_name'] ?? 'Our Platform';
                    $subjMap = ['grant_sandbox'=>'Your API sandbox access is ready','grant_live'=>'Your API LIVE access is approved','reject'=>'Update on your API access request','suspend'=>'Your API access has been suspended'];
                    SENDEMAIL($u['email'], trim(($u['first_name']??'').' '.($u['last_name']??'')), $subjMap[$action] ?? 'API access update',
                        '<p>'.htmlspecialchars($t['msg']).'</p>'.($note!==''?'<p><em>'.htmlspecialchars($note).'</em></p>':'').'<p><a href="'.root.'api-access">Open your API access</a></p><p>— '.htmlspecialchars($brand).'</p>', null);
                }
            } catch (\Throwable $e) { error_log('agent-api act notify: '.$e->getMessage()); }
        }
        $_SESSION['message'] = ['type'=>'success','text'=>$t['msg']];
    } catch (\Throwable $e) { error_log('agent-api/act: '.$e->getMessage()); $_SESSION['message'] = ['type'=>'error','text'=>'Could not update access.']; }
    header('Location: '.$back); exit;
});

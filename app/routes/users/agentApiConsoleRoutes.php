<?php
// ============================================================================
// app/routes/users/agentApiConsoleRoutes.php — Agent ERP: full API CONSOLE.
//
// The "whole lot of dashboard and pages" for API access (owner 2026-10-25):
//   GET  /agent/api            → Overview (status, wallet, usage, next step)
//   GET  /agent/api/services   → per-service HYBRID lifecycle (request→sandbox→
//                                 request-live→certifying→live), per service
//   GET  /agent/api/keys       → key management (create/revoke, env, IP allowlist)
//   GET  /agent/api/webhooks   → webhook endpoints + delivery history
//   GET  /agent/api/logs       → request/usage log
//   GET  /agent/api/docs       → API documentation (reuses auth/api-docs.php)
//   POST /agent/api/service/request        (agent asks for a service)
//   POST /agent/api/service/request-live   (agent asks to certify a service live)
//   POST /agent/api/webhooks/save|delete|rotate  (agent manages webhooks)
//
// HYBRID model: the agency gets IN once (agent_api_access via the legacy
// /api-access request flow, kept). Then each service is certified separately on
// agent_api_services.lifecycle. Keys + IP + revoke reuse the existing
// /api-access/generate + /api-access/revoke handlers (agentApiRoutes.php).
//
// Gated by AGENT_OR_STAFF_AUTH + agent_can('api',...). Dedicated portal layout.
// ============================================================================
@$SECURE or die('Access Denied!');

if (!function_exists('_agent_api_console_ctx')) {
    /** Shared guard: resolve agency context for an api-capable agent/staff, or
     *  redirect. Returns [$ctx, $owner]. $need = 'view' (read) or 'manage' (write). */
    function _agent_api_console_ctx($db, string $need = 'view'): array
    {
        AGENT_OR_STAFF_AUTH($db);
        $ctx = agent_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { header('Location: ' . root . 'login'); exit; }
        if (!agent_can($db, 'api', $need)) {
            $_SESSION['message'] = ['type' => 'error', 'text' => 'You do not have access to API settings.'];
            header('Location: ' . root . 'agent/dashboard'); exit;
        }
        return [$ctx, (string) $ctx['owner']];
    }
}

// NOTE: console pages render with `require_once views."…"` DIRECTLY in each
// route closure (not via a helper function) so the page variables ($db, $owner,
// $keys, $csrf, …) are in the view's scope — exactly like every other portal
// route. Rendering through a function would hide the closure's locals.

// ---- OVERVIEW --------------------------------------------------------------
$router->get('/agent/api', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_api_console_ctx($db, 'view');

    $access       = agent_api_access_get($db, $owner);
    $accessStatus = (string) ($access['status'] ?? 'none');
    $canMint      = agent_api_can_mint($db, $owner);
    $svcRows      = agent_api_all_service_rows($db, $owner);
    $allSvcs      = agent_api_console_services();
    $liveCount    = 0; $sandboxCount = 0;
    foreach ($svcRows as $r) {
        $lc = (string) ($r['lifecycle'] ?? 'none');
        if ($lc === 'live') { $liveCount++; }
        elseif (in_array($lc, ['sandbox','certifying'], true)) { $sandboxCount++; }
    }
    $walletBalance = function_exists('agent_api_wallet_balance') ? (float) agent_api_wallet_balance($db, $owner) : 0.0;
    // Guarded: these tables are created by ensureAgentApiSchema; a brand-new
    // install that hasn't run the schema update yet should still render (0s),
    // not 500. (Same defensive read pattern used across the portal.)
    $usageCount = $activeKeys = $webhookCount = 0;
    try { $usageCount   = (int) $db->count('agent_api_usage',  ['user_id' => $owner]); } catch (\Throwable $e) {}
    try { $activeKeys   = (int) $db->count('agent_api_keys',   ['user_id' => $owner, 'status' => 'active']); } catch (\Throwable $e) {}
    try { $webhookCount = (int) $db->count('agent_api_webhooks',['user_id' => $owner, 'status' => 'active']); } catch (\Throwable $e) {}
    $agentApiHost  = strtolower(trim((string) ($GLOBALS['app']['agent_api_host'] ?? '')));

    $apiActive = 'overview';
    $title = 'API Console';
    require_once views . 'agent/layout-top.php';
    require_once views . 'agent/api/overview.php';
    require_once views . 'agent/layout-bottom.php';
});

// ---- SERVICES (hybrid per-service lifecycle) -------------------------------
$router->get('/agent/api/services', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_api_console_ctx($db, 'view');

    $access       = agent_api_access_get($db, $owner);
    $accessStatus = (string) ($access['status'] ?? 'none');
    $allSvcs      = agent_api_console_services();
    $svcRows      = agent_api_all_service_rows($db, $owner);
    $canManage    = agent_can($db, 'api', 'manage');
    $csrf         = CSRF::getToken();

    $apiActive = 'services';
    $title = 'API Services';
    require_once views . 'agent/layout-top.php';
    require_once views . 'agent/api/services.php';
    require_once views . 'agent/layout-bottom.php';
});

// ---- KEYS ------------------------------------------------------------------
$router->get('/agent/api/keys', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_api_console_ctx($db, 'view');

    $canMint = agent_api_can_mint($db, $owner);
    $keys = $db->select('agent_api_keys',
        ['id','key_prefix','label','ip_allowlist','env','status','last_used_at','created_at','revoked_at'],
        ['user_id' => $owner, 'ORDER' => ['id' => 'DESC']]) ?: [];
    $freshKey = $_SESSION['agent_api_fresh_key'] ?? null; unset($_SESSION['agent_api_fresh_key']);
    $canManage = agent_can($db, 'api', 'manage');
    $csrf = CSRF::getToken();

    $apiActive = 'keys';
    $title = 'API Keys';
    require_once views . 'agent/layout-top.php';
    require_once views . 'agent/api/keys.php';
    require_once views . 'agent/layout-bottom.php';
});

// ---- WEBHOOKS --------------------------------------------------------------
$router->get('/agent/api/webhooks', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_api_console_ctx($db, 'view');

    $hooks = [];
    try {
        $hooks = $db->select('agent_api_webhooks',
            ['id','url','events','secret','env','status','last_delivery_at','last_status_code','fail_count','created_at'],
            ['user_id' => $owner, 'ORDER' => ['id' => 'DESC']]) ?: [];
    } catch (\Throwable $e) { error_log('agent/api/webhooks select: ' . $e->getMessage()); }
    // Recent delivery attempts across this agency's hooks (for the history panel).
    $deliveries = [];
    try {
        $deliveries = $db->select('agent_api_webhook_deliveries',
            ['webhook_id','event','response_code','delivered','attempt','created_at'],
            ['user_id' => $owner, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 30]) ?: [];
    } catch (\Throwable $e) { error_log('agent/api/webhooks deliveries: ' . $e->getMessage()); }
    $freshSecret = $_SESSION['agent_api_fresh_secret'] ?? null; unset($_SESSION['agent_api_fresh_secret']);
    $eventList = agent_api_webhook_events();
    $canManage = agent_can($db, 'api', 'manage');
    $canMint   = agent_api_can_mint($db, $owner); // ''|sandbox|live — gate adding
    $csrf = CSRF::getToken();

    $apiActive = 'webhooks';
    $title = 'API Webhooks';
    require_once views . 'agent/layout-top.php';
    require_once views . 'agent/api/webhooks.php';
    require_once views . 'agent/layout-bottom.php';
});

// ---- LOGS ------------------------------------------------------------------
$router->get('/agent/api/logs', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_api_console_ctx($db, 'view');

    $logs = $db->select('agent_api_usage',
        ['key_id','endpoint','ip','status_code','created_at'],
        ['user_id' => $owner, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 200]) ?: [];
    $usageCount = (int) $db->count('agent_api_usage', ['user_id' => $owner]);

    $apiActive = 'logs';
    $title = 'API Logs';
    require_once views . 'agent/layout-top.php';
    require_once views . 'agent/api/logs.php';
    require_once views . 'agent/layout-bottom.php';
});

// ---- DOCS (dedicated, well-structured console documentation) ---------------
$router->get('/agent/api/docs', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_api_console_ctx($db, 'view');

    // Host is shown as-is; empty means "not configured yet" (the docs view notes
    // this rather than faking a real host).
    $agentApiHost = strtolower(trim((string) ($GLOBALS['app']['agent_api_host'] ?? '')));
    // Only services the agency can actually call appear "enabled" in the docs.
    $services = [];
    foreach (agent_api_console_services() as $svc => $_lbl) {
        if (agent_api_service_can_call($db, $owner, $svc) !== '') { $services[] = ['service' => $svc]; }
    }
    $apiActive = 'docs';
    $title = 'API Documentation';
    require_once views . 'agent/layout-top.php';
    require_once views . 'agent/api/docs.php';   // dedicated structured docs
    require_once views . 'agent/layout-bottom.php';
});

// ============================================================================
// POST — per-service lifecycle (agent side of the hybrid certification)
// ============================================================================

// Agent requests a service (none/rejected → requested). Agency must be IN first.
$router->post('/agent/api/service/request', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_api_console_ctx($db, 'manage');
    CSRF::guard();
    $service = strtolower(trim((string) ($_POST['service'] ?? '')));
    $back = root . 'agent/api/services';

    // The agency must have cleared the top-level gate (sandbox or better) before
    // requesting individual services — that is the "get in once" step.
    if (agent_api_can_mint($db, $owner) === '') {
        $_SESSION['message'] = ['type'=>'error','text'=>'Request API access first (Overview), then request individual services once your account manager grants you in.'];
        header('Location: ' . $back); exit;
    }
    $res = agent_api_service_set_lifecycle($db, $owner, $service, 'requested', [
        'use_case' => substr(trim((string)($_POST['use_case'] ?? '')), 0, 2000) ?: null,
    ]);
    if (function_exists('agent_audit_log')) { agent_audit_log($db, $owner, (string)$ctx['actor'], 'api.service.request', $service); }
    $_SESSION['message'] = ['type' => $res['ok'] ? 'success' : 'error',
        'text' => $res['ok'] ? ucfirst($service) . ' requested. We will enable its sandbox and reach out.' : $res['message']];
    header('Location: ' . $back); exit;
});

// Agent requests LIVE certification for a service (sandbox → certifying).
$router->post('/agent/api/service/request-live', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_api_console_ctx($db, 'manage');
    CSRF::guard();
    $service = strtolower(trim((string) ($_POST['service'] ?? '')));
    $back = root . 'agent/api/services';
    $res = agent_api_service_set_lifecycle($db, $owner, $service, 'certifying');
    if (function_exists('agent_audit_log')) { agent_audit_log($db, $owner, (string)$ctx['actor'], 'api.service.request_live', $service); }
    $_SESSION['message'] = ['type' => $res['ok'] ? 'success' : 'error',
        'text' => $res['ok'] ? ucfirst($service) . ': live certification requested. We will review your sandbox integration.' : $res['message']];
    header('Location: ' . $back); exit;
});

// ============================================================================
// POST — webhooks (agent manages their own endpoints)
// ============================================================================

$router->post('/agent/api/webhooks/save', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_api_console_ctx($db, 'manage');
    CSRF::guard();
    $back = root . 'agent/api/webhooks';

    // GATE: webhooks are part of the paid integration — an agency may only
    // register them once it is cleared for API access (sandbox or live). Before
    // that, there are no keys/calls for events to fire from, so adding a webhook
    // is meaningless. Mirrors the key-minting gate. '' => not yet approved.
    $mintEnv = agent_api_can_mint($db, $owner);
    if ($mintEnv === '') {
        $_SESSION['message'] = ['type'=>'error','text'=>'API access is not active yet. Request access and wait for approval before adding webhooks.'];
        header('Location: ' . $back); exit;
    }

    $id   = (int) ($_POST['id'] ?? 0);
    $url  = trim((string) ($_POST['url'] ?? ''));
    $env  = ($_POST['env'] ?? 'sandbox') === 'live' ? 'live' : 'sandbox';
    // A sandbox-only agency cannot register a LIVE webhook — clamp to what they
    // are cleared for (live-cleared agencies may register either env).
    if ($env === 'live' && $mintEnv !== 'live') { $env = 'sandbox'; }
    $valid = agent_api_webhook_events();
    $events = array_values(array_intersect($valid, (array) ($_POST['events'] ?? [])));
    $eventsCsv = implode(',', $events);

    // Validate URL: must be a real https:// endpoint.
    if (!filter_var($url, FILTER_VALIDATE_URL) || stripos($url, 'https://') !== 0) {
        $_SESSION['message'] = ['type'=>'error','text'=>'Enter a valid https:// webhook URL.'];
        header('Location: ' . $back); exit;
    }
    try {
        if ($id > 0) {
            $own = $db->get('agent_api_webhooks', 'user_id', ['id' => $id]);
            if ((string)$own !== $owner) { $_SESSION['message'] = ['type'=>'error','text'=>'Not your webhook.']; header('Location: '.$back); exit; }
            $db->update('agent_api_webhooks',
                ['url'=>$url, 'events'=>$eventsCsv, 'env'=>$env, 'updated_at'=>date('Y-m-d H:i:s')],
                ['id'=>$id]);
            $msg = 'Webhook updated.';
        } else {
            $secret = agent_api_webhook_new_secret();
            $db->insert('agent_api_webhooks', [
                'user_id'=>$owner, 'url'=>$url, 'events'=>$eventsCsv, 'secret'=>$secret,
                'env'=>$env, 'status'=>'active', 'created_at'=>date('Y-m-d H:i:s'),
            ]);
            $_SESSION['agent_api_fresh_secret'] = $secret; // show once
            $msg = 'Webhook created. Copy the signing secret now.';
        }
        if (function_exists('agent_audit_log')) { agent_audit_log($db, $owner, (string)$ctx['actor'], 'api.webhook.save', $url); }
        $_SESSION['message'] = ['type'=>'success','text'=>$msg];
    } catch (\Throwable $e) { error_log('agent/api/webhooks/save: '.$e->getMessage()); $_SESSION['message']=['type'=>'error','text'=>'Could not save the webhook.']; }
    header('Location: ' . $back); exit;
});

$router->post('/agent/api/webhooks/delete', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_api_console_ctx($db, 'manage');
    CSRF::guard();
    $back = root . 'agent/api/webhooks';
    $id = (int) ($_POST['id'] ?? 0);
    try {
        $own = $db->get('agent_api_webhooks', 'user_id', ['id' => $id]);
        if ((string)$own === $owner) {
            $db->delete('agent_api_webhooks', ['id' => $id]);
            if (function_exists('agent_audit_log')) { agent_audit_log($db, $owner, (string)$ctx['actor'], 'api.webhook.delete', (string)$id); }
            $_SESSION['message'] = ['type'=>'success','text'=>'Webhook removed.'];
        } else { $_SESSION['message'] = ['type'=>'error','text'=>'Not your webhook.']; }
    } catch (\Throwable $e) { error_log('agent/api/webhooks/delete: '.$e->getMessage()); $_SESSION['message']=['type'=>'error','text'=>'Could not delete.']; }
    header('Location: ' . $back); exit;
});

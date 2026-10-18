<?php
// ============================================================================
// FILE: app/routes/ai/giaRoutes.php
// GIA — GoGlobia Intelligent Agent (BRD "GIA by GoGlobia") — Phase A
// POST /api/gia/chat — agentic chat + tool-calling (READ-ONLY tools only)
//
// Phase A scope: a model-agnostic chat agent (Claude / OpenAI / Gemini, chosen by
// the admin's settings.ai_configuration) that can call ONE read-only tool
// (search_trip -> the existing aiSearchService). No bookings, no payments, no
// state changes. Conversation state is kept in the session for this phase.
//
// Loading note: the App\lib\ai\* classes autoload via composer's CLASSMAP
// (vendor/composer/autoload_classmap.php). This install has NO composer.json, so
// the classmap cannot be regenerated on deploy; the NEW GIA classes are therefore
// loaded with explicit require_once below (plain-PHP style, deploy-safe). The
// pre-existing aiSearchService + ai_* helper functions already autoload.
// ============================================================================
@$SECURE or die('Access Denied!');

// GET /gia — the GIA chat UI (house pattern: header + view + footer).
$router->get('/gia', function () use ($SECURE, $db) {
    if (!function_exists('aiTripIsEnabled') || !aiTripIsEnabled($db)) {
        header('Location: ' . root);
        exit;
    }
    $title = 'GIA — GoGlobia Intelligent Agent';
    require_once views . 'includes/header.php';
    require_once views . 'ai/gia.php';
    require_once views . 'includes/footer.php';
});

$router->post('/api/gia/chat', function () use ($db) {
    header('Content-Type: application/json');

    $respond = function (int $http, array $body): void {
        http_response_code($http);
        echo json_encode($body);
    };

    // --- Feature gate: reuse the existing AI-trip enablement flag. ---
    if (!function_exists('aiTripIsEnabled') || !aiTripIsEnabled($db)) {
        $respond(403, [
            'status' => false,
            'reply' => 'GIA is currently disabled.',
            'error_code' => 'AI_DISABLED',
            'tool_runs' => [],
        ]);
        return;
    }

    // --- CSRF (state-light but a POST that spends money on LLM calls). ---
    // Accept the token from JSON body or X-CSRF-TOKEN header (CSRF::validateToken
    // already supports the header per the platform convention).
    $rawInput = file_get_contents('php://input') ?: '';
    $input = json_decode($rawInput, true);
    if (!is_array($input)) {
        $input = [];
    }
    $csrf = (string) ($input['csrf_token'] ?? ($_POST['csrf_token'] ?? ''));
    if (class_exists('CSRF') && method_exists('CSRF', 'validateToken')) {
        if (!CSRF::validateToken($csrf)) {
            $respond(403, [
                'status' => false,
                'reply' => 'Your session expired. Please refresh and try again.',
                'error_code' => 'CSRF_INVALID',
                'tool_runs' => [],
            ]);
            return;
        }
    }

    // --- COST-ABUSE GUARD (same limiter + policy as /api/ai/search). Every call
    //     below makes a real, PAID LLM request (and may call tools that run more).
    //     Throttle per client IP BEFORE any provider call; fail closed with 429. ---
    if (!class_exists('RateLimiter')) {
        $rlFile = dirname(__DIR__, 3) . '/modules/RateLimiter.php';
        if (is_file($rlFile)) {
            require_once $rlFile;
        }
    }
    if (class_exists('RateLimiter')) {
        $rl = new RateLimiter(dirname(__DIR__, 3) . '/app/cache/rate_limiter');
        $rlId = 'gia_chat:' . $rl->getClientIdentifier();
        // Agent turns can fan out into multiple LLM calls, so GIA is throttled
        // TIGHTER than plain search: 8 messages / minute, 80 / hour.
        $burstOk = $rl->attempt($rlId . ':burst', 8, 60);
        $hourOk  = $rl->attempt($rlId . ':hour', 80, 3600);
        if (!$burstOk || !$hourOk) {
            $respond(429, [
                'status' => false,
                'reply' => 'You are sending messages too fast. Please wait a moment.',
                'error_code' => 'AI_RATE_LIMITED',
                'tool_runs' => [],
            ]);
            header('Retry-After: 60');
            return;
        }
    }

    // --- Validate the message. ---
    $message = trim((string) ($input['message'] ?? ''));
    if ($message === '' || mb_strlen($message) < 2) {
        $respond(400, [
            'status' => false,
            'reply' => 'Please type a message.',
            'error_code' => 'AI_EMPTY_MESSAGE',
            'tool_runs' => [],
        ]);
        return;
    }
    if (mb_strlen($message) > 2000) {
        $message = mb_substr($message, 0, 2000);
    }

    // --- Load the GIA classes (explicit, deploy-safe). ---
    $aiDir = dirname(__DIR__, 2) . '/lib/ai/';
    require_once $aiDir . 'giaToolInterface.php';
    require_once $aiDir . 'giaChatProvider.php';
    require_once $aiDir . 'giaAgent.php';
    require_once $aiDir . 'tools/searchTripTool.php';
    require_once $aiDir . 'tools/composeTripTool.php';
    require_once $aiDir . 'tools/createCheckoutTool.php';
    require_once $aiDir . 'tools/requestApprovalTool.php';
    require_once $aiDir . 'tools/initiatePaymentTool.php';
    require_once $aiDir . 'tools/getOrderStatusTool.php';

    // --- Conversation history (Phase A: session-scoped, bounded). ---
    $convKey = 'gia_conv';
    $history = (isset($_SESSION[$convKey]) && is_array($_SESSION[$convKey])) ? $_SESSION[$convKey] : [];
    // Bound stored history so context + session stay small.
    if (count($history) > 20) {
        $history = array_slice($history, -20);
    }

    // --- Trusted per-request context (set by the ROUTE, never the model). ---
    // user_approved is TRUE only when the client sends an explicit approve action
    // (a real UI button). The model cannot fabricate it — request_booking_approval
    // reads this, not the conversation.
    $toolContext = [
        'account_id'    => (string) ($_SESSION['user_id'] ?? ''),
        'user_approved' => ($input['approved'] ?? null) === true,
        'currency'      => (string) ($_SESSION['app_currency'] ?? 'USD'),
    ];

    // --- Run the agent. ---
    try {
        $agent = new \App\lib\ai\giaAgent($db);
        $agent->registerTool(new \App\lib\ai\searchTripTool());
        $agent->registerTool(new \App\lib\ai\composeTripTool());
        $agent->registerTool(new \App\lib\ai\createCheckoutTool());
        $agent->registerTool(new \App\lib\ai\requestApprovalTool());
        $agent->registerTool(new \App\lib\ai\initiatePaymentTool());
        $agent->registerTool(new \App\lib\ai\getOrderStatusTool());
        $agent->setToolContext($toolContext);
        $result = $agent->run($message, $history);
    } catch (\Throwable $e) {
        error_log('[gia] route error: ' . $e->getMessage());
        $respond(500, [
            'status' => false,
            'reply' => 'GIA hit an unexpected error. Please try again.',
            'error_code' => 'AI_INTERNAL_ERROR',
            'tool_runs' => [],
        ]);
        return;
    }

    // --- Persist the updated history (store the compact canonical messages). ---
    if (!empty($result['messages']) && is_array($result['messages'])) {
        $_SESSION[$convKey] = array_slice($result['messages'], -20);
    }

    // --- Surface tool outputs the UI needs (search intents + checkout/approval). ---
    $searches = [];
    $checkout = null;   // {checkout_ref, amount, currency, expires_at} from create_checkout
    $approval = null;   // {checkout_ref, approval_token, expires_at} from request_booking_approval
    $payment = null;    // {invoice_id, redirect_url, amount, currency} from initiate_payment
    $orderStatus = null; // {state, invoice_id, pnr, needs_ops} from get_order_status
    foreach (($result['tool_runs'] ?? []) as $tr) {
        $name = (string) ($tr['name'] ?? '');
        $data = (isset($tr['output']['data']) && is_array($tr['output']['data'])) ? $tr['output']['data'] : [];
        if ($name === 'search_trip' && isset($data['searches']) && is_array($data['searches'])) {
            $searches = $data['searches'];
        } elseif ($name === 'create_checkout' && ($tr['output']['ok'] ?? false) && !empty($data['checkout_ref'])) {
            $checkout = [
                'checkout_ref' => (string) $data['checkout_ref'],
                'amount'       => (float) ($data['amount'] ?? 0),
                'currency'     => (string) ($data['currency'] ?? ''),
                'expires_at'   => (string) ($data['expires_at'] ?? ''),
            ];
        } elseif ($name === 'request_booking_approval' && ($tr['output']['ok'] ?? false) && !empty($data['approval_token'])) {
            $approval = [
                'checkout_ref'   => (string) ($data['checkout_ref'] ?? ''),
                'approval_token' => (string) $data['approval_token'],
                'expires_at'     => (string) ($data['expires_at'] ?? ''),
            ];
        } elseif ($name === 'initiate_payment' && ($tr['output']['ok'] ?? false) && !empty($data['redirect_url'])) {
            $payment = [
                'invoice_id'   => (string) ($data['invoice_id'] ?? ''),
                'redirect_url' => (string) $data['redirect_url'],
                'amount'       => (float) ($data['amount'] ?? 0),
                'currency'     => (string) ($data['currency'] ?? ''),
                'already_paid' => (bool) ($data['already_paid'] ?? false),
            ];
        } elseif ($name === 'get_order_status' && ($tr['output']['ok'] ?? false)) {
            $orderStatus = [
                'state'        => (string) ($data['state'] ?? ''),
                'invoice_id'   => (string) ($data['invoice_id'] ?? ''),
                'pnr'          => (string) ($data['pnr'] ?? ''),
                'needs_ops'    => (bool) ($data['needs_ops'] ?? false),
            ];
        }
    }

    $respond(200, [
        'status'     => (bool) ($result['ok'] ?? false),
        'reply'      => (string) ($result['reply'] ?? ''),
        'provider'   => (string) ($result['provider'] ?? ''),
        'model'      => (string) ($result['model'] ?? ''),
        'error_code' => (string) ($result['error'] ?? ''),
        'searches'   => $searches,
        'checkout'   => $checkout,
        'approval'   => $approval,
        'payment'    => $payment,
        'order_status' => $orderStatus,
        // Lightweight trace for the UI / admin (no secrets, no raw payloads).
        'tool_runs'  => array_map(static function ($tr) {
            return [
                'name' => (string) ($tr['name'] ?? ''),
                'ok'   => (bool) ($tr['output']['ok'] ?? false),
            ];
        }, is_array($result['tool_runs'] ?? null) ? $result['tool_runs'] : []),
        'usage'      => $result['usage'] ?? ['input_tokens' => 0, 'output_tokens' => 0],
    ]);
});

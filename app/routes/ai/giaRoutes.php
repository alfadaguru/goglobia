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
            'reply' => "I'm just getting set up and can't chat just yet. Please check back soon!",
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
    require_once $aiDir . 'tools/fetchLiveOffersTool.php';
    require_once $aiDir . 'tools/selectOptionTool.php';
    require_once $aiDir . 'tools/composeTripTool.php';
    require_once $aiDir . 'tools/createCheckoutTool.php';
    require_once $aiDir . 'tools/requestApprovalTool.php';
    require_once $aiDir . 'tools/initiatePaymentTool.php';
    require_once $aiDir . 'tools/getOrderStatusTool.php';
    // CRM + analytics (persistent chat, leads, logging). Fail-soft: if these are
    // unavailable the chat still works, just without persistence/reporting.
    require_once $aiDir . 'gia_analytics.php';
    require_once $aiDir . 'gia_crm.php';

    // --- Resolve the persistent conversation (cross-tab/device). The server DB is
    //     the source of truth; the PHP session is just a fast same-session cache. ---
    $browserId = function_exists('gia_browser_id') ? gia_browser_id() : '';
    $userId = (string) ($_SESSION['user_id'] ?? '');
    $surface = (string) ($input['surface'] ?? 'widget');
    $conv = function_exists('gia_conv_get_or_create')
        ? gia_conv_get_or_create($db, $browserId, $userId, (string) ($input['context'] ?? ''), $surface)
        : null;
    $convId = (int) ($conv['id'] ?? 0);

    // --- Conversation history: prefer the persisted transcript (so a NEW tab /
    //     refresh / another device resumes the same chat); fall back to session. ---
    $convKey = 'gia_conv';
    $history = [];
    if ($convId > 0 && function_exists('gia_conv_history')) {
        $history = gia_conv_history($db, $convId, 20);
    }
    if ($history === [] && isset($_SESSION[$convKey]) && is_array($_SESSION[$convKey])) {
        $history = $_SESSION[$convKey];
    }
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
        // The last search's params+suppliers per module, so fetch_live_offers can run
        // the live supplier lookup for a module the traveller searched earlier. Set by
        // the ROUTE from prior search_trip results — never model-supplied.
        'last_search_params' => (isset($_SESSION['gia_last_search']) && is_array($_SESSION['gia_last_search']))
            ? $_SESSION['gia_last_search'] : [],
    ];

    // --- Run the agent. ---
    try {
        $agent = new \App\lib\ai\giaAgent($db);
        $agent->registerTool(new \App\lib\ai\searchTripTool());
        $agent->registerTool(new \App\lib\ai\fetchLiveOffersTool());
        $agent->registerTool(new \App\lib\ai\selectOptionTool());
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

    // --- Persist this turn to the DB transcript (cross-tab resume + reporting). ---
    if ($convId > 0 && function_exists('gia_log_turn')) {
        gia_log_turn(
            $db,
            $convId,
            $message,
            (string) ($result['reply'] ?? ''),
            is_array($result['usage'] ?? null) ? $result['usage'] : []
        );
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
        $logEvent = ($convId > 0 && function_exists('gia_log_event'));
        if ($name === 'search_trip' && isset($data['searches']) && is_array($data['searches'])) {
            $searches = $data['searches'];
            // Cache each module's search params+suppliers so a later fetch_live_offers
            // (live-supplier modules) can run the supplier lookup. Route-set, not model.
            if (!isset($_SESSION['gia_last_search']) || !is_array($_SESSION['gia_last_search'])) {
                $_SESSION['gia_last_search'] = [];
            }
            foreach ($data['searches'] as $sr) {
                if (!is_array($sr)) { continue; }
                $mod = (string) ($sr['module'] ?? $sr['kind'] ?? '');
                if ($mod === '') { continue; }
                $_SESSION['gia_last_search'][$mod] = [
                    'params'    => is_array($sr['params'] ?? null) ? $sr['params'] : [],
                    'suppliers' => is_array($sr['suppliers'] ?? null) ? $sr['suppliers'] : [],
                ];
            }
            if ($logEvent) {
                // One search event per module the search touched (for the funnel).
                $modulesSeen = [];
                foreach ($data['searches'] as $sr) {
                    $mod = is_array($sr) ? (string) ($sr['module'] ?? $sr['kind'] ?? '') : '';
                    if ($mod === '' || isset($modulesSeen[$mod])) {
                        continue;
                    }
                    $modulesSeen[$mod] = true;
                    $hasCards = is_array($sr) && !empty($sr['cards']);
                    gia_log_event($db, $convId, 'search', [
                        'module' => $mod,
                        'meta'   => $hasCards ? 'results' : 'intent',
                    ]);
                }
            }
        } elseif ($name === 'fetch_live_offers' && ($tr['output']['ok'] ?? false) && isset($data['cards']) && is_array($data['cards'])) {
            // Live-supplier offers render as pickable cards — surface them like a search.
            $mod = (string) ($data['module'] ?? '');
            $searches[] = [
                'module'  => $mod,
                'kind'    => $mod,
                'title'   => ucfirst($mod) . ' options',
                'cards'   => $data['cards'],
                'live'    => true,
                'expires' => (bool) ($data['expires'] ?? false),
            ];
            if ($logEvent && $mod !== '') {
                gia_log_event($db, $convId, 'search', ['module' => $mod, 'meta' => 'live_offers']);
            }
        } elseif ($name === 'create_checkout' && ($tr['output']['ok'] ?? false) && !empty($data['checkout_ref'])) {
            $checkout = [
                'checkout_ref' => (string) $data['checkout_ref'],
                'amount'       => (float) ($data['amount'] ?? 0),
                'currency'     => (string) ($data['currency'] ?? ''),
                'expires_at'   => (string) ($data['expires_at'] ?? ''),
            ];
            if ($logEvent) {
                gia_log_event($db, $convId, 'checkout', [
                    'ref'      => $checkout['checkout_ref'],
                    'amount'   => $checkout['amount'],
                    'currency' => $checkout['currency'],
                ]);
            }
        } elseif ($name === 'request_booking_approval' && ($tr['output']['ok'] ?? false) && !empty($data['approval_token'])) {
            $approval = [
                'checkout_ref'   => (string) ($data['checkout_ref'] ?? ''),
                'approval_token' => (string) $data['approval_token'],
                'expires_at'     => (string) ($data['expires_at'] ?? ''),
            ];
            if ($logEvent) {
                gia_log_event($db, $convId, 'approve', ['ref' => $approval['checkout_ref']]);
            }
        } elseif ($name === 'initiate_payment' && ($tr['output']['ok'] ?? false) && !empty($data['redirect_url'])) {
            $payment = [
                'invoice_id'   => (string) ($data['invoice_id'] ?? ''),
                'redirect_url' => (string) $data['redirect_url'],
                'amount'       => (float) ($data['amount'] ?? 0),
                'currency'     => (string) ($data['currency'] ?? ''),
                'already_paid' => (bool) ($data['already_paid'] ?? false),
            ];
            if ($logEvent) {
                gia_log_event($db, $convId, 'pay', [
                    'ref'      => $payment['invoice_id'],
                    'amount'   => $payment['amount'],
                    'currency' => $payment['currency'],
                ]);
            }
        } elseif ($name === 'get_order_status' && ($tr['output']['ok'] ?? false)) {
            $orderStatus = [
                'state'        => (string) ($data['state'] ?? ''),
                'invoice_id'   => (string) ($data['invoice_id'] ?? ''),
                'pnr'          => (string) ($data['pnr'] ?? ''),
                'needs_ops'    => (bool) ($data['needs_ops'] ?? false),
            ];
            // A CONFIRMED order with a PNR is a real booking — the revenue-bearing event.
            if ($logEvent && $orderStatus['state'] === 'CONFIRMED' && $orderStatus['pnr'] !== '') {
                gia_log_event($db, $convId, 'book', [
                    'ref'      => $orderStatus['invoice_id'] !== '' ? $orderStatus['invoice_id'] : $orderStatus['pnr'],
                    'amount'   => isset($data['amount']) ? (float) $data['amount'] : null,
                    'currency' => (string) ($data['currency'] ?? ''),
                    'meta'     => $orderStatus['pnr'],
                ]);
            }
        }
    }

    // To the traveler, GIA is the assistant — the underlying provider/model, token
    // usage and raw errors are NEVER exposed. Those diagnostics go to ADMINS only.
    $isAdmin = strtolower((string) ($_SESSION['user_role'] ?? '')) === 'admin'
        || !empty($_SESSION['admin_logged_in']);

    $payloadOut = [
        'status'     => (bool) ($result['ok'] ?? false),
        'reply'      => (string) ($result['reply'] ?? ''),
        'error_code' => (string) ($result['error'] ?? ''),
        'searches'   => $searches,
        'checkout'   => $checkout,
        'approval'   => $approval,
        'payment'    => $payment,
        'order_status' => $orderStatus,
        // Lets the client tie follow-ups to this persistent conversation.
        'conv_key'   => (string) ($conv['conv_key'] ?? ''),
    ];
    if ($isAdmin) {
        // Admin-only diagnostics so an operator can see WHY GIA failed (e.g. the real
        // HTTP code + provider error) without it ever reaching a customer.
        $payloadOut['_admin'] = [
            'provider'  => (string) ($result['provider'] ?? ''),
            'model'     => (string) ($result['model'] ?? ''),
            'debug'     => (string) ($result['debug'] ?? ''),
            'usage'     => $result['usage'] ?? ['input_tokens' => 0, 'output_tokens' => 0],
            'tool_runs' => array_map(static function ($tr) {
                return ['name' => (string) ($tr['name'] ?? ''), 'ok' => (bool) ($tr['output']['ok'] ?? false)];
            }, is_array($result['tool_runs'] ?? null) ? $result['tool_runs'] : []),
        ];
    }
    $respond(200, $payloadOut);
});

// ============================================================================
// GET /api/gia/history — rehydrate the persistent conversation for THIS browser
// (and user, if logged in) so a new tab / refresh / another device resumes the
// same chat. Read-only; no provider call; no CSRF needed (returns only this
// browser's own transcript, which it already has client-side).
// ============================================================================
$router->get('/api/gia/history', function () use ($db) {
    header('Content-Type: application/json');
    $out = ['status' => true, 'messages' => [], 'conv_key' => ''];
    if (!function_exists('aiTripIsEnabled') || !aiTripIsEnabled($db)) {
        echo json_encode($out);
        return;
    }
    $aiDir = dirname(__DIR__, 2) . '/lib/ai/';
    require_once $aiDir . 'gia_analytics.php';
    require_once $aiDir . 'gia_crm.php';
    if (!function_exists('gia_browser_id')) {
        echo json_encode($out);
        return;
    }
    $browserId = gia_browser_id();
    $userId = (string) ($_SESSION['user_id'] ?? '');
    // Do NOT create a conversation on a read; only resume an existing one.
    try {
        $where = null;
        if ($userId !== '') {
            $where = ['user_id' => $userId, 'ORDER' => ['last_at' => 'DESC'], 'LIMIT' => 1];
        } elseif ($browserId !== '') {
            $where = ['browser_id' => $browserId, 'ORDER' => ['last_at' => 'DESC'], 'LIMIT' => 1];
        }
        if ($where !== null && function_exists('ensureGiaAnalyticsSchema')) {
            ensureGiaAnalyticsSchema($db);
            $conv = $db->get('gia_conversations', ['id', 'conv_key'], $where);
            if (is_array($conv) && !empty($conv['id'])) {
                $out['messages'] = gia_conv_history($db, (int) $conv['id'], 20);
                $out['conv_key'] = (string) ($conv['conv_key'] ?? '');
            }
        }
    } catch (\Throwable $e) {
        error_log('[gia] history route: ' . $e->getMessage());
    }
    echo json_encode($out);
});

// ============================================================================
// POST /api/gia/lead — capture a visitor's name/email/phone (soft, optional).
// Matches an existing user by email/phone (links) or creates a light lead row;
// never creates a full account. CSRF-guarded (state-changing). Fail-soft.
// ============================================================================
$router->post('/api/gia/lead', function () use ($db) {
    header('Content-Type: application/json');
    $respond = function (int $http, array $body): void {
        http_response_code($http);
        echo json_encode($body);
    };

    if (!function_exists('aiTripIsEnabled') || !aiTripIsEnabled($db)) {
        $respond(403, ['status' => false, 'message' => 'Unavailable.']);
        return;
    }

    $raw = file_get_contents('php://input') ?: '';
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        $input = [];
    }
    $csrf = (string) ($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (class_exists('CSRF') && method_exists('CSRF', 'validateToken') && !CSRF::validateToken($csrf)) {
        $respond(403, ['status' => false, 'message' => 'Your session expired. Please refresh.']);
        return;
    }

    $aiDir = dirname(__DIR__, 2) . '/lib/ai/';
    require_once $aiDir . 'gia_analytics.php';
    require_once $aiDir . 'gia_crm.php';
    if (!function_exists('gia_lead_capture') || !function_exists('gia_browser_id')) {
        $respond(200, ['status' => false, 'message' => 'Could not save right now.']);
        return;
    }

    $browserId = gia_browser_id();
    $userId = (string) ($_SESSION['user_id'] ?? '');
    $conv = gia_conv_get_or_create($db, $browserId, $userId, (string) ($input['context'] ?? ''), (string) ($input['surface'] ?? 'widget'));
    $convId = (int) ($conv['id'] ?? 0);

    $res = gia_lead_capture($db, $convId, [
        'name'    => (string) ($input['name'] ?? ''),
        'email'   => (string) ($input['email'] ?? ''),
        'phone'   => (string) ($input['phone'] ?? ''),
        'context' => (string) ($input['context'] ?? ''),
    ]);
    if (empty($res['ok'])) {
        $respond(200, ['status' => false, 'message' => 'Please share a valid email or phone.']);
        return;
    }
    $respond(200, [
        'status'  => true,
        'message' => 'Thanks — saved! I\'ll keep your trip details handy.',
    ]);
});

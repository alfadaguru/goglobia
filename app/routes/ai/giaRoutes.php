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
    require_once $aiDir . 'tools/createBookingTool.php';
    require_once $aiDir . 'tools/requestApprovalTool.php';
    require_once $aiDir . 'tools/initiatePaymentTool.php';
    require_once $aiDir . 'tools/getOrderStatusTool.php';
    require_once $aiDir . 'tools/requestHumanTool.php';
    require_once $aiDir . 'tools/umrahTools.php'; // DEDICATED umrah flow (own tools)
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

    // --- HUMAN HANDOFF: if a staff member has taken over this conversation, GIA
    //     pauses — we record the traveller's message for the agent to see, but do
    //     NOT call the model. The widget keeps polling /history for staff replies. ---
    if ($convId > 0 && (int) ($conv['staff_active'] ?? 0) === 1) {
        if (function_exists('gia_log_turn')) {
            gia_log_turn($db, $convId, $message, '', []); // store user msg only
        }
        $respond(200, [
            'status'       => true,
            'reply'        => 'Thanks — a member of our team is helping you on this chat and will reply here shortly.',
            'handoff'      => true,
            'staff_active' => true,
            'conv_key'     => (string) ($conv['conv_key'] ?? ''),
            'tool_runs'    => [],
        ]);
        return;
    }

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
        // Conversation handle so request_human can flag/alert the right chat.
        'conv_id'       => $convId,
        'conv_key'      => (string) ($conv['conv_key'] ?? ''),
        // The last search's params+suppliers per module, so fetch_live_offers can run
        // the live supplier lookup for a module the traveller searched earlier. Set by
        // the ROUTE from prior search_trip results — never model-supplied.
        'last_search_params' => (isset($_SESSION['gia_last_search']) && is_array($_SESSION['gia_last_search']))
            ? $_SESSION['gia_last_search'] : [],
    ];

    // --- Run the agent. ---
    try {
        $agent = new \App\lib\ai\giaAgent($db);
        // DEDICATED umrah tools FIRST — umrah uses its own flow, never search_trip.
        $agent->registerTool(new \App\lib\ai\umrahSearchTool());
        $agent->registerTool(new \App\lib\ai\umrahTiersTool());
        $agent->registerTool(new \App\lib\ai\umrahBookTool());
        $agent->registerTool(new \App\lib\ai\searchTripTool());
        $agent->registerTool(new \App\lib\ai\fetchLiveOffersTool());
        $agent->registerTool(new \App\lib\ai\selectOptionTool());
        $agent->registerTool(new \App\lib\ai\composeTripTool());
        $agent->registerTool(new \App\lib\ai\createCheckoutTool());
        $agent->registerTool(new \App\lib\ai\createBookingTool());
        $agent->registerTool(new \App\lib\ai\requestApprovalTool());
        $agent->registerTool(new \App\lib\ai\initiatePaymentTool());
        $agent->registerTool(new \App\lib\ai\getOrderStatusTool());
        $agent->registerTool(new \App\lib\ai\requestHumanTool());
        $agent->setToolContext($toolContext);
        // Personalize for a signed-in traveler: resolve safe facts (first name,
        // currency) from the users table and let GIA greet them by name + answer
        // "do you know me?" truthfully. Guests resolve to [] -> no-op. Fail-soft.
        if ($userId !== '' && function_exists('gia_user_profile')) {
            $agent->setUserProfile(gia_user_profile($db, $userId));
        }
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
        // DEDICATED umrah tools surface cards the same way search_trip does, so the
        // web frontend renders them with no special handling. umrah_search returns a
        // 'searches' block; umrah_tiers returns tiers for the picked departure.
        if (($name === 'umrah_search') && isset($data['searches']) && is_array($data['searches'])) {
            $searches = array_merge($searches, $data['searches']);
        } elseif ($name === 'umrah_tiers' && isset($data['umrah_tiers']) && is_array($data['umrah_tiers'])) {
            // Present tiers as pickable cards (one per tier), carrying departure_tier_id.
            $tierCards = [];
            foreach ($data['umrah_tiers'] as $t) {
                if (!is_array($t)) { continue; }
                $tierCards[] = [
                    'id'                => (string) ($t['departure_tier_id'] ?? ''),
                    'departure_tier_id' => (int) ($t['departure_tier_id'] ?? 0),
                    'kind'              => 'umrah',
                    'name'              => (string) ($t['tier_label'] ?? 'Tier'),
                    'title'             => (string) ($t['tier_label'] ?? 'Tier'),
                    'subtitle'          => trim((string) ($t['room_sharing'] ?? '')),
                    'price'             => (float) ($t['price'] ?? 0),
                    'currency'          => (string) ($t['currency'] ?? 'NGN'),
                    'availability'      => (string) ($t['availability'] ?? 'available'),
                    'inclusions'        => is_array($t['inclusions'] ?? null) ? $t['inclusions'] : [],
                ];
            }
            if ($tierCards !== []) {
                $searches[] = ['module' => 'umrah', 'title' => 'Umrah tiers', 'cards' => $tierCards];
            }
        } elseif ($name === 'search_trip' && isset($data['searches']) && is_array($data['searches'])) {
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
            $where = ['user_id' => $userId, 'closed' => 0, 'ORDER' => ['last_at' => 'DESC'], 'LIMIT' => 1];
        } elseif ($browserId !== '') {
            $where = ['browser_id' => $browserId, 'closed' => 0, 'ORDER' => ['last_at' => 'DESC'], 'LIMIT' => 1];
        }
        if ($where !== null && function_exists('ensureGiaAnalyticsSchema')) {
            ensureGiaAnalyticsSchema($db);
            $conv = $db->get('gia_conversations', ['id', 'conv_key', 'needs_human', 'staff_active'], $where);
            if (is_array($conv) && !empty($conv['id'])) {
                $out['messages'] = gia_conv_history($db, (int) $conv['id'], 40);
                $out['conv_key'] = (string) ($conv['conv_key'] ?? '');
                // Handoff state so the widget can show "a human is helping" + keep polling.
                $out['staff_active'] = (int) ($conv['staff_active'] ?? 0) === 1;
                $out['needs_human'] = (int) ($conv['needs_human'] ?? 0) === 1;
            }
        }
    } catch (\Throwable $e) {
        error_log('[gia] history route: ' . $e->getMessage());
    }
    echo json_encode($out);
});

// ============================================================================
// GET /api/gia/opener — the personalized opening line + context-aware shortcut
// chips for THIS browser/user. Replaces the widget's fully-static greeting:
// name-aware line + suggestions drawn from the traveler's OWN recent activity
// (last booking / last GIA module), with sensible fallbacks. Read-only; the
// widget shows an instant default first, then swaps in this result when it
// arrives — so there is no delay-to-first-bubble.
//   ?llm=1 permits ONE short agent-authored line (cached per user+module/day)
//          when there is real personalization to justify it.
// ============================================================================
$router->get('/api/gia/opener', function () use ($db) {
    header('Content-Type: application/json');
    $out = ['status' => true, 'greeting' => '', 'shortcuts' => [], 'source' => 'static'];
    if (!function_exists('aiTripIsEnabled') || !aiTripIsEnabled($db)) {
        echo json_encode($out);
        return;
    }
    $aiDir = dirname(__DIR__, 2) . '/lib/ai/';
    require_once $aiDir . 'gia_analytics.php';
    require_once $aiDir . 'gia_crm.php';
    require_once $aiDir . 'gia_opener.php';

    $browserId = function_exists('gia_browser_id') ? gia_browser_id() : '';
    $userId    = (string) ($_SESSION['user_id'] ?? '');
    $context   = (string) ($_GET['context'] ?? '');
    // Only allow the (paid, cached) authored line for signed-in travelers who opt
    // the widget into it — keeps anonymous page opens on the free, instant path.
    $allowLlm  = ($userId !== '') && ((string) ($_GET['llm'] ?? '') === '1');

    // COST GUARD: the LLM-authored opener is a real paid call (cached per
    // user+module/day, but ?context= varies the cache key). Throttle the authoring
    // path per user so a refresh/context loop can't fan out paid calls. On trip,
    // we DON'T error — we just fall back to the free static greeting.
    if ($allowLlm) {
        if (!class_exists('RateLimiter')) {
            $rlFile = dirname(__DIR__, 3) . '/modules/RateLimiter.php';
            if (is_file($rlFile)) { require_once $rlFile; }
        }
        if (class_exists('RateLimiter')) {
            try {
                $rl = new RateLimiter(dirname(__DIR__, 3) . '/app/cache/rate_limiter');
                // 6 authored openers / 10 min per user is ample for real page opens.
                if (!$rl->attempt('gia_opener:' . $userId, 6, 600)) {
                    $allowLlm = false;
                }
            } catch (\Throwable $e) {
                $allowLlm = false; // fail closed on the paid path only
            }
        }
    }

    try {
        $opener = gia_build_opener($db, $userId, $browserId, $context, $allowLlm);
        $out['greeting']  = (string) ($opener['greeting'] ?? '');
        $out['shortcuts'] = array_values((array) ($opener['shortcuts'] ?? []));
        $out['source']    = (string) ($opener['source'] ?? 'static');
    } catch (\Throwable $e) {
        error_log('[gia] opener route: ' . $e->getMessage());
    }
    echo json_encode($out);
});

// ============================================================================
// POST /api/gia/reset — start a fresh chat. Closes the active conversation for
// this browser/user so the NEXT message opens a brand-new conversation (new
// transcript), and clears the session cache. CSRF-guarded. Does not delete the
// old conversation (admin reports keep it); it just stops resuming it.
// ============================================================================
$router->post('/api/gia/reset', function () use ($db) {
    header('Content-Type: application/json');
    if (!function_exists('aiTripIsEnabled') || !aiTripIsEnabled($db)) {
        echo json_encode(['status' => true]);
        return;
    }
    $raw = file_get_contents('php://input') ?: '';
    $input = json_decode($raw, true);
    if (!is_array($input)) { $input = []; }
    $csrf = (string) ($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (class_exists('CSRF') && method_exists('CSRF', 'validateToken') && !CSRF::validateToken($csrf)) {
        http_response_code(403);
        echo json_encode(['status' => false, 'message' => 'Your session expired. Please refresh.']);
        return;
    }

    $aiDir = dirname(__DIR__, 2) . '/lib/ai/';
    require_once $aiDir . 'gia_analytics.php';
    require_once $aiDir . 'gia_crm.php';

    // Mark the current conversation ended for this browser/user so it is no longer
    // resumed; a new one is created on the next message. We "retire" by clearing the
    // browser_id link (so history/get-or-create won't match it) — the row + its
    // transcript stay intact for admin reports.
    try {
        if (function_exists('gia_browser_id') && function_exists('ensureGiaAnalyticsSchema')) {
            ensureGiaAnalyticsSchema($db);
            $browserId = gia_browser_id();
            $userId = (string) ($_SESSION['user_id'] ?? '');
            $where = null;
            if ($userId !== '') {
                $where = ['user_id' => $userId, 'ORDER' => ['last_at' => 'DESC'], 'LIMIT' => 1];
            } elseif ($browserId !== '') {
                $where = ['browser_id' => $browserId, 'ORDER' => ['last_at' => 'DESC'], 'LIMIT' => 1];
            }
            if ($where !== null) {
                $conv = $db->get('gia_conversations', ['id'], $where);
                if (is_array($conv) && !empty($conv['id'])) {
                    // Retire: mark closed so get-or-create/history won't resume it.
                    // The row + transcript stay intact (admin reports keep history).
                    $db->update('gia_conversations', ['closed' => 1], ['id' => (int) $conv['id']]);
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('[gia] reset: ' . $e->getMessage());
    }

    // Clear per-session caches so the next message is a clean slate.
    unset($_SESSION['gia_conv'], $_SESSION['gia_last_search'], $_SESSION['gia_live_offers']);
    echo json_encode(['status' => true]);
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

    // Nurture: follow up the lead with their options (email + optional WhatsApp).
    // Fail-soft + deduped inside the helper; never affects the response.
    require_once $aiDir . 'gia_notify.php';
    if (function_exists('App\\lib\\ai\\gia_nurture_lead') && !empty($res['lead_id'])) {
        try { \App\lib\ai\gia_nurture_lead($db, (int) $res['lead_id'], $convId); } catch (\Throwable $e) {}
    }

    $respond(200, [
        'status'  => true,
        'message' => 'Thanks — saved! I\'ll keep your trip details handy.',
    ]);
});

// ============================================================================
// VOICE — provider-grade speech, with a graceful browser fallback.
//
// These two endpoints UPGRADE GIA's voice to the admin's active AI provider when
// that provider genuinely supports it (OpenAI Whisper STT + OpenAI TTS; Gemini
// audio-in for STT). When it doesn't (Claude has no voice; Gemini TTS isn't GA;
// voice disabled; no key), they respond fallback:true and the widget uses the
// browser Web Speech API. Both are gated on the AI feature flag + voice_enabled,
// CSRF-checked, and rate-limited (voice calls are PAID provider requests).
// ============================================================================

// Shared voice gate: feature on + voice on + CSRF + rate limit. Returns the
// decoded JSON input on success, or null after already emitting the error JSON.
$__giaVoiceGate = function ($db, string $bucket, array $input) {
    $deny = function (int $http, array $body): void {
        http_response_code($http);
        echo json_encode($body);
    };

    // Feature + voice toggle.
    $aiDir = dirname(__DIR__, 2) . '/lib/ai/';
    require_once $aiDir . 'gia_voice.php';
    if (!function_exists('aiTripIsEnabled') || !aiTripIsEnabled($db) || !gia_voice_enabled($db)) {
        $deny(403, ['status' => false, 'fallback' => true, 'error_code' => 'VOICE_DISABLED']);
        return false;
    }

    // CSRF (JSON body or X-CSRF-TOKEN header).
    $csrf = (string) ($input['csrf_token'] ?? ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
    if (class_exists('CSRF') && method_exists('CSRF', 'validateToken') && !CSRF::validateToken($csrf)) {
        $deny(403, ['status' => false, 'fallback' => false, 'error_code' => 'CSRF_INVALID']);
        return false;
    }

    // Rate limit (voice = paid provider call). Tighter than chat.
    if (!class_exists('RateLimiter')) {
        $rlFile = dirname(__DIR__, 3) . '/modules/RateLimiter.php';
        if (is_file($rlFile)) { require_once $rlFile; }
    }
    if (class_exists('RateLimiter')) {
        $rl = new RateLimiter(dirname(__DIR__, 3) . '/app/cache/rate_limiter');
        $rlId = 'gia_voice_' . $bucket . ':' . $rl->getClientIdentifier();
        if (!$rl->attempt($rlId . ':burst', 10, 60) || !$rl->attempt($rlId . ':hour', 120, 3600)) {
            http_response_code(429);
            header('Retry-After: 60');
            echo json_encode(['status' => false, 'fallback' => true, 'error_code' => 'VOICE_RATE_LIMITED']);
            return false;
        }
    }
    return true;
};

// POST /api/gia/voice/transcribe — audio -> text via the active provider's STT.
// Accepts a multipart upload (field "audio") OR JSON {audio_base64, mime}.
// Response: {status, text, fallback, error_code}. fallback:true => use browser.
$router->post('/api/gia/voice/transcribe', function () use ($db, $__giaVoiceGate) {
    header('Content-Type: application/json');

    // Read audio either from a multipart file or a base64 JSON body.
    $bytes = '';
    $mime  = '';
    $input = [];
    if (!empty($_FILES['audio']) && is_uploaded_file($_FILES['audio']['tmp_name'] ?? '')) {
        $bytes = (string) @file_get_contents($_FILES['audio']['tmp_name']);
        $mime  = (string) ($_FILES['audio']['type'] ?? '');
        // CSRF for multipart comes via POST field or header.
        $input = ['csrf_token' => (string) ($_POST['csrf_token'] ?? '')];
    } else {
        $raw = file_get_contents('php://input') ?: '';
        $j = json_decode($raw, true);
        $input = is_array($j) ? $j : [];
        $b64 = (string) ($input['audio_base64'] ?? '');
        if ($b64 !== '') {
            $bytes = (string) base64_decode($b64, true);
        }
        $mime = (string) ($input['mime'] ?? '');
    }

    if (!$__giaVoiceGate($db, 'stt', $input)) {
        return;
    }

    // Cap audio size (defense-in-depth; Whisper's own limit is 25 MB).
    if (strlen($bytes) > 20 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['status' => false, 'fallback' => true, 'error_code' => 'VOICE_TOO_LARGE']);
        return;
    }

    $res = gia_voice_transcribe($db, $bytes, $mime);
    http_response_code($res['ok'] ? 200 : 200); // fallback is not an error to the client
    echo json_encode([
        'status'   => (bool) $res['ok'],
        'text'     => (string) $res['text'],
        'provider' => (string) $res['provider'],
        'fallback' => (bool) $res['fallback'],
        'error_code' => $res['ok'] ? '' : 'VOICE_STT_FAILED',
    ]);
});

// POST /api/gia/voice/speak — text -> audio via the active provider's TTS.
// JSON {text, voice?}. On success returns raw audio (audio/mpeg). When no server
// TTS is available, returns JSON {fallback:true} so the widget speaks in-browser.
$router->post('/api/gia/voice/speak', function () use ($db, $__giaVoiceGate) {
    $raw = file_get_contents('php://input') ?: '';
    $input = json_decode($raw, true);
    if (!is_array($input)) { $input = []; }

    if (!$__giaVoiceGate($db, 'tts', $input)) {
        header('Content-Type: application/json');
        return;
    }

    $text  = (string) ($input['text'] ?? '');
    $voice = (string) ($input['voice'] ?? '');
    $res   = gia_voice_speak($db, $text, $voice);

    if (!empty($res['ok']) && $res['audio'] !== '') {
        header('Content-Type: ' . ($res['mime'] !== '' ? $res['mime'] : 'audio/mpeg'));
        header('Content-Length: ' . strlen($res['audio']));
        header('Cache-Control: no-store');
        echo $res['audio'];
        return;
    }

    // No server TTS (or failure) -> tell the widget to use the browser voice.
    header('Content-Type: application/json');
    echo json_encode([
        'status'   => false,
        'fallback' => (bool) ($res['fallback'] ?? true),
        'error_code' => 'VOICE_TTS_FAILED',
    ]);
});

// GET /api/gia/voice/capabilities — tiny read endpoint so the widget can refresh
// its STT/TTS capability at runtime (also inlined into the widget config). No
// CSRF (read-only, exposes only on/off booleans — never the provider key).
$router->get('/api/gia/voice/capabilities', function () use ($db) {
    header('Content-Type: application/json');
    $aiDir = dirname(__DIR__, 2) . '/lib/ai/';
    require_once $aiDir . 'gia_voice.php';
    $caps = gia_voice_capabilities($db);
    // Do NOT leak the concrete provider name to anonymous visitors beyond what the
    // widget needs (stt/tts booleans + voice list are harmless).
    echo json_encode([
        'status'    => true,
        'enabled'   => (bool) $caps['enabled'],
        'stt'       => (bool) $caps['stt'],
        'tts'       => (bool) $caps['tts'],
        'voices'    => $caps['voices'],
        'tts_voice' => (string) $caps['tts_voice'],
        'speed'     => (float) ($caps['speed'] ?? 1.0),
        'autospeak' => (bool) ($caps['autospeak'] ?? false),
    ]);
});

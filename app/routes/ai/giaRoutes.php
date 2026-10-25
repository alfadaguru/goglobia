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
    // A turn may legitimately run long (a multi-city flight search has the supplier
    // poll up to ~90s + a large transfer, then a model round-trip). GIA's own ceiling
    // is MAX_WALL_SECONDS (150s); make sure the web server's PHP limit doesn't kill the
    // request before that. 180s = GIA ceiling + headroom. Safe: GIA self-bounds anyway.
    @set_time_limit(180);

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
    require_once $aiDir . 'tools/userAccountTool.php';   // full account awareness (all bookings/wallet/loyalty)
    require_once $aiDir . 'tools/myTravellersTool.php';  // saved travellers for reuse (never re-type)
    require_once $aiDir . 'tools/updateProfileTool.php'; // allow-listed profile update
    require_once $aiDir . 'tools/registerAccountTool.php'; // passwordless phone-OTP account signup
    require_once $aiDir . 'tools/umrahTools.php'; // DEDICATED umrah flow (own tools)
    require_once $aiDir . 'tools/flightsTools.php'; // DEDICATED flights flow (own tools)
    require_once $aiDir . 'tools/staysTools.php'; // DEDICATED stays flow (own tools)
    require_once $aiDir . 'gia_trace.php'; // step tracer (breadcrumb log for diagnosis)
    require_once $aiDir . 'gia_pending_travellers.php'; // pilgrim-collection engine
    require_once $aiDir . 'gia_pt_web.php';             // web-native pilgrim handlers
    require_once $aiDir . 'gia_account.php';            // full account awareness (all bookings/wallet/loyalty)
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

    // PERSONALISATION FALLBACK (Phase 6): if the PHP session has no user_id (e.g.
    // it expired, or the widget is embedded where the session cookie isn't shared)
    // but THIS conversation is already linked to an account, treat that linked
    // user as the account for personalisation + account tools. The link is only
    // ever set by a trusted path (login at capture time, or a verified channel
    // link), so this cannot be spoofed by the client. Falls back to lead-less
    // guest when neither exists.
    $effectiveUserId = $userId !== '' ? $userId : (string) ($conv['user_id'] ?? '');

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
        'account_id'    => $effectiveUserId,
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

    if (function_exists('gia_trace')) {
        \gia_trace('chat_in', __FILE__, 'surface=WEB conv=' . $convId . ' msg="' . mb_substr($message, 0, 80) . '"');
    }

    // --- UMRAH PILGRIM COLLECTION (web) intercept ---------------------------
    //     If this web conversation is mid pilgrim-collection (a held umrah booking
    //     is waiting for passports), a TYPED message is handled deterministically
    //     by the collection engine BEFORE the model — exactly like the channel
    //     dispatcher does. The model never runs during collection, so it can't
    //     hallucinate the step or move money. Passport PHOTOS come via the separate
    //     /api/gia/umrah/passport upload endpoint. Once the manifest is complete we
    //     surface the two pay options (bank transfer / card) in the response. ---
    $ptConvKey = (string) ($conv['conv_key'] ?? '');
    if ($ptConvKey !== '' && \App\lib\ai\gia_pt_web_active($db, $ptConvKey) !== null) {
        $pt = \App\lib\ai\gia_pt_web_handle_text($db, $ptConvKey, $message);
        if (is_array($pt)) {
            if ($convId > 0 && function_exists('gia_log_turn')) {
                gia_log_turn($db, $convId, $message, (string) ($pt['reply'] ?? ''), []);
            }
            $out = [
                'status'     => true,
                'reply'      => (string) ($pt['reply'] ?? ''),
                'error_code' => '',
                'searches'   => [],
                'checkout'   => null,
                'approval'   => null,
                'payment'    => null,
                'order_status' => null,
                'collecting' => !($pt['done'] ?? false),   // widget keeps the upload control visible
                'conv_key'   => $ptConvKey,
            ];
            // Manifest complete -> attach the two GIA-native pay options.
            if (!empty($pt['done']) && (string) ($pt['invoice_id'] ?? '') !== '') {
                $out['pay_options'] = gia_web_pay_options((string) $pt['invoice_id']);
            }
            $respond(200, $out);
            return;
        }
    }

    // --- Run the agent. ---
    try {
        $agent = new \App\lib\ai\giaAgent($db);
        // DEDICATED umrah tools FIRST — umrah uses its own flow, never search_trip.
        $agent->registerTool(new \App\lib\ai\umrahSearchTool());
        $agent->registerTool(new \App\lib\ai\umrahTiersTool());
        $agent->registerTool(new \App\lib\ai\umrahBookTool());
        // DEDICATED flights tools — flights use their own flow, never search_trip.
        $agent->registerTool(new \App\lib\ai\flightsSearchTool());
        $agent->registerTool(new \App\lib\ai\flightsSelectTool());
        $agent->registerTool(new \App\lib\ai\flightsBookTool());
        $agent->registerTool(new \App\lib\ai\flightsAddPassengerTool());
        // DEDICATED stays tools — stays use their own flow, never search_trip.
        $agent->registerTool(new \App\lib\ai\staysSearchTool());
        $agent->registerTool(new \App\lib\ai\staysRoomsTool());
        $agent->registerTool(new \App\lib\ai\staysBookTool());
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
        $agent->registerTool(new \App\lib\ai\userAccountTool());
        $agent->registerTool(new \App\lib\ai\myTravellersTool());
        $agent->registerTool(new \App\lib\ai\updateProfileTool());
        $agent->registerTool(new \App\lib\ai\registerAccountTool());
        $agent->setToolContext($toolContext);
        // Personalize for a signed-in traveler: resolve safe facts (first name,
        // currency) from the users table and let GIA greet them by name + answer
        // "do you know me?" truthfully. Guests resolve to [] -> no-op. Fail-soft.
        if ($effectiveUserId !== '' && function_exists('gia_user_profile')) {
            $agent->setUserProfile(gia_user_profile($db, $effectiveUserId));
        }

        // ACCOUNT AWARENESS: give GIA a one-line head-start on the signed-in
        // traveller's upcoming trips so it can be proactively helpful ("you have
        // a flight on the 3rd — want to check in?"). Full detail is on demand via
        // the get_my_account tool. Cheap single read; fail-soft; guests skipped.
        // Uses $effectiveUserId so a session-less but account-linked conversation
        // still gets personalised (Phase 6).
        if ($effectiveUserId !== '' && function_exists('gia_user_snapshot')) {
            try {
                $__snap = gia_user_snapshot($db, $effectiveUserId);
                $__line = function_exists('gia_user_summary_line') ? gia_user_summary_line($__snap) : '';
                if ($__line !== '') {
                    $agent->addSystemNote('ACCOUNT CONTEXT (trusted): ' . $__line
                        . ' Use the get_my_account tool for full booking/wallet/loyalty detail when asked. '
                        . 'Never invent bookings or balances.');
                }
            } catch (\Throwable $e) {
                error_log('[gia] account awareness note: ' . $e->getMessage());
            }
        }

        // --- AD-CAMPAIGN ATTRIBUTION (Support P4, spec §7) -------------------
        //     If this visitor arrived from a registered ad, resolve the campaign,
        //     first-touch stamp it onto the conversation, and hand GIA a short
        //     campaign-aware note so it acknowledges the offer. Conversion is
        //     tracked per campaign (ad -> chat -> held -> paid) off the stamped
        //     campaign_id. EVERYTHING here is function_exists-guarded and wrapped
        //     in try/catch: a campaigns bug must NEVER break the chat (fail-soft).
        try {
            if (!function_exists('ad_campaign_resolve') && is_file(root . 'app/lib/support/campaigns.php')) {
                require_once root . 'app/lib/support/campaigns.php';
            }
            if (function_exists('ad_campaign_resolve')) {
                // Source params: prefer src params carried on THIS chat POST (the
                // widget passthrough), else the landing cookie / live $_GET.
                $srcParams = [];
                if (isset($input['src']) && is_array($input['src'])) {
                    foreach (['utm_source', 'utm_campaign', 'utm_medium', 'fbclid', 'gclid', 'ttclid'] as $k) {
                        if (isset($input['src'][$k]) && is_string($input['src'][$k]) && $input['src'][$k] !== '') {
                            $srcParams[$k] = substr($input['src'][$k], 0, 191);
                        }
                    }
                }
                if (!$srcParams && function_exists('gia_landing_source')) {
                    $srcParams = gia_landing_source();
                }
                if (!$srcParams && function_exists('ad_campaign_capture_from_request')) {
                    $srcParams = ad_campaign_capture_from_request();
                }

                // A campaign already stamped on the conv wins (first-touch persists).
                $campaign = null;
                $existingCid = (int) ($conv['campaign_id'] ?? 0);
                if ($existingCid > 0 && function_exists('ad_campaign_resolve')) {
                    try {
                        $campaign = $db->get('gia_ad_campaigns', '*', ['id' => $existingCid]);
                        if (!is_array($campaign)) { $campaign = null; }
                    } catch (\Throwable $e) { $campaign = null; }
                }
                if ($campaign === null && $srcParams) {
                    $campaign = ad_campaign_resolve($db, $srcParams);
                    // First-touch: stamp only if the conv has no campaign yet.
                    if (is_array($campaign) && !empty($campaign['id'])
                        && $existingCid <= 0 && $convId > 0
                        && function_exists('ad_campaign_stamp_conversation')) {
                        ad_campaign_stamp_conversation($db, $convId, (int) $campaign['id']);
                    }
                }

                // Make GIA campaign-aware for this attributed conversation.
                if (is_array($campaign) && !empty($campaign['id'])
                    && function_exists('ad_campaign_system_note')) {
                    $agent->addSystemNote(ad_campaign_system_note($campaign));
                }
            }
        } catch (\Throwable $e) {
            error_log('[gia] ad-campaign attribution: ' . $e->getMessage());
        }

        // CRITICAL — release the PHP session lock before running the agent. A tool
        // like flights_search loops back over HTTP to /modules/flights/<supplier>/
        // search, whose front controller (modules/index.php) calls session_start().
        // PHP locks a session exclusively, so that sub-request blocks waiting for
        // THIS request to release it — a self-deadlock that stalls the whole turn
        // until the 90s timeout (observed: adivaha loopback http=0 after 90s, while
        // a direct curl to the same gateway returns fares in ~9s). We've finished
        // READING the session (CSRF checked, history + context already built), and
        // we reopen it after the agent returns to persist the turn. Everything the
        // tools need is in $toolContext, not $_SESSION.
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        $result = $agent->run($message, $history);
        // Reopen so the post-run $_SESSION writes (transcript cache, last_search) land.
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
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
    $collectingUmrah = false; // true when umrah_book just started pilgrim collection
    $payOptions = null;       // {invoice_id, options[]} when a manifest just completed (flights)
    foreach (($result['tool_runs'] ?? []) as $tr) {
        $name = (string) ($tr['name'] ?? '');
        $data = (isset($tr['output']['data']) && is_array($tr['output']['data'])) ? $tr['output']['data'] : [];
        $logEvent = ($convId > 0 && function_exists('gia_log_event'));
        // DEDICATED umrah tools surface cards the same way search_trip does, so the
        // web frontend renders them with no special handling. umrah_search returns a
        // 'searches' block; umrah_tiers returns tiers for the picked departure.
        if (($name === 'umrah_search') && isset($data['searches']) && is_array($data['searches'])) {
            $searches = array_merge($searches, $data['searches']);
        } elseif ($name === 'flights_search' && isset($data['searches']) && is_array($data['searches'])) {
            // Dedicated flights fares render as the same pickable cards the UI knows.
            $searches = array_merge($searches, $data['searches']);
        } elseif ($name === 'flights_add_passenger' && ($tr['output']['ok'] ?? false) && !empty($data['needs_payment']) && !empty($data['invoice_id'])) {
            // Flight manifest complete -> surface the two GIA-native pay options,
            // exactly like umrah once its pilgrims are done.
            $payOptions = gia_web_pay_options((string) $data['invoice_id']);
        } elseif (($name === 'stays_search' || $name === 'stays_rooms') && isset($data['searches']) && is_array($data['searches'])) {
            // Dedicated stays: properties (stays_search) and rooms (stays_rooms) both
            // render as pickable cards the UI already knows.
            $searches = array_merge($searches, $data['searches']);
        } elseif ($name === 'stays_book' && ($tr['output']['ok'] ?? false) && !empty($data['needs_payment']) && !empty($data['invoice_id'])) {
            // Stays needs no passport/passenger manifest — a held room goes straight
            // to the two GIA-native pay options.
            $payOptions = gia_web_pay_options((string) $data['invoice_id']);
        } elseif ($name === 'umrah_book' && ($tr['output']['ok'] ?? false)
                  && (string) ($data['module'] ?? '') === 'umrah'
                  && !empty($data['needs_travellers']) && !empty($data['invoice_id'])) {
            // A held, UNPAID umrah booking was just created. Umrah needs every
            // pilgrim's passport BEFORE payment (Saudi visa), so START pilgrim
            // collection for this web conversation and ask for the first passport.
            // Pay options are DEFERRED until the manifest is complete (mirrors the
            // channel flow). The widget shows the passport-upload control while
            // 'collecting' is true.
            $ptKey = (string) ($conv['conv_key'] ?? '');
            $uBkId = (int) ($data['umrah_booking_id'] ?? 0);
            $uPax  = (int) ($data['pax'] ?? 0);
            // If the book was REUSED (double-book guard) a collection is already live
            // for this conversation — DON'T call gia_pt_start again, it supersedes the
            // row and resets passport progress to 0. Just keep showing the collector.
            $reusedBooking = !empty($tr['output']['reused']);
            $alreadyCollecting = ($ptKey !== '' && function_exists('gia_pt_active')
                && is_array(gia_pt_active($db, $ptKey)));
            if ($ptKey !== '' && $uBkId > 0 && $uPax > 0 && function_exists('gia_pt_start')
                && !($reusedBooking || $alreadyCollecting)) {
                gia_pt_start($db, $ptKey, 'umrah', (string) $data['invoice_id'], $uBkId, $uPax);
                $collectingUmrah = true; // flagged into the response below
            } elseif ($reusedBooking || $alreadyCollecting) {
                $collectingUmrah = true; // collection already live — keep the control visible
            }
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
        // True right after umrah_book: a held booking is now collecting pilgrim
        // passports — the widget shows the passport-upload control.
        'collecting' => $collectingUmrah,
        // Set when a manifest just completed (e.g. flights passengers all added):
        // the widget renders the two GIA-native pay options for this invoice.
        'pay_options' => $payOptions,
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

// ----------------------------------------------------------------------------
// gia_web_pay_options() — the two chat-native payment choices the WEB widget
// renders once an umrah manifest is complete. We do NOT create the Paystack VA /
// checkout here — that happens on the user's TAP (POST /api/gia/umrah/pay), so a
// dead-end booking never provisions a virtual account. This just describes the
// buttons the widget shows. Kept a plain function (no DB) so both the intercept
// and the confirm path can reuse it.
// ----------------------------------------------------------------------------
if (!function_exists('gia_web_pay_options')) {
    function gia_web_pay_options(string $invoiceId): array
    {
        return [
            'invoice_id' => $invoiceId,
            'options'    => [
                ['id' => 'paybank', 'label' => 'Pay by bank transfer', 'desc' => 'Get a dedicated Nigerian account number to transfer to.'],
                ['id' => 'paycard', 'label' => 'Pay by card / online', 'desc' => 'Pay securely with card, bank, USSD and more.'],
            ],
        ];
    }
}

// ============================================================================
// POST /api/gia/umrah/passport — upload ONE pilgrim's passport photo (web).
// Mirrors the WhatsApp "send a passport photo" step: OCR the image, show the
// traveller what was read, and await a typed YES (sent through /api/gia/chat,
// handled by the pilgrim-collection intercept). Only works when THIS web
// conversation is actively collecting pilgrims for a held umrah booking.
// ============================================================================
$router->post('/api/gia/umrah/passport', function () use ($db) {
    header('Content-Type: application/json');
    $respond = function (int $http, array $body): void { http_response_code($http); echo json_encode($body); };

    if (!function_exists('aiTripIsEnabled') || !aiTripIsEnabled($db)) {
        $respond(403, ['ok' => false, 'reply' => "I can't take passports right now.", 'error_code' => 'AI_DISABLED']);
        return;
    }
    // CSRF (multipart: token in POST field or header).
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (class_exists('CSRF') && method_exists('CSRF', 'validateToken') && !CSRF::validateToken($csrf)) {
        $respond(403, ['ok' => false, 'reply' => 'Your session expired. Please refresh and try again.', 'error_code' => 'CSRF_INVALID']);
        return;
    }
    // Rate-limit uploads (OCR is a paid call). Tighter than chat: 12/min, 60/hour.
    if (!class_exists('RateLimiter')) {
        $rlFile = dirname(__DIR__, 3) . '/modules/RateLimiter.php';
        if (is_file($rlFile)) { require_once $rlFile; }
    }
    if (class_exists('RateLimiter')) {
        $rl = new RateLimiter(dirname(__DIR__, 3) . '/app/cache/rate_limiter');
        $rlId = 'gia_passport:' . $rl->getClientIdentifier();
        if (!$rl->attempt($rlId . ':burst', 12, 60) || !$rl->attempt($rlId . ':hour', 60, 3600)) {
            $respond(429, ['ok' => false, 'reply' => 'Too many uploads too fast — please wait a moment.', 'error_code' => 'AI_RATE_LIMITED']);
            header('Retry-After: 60');
            return;
        }
    }

    $file = $_FILES['passport_image'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        $respond(400, ['ok' => false, 'reply' => 'No passport image was uploaded. Please choose a photo of the data page.', 'error_code' => 'AI_NO_FILE']);
        return;
    }
    // Bound size (6 MB) + allow only image types (OCR providers accept jpg/png/webp).
    if ((int) ($file['size'] ?? 0) > 6 * 1024 * 1024) {
        $respond(400, ['ok' => false, 'reply' => 'That image is too large (max 6 MB). Please upload a smaller photo.', 'error_code' => 'AI_FILE_TOO_BIG']);
        return;
    }
    $bytes = (string) file_get_contents($file['tmp_name']);
    $mime  = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) { $mime = (string) finfo_file($fi, $file['tmp_name']); finfo_close($fi); }
    }
    if ($mime === '') { $mime = (string) ($file['type'] ?? 'image/jpeg'); }
    if (strpos($mime, 'image/') !== 0) {
        $respond(400, ['ok' => false, 'reply' => 'Please upload an image (JPG/PNG/WEBP) of the passport data page.', 'error_code' => 'AI_BAD_TYPE']);
        return;
    }

    // Resolve THIS web conversation (same key the chat route uses) so the upload
    // attaches to the right collection.
    $aiDir = dirname(__DIR__, 2) . '/lib/ai/';
    require_once $aiDir . 'gia_analytics.php';
    require_once $aiDir . 'gia_crm.php';
    require_once $aiDir . 'gia_pending_travellers.php';
    require_once $aiDir . 'gia_pt_web.php';
    $browserId = function_exists('gia_browser_id') ? gia_browser_id() : '';
    $userId = (string) ($_SESSION['user_id'] ?? '');
    $conv = function_exists('gia_conv_get_or_create')
        ? gia_conv_get_or_create($db, $browserId, $userId, 'umrah', 'widget') : null;
    $convKey = (string) ($conv['conv_key'] ?? '');
    $convId  = (int) ($conv['id'] ?? 0);
    if ($convKey === '' || \App\lib\ai\gia_pt_web_active($db, $convKey) === null) {
        $respond(409, ['ok' => false, 'reply' => "There's no booking waiting for passports right now.", 'error_code' => 'AI_PT_NONE']);
        return;
    }

    $res = \App\lib\ai\gia_pt_web_handle_image($db, $convKey, $bytes, $mime);
    if ($convId > 0 && function_exists('gia_log_turn')) {
        gia_log_turn($db, $convId, '[passport photo uploaded]', (string) ($res['reply'] ?? ''), []);
    }
    $respond(200, [
        'ok'         => (bool) ($res['ok'] ?? false),
        'reply'      => (string) ($res['reply'] ?? ''),
        'collecting' => true, // still collecting until a typed YES completes the manifest
        'conv_key'   => $convKey,
    ]);
});

// ============================================================================
// POST /api/gia/umrah/pay — the user tapped one of the two pay options for a
// completed umrah manifest (web). 'paybank' -> Paystack dedicated virtual account
// (bank transfer); 'paycard' -> Paystack hosted checkout page. Both are created
// deterministically here (never by the model) via the channel-agnostic payment
// engine. Returns the account details or the checkout URL for the widget.
// ============================================================================
$router->post('/api/gia/umrah/pay', function () use ($db) {
    header('Content-Type: application/json');
    $respond = function (int $http, array $body): void { http_response_code($http); echo json_encode($body); };

    if (!function_exists('aiTripIsEnabled') || !aiTripIsEnabled($db)) {
        $respond(403, ['ok' => false, 'message' => "Payments aren't available right now.", 'error_code' => 'AI_DISABLED']);
        return;
    }
    $raw = file_get_contents('php://input') ?: '';
    $in = json_decode($raw, true);
    if (!is_array($in)) { $in = []; }
    $csrf = (string) ($in['csrf_token'] ?? ($_POST['csrf_token'] ?? ''));
    if (class_exists('CSRF') && method_exists('CSRF', 'validateToken') && !CSRF::validateToken($csrf)) {
        $respond(403, ['ok' => false, 'message' => 'Your session expired. Please refresh and try again.', 'error_code' => 'CSRF_INVALID']);
        return;
    }

    $kind      = (string) ($in['option'] ?? '');       // 'paybank' | 'paycard'
    $invoiceId = (string) ($in['invoice_id'] ?? '');
    if (!in_array($kind, ['paybank', 'paycard'], true) || $invoiceId === '' || !preg_match('/^[A-Za-z0-9\-]{4,64}$/', $invoiceId)) {
        $respond(400, ['ok' => false, 'message' => 'Invalid payment request.', 'error_code' => 'AI_BAD_PAY_REQ']);
        return;
    }

    // SAFETY: only let a user pay a booking whose pilgrim manifest is COMPLETE, and
    // only one that THIS web conversation is actually handling — never an arbitrary
    // invoice id. We bind by resolving this conversation's active collection (or the
    // just-completed one) to the invoice.
    $aiDir = dirname(__DIR__, 2) . '/lib/ai/';
    require_once $aiDir . 'gia_analytics.php';
    require_once $aiDir . 'gia_crm.php';
    require_once $aiDir . 'gia_pending_travellers.php';
    require_once $aiDir . 'gia_booking_payment.php';
    $browserId = function_exists('gia_browser_id') ? gia_browser_id() : '';
    $userId = (string) ($_SESSION['user_id'] ?? '');
    $conv = function_exists('gia_conv_get_or_create')
        ? gia_conv_get_or_create($db, $browserId, $userId, 'umrah', 'widget') : null;
    $convKey = (string) ($conv['conv_key'] ?? '');
    // Prove the invoice belongs to THIS conversation, then confirm its manifest is
    // complete, before provisioning payment — for EITHER a dedicated umrah booking
    // (pilgrim passports) OR a dedicated flights booking (passenger details).
    $ownedComplete = false;
    // (a) UMRAH: a gia_pending_travellers row for this conv + invoice, manifest done.
    if ($convKey !== '') {
        try {
            $own = $db->get('gia_pending_travellers', '*',
                ['conv_key' => $convKey, 'invoice_id' => $invoiceId, 'ORDER' => ['id' => 'DESC']]);
        } catch (\Throwable $e) { $own = null; }
        if (is_array($own)) {
            $uBkId = (int) ($own['umrah_booking_id'] ?? 0);
            if ($uBkId > 0 && function_exists('gia_pt_is_complete') && gia_pt_is_complete($db, $uBkId)) {
                $ownedComplete = true;
            } else {
                $respond(409, ['ok' => false, 'message' => "I still need all pilgrims' passports before payment.", 'error_code' => 'AI_PAY_INCOMPLETE']);
                return;
            }
        }
    }
    // (b) FLIGHTS: an ai_trip booking stamped with this conv_key + a complete
    //     passenger manifest (gia_flights_manifest_complete).
    if (!$ownedComplete) {
        require_once $aiDir . 'gia_flights.php';
        try {
            $fb = $db->get('bookings', ['booking_data', 'module_type', 'payment_status'], ['invoice_id' => $invoiceId]);
        } catch (\Throwable $e) { $fb = null; }
        if (is_array($fb) && strtolower((string) ($fb['module_type'] ?? '')) === 'ai_trip') {
            $bd = json_decode((string) ($fb['booking_data'] ?? ''), true);
            $bookedConv = is_array($bd) ? (string) ($bd['gia_conv_key'] ?? '') : '';
            $giaSvc = is_array($bd) ? strtolower((string) ($bd['gia_service'] ?? '')) : '';
            if ($convKey !== '' && $bookedConv !== '' && hash_equals($bookedConv, $convKey)) {
                if ($giaSvc === 'stays') {
                    // STAYS has no passenger manifest — a conv-owned, unpaid held room
                    // is ready to pay as soon as it's booked.
                    $ownedComplete = true;
                } elseif (function_exists('App\\lib\\ai\\gia_flights_manifest_complete') && \App\lib\ai\gia_flights_manifest_complete($db, $invoiceId)) {
                    $ownedComplete = true;
                } else {
                    $respond(409, ['ok' => false, 'message' => "I still need every passenger's details before payment.", 'error_code' => 'AI_PAY_INCOMPLETE']);
                    return;
                }
            }
        }
    }
    if (!$ownedComplete) {
        $respond(409, ['ok' => false, 'message' => "I can't find that booking on this chat.", 'error_code' => 'AI_PAY_NOT_OURS']);
        return;
    }

    $email = '';
    if ($userId !== '') {
        try { $u = $db->get('users', ['email'], ['id' => $userId]); $email = (string) ($u['email'] ?? ''); }
        catch (\Throwable $e) { $email = ''; }
    }

    try {
        if ($kind === 'paybank') {
            if (!function_exists('gia_bp_create_dva')) {
                $respond(500, ['ok' => false, 'message' => 'Bank transfer is unavailable right now.', 'error_code' => 'AI_PAY_UNAVAIL']);
                return;
            }
            $r = gia_bp_create_dva($db, $invoiceId, 'web', $email);
            if (empty($r['ok'])) {
                $respond(200, ['ok' => false, 'message' => (string) ($r['message'] ?? "I couldn't set up the transfer account. You can pay by card instead."), 'error_code' => 'AI_PAY_DVA']);
                return;
            }
            $respond(200, [
                'ok'             => true,
                'method'         => 'bank',
                'account_number' => (string) ($r['account_number'] ?? ''),
                'bank_name'      => (string) ($r['bank_name'] ?? ''),
                'account_name'   => (string) ($r['account_name'] ?? ''),
                'amount'         => (float) ($r['amount'] ?? 0),
                'currency'       => (string) ($r['currency'] ?? 'NGN'),
                'expires_at'     => (string) ($r['expires_at'] ?? ''),
            ]);
            return;
        }
        // paycard -> hosted checkout page.
        if (!function_exists('gia_bp_create_checkout')) {
            $respond(500, ['ok' => false, 'message' => 'Card payment is unavailable right now.', 'error_code' => 'AI_PAY_UNAVAIL']);
            return;
        }
        $r = gia_bp_create_checkout($db, $invoiceId, 'web', $email);
        if (empty($r['ok']) || empty($r['authorization_url'])) {
            $respond(200, ['ok' => false, 'message' => (string) ($r['message'] ?? "I couldn't start the online payment. Please try again."), 'error_code' => 'AI_PAY_CHECKOUT']);
            return;
        }
        $respond(200, [
            'ok'           => true,
            'method'       => 'card',
            'redirect_url' => (string) $r['authorization_url'],
            'amount'       => (float) ($r['amount'] ?? 0),
            'currency'     => (string) ($r['currency'] ?? ''),
            'expires_at'   => (string) ($r['expires_at'] ?? ''),
        ]);
    } catch (\Throwable $e) {
        error_log('[gia] web pay ' . $kind . ': ' . $e->getMessage());
        $respond(500, ['ok' => false, 'message' => 'Payment setup hit an error. Please try again.', 'error_code' => 'AI_PAY_ERROR']);
    }
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

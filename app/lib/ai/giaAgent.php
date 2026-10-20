<?php

namespace App\lib\ai;

/**
 * GIA agent runtime — the DETERMINISTIC executor (BRD §10.3 planner/executor
 * separation, §10.6 agent reliability & safety).
 *
 * Responsibilities:
 *  - Resolve the active provider/key from settings.ai_configuration
 *    (passportAiActiveProviderConfig) — the model never holds the key.
 *  - Run the bounded model → tool_use → execute → feed-result → model loop.
 *  - NEVER let the model execute a tool that isn't registered, or whose input
 *    fails a basic schema check, or whose risk class exceeds the ceiling.
 *  - Bound turns / tool-calls / wall-time (no uncontrolled recursion).
 *  - Collect the raw tool outputs (e.g. live search intents) for the UI, separate
 *    from the model's natural-language reply.
 *
 * Phase A is READ-ONLY: the only registered tool is search_trip (R0). No money /
 * state-changing tools exist yet; the risk ceiling is R1.
 */
class giaAgent
{
    /** Hard safety bounds (BRD §10.6 "no uncontrolled autonomous recursion"). */
    private const MAX_TURNS = 6;        // model round-trips per user message
    private const MAX_TOOL_CALLS = 8;   // total tool executions per user message
    private const MAX_WALL_SECONDS = 90;

    /** Base risk ceiling (no money): R0/R1 read, R2 reversible controlled-write
     *  (reprice, freeze snapshot, record approval). R3 (start a real payment) is
     *  allowed ONLY when the trusted route context carries user_approved === true —
     *  i.e. a real identity-backed approve action, never the model's say-so
     *  (BRD §10.5). R4+ (issue/cancel/refund) stays forbidden here; GIA reads the
     *  authoritative state rather than performing those. See effectiveRiskCeiling(). */
    private const BASE_RISK_CEILING = ['R0', 'R1', 'R2'];

    private $db;
    private giaChatProvider $provider;
    /** @var array<string,giaToolInterface> */
    private array $tools = [];
    private string $systemPrompt;
    /** Extra per-request context passed to every tool (account_id, user_approved, …).
     *  Set ONLY by the trusted route layer — never by the model. */
    private array $toolContext = [];

    public function __construct($db, ?giaChatProvider $provider = null)
    {
        $this->db = $db;
        $this->provider = $provider ?? new giaChatProvider();
        $this->systemPrompt = $this->defaultSystemPrompt();
    }

    public function registerTool(giaToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /** Trusted context the route supplies (e.g. account_id, user_approved flag). */
    public function setToolContext(array $context): void
    {
        $this->toolContext = $context;
    }

    public function setSystemPrompt(string $prompt): void
    {
        if (trim($prompt) !== '') {
            $this->systemPrompt = $prompt;
        }
    }

    /**
     * Run one user turn. $history is the prior canonical messages (may be empty).
     *
     * @return array{
     *   ok:bool, reply:string, provider:string, model:string, stop:string,
     *   tool_runs:array<int,array>, messages:array, usage:array, error?:string
     * }
     */
    public function run(string $userMessage, array $history = []): array
    {
        $config = function_exists('passportAiActiveProviderConfig')
            ? \passportAiActiveProviderConfig($this->db)
            : null;
        if (!is_array($config) || trim((string) ($config['api_key'] ?? '')) === '') {
            // Log the real reason for operators; the traveler just hears GIA is resting.
            error_log('[gia] not configured: no active AI provider/key resolved');
            return $this->fail("I'm just getting set up and can't chat just yet. Please check back soon!", 'AI_NOT_CONFIGURED');
        }

        $messages = $history;
        $messages[] = ['role' => 'user', 'text' => $userMessage];

        $tools = array_values($this->tools);
        $toolRuns = [];
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];
        $startedAt = microtime(true);
        $provider = '';
        $model = '';

        for ($turn = 0; $turn < self::MAX_TURNS; $turn++) {
            if ((microtime(true) - $startedAt) > self::MAX_WALL_SECONDS) {
                return $this->partial($messages, $toolRuns, $usage, 'GIA timed out while working on this. Please try again.', 'AI_TIMEOUT', $provider, $model);
            }

            $resp = $this->provider->chat($messages, $tools, $config, $this->systemPrompt);
            $provider = (string) ($resp['provider'] ?? $provider);
            $model = (string) ($resp['model'] ?? $model);
            $usage['input_tokens']  += (int) ($resp['usage']['input_tokens'] ?? 0);
            $usage['output_tokens'] += (int) ($resp['usage']['output_tokens'] ?? 0);

            if (!($resp['ok'] ?? false)) {
                // Raw provider detail (http + error) is logged AND carried as `debug`
                // for the route to expose to ADMINS ONLY — travelers never see it.
                $rawDetail = 'http=' . (int) ($resp['http'] ?? 0) . ' ' . (string) ($resp['error'] ?? '');
                return $this->partial($messages, $toolRuns, $usage, $this->userFacingError($resp), 'AI_PROVIDER_ERROR', $provider, $model, $rawDetail);
            }

            // Record the assistant turn (text + any tool calls) into history.
            $assistant = [
                'role' => 'assistant',
                'text' => (string) ($resp['text'] ?? ''),
            ];
            if (!empty($resp['tool_calls'])) {
                $assistant['tool_calls'] = $resp['tool_calls'];
            }
            $messages[] = $assistant;

            // Model answered with no tool call -> done.
            if (($resp['stop'] ?? 'end') !== 'tool_use' || empty($resp['tool_calls'])) {
                return [
                    'ok' => true,
                    'reply' => (string) ($resp['text'] ?? ''),
                    'provider' => $provider,
                    'model' => $model,
                    'stop' => 'end',
                    'tool_runs' => $toolRuns,
                    'messages' => $messages,
                    'usage' => $usage,
                ];
            }

            // Execute each requested tool, deterministically & guarded.
            $results = [];
            foreach ($resp['tool_calls'] as $call) {
                if (count($toolRuns) >= self::MAX_TOOL_CALLS) {
                    $results[] = $this->toolResultBlock($call, ['ok' => false, 'error_code' => 'AI_TOOL_BUDGET', 'message' => 'Tool-call budget exhausted for this turn.']);
                    continue;
                }
                $out = $this->executeTool($call);
                $toolRuns[] = [
                    'name'  => (string) ($call['name'] ?? ''),
                    'input' => is_array($call['input'] ?? null) ? $call['input'] : [],
                    'output' => $out,
                ];
                $results[] = $this->toolResultBlock($call, $out);
            }

            // Feed the tool results back and loop for the model's next step.
            $messages[] = ['role' => 'tool', 'tool_results' => $results];
        }

        // Hit the turn ceiling without a final text answer.
        return $this->partial($messages, $toolRuns, $usage, 'I gathered some results but could not finish composing a reply. Please refine your request.', 'AI_MAX_TURNS', $provider, $model);
    }

    /** Validate + run a single tool call. The model can NEVER bypass these checks. */
    private function executeTool(array $call): array
    {
        $name = (string) ($call['name'] ?? '');
        $input = is_array($call['input'] ?? null) ? $call['input'] : [];

        $tool = $this->tools[$name] ?? null;
        if ($tool === null) {
            return ['ok' => false, 'error_code' => 'AI_UNKNOWN_TOOL', 'message' => 'Unknown tool: ' . $name];
        }
        if (!in_array($tool->riskClass(), $this->effectiveRiskCeiling(), true)) {
            // Defense in depth: a tool above the current ceiling can never run — e.g.
            // a payment (R3) is blocked unless the route supplied a real user approval.
            return ['ok' => false, 'error_code' => 'AI_TOOL_FORBIDDEN', 'message' => 'This action is not permitted without your explicit approval.'];
        }
        $schemaError = $this->validateAgainstSchema($input, $tool->inputSchema());
        if ($schemaError !== '') {
            return ['ok' => false, 'error_code' => 'AI_TOOL_INPUT_INVALID', 'message' => $schemaError];
        }

        try {
            // Trusted route context (account_id, user_approved, …) is merged in with
            // db; it comes from the route, NOT the model, so a tool like
            // request_booking_approval cannot be tricked into self-approving.
            $ctx = array_merge($this->toolContext, ['db' => $this->db]);
            $out = $tool->run($input, $ctx);
            return is_array($out) ? $out : ['ok' => false, 'error_code' => 'AI_TOOL_BAD_OUTPUT', 'message' => 'Tool returned no result.'];
        } catch (\Throwable $e) {
            error_log('[gia] tool ' . $name . ' threw: ' . $e->getMessage());
            return ['ok' => false, 'error_code' => 'AI_TOOL_ERROR', 'message' => 'That action failed. Please try again.'];
        }
    }

    /**
     * The risk ceiling for THIS request. R3 (start a real payment) is permitted only
     * when the trusted route context carries user_approved === true — a real,
     * identity-backed approve action. The model cannot raise its own ceiling: this
     * reads $this->toolContext (set by the route), never tool input. R4+ never.
     */
    private function effectiveRiskCeiling(): array
    {
        $ceiling = self::BASE_RISK_CEILING;
        if (($this->toolContext['user_approved'] ?? null) === true) {
            $ceiling[] = 'R3';
        }
        return $ceiling;
    }

    /** Minimal deterministic JSON-Schema check: required keys + top-level types. */
    private function validateAgainstSchema(array $input, array $schema): string
    {
        foreach (($schema['required'] ?? []) as $req) {
            if (!array_key_exists($req, $input) || $input[$req] === null || $input[$req] === '') {
                return 'Missing required field: ' . $req;
            }
        }
        $props = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ($input as $k => $v) {
            if (!isset($props[$k]['type'])) {
                continue; // unknown property — ignore rather than reject
            }
            $t = $props[$k]['type'];
            $ok = match ($t) {
                'string'  => is_string($v),
                'integer' => is_int($v),
                'number'  => is_int($v) || is_float($v),
                'boolean' => is_bool($v),
                'array'   => is_array($v),
                'object'  => is_array($v),
                default   => true,
            };
            if (!$ok) {
                return 'Field "' . $k . '" must be of type ' . $t . '.';
            }
        }
        return '';
    }

    /** Pack a tool's result into the canonical tool_result block for the provider. */
    private function toolResultBlock(array $call, array $out): array
    {
        return [
            'id'     => (string) ($call['id'] ?? ''),
            'name'   => (string) ($call['name'] ?? ''),
            // The model sees a compact view; heavy raw payloads stay out of context.
            'output' => $this->compactToolOutput($out),
        ];
    }

    /** Keep the model-facing tool result small (BRD §10.2 minimize context). */
    private function compactToolOutput(array $out): array
    {
        $compact = [
            'ok'         => (bool) ($out['ok'] ?? false),
            'message'    => (string) ($out['message'] ?? ''),
            'error_code' => (string) ($out['error_code'] ?? ''),
        ];
        if (isset($out['data']) && is_array($out['data'])) {
            $d = $out['data'];
            $compact['module']       = (string) ($d['module'] ?? '');
            $compact['modules']      = array_slice(is_array($d['modules'] ?? null) ? $d['modules'] : [], 0, 12);
            $compact['hint']         = (string) ($d['hint'] ?? '');
            $compact['search_count'] = (int) ($d['search_count'] ?? 0);
            // Give the model a SMALL, grounded slice of the REAL options so it can
            // suggest specifics ("the 7-day package is ₦850k, best value") instead of
            // only knowing a count. The full payload still goes to the HTTP caller for
            // rich-card rendering; here we send at most a few items, trimmed to the
            // fields a model needs to talk about an option. Field-tolerant: works for
            // real result cards AND intent-only entries (which just carry title/subtitle).
            if (isset($d['searches']) && is_array($d['searches'])) {
                $compact['options'] = $this->modelFacingOptions($d['searches']);
            }
        }
        return $compact;
    }

    /**
     * Build the small, model-facing list of REAL options from the raw search
     * payload. The model only needs enough to name an option and talk price — it
     * does NOT need images, slugs, full descriptions or supplier internals (those
     * stay in the full payload for the UI). We cap hard so context stays small.
     *
     * Each `searches[]` entry is per-module; once a module fetches real inventory
     * it carries a `cards` (or `items`/`results`) array of bookable options. An
     * intent-only entry has no cards — we still surface its title/subtitle so the
     * model can at least describe what it searched. Field-tolerant by design.
     *
     * @param array<int,mixed> $searches
     * @return array<int,array<string,mixed>>
     */
    private function modelFacingOptions(array $searches): array
    {
        $out = [];
        $perModuleCap = 4;   // at most N real options per module block
        $totalCap = 10;      // and never more than this across all modules

        foreach ($searches as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $module = (string) ($entry['module'] ?? $entry['kind'] ?? '');

            // Find the real result list under whichever key the module used.
            $cards = null;
            foreach (['cards', 'items', 'results', 'offers', 'options'] as $k) {
                if (isset($entry[$k]) && is_array($entry[$k]) && $entry[$k] !== []) {
                    $cards = $entry[$k];
                    break;
                }
            }

            if ($cards === null) {
                // Intent-only (no inline inventory for this module — e.g. a
                // live-supplier deep-link like flights): still tell the model what
                // was searched so it isn't blind and can describe the hand-off.
                $info = [
                    'module'   => $module,
                    'title'    => (string) ($entry['title'] ?? ''),
                    'subtitle' => (string) ($entry['subtitle'] ?? ''),
                    'has_results' => false,
                    'deep_link' => true,
                ];
                // A flight entry carries a structured summary of the trip GIA set up;
                // surface its key facts so GIA can confirm them in its reply.
                if (isset($entry['summary']) && is_array($entry['summary'])) {
                    $s = $entry['summary'];
                    $info['trip'] = array_filter([
                        'route'      => trim((string) ($s['origin_label'] ?? $s['origin_code'] ?? '')
                                        . ' → ' . (string) ($s['destination_label'] ?? $s['destination_code'] ?? '')),
                        'trip_type'  => (string) ($s['trip_label'] ?? ''),
                        'depart'     => (string) ($s['departure_date'] ?? ''),
                        'return'     => (string) ($s['return_date'] ?? ''),
                        'travellers' => (string) ($s['travellers'] ?? ''),
                        'cabin'      => (string) ($s['cabin'] ?? ''),
                    ], static fn($v) => $v !== '' && $v !== null);
                }
                $out[] = array_filter($info, static fn($v) => $v !== '' && $v !== null && $v !== []);
                if (count($out) >= $totalCap) {
                    break;
                }
                continue;
            }

            $n = 0;
            foreach ($cards as $card) {
                if (!is_array($card)) {
                    continue;
                }
                $out[] = $this->trimOption($module, $card);
                $n++;
                if ($n >= $perModuleCap || count($out) >= $totalCap) {
                    break;
                }
            }
            if (count($out) >= $totalCap) {
                break;
            }
        }

        return $out;
    }

    /** Trim one real result card to the handful of fields a model talks about. */
    private function trimOption(string $module, array $card): array
    {
        $title = (string) ($card['name'] ?? $card['title'] ?? '');
        $subtitle = (string) ($card['subtitle'] ?? $card['location'] ?? '');
        // Price: prefer an explicit total/display price, else the lead adult price.
        $price = $card['price']
            ?? $card['displayTotal']
            ?? $card['total']
            ?? $card['adult_price']
            ?? $card['amount']
            ?? null;
        $currency = (string) ($card['currency'] ?? '');

        $opt = [
            'module'      => $module,
            'title'       => $title,
            'has_results' => true,
        ];
        if ($subtitle !== '') {
            $opt['subtitle'] = $subtitle;
        }
        if ($price !== null && $price !== '') {
            $opt['price'] = is_numeric($price) ? (float) $price : (string) $price;
        }
        if ($currency !== '') {
            $opt['currency'] = $currency;
        }
        // A stable id/slug lets the model refer to a specific option in follow-ups.
        $ref = (string) ($card['id'] ?? $card['slug'] ?? $card['ref'] ?? '');
        if ($ref !== '') {
            $opt['ref'] = $ref;
        }
        return $opt;
    }

    private function defaultSystemPrompt(): string
    {
        // BRD §7 experience principles + §10.5/§10.6 safety.
        // Tone: warm, search-first, proactive — GIA owns its own identity. The
        // SAFETY rules below are non-negotiable and unchanged in substance.
        return "You are GIA — GoGlobia's own travel agent. You are warm, upbeat and genuinely "
            . "helpful, like the best human travel agent a traveler has ever had. You are GIA, by "
            . "GoGlobia; you don't discuss what powers you under the hood — if asked which AI or model "
            . "you are, simply say you're GIA, GoGlobia's travel assistant, built to help them plan and "
            . "book, and get right back to helping. Keep replies short, friendly and specific. Don't "
            . "stack emoji; at most one, and only when it fits.\n"
            . "WHAT WE OFFER: GoGlobia books the FULL range of travel — flights, hotels/stays, tours, "
            . "car hire, visas, eSIMs, ferries, rail, bus AND Umrah. Treat them all as equals; do NOT "
            . "assume or over-emphasise any one service. Umrah is just one of many — most travellers come "
            . "for flights, hotels and the rest, so never make Umrah the headline unless the traveller "
            . "raised it. Your opening greeting should be warm and OPEN-ENDED ('where would you like to "
            . "go, or what can I help you book?') — do not list Umrah as a featured example.\n"
            . "YOUR MISSION: be the kind of travel assistant so helpful and so on-top-of-it that people "
            . "happily book. You are NOT a pushy salesperson and you never pressure — you convert by "
            . "being genuinely useful, showing great real options fast, and making the next step easy. "
            . "Every reply should move the traveler one small step closer to a trip they're excited about, "
            . "and should end with a clear, low-friction next step. Never leave them at a dead end.\n"
            . "SEARCH IMMEDIATELY — DO NOT INTERROGATE: The instant you can tell what service they want, "
            . "call search_trip RIGHT NOW and show real options. Do NOT ask a question first when a search "
            . "would answer it. Fill in sensible defaults silently (nearest sensible date, 1 adult) and "
            . "mention them briefly so they can change them — never withhold results waiting for details. "
            . "Examples: 'flights to Dubai' -> search NOW; 'hotels in Lagos' -> search NOW; 'a visa for the "
            . "UK' -> search NOW; 'umrah' -> search NOW; 'I want to travel' -> ask ONE friendly question "
            . "about what kind of trip, then search. "
            . "ORIGIN-INDEPENDENT SERVICES: some services (e.g. Umrah packages, visas, tours, hotels) are "
            . "chosen by destination/dates/type, NOT by where the traveller departs from — so do NOT ask "
            . "'where are you departing from?' before showing them; just show the options. Departure city "
            . "only matters for flights. The ONLY time you ask before searching is when you genuinely "
            . "cannot tell which service they mean. One question at a time, never a checklist.\n"
            . "USING RESULTS — RECOMMEND LIKE A TRUSTED ADVISOR: search_trip returns real options with "
            . "names and prices, AND the interface shows them to the traveller as rich cards right below "
            . "your message. So DO NOT re-list every option or repeat all the prices — the cards already "
            . "do that. Instead add the value a human agent would: in a sentence or two, point to a best "
            . "pick by NAME with a genuine reason ('the 7-day Simple Umrah is great value at that price', "
            . "'the Premium one suits a fuller Ramadan stay'), and invite the next step. You MAY use "
            . "HONEST, real encouragement (popular / few departures) ONLY if it is true from the tool "
            . "data — never invent scarcity, ratings, demand or deadlines. If they hesitate, help (a "
            . "cheaper option, a different date, answer the worry) rather than push. Always close with "
            . "one easy next step ('want me to set up the 7-day one?', 'shall I pull up the details?'). "
            . "Guided and confident, never pressured.\n"
            . "FORMATTING — PLAIN, SHORT, SCANNABLE: Reply in short plain sentences. NEVER use markdown "
            . "tables (no '|' columns — they render as ugly raw text here) and avoid headings. A couple "
            . "of short lines, or at most a few '- ' bullets, is plenty. Don't restate the card list; "
            . "keep it conversational and brief.\n"
            . "TOOLS &amp; BOOKING FLOW (follow this order to complete a booking): "
            . "(a) search_trip — find options. For umrah/stays/tours/bus/visa this returns individual "
            . "pickable cards. For flights/cars/rail/ferries/esim it returns an overview only. "
            . "(b) fetch_live_offers — for flights/cars/rail/ferries/esim, call this (with the module) "
            . "when the traveler wants to actually choose one; it returns individual offers with prices "
            . "and a ref each. (c) select_option — when the traveler picks a specific option, start a "
            . "booking draft: pass its module + id/ref (the card id for local modules, or the ref from "
            . "fetch_live_offers for live ones). (d) reprice_trip — confirm the live price of that draft "
            . "(ALWAYS before checkout; the earlier price is not final — and for flights do this again "
            . "right before approval as fares are time-sensitive). (e) create_checkout — freeze the "
            . "repriced total. (f) request_booking_approval — AFTER they tap Approve in the interface. "
            . "(g) initiate_payment — begin the real payment. (h) get_order_status — check the real "
            . "booking state. Full flow: (search_trip [+ fetch_live_offers for live modules]) -> pick -> "
            . "select_option -> reprice_trip -> create_checkout -> (tap Approve) -> "
            . "request_booking_approval -> initiate_payment -> get_order_status. All 10 services can book "
            . "in-chat this way. If a live fare has expired by approval time, reprice will say so — "
            . "warmly offer to pull fresh options.\n"
            . "TALKING TO A HUMAN: if the traveler asks to speak to a person, is frustrated, or needs "
            . "something you genuinely cannot do, call request_human (with a short reason). It alerts the "
            . "team; then reassure them a team member will join this chat shortly and offer to take their "
            . "email/phone so staff can reach them. Don't overuse it — try to help first.\n"
            . "SAFETY (never bend these): (1) Never invent flights, hotels, prices, availability, "
            . "bookings or confirmations — only state what a tool actually returned. If a search came "
            . "back empty, say so warmly and offer an alternative. (2) Before any checkout, reprice so "
            . "the total is current; if the price changed, show the new total clearly. (3) You may NOT "
            . "approve on the traveler's behalf. Only call request_booking_approval when they have "
            . "explicitly approved the exact amount via the interface; if they just said 'ok' in chat, "
            . "warmly ask them to tap Approve. (4) initiate_payment starts a REAL payment and only works "
            . "after a valid approval — it begins payment, it does NOT confirm the booking. "
            . "(5) get_order_status is the ONLY source of truth for whether a booking is confirmed. "
            . "NEVER say a booking is confirmed, ticketed or paid unless get_order_status returns that "
            . "state with evidence (a PNR). If the status is 'partial' or 'failed', tell the traveler "
            . "clearly that part of the trip did not book and needs support — never claim everything is "
            . "booked. (6) Always show the exact amount, currency and what it covers before asking for "
            . "approval.";
    }

    /**
     * USER-FACING copy only. GIA speaks for itself — it never mentions a provider,
     * model, API key, settings or any internal plumbing (that would both confuse
     * travelers and leak implementation). The real HTTP code + raw error are logged
     * via partial()'s $rawError for operators; the traveler just hears from GIA.
     */
    private function userFacingError(array $resp): string
    {
        $http = (int) ($resp['http'] ?? 0);
        if ($http === 429) {
            return "I'm getting a lot of requests right now — give me a moment and try again.";
        }
        // Everything else (auth, network, bad request, timeout) is a momentary GIA hiccup
        // as far as the traveler is concerned.
        return "Sorry, I'm having a little trouble at the moment. Please try again in a few seconds.";
    }

    private function fail(string $message, string $code): array
    {
        return [
            'ok' => false, 'reply' => $message, 'provider' => '', 'model' => '',
            'stop' => 'error', 'tool_runs' => [], 'messages' => [],
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0], 'error' => $code,
        ];
    }

    private function partial(array $messages, array $toolRuns, array $usage, string $message, string $code, string $provider, string $model, string $rawError = ''): array
    {
        if ($rawError !== '') {
            error_log('[gia] ' . $code . ': ' . $rawError);
        }
        return [
            'ok' => false, 'reply' => $message, 'provider' => $provider, 'model' => $model,
            'stop' => 'error', 'tool_runs' => $toolRuns, 'messages' => $messages,
            'usage' => $usage, 'error' => $code,
            'debug' => $rawError, // admin-only; the route strips this for non-admins
        ];
    }
}

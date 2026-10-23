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
     * Append a short trusted instruction to the active system prompt (e.g. a
     * channel's bot-disclosure requirement). Trusted/caller-supplied only — never
     * model- or user-derived. Empty => no-op.
     */
    public function addSystemNote(string $note): void
    {
        $note = trim($note);
        if ($note !== '') {
            $this->systemPrompt .= "\n" . $note;
        }
    }

    /**
     * Personalize the agent for a KNOWN traveler. The route/adapter resolves the
     * logged-in (or matched) user to safe facts via gia_user_profile() and passes
     * them here; we append a short, trusted "who you're talking to" block to the
     * active system prompt so GIA greets them by name and answers "do you know me?"
     * truthfully. Channel-agnostic: web + every messaging adapter use this identically.
     *
     * Trusted input only — the caller derives this from the session/verified identity,
     * never from the model or the user's typed text. Empty array => no-op (guest).
     *
     * @param array{first_name?:string,full_name?:string,currency?:string,role?:string} $profile
     */
    public function setUserProfile(array $profile): void
    {
        $first = trim((string) ($profile['first_name'] ?? ''));
        $cur   = trim((string) ($profile['currency'] ?? ''));
        if ($first === '' && $cur === '') {
            return; // nothing personalizing to add
        }
        $facts = [];
        if ($first !== '') {
            $facts[] = "Their first name is {$first} — greet them by name naturally (don't overuse it) "
                     . "and, if they ask whether you know them, warmly confirm you do.";
        }
        if ($cur !== '') {
            $facts[] = "Their preferred currency is {$cur}; prefer it when showing prices.";
        }
        $block = "\nABOUT THIS TRAVELER (trusted — they are signed in to GoGlobia): "
               . implode(' ', $facts)
               . " Never reveal account internals, ids or anything beyond what helps the conversation.";
        $this->systemPrompt .= $block;
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

            // Model answered with no tool call -> done. Run the containment guard on
            // the final text so a fabricated price can never reach the traveller.
            if (($resp['stop'] ?? 'end') !== 'tool_use' || empty($resp['tool_calls'])) {
                $guarded = $this->guardReplyContainment((string) ($resp['text'] ?? ''), $toolRuns);
                return [
                    'ok' => true,
                    'reply' => $guarded['reply'],
                    'reply_flagged' => $guarded['flagged'],
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
                $callName = (string) ($call['name'] ?? '');
                // IN-TURN CONTEXT FEED: a search_trip result carries each module's
                // params+suppliers. fetch_live_offers reads these from toolContext
                // (not from model input), so merge them NOW — otherwise the model
                // could not run the live lookup in the SAME turn as the search (the
                // route only refreshes the session cache AFTER the turn). This makes
                // "search then show live options" work in one turn, on every channel.
                if ($callName === 'search_trip' && !empty($out['ok']) && isset($out['data']['searches']) && is_array($out['data']['searches'])) {
                    if (!isset($this->toolContext['last_search_params']) || !is_array($this->toolContext['last_search_params'])) {
                        $this->toolContext['last_search_params'] = [];
                    }
                    foreach ($out['data']['searches'] as $sr) {
                        if (!is_array($sr)) { continue; }
                        $mod = (string) ($sr['module'] ?? ($sr['kind'] ?? ''));
                        if ($mod === '') { continue; }
                        $this->toolContext['last_search_params'][$mod] = [
                            'params'    => is_array($sr['params'] ?? null) ? $sr['params'] : [],
                            'suppliers' => is_array($sr['suppliers'] ?? null) ? $sr['suppliers'] : [],
                        ];
                    }
                }
                $toolRuns[] = [
                    'name'  => $callName,
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
            // UMRAH TIERS: the dedicated umrah_tiers tool returns its priced tiers
            // under 'umrah_tiers' (NOT 'searches'), so without this the model saw a
            // result with ok:true but ZERO options it could describe — and then told
            // the traveller "no bookable tiers" even though the backend returned one.
            // Surface the tiers (and the departure) in a compact, model-facing shape
            // so GIA can actually name and price them.
            if (isset($d['umrah_tiers']) && is_array($d['umrah_tiers'])) {
                $compact['umrah_tiers'] = array_slice(array_map(static function ($t) {
                    return [
                        'departure_tier_id' => (int) ($t['departure_tier_id'] ?? 0),
                        'tier_label'        => (string) ($t['tier_label'] ?? 'Standard'),
                        'room_sharing'      => (string) ($t['room_sharing'] ?? ''),
                        'price'             => $t['price'] ?? ($t['unit_price'] ?? null),
                        'currency'          => (string) ($t['currency'] ?? 'NGN'),
                        'availability'      => (string) ($t['availability'] ?? 'available'),
                    ];
                }, $d['umrah_tiers']), 0, 10);
                $compact['tier_count'] = count($d['umrah_tiers']);
                if (isset($d['departure']) && is_array($d['departure'])) {
                    $compact['departure'] = [
                        'departure_id'   => (int) ($d['departure']['departure_id'] ?? 0),
                        'origin_city'    => (string) ($d['departure']['origin_city'] ?? ''),
                        'departure_date' => (string) ($d['departure']['departure_date'] ?? ''),
                        'return_date'    => (string) ($d['departure']['return_date'] ?? ''),
                    ];
                }
            }

            // STAYS ROOMS: select_option on a property returns bookable rooms under
            // 'stays_rooms' (same shape of bug umrah_tiers had — the model was blind
            // to them). Surface a trimmed, model-facing list so GIA can name a room +
            // carry its ref (stayroom:stay:room:option) into create_booking.
            if (isset($d['stays_rooms']) && is_array($d['stays_rooms'])) {
                $rooms = [];
                foreach ($d['stays_rooms'] as $rm) {
                    if (!is_array($rm)) { continue; }
                    foreach (($rm['options'] ?? []) as $o) {
                        if (!is_array($o)) { continue; }
                        $rooms[] = [
                            'ref'       => 'stayroom:' . (int) ($d['stay_id'] ?? 0) . ':' . (int) ($rm['room_id'] ?? 0) . ':' . (int) ($o['option_id'] ?? 0),
                            'room_name' => (string) ($rm['room_name'] ?? 'Room'),
                            'board'     => (string) ($o['board'] ?? ''),
                            'price'     => $o['price'] ?? null,
                            'currency'  => (string) ($o['currency'] ?? ($d['currency'] ?? '')),
                            'free_cancellation' => !empty($o['free_cancellation']),
                        ];
                        if (count($rooms) >= 12) { break 2; }
                    }
                }
                $compact['stays_rooms'] = $rooms;
                if (!empty($d['need_dates'])) { $compact['need_dates'] = true; }
                if (isset($d['property'])) { $compact['property'] = (string) $d['property']; }
            }

            // LIVE OFFERS: fetch_live_offers returns bookable offers under 'offers'
            // (and/or 'cards'), NOT 'searches', so modelFacingOptions never ran on
            // them — the model knew only a count. Surface a trimmed list with refs.
            $liveList = is_array($d['offers'] ?? null) ? $d['offers'] : (is_array($d['cards'] ?? null) ? $d['cards'] : null);
            if ($liveList !== null && !isset($compact['options'])) {
                $compact['options'] = $this->modelFacingOptions([[
                    'module' => (string) ($d['module'] ?? ''),
                    'cards'  => $liveList,
                ]]);
            }

            // BOOKING-CHAIN HANDLES + STATE: the model must pass these refs/tokens
            // FORWARD as inputs to the next tool (draft_hash -> reprice -> checkout
            // -> booking -> approval -> payment), and must read order state to speak
            // truthfully about confirmation. compactToolOutput previously dropped ALL
            // of them, so the chain was unexecutable and GIA couldn't state what it
            // created. Pass the meaningful scalars through verbatim when present.
            foreach ([
                'draft_hash', 'checkout_ref', 'approval_token', 'invoice_id', 'booking_ref',
                'umrah_booking_id', 'redirect_url', 'next', 'state', 'pnr',
            ] as $k) {
                if (isset($d[$k]) && (is_scalar($d[$k]))) { $compact[$k] = $d[$k]; }
            }
            // Money + status scalars the model may legitimately state (containment
            // still validates any amount GIA quotes against the raw tool output).
            foreach (['amount', 'amount_due_now', 'total', 'old_total', 'currency'] as $k) {
                if (isset($d[$k]) && is_numeric($d[$k])) { $compact[$k] = $d[$k]; }
            }
            if (isset($d['currency']) && is_string($d['currency'])) { $compact['currency'] = (string) $d['currency']; }
            foreach (['price_changed', 'already_paid', 'needs_travellers', 'needs_ops', 'time_sensitive'] as $k) {
                if (isset($d[$k])) { $compact[$k] = (bool) $d[$k]; }
            }
            if (isset($d['installments']) && is_array($d['installments'])) {
                $compact['installments'] = array_slice($d['installments'], 0, 6);
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

    /**
     * CONTAINMENT REPLY GUARD. The model is instructed never to invent products or
     * prices, but instruction-following is best-effort — so this is the CODE that
     * enforces it before a reply ever reaches the traveller. It focuses on the
     * highest-harm lie: a fabricated PRICE. Every monetary figure in the reply must
     * correspond to a real price the tools returned this turn (or an amount the
     * traveller/checkout already established). If the reply quotes a price that is
     * NOT backed by tool data, we log a hallucination alert and neutralise the reply
     * rather than let a made-up price reach the customer.
     *
     * Deliberately conservative: it only acts on monetary figures (unambiguous,
     * high-harm), never on general prose, so it cannot mangle a legitimate answer.
     *
     * @param array<int,array<string,mixed>> $toolRuns
     * @return array{reply:string,flagged:bool}
     */
    private function guardReplyContainment(string $reply, array $toolRuns): array
    {
        if (trim($reply) === '') {
            return ['reply' => $reply, 'flagged' => false];
        }

        // 1) Collect every REAL numeric amount the tools surfaced this turn — card
        //    prices, checkout/approval/payment amounts, order totals. These are the
        //    only figures the model is allowed to state.
        $realAmounts = [];
        $addAmount = static function ($v) use (&$realAmounts): void {
            if ($v === null || $v === '' || !is_numeric($v)) { return; }
            $realAmounts[] = round((float) $v, 2);
        };
        $scan = function ($node) use (&$scan, $addAmount): void {
            if (is_array($node)) {
                foreach ($node as $k => $v) {
                    if (is_array($v)) { $scan($v); continue; }
                    $key = is_string($k) ? strtolower($k) : '';
                    // Only treat money-ish keys as real amounts (avoid ids/counts).
                    if (preg_match('/(price|amount|total|fare|cost|due|deposit|balance|payable|subtotal)/', $key)) {
                        $addAmount($v);
                    }
                }
            }
        };
        foreach ($toolRuns as $tr) {
            if (is_array($tr['output'] ?? null)) { $scan($tr['output']); }
        }

        // 2) Pull every monetary figure the MODEL stated. Match "NGN 1,617,250.67",
        //    "₦58,400", "$500", "150000 USD", etc. — a currency marker adjacent to a
        //    number. Bare numbers (dates, counts, "2 adults") are ignored.
        $curr = '(?:NGN|USD|GHS|KES|ZAR|GBP|EUR|₦|\$|£|€)';
        $num  = '\d[\d,]*(?:\.\d+)?';
        $pattern = '/(?:' . $curr . '\s?' . $num . ')|(?:' . $num . '\s?' . $curr . ')/u';
        if (!preg_match_all($pattern, $reply, $m) || empty($m[0])) {
            return ['reply' => $reply, 'flagged' => false]; // no money quoted -> nothing to check
        }

        $tol = 0.01;
        $bad = [];
        foreach ($m[0] as $hit) {
            if (!preg_match('/' . $num . '/', $hit, $nm)) { continue; }
            $stated = round((float) str_replace(',', '', $nm[0]), 2);
            if ($stated <= 0) { continue; }
            $ok = false;
            foreach ($realAmounts as $real) {
                if (abs($real - $stated) <= $tol) { $ok = true; break; }
            }
            if (!$ok) { $bad[] = $stated; }
        }

        if ($bad === []) {
            return ['reply' => $reply, 'flagged' => false];
        }

        // 3) A price was quoted that no tool returned. This is exactly the failure
        //    the owner hit (invented umrah prices). Do NOT ship it. Log, and replace
        //    the reply with a safe deflection that leans on the real cards/next step.
        error_log('[gia][containment] blocked reply quoting unverified amount(s): '
            . implode(', ', array_map('strval', $bad))
            . ' | realAmounts=' . implode(',', array_map('strval', $realAmounts)));

        $hasRealOptions = $realAmounts !== [];
        $safe = $hasRealOptions
            ? "Here are the real options from our system — the details and exact prices are on the cards below. Which one would you like to go with?"
            : "Let me pull the live options from our system so I can give you exact, up-to-date prices. One moment — could you confirm what you're looking for?";

        return ['reply' => $safe, 'flagged' => true];
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
        // UMRAH: expose the EXACT ids the dedicated umrah tools require, so the model
        // never has to infer them from a generic 'ref' (a wrong guess shows up as
        // "that departure is not open for booking"). A umrah_search card -> its
        // departure_id (pass to umrah_tiers); a umrah_tiers card -> its
        // departure_tier_id (pass to umrah_book).
        if ($module === 'umrah') {
            if (isset($card['departure_id']) && (int) $card['departure_id'] > 0) {
                $opt['departure_id'] = (int) $card['departure_id'];
            }
            if (isset($card['departure_tier_id']) && (int) $card['departure_tier_id'] > 0) {
                $opt['departure_tier_id'] = (int) $card['departure_tier_id'];
            }
        }
        return $opt;
    }

    private function defaultSystemPrompt(): string
    {
        // BRD §7 experience principles + §10.5/§10.6 safety.
        // Tone: warm, search-first, proactive — GIA owns its own identity. The
        // SAFETY rules below are non-negotiable and unchanged in substance.
        // CURRENT DATE: the model has NO inherent knowledge of today — without this it
        // guesses a stale year/date (e.g. resolving "tomorrow" to the wrong year) and
        // searches wrong dates. Inject the real date (app timezone) so every relative
        // date ("tomorrow", "next Friday", "this weekend") resolves correctly.
        $todayLine = "TODAY'S DATE is " . date('l, j F Y') . " (ISO " . date('Y-m-d') . "). "
            . "Resolve ALL relative dates from THIS date: 'today' = " . date('Y-m-d')
            . ", 'tomorrow' = " . date('Y-m-d', strtotime('+1 day'))
            . ". NEVER use any other year. Never ask the traveller what today's date is.\n";
        return $todayLine
            . "You are GIA — GoGlobia's own travel agent. You are warm, upbeat and genuinely "
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
            . "LIVE MODULES — ALWAYS FETCH LIVE OFFERS, NEVER STOP AT THE INTENT: for cars, rail, "
            . "ferries and eSIM, search_trip only prepares the search; it does NOT return bookable options. "
            . "(FLIGHTS are NOT in this list — flights use their own dedicated tools flights_search/"
            . "flights_select/flights_book, never search_trip/fetch_live_offers.) "
            . "The INSTANT you have called search_trip for one of these, you MUST immediately call "
            . "fetch_live_offers (with that module) in the SAME turn to pull the real, priced options and "
            . "show them in the chat. Do this automatically — do not wait to be asked, and do not tell the "
            . "traveller to click anything. A search_trip result for a live module with no cards is NOT 'no "
            . "availability' — it just means the live lookup hasn't run yet, so run it. "
            . "SAYING 'NO OPTIONS' — ONLY AFTER A REAL LIVE SEARCH: never tell the traveller there are no "
            . "flights/cars/etc. unless fetch_live_offers ACTUALLY returned zero offers. When it genuinely "
            . "returns none, say so plainly, state the exact route and date you searched live (e.g. 'I "
            . "searched Kano -> Lagos for 21 Oct and our live airline suppliers returned nothing right "
            . "now'), then offer concrete alternatives (a nearby date, or a different departure airport) and "
            . "re-search. NEVER claim no availability from a search_trip intent alone.\n"
            . "USING RESULTS — RECOMMEND LIKE A TRUSTED ADVISOR: search_trip returns real options with "
            . "names and prices, AND the interface shows them to the traveller as rich cards right below "
            . "your message. So DO NOT re-list every option or repeat all the prices — the cards already "
            . "do that. Instead add the value a human agent would: in a sentence or two, point to a best "
            . "pick USING ONLY THE EXACT NAME AND PRICE AS THEY APPEAR IN THE TOOL RESULT (copy the "
            . "option's title verbatim — do NOT translate, shorten, embellish or invent a name), with a "
            . "genuine reason tied to real tool data (e.g. a lower price, a longer duration, more "
            . "inclusions), and invite the next step. You MAY use HONEST, real encouragement (popular / "
            . "few departures) ONLY if it is true from the tool data — never invent scarcity, ratings, "
            . "demand or deadlines. If they hesitate, help (a "
            . "cheaper option, a different date, answer the worry) rather than push. Always close with "
            . "one easy next step ('want me to set up the 7-day one?', 'shall I pull up the details?'). "
            . "Guided and confident, never pressured.\n"
            . "FORMATTING — PLAIN, SHORT, SCANNABLE: Reply in short plain sentences. NEVER use markdown "
            . "tables (no '|' columns — they render as ugly raw text here) and avoid headings. A couple "
            . "of short lines, or at most a few '- ' bullets, is plenty. Don't restate the card list; "
            . "keep it conversational and brief.\n"
            . "UMRAH HAS ITS OWN DEDICATED TOOLS — use these for ANYTHING Umrah, and NEVER "
            . "use search_trip for Umrah: (u1) umrah_search — list our real Umrah departures "
            . "(pass a city/month only if the traveller named them). (u2) umrah_tiers — after "
            . "they pick a departure, show its tiers + prices. Pass the EXACT departure_id from "
            . "the chosen option in the umrah_search result when you have it; and ALWAYS also pass "
            . "'when' = what the traveller said about the date (e.g. \"16th December\"), so the "
            . "system can resolve the right departure. NEVER invent a numeric id from a date. (u3) "
            . "umrah_book — create the held unpaid booking. Pass the EXACT departure_tier_id from "
            . "the chosen tier in the umrah_tiers result (never guess it). "
            . "CRITICAL — DO NOT LOOP: once umrah_tiers has returned tiers for a departure, the NEXT "
            . "time the traveller confirms in ANY way — 'yes', 'ok', 'book it', 'go ahead', the tier "
            . "name, or a number like '1' — you MUST call umrah_book immediately with that tier's "
            . "departure_tier_id (use pax=1 if they haven't said otherwise). Do NOT call umrah_tiers "
            . "again, do NOT re-show the tiers, and do NOT ask 'shall I book?' more than once — if "
            . "you already asked and they said yes/1/the tier, that IS the go-ahead: book it. "
            . "After umrah_book, the system collects each "
            . "pilgrim's passport and then shows payment — you do NOT handle umrah payment or "
            . "passports yourself. Only ever state Umrah packages/prices that umrah_search or "
            . "umrah_tiers returned.\n"
            . "FLIGHTS HAVE THEIR OWN DEDICATED TOOLS — use these for ANY flight, and NEVER use "
            . "search_trip/fetch_live_offers for flights: (f1) flights_search — real bookable fares. "
            . "You MUST already have the departure city/airport, destination and departure date; ask "
            . "for whatever is missing and NEVER guess a date or a departure city (no 'today', no "
            . "assumed origin). (f2) flights_select — after they pick a fare, reconfirm "
            . "the live price and get the exact passenger fields this fare needs. "
            . "CRITICAL — DO NOT RE-SEARCH ON A PICK: once flights_search has returned fares, when the "
            . "traveller chooses one — 'the first one', 'the 06:30', 'yes', a number, or the airline/"
            . "time — you MUST call flights_select with THAT fare's ref from the results you already "
            . "have. Do NOT call flights_search again, do NOT re-show the same list. (Each fare's title "
            . "includes its departure time so you can map 'the 06:30 one' to the right ref.) (f3) "
            . "flights_book — after flights_select, create the held unpaid booking (pass the same ref + "
            . "passenger counts). Then collect EACH passenger's required details with "
            . "flights_add_passenger (name, title, date of birth, and passport fields when the fare "
            . "needs them); when the last one is saved the system shows payment. The ticket is issued "
            . "only AFTER payment. You do NOT handle flight payment or ticketing yourself. Only ever "
            . "state fares that flights_search/flights_select returned.\n"
            . "STAYS (HOTELS) HAVE THEIR OWN DEDICATED TOOLS — use these for ANY hotel, and NEVER use "
            . "search_trip for stays: (s1) stays_search — our real properties in a city (pass the city). "
            . "(s2) stays_rooms — after they pick a property AND give check-in + check-out dates, show "
            . "that property's real rooms + prices for those dates. You MUST have the dates first — ask "
            . "for them, NEVER guess dates (dates are to stays what passengers are to flights). Pass the "
            . "EXACT stay_id from the chosen property card (never a list position like '1'). (s3) "
            . "stays_book — create the held unpaid booking. "
            . "CRITICAL — DO NOT LOOP: once stays_rooms has returned rooms, when the traveller picks one "
            . "('the Standard Room', a number, or 'yes book it'), you MUST call stays_book immediately "
            . "with that room's ref ('stayroom:...') + the check-in/out dates. Do NOT call stays_rooms "
            . "or stays_search again, and do NOT keep asking for name/email/phone BEFORE booking — the "
            . "held booking needs no guest contact; collect contact (if at all) only when you actually "
            . "have it, never as a blocker to stays_book. After stays_book, payment is offered; paying "
            . "confirms the stay (no ticket to issue). Only ever state properties/rooms/prices "
            . "that stays_search or stays_rooms returned.\n"
            . "TOOLS &amp; BOOKING FLOW (follow this order to complete a booking): "
            . "(NOTE: Umrah, Flights and Stays do NOT use this generic chain — they have their own "
            . "dedicated tools described above. This chain is for tours/bus/visa/cars/rail/ferries/esim.) "
            . "(a) search_trip — find options. For tours/bus/visa this returns individual "
            . "pickable cards. For cars/rail/ferries/esim it returns an overview only. "
            . "(b) fetch_live_offers — for cars/rail/ferries/esim, call this (with the module) "
            . "when the traveler wants to actually choose one; it returns individual offers with prices "
            . "and a ref each. (c) select_option — when the traveler picks a specific option, start a "
            . "booking draft: pass its module + id/ref (the card id for local modules, or the ref from "
            . "fetch_live_offers for live ones). "
            . "(d) reprice_trip — confirm the live price of that draft "
            . "(ALWAYS before checkout; the earlier price is not final — and for flights do this again "
            . "right before approval as fares are time-sensitive). (e) create_checkout — freeze the "
            . "repriced total. (f) create_booking — turn the frozen quote into a payable booking (unpaid; "
            . "no charge) and get its invoice reference. (g) request_booking_approval — AFTER they tap "
            . "Approve in the interface. (h) initiate_payment — begin the real payment (needs the invoice "
            . "from create_booking). (i) get_order_status — check the real booking state. Full flow: "
            . "(search_trip [+ fetch_live_offers for live modules]) -> pick -> select_option -> "
            . "reprice_trip -> create_checkout -> create_booking -> (tap Approve) -> "
            . "request_booking_approval -> initiate_payment -> get_order_status. All 10 services can book "
            . "in-chat this way. If a live fare has expired by approval time, reprice will say so — "
            . "warmly offer to pull fresh options.\n"
            . "TALKING TO A HUMAN: if the traveler asks to speak to a person, is frustrated, or needs "
            . "something you genuinely cannot do, call request_human (with a short reason). It alerts the "
            . "team; then reassure them a team member will join this chat shortly and offer to take their "
            . "email/phone so staff can reach them. Don't overuse it — try to help first.\n"
            . "SAFETY (never bend these): (1) STRICT CONTAINMENT — you may ONLY name, describe or price "
            . "a product (umrah package, hotel, tour, flight, car, visa, eSIM, etc.) that appears in the "
            . "options a tool returned THIS conversation. Use the option's title, location, dates, "
            . "duration and price EXACTLY as the tool gave them — never invent, guess, translate, round, "
            . "localise or 'improve' any of these, and never add a city, country, date or feature that is "
            . "not in the tool data. If you are unsure whether something is real, call a tool or say you "
            . "need to check — do NOT fill the gap from memory. If no tool has returned options yet, do "
            . "NOT describe any specific package; run the search first. Never invent flights, hotels, "
            . "prices, availability, bookings or confirmations. If a search came back empty, say so "
            . "warmly and offer an alternative — do not substitute a made-up option. You read the SAME "
            . "live inventory the GoGlobia website shows — NEVER tell a traveller you cannot see or browse "
            . "the website, or that results are 'off-system'; that is false. If a search is empty, do NOT "
            . "claim a 'supplier availability issue' or invent a reason — just say you didn't find a match "
            . "for those exact filters and offer to broaden them (e.g. show all departures, or try without "
            . "a specific month). NEVER invent or assume a YEAR a traveller didn't state — if they say a "
            . "month with no year, search the next occurrence of that month, don't guess a past year. (2) Before any "
            . "checkout, reprice so "
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

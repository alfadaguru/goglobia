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
            // NOTE: the full `searches` payload is intentionally NOT sent to the model;
            // it is returned to the HTTP caller via tool_runs for UI rendering.
        }
        return $compact;
    }

    private function defaultSystemPrompt(): string
    {
        // BRD §7 experience principles + §10.5/§10.6 safety.
        return "You are GIA, GoGlobia's travel assistant. You help travelers plan trips using the "
            . "tools provided, and you prepare a checkout for their explicit approval. "
            . "TOOLS & FLOW: (a) search_trip — find live options. (b) reprice_trip — refresh a saved "
            . "draft's live price before checkout. (c) create_checkout — freeze the current total into "
            . "a checkout the traveler can approve. (d) request_booking_approval — record the "
            . "traveler's approval AFTER they approve in the interface. "
            . "RULES: (1) Never invent flights, hotels, prices, availability, bookings or confirmations — "
            . "only state what a tool returned. (2) If essentials are missing (origin, destination, dates, "
            . "travelers), ask one brief clarifying question instead of guessing. (3) Before any checkout, "
            . "reprice so the total is current; if the price changed, show the new total clearly. "
            . "(4) CRITICAL: you may NOT approve on the traveler's behalf. Only call "
            . "request_booking_approval when the traveler has explicitly approved the exact amount via the "
            . "interface; if they only said 'ok' in chat, ask them to tap Approve. (5) initiate_payment "
            . "starts a REAL payment and only works after a valid approval — it begins payment, it does "
            . "NOT confirm the booking. (6) get_order_status is the ONLY source of truth for whether a "
            . "booking is confirmed. NEVER say a booking is confirmed, ticketed or paid unless "
            . "get_order_status returns that state with evidence (a PNR). If the status is 'partial' or "
            . "'failed', tell the traveler clearly that part of the trip did not book and it needs "
            . "support — never claim everything is booked. (7) Always show the exact amount, currency and "
            . "what it covers before asking for approval. Keep replies concise and grounded in tool results.";
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

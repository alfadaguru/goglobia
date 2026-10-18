<?php

namespace App\lib\ai;

/**
 * GIA model-agnostic CHAT + TOOL-CALLING provider (BRD §10.2 / EPIC 02).
 *
 * The existing providers (claude/openai/gemini) are extraction-only (vision OCR).
 * GIA needs multi-turn chat WITH tool-calling. This class adds exactly that, as a
 * single normalized `chat()` across all three providers, driven by the SAME
 * settings.ai_configuration key/provider the admin already sets
 * (resolved via passportAiActiveProviderConfig()).
 *
 * Every per-provider request/response shape below is taken from the providers'
 * OFFICIAL 2026 documentation (recorded verbatim in the BRD §36.5), NOT guessed:
 *
 *   Claude /v1/messages     tools:[{name,description,input_schema}]
 *                           -> content block {type:tool_use,id,name,input(object)}; stop_reason:tool_use
 *                           -> result: user msg {type:tool_result,tool_use_id,content}
 *   OpenAI /v1/chat/completions  tools:[{type:function,function:{name,description,parameters}}]
 *                           -> message.tool_calls[]{id,type,function:{name,arguments(JSON STRING)}}; finish_reason:tool_calls
 *                           -> result: msg {role:tool,tool_call_id,content}
 *   Gemini :generateContent tools:[{functionDeclarations:[{name,description,parameters}]}]
 *                           -> parts[].functionCall{name,args(object)}
 *                           -> result: parts[].functionResponse{name,response}
 *
 * Transport reuses aiHttpPost() (app/lib/ai/aiHttp.php). No streaming in Phase A.
 *
 * Internal canonical message shape used by giaAgent (provider-agnostic):
 *   ['role'=>'system'|'user'|'assistant'|'tool',
 *    'text'=>?string,                              // plain text content
 *    'tool_calls'=>?[['id'=>,'name'=>,'input'=>array]],  // assistant asked to call tools
 *    'tool_results'=>?[['id'=>,'name'=>,'output'=>array|string]] ]  // results fed back
 *
 * chat() returns:
 *   ['ok'=>bool, 'provider'=>, 'model'=>, 'stop'=>'end'|'tool_use'|'error',
 *    'text'=>string, 'tool_calls'=>[['id'=>,'name'=>,'input'=>array]],
 *    'usage'=>['input_tokens'=>?,'output_tokens'=>?], 'error'=>?string, 'http'=>int]
 */
class giaChatProvider
{
    private const DEFAULT_MAX_TOKENS = 2048;

    /**
     * One normalized chat turn across providers.
     *
     * @param array $messages  canonical messages (see class docblock)
     * @param array $tools     giaToolInterface[] (may be empty for a plain chat turn)
     * @param array $config    resolved provider config from passportAiActiveProviderConfig():
     *                         requires 'key' (claude|openai|gemini), 'api_key', 'model',
     *                         'endpoint' (optional), 'timeout' (optional)
     * @param string $systemPrompt
     */
    public function chat(array $messages, array $tools, array $config, string $systemPrompt = ''): array
    {
        $key = strtolower(trim((string) ($config['key'] ?? '')));
        if ($key === '' || trim((string) ($config['api_key'] ?? '')) === '') {
            return $this->err($key, 'AI provider is not configured.');
        }

        return match ($key) {
            'claude' => $this->chatClaude($messages, $tools, $config, $systemPrompt),
            'openai' => $this->chatOpenAi($messages, $tools, $config, $systemPrompt),
            'gemini' => $this->chatGemini($messages, $tools, $config, $systemPrompt),
            default  => $this->err($key, 'Unsupported AI provider: ' . $key),
        };
    }

    // ───────────────────────────── CLAUDE ─────────────────────────────
    // Verified: platform.claude.com/docs/en/agents-and-tools/tool-use
    private function chatClaude(array $messages, array $tools, array $config, string $systemPrompt): array
    {
        $endpoint = trim((string) ($config['endpoint'] ?? '')) ?: 'https://api.anthropic.com/v1/messages';
        $model    = $this->resolveClaudeModel(trim((string) ($config['model'] ?? '')));
        $timeout  = (int) ($config['timeout'] ?? 30);

        $payload = [
            'model'      => $model,
            'max_tokens' => self::DEFAULT_MAX_TOKENS,
            'messages'   => $this->toClaudeMessages($messages),
        ];
        if ($systemPrompt !== '') {
            $payload['system'] = $systemPrompt;
        }
        if ($tools !== []) {
            $payload['tools'] = array_map(fn ($t) => [
                'name'         => $t->name(),
                'description'  => $t->description(),
                'input_schema' => $t->inputSchema(),
            ], $tools);
            // One tool call per turn keeps the executor deterministic (BRD §10.3).
            $payload['tool_choice'] = ['type' => 'auto', 'disable_parallel_tool_use' => true];
        }

        $headers = [
            'x-api-key: ' . trim((string) $config['api_key']),
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ];

        $res = \aiHttpPost($endpoint, $payload, $headers, $timeout);
        if (!$this->httpOk($res)) {
            return $this->err('claude', $this->apiError($res), $res['http'] ?? 0);
        }
        $json = $res['json'];

        $text = '';
        $toolCalls = [];
        foreach (($json['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            } elseif (($block['type'] ?? '') === 'tool_use') {
                $toolCalls[] = [
                    'id'    => (string) ($block['id'] ?? ''),
                    'name'  => (string) ($block['name'] ?? ''),
                    'input' => is_array($block['input'] ?? null) ? $block['input'] : [],
                ];
            }
        }
        $stop = ($json['stop_reason'] ?? '') === 'tool_use' ? 'tool_use' : 'end';

        return [
            'ok' => true, 'provider' => 'claude', 'model' => $model, 'stop' => $stop,
            'text' => $text, 'tool_calls' => $toolCalls,
            'usage' => [
                'input_tokens'  => (int) ($json['usage']['input_tokens'] ?? 0),
                'output_tokens' => (int) ($json['usage']['output_tokens'] ?? 0),
            ],
            'http' => $res['http'] ?? 200,
        ];
    }

    /** Canonical messages -> Claude messages[] (system handled separately). */
    private function toClaudeMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $role = $m['role'] ?? 'user';
            if ($role === 'system') {
                continue; // folded into the top-level `system` param
            }
            if ($role === 'tool') {
                // Tool results go back as a USER message with tool_result blocks.
                $blocks = [];
                foreach (($m['tool_results'] ?? []) as $tr) {
                    $blocks[] = [
                        'type'        => 'tool_result',
                        'tool_use_id' => (string) ($tr['id'] ?? ''),
                        'content'     => $this->asText($tr['output'] ?? ''),
                    ];
                }
                if ($blocks !== []) {
                    $out[] = ['role' => 'user', 'content' => $blocks];
                }
                continue;
            }
            if ($role === 'assistant' && !empty($m['tool_calls'])) {
                $blocks = [];
                if (($m['text'] ?? '') !== '') {
                    $blocks[] = ['type' => 'text', 'text' => (string) $m['text']];
                }
                foreach ($m['tool_calls'] as $tc) {
                    $blocks[] = [
                        'type'  => 'tool_use',
                        'id'    => (string) ($tc['id'] ?? ''),
                        'name'  => (string) ($tc['name'] ?? ''),
                        'input' => is_array($tc['input'] ?? null) ? $tc['input'] : [],
                    ];
                }
                $out[] = ['role' => 'assistant', 'content' => $blocks];
                continue;
            }
            // plain user/assistant text
            $out[] = ['role' => $role === 'assistant' ? 'assistant' : 'user',
                      'content' => (string) ($m['text'] ?? '')];
        }
        return $out;
    }

    // ───────────────────────────── OPENAI ─────────────────────────────
    // Verified: Chat Completions tools format (function.arguments is a JSON STRING)
    private function chatOpenAi(array $messages, array $tools, array $config, string $systemPrompt): array
    {
        $endpoint = trim((string) ($config['endpoint'] ?? '')) ?: 'https://api.openai.com/v1/chat/completions';
        $model    = trim((string) ($config['model'] ?? '')) ?: 'gpt-4o';
        $timeout  = (int) ($config['timeout'] ?? 30);

        $payload = [
            'model'    => $model,
            'messages' => $this->toOpenAiMessages($messages, $systemPrompt),
        ];
        if ($tools !== []) {
            $payload['tools'] = array_map(fn ($t) => [
                'type'     => 'function',
                'function' => [
                    'name'        => $t->name(),
                    'description' => $t->description(),
                    'parameters'  => $t->inputSchema(),
                ],
            ], $tools);
            $payload['tool_choice'] = 'auto';
        }

        $headers = [
            'Authorization: Bearer ' . trim((string) $config['api_key']),
            'Content-Type: application/json',
        ];

        $res = \aiHttpPost($endpoint, $payload, $headers, $timeout);
        if (!$this->httpOk($res)) {
            return $this->err('openai', $this->apiError($res), $res['http'] ?? 0);
        }
        $json = $res['json'];
        $msg  = $json['choices'][0]['message'] ?? [];

        $text = (string) ($msg['content'] ?? '');
        $toolCalls = [];
        foreach (($msg['tool_calls'] ?? []) as $tc) {
            // arguments is a JSON STRING per the spec — decode defensively.
            $args = [];
            $raw = $tc['function']['arguments'] ?? '';
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $args = $decoded;
                }
            } elseif (is_array($raw)) {
                $args = $raw;
            }
            $toolCalls[] = [
                'id'    => (string) ($tc['id'] ?? ''),
                'name'  => (string) ($tc['function']['name'] ?? ''),
                'input' => $args,
            ];
        }
        $stop = ($json['choices'][0]['finish_reason'] ?? '') === 'tool_calls' ? 'tool_use' : 'end';

        return [
            'ok' => true, 'provider' => 'openai', 'model' => $model, 'stop' => $stop,
            'text' => $text, 'tool_calls' => $toolCalls,
            'usage' => [
                'input_tokens'  => (int) ($json['usage']['prompt_tokens'] ?? 0),
                'output_tokens' => (int) ($json['usage']['completion_tokens'] ?? 0),
            ],
            'http' => $res['http'] ?? 200,
        ];
    }

    private function toOpenAiMessages(array $messages, string $systemPrompt): array
    {
        $out = [];
        if ($systemPrompt !== '') {
            $out[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        foreach ($messages as $m) {
            $role = $m['role'] ?? 'user';
            if ($role === 'system') {
                $out[] = ['role' => 'system', 'content' => (string) ($m['text'] ?? '')];
                continue;
            }
            if ($role === 'tool') {
                foreach (($m['tool_results'] ?? []) as $tr) {
                    $out[] = [
                        'role'         => 'tool',
                        'tool_call_id' => (string) ($tr['id'] ?? ''),
                        'content'      => $this->asText($tr['output'] ?? ''),
                    ];
                }
                continue;
            }
            if ($role === 'assistant' && !empty($m['tool_calls'])) {
                $tcs = [];
                foreach ($m['tool_calls'] as $tc) {
                    $tcs[] = [
                        'id'       => (string) ($tc['id'] ?? ''),
                        'type'     => 'function',
                        'function' => [
                            'name'      => (string) ($tc['name'] ?? ''),
                            'arguments' => json_encode(is_array($tc['input'] ?? null) ? $tc['input'] : []),
                        ],
                    ];
                }
                $out[] = [
                    'role'       => 'assistant',
                    'content'    => (string) ($m['text'] ?? ''),
                    'tool_calls' => $tcs,
                ];
                continue;
            }
            $out[] = ['role' => $role === 'assistant' ? 'assistant' : 'user',
                      'content' => (string) ($m['text'] ?? '')];
        }
        return $out;
    }

    // ───────────────────────────── GEMINI ─────────────────────────────
    // Verified: ai.google.dev/gemini-api/docs/function-calling (generateContent)
    private function chatGemini(array $messages, array $tools, array $config, string $systemPrompt): array
    {
        $model    = trim((string) ($config['model'] ?? '')) ?: 'gemini-2.0-flash';
        $base     = trim((string) ($config['endpoint'] ?? ''));
        if ($base === '') {
            $base = 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent';
        }
        $endpoint = str_replace('{model}', rawurlencode($model), $base);
        // Gemini takes the key as a query param.
        $endpoint .= (strpos($endpoint, '?') === false ? '?' : '&') . 'key=' . rawurlencode(trim((string) $config['api_key']));
        $timeout  = (int) ($config['timeout'] ?? 30);

        $payload = ['contents' => $this->toGeminiContents($messages)];
        if ($systemPrompt !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $systemPrompt]]];
        }
        if ($tools !== []) {
            $payload['tools'] = [[
                'functionDeclarations' => array_map(fn ($t) => [
                    'name'        => $t->name(),
                    'description' => $t->description(),
                    'parameters'  => $t->inputSchema(),
                ], $tools),
            ]];
            $payload['toolConfig'] = ['functionCallingConfig' => ['mode' => 'AUTO']];
        }

        $headers = ['Content-Type: application/json'];

        $res = \aiHttpPost($endpoint, $payload, $headers, $timeout);
        if (!$this->httpOk($res)) {
            return $this->err('gemini', $this->apiError($res), $res['http'] ?? 0);
        }
        $json = $res['json'];

        $text = '';
        $toolCalls = [];
        $parts = $json['candidates'][0]['content']['parts'] ?? [];
        foreach ($parts as $i => $part) {
            if (isset($part['text'])) {
                $text .= (string) $part['text'];
            } elseif (isset($part['functionCall'])) {
                $fc = $part['functionCall'];
                $toolCalls[] = [
                    // Gemini function calls carry no id; synthesize a stable one.
                    'id'    => 'gem_' . $i . '_' . substr(md5((string) ($fc['name'] ?? '') . json_encode($fc['args'] ?? [])), 0, 10),
                    'name'  => (string) ($fc['name'] ?? ''),
                    'input' => is_array($fc['args'] ?? null) ? $fc['args'] : [],
                ];
            }
        }
        $stop = $toolCalls !== [] ? 'tool_use' : 'end';

        return [
            'ok' => true, 'provider' => 'gemini', 'model' => $model, 'stop' => $stop,
            'text' => $text, 'tool_calls' => $toolCalls,
            'usage' => [
                'input_tokens'  => (int) ($json['usageMetadata']['promptTokenCount'] ?? 0),
                'output_tokens' => (int) ($json['usageMetadata']['candidatesTokenCount'] ?? 0),
            ],
            'http' => $res['http'] ?? 200,
        ];
    }

    private function toGeminiContents(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $role = $m['role'] ?? 'user';
            if ($role === 'system') {
                continue; // folded into systemInstruction
            }
            if ($role === 'tool') {
                $parts = [];
                foreach (($m['tool_results'] ?? []) as $tr) {
                    $resp = $tr['output'] ?? [];
                    if (!is_array($resp)) {
                        $resp = ['result' => (string) $resp];
                    }
                    $parts[] = ['functionResponse' => [
                        'name'     => (string) ($tr['name'] ?? ''),
                        'response' => $resp,
                    ]];
                }
                if ($parts !== []) {
                    $out[] = ['role' => 'user', 'parts' => $parts];
                }
                continue;
            }
            if ($role === 'assistant' && !empty($m['tool_calls'])) {
                $parts = [];
                if (($m['text'] ?? '') !== '') {
                    $parts[] = ['text' => (string) $m['text']];
                }
                foreach ($m['tool_calls'] as $tc) {
                    $parts[] = ['functionCall' => [
                        'name' => (string) ($tc['name'] ?? ''),
                        'args' => is_array($tc['input'] ?? null) ? $tc['input'] : [],
                    ]];
                }
                $out[] = ['role' => 'model', 'parts' => $parts];
                continue;
            }
            $out[] = [
                'role'  => $role === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) ($m['text'] ?? '')]],
            ];
        }
        return $out;
    }

    // ───────────────────────────── helpers ─────────────────────────────
    private function resolveClaudeModel(string $model): string
    {
        if ($model === '') {
            return 'claude-sonnet-4-6';
        }
        $aliases = [
            'claude-sonnet-4-20250514' => 'claude-sonnet-4-6',
            'claude-sonnet-4'          => 'claude-sonnet-4-6',
            'claude-sonnet-4.0'        => 'claude-sonnet-4-6',
            'claude-3-5-sonnet-latest' => 'claude-sonnet-4-6',
            'claude-3-5-sonnet-20241022' => 'claude-sonnet-4-6',
            'claude-3-7-sonnet-20250219' => 'claude-sonnet-4-6',
            'claude-sonnet-4-5-20250929' => 'claude-sonnet-4-5',
        ];
        return $aliases[$model] ?? $model;
    }

    /** Tool result output -> string content for providers that want text. */
    private function asText($output): string
    {
        if (is_string($output)) {
            return $output;
        }
        return (string) json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * aiHttpPost()'s `ok` only reflects curl TRANSPORT success — it is true even for
     * an HTTP 401/400/500. A real success needs a 2xx status AND a parseable JSON body.
     * (This is what makes an invalid-key 401 surface as an error rather than being
     * silently parsed as an empty/garbage response.)
     */
    private function httpOk(array $res): bool
    {
        $http = (int) ($res['http'] ?? 0);
        return ($res['ok'] ?? false) && $http >= 200 && $http < 300 && is_array($res['json'] ?? null);
    }

    private function apiError(array $res): string
    {
        $msg = '';
        if (is_array($res['json'] ?? null)) {
            $msg = (string) ($res['json']['error']['message'] ?? $res['json']['message'] ?? '');
        }
        if ($msg === '') {
            $msg = (string) ($res['error'] ?? ('HTTP ' . (int) ($res['http'] ?? 0)));
        }
        // Never leak a key into logs/UI.
        $msg = preg_replace('/sk-ant-[A-Za-z0-9_-]+/', '[redacted]', $msg) ?? $msg;
        $msg = preg_replace('/AIza[A-Za-z0-9_-]+/', '[redacted]', $msg) ?? $msg;
        return trim(mb_substr($msg, 0, 240));
    }

    private function err(string $provider, string $message, int $http = 0): array
    {
        return [
            'ok' => false, 'provider' => $provider, 'model' => '', 'stop' => 'error',
            'text' => '', 'tool_calls' => [], 'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            'error' => $message, 'http' => $http,
        ];
    }
}

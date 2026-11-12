<?php

/**
 * GIA provider-grade voice helper.
 *
 * Upgrades GIA's voice beyond the browser Web Speech baseline WHEN the admin's
 * active AI provider genuinely supports it, and fails SOFT to the browser
 * otherwise (the widget keeps a Web Speech fallback for everything here).
 *
 * Capability matrix (verified GA endpoints + long-stable model names only —
 * we deliberately do NOT hardcode speculative/future model IDs):
 *   - OpenAI : STT via POST /v1/audio/transcriptions (model whisper-1, multipart)
 *              TTS via POST /v1/audio/speech        (model tts-1, JSON -> audio bytes)
 *   - Gemini : STT via generateContent inline_data audio (model gemini-2.0-flash)
 *              TTS -> NOT GA at time of writing -> browser fallback
 *   - Claude : no native voice at all -> browser fallback for both
 *
 * All functions resolve the active provider through passportAiActiveProviderConfig()
 * (the same resolver giaChatProvider uses), so the key/model/endpoint come from
 * the admin's saved AI configuration, never from the client.
 *
 * Loaded via explicit require_once (no composer autoload for app classes).
 */

require_once __DIR__ . '/aiHttp.php';

if (!function_exists('gia_voice_config_chain')) {
    /**
     * Ordered provider config chain for voice (primary + backups), same source as
     * GIA chat. Falls back to the single active config if the chain helper is
     * unavailable (back-compat). Returns [] when no provider is configured.
     */
    function gia_voice_config_chain($db): array
    {
        if (function_exists('passportAiProviderConfigChain')) {
            $chain = \passportAiProviderConfigChain($db);
            if (is_array($chain) && $chain !== []) {
                return $chain;
            }
        }
        $single = function_exists('passportAiActiveProviderConfig')
            ? \passportAiActiveProviderConfig($db) : null;
        return is_array($single) ? [$single] : [];
    }
}

if (!function_exists('gia_voice_should_failover')) {
    /**
     * Decide from a voice helper's error string whether to try the next key.
     * The per-provider voice helpers return strings like "STT http 401" /
     * "TTS http 429" / "STT transport error: …". We parse any embedded HTTP code
     * and defer to the shared aiProviderErrorIsFailoverable policy (auth/billing/
     * quota/5xx/transport only — not a malformed request). Transport/missing-key
     * errors with no code are treated as failoverable (a different key may work).
     */
    function gia_voice_should_failover(string $error): bool
    {
        $http = 0;
        if (preg_match('/\bhttp\s*(\d{3})\b/i', $error, $m)) {
            $http = (int) $m[1];
        }
        if ($http === 0) {
            // transport error / key missing / empty transcript: a different key
            // (esp. a different provider) may still succeed — allow failover.
            return true;
        }
        return function_exists('aiProviderErrorIsFailoverable')
            ? \aiProviderErrorIsFailoverable($http, $error)
            : in_array($http, [401, 402, 403, 408, 429], true) || $http >= 500;
    }
}

if (!function_exists('gia_voice_chain_has_next_stt')) {
    /** Is there a later chain entry that has a server STT path (openai|gemini)? */
    function gia_voice_chain_has_next_stt(array $chain, int $afterIndex): bool
    {
        for ($i = $afterIndex + 1; $i < count($chain); $i++) {
            $k = strtolower(trim((string) ($chain[$i]['key'] ?? '')));
            if ($k === 'openai' || $k === 'gemini') {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('gia_voice_chain_has_next_tts')) {
    /** Is there a later chain entry that has a server TTS path (openai only)? */
    function gia_voice_chain_has_next_tts(array $chain, int $afterIndex): bool
    {
        for ($i = $afterIndex + 1; $i < count($chain); $i++) {
            if (strtolower(trim((string) ($chain[$i]['key'] ?? ''))) === 'openai') {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('gia_voice_enabled')) {
    /**
     * Is the GIA voice feature switched on in admin settings?
     * Independent of provider capability — browser voice needs no provider.
     */
    function gia_voice_enabled($db): bool
    {
        $s = passportAiSettings($db);
        return !empty($s['voice_enabled']);
    }
}

if (!function_exists('gia_voice_prefs')) {
    /**
     * Admin voice preferences (reading voice, speed, auto-speak default), already
     * normalized/clamped by aiConfigurationNormalize(). Safe defaults if unset.
     *
     * @return array{voice:string, speed:float, autospeak:bool}
     */
    function gia_voice_prefs($db): array
    {
        $s = passportAiSettings($db);
        $allowed = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];
        $voice = strtolower(trim((string) ($s['voice_tts_voice'] ?? 'nova')));
        if (!in_array($voice, $allowed, true)) {
            $voice = 'nova';
        }
        $speed = (float) ($s['voice_speed'] ?? 1.0);
        if ($speed < 0.5) { $speed = 0.5; }
        if ($speed > 2.0) { $speed = 2.0; }
        return [
            'voice'     => $voice,
            'speed'     => round($speed, 2),
            'autospeak' => !empty($s['voice_autospeak']),
        ];
    }
}

if (!function_exists('gia_voice_capabilities')) {
    /**
     * What CAN we do server-side right now, given the active provider?
     *
     * Returns a plain array the widget config can consume to decide whether to
     * record+POST (provider path) or use the browser Web Speech API (fallback).
     *
     * @return array{enabled:bool, provider:string, stt:bool, tts:bool,
     *               voices:string[], tts_voice:string, speed:float, autospeak:bool}
     */
    function gia_voice_capabilities($db): array
    {
        $prefs = gia_voice_prefs($db);
        $out = [
            'enabled'   => false,
            'provider'  => '',
            'stt'       => false,   // server speech-to-text available
            'tts'       => false,   // server text-to-speech available
            'voices'    => [],
            'tts_voice' => $prefs['voice'],     // admin-chosen reading voice
            'speed'     => $prefs['speed'],     // admin-chosen reading rate
            'autospeak' => $prefs['autospeak'], // read replies aloud by default
        ];

        if (!gia_voice_enabled($db)) {
            return $out;
        }
        $out['enabled'] = true;

        $cfg = passportAiActiveProviderConfig($db);
        if (!is_array($cfg)) {
            return $out; // no usable provider -> browser only
        }

        $key = strtolower(trim((string) ($cfg['key'] ?? '')));
        $out['provider'] = $key;

        switch ($key) {
            case 'openai':
                $out['stt']       = true;
                $out['tts']       = true;
                $out['voices']    = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];
                break;
            case 'gemini':
                $out['stt'] = true;   // audio-in via generateContent is GA
                $out['tts'] = false;  // Gemini TTS not GA -> browser fallback
                break;
            case 'claude':
            default:
                // Claude has no native voice; anything else -> browser.
                break;
        }

        return $out;
    }
}

if (!function_exists('gia_voice_transcribe')) {
    /**
     * Transcribe audio bytes to text using the active provider.
     *
     * @param string $audioBytes raw audio (as uploaded by the browser MediaRecorder)
     * @param string $mime       e.g. audio/webm, audio/mp4, audio/wav
     * @return array{ok:bool, text:string, provider:string, fallback:bool, error:string}
     *         fallback=true means "no server path — client should use the browser".
     */
    function gia_voice_transcribe($db, string $audioBytes, string $mime): array
    {
        $fail = static function (string $msg, bool $fallback = true): array {
            return ['ok' => false, 'text' => '', 'provider' => '', 'fallback' => $fallback, 'error' => $msg];
        };

        if (!gia_voice_enabled($db)) {
            return $fail('Voice is disabled.', false);
        }
        if ($audioBytes === '') {
            return $fail('No audio received.', false);
        }

        // Walk the key chain (primary + backups). STT has a server path for openai
        // (Whisper) and gemini only; a claude entry is skipped. On a key-fixable
        // error we advance to the next key; otherwise fail SOFT to the browser.
        $chain = gia_voice_config_chain($db);
        if ($chain === []) {
            return $fail('No provider — use browser.');
        }
        $hadServerPath = false;
        $lastErr = '';
        foreach ($chain as $ci => $cfg) {
            $key     = strtolower(trim((string) ($cfg['key'] ?? '')));
            $apiKey  = trim((string) ($cfg['api_key'] ?? ''));
            $timeout = (int) ($cfg['timeout'] ?? 30);

            if ($key === 'openai') {
                $res = gia_voice_transcribe_openai($audioBytes, $mime, $apiKey, $timeout);
            } elseif ($key === 'gemini') {
                $res = gia_voice_transcribe_gemini($audioBytes, $mime, $apiKey, trim((string) ($cfg['model'] ?? '')), trim((string) ($cfg['endpoint'] ?? '')), $timeout);
            } else {
                continue; // claude/unknown: no server STT for this entry — try next key
            }
            $hadServerPath = true;
            if (!empty($res['ok'])) {
                return $res;
            }
            $lastErr = (string) ($res['error'] ?? '');
            if (gia_voice_should_failover($lastErr) && gia_voice_chain_has_next_stt($chain, $ci)) {
                error_log('[gia] voice STT key ' . (string) ($cfg['fallback_label'] ?? ('rank' . $ci))
                    . ' failed (' . substr($lastErr, 0, 100) . ') — trying next backup key');
                continue;
            }
            return $res; // not failoverable, or no further STT-capable key
        }

        // No chain entry had a server STT path (e.g. all claude) -> browser.
        return $fail($hadServerPath ? ('STT failed: ' . $lastErr) : 'Provider has no server STT — use browser.');
    }
}

if (!function_exists('gia_voice_transcribe_openai')) {
    /** OpenAI Whisper: POST /v1/audio/transcriptions (multipart, model whisper-1). */
    function gia_voice_transcribe_openai(string $audioBytes, string $mime, string $apiKey, int $timeout): array
    {
        $fail = static function (string $msg, bool $fallback = true): array {
            return ['ok' => false, 'text' => '', 'provider' => 'openai', 'fallback' => $fallback, 'error' => $msg];
        };
        if ($apiKey === '') {
            return $fail('OpenAI key missing.');
        }

        $ext  = gia_voice_ext_for_mime($mime);
        $tmp  = tempnam(sys_get_temp_dir(), 'gia_stt_');
        if ($tmp === false) {
            return $fail('Temp file failed.');
        }
        $path = $tmp . '.' . $ext;
        // Rename so CURLFile carries a sensible extension the API recognizes.
        if (!@rename($tmp, $path)) {
            $path = $tmp; // fall back to the extensionless temp name
        }
        if (@file_put_contents($path, $audioBytes) === false) {
            @unlink($path);
            return $fail('Temp write failed.');
        }

        $started = microtime(true);
        $ch = curl_init('https://api.openai.com/v1/audio/transcriptions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => max(10, $timeout),
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
            CURLOPT_POSTFIELDS     => [
                'file'            => new CURLFile($path, $mime !== '' ? $mime : 'audio/webm', 'audio.' . $ext),
                'model'           => 'whisper-1',
                'response_format' => 'json',
            ],
        ]);
        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = (string) curl_error($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        @unlink($path);

        if ($errno !== 0 || $raw === false) {
            return $fail('STT transport error: ' . $error);
        }
        if ($http < 200 || $http >= 300) {
            return $fail('STT http ' . $http);
        }
        $json = json_decode((string) $raw, true);
        $text = is_array($json) ? trim((string) ($json['text'] ?? '')) : '';
        if ($text === '') {
            return $fail('Empty transcript.');
        }
        return ['ok' => true, 'text' => $text, 'provider' => 'openai', 'fallback' => false, 'error' => ''];
    }
}

if (!function_exists('gia_voice_transcribe_gemini')) {
    /** Gemini: generateContent with inline_data audio part (model gemini-2.0-flash). */
    function gia_voice_transcribe_gemini(string $audioBytes, string $mime, string $apiKey, string $model, string $endpoint, int $timeout): array
    {
        $fail = static function (string $msg, bool $fallback = true): array {
            return ['ok' => false, 'text' => '', 'provider' => 'gemini', 'fallback' => $fallback, 'error' => $msg];
        };
        if ($apiKey === '') {
            return $fail('Gemini key missing.');
        }
        $model = $model !== '' ? $model : 'gemini-2.0-flash';
        if ($endpoint === '') {
            $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent';
        }
        $endpoint = str_replace('{model}', rawurlencode($model), $endpoint);
        if (!str_contains($endpoint, 'key=')) {
            $endpoint .= (str_contains($endpoint, '?') ? '&' : '?') . 'key=' . rawurlencode($apiKey);
        }

        $payload = [
            'contents' => [[
                'parts' => [
                    ['text' => 'Transcribe this audio to plain text. Return only the spoken words, nothing else.'],
                    ['inline_data' => [
                        'mime_type' => $mime !== '' ? $mime : 'audio/webm',
                        'data'      => base64_encode($audioBytes),
                    ]],
                ],
            ]],
        ];

        $res = aiHttpPost($endpoint, $payload, ['Content-Type: application/json'], max(10, $timeout));
        if (!$res['ok']) {
            return $fail('STT transport error: ' . $res['error']);
        }
        if ($res['http'] < 200 || $res['http'] >= 300) {
            return $fail('STT http ' . $res['http']);
        }
        $text = '';
        $parts = $res['json']['candidates'][0]['content']['parts'] ?? [];
        if (is_array($parts)) {
            foreach ($parts as $p) {
                if (isset($p['text'])) {
                    $text .= (string) $p['text'];
                }
            }
        }
        $text = trim($text);
        if ($text === '') {
            return $fail('Empty transcript.');
        }
        return ['ok' => true, 'text' => $text, 'provider' => 'gemini', 'fallback' => false, 'error' => ''];
    }
}

if (!function_exists('gia_voice_speak')) {
    /**
     * Synthesize speech from text using the active provider.
     *
     * @return array{ok:bool, audio:string, mime:string, provider:string, fallback:bool, error:string}
     *         On success `audio` is raw bytes (mp3). fallback=true means the caller
     *         should use the browser SpeechSynthesis API instead.
     */
    function gia_voice_speak($db, string $text, string $voice = ''): array
    {
        $fail = static function (string $msg, bool $fallback = true): array {
            return ['ok' => false, 'audio' => '', 'mime' => '', 'provider' => '', 'fallback' => $fallback, 'error' => $msg];
        };

        if (!gia_voice_enabled($db)) {
            return $fail('Voice is disabled.', false);
        }
        $text = trim($text);
        if ($text === '') {
            return $fail('No text.', false);
        }
        // OpenAI TTS hard-limits input to 4096 chars; keep well under.
        if (strlen($text) > 4000) {
            $text = substr($text, 0, 4000);
        }

        // Fall back to the admin's saved reading voice + speed when the client
        // didn't request a specific one. The client CAN override the voice, but
        // the speed always comes from admin settings (one consistent cadence).
        $prefs = gia_voice_prefs($db);
        $allowed = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];
        if (!in_array($voice, $allowed, true)) {
            $voice = $prefs['voice'];
        }
        $speed = $prefs['speed'];

        // Walk the key chain (primary + backups). TTS has a server path for openai
        // only; non-openai entries are skipped. Fail over on a key-fixable error.
        $chain = gia_voice_config_chain($db);
        if ($chain === []) {
            return $fail('No provider — use browser.');
        }
        $hadServerPath = false;
        $lastErr = '';
        foreach ($chain as $ci => $cfg) {
            $key     = strtolower(trim((string) ($cfg['key'] ?? '')));
            $apiKey  = trim((string) ($cfg['api_key'] ?? ''));
            $timeout = (int) ($cfg['timeout'] ?? 30);

            if ($key !== 'openai') {
                continue; // only openai has a server TTS path — try next key
            }
            $hadServerPath = true;
            $res = gia_voice_speak_openai($text, $voice, $apiKey, $timeout, $speed);
            if (!empty($res['ok'])) {
                return $res;
            }
            $lastErr = (string) ($res['error'] ?? '');
            if (gia_voice_should_failover($lastErr) && gia_voice_chain_has_next_tts($chain, $ci)) {
                error_log('[gia] voice TTS key ' . (string) ($cfg['fallback_label'] ?? ('rank' . $ci))
                    . ' failed (' . substr($lastErr, 0, 100) . ') — trying next backup key');
                continue;
            }
            return $res;
        }

        // No chain entry had a server TTS path -> browser.
        return $fail($hadServerPath ? ('TTS failed: ' . $lastErr) : 'Provider has no server TTS — use browser.');
    }
}

if (!function_exists('gia_voice_speak_openai')) {
    /** OpenAI TTS: POST /v1/audio/speech (model tts-1, JSON -> raw mp3 bytes). */
    function gia_voice_speak_openai(string $text, string $voice, string $apiKey, int $timeout, float $speed = 1.0): array
    {
        $fail = static function (string $msg, bool $fallback = true): array {
            return ['ok' => false, 'audio' => '', 'mime' => '', 'provider' => 'openai', 'fallback' => $fallback, 'error' => $msg];
        };
        if ($apiKey === '') {
            return $fail('OpenAI key missing.');
        }
        $allowed = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];
        $voice   = in_array($voice, $allowed, true) ? $voice : 'nova';
        // OpenAI /audio/speech accepts speed 0.25–4.0; our admin range is 0.5–2.0.
        if ($speed < 0.5) { $speed = 0.5; }
        if ($speed > 2.0) { $speed = 2.0; }

        $started = microtime(true);
        $ch = curl_init('https://api.openai.com/v1/audio/speech');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => max(10, $timeout),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model'           => 'tts-1',
                'input'           => $text,
                'voice'           => $voice,
                'response_format' => 'mp3',
                'speed'           => round($speed, 2),
            ]),
        ]);
        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = (string) curl_error($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            return $fail('TTS transport error: ' . $error);
        }
        if ($http < 200 || $http >= 300) {
            // Body is a JSON error here, not audio — surface nothing sensitive.
            return $fail('TTS http ' . $http);
        }
        if (!is_string($raw) || $raw === '') {
            return $fail('Empty audio.');
        }
        return ['ok' => true, 'audio' => $raw, 'mime' => 'audio/mpeg', 'provider' => 'openai', 'fallback' => false, 'error' => ''];
    }
}

if (!function_exists('gia_voice_ext_for_mime')) {
    /** Map a browser MediaRecorder MIME to a file extension Whisper recognizes. */
    function gia_voice_ext_for_mime(string $mime): string
    {
        $mime = strtolower(trim($mime));
        // strip ;codecs=...
        $semi = strpos($mime, ';');
        if ($semi !== false) {
            $mime = substr($mime, 0, $semi);
        }
        return match ($mime) {
            'audio/webm'            => 'webm',
            'audio/mp4', 'audio/aac'=> 'mp4',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/ogg'             => 'ogg',
            'audio/wav', 'audio/x-wav', 'audio/wave' => 'wav',
            'audio/flac'            => 'flac',
            default                 => 'webm',
        };
    }
}

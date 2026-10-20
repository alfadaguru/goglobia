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

if (!function_exists('gia_voice_capabilities')) {
    /**
     * What CAN we do server-side right now, given the active provider?
     *
     * Returns a plain array the widget config can consume to decide whether to
     * record+POST (provider path) or use the browser Web Speech API (fallback).
     *
     * @return array{enabled:bool, provider:string, stt:bool, tts:bool,
     *               voices:string[], tts_voice:string}
     */
    function gia_voice_capabilities($db): array
    {
        $out = [
            'enabled'   => false,
            'provider'  => '',
            'stt'       => false,   // server speech-to-text available
            'tts'       => false,   // server text-to-speech available
            'voices'    => [],
            'tts_voice' => '',
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
                $out['tts_voice'] = 'nova';
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

        $cfg = passportAiActiveProviderConfig($db);
        if (!is_array($cfg)) {
            return $fail('No provider — use browser.');
        }
        $key     = strtolower(trim((string) ($cfg['key'] ?? '')));
        $apiKey  = trim((string) ($cfg['api_key'] ?? ''));
        $timeout = (int) ($cfg['timeout'] ?? 30);

        if ($key === 'openai') {
            return gia_voice_transcribe_openai($audioBytes, $mime, $apiKey, $timeout);
        }
        if ($key === 'gemini') {
            return gia_voice_transcribe_gemini($audioBytes, $mime, $apiKey, trim((string) ($cfg['model'] ?? '')), trim((string) ($cfg['endpoint'] ?? '')), $timeout);
        }

        // Claude / unknown: no server STT -> browser.
        return $fail('Provider has no server STT — use browser.');
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

        $cfg = passportAiActiveProviderConfig($db);
        if (!is_array($cfg)) {
            return $fail('No provider — use browser.');
        }
        $key     = strtolower(trim((string) ($cfg['key'] ?? '')));
        $apiKey  = trim((string) ($cfg['api_key'] ?? ''));
        $timeout = (int) ($cfg['timeout'] ?? 30);

        if ($key === 'openai') {
            return gia_voice_speak_openai($text, $voice, $apiKey, $timeout);
        }

        // Gemini TTS is not GA; Claude has no voice -> browser.
        return $fail('Provider has no server TTS — use browser.');
    }
}

if (!function_exists('gia_voice_speak_openai')) {
    /** OpenAI TTS: POST /v1/audio/speech (model tts-1, JSON -> raw mp3 bytes). */
    function gia_voice_speak_openai(string $text, string $voice, string $apiKey, int $timeout): array
    {
        $fail = static function (string $msg, bool $fallback = true): array {
            return ['ok' => false, 'audio' => '', 'mime' => '', 'provider' => 'openai', 'fallback' => $fallback, 'error' => $msg];
        };
        if ($apiKey === '') {
            return $fail('OpenAI key missing.');
        }
        $allowed = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];
        $voice   = in_array($voice, $allowed, true) ? $voice : 'nova';

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

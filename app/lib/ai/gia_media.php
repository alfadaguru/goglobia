<?php
// ============================================================================
// FILE: app/lib/ai/gia_media.php
// GIA inbound MEDIA handling for messaging channels: download a voice note /
// image / document from the platform, then route it to an EXISTING engine —
// voice -> gia_voice_transcribe() (STT), image/PDF -> passport OCR
// (aiProviderInterface::extract) — and return something the chat can use.
//
// SECURITY (this is the risky part — media URLs are attacker-adjacent):
//   * SSRF ALLOWLIST — we only ever fetch from each platform's known media hosts
//     (api.telegram.org, graph.facebook.com, lookaside.fbsbx.com, api.greenapi.com).
//     A URL on any other host is refused outright.
//   * SIZE CAP — downloads are bounded (default 12 MB) so a huge file can't OOM us.
//   * MIME CHECK — the engine decides what it accepts; we pass the real bytes+mime.
//   * TLS verified on every fetch.
// Nothing here books or charges. FAIL-SOFT: returns a friendly message on any error.
// ============================================================================

namespace App\lib\ai;

if (!function_exists('App\\lib\\ai\\gia_media_allowed_host')) {
    /** SSRF guard: only these hosts may be fetched for channel media. */
    function gia_media_allowed_host(string $url): bool
    {
        // Must be https (defense in depth; the fetchers also enforce this).
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'https') { return false; }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') { return false; }
        $allow = [
            'api.telegram.org',      // Telegram file download
            'graph.facebook.com',    // Meta media-id lookup
            'lookaside.fbsbx.com',   // Meta WhatsApp media
            'fbsbx.com',             // Messenger CDN (cdn.fbsbx.com) — subdomain match
            'fbcdn.net',             // Messenger CDN (scontent.*.fbcdn.net) — subdomain match
            'api.greenapi.com',      // Green API downloadFile
            'media.greenapi.com',
        ];
        foreach ($allow as $h) {
            // Exact host or a subdomain of an allowed host.
            if ($host === $h || substr($host, -(strlen($h) + 1)) === '.' . $h) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('App\\lib\\ai\\gia_media_fetch')) {
    /**
     * Download bytes from an ALLOWLISTED media URL, bounded in size. Optional
     * bearer header (Meta). Returns ['ok'=>bool,'bytes'=>string,'mime'=>string] .
     */
    function gia_media_fetch(string $url, ?string $bearer = null, int $maxBytes = 12582912): array
    {
        if ($url === '' || !preg_match('#^https://#i', $url) || !gia_media_allowed_host($url)) {
            return ['ok' => false, 'error' => 'blocked_host'];
        }
        $headers = [];
        if ($bearer !== null && $bearer !== '') {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,            // no redirect-based SSRF escape
            CURLOPT_PROTOCOLS      => defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 0,
            // Abort if the server declares a body bigger than the cap.
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow) use ($maxBytes) {
                return ($dlTotal > $maxBytes || $dlNow > $maxBytes) ? 1 : 0;
            },
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mime = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err !== '' || $code < 200 || $code >= 300 || $body === false) {
            error_log('[gia] media fetch failed (HTTP ' . $code . ')');
            return ['ok' => false, 'error' => 'fetch_failed'];
        }
        if (strlen($body) > $maxBytes) {
            return ['ok' => false, 'error' => 'too_large'];
        }
        // Normalize the mime (strip "; charset=").
        $mime = trim(explode(';', $mime)[0] ?? '');
        return ['ok' => true, 'bytes' => (string) $body, 'mime' => $mime];
    }
}

if (!function_exists('App\\lib\\ai\\gia_media_resolve_url')) {
    /**
     * Resolve the actual download URL (+ optional bearer) for a channel's media
     * reference. Telegram: file_id -> getFile -> file_path -> file URL. Meta: media
     * id -> graph lookup -> lookaside url + bearer. Green API: URL is already given.
     * Returns ['ok'=>bool,'url'=>string,'bearer'=>?string,'mime'=>string].
     */
    function gia_media_resolve_url($db, string $channel, array $ref): array
    {
        require_once __DIR__ . '/gia_channels_config.php';
        $cfg = \gia_channels_config($db);

        if ($channel === 'telegram') {
            $token = (string) ($cfg['telegram']['bot_token'] ?? '');
            $fileId = (string) ($ref['file_id'] ?? '');
            if ($token === '' || $fileId === '') { return ['ok' => false]; }
            $r = gia_media_fetch_json('https://api.telegram.org/bot' . $token . '/getFile?file_id=' . rawurlencode($fileId));
            $path = (string) ($r['result']['file_path'] ?? '');
            if ($path === '') { return ['ok' => false]; }
            return ['ok' => true, 'url' => 'https://api.telegram.org/file/bot' . $token . '/' . $path, 'bearer' => null, 'mime' => (string) ($ref['mime'] ?? '')];
        }

        if ($channel === 'whatsapp') {
            $mode = (string) ($cfg['whatsapp']['mode'] ?? 'greenapi');
            if ($mode === 'meta') {
                $token = (string) ($cfg['whatsapp']['meta_access_token'] ?? '');
                $mediaId = (string) ($ref['media_id'] ?? '');
                if ($token === '' || $mediaId === '') { return ['ok' => false]; }
                $r = gia_media_fetch_json('https://graph.facebook.com/v21.0/' . rawurlencode($mediaId), $token);
                $url = (string) ($r['url'] ?? '');
                if ($url === '') { return ['ok' => false]; }
                return ['ok' => true, 'url' => $url, 'bearer' => $token, 'mime' => (string) ($r['mime_type'] ?? ($ref['mime'] ?? ''))];
            }
            // Green API: downloadUrl is already in the webhook (pre-signed).
            $url = (string) ($ref['download_url'] ?? '');
            if ($url === '') { return ['ok' => false]; }
            return ['ok' => true, 'url' => $url, 'bearer' => null, 'mime' => (string) ($ref['mime'] ?? '')];
        }

        if ($channel === 'messenger') {
            // Messenger attachments arrive as a direct (already-usable) URL.
            $url = (string) ($ref['url'] ?? '');
            if ($url === '') { return ['ok' => false]; }
            return ['ok' => true, 'url' => $url, 'bearer' => null, 'mime' => (string) ($ref['mime'] ?? '')];
        }

        return ['ok' => false];
    }
}

if (!function_exists('App\\lib\\ai\\gia_media_fetch_json')) {
    /** Small GET+JSON for media-URL resolution (allowlisted + TLS-verified). */
    function gia_media_fetch_json(string $url, ?string $bearer = null): array
    {
        if (!gia_media_allowed_host($url)) { return []; }
        $headers = ['Accept: application/json'];
        if ($bearer !== null && $bearer !== '') { $headers[] = 'Authorization: Bearer ' . $bearer; }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) { return []; }
        $j = json_decode((string) $body, true);
        return is_array($j) ? $j : [];
    }
}

if (!function_exists('App\\lib\\ai\\gia_media_handle')) {
    /**
     * Process one inbound media item and return an outcome for the spine:
     *   ['mode' => 'text', 'text' => <string>]  -> feed this to the agent as the user's message
     *   ['mode' => 'reply','reply' => <string>]  -> send this reply directly (no agent)
     * $kind is 'audio'|'image'|'document'|'video'|'location'|'other'.
     */
    function gia_media_handle($db, string $channel, string $kind, array $ref): array
    {
        // --- VOICE / AUDIO -> transcribe -> treat transcript as the user's text. ---
        if ($kind === 'audio') {
            require_once __DIR__ . '/gia_voice.php';
            // gia_voice.php is GLOBAL-namespaced (no namespace), so check + call the
            // bare global name — not __NAMESPACE__ . '\\...'.
            if (!\function_exists('gia_voice_transcribe')) {
                return ['mode' => 'reply', 'reply' => "I can't listen to voice notes right now — please type your message."];
            }
            $res = gia_media_resolve_url($db, $channel, $ref);
            if (empty($res['ok'])) { return ['mode' => 'reply', 'reply' => "I couldn't open that voice note — mind typing it instead?"]; }
            $dl = gia_media_fetch($res['url'], $res['bearer'] ?? null);
            if (empty($dl['ok'])) { return ['mode' => 'reply', 'reply' => "I couldn't download that voice note — please type your message."]; }
            $mime = $dl['mime'] !== '' ? $dl['mime'] : ((string) ($res['mime'] ?? 'audio/ogg'));
            $tr = \gia_voice_transcribe($db, $dl['bytes'], $mime);
            $text = trim((string) ($tr['text'] ?? ''));
            if (empty($tr['ok']) || $text === '') {
                return ['mode' => 'reply', 'reply' => "I couldn't quite make out that voice note — could you type it or try again?"];
            }
            return ['mode' => 'text', 'text' => $text];
        }

        // --- IMAGE / DOCUMENT -> passport OCR -> summarize extracted details. ---
        if ($kind === 'image' || $kind === 'document') {
            if (!function_exists('passportAiIsEnabled') || !\passportAiIsEnabled($db)) {
                return ['mode' => 'reply', 'reply' => "Thanks! I can see you sent a document. Tell me the trip you want and I'll help you book it."];
            }
            $cfg = function_exists('passportAiActiveProviderConfig') ? \passportAiActiveProviderConfig($db) : null;
            if (!is_array($cfg)) {
                return ['mode' => 'reply', 'reply' => "Thanks for the document! Let's start with your trip — where would you like to go?"];
            }
            $res = gia_media_resolve_url($db, $channel, $ref);
            if (empty($res['ok'])) { return ['mode' => 'reply', 'reply' => "I couldn't open that file — you can also just type your details."]; }
            $dl = gia_media_fetch($res['url'], $res['bearer'] ?? null);
            if (empty($dl['ok'])) { return ['mode' => 'reply', 'reply' => "I couldn't download that file — please type your details instead."]; }
            $mime = $dl['mime'] !== '' ? $dl['mime'] : ((string) ($res['mime'] ?? ''));
            // Only images go to the OCR provider (it extracts from an image).
            if (strpos($mime, 'image/') !== 0) {
                return ['mode' => 'reply', 'reply' => "Got your document. For a passport I can read a clear PHOTO of it — otherwise just tell me the passenger details."];
            }
            $provider = gia_ocr_make_provider((string) ($cfg['key'] ?? ''));
            if ($provider === null) {
                return ['mode' => 'reply', 'reply' => "Thanks for the photo! Tell me the trip you'd like and we'll get started."];
            }
            try {
                $out = $provider->extract($dl['bytes'], $mime, $cfg);
            } catch (\Throwable $e) {
                error_log('[gia] media ocr: ' . $e->getMessage());
                $out = ['status' => false];
            }
            if (empty($out['status']) || empty($out['data'])) {
                return ['mode' => 'reply', 'reply' => "I had trouble reading that photo clearly. You can resend a sharper one, or just type the passenger name, passport number and expiry."];
            }
            $d = (array) $out['data'];
            $name = trim((string) (($d['given_names'] ?? $d['first_name'] ?? '') . ' ' . ($d['surname'] ?? $d['last_name'] ?? '')));
            $passport = (string) ($d['passport_number'] ?? $d['document_number'] ?? '');
            $parts = [];
            if ($name !== '') { $parts[] = 'name ' . $name; }
            if ($passport !== '') { $parts[] = 'passport ' . $passport; }
            $summary = $parts !== [] ? implode(', ', $parts) : 'your passport details';
            // Feed as the user's message so GIA naturally confirms + continues.
            return ['mode' => 'text', 'text' => "Here are my passport details from the photo I sent: " . $summary . ". Please use these for the booking."];
        }

        // --- LOCATION -> feed coordinates to the agent as context for "near me". ---
        if ($kind === 'location') {
            $lat = $ref['lat'] ?? null;
            $lng = $ref['lng'] ?? null;
            if (is_numeric($lat) && is_numeric($lng)) {
                return ['mode' => 'text', 'text' => "I'm sharing my location: latitude " . (float) $lat . ", longitude " . (float) $lng . ". Find options near me."];
            }
            return ['mode' => 'reply', 'reply' => "Thanks for sharing your location! Tell me what you're looking for near there — a hotel, a tour?"];
        }

        // --- VIDEO / STICKER / OTHER -> graceful acknowledgement (no silence). ---
        return ['mode' => 'reply', 'reply' => "I can't open that kind of message yet — but tell me where you'd like to go and I'll find real options and book it with you."];
    }
}

if (!function_exists('App\\lib\\ai\\gia_ocr_make_provider')) {
    /** Mirror aiExtractionService::makeProvider (which is private) to reuse the OCR providers. */
    function gia_ocr_make_provider(string $key): ?aiProviderInterface
    {
        $dir = __DIR__ . '/';
        $map = ['openai' => 'openAiProvider', 'claude' => 'claudeProvider', 'gemini' => 'geminiProvider'];
        $cls = $map[$key] ?? '';
        if ($cls === '') { return null; }
        require_once $dir . 'aiProviderInterface.php';
        require_once $dir . 'aiHttp.php';
        require_once $dir . 'passportExtractionHelpers.php';
        $file = $dir . $cls . '.php';
        if (!is_file($file)) { return null; }
        require_once $file;
        $fqcn = __NAMESPACE__ . '\\' . $cls;
        if (!class_exists($fqcn)) { return null; }
        $obj = new $fqcn();
        return ($obj instanceof aiProviderInterface) ? $obj : null;
    }
}

<?php
// ============================================================================
// FILE: app/lib/ai/gia_channel_send.php
// GIA OUTBOUND — send a reply back to a traveler on their messaging channel.
// One entry point, gia_channel_send($db,$channel,$toId,$text), dispatched per
// channel:
//   whatsapp  -> Green API (reuse SENDWHATSAPP) OR Meta Cloud API, per admin mode
//   telegram  -> Bot API sendMessage
//   messenger -> Send API (graph.facebook.com /me/messages)
//
// TLS is always verified. Secrets come from gia_channels_config() (admin-owned).
// Returns bool; logs failures without leaking tokens. Pure egress — no DB writes.
// ============================================================================

if (!function_exists('gia_channel_http_post_json')) {
    /** Minimal JSON POST with TLS verification on. Returns [ok,httpCode,body]. */
    function gia_channel_http_post_json(string $url, array $payload, array $headers = []): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err !== '') {
            error_log('[gia] send curl error (HTTP ' . $code . ')');
            return ['ok' => false, 'code' => $code, 'body' => ''];
        }
        return ['ok' => ($code >= 200 && $code < 300), 'code' => $code, 'body' => (string) $body];
    }
}

if (!function_exists('gia_channel_http_get')) {
    /** Simple GET returning ['ok','code','body']. Used to read provider settings. */
    function gia_channel_http_get(string $url): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err !== '') { return ['ok' => false, 'code' => $code, 'body' => '']; }
        return ['ok' => ($code >= 200 && $code < 300), 'code' => $code, 'body' => (string) $body];
    }
}

if (!function_exists('gia_channel_send')) {
    /**
     * @param object $db
     * @param string $channel 'whatsapp' | 'messenger' | 'telegram'
     * @param string $toId    recipient id on that channel (phone / PSID / chat id)
     * @param string $text    message text (used when $rich has no buttons/cards)
     * @param array  $rich    OPTIONAL normalized rich message (see gia_channel_render.php):
     *                        ['text'=>, 'buttons'=>[...], 'cards'=>[...]]. When it
     *                        carries choices, each channel renders them natively
     *                        where it reliably can, else degrades to numbered text.
     * @return bool sent ok
     */
    function gia_channel_send($db, string $channel, string $toId, string $text, array $rich = []): bool
    {
        $channel = strtolower(trim($channel));
        $toId = trim($toId);
        require_once __DIR__ . '/gia_channels_config.php';
        require_once __DIR__ . '/gia_channel_render.php';

        // Normalize: if no explicit rich payload, treat $text as a plain message.
        $norm = gia_render_norm($rich !== [] ? $rich : $text);
        if (($norm['text'] ?? '') === '' && empty($norm['buttons']) && empty($norm['cards'])) {
            $norm['text'] = (string) $text;
        }
        if ($toId === '' || (trim((string) $norm['text']) === '' && empty($norm['buttons']) && empty($norm['cards']))) {
            return false;
        }
        $cfg = gia_channels_config($db);

        switch ($channel) {
            case 'whatsapp':
                return gia_channel_send_whatsapp($db, $cfg['whatsapp'] ?? [], $toId, $norm);
            case 'telegram':
                return gia_channel_send_telegram($cfg['telegram'] ?? [], $toId, $norm);
            case 'messenger':
                return gia_channel_send_messenger($cfg['messenger'] ?? [], $toId, $norm);
            default:
                return false;
        }
    }
}

if (!function_exists('gia_channel_send_whatsapp')) {
    function gia_channel_send_whatsapp($db, array $waCfg, string $toPhone, array $norm): bool
    {
        $mode = (string) ($waCfg['mode'] ?? 'greenapi');
        $hasChoices = !empty($norm['buttons']) || !empty($norm['cards']);

        if ($mode === 'meta') {
            $pnid  = trim((string) ($waCfg['meta_phone_number_id'] ?? ''));
            $token = trim((string) ($waCfg['meta_access_token'] ?? ''));
            if ($pnid === '' || $token === '') {
                error_log('[gia] whatsapp(meta) send: missing phone_number_id/token');
                return false;
            }
            $url = 'https://graph.facebook.com/v21.0/' . rawurlencode($pnid) . '/messages';
            $to  = preg_replace('/[^\d]/', '', $toPhone);
            // Prefer a native interactive message when there are choices.
            if ($hasChoices) {
                $interactive = gia_render_whatsapp_interactive($norm);
                if (is_array($interactive)) {
                    $r = gia_channel_http_post_json($url, [
                        'messaging_product' => 'whatsapp',
                        'to'                => $to,
                        'type'              => 'interactive',
                        'interactive'       => $interactive,
                    ], ['Authorization: Bearer ' . $token]);
                    if ($r['ok']) { return true; }
                    // fall through to plain text on failure
                }
            }
            $r = gia_channel_http_post_json($url, [
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'text',
                'text'              => ['body' => (string) ($norm['text'] !== '' ? $norm['text'] : gia_render_text_fallback($norm))],
            ], ['Authorization: Bearer ' . $token]);
            return $r['ok'];
        }

        // Green API: its interactive buttons are BETA/unstable (verified in docs),
        // so we render choices as reliable NUMBERED TEXT. The traveler replies with
        // a number/name, read as a normal message — no callback needed.
        $out = $hasChoices ? gia_render_text_fallback($norm) : (string) $norm['text'];
        if (trim($out) === '') { return false; }
        // Send via the Green API provider DIRECTLY with the greenapi creds — do NOT
        // route through SENDWHATSAPP(), which keys off settings.whatsapp_provider:
        // if an operator set the NOTIFICATION provider to something other than
        // greenapi, SENDWHATSAPP would use the wrong account (or fail) for GIA's
        // chat replies. GIA's WhatsApp channel is explicitly greenapi here, so we
        // load the greenapi credentials regardless of the global notification choice.
        try {
            $settings = $db->get('settings', ['whatsapp_providers_config'], ['id' => 1]) ?: [];
            $allCfg = json_decode((string) ($settings['whatsapp_providers_config'] ?? '{}'), true);
            $gc = (is_array($allCfg) && is_array($allCfg['greenapi'] ?? null)) ? $allCfg['greenapi'] : [];
            if (empty($gc['instance_id']) || empty($gc['token'])) {
                error_log('[gia] whatsapp(greenapi) send: Green API credentials not set (Settings → WhatsApp)');
                return false;
            }
            $provFile = dirname(__DIR__, 1) . '/notifications/whatsapp/greenapi.php';
            if (!class_exists('GreenapiProvider') && is_file($provFile)) { require_once $provFile; }
            if (!class_exists('GreenapiProvider')) { return false; }
            $prov = new \GreenapiProvider($gc);
            return (bool) $prov->send($toPhone, '', $out);
        } catch (\Throwable $e) {
            error_log('[gia] whatsapp(greenapi) send: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('gia_channel_send_telegram')) {
    function gia_channel_send_telegram(array $tgCfg, string $chatId, array $norm): bool
    {
        $token = trim((string) ($tgCfg['bot_token'] ?? ''));
        if ($token === '') {
            error_log('[gia] telegram send: missing bot_token');
            return false;
        }
        // Telegram renders text + inline keyboard natively in one message.
        $body = gia_render_telegram($norm, $chatId);
        $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
        $r = gia_channel_http_post_json($url, $body);
        return $r['ok'];
    }
}

if (!function_exists('gia_channel_send_messenger')) {
    function gia_channel_send_messenger(array $fbCfg, string $psid, array $norm): bool
    {
        $token = trim((string) ($fbCfg['page_token'] ?? ''));
        if ($token === '') {
            error_log('[gia] messenger send: missing page_token');
            return false;
        }
        $url = 'https://graph.facebook.com/v21.0/me/messages?access_token=' . rawurlencode($token);
        $hasChoices = !empty($norm['buttons']) || !empty($norm['cards']);
        $message = $hasChoices ? gia_render_messenger_message($norm) : null;
        if (!is_array($message)) {
            $txt = (string) ($norm['text'] !== '' ? $norm['text'] : gia_render_text_fallback($norm));
            if (trim($txt) === '') { return false; }
            $message = ['text' => $txt];
        }
        $r = gia_channel_http_post_json($url, [
            'recipient'      => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message'        => $message,
        ]);
        return $r['ok'];
    }
}

if (!function_exists('gia_channel_site_base')) {
    /**
     * Resolve the ABSOLUTE public base URL of this install (always https, with a
     * trailing slash), for building webhook URLs we hand to external providers.
     * Order: settings.site_url -> the `root` constant (only if absolute) -> APP_URL
     * from .env. Returns '' if none is absolute — callers MUST refuse to configure
     * a provider rather than send a broken relative URL. NEVER returns '/'.
     */
    function gia_channel_site_base($db): string
    {
        $candidates = [];
        try {
            $su = (string) ($db->get('settings', 'site_url', ['id' => 1]) ?? '');
            if ($su !== '') { $candidates[] = $su; }
        } catch (\Throwable $e) { /* ignore */ }
        if (defined('root')) { $candidates[] = (string) root; }
        $env = getenv('APP_URL');
        if ($env !== false && $env !== '') { $candidates[] = (string) $env; }

        foreach ($candidates as $c) {
            $c = trim($c);
            // Must be an absolute http(s) URL with a host — reject '/', relative, localhost.
            if (preg_match('#^https?://[^/]+#i', $c)) {
                $host = parse_url($c, PHP_URL_HOST) ?: '';
                if ($host === '' || preg_match('/^(localhost|127\.0\.0\.1|::1)$/i', $host)) {
                    continue; // never register a localhost webhook with a live provider
                }
                // Force https for provider webhooks; normalize a single trailing slash.
                $c = preg_replace('#^http://#i', 'https://', $c);
                return rtrim($c, '/') . '/';
            }
        }
        return '';
    }
}

if (!function_exists('gia_greenapi_set_webhook')) {
    /**
     * ONE-CLICK: register GoGlobia as Green API's incoming-webhook target, so
     * "hi" from a traveller reaches GIA without the operator editing the Green API
     * console by hand. Calls Green API SetSettings on the INSTANCE host with:
     *   webhookUrl = <site>/webhooks/gia/whatsapp?secret=<webhook_secret>
     *   incomingWebhook = "yes"  (we only need inbound messages)
     * The secret rides in the URL query (our webhook reads ?secret=), not in
     * webhookUrlToken. Requires: greenapi creds set (Settings → WhatsApp) AND a
     * webhook_secret set (GIA → Channels). Returns ['ok'=>bool,'message'=>string].
     * Never logs the token.
     */
    function gia_greenapi_set_webhook($db): array
    {
        require_once __DIR__ . '/gia_channels_config.php';
        $wa = gia_channels_config($db)['whatsapp'] ?? [];
        $secret = trim((string) ($wa['webhook_secret'] ?? ''));
        if ($secret === '') {
            return ['ok' => false, 'message' => 'Set a WhatsApp webhook secret first, then save, then enable.'];
        }

        // Green API credentials from the notification config (shared, by design).
        try {
            $settings = $db->get('settings', ['whatsapp_providers_config'], ['id' => 1]) ?: [];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not read settings.'];
        }
        $allCfg = json_decode((string) ($settings['whatsapp_providers_config'] ?? '{}'), true);
        $gc = (is_array($allCfg) && is_array($allCfg['greenapi'] ?? null)) ? $allCfg['greenapi'] : [];
        $iid = trim((string) ($gc['instance_id'] ?? ''));
        $tok = trim((string) ($gc['token'] ?? ''));
        if ($iid === '' || $tok === '') {
            return ['ok' => false, 'message' => 'Green API credentials are not set (Settings → WhatsApp).'];
        }

        // Resolve the instance host via the provider (first-4-digits rule / api_url).
        $provFile = dirname(__DIR__, 1) . '/notifications/whatsapp/greenapi.php';
        if (!class_exists('GreenapiProvider') && is_file($provFile)) { require_once $provFile; }
        if (!class_exists('GreenapiProvider')) {
            return ['ok' => false, 'message' => 'Green API provider unavailable.'];
        }
        $host = rtrim((new \GreenapiProvider($gc))->getBaseUrl(), '/');

        // Resolve an ABSOLUTE site base. If we can't, REFUSE — writing a relative
        // URL (the old bug) corrupts the operator's Green API config and silently
        // breaks inbound. Better to fail loudly than to stomp their settings.
        $base = gia_channel_site_base($db);
        if ($base === '') {
            return ['ok' => false, 'message' => 'Could not determine this site\'s public URL. Set it in Settings → General (Site URL) to an https address, then try again.'];
        }
        // Our inbound endpoint, with the shared secret as a ?secret= query param
        // (our webhook reads $_GET['secret'], and also accepts the Authorization
        // header if Green API is configured that way instead).
        $webhookUrl = $base . 'webhooks/gia/whatsapp?secret=' . rawurlencode($secret);

        // IMPORTANT: Green API's setSettings REPLACES the whole settings block, so
        // sending only a couple of keys would blank the rest. To avoid turning OFF
        // the operator's other toggles, READ the current settings first and change
        // ONLY webhookUrl + incomingWebhook, preserving everything else.
        $current = [];
        $getUrl = $host . '/waInstance' . rawurlencode($iid) . '/getSettings/' . rawurlencode($tok);
        $g = gia_channel_http_get($getUrl);
        if (!empty($g['ok'])) {
            $decoded = json_decode((string) ($g['body'] ?? ''), true);
            if (is_array($decoded)) { $current = $decoded; }
        }
        // Green API only accepts a known set of keys on setSettings; keep the ones
        // it returns and overlay just what GIA needs.
        $payload = $current;
        $payload['webhookUrl']      = $webhookUrl;
        $payload['incomingWebhook'] = 'yes';          // GIA needs inbound messages
        unset($payload['wid'], $payload['countInstanceForbiddenReceiveWebhook']); // non-settable echoes
        // Drop null-ish values Green API may echo that it won't accept back.
        foreach ($payload as $k => $v) {
            if ($v === null) { unset($payload[$k]); }
        }

        $url = $host . '/waInstance' . rawurlencode($iid) . '/setSettings/' . rawurlencode($tok);
        $r = gia_channel_http_post_json($url, $payload);
        if (!empty($r['ok'])) {
            return ['ok' => true, 'message' => 'Green API now points incoming messages at GIA (your other Green API settings were left as they were). Send your WhatsApp number a "hi" to test.'];
        }
        return ['ok' => false, 'message' => 'Green API rejected the request (HTTP ' . (int) ($r['code'] ?? 0) . '). Check the instance ID / token and that the instance is authorized.'];
    }
}

if (!function_exists('gia_telegram_set_webhook')) {
    /**
     * One-click: register GoGlobia's /webhooks/gia/telegram as the bot's webhook
     * via Telegram's setWebhook, passing our secret as secret_token (Telegram then
     * echoes it back in the X-Telegram-Bot-Api-Secret-Token header on every update).
     * Mirrors gia_greenapi_set_webhook. Returns ['ok'=>bool,'message'=>string].
     */
    function gia_telegram_set_webhook($db): array
    {
        require_once __DIR__ . '/gia_channels_config.php';
        $tg = gia_channels_config($db)['telegram'] ?? [];
        $token  = trim((string) ($tg['bot_token'] ?? ''));
        $secret = trim((string) ($tg['webhook_secret'] ?? ''));
        if ($token === '') {
            return ['ok' => false, 'message' => 'Set the Telegram bot token first, then save, then connect.'];
        }
        if ($secret === '') {
            return ['ok' => false, 'message' => 'Set a Telegram webhook secret first (any value you choose), then save, then connect.'];
        }

        $base = gia_channel_site_base($db);
        if ($base === '') {
            return ['ok' => false, 'message' => 'Could not determine this site\'s public URL. Set it in Settings → General (Site URL) to an https address, then try again.'];
        }
        $webhookUrl = $base . 'webhooks/gia/telegram';
        $url = 'https://api.telegram.org/bot' . $token . '/setWebhook';
        $r = gia_channel_http_post_json($url, [
            'url'                  => $webhookUrl,
            // Telegram validates this 1-256 char token and echoes it back per update.
            'secret_token'         => $secret,
            'allowed_updates'      => ['message'],
            'drop_pending_updates' => true,
        ]);
        if (!empty($r['ok'])) {
            return ['ok' => true, 'message' => 'Telegram is now sending messages to GIA. Open your bot in Telegram and say "hi" to test.'];
        }
        return ['ok' => false, 'message' => 'Telegram rejected the request (HTTP ' . (int) ($r['code'] ?? 0) . '). Check the bot token.'];
    }
}

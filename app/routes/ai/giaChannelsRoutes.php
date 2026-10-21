<?php
@$SECURE or die('Access Denied!');
// ============================================================================
// FILE: app/routes/ai/giaChannelsRoutes.php
// PUBLIC, unauthenticated webhook adapters that let GIA talk on messaging
// channels. Each verifies the platform's authenticity its own way, parses its
// own payload, hands a normalized (channel, sender, text, msgId) to the shared
// spine gia_channel_handle(), then sends GIA's reply via gia_channel_send().
//
//   Telegram  : POST /webhooks/gia/telegram      (secret header)
//   WhatsApp  : GET/POST /webhooks/gia/whatsapp   (Green API ?secret= | Meta sig)
//   Messenger : GET/POST /webhooks/gia/messenger  (Meta verify + X-Hub-Signature)
//
// SECURITY: ack fast (200) and process inline but cheaply; the spine dedupes
// platform retries. All credential/secret reads come from the admin-owned
// gia_channels_config(). We NEVER trust the sender id for money here — the
// spine keeps money tools locked on channels until Phase 2/3.
// ============================================================================

$giaChLib = function () use ($db) {
    $aiDir = dirname(__DIR__, 2) . '/lib/ai/';
    require_once $aiDir . 'gia_channels_config.php';
    require_once $aiDir . 'gia_analytics.php';
    require_once $aiDir . 'gia_crm.php';
    require_once $aiDir . 'gia_channel.php';
    require_once $aiDir . 'gia_channel_send.php';
};

// Fast 200 so the platform does not retry; optional body for debugging.
$giaChAck = function (int $code = 200, string $body = 'ok') {
    http_response_code($code);
    header('Content-Type: text/plain');
    echo $body;
};

// ----------------------------------------------------------------------------
// GET /gia/pay/<invoice> — the hosted pay-link target for the card path. GIA
// sends a signed, expiring link (gia_pay_link); this verifies it, grants THIS
// session access to just that invoice (the same owned_invoices mechanism the
// cart uses), and redirects into the EXISTING invoice/payment flow. It does not
// render its own payment UI or move money — it reuses the proven gateway path.
// ----------------------------------------------------------------------------
$router->get('/gia/pay/([A-Za-z0-9]{4,40})', function ($invoiceId) use ($db) {
    $aiDir = dirname(__DIR__, 2) . '/lib/ai/';
    require_once $aiDir . 'gia_pay.php';

    $fail = function (string $msg) {
        http_response_code(400);
        $safe = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
        $home = htmlspecialchars((defined('root') ? root : '/'), ENT_QUOTES, 'UTF-8');
        echo "<!doctype html><meta name='viewport' content='width=device-width,initial-scale=1'>"
           . "<div style=\"font-family:system-ui;max-width:28rem;margin:4rem auto;padding:0 1rem;text-align:center\">"
           . "<h2>Payment link problem</h2><p>{$safe}</p>"
           . "<p><a href=\"{$home}\">Go to GoGlobia</a></p></div>";
        exit;
    };

    $exp = (int) ($_GET['exp'] ?? 0);
    $sig = (string) ($_GET['sig'] ?? '');
    if (!function_exists('gia_pay_link_verify') || !gia_pay_link_verify($invoiceId, $exp, $sig)) {
        $fail('This payment link is invalid or has expired. Please ask GIA for a fresh link.');
    }

    $booking = $db->get('bookings', ['invoice_id', 'payment_status', 'module_type'], ['invoice_id' => $invoiceId]);
    if (!is_array($booking) || empty($booking['invoice_id'])) {
        $fail('We could not find that booking.');
    }
    if ((string) ($booking['payment_status'] ?? '') === 'paid') {
        header('Location: ' . (defined('root') ? root : '/') . 'invoice/' . rawurlencode($invoiceId));
        exit;
    }

    // Grant THIS session access to only this invoice (cart's guest mechanism),
    // then hand off to the normal invoice/payment flow (enforceInvoiceAccess OK).
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) { session_start(); }
    if (!isset($_SESSION['owned_invoices']) || !is_array($_SESSION['owned_invoices'])) {
        $_SESSION['owned_invoices'] = [];
    }
    if (!in_array($invoiceId, $_SESSION['owned_invoices'], true)) {
        $_SESSION['owned_invoices'][] = $invoiceId;
    }
    header('Location: ' . (defined('root') ? root : '/') . 'invoice/' . rawurlencode($invoiceId));
    exit;
});

// ----------------------------------------------------------------------------
// TELEGRAM — POST /webhooks/gia/telegram
// Auth: Telegram echoes the secret we set at setWebhook time in the header
// X-Telegram-Bot-Api-Secret-Token. Compare constant-time.
// ----------------------------------------------------------------------------
$router->post('/webhooks/gia/telegram', function () use ($db, $giaChLib, $giaChAck) {
    $giaChLib();
    require_once dirname(__DIR__, 2) . '/lib/ai/gia_webhook_log.php';
    $raw = file_get_contents('php://input') ?: '';
    $logIt = function (string $outcome, int $code, array $extra = []) use ($db, $raw) {
        gia_webhook_log_record($db, 'telegram', $outcome, $code, array_merge(['raw' => $raw], $extra));
    };

    if (!gia_channel_enabled($db, 'telegram')) { $logIt('disabled', 200); $giaChAck(200, 'disabled'); return; }

    $cfg = gia_channels_config($db)['telegram'] ?? [];
    $secret = (string) ($cfg['webhook_secret'] ?? '');
    $got = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    if ($secret !== '' && !hash_equals($secret, $got)) {
        $logIt('bad_secret', 403); $giaChAck(403, 'bad secret'); return;
    }

    $u = json_decode($raw, true);
    if (!is_array($u)) { $logIt('ignored', 200); $giaChAck(200, 'ignored'); return; }

    // Normalize: callback_query (button tap) OR a message (text / voice / photo /
    // document / location). Ignore edits/channel posts/etc.
    $p = null; $cbId = '';
    if (isset($u['callback_query']) && is_array($u['callback_query'])) {
        $cb = $u['callback_query'];
        $cbId = (string) ($cb['id'] ?? '');
        $p = ['sender' => (string) ($cb['message']['chat']['id'] ?? ''), 'id' => (string) ($cb['id'] ?? ''), 'kind' => 'text', 'text' => (string) ($cb['data'] ?? '')];
    } elseif (isset($u['message']) && is_array($u['message'])) {
        $msg = $u['message'];
        $chatId = (string) ($msg['chat']['id'] ?? '');
        $mid = (string) ($msg['message_id'] ?? '');
        if (isset($msg['text'])) {
            $p = ['sender' => $chatId, 'id' => $mid, 'kind' => 'text', 'text' => (string) $msg['text']];
        } elseif (isset($msg['voice']) || isset($msg['audio'])) {
            $a = $msg['voice'] ?? $msg['audio'];
            $p = ['sender' => $chatId, 'id' => $mid, 'kind' => 'audio', 'media' => ['file_id' => (string) ($a['file_id'] ?? ''), 'mime' => (string) ($a['mime_type'] ?? 'audio/ogg')]];
        } elseif (isset($msg['photo']) && is_array($msg['photo']) && $msg['photo'] !== []) {
            $ph = end($msg['photo']); // largest size last
            $p = ['sender' => $chatId, 'id' => $mid, 'kind' => 'image', 'media' => ['file_id' => (string) ($ph['file_id'] ?? ''), 'mime' => 'image/jpeg']];
        } elseif (isset($msg['document'])) {
            $doc = $msg['document'];
            $p = ['sender' => $chatId, 'id' => $mid, 'kind' => 'document', 'media' => ['file_id' => (string) ($doc['file_id'] ?? ''), 'mime' => (string) ($doc['mime_type'] ?? '')]];
        } elseif (isset($msg['location'])) {
            $p = ['sender' => $chatId, 'id' => $mid, 'kind' => 'location', 'media' => ['lat' => $msg['location']['latitude'] ?? null, 'lng' => $msg['location']['longitude'] ?? null]];
        } elseif (isset($msg['video']) || isset($msg['sticker'])) {
            $p = ['sender' => $chatId, 'id' => $mid, 'kind' => 'other'];
        }
    }
    if ($p === null || (string) ($p['sender'] ?? '') === '') { $logIt('ignored', 200); $giaChAck(200, 'ignored'); return; }

    // Dismiss a button's loading spinner (best-effort).
    if ($cbId !== '') {
        $tok = (string) (gia_channels_config($db)['telegram']['bot_token'] ?? '');
        if ($tok !== '' && function_exists('gia_channel_http_post_json')) {
            @gia_channel_http_post_json('https://api.telegram.org/bot' . $tok . '/answerCallbackQuery', ['callback_query_id' => $cbId]);
        }
    }

    $logIt('ok', 200, ['sender' => (string) ($p['sender'] ?? ''), 'kind' => (string) ($p['kind'] ?? ''), 'text' => (string) ($p['text'] ?? '')]);
    gia_ch_dispatch($db, 'telegram', $p, 'tg:');
    $giaChAck(200, 'ok');
});

// ----------------------------------------------------------------------------
// WHATSAPP — GET + POST /webhooks/gia/whatsapp
// Mode 'greenapi': POST incomingMessageReceived; auth via ?secret= (Green API
//                  does not sign webhooks) compared constant-time.
// Mode 'meta'    : GET verify handshake (hub.*) + POST signed X-Hub-Signature-256.
// ----------------------------------------------------------------------------
$router->get('/webhooks/gia/whatsapp', function () use ($db, $giaChLib, $giaChAck) {
    $giaChLib();
    $cfg = gia_channels_config($db)['whatsapp'] ?? [];
    // Meta verification handshake (only meaningful in meta mode).
    $mode = (string) ($cfg['mode'] ?? 'greenapi');
    if ($mode === 'meta') {
        $verify = (string) ($cfg['meta_verify_token'] ?? '');
        $hubMode = (string) ($_GET['hub_mode'] ?? ($_GET['hub.mode'] ?? ''));
        $hubTok  = (string) ($_GET['hub_verify_token'] ?? ($_GET['hub.verify_token'] ?? ''));
        $hubCh   = (string) ($_GET['hub_challenge'] ?? ($_GET['hub.challenge'] ?? ''));
        if ($hubMode === 'subscribe' && $verify !== '' && hash_equals($verify, $hubTok)) {
            http_response_code(200); echo $hubCh; return;
        }
        $giaChAck(403, 'verify failed'); return;
    }
    $giaChAck(200, 'ok');
});

$router->post('/webhooks/gia/whatsapp', function () use ($db, $giaChLib, $giaChAck) {
    $giaChLib();
    require_once dirname(__DIR__, 2) . '/lib/ai/gia_webhook_log.php';
    $raw  = file_get_contents('php://input') ?: '';
    // Pull an event type for the log even before we fully parse/authenticate.
    $peek = json_decode($raw, true);
    $evType = is_array($peek) ? (string) ($peek['typeWebhook'] ?? '') : '';
    $logIt = function (string $outcome, int $code, array $extra = []) use ($db, $raw, $evType) {
        gia_webhook_log_record($db, 'whatsapp', $outcome, $code, array_merge(['event_type' => $evType, 'raw' => $raw], $extra));
    };

    if (!gia_channel_enabled($db, 'whatsapp')) { $logIt('disabled', 200); $giaChAck(200, 'disabled'); return; }
    $cfg  = gia_channels_config($db)['whatsapp'] ?? [];
    $mode = (string) ($cfg['mode'] ?? 'greenapi');

    if ($mode === 'meta') {
        // Verify X-Hub-Signature-256 over the RAW body with the app secret.
        $appSecret = (string) ($cfg['meta_app_secret'] ?? '');
        $sigHeader = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
        if ($appSecret === '' || !gia_ch_verify_meta_sig($raw, $sigHeader, $appSecret)) {
            $logIt('bad_signature', 403); $giaChAck(403, 'bad signature'); return;
        }
        $data = json_decode($raw, true);
        $parsed = gia_ch_parse_meta_whatsapp(is_array($data) ? $data : []);
    } else {
        // Green API: it does not sign webhooks, so a shared secret is the ONLY
        // authentication. Green API can send it TWO ways depending on how it was
        // configured: as a ?secret= query param (our one-click default) OR as the
        // "Webhook authorization header" (sent as `Authorization: Bearer <secret>`
        // or a bare value). Accept EITHER so it works regardless of console setup.
        $secret = (string) ($cfg['webhook_secret'] ?? '');
        $got = (string) ($_GET['secret'] ?? '');
        if ($got === '') {
            // Fall back to the Authorization header (Apache passes it via .htaccess).
            $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
            if ($auth !== '') {
                // Strip a leading "Bearer " / "Basic " scheme if present.
                $got = trim(preg_replace('/^\s*(Bearer|Basic)\s+/i', '', $auth));
            }
        }
        if ($secret === '') {
            error_log('[gia] whatsapp(greenapi) webhook: refused — no webhook_secret configured');
            $logIt('secret_required', 403); $giaChAck(403, 'secret required'); return;
        }
        if (!hash_equals($secret, $got)) {
            $logIt('bad_secret', 403); $giaChAck(403, 'bad secret'); return;
        }
        $data = json_decode($raw, true);
        $parsed = gia_ch_parse_greenapi(is_array($data) ? $data : []);
    }

    if ($parsed === null) { $logIt('ignored', 200); $giaChAck(200, 'ignored'); return; }
    $logIt('ok', 200, ['sender' => (string) ($parsed['sender'] ?? ''), 'kind' => (string) ($parsed['kind'] ?? ''), 'text' => (string) ($parsed['text'] ?? '')]);
    gia_ch_dispatch($db, 'whatsapp', $parsed, 'wa:');
    $giaChAck(200, 'ok');
});

// ----------------------------------------------------------------------------
// MESSENGER — GET + POST /webhooks/gia/messenger
// GET verify handshake (hub.*) + POST signed X-Hub-Signature-256.
// ----------------------------------------------------------------------------
$router->get('/webhooks/gia/messenger', function () use ($db, $giaChLib, $giaChAck) {
    $giaChLib();
    $cfg = gia_channels_config($db)['messenger'] ?? [];
    $verify = (string) ($cfg['verify_token'] ?? '');
    $hubMode = (string) ($_GET['hub_mode'] ?? ($_GET['hub.mode'] ?? ''));
    $hubTok  = (string) ($_GET['hub_verify_token'] ?? ($_GET['hub.verify_token'] ?? ''));
    $hubCh   = (string) ($_GET['hub_challenge'] ?? ($_GET['hub.challenge'] ?? ''));
    if ($hubMode === 'subscribe' && $verify !== '' && hash_equals($verify, $hubTok)) {
        http_response_code(200); echo $hubCh; return;
    }
    $giaChAck(403, 'verify failed');
});

$router->post('/webhooks/gia/messenger', function () use ($db, $giaChLib, $giaChAck) {
    $giaChLib();
    require_once dirname(__DIR__, 2) . '/lib/ai/gia_webhook_log.php';
    $raw = file_get_contents('php://input') ?: '';
    $logIt = function (string $outcome, int $code, array $extra = []) use ($db, $raw) {
        gia_webhook_log_record($db, 'messenger', $outcome, $code, array_merge(['raw' => $raw], $extra));
    };

    if (!gia_channel_enabled($db, 'messenger')) { $logIt('disabled', 200); $giaChAck(200, 'disabled'); return; }
    $cfg = gia_channels_config($db)['messenger'] ?? [];

    $appSecret = (string) ($cfg['app_secret'] ?? '');
    $sigHeader = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    if ($appSecret === '' || !gia_ch_verify_meta_sig($raw, $sigHeader, $appSecret)) {
        $logIt('bad_signature', 403); $giaChAck(403, 'bad signature'); return;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) { $logIt('ignored', 200); $giaChAck(200, 'ignored'); return; }
    $dispatched = 0;

    // Messenger batches events under entry[].messaging[]. Handle text, button taps
    // (quick_reply/postback), and attachments (image/audio/file/video/location);
    // skip echoes (our own sends) and delivery/read receipts.
    foreach (($data['entry'] ?? []) as $entry) {
        foreach (($entry['messaging'] ?? []) as $ev) {
            if (!empty($ev['message']['is_echo'])) { continue; }
            $psid = (string) ($ev['sender']['id'] ?? '');
            if ($psid === '') { continue; }
            $mid = (string) ($ev['message']['mid'] ?? ($ev['postback']['mid'] ?? ($ev['timestamp'] ?? '')));
            $p = null;

            if (isset($ev['message']['quick_reply']['payload'])) {
                $p = ['sender' => $psid, 'id' => $mid, 'kind' => 'text', 'text' => (string) $ev['message']['quick_reply']['payload']];
            } elseif (isset($ev['postback']['payload'])) {
                $p = ['sender' => $psid, 'id' => $mid, 'kind' => 'text', 'text' => (string) $ev['postback']['payload']];
            } elseif (isset($ev['message']['text']) && $ev['message']['text'] !== '') {
                $p = ['sender' => $psid, 'id' => $mid, 'kind' => 'text', 'text' => (string) $ev['message']['text']];
            } elseif (!empty($ev['message']['attachments']) && is_array($ev['message']['attachments'])) {
                $att = $ev['message']['attachments'][0] ?? [];
                $atype = (string) ($att['type'] ?? '');
                $payload = is_array($att['payload'] ?? null) ? $att['payload'] : [];
                if ($atype === 'location') {
                    $c = $payload['coordinates'] ?? [];
                    $p = ['sender' => $psid, 'id' => $mid, 'kind' => 'location', 'media' => ['lat' => $c['lat'] ?? null, 'lng' => $c['long'] ?? null]];
                } else {
                    $kmap = ['image' => 'image', 'audio' => 'audio', 'file' => 'document', 'video' => 'video'];
                    $kind = $kmap[$atype] ?? 'other';
                    $p = ['sender' => $psid, 'id' => $mid, 'kind' => $kind, 'media' => ['url' => (string) ($payload['url'] ?? '')]];
                }
            }
            if ($p === null) { continue; }
            $logIt('ok', 200, ['sender' => (string) ($p['sender'] ?? ''), 'kind' => (string) ($p['kind'] ?? ''), 'text' => (string) ($p['text'] ?? '')]);
            gia_ch_dispatch($db, 'messenger', $p, 'fb:');
            $dispatched++;
        }
    }
    if ($dispatched === 0) { $logIt('ignored', 200); }
    $giaChAck(200, 'ok');
});

// ----------------------------------------------------------------------------
// Shared dispatch: take a normalized parse ['sender','id','kind','text'?,'media'?]
// and drive the right path for every channel — text/buttons -> the agent spine;
// voice/image/doc/location -> gia_media_handle() (download + STT/OCR/geocode) which
// returns either text to feed the agent, or a direct reply. ONE place so all three
// adapters behave identically. $db/$globals come from the including route scope.
// ----------------------------------------------------------------------------
if (!function_exists('gia_ch_dispatch')) {
    function gia_ch_dispatch($db, string $channel, array $p, string $idPrefix): void
    {
        $sender = (string) ($p['sender'] ?? '');
        $kind   = (string) ($p['kind'] ?? 'other');
        $msgId  = $idPrefix . (string) ($p['id'] ?? '');
        if ($sender === '') { return; }

        $send = function (string $reply, array $rich = []) use ($db, $channel, $sender) {
            if (trim($reply) === '' && $rich === []) { return; }
            if (function_exists('gia_channel_send')) {
                gia_channel_send($db, $channel, $sender, $reply, $rich);
            }
        };

        // TEXT / tapped-button -> the agent spine (handles its own reply + rich).
        if ($kind === 'text') {
            $text = (string) ($p['text'] ?? '');
            if (trim($text) === '') { return; }
            $res = \App\lib\ai\gia_channel_handle($db, $channel, $sender, $text, $msgId);
            if (empty($res['skip']) && (!empty($res['reply']) || !empty($res['rich']))) {
                $send((string) ($res['reply'] ?? ''), is_array($res['rich'] ?? null) ? $res['rich'] : []);
            }
            return;
        }

        // MEDIA / LOCATION -> gia_media_handle: either returns text to run through
        // the agent, or a ready reply to send as-is. Dedupe on the platform msg id
        // the same way the spine does (so a webhook retry doesn't double-process).
        require_once dirname(__DIR__, 2) . '/lib/ai/gia_media.php';
        if (function_exists('App\\lib\\ai\\gia_channel_seen_before')
            && \App\lib\ai\gia_channel_seen_before($db, $channel, $msgId)) {
            return; // already handled this media message
        }
        $ref = is_array($p['media'] ?? null) ? $p['media'] : [];
        $out = \App\lib\ai\gia_media_handle($db, $channel, $kind, $ref);
        if (($out['mode'] ?? '') === 'text' && trim((string) ($out['text'] ?? '')) !== '') {
            // Feed the transcript / extracted details / location to the agent. Use a
            // distinct derived id so the agent's own dedupe doesn't clash with ours.
            $res = \App\lib\ai\gia_channel_handle($db, $channel, $sender, (string) $out['text'], $msgId . ':m');
            if (empty($res['skip']) && (!empty($res['reply']) || !empty($res['rich']))) {
                $send((string) ($res['reply'] ?? ''), is_array($res['rich'] ?? null) ? $res['rich'] : []);
            }
            return;
        }
        // Direct reply (graceful fallback / engine couldn't process).
        $send((string) ($out['reply'] ?? "Tell me where you'd like to go and I'll find real options."));
    }
}

// ----------------------------------------------------------------------------
// Shared parse/verify helpers (file-local; guarded so a re-include is safe).
// ----------------------------------------------------------------------------
if (!function_exists('gia_ch_verify_meta_sig')) {
    /** Verify Meta's X-Hub-Signature-256: 'sha256=' . HMAC-SHA256(rawBody, appSecret). */
    function gia_ch_verify_meta_sig(string $rawBody, string $header, string $appSecret): bool
    {
        if (strpos($header, 'sha256=') !== 0) { return false; }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);
        return hash_equals($expected, $header);
    }
}

if (!function_exists('gia_ch_parse_greenapi')) {
    /**
     * Green API incomingMessageReceived -> normalized parse, or null to ignore.
     * Return shape: ['sender'=>, 'id'=>, 'kind'=>'text'|'audio'|'image'|'document'|
     * 'video'|'location'|'other', 'text'=>?, 'media'=>?[download_url,mime]].
     * chatId looks like '2349128543573@c.us'; keep only the digits as the sender id.
     */
    function gia_ch_parse_greenapi(array $d): ?array
    {
        if (($d['typeWebhook'] ?? '') !== 'incomingMessageReceived') { return null; }
        $chatId = (string) ($d['senderData']['chatId'] ?? '');
        $sender = preg_replace('/[^\d]/', '', explode('@', $chatId)[0] ?? '');
        $id = (string) ($d['idMessage'] ?? '');
        if ($sender === '') { return null; }
        $md = $d['messageData'] ?? [];
        $type = (string) ($md['typeMessage'] ?? '');

        if ($type === 'textMessage') {
            return ['sender' => $sender, 'id' => $id, 'kind' => 'text', 'text' => (string) ($md['textMessageData']['textMessage'] ?? '')];
        }
        if ($type === 'extendedTextMessage') {
            return ['sender' => $sender, 'id' => $id, 'kind' => 'text', 'text' => (string) ($md['extendedTextMessageData']['text'] ?? '')];
        }
        if ($type === 'locationMessage') {
            $loc = $md['locationMessageData'] ?? [];
            return ['sender' => $sender, 'id' => $id, 'kind' => 'location', 'media' => ['lat' => $loc['latitude'] ?? null, 'lng' => $loc['longitude'] ?? null]];
        }
        // Media messages carry fileMessageData.downloadUrl + mimeType.
        $mediaTypes = ['imageMessage' => 'image', 'audioMessage' => 'audio', 'documentMessage' => 'document', 'videoMessage' => 'video', 'stickerMessage' => 'other'];
        if (isset($mediaTypes[$type])) {
            $fm = $md['fileMessageData'] ?? [];
            return [
                'sender' => $sender, 'id' => $id, 'kind' => $mediaTypes[$type],
                'media' => ['download_url' => (string) ($fm['downloadUrl'] ?? ''), 'mime' => (string) ($fm['mimeType'] ?? '')],
            ];
        }
        return ['sender' => $sender, 'id' => $id, 'kind' => 'other'];
    }
}

if (!function_exists('gia_ch_parse_meta_whatsapp')) {
    /**
     * Meta Cloud API WhatsApp inbound -> normalized parse (same shape as
     * gia_ch_parse_greenapi) or null. Handles text, interactive replies, media
     * (image/audio/document/video carry a media_id to resolve), and location.
     */
    function gia_ch_parse_meta_whatsapp(array $d): ?array
    {
        foreach (($d['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];
                foreach (($value['messages'] ?? []) as $m) {
                    $type = (string) ($m['type'] ?? '');
                    $from = preg_replace('/[^\d]/', '', (string) ($m['from'] ?? ''));
                    $id   = (string) ($m['id'] ?? '');
                    if ($from === '') { continue; }

                    if ($type === 'text') {
                        $t = (string) ($m['text']['body'] ?? '');
                        if ($t !== '') { return ['sender' => $from, 'id' => $id, 'kind' => 'text', 'text' => $t]; }
                        continue;
                    }
                    if ($type === 'interactive') {
                        $t = (string) ($m['interactive']['button_reply']['id'] ?? ($m['interactive']['list_reply']['id'] ?? ''));
                        if ($t !== '') { return ['sender' => $from, 'id' => $id, 'kind' => 'text', 'text' => $t]; }
                        continue;
                    }
                    if ($type === 'location') {
                        $loc = $m['location'] ?? [];
                        return ['sender' => $from, 'id' => $id, 'kind' => 'location', 'media' => ['lat' => $loc['latitude'] ?? null, 'lng' => $loc['longitude'] ?? null]];
                    }
                    $mediaTypes = ['image' => 'image', 'audio' => 'audio', 'voice' => 'audio', 'document' => 'document', 'video' => 'video', 'sticker' => 'other'];
                    if (isset($mediaTypes[$type])) {
                        $mObj = $m[$type] ?? [];
                        return [
                            'sender' => $from, 'id' => $id, 'kind' => $mediaTypes[$type],
                            'media' => ['media_id' => (string) ($mObj['id'] ?? ''), 'mime' => (string) ($mObj['mime_type'] ?? '')],
                        ];
                    }
                    return ['sender' => $from, 'id' => $id, 'kind' => 'other'];
                }
            }
        }
        return null;
    }
}

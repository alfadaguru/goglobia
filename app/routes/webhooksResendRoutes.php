<?php
// ============================================================================
// RESEND WEBHOOK — campaign delivery analytics (opens / deliveries / bounces …)
// ----------------------------------------------------------------------------
// Public, unauthenticated endpoint that receives Resend's asynchronous email
// events. Resend signs webhooks with Svix, so verification is NOT the same
// single-HMAC recipe as the Paystack webhook — see "SIGNATURE VERIFICATION".
//
// Register this URL in the Resend Dashboard (Webhooks > Add Endpoint) and copy
// the generated "Signing Secret" (starts with `whsec_`) into:
//       Admin > Settings > Email > Resend > Webhook Secret
// which stores it at settings.email_providers_config -> resend.webhook_secret.
//       https://<your-domain>/webhooks/resend/events
//
// We DO NOT get our own send id back from SENDEMAIL() (it returns bool only), so
// events are correlated to a campaign recipient by the RECIPIENT EMAIL ADDRESS,
// matched to the most-recent email_campaign_log row for that address. This is a
// deliberate trade-off to avoid changing core SENDEMAIL across the whole app.
// The FIRST event we see for a recipient also stores Resend's email_id
// (data.email_id) into email_campaign_log.resend_id, so subsequent events for
// the same message can be matched by id directly (more precise than email).
//
// ⚠️ CONFIDENCE NOTE — the Svix HMAC construction below (signed string =
//    "{svix-id}.{svix-timestamp}.{rawBody}", secret = base64-decode of the part
//    after the `whsec_` prefix, HMAC-SHA256, base64-encoded, compared against the
//    space-separated `v1,<sig>` entries in svix-signature) is implemented to the
//    documented Svix spec but was NOT validated against a live Resend delivery in
//    this environment. VALIDATE with a real Resend test webhook before relying on
//    the signature gate in production. Until then, treat a verified event as
//    "probably authentic" — it only writes analytics timestamps, never money or
//    auth state, so the blast radius of a mis-verify is limited to stats.
// ============================================================================

@$SECURE or die('Access Denied!');

$router->post('webhooks/resend/events', function () use ($SECURE, $db) {

    // Plain-text ack helper. Resend retries on non-2xx; once we have understood a
    // request (valid signature, whether or not it was an event we act on) we
    // return 200 so it stops retrying. Bad signature → 401 (do not trust).
    $ack = function (int $code = 200, string $body = 'OK') {
        http_response_code($code);
        header('Content-Type: text/plain');
        echo $body;
        exit;
    };

    // Always read the RAW body first — the signature is computed over these exact
    // bytes, so json_decode()+re-encode would break verification.
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        error_log('RESEND WEBHOOK | empty body');
        $ack(200, 'ignored');
    }

    // ---- Resolve the Svix signing secret from the resend provider config. ----
    $secret = '';
    try {
        $cfgJson = $db->get('settings', 'email_providers_config', ['id' => 1]);
        $cfg = json_decode((string) $cfgJson, true);
        if (is_array($cfg)) {
            $secret = trim((string) ($cfg['resend']['webhook_secret'] ?? ''));
        }
    } catch (\Throwable $e) {
        error_log('RESEND WEBHOOK | could not read webhook secret: ' . $e->getMessage());
    }
    if ($secret === '') {
        error_log('RESEND WEBHOOK | no webhook secret configured — cannot verify signature');
        $ack(200, 'unconfigured');
    }

    // ---- SIGNATURE VERIFICATION (Svix). -------------------------------------
    // Svix headers (lowercase, so $_SERVER uppercases + HTTP_ prefixes them).
    $svixId   = $_SERVER['HTTP_SVIX_ID']        ?? ($_SERVER['HTTP_WEBHOOK_ID']        ?? '');
    $svixTs   = $_SERVER['HTTP_SVIX_TIMESTAMP'] ?? ($_SERVER['HTTP_WEBHOOK_TIMESTAMP'] ?? '');
    $svixSig  = $_SERVER['HTTP_SVIX_SIGNATURE'] ?? ($_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? '');
    if ($svixId === '' || $svixTs === '' || $svixSig === '') {
        error_log('RESEND WEBHOOK | missing svix headers');
        $ack(401, 'missing signature headers');
    }

    // Replay window: reject timestamps more than ~5 minutes from now (either way).
    $ts = (int) $svixTs;
    if ($ts <= 0 || abs(time() - $ts) > 300) {
        error_log('RESEND WEBHOOK | timestamp outside tolerance window');
        $ack(401, 'stale timestamp');
    }

    // Secret: strip the `whsec_` prefix, then base64-decode the remainder to raw
    // key bytes. (Svix secrets are "whsec_" + base64.)
    $secretBody = (strpos($secret, 'whsec_') === 0) ? substr($secret, 6) : $secret;
    $keyBytes = base64_decode($secretBody, true);
    if ($keyBytes === false || $keyBytes === '') {
        error_log('RESEND WEBHOOK | webhook secret is not valid base64');
        $ack(200, 'unconfigured'); // misconfigured secret — do not loop Resend
    }

    // Signed content = "{id}.{timestamp}.{rawBody}"; HMAC-SHA256 -> base64.
    $signedContent = $svixId . '.' . $svixTs . '.' . $raw;
    $expected = base64_encode(hash_hmac('sha256', $signedContent, $keyBytes, true));

    // svix-signature is a space-separated list of "v1,<base64sig>" (there can be
    // more than one, e.g. during secret rotation). Accept if ANY v1 entry matches.
    $matched = false;
    foreach (explode(' ', trim($svixSig)) as $part) {
        if ($part === '') { continue; }
        $pieces = explode(',', $part, 2);
        if (count($pieces) !== 2) { continue; }
        [$ver, $sig] = $pieces;
        if ($ver !== 'v1') { continue; } // only v1 (HMAC-SHA256) supported
        if (hash_equals($expected, $sig)) { $matched = true; break; }
    }
    if (!$matched) {
        error_log('RESEND WEBHOOK | invalid signature');
        $ack(401, 'invalid signature');
    }

    // ---- Parse the verified payload. ----------------------------------------
    $payload = json_decode($raw, true);
    if (!is_array($payload) || empty($payload['type']) || !isset($payload['data']) || !is_array($payload['data'])) {
        error_log('RESEND WEBHOOK | verified but unparseable payload');
        $ack(200, 'ignored');
    }

    $type = (string) $payload['type'];
    $data = $payload['data'];

    // Recipient: Resend `data.to` is an ARRAY of addresses. Use the first.
    $to = $data['to'] ?? null;
    $recipient = '';
    if (is_array($to)) {
        $recipient = (string) ($to[0] ?? '');
    } elseif (is_string($to)) {
        $recipient = $to;
    }
    $recipient = strtolower(trim($recipient));

    $emailId = (string) ($data['email_id'] ?? ($data['id'] ?? ''));

    // Event timestamp: prefer the payload's created_at; fall back to "now".
    $eventAt = date('Y-m-d H:i:s');
    if (!empty($payload['created_at'])) {
        $parsed = strtotime((string) $payload['created_at']);
        if ($parsed !== false) { $eventAt = date('Y-m-d H:i:s', $parsed); }
    }

    // Map the Resend event type -> the email_campaign_log column to stamp.
    // (Only analytics columns; we never flip the send `status` from a webhook.)
    $colMap = [
        'email.delivered'        => 'delivered_at',
        'email.opened'           => 'opened_at',
        'email.clicked'          => 'clicked_at',
        'email.bounced'          => 'bounced_at',
        'email.complained'       => 'complained_at',
        'email.delivery_delayed' => null, // understood, but nothing to record
        'email.sent'             => null, // we already log our own send
        'email.failed'           => null,
    ];
    // Events we do not model at all (received/scheduled/suppressed/etc.) -> ack & drop.
    if (!array_key_exists($type, $colMap)) {
        $ack(200, 'ignored:' . $type);
    }
    $col = $colMap[$type];

    // ---- Correlate to a campaign-log row. -----------------------------------
    // Prefer matching by resend_id (set on a prior event); otherwise fall back to
    // the most-recent log row for this recipient. Stamping the matched row also
    // backfills resend_id so later events hit the id path.
    try {
        $row = null;
        if ($emailId !== '') {
            $row = $db->get('email_campaign_log', ['id', 'resend_id'], ['resend_id' => $emailId]);
        }
        if (!$row && $recipient !== '') {
            $row = $db->get('email_campaign_log', ['id', 'resend_id'], [
                'email'  => $recipient,
                'ORDER'  => ['id' => 'DESC'],
            ]);
        }

        // Suppress bad addresses at the SOURCE so future campaigns don't re-mail
        // them (deliverability death-spiral + Gmail/Yahoo bulk-sender penalties).
        // Keyed on the recipient email; harmless if they aren't a subscriber.
        // Only ever moves an *active* subscriber out — never resurrects one that
        // already unsubscribed/bounced, and never touches pending opt-ins.
        if ($recipient !== '' && ($type === 'email.bounced' || $type === 'email.complained')) {
            try {
                $newStatus = ($type === 'email.bounced') ? 'bounced' : 'unsubscribed';
                // Scoped to currently-'subscribed' rows: we only ever move an
                // active subscriber out, so stamping unsubscribed_at here is always
                // the first time it leaves — no existing value to preserve.
                $db->update('newsletter_subscribers',
                    [
                        'status'          => $newStatus,
                        'unsubscribed_at' => date('Y-m-d H:i:s'),
                    ],
                    ['email' => $recipient, 'status' => 'subscribed']);
            } catch (\Throwable $e) {
                error_log('RESEND WEBHOOK | suppress ' . $type . ': ' . $e->getMessage());
            }
        }

        if ($row) {
            // Backfill resend_id so later events for this message match by id.
            if ($emailId !== '' && empty($row['resend_id'])) {
                try { $db->update('email_campaign_log', ['resend_id' => $emailId], ['id' => $row['id'], 'resend_id' => null]); }
                catch (\Throwable $e) { /* best-effort */ }
            }
            // Stamp the timestamp column ATOMICALLY — `WHERE id = ? AND col IS NULL`
            // means the first concurrent event wins and a later duplicate event
            // can't overwrite it (opens/clicks fire repeatedly). Read-then-write
            // would race; this cannot.
            if ($col !== null) {
                try { $db->update('email_campaign_log', [$col => $eventAt], ['id' => $row['id'], $col => null]); }
                catch (\Throwable $e) { error_log('RESEND WEBHOOK | stamp ' . $col . ': ' . $e->getMessage()); }
            }
            $ack(200, 'recorded:' . $type);
        }

        // No matching campaign-log row (e.g. a transactional email, not a
        // campaign). We may still have suppressed the address above; either way
        // it's understood and verified, so ack.
        $ack(200, 'no-match');
    } catch (\Throwable $e) {
        error_log('RESEND WEBHOOK | DB error recording event: ' . $e->getMessage());
        $ack(200, 'error-handled'); // verified request; don't force endless retries
    }
});

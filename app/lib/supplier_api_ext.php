<?php
// FILE: app/lib/supplier_api_ext.php
// MODULE 59 — API & integrations, DEEPENING (PMS Phase 3).
//
// WHAT THE BASE ALREADY HAS (do NOT rebuild — see app/lib/supplier_growth.php):
//   integration_catalogue / integration_config_get / integration_config_save /
//   integration_status  — per-org channel-manager/RMS/AI config, honest 'stub' status.
//   The honest Integrations info page (app/views/supplier/integrations.php).
//
// WHAT THIS ADDS (the genuine gaps the base never built):
//   1. OUTBOUND WEBHOOK ENDPOINTS — a supplier registers its own HTTPS URL + a signing
//      secret and selects which event types to receive (booking.created, …). Real
//      records in `supplier_webhook_endpoints`.
//   2. WEBHOOK DELIVERY LOG with IDEMPOTENCY + RETRY accounting — every event we want to
//      push to an endpoint becomes one row in `supplier_webhook_deliveries`, keyed by a
//      unique (endpoint_id, event_key) so re-enqueuing the same event is a no-op
//      (idempotent). Retry count / next-attempt are tracked. **HONEST STUB**: there is no
//      background HTTP delivery worker wired in this install, so deliveries are recorded
//      as 'pending' and reported as "queued — delivery worker not connected". We never
//      claim an event was actually POSTed to the endpoint.
//   3. A read model for the developer docs page (events + endpoint list).
//
// NO MONEY. Nothing here touches GL / folios / earnings — webhooks are notifications,
// not transactions. All org-scoped and owner-gated at the route layer.
// Every function if_not_exists-guarded. NOT runtime-verified (no DB here) — logic + php -l.
//
// DB-GATED: needs the two additive tables in schema_sql[] (supplier_webhook_endpoints,
// supplier_webhook_deliveries). Functions fail soft (return []/false) until they exist.

// ---------------------------------------------------------------------------
// EVENT CATALOGUE — the webhook event types a supplier can subscribe to.
// Keys are stable contract strings; never faked — each maps to a real platform
// activity that the enqueue helper below can be called for.
// ---------------------------------------------------------------------------
if (!function_exists('supplier_webhook_events')) {
    function supplier_webhook_events(): array
    {
        return [
            'booking.created'   => 'A new reservation was created for one of your properties.',
            'booking.cancelled' => 'A reservation was cancelled.',
            'booking.modified'  => 'A reservation’s dates, rooms or price changed.',
            'payout.sent'       => 'A payout was released to your account.',
            'review.created'    => 'A guest left a review.',
        ];
    }
}

// ---------------------------------------------------------------------------
// ENDPOINTS — a supplier's own outbound webhook receivers.
// ---------------------------------------------------------------------------
if (!function_exists('supplier_webhook_endpoint_list')) {
    function supplier_webhook_endpoint_list($db, int $orgId): array
    {
        if ($orgId <= 0) { return []; }
        try {
            return $db->select('supplier_webhook_endpoints', '*',
                ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC']]) ?: [];
        } catch (\Throwable $e) { error_log('supplier_webhook_endpoint_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('supplier_webhook_secret_gen')) {
    /** A fresh signing secret the supplier uses to verify our HMAC-SHA256 signature. */
    function supplier_webhook_secret_gen(): string
    {
        try { return 'whsec_' . bin2hex(random_bytes(24)); }
        catch (\Throwable $e) { return 'whsec_' . bin2hex(hash('sha256', uniqid('', true) . mt_rand(), true)); }
    }
}

if (!function_exists('supplier_webhook_normalize_events')) {
    /** Keep only known event keys; return a comma-joined canonical string (or '' = all). */
    function supplier_webhook_normalize_events($raw): string
    {
        $valid = array_keys(supplier_webhook_events());
        $picked = [];
        if (is_array($raw)) {
            foreach ($raw as $k) {
                $k = trim((string) $k);
                if ($k !== '' && in_array($k, $valid, true) && !in_array($k, $picked, true)) { $picked[] = $k; }
            }
        }
        return implode(',', $picked);
    }
}

if (!function_exists('supplier_webhook_endpoint_add')) {
    /**
     * Register an endpoint. URL must be absolute https:// (we never POST secrets to
     * plaintext http). Returns ['ok'=>bool,'id'=>int,'secret'=>string,'error'=>string].
     * The plaintext secret is returned ONCE here for the supplier to copy.
     */
    function supplier_webhook_endpoint_add($db, int $orgId, string $url, $events): array
    {
        $out = ['ok' => false, 'id' => 0, 'secret' => '', 'error' => ''];
        if ($orgId <= 0) { $out['error'] = 'No organisation.'; return $out; }
        $url = trim($url);
        $parts = parse_url($url);
        if ($url === '' || !$parts || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            $out['error'] = 'Enter a valid https:// URL.'; return $out;
        }
        if (strlen($url) > 500) { $out['error'] = 'URL too long.'; return $out; }
        try {
            // Soft cap per org to stop runaway registration.
            $existing = (int) $db->count('supplier_webhook_endpoints', ['org_id' => $orgId]);
            if ($existing >= 20) { $out['error'] = 'Endpoint limit reached (20).'; return $out; }
            $secret = supplier_webhook_secret_gen();
            $db->insert('supplier_webhook_endpoints', [
                'org_id'       => $orgId,
                'url'          => $url,
                'secret'       => $secret,
                'events'       => supplier_webhook_normalize_events($events),
                'status'       => 1,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            $id = (int) $db->id();
            if ($id <= 0) { $out['error'] = 'Could not save.'; return $out; }
            $out['ok'] = true; $out['id'] = $id; $out['secret'] = $secret;
        } catch (\Throwable $e) { error_log('supplier_webhook_endpoint_add: ' . $e->getMessage()); $out['error'] = 'Could not save.'; }
        return $out;
    }
}

if (!function_exists('supplier_webhook_endpoint_toggle')) {
    /** Enable/disable an endpoint. Org-scoped — never touches another org's row. */
    function supplier_webhook_endpoint_toggle($db, int $orgId, int $endpointId, bool $enabled): bool
    {
        if ($orgId <= 0 || $endpointId <= 0) { return false; }
        try {
            if (!$db->has('supplier_webhook_endpoints', ['id' => $endpointId, 'org_id' => $orgId])) { return false; }
            $db->update('supplier_webhook_endpoints',
                ['status' => $enabled ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $endpointId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('supplier_webhook_endpoint_toggle: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('supplier_webhook_endpoint_rotate_secret')) {
    /** Rotate (regenerate) the signing secret. Returns new plaintext secret or '' on fail. */
    function supplier_webhook_endpoint_rotate_secret($db, int $orgId, int $endpointId): string
    {
        if ($orgId <= 0 || $endpointId <= 0) { return ''; }
        try {
            if (!$db->has('supplier_webhook_endpoints', ['id' => $endpointId, 'org_id' => $orgId])) { return ''; }
            $secret = supplier_webhook_secret_gen();
            $db->update('supplier_webhook_endpoints',
                ['secret' => $secret, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $endpointId, 'org_id' => $orgId]);
            return $secret;
        } catch (\Throwable $e) { error_log('supplier_webhook_endpoint_rotate_secret: ' . $e->getMessage()); return ''; }
    }
}

if (!function_exists('supplier_webhook_endpoint_delete')) {
    function supplier_webhook_endpoint_delete($db, int $orgId, int $endpointId): bool
    {
        if ($orgId <= 0 || $endpointId <= 0) { return false; }
        try {
            if (!$db->has('supplier_webhook_endpoints', ['id' => $endpointId, 'org_id' => $orgId])) { return false; }
            // Clean up the delivery log for this endpoint too (org-scoped).
            $db->delete('supplier_webhook_deliveries', ['endpoint_id' => $endpointId, 'org_id' => $orgId]);
            $db->delete('supplier_webhook_endpoints', ['id' => $endpointId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('supplier_webhook_endpoint_delete: ' . $e->getMessage()); return false; }
    }
}

// ---------------------------------------------------------------------------
// DELIVERY LOG — idempotent enqueue + retry accounting.
// ---------------------------------------------------------------------------
if (!function_exists('supplier_webhook_endpoint_wants')) {
    /** True if the endpoint is enabled and subscribed to $eventType ('' events = all). */
    function supplier_webhook_endpoint_wants(array $endpoint, string $eventType): bool
    {
        if ((int) ($endpoint['status'] ?? 0) !== 1) { return false; }
        $ev = trim((string) ($endpoint['events'] ?? ''));
        if ($ev === '') { return true; } // subscribed to everything
        $list = array_map('trim', explode(',', $ev));
        return in_array($eventType, $list, true);
    }
}

if (!function_exists('supplier_webhook_enqueue')) {
    /**
     * Record that $eventType for $eventKey should be delivered to every enabled, subscribed
     * endpoint of $orgId. IDEMPOTENT: the unique (endpoint_id, event_key) key means calling
     * this twice for the same business event (e.g. a webhook re-fired, a page reload) inserts
     * at most one delivery row per endpoint — a duplicate insert is swallowed, not doubled.
     *
     * $eventKey must be a STABLE, caller-supplied identity for the business event
     * (e.g. "booking.created:84213") so retries dedupe correctly.
     *
     * HONEST STUB: this only RECORDS the intent as 'pending'. No HTTP POST happens here —
     * this install ships no background delivery worker. Rows stay 'pending' and the UI
     * reports "queued — delivery worker not connected". We never mark 'delivered' or fake
     * a 2xx response. Returns the number of delivery rows created.
     */
    function supplier_webhook_enqueue($db, int $orgId, string $eventType, string $eventKey, array $payload = []): int
    {
        if ($orgId <= 0) { return 0; }
        $eventType = trim($eventType);
        $eventKey  = trim($eventKey);
        if ($eventType === '' || $eventKey === '') { return 0; }
        if (!array_key_exists($eventType, supplier_webhook_events())) { return 0; }
        $created = 0;
        try {
            $endpoints = $db->select('supplier_webhook_endpoints', ['id', 'status', 'events'],
                ['org_id' => $orgId, 'status' => 1]) ?: [];
            if (!$endpoints) { return 0; }
            $body = json_encode(['event' => $eventType, 'key' => $eventKey, 'data' => $payload], JSON_UNESCAPED_SLASHES);
            if ($body === false) { $body = json_encode(['event' => $eventType, 'key' => $eventKey]); }
            foreach ($endpoints as $ep) {
                if (!supplier_webhook_endpoint_wants($ep, $eventType)) { continue; }
                $epId = (int) $ep['id'];
                // Idempotency: skip if this (endpoint,event_key) already exists.
                if ($db->has('supplier_webhook_deliveries', ['endpoint_id' => $epId, 'event_key' => $eventKey])) { continue; }
                try {
                    $db->insert('supplier_webhook_deliveries', [
                        'org_id'       => $orgId,
                        'endpoint_id'  => $epId,
                        'event_type'   => $eventType,
                        'event_key'    => $eventKey,
                        'payload_json' => $body,
                        'status'       => 'pending',   // never faked as 'delivered'
                        'attempts'     => 0,
                        'created_at'   => date('Y-m-d H:i:s'),
                    ]);
                    $created++;
                } catch (\Throwable $dupe) {
                    // Unique-key collision = another request already enqueued it. That's the
                    // idempotency guarantee working; not an error.
                    continue;
                }
            }
        } catch (\Throwable $e) { error_log('supplier_webhook_enqueue: ' . $e->getMessage()); }
        return $created;
    }
}

if (!function_exists('supplier_webhook_delivery_list')) {
    /** Recent deliveries for an org (most recent first), capped. For the UI log table. */
    function supplier_webhook_delivery_list($db, int $orgId, int $limit = 50): array
    {
        if ($orgId <= 0) { return []; }
        $limit = max(1, min(200, $limit));
        try {
            return $db->select('supplier_webhook_deliveries',
                ['id', 'endpoint_id', 'event_type', 'event_key', 'status', 'attempts', 'last_attempt_at', 'created_at'],
                ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => $limit]) ?: [];
        } catch (\Throwable $e) { error_log('supplier_webhook_delivery_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('supplier_webhook_delivery_retry')) {
    /**
     * Mark a non-delivered delivery row for another attempt: bumps attempts, stamps
     * last_attempt_at, and resets status to 'pending'. HONEST STUB — it does NOT perform
     * the HTTP POST (no worker). It only records that a retry was requested so retry
     * accounting is truthful once a worker is wired. Returns false if the row is already
     * 'delivered', not this org's, or attempts are exhausted.
     */
    function supplier_webhook_delivery_retry($db, int $orgId, int $deliveryId): bool
    {
        if ($orgId <= 0 || $deliveryId <= 0) { return false; }
        try {
            $row = $db->get('supplier_webhook_deliveries', ['status', 'attempts'],
                ['id' => $deliveryId, 'org_id' => $orgId]);
            if (!$row) { return false; }
            if ((string) $row['status'] === 'delivered') { return false; }
            $attempts = (int) $row['attempts'];
            if ($attempts >= 8) {
                // Exhausted: park it as 'failed' so the UI stops offering retry — honest.
                $db->update('supplier_webhook_deliveries',
                    ['status' => 'failed', 'last_attempt_at' => date('Y-m-d H:i:s')],
                    ['id' => $deliveryId, 'org_id' => $orgId]);
                return false;
            }
            $db->update('supplier_webhook_deliveries', [
                'status'          => 'pending',
                'attempts'        => $attempts + 1,
                'last_attempt_at' => date('Y-m-d H:i:s'),
            ], ['id' => $deliveryId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('supplier_webhook_delivery_retry: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('supplier_webhook_signature')) {
    /**
     * The HMAC-SHA256 signature a real delivery worker WOULD send as the
     * `X-GoGlobia-Signature` header, computed over the raw JSON body with the endpoint
     * secret. Exposed so the docs page can show the exact verification recipe, and so a
     * future worker reuses one canonical signing function (no drift).
     */
    function supplier_webhook_signature(string $secret, string $rawBody): string
    {
        return 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
    }
}

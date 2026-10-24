<?php
// ============================================================================
// FILE: app/lib/ai/gia_crm.php
// GIA persistence + CRM helpers. Sits on top of gia_analytics.php (the schema).
//
// Responsibilities (all FAIL-SOFT — must NEVER break the chat):
//   - gia_browser_id()        : stable per-browser id (cookie) for cross-tab/device.
//   - gia_conv_get_or_create(): find/open the conversation row for this browser/user.
//   - gia_conv_history()      : rehydrate prior messages (so a new tab resumes).
//   - gia_log_turn()          : persist a user+assistant turn, roll usage.
//   - gia_log_event()         : persist a structured outcome (search/book/...).
//   - gia_lead_capture()      : match-or-create a lead against the users table.
//
// Identity: users are keyed on users.user_id (the long string), consistent with
// gia_checkouts.user_id. A conversation can be anonymous (browser_id only), tied
// to a lead (email/phone given), and/or tied to a real user (logged in or matched).
// ============================================================================

if (!function_exists('gia_browser_id')) {
    /**
     * Stable browser id for cross-tab/device continuity. Read from the
     * gia_bid cookie; minted + set if absent. 32 hex chars, 1-year cookie.
     * Returns '' only if headers are already sent and no cookie exists.
     */
    function gia_browser_id(): string
    {
        $existing = (string) ($_COOKIE['gia_bid'] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $existing)) {
            return $existing;
        }
        try {
            $bid = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            $bid = md5(uniqid('gia', true));
        }
        // Best-effort cookie; if headers are already sent this silently no-ops and
        // we still return the id for this request (session cache carries it).
        if (!headers_sent()) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            @setcookie('gia_bid', $bid, [
                'expires'  => time() + 31536000,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => false, // readable by the widget JS is fine; it's not a secret
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE['gia_bid'] = $bid;
        // First-touch ad-source capture (sibling of the browser id). If this
        // landing carries ad params and no gia_src cookie exists yet, persist a
        // JSON snapshot (30-day) so the ad source survives across pages until
        // the chat starts. Never overwrite an existing cookie (first-touch).
        gia_capture_landing_source();
        return $bid;
    }
}

if (!function_exists('gia_capture_landing_source')) {
    /**
     * Fail-soft, first-touch ad-source capture. If the current request has any
     * recognised ad params in $_GET and no gia_src cookie is set yet, write a
     * 30-day gia_src cookie holding a JSON map of just the present params.
     * No-op if a gia_src cookie already exists or headers are already sent.
     */
    function gia_capture_landing_source(): void
    {
        if (!empty($_COOKIE['gia_src'])) {
            return; // first-touch: never overwrite an existing source
        }
        $keys = ['utm_source', 'utm_campaign', 'utm_medium', 'fbclid', 'gclid', 'ttclid'];
        $src = [];
        foreach ($keys as $k) {
            if (isset($_GET[$k]) && $_GET[$k] !== '') {
                // keep it small + safe; cookie is non-authoritative metadata
                $src[$k] = substr((string) $_GET[$k], 0, 256);
            }
        }
        if (!$src) {
            return; // no ad params on this landing
        }
        $json = json_encode($src);
        if (!is_string($json)) {
            return;
        }
        if (!headers_sent()) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            @setcookie('gia_src', $json, [
                'expires'  => time() + 2592000, // 30 days
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE['gia_src'] = $json;
    }
}

if (!function_exists('gia_landing_source')) {
    /**
     * Return the captured ad-source as an array. Prefers the decoded gia_src
     * cookie (first-touch, survives across pages); falls back to the live
     * $_GET ad params on the current request. Returns [] if none. Fail-soft.
     */
    function gia_landing_source(): array
    {
        $cookie = (string) ($_COOKIE['gia_src'] ?? '');
        if ($cookie !== '') {
            $decoded = json_decode($cookie, true);
            if (is_array($decoded) && $decoded) {
                return $decoded;
            }
        }
        $keys = ['utm_source', 'utm_campaign', 'utm_medium', 'fbclid', 'gclid', 'ttclid'];
        $src = [];
        foreach ($keys as $k) {
            if (isset($_GET[$k]) && $_GET[$k] !== '') {
                $src[$k] = substr((string) $_GET[$k], 0, 256);
            }
        }
        return $src;
    }
}

if (!function_exists('gia_conv_get_or_create')) {
    /**
     * Find or open the conversation for this browser/user. Returns the conv row
     * array (at least ['id'=>int, 'conv_key'=>string]) or null on failure.
     *
     * @param object $db
     * @param string $browserId
     * @param string $userId  users.user_id string ('' if anonymous)
     * @param string $context module context (umrah/stays/... or '')
     * @param string $surface 'widget' | 'page'
     */
    function gia_conv_get_or_create($db, string $browserId, string $userId = '', string $context = '', string $surface = 'widget'): ?array
    {
        if (!function_exists('ensureGiaAnalyticsSchema')) {
            return null;
        }
        ensureGiaAnalyticsSchema($db);
        try {
            // Prefer the most recent OPEN conversation for this user (if known),
            // else browser. closed=1 rows (ended via New chat) are never resumed.
            $where = null;
            if ($userId !== '') {
                $where = ['user_id' => $userId, 'closed' => 0, 'ORDER' => ['last_at' => 'DESC'], 'LIMIT' => 1];
            } elseif ($browserId !== '') {
                $where = ['browser_id' => $browserId, 'closed' => 0, 'ORDER' => ['last_at' => 'DESC'], 'LIMIT' => 1];
            } else {
                return null;
            }
            $row = $db->get('gia_conversations', '*', $where);
            if (is_array($row) && !empty($row['id'])) {
                // Upgrade an anonymous conversation to a known user when they log in.
                if ($userId !== '' && (string) ($row['user_id'] ?? '') === '') {
                    $db->update('gia_conversations', ['user_id' => $userId], ['id' => $row['id']]);
                    $row['user_id'] = $userId;
                }
                return $row;
            }

            // None yet — open a new conversation.
            $convKey = bin2hex(random_bytes(16));
            $db->insert('gia_conversations', [
                'conv_key'   => $convKey,
                'browser_id' => $browserId !== '' ? $browserId : null,
                'user_id'    => $userId !== '' ? $userId : null,
                'context'    => $context !== '' ? $context : null,
                'surface'    => $surface === 'page' ? 'page' : 'widget',
            ]);
            $id = (int) $db->id();
            if ($id <= 0) {
                return null;
            }
            return ['id' => $id, 'conv_key' => $convKey, 'user_id' => $userId, 'browser_id' => $browserId];
        } catch (\Throwable $e) {
            error_log('[gia] conv_get_or_create: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('gia_conv_get_or_create_channel')) {
    /**
     * Messaging-channel sibling of gia_conv_get_or_create(): find/open the
     * conversation for a sender on a messaging channel, keyed by
     * (channel, channel_user_id) — e.g. ('whatsapp','2349128543573'),
     * ('telegram','12345'), ('messenger','<PSID>'). Same row shape + conv_key
     * generation as the web resolver, so history/logging/reporting all reuse.
     *
     * $userId is the matched GoGlobia users.user_id when known (Phase 2 identity),
     * else '' — a conversation can run anonymously and be linked to an account later.
     *
     * @param object $db
     * @param string $channel       'whatsapp' | 'messenger' | 'telegram'
     * @param string $channelUserId the sender's id on that channel (phone/PSID/tg-id)
     * @param string $userId        users.user_id if already matched ('' otherwise)
     * @param string $context       module context ('' = generic)
     * @return array|null the conversation row, or null on failure
     */
    function gia_conv_get_or_create_channel($db, string $channel, string $channelUserId, string $userId = '', string $context = ''): ?array
    {
        $channel = strtolower(trim($channel));
        $channelUserId = trim($channelUserId);
        if (!function_exists('ensureGiaAnalyticsSchema') || $channel === '' || $channelUserId === '') {
            return null;
        }
        ensureGiaAnalyticsSchema($db);
        try {
            // Resume the most recent OPEN conversation for this channel identity.
            $row = $db->get('gia_conversations', '*', [
                'channel'         => $channel,
                'channel_user_id' => $channelUserId,
                'closed'          => 0,
                'ORDER'           => ['last_at' => 'DESC'],
                'LIMIT'           => 1,
            ]);
            if (is_array($row) && !empty($row['id'])) {
                // Link to a real account once identity is established (Phase 2).
                if ($userId !== '' && (string) ($row['user_id'] ?? '') === '') {
                    $db->update('gia_conversations', ['user_id' => $userId], ['id' => $row['id']]);
                    $row['user_id'] = $userId;
                }
                return $row;
            }

            $convKey = bin2hex(random_bytes(16));
            $db->insert('gia_conversations', [
                'conv_key'        => $convKey,
                'user_id'         => $userId !== '' ? $userId : null,
                'context'         => $context !== '' ? $context : null,
                'surface'         => $channel,   // reporting: surface shows the channel
                'channel'         => $channel,
                'channel_user_id' => $channelUserId,
            ]);
            $id = (int) $db->id();
            if ($id <= 0) {
                return null;
            }
            return [
                'id' => $id, 'conv_key' => $convKey, 'user_id' => $userId,
                'channel' => $channel, 'channel_user_id' => $channelUserId,
            ];
        } catch (\Throwable $e) {
            error_log('[gia] conv_get_or_create_channel: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('gia_conv_history')) {
    /**
     * Rehydrate the canonical message history for a conversation so a new tab /
     * refresh / device resumes the same chat. Returns an array of
     * ['role'=>'user'|'assistant', 'text'=>string], oldest-first, bounded.
     *
     * @return array<int,array{role:string,text:string}>
     */
    function gia_conv_history($db, int $convId, int $limit = 20): array
    {
        if ($convId <= 0 || !function_exists('ensureGiaAnalyticsSchema')) {
            return [];
        }
        ensureGiaAnalyticsSchema($db);
        try {
            $rows = $db->select('gia_messages', ['role', 'text'], [
                'conv_id' => $convId,
                'ORDER' => ['id' => 'DESC'],
                'LIMIT' => max(1, min(100, $limit)),
            ]) ?: [];
            $rows = array_reverse($rows); // back to oldest-first
            $out = [];
            foreach ($rows as $r) {
                $role = (string) ($r['role'] ?? '');
                if ($role !== 'user' && $role !== 'assistant') {
                    continue;
                }
                $out[] = ['role' => $role, 'text' => (string) ($r['text'] ?? '')];
            }
            return $out;
        } catch (\Throwable $e) {
            error_log('[gia] conv_history: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('gia_log_turn')) {
    /**
     * Persist one exchange: the user message + GIA's reply, and roll token usage /
     * counters onto the conversation. Fail-soft.
     *
     * @param array $usage  ['input_tokens'=>int,'output_tokens'=>int]
     */
    function gia_log_turn($db, int $convId, string $userText, string $assistantText, array $usage = []): void
    {
        if ($convId <= 0 || !function_exists('ensureGiaAnalyticsSchema')) {
            return;
        }
        ensureGiaAnalyticsSchema($db);
        try {
            if ($userText !== '') {
                $db->insert('gia_messages', ['conv_id' => $convId, 'role' => 'user', 'text' => $userText]);
            }
            if ($assistantText !== '') {
                $db->insert('gia_messages', ['conv_id' => $convId, 'role' => 'assistant', 'text' => $assistantText]);
            }
            $tin = (int) ($usage['input_tokens'] ?? 0);
            $tout = (int) ($usage['output_tokens'] ?? 0);
            $last = $assistantText !== '' ? $assistantText : $userText;
            if (function_exists('mb_substr')) {
                $last = mb_substr($last, 0, 480);
            } else {
                $last = substr($last, 0, 480);
            }
            // Atomic-ish counter bump via raw expression; Medoo update with Raw.
            $db->update('gia_conversations', [
                'msg_count[+]'  => ($userText !== '' ? 1 : 0) + ($assistantText !== '' ? 1 : 0),
                'token_in[+]'   => $tin,
                'token_out[+]'  => $tout,
                'last_message'  => $last,
            ], ['id' => $convId]);
        } catch (\Throwable $e) {
            error_log('[gia] log_turn: ' . $e->getMessage());
        }
    }
}

if (!function_exists('gia_log_event')) {
    /**
     * Persist a structured outcome event (search / view / checkout / approve /
     * pay / book / lead_capture) and bump conversation rollups where relevant.
     * Fail-soft.
     *
     * @param array $data ['module'=>?, 'ref'=>?, 'amount'=>?, 'currency'=>?, 'meta'=>?]
     */
    function gia_log_event($db, int $convId, string $type, array $data = []): void
    {
        if ($type === '' || !function_exists('ensureGiaAnalyticsSchema')) {
            return;
        }
        ensureGiaAnalyticsSchema($db);
        try {
            $amount = isset($data['amount']) && $data['amount'] !== '' ? (float) $data['amount'] : null;
            $db->insert('gia_events', [
                'conv_id'  => $convId > 0 ? $convId : null,
                'type'     => substr($type, 0, 24),
                'module'   => isset($data['module']) ? substr((string) $data['module'], 0, 24) : null,
                'ref'      => isset($data['ref']) ? substr((string) $data['ref'], 0, 120) : null,
                'amount'   => $amount,
                'currency' => isset($data['currency']) ? substr((string) $data['currency'], 0, 10) : null,
                'meta'     => isset($data['meta']) ? substr((string) $data['meta'], 0, 255) : null,
            ]);
            if ($convId > 0) {
                if ($type === 'search') {
                    $db->update('gia_conversations', ['search_count[+]' => 1], ['id' => $convId]);
                } elseif ($type === 'book') {
                    $upd = ['booking_count[+]' => 1];
                    if ($amount !== null && $amount > 0) {
                        $upd['revenue[+]'] = $amount;
                        if (!empty($data['currency'])) {
                            $upd['revenue_currency'] = substr((string) $data['currency'], 0, 10);
                        }
                    }
                    $db->update('gia_conversations', $upd, ['id' => $convId]);
                }
            }
        } catch (\Throwable $e) {
            error_log('[gia] log_event: ' . $e->getMessage());
        }
    }
}

if (!function_exists('gia_lead_capture')) {
    /**
     * Match-or-create a lead from a captured name/email/phone, link it to the
     * conversation, and tie to an existing user when the email matches. Does NOT
     * create a full user account (keeps the users table clean). Returns
     * ['ok'=>bool, 'lead_id'=>int, 'linked_user_id'=>string, 'status'=>string].
     *
     * @param array $in ['name'=>?, 'email'=>?, 'phone'=>?, 'context'=>?]
     */
    function gia_lead_capture($db, int $convId, array $in): array
    {
        if (!function_exists('ensureGiaAnalyticsSchema')) {
            return ['ok' => false, 'lead_id' => 0, 'linked_user_id' => '', 'status' => ''];
        }
        ensureGiaAnalyticsSchema($db);

        $name = trim((string) ($in['name'] ?? ''));
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $phone = trim((string) ($in['phone'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }
        // Keep only sane phone characters.
        if ($phone !== '') {
            $phone = preg_replace('/[^0-9+\-\s()]/', '', $phone) ?? '';
            $phone = trim($phone);
            if (strlen($phone) < 6 || strlen($phone) > 40) {
                $phone = '';
            }
        }
        if ($email === '' && $phone === '') {
            return ['ok' => false, 'lead_id' => 0, 'linked_user_id' => '', 'status' => ''];
        }

        try {
            // 1) Match an existing user by email (then phone) — link, never create account.
            $linkedUserId = '';
            if ($email !== '') {
                $u = $db->get('users', ['user_id'], ['email' => $email]);
                if (is_array($u) && !empty($u['user_id'])) {
                    $linkedUserId = (string) $u['user_id'];
                }
            }
            if ($linkedUserId === '' && $phone !== '') {
                $u = $db->get('users', ['user_id'], ['phone' => $phone]);
                if (is_array($u) && !empty($u['user_id'])) {
                    $linkedUserId = (string) $u['user_id'];
                }
            }

            // 2) Find an existing lead (same email or phone) to update rather than dup.
            $leadRow = null;
            if ($email !== '') {
                $leadRow = $db->get('gia_leads', '*', ['email' => $email]);
            }
            if (!$leadRow && $phone !== '') {
                $leadRow = $db->get('gia_leads', '*', ['phone' => $phone]);
            }

            $status = $linkedUserId !== '' ? 'linked' : 'lead';
            $context = isset($in['context']) ? substr((string) $in['context'], 0, 40) : null;

            if (is_array($leadRow) && !empty($leadRow['id'])) {
                $leadId = (int) $leadRow['id'];
                $upd = ['status' => $status];
                if ($name !== '') { $upd['name'] = substr($name, 0, 160); }
                if ($email !== '') { $upd['email'] = $email; }
                if ($phone !== '') { $upd['phone'] = $phone; }
                if ($linkedUserId !== '') { $upd['user_id'] = $linkedUserId; }
                $db->update('gia_leads', $upd, ['id' => $leadId]);
            } else {
                $db->insert('gia_leads', [
                    'name'    => $name !== '' ? substr($name, 0, 160) : null,
                    'email'   => $email !== '' ? $email : null,
                    'phone'   => $phone !== '' ? $phone : null,
                    'user_id' => $linkedUserId !== '' ? $linkedUserId : null,
                    'status'  => $status,
                    'source'  => 'gia',
                    'context' => $context,
                ]);
                $leadId = (int) $db->id();
            }

            // 3) Link the conversation to the lead (and user).
            if ($convId > 0 && $leadId > 0) {
                $upd = ['lead_id' => $leadId];
                if ($linkedUserId !== '') {
                    $upd['user_id'] = $linkedUserId;
                }
                $db->update('gia_conversations', $upd, ['id' => $convId]);
                gia_log_event($db, $convId, 'lead_capture', [
                    'ref'  => $email !== '' ? $email : $phone,
                    'meta' => $status,
                ]);
            }

            return ['ok' => true, 'lead_id' => $leadId, 'linked_user_id' => $linkedUserId, 'status' => $status];
        } catch (\Throwable $e) {
            error_log('[gia] lead_capture: ' . $e->getMessage());
            return ['ok' => false, 'lead_id' => 0, 'linked_user_id' => '', 'status' => ''];
        }
    }
}

if (!function_exists('gia_user_profile')) {
    /**
     * Resolve a logged-in / matched user into the SAFE personalization facts GIA
     * may know about them — so the agent can greet by name and tailor replies.
     * Keyed on users.user_id (the long string), per this file's identity convention
     * and gia_checkouts.user_id. Returns an empty array for guests or on any error
     * (FAIL-SOFT — must never break the chat). This is channel-agnostic: the web
     * route and the future messaging adapters all resolve identity the same way.
     *
     * Deliberately NARROW: first name + preferred currency only. It never returns
     * email/phone/address or anything that would be PII to echo into a model prompt.
     *
     * @param object $db
     * @param string $userId users.user_id ('' => guest => [])
     * @return array{first_name?:string,full_name?:string,currency?:string,role?:string}
     */
    function gia_user_profile($db, string $userId): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return [];
        }
        try {
            $u = $db->get('users', ['first_name', 'last_name', 'currency', 'role'], ['user_id' => $userId]);
            if (!is_array($u) || $u === []) {
                return [];
            }
            $first = trim((string) ($u['first_name'] ?? ''));
            $last  = trim((string) ($u['last_name'] ?? ''));
            $out = [];
            if ($first !== '') { $out['first_name'] = $first; }
            $full = trim($first . ' ' . $last);
            if ($full !== '') { $out['full_name'] = $full; }
            $cur = trim((string) ($u['currency'] ?? ''));
            if ($cur !== '') { $out['currency'] = strtoupper($cur); }
            $role = trim((string) ($u['role'] ?? ''));
            if ($role !== '') { $out['role'] = $role; }
            return $out;
        } catch (\Throwable $e) {
            error_log('[gia] user_profile: ' . $e->getMessage());
            return [];
        }
    }
}

// ============================================================================
// LIVE-OFFER CACHE (DB-backed) — replaces the old $_SESSION['gia_live_offers'].
// fetch_live_offers stores each live offer's STABLE fields here keyed by
// (conv_id, module:ref); select_option reads them back. Must be the DB (not the
// session) so it survives stateless messaging webhooks — a PHP session does not
// exist on a WhatsApp/Telegram/Messenger request. Short-lived + bounded.
// ============================================================================

if (!function_exists('gia_offer_cache_ensure_schema')) {
    function gia_offer_cache_ensure_schema($db): void
    {
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `gia_offer_cache` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `conv_id` INT(11) NOT NULL,
                `cache_key` VARCHAR(160) NOT NULL,
                `payload` LONGTEXT DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_conv_key` (`conv_id`,`cache_key`),
                KEY `conv_id` (`conv_id`),
                KEY `created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            error_log('[gia] offer_cache schema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('gia_offer_cache_put')) {
    /**
     * Store one live offer's stable fields for a conversation. $key is the same
     * "module:ref" the tools already use. Idempotent upsert. No-op if conv_id<=0.
     */
    function gia_offer_cache_put($db, int $convId, string $key, array $value): void
    {
        if ($convId <= 0 || $key === '') { return; }
        gia_offer_cache_ensure_schema($db);
        try {
            $json = json_encode($value);
            $exists = $db->get('gia_offer_cache', ['id'], ['conv_id' => $convId, 'cache_key' => $key]);
            if (is_array($exists) && !empty($exists['id'])) {
                $db->update('gia_offer_cache', ['payload' => $json], ['id' => $exists['id']]);
            } else {
                $db->insert('gia_offer_cache', ['conv_id' => $convId, 'cache_key' => $key, 'payload' => $json]);
            }
            // Bound: keep only the most recent 60 rows for this conversation.
            $ids = $db->select('gia_offer_cache', ['id'], [
                'conv_id' => $convId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => [60, 1000000],
            ]);
            if (is_array($ids) && $ids !== []) {
                $old = array_map(static fn($r) => (int) $r['id'], $ids);
                if ($old !== []) { $db->delete('gia_offer_cache', ['id' => $old]); }
            }
        } catch (\Throwable $e) {
            error_log('[gia] offer_cache put: ' . $e->getMessage());
        }
    }
}

if (!function_exists('gia_offer_cache_get')) {
    /** Retrieve one cached offer (decoded array) or null. */
    function gia_offer_cache_get($db, int $convId, string $key): ?array
    {
        if ($convId <= 0 || $key === '') { return null; }
        gia_offer_cache_ensure_schema($db);
        try {
            $row = $db->get('gia_offer_cache', ['payload'], ['conv_id' => $convId, 'cache_key' => $key]);
            if (is_array($row) && isset($row['payload'])) {
                $v = json_decode((string) $row['payload'], true);
                return is_array($v) ? $v : null;
            }
        } catch (\Throwable $e) {
            error_log('[gia] offer_cache get: ' . $e->getMessage());
        }
        return null;
    }
}

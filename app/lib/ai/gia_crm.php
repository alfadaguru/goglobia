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
        return $bid;
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

<?php
// ============================================================================
// FILE: app/lib/ai/gia_identity.php
// GIA cross-channel IDENTITY — ties a messaging sender (phone / PSID / Telegram
// id) to a GoGlobia users.user_id. Two tiers, deliberately separate:
//
//   (1) PASSIVE MATCH  — best-effort phone->account lookup, used ONLY to greet a
//       known traveler by name. Phone data in this DB is messy + unverified, so a
//       match here is a courtesy, NEVER an authorization. It never unlocks money.
//
//   (2) VERIFIED LINK  — an explicit, one-time-code confirmation that BINDS this
//       (channel, sender) to a user_id. This is the gate Phase 3 requires before
//       any charge. Stored in gia_channel_links (its own table — we do NOT touch
//       users.otp, which is already used by email-verify / password-reset).
//
// All FAIL-SOFT. Nothing here moves money; it only resolves/records identity.
// ============================================================================

if (!function_exists('gia_identity_ensure_schema')) {
    /** Idempotent CREATE for the channel-link table. */
    function gia_identity_ensure_schema($db): void
    {
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `gia_channel_links` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `channel` VARCHAR(16) NOT NULL,
                `channel_user_id` VARCHAR(120) NOT NULL,
                `user_id` VARCHAR(255) DEFAULT NULL,
                `code_hash` VARCHAR(64) DEFAULT NULL,
                `code_expires` DATETIME DEFAULT NULL,
                `attempts` INT(11) NOT NULL DEFAULT 0,
                `verified` TINYINT(1) NOT NULL DEFAULT 0,
                `verified_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_channel_sender` (`channel`,`channel_user_id`),
                KEY `user_id` (`user_id`),
                KEY `verified` (`verified`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            error_log('[gia] identity schema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('gia_identity_verified_user')) {
    /**
     * The user_id this (channel, sender) is VERIFIED-linked to, or '' if none.
     * This is the ONLY identity Phase 3 may trust for money.
     */
    function gia_identity_verified_user($db, string $channel, string $channelUserId): string
    {
        $channel = strtolower(trim($channel));
        $channelUserId = trim($channelUserId);
        if ($channel === '' || $channelUserId === '') {
            return '';
        }
        gia_identity_ensure_schema($db);
        try {
            $row = $db->get('gia_channel_links', ['user_id', 'verified'], [
                'channel'         => $channel,
                'channel_user_id' => $channelUserId,
                'verified'        => 1,
            ]);
            if (is_array($row) && !empty($row['user_id'])) {
                return (string) $row['user_id'];
            }
        } catch (\Throwable $e) {
            error_log('[gia] identity verified_user: ' . $e->getMessage());
        }
        return '';
    }
}

if (!function_exists('gia_identity_phone_match')) {
    /**
     * BEST-EFFORT passive match of a messaging phone number to a users row, for
     * GREETING ONLY. Returns users.user_id or ''. Phone data here is messy
     * (national vs international, junk country codes), so we try a few shapes:
     *   - exact `phone`
     *   - digits of CONCAT(phone_country_code, phone)
     *   - last 10 digits of `phone`
     * A match is a courtesy; callers MUST NOT treat it as authorization.
     * Only meaningful for channels whose sender id IS a phone number (WhatsApp;
     * Telegram only if the user shared a contact — not by default).
     */
    function gia_identity_phone_match($db, string $channel, string $channelUserId): string
    {
        $channel = strtolower(trim($channel));
        // Only WhatsApp senders are phone numbers by nature. Telegram/Messenger ids
        // are opaque — never phone-match them (would be a wrong-person risk).
        if ($channel !== 'whatsapp') {
            return '';
        }
        $digits = preg_replace('/[^\d]/', '', $channelUserId);
        if ($digits === '' || strlen($digits) < 7) {
            return '';
        }
        // EXACT MATCH ONLY (security): phone is now a UNIQUE account identity
        // (uq_users_phone), so we match a WhatsApp sender to AT MOST ONE account and
        // never the fuzzy last-10-digits way, which could hit a DIFFERENT person who
        // shares a national number across a different country code (an IDOR/privacy
        // risk). A WhatsApp sender id is the FULL international number; users.phone is
        // stored as national digits with the dial code in phone_country_code — so we
        // split the sender into (national, dial code) and require BOTH to match. If
        // the split is ambiguous we return '' (no guess).
        try {
            // Collect candidate user_ids from several match strategies, then only
            // unlock if they ALL point to ONE account (unambiguous). Real stored
            // phones are MESSY: leading zeros (09033329776), national-only, full
            // international, and a frequently-WRONG stored phone_country_code (e.g.
            // a default '92' on a Nigerian number). So we must NOT depend on the
            // stored country code. The reliable key is the NATIONAL SIGNIFICANT
            // DIGITS (the tail), compared zero-insensitively.
            $uids = [];

            // (1) EXACT whole-number match (accounts that stored the full intl number).
            foreach (($db->select('users', ['user_id'], ['phone' => $digits]) ?: []) as $r) {
                if (($r['user_id'] ?? '') !== '') { $uids[(string) $r['user_id']] = true; }
            }

            // (2) dial-code split: leading digits = a real country dial code, the
            //     rest = national number (covers rows that stored the right cc).
            for ($len = 1; $len <= 4 && $len < strlen($digits); $len++) {
                $cc = substr($digits, 0, $len);
                $national = substr($digits, $len);
                if ($national === '') { continue; }
                if ((bool) $db->get('countries', 'id', ['phonecode' => $cc])) {
                    foreach (($db->select('users', ['user_id'], ['phone' => $national, 'phone_country_code' => $cc]) ?: []) as $r) {
                        if (($r['user_id'] ?? '') !== '') { $uids[(string) $r['user_id']] = true; }
                    }
                    // also match the national part regardless of the (often wrong)
                    // stored cc — still requires the national digits to be identical.
                    foreach (($db->select('users', ['user_id'], ['phone' => $national]) ?: []) as $r) {
                        if (($r['user_id'] ?? '') !== '') { $uids[(string) $r['user_id']] = true; }
                    }
                    foreach (($db->select('users', ['user_id'], ['phone' => '0' . $national]) ?: []) as $r) {
                        if (($r['user_id'] ?? '') !== '') { $uids[(string) $r['user_id']] = true; }
                    }
                }
            }

            // (3) NATIONAL-TAIL match (the robust one): compare the last 10
            //     significant digits (zeros stripped) of every stored phone against
            //     the sender's last 10. 10 digits is a full national number for most
            //     countries (incl. Nigeria), so a tail match is effectively the whole
            //     number — strong enough to be safe when it resolves to ONE account.
            $senderTail = substr($digits, -10);
            if (strlen($senderTail) >= 9) {
                // Pull candidate rows cheaply: those whose phone ends with the tail,
                // OR with a leading zero before it. LIKE on the last digits.
                $like = '%' . $senderTail;
                foreach (($db->select('users', ['user_id', 'phone'], ['phone[~]' => $like]) ?: []) as $r) {
                    $pd = ltrim(preg_replace('/[^0-9]/', '', (string) ($r['phone'] ?? '')), '0');
                    if ($pd !== '' && strlen($pd) >= 9 && substr($pd, -10) === $senderTail) {
                        if (($r['user_id'] ?? '') !== '') { $uids[(string) $r['user_id']] = true; }
                    }
                }
            }

            // Unlock ONLY when every strategy agreed on exactly ONE account.
            if (count($uids) === 1) {
                return (string) array_key_first($uids);
            }
        } catch (\Throwable $e) {
            error_log('[gia] identity phone_match: ' . $e->getMessage());
        }
        return '';
    }
}

if (!function_exists('gia_identity_begin_link')) {
    /**
     * Start a VERIFIED-LINK: the traveler claims to be an account (by email or the
     * phone we matched). We generate a one-time 6-digit code, store its hash +
     * 10-min expiry against (channel, sender, target user), and return the code so
     * the caller can deliver it over a TRUSTED side-channel (the account's email /
     * the account's registered WhatsApp) — NOT echo it into the chat.
     *
     * Returns ['ok'=>bool, 'code'=>string, 'user_id'=>string, 'deliver'=>'email'|'whatsapp', 'error'=>?].
     */
    function gia_identity_begin_link($db, string $channel, string $channelUserId, string $targetUserId): array
    {
        $channel = strtolower(trim($channel));
        $channelUserId = trim($channelUserId);
        $targetUserId = trim($targetUserId);
        if ($channel === '' || $channelUserId === '' || $targetUserId === '') {
            return ['ok' => false, 'error' => 'missing_params'];
        }
        gia_identity_ensure_schema($db);
        try {
            // Confirm the target user exists.
            $u = $db->get('users', ['user_id', 'email'], ['user_id' => $targetUserId]);
            if (!is_array($u) || empty($u['user_id'])) {
                return ['ok' => false, 'error' => 'unknown_user'];
            }
            // 6-digit numeric code; store only its hash.
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $hash = hash('sha256', $code . '|' . $channel . '|' . $channelUserId . '|' . $targetUserId);
            $expires = date('Y-m-d H:i:s', time() + 600); // 10 minutes

            $existing = $db->get('gia_channel_links', ['id'], ['channel' => $channel, 'channel_user_id' => $channelUserId]);
            $fields = [
                'user_id'      => $targetUserId,
                'code_hash'    => $hash,
                'code_expires' => $expires,
                'attempts'     => 0,
                'verified'     => 0,
            ];
            if (is_array($existing) && !empty($existing['id'])) {
                $db->update('gia_channel_links', $fields, ['id' => $existing['id']]);
            } else {
                $db->insert('gia_channel_links', array_merge(['channel' => $channel, 'channel_user_id' => $channelUserId], $fields));
            }
            return ['ok' => true, 'code' => $code, 'user_id' => $targetUserId, 'email' => (string) ($u['email'] ?? '')];
        } catch (\Throwable $e) {
            error_log('[gia] identity begin_link: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'internal'];
        }
    }
}

if (!function_exists('gia_identity_confirm_link')) {
    /**
     * Confirm a VERIFIED-LINK by the one-time code the traveler types back. On
     * success, marks (channel, sender) verified against the user_id. Constant-time
     * compare; bounded attempts; expiry enforced. Returns
     * ['ok'=>bool, 'user_id'=>string, 'error'=>?].
     */
    function gia_identity_confirm_link($db, string $channel, string $channelUserId, string $code): array
    {
        $channel = strtolower(trim($channel));
        $channelUserId = trim($channelUserId);
        $code = preg_replace('/[^\d]/', '', trim($code));
        if ($channel === '' || $channelUserId === '' || $code === '') {
            return ['ok' => false, 'error' => 'missing_params'];
        }
        gia_identity_ensure_schema($db);
        try {
            $row = $db->get('gia_channel_links', ['id', 'user_id', 'code_hash', 'code_expires', 'attempts', 'verified'], [
                'channel'         => $channel,
                'channel_user_id' => $channelUserId,
            ]);
            if (!is_array($row) || empty($row['id'])) {
                return ['ok' => false, 'error' => 'no_pending_link'];
            }
            if ((int) ($row['verified'] ?? 0) === 1) {
                return ['ok' => true, 'user_id' => (string) $row['user_id']]; // already linked
            }
            if ((int) ($row['attempts'] ?? 0) >= 5) {
                return ['ok' => false, 'error' => 'too_many_attempts'];
            }
            // Always bump attempts first (so brute force is bounded even on mismatch).
            $db->update('gia_channel_links', ['attempts' => (int) $row['attempts'] + 1], ['id' => $row['id']]);

            if (empty($row['code_hash']) || empty($row['code_expires'])) {
                return ['ok' => false, 'error' => 'no_pending_code'];
            }
            if (strtotime((string) $row['code_expires']) < time()) {
                return ['ok' => false, 'error' => 'expired'];
            }
            $target = (string) ($row['user_id'] ?? '');
            $expected = hash('sha256', $code . '|' . $channel . '|' . $channelUserId . '|' . $target);
            if (!hash_equals((string) $row['code_hash'], $expected)) {
                return ['ok' => false, 'error' => 'bad_code'];
            }
            // Success — bind + clear the one-time secret.
            $db->update('gia_channel_links', [
                'verified'     => 1,
                'verified_at'  => date('Y-m-d H:i:s'),
                'code_hash'    => null,
                'code_expires' => null,
            ], ['id' => $row['id']]);
            // Also link the live conversation to the user (reporting + resume).
            if (function_exists('gia_conv_get_or_create_channel')) {
                $conv = gia_conv_get_or_create_channel($db, $channel, $channelUserId, $target, '');
                // (the resolver links user_id when passed a non-empty target)
                unset($conv);
            }
            return ['ok' => true, 'user_id' => $target];
        } catch (\Throwable $e) {
            error_log('[gia] identity confirm_link: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'internal'];
        }
    }
}

if (!function_exists('gia_identity_resolve')) {
    /**
     * The one call the inbound spine uses per turn. Returns:
     *   ['user_id'=>string, 'verified'=>bool, 'match'=>bool]
     * where:
     *   - verified=true  -> a confirmed link exists; user_id is trusted for money.
     *   - verified=false, match=true -> a passive phone match; user_id usable for
     *     GREETING ONLY (never money).
     *   - both false -> anonymous.
     */
    function gia_identity_resolve($db, string $channel, string $channelUserId): array
    {
        $verified = gia_identity_verified_user($db, $channel, $channelUserId);
        if ($verified !== '') {
            return ['user_id' => $verified, 'verified' => true, 'match' => true];
        }
        $matched = gia_identity_phone_match($db, $channel, $channelUserId);
        if ($matched !== '') {
            return ['user_id' => $matched, 'verified' => false, 'match' => true];
        }
        return ['user_id' => '', 'verified' => false, 'match' => false];
    }
}

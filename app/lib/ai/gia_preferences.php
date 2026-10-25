<?php
// app/lib/ai/gia_preferences.php
//
// Opt-in, consent-backed long-term travel preferences for GIA (world-class-GIA
// GA4). Implements the GIA BRD memory requirements:
//   MEM-001 separate opt-in long-term preferences (this store)
//   MEM-002 user can view / correct / delete (list/set/forget)
//   MEM-003 sensitive docs/payment NEVER stored here (allow-list of keys only)
//   MEM-004 retrieval scoped by account (every call takes user_id)
//   MEM-005 provenance + consent basis + timestamp + optional expiry per fact
//   MEM-006 learned ONLY from explicitly consented behaviour (consent flag required)
//   MEM-008 personalization can be disabled without blocking booking (master off switch)
//
// A preference is a single allow-listed key (e.g. preferred_cabin) with a short
// value, a consent flag, provenance ('stated' | 'confirmed_behaviour') and an
// optional expiry. Nothing is written unless consented. Idempotent, fail-soft.

if (!function_exists('gia_pref_keys')) {
    /**
     * Allow-list of preference keys GIA may store, with a human label + validator.
     * NOTE: deliberately NO passport/payment/document keys (MEM-003).
     */
    function gia_pref_keys(): array
    {
        return [
            'home_airport'     => 'home airport',      // e.g. KAN
            'preferred_cabin'  => 'cabin class',       // economy|premium_economy|business|first
            'preferred_airline'=> 'preferred airline', // free text / code
            'seat_preference'  => 'seat preference',   // aisle|window|middle
            'meal_preference'  => 'meal preference',   // e.g. halal, vegetarian
            'budget_ceiling'   => 'budget ceiling',    // number + currency, free text
            'hotel_stars'      => 'hotel star rating', // 3|4|5
            'language'         => 'language',          // en|fr|...
        ];
    }
}

if (!function_exists('ensureGiaPreferencesSchema')) {
    function ensureGiaPreferencesSchema($db): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `gia_user_preferences` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` varchar(255) NOT NULL,
                `pref_key` varchar(48) NOT NULL,
                `pref_value` varchar(255) NOT NULL,
                `consent` tinyint(1) NOT NULL DEFAULT 1,
                `provenance` varchar(32) NOT NULL DEFAULT 'stated',
                `expires_at` datetime DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_user_key` (`user_id`,`pref_key`),
                KEY `idx_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            // Master off-switch (MEM-008): a user can disable personalization.
            $cols = $db->query("SHOW COLUMNS FROM `users` LIKE 'gia_personalization_off'")->fetchAll();
            if (count($cols) === 0) {
                $db->pdo->exec("ALTER TABLE `users` ADD COLUMN `gia_personalization_off` TINYINT(1) NOT NULL DEFAULT 0");
            }
        } catch (\Throwable $e) {
            error_log('ensureGiaPreferencesSchema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('gia_pref_personalization_enabled')) {
    /** MEM-008: false if the user turned personalization off. Default on. */
    function gia_pref_personalization_enabled($db, string $userId): bool
    {
        $userId = trim($userId);
        if ($userId === '') { return false; }
        try {
            ensureGiaPreferencesSchema($db);
            $off = $db->get('users', 'gia_personalization_off', ['user_id' => $userId]);
            return (int) ($off ?? 0) !== 1;
        } catch (\Throwable $e) {
            return true;
        }
    }
}

if (!function_exists('gia_pref_set_personalization')) {
    /** MEM-008: turn personalization on/off for the user. */
    function gia_pref_set_personalization($db, string $userId, bool $enabled): bool
    {
        $userId = trim($userId);
        if ($userId === '') { return false; }
        try {
            ensureGiaPreferencesSchema($db);
            $db->update('users', ['gia_personalization_off' => $enabled ? 0 : 1], ['user_id' => $userId]);
            return true;
        } catch (\Throwable $e) {
            error_log('gia_pref_set_personalization: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('gia_pref_set')) {
    /**
     * Store ONE consented preference (MEM-005/006). Refuses unknown keys
     * (allow-list / MEM-003) and anything without consent. Returns ok/err.
     *
     * @param string $provenance 'stated' (user said so) | 'confirmed_behaviour'
     * @param int    $ttlDays    0 = no expiry.
     */
    function gia_pref_set($db, string $userId, string $key, string $value, bool $consent, string $provenance = 'stated', int $ttlDays = 0): array
    {
        $userId = trim($userId);
        $key = strtolower(trim($key));
        $value = trim($value);
        if ($userId === '') { return ['ok' => false, 'error' => 'no_user']; }
        if (!array_key_exists($key, gia_pref_keys())) { return ['ok' => false, 'error' => 'bad_key', 'message' => 'I can only remember travel preferences like cabin, seat, home airport or budget.']; }
        if (!$consent) { return ['ok' => false, 'error' => 'no_consent', 'message' => 'I\'ll only remember that if you want me to.']; }
        if ($value === '') { return ['ok' => false, 'error' => 'empty']; }
        $value = substr($value, 0, 255);
        $expires = $ttlDays > 0 ? date('Y-m-d H:i:s', time() + $ttlDays * 86400) : null;
        try {
            ensureGiaPreferencesSchema($db);
            $existing = $db->get('gia_user_preferences', 'id', ['user_id' => $userId, 'pref_key' => $key]);
            if ($existing) {
                $db->update('gia_user_preferences', [
                    'pref_value' => $value, 'consent' => 1, 'provenance' => substr($provenance, 0, 32),
                    'expires_at' => $expires, 'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => (int) $existing]);
            } else {
                $db->insert('gia_user_preferences', [
                    'user_id' => $userId, 'pref_key' => $key, 'pref_value' => $value,
                    'consent' => 1, 'provenance' => substr($provenance, 0, 32),
                    'expires_at' => $expires, 'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
            return ['ok' => true, 'label' => gia_pref_keys()[$key]];
        } catch (\Throwable $e) {
            error_log('gia_pref_set: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'db'];
        }
    }
}

if (!function_exists('gia_pref_list')) {
    /** All live (consented, unexpired) preferences for a user. [] if personalization off. */
    function gia_pref_list($db, string $userId): array
    {
        $userId = trim($userId);
        if ($userId === '') { return []; }
        if (!gia_pref_personalization_enabled($db, $userId)) { return []; }
        try {
            ensureGiaPreferencesSchema($db);
            $rows = $db->select('gia_user_preferences', ['pref_key', 'pref_value', 'provenance', 'expires_at'], [
                'user_id' => $userId, 'consent' => 1,
            ]) ?: [];
            $now = date('Y-m-d H:i:s');
            $out = [];
            foreach ($rows as $r) {
                if (!empty($r['expires_at']) && (string) $r['expires_at'] < $now) { continue; }
                $out[(string) $r['pref_key']] = (string) $r['pref_value'];
            }
            return $out;
        } catch (\Throwable $e) {
            error_log('gia_pref_list: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('gia_pref_forget')) {
    /** MEM-002: delete one preference (or all when key=''). Scoped to the user. */
    function gia_pref_forget($db, string $userId, string $key = ''): bool
    {
        $userId = trim($userId);
        if ($userId === '') { return false; }
        try {
            ensureGiaPreferencesSchema($db);
            $where = ['user_id' => $userId];
            if (trim($key) !== '') { $where['pref_key'] = strtolower(trim($key)); }
            $db->delete('gia_user_preferences', $where);
            return true;
        } catch (\Throwable $e) {
            error_log('gia_pref_forget: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('gia_pref_summary_note')) {
    /** A short system-note line GIA can use to rank/pre-fill. '' if none/off. */
    function gia_pref_summary_note($db, string $userId): string
    {
        $prefs = gia_pref_list($db, $userId);
        if ($prefs === []) { return ''; }
        $labels = gia_pref_keys();
        $parts = [];
        foreach ($prefs as $k => $v) {
            $parts[] = ($labels[$k] ?? $k) . ': ' . $v;
        }
        return 'SAVED PREFERENCES (trusted, consented — use to rank options and pre-fill, '
            . 'but never override an explicit request): ' . implode('; ', $parts) . '.';
    }
}

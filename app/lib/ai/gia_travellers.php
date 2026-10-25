<?php
// app/lib/ai/gia_travellers.php
//
// Saved-traveller store for GIA so a returning customer NEVER re-types a
// passenger's name/DOB/passport/nationality. World-class-GIA workstream, GA1.
//
// Source of truth = a user-scoped `user_travellers` table, AUTO-SEEDED from the
// customer's own booking history (flights store pax in bookings.travellers JSON;
// umrah stores pilgrims in umrah_booking_travellers) and kept updated as they
// book. Deduped by (passport_number) when present, else by (first+last+dob).
//
// Consent/governance (GIA BRD MEM-001..008): this is the customer's OWN data
// re-surfaced to them for reuse; gia_travellers_forget() lets them delete a saved
// traveller (view/correct/delete). Never shared across users; always scoped to one
// users.user_id.
//
// Idempotent, fail-soft (mirrors ensureGiaAnalyticsSchema). All functions guarded.

if (!function_exists('ensureGiaTravellersSchema')) {
    function ensureGiaTravellersSchema($db): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `user_travellers` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` varchar(255) NOT NULL,
                `title` varchar(16) DEFAULT NULL,
                `first_name` varchar(120) NOT NULL,
                `middle_name` varchar(120) DEFAULT NULL,
                `last_name` varchar(120) DEFAULT NULL,
                `gender` varchar(8) DEFAULT NULL,
                `dob` date DEFAULT NULL,
                `nationality` varchar(80) DEFAULT NULL,
                `passport_number` varchar(64) DEFAULT NULL,
                `passport_expiry` date DEFAULT NULL,
                `email` varchar(255) DEFAULT NULL,
                `phone` varchar(40) DEFAULT NULL,
                `relationship` varchar(40) DEFAULT NULL,
                `is_self` tinyint(1) NOT NULL DEFAULT 0,
                `dedupe_key` varchar(191) NOT NULL,
                `last_used_at` datetime DEFAULT NULL,
                `use_count` int(11) NOT NULL DEFAULT 1,
                `source` varchar(24) NOT NULL DEFAULT 'history',
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_user_dedupe` (`user_id`,`dedupe_key`),
                KEY `idx_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (\Throwable $e) {
            error_log('ensureGiaTravellersSchema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('gia_traveller_dedupe_key')) {
    /** Stable identity key: passport if present, else name+dob. Lowercased. */
    function gia_traveller_dedupe_key(array $t): string
    {
        $pp = strtolower(preg_replace('/\s+/', '', (string) ($t['passport_number'] ?? '')));
        if ($pp !== '') { return 'pp:' . $pp; }
        $fn = strtolower(trim((string) ($t['first_name'] ?? '')));
        $ln = strtolower(trim((string) ($t['last_name'] ?? '')));
        $db = trim((string) ($t['dob'] ?? ''));
        return 'nm:' . $fn . '|' . $ln . '|' . $db;
    }
}

if (!function_exists('gia_traveller_upsert')) {
    /**
     * Insert or update ONE saved traveller for a user (idempotent on dedupe_key).
     * On a repeat, fills blank fields + bumps use_count/last_used_at. Returns the
     * dedupe_key, or '' if there wasn't enough to identify a person.
     */
    function gia_traveller_upsert($db, string $userId, array $t, string $source = 'history'): string
    {
        $userId = trim($userId);
        $first  = trim((string) ($t['first_name'] ?? ''));
        if ($userId === '' || $first === '') { return ''; }
        // Need SOME identity beyond a bare first name.
        $key = gia_traveller_dedupe_key($t);
        if ($key === 'nm:' . strtolower($first) . '||') { return ''; } // only a first name, skip

        ensureGiaTravellersSchema($db);
        $norm = [
            'title'           => substr((string) ($t['title'] ?? ''), 0, 16) ?: null,
            'first_name'      => substr($first, 0, 120),
            'middle_name'     => substr((string) ($t['middle_name'] ?? ''), 0, 120) ?: null,
            'last_name'       => substr((string) ($t['last_name'] ?? ''), 0, 120) ?: null,
            'gender'          => substr((string) ($t['gender'] ?? ''), 0, 8) ?: null,
            'dob'             => gia__date_or_null($t['dob'] ?? ''),
            'nationality'     => substr((string) ($t['nationality'] ?? ''), 0, 80) ?: null,
            'passport_number' => substr((string) ($t['passport_number'] ?? ''), 0, 64) ?: null,
            'passport_expiry' => gia__date_or_null($t['passport_expiry'] ?? ''),
            'email'           => substr((string) ($t['email'] ?? ''), 0, 255) ?: null,
            'phone'           => substr((string) ($t['phone'] ?? ''), 0, 40) ?: null,
            'is_self'         => !empty($t['is_self']) ? 1 : 0,
        ];
        try {
            $existing = $db->get('user_travellers', ['id', 'use_count'], ['user_id' => $userId, 'dedupe_key' => $key]);
            if ($existing) {
                // Fill only blanks; never overwrite a good value with an empty one.
                $patch = ['updated_at' => date('Y-m-d H:i:s'), 'last_used_at' => date('Y-m-d H:i:s')];
                foreach ($norm as $col => $val) {
                    if ($val !== null && $val !== '' && $val !== 0) { $patch[$col] = $val; }
                }
                $patch['use_count'] = (int) ($existing['use_count'] ?? 1) + 1;
                $db->update('user_travellers', $patch, ['id' => (int) $existing['id']]);
            } else {
                $db->insert('user_travellers', array_merge($norm, [
                    'user_id'      => $userId,
                    'dedupe_key'   => $key,
                    'source'       => substr($source, 0, 24),
                    'last_used_at' => date('Y-m-d H:i:s'),
                    'use_count'    => 1,
                    'created_at'   => date('Y-m-d H:i:s'),
                ]));
            }
        } catch (\Throwable $e) {
            error_log('gia_traveller_upsert: ' . $e->getMessage());
        }
        return $key;
    }
}

if (!function_exists('gia_travellers_seed_from_history')) {
    /**
     * Populate a user's saved travellers from their OWN past bookings — flights
     * (bookings.travellers JSON) + umrah (umrah_booking_travellers). Idempotent
     * via the upsert dedupe. Cheap + bounded (recent bookings only); safe to call
     * lazily the first time a user's roster is requested.
     */
    function gia_travellers_seed_from_history($db, string $userId): void
    {
        $userId = trim($userId);
        if ($userId === '') { return; }
        ensureGiaTravellersSchema($db);

        // 1) Flights / ai_trip pax stored as JSON in bookings.travellers.
        try {
            $rows = $db->select('bookings', ['travellers', 'email', 'phone'], [
                'user_id' => $userId, 'travellers[!]' => null, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 50,
            ]) ?: [];
            foreach ($rows as $r) {
                $trav = json_decode((string) ($r['travellers'] ?? ''), true);
                if (!is_array($trav)) { continue; }
                foreach ($trav as $k => $p) {
                    if (!is_array($p)) { continue; }
                    // Skip the thin "primary_guest" contact-only shape (stays) unless it has a name.
                    if (trim((string) ($p['first_name'] ?? '')) === '') { continue; }
                    gia_traveller_upsert($db, $userId, $p, 'history');
                }
            }
        } catch (\Throwable $e) {
            error_log('gia_travellers_seed (flights): ' . $e->getMessage());
        }

        // 2) Umrah pilgrims via umrah_bookings.user_id -> umrah_booking_travellers.
        try {
            $ub = $db->select('umrah_bookings', ['id'], ['user_id' => $userId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 50]) ?: [];
            $ids = array_values(array_filter(array_map(static fn($x) => (int) ($x['id'] ?? 0), $ub)));
            if ($ids) {
                $pax = $db->select('umrah_booking_travellers', [
                    'title', 'first_name', 'middle_name', 'last_name', 'gender', 'dob',
                    'nationality', 'passport_number', 'passport_expiry',
                ], ['umrah_booking_id' => $ids]) ?: [];
                foreach ($pax as $p) {
                    if (trim((string) ($p['first_name'] ?? '')) === '') { continue; }
                    gia_traveller_upsert($db, $userId, $p, 'history');
                }
            }
        } catch (\Throwable $e) {
            error_log('gia_travellers_seed (umrah): ' . $e->getMessage());
        }
    }
}

if (!function_exists('gia_travellers_list')) {
    /**
     * Return a user's saved travellers (most-used / most-recent first). Seeds from
     * history on first call if the store is empty for this user. Each row is a
     * compact, model-safe shape; passport numbers are included because the whole
     * point is reuse, but this is the user's OWN data returned only to them.
     */
    function gia_travellers_list($db, string $userId, int $limit = 12): array
    {
        $userId = trim($userId);
        if ($userId === '') { return []; }
        ensureGiaTravellersSchema($db);
        try {
            $have = (int) $db->count('user_travellers', ['user_id' => $userId]);
            if ($have === 0) {
                gia_travellers_seed_from_history($db, $userId);
            }
            $rows = $db->select('user_travellers', [
                'id', 'title', 'first_name', 'middle_name', 'last_name', 'gender', 'dob',
                'nationality', 'passport_number', 'passport_expiry', 'email', 'phone',
                'is_self', 'use_count',
            ], ['user_id' => $userId, 'ORDER' => ['use_count' => 'DESC', 'last_used_at' => 'DESC'], 'LIMIT' => max(1, min(50, $limit))]) ?: [];
            return $rows;
        } catch (\Throwable $e) {
            error_log('gia_travellers_list: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('gia_travellers_get')) {
    /**
     * Load ONE saved traveller by id, scoped to the user (so a model can't reuse
     * someone else's record). Returns the full row (incl. passport for reuse) or
     * null. Bumps last_used_at/use_count so the roster ranks by real reuse.
     */
    function gia_travellers_get($db, string $userId, int $id): ?array
    {
        $userId = trim($userId);
        if ($userId === '' || $id <= 0) { return null; }
        ensureGiaTravellersSchema($db);
        try {
            $row = $db->get('user_travellers', [
                'id', 'title', 'first_name', 'middle_name', 'last_name', 'gender', 'dob',
                'nationality', 'passport_number', 'passport_expiry', 'email', 'phone', 'use_count',
            ], ['user_id' => $userId, 'id' => $id]);
            if (!is_array($row) || $row === []) { return null; }
            try {
                $db->update('user_travellers', [
                    'last_used_at' => date('Y-m-d H:i:s'),
                    'use_count'    => (int) ($row['use_count'] ?? 1) + 1,
                ], ['id' => $id]);
            } catch (\Throwable $e) { /* ranking only; non-fatal */ }
            return $row;
        } catch (\Throwable $e) {
            error_log('gia_travellers_get: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('gia_travellers_forget')) {
    /** Delete one saved traveller (BRD MEM: user can delete). Scoped to the user. */
    function gia_travellers_forget($db, string $userId, int $id): bool
    {
        $userId = trim($userId);
        if ($userId === '' || $id <= 0) { return false; }
        try {
            $db->delete('user_travellers', ['user_id' => $userId, 'id' => $id]);
            return true;
        } catch (\Throwable $e) {
            error_log('gia_travellers_forget: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('gia__date_or_null')) {
    /** Normalise a date-ish string to YYYY-MM-DD or null. Internal. */
    function gia__date_or_null($v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') { return null; }
        $ts = strtotime($v);
        return $ts === false ? null : date('Y-m-d', $ts);
    }
}

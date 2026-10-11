<?php
// FILE: app/lib/supplier_locks_ext.php
// SMART LOCKS & ACCESS CONTROL — EXTENSION (PMS module #52; docs 01b §2).
//
// NO lock registry existed before this file. This builds the genuine gaps:
//
//   1. LOCK REGISTRY   (stays_locks)            — a physical smart lock / keypad /
//      lockbox / gateway device per property, optionally mapped to a physical room
//      (stays_physical_rooms) or to a named common-area door (no room id). Fields:
//      name, kind, provider (free-text vendor label, e.g. "August"/"Nuki"), an
//      external_ref (the vendor's device id, operator-entered — NOT fetched), a
//      battery_pct snapshot (operator-entered), and an online/offline/unknown
//      status that is ALWAYS 'unknown' until a real adapter lands (see honest note).
//   2. ROOM↔LOCK MAPPING — a lock may carry physical_room_id. locks_for_room()
//      and locks_for_stay() expose the mapping; a lock with no room is a shared
//      entrance (lobby/gate/pool).
//   3. CREDENTIAL LIFECYCLE (stays_lock_credentials) — issue / revoke records for a
//      PIN or mobile credential against a lock, optionally scoped to a reservation
//      (stays_reservations) and a validity window (valid_from / valid_until). State
//      machine: pending → active → revoked | expired. Issue and revoke are the two
//      operator actions; expiry is computed on read (creds_expire_due advances rows
//      whose valid_until has passed). A 4–10 digit PIN is generated server-side if
//      the operator does not supply one (mobile credentials store an invite handle,
//      not a secret).
//   4. ACCESS LOG      (stays_lock_access_log)  — an append-only audit of every
//      issue/revoke/expire plus MANUAL operator-entered unlock/denied events. It is
//      NOT a live device feed (no adapter) — manual rows are labelled source='manual'
//      and adapter rows (future) would be source='device'.
//   5. PROVIDER ADAPTER — an HONEST STUB. locks_adapter_status() always reports
//      'not connected' and locks_push_credential()/locks_pull_events() are no-ops
//      that record the intent in the access log and return a not-connected result.
//      No August/Nuki/Igloohome/Salto/Yale vendor SDK ships, so NOTHING here calls
//      a device. The stored PIN / credential record is the operational source of
//      truth; the physical lock is programmed out-of-band by staff until a vendor
//      adapter is added. We never fake a live integration.
//
// GATE: there is no dedicated 'locks' capability key in the role catalogue, so —
// exactly like supplier_maint_ext.php — this module gates on the ROOMS capability:
//   view   => supplier_can($db,'rooms','view')
//   mutate => supplier_can($db,'rooms','edit')
// Do NOT invent a new capability key or the role-builder won't grant it.
//
// MONEY (honest): this module records NO money. Access credentials and logs carry
// no charge, no folio line, no payout, no GL entry. There is deliberately NOTHING
// to route through gl_post / folio_add_item / supplier_earnings — a door PIN is not
// a billable item. If a future phase charges a guest for a lost-fob / re-key fee,
// THAT step must go through folio_add_item (guest billing) or an internal cost
// ledger — flagged DB-GATED for that phase; it is out of scope here.
//
// All functions are if_not_exists-guarded, non-fatal (try/catch + error_log) and
// additive. Schema is self-healing via _locks_ext_ensure_schema() (SHOW TABLES /
// CREATE IF NOT EXISTS, §5 pattern); failures degrade gracefully to read-only.

if (!function_exists('locks_kinds')) {
    /** Physical device kinds. Config, not stored as an enum constraint beyond the DDL. */
    function locks_kinds(): array
    {
        return [
            'smart_lock' => 'Smart lock',
            'keypad'     => 'Keypad',
            'lockbox'    => 'Lockbox / key safe',
            'gateway'    => 'Gateway / bridge',
            'reader'     => 'Card / fob reader',
        ];
    }
}

if (!function_exists('locks_device_statuses')) {
    /** Device connectivity states. Until an adapter ships, every lock is 'unknown'. */
    function locks_device_statuses(): array
    {
        return ['unknown' => 'Unknown', 'online' => 'Online', 'offline' => 'Offline'];
    }
}

if (!function_exists('locks_credential_types')) {
    function locks_credential_types(): array
    {
        return ['pin' => 'PIN code', 'mobile' => 'Mobile credential', 'card' => 'Card / fob'];
    }
}

if (!function_exists('locks_credential_statuses')) {
    function locks_credential_statuses(): array
    {
        return ['pending' => 'Pending', 'active' => 'Active', 'revoked' => 'Revoked', 'expired' => 'Expired'];
    }
}

if (!function_exists('_locks_ext_org')) {
    /** Resolve org id for a property (bridge to existing helper; 0 on failure). */
    function _locks_ext_org($db, int $stayId): int
    {
        if ($stayId <= 0) { return 0; }
        return function_exists('supplier_property_org') ? (int) supplier_property_org($db, $stayId) : 0;
    }
}

if (!function_exists('_locks_date')) {
    /** Normalise a datetime input to Y-m-d H:i:s or null (never an invalid string). */
    function _locks_date($v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') { return null; }
        $ts = strtotime($v);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }
}

if (!function_exists('_locks_ext_ensure_schema')) {
    /**
     * Idempotent self-healing schema (same pattern as §5 / ensureCoreFixSchema).
     * Creates the 3 new tables if absent. No ALTERs on existing tables. Failures
     * are swallowed (a DB user without CREATE just means the feature stays empty).
     * Runs at most once per request via a static flag.
     */
    function _locks_ext_ensure_schema($db): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `stays_locks` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) DEFAULT NULL,
                `stay_id` int(11) NOT NULL,
                `physical_room_id` int(11) DEFAULT NULL,
                `name` varchar(191) NOT NULL,
                `kind` enum('smart_lock','keypad','lockbox','gateway','reader') NOT NULL DEFAULT 'smart_lock',
                `provider` varchar(80) DEFAULT NULL,
                `external_ref` varchar(191) DEFAULT NULL,
                `location_label` varchar(191) DEFAULT NULL,
                `device_status` enum('unknown','online','offline') NOT NULL DEFAULT 'unknown',
                `battery_pct` tinyint(4) DEFAULT NULL,
                `last_seen_at` datetime DEFAULT NULL,
                `active` tinyint(1) NOT NULL DEFAULT 1,
                `notes` text DEFAULT NULL,
                `created_by` varchar(155) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_stay` (`stay_id`,`active`),
                KEY `idx_room` (`physical_room_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `stays_lock_credentials` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) DEFAULT NULL,
                `stay_id` int(11) NOT NULL,
                `lock_id` int(11) NOT NULL,
                `reservation_id` int(11) DEFAULT NULL,
                `cred_type` enum('pin','mobile','card') NOT NULL DEFAULT 'pin',
                `label` varchar(191) DEFAULT NULL,
                `pin_code` varchar(16) DEFAULT NULL,
                `mobile_handle` varchar(191) DEFAULT NULL,
                `status` enum('pending','active','revoked','expired') NOT NULL DEFAULT 'active',
                `valid_from` datetime DEFAULT NULL,
                `valid_until` datetime DEFAULT NULL,
                `pushed` tinyint(1) NOT NULL DEFAULT 0,
                `revoked_at` datetime DEFAULT NULL,
                `created_by` varchar(155) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_lock` (`lock_id`,`status`),
                KEY `idx_stay` (`stay_id`,`status`),
                KEY `idx_res` (`reservation_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `stays_lock_access_log` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `stay_id` int(11) NOT NULL,
                `lock_id` int(11) NOT NULL,
                `credential_id` int(11) DEFAULT NULL,
                `event` varchar(32) NOT NULL,
                `source` enum('manual','device','system') NOT NULL DEFAULT 'manual',
                `detail` varchar(255) DEFAULT NULL,
                `actor` varchar(155) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_lock` (`lock_id`),
                KEY `idx_stay` (`stay_id`,`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            error_log('_locks_ext_ensure_schema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('_locks_log')) {
    /**
     * Append an access-log row. source defaults to 'manual' because no device feed
     * exists; the stub adapter uses 'system'. Non-fatal. Caller has already
     * ownership-checked the lock's stay_id.
     */
    function _locks_log($db, int $stayId, int $lockId, string $event, array $o = []): void
    {
        if ($stayId <= 0 || $lockId <= 0) { return; }
        try {
            $db->insert('stays_lock_access_log', [
                'stay_id'       => $stayId,
                'lock_id'       => $lockId,
                'credential_id' => isset($o['credential_id']) ? ((int) $o['credential_id'] ?: null) : null,
                'event'         => substr($event, 0, 32),
                'source'        => in_array(($o['source'] ?? 'manual'), ['manual', 'device', 'system'], true) ? $o['source'] : 'manual',
                'detail'        => isset($o['detail']) ? substr((string) $o['detail'], 0, 255) : null,
                'actor'         => (string) ($_SESSION['user_id'] ?? ''),
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) { error_log('_locks_log: ' . $e->getMessage()); }
    }
}

// ============================================================================
// LOCK REGISTRY + ROOM MAPPING
// ============================================================================
if (!function_exists('lock_create')) {
    /** Register a lock on a property, optionally mapped to a physical room. Returns new id or 0. */
    function lock_create($db, int $stayId, array $d): int
    {
        if ($stayId <= 0) { return 0; }
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') { return 0; }
        $kind = isset(locks_kinds()[$d['kind'] ?? '']) ? $d['kind'] : 'smart_lock';
        _locks_ext_ensure_schema($db);
        try {
            // Validate the room belongs to THIS property (IDOR guard); else treat as shared door.
            $pr = (int) ($d['physical_room_id'] ?? 0);
            if ($pr > 0 && !$db->has('stays_physical_rooms', ['id' => $pr, 'stay_id' => $stayId])) { $pr = 0; }
            $bat = (isset($d['battery_pct']) && $d['battery_pct'] !== '') ? max(0, min(100, (int) $d['battery_pct'])) : null;
            $org = _locks_ext_org($db, $stayId);
            $db->insert('stays_locks', [
                'org_id'           => $org > 0 ? $org : null,
                'stay_id'          => $stayId,
                'physical_room_id' => $pr > 0 ? $pr : null,
                'name'             => substr($name, 0, 191),
                'kind'             => $kind,
                'provider'         => trim((string) ($d['provider'] ?? '')) ?: null,
                'external_ref'     => trim((string) ($d['external_ref'] ?? '')) ?: null,
                'location_label'   => trim((string) ($d['location_label'] ?? '')) ?: null,
                'device_status'    => 'unknown', // always unknown until a real adapter reports in
                'battery_pct'      => $bat,
                'active'           => 1,
                'notes'            => trim((string) ($d['notes'] ?? '')) ?: null,
                'created_by'       => (string) ($_SESSION['user_id'] ?? ''),
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
            $id = (int) $db->id();
            if ($id > 0) { _locks_log($db, $stayId, $id, 'lock_registered', ['source' => 'manual', 'detail' => $kind . ' / ' . $name]); }
            return $id;
        } catch (\Throwable $e) { error_log('lock_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('lock_update')) {
    /** Update editable fields (mapping, provider ref, battery snapshot, notes, active). */
    function lock_update($db, int $lockId, int $stayId, array $d): bool
    {
        if ($lockId <= 0 || $stayId <= 0) { return false; }
        _locks_ext_ensure_schema($db);
        try {
            if (!$db->has('stays_locks', ['id' => $lockId, 'stay_id' => $stayId])) { return false; }
            $upd = ['updated_at' => date('Y-m-d H:i:s')];
            if (isset($d['name']) && trim((string) $d['name']) !== '') { $upd['name'] = substr(trim((string) $d['name']), 0, 191); }
            if (isset($d['kind']) && isset(locks_kinds()[$d['kind']])) { $upd['kind'] = $d['kind']; }
            if (array_key_exists('physical_room_id', $d)) {
                $pr = (int) $d['physical_room_id'];
                if ($pr > 0 && !$db->has('stays_physical_rooms', ['id' => $pr, 'stay_id' => $stayId])) { $pr = 0; }
                $upd['physical_room_id'] = $pr > 0 ? $pr : null;
            }
            if (array_key_exists('provider', $d))       { $upd['provider'] = trim((string) $d['provider']) ?: null; }
            if (array_key_exists('external_ref', $d))   { $upd['external_ref'] = trim((string) $d['external_ref']) ?: null; }
            if (array_key_exists('location_label', $d)) { $upd['location_label'] = trim((string) $d['location_label']) ?: null; }
            if (array_key_exists('notes', $d))          { $upd['notes'] = trim((string) $d['notes']) ?: null; }
            if (isset($d['battery_pct']) && $d['battery_pct'] !== '') { $upd['battery_pct'] = max(0, min(100, (int) $d['battery_pct'])); }
            if (array_key_exists('active', $d))         { $upd['active'] = ((string) $d['active'] === '1') ? 1 : 0; }
            $db->update('stays_locks', $upd, ['id' => $lockId, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('lock_update: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('locks_for_stay')) {
    /** All locks in a scope (array of stay ids), newest first. */
    function locks_for_stay($db, array $stayIds, bool $activeOnly = false): array
    {
        if (empty($stayIds)) { return []; }
        _locks_ext_ensure_schema($db);
        try {
            $w = ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 500];
            if ($activeOnly) { $w['active'] = 1; }
            return $db->select('stays_locks', '*', $w) ?: [];
        } catch (\Throwable $e) { error_log('locks_for_stay: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('locks_for_room')) {
    /** Locks mapped to a specific physical room (the room↔lock mapping read side). */
    function locks_for_room($db, int $roomId, int $stayId): array
    {
        if ($roomId <= 0 || $stayId <= 0) { return []; }
        _locks_ext_ensure_schema($db);
        try {
            return $db->select('stays_locks', '*', ['physical_room_id' => $roomId, 'stay_id' => $stayId, 'active' => 1]) ?: [];
        } catch (\Throwable $e) { error_log('locks_for_room: ' . $e->getMessage()); return []; }
    }
}

// ============================================================================
// CREDENTIAL LIFECYCLE — issue / revoke / expire
// ============================================================================
if (!function_exists('_locks_gen_pin')) {
    /** Generate a cryptographically-random numeric PIN of $len digits (4–10). */
    function _locks_gen_pin(int $len = 6): string
    {
        $len = max(4, min(10, $len));
        $out = '';
        for ($i = 0; $i < $len; $i++) { $out .= (string) random_int(0, 9); }
        return $out;
    }
}

if (!function_exists('lock_credential_issue')) {
    /**
     * Issue a credential (PIN / mobile / card) against a lock. The lock MUST belong
     * to $stayId (IDOR guard, re-checked in the txn). A PIN is generated if none is
     * supplied (operator may override). An optional reservation_id is validated to
     * the property. An optional validity window is stored. Rows are created 'active'
     * (there is no device to go 'pending' for) unless valid_from is in the future.
     *
     * HONEST: issuing does NOT program the physical lock — no adapter exists. The
     * record IS the source of truth; staff program the device out-of-band. We also
     * fire the stub push (locks_push_credential) which only logs intent.
     *
     * Returns ['ok'=>bool, 'id'=>int, 'pin'=>?string, 'message'=>string].
     */
    function lock_credential_issue($db, int $lockId, int $stayId, array $d): array
    {
        $res = ['ok' => false, 'id' => 0, 'pin' => null, 'message' => ''];
        if ($lockId <= 0 || $stayId <= 0) { $res['message'] = 'Invalid lock.'; return $res; }
        $type = isset(locks_credential_types()[$d['cred_type'] ?? '']) ? $d['cred_type'] : 'pin';
        _locks_ext_ensure_schema($db);

        $from = _locks_date($d['valid_from'] ?? '');
        $until = _locks_date($d['valid_until'] ?? '');
        if ($from !== null && $until !== null && strtotime($until) <= strtotime($from)) {
            $res['message'] = 'Valid-until must be after valid-from.'; return $res;
        }

        // PIN: operator-supplied (digits only) or generated. Mobile/card: a handle, not a secret.
        $pin = null; $handle = null;
        if ($type === 'pin') {
            $raw = preg_replace('/\D+/', '', (string) ($d['pin_code'] ?? ''));
            $pin = ($raw !== '' && strlen($raw) >= 4 && strlen($raw) <= 10) ? $raw : _locks_gen_pin(6);
        } else {
            $handle = trim((string) ($d['mobile_handle'] ?? '')) ?: null; // e.g. an email/phone invite target
        }

        try {
            $newId = 0;
            $db->action(function ($db) use ($lockId, $stayId, $type, $d, $from, $until, $pin, $handle, &$newId) {
                // Re-verify ownership inside the txn; lock the row so a concurrent
                // revoke/delete of the lock can't race the credential insert.
                $lk = $db->query('SELECT id, org_id FROM stays_locks WHERE id=:i AND stay_id=:s FOR UPDATE',
                    [':i' => $lockId, ':s' => $stayId]);
                $lockRow = $lk ? $lk->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$lockRow) { $newId = 0; return false; }

                $resId = (int) ($d['reservation_id'] ?? 0);
                if ($resId > 0 && !$db->has('stays_reservations', ['id' => $resId, 'stay_id' => $stayId])) { $resId = 0; }

                $status = ($from !== null && strtotime($from) > time()) ? 'pending' : 'active';
                $db->insert('stays_lock_credentials', [
                    'org_id'         => isset($lockRow['org_id']) ? ($lockRow['org_id'] ?: null) : null,
                    'stay_id'        => $stayId,
                    'lock_id'        => $lockId,
                    'reservation_id' => $resId > 0 ? $resId : null,
                    'cred_type'      => $type,
                    'label'          => trim((string) ($d['label'] ?? '')) ?: null,
                    'pin_code'       => $pin,
                    'mobile_handle'  => $handle,
                    'status'         => $status,
                    'valid_from'     => $from,
                    'valid_until'    => $until,
                    'pushed'         => 0,
                    'created_by'     => (string) ($_SESSION['user_id'] ?? ''),
                    'created_at'     => date('Y-m-d H:i:s'),
                ]);
                $newId = (int) $db->id();
                return true;
            });

            if ($newId <= 0) { $res['message'] = 'Lock not found for this property.'; return $res; }
            _locks_log($db, $stayId, $lockId, 'credential_issued',
                ['credential_id' => $newId, 'source' => 'manual', 'detail' => $type . ($pin ? ' PIN set' : '')]);
            // Stub adapter: records not-connected intent only, never touches a device.
            if (function_exists('locks_push_credential')) { try { locks_push_credential($db, $newId, $stayId); } catch (\Throwable $e) {} }
            $res['ok'] = true; $res['id'] = $newId; $res['pin'] = $pin;
            $res['message'] = 'Credential issued.';
            return $res;
        } catch (\Throwable $e) {
            error_log('lock_credential_issue: ' . $e->getMessage());
            $res['message'] = 'Could not issue credential.';
            return $res;
        }
    }
}

if (!function_exists('lock_credential_revoke')) {
    /**
     * Revoke an active/pending credential. Idempotent-ish: a row already revoked or
     * expired is left as-is and returns false. Scoped to $stayId (IDOR guard).
     * HONEST: revocation marks the record; it does NOT remotely wipe the device.
     */
    function lock_credential_revoke($db, int $credId, int $stayId): bool
    {
        if ($credId <= 0 || $stayId <= 0) { return false; }
        _locks_ext_ensure_schema($db);
        try {
            $ok = false;
            $db->action(function ($db) use ($credId, $stayId, &$ok) {
                $row = $db->get('stays_lock_credentials', ['id', 'lock_id', 'status'],
                    ['id' => $credId, 'stay_id' => $stayId]);
                if (!$row) { $ok = false; return false; }
                if (!in_array((string) $row['status'], ['active', 'pending'], true)) { $ok = false; return false; }
                $db->update('stays_lock_credentials',
                    ['status' => 'revoked', 'revoked_at' => date('Y-m-d H:i:s')],
                    ['id' => $credId, 'stay_id' => $stayId]);
                _locks_log($db, $stayId, (int) $row['lock_id'], 'credential_revoked',
                    ['credential_id' => $credId, 'source' => 'manual']);
                $ok = true;
                return true;
            });
            return $ok;
        } catch (\Throwable $e) { error_log('lock_credential_revoke: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('locks_creds_expire_due')) {
    /**
     * Advance active/pending credentials whose valid_until has passed → 'expired',
     * and activate pending credentials whose valid_from has arrived. Runs on-demand
     * (page load) — idempotent, bounded, logs each transition. Returns count changed.
     */
    function locks_creds_expire_due($db, array $stayIds): int
    {
        if (empty($stayIds)) { return 0; }
        _locks_ext_ensure_schema($db);
        $now = date('Y-m-d H:i:s');
        $changed = 0;
        try {
            $rows = $db->select('stays_lock_credentials', ['id', 'stay_id', 'lock_id', 'status', 'valid_from', 'valid_until'],
                ['stay_id' => $stayIds, 'status' => ['active', 'pending'], 'LIMIT' => 1000]) ?: [];
            foreach ($rows as $r) {
                $vu = (string) ($r['valid_until'] ?? '');
                $vf = (string) ($r['valid_from'] ?? '');
                if ($vu !== '' && strtotime($vu) !== false && strtotime($vu) <= time()) {
                    $db->update('stays_lock_credentials', ['status' => 'expired'], ['id' => (int) $r['id']]);
                    _locks_log($db, (int) $r['stay_id'], (int) $r['lock_id'], 'credential_expired',
                        ['credential_id' => (int) $r['id'], 'source' => 'system']);
                    $changed++;
                } elseif ((string) $r['status'] === 'pending' && $vf !== '' && strtotime($vf) !== false && strtotime($vf) <= time()) {
                    $db->update('stays_lock_credentials', ['status' => 'active'], ['id' => (int) $r['id']]);
                    _locks_log($db, (int) $r['stay_id'], (int) $r['lock_id'], 'credential_activated',
                        ['credential_id' => (int) $r['id'], 'source' => 'system']);
                    $changed++;
                }
            }
        } catch (\Throwable $e) { error_log('locks_creds_expire_due: ' . $e->getMessage()); }
        return $changed;
    }
}

if (!function_exists('locks_credentials_for_lock')) {
    /** Credentials on a lock, newest first (scoped). */
    function locks_credentials_for_lock($db, int $lockId, int $stayId, int $limit = 200): array
    {
        if ($lockId <= 0 || $stayId <= 0) { return []; }
        _locks_ext_ensure_schema($db);
        try {
            return $db->select('stays_lock_credentials', '*',
                ['lock_id' => $lockId, 'stay_id' => $stayId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => $limit]) ?: [];
        } catch (\Throwable $e) { error_log('locks_credentials_for_lock: ' . $e->getMessage()); return []; }
    }
}

// ============================================================================
// ACCESS LOG — manual operator entry + read
// ============================================================================
if (!function_exists('lock_log_manual_event')) {
    /**
     * Operator-entered access event (unlock / denied / battery_low / tamper / note).
     * This is MANUAL data — there is no live device feed. Scoped to $stayId.
     */
    function lock_log_manual_event($db, int $lockId, int $stayId, string $event, string $detail = ''): bool
    {
        if ($lockId <= 0 || $stayId <= 0) { return false; }
        $event = preg_replace('/[^a-z0-9_]+/', '', strtolower(trim($event)));
        if ($event === '') { return false; }
        _locks_ext_ensure_schema($db);
        try {
            if (!$db->has('stays_locks', ['id' => $lockId, 'stay_id' => $stayId])) { return false; }
            _locks_log($db, $stayId, $lockId, $event, ['source' => 'manual', 'detail' => $detail]);
            return true;
        } catch (\Throwable $e) { error_log('lock_log_manual_event: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('locks_access_log')) {
    /** Recent access-log rows in a scope, newest first. */
    function locks_access_log($db, array $stayIds, int $limit = 200): array
    {
        if (empty($stayIds)) { return []; }
        _locks_ext_ensure_schema($db);
        try {
            return $db->select('stays_lock_access_log', '*',
                ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => $limit]) ?: [];
        } catch (\Throwable $e) { error_log('locks_access_log: ' . $e->getMessage()); return []; }
    }
}

// ============================================================================
// PROVIDER ADAPTER — HONEST STUB (reports 'not connected')
// ============================================================================
if (!function_exists('locks_adapter_status')) {
    /**
     * HONEST STUB. No smart-lock vendor SDK ships (August / Nuki / Igloohome /
     * Salto / Yale / SmartThings). This ALWAYS reports not-connected. The stored
     * PIN / credential rows are the operational source of truth; staff program the
     * physical device out-of-band until a real adapter is implemented.
     */
    function locks_adapter_status($db, int $stayId = 0): array
    {
        return [
            'connected' => false,
            'provider'  => null,
            'message'   => 'No smart-lock vendor adapter is connected. Credentials and access events are managed manually; physical devices are programmed out-of-band.',
        ];
    }
}

if (!function_exists('locks_push_credential')) {
    /**
     * HONEST STUB. A real adapter would program the PIN/mobile credential onto the
     * physical lock. There is none, so this is a no-op that records the not-connected
     * intent in the access log and returns the stub status. It NEVER marks the
     * credential 'pushed' and NEVER claims success.
     */
    function locks_push_credential($db, int $credId, int $stayId): array
    {
        $status = locks_adapter_status($db, $stayId);
        if ($credId > 0 && $stayId > 0) {
            try {
                $c = $db->get('stays_lock_credentials', ['lock_id'], ['id' => $credId, 'stay_id' => $stayId]);
                if ($c) {
                    _locks_log($db, $stayId, (int) $c['lock_id'], 'push_skipped_no_adapter',
                        ['credential_id' => $credId, 'source' => 'system', 'detail' => 'Adapter not connected; credential not pushed to device.']);
                }
            } catch (\Throwable $e) { error_log('locks_push_credential: ' . $e->getMessage()); }
        }
        return $status;
    }
}

if (!function_exists('locks_pull_events')) {
    /**
     * HONEST STUB. A real adapter would pull unlock/denied/battery events from the
     * vendor cloud into stays_lock_access_log (source='device'). There is none, so
     * this returns 0 and does nothing. Operators use lock_log_manual_event instead.
     */
    function locks_pull_events($db, array $stayIds): int
    {
        return 0;
    }
}

// ============================================================================
// ANALYTICS — all from REAL rows
// ============================================================================
if (!function_exists('locks_analytics')) {
    /**
     * KPIs for a scope: total locks, active locks, rooms mapped (distinct), active
     * credentials, pending credentials, revoked-30d, expiring-48h, low-battery
     * (battery_pct < 20, operator-entered). Adapter is always not-connected.
     */
    function locks_analytics($db, array $stayIds): array
    {
        $out = ['locks' => 0, 'active_locks' => 0, 'rooms_mapped' => 0, 'active_creds' => 0,
                'pending_creds' => 0, 'revoked_30d' => 0, 'expiring_48h' => 0, 'low_battery' => 0,
                'adapter_connected' => false];
        if (empty($stayIds)) { return $out; }
        _locks_ext_ensure_schema($db);
        try {
            $out['locks']        = (int) $db->count('stays_locks', ['stay_id' => $stayIds]);
            $out['active_locks'] = (int) $db->count('stays_locks', ['stay_id' => $stayIds, 'active' => 1]);
            $out['rooms_mapped'] = (int) $db->count('stays_locks', ['stay_id' => $stayIds, 'physical_room_id[!]' => null, 'active' => 1]);
            $out['active_creds'] = (int) $db->count('stays_lock_credentials', ['stay_id' => $stayIds, 'status' => 'active']);
            $out['pending_creds'] = (int) $db->count('stays_lock_credentials', ['stay_id' => $stayIds, 'status' => 'pending']);
            $since30 = date('Y-m-d H:i:s', strtotime('-30 days'));
            $out['revoked_30d'] = (int) $db->count('stays_lock_credentials',
                ['stay_id' => $stayIds, 'status' => 'revoked', 'revoked_at[>=]' => $since30]);
            $soon = date('Y-m-d H:i:s', strtotime('+48 hours'));
            $out['expiring_48h'] = (int) $db->count('stays_lock_credentials', [
                'stay_id' => $stayIds, 'status' => 'active',
                'valid_until[!]' => null, 'valid_until[<=]' => $soon, 'valid_until[>=]' => date('Y-m-d H:i:s'),
            ]);
            $out['low_battery'] = (int) $db->count('stays_locks',
                ['stay_id' => $stayIds, 'active' => 1, 'battery_pct[!]' => null, 'battery_pct[<]' => 20]);
        } catch (\Throwable $e) { error_log('locks_analytics: ' . $e->getMessage()); }
        return $out;
    }
}

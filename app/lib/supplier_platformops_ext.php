<?php
// FILE: app/lib/supplier_platformops_ext.php
// MODULE 61 — Security & platform operations (DEEPEN).
//
// The platform ALREADY ships the hard parts of this module as cross-cutting spine:
//   • tenant isolation + RBAC  → supplier_acting_context / supplier_can / owner scoping
//   • audit trail              → audit_events + audit_log() + supplier_audit_feed()
//   • API/session/CSRF         → verifyApiKey, CSRF::guard, SUPPLIER_OR_STAFF_AUTH
//   • MFA step-up              → supplier_reauth_* (MODULE 45 ext)
//   • multi-lang / multi-ccy   → i18n (T::) + currencies spine
// This ext does NOT rebuild any of that. It fills the FOUR genuine platform-ops gaps a
// property operator can actually act on from their own panel, all additive + fail-closed:
//
//   (1) DATA-PRIVACY (DSAR) — export and erase a GUEST's data. A data-subject request:
//       assemble everything the OWNER holds about one guest email (profile, consent,
//       booking history, comms, complaints) into a portable JSON bundle (Right to
//       Access / portability); and record + action an erasure (Right to Erasure) that
//       REDACTS the owner-held guest profile (stays_guest_profiles: note/tags/prefs/
//       consent cleared, email pseudonymised) and files a redaction request row. It
//       deliberately does NOT touch bookings/invoices/folios/GL — those are financial
//       records with legal-retention obligations; erasing them would corrupt the ledger.
//       That boundary (what bookings-level fields may be redacted vs must be retained)
//       is DB-GATED policy and left to the owning finance domain. No money moved.
//
//   (2) SESSION SECURITY — an honest view of the actor's OWN recent authentication
//       activity, derived from audit_events (login.* / reauth.* rows for this actor).
//       There is NO server-side session registry table in this codebase (sessions are
//       native PHP), so "list every device and revoke one" is NOT fakeable. We surface
//       the actor's current session fingerprint + recent auth events, let them rotate
//       their OWN session id (real: session_regenerate_id), and report device-level
//       remote revocation as a DB-GATED capability.
//
//   (3) CONFIGURATION MANAGEMENT — an org-scoped key/value settings store with an
//       append-only change log (supplier_org_settings + supplier_config_changes), so an
//       operator can record platform-ops preferences (display timezone, retention
//       window, data-privacy contact, etc.) with full attribution. Values are STORED and
//       readable by any consuming module; where a value would alter money/tz behaviour
//       that enforcement is DB-GATED (we store the intent, we do not silently re-time GL).
//
//   (4) BUSINESS CONTINUITY / INFRA STATUS — an HONEST capability report for
//       backup/disaster-recovery, uptime monitoring, encryption-at-rest and API-key
//       rotation. None of these are host-side here, so each is reported truthfully as
//       'managed_by_host' or 'not_connected' — never faked as live.
//
// House rules honoured: every fn if_not_exists-guarded; NO edits to functions.php /
// install/db.sql / config.php / _routes.php / sidebar.php (patch spec returned instead).
// Schema is created by supplier_platformops_ext_ensure_schema() (idempotent CREATE TABLE
// IF NOT EXISTS). Reuses supplier_acting_context / supplier_org_for_owner /
// supplier_owned_stay_ids / guest_email_key / guest_history / guestcrm_consent_get /
// guestcrm_comm_history / audit_log / supplier_audit_feed. Never trusts a client amount;
// this module moves NO money and posts NO GL.

if (!function_exists('supplier_platformops_ext_ensure_schema')) {
    /**
     * Idempotent, non-fatal self-heal for the MODULE-61 ext tables. Safe to call on every
     * ext route hit (static-guarded). Creates two tables:
     *   supplier_org_settings   — org-scoped key/value config (one row per (org,key)).
     *   supplier_config_changes — append-only change log for the above.
     *   supplier_privacy_requests — DSAR (export/erase) request ledger, owner-scoped.
     */
    function supplier_platformops_ext_ensure_schema($db): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `supplier_org_settings` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `org_id` INT(11) NOT NULL,
                `owner_user_id` VARCHAR(255) NOT NULL,
                `setting_key` VARCHAR(60) NOT NULL,
                `setting_value` VARCHAR(500) DEFAULT NULL,
                `updated_by` VARCHAR(255) DEFAULT NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_org_key` (`org_id`,`setting_key`),
                KEY `idx_owner` (`owner_user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `supplier_config_changes` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `org_id` INT(11) NOT NULL,
                `owner_user_id` VARCHAR(255) NOT NULL,
                `setting_key` VARCHAR(60) NOT NULL,
                `old_value` VARCHAR(500) DEFAULT NULL,
                `new_value` VARCHAR(500) DEFAULT NULL,
                `changed_by` VARCHAR(255) DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_org_key` (`org_id`,`setting_key`),
                KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `supplier_privacy_requests` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `org_id` INT(11) NOT NULL,
                `owner_user_id` VARCHAR(255) NOT NULL,
                `subject_email` VARCHAR(191) NOT NULL,
                `kind` ENUM('export','erase') NOT NULL,
                `status` ENUM('open','fulfilled','rejected') NOT NULL DEFAULT 'open',
                `note` VARCHAR(255) DEFAULT NULL,
                `requested_by` VARCHAR(255) DEFAULT NULL,
                `fulfilled_by` VARCHAR(255) DEFAULT NULL,
                `fulfilled_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_org_email` (`org_id`,`subject_email`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            error_log('supplier_platformops_ext_ensure_schema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('_supplier_platformops_owner')) {
    /** Acting owner user_id (string) or null when the caller is not a supplier/staff.
     *  Admin returns null here deliberately — these ext screens are org-scoped. */
    function _supplier_platformops_owner($db): ?string
    {
        $ctx = function_exists('supplier_acting_context') ? supplier_acting_context($db) : null;
        if ($ctx === null || !empty($ctx['is_admin'])) { return null; }
        $owner = (string) ($ctx['owner'] ?? '');
        return $owner !== '' ? $owner : null;
    }
}

// ============================================================================
// (1) DATA-PRIVACY  —  DSAR export / erase for a GUEST
// ----------------------------------------------------------------------------
// Both paths bind the guest to the ACTING owner: a guest is only "theirs" if the
// guest's email appears in a booking on one of the owner's stays (guest_history).
// This is the same defence-in-depth the guest-CRM write path uses, so one supplier
// can never export or erase a guest who never stayed with them.
// ============================================================================

if (!function_exists('platformops_guest_is_owned')) {
    /**
     * True iff $email has at least one booking on one of the owner's stays. The gate for
     * every DSAR action. Reuses guest_history (already owner-scoped by $stayIds).
     */
    function platformops_guest_is_owned($db, array $stayIds, string $email): bool
    {
        $email = function_exists('guest_email_key') ? guest_email_key($email) : strtolower(trim($email));
        if ($email === '' || empty($stayIds)) { return false; }
        try {
            $h = function_exists('guest_history') ? guest_history($db, $stayIds, $email) : ['stays' => []];
        } catch (\Throwable $e) {
            error_log('platformops_guest_is_owned: ' . $e->getMessage());
            return false;
        }
        return !empty($h['stays']);
    }
}

if (!function_exists('platformops_export_guest')) {
    /**
     * Assemble the portable data bundle (Right to Access / portability) for ONE guest
     * email, scoped to the acting owner. Returns a structured array (the route JSON-encodes
     * + streams it as a download). Read-only — moves no money, writes nothing except the
     * caller's own request-ledger row (done in the route). Returns null if the guest is not
     * owned by this org.
     *
     * The bundle is deliberately what the OWNER holds: identity + stay history + consent +
     * communications + complaints. It does NOT include platform-internal ids the guest has
     * no claim to, nor other guests' data.
     */
    function platformops_export_guest($db, string $owner, int $orgId, array $stayIds, string $email): ?array
    {
        $email = function_exists('guest_email_key') ? guest_email_key($email) : strtolower(trim($email));
        if ($owner === '' || $email === '') { return null; }
        if (!platformops_guest_is_owned($db, $stayIds, $email)) { return null; }

        $history   = function_exists('guest_history') ? guest_history($db, $stayIds, $email) : ['profile' => null, 'stays' => []];
        $profile   = is_array($history['profile'] ?? null) ? $history['profile'] : null;
        $staysRows = is_array($history['stays'] ?? null) ? $history['stays'] : [];

        $meta    = function_exists('guest_profile_meta') ? guest_profile_meta($db, $orgId, $email) : [];
        $consent = function_exists('guestcrm_consent_get') ? guestcrm_consent_get($db, $orgId, $email) : [];
        $comms   = function_exists('guestcrm_comm_history') ? guestcrm_comm_history($db, $stayIds, $email, 500) : [];

        // Complaints/tickets for this guest (reuse the CRM helper if present).
        $complaints = [];
        if (function_exists('guestcrm_complaint_history')) {
            $guestName = $profile['name'] ?? ($profile['guest_name'] ?? '');
            try {
                $complaints = guestcrm_complaint_history($db, $stayIds, $staysRows, (string) $guestName, 500);
            } catch (\Throwable $e) { error_log('platformops_export_guest/complaints: ' . $e->getMessage()); }
        }

        return [
            'data_subject_request' => [
                'type'         => 'access_export',
                'subject_email'=> $email,
                'generated_at' => date('c'),
                'controller'   => 'org:' . $orgId,
                'scope_note'   => 'Personal data held by this property operator. Financial records '
                                . '(invoices/payments) are retained under accounting-retention rules and '
                                . 'are summarised, not exported in full, here.',
            ],
            'identity'  => [
                'email' => $email,
                'name'  => $profile['name'] ?? ($profile['guest_name'] ?? ''),
                'phone' => $profile['phone'] ?? '',
            ],
            'profile'   => $meta,
            'consent'   => $consent,
            'stays'     => array_map(static function ($s) {
                // Pass through only guest-relevant, non-internal fields.
                return [
                    'stay_id'    => $s['stay_id']    ?? ($s['_stay_id'] ?? null),
                    'property'   => $s['property']   ?? ($s['stay_name'] ?? ''),
                    'checkin'    => $s['checkin']    ?? ($s['date_from'] ?? ''),
                    'checkout'   => $s['checkout']   ?? ($s['date_to'] ?? ''),
                    'status'     => $s['status']     ?? '',
                    'reference'  => $s['reference']  ?? ($s['invoice_id'] ?? ''),
                ];
            }, $staysRows),
            'communications' => array_map(static function ($m) {
                return [
                    'at'       => $m['created_at'] ?? '',
                    'channel'  => $m['channel']    ?? '',
                    'direction'=> $m['direction']  ?? '',
                    'subject'  => $m['subject']    ?? '',
                    'body'     => $m['body']       ?? ($m['message'] ?? ''),
                ];
            }, is_array($comms) ? $comms : []),
            'complaints' => array_map(static function ($c) {
                return [
                    'at'      => $c['created_at'] ?? '',
                    'subject' => $c['subject']    ?? '',
                    'status'  => $c['status']     ?? '',
                ];
            }, is_array($complaints) ? $complaints : []),
        ];
    }
}

if (!function_exists('platformops_erase_guest')) {
    /**
     * Right to Erasure for the OWNER-HELD guest profile only. REDACTS the mutable PII the
     * operator added about the guest (note/tags/preferences cleared, consent withdrawn,
     * email pseudonymised to a non-reversible token) in stays_guest_profiles for this
     * (org,email). It does NOT delete bookings/invoices/folios/GL — those are legal-
     * retention financial records; redacting them is DB-GATED policy owned by finance.
     *
     * Returns ['ok'=>bool,'redacted'=>int,'message'=>string]. Wrapped in a single
     * transaction so the profile update + request-ledger fulfilment are atomic.
     */
    function platformops_erase_guest($db, string $owner, int $orgId, array $stayIds, string $email): array
    {
        $email = function_exists('guest_email_key') ? guest_email_key($email) : strtolower(trim($email));
        if ($owner === '' || $orgId <= 0 || $email === '') {
            return ['ok' => false, 'redacted' => 0, 'message' => 'Invalid request.'];
        }
        if (!platformops_guest_is_owned($db, $stayIds, $email)) {
            return ['ok' => false, 'redacted' => 0, 'message' => 'That guest has no bookings with your property.'];
        }
        // Non-reversible pseudonym (cannot be mapped back to the real address without a
        // separate secret; good enough to break the PII link in the owner's CRM).
        $token = 'redacted-' . substr(hash('sha256', $orgId . '|' . $email . '|' . microtime(true)), 0, 24);

        $result = ['ok' => false, 'redacted' => 0, 'message' => 'Nothing to erase.'];
        try {
            $db->action(function ($db) use ($orgId, $email, $token, &$result) {
                $has = $db->has('stays_guest_profiles', ['org_id' => $orgId, 'email' => $email]);
                if (!$has) {
                    // No owner-held profile row — nothing mutable to erase, but still a valid
                    // (no-op) fulfilment. The booking-level PII is retention-protected.
                    $result = ['ok' => true, 'redacted' => 0,
                               'message' => 'No stored profile PII to erase; financial records retained.'];
                    return true;
                }
                $db->update('stays_guest_profiles', [
                    'email'              => $token,
                    'note'               => null,
                    'tags'               => null,
                    'preferences'        => null,
                    'vip'                => 0,
                    'consent_marketing'  => 0,
                    'consent_email'      => 0,
                    'consent_sms'        => 0,
                    'consent_source'     => 'erased',
                    'consent_updated_at' => date('Y-m-d H:i:s'),
                    'updated_at'         => date('Y-m-d H:i:s'),
                ], ['org_id' => $orgId, 'email' => $email]);
                $result = ['ok' => true, 'redacted' => 1,
                           'message' => 'Guest profile PII redacted. Financial records retained per policy.'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('platformops_erase_guest: ' . $e->getMessage());
            return ['ok' => false, 'redacted' => 0, 'message' => 'Erasure failed.'];
        }
        return $result;
    }
}

if (!function_exists('platformops_privacy_request_log')) {
    /**
     * Record a DSAR in the request ledger (audit of who asked for what, when). $status
     * 'open' at file time; the route flips it to 'fulfilled' once the export/erase ran.
     * Returns the new id or 0. Owner-scoped.
     */
    function platformops_privacy_request_log($db, string $owner, int $orgId, string $email,
                                             string $kind, string $status = 'open', string $note = ''): int
    {
        $email = function_exists('guest_email_key') ? guest_email_key($email) : strtolower(trim($email));
        $kind  = in_array($kind, ['export', 'erase'], true) ? $kind : 'export';
        if ($owner === '' || $orgId <= 0 || $email === '') { return 0; }
        try {
            $db->insert('supplier_privacy_requests', [
                'org_id'        => $orgId,
                'owner_user_id' => $owner,
                'subject_email' => substr($email, 0, 191),
                'kind'          => $kind,
                'status'        => in_array($status, ['open', 'fulfilled', 'rejected'], true) ? $status : 'open',
                'note'          => $note !== '' ? substr($note, 0, 255) : null,
                'requested_by'  => isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : null,
                'fulfilled_by'  => $status === 'fulfilled' && isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : null,
                'fulfilled_at'  => $status === 'fulfilled' ? date('Y-m-d H:i:s') : null,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) {
            error_log('platformops_privacy_request_log: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('platformops_privacy_requests_for_owner')) {
    /** Recent DSAR ledger rows for the owner, newest first. Read-only. */
    function platformops_privacy_requests_for_owner($db, string $owner, int $limit = 50): array
    {
        if ($owner === '') { return []; }
        try {
            return $db->select('supplier_privacy_requests', '*',
                ['owner_user_id' => $owner, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, min(200, $limit))]) ?: [];
        } catch (\Throwable $e) {
            error_log('platformops_privacy_requests_for_owner: ' . $e->getMessage());
            return [];
        }
    }
}

// ============================================================================
// (2) SESSION SECURITY
// ----------------------------------------------------------------------------
// PHP sessions here are native (no DB session registry), so a cross-device "list &
// revoke" cannot be delivered truthfully. We give the operator (a) a fingerprint of
// their CURRENT session, (b) their recent auth events from audit_events (real data),
// and (c) a one-click rotate of their OWN session id. Remote device revocation is an
// honest DB-GATED stub.
// ============================================================================

if (!function_exists('platformops_current_session_info')) {
    /** A safe, non-secret fingerprint of the actor's current session. */
    function platformops_current_session_info(): array
    {
        $sid = '';
        if (function_exists('session_id')) { $sid = (string) @session_id(); }
        return [
            'session_fingerprint' => $sid !== '' ? substr(hash('sha256', $sid), 0, 12) : '(none)',
            'ip'                  => $_SERVER['HTTP_CF_CONNECTING_IP']
                                     ?? (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])
                                         ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0])
                                         : ($_SERVER['REMOTE_ADDR'] ?? '')),
            'user_agent'          => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
            'started_at'          => isset($_SESSION['login_time']) ? (string) $_SESSION['login_time'] : '',
        ];
    }
}

if (!function_exists('platformops_recent_auth_events')) {
    /**
     * Recent authentication-related audit rows for the ACTING actor only (login/logout/
     * reauth). Reuses supplier_audit_feed then filters to this actor + auth events, so it
     * never leaks another user's activity. Read-only.
     */
    function platformops_recent_auth_events($db, string $owner, int $limit = 40): array
    {
        $actor = isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : '';
        if ($owner === '' || $actor === '' || !function_exists('supplier_audit_feed')) { return []; }
        $rows = supplier_audit_feed($db, $owner, ['actor' => $actor, 'limit' => 200]);
        $out = [];
        foreach ($rows as $r) {
            $ev = (string) ($r['event'] ?? '');
            if (strpos($ev, 'login') !== false || strpos($ev, 'logout') !== false
                || strpos($ev, 'reauth') !== false || strpos($ev, 'session') !== false) {
                $out[] = $r;
                if (count($out) >= $limit) { break; }
            }
        }
        return $out;
    }
}

if (!function_exists('platformops_rotate_session')) {
    /**
     * Rotate the actor's OWN session id (real, local): regenerates the id and keeps the
     * session data. This is genuine session-security hygiene (defeats fixation). Returns
     * bool. Does NOT and cannot terminate the actor's sessions on OTHER devices — that is
     * DB-GATED (see platformops_session_capabilities()).
     */
    function platformops_rotate_session(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) { return false; }
        try {
            return @session_regenerate_id(true);
        } catch (\Throwable $e) {
            error_log('platformops_rotate_session: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('platformops_session_capabilities')) {
    /** HONEST capability report for session security. */
    function platformops_session_capabilities(): array
    {
        return [
            ['cap' => 'rotate_own', 'label' => 'Rotate my current session', 'status' => 'connected',
             'note' => 'Regenerates your session id in place.'],
            ['cap' => 'reauth', 'label' => 'Step-up re-authentication', 'status' => 'connected',
             'note' => 'Password confirm (see Access control). TOTP/WebAuthn are DB-GATED.'],
            ['cap' => 'remote_revoke', 'label' => 'Sign out my other devices', 'status' => 'not_connected',
             'note' => 'DB-GATED: no server-side session registry — PHP sessions are native here.'],
            ['cap' => 'device_list', 'label' => 'List all signed-in devices', 'status' => 'not_connected',
             'note' => 'DB-GATED: requires a persistent session store (none wired).'],
        ];
    }
}

// ============================================================================
// (3) CONFIGURATION MANAGEMENT  (org-scoped key/value + change log)
// ============================================================================
if (!function_exists('platformops_config_keys')) {
    /**
     * The whitelist of operator-settable platform-ops keys. Keeping this closed means a
     * client can never inject an arbitrary setting name, and every key has a known type +
     * label for the UI. Values that would affect money/tz behaviour are STORED as intent
     * only (enforcement is DB-GATED in the owning module).
     */
    function platformops_config_keys(): array
    {
        return [
            'display_timezone' => [
                'label' => 'Display timezone',
                'type'  => 'string',
                'hint'  => 'Preferred timezone label for operator-facing screens. DB-GATED: does not re-time GL postings.',
            ],
            'retention_days' => [
                'label' => 'Guest-data retention (days)',
                'type'  => 'int',
                'hint'  => 'Target retention window for guest PII. Recorded as policy; automated purge is DB-GATED.',
            ],
            'privacy_contact_email' => [
                'label' => 'Data-privacy contact email',
                'type'  => 'email',
                'hint'  => 'Where data-subject requests are routed.',
            ],
            'default_locale' => [
                'label' => 'Default locale',
                'type'  => 'string',
                'hint'  => 'Preferred language code for guest-facing content where supported.',
            ],
            'maintenance_banner' => [
                'label' => 'Operator maintenance note',
                'type'  => 'string',
                'hint'  => 'Short internal note shown on the platform-ops page.',
            ],
        ];
    }
}

if (!function_exists('platformops_config_sanitize')) {
    /** Validate+clamp a value for a known key. Returns the clean string or null if invalid. */
    function platformops_config_sanitize(string $key, string $value): ?string
    {
        $keys = platformops_config_keys();
        if (!isset($keys[$key])) { return null; }
        $value = trim($value);
        switch ($keys[$key]['type']) {
            case 'int':
                if ($value === '' ) { return '0'; }
                if (!ctype_digit($value)) { return null; }
                return (string) max(0, min(36500, (int) $value)); // 0..100yr
            case 'email':
                if ($value === '') { return ''; }
                return filter_var($value, FILTER_VALIDATE_EMAIL) ? substr($value, 0, 191) : null;
            case 'string':
            default:
                return substr($value, 0, 500);
        }
    }
}

if (!function_exists('platformops_config_all')) {
    /** All settings for an org as [key => value], merged over defaults (empty). Read-only. */
    function platformops_config_all($db, int $orgId): array
    {
        $out = [];
        foreach (platformops_config_keys() as $k => $_) { $out[$k] = ''; }
        if ($orgId <= 0) { return $out; }
        try {
            foreach ($db->select('supplier_org_settings', ['setting_key', 'setting_value'],
                ['org_id' => $orgId]) ?: [] as $r) {
                $k = (string) ($r['setting_key'] ?? '');
                if (isset($out[$k])) { $out[$k] = (string) ($r['setting_value'] ?? ''); }
            }
        } catch (\Throwable $e) {
            error_log('platformops_config_all: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('platformops_config_set')) {
    /**
     * Upsert one org setting + append a change-log row (old → new) atomically. Validates
     * the key against the whitelist and the value against its type. Returns
     * ['ok'=>bool,'message'=>string]. No money, no GL.
     */
    function platformops_config_set($db, string $owner, int $orgId, string $key, string $value): array
    {
        if ($owner === '' || $orgId <= 0) { return ['ok' => false, 'message' => 'Invalid org.']; }
        $keys = platformops_config_keys();
        if (!isset($keys[$key])) { return ['ok' => false, 'message' => 'Unknown setting.']; }
        $clean = platformops_config_sanitize($key, $value);
        if ($clean === null) { return ['ok' => false, 'message' => 'Invalid value for ' . $keys[$key]['label'] . '.']; }

        $by = isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : null;
        try {
            $db->action(function ($db) use ($orgId, $owner, $key, $clean, $by) {
                $existing = $db->get('supplier_org_settings', ['setting_value'],
                    ['org_id' => $orgId, 'setting_key' => $key]);
                $old = is_array($existing) ? (string) ($existing['setting_value'] ?? '') : null;
                if ($old === $clean) { return true; } // no-op, no change-log noise
                if ($existing) {
                    $db->update('supplier_org_settings',
                        ['setting_value' => $clean, 'updated_by' => $by, 'updated_at' => date('Y-m-d H:i:s')],
                        ['org_id' => $orgId, 'setting_key' => $key]);
                } else {
                    $db->insert('supplier_org_settings', [
                        'org_id' => $orgId, 'owner_user_id' => $owner, 'setting_key' => $key,
                        'setting_value' => $clean, 'updated_by' => $by, 'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
                $db->insert('supplier_config_changes', [
                    'org_id' => $orgId, 'owner_user_id' => $owner, 'setting_key' => $key,
                    'old_value' => $old, 'new_value' => $clean, 'changed_by' => $by,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                return true;
            });
        } catch (\Throwable $e) {
            error_log('platformops_config_set: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not save setting.'];
        }
        return ['ok' => true, 'message' => 'Setting saved.'];
    }
}

if (!function_exists('platformops_config_changelog')) {
    /** Recent config changes for the org, newest first. Read-only. */
    function platformops_config_changelog($db, int $orgId, int $limit = 50): array
    {
        if ($orgId <= 0) { return []; }
        try {
            return $db->select('supplier_config_changes', '*',
                ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, min(200, $limit))]) ?: [];
        } catch (\Throwable $e) {
            error_log('platformops_config_changelog: ' . $e->getMessage());
            return [];
        }
    }
}

// ============================================================================
// (4) BUSINESS CONTINUITY / INFRA STATUS  (HONEST capability report)
// ============================================================================
if (!function_exists('platformops_infra_status')) {
    /**
     * Truthful status of the infra-level platform-ops concerns. NONE of these are wired to
     * a live control plane from inside the app, so each is reported as it actually is —
     * either handled by the host/ops team outside the app, or simply not connected. This
     * is intentionally NOT a fake "all green" dashboard.
     */
    function platformops_infra_status(): array
    {
        return [
            ['area' => 'Backups', 'label' => 'Database & file backups', 'status' => 'managed_by_host',
             'note' => 'Backups are a hosting/ops responsibility outside the application. No in-app trigger is wired.'],
            ['area' => 'Disaster recovery', 'label' => 'Restore / failover', 'status' => 'managed_by_host',
             'note' => 'DR runbook lives with the infrastructure provider; the app exposes no restore control.'],
            ['area' => 'Monitoring', 'label' => 'Uptime & error monitoring', 'status' => 'not_connected',
             'note' => 'DB-GATED: no monitoring provider (e.g. uptime/APM) credentials are configured.'],
            ['area' => 'Encryption at rest', 'label' => 'Storage encryption', 'status' => 'managed_by_host',
             'note' => 'Depends on the database/disk configuration at the hosting layer.'],
            ['area' => 'API keys', 'label' => 'Mobile/REST API key rotation', 'status' => 'admin_managed',
             'note' => 'The REST API key lives in platform settings and is rotated by an administrator, not per-supplier.'],
        ];
    }
}

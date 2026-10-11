<?php
// FILE: app/lib/supplier_rbac_ext.php
// MODULE 45 — Users, roles & permissions (DEEPEN).
//
// Builds the genuine RBAC gaps ON TOP of the shipped spine (supplier_roles,
// supplier_role_property, supplier_staff, supplier_role_limits, audit_events,
// supplier_can / supplier_can_approve / supplier_acting_context). It does NOT
// rebuild any of those. What this adds, all additive + fail-closed:
//
//   (1) DEPARTMENTS — an org-owned department taxonomy (front-desk, housekeeping,
//       finance, F&B, maintenance…) + a nullable supplier_staff.department_id so a
//       staff member's *area* is first-class, orthogonal to their role's property
//       scope. Pure data model + helpers; supplier_can() is UNTOUCHED (departments
//       are an organisational lens / reporting filter, not a new permission axis —
//       the permission axis is still the role matrix + property scope).
//
//   (2) SENSITIVE-ACTION RE-AUTH (step-up) — a short-lived "I just proved my
//       password" session stamp. supplier_reauth_ok() tells a sensitive handler
//       whether the actor re-authenticated within the TTL; supplier_reauth_confirm()
//       verifies the posted password against users.password (password_verify) and,
//       on success, stamps the window. No external IdP / WebAuthn is contacted — a
//       hardware-key / SSO step-up is explicitly a DB-GATED follow-on (see stub
//       supplier_reauth_methods()).
//
//   (3) STAFF AUDIT LOG (owner-facing) — audit_recent() already writes/reads
//       audit_events, but there was NO owner-scoped read path. supplier_audit_feed()
//       returns audit rows scoped to the owner's org AND restricted to actors who are
//       the owner or the owner's own staff, so one supplier can never read another's
//       trail. supplier_audit_stamp() is a thin convenience over audit_log().
//
//   (4) APPROVAL ESCALATION QUEUE — supplier_can_approve() already decides whether a
//       role may approve a value, but a denied staff action had nowhere to go. This
//       records an honest escalation request (supplier_approval_requests) that an
//       owner/authorised approver resolves. It records the DECISION only — it moves
//       NO money and posts NO GL. Any consuming money path (refund/discount/payout)
//       must still run its own gl_post/folio path AFTER an 'approved' decision; that
//       wiring is DB-GATED and intentionally left to the owning domain.
//
// House rules honoured: every fn if_not_exists-guarded; no edits to functions.php /
// install/db.sql / config.php. Schema is created by supplier_rbac_ext_ensure_schema()
// (idempotent CREATE TABLE IF NOT EXISTS + guarded SHOW COLUMNS ALTER), mirrored in
// the patch spec for install/db.sql. Reuses supplier_acting_context / supplier_can /
// supplier_can_approve / supplier_org_for_owner / audit_log.

if (!function_exists('supplier_rbac_ext_ensure_schema')) {
    /**
     * Idempotent, non-fatal self-heal for the MODULE-45 ext tables + the one additive
     * column on supplier_staff. Safe to call on every ext route hit (static-guarded).
     */
    function supplier_rbac_ext_ensure_schema($db): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            // Org-owned departments. owner_user_id is the varchar users.user_id (the
            // identity the whole supplier spine keys on); org_id is a convenience copy.
            $db->query("CREATE TABLE IF NOT EXISTS `supplier_departments` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `owner_user_id` VARCHAR(255) NOT NULL,
                `org_id` INT(11) DEFAULT NULL,
                `name` VARCHAR(120) NOT NULL,
                `code` VARCHAR(40) DEFAULT NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_owner` (`owner_user_id`),
                KEY `idx_active` (`active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Honest escalation queue. Records the request + the decision; moves no
            // money. subject_type/subject_id point at whatever the approval is about
            // (e.g. 'booking', 123) so a later money path can correlate.
            $db->query("CREATE TABLE IF NOT EXISTS `supplier_approval_requests` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `owner_user_id` VARCHAR(255) NOT NULL,
                `org_id` INT(11) DEFAULT NULL,
                `requested_by` VARCHAR(255) NOT NULL,
                `limit_key` VARCHAR(40) NOT NULL,
                `amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
                `stay_id` INT(11) DEFAULT NULL,
                `subject_type` VARCHAR(40) DEFAULT NULL,
                `subject_id` VARCHAR(64) DEFAULT NULL,
                `reason` VARCHAR(255) DEFAULT NULL,
                `status` ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
                `decided_by` VARCHAR(255) DEFAULT NULL,
                `decided_at` DATETIME DEFAULT NULL,
                `decision_note` VARCHAR(255) DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_owner_status` (`owner_user_id`,`status`),
                KEY `idx_requested_by` (`requested_by`),
                KEY `idx_subject` (`subject_type`,`subject_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Additive column: supplier_staff.department_id (nullable). Guarded so a
            // re-run or an older DB without the column is healed once.
            $cols = $db->query("SHOW COLUMNS FROM `supplier_staff` LIKE 'department_id'")->fetchAll();
            if (empty($cols)) {
                $db->query("ALTER TABLE `supplier_staff` ADD COLUMN `department_id` INT(11) DEFAULT NULL AFTER `role_id`");
            }
        } catch (\Throwable $e) {
            error_log('supplier_rbac_ext_ensure_schema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('_supplier_rbac_owner')) {
    /** Acting owner user_id (string) or null when the caller is not a supplier/staff.
     *  Admin returns null here deliberately — these ext screens are org-scoped and an
     *  admin has no single org (admin manages suppliers via the admin panel). */
    function _supplier_rbac_owner($db): ?string
    {
        $ctx = function_exists('supplier_acting_context') ? supplier_acting_context($db) : null;
        if ($ctx === null || !empty($ctx['is_admin'])) { return null; }
        $owner = (string) ($ctx['owner'] ?? '');
        return $owner !== '' ? $owner : null;
    }
}

// ============================================================================
// (1) DEPARTMENTS
// ============================================================================
if (!function_exists('supplier_department_seeds')) {
    /** Suggested starter departments (offered on an empty list; never auto-inserted). */
    function supplier_department_seeds(): array
    {
        return [
            ['name' => 'Front Desk',    'code' => 'frontdesk'],
            ['name' => 'Housekeeping',  'code' => 'housekeeping'],
            ['name' => 'Maintenance',   'code' => 'maintenance'],
            ['name' => 'Food & Beverage','code' => 'fnb'],
            ['name' => 'Finance',       'code' => 'finance'],
            ['name' => 'Management',    'code' => 'management'],
        ];
    }
}

if (!function_exists('supplier_departments_for_owner')) {
    /** All departments for an owner (active-first), fail-closed to []. */
    function supplier_departments_for_owner($db, string $owner, bool $activeOnly = false): array
    {
        if ($owner === '') { return []; }
        try {
            $where = ['owner_user_id' => $owner];
            if ($activeOnly) { $where['active'] = 1; }
            $where['ORDER'] = ['active' => 'DESC', 'name' => 'ASC'];
            return $db->select('supplier_departments',
                ['id', 'name', 'code', 'active', 'created_at'], $where) ?: [];
        } catch (\Throwable $e) {
            error_log('supplier_departments_for_owner: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('supplier_department_owned')) {
    /** A department row ONLY if it belongs to $owner (IDOR guard), else null. */
    function supplier_department_owned($db, int $id, string $owner): ?array
    {
        if ($id <= 0 || $owner === '') { return null; }
        try {
            $r = $db->get('supplier_departments', '*', ['id' => $id]);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$r) { return null; }
        return ((string) ($r['owner_user_id'] ?? '') === $owner) ? $r : null;
    }
}

if (!function_exists('supplier_department_save')) {
    /**
     * Create/update a department for $owner. $id=0 → insert. Returns the department id
     * or 0 on failure. Caller must have already passed supplier_can('staff','add'|'edit').
     */
    function supplier_department_save($db, string $owner, int $id, string $name, string $code = '', bool $active = true): int
    {
        $name = trim($name);
        if ($owner === '' || $name === '') { return 0; }
        $code = trim($code);
        $code = $code !== '' ? substr(preg_replace('/[^a-z0-9_\-]/i', '', $code), 0, 40) : null;
        $orgId = function_exists('supplier_org_for_owner') ? (supplier_org_for_owner($db, $owner) ?: null) : null;
        try {
            if ($id > 0) {
                if (!supplier_department_owned($db, $id, $owner)) { return 0; }
                $db->update('supplier_departments', [
                    'name'       => substr($name, 0, 120),
                    'code'       => $code,
                    'active'     => $active ? 1 : 0,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => $id]);
                return $id;
            }
            $db->insert('supplier_departments', [
                'owner_user_id' => $owner,
                'org_id'        => $orgId,
                'name'          => substr($name, 0, 120),
                'code'          => $code,
                'active'        => $active ? 1 : 0,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) {
            error_log('supplier_department_save: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('supplier_staff_set_department')) {
    /**
     * Assign (or clear, $deptId=0) a staff member's department. Both the staff row and
     * the department must belong to $owner — the cross-tenant guard. Returns bool.
     */
    function supplier_staff_set_department($db, string $owner, int $staffId, int $deptId): bool
    {
        if ($owner === '' || $staffId <= 0) { return false; }
        try {
            $staff = $db->get('supplier_staff', ['owner_user_id'], ['id' => $staffId]);
        } catch (\Throwable $e) {
            return false;
        }
        if (!$staff || (string) ($staff['owner_user_id'] ?? '') !== $owner) { return false; }
        if ($deptId > 0 && !supplier_department_owned($db, $deptId, $owner)) { return false; }
        try {
            $db->update('supplier_staff',
                ['department_id' => $deptId > 0 ? $deptId : null],
                ['id' => $staffId]);
            return true;
        } catch (\Throwable $e) {
            error_log('supplier_staff_set_department: ' . $e->getMessage());
            return false;
        }
    }
}

// ============================================================================
// (2) SENSITIVE-ACTION RE-AUTHENTICATION (step-up)
// ============================================================================
if (!function_exists('supplier_reauth_ttl')) {
    /** Seconds a step-up stays valid after a successful password confirm. */
    function supplier_reauth_ttl(): int { return 300; } // 5 minutes
}

if (!function_exists('supplier_reauth_ok')) {
    /**
     * True iff the current actor re-authenticated within the TTL window. Keyed to the
     * acting user_id so a session fixation / user-switch cannot inherit a stale stamp.
     */
    function supplier_reauth_ok(): bool
    {
        $actor = isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : '';
        if ($actor === '') { return false; }
        $stamp = $_SESSION['supplier_reauth'] ?? null;
        if (!is_array($stamp)) { return false; }
        if ((string) ($stamp['uid'] ?? '') !== $actor) { return false; }
        $at = (int) ($stamp['at'] ?? 0);
        return $at > 0 && (time() - $at) <= supplier_reauth_ttl();
    }
}

if (!function_exists('supplier_reauth_confirm')) {
    /**
     * Verify $password against the ACTING user's stored hash (users.password) and, on
     * success, stamp the step-up window. Returns bool. Never reveals which half failed.
     * This is a local password step-up only — SSO/WebAuthn re-auth is DB-GATED (see
     * supplier_reauth_methods()).
     */
    function supplier_reauth_confirm($db, string $password): bool
    {
        $actor = isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : '';
        if ($actor === '' || $password === '') { return false; }
        try {
            $u = $db->get('users', ['password'], ['user_id' => $actor]);
        } catch (\Throwable $e) {
            return false;
        }
        $hash = is_array($u) ? (string) ($u['password'] ?? '') : '';
        if ($hash === '' || !password_verify($password, $hash)) { return false; }
        $_SESSION['supplier_reauth'] = ['uid' => $actor, 'at' => time()];
        return true;
    }
}

if (!function_exists('supplier_reauth_clear')) {
    /** Drop the step-up stamp (e.g. after a sensitive action completes). */
    function supplier_reauth_clear(): void { unset($_SESSION['supplier_reauth']); }
}

if (!function_exists('supplier_reauth_methods')) {
    /**
     * HONEST capability report for the step-up subsystem. 'password' is live; stronger
     * factors require an external edge that is NOT connected here.
     */
    function supplier_reauth_methods(): array
    {
        return [
            ['method' => 'password', 'label' => 'Password confirmation', 'status' => 'connected'],
            ['method' => 'totp',     'label' => 'Authenticator app (TOTP)', 'status' => 'not_connected',
             'note' => 'DB-GATED: no TOTP secret store / verifier wired yet.'],
            ['method' => 'webauthn', 'label' => 'Security key / passkey',   'status' => 'not_connected',
             'note' => 'DB-GATED: no WebAuthn relying-party integration yet.'],
            ['method' => 'sso',      'label' => 'SSO re-auth',              'status' => 'not_connected',
             'note' => 'DB-GATED: no external IdP connected.'],
        ];
    }
}

// ============================================================================
// (3) STAFF AUDIT LOG (owner-facing read path)
// ============================================================================
if (!function_exists('supplier_audit_actor_ids_for_owner')) {
    /** The set of user_ids whose actions belong to this org: the owner + all staff
     *  rows ever linked (any status, so revoked staff history still shows). */
    function supplier_audit_actor_ids_for_owner($db, string $owner): array
    {
        if ($owner === '') { return []; }
        $ids = [$owner];
        try {
            $rows = $db->select('supplier_staff', ['staff_user_id'], ['owner_user_id' => $owner]) ?: [];
            foreach ($rows as $r) {
                $sid = (string) ($r['staff_user_id'] ?? '');
                if ($sid !== '') { $ids[$sid] = $sid; }
            }
        } catch (\Throwable $e) {
            error_log('supplier_audit_actor_ids_for_owner: ' . $e->getMessage());
        }
        return array_values(array_unique($ids));
    }
}

if (!function_exists('supplier_audit_feed')) {
    /**
     * Owner-scoped audit rows. Scoped two ways (both must hold): org_id == owner's org
     * OR actor_user_id ∈ the owner's people. We OR them because early audit rows may
     * lack org_id but still carry an actor; the actor allow-list prevents cross-tenant
     * leakage either way. Optional $event substring + actor filter. Fail-closed to [].
     */
    function supplier_audit_feed($db, string $owner, array $opts = []): array
    {
        if ($owner === '') { return []; }
        $actorIds = supplier_audit_actor_ids_for_owner($db, $owner);
        if (empty($actorIds)) { return []; }
        $orgId = function_exists('supplier_org_for_owner') ? (int) supplier_org_for_owner($db, $owner) : 0;
        $limit = max(1, min(500, (int) ($opts['limit'] ?? 150)));

        // Base: any row whose actor is one of ours. (org_id alone is not trusted as the
        // sole scope because audit_events.org_id is nullable for many emitters.)
        $where = ['actor_user_id' => $actorIds];
        if (!empty($opts['actor']) && in_array((string) $opts['actor'], $actorIds, true)) {
            $where['actor_user_id'] = (string) $opts['actor'];
        }
        if (!empty($opts['event'])) {
            $where['event[~]'] = (string) $opts['event'];
        }
        $where['ORDER'] = ['id' => 'DESC'];
        $where['LIMIT'] = $limit;
        try {
            $rows = $db->select('audit_events',
                ['id', 'org_id', 'actor_user_id', 'event', 'subject_type', 'subject_id', 'meta', 'ip', 'created_at'],
                $where) ?: [];
        } catch (\Throwable $e) {
            error_log('supplier_audit_feed: ' . $e->getMessage());
            return [];
        }
        // Defence in depth: if a row DOES carry an org_id, it must be ours.
        $out = [];
        foreach ($rows as $r) {
            $rowOrg = $r['org_id'] === null ? null : (int) $r['org_id'];
            if ($rowOrg !== null && $orgId > 0 && $rowOrg !== $orgId) { continue; }
            $out[] = $r;
        }
        return $out;
    }
}

if (!function_exists('supplier_audit_stamp')) {
    /** Thin owner-scoped wrapper over audit_log() so ext routes record consistently. */
    function supplier_audit_stamp($db, string $owner, string $event, ?string $subjectType = null,
                                  $subjectId = null, array $meta = []): void
    {
        if (!function_exists('audit_log')) { return; }
        $orgId = function_exists('supplier_org_for_owner') ? (supplier_org_for_owner($db, $owner) ?: null) : null;
        audit_log($db, $event, $subjectType, $subjectId, $meta, $orgId);
    }
}

// ============================================================================
// (4) APPROVAL ESCALATION QUEUE  (records decisions only — NO money movement)
// ============================================================================
if (!function_exists('supplier_approval_request_create')) {
    /**
     * Record an escalation: "staff X wants to approve $amount for $key but their role
     * cannot (supplier_can_approve === false), so route it to an owner/approver."
     * Returns the new request id or 0. Idempotent is NOT claimed — callers dedupe by
     * subject if needed. Moves NO money.
     */
    function supplier_approval_request_create($db, string $owner, string $key, float $amount,
                                              $stayId = null, ?string $subjectType = null,
                                              $subjectId = null, string $reason = ''): int
    {
        if ($owner === '' || $key === '') { return 0; }
        $requestedBy = isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : '';
        if ($requestedBy === '') { return 0; }
        $orgId = function_exists('supplier_org_for_owner') ? (supplier_org_for_owner($db, $owner) ?: null) : null;
        try {
            $db->insert('supplier_approval_requests', [
                'owner_user_id' => $owner,
                'org_id'        => $orgId,
                'requested_by'  => $requestedBy,
                'limit_key'     => substr($key, 0, 40),
                'amount'        => round($amount, 2),
                'stay_id'       => $stayId !== null ? (int) $stayId : null,
                'subject_type'  => $subjectType !== null ? substr($subjectType, 0, 40) : null,
                'subject_id'    => ($subjectId === null || $subjectId === '') ? null : substr((string) $subjectId, 0, 64),
                'reason'        => $reason !== '' ? substr($reason, 0, 255) : null,
                'status'        => 'pending',
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
            $id = (int) $db->id();
            supplier_audit_stamp($db, $owner, 'approval.requested', 'approval_request', $id,
                ['key' => $key, 'amount' => round($amount, 2), 'stay_id' => $stayId]);
            return $id;
        } catch (\Throwable $e) {
            error_log('supplier_approval_request_create: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('supplier_approval_requests_for_owner')) {
    /** Escalation requests for an owner, newest first, optional status filter. */
    function supplier_approval_requests_for_owner($db, string $owner, ?string $status = null, int $limit = 100): array
    {
        if ($owner === '') { return []; }
        try {
            $where = ['owner_user_id' => $owner];
            if ($status !== null && $status !== '') { $where['status'] = $status; }
            $where['ORDER'] = ['id' => 'DESC'];
            $where['LIMIT'] = max(1, min(500, $limit));
            return $db->select('supplier_approval_requests', '*', $where) ?: [];
        } catch (\Throwable $e) {
            error_log('supplier_approval_requests_for_owner: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('supplier_approval_request_decide')) {
    /**
     * Resolve a pending escalation. The DECIDER must be able to approve the amount
     * themselves (supplier_can_approve) — an owner always can; a staff approver only
     * within their own role limit, closing the loophole where a capped approver rubber-
     * stamps a larger request. Transitions pending → approved|rejected only. Returns
     * ['ok'=>bool,'error'=>?string]. Moves NO money — a 'approved' decision is a SIGNAL
     * the consuming money path (DB-GATED) must act on, re-checking its own invariants.
     */
    function supplier_approval_request_decide($db, string $owner, int $id, string $decision, string $note = ''): array
    {
        if ($owner === '' || $id <= 0) { return ['ok' => false, 'error' => 'Bad request.']; }
        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            return ['ok' => false, 'error' => 'Unknown decision.'];
        }
        try {
            $req = $db->get('supplier_approval_requests', '*', ['id' => $id]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Lookup failed.'];
        }
        if (!$req || (string) ($req['owner_user_id'] ?? '') !== $owner) {
            return ['ok' => false, 'error' => 'Not found.']; // IDOR / cross-tenant guard
        }
        if (($req['status'] ?? '') !== 'pending') {
            return ['ok' => false, 'error' => 'Already decided.'];
        }
        // Approving requires the decider actually hold the authority for this value.
        if ($decision === 'approved' && function_exists('supplier_can_approve')) {
            $amt   = (float) ($req['amount'] ?? 0);
            $key   = (string) ($req['limit_key'] ?? '');
            $stay  = $req['stay_id'] !== null ? (int) $req['stay_id'] : null;
            if (!supplier_can_approve($db, $key, $amt, $stay)) {
                return ['ok' => false, 'error' => 'You are not authorised to approve this amount.'];
            }
        }
        $decider = isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : '';
        try {
            $db->update('supplier_approval_requests', [
                'status'        => $decision,
                'decided_by'    => $decider,
                'decided_at'    => date('Y-m-d H:i:s'),
                'decision_note' => $note !== '' ? substr($note, 0, 255) : null,
            ], ['id' => $id, 'status' => 'pending']); // CAS on status — no double-decide
        } catch (\Throwable $e) {
            error_log('supplier_approval_request_decide: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Update failed.'];
        }
        supplier_audit_stamp($db, $owner, 'approval.' . $decision, 'approval_request', $id,
            ['key' => $req['limit_key'] ?? '', 'amount' => $req['amount'] ?? 0]);
        return ['ok' => true, 'error' => null];
    }
}

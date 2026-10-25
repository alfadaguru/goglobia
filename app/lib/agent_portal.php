<?php
// ============================================================================
// app/lib/agent_portal.php
// Travel-Agent ERP / B2B portal — shared foundation.
//
// This is the agent-side sibling of the supplier portal spine. It deliberately
// MIRRORS the supplier RBAC/context model (app/lib/functions.php:440-630 and
// app/routes/users/supplierStaffRoutes.php) rather than reusing those functions,
// so a change to the supplier side never silently changes the agent side.
//
// Contents:
//   • ensureAgentPortalSchema($db) — idempotent self-heal of all agent_* tables
//     + the agency branding/markup columns + bookings attribution columns.
//   • agent_acting_context($db) / agent_staff_context($db,$actor) — resolve the
//     logged-in user to an agency OWNER or active STAFF context (staff→owner).
//   • agent_role_allows() / agent_can() — the permission gate ({module:{action}}).
//   • AGENT_OR_STAFF_AUTH($db) — route guard (bounces non-agents to login).
//   • agent_default_roles() / agent_seed_default_roles($db,$owner) — the 4 locked
//     default roles, seeded per agency.
//   • agent_can_capabilities() — the capability catalogue (modules × actions).
//
// Owner = a users row with role='agent'. Staff = a users row (role='customer')
// linked via agent_staff to that owner. The whole portal is scoped to the owner.
// ============================================================================

// ----------------------------------------------------------------------------
// SCHEMA (idempotent, self-healing — mirrors ensurePaystackDvaSchema style).
// Wired from config.php after the DB connects. Keep install/db.sql in sync.
// ----------------------------------------------------------------------------
if (!function_exists('ensureAgentPortalSchema')) {
    function ensureAgentPortalSchema($db): void
    {
        static $checked = false;
        if ($checked) { return; }
        $checked = true;

        try {
            // --- agent_roles: per-agency roles (default + custom) -------------
            $db->query("CREATE TABLE IF NOT EXISTS `agent_roles` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `owner_user_id` varchar(255) NOT NULL,
                `name` varchar(120) NOT NULL,
                `permissions` longtext DEFAULT NULL,
                `is_system` tinyint(1) NOT NULL DEFAULT 0,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_owner` (`owner_user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // --- agent_staff: invited sub-users scoped to an owner -----------
            $db->query("CREATE TABLE IF NOT EXISTS `agent_staff` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `owner_user_id` varchar(255) NOT NULL,
                `staff_user_id` varchar(255) DEFAULT NULL,
                `role_id` int(11) DEFAULT NULL,
                `invited_email` varchar(255) DEFAULT NULL,
                `invite_token` varchar(255) DEFAULT NULL,
                `invite_expires` datetime DEFAULT NULL,
                `status` enum('invited','active','suspended','revoked') NOT NULL DEFAULT 'invited',
                `accepted_at` datetime DEFAULT NULL,
                `created_by` varchar(255) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_owner_email` (`owner_user_id`,`invited_email`),
                KEY `idx_staff` (`staff_user_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // --- agent_clients: the agency's own CRM / client book -----------
            $db->query("CREATE TABLE IF NOT EXISTS `agent_clients` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `owner_user_id` varchar(255) NOT NULL,
                `title` varchar(16) DEFAULT NULL,
                `first_name` varchar(120) DEFAULT NULL,
                `last_name` varchar(120) DEFAULT NULL,
                `email` varchar(255) DEFAULT NULL,
                `phone` varchar(40) DEFAULT NULL,
                `phone_country_code` varchar(12) DEFAULT NULL,
                `dob` date DEFAULT NULL,
                `nationality` varchar(2) DEFAULT NULL,
                `passport_number` varchar(60) DEFAULT NULL,
                `passport_expiry` date DEFAULT NULL,
                `tags` varchar(255) DEFAULT NULL,
                `notes` text DEFAULT NULL,
                `created_by` varchar(255) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_owner` (`owner_user_id`),
                KEY `idx_email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // --- agent_trip_plans + items: saved itineraries / proposals -----
            $db->query("CREATE TABLE IF NOT EXISTS `agent_trip_plans` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `ref` varchar(40) NOT NULL,
                `owner_user_id` varchar(255) NOT NULL,
                `created_by` varchar(255) DEFAULT NULL,
                `client_id` int(11) DEFAULT NULL,
                `client_name` varchar(191) DEFAULT NULL,
                `client_email` varchar(255) DEFAULT NULL,
                `title` varchar(191) DEFAULT NULL,
                `status` enum('draft','quoted','sent','accepted','booked','archived') NOT NULL DEFAULT 'draft',
                `items_data` longtext DEFAULT NULL,
                `totals_data` longtext DEFAULT NULL,
                `snapshot_hash` varchar(64) DEFAULT NULL,
                `client_token` varchar(80) DEFAULT NULL,
                `currency` varchar(10) DEFAULT NULL,
                `notes` text DEFAULT NULL,
                `client_message` text DEFAULT NULL,
                `expires_at` datetime DEFAULT NULL,
                `sent_at` datetime DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_ref` (`ref`),
                KEY `idx_owner` (`owner_user_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // --- agent_audit: per-agency action log --------------------------
            $db->query("CREATE TABLE IF NOT EXISTS `agent_audit` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `owner_user_id` varchar(255) NOT NULL,
                `actor_user_id` varchar(255) DEFAULT NULL,
                `action` varchar(120) DEFAULT NULL,
                `detail` text DEFAULT NULL,
                `ip` varchar(64) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_owner` (`owner_user_id`),
                KEY `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // --- agencies branding + onboarding columns ----------------------
            foreach ([
                ['agencies', 'brand_primary',   "ALTER TABLE `agencies` ADD COLUMN `brand_primary` VARCHAR(7) DEFAULT NULL"],
                ['agencies', 'brand_accent',    "ALTER TABLE `agencies` ADD COLUMN `brand_accent` VARCHAR(7) DEFAULT NULL"],
                ['agencies', 'onboarding_done', "ALTER TABLE `agencies` ADD COLUMN `onboarding_done` TINYINT(1) NOT NULL DEFAULT 0"],
            ] as [$tbl, $col, $sql]) {
                try {
                    if (!$db->query("SHOW COLUMNS FROM `$tbl` LIKE '$col'")->fetch()) {
                        $db->query($sql);
                    }
                } catch (\Throwable $e) { error_log("ensureAgentPortalSchema $col: " . $e->getMessage()); }
            }

            // --- users self-markup column (agency client-price over B2B) -----
            try {
                if (!$db->query("SHOW COLUMNS FROM `users` LIKE 'agent_customer_markup'")->fetch()) {
                    $db->query("ALTER TABLE `users` ADD COLUMN `agent_customer_markup` DECIMAL(10,2) DEFAULT NULL");
                }
                if (!$db->query("SHOW COLUMNS FROM `users` LIKE 'agent_customer_markup_type'")->fetch()) {
                    $db->query("ALTER TABLE `users` ADD COLUMN `agent_customer_markup_type` VARCHAR(20) DEFAULT 'percentage'");
                }
            } catch (\Throwable $e) { error_log('ensureAgentPortalSchema markup: ' . $e->getMessage()); }

            // --- bookings attribution + agent client-price columns -----------
            foreach ([
                ['agency_id',          "ALTER TABLE `bookings` ADD COLUMN `agency_id` VARCHAR(255) DEFAULT NULL"],
                ['booked_by_staff_id', "ALTER TABLE `bookings` ADD COLUMN `booked_by_staff_id` VARCHAR(255) DEFAULT NULL"],
                ['agent_client_price', "ALTER TABLE `bookings` ADD COLUMN `agent_client_price` DECIMAL(14,2) DEFAULT NULL"],
                ['agent_client_margin',"ALTER TABLE `bookings` ADD COLUMN `agent_client_margin` DECIMAL(14,2) DEFAULT NULL"],
            ] as [$col, $sql]) {
                try {
                    if (!$db->query("SHOW COLUMNS FROM `bookings` LIKE '$col'")->fetch()) {
                        $db->query($sql);
                    }
                } catch (\Throwable $e) { error_log("ensureAgentPortalSchema bookings.$col: " . $e->getMessage()); }
            }

            // --- settings toggles --------------------------------------------
            foreach ([
                ['agent_portal_enabled',      "ALTER TABLE `settings` ADD COLUMN `agent_portal_enabled` ENUM('1','0') NOT NULL DEFAULT '1'"],
                ['agent_dva_enabled',         "ALTER TABLE `settings` ADD COLUMN `agent_dva_enabled` ENUM('1','0') NOT NULL DEFAULT '1'"],
                ['agent_self_markup_enabled', "ALTER TABLE `settings` ADD COLUMN `agent_self_markup_enabled` ENUM('1','0') NOT NULL DEFAULT '1'"],
            ] as [$col, $sql]) {
                try {
                    if (!$db->query("SHOW COLUMNS FROM `settings` LIKE '$col'")->fetch()) {
                        $db->query($sql);
                        if (is_array($GLOBALS['app'] ?? null)) {
                            $GLOBALS['app'][$col] = ($col === 'agent_self_markup_enabled' || $col === 'agent_portal_enabled' || $col === 'agent_dva_enabled') ? '1' : '0';
                        }
                    }
                } catch (\Throwable $e) { error_log("ensureAgentPortalSchema settings.$col: " . $e->getMessage()); }
            }
        } catch (\Throwable $e) {
            // Never break the page over a migration (e.g. a DB user without ALTER).
            error_log('ensureAgentPortalSchema: ' . $e->getMessage());
        }
    }
}

// ----------------------------------------------------------------------------
// CAPABILITY CATALOGUE — modules × actions an agent role may grant.
// The granted permission is the KEY being present (value ''), mirroring the
// users_roles / supplier_roles convention.
// ----------------------------------------------------------------------------
if (!function_exists('agent_capabilities_catalogue')) {
    function agent_capabilities_catalogue(): array
    {
        return [
            'bookings' => ['view', 'add', 'cancel', 'refund', 'edit'],
            'clients'  => ['view', 'add', 'edit', 'delete'],
            'trips'    => ['view', 'add', 'edit', 'send', 'book'],
            'wallet'   => ['view', 'fund', 'manage'],
            'reports'  => ['view', 'export'],
            'branding' => ['view', 'edit'],
            'staff'    => ['view', 'manage'],
            'roles'    => ['view', 'manage'],
            'api'      => ['view', 'manage'],
        ];
    }
}

// ----------------------------------------------------------------------------
// DEFAULT ROLES — the 4 locked system roles seeded per agency.
// ----------------------------------------------------------------------------
if (!function_exists('agent_default_roles')) {
    function agent_default_roles(): array
    {
        // permissions: {module:{action:''}} — '*' handled specially in agent_can.
        $all = agent_capabilities_catalogue();
        $grant = function (array $modules) use ($all) {
            $p = [];
            foreach ($modules as $m) {
                if (!isset($all[$m])) { continue; }
                $p[$m] = [];
                foreach ($all[$m] as $a) { $p[$m][$a] = ''; }
            }
            return $p;
        };
        return [
            // Owner is implicit (is_owner => unrestricted); this role exists so an
            // owner can grant a "Manager" that is close to full but not role/staff.
            ['name' => 'Manager',       'permissions' => $grant(['bookings','clients','trips','wallet','reports','branding','api'])],
            ['name' => 'Booking Agent', 'permissions' => $grant(['bookings','clients','trips'])],
            ['name' => 'Accountant',    'permissions' => $grant(['wallet','reports'])],
        ];
    }
}

if (!function_exists('agent_seed_default_roles')) {
    /** Seed the default roles for an owner once (idempotent per owner). */
    function agent_seed_default_roles($db, string $owner): void
    {
        if ($owner === '') { return; }
        try {
            $existing = (int) $db->count('agent_roles', ['owner_user_id' => $owner, 'is_system' => 1]);
            if ($existing > 0) { return; }
            foreach (agent_default_roles() as $r) {
                $db->insert('agent_roles', [
                    'owner_user_id' => $owner,
                    'name'          => $r['name'],
                    'permissions'   => json_encode($r['permissions']),
                    'is_system'     => 1,
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) { error_log('agent_seed_default_roles: ' . $e->getMessage()); }
    }
}

// ----------------------------------------------------------------------------
// CONTEXT RESOLUTION — mirrors supplier_acting_context / supplier_staff_context.
// ----------------------------------------------------------------------------
if (!function_exists('agent_is_admin')) {
    function agent_is_admin(): bool
    {
        return isset($_SESSION['user_id']) && (($_SESSION['user_role'] ?? '') === 'admin');
    }
}

if (!function_exists('agent_acting_context')) {
    /**
     * Resolve the logged-in user to an agency context:
     *   OWNER  → ['owner'=>uid,'actor'=>uid,'is_owner'=>true,'role_id'=>null,
     *             'is_admin'=>false,'owner_active'=>bool]
     *   STAFF  → ['owner'=>parent,'actor'=>uid,'is_owner'=>false,'role_id'=>?int,'is_admin'=>false]
     *   ADMIN  → ['owner'=>'','actor'=>uid,'is_owner'=>false,'role_id'=>null,'is_admin'=>true]
     *   else   → null
     */
    function agent_acting_context($db): ?array
    {
        if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] === '') {
            return null;
        }
        $actor = (string) $_SESSION['user_id'];
        if (agent_is_admin()) {
            return ['owner' => '', 'actor' => $actor, 'is_owner' => false, 'role_id' => null, 'is_admin' => true];
        }
        if (($_SESSION['user_role'] ?? '') === 'agent') {
            $ownerActive = false;
            try {
                $row = $db->get('users', ['status'], ['user_id' => $actor]);
                $ownerActive = $row && ($row['status'] ?? '') === 'active';
            } catch (\Throwable $e) { $ownerActive = false; }
            return ['owner' => $actor, 'actor' => $actor, 'is_owner' => true, 'role_id' => null,
                    'is_admin' => false, 'owner_active' => $ownerActive];
        }
        // Staff resolution (a customer-role user linked via agent_staff).
        $staffCtx = agent_staff_context($db, $actor);
        if (is_array($staffCtx)) {
            return $staffCtx + ['is_admin' => false];
        }
        return null;
    }
}

if (!function_exists('agent_staff_context')) {
    /**
     * Resolve a logged-in user who is active STAFF of an agency. Fail-closed:
     *   - an ACTIVE agent_staff row links staff_user_id → owner;
     *   - the parent owner is a real user role='agent' status='active';
     *   - the attached role_id (if any) belongs to that SAME owner.
     */
    function agent_staff_context($db, string $actor): ?array
    {
        if ($actor === '') { return null; }
        try {
            $staff = $db->get('agent_staff', ['owner_user_id', 'role_id', 'status'],
                ['staff_user_id' => $actor, 'status' => 'active']);
        } catch (\Throwable $e) { return null; }
        if (!$staff) { return null; }
        $owner = (string) ($staff['owner_user_id'] ?? '');
        if ($owner === '') { return null; }
        try {
            $ownerRow = $db->get('users', ['role', 'status'], ['user_id' => $owner]);
        } catch (\Throwable $e) { return null; }
        if (!$ownerRow || ($ownerRow['role'] ?? '') !== 'agent' || ($ownerRow['status'] ?? '') !== 'active') {
            return null;
        }
        $roleId = isset($staff['role_id']) ? (int) $staff['role_id'] : 0;
        if ($roleId > 0) {
            try {
                $role = $db->get('agent_roles', ['owner_user_id'], ['id' => $roleId]);
            } catch (\Throwable $e) { $role = null; }
            if (!$role || (string) ($role['owner_user_id'] ?? '') !== $owner) {
                $roleId = 0; // foreign/missing role → no permissions (fail-closed)
            }
        }
        return ['owner' => $owner, 'actor' => $actor, 'is_owner' => false, 'role_id' => $roleId ?: null];
    }
}

if (!function_exists('agent_role_allows')) {
    /** Does the acting context grant $action on $module? Owner = unrestricted. */
    function agent_role_allows($db, array $ctx, string $module, string $action): bool
    {
        if (!empty($ctx['is_owner'])) { return true; }
        $roleId = isset($ctx['role_id']) ? (int) $ctx['role_id'] : 0;
        if ($roleId <= 0) { return false; }
        try {
            $role = $db->get('agent_roles', ['owner_user_id', 'permissions'], ['id' => $roleId]);
        } catch (\Throwable $e) { return false; }
        if (!$role) { return false; }
        if ((string) ($role['owner_user_id'] ?? '') !== (string) ($ctx['owner'] ?? "\0")) { return false; }
        $perms = [];
        if (!empty($role['permissions'])) {
            $decoded = json_decode((string) $role['permissions'], true);
            if (is_array($decoded)) { $perms = $decoded; }
        }
        // '*' wildcard (reserved; owner already returned true above).
        if (isset($perms['*'])) { return true; }
        if (!isset($perms[$module]) || !is_array($perms[$module]) || !array_key_exists($action, $perms[$module])) {
            return false;
        }
        return true;
    }
}

if (!function_exists('agent_can')) {
    /**
     * THE gate. True iff the current session may perform $action on $module in the
     * agent portal. Admin → true. Owner → true (but write-actions require an
     * approved/active account). Staff → per role matrix. Fail-closed.
     */
    function agent_can($db, string $module, string $action): bool
    {
        $ctx = agent_acting_context($db);
        if (!$ctx) { return false; }
        if (!empty($ctx['is_admin'])) { return true; }
        // A pending (not-yet-approved) owner may only 'view' — no consequential
        // writes until an admin approves (mirrors the supplier pending guard).
        if (!empty($ctx['is_owner']) && empty($ctx['owner_active']) && $action !== 'view') {
            return false;
        }
        return agent_role_allows($db, $ctx, $module, $action);
    }
}

if (!function_exists('AGENT_OR_STAFF_AUTH')) {
    /** Route guard: allow an agency owner or active staff (or admin); else login. */
    function AGENT_OR_STAFF_AUTH($db = null): void
    {
        if (!isset($_SESSION['user_id'])) {
            header('Location: ' . root . 'login');
            exit;
        }
        if ($db === null) { $db = $GLOBALS['db'] ?? null; }
        $ctx = $db !== null ? agent_acting_context($db) : null;
        if ($ctx === null) {
            header('Location: ' . root . 'login');
            exit;
        }
    }
}

if (!function_exists('agent_audit_log')) {
    /** Append a row to agent_audit (best-effort; never throws into the request). */
    function agent_audit_log($db, string $owner, string $actor, string $action, string $detail = ''): void
    {
        try {
            $db->insert('agent_audit', [
                'owner_user_id' => $owner,
                'actor_user_id' => $actor,
                'action'        => $action,
                'detail'        => $detail,
                'ip'            => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) { error_log('agent_audit_log: ' . $e->getMessage()); }
    }
}

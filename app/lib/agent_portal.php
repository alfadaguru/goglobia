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

            // --- agent_api_access: the PAID API-access lifecycle, one row per
            // agency. Stage machine (admin advances each step):
            //   none → requested → sandbox → certifying → live
            //   (+ rejected / suspended from any point).
            // The agent REQUESTS access (not instant); we reach out; admin grants
            // SANDBOX; agent builds + self-certifies ready; admin certifies and
            // grants LIVE. agent_api_keys.env gates which keys they can mint.
            $db->query("CREATE TABLE IF NOT EXISTS `agent_api_access` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `owner_user_id` varchar(255) NOT NULL,
                `status` enum('none','requested','sandbox','certifying','live','rejected','suspended') NOT NULL DEFAULT 'none',
                `company_name` varchar(255) DEFAULT NULL,
                `contact_email` varchar(255) DEFAULT NULL,
                `contact_phone` varchar(40) DEFAULT NULL,
                `use_case` text DEFAULT NULL,
                `expected_volume` varchar(120) DEFAULT NULL,
                `admin_note` text DEFAULT NULL,
                `requested_at` datetime DEFAULT NULL,
                `sandbox_granted_at` datetime DEFAULT NULL,
                `certify_requested_at` datetime DEFAULT NULL,
                `live_granted_at` datetime DEFAULT NULL,
                `reviewed_by` varchar(255) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_owner` (`owner_user_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            // Tag each API key sandbox|live so the two environments are distinct.
            // Existing keys default to 'sandbox' (safe — they can't hit live
            // unless the live flow granted them). Idempotent add.
            try {
                if (!$db->query("SHOW COLUMNS FROM `agent_api_keys` LIKE 'env'")->fetch()) {
                    $db->query("ALTER TABLE `agent_api_keys` ADD COLUMN `env` enum('sandbox','live') NOT NULL DEFAULT 'sandbox'");
                }
            } catch (\Throwable $e) { error_log('ensureAgentPortalSchema agent_api_keys.env: ' . $e->getMessage()); }

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

// ----------------------------------------------------------------------------
// BRANDING OVERRIDE — the agent-side parallel of wl_email_brand() (stays).
// Given a booking, if it belongs to an agency (bookings.agency_id), return that
// agency's brand identity so GENERATE_BOOKING_PDF() + emails render the agency's
// logo / name / contact / colours instead of the platform's. Returns null when
// the booking is not an agency booking (so platform branding is kept). Works for
// ALL services — the caller swaps $settings + logo the same way for every module.
// ----------------------------------------------------------------------------
if (!function_exists('agent_booking_brand')) {
    function agent_booking_brand($db, array $booking): ?array
    {
        $ownerId = (string) ($booking['agency_id'] ?? '');
        // Fallback: a booking made by an agent before agency_id existed — if the
        // owning user is an agent, treat it as an agency booking.
        if ($ownerId === '') {
            $uid = (string) ($booking['user_id'] ?? '');
            if ($uid === '') { return null; }
            try {
                $u = $db->get('users', ['role'], ['user_id' => $uid]);
                if (!$u || strtolower((string)($u['role'] ?? '')) !== 'agent') { return null; }
                $ownerId = $uid;
            } catch (\Throwable $e) { return null; }
        }
        try {
            $ag = $db->get('agencies',
                ['agency_name','email','phone','logo','brand_primary','brand_accent','address','city','country'],
                ['user_id' => $ownerId]);
        } catch (\Throwable $e) { return null; }
        if (!$ag || empty($ag['agency_name'])) { return null; }

        // Resolve a usable absolute logo URL (agencies.logo stores a relative path).
        $logoUrl = '';
        $logo = (string) ($ag['logo'] ?? '');
        if ($logo !== '') {
            $logoUrl = (stripos($logo, 'http') === 0) ? $logo : (rtrim(root, '/') . '/' . ltrim($logo, '/'));
        }
        $addressParts = array_filter([
            trim((string)($ag['address'] ?? '')),
            trim((string)($ag['city'] ?? '')),
            trim((string)($ag['country'] ?? '')),
        ]);
        return [
            'companyName'  => (string) $ag['agency_name'],
            'supportEmail' => (string) ($ag['email'] ?? ''),
            'supportPhone' => (string) ($ag['phone'] ?? ''),
            'logoUrl'      => $logoUrl,
            'colorPrimary' => (string) ($ag['brand_primary'] ?? ''),
            'colorAccent'  => (string) ($ag['brand_accent'] ?? ''),
            'address'      => implode(', ', $addressParts),
        ];
    }
}

// ----------------------------------------------------------------------------
// SELF-MARKUP (P5/M10) — agency client price over the B2B cost the agent pays.
// Stamps bookings.agent_client_price + agent_client_margin from the agency's
// configured markup (users.agent_customer_markup[_type]). The agent still PAYS
// $agentCost to us; the client price is what their BRANDED invoice shows. No-op
// if self-markup is disabled (settings) or the agency set no markup.
// ----------------------------------------------------------------------------
if (!function_exists('agent_self_markup_value')) {
    function agent_self_markup_value($db, string $ownerId): array
    {
        // [value, type] — type 'percentage'|'fixed'. Zero value => none.
        try {
            $u = $db->get('users', ['agent_customer_markup','agent_customer_markup_type'], ['user_id' => $ownerId]);
        } catch (\Throwable $e) { return [0.0, 'percentage']; }
        $v = (float) ($u['agent_customer_markup'] ?? 0);
        $t = (string) ($u['agent_customer_markup_type'] ?? 'percentage');
        return [$v, $t === 'fixed' ? 'fixed' : 'percentage'];
    }
}
if (!function_exists('agent_apply_self_markup')) {
    function agent_apply_self_markup($db, string $ownerId, string $invoiceId, float $agentCost, string $currency): void
    {
        if ((string) ($GLOBALS['app']['agent_self_markup_enabled'] ?? '1') !== '1') { return; }
        [$val, $type] = agent_self_markup_value($db, $ownerId);
        if ($val <= 0) { return; }
        $margin = $type === 'fixed' ? $val : round($agentCost * $val / 100, 2);
        if ($margin <= 0) { return; }
        $clientPrice = round($agentCost + $margin, 2);
        try {
            $db->update('bookings',
                ['agent_client_price' => $clientPrice, 'agent_client_margin' => $margin],
                ['invoice_id' => $invoiceId]);
        } catch (\Throwable $e) { error_log('agent_apply_self_markup: ' . $e->getMessage()); }
    }
}

// ----------------------------------------------------------------------------
// API-ACCESS PAID-SERVICE STAGE MACHINE (docs/AGENT-API.md).
// Lifecycle: none → requested → sandbox → certifying → live (+ rejected/suspended).
// The agent requests; admin advances each stage; agent_api_keys.env (sandbox|live)
// gates which keys the agent may mint. Helpers below read/advance the single
// agent_api_access row per agency owner.
// ----------------------------------------------------------------------------
if (!function_exists('agent_api_access_get')) {
    /** The agency's access row (creates a 'none' row on first read). */
    function agent_api_access_get($db, string $ownerId): array
    {
        $row = null;
        try { $row = $db->get('agent_api_access', '*', ['owner_user_id' => $ownerId]); } catch (\Throwable $e) {}
        if (!$row) {
            try {
                $db->insert('agent_api_access', [
                    'owner_user_id' => $ownerId, 'status' => 'none', 'created_at' => date('Y-m-d H:i:s'),
                ]);
                $row = $db->get('agent_api_access', '*', ['owner_user_id' => $ownerId]);
            } catch (\Throwable $e) { /* table may lag */ }
        }
        return is_array($row) ? $row : ['owner_user_id' => $ownerId, 'status' => 'none'];
    }
}
if (!function_exists('agent_api_access_status')) {
    function agent_api_access_status($db, string $ownerId): string
    {
        return (string) (agent_api_access_get($db, $ownerId)['status'] ?? 'none');
    }
}
if (!function_exists('agent_api_can_mint')) {
    /** Which key env (if any) the agency may currently generate: 'sandbox'|'live'|''. */
    function agent_api_can_mint($db, string $ownerId): string
    {
        $s = agent_api_access_status($db, $ownerId);
        if ($s === 'live') { return 'live'; }                 // live-approved: can mint live (and sandbox)
        if ($s === 'sandbox' || $s === 'certifying') { return 'sandbox'; }
        return ''; // none/requested/rejected/suspended → cannot mint
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

// ============================================================================
// API CONSOLE — HYBRID PER-SERVICE CERTIFICATION (owner decision 2026-10-25).
// The agency gets IN once via agent_api_access (the gate). Then EACH service
// runs its own lifecycle on agent_api_services.lifecycle before it can be
// called live. So a key may exist, but a call to /flights only works live once
// FLIGHTS itself is live-certified. Agent requests per service; admin certifies.
// ============================================================================

if (!function_exists('agent_api_console_services')) {
    /** The services the API exposes + display labels (the 10 bookable w/ API eps). */
    function agent_api_console_services(): array
    {
        return [
            'flights' => 'Flights', 'stays' => 'Hotels / Stays', 'cars' => 'Cars',
            'tours' => 'Tours', 'visa' => 'Visa', 'umrah' => 'Umrah', 'esim' => 'eSIM',
            'bus' => 'Bus', 'ferries' => 'Ferries', 'rail' => 'Rail',
        ];
    }
}

if (!function_exists('agent_api_service_row')) {
    /** The agent_api_services row for (owner,service), or a synthetic 'none' default. */
    function agent_api_service_row($db, string $owner, string $service): array
    {
        try {
            $r = $db->get('agent_api_services',
                ['id','service','enabled','fee_type','fee_value','lifecycle','requested_at',
                 'sandbox_granted_at','certify_requested_at','live_granted_at','use_case','admin_note'],
                ['user_id' => $owner, 'service' => $service]);
            if ($r) { return $r; }
        } catch (\Throwable $e) { error_log('agent_api_service_row: ' . $e->getMessage()); }
        return ['service' => $service, 'enabled' => 0, 'fee_type' => 'percentage',
                'fee_value' => 0, 'lifecycle' => 'none'];
    }
}

if (!function_exists('agent_api_all_service_rows')) {
    /** All configured service rows for an owner, keyed by service. */
    function agent_api_all_service_rows($db, string $owner): array
    {
        $out = [];
        try {
            $rows = $db->select('agent_api_services',
                ['service','enabled','fee_type','fee_value','lifecycle','requested_at',
                 'sandbox_granted_at','certify_requested_at','live_granted_at','use_case','admin_note'],
                ['user_id' => $owner]) ?: [];
            foreach ($rows as $r) { $out[(string)$r['service']] = $r; }
        } catch (\Throwable $e) { error_log('agent_api_all_service_rows: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('agent_api_service_can_call')) {
    /** Which env a given service may be CALLED in: 'live'|'sandbox'|'' (not yet).
     *  Requires BOTH the agency gate (agent_api_can_mint) AND the per-service
     *  lifecycle — the hybrid model: in once, certified per service. */
    function agent_api_service_can_call($db, string $owner, string $service): string
    {
        $gate = agent_api_can_mint($db, $owner);      // '' | 'sandbox' | 'live'
        if ($gate === '') { return ''; }
        $life = (string) (agent_api_service_row($db, $owner, $service)['lifecycle'] ?? 'none');
        if ($life === 'live' && $gate === 'live') { return 'live'; }
        if (in_array($life, ['sandbox','certifying','live'], true)) { return 'sandbox'; }
        return '';
    }
}

if (!function_exists('agent_api_service_set_lifecycle')) {
    /** Transition a service's lifecycle (upserts the row). Validates the move.
     *  Returns ['ok'=>bool,'message'=>string]. Admin and agent both route here. */
    function agent_api_service_set_lifecycle($db, string $owner, string $service, string $to, array $extra = []): array
    {
        $services = agent_api_console_services();
        if (!isset($services[$service])) { return ['ok' => false, 'message' => 'Unknown service.']; }
        $valid = ['none','requested','sandbox','certifying','live','rejected','suspended'];
        if (!in_array($to, $valid, true)) { return ['ok' => false, 'message' => 'Invalid state.']; }

        $row = agent_api_service_row($db, $owner, $service);
        $from = (string) ($row['lifecycle'] ?? 'none');
        // Allowed transitions (agent-driven requests + admin-driven grants).
        $allowed = [
            'requested'  => ['none','rejected','suspended'],              // agent requests
            'certifying' => ['sandbox'],                                 // agent asks for live
            'sandbox'    => ['requested','certifying','suspended'],       // admin grants sandbox
            'live'       => ['certifying','sandbox','suspended'],         // admin certifies live
            'rejected'   => ['requested','certifying'],                   // admin rejects
            'suspended'  => ['sandbox','certifying','live'],              // admin suspends
            'none'       => $valid,                                       // reset
        ];
        if (!in_array($from, $allowed[$to] ?? [], true) && $from !== $to) {
            return ['ok' => false, 'message' => "Cannot move $service from '$from' to '$to'."];
        }

        $stampMap = ['requested'=>'requested_at','sandbox'=>'sandbox_granted_at',
                     'certifying'=>'certify_requested_at','live'=>'live_granted_at'];
        $data = ['lifecycle' => $to, 'updated_at' => date('Y-m-d H:i:s')];
        if (isset($stampMap[$to])) { $data[$stampMap[$to]] = date('Y-m-d H:i:s'); }
        // When live is granted, also mark the service enabled so keys may call it.
        if ($to === 'live' || $to === 'sandbox') { $data['enabled'] = 1; }
        if ($to === 'suspended' || $to === 'rejected') { $data['enabled'] = 0; }
        foreach (['use_case','admin_note','reviewed_by','fee_type','fee_value'] as $k) {
            if (array_key_exists($k, $extra)) { $data[$k] = $extra[$k]; }
        }
        try {
            if (!empty($row['id'])) {
                $db->update('agent_api_services', $data, ['user_id' => $owner, 'service' => $service]);
            } else {
                $db->insert('agent_api_services', array_merge(
                    ['user_id' => $owner, 'service' => $service, 'created_at' => date('Y-m-d H:i:s')], $data));
            }
            return ['ok' => true, 'message' => ucfirst($service) . ' → ' . $to];
        } catch (\Throwable $e) {
            error_log('agent_api_service_set_lifecycle: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not update the service.'];
        }
    }
}

// ============================================================================
// API CONSOLE — WEBHOOKS. Agencies register HTTPS endpoints + events; we sign
// each delivery with HMAC-SHA256 over the raw body (X-Goglobia-Signature) and
// log every attempt. Fire via agent_api_webhook_fire() from booking events.
// ============================================================================

if (!function_exists('agent_api_webhook_events')) {
    /** The event names an agency can subscribe a webhook to. */
    function agent_api_webhook_events(): array
    {
        return ['booking.created','booking.confirmed','booking.cancelled',
                'booking.failed','payment.succeeded','payment.refunded','key.revoked'];
    }
}

if (!function_exists('agent_api_webhook_new_secret')) {
    /** A fresh webhook signing secret (shown to the agent, stored as-is to sign). */
    function agent_api_webhook_new_secret(): string
    {
        try { return 'whsec_' . bin2hex(random_bytes(24)); }
        catch (\Throwable $e) { return 'whsec_' . bin2hex(md5((string)mt_rand(), true)); }
    }
}

if (!function_exists('agent_api_webhook_fire')) {
    /** Deliver $event+$payload to every active webhook of $owner subscribed to it.
     *  Best-effort, short timeout; logs each attempt. Called from booking flows. */
    function agent_api_webhook_fire($db, string $owner, string $event, array $payload, string $env = 'live'): void
    {
        $hooks = [];
        try {
            $hooks = $db->select('agent_api_webhooks', ['id','url','events','secret','env'],
                ['user_id' => $owner, 'status' => 'active']) ?: [];
        } catch (\Throwable $e) { error_log('agent_api_webhook_fire select: ' . $e->getMessage()); return; }
        if (!$hooks) { return; }

        $body = json_encode(['event' => $event, 'data' => $payload, 'ts' => time()], JSON_UNESCAPED_SLASHES);
        foreach ($hooks as $h) {
            if ((string)($h['env'] ?? 'sandbox') !== $env) { continue; }
            $subs = array_filter(array_map('trim', explode(',', (string)($h['events'] ?? ''))));
            if ($subs && !in_array($event, $subs, true)) { continue; } // empty = all events
            $sig = hash_hmac('sha256', $body, (string)$h['secret']);
            $code = 0; $respBody = '';
            try {
                $ch = curl_init((string)$h['url']);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'X-Goglobia-Event: ' . $event,
                        'X-Goglobia-Signature: sha256=' . $sig,
                    ],
                ]);
                $respBody = (string) curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
            } catch (\Throwable $e) { error_log('agent_api_webhook_fire send: ' . $e->getMessage()); }

            $ok = ($code >= 200 && $code < 300);
            try {
                $db->insert('agent_api_webhook_deliveries', [
                    'webhook_id' => (int)$h['id'], 'user_id' => $owner, 'event' => $event,
                    'payload' => substr($body, 0, 4000), 'response_code' => $code,
                    'response_body' => substr($respBody, 0, 2000), 'attempt' => 1,
                    'delivered' => $ok ? 1 : 0,
                    'next_retry_at' => $ok ? null : date('Y-m-d H:i:s', time() + 300),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $db->update('agent_api_webhooks', [
                    'last_delivery_at' => date('Y-m-d H:i:s'), 'last_status_code' => $code,
                    'fail_count' => $ok ? 0 : (int)($db->get('agent_api_webhooks','fail_count',['id'=>(int)$h['id']]) ?? 0) + 1,
                ], ['id' => (int)$h['id']]);
            } catch (\Throwable $e) { error_log('agent_api_webhook_fire log: ' . $e->getMessage()); }
        }
    }
}

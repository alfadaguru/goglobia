<?php
// app/lib/support/rbac.php
// Customer Support module — Phase 1: RBAC + employee foundation.
// Design-of-record: docs/support/CUSTOMER_SUPPORT_MODULE_SPEC.md (§2 data model,
// §7 LOCKED owner decisions). This file is the SINGLE gate for support staff
// identity + capabilities; every support route pairs ADMIN_AUTH() with
// support_can($db, '<cap>').
//
// A "support staff" IS a users row with an elevated capability — support_staff
// adds the support-specific fields (role, display name, nudge phone, shift group)
// on top of it. A users.role==='admin' is treated as an implicit full-admin even
// with NO support_staff row, so the first admin can reach the module before any
// staff is provisioned.
//
// All functions are global (namespace-free) + function_exists-guarded so this
// file is safe to require more than once (config.php + route files).
// NOTE: this is a LIBRARY (loaded from config.php during bootstrap, BEFORE
// $SECURE is set), so it must NOT carry the `@$SECURE or die` route/view guard —
// doing so kills the whole app at boot. No other app/lib file carries it.

if (!function_exists('ensureSupportRbacSchema')) {
    /**
     * Idempotent self-healing schema for the Customer Support RBAC foundation.
     * Mirrors ensureSupplierStaysSchema (functions.php): CREATE TABLE IF NOT
     * EXISTS wrapped in try/catch with error_log on failure, guarded by a static
     * so the SHOW/CREATE runs at most once per request. Called from the top of
     * the other helpers so they self-heal even if config.php has not primed it.
     */
    function ensureSupportRbacSchema($db): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            // --- support_roles: named capability bundles (JSON array of cap slugs) ----
            // is_system=1 rows are the 4 LOCKED defaults (admin/support_lead/
            // support_agent/marketing); admins may add custom (is_system=0) roles.
            $db->query("CREATE TABLE IF NOT EXISTS `support_roles` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(80) NOT NULL,
                `slug` VARCHAR(80) NOT NULL,
                `is_system` TINYINT(1) NOT NULL DEFAULT 0,
                `permissions` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_slug` (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // --- support_staff: a users row elevated into a support employee ----------
            // user_id FKs users.user_id (VARCHAR); role_id FKs support_roles.id.
            $db->query("CREATE TABLE IF NOT EXISTS `support_staff` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `user_id` VARCHAR(255) NOT NULL,
                `role_id` INT(11) DEFAULT NULL,
                `display_name` VARCHAR(120) DEFAULT NULL,
                `phone` VARCHAR(32) DEFAULT NULL,
                `shift_group` VARCHAR(60) DEFAULT NULL,
                `status` ENUM('active','suspended') NOT NULL DEFAULT 'active',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_user` (`user_id`),
                KEY `idx_role` (`role_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            error_log('ensureSupportRbacSchema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('support_capability_catalogue')) {
    /**
     * The full catalogue of support capability slugs (§2 / §7 of the spec). The
     * single source of truth for what caps exist; the roles UI lists these and
     * the default-role seed draws from them. '*' is the wildcard (all caps) and
     * is intentionally NOT in this list.
     */
    function support_capability_catalogue(): array
    {
        return [
            'chat.view',
            'chat.takeover',
            'chat.assign',
            'tickets.view',
            'tickets.manage',
            'bookings.view',
            'bookings.notes',
            'bookings.resend',
            'bookings.money',       // refund / cancel / void — Support Lead+ only
            'users.view',
            'attendance.view_self',
            'attendance.view_all',
            'shifts.manage',
            'campaigns.manage',
            'campaigns.analytics',
            'targets.view',
            'targets.set',
            'roles.manage',
            'staff.manage',
            'dashboard.view',
        ];
    }
}

if (!function_exists('support_seed_default_roles')) {
    /**
     * Insert the 4 LOCKED default roles (§7.1) IF ABSENT. Idempotent: existence is
     * checked by slug so a role is never duplicated, and the .* shorthands below
     * are expanded to the explicit cap list at seed time (no wildcards persisted
     * except the admin '*').
     */
    function support_seed_default_roles($db): void
    {
        ensureSupportRbacSchema($db);

        // Capability groups used to expand the "chat.* / tickets.*" shorthands.
        $chatAll    = ['chat.view', 'chat.takeover', 'chat.assign'];
        $ticketsAll = ['tickets.view', 'tickets.manage'];

        // Support Lead: all chat + all tickets + bookings money actions + team
        // attendance/targets + reassign. NO roles.manage / staff.manage (admin only).
        $lead = array_merge($chatAll, $ticketsAll, [
            'bookings.view', 'bookings.notes', 'bookings.resend', 'bookings.money',
            'users.view',
            'attendance.view_self', 'attendance.view_all',
            'shifts.manage',
            'targets.view',
            'dashboard.view',
        ]);

        // Support Agent: chats (view+takeover) + tickets + bookings view/notes/
        // resend. NO bookings.money (money actions are Lead+ only, §7.2).
        $agent = [
            'chat.view', 'chat.takeover',
            'tickets.view', 'tickets.manage',
            'bookings.view', 'bookings.notes', 'bookings.resend',
            'users.view',
            'attendance.view_self',
            'targets.view',
            'dashboard.view',
        ];

        // Marketing: campaigns CRUD + conversion/analytics + see targets + dashboard.
        $marketing = [
            'campaigns.manage', 'campaigns.analytics',
            'targets.view',
            'dashboard.view',
        ];

        $defaults = [
            ['name' => 'Admin',         'slug' => 'admin',         'permissions' => ['*']],
            ['name' => 'Support Lead',  'slug' => 'support_lead',  'permissions' => $lead],
            ['name' => 'Support Agent', 'slug' => 'support_agent', 'permissions' => $agent],
            ['name' => 'Marketing',     'slug' => 'marketing',     'permissions' => $marketing],
        ];

        try {
            foreach ($defaults as $role) {
                // Check-by-slug: only insert when absent, never update/duplicate.
                $exists = (int) $db->count('support_roles', ['slug' => $role['slug']]);
                if ($exists > 0) {
                    continue;
                }
                $db->insert('support_roles', [
                    'name'        => $role['name'],
                    'slug'        => $role['slug'],
                    'is_system'   => 1,
                    'permissions' => json_encode(array_values($role['permissions'])),
                ]);
            }
        } catch (\Throwable $e) {
            error_log('support_seed_default_roles: ' . $e->getMessage());
        }
    }
}

if (!function_exists('support_current_staff')) {
    /**
     * Return the support_staff row for $_SESSION['user_id'] enriched with the
     * joined role's decoded permissions array (key 'permissions'), or null.
     *
     * Special case: a users.role==='admin' with NO support_staff row is an
     * implicit full-admin — we return a synthetic row with permissions ['*'] so
     * the owner/admin can reach the module before any staff is provisioned.
     *
     * Suspended staff (status!=='active') resolve to null (treated as no access),
     * UNLESS the user is an admin (admins keep implicit full access).
     *
     * Cached in a request-static keyed by user_id.
     */
    function support_current_staff($db)
    {
        static $cache = [];

        if (empty($_SESSION['user_id'])) {
            return null;
        }
        $uid = (string) $_SESSION['user_id'];
        if (array_key_exists($uid, $cache)) {
            return $cache[$uid];
        }

        ensureSupportRbacSchema($db);

        $isAdmin = false;
        try {
            $u = $db->get('users', ['role'], ['user_id' => $uid]);
            $isAdmin = (($u['role'] ?? '') === 'admin');
        } catch (\Throwable $e) {
            error_log('support_current_staff(users): ' . $e->getMessage());
        }

        $staff = null;
        try {
            $staff = $db->get('support_staff', '*', ['user_id' => $uid]);
        } catch (\Throwable $e) {
            error_log('support_current_staff(staff): ' . $e->getMessage());
            $staff = null;
        }

        if ($staff) {
            // Suspended non-admins have no access.
            if (($staff['status'] ?? 'active') !== 'active' && !$isAdmin) {
                return $cache[$uid] = null;
            }
            $perms = [];
            if (!empty($staff['role_id'])) {
                try {
                    $role = $db->get('support_roles', ['permissions'], ['id' => (int) $staff['role_id']]);
                    if ($role && !empty($role['permissions'])) {
                        $decoded = json_decode((string) $role['permissions'], true);
                        if (is_array($decoded)) {
                            $perms = $decoded;
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('support_current_staff(role): ' . $e->getMessage());
                }
            }
            // An admin always carries full caps regardless of their role row.
            if ($isAdmin && !in_array('*', $perms, true)) {
                $perms[] = '*';
            }
            $staff['permissions'] = array_values($perms);
            return $cache[$uid] = $staff;
        }

        // No staff row but an admin → synthetic implicit full-admin.
        if ($isAdmin) {
            return $cache[$uid] = [
                'id'           => 0,
                'user_id'      => $uid,
                'role_id'      => null,
                'display_name' => null,
                'phone'        => null,
                'shift_group'  => null,
                'status'       => 'active',
                'permissions'  => ['*'],
            ];
        }

        return $cache[$uid] = null;
    }
}

if (!function_exists('support_can')) {
    /**
     * Capability gate. True when the current staff's permissions contain the
     * wildcard '*' (admins) or the exact $cap. No session / no staff → false.
     */
    function support_can($db, string $cap): bool
    {
        $staff = support_current_staff($db);
        if (!$staff) {
            return false;
        }
        $perms = $staff['permissions'] ?? [];
        if (!is_array($perms)) {
            return false;
        }
        return in_array('*', $perms, true) || in_array($cap, $perms, true);
    }
}

if (!function_exists('support_is_staff')) {
    /**
     * True when the current user may reach the support module at all: an admin
     * (implicit) OR an active support_staff row. support_current_staff() already
     * resolves both (and excludes suspended non-admins), so this is a thin wrap.
     */
    function support_is_staff($db): bool
    {
        return support_current_staff($db) !== null;
    }
}

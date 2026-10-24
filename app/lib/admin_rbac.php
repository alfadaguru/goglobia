<?php
// app/lib/admin_rbac.php
// Employee / admin RBAC enforcement core (Employee RBAC Consolidation, S1).
// Design-of-record: docs/support/EMPLOYEE_RBAC_CONSOLIDATION.md.
//
// This is the ONE RBAC layer. It enforces the EXISTING `users_roles` table (the
// legacy per-area × per-action permission grid) that until now was never
// actually checked. An employee is a `users` row with role='employee' (or
// 'admin') plus a `role_id` FK into `users_roles`.
//
// Permission convention (verified against app/views/admin/users/users-roles-manage.php:59-63
// and the install/db.sql seed rows): a grant is recorded by the PRESENCE of the
// action key inside the area object, with an empty-string value, e.g.
//     {"bookings":{"page_access":"","view":"","edit":""}}
// "granted" therefore means array_key_exists($action, $perms[$area]) — NOT a
// truthy value (the value is '' which is falsy). Absent key = denied.
//
// SAFETY INVARIANTS:
//  - The Admin role (type_name/base_type 'admin', or a '*' permission) is
//    ALL-ALLOW and is force-repaired to a complete grant on every boot, so a
//    real admin can never be locked out by a malformed permissions blob.
//  - All functions are global + function_exists-guarded (safe to require twice).
//
// NOTE: this is a LIBRARY (required from config.php during bootstrap, BEFORE
// $SECURE is defined). It MUST NOT carry the `@$SECURE or die` route/view guard
// — doing so kills the whole app at boot (see the P1 hotfix 9b602b1). No other
// app/lib file carries it; neither does this one.

if (!function_exists('admin_area_catalogue')) {
    /**
     * The granular catalogue of admin areas and the actions each supports.
     * Superset of the legacy grid in users-roles-manage.php plus every real
     * admin sub-area and every former support capability, re-expressed as
     * area × action. The roles UI lists these; the default-role seed draws from
     * them. Keys are area slugs; values are the ordered list of valid actions.
     *
     * 'page_access' is the "can open this screen at all" toggle; the CRUD verbs
     * (add/edit/view/delete) gate the operations within it. Support areas that
     * are inherently binary (e.g. chat takeover) still model it as page_access +
     * the verbs that make sense, so the single admin_can($area,$action) gate is
     * uniform everywhere.
     */
    function admin_area_catalogue(): array
    {
        return [
            // --- Core admin (mirrors the legacy users_roles grid) ---------------
            'dashboard'              => ['page_access', 'view'],
            'users'                  => ['page_access', 'add', 'edit', 'view', 'delete'],
            'users_roles'            => ['page_access', 'add', 'edit', 'view', 'delete'],
            'employees'              => ['page_access', 'add', 'edit', 'view', 'delete'],
            'bookings'               => ['page_access', 'add', 'edit', 'view', 'delete', 'refund', 'cancel', 'void'],
            'transactions'           => ['page_access', 'add', 'edit', 'view', 'delete'],
            'settings'               => ['page_access', 'edit', 'view'],
            'modules'                => ['page_access', 'add', 'edit', 'view', 'delete'],
            'cms'                    => ['page_access', 'add', 'edit', 'view', 'delete'],
            'logs'                   => ['page_access', 'view'],
            'languages'              => ['page_access', 'add', 'edit', 'view', 'delete'],
            'currencies'             => ['page_access', 'add', 'edit', 'view', 'delete'],
            'payment_gateways'       => ['page_access', 'edit', 'view'],
            'notification_templates' => ['page_access', 'add', 'edit', 'view', 'delete'],
            'reports'                => ['page_access', 'view'],
            'suppliers'              => ['page_access', 'add', 'edit', 'view', 'delete'],

            // --- Customer Support areas (replacing the support capability slugs) -
            // Migration map from the old support_can caps (see
            // docs/support/EMPLOYEE_RBAC_CONSOLIDATION.md S3):
            //   dashboard.view        -> support_dashboard.view
            //   chat.view/takeover    -> support_desk.view / .edit
            //   chat.assign           -> support_desk.edit
            //   tickets.view/manage   -> support_tickets.view / .edit
            //   bookings.view/notes   -> bookings.view (core area)
            //   bookings.resend       -> bookings.edit
            //   bookings.money        -> bookings.refund/.cancel/.void
            //   users.view            -> users.view (core area)
            //   attendance.view_self  -> support_attendance.view
            //   attendance.view_all   -> support_attendance.view_all
            //   shifts.manage         -> support_shifts.edit
            //   campaigns.manage      -> support_campaigns.edit
            //   campaigns.analytics   -> support_campaigns.view
            //   targets.view/set      -> support_targets.view / .edit
            //   roles.manage          -> users_roles.edit (core area)
            //   staff.manage          -> employees.edit (core area)
            'support_dashboard'      => ['page_access', 'view'],
            'support_desk'           => ['page_access', 'view', 'edit'],       // view=see chats, edit=reply/takeover/assign
            'support_tickets'        => ['page_access', 'view', 'edit'],
            'support_attendance'     => ['page_access', 'view', 'view_all', 'edit'],
            'support_shifts'         => ['page_access', 'view', 'edit'],
            'support_campaigns'      => ['page_access', 'view', 'edit'],
            'support_targets'        => ['page_access', 'view', 'edit'],
        ];
    }
}

if (!function_exists('admin_rbac_all_grant')) {
    /**
     * Build a complete-grant permissions array (every area, every action, all
     * granted with the legacy empty-string value). Used to repair the Admin role
     * and to express base_type='admin' roles. Mirrors the on-disk convention so
     * the existing users-roles-manage.php grid renders it correctly.
     */
    function admin_rbac_all_grant(): array
    {
        $out = [];
        foreach (admin_area_catalogue() as $area => $actions) {
            $out[$area] = [];
            foreach ($actions as $a) {
                $out[$area][$a] = '';
            }
        }
        return $out;
    }
}

if (!function_exists('ensureAdminRbacSchema')) {
    /**
     * Idempotent self-healing schema for the consolidated RBAC layer. Widens
     * `users_roles` with the governance columns (kind/slug/base_type/description/
     * status/timestamps) and `users` with (role_id/shift_group/nudge_phone), then
     * seeds/repairs the default roles. Follows the repo's ensure-* pattern
     * (functions.php:262 ensureCoreFixSchema): SHOW COLUMNS then ALTER, wrapped in
     * try/catch with error_log, guarded by a request-static so it runs once.
     *
     * Column definitions deliberately MATCH the columns already present in the
     * live dev DB (kind varchar(20) default 'custom', base_type varchar(20)
     * default 'employee', slug varchar(30), status tinyint(1) default 1) so a
     * fresh install and an already-migrated DB converge to the same shape.
     */
    function ensureAdminRbacSchema($db): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            // --- users_roles governance columns -------------------------------
            $roleCols = [];
            foreach ($db->query("SHOW COLUMNS FROM `users_roles`")->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                $roleCols[strtolower($c['Field'])] = true;
            }
            $roleAdds = [
                'kind'        => "ADD COLUMN `kind` VARCHAR(20) NOT NULL DEFAULT 'custom'",
                'slug'        => "ADD COLUMN `slug` VARCHAR(30) DEFAULT NULL",
                'base_type'   => "ADD COLUMN `base_type` VARCHAR(20) NOT NULL DEFAULT 'employee'",
                'description' => "ADD COLUMN `description` VARCHAR(255) DEFAULT NULL",
                'status'      => "ADD COLUMN `status` TINYINT(1) NOT NULL DEFAULT 1",
                'updated_at'  => "ADD COLUMN `updated_at` DATETIME DEFAULT NULL",
                'created_at'  => "ADD COLUMN `created_at` DATETIME DEFAULT NULL",
            ];
            foreach ($roleAdds as $col => $ddl) {
                if (!isset($roleCols[$col])) {
                    $db->query("ALTER TABLE `users_roles` $ddl");
                }
            }

            // --- users RBAC columns -------------------------------------------
            $userCols = [];
            foreach ($db->query("SHOW COLUMNS FROM `users`")->fetchAll(\PDO::FETCH_ASSOC) as $c) {
                $userCols[strtolower($c['Field'])] = true;
            }
            $userAdds = [
                'role_id'     => "ADD COLUMN `role_id` INT(11) DEFAULT NULL",
                'shift_group' => "ADD COLUMN `shift_group` VARCHAR(60) DEFAULT NULL",
                'nudge_phone' => "ADD COLUMN `nudge_phone` VARCHAR(32) DEFAULT NULL",
            ];
            foreach ($userAdds as $col => $ddl) {
                if (!isset($userCols[$col])) {
                    $db->query("ALTER TABLE `users` $ddl");
                }
            }
        } catch (\Throwable $e) {
            error_log('ensureAdminRbacSchema(alter): ' . $e->getMessage());
        }

        // Seed/repair the default roles (own try/catch inside).
        admin_rbac_seed_default_roles($db);
    }
}

if (!function_exists('admin_rbac_seed_default_roles')) {
    /**
     * Seed the default roles onto `users_roles` and ALWAYS repair the Admin row
     * to a complete grant (never-lock-out invariant). Existence is checked by
     * slug (preferred) or case-insensitive type_name so pre-existing seed rows
     * (Admin/Supplier/Employee/Customer/Agent — install/db.sql:31734) are reused,
     * not duplicated.
     *
     * Defaults map the former support roles onto users_roles with granular perms
     * (see admin_area_catalogue migration map). Admin = base_type 'admin' + full
     * grant; the three support roles = base_type 'employee'.
     */
    function admin_rbac_seed_default_roles($db): void
    {
        // Granular permission sets expressed as area => [granted actions].
        $grant = function (array $spec): array {
            $out = [];
            foreach ($spec as $area => $actions) {
                $out[$area] = [];
                foreach ($actions as $a) {
                    $out[$area][$a] = '';
                }
            }
            return $out;
        };

        $supportLead = $grant([
            'support_dashboard'  => ['page_access', 'view'],
            'support_desk'       => ['page_access', 'view', 'edit'],
            'support_tickets'    => ['page_access', 'view', 'edit'],
            'support_attendance' => ['page_access', 'view', 'view_all'],
            'support_shifts'     => ['page_access', 'view', 'edit'],
            'support_targets'    => ['page_access', 'view'],
            'bookings'           => ['page_access', 'view', 'edit', 'refund', 'cancel', 'void'],
            'users'              => ['page_access', 'view'],
            'reports'            => ['page_access', 'view'],
        ]);

        $supportAgent = $grant([
            'support_dashboard'  => ['page_access', 'view'],
            'support_desk'       => ['page_access', 'view', 'edit'],
            'support_tickets'    => ['page_access', 'view', 'edit'],
            'support_attendance' => ['page_access', 'view'],
            'support_targets'    => ['page_access', 'view'],
            'bookings'           => ['page_access', 'view', 'edit'], // view/notes/resend; NO money (refund/cancel/void)
            'users'              => ['page_access', 'view'],
        ]);

        $marketing = $grant([
            'support_dashboard' => ['page_access', 'view'],
            'support_campaigns' => ['page_access', 'view', 'edit'],
            'support_targets'   => ['page_access', 'view'],
            'reports'           => ['page_access', 'view'],
        ]);

        $defaults = [
            ['type_name' => 'Admin',         'slug' => 'admin',         'base_type' => 'admin',    'perms' => null],            // null => full grant
            ['type_name' => 'Support Lead',  'slug' => 'support_lead',  'base_type' => 'employee', 'perms' => $supportLead],
            ['type_name' => 'Support Agent', 'slug' => 'support_agent', 'base_type' => 'employee', 'perms' => $supportAgent],
            ['type_name' => 'Marketing',     'slug' => 'marketing',     'base_type' => 'employee', 'perms' => $marketing],
        ];

        try {
            foreach ($defaults as $r) {
                // Resolve an existing row: by slug first, else case-insensitive type_name.
                $existing = null;
                try {
                    $existing = $db->get('users_roles', ['id', 'type_name'], ['slug' => $r['slug']]);
                } catch (\Throwable $e) { /* slug column may be mid-migration */ }
                if (!$existing) {
                    // Case-insensitive type_name match (e.g. seed 'Admin').
                    $rows = $db->select('users_roles', ['id', 'type_name']);
                    foreach ((array) $rows as $row) {
                        if (strtolower((string) $row['type_name']) === strtolower($r['type_name'])) {
                            $existing = $row;
                            break;
                        }
                    }
                }

                $perms = $r['perms'];
                if ($r['base_type'] === 'admin') {
                    $perms = admin_rbac_all_grant(); // ALWAYS full — repair every boot.
                }
                $now = date('Y-m-d H:i:s');

                if ($existing) {
                    $update = [
                        'slug'        => $r['slug'],
                        'base_type'   => $r['base_type'],
                        'kind'        => 'system',
                        'status'      => 1,
                        'updated_at'  => $now,
                    ];
                    // Repair Admin perms always; for the support roles only seed
                    // perms if the row has none yet (don't stomp admin edits).
                    if ($r['base_type'] === 'admin') {
                        $update['permissions'] = json_encode($perms);
                    } else {
                        $cur = $db->get('users_roles', ['permissions'], ['id' => $existing['id']]);
                        $decoded = !empty($cur['permissions']) ? json_decode((string) $cur['permissions'], true) : null;
                        if (empty($decoded) || !is_array($decoded)) {
                            $update['permissions'] = json_encode($perms);
                        }
                    }
                    $db->update('users_roles', $update, ['id' => $existing['id']]);
                } else {
                    $db->insert('users_roles', [
                        'type_name'   => $r['type_name'],
                        'slug'        => $r['slug'],
                        'base_type'   => $r['base_type'],
                        'kind'        => 'system',
                        'status'      => 1,
                        'permissions' => json_encode($perms),
                        'created_at'  => $now,
                        'updated_at'  => $now,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log('admin_rbac_seed_default_roles: ' . $e->getMessage());
        }
    }
}

if (!function_exists('admin_current_role')) {
    /**
     * Resolve the logged-in user's users_roles row (with decoded permissions),
     * cached per request keyed by user_id. Resolution order:
     *   1. users.role_id -> users_roles.id
     *   2. users.role (lowercase) -> users_roles.slug
     *   3. users.role -> case-insensitive users_roles.type_name
     * A users.role==='admin' with no resolvable row still resolves to a synthetic
     * all-allow role, so the owner/admin is never locked out mid-migration.
     *
     * Returns an array with keys: id, type_name, base_type, permissions (decoded
     * array), is_admin (bool). Returns null when there is no logged-in user.
     */
    function admin_current_role($db)
    {
        static $cache = [];

        if (empty($_SESSION['user_id'])) {
            return null;
        }
        $uid = (string) $_SESSION['user_id'];
        if (array_key_exists($uid, $cache)) {
            return $cache[$uid];
        }

        ensureAdminRbacSchema($db);

        $user = null;
        try {
            $user = $db->get('users', ['user_id', 'role', 'role_id'], ['user_id' => $uid]);
        } catch (\Throwable $e) {
            error_log('admin_current_role(user): ' . $e->getMessage());
        }
        if (!$user) {
            return $cache[$uid] = null;
        }

        $roleName = strtolower((string) ($user['role'] ?? ''));
        $isAdmin  = ($roleName === 'admin');

        $row = null;
        // An 'employee' is linked to its role ONLY via users.role_id — the bare
        // string 'employee' must NOT fall back to matching the generic legacy
        // "Employee" seed row (that would admit a role-less employee with empty
        // perms). For every other role (admin/agent/supplier/customer) the
        // users.role STRING is the canonical link, so the slug/type_name fallback
        // is kept for them.
        $nameFallback = ($roleName !== '' && $roleName !== 'employee');
        try {
            if (!empty($user['role_id'])) {
                $row = $db->get('users_roles', '*', ['id' => (int) $user['role_id']]);
            }
            if (!$row && $nameFallback) {
                // by slug
                try {
                    $row = $db->get('users_roles', '*', ['slug' => $roleName]);
                } catch (\Throwable $e) { /* slug may not exist yet */ }
            }
            if (!$row && $nameFallback) {
                // case-insensitive type_name
                $all = $db->select('users_roles', '*');
                foreach ((array) $all as $r) {
                    if (strtolower((string) $r['type_name']) === $roleName) {
                        $row = $r;
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('admin_current_role(role): ' . $e->getMessage());
        }

        $perms = [];
        $baseType = $isAdmin ? 'admin' : 'employee';
        if ($row) {
            $baseType = (string) ($row['base_type'] ?? $baseType);
            if (!empty($row['permissions'])) {
                $decoded = json_decode((string) $row['permissions'], true);
                if (is_array($decoded)) {
                    $perms = $decoded;
                }
            }
        }

        // Admin is all-allow regardless of what the row holds.
        if ($isAdmin || strtolower($baseType) === 'admin') {
            $perms = admin_rbac_all_grant();
            $isAdmin = true;
        }

        // Role active-status (users_roles.status, governance column default 1).
        // Admin is always active. For an employee role, a row with status 0 is a
        // disabled role — callers (ADMIN_AUTH) use this to bounce the employee.
        $status = $isAdmin ? 1 : (isset($row['status']) ? (int) $row['status'] : ($row ? 1 : 0));

        return $cache[$uid] = [
            'id'         => $row['id'] ?? null,
            'type_name'  => $row['type_name'] ?? ($isAdmin ? 'Admin' : $user['role']),
            'base_type'  => $isAdmin ? 'admin' : $baseType,
            'status'     => $status,
            'permissions' => $perms,
            'is_admin'   => $isAdmin,
        ];
    }
}

if (!function_exists('admin_staff_id')) {
    /**
     * The current employee's STAFF KEY — i.e. users.user_id (VARCHAR). This
     * replaces the old support_staff.id (INT) as the identity that attendance /
     * shifts / targets / chat-assignment rows are keyed by after the Employee
     * RBAC consolidation (S3). Returns '' when there is no logged-in user.
     *
     * Any admin or employee with a session has a staff key (their users.user_id);
     * there is no separate provisioning step anymore — being an employee with an
     * active role (or an admin) IS being staff.
     */
    function admin_staff_id($db): string
    {
        $role = admin_current_role($db);
        if (!$role) {
            return '';
        }
        return (string) ($_SESSION['user_id'] ?? '');
    }
}

if (!function_exists('admin_is_staff')) {
    /**
     * True when the current user may act as support staff at all: an admin
     * (all-allow) or an employee with an active role. Replaces support_is_staff().
     */
    function admin_is_staff($db): bool
    {
        $role = admin_current_role($db);
        if (!$role) {
            return false;
        }
        if (!empty($role['is_admin'])) {
            return true;
        }
        // An active, resolved employee role.
        return !empty($role['id']) && !empty($role['status']);
    }
}

if (!function_exists('admin_can')) {
    /**
     * The capability gate. True when the current role grants $action on $area.
     *
     * A grant is the PRESENCE of the action key under the area (legacy '' value),
     * so this tests array_key_exists, NOT truthiness. Admin (is_admin) short-
     * circuits to true. A permissions blob containing the wildcard '*' (either as
     * a top-level flag or an area) is also treated as all-allow for forward
     * compatibility with the support '*' convention.
     */
    function admin_can($db, string $area, string $action = 'view'): bool
    {
        $role = admin_current_role($db);
        if (!$role) {
            return false;
        }
        if (!empty($role['is_admin'])) {
            return true;
        }
        $perms = $role['permissions'] ?? [];
        if (!is_array($perms)) {
            return false;
        }
        // Wildcard escape hatches.
        if (array_key_exists('*', $perms) || in_array('*', $perms, true)) {
            return true;
        }
        if (!isset($perms[$area]) || !is_array($perms[$area])) {
            return false;
        }
        return array_key_exists($action, $perms[$area]);
    }
}

if (!function_exists('admin_require')) {
    /**
     * The enforcement gate for a route handler. Assumes ADMIN_AUTH() has already
     * confirmed an authenticated admin/employee session. If the current role does
     * NOT grant $action on $area, deny: 403 JSON for AJAX/JSON requests, otherwise
     * redirect to the admin dashboard with an access-denied flash.
     *
     * Call AFTER ADMIN_AUTH() where granular control is wanted:
     *     ADMIN_AUTH();
     *     admin_require($db, 'bookings', 'refund');
     */
    function admin_require($db, string $area, string $action = 'view'): void
    {
        if (admin_can($db, $area, $action)) {
            return;
        }

        $isAjax = (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest')
            || (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
            || (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false);

        if ($isAjax) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
                'area'    => $area,
                'action'  => $action,
            ]);
            exit;
        }

        $_SESSION['flash_error'] = 'You do not have permission to access that area.';
        header('Location: ' . root . 'admin');
        exit;
    }
}

<?php
// app/routes/admin/supportRolesRoutes.php
// Employee RBAC consolidation — Roles admin (manage custom roles + the seeded
// system roles) on the ONE users_roles layer. Design-of-record:
// docs/support/EMPLOYEE_RBAC_CONSOLIDATION.md (S4, folded into S3).
//
// Every handler pairs ADMIN_AUTH() with admin_can($db,'users_roles','edit') and
// self-heals the schema on entry. CSRF on every POST (house pattern).
//
// The granular area x action catalogue + default-role seed live in
// app/lib/admin_rbac.php (loaded globally in config.php) — this file is the UI
// over users_roles, it does NOT define the catalogue.
//
// Permission convention (users_roles.permissions JSON): a grant is the PRESENCE
// of the action key under the area with an empty-string value, e.g.
//   {"bookings":{"page_access":"","view":"","refund":""}}
// matching app/views/admin/users/users-roles-manage.php.
@$SECURE or die('Access Denied!');

// --- shared gate for the roles screens --------------------------------------
// ADMIN_AUTH() first (admin/employee session), then the granular
// users_roles.edit permission. A full admin is all-allow (admin_can).
$supportRolesGate = function ($db) {
    ADMIN_AUTH();
    ensureAdminRbacSchema($db);
    if (!admin_can($db, 'users_roles', 'edit')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'You do not have permission to manage roles.'];
        redirect(root . admin . '/support');
        exit;
    }
};

// ================================ LIST
$router->get(admin.'/support/roles', function () use ($SECURE, $db, $supportRolesGate) {
    $supportRolesGate($db);
    $title = 'Roles'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/roles.php";
    require_once views."includes/footer.php";
});

// ================================ CREATE (form)
$router->get(admin.'/support/roles/create', function () use ($SECURE, $db, $supportRolesGate) {
    $supportRolesGate($db);
    $title = 'New Role'; $description = ''; $header = true; $footer = true;
    $_GET['id'] = 0;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/roles-manage.php";
    require_once views."includes/footer.php";
});

// ================================ EDIT (form)
$router->get(admin.'/support/roles/edit/([0-9]+)', function ($id) use ($SECURE, $db, $supportRolesGate) {
    $supportRolesGate($db);
    $title = 'Edit Role'; $description = ''; $header = true; $footer = true;
    $_GET['id'] = (int) $id;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/roles-manage.php";
    require_once views."includes/footer.php";
});

// ================================ SAVE (create/update)
$router->post(admin.'/support/roles/save', function () use ($SECURE, $db, $supportRolesGate) {
    $supportRolesGate($db);
    CSRF::verifyRequest();

    $id   = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));

    if ($name === '') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Role name is required.'];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/support/roles');
        return;
    }

    // Build the permissions blob from the posted area x action grid, whitelisted
    // against the authoritative catalogue so a client can't inject arbitrary
    // area/action keys. A granted action is stored as '' (presence = granted).
    $catalogue = admin_area_catalogue();
    $postedPerms = (array) ($_POST['permissions'] ?? []);
    $perms = [];
    foreach ($catalogue as $area => $actions) {
        $postedArea = isset($postedPerms[$area]) && is_array($postedPerms[$area]) ? $postedPerms[$area] : [];
        foreach ($actions as $action) {
            if (isset($postedArea[$action])) {
                $perms[$area][$action] = '';
            }
        }
    }

    $now = date('Y-m-d H:i:s');
    try {
        if ($id > 0) {
            $existing = $db->get('users_roles', '*', ['id' => $id]);
            if (!$existing) { throw new Exception('Role not found.'); }

            // The Admin role (base_type/type_name 'admin') is always-all — its
            // perms are force-repaired at boot, so never let this form shrink it.
            $isAdminRole = (strtolower((string) ($existing['base_type'] ?? '')) === 'admin')
                || (strtolower((string) ($existing['type_name'] ?? '')) === 'admin');
            if ($isAdminRole) {
                throw new Exception('The Admin role is all-access and cannot be edited.');
            }

            $db->update('users_roles', [
                'type_name'   => $name,
                'permissions' => json_encode($perms),
                'updated_at'  => $now,
            ], ['id' => $id]);
            $msg = 'Role updated.';
        } else {
            // New custom role: slug derived from the name (unique). kind=custom.
            $slug = function_exists('slugify') ? slugify($name) : strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));
            $slug = trim((string) $slug, '_-');
            if ($slug === '') { $slug = 'role_' . substr(md5($name . microtime()), 0, 8); }
            $base = $slug; $n = 2;
            while ((int) $db->count('users_roles', ['slug' => $slug]) > 0) {
                $slug = $base . '_' . $n; $n++;
            }
            $db->insert('users_roles', [
                'type_name'   => $name,
                'slug'        => $slug,
                'base_type'   => 'employee',
                'kind'        => 'custom',
                'status'      => 1,
                'permissions' => json_encode($perms),
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            $id  = (int) $db->id();
            $msg = 'Role created.';
        }
        $_SESSION['message'] = ['type' => 'success', 'text' => $msg];
        redirect(root . admin . '/support/roles/edit/' . $id);
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/support/roles');
    }
});

// ================================ DELETE (custom roles only)
$router->post(admin.'/support/roles/delete', function () use ($SECURE, $db, $supportRolesGate) {
    $supportRolesGate($db);
    CSRF::verifyRequest();

    $id = (int) ($_POST['id'] ?? 0);
    try {
        if ($id <= 0) { throw new Exception('Invalid role.'); }
        $role = $db->get('users_roles', '*', ['id' => $id]);
        if (!$role) { throw new Exception('Role not found.'); }

        // System roles (kind='system', incl. the legacy seed rows 1-5) and the
        // Admin role can never be deleted.
        $isSystem = (strtolower((string) ($role['kind'] ?? '')) === 'system') || ((int) $id <= 5);
        if ($isSystem) {
            throw new Exception('System roles cannot be deleted.');
        }
        // A role still assigned to any employee cannot be deleted — reassign first.
        $inUse = (int) $db->count('users', ['role_id' => $id]);
        if ($inUse > 0) {
            throw new Exception('This role is assigned to ' . $inUse . ' employee' . ($inUse === 1 ? '' : 's') . '. Reassign them before deleting.');
        }
        $db->delete('users_roles', ['id' => $id]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Role deleted.'];
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
    }
    redirect(root . admin . '/support/roles');
});

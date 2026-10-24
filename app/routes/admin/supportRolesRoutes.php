<?php
// app/routes/admin/supportRolesRoutes.php
// Customer Support module — Phase 1: RBAC roles admin (manage custom roles +
// view the LOCKED system roles). Design-of-record:
// docs/support/CUSTOMER_SUPPORT_MODULE_SPEC.md (§2 data model, §3 routes,
// §7 LOCKED decisions). Every handler pairs ADMIN_AUTH() with the
// support_can($db,'roles.manage') capability gate (rbac.php) and seeds/self-heals
// the schema on entry. CSRF on every POST per the house pattern
// (campaignsRoutes.php).
//
// The capability catalogue + default-role seed both live in
// app/lib/support/rbac.php (loaded globally in config.php) — this file is the UI
// over support_roles, it does NOT define caps.
@$SECURE or die('Access Denied!');

// --- shared capability gate for the roles screens ---------------------------
// ADMIN_AUTH() first (admin session), then the support-specific 'roles.manage'
// capability. An admin resolves to the implicit full-admin (rbac.php), so the
// owner can always reach this before any staff is provisioned.
$supportRolesGate = function ($db) {
    ADMIN_AUTH();
    ensureSupportRbacSchema($db);
    if (!support_can($db, 'roles.manage')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'You do not have permission to manage support roles.'];
        redirect(root . admin . '/support');
        exit;
    }
};

// ================================ LIST
$router->get(admin.'/support/roles', function () use ($SECURE, $db, $supportRolesGate) {
    $supportRolesGate($db);
    // Make sure the 4 LOCKED defaults exist so the list is never empty on a
    // fresh install (idempotent, check-by-slug).
    if (function_exists('support_seed_default_roles')) { support_seed_default_roles($db); }
    $title = 'Support Roles'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/roles.php";
    require_once views."includes/footer.php";
});

// ================================ CREATE (form)
$router->get(admin.'/support/roles/create', function () use ($SECURE, $db, $supportRolesGate) {
    $supportRolesGate($db);
    $title = 'New Support Role'; $description = ''; $header = true; $footer = true;
    $_GET['id'] = 0;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/roles-manage.php";
    require_once views."includes/footer.php";
});

// ================================ EDIT (form)
$router->get(admin.'/support/roles/edit/([0-9]+)', function ($id) use ($SECURE, $db, $supportRolesGate) {
    $supportRolesGate($db);
    $title = 'Edit Support Role'; $description = ''; $header = true; $footer = true;
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

    // Whitelist the posted caps against the authoritative catalogue so an
    // attacker can't inject arbitrary cap slugs (never trust client values).
    $catalogue = function_exists('support_capability_catalogue') ? support_capability_catalogue() : [];
    $posted    = array_map('strval', (array) ($_POST['permissions'] ?? []));
    $perms     = array_values(array_intersect($catalogue, $posted)); // catalogue order

    try {
        if ($id > 0) {
            $existing = $db->get('support_roles', '*', ['id' => $id]);
            if (!$existing) { throw new Exception('Role not found.'); }

            // System roles: caps are editable, but slug/is_system are LOCKED (§7).
            // The admin role is always-all — never let its '*' be stripped.
            if ((int) $existing['is_system'] === 1 && ($existing['slug'] ?? '') === 'admin') {
                $perms = ['*'];
            }
            $db->update('support_roles', [
                'name'        => $name,
                'permissions' => json_encode($perms),
            ], ['id' => $id]);
            $msg = 'Role updated.';
        } else {
            // New custom role: slug derived from the name (unique). is_system=0.
            $slug = function_exists('slugify') ? slugify($name) : strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));
            $slug = trim((string) $slug, '_-');
            if ($slug === '') { $slug = 'role_' . substr(md5($name . microtime()), 0, 8); }
            // De-duplicate the slug against existing rows (slug is UNIQUE).
            $base = $slug; $n = 2;
            while ((int) $db->count('support_roles', ['slug' => $slug]) > 0) {
                $slug = $base . '_' . $n; $n++;
            }
            $db->insert('support_roles', [
                'name'        => $name,
                'slug'        => $slug,
                'is_system'   => 0,
                'permissions' => json_encode($perms),
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

// ================================ DELETE (non-system only)
$router->post(admin.'/support/roles/delete', function () use ($SECURE, $db, $supportRolesGate) {
    $supportRolesGate($db);
    CSRF::verifyRequest();

    $id = (int) ($_POST['id'] ?? 0);
    try {
        if ($id <= 0) { throw new Exception('Invalid role.'); }
        $role = $db->get('support_roles', '*', ['id' => $id]);
        if (!$role) { throw new Exception('Role not found.'); }

        // System roles (the 4 LOCKED defaults) can never be deleted (§7).
        if ((int) $role['is_system'] === 1) {
            throw new Exception('System roles cannot be deleted.');
        }
        // A role in use by any support_staff cannot be deleted — reassign first.
        $inUse = (int) $db->count('support_staff', ['role_id' => $id]);
        if ($inUse > 0) {
            throw new Exception('This role is assigned to ' . $inUse . ' staff member' . ($inUse === 1 ? '' : 's') . '. Reassign them before deleting.');
        }
        $db->delete('support_roles', ['id' => $id]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Role deleted.'];
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
    }
    redirect(root . admin . '/support/roles');
});

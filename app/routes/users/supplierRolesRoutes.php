<?php
// FILE: app/routes/users/supplierRolesRoutes.php
// Supplier-defined custom roles (Phase 1 inc 4). A supplier builds their own roles
// — a permission matrix (modules × actions, same convention as users_roles) plus a
// PROPERTY SCOPE (all the owner's properties, or a selected subset). Staff are
// assigned these roles in increment 5; supplier_role_allows() (functions.php)
// enforces them.
//
// Security: owner-only. SUPPLIER_AUTH() + supplier_can('staff', <action>) gate every
// handler, so only an owner (or a staff member whose role grants 'staff') manages
// roles. Every role/row is scoped to the acting owner — a supplier can never read,
// edit, delete, or scope another supplier's role or property.

@$SECURE or die('Access Denied!');

if (!function_exists('_supplier_roles_owner')) {
    /** Acting supplier's owner user_id (string), or null if not a supplier. */
    function _supplier_roles_owner($db): ?string
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { return null; }
        return (string) $ctx['owner'];
    }
}

if (!function_exists('_supplier_roles_deny')) {
    function _supplier_roles_deny(string $msg = 'You are not authorised to do that.'): void
    {
        $_SESSION['message'] = ['type' => 'error', 'text' => $msg];
        header('Location: ' . root . 'supplier/roles');
        exit;
    }
}

if (!function_exists('_supplier_role_owned')) {
    /** Fetch a role row ONLY if it belongs to $owner (else null) — the IDOR guard. */
    function _supplier_role_owned($db, int $roleId, string $owner): ?array
    {
        $r = $db->get('supplier_roles', '*', ['id' => $roleId]);
        if (!$r) { return null; }
        return ((string) ($r['owner_user_id'] ?? '') === $owner) ? $r : null;
    }
}

// Build the permissions JSON from posted checkboxes, restricted to the canonical
// supplier module/action set (never trust arbitrary keys).
if (!function_exists('_supplier_role_build_perms')) {
    function _supplier_role_build_perms(array $posted): string
    {
        $modules = supplier_role_modules();
        $out = [];
        foreach ($modules as $mod => $meta) {
            $actions = $posted[$mod] ?? null;
            if (!is_array($actions)) { continue; }
            $entry = [];
            foreach ($meta['actions'] as $act) {
                if (isset($actions[$act])) { $entry[$act] = ''; }
            }
            if (!empty($entry)) { $out[$mod] = $entry; }
        }
        return json_encode($out);
    }
}

// ============================================================================
// LIST — GET /supplier/roles
// ============================================================================
$router->get('/supplier/roles', function () use ($SECURE, $db) {
    SUPPLIER_AUTH();
    $owner = _supplier_roles_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'staff', 'view')) { _supplier_roles_deny(); }

    $roles = $db->select('supplier_roles', ['id', 'name', 'scope_type', 'created_at'],
        ['owner_user_id' => $owner, 'ORDER' => ['id' => 'DESC']]) ?: [];

    // staff count per role (for display), scoped to owner.
    $counts = [];
    try {
        foreach ($roles as $r) {
            $counts[(int) $r['id']] = (int) $db->count('supplier_staff',
                ['owner_user_id' => $owner, 'role_id' => (int) $r['id']]);
        }
    } catch (\Throwable $e) {}

    $title = 'Roles & Permissions';
    $description = 'Define what your team members can do';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/roles/list.php";
    require_once views . "includes/footer.php";
});

// ============================================================================
// ADD / EDIT FORM — GET /supplier/roles/add  and  /supplier/roles/edit/{id}
// ============================================================================
$supplierRoleForm = function ($id = 0) use ($SECURE, $db) {
    SUPPLIER_AUTH();
    $owner = _supplier_roles_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'staff', $id ? 'edit' : 'add')) { _supplier_roles_deny(); }

    $role = null;
    if ($id) {
        $role = _supplier_role_owned($db, (int) $id, $owner);
        if (!$role) { _supplier_roles_deny('That role is not yours.'); }
    }

    // The owner's properties, for the 'selected' scope picker.
    $properties = $db->select('stays', ['id', 'name'],
        ['user_id' => $owner, 'ORDER' => ['id' => 'DESC']]) ?: [];

    // Pre-selected scoped properties on edit.
    $scopedIds = [];
    if ($role && ($role['scope_type'] ?? 'all') === 'selected') {
        try {
            foreach ($db->select('supplier_role_property', ['stay_id'], ['role_id' => (int) $role['id']]) ?: [] as $rp) {
                $scopedIds[] = (int) $rp['stay_id'];
            }
        } catch (\Throwable $e) {}
    }

    $isEdit = (bool) $id;
    $modules = supplier_role_modules();
    $rolePerms = [];
    if ($role && !empty($role['permissions'])) {
        $rolePerms = json_decode((string) $role['permissions'], true) ?: [];
    }

    // Approval limits (inc S16): the configurable keys + any values already set on
    // this role, so the form can render + preselect them. Defensive.
    $limitKeys = function_exists('supplier_approval_limit_keys') ? supplier_approval_limit_keys() : [];
    $roleLimits = [];
    if ($role && $id) {
        try {
            foreach ($db->select('supplier_role_limits', ['limit_key', 'unlimited', 'max_value'], ['role_id' => (int) $id]) ?: [] as $rl) {
                $roleLimits[$rl['limit_key']] = (int) ($rl['unlimited'] ?? 0) === 1
                    ? 'unlimited'
                    : ($rl['max_value'] === null ? '' : (string) (float) $rl['max_value']);
            }
        } catch (\Throwable $e) {}
    }

    $title = $isEdit ? 'Edit Role' : 'Create Role';
    $description = '';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/roles/form.php";
    require_once views . "includes/footer.php";
};
$router->get('/supplier/roles/add', function () use ($supplierRoleForm) { $supplierRoleForm(0); });
$router->get('/supplier/roles/edit/([0-9]+)', function ($id) use ($supplierRoleForm) { $supplierRoleForm((int) $id); });

// ============================================================================
// SAVE — POST /supplier/roles/save  (create or update)
// ============================================================================
$router->post('/supplier/roles/save', function () use ($SECURE, $db) {
    SUPPLIER_AUTH();
    CSRF::guard();
    $owner = _supplier_roles_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }

    $id   = (int) ($_POST['id'] ?? 0);
    if (!supplier_can($db, 'staff', $id ? 'edit' : 'add')) { _supplier_roles_deny(); }

    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Role name is required.'];
        header('Location: ' . root . 'supplier/roles/' . ($id ? 'edit/' . $id : 'add'));
        exit;
    }

    $scopeType = (($_POST['scope_type'] ?? 'all') === 'selected') ? 'selected' : 'all';
    $permsJson = _supplier_role_build_perms(is_array($_POST['permissions'] ?? null) ? $_POST['permissions'] : []);

    // Validate the posted property ids BELONG to the owner before scoping to them.
    $postedStayIds = array_map('intval', is_array($_POST['properties'] ?? null) ? $_POST['properties'] : []);
    $validStayIds = [];
    if ($scopeType === 'selected' && !empty($postedStayIds)) {
        try {
            $owned = $db->select('stays', ['id'], ['user_id' => $owner, 'id' => $postedStayIds]) ?: [];
            foreach ($owned as $o) { $validStayIds[] = (int) $o['id']; }
        } catch (\Throwable $e) {}
    }

    try {
        if ($id) {
            // OWNERSHIP guard before update.
            if (!_supplier_role_owned($db, $id, $owner)) { _supplier_roles_deny('That role is not yours.'); }
            $db->update('supplier_roles', [
                'name' => $name, 'permissions' => $permsJson,
                'scope_type' => $scopeType, 'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $id, 'owner_user_id' => $owner]);
            $roleId = $id;
        } else {
            $db->insert('supplier_roles', [
                'owner_user_id' => $owner, 'name' => $name,
                'permissions' => $permsJson, 'scope_type' => $scopeType,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $roleId = (int) $db->id();
        }

        // Rebuild the property-scope rows (only for 'selected'; owner-validated ids).
        if ($roleId > 0) {
            $db->delete('supplier_role_property', ['role_id' => $roleId]);
            if ($scopeType === 'selected') {
                foreach ($validStayIds as $sid) {
                    $db->insert('supplier_role_property', ['role_id' => $roleId, 'stay_id' => $sid]);
                }
            }
        }

        // Approval limits (inc S16) — rebuild from $_POST['limits'][key]. Only keys in
        // the canonical catalogue are accepted; a blank value = not configured
        // (row removed → supplier_can_approve denies/escalates); 'unlimited' = no cap;
        // a number = the max. Non-breaking: absent when the form doesn't post limits.
        if ($roleId > 0 && function_exists('supplier_approval_limit_keys')) {
            try {
                $limitKeys = supplier_approval_limit_keys();
                $posted = is_array($_POST['limits'] ?? null) ? $_POST['limits'] : [];
                foreach ($limitKeys as $k => $meta) {
                    $raw = isset($posted[$k]) ? trim((string) $posted[$k]) : '';
                    // Always clear the existing row first, then set the new state.
                    $db->delete('supplier_role_limits', ['role_id' => $roleId, 'limit_key' => $k]);
                    if ($raw === '') { continue; } // not configured
                    if (strtolower($raw) === 'unlimited') {
                        $db->insert('supplier_role_limits', [
                            'role_id' => $roleId, 'limit_key' => $k, 'unlimited' => 1,
                            'max_value' => null, 'created_at' => date('Y-m-d H:i:s'),
                        ]);
                        continue;
                    }
                    if (!is_numeric($raw)) { continue; } // ignore garbage
                    $val = round((float) $raw, 2);
                    if ($val < 0) { $val = 0; }
                    // Percent limits clamp to 100.
                    if (($meta['unit'] ?? '') === 'percent' && $val > 100) { $val = 100; }
                    $db->insert('supplier_role_limits', [
                        'role_id' => $roleId, 'limit_key' => $k, 'unlimited' => 0,
                        'max_value' => $val, 'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('supplier role limits save: ' . $e->getMessage());
            }
        }
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Role saved.'];
    } catch (\Throwable $e) {
        error_log('supplier role save: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not save the role.'];
    }
    header('Location: ' . root . 'supplier/roles');
    exit;
});

// ============================================================================
// DELETE — POST /supplier/roles/delete
// ============================================================================
$router->post('/supplier/roles/delete', function () use ($SECURE, $db) {
    SUPPLIER_AUTH();
    CSRF::guard();
    $owner = _supplier_roles_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'staff', 'delete')) { _supplier_roles_deny(); }

    $id = (int) ($_POST['id'] ?? 0);
    if (!$id || !_supplier_role_owned($db, $id, $owner)) { _supplier_roles_deny('That role is not yours.'); }

    // Refuse to delete a role still assigned to staff (avoid orphaning staff).
    try {
        $inUse = (int) $db->count('supplier_staff', ['owner_user_id' => $owner, 'role_id' => $id]);
        if ($inUse > 0) {
            _supplier_roles_deny('This role is assigned to ' . $inUse . ' staff member' . ($inUse === 1 ? '' : 's') . '. Reassign them first.');
        }
        $db->delete('supplier_role_property', ['role_id' => $id]);
        $db->delete('supplier_role_limits', ['role_id' => $id]); // inc S16: no orphan limits
        $db->delete('supplier_roles', ['id' => $id, 'owner_user_id' => $owner]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Role deleted.'];
    } catch (\Throwable $e) {
        error_log('supplier role delete: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not delete the role.'];
    }
    header('Location: ' . root . 'supplier/roles');
    exit;
});

<?php
// app/routes/admin/supportStaffRoutes.php
// Customer Support module — Phase 1: support STAFF admin screens. Assign an
// existing users row as a support employee (role + display name + nudge phone +
// shift group + status). Design-of-record: docs/support/CUSTOMER_SUPPORT_MODULE_SPEC.md
// (§2 data model, §3 routes, §7 LOCKED decisions). Shape mirrors
// app/routes/admin/campaignsRoutes.php; every handler is ADMIN_AUTH() + the
// support_can($db,'staff.manage') capability gate, CSRF on POST.
@$SECURE or die('Access Denied!');

// ================================ LIST
// support_staff joined to users for the human-readable columns (name/email/phone)
// plus the role name. Rendered via the header/page/footer triple.
$router->get(admin.'/support/staff', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    ensureSupportRbacSchema($db);
    if (!support_can($db, 'staff.manage')) { http_response_code(403); die('Access Denied!'); }
    $title = 'Support Staff'; $description = ''; $header = true; $footer = true;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/staff.php";
    require_once views."includes/footer.php";
});

// ================================ CREATE (form)
$router->get(admin.'/support/staff/create', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    ensureSupportRbacSchema($db);
    if (!support_can($db, 'staff.manage')) { http_response_code(403); die('Access Denied!'); }
    $title = 'Add Support Staff'; $description = ''; $header = true; $footer = true;
    $_GET['id'] = 0;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/staff-manage.php";
    require_once views."includes/footer.php";
});

// ================================ EDIT (form)
$router->get(admin.'/support/staff/edit/([0-9]+)', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    ensureSupportRbacSchema($db);
    if (!support_can($db, 'staff.manage')) { http_response_code(403); die('Access Denied!'); }
    $title = 'Edit Support Staff'; $description = ''; $header = true; $footer = true;
    $_GET['id'] = (int) $id;
    require_once views."includes/header.php";
    require_once "app/views/admin/support/staff-manage.php";
    require_once views."includes/footer.php";
});

// ================================ SAVE (upsert — one staff row per user_id)
$router->post(admin.'/support/staff/save', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::verifyRequest();
    ensureSupportRbacSchema($db);
    if (!support_can($db, 'staff.manage')) { http_response_code(403); die('Access Denied!'); }

    $id      = (int) ($_POST['id'] ?? 0);
    $userId  = trim((string) ($_POST['user_id'] ?? ''));
    $roleId  = (int) ($_POST['role_id'] ?? 0);
    $display = trim((string) ($_POST['display_name'] ?? ''));
    $phone   = trim((string) ($_POST['phone'] ?? ''));
    $shift   = trim((string) ($_POST['shift_group'] ?? ''));
    $status  = in_array($_POST['status'] ?? '', ['active', 'suspended'], true) ? $_POST['status'] : 'active';

    try {
        // The target user is mandatory and must be a real users row.
        if ($userId === '') { throw new Exception('Select a user to add as support staff.'); }
        $user = $db->get('users', ['user_id', 'first_name', 'last_name', 'phone'], ['user_id' => $userId]);
        if (!$user) { throw new Exception('That user could not be found.'); }

        // Role is optional at the DB level (role_id nullable) but required by the
        // form — a staff member with no role has no capabilities.
        if ($roleId <= 0 || !$db->count('support_roles', ['id' => $roleId])) {
            throw new Exception('Pick a role for this staff member.');
        }

        // Defaults: display name falls back to the user's full name, phone to the
        // user's own phone (used for GIA shift nudges).
        if ($display === '') {
            $display = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        }
        if ($phone === '') { $phone = trim((string) ($user['phone'] ?? '')); }

        $data = [
            'role_id'      => $roleId,
            'display_name' => $display !== '' ? $display : null,
            'phone'        => $phone !== '' ? $phone : null,
            'shift_group'  => $shift !== '' ? $shift : null,
            'status'       => $status,
        ];

        // Upsert keyed on the UNIQUE user_id: one staff row per user. On edit we
        // locate the row by its id; on create we guard against re-adding a user
        // that already has a staff row (the UNIQUE key would otherwise error).
        if ($id > 0) {
            $existing = $db->get('support_staff', '*', ['id' => $id]);
            if (!$existing) { throw new Exception('Staff record not found.'); }
            // Do not allow moving a staff row onto a different user_id (that would
            // collide with the UNIQUE key / change identity); edit keeps its user.
            $db->update('support_staff', $data, ['id' => $id]);
        } else {
            if ($db->count('support_staff', ['user_id' => $userId])) {
                throw new Exception('That user is already a support staff member.');
            }
            $data['user_id'] = $userId;
            $db->insert('support_staff', $data);
            $id = (int) $db->id();
        }

        $_SESSION['message'] = ['type' => 'success', 'text' => 'Support staff saved.'];
        redirect(root . admin . '/support/staff');
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/support/staff');
    }
});

// ================================ DELETE (removes the staff row, NOT the user)
$router->post(admin.'/support/staff/delete', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::verifyRequest();
    ensureSupportRbacSchema($db);
    if (!support_can($db, 'staff.manage')) { http_response_code(403); die('Access Denied!'); }

    $id = (int) ($_POST['id'] ?? 0);
    try {
        if ($id > 0) {
            // Only the support_staff elevation is removed — the underlying users
            // row is left completely intact.
            $db->delete('support_staff', ['id' => $id]);
        }
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Support staff removed.'];
    } catch (\Throwable $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
    }
    redirect(root . admin . '/support/staff');
});

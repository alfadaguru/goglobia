<?php
// ============================================================================
// app/routes/users/agentStaffRoutes.php — Agent ERP P2: staff & roles.
// Mirrors app/routes/users/supplierStaffRoutes.php (invite/accept/revoke +
// roles CRUD), adapted to the agent spine (agent_acting_context / agent_can /
// agent_staff / agent_roles). Owner-scoped, multi-tenant, with the same
// account-takeover (HIGH-4) and re-invite (HIGH-5) guards.
//   GET  /agent/staff                → team list + invite form + roles summary
//   POST /agent/staff/invite         → email a 7-day single-use invite
//   POST /agent/staff/revoke         → revoke an invite/member
//   GET  /agent/staff/accept         → public token-gated accept form
//   POST /agent/staff/accept         → create/link user, consume invite
//   GET  /agent/roles                → roles list + builder
//   POST /agent/roles/save           → create/update a custom role
//   POST /agent/roles/delete         → delete a custom (non-system) role
// ============================================================================
@$SECURE or die('Access Denied!');

if (!function_exists('_agent_staff_owner')) {
    /** The acting OWNER user_id, or null if not an agency owner/staff. */
    function _agent_staff_owner($db): ?string
    {
        $ctx = agent_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { return null; }
        $owner = (string) ($ctx['owner'] ?? '');
        return $owner !== '' ? $owner : null;
    }
}
if (!function_exists('_agent_staff_deny')) {
    function _agent_staff_deny(string $msg = 'You do not have permission to do that.'): void
    {
        $_SESSION['message'] = ['type' => 'error', 'text' => $msg];
        header('Location: ' . root . 'agent/staff');
        exit;
    }
}

// ---- GET /agent/staff -------------------------------------------------------
$router->get('/agent/staff', function () use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    $owner = _agent_staff_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $ctx = agent_acting_context($db);
    if (!($ctx['is_owner'] ?? false) && !agent_can($db, 'staff', 'view')) { _agent_staff_deny(); }

    $staff = $db->select('agent_staff',
        ['id','staff_user_id','role_id','invited_email','status','invite_expires','accepted_at','created_at'],
        ['owner_user_id' => $owner, 'ORDER' => ['id' => 'DESC']]) ?: [];
    $roles = $db->select('agent_roles', ['id','name','is_system'], ['owner_user_id' => $owner]) ?: [];
    $roleNames = [];
    foreach ($roles as $r) { $roleNames[(int) $r['id']] = (string) $r['name']; }
    // Resolve staff display names.
    foreach ($staff as &$s) {
        $s['role_name'] = $s['role_id'] ? ($roleNames[(int) $s['role_id']] ?? '—') : 'Owner-set';
        $s['display'] = (string) ($s['invited_email'] ?? '');
        if (!empty($s['staff_user_id'])) {
            try { $u = $db->get('users', ['first_name','last_name','email'], ['user_id' => $s['staff_user_id']]);
                  if ($u) { $s['display'] = trim(($u['first_name']??'').' '.($u['last_name']??'')) ?: (string)($u['email']??$s['display']); } }
            catch (\Throwable $e) {}
        }
    }
    unset($s);
    $canManage = ($ctx['is_owner'] ?? false) || agent_can($db, 'staff', 'manage');

    $title = 'Staff & Roles';
    require_once views."agent/layout-top.php";
    require_once views."agent/staff.php";
    require_once views."agent/layout-bottom.php";
});

// ---- POST /agent/staff/invite ----------------------------------------------
$router->post('/agent/staff/invite', function () use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _agent_staff_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $ctx = agent_acting_context($db);
    if (!($ctx['is_owner'] ?? false) && !agent_can($db, 'staff', 'manage')) { _agent_staff_deny(); }

    $email  = trim($_POST['email'] ?? '');
    $roleId = (int) ($_POST['role_id'] ?? 0);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { _agent_staff_deny('Please enter a valid email address.'); }
    if ($roleId > 0) {
        $role = $db->get('agent_roles', ['id'], ['id' => $roleId, 'owner_user_id' => $owner]);
        if (!$role) { _agent_staff_deny('That role is not yours.'); }
    } else { $roleId = null; }

    // HIGH-4: only a new email or an existing plain 'customer' may be invited.
    $existing = $db->get('users', ['role'], ['email' => $email]);
    if ($existing && !in_array(($existing['role'] ?? ''), ['customer', ''], true)) {
        _agent_staff_deny('That email already belongs to a ' . htmlspecialchars((string) $existing['role']) . ' account and cannot be invited as staff.');
    }

    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 7 * 24 * 3600);

    // HIGH-5: reuse a prior revoked/rejected row (UNIQUE owner+email); refuse if active/invited.
    $prior = $db->get('agent_staff', ['id', 'status'], ['owner_user_id' => $owner, 'invited_email' => $email]);
    try {
        if ($prior) {
            if (in_array((string) $prior['status'], ['active', 'invited'], true)) {
                _agent_staff_deny('That email is already a member or has a pending invitation.');
            }
            $db->update('agent_staff', [
                'staff_user_id' => null, 'role_id' => $roleId, 'invite_token' => $token,
                'invite_expires' => $expires, 'status' => 'invited', 'accepted_at' => null,
                'created_by' => $owner,
            ], ['id' => (int) $prior['id'], 'owner_user_id' => $owner]);
        } else {
            $db->insert('agent_staff', [
                'owner_user_id' => $owner, 'staff_user_id' => null, 'role_id' => $roleId,
                'invited_email' => $email, 'invite_token' => $token, 'invite_expires' => $expires,
                'status' => 'invited', 'created_by' => $owner, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    } catch (\Throwable $e) {
        error_log('agent staff invite: ' . $e->getMessage());
        _agent_staff_deny('Could not create the invitation.');
    }

    if (function_exists('SENDEMAIL')) {
        try {
            $brand = $GLOBALS['app']['business_name'] ?? ($GLOBALS['app']['app_name'] ?? 'Our Platform');
            $acceptUrl = root . 'agent/staff/accept?token=' . urlencode($token);
            SENDEMAIL($email, $email, 'You have been invited to join an agency team',
                '<p>Hello,</p><p>You have been invited to join an agency team on ' . htmlspecialchars($brand)
                . '. Click below to set your password and accept — the link expires in 7 days.</p>'
                . '<p><a href="' . $acceptUrl . '">Accept invitation</a></p>'
                . '<p>If you did not expect this, you can ignore this email.</p>', null);
        } catch (\Throwable $e) { error_log('agent staff invite email: ' . $e->getMessage()); }
    }
    if (function_exists('agent_audit_log')) { agent_audit_log($db, $owner, (string) $ctx['actor'], 'staff.invite', $email); }

    $_SESSION['message'] = ['type' => 'success', 'text' => 'Invitation sent to ' . htmlspecialchars($email) . '.'];
    header('Location: ' . root . 'agent/staff');
    exit;
});

// ---- POST /agent/staff/revoke ----------------------------------------------
$router->post('/agent/staff/revoke', function () use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _agent_staff_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $ctx = agent_acting_context($db);
    if (!($ctx['is_owner'] ?? false) && !agent_can($db, 'staff', 'manage')) { _agent_staff_deny(); }

    $id  = (int) ($_POST['id'] ?? 0);
    $row = $id ? $db->get('agent_staff', ['id'], ['id' => $id, 'owner_user_id' => $owner]) : null;
    if (!$row) { _agent_staff_deny('That staff record is not yours.'); }
    try {
        $db->update('agent_staff', ['status' => 'revoked', 'invite_token' => null, 'invite_expires' => null],
            ['id' => $id, 'owner_user_id' => $owner]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Access revoked.'];
    } catch (\Throwable $e) { error_log('agent staff revoke: ' . $e->getMessage()); _agent_staff_deny('Could not revoke access.'); }
    header('Location: ' . root . 'agent/staff');
    exit;
});

// ---- GET /agent/staff/accept (public, token-gated) -------------------------
$router->get('/agent/staff/accept', function () use ($SECURE, $db) {
    $token = $_GET['token'] ?? '';
    $invite = null;
    if ($token !== '') {
        $invite = $db->get('agent_staff', ['id','invited_email','status','invite_expires'],
            ['invite_token' => $token, 'status' => 'invited']);
        if ($invite && !empty($invite['invite_expires']) && strtotime($invite['invite_expires']) < time()) { $invite = null; }
    }
    $existingAccount = false;
    if ($invite) {
        $u = $db->get('users', ['user_id'], ['email' => (string) $invite['invited_email']]);
        if ($u) {
            $sessionUid = (string) ($_SESSION['user_id'] ?? '');
            $existingAccount = ($sessionUid === '' || $sessionUid !== (string) $u['user_id']);
        }
    }
    $title = 'Accept Invitation';
    require_once views."agent/layout-top.php";
    require_once views."agent/staff-accept.php";
    require_once views."agent/layout-bottom.php";
});

// ---- POST /agent/staff/accept ----------------------------------------------
$router->post('/agent/staff/accept', function () use ($SECURE, $db) {
    CSRF::guard();
    $token = $_POST['token'] ?? '';
    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm_password'] ?? '');
    $first    = trim($_POST['first_name'] ?? '');
    $last     = trim($_POST['last_name'] ?? '');
    $back = root . 'agent/staff/accept?token=' . urlencode($token);

    $invite = $token !== '' ? $db->get('agent_staff', '*', ['invite_token' => $token, 'status' => 'invited']) : null;
    if ($invite && !empty($invite['invite_expires']) && strtotime($invite['invite_expires']) < time()) { $invite = null; }
    if (!$invite) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'This invitation is invalid or has expired.'];
        header('Location: ' . root . 'login'); exit;
    }
    if ($first === '' || $last === '' || $password === '' || $confirm === '') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Please complete all fields.']; header('Location: ' . $back); exit;
    }
    if ($password !== $confirm) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Passwords do not match.']; header('Location: ' . $back); exit;
    }
    if (strlen($password) < 6) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Password must be at least 6 characters.']; header('Location: ' . $back); exit;
    }

    $email = (string) $invite['invited_email'];
    try {
        $user = $db->get('users', ['id','user_id'], ['email' => $email]);
        if ($user) {
            // HIGH-4: existing account — must be signed in as it; LINK only, no cred write.
            $sessionUid = (string) ($_SESSION['user_id'] ?? '');
            if ($sessionUid === '' || $sessionUid !== (string) $user['user_id']) {
                $_SESSION['message'] = ['type' => 'error', 'text' =>
                    'An account already exists for ' . htmlspecialchars($email) . '. Please sign in to that account first, then open the invitation link.'];
                header('Location: ' . root . 'login'); exit;
            }
            $staffUserId = (string) $user['user_id'];
        } else {
            $staffUserId = generateUserId(); $retry = 0;
            while ($retry < 5 && $db->has('users', ['user_id' => $staffUserId])) { $staffUserId = generateUserId(); $retry++; }
            $default_curr = $db->get('currencies', 'name', ['default' => 1]) ?: 'USD';
            $db->insert('users', [
                'user_id' => $staffUserId, 'first_name' => $first, 'last_name' => $last, 'email' => $email,
                'currency' => $default_curr, 'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'customer', 'status' => 'active', 'banned' => 0, 'email_verified' => 1,
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        $db->update('agent_staff', [
            'staff_user_id' => $staffUserId, 'status' => 'active', 'accepted_at' => date('Y-m-d H:i:s'),
            'invite_token' => null, 'invite_expires' => null,
        ], ['id' => (int) $invite['id']]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Invitation accepted. You can now sign in.'];
    } catch (\Throwable $e) {
        error_log('agent staff accept: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not complete your account. Please contact the agency.'];
        header('Location: ' . $back); exit;
    }
    header('Location: ' . root . 'login');
    exit;
});

// ============================================================================
// ROLES — list / save / delete  (owner-scoped; system roles are protected)
// ============================================================================
$router->get('/agent/roles', function () use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    $owner = _agent_staff_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $ctx = agent_acting_context($db);
    if (!($ctx['is_owner'] ?? false) && !agent_can($db, 'roles', 'view')) { _agent_staff_deny(); }

    // Seed defaults if none yet (approved owners get them at approval; this is a safety net).
    if (function_exists('agent_seed_default_roles')) { agent_seed_default_roles($db, $owner); }

    $roles = $db->select('agent_roles', ['id','name','permissions','is_system','created_at'],
        ['owner_user_id' => $owner, 'ORDER' => ['is_system' => 'DESC', 'id' => 'ASC']]) ?: [];
    $catalogue = agent_capabilities_catalogue();
    $canManage = ($ctx['is_owner'] ?? false) || agent_can($db, 'roles', 'manage');

    $title = 'Roles & Permissions';
    require_once views."agent/layout-top.php";
    require_once views."agent/roles.php";
    require_once views."agent/layout-bottom.php";
});

$router->post('/agent/roles/save', function () use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _agent_staff_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $ctx = agent_acting_context($db);
    if (!($ctx['is_owner'] ?? false) && !agent_can($db, 'roles', 'manage')) { _agent_staff_deny(); }

    $id   = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') { $_SESSION['message'] = ['type'=>'error','text'=>'Role name is required.']; header('Location: ' . root . 'agent/roles'); exit; }

    // Build permissions from posted perms[module][action]=1 against the catalogue.
    $catalogue = agent_capabilities_catalogue();
    $posted = is_array($_POST['perms'] ?? null) ? $_POST['perms'] : [];
    $perms = [];
    foreach ($catalogue as $mod => $actions) {
        foreach ($actions as $act) {
            if (!empty($posted[$mod][$act])) { $perms[$mod][$act] = ''; }
        }
    }
    $payload = ['owner_user_id'=>$owner,'name'=>$name,'permissions'=>json_encode($perms),'updated_at'=>date('Y-m-d H:i:s')];
    try {
        if ($id > 0) {
            // Only own, non-system roles are editable.
            $role = $db->get('agent_roles', ['id','is_system'], ['id'=>$id,'owner_user_id'=>$owner]);
            if (!$role) { _agent_staff_deny('That role is not yours.'); }
            if ((int) $role['is_system'] === 1) { _agent_staff_deny('Default roles cannot be edited.'); }
            $db->update('agent_roles', $payload, ['id'=>$id,'owner_user_id'=>$owner]);
            $_SESSION['message'] = ['type'=>'success','text'=>'Role updated.'];
        } else {
            $payload['is_system'] = 0; $payload['created_at'] = date('Y-m-d H:i:s');
            $db->insert('agent_roles', $payload);
            $_SESSION['message'] = ['type'=>'success','text'=>'Role created.'];
        }
    } catch (\Throwable $e) { error_log('agent roles save: ' . $e->getMessage()); $_SESSION['message'] = ['type'=>'error','text'=>'Could not save the role.']; }
    header('Location: ' . root . 'agent/roles');
    exit;
});

$router->post('/agent/roles/delete', function () use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    CSRF::guard();
    $owner = _agent_staff_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    $ctx = agent_acting_context($db);
    if (!($ctx['is_owner'] ?? false) && !agent_can($db, 'roles', 'manage')) { _agent_staff_deny(); }

    $id = (int) ($_POST['id'] ?? 0);
    $role = $id ? $db->get('agent_roles', ['id','is_system'], ['id'=>$id,'owner_user_id'=>$owner]) : null;
    if (!$role) { _agent_staff_deny('That role is not yours.'); }
    if ((int) $role['is_system'] === 1) { _agent_staff_deny('Default roles cannot be deleted.'); }
    try {
        // Detach staff on this role so none dangle (they fall back to no-permission).
        $db->update('agent_staff', ['role_id'=>null], ['role_id'=>$id,'owner_user_id'=>$owner]);
        $db->delete('agent_roles', ['id'=>$id,'owner_user_id'=>$owner]);
        $_SESSION['message'] = ['type'=>'success','text'=>'Role deleted.'];
    } catch (\Throwable $e) { error_log('agent roles delete: ' . $e->getMessage()); _agent_staff_deny('Could not delete the role.'); }
    header('Location: ' . root . 'agent/roles');
    exit;
});

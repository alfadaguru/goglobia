<?php
// FILE: app/routes/users/supplierStaffRoutes.php
// Supplier staff invitations (Phase 1 inc 5). An owner invites team members by
// email; the invitee receives a single-use, expiring token link, sets their own
// password, and is linked as staff of that supplier with a role. Staff then log
// in through the normal /login and are scoped to the owner's properties by their
// role (supplier_staff_context + supplier_role_allows).
//
// Security:
//   - management (invite/revoke/resend) is OWNER-only: SUPPLIER_AUTH() +
//     supplier_can('staff', <action>) + CSRF.
//   - the accept flow is PUBLIC but token-gated: single-use, expiring, bound to
//     the invited email. Accepting never grants owner/admin — only a staff link.
//   - an owner can only invite under their own account; role_id (if any) is
//     validated to belong to the owner before being attached.

@$SECURE or die('Access Denied!');

if (!function_exists('_supplier_staff_owner')) {
    function _supplier_staff_owner($db): ?string
    {
        $ctx = supplier_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { return null; }
        return (string) $ctx['owner'];
    }
}
if (!function_exists('_supplier_staff_deny')) {
    function _supplier_staff_deny(string $msg = 'You are not authorised to do that.'): void
    {
        $_SESSION['message'] = ['type' => 'error', 'text' => $msg];
        header('Location: ' . root . 'supplier/staff');
        exit;
    }
}

// ============================================================================
// LIST — GET /supplier/staff  (owner's team + pending invites)
// ============================================================================
$router->get('/supplier/staff', function () use ($SECURE, $db) {
    SUPPLIER_AUTH(); // owner-only management area
    $owner = _supplier_staff_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'staff', 'view')) { _supplier_staff_deny(); }

    $staff = $db->select('supplier_staff',
        ['id', 'staff_user_id', 'role_id', 'invited_email', 'status', 'invite_expires', 'accepted_at', 'created_at'],
        ['owner_user_id' => $owner, 'ORDER' => ['id' => 'DESC']]) ?: [];

    // Roles for the invite dropdown + name lookup, scoped to owner.
    $roles = $db->select('supplier_roles', ['id', 'name'], ['owner_user_id' => $owner]) ?: [];
    $roleNames = [];
    foreach ($roles as $r) { $roleNames[(int) $r['id']] = $r['name']; }

    $title = 'Staff';
    $description = 'Invite and manage your team';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/staff/list.php";
    require_once views . "includes/footer.php";
});

// ============================================================================
// INVITE — POST /supplier/staff/invite
// ============================================================================
$router->post('/supplier/staff/invite', function () use ($SECURE, $db) {
    SUPPLIER_AUTH();
    CSRF::guard();
    $owner = _supplier_staff_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'staff', 'add')) { _supplier_staff_deny(); }

    $email  = trim($_POST['email'] ?? '');
    $roleId = (int) ($_POST['role_id'] ?? 0);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        _supplier_staff_deny('Please enter a valid email address.');
    }
    // Role (if chosen) MUST belong to this owner.
    if ($roleId > 0) {
        $role = $db->get('supplier_roles', ['id'], ['id' => $roleId, 'owner_user_id' => $owner]);
        if (!$role) { _supplier_staff_deny('That role is not yours.'); }
    } else {
        $roleId = null;
    }

    // Don't invite an email that is already this owner's staff (unique owner+email).
    if ($db->has('supplier_staff', ['owner_user_id' => $owner, 'invited_email' => $email])) {
        _supplier_staff_deny('That email has already been invited.');
    }
    // Don't invite the owner themselves or an existing admin.
    $existing = $db->get('users', ['role'], ['email' => $email]);
    if ($existing && in_array(($existing['role'] ?? ''), ['admin'], true)) {
        _supplier_staff_deny('That email belongs to an administrator.');
    }

    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 7 * 24 * 3600); // 7-day invite window

    try {
        $db->insert('supplier_staff', [
            'owner_user_id' => $owner,
            'staff_user_id' => null,
            'role_id'       => $roleId,
            'invited_email' => $email,
            'invite_token'  => $token,
            'invite_expires'=> $expires,
            'status'        => 'invited',
            'created_by'    => $owner,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {
        error_log('supplier staff invite: ' . $e->getMessage());
        _supplier_staff_deny('Could not create the invitation.');
    }

    // Email the invite link (best-effort; the row is created regardless).
    if (function_exists('SENDEMAIL')) {
        try {
            $companyName = $GLOBALS['app']['business_name'] ?? ($GLOBALS['app']['app_name'] ?? 'Our Platform');
            $acceptUrl = root . 'supplier/staff/accept?token=' . urlencode($token);
            SENDEMAIL(
                $email, $email,
                'You have been invited to join a supplier team',
                '<p>Hello,</p>'
                . '<p>You have been invited to join a supplier team on ' . htmlspecialchars($companyName) . '. '
                . 'Click the link below to set your password and accept — the link expires in 7 days.</p>'
                . '<p><a href="' . $acceptUrl . '">Accept invitation</a></p>'
                . '<p>If you did not expect this, you can ignore this email.</p>',
                null
            );
        } catch (\Throwable $e) {
            error_log('supplier staff invite email: ' . $e->getMessage());
        }
    }

    $_SESSION['message'] = ['type' => 'success', 'text' => 'Invitation sent to ' . htmlspecialchars($email) . '.'];
    header('Location: ' . root . 'supplier/staff');
    exit;
});

// ============================================================================
// REVOKE — POST /supplier/staff/revoke  (remove invite or deactivate a member)
// ============================================================================
$router->post('/supplier/staff/revoke', function () use ($SECURE, $db) {
    SUPPLIER_AUTH();
    CSRF::guard();
    $owner = _supplier_staff_owner($db);
    if ($owner === null) { header('Location: ' . root . 'login'); exit; }
    if (!supplier_can($db, 'staff', 'delete')) { _supplier_staff_deny(); }

    $id = (int) ($_POST['id'] ?? 0);
    // OWNERSHIP: the row must belong to this owner.
    $row = $id ? $db->get('supplier_staff', ['id'], ['id' => $id, 'owner_user_id' => $owner]) : null;
    if (!$row) { _supplier_staff_deny('That staff record is not yours.'); }

    try {
        $db->update('supplier_staff',
            ['status' => 'revoked', 'invite_token' => null, 'invite_expires' => null],
            ['id' => $id, 'owner_user_id' => $owner]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Access revoked.'];
    } catch (\Throwable $e) {
        error_log('supplier staff revoke: ' . $e->getMessage());
        _supplier_staff_deny('Could not revoke access.');
    }
    header('Location: ' . root . 'supplier/staff');
    exit;
});

// ============================================================================
// ACCEPT — GET /supplier/staff/accept?token=...  (public, token-gated form)
// ============================================================================
$router->get('/supplier/staff/accept', function () use ($SECURE, $db) {
    $token = $_GET['token'] ?? '';
    $invite = null;
    if ($token !== '') {
        $invite = $db->get('supplier_staff', ['id', 'invited_email', 'status', 'invite_expires'],
            ['invite_token' => $token, 'status' => 'invited']);
        // Expiry check (string compare on Y-m-d H:i:s is safe/lexical-correct).
        if ($invite && !empty($invite['invite_expires']) && strtotime($invite['invite_expires']) < time()) {
            $invite = null;
        }
    }
    $title = 'Accept Invitation';
    $description = '';
    $header = true; $footer = true;
    require_once views . "includes/header.php";
    require_once views . "supplier/staff/accept.php"; // renders error if $invite is null
    require_once views . "includes/footer.php";
});

// ============================================================================
// ACCEPT SUBMIT — POST /supplier/staff/accept  (create/link user, set password)
// ============================================================================
$router->post('/supplier/staff/accept', function () use ($SECURE, $db) {
    CSRF::guard();
    $token            = $_POST['token'] ?? '';
    $password         = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    $first_name       = trim($_POST['first_name'] ?? '');
    $last_name        = trim($_POST['last_name'] ?? '');

    $back = root . 'supplier/staff/accept?token=' . urlencode($token);

    // Re-validate the token server-side (single-use, unexpired, still 'invited').
    $invite = $token !== '' ? $db->get('supplier_staff', '*',
        ['invite_token' => $token, 'status' => 'invited']) : null;
    if ($invite && !empty($invite['invite_expires']) && strtotime($invite['invite_expires']) < time()) {
        $invite = null;
    }
    if (!$invite) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'This invitation is invalid or has expired.'];
        header('Location: ' . root . 'login');
        exit;
    }

    if ($first_name === '' || $last_name === '' || $password === '' || $confirm_password === '') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Please complete all fields.'];
        header('Location: ' . $back); exit;
    }
    if ($password !== $confirm_password) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Passwords do not match.'];
        header('Location: ' . $back); exit;
    }
    if (strlen($password) < 6) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Password must be at least 6 characters.'];
        header('Location: ' . $back); exit;
    }

    $email = (string) $invite['invited_email'];

    try {
        // Link to an existing user with this email, or create a new one. The new
        // account's role is 'customer' (NOT supplier/admin) — being staff is a
        // SEPARATE linkage via supplier_staff, so accepting can never grant the
        // owner/admin role. Access comes solely from the supplier_staff row.
        $user = $db->get('users', ['id', 'user_id'], ['email' => $email]);
        if ($user) {
            $staffUserId = (string) $user['user_id'];
            // Only set a password if the invitee is creating their credential here.
            $db->update('users', [
                'password'   => password_hash($password, PASSWORD_DEFAULT),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => (int) $user['id']]);
        } else {
            $staffUserId = generateUserId();
            $retry = 0;
            while ($retry < 5 && $db->has('users', ['user_id' => $staffUserId])) {
                $staffUserId = generateUserId(); $retry++;
            }
            $default_curr = $db->get('currencies', 'name', ['default' => 1]) ?: 'USD';
            $db->insert('users', [
                'user_id'        => $staffUserId,
                'first_name'     => $first_name,
                'last_name'      => $last_name,
                'email'          => $email,
                'currency'       => $default_curr,
                'password'       => password_hash($password, PASSWORD_DEFAULT),
                'role'           => 'customer',   // NEVER supplier/admin here
                'status'         => 'active',
                'banned'         => 0,
                'email_verified' => 1,
                'created_at'     => date('Y-m-d H:i:s'),
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);
        }

        // Consume the invite: link the staff user, activate, clear the token
        // (single-use). Scoped to this invite id.
        $db->update('supplier_staff', [
            'staff_user_id'  => $staffUserId,
            'status'         => 'active',
            'accepted_at'    => date('Y-m-d H:i:s'),
            'invite_token'   => null,
            'invite_expires' => null,
        ], ['id' => (int) $invite['id']]);

        $_SESSION['login_success'] = 'reset'; // reuse a generic success banner
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Invitation accepted. You can now sign in.'];
    } catch (\Throwable $e) {
        error_log('supplier staff accept: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not complete your account. Please contact the supplier.'];
        header('Location: ' . $back); exit;
    }

    header('Location: ' . root . 'login');
    exit;
});

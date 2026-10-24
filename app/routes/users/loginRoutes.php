<?php
// FILE: app/routes/users/login.php
// All login routes (GET & POST)

@$SECURE or die('Access Denied!');

// ==================================== 
// LOGIN ROUTES
// ====================================
$router->get('/login', function () use ($SECURE, $db) {
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        $redirectTo = (string)($_GET['redirect'] ?? ($_SESSION['login_redirect'] ?? ''));
        unset($_SESSION['login_redirect']);
        $rootHost = parse_url(root, PHP_URL_HOST);
        $redirHost = parse_url($redirectTo, PHP_URL_HOST);
        if ($redirectTo !== '' && $redirHost && $rootHost && strcasecmp((string)$redirHost, (string)$rootHost) === 0) {
            header('Location: ' . $redirectTo);
        } else {
            // Role-aware home: a supplier goes to their own area, not /dashboard (the
            // customer dashboard). /dashboard itself also re-routes suppliers as a backstop.
            $dest = (strtolower((string)($_SESSION['user_role'] ?? '')) === 'supplier') ? 'supplier/dashboard' : 'dashboard';
            header('Location: ' . root . $dest);
        }
        exit;
    }
    if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']) {
        header('Location: '.root.'admin/dashboard');
        exit;
    }

    // Remember Me — auto-login from cookie
    if (!empty($_COOKIE['remember_me'])) {
        global $env;
        $cookieRaw = base64_decode($_COOKIE['remember_me'], true);
        if ($cookieRaw !== false) {
            $parts = explode('|', $cookieRaw, 2);
            if (count($parts) === 2) {
                [$cookieUserId, $cookieHmac] = $parts;
                $cookieUserId = (int) $cookieUserId;
                $remembered = $db->get('users', '*', ['id' => $cookieUserId]);
                if ($remembered) {
                    // SECURITY (M4): prefer a dedicated REMEMBER_ME_SECRET so the
                    // cookie HMAC is not tied to the DB password (which would be
                    // exposed together with the data on a DB/.env leak). Falls
                    // back to the legacy derivation so cookies issued before this
                    // change still validate during the transition window.
                    $secret = trim((string)($env['REMEMBER_ME_SECRET'] ?? ''));
                    if ($secret === '') {
                        $secret = ($env['DB_PASSWORD'] ?? '') . ($env['DB_DATABASE'] ?? '');
                    }
                    $expected = hash_hmac('sha256', $cookieUserId . '|' . $remembered['email'] . '|' . $remembered['password'], $secret);
                    if (hash_equals($expected, $cookieHmac)
                        && $remembered['status'] === 'active'
                        && !$remembered['banned']
                        && $remembered['email_verified']) {
                        // SECURITY (M2): regenerate session id on auto-login too.
                        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
                            session_regenerate_id(true);
                        }
                        $_SESSION['user_id']    = $remembered['user_id'];
                        $_SESSION['user_email'] = $remembered['email'];
                        $_SESSION['user_name']  = $remembered['first_name'] . ' ' . $remembered['last_name'];
                        $_SESSION['user_role']  = $remembered['role'];
                        $_SESSION['login_time'] = time();
                        if ($remembered['role'] === 'admin') {
                            $_SESSION['admin_logged_in'] = true;
                            header('Location: ' . root . 'admin/dashboard');
                        } elseif (($remembered['role'] ?? '') === 'supplier') {
                            // Remember-me for a supplier must land in the supplier area —
                            // previously it fell to /dashboard (the customer dashboard),
                            // which is the reported "supplier sees normal user dashboard" bug.
                            header('Location: ' . root . 'supplier/dashboard');
                        } else {
                            header('Location: ' . root . 'dashboard');
                        }
                        exit;
                    }
                }
            }
        }
        // Invalid or expired cookie — clear it
        setcookie('remember_me', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
    }

    // ALWAYS LOAD LIC SO _lic_block() IS AVAILABLE TO THE LOGIN VIEW
    require_once __DIR__ . '/../../lib/lic.php';

    // RUN LICENSE CHECK ON EVERY LOGIN PAGE LOAD — CACHE MAKES REPEAT VISITS INSTANT
    // ONLY CALLS REMOTE API WHEN CACHE IS MISSING, EXPIRED OR CORRUPTED
    if (!isset($_SESSION['lic_err'])) {
        $_licR = _lic_check($db);
        if (!$_licR['ok']) {
            $_SESSION['lic_err'] = $_licR['msg'];
            $_SESSION['lic_key'] = $_licR['key'];
        }
    }

    // Optional return URL (e.g. back to AI invoice after header Login)
    $loginRedirect = (string)($_GET['redirect'] ?? '');
    if ($loginRedirect !== '') {
        $_SESSION['login_redirect'] = $loginRedirect;
    }

    $title = T::login;
    $description = T::signin_description;
    $header = true;
    $footer = true;
    // WHITE-LABEL: on a hotel's own branded host, render login inside the HOTEL's booking
    // chrome (its logo/nav/footer) instead of the GoGlobia platform header/footer, so the
    // auth page doesn't leak the platform brand onto the hotel's domain.
    $__wlChrome = function_exists('wl_booking_tenant') ? wl_booking_tenant($db) : false;
    if ($__wlChrome) {
        require views . "supplier/stays/site/_bookingchrome_head.php";
        require_once views . "auth/login.php";
        require views . "supplier/stays/site/_bookingchrome_foot.php";
    } else {
        require_once views."includes/header.php";
        require_once views."auth/login.php";
        require_once views."includes/footer.php";
    }
});

$router->post('/login', function () use ($SECURE, $db) {
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['login_error'] = 'csrf';
        header('Location: ' . root . 'login');
        exit;
    }

    // CHECK LICENSE BEFORE PROCESSING ANY CREDENTIALS — BLOCKS ALL USERS IF INVALID
    require_once __DIR__ . '/../../lib/lic.php';
    $_licR = _lic_check($db);
    if (!$_licR['ok']) {
        $s = $db->get('settings', '*');
        $_SESSION['lic_err'] = $_licR['msg'];
        $_SESSION['lic_key'] = trim((string) ($s['license_key'] ?? ''));
        header('Location: ' . root . 'login');
        exit;
    }

    // Identifier may be an email OR a phone number (same field). Keep the POST
    // key 'email' for form/back-compat; resolveLoginIdentifier() figures out which.
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if (empty($email) || empty($password)) {
        $_SESSION['login_error'] = 'empty';
        header('Location: ' . root . 'login');
        exit;
    }

    $resolved = resolveLoginIdentifier($db, $email);
    if ($resolved['reason'] === 'ambiguous_phone') {
        // That phone is on more than one account — can't tell which; use email.
        $_SESSION['login_error'] = 'ambiguous_phone';
        header('Location: ' . root . 'login');
        exit;
    }
    $user = $resolved['user'];
    if (!$user) {
        $_SESSION['login_error'] = 'invalid';
        header('Location: ' . root . 'login');
        exit;
    }

    $userId = (int)$user['id'];
    if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
        $_SESSION['login_error'] = 'locked';
        $_SESSION['lock_until'] = $user['locked_until'];
        header('Location: ' . root . 'login');
        exit;
    }

    if (password_verify($password, $user['password'])) {
        if (!$user['email_verified']) {
            $_SESSION['login_error'] = 'email_not_verified';
            $_SESSION['unverified_user_id'] = $user['id'];
            header('Location: ' . root . 'login');
            exit;
        }

        $db->update('users', [
            'login_attempts' => 0,
            'locked_until' => null,
            'last_login' => date('Y-m-d H:i:s')
        ], ['id' => $userId]);

        // Finalise login via the shared helper (status/ban gate + session keys +
        // remember-me + deferred webhook/log + role redirect). Same code path the
        // passwordless phone-OTP login uses, so the two can never drift.
        $fin = establish_login_session($db, $user, [
            'remember' => !empty($_POST['remember_me']),
            'redirect' => (string) ($_POST['redirect'] ?? ''),
            'method'   => 'password',
        ]);
        header('Location: ' . $fin['redirect']);
        exit;
    } else {
        $newAttempts = ($user['login_attempts'] ?? 0) + 1;
        $maxAttempts = 5;
        $lockDuration = 30 * 60;
        $updateData = ['login_attempts' => $newAttempts];

        if ($newAttempts >= $maxAttempts) {
            $lockUntil = date('Y-m-d H:i:s', time() + $lockDuration);
            $updateData['locked_until'] = $lockUntil;
            $_SESSION['login_error'] = 'locked';
            $_SESSION['lock_until'] = $lockUntil;

            logUserActivity($db, $userId, 'login_failed', 'Account locked due to too many failed attempts');
            
            // Trigger account locked webhook
            triggerWebhook('users/login', 'login.locked', [
                'user_id' => $user['user_id'],
                'email' => $email,
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'lock_reason' => 'Multiple failed login attempts',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } else {
            $_SESSION['login_error'] = 'invalid';
            $_SESSION['attempts_remaining'] = $maxAttempts - $newAttempts;

            logUserActivity($db, $userId, 'login_failed', 'Invalid password attempt');
            
            // Trigger failed login webhook
            triggerWebhook('users/login', 'login.failed', [
                'email' => $email,
                'reason' => 'invalid_password',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'timestamp' => date('Y-m-d H:i:s'),
                'attempts_count' => $newAttempts
            ]);
        }

        $db->update('users', $updateData, ['id' => $userId]);
        header('Location: ' . root . 'login');
        exit;
    }
});

// ====================================
// LICENSE KEY UPDATE (PUBLIC ROUTE)
// REQUIRES VALID ADMIN CREDENTIALS
// NO SESSION CREATED — ONLY UPDATES DB
// ====================================
// SECURITY: This route cannot require a logged-in admin SESSION, because an
// invalid license blocks all logins (see POST /login above) — so the recovery
// path must be usable while nobody is logged in. Instead it verifies the
// admin's email+password inline (the same credentials the license gate is
// refusing) BEFORE overwriting the key, and creates no session. This closes
// the previous gap where any visitor who could load /login could overwrite the
// license key with CSRF alone.
$router->post('/license-update', function () use ($SECURE, $db) {
    // VALIDATE CSRF TOKEN FIRST
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        header('Location: ' . root . 'login'); exit;
    }

    // VERIFY ADMIN CREDENTIALS — no session is required/created, but the caller
    // must prove possession of an active admin account.
    $adminEmail    = trim($_POST['admin_email'] ?? '');
    $adminPassword = (string)($_POST['admin_password'] ?? '');
    if ($adminEmail === '' || $adminPassword === '') {
        $_SESSION['lic_err'] = 'Admin email and password are required to update the license key.';
        header('Location: ' . root . 'login'); exit;
    }

    $admin = $db->get('users', '*', ['email' => $adminEmail]);
    // Account must exist, be an active admin, not banned, and not locked out.
    $isAdmin = $admin
        && ($admin['role'] ?? '') === 'admin'
        && ($admin['status'] ?? '') === 'active'
        && empty($admin['banned'])
        && !(($admin['locked_until'] ?? null) && strtotime((string)$admin['locked_until']) > time());
    if (!$isAdmin || !password_verify($adminPassword, (string)($admin['password'] ?? ''))) {
        // Uniform error — do not reveal which factor failed.
        $_SESSION['lic_err'] = 'Invalid admin credentials.';
        header('Location: ' . root . 'login'); exit;
    }

    $newKey = trim($_POST['lic_key'] ?? '');

    // LICENSE KEY MUST NOT BE EMPTY
    if (!$newKey) {
        $_SESSION['lic_err'] = 'License key cannot be empty.';
        header('Location: ' . root . 'login'); exit;
    }

    // UPDATE LICENSE KEY AND CLEAR ENCRYPTED CACHE — FORCES FRESH API CHECK ON NEXT LOGIN
    $settings = $db->get('settings', '*');
    $db->update('settings', [
        'license_key'    => $newKey,
        'license_secret' => null,
        'license_date'   => date('Y-m-d H:i:s'),
    ], ['id' => $settings['id']]);

    if (function_exists('logUserActivity')) {
        logUserActivity($db, (int)$admin['id'], 'license_update', 'License key updated via recovery form');
    }

    // REDIRECT TO LOGIN — GET ROUTE WILL RUN FRESH LICENSE CHECK
    $_SESSION['success'] = 'License key updated. Validating...';
    header('Location: ' . root . 'login'); exit;
});

// ====================================
// QUICK LOGIN API (For Booking Page)
// ====================================
$router->post('/api/booking/quick-login', function () use ($SECURE, $db) {
    $defaultRedirect = root;
    $redirectTo = (string)($_POST['redirect_to'] ?? $defaultRedirect);
    // Same-origin only (prevent open redirect)
    $rootHost = parse_url(root, PHP_URL_HOST);
    $redirHost = parse_url($redirectTo, PHP_URL_HOST);
    if ($redirectTo === '' || ($redirHost && $rootHost && strcasecmp((string)$redirHost, (string)$rootHost) !== 0)) {
        if (isset($redirectTo[0]) && $redirectTo[0] === '/' && strpos($redirectTo, '//') === false) {
            $redirectTo = rtrim((string)root, '/') . $redirectTo;
        } else {
            $redirectTo = $defaultRedirect;
        }
    }

    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['login_error'] = 'csrf';
        header('Location: ' . $redirectTo);
        exit;
    }

    // Identifier may be email OR phone (same field) — resolveLoginIdentifier decides.
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $_SESSION['login_error'] = 'empty';
        header('Location: ' . $redirectTo);
        exit;
    }

    $resolved = resolveLoginIdentifier($db, $email);
    if ($resolved['reason'] === 'ambiguous_phone') {
        $_SESSION['login_error'] = 'ambiguous_phone';
        header('Location: ' . $redirectTo);
        exit;
    }
    $user = $resolved['user'];
    if (!$user) {
        $_SESSION['login_error'] = 'invalid';
        header('Location: ' . $redirectTo);
        exit;
    }

    // BRUTE-FORCE LOCKOUT — quick-login previously did NO attempt tracking, so an
    // attacker could brute-force here while the main /login locked them out. Honour
    // and apply the same 5-attempt / 30-min lock as the web /login.
    $qlUserId = (int) $user['id'];
    if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        $_SESSION['login_error'] = 'locked';
        $_SESSION['lock_until'] = $user['locked_until'];
        header('Location: ' . $redirectTo);
        exit;
    }

    if (password_verify($password, $user['password'])) {
        if (!$user['email_verified']) {
            $_SESSION['login_error'] = 'email_not_verified';
            header('Location: ' . $redirectTo);
            exit;
        }

        if ($user['status'] !== 'active' || $user['banned']) {
            $_SESSION['login_error'] = 'invalid';
            header('Location: ' . $redirectTo);
            exit;
        }

        // Success - clear the failed-attempt counter + Set session
        $db->update('users', ['login_attempts' => 0, 'locked_until' => null], ['id' => $qlUserId]);
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['login_time'] = time();

        if ($user['role'] === 'admin') {
            $_SESSION['admin_logged_in'] = true;
        }

        header('Location: ' . $redirectTo);
        exit;
    } else {
        // Count the failed attempt; lock at the threshold (same policy as /login).
        $qlAttempts = (int) ($user['login_attempts'] ?? 0) + 1;
        $qlUpdate = ['login_attempts' => $qlAttempts];
        if ($qlAttempts >= 5) {
            $qlUpdate['locked_until'] = date('Y-m-d H:i:s', time() + 30 * 60);
            $_SESSION['login_error'] = 'locked';
            $_SESSION['lock_until'] = $qlUpdate['locked_until'];
        } else {
            $_SESSION['login_error'] = 'invalid';
        }
        $db->update('users', $qlUpdate, ['id' => $qlUserId]);
        header('Location: ' . $redirectTo);
        exit;
    }
});

// ============================================================================
// PASSWORDLESS PHONE-OTP LOGIN (GIA phone-auth workstream, Phase 3b)
// Flow: GET /login/phone (enter phone) -> POST /login/phone (send OTP) ->
//       enter the code on the same page -> POST /login/phone/verify (verify +
//       establish session via the shared establish_login_session()).
// Reuses the OTP engine (purpose 'login'), honours the license gate and the
// same lockout columns as the password login. Only accounts that HAVE a phone
// can use it; a shared number is rejected (ambiguous) exactly like 3a.
// ============================================================================
$router->get('/login/phone', function () use ($SECURE, $db) {
    if (!empty($_SESSION['user_id'])) { header('Location: ' . root . 'dashboard'); exit; }
    $title = T::login ?? 'Log in';
    $header = true; $footer = true;
    $phoneStep = $_SESSION['phone_login_step'] ?? 'enter';
    require_once views . "includes/header.php";
    require_once views . "auth/login-phone.php";
    require_once views . "includes/footer.php";
});

$router->post('/login/phone', function () use ($SECURE, $db) {
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['login_error'] = 'csrf'; header('Location: ' . root . 'login/phone'); exit;
    }
    // License gate — same as the password login (blocks all logins when invalid).
    require_once __DIR__ . '/../../lib/lic.php';
    $_licR = _lic_check($db);
    if (!$_licR['ok']) {
        $s = $db->get('settings', '*');
        $_SESSION['lic_err'] = $_licR['msg'];
        $_SESSION['lic_key'] = trim((string) ($s['license_key'] ?? ''));
        header('Location: ' . root . 'login'); exit;
    }
    $identifier = trim($_POST['phone'] ?? '');
    if ($identifier === '') { $_SESSION['login_error'] = 'empty'; header('Location: ' . root . 'login/phone'); exit; }

    $resolved = resolveLoginIdentifier($db, $identifier);
    if ($resolved['reason'] === 'ambiguous_phone') {
        $_SESSION['login_error'] = 'ambiguous_phone'; header('Location: ' . root . 'login/phone'); exit;
    }
    $user = $resolved['user'];
    // Do NOT reveal whether the number exists — always show the code step. Only
    // actually send an OTP when we have a user with a phone.
    if ($user && trim((string) ($user['phone'] ?? '')) !== '') {
        if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            $_SESSION['login_error'] = 'locked'; $_SESSION['lock_until'] = $user['locked_until'];
            header('Location: ' . root . 'login/phone'); exit;
        }
        $res = otp_issue($db, (string) $user['user_id'], 'login');
        if (!empty($res['ok'])) {
            sendOtpWhatsApp($db, $user['phone'], $user['phone_country_code'] ?? '', $res['code'], $user['first_name'] ?? '');
        }
        // Remember who we're verifying (by user_id) — never trust the client for this.
        $_SESSION['phone_login_user'] = (string) $user['user_id'];
    }
    $_SESSION['phone_login_step'] = 'code';
    $_SESSION['phone_login_identifier'] = $identifier;
    $_SESSION['verify_phone_success'] = 'If that number has an account, we sent a login code by WhatsApp.';
    header('Location: ' . root . 'login/phone'); exit;
});

$router->post('/login/phone/verify', function () use ($SECURE, $db) {
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['login_error'] = 'csrf'; header('Location: ' . root . 'login/phone'); exit;
    }
    require_once __DIR__ . '/../../lib/lic.php';
    $_licR = _lic_check($db);
    if (!$_licR['ok']) { header('Location: ' . root . 'login'); exit; }

    $uid  = (string) ($_SESSION['phone_login_user'] ?? '');
    $code = trim($_POST['otp'] ?? '');
    if ($uid === '') {
        // No pending phone login — start over (don't leak anything).
        $_SESSION['login_error'] = 'invalid'; header('Location: ' . root . 'login/phone'); exit;
    }
    $res = otp_verify($db, $uid, 'login', $code);
    if (empty($res['ok'])) {
        $_SESSION['login_error'] = 'invalid';
        $_SESSION['verify_phone_error'] = $res['message'] ?? 'Incorrect or expired code.';
        header('Location: ' . root . 'login/phone'); exit;
    }
    // OTP proves phone ownership. Load the full user and establish the session
    // through the SAME finaliser the password login uses.
    $user = $db->get('users', '*', ['user_id' => $uid]);
    if (!$user) { $_SESSION['login_error'] = 'invalid'; header('Location: ' . root . 'login/phone'); exit; }

    unset($_SESSION['phone_login_user'], $_SESSION['phone_login_step'], $_SESSION['phone_login_identifier']);
    $fin = establish_login_session($db, $user, [
        'remember' => !empty($_POST['remember_me']),
        'redirect' => (string) ($_POST['redirect'] ?? ''),
        'method'   => 'phone_otp',
    ]);
    header('Location: ' . $fin['redirect']); exit;
});

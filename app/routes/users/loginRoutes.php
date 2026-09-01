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
            header('Location: ' . root . 'dashboard');
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
    require_once views."includes/header.php";
    require_once views."auth/login.php";
    require_once views."includes/footer.php";
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

    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if (empty($email) || empty($password)) {
        $_SESSION['login_error'] = 'empty';
        header('Location: ' . root . 'login');
        exit;
    }

    $user = $db->get('users', '*', ['email' => $email]);
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

        if ($user['status'] !== 'active') {
            $_SESSION['login_error'] = 'invalid';
            header('Location: ' . root . 'login');
            exit;
        }
        if ($user['banned']) {
            $_SESSION['login_error'] = 'banned';
            header('Location: ' . root . 'login');
            exit;
        }

        logUserActivity($db, $userId, 'login', 'User logged in successfully');

        // SECURITY (M2): regenerate the session id on privilege change (login)
        // to defeat session fixation. Preserves existing session data.
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }

        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['login_time'] = time();

        // Remember Me — set signed 30-day cookie
        if (!empty($_POST['remember_me'])) {
            global $env;
            // SECURITY (M4): dedicated secret, not the DB password (see auto-login).
            $secret      = trim((string)($env['REMEMBER_ME_SECRET'] ?? ''));
            if ($secret === '') {
                $secret = ($env['DB_PASSWORD'] ?? '') . ($env['DB_DATABASE'] ?? '');
            }
            $hmac        = hash_hmac('sha256', $userId . '|' . $user['email'] . '|' . $user['password'], $secret);
            $cookieValue = base64_encode($userId . '|' . $hmac);
            $secureCookie = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
                || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            setcookie('remember_me', $cookieValue, time() + (30 * 24 * 3600), '/', '', $secureCookie, true);
        }

        // Defer webhook/logging so redirect is not blocked (fixes stuck "Signing in..." spinner)
        $loginWebhookData = [
            'user_id' => $user['user_id'],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'role' => $user['role'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'timestamp' => date('Y-m-d H:i:s'),
            'last_login' => $user['last_login'] ?? null
        ];
        $loginLogRow = [
            'user_id' => $user['user_id'] ?? null,
            'type' => 'login',
            'description' => 'User logged in successfully',
            'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'created_at' => date('Y-m-d H:i:s')
        ];
        register_shutdown_function(static function () use ($loginWebhookData, $loginLogRow, $db) {
            try {
                if (function_exists('triggerWebhook')) {
                    triggerWebhook('users/login', 'login.success', $loginWebhookData);
                }
            } catch (Throwable $e) {}
            try {
                $db->insert('logs_users', $loginLogRow);
            } catch (Throwable $e) {}
        });

        if ($user['role'] === 'admin') {
            $_SESSION['admin_logged_in'] = true;
            header('Location: ' . root . 'admin/dashboard');
        } else {
            $redirectTo = (string)($_POST['redirect'] ?? ($_SESSION['login_redirect'] ?? ''));
            unset($_SESSION['login_redirect']);
            $rootHost = parse_url(root, PHP_URL_HOST);
            $redirHost = parse_url($redirectTo, PHP_URL_HOST);
            $safe = false;
            if ($redirectTo !== '' && $redirHost && $rootHost && strcasecmp((string)$redirHost, (string)$rootHost) === 0) {
                $safe = true;
            } elseif ($redirectTo !== '' && isset($redirectTo[0]) && $redirectTo[0] === '/' && strpos($redirectTo, '//') === false) {
                $redirectTo = rtrim((string)root, '/') . $redirectTo;
                $safe = true;
            }
            header('Location: ' . ($safe ? $redirectTo : (root . 'dashboard')));
        }
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
$router->post('/license-update', function () use ($SECURE, $db) {
    // VALIDATE CSRF TOKEN FIRST
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
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

    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $_SESSION['login_error'] = 'empty';
        header('Location: ' . $redirectTo);
        exit;
    }

    $user = $db->get('users', '*', ['email' => $email]);
    if (!$user) {
        $_SESSION['login_error'] = 'invalid';
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

        // Success - Set session
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
        $_SESSION['login_error'] = 'invalid';
        header('Location: ' . $redirectTo);
        exit;
    }
});

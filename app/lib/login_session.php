<?php
// app/lib/login_session.php
//
// Shared customer/admin/supplier login finaliser. Extracted from the POST
// /login success path (app/routes/users/loginRoutes.php) so the password login
// and the passwordless phone-OTP login (GIA phone-auth workstream, Phase 3b)
// establish the session IDENTICALLY instead of duplicating ~90 lines.
//
// This covers everything AFTER identity has been proven (password_verify OR a
// verified OTP) and AFTER the caller has handled brute-force lockout counting:
//   - active/pending-supplier/rejected/banned status gate
//   - session fixation defence (regenerate id)
//   - the canonical session keys (user_id/user_email/user_name/user_role/login_time)
//   - optional signed remember-me cookie
//   - deferred login webhook + logs_users row
//   - role-based redirect (admin/supplier/staff/customer) with safe-redirect guard
//
// It returns the redirect TARGET (string) and sets any login_error itself when a
// gate blocks; the caller just does header('Location: '.$target); exit;. This
// keeps all header()/exit control flow in the route, not buried in a helper.

if (!function_exists('establish_login_session')) {
    /**
     * Finalise a login once identity is proven. Sets session + cookies + logs,
     * applies the status/ban gate, and returns the URL to redirect to.
     *
     * @param object $db
     * @param array  $user  Full users row (must include user_id, email,
     *                       first_name, last_name, role, status, banned, password).
     * @param array  $opts  [
     *     'remember'  => bool    // set the 30-day remember-me cookie
     *     'redirect'  => string  // requested post-login redirect (customer only)
     *     'method'    => string  // 'password' | 'phone_otp' (for logging)
     *   ]
     * @return array{ok:bool, redirect:string}
     *   ok=false means a gate blocked login; a login_error session key is set and
     *   'redirect' points back to the login screen.
     */
    function establish_login_session($db, array $user, array $opts = []): array
    {
        $userId   = (int) $user['id'];
        $remember = !empty($opts['remember']);
        $method   = (string) ($opts['method'] ?? 'password');

        // --- Status / ban gate (identical to the password path) ---
        $pendingSupplierOk = (($user['role'] ?? '') === 'supplier' && $user['status'] === 'pending');
        if ($user['status'] !== 'active' && !$pendingSupplierOk) {
            if (($user['role'] ?? '') === 'supplier' && $user['status'] === 'rejected') {
                $_SESSION['login_error'] = 'supplier_rejected';
                $_SESSION['supplier_rejected_reason'] = (string) ($user['supplier_rejected_reason'] ?? '');
            } else {
                $_SESSION['login_error'] = 'invalid';
            }
            return ['ok' => false, 'redirect' => root . 'login'];
        }
        if ($pendingSupplierOk) {
            $_SESSION['supplier_approval_pending'] = true;
        }
        if (!empty($user['banned'])) {
            $_SESSION['login_error'] = 'banned';
            return ['ok' => false, 'redirect' => root . 'login'];
        }

        if (function_exists('logUserActivity')) {
            logUserActivity($db, $userId, 'login', 'User logged in successfully (' . $method . ')');
        }

        // SECURITY (M2): regenerate session id on login to defeat fixation.
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }

        $_SESSION['user_id']    = $user['user_id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name']  = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['user_role']  = $user['role'];
        $_SESSION['login_time'] = time();

        // Remember-me — signed 30-day cookie (same scheme as the auto-login reader).
        if ($remember) {
            global $env;
            $secret = trim((string) ($env['REMEMBER_ME_SECRET'] ?? ''));
            if ($secret === '') {
                $secret = ($env['DB_PASSWORD'] ?? '') . ($env['DB_DATABASE'] ?? '');
            }
            $hmac        = hash_hmac('sha256', $user['user_id'] . '|' . $user['email'] . '|' . $user['password'], $secret);
            $cookieValue = base64_encode($user['user_id'] . '|' . $hmac);
            $secureCookie = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
                || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            setcookie('remember_me', $cookieValue, time() + (30 * 24 * 3600), '/', '', $secureCookie, true);
        }

        // Defer webhook + activity log so the redirect isn't blocked.
        $webhookData = [
            'user_id'    => $user['user_id'],
            'email'      => $user['email'],
            'first_name' => $user['first_name'],
            'last_name'  => $user['last_name'],
            'role'       => $user['role'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'timestamp'  => date('Y-m-d H:i:s'),
            'last_login' => $user['last_login'] ?? null,
            'method'     => $method,
        ];
        $logRow = [
            'user_id'     => $user['user_id'] ?? null,
            'type'        => 'login',
            'description' => 'User logged in successfully (' . $method . ')',
            'user_ip'     => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'created_at'  => date('Y-m-d H:i:s'),
        ];
        register_shutdown_function(static function () use ($webhookData, $logRow, $db) {
            try {
                if (function_exists('triggerWebhook')) {
                    triggerWebhook('users/login', 'login.success', $webhookData);
                }
            } catch (\Throwable $e) {
            }
            try {
                $db->insert('logs_users', $logRow);
            } catch (\Throwable $e) {
            }
        });

        // --- Role-based redirect ---
        if ($user['role'] === 'admin') {
            $_SESSION['admin_logged_in'] = true;
            return ['ok' => true, 'redirect' => root . 'admin/dashboard'];
        }
        if ($user['role'] === 'supplier') {
            return ['ok' => true, 'redirect' => root . 'supplier/dashboard'];
        }
        // Supplier staff (ordinary user linked via supplier_staff) → supplier area.
        try {
            if ($db->has('supplier_staff', ['staff_user_id' => (string) $user['user_id'], 'status' => 'active'])) {
                return ['ok' => true, 'redirect' => root . 'supplier/dashboard'];
            }
        } catch (\Throwable $e) {
        }

        // Customer — honour a same-origin / relative redirect, else the dashboard.
        $redirectTo = (string) ($opts['redirect'] ?? ($_SESSION['login_redirect'] ?? ''));
        unset($_SESSION['login_redirect']);
        $rootHost  = parse_url(root, PHP_URL_HOST);
        $redirHost = $redirectTo !== '' ? parse_url($redirectTo, PHP_URL_HOST) : null;
        $safe = false;
        if ($redirectTo !== '' && $redirHost && $rootHost && strcasecmp((string) $redirHost, (string) $rootHost) === 0) {
            $safe = true;
        } elseif ($redirectTo !== '' && isset($redirectTo[0]) && $redirectTo[0] === '/' && strpos($redirectTo, '//') === false) {
            $redirectTo = rtrim((string) root, '/') . $redirectTo;
            $safe = true;
        }
        return ['ok' => true, 'redirect' => $safe ? $redirectTo : (root . 'dashboard')];
    }
}

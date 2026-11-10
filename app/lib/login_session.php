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

if (!function_exists('claimGuestBookingsForUser')) {
    /**
     * Durably attach the guest bookings made in THIS session to the user who just
     * logged in (D2 gap fix). A guest checkout leaves bookings.user_id = NULL and
     * only reachable via $_SESSION['owned_invoices']; that reachability dies with
     * the session (new device/after logout). On login we re-parent every owned,
     * still-unowned invoice to this user so "attach the booking to the account"
     * becomes durable, not session-scoped. Safe + idempotent: it only ever claims
     * invoices THIS session already owned, and only rows whose user_id is empty.
     *
     * @param object $db
     * @param array  $user  the users row (needs user_id, id, email, first/last name)
     * @return int  number of bookings claimed
     */
    function claimGuestBookingsForUser($db, array $user): int
    {
        $owned = $_SESSION['owned_invoices'] ?? null;
        if (!is_array($owned) || empty($owned)) { return 0; }

        $userId = (string) ($user['user_id'] ?? '');
        if ($userId === '') { return 0; }

        // Minimal owner stamp mirrored into user_data (same keys the booking
        // routes write), so the invoice shows the owner even offline.
        $userData = json_encode([
            'id'         => $user['user_id'] ?? null,
            'email'      => $user['email'] ?? '',
            'first_name' => $user['first_name'] ?? '',
            'last_name'  => $user['last_name'] ?? '',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $claimed = 0;
        foreach (array_unique($owned) as $inv) {
            $inv = (string) $inv;
            if ($inv === '') { continue; }
            try {
                // Read the current owner first, then claim ONLY if still unowned
                // (guest NULL/empty). This never steals a booking already owned by
                // another account, and is idempotent (re-login = no-op).
                $row = $db->get('bookings', ['user_id'], ['invoice_id' => $inv]);
                if (!$row) { continue; }
                $currentOwner = trim((string) ($row['user_id'] ?? ''));
                if ($currentOwner !== '') { continue; } // already owned — leave it

                $db->update('bookings', [
                    'user_id'   => $userId,
                    'user_data' => $userData,
                ], ['invoice_id' => $inv]);
                $claimed++;
            } catch (\Throwable $e) {
                error_log('claimGuestBookingsForUser: ' . $e->getMessage());
            }
        }
        return $claimed;
    }
}

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
        // SCHEME CONSISTENCY (auth audit finding #1): the live gate everywhere is
        // $_SESSION['user_id'], but the auth class (auth::isLoggedIn) and
        // checkUser() key on 'user_logged_in', which this finaliser never set — so
        // those (currently unused) gates would wrongly treat a logged-in customer
        // as logged-out. Set it here too so BOTH schemes agree and any future route
        // using checkUser()/auth::isLoggedIn() behaves correctly. (logout clears it
        // via session_destroy.)
        $_SESSION['user_logged_in'] = true;

        // D2 gap fix: durably attach any guest bookings made in this session to the
        // user now logging in (re-parents bookings.user_id, not just owned_invoices).
        if (function_exists('claimGuestBookingsForUser')) {
            claimGuestBookingsForUser($db, $user);
        }

        // Remember-me — signed 30-day cookie (same scheme as the auto-login reader).
        if ($remember) {
            global $env;
            $secret = trim((string) ($env['REMEMBER_ME_SECRET'] ?? ''));
            if ($secret === '') {
                $secret = ($env['DB_PASSWORD'] ?? '') . ($env['DB_DATABASE'] ?? '');
            }
            // auth fresh-audit MEDIUM: embed a server-side issue timestamp in the
            // SIGNED payload so a disclosed cookie expires server-side after 30 days
            // (the reader enforces it) instead of replaying until the password
            // changes. Form: id|issuedAt|hmac(id|issuedAt|email|password).
            //
            // Use the INTEGER id ($userId), not the varchar user_id hash: the reader
            // casts the first field to (int) and looks the row up by `id`. The old
            // code wrote the varchar user_id here, which cast to (int)0 on read, so
            // remember-me auto-login silently NEVER matched a user. Writing the int
            // id both fixes that latent bug and binds the HMAC to the looked-up key.
            $issuedAt    = time();
            $hmac        = hash_hmac('sha256', $userId . '|' . $issuedAt . '|' . $user['email'] . '|' . $user['password'], $secret);
            $cookieValue = base64_encode($userId . '|' . $issuedAt . '|' . $hmac);
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
        // Travel agent → the dedicated agent portal (NOT the customer dashboard).
        if ($user['role'] === 'agent') {
            return ['ok' => true, 'redirect' => root . 'agent/dashboard'];
        }
        // Supplier staff (ordinary user linked via supplier_staff) → supplier area.
        try {
            if ($db->has('supplier_staff', ['staff_user_id' => (string) $user['user_id'], 'status' => 'active'])) {
                return ['ok' => true, 'redirect' => root . 'supplier/dashboard'];
            }
        } catch (\Throwable $e) {
        }
        // Agency staff (ordinary user linked via agent_staff) → agent portal.
        try {
            if ($db->has('agent_staff', ['staff_user_id' => (string) $user['user_id'], 'status' => 'active'])) {
                return ['ok' => true, 'redirect' => root . 'agent/dashboard'];
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

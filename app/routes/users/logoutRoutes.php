<?php
// FILE: app/routes/users/logout.php
// Logout route

@$SECURE or die('Access Denied!');

// ====================================
// LOGOUT ROUTE
// ====================================

$router->get('/logout', function () use ($SECURE,$db) {
    // Check if user is logged in
    if (isset($_SESSION['user_id']) || isset($_SESSION['admin_logged_in'])) {
        // LOGOUT-CSRF GUARD (auth audit #4 + re-audit regression fix).
        // /logout is a GET state-changing action, so a cross-site
        // <img src=".../logout"> could force-log-out a victim. BUT a pure
        // CSRF-token-on-GET requirement broke legitimate logout on a stale tab
        // (the rendered ?t= token expires after 1h, csrf.php) or after the single
        // global token rotated in another tab — the user would be unable to log
        // out. The robust defence is SAME-SITE verification (which a real logout
        // navigation always satisfies and a cross-site <img>/form never can),
        // with the CSRF token as an accepted bonus:
        //   ALLOW if  Sec-Fetch-Site ∈ {same-origin, same-site, none}  (none = a
        //             direct top-level navigation / typed URL, not cross-site),
        //        OR   Origin/Referer host == our host,
        //        OR   a valid CSRF token is present.
        //   BLOCK only a clearly cross-site request with no valid token.
        $okLogout = false;
        $sfs = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
        if ($sfs !== '') {
            // Modern browsers: a same-site logout link/nav sends same-origin/same-site;
            // a cross-site <img>/form sends 'cross-site'. 'none' = user-initiated nav.
            $okLogout = in_array($sfs, ['same-origin', 'same-site', 'none'], true);
        } else {
            // Older browsers (no Sec-Fetch-Site): compare Origin/Referer host to ours.
            $rootHost = strtolower((string) parse_url(root, PHP_URL_HOST));
            $srcUrl   = (string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
            if ($srcUrl === '') {
                // No Sec-Fetch-Site AND no Origin/Referer at all. This is NOT proof of
                // a same-site nav — a cross-site top-level <a rel=noreferrer> on a
                // legacy browser (no Sec-Fetch-Site) sends neither header yet still
                // carries the Lax session cookie → forced-logout CSRF. Do NOT allow
                // here; fall through to the CSRF-token requirement below. Our own
                // logout links always carry ?t=<token>, so a real same-site logout
                // still succeeds; a header-stripped cross-site nav does not.
                $okLogout = false;
            } else {
                $srcHost = strtolower((string) parse_url($srcUrl, PHP_URL_HOST));
                $okLogout = ($srcHost !== '' && $rootHost !== '' && $srcHost === $rootHost);
            }
        }
        // CSRF token (from ?t= link or header/POST) is always an acceptable proof too.
        if (!$okLogout && class_exists('CSRF')) {
            $logoutToken = (string) ($_GET['t'] ?? '');
            if ($logoutToken === '') { $logoutToken = CSRF::tokenFromRequest(); }
            $okLogout = CSRF::validateToken($logoutToken);
        }
        if (!$okLogout) {
            // Clearly cross-site with no valid token → refuse (don't destroy session).
            header('Location: ' . root . (($_SESSION['user_role'] ?? '') === 'admin' ? 'admin/dashboard' : 'dashboard'));
            exit;
        }

        // Calculate session duration
        $sessionDuration = isset($_SESSION['login_time']) ? (time() - $_SESSION['login_time']) : 0;
        
        // Get user data before destroying session
        $user_id = $_SESSION['user_id'] ?? null;
        $user_email = $_SESSION['user_email'] ?? null;
        $user_name = $_SESSION['user_name'] ?? '';
        $name_parts = explode(' ', $user_name, 2);
        
        // Trigger logout webhook
        if ($user_id) {
            triggerWebhook('users/logout', 'logout.success', [
                'user_id' => $user_id,
                'email' => $user_email,
                'first_name' => $name_parts[0] ?? '',
                'last_name' => $name_parts[1] ?? '',
                'session_duration' => $sessionDuration,
                'login_time' => isset($_SESSION['login_time']) ? date('Y-m-d H:i:s', $_SESSION['login_time']) : null,
                'logout_time' => date('Y-m-d H:i:s'),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'reason' => 'user_initiated'
            ]);
        }
        
        // Set logout success message
        $_SESSION['login_success'] = 'logout';

        // LOG RECORDS
        $db->insert('logs_users', [
            'user_id' => $_SESSION['user_id'] ?? null,
            'type' => 'logout',
            'description' => 'User logged out successfully',
            'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Destroy all session data
        session_destroy();

        // Start new session for the success message
        session_start();
        // Session-fixation defence (auth audit finding #4): after destroy+start PHP
        // reuses the SAME session id; regenerate so the post-logout session gets a
        // fresh id (mirrors auth::logout()).
        if (!headers_sent()) { session_regenerate_id(true); }
        $_SESSION['login_success'] = 'logout';
        $_SESSION['theme'] = 'default';
    }

    // Clear Remember Me cookie
    setcookie('remember_me', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);

    // Redirect to login page
    header('Location: ' . root . 'login');
    exit;
});

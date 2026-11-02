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
        // CSRF (auth audit finding #4): /logout is a GET state-changing action. A
        // cross-site <img src=".../logout"> would otherwise force-log-out a victim
        // (logout-CSRF). Require the session CSRF token — the site's own logout
        // links carry ?t=<token> (see header.php / sidebar.php). Accept it from the
        // ?t query param OR the standard transports (X-CSRF-TOKEN header / POST).
        // Only enforced for an authenticated session; a tokenless logout of a guest
        // is a harmless no-op anyway.
        $logoutToken = (string) ($_GET['t'] ?? '');
        if ($logoutToken === '' && class_exists('CSRF')) { $logoutToken = CSRF::tokenFromRequest(); }
        if (!class_exists('CSRF') || !CSRF::validateToken($logoutToken)) {
            // Invalid/absent token → do NOT destroy the session. Bounce to the
            // dashboard (logged-in) so a forged request can't log the user out.
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

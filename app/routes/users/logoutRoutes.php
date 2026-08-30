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
        $_SESSION['login_success'] = 'logout';
        $_SESSION['theme'] = 'default';
    }

    // Clear Remember Me cookie
    setcookie('remember_me', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);

    // Redirect to login page
    header('Location: ' . root . 'login');
    exit;
});

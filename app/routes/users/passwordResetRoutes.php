<?php
// FILE: app/routes/users/password-reset.php
// Forgot password and reset password routes

@$SECURE or die('Access Denied!');

// ==================================== 
// FORGOT PASSWORD ROUTES
// ====================================
$router->get('/forgot-password', function () use ($SECURE, $db) {
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        header('Location: ' . root . 'dashboard');
        exit;
    }
    $title = T::forgot_password;
    $description = T::reset_instructions;
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once views."auth/forgot-password.php";
    require_once views."includes/footer.php";
});

$router->post('/forgot-password', function () use ($SECURE, $db) {
    $token = $_POST['csrf_token'] ?? '';
    if (!CSRF::validateToken($token)) {
        $_SESSION['error'] = T::error_csrf_token;
        header('Location: ' . root . 'forgot-password');
        exit;
    }

    $email = trim($_POST['email'] ?? '');
    $errors = [];
    if (empty($email)) {
        $errors[] = T::error_empty_fields;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = T::error_invalid_email;
    }

    if (!empty($errors)) {
        $_SESSION['error'] = implode('<br>', $errors);
        $_SESSION['form_data'] = ['email' => $email];
        header('Location: ' . root . 'forgot-password');
        exit;
    }

    // ENUMERATION-SAFE (auth audit finding #2): ALWAYS answer with the SAME
    // neutral message whether or not an account exists — the old distinct
    // T::error_email_not_found let an attacker enumerate registered emails. The
    // real send only happens for a valid active account; the response never
    // reveals the outcome. Mirrors the already-hardened resend-verification route.
    unset($_SESSION['form_data']);
    $neutral = function () {
        $_SESSION['success'] = T::success_reset_email_sent
            ?? 'If an account exists for that email, we\'ve sent a password-reset link.';
        header('Location: ' . root . 'forgot-password');
        exit;
    };

    try {
        $user = $db->get('users', ['id', 'user_id', 'first_name', 'last_name', 'email', 'phone', 'phone_country_code', 'reset_token_expires'], [
            'email' => $email,
            'status' => 'active'
        ]);

        // No account (or inactive) → neutral success, no work, no leak.
        if (!$user) {
            $neutral();
        }

        // RATE-LIMIT (auth audit finding #2): if a reset token was issued in the
        // last 5 minutes, don't reissue / re-email (anti mail-bomb + anti
        // token-churn). Still answer neutrally. Token lives 1h, so "issued within
        // 5 min" == expires is within (3600 - 300) of now.
        $lastExpires = strtotime((string) ($user['reset_token_expires'] ?? '')) ?: 0;
        if ($lastExpires > 0 && ($lastExpires - time()) > (3600 - 300)) {
            $neutral();
        }

        logUserActivity($db, $user['id'], 'forgot_password', 'Password reset requested');

        triggerWebhook('users/password-reset', 'password.reset_requested', [
            'user_id' => $user['user_id'] ?? null,
            'email' => $email,
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        $resetToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);
        $db->update('users', [
            'reset_token' => $resetToken,
            'reset_token_expires' => $expiresAt
        ], ['id' => $user['id']]);

        sendNotification($user['id'], 'forgot_password', []);

        // Uniform neutral success regardless of which channel fired (no enumeration).
        $neutral();
    } catch (Exception $e) {
        error_log('[forgot-password] ' . $e->getMessage());
        // Even on error, stay neutral (do not reveal processing differences).
        $neutral();
    }
});

// ==================================== 
// RESET PASSWORD ROUTES
// ====================================
$router->get('/reset-password', function () use ($SECURE, $db) {
    $token = $_GET['token'] ?? '';
    if (empty($token)) {
        $_SESSION['error'] = T::error_token_missing;
        header('Location: ' . root . 'forgot-password');
        exit;
    }

    $user = $db->get('users', '*', [
        'reset_token' => $token,
        'reset_token_expires[>]' => date('Y-m-d H:i:s')
    ]);

    if (!$user) {
        $_SESSION['error'] = T::error_token_invalid;
        header('Location: ' . root . 'forgot-password');
        exit;
    }

    $title = T::reset_password;
    $description = T::set_new_password;
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once views."auth/reset-password.php";
    require_once views."includes/footer.php";
});

$router->post('/reset-password', function () use ($SECURE, $db) {
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = T::error_csrf_token;
        header('Location: ' . root . 'forgot-password');
        exit;
    }

    $token = $_POST['token'] ?? '';
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    if (empty($token) || empty($password) || empty($confirm_password)) {
        $_SESSION['error'] = T::error_empty_fields;
        header('Location: ' . root . 'reset-password?token=' . urlencode($token));
        exit;
    }
    if ($password !== $confirm_password) {
        $_SESSION['error'] = T::error_password_mismatch;
        header('Location: ' . root . 'reset-password?token=' . urlencode($token));
        exit;
    }
    if (strlen($password) < 8) { // auth audit #5: raised 6 -> 8 minimum
        $_SESSION['error'] = T::error_password_weak;
        header('Location: ' . root . 'reset-password?token=' . urlencode($token));
        exit;
    }

    $user = $db->get('users', '*', [
        'reset_token' => $token,
        'reset_token_expires[>]' => date('Y-m-d H:i:s')
    ]);

    if (!$user) {
        $_SESSION['error'] = T::error_token_invalid;
        header('Location: ' . root . 'forgot-password');
        exit;
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    try {
        $db->update('users', [
            'password' => $hashed_password,
            'reset_token' => null,
            'reset_token_expires' => null,
            'login_attempts' => 0,
            'locked_until' => null,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $user['id']]);

        logUserActivity($db, $user['id'], 'password_reset', 'Password successfully reset');

        // Trigger password reset completed webhook
        triggerWebhook('users/password-reset', 'password.reset_completed', [
            'user_id' => $user['user_id'],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        sendNotification($user['id'], 'password_reset', []);

        $_SESSION['login_success'] = 'reset';
        header('Location: ' . root . 'login');
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = T::error_password_reset_failed;
        header('Location: ' . root . 'forgot-password');
        exit;
    }
});

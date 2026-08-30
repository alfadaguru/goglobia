<?php
// FILE: app/routes/users/email-verification.php
// Email verification and resend routes

@$SECURE or die('Access Denied!');

// ==================================== 
// EMAIL VERIFICATION ROUTES
// ====================================
$router->get('/verify-email', function () use ($SECURE, $db) {
    $token = $_GET['token'] ?? '';
    if (empty($token)) {
        $_SESSION['error'] = T::error_token_missing;
        header('Location: ' . root . 'login');
        exit;
    }

    $user = $db->get('users', '*', [
        'reset_token' => $token,
        'reset_token_expires[>]' => date('Y-m-d H:i:s')
    ]);

    if (!$user) {
        $_SESSION['error'] = T::error_token_invalid;
        header('Location: ' . root . 'login');
        exit;
    }

    try {
        $db->update('users', [
            'email_verified' => 1,
            'status' => 'active',
            'reset_token' => null,
            'reset_token_expires' => null,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $user['id']]);

        logUserActivity($db, $user['id'], 'email_verified', 'Email address verified successfully');

        // Trigger email verification webhook
        $signupTime = strtotime($user['created_at'] ?? 'now');
        $verifyTime = time();
        triggerWebhook('users/email-verification', 'email.verified', [
            'user_id' => $user['user_id'],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'timestamp' => date('Y-m-d H:i:s'),
            'time_to_verify' => $verifyTime - $signupTime
        ]);

        sendNotification($user['id'], 'email_verified', []);

        $_SESSION['login_success'] = 'verified';
        header('Location: ' . root . 'login');
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = T::error_email_verification_failed;
        header('Location: ' . root . 'login');
        exit;
    }
});

// ==================================== 
// RESEND VERIFICATION ROUTES
// ====================================
$router->post('/resend-verification', function () use ($SECURE, $db) {
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = T::error_csrf_token;
        header('Location: ' . root . 'login');
        exit;
    }

    $userId = $_POST['user_id'] ?? 0;
    if (!$userId) {
        $_SESSION['error'] = T::error_invalid_request;
        header('Location: ' . root . 'login');
        exit;
    }

    $user = $db->get('users', '*', ['id' => $userId]);
    if (!$user) {
        $_SESSION['error'] = T::error_user_not_found;
        header('Location: ' . root . 'login');
        exit;
    }
    if ($user['email_verified']) {
        $_SESSION['error'] = T::error_email_already_verified;
        header('Location: ' . root . 'login');
        exit;
    }

    $verificationToken = bin2hex(random_bytes(32));
    try {
        $db->update('users', [
            'reset_token' => $verificationToken,
            'reset_token_expires' => date('Y-m-d H:i:s', time() + (24 * 3600)),
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $userId]);

        logUserActivity($db, $userId, 'resend_verification', 'Verification email resent');

        // Trigger resend verification webhook
        triggerWebhook('users/email-verification', 'email.verification_resent', [
            'user_id' => $user['user_id'],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        $notificationResults = sendNotification($userId, 'resend_verification', []);

        $emailSent = $notificationResults['email'] ?? false;
        $whatsappSent = $notificationResults['whatsapp'] ?? false;
        $smsSent = $notificationResults['sms'] ?? false;

        if ($emailSent || $whatsappSent || $smsSent) {
            $_SESSION['success'] = T::success_verification_email_sent;
        } else {
            $_SESSION['success'] = T::success_verification_no_email;
        }
    } catch (Exception $e) {
        $_SESSION['error'] = T::error_resend_verification_failed;
    }
    header('Location: ' . root . 'login');
    exit;
});

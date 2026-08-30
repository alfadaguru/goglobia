<?php
// FILE: app/routes/api/users/password-reset.php
// Users API password reset endpoint

@$SECURE or die('Access Denied!');

// ==================================== 
// FORGOT PASSWORD
// ====================================

$router->post('/api/forgot-password', function () use ($SECURE, $db) {

    header('Content-Type: application/json');

    $data = json_decode(file_get_contents('php://input'), true);

    $email = trim($data['email'] ?? '');
    $errors = [];
    if (empty($email)) {
        $errors[] = T::error_empty_fields;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = T::error_invalid_email;
    }

    if (!empty($errors)) {
        echo json_encode([
            'status' => 'error',
            'message' => implode(', ', $errors)
        ]);
        exit;
    }

    try {
        $user = $db->get('users', ['id', 'first_name', 'last_name', 'email', 'phone', 'phone_country_code'], [
            'email' => $email,
            'status' => 'active'
        ]);

        if (!$user) {
            echo json_encode([
                'status' => 'error',
                'message' => T::error_email_not_found
            ]);
            exit;
        }

        $otpToken = sprintf("%06d", mt_rand(100000, 999999));
        $expiresAt = date('Y-m-d H:i:s', time() + 300); // 5 minute expiry
        $db->update('users', [
            'otp' => $otpToken,
            'otp_expires' => $expiresAt
        ], ['id' => $user['id']]);

        $notificationResults = sendNotification($user['id'], 'forgot_password_api', []);

        logUserActivity($db, $user['id'], 'forgot_password', 'Password reset requested via API');

        // Trigger password reset requested webhook
        triggerWebhook('users/password-reset', 'password.reset_requested', [
            'user_id' => $user['user_id'] ?? null,
            'email' => $email,
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        $emailSent = $notificationResults['email'] ?? false;
        $whatsappSent = $notificationResults['whatsapp'] ?? false;
        $smsSent = $notificationResults['sms'] ?? false;

        if ($emailSent || $whatsappSent || $smsSent) {
            echo json_encode([
                'status' => 'success',
                'message' => T::success_reset_email_sent
            ]);
        } else {
            echo json_encode([
                'status' => 'success',
                'message' => T::success_reset_no_email
            ]);
        }
        exit;
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => trim($e->getMessage()) !== '' ? $e->getMessage() : T::error_password_reset_failed,
        ]);
        exit;
    }
});

// ==================================== 
// RESEND PASSWORD OTP
// ====================================

$router->post('/api/resend-password-otp', function () use ($SECURE, $db) {

    header('Content-Type: application/json');

    $data = json_decode(file_get_contents('php://input'), true);

    $email = trim($data['email'] ?? '');
    $errors = [];
    if (empty($email)) {
        $errors[] = T::error_empty_fields;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = T::error_invalid_email;
    }

    if (!empty($errors)) {
        echo json_encode([
            'status' => 'error',
            'message' => implode(', ', $errors)
        ]);
        exit;
    }

    try {
        $user = $db->get('users', ['id', 'first_name', 'last_name', 'email', 'phone', 'phone_country_code'], [
            'email' => $email,
            'status' => 'active'
        ]);

        if (!$user) {
            echo json_encode([
                'status' => 'error',
                'message' => T::error_email_not_found
            ]);
            exit;
        }

        $otpToken = sprintf("%06d", mt_rand(100000, 999999));
        $expiresAt = date('Y-m-d H:i:s', time() + 300); // 5 minute expiry
        $db->update('users', [
            'otp' => $otpToken,
            'otp_expires' => $expiresAt
        ], ['id' => $user['id']]);

        $notificationResults = sendNotification($user['id'], 'forgot_password_api', []);

        logUserActivity($db, $user['id'], 'resend_password_otp', 'Password reset OTP resent via API');

        $emailSent = $notificationResults['email'] ?? false;
        $whatsappSent = $notificationResults['whatsapp'] ?? false;
        $smsSent = $notificationResults['sms'] ?? false;

        if ($emailSent || $whatsappSent || $smsSent) {
            echo json_encode([
                'status' => 'success',
                'message' => T::success_reset_email_sent
            ]);
        } else {
            echo json_encode([
                'status' => 'success',
                'message' => T::success_reset_no_email
            ]);
        }
        exit;
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => trim($e->getMessage()) !== '' ? $e->getMessage() : T::error_password_reset_failed,
        ]);
        exit;
    }
});

// ==================================== 
// VERIFY OTP (Unified General API)
// ====================================

$router->post('/api/verify-otp', function () use ($SECURE, $db) {

    header('Content-Type: application/json');

    $data = json_decode(file_get_contents('php://input'), true);

    $email = trim($data['email'] ?? '');
    $otp = trim($data['otp'] ?? '');
    $action = trim($data['action'] ?? ''); // 'signup' or 'forgot_password'

    if (empty($email) || empty($otp) || empty($action)) {
        echo json_encode([
            'status' => 'error',
            'message' => T::error_empty_fields
        ]);
        exit;
    }

    if (!in_array($action, ['signup', 'forgot_password'])) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid action'
        ]);
        exit;
    }

    try {
        $user = $db->get('users', '*', [
            'email' => $email,
            'otp' => $otp,
            'otp_expires[>]' => date('Y-m-d H:i:s')
        ]);

        if (!$user) {
            echo json_encode([
                'status' => 'error',
                'message' => T::error_token_invalid
            ]);
            exit;
        }

        if ($action === 'signup') {
            // Verify email and activate user
            $db->update('users', [
                'email_verified' => 1,
                'status' => 'active',
                'otp' => null,
                'otp_expires' => null,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $user['id']]);

            logUserActivity($db, $user['id'], 'email_verified', 'Email verified successfully via API during signup');

            echo json_encode([
                'status' => 'success',
                'message' => 'Email verified successfully',
                'next_step' => 'signup_completed'
            ]);
            exit;

        } else if ($action === 'forgot_password') {
            // Generate a secure reset token
            $resetToken = bin2hex(random_bytes(16));
            $expiresAt = date('Y-m-d H:i:s', time() + 300); // 5 minutes expiry

            $db->update('users', [
                'reset_token' => $resetToken,
                'reset_token_expires' => $expiresAt,
                'otp' => null,
                'otp_expires' => null,
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $user['id']]);

            logUserActivity($db, $user['id'], 'otp_verified', 'Password reset OTP verified via API');

            echo json_encode([
                'status' => 'success',
                'message' => 'OTP verified successfully',
                'next_step' => 'change_password',
                'token' => $resetToken
            ]);
            exit;
        }

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => trim($e->getMessage()) !== '' ? $e->getMessage() : 'Verification failed',
        ]);
        exit;
    }
});

// ==================================== 
// RESET PASSWORD
// ====================================

$router->post('/api/reset-password', function () use ($SECURE, $db) {

    header('Content-Type: application/json');

    $data = json_decode(file_get_contents('php://input'), true);

    $token = $data['token'] ?? $data['otp'] ?? '';
    $password = trim($data['password'] ?? '');
    $confirm_password = trim($data['confirm_password'] ?? '');

    if (empty($token) || empty($password) || empty($confirm_password)) {
        echo json_encode([
            'status' => 'error',
            'message' => T::error_empty_fields
        ]);
        exit;
    }

    if ($password !== $confirm_password) {
        echo json_encode([
            'status' => 'error',
            'message' => T::error_password_mismatch
        ]);
        exit;
    }

    if (strlen($password) < 6) {
        echo json_encode([
            'status' => 'error',
            'message' => T::error_password_weak
        ]);
        exit;
    }

    // Find user by reset_token
    $user = $db->get('users', '*', [
        'reset_token' => $token,
        'reset_token_expires[>]' => date('Y-m-d H:i:s')
    ]);

    if (!$user) {
        echo json_encode([
            'status' => 'error',
            'message' => T::error_token_invalid
        ]);
        exit;
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    try {
        $updateFields = [
            'password' => $hashed_password,
            'reset_token' => null,
            'reset_token_expires' => null,
            'login_attempts' => 0,
            'locked_until' => null,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $db->update('users', $updateFields, ['id' => $user['id']]);

        logUserActivity($db, $user['id'], 'password_reset', 'Password successfully reset via API');

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

        // Send success email only to the user
        $template = $db->get('notification_templates', '*', ['name' => 'password_reset', 'type' => 'email', 'status' => 1]);
        if ($template) {
            $rendered = renderEmailTemplate($template, $user);
            SENDEMAIL($user['email'], $user['first_name'] . ' ' . $user['last_name'], $rendered['subject'], $rendered['body_html']);
        }

        echo json_encode([
            'status' => 'success',
            'message' => T::success_password_reset
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => trim($e->getMessage()) !== '' ? $e->getMessage() : T::error_password_reset_failed
        ]);
        exit;
    }
});

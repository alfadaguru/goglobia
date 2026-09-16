<?php
// FILE: app/routes/api/users/login.php

@$SECURE or die('Access Denied!');

require_once 'app/lib/jwt.php';

$router->post('/api/login', function () use ($db) {

    header('Content-Type: application/json');

    $input = json_decode(file_get_contents("php://input"), true);

    if (!$input) {
        echo json_encode([
            'status' => 'error',
            'code' => 'INVALID_JSON',
            'message' => 'Invalid JSON payload'
        ]);
        exit;
    }

    $email    = trim($input['email'] ?? '');
    $password = trim($input['password'] ?? '');
    $remember_me = $input['remember_me'] ?? false;

    if (empty($email) || empty($password)) {
        echo json_encode([
            'status' => 'error',
            'code' => 'EMPTY_FIELDS',
            'message' => 'Email and password are required'
        ]);
        exit;
    }

    $user = $db->get('users', '*', ['email' => $email]);

    if (!$user) {
        echo json_encode([
            'status' => 'error',
            'code' => 'INVALID_CREDENTIALS',
            'message' => 'Invalid email or password'
        ]);
        exit;
    }

    $userId = (int)$user['id'];

    if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
        echo json_encode([
            'status' => 'error',
            'code' => 'ACCOUNT_LOCKED',
            'message' => 'Account is temporarily locked',
            'locked_until' => $user['locked_until']
        ]);
        exit;
    }

    if (!password_verify($password, $user['password'])) {

        // BRUTE-FORCE LOCKOUT — mirror the web /login policy (5 attempts → 30-min
        // lock). Previously the API only INCREMENTED login_attempts and never set
        // locked_until, so an attacker brute-forcing exclusively via /api/login was
        // never locked out (the counter just climbed past 5 with no effect).
        $attempts     = ($user['login_attempts'] ?? 0) + 1;
        $maxAttempts  = 5;
        $lockDuration = 30 * 60;
        $updateData   = ['login_attempts' => $attempts];

        if ($attempts >= $maxAttempts) {
            $lockUntil = date('Y-m-d H:i:s', time() + $lockDuration);
            $updateData['locked_until'] = $lockUntil;
            $db->update('users', $updateData, ['id' => $userId]);
            if (function_exists('logUserActivity')) {
                logUserActivity($db, $userId, 'login_failed', 'Account locked due to too many failed attempts (API)');
            }
            echo json_encode([
                'status'       => 'error',
                'code'         => 'ACCOUNT_LOCKED',
                'message'      => 'Account is temporarily locked',
                'locked_until' => $lockUntil
            ]);
            exit;
        }

        $db->update('users', $updateData, ['id' => $userId]);
        echo json_encode([
            'status' => 'error',
            'code' => 'INVALID_CREDENTIALS',
            'message' => 'Invalid email or password',
            'attempts_remaining' => max(0, $maxAttempts - $attempts)
        ]);
        exit;
    }

    if (!$user['email_verified']) {
        echo json_encode([
            'status' => 'error',
            'code' => 'EMAIL_NOT_VERIFIED',
            'message' => 'Please verify your email first',
            'user_id' => $user['user_id']
        ]);
        exit;
    }

    if ($user['status'] !== 'active') {
        echo json_encode([
            'status' => 'error',
            'code' => 'ACCOUNT_INACTIVE',
            'message' => 'Account is inactive'
        ]);
        exit;
    }

    if ($user['banned']) {
        echo json_encode([
            'status' => 'error',
            'code' => 'ACCOUNT_BANNED',
            'message' => 'Your account is banned'
        ]);
        exit;
    }

    $db->update('users', [
        'login_attempts' => 0,
        'locked_until' => null,
        'last_login' => date('Y-m-d H:i:s')
    ], ['id' => $userId]);

    logUserActivity($db, $userId, 'login', 'API login successful');

    $accessTokenExpiry = 15 * 60;

    $accessToken = JWT::generate([
        'user_id' => $user['user_id'],
        'email'   => $user['email'],
        'role'    => $user['role']
    ], $accessTokenExpiry);

    $refreshTokenExpiry = 15 * 24 * 3600;

    $refreshToken = JWT::generate([
        'user_id' => $user['id'],
        'type'    => 'refresh'
    ], $refreshTokenExpiry);

    $db->update('users', [
        'refresh_token' => hash('sha256', $refreshToken),
        'refresh_token_expires' => date('Y-m-d H:i:s', time() + $refreshTokenExpiry)
    ], ['id' => $user['id']]);

    echo json_encode([
        'status' => 'success',
        'code' => 'LOGIN_SUCCESS',
        'message' => 'Login successful',
        'data' => [
            'user_id' => $user['user_id'],
            'email' => $user['email'],
            'name' => $user['first_name'] . ' ' . $user['last_name'],
            'role' => $user['role'],
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessTokenExpiry
        ]
    ]);
    exit;
});

<?php
// FILE: app/routes/api/auth/refresh.php
// AUTH REFRESH TOKEN ENDPOINT

@$SECURE or die('Access Denied!');

// Include JWT utility
require_once 'app/lib/jwt.php';

// ============================================================================
// REFRESH TOKEN ENDPOINT
// ============================================================================

$router->post('/api/auth/refresh', function () use ($db) {
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

    $refreshToken = trim($input['refresh_token'] ?? '');

    if (empty($refreshToken)) {
        echo json_encode([
            'status' => 'error',
            'code' => 'MISSING_REFRESH_TOKEN',
            'message' => 'Refresh token is required'
        ]);
        exit;
    }

    // Verify refresh token
    $tokenData = JWT::verify($refreshToken);

    if (!$tokenData || ($tokenData['type'] ?? '') !== 'refresh') {
        echo json_encode([
            'status' => 'error',
            'code' => 'INVALID_REFRESH_TOKEN',
            'message' => 'Invalid refresh token'
        ]);
        exit;
    }

    $userId = $tokenData['user_id'];

    // Check if refresh token exists in users table and is not expired
    $refreshTokenHash = hash('sha256', $refreshToken);
    $user = $db->get('users', ['refresh_token', 'refresh_token_expires'], ['id' => $userId]);

    if (!$user || $user['refresh_token'] !== $refreshTokenHash || strtotime($user['refresh_token_expires']) < time()) {
        echo json_encode([
            'status' => 'error',
            'code' => 'EXPIRED_REFRESH_TOKEN',
            'message' => 'Refresh token has expired'
        ]);
        exit;
    }

    // Get user data
    $user = $db->get('users', ['id', 'user_id', 'email', 'first_name', 'last_name', 'role'], ['id' => $userId]);

    if (!$user) {
        echo json_encode([
            'status' => 'error',
            'code' => 'USER_NOT_FOUND',
            'message' => 'User not found'
        ]);
        exit;
    }

    // Generate new access token
    $accessTokenExpiry = 15 * 60; // 15 minutes
    
    $accessToken = JWT::generate([
        'user_id' => $user['user_id'],
        'email'   => $user['email'],
        'role'    => $user['role'],
        'type'    => 'access'
    ], $accessTokenExpiry);

    echo json_encode([
        'status' => 'success',
        'code' => 'TOKEN_REFRESHED',
        'message' => 'Access token refreshed successfully',
        'data' => [
            'user_id' => $user['user_id'],
            'email' => $user['email'],
            'name' => $user['first_name'] . ' ' . $user['last_name'],
            'role' => $user['role'],
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessTokenExpiry
        ]
    ]);
    exit;
});

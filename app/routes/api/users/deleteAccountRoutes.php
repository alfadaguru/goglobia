<?php
// FILE: app/routes/api/users/deleteAccountRoutes.php
// Delete User Account API endpoint for Mobile App

@$SECURE or die('Access Denied!');

require_once 'app/lib/jwt.php';

// ====================================
// DELETE USER ACCOUNT API
// ====================================
$router->post('/api/users/delete-account', function () use ($db) {
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    $user = null;
    $user_id_str = '';

    // 1. Authentication & Authorization
    // Method A: Check for valid Access Token in Bearer header
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] 
        ?? $headers['authorization'] 
        ?? $_SERVER['HTTP_AUTHORIZATION'] 
        ?? '';

    $accessToken = '';
    if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $accessToken = trim($matches[1]);
    }

    $accessTokenValid = false;
    if (!empty($accessToken)) {
        try {
            $tokenData = JWT::verify($accessToken);
            if ($tokenData && !empty($tokenData['user_id'])) {
                $user_id_str = $tokenData['user_id'];
                $user = $db->get('users', '*', ['user_id' => $user_id_str]);
                if ($user) {
                    $accessTokenValid = true;
                }
            }
        } catch (Exception $e) {
            // Token is expired or invalid; we will fallback to Refresh Token below
        }
    }

    // Method B: Fallback to verifying Refresh Token from request payload
    if (!$accessTokenValid) {
        $refreshToken = trim($input['refresh_token'] ?? '');

        if (empty($refreshToken)) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'code' => 'UNAUTHORIZED',
                'message' => 'Valid access token or refresh token is required'
            ]);
            exit;
        }

        try {
            $tokenData = JWT::verify($refreshToken);
        } catch (Exception $e) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'code' => 'INVALID_REFRESH_TOKEN',
                'message' => 'Invalid or expired refresh token'
            ]);
            exit;
        }

        if (!$tokenData || ($tokenData['type'] ?? '') !== 'refresh' || empty($tokenData['user_id'])) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'code' => 'INVALID_REFRESH_TOKEN_PAYLOAD',
                'message' => 'Invalid refresh token payload'
            ]);
            exit;
        }

        $numeric_id = $tokenData['user_id'];
        $refreshTokenHash = hash('sha256', $refreshToken);

        $user = $db->get('users', '*', ['id' => $numeric_id]);

        if (!$user || $user['refresh_token'] !== $refreshTokenHash || strtotime($user['refresh_token_expires']) < time()) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'code' => 'EXPIRED_REFRESH_TOKEN',
                'message' => 'Refresh token has expired or is invalid'
            ]);
            exit;
        }

        $user_id_str = $user['user_id'];
    }

    // Validate User exists
    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'code' => 'USER_NOT_FOUND',
            'message' => 'User not found'
        ]);
        exit;
    }

    // Check if user is admin (prevent admin deletion via mobile API)
    if (strtolower($user['role'] ?? '') === 'admin') {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'code' => 'ADMIN_DELETION_DENIED',
            'message' => 'Administrators cannot delete their accounts'
        ]);
        exit;
    }

    // 2. Validation & Error Handling (Password verification)
    $password = trim($input['password'] ?? '');

    if (empty($password)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'code' => 'PASSWORD_REQUIRED',
            'message' => 'Password is required to confirm account deletion'
        ]);
        exit;
    }

    if (!password_verify($password, $user['password'])) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'code' => 'INCORRECT_PASSWORD',
            'message' => 'The password provided is incorrect'
        ]);
        exit;
    }

    // 3. Audit Logging (Log before data purge for traceability)
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    error_log("AUDIT | USER_DELETION | User ID: {$user_id_str} | Email: {$user['email']} | Timestamp: {$timestamp} | IP: {$ip}");

    // 4. Data Deletion & Anonymization Logic
    // Anonymize bookings/orders to preserve transaction & reporting data (GDPR compliant)
    $db->update('bookings', ['user_id' => null, 'user_data' => null], ['user_id' => $user_id_str]);
    
    // Update other module-specific tables if they refer to user_id
    $db->update('cars', ['user_id' => null], ['user_id' => $user_id_str]);
    $db->update('flights', ['user_id' => null], ['user_id' => $user_id_str]);
    $db->update('stays', ['user_id' => null], ['user_id' => $user_id_str]);
    $db->update('tours', ['user_id' => null], ['user_id' => $user_id_str]);
    $db->update('umrah', ['user_id' => null], ['user_id' => $user_id_str]);

    // Hard delete personal data from other linked tables
    $db->delete('agencies', ['user_id' => $user_id_str]);
    $db->delete('favorites', ['user_id' => $user_id_str]);
    $db->delete('favourites', ['user_id' => $user_id_str]);
    $db->delete('logs_bookings', ['user_id' => $user_id_str]);
    $db->delete('logs_searches', ['user_id' => $user_id_str]);
    $db->delete('logs_users', ['user_id' => $user_id_str]);
    $db->delete('notes', ['user_id' => $user_id_str]);
    $db->delete('credits', ['user_id' => $user_id_str]);
    $db->delete('deposit', ['user_id' => $user_id_str]);
    $db->delete('transactions', ['user_id' => $user_id_str]);
    $db->delete('tickets', ['user_id' => $user_id_str]);

    // Finally, hard delete user profile record
    $db->delete('users', ['user_id' => $user_id_str]);

    // 5. Session & Token Cleanup
    session_unset();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    // 6. API Response Implementation
    echo json_encode([
        'status' => 'success',
        'message' => 'Your account and all associated personal data have been deleted successfully.'
    ]);
    exit;
});

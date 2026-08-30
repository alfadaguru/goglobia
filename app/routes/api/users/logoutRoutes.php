<?php
// FILE: app/routes/api/users/logout.php
// Users API logout endpoint

@$SECURE or die('Access Denied!');

require_once 'app/lib/jwt.php';

// ====================================
// USERS LOGOUT API
// ====================================

$router->post('/api/users/logout', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    // Get token from Authorization header. Apache/XAMPP may expose it via getallheaders().
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization']
        ?? $headers['authorization']
        ?? $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if (empty($authHeader) || !preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Authorization token required'
        ]);
        return;
    }

    $token = $matches[1];

    // Verify token
    $payload = JWT::verify($token);
    if (!$payload) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid or expired token'
        ]);
        return;
    }

    $userId = $payload['user_id'];

    // Token is valid, "logout" by returning success
    $response = [
        'status' => 'success',
        'message' => 'Logged out successfully'
    ];

    echo json_encode($response);
});

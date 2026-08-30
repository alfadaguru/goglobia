<?php
// FILE: app/routes/api/users/favouritesRoutes.php

@$SECURE or die('Access Denied!');

// ====================================
// FAVOURITES API
// ====================================

$router->post('/api/users/favourites', function () use ($db) {
    header('Content-Type: application/json');

    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
        exit;
    }

    require_once 'app/lib/jwt.php';
    $tokenData = JWT::verify($matches[1]);
    if (!$tokenData) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired token']);
        exit;
    }

    $user_id = $tokenData['user_id'];

    $favouritesRaw = $db->select('favourites', '*', [
        'user_id' => $user_id,
        'ORDER' => ['id' => 'DESC']
    ]);

    $favourites = [];
    if (is_array($favouritesRaw)) {
        foreach ($favouritesRaw as $f) {
            $favourites[] = [
                'id' => $f['id'] ?? '',
                'module' => $f['module'] ?? '',
                'item_id' => $f['item_id'] ?? '',
                'date' => $f['created_at'] ?? ''
            ];
        }
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Favourites retrieved',
        'data' => $favourites
    ]);
    exit;
});

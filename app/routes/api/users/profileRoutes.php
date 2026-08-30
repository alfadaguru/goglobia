<?php
// FILE: app/routes/api/users/profile.php
// USERS PROFILE API (GET & POST)

@$SECURE or die('Access Denied!');

require_once 'app/lib/jwt.php';


// ==================================================
// GET USER PROFILE
// ==================================================
$router->get('/api/users/profile', function () use ($db) {

    header('Content-Type: application/json');

    // ---- AUTH ----
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Authentication required'
        ]);
        exit;
    }

    $tokenData = JWT::verify($matches[1]);
    if (!$tokenData) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid or expired token'
        ]);
        exit;
    }

    $user_id = $tokenData['user_id']; // this is users.id

    // ---- FETCH USER ----
    $user = $db->get('users', [
        'user_id',
        'title',
        'first_name',
        'last_name',
        'email',
        'phone_country_code',
        'phone',
        'country',
        'state',
        'address',
        'po_box',
        'status',
        'role',
        'created_at',
        'updated_at'
    ], ['user_id' => $user_id]); 

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'User not found'
        ]);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'User profile retrieved',
        'data' => $user
    ]);
    exit;
});


// ==================================================
// UPDATE USER PROFILE
// ==================================================
$router->post('/api/users/profile', function () use ($db) {

    header('Content-Type: application/json');

    // ---- AUTH ----
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Authentication required'
        ]);
        exit;
    }

    $tokenData = JWT::verify($matches[1]);
    if (!$tokenData) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid or expired token'
        ]);
        exit;
    }

    $user_id = $tokenData['user_id']; // this is users.id

    // ---- INPUT ----
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid JSON payload'
        ]);
        exit;
    }

    // ---- GET CURRENT EMAIL (EMAIL PROTECTED) ----
    $currentUser = $db->get('users', ['email'], ['user_id' => $user_id]); 
    if (!$currentUser) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'User not found'
        ]);
        exit;
    }

    // ---- BUILD UPDATE DATA ----
    $updateData = [
        'updated_at' => date('Y-m-d H:i:s'),
        'email' => $currentUser['email'] // email protected
    ];

    if (isset($data['title'])) $updateData['title'] = trim($data['title']);
    if (isset($data['first_name'])) $updateData['first_name'] = trim($data['first_name']);
    if (isset($data['last_name'])) $updateData['last_name'] = trim($data['last_name']);
    if (isset($data['phone_country_code'])) $updateData['phone_country_code'] = trim($data['phone_country_code']);
    if (isset($data['phone'])) {
        $phone = preg_replace('/\D/', '', $data['phone']);
        $updateData['phone'] = ltrim($phone, '0');
    }
    if (isset($data['country'])) $updateData['country'] = trim($data['country']);
    if (isset($data['state'])) $updateData['state'] = trim($data['state']);
    if (isset($data['address'])) $updateData['address'] = trim($data['address']);
    if (isset($data['po_box'])) $updateData['po_box'] = trim($data['po_box']);

    if (count($updateData) === 2) { // only email + updated_at
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'No valid fields to update'
        ]);
        exit;
    }

    // ---- UPDATE ----
    $updated = $db->update('users', $updateData, ['user_id' => $user_id]);

    if ($updated === false) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to update profile'
        ]);
        exit;
    }

    // Fetch the updated user data to return in the response
    $updatedUser = $db->get('users', [
        'user_id',
        'title',
        'first_name',
        'last_name',
        'email',
        'phone_country_code',
        'phone',
        'country',
        'state',
        'address',
        'po_box',
        'status',
        'role',
        'created_at',
        'updated_at'
    ], ['user_id' => $user_id]); 

    echo json_encode([
        'status' => 'success',
        'message' => 'Profile updated successfully',
        'data' => $updatedUser
    ]);
    exit;
});

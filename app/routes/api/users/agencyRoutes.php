<?php
// FILE: app/routes/api/users/agencyRoutes.php

@$SECURE or die('Access Denied!');

// ====================================
// AGENCY API
// ====================================

$router->post('/api/users/agency', function () use ($db) {
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

    require_once 'app/lib/jwt.php';
    $tokenData = JWT::verify($matches[1]);
    if (!$tokenData) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid or expired token'
        ]);
        exit;
    }

    $user_id = $tokenData['user_id'];

    $user = $db->get('users', ['role', 'email'], ['user_id' => $user_id]);
    if (!$user || $user['role'] !== 'agent') {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Access Denied - Agents Only'
        ]);
        exit;
    }

    $agencyRaw = $db->get('agencies', '*', ['user_id' => $user_id]);

    if (!$agencyRaw) {
        $db->insert('agencies', [
            'user_id' => $user_id,
            'agency_name' => '',
            'license_number' => '',
            'city' => '',
            'address' => '',
            'country' => '',
            'phone' => '',
            'email' => $user['email'],
            'logo' => '',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        $agencyRaw = $db->get('agencies', '*', ['user_id' => $user_id]);
    }

    $agency = [
        'agency_name' => $agencyRaw['agency_name'] ?? '',
        'license_number' => $agencyRaw['license_number'] ?? '',
        'city' => $agencyRaw['city'] ?? '',
        'address' => $agencyRaw['address'] ?? '',
        'country' => $agencyRaw['country'] ?? '',
        'phone' => $agencyRaw['phone'] ?? '',
        'email' => $agencyRaw['email'] ?? '',
        'logo' => !empty($agencyRaw['logo']) ? root . $agencyRaw['logo'] : ''
    ];

    echo json_encode([
        'status' => 'success',
        'message' => 'Agency details retrieved',
        'data' => $agency
    ]);
    exit;
});

// ====================================
// UPDATE AGENCY API
// ====================================

$router->post('/api/users/agency/update', function () use ($db) {
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

    require_once 'app/lib/jwt.php';
    $tokenData = JWT::verify($matches[1]);
    if (!$tokenData) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid or expired token'
        ]);
        exit;
    }

    $user_id = $tokenData['user_id'];

    $user = $db->get('users', ['role'], ['user_id' => $user_id]);
    if (!$user || $user['role'] !== 'agent') {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Access Denied - Agents Only'
        ]);
        exit;
    }

    // Validation
    $required_fields = ['agency_name', 'license_number', 'city', 'country', 'phone', 'address', 'email'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
            exit;
        }
    }

    // Email validation
    if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid email format']);
        exit;
    }

    // Handle logo upload
    $logo_path = null;
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        // SECURITY: real MIME validation (not the spoofable client type).
        $chk = secureUploadCheck($_FILES['logo'], ['jpg', 'jpeg', 'png'], 2 * 1024 * 1024);
        if (!$chk['ok']) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $chk['error'] ?? 'Invalid image file']);
            exit;
        }

        $upload_dir = 'uploads/agencies/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $filename = bin2hex(random_bytes(8)) . '_' . time() . '.' . $chk['ext'];
        $target_path = $upload_dir . $filename;

        if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_path)) {
            @chmod($target_path, 0644);
            $logo_path = 'uploads/agencies/' . $filename;
        }
    }

    // Prepare data
    $data = [
        'agency_name' => trim($_POST['agency_name']),
        'license_number' => trim($_POST['license_number']),
        'city' => trim($_POST['city']),
        'address' => trim($_POST['address']),
        'country' => trim($_POST['country']),
        'phone' => trim($_POST['phone']),
        'email' => trim($_POST['email']),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    if ($logo_path) {
        $data['logo'] = $logo_path;
    }

    // Check if agency exists
    $existing = $db->get('agencies', '*', ['user_id' => $user_id]);

    if ($existing) {
        $db->update('agencies', $data, ['user_id' => $user_id]);
    } else {
        $data['user_id'] = $user_id;
        $data['created_at'] = date('Y-m-d H:i:s');
        $db->insert('agencies', $data);
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Agency details updated successfully'
    ]);
    exit;
});

<?php
// FILE: app/routes/api/users/depositRoutes.php

@$SECURE or die('Access Denied!');

// ====================================
// DEPOSIT API
// ====================================

$router->post('/api/users/deposit', function () use ($db) {
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

    // Fetch enabled payment gateways
    $paymentGateways = $db->select('payment_gateways', [
        'name',
        'display_name',
        'type'
    ], [
        'status' => 1
    ]);

    // Fetch user's deposit history
    $depositsRaw = $db->select('deposit', '*', [
        'user_id' => $user_id,
        'ORDER' => ['created_at' => 'DESC']
    ]);

    $deposits = [];
    if (is_array($depositsRaw)) {
        foreach ($depositsRaw as $d) {
            $deposits[] = [
                'id' => $d['id'] ?? '',
                'amount' => isset($d['amount']) ? number_format((float)$d['amount'], 2, '.', '') : "0.00",
                'currency' => $d['currency'] ?? '',
                'transaction_id' => $d['transaction_id'] ?? '',
                'payment_method' => $d['payment_method'] ?? '',
                'status' => $d['status'] ?? 'pending',
                'details' => $d['details'] ?? '',
                'attachment' => $d['attachment'] ?? null,
                'date' => $d['created_at'] ?? ''
            ];
        }
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Deposit history retrieved',
        'data' => [
            'gateways' => $paymentGateways,
            'deposits' => $deposits
        ]
    ]);
    exit;
});

// ====================================
// DEPOSIT REQUEST SUBMISSION API
// ====================================
$router->post('/api/users/deposit/add', function () use ($db) {
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

    $user = $db->get('users', ['id', 'user_id', 'role', 'email', 'first_name', 'last_name'], ['user_id' => $user_id]);
    if (!$user || $user['role'] !== 'agent') {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Access Denied - Agents Only'
        ]);
        exit;
    }

    // Validation
    $amount = floatval($_POST['amount'] ?? 0);
    $currency = trim($_POST['currency'] ?? '');
    $transactionId = trim($_POST['transaction_id'] ?? '');
    $paymentMethod = trim($_POST['payment_method'] ?? '');
    $details = trim($_POST['details'] ?? '');

    if ($amount <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid amount. Please enter a valid amount.']);
        exit;
    }

    if (empty($currency) || empty($transactionId) || empty($paymentMethod)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Amount, currency, transaction_id, and payment_method are required']);
        exit;
    }

    // Attachment validation
    if (empty($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Payment proof attachment is required']);
        exit;
    }

    $file = $_FILES['attachment'];

    // SECURITY: validate real MIME + safe extension (finfo), not just the name.
    $chk = secureUploadCheck($file, ['jpg', 'jpeg', 'png', 'pdf'], 5 * 1024 * 1024);
    if (!$chk['ok']) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $chk['error'] ?? 'Invalid file.']);
        exit;
    }
    $fileExtension = $chk['ext'];

    $uploadDir = 'uploads/deposit/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $uniqueId = 'DEP_' . bin2hex(random_bytes(8));
    $fileName = $uniqueId . '.' . $fileExtension;
    $uploadPath = $uploadDir . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to upload attachment file']);
        exit;
    }

    $depositId = 'TXN' . strtoupper(substr(uniqid(), -7));

    // Insert deposit
    $result = $db->insert('deposit', [
        'id' => $depositId,
        'user_id' => $user_id,
        'amount' => $amount,
        'currency' => $currency,
        'transaction_id' => $transactionId,
        'payment_method' => $paymentMethod,
        'status' => 'pending',
        'details' => $details,
        'attachment' => $uploadPath,
        'created_at' => date('Y-m-d H:i:s')
    ]);

    if ($result) {
        $db->insert('logs_users', [
            'user_id' => $user_id,
            'type' => 'deposit_request',
            'description' => 'Deposit request submitted via API: ' . $depositId . ' - ' . $currency . ' ' . $amount,
            'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
            'created_at' => date('Y-m-d H:i:s'),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ]);

        $depositData = [
            'id' => $depositId,
            'amount' => $amount,
            'currency' => $currency,
            'transaction_id' => $transactionId,
            'payment_method' => $paymentMethod,
            'details' => $details
        ];
        
        // Disable warning for NOTIFY if it doesn't exist
        if (class_exists('NOTIFY')) {
            try { NOTIFY::deposit('new_request', $depositData, $user); } catch (Exception $e) {}
        }

        echo json_encode([
            'status' => 'success',
            'message' => 'Your deposit request has been submitted successfully and is pending approval.',
            'deposit_id' => $depositId
        ]);
    } else {
        if (file_exists($uploadPath)) {
            unlink($uploadPath);
        }

        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error. Failed to record deposit request']);
    }
    exit;
});

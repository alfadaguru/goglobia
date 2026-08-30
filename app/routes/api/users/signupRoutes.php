<?php
// FILE: app/routes/api/users/signup.php
// All signup routes (GET & POST)

@$SECURE or die('Access Denied!');

// ====================================
// SIGNUP ROUTES
// ====================================

/**
 * GET CAPTCHA CHALLENGE
 * Providing question, hash and timestamp
 */
$router->get('/api/captcha', function () {
    header('Content-Type: application/json');
    $captcha = Captcha::generate();
    echo json_encode([
        'status' => 'success',
        'question' => 'Security Check: What is ' . $captcha['question'] . '? *',
        'hash' => $captcha['hash'],
        'timestamp' => $captcha['timestamp']
    ]);
    exit;
});

$router->post('/api/signup', function () use ($db) {

    header('Content-Type: application/json');

    $data = json_decode(file_get_contents("php://input"), true);

    if (!$data) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid request'
        ]);
        exit;
    }

    // ================================
    // GET DATA
    // ================================
    $first_name = trim($data['first_name'] ?? '');
    $last_name = trim($data['last_name'] ?? '');
    $email = trim($data['email'] ?? '');
    $password = trim($data['password'] ?? '');
    $confirm_password = trim($data['confirm_password'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $phone_country_code = trim($data['phone_country_code'] ?? '92');
    $terms = $data['terms'] ?? false;
    $userType = trim($data['user_type'] ?? 'customer');
    $userRole = ($userType === 'agent') ? 'agent' : 'customer';

    // ================================
    // VALIDATIONS
    // ================================

    // Check if user registration is disabled globally
    if (isset($GLOBALS['app']['user_registration']) && $GLOBALS['app']['user_registration'] == '0') {
        echo json_encode(['status' => 'error', 'message' => 'registration_disabled']);
        exit;
    }

    // Check if agent registration is disabled globally
    if ($userRole === 'agent' && isset($GLOBALS['app']['agent_registration']) && $GLOBALS['app']['agent_registration'] == '0') {
        echo json_encode(['status' => 'error', 'message' => 'agent_registration_disabled']);
        exit;
    }

    // CAPTCHA validation
    $captchaAnswer = trim($data['captcha_answer'] ?? '');
    $captchaHash = $data['captcha_hash'] ?? '';
    $captchaTimestamp = $data['captcha_timestamp'] ?? 0;

    $captchaCheck = Captcha::validate($captchaAnswer, $captchaHash, $captchaTimestamp);
    if (!$captchaCheck['valid']) {
        echo json_encode(['status' => 'error', 'message' => $captchaCheck['error']]);
        exit;
    }

    if (empty($first_name) || empty($last_name) || empty($email) || empty($password) || empty($confirm_password)) {
        echo json_encode(['status' => 'error', 'message' => 'empty']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'invalid_email']);
        exit;
    }

    $check = $db->get('users', ['id', 'user_id', 'email_verified', 'status'], ['email' => $email]);
    $existingUserId = null;
    $existingCustomId = null;

    if ($check) {
        if ($check['email_verified'] == 1 || $check['status'] == 'active') {
            echo json_encode(['status' => 'error', 'message' => 'email_exists']);
            exit;
        } else {
            // Keep existing IDs for update
            $existingUserId = $check['id'];
            $existingCustomId = $check['user_id'];
        }
    }

    if ($password !== $confirm_password) {
        echo json_encode(['status' => 'error', 'message' => 'password_mismatch']);
        exit;
    }

    if (strlen($password) < 6) {
        echo json_encode(['status' => 'error', 'message' => 'password_weak']);
        exit;
    }

    if (!$terms) {
        echo json_encode(['status' => 'error', 'message' => 'terms_required']);
        exit;
    }

    // ================================
    // PASSWORD HASH
    // ================================
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // ================================
    // GENERATE UNIQUE USER ID
    // ================================
    $custom_user_id = $existingCustomId;
    if (!$existingCustomId) {
        $custom_user_id = generateUserId();
        $max_retries = 5;
        $retry = 0;
        while ($retry < $max_retries) {
            if (!$db->has('users', ['user_id' => $custom_user_id])) {
                break;
            }
            $custom_user_id = generateUserId();
            $retry++;
        }
        if ($retry >= $max_retries) {
            echo json_encode(['status' => 'error', 'message' => 'registration_failed']);
            exit;
        }
    }

    // ================================
    // TOKEN
    // ================================
    $verificationToken = sprintf("%06d", mt_rand(100000, 999999));

    // Get default currency from database for new user
    $default_curr = $db->get('currencies', 'name', ['default' => 1]);
    $user_currency = $default_curr ?? 'USD';

    $insert_data = [
        'user_id' => $custom_user_id,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'email' => $email,
        'phone' => $phone,
        'phone_country_code' => getPhoneCode($phone_country_code, $db),
        'currency' => $user_currency,
        'password' => $hashed_password,
        'role' => $userRole,
        'status' => 'inactive',
        'banned' => 0,
        'email_verified' => 0,
        'login_attempts' => 0,
        'locked_until' => null,
        'otp' => $verificationToken,
        'otp_expires' => date('Y-m-d H:i:s', time() + (24 * 3600)),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    try {
        if ($existingUserId) {
            $db->update('users', $insert_data, ['id' => $existingUserId]);
            $userId = $existingUserId;
            $result = true;
        } else {
            // Fresh insert
            $result = $db->insert('users', $insert_data);
            $userId = $db->id();
        }

        if ($result && $userId) {

            logUserActivity($db, $userId, 'signup', 'User registered successfully');

            triggerWebhook('users/signup', 'signup.success', [
                'user_id' => $insert_data['user_id'],
                'email' => $email,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'phone' => $phone,
                'phone_country_code' => $phone_country_code,
                'role' => $userRole,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'timestamp' => date('Y-m-d H:i:s'),
                'source' => 'mobile'
            ]);

            $notificationResults = sendNotification($userId, 'signup_welcome_api', []);

            $emailSent = $notificationResults['email'] ?? false;
            $whatsappSent = $notificationResults['whatsapp'] ?? false;
            $smsSent = $notificationResults['sms'] ?? false;

            // ================================
            // RESPONSE
            // ================================
            $response_data = [
                'user_id' => $insert_data['user_id'],
                'email' => $email
            ];

            if ($emailSent || $whatsappSent || $smsSent) {
                echo json_encode([
                    'status' => 'success',
                    'message' => T::success_registration_complete,
                    'data' => $response_data
                ]);
            } else {
                echo json_encode([
                    'status' => 'success',
                    'message' => T::success_registration_no_email,
                    'data' => $response_data
                ]);
            }

        } else {
            error_log('Signup failed - DB insert false: ' . $email);

            echo json_encode([
                'status' => 'error',
                'message' => 'registration_failed'
            ]);
        }

    } catch (Exception $e) {

        error_log('Signup exception: ' . $e->getMessage() . ' | Email: ' . $email);

        echo json_encode([
            'status' => 'error',
            'message' => trim($e->getMessage()) !== '' ? $e->getMessage() : 'registration_failed'
        ]);
    }

});
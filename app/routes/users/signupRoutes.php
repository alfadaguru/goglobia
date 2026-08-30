<?php
// FILE: app/routes/users/signup.php
// All signup routes (GET & POST)

@$SECURE or die('Access Denied!');

// ==================================== 
// SIGNUP ROUTES
// ====================================
$router->get('/signup', function () use ($SECURE, $db) {
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        header('Location: ' . root . 'dashboard');
        exit;
    }

    // Check if registration is disabled
    if (isset($GLOBALS['app']['user_registration']) && $GLOBALS['app']['user_registration'] == '0') {
        $_SESSION['login_error'] = 'registration_disabled';
        header('Location: ' . root . 'login');
        exit;
    }

    // Check if signing up as agent
    $signupType = $_GET['type'] ?? 'customer';
    $isAgent = ($signupType === 'agent');

    // Check if agent registration is disabled
    if ($isAgent && isset($GLOBALS['app']['agent_registration']) && $GLOBALS['app']['agent_registration'] == '0') {
        header('Location: ' . root . 'signup');
        exit;
    }

    // Generate CAPTCHA for signup form
    $captchaData = Captcha::generate();
    $_SESSION['captcha_data'] = $captchaData;

    $title = T::signup;
    $description = T::signup_description;
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once views."auth/signup.php";
    require_once views."includes/footer.php";
});

$router->post('/signup', function () use ($SECURE, $db) {
    // Check if registration is disabled
    if (isset($GLOBALS['app']['user_registration']) && $GLOBALS['app']['user_registration'] == '0') {
        $_SESSION['login_error'] = 'registration_disabled';
        header('Location: ' . root . 'login');
        exit;
    }

    // Store form data for repopulation on error
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $phone_country_code = trim($_POST['phone_country_code'] ?? '92');
    $terms = isset($_POST['terms']);
    $userType = trim($_POST['user_type'] ?? 'customer');
    $userRole = ($userType === 'agent') ? 'agent' : 'customer';

    // Check if agent registration is disabled
    if ($userRole === 'agent' && isset($GLOBALS['app']['agent_registration']) && $GLOBALS['app']['agent_registration'] == '0') {
        $_SESSION['signup_error'] = 'agent_registration_disabled';
        header('Location: ' . root . 'signup');
        exit;
    }

    // Store signup type for error redirects
    $_SESSION['signup_type'] = $userType;
    $redirectUrl = root . 'signup' . ($userType === 'agent' ? '?type=agent' : '');

    $_SESSION['form_data'] = [
        'first_name' => $first_name,
        'last_name' => $last_name,
        'email' => $email,
        'phone' => $phone,
        'phone_country_code' => $phone_country_code
    ];

    // CSRF validation
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['signup_error'] = 'csrf';
        header('Location: ' . $redirectUrl);
        exit;
    }

    // Bot detection - temporarily disabled for debugging
    // $botCheck = Captcha::detectBot();
    // if ($botCheck['is_bot']) {
    //     // Silently reject bots (don't give them feedback)
    //     error_log('Bot signup attempt detected: ' . $botCheck['reason'] . ' | IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    //     sleep(2); // Slow down bot
    //     $_SESSION['signup_error'] = 'invalid_request';
    //     header('Location: ' . $redirectUrl);
    //     exit;
    // }

    // CAPTCHA validation
    $captchaAnswer = trim($_POST['captcha_answer'] ?? '');
    $captchaHash = $_POST['captcha_hash'] ?? '';
    $captchaTimestamp = $_POST['captcha_timestamp'] ?? 0;

    $captchaCheck = Captcha::validate($captchaAnswer, $captchaHash, $captchaTimestamp);
    if (!$captchaCheck['valid']) {
        $_SESSION['signup_error'] = $captchaCheck['error'];
        header('Location: ' . $redirectUrl);
        exit;
    }

    if (empty($first_name) || empty($last_name) || empty($email) || empty($password) || empty($confirm_password)) {
        $_SESSION['signup_error'] = 'empty';
        header('Location: ' . $redirectUrl);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['signup_error'] = 'invalid_email';
        header('Location: ' . $redirectUrl);
        exit;
    }
    if ($db->has('users', ['email' => $email])) {
        $_SESSION['signup_error'] = 'email_exists';
        header('Location: ' . $redirectUrl);
        exit;
    }
    if ($password !== $confirm_password) {
        $_SESSION['signup_error'] = 'password_mismatch';
        header('Location: ' . $redirectUrl);
        exit;
    }
    if (strlen($password) < 6) {
        $_SESSION['signup_error'] = 'password_weak';
        header('Location: ' . $redirectUrl);
        exit;
    }
    if (!$terms) {
        $_SESSION['signup_error'] = 'terms_required';
        header('Location: ' . $redirectUrl);
        exit;
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
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
        $_SESSION['signup_error'] = 'registration_failed';
        header('Location: ' . $redirectUrl);
        exit;
    }

    $verificationToken = bin2hex(random_bytes(32));

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
        'reset_token' => $verificationToken,
        'reset_token_expires' => date('Y-m-d H:i:s', time() + (24 * 3600)),
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    try {
        $result = $db->insert('users', $insert_data);
        $userId = $db->id();

        if ($result && $userId) {
            logUserActivity($db, $userId, 'signup', 'User registered successfully');

            // Trigger signup webhook
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
                'source' => 'web'
            ]);

            $notificationResults = sendNotification($userId, 'signup_welcome', []);

            $emailSent = $notificationResults['email'] ?? false;
            $whatsappSent = $notificationResults['whatsapp'] ?? false;
            $smsSent = $notificationResults['sms'] ?? false;

            unset($_SESSION['form_data']);
            unset($_SESSION['signup_type']); // Clear signup type on success

            if ($emailSent || $whatsappSent || $smsSent) {
                $_SESSION['success'] = T::success_registration_complete;
            } else {
                $_SESSION['success'] = T::success_registration_no_email;
            }

            header('Location: ' . root . 'login');
            exit;
        } else {
            error_log('Signup failed - Database insert returned false for email: ' . $email);
            $_SESSION['signup_error'] = 'registration_failed';
            header('Location: ' . $redirectUrl);
            exit;
        }
    } catch (Exception $e) {
        error_log('Signup exception: ' . $e->getMessage() . ' | Email: ' . $email);
        $_SESSION['signup_error'] = 'registration_failed';
        header('Location: ' . $redirectUrl);
        exit;
    }
});

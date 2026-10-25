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

    // Countries for the (optional) phone country-code selector — same query the
    // customer profile screen uses (app/routes/users/profileRoutes.php), so the
    // signup phone field reuses the identical dropdown rather than a new widget.
    $countries = $db->select('countries', ['iso', 'nicename', 'phonecode'], ['ORDER' => ['nicename' => 'ASC']]);

    $title = T::signup;
    $description = T::signup_description;
    $header = true;
    $footer = true;
    // WHITE-LABEL: render signup inside the hotel's booking chrome on a tenant host, so the
    // page doesn't leak the GoGlobia platform brand onto the hotel's own domain.
    $__wlChrome = function_exists('wl_booking_tenant') ? wl_booking_tenant($db) : false;
    if ($__wlChrome) {
        require views . "supplier/stays/site/_bookingchrome_head.php";
        require_once views . "auth/signup.php";
        require views . "supplier/stays/site/_bookingchrome_foot.php";
    } else {
        require_once views."includes/header.php";
        require_once views."auth/signup.php";
        require_once views."includes/footer.php";
    }
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
    // Phone is OPTIONAL. Do NOT default the country code to '92' (Pakistan) when
    // the user leaves it blank — that was writing a wrong code onto every
    // phone-less signup. Empty stays empty; getPhoneCode('') returns '' below.
    $phone_country_code = trim($_POST['phone_country_code'] ?? '');
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

    // Phone is OPTIONAL — only validate/normalise when the user actually typed one.
    // If a number is given, a country code is required and the digits must be a
    // plausible E.164 (6–15 digits). Store the national digits (country code kept
    // separately in phone_country_code), matching the rest of the app.
    if ($phone !== '') {
        if ($phone_country_code === '') {
            $_SESSION['signup_error'] = 'phone_country_required';
            header('Location: ' . $redirectUrl);
            exit;
        }
        if (!validatePhoneForCountry($phone, $phone_country_code, $db)) {
            $_SESSION['signup_error'] = 'phone_invalid';
            header('Location: ' . $redirectUrl);
            exit;
        }
        $phone = normalizePhoneForStorage($phone, $phone_country_code, $db);
    } else {
        // No number → no stray country code either.
        $phone_country_code = '';
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
        // Agents require admin approval (Agent ERP): they start 'pending' so they
        // surface in the admin approval queue. Email verification confirms the
        // address but does NOT activate them (see emailVerificationRoutes.php) —
        // an admin must approve. Everyone else keeps the normal 'inactive' gate.
        'status' => ($userRole === 'agent') ? 'pending' : 'inactive',
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

            // REFERRAL — mint this new customer their own shareable code, and (if they
            // arrived via someone's code) attach them as a referee. referral_attach()
            // enforces the fraud rules (program-enabled, valid code, no self-referral,
            // one referrer per referee). All no-ops when the program is disabled.
            if (function_exists('referral_get_or_mint_code')) {
                try {
                    referral_get_or_mint_code($db, $insert_data['user_id']);
                    $refCodeIn = strtoupper(trim((string) ($_POST['ref'] ?? $_SESSION['pending_ref_code'] ?? '')));
                    if ($refCodeIn !== '' && function_exists('referral_attach')) {
                        referral_attach($db, $insert_data['user_id'], $refCodeIn);
                    }
                    unset($_SESSION['pending_ref_code']);
                } catch (\Throwable $e) {
                    error_log('signup referral: ' . $e->getMessage());
                }
            }

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

            // PHONE VERIFY (optional): if the new account has a phone, issue a
            // purpose-scoped OTP and deliver it over WhatsApp (SMS fallback). This
            // is non-blocking — email verification still gates status=active; the
            // phone OTP only sets users.phone_verified when confirmed at
            // /verify-phone. Fire-and-forget; failures are logged, never fatal.
            if ($phone !== '' && function_exists('otp_issue') && function_exists('sendOtpWhatsApp')) {
                try {
                    $otpRes = otp_issue($db, $insert_data['user_id'], 'phone_verify');
                    if (!empty($otpRes['ok'])) {
                        sendOtpWhatsApp($db, $phone, $phone_country_code, $otpRes['code'], $first_name);
                        $_SESSION['pending_phone_verify_user'] = $insert_data['user_id'];
                    }
                } catch (\Throwable $e) {
                    error_log('signup phone-verify otp: ' . $e->getMessage());
                }
            }

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

            // If a phone was provided, send them to the (optional, skippable)
            // phone-verify step where the WhatsApp OTP just issued can be entered.
            // Otherwise the usual login redirect (email verification handles that).
            if (!empty($_SESSION['pending_phone_verify_user'])) {
                header('Location: ' . root . 'verify-phone');
                exit;
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

// ============================================================================
// PHONE VERIFICATION (optional) — confirm users.phone via a WhatsApp OTP.
// Reached after a signup that supplied a phone ($_SESSION['pending_phone_verify_user']),
// or by a logged-in user who wants to verify their number. Uses the shared OTP
// engine (app/lib/otp.php), purpose 'phone_verify'. Non-blocking: it only sets
// users.phone_verified; it never gates login/activation (email handles that).
// ============================================================================
$router->get('/verify-phone', function () use ($SECURE, $db) {
    // Who is verifying: a just-signed-up user, or the logged-in user.
    $uid = (string) ($_SESSION['pending_phone_verify_user'] ?? $_SESSION['user_id'] ?? '');
    if ($uid === '') {
        header('Location: ' . root . 'login');
        exit;
    }
    $u = $db->get('users', ['phone', 'phone_country_code', 'phone_verified'], ['user_id' => $uid]);
    if (!$u || trim((string) ($u['phone'] ?? '')) === '') {
        // Nothing to verify.
        header('Location: ' . root . (isset($_SESSION['user_id']) ? 'dashboard' : 'login'));
        exit;
    }
    if ((int) ($u['phone_verified'] ?? 0) === 1) {
        unset($_SESSION['pending_phone_verify_user']);
        header('Location: ' . root . (isset($_SESSION['user_id']) ? 'dashboard' : 'login'));
        exit;
    }
    // Masked phone for display (show only last 3 digits).
    $digits = preg_replace('/\D/', '', (string) $u['phone']);
    $maskedPhone = strlen($digits) > 3 ? str_repeat('•', max(0, strlen($digits) - 3)) . substr($digits, -3) : $digits;

    $title = T::verify_phone ?? 'Verify your phone';
    $header = true;
    $footer = true;
    require_once views . "includes/header.php";
    require_once views . "auth/verify-phone.php";
    require_once views . "includes/footer.php";
});

$router->post('/verify-phone', function () use ($SECURE, $db) {
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['verify_phone_error'] = 'Security check failed. Please try again.';
        header('Location: ' . root . 'verify-phone');
        exit;
    }
    $uid  = (string) ($_SESSION['pending_phone_verify_user'] ?? $_SESSION['user_id'] ?? '');
    $code = trim($_POST['otp'] ?? '');
    if ($uid === '') {
        header('Location: ' . root . 'login');
        exit;
    }
    $res = otp_verify($db, $uid, 'phone_verify', $code);
    if (!empty($res['ok'])) {
        try {
            $db->update('users', ['phone_verified' => 1], ['user_id' => $uid]);
        } catch (\Throwable $e) {
            error_log('verify-phone set flag: ' . $e->getMessage());
        }
        unset($_SESSION['pending_phone_verify_user']);
        if (function_exists('logUserActivity')) {
            $row = $db->get('users', ['id'], ['user_id' => $uid]);
            if ($row) { logUserActivity($db, $row['id'], 'phone_verified', 'Phone verified via OTP'); }
        }
        $_SESSION['verify_phone_success'] = 'Your phone number has been verified.';
        header('Location: ' . root . (isset($_SESSION['user_id']) ? 'dashboard' : 'login'));
        exit;
    }
    $_SESSION['verify_phone_error'] = $res['message'] ?? 'Verification failed.';
    header('Location: ' . root . 'verify-phone');
    exit;
});

$router->post('/verify-phone/resend', function () use ($SECURE, $db) {
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['verify_phone_error'] = 'Security check failed. Please try again.';
        header('Location: ' . root . 'verify-phone');
        exit;
    }
    $uid = (string) ($_SESSION['pending_phone_verify_user'] ?? $_SESSION['user_id'] ?? '');
    if ($uid === '') {
        header('Location: ' . root . 'login');
        exit;
    }
    $u = $db->get('users', ['phone', 'phone_country_code', 'first_name'], ['user_id' => $uid]);
    if ($u && trim((string) ($u['phone'] ?? '')) !== '') {
        $res = otp_issue($db, $uid, 'phone_verify');
        if (!empty($res['ok'])) {
            sendOtpWhatsApp($db, $u['phone'], $u['phone_country_code'] ?? '', $res['code'], $u['first_name'] ?? '');
            $_SESSION['verify_phone_success'] = 'A new code has been sent.';
        } else {
            $_SESSION['verify_phone_error'] = $res['message'] ?? 'Please wait before requesting another code.';
        }
    }
    header('Location: ' . root . 'verify-phone');
    exit;
});

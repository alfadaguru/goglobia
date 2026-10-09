<?php
// FILE: app/routes/users/supplierSignupRoutes.php
// Supplier self-registration — GET form + POST handler.
//
// A supplier registers to offer their own services. The account is created in a
// PENDING state (role='supplier', status='pending') and cannot log in until an
// admin approves it (see app/routes/admin/suppliersRoutes.php and the status
// gate in app/routes/users/loginRoutes.php). Mirrors the customer/agent signup
// flow (CSRF + CAPTCHA + dup-email + webhook) so it stays consistent with the
// rest of the codebase. The 'Supplier' role already ships in users_roles (id 2).

@$SECURE or die('Access Denied!');

// ====================================
// SUPPLIER SIGNUP — FORM
// ====================================
$router->get('/supplier-signup', function () use ($SECURE, $db) {
    // Already logged in → send to the right home.
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        $dest = (($_SESSION['user_role'] ?? '') === 'supplier') ? 'supplier/dashboard' : 'dashboard';
        header('Location: ' . root . $dest);
        exit;
    }

    // Supplier signups are gated by settings.supplier_registration ("Allow
    // suppliers to register and list their services"). It ships DEFAULT '0'
    // (closed), so signups are open only when it is explicitly '1' — an admin
    // turns the feature on in Settings.
    if (($GLOBALS['app']['supplier_registration'] ?? '0') !== '1') {
        $_SESSION['login_error'] = 'supplier_registration_disabled';
        header('Location: ' . root . 'login');
        exit;
    }

    // Generate a fresh CAPTCHA (same mechanism as /signup).
    $captchaData = Captcha::generate();
    $_SESSION['captcha_data'] = $captchaData;

    $title = 'Become a Supplier';
    $description = 'Register as a supplier and list your travel services.';
    $header = true;
    $footer = true;
    require_once views . "includes/header.php";
    require_once views . "auth/supplier-signup.php";
    require_once views . "includes/footer.php";
});

// ====================================
// SUPPLIER SIGNUP — SUBMIT
// ====================================
$router->post('/supplier-signup', function () use ($SECURE, $db) {
    $redirectUrl = root . 'supplier-signup';

    // Operator toggle (same gate as the GET route): open only when enabled.
    if (($GLOBALS['app']['supplier_registration'] ?? '0') !== '1') {
        $_SESSION['login_error'] = 'supplier_registration_disabled';
        header('Location: ' . root . 'login');
        exit;
    }

    // Collect + keep for repopulation on error.
    $first_name         = trim($_POST['first_name'] ?? '');
    $last_name          = trim($_POST['last_name'] ?? '');
    $company            = trim($_POST['company'] ?? '');
    $email              = trim($_POST['email'] ?? '');
    $password           = trim($_POST['password'] ?? '');
    $confirm_password   = trim($_POST['confirm_password'] ?? '');
    $phone              = trim($_POST['phone'] ?? '');
    $phone_country_code = trim($_POST['phone_country_code'] ?? '92');
    $terms              = isset($_POST['terms']);

    $_SESSION['supplier_form_data'] = [
        'first_name' => $first_name,
        'last_name'  => $last_name,
        'company'    => $company,
        'email'      => $email,
        'phone'      => $phone,
        'phone_country_code' => $phone_country_code,
    ];

    // CSRF (same validator as /signup).
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['supplier_signup_error'] = 'csrf';
        header('Location: ' . $redirectUrl);
        exit;
    }

    // CAPTCHA.
    $captchaCheck = Captcha::validate(
        trim($_POST['captcha_answer'] ?? ''),
        $_POST['captcha_hash'] ?? '',
        $_POST['captcha_timestamp'] ?? 0
    );
    if (!$captchaCheck['valid']) {
        $_SESSION['supplier_signup_error'] = $captchaCheck['error'];
        header('Location: ' . $redirectUrl);
        exit;
    }

    // Field validation (mirrors /signup).
    if (empty($first_name) || empty($last_name) || empty($email) || empty($password) || empty($confirm_password)) {
        $_SESSION['supplier_signup_error'] = 'empty';
        header('Location: ' . $redirectUrl);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['supplier_signup_error'] = 'invalid_email';
        header('Location: ' . $redirectUrl);
        exit;
    }
    if ($db->has('users', ['email' => $email])) {
        $_SESSION['supplier_signup_error'] = 'email_exists';
        header('Location: ' . $redirectUrl);
        exit;
    }
    if ($password !== $confirm_password) {
        $_SESSION['supplier_signup_error'] = 'password_mismatch';
        header('Location: ' . $redirectUrl);
        exit;
    }
    if (strlen($password) < 6) {
        $_SESSION['supplier_signup_error'] = 'password_weak';
        header('Location: ' . $redirectUrl);
        exit;
    }
    if (!$terms) {
        $_SESSION['supplier_signup_error'] = 'terms_required';
        header('Location: ' . $redirectUrl);
        exit;
    }

    // Unique user_id (same retry pattern as /signup).
    $custom_user_id = generateUserId();
    $retry = 0;
    while ($retry < 5) {
        if (!$db->has('users', ['user_id' => $custom_user_id])) {
            break;
        }
        $custom_user_id = generateUserId();
        $retry++;
    }
    if ($retry >= 5) {
        $_SESSION['supplier_signup_error'] = 'registration_failed';
        header('Location: ' . $redirectUrl);
        exit;
    }

    $default_curr  = $db->get('currencies', 'name', ['default' => 1]);
    $user_currency = $default_curr ?? 'USD';

    // A supplier starts PENDING. email_verified = 1 because the gate here is admin
    // approval, not email verification — we do not want a second gate. The
    // company name is kept in `title` (a free-text column on users) so we don't
    // need a new column for a first build.
    $insert_data = [
        'user_id'            => $custom_user_id,
        'title'              => $company,
        'first_name'         => $first_name,
        'last_name'          => $last_name,
        'email'              => $email,
        'phone'              => $phone,
        'phone_country_code' => getPhoneCode($phone_country_code, $db),
        'currency'           => $user_currency,
        'password'           => password_hash($password, PASSWORD_DEFAULT),
        'role'               => 'supplier',
        'status'             => 'pending',
        'banned'             => 0,
        'email_verified'     => 1,
        'login_attempts'     => 0,
        'locked_until'       => null,
        'created_at'         => date('Y-m-d H:i:s'),
        'updated_at'         => date('Y-m-d H:i:s'),
    ];

    try {
        $result = $db->insert('users', $insert_data);
        $newId  = $db->id();

        if (!$result || !$newId) {
            error_log('Supplier signup failed - insert returned false for: ' . $email);
            $_SESSION['supplier_signup_error'] = 'registration_failed';
            header('Location: ' . $redirectUrl);
            exit;
        }

        logUserActivity($db, $newId, 'signup', 'Supplier registered — pending approval');

        if (function_exists('triggerWebhook')) {
            triggerWebhook('users/signup', 'signup.success', [
                'user_id'    => $custom_user_id,
                'email'      => $email,
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'role'       => 'supplier',
                'status'     => 'pending',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'timestamp'  => date('Y-m-d H:i:s'),
                'source'     => 'web',
            ]);
        }

        // Confirmation email to the supplier + alert to the site admin. We use
        // SENDEMAIL() directly (as notify.php/quick-login do) rather than a
        // template dir, since there is no generic 'users' notification template.
        $supplierName = trim($first_name . ' ' . $last_name);
        $companyName  = $GLOBALS['app']['business_name'] ?? ($GLOBALS['app']['app_name'] ?? 'Our Platform');
        if (function_exists('SENDEMAIL')) {
            try {
                SENDEMAIL(
                    $email,
                    $supplierName,
                    'Your supplier application has been received',
                    '<p>Hi ' . htmlspecialchars($supplierName) . ',</p>'
                    . '<p>Thank you for applying to become a supplier on ' . htmlspecialchars($companyName) . '. '
                    . 'Your application is now <strong>pending review</strong> by our team. '
                    . 'You will receive an email as soon as a decision is made, and you can log in once your account is approved.</p>'
                    . '<p>— ' . htmlspecialchars($companyName) . '</p>',
                    null
                );
            } catch (\Throwable $e) {
                error_log('Supplier signup: applicant email failed — ' . $e->getMessage());
            }

            // Notify the first admin (best-effort).
            try {
                $adminEmail = $GLOBALS['app']['contact_email'] ?? '';
                if (empty($adminEmail)) {
                    $adminRow = $db->get('users', ['email'], ['role' => 'admin', 'ORDER' => ['id' => 'ASC']]);
                    $adminEmail = $adminRow['email'] ?? '';
                }
                if (!empty($adminEmail)) {
                    SENDEMAIL(
                        $adminEmail,
                        'Administrator',
                        'New supplier application pending approval',
                        '<p>A new supplier has registered and is awaiting approval:</p>'
                        . '<ul>'
                        . '<li><strong>Name:</strong> ' . htmlspecialchars($supplierName) . '</li>'
                        . '<li><strong>Company:</strong> ' . htmlspecialchars($company !== '' ? $company : '—') . '</li>'
                        . '<li><strong>Email:</strong> ' . htmlspecialchars($email) . '</li>'
                        . '<li><strong>Phone:</strong> ' . htmlspecialchars($phone) . '</li>'
                        . '</ul>'
                        . '<p><a href="' . root . 'admin/suppliers">Review pending suppliers</a></p>',
                        null
                    );
                }
            } catch (\Throwable $e) {
                error_log('Supplier signup: admin alert failed — ' . $e->getMessage());
            }
        }

        unset($_SESSION['supplier_form_data']);
        $_SESSION['supplier_signup_success'] = 'pending';
        header('Location: ' . root . 'login');
        exit;
    } catch (\Throwable $e) {
        error_log('Supplier signup exception: ' . $e->getMessage() . ' | Email: ' . $email);
        $_SESSION['supplier_signup_error'] = 'registration_failed';
        header('Location: ' . $redirectUrl);
        exit;
    }
});

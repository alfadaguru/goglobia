<?php
// app/routes/ajaxRoutes.php
@$SECURE or die('Access Denied!');

$router->post('/ajax', function () use ($SECURE,$db) {
    // Get JSON input to check action type
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    // Fallback for form-urlencoded (preventing scanner false positives)
    if (!$input && !empty($_POST)) {
        $input = $_POST;
    }

    $action = $input['action'] ?? '';

    // CRUD-related actions - delegate to CRUD library
    $crud_actions = ['toggle_status', 'set_default', 'check_default', 'delete_record'];
    if (in_array($action, $crud_actions)) {
        
        // 1. Authentication & Role Check
        if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Forbidden: Admin access required']);
            exit();
        }

        // 2. CSRF Protection
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $isAjax = (isset($headers['X-Requested-With']) && strtolower($headers['X-Requested-With']) === 'xmlhttprequest') || 
                  (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $isSameOrigin = !empty($referer) && parse_url($referer, PHP_URL_HOST) === $host;

        if (!$isAjax && !$isSameOrigin) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'CSRF validation failed']);
            exit();
        }

        // 3. Strict Table Whitelist
        $allowed_tables = [
            'blog_categories', 'blogs', 'bookings', 'cars', 'cars_settings', 'cms',
            'countries', 'credits', 'currencies', 'deposit', 'favorites', 'flights',
            'flights_airlines', 'flights_airports', 'languages', 'locations',
            'logs_bookings', 'logs_searches', 'logs_transactions', 'logs_users',
            'logs_webhooks', 'modules', 'payment_gateways', 'promo_codes', 'stays', 'stays_rooms',
            'stays_settings', 'tickets', 'tours', 'tours_settings', 'transactions',
            'umrah', 'umrah_settings', 'users', 'users_roles', 'visa', 'visa_bookings', 'visa_settings',
            'airalo_packages', 'airalo_countries',
            'bus', 'bus_routes', 'bus_operators', 'bus_settings',
            'ai_suggestions'
        ];

        $table = $input['table'] ?? '';

        // TBO Holidays content hotels live in the module content DB
        if ($table === 'tbo_hotels') {
            require_once __DIR__ . '/../../modules/stays/tbo-holidays/api.php';
            $tboModule = tboHolidaysGetModule($db);
            if (!$tboModule) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => 'TBO Holidays module not found']);
                exit();
            }
            try {
                $contentDb = tboHolidaysContentDb($tboModule);
                CRUD::handleAjax($contentDb);
            } catch (Exception $e) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            }
            exit();
        }

        if (empty($table) || !in_array($table, $allowed_tables)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid table specified']);
            exit();
        }

        CRUD::handleAjax($db);
        exit();
    }

    // Clean any output buffer and start fresh for non-CRUD actions
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    // Set JSON header first
    header('Content-Type: application/json');

    // Handle other non-CRUD actions below
    // Add your custom AJAX handlers here

    // Sitemap generation action
    if ($action === 'generate_sitemap') {
        $result = generateSitemap($db);
        ob_end_clean();
        echo json_encode($result);
        exit();
    }
    
    // Server-side validation to reject unknown actions with an error instead of success
    // This stops scanners from reporting false positives on "insert_record" or "update_record"
    if (!empty($action) && $action !== 'generate_sitemap') {
        ob_end_clean();
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid or unsupported action: ' . htmlspecialchars($action),
        ]);
        exit();
    }

    // Default response for other actions
    ob_end_clean();
    echo json_encode([
        'status' => 'success',
        'message' => 'AJAX route is working!',
    ]);
    exit();

});

$router->post('/markup_calculate', function () use ($SECURE, $db) {
    // Get POST data
    $price = floatval($_POST['price'] ?? 0);
    $type = $_POST['type'] ?? '';

    // Clean any output buffer
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    // Set JSON header
    header('Content-Type: application/json');

    try {
        // Call MARKUP function
        $result = MARKUP($price, $type, $db);

        // Return result as JSON
        ob_end_clean();
        echo json_encode([
            'status' => 'success',
            'data' => $result,
        ]);
    } catch (Exception $e) {
        // Handle errors
        ob_end_clean();
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage(),
        ]);
    }
    exit();
});

// ====================================
// AJAX LOGOUT ENDPOINT
// ====================================
$router->post('/ajax/logout', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        // Check if user is logged in
        if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_logged_in'])) {
            echo json_encode([
                'success' => false,
                'message' => 'Not logged in'
            ]);
            exit;
        }

        // LOG RECORDS
        if (isset($_SESSION['user_id'])) {
            $db->insert('logs_users', [
                'user_id' => $_SESSION['user_id'] ?? null,
                'type' => 'logout',
                'description' => 'User logged out successfully via AJAX',
                'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }

        // Destroy all session data
        session_destroy();

        // Start new session for future use
        session_start();
        $_SESSION['theme'] = 'default';

        // Clear Remember Me cookie
        setcookie('remember_me', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);

        echo json_encode([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Logout failed: ' . $e->getMessage()
        ]);
    }
    exit;
});

// ====================================
// AJAX LOGIN ENDPOINT
// ====================================
$router->post('/ajax/login', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);

        $email = trim($input['email'] ?? '');
        $password = trim($input['password'] ?? '');

        if (empty($email) || empty($password)) {
            echo json_encode([
                'success' => false,
                'message' => 'Email and password are required'
            ]);
            exit;
        }

        $user = $db->get('users', '*', ['email' => $email]);
        if (!$user) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid email or password'
            ]);
            exit;
        }

        $userId = (int)$user['id'];

        // Check if account is locked
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            echo json_encode([
                'success' => false,
                'message' => 'Account is locked. Please try again later.'
            ]);
            exit;
        }

        // Verify password
        if (password_verify($password, $user['password'])) {
            // Check email verification
            if (!$user['email_verified']) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Please verify your email address first'
                ]);
                exit;
            }

            // Check account status
            if ($user['status'] !== 'active') {
                echo json_encode([
                    'success' => false,
                    'message' => 'Account is not active'
                ]);
                exit;
            }

            if ($user['banned']) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Account has been banned'
                ]);
                exit;
            }

            // Reset login attempts
            $db->update('users', [
                'login_attempts' => 0,
                'locked_until' => null,
                'last_login' => date('Y-m-d H:i:s')
            ], ['id' => $userId]);

            // Set session variables
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['login_time'] = time();

            if ($user['role'] === 'admin') {
                $_SESSION['admin_logged_in'] = true;
            }

            // Log activity
            $db->insert('logs_users', [
                'user_id' => $user['user_id'] ?? null,
                'type' => 'login',
                'description' => 'User logged in successfully via AJAX',
                'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'created_at' => date('Y-m-d H:i:s')
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Login successful',
                'user' => [
                    'name' => $user['first_name'] . ' ' . $user['last_name'],
                    'email' => $user['email'],
                    'role' => $user['role']
                ]
            ]);

        } else {
            // Invalid password - increment login attempts
            $newAttempts = ($user['login_attempts'] ?? 0) + 1;
            $maxAttempts = 5;
            $updateData = ['login_attempts' => $newAttempts];

            if ($newAttempts >= $maxAttempts) {
                $lockUntil = date('Y-m-d H:i:s', time() + (30 * 60));
                $updateData['locked_until'] = $lockUntil;

                $db->update('users', $updateData, ['id' => $userId]);

                echo json_encode([
                    'success' => false,
                    'message' => 'Too many failed attempts. Account locked for 30 minutes.'
                ]);
            } else {
                $db->update('users', $updateData, ['id' => $userId]);

                $remaining = $maxAttempts - $newAttempts;
                echo json_encode([
                    'success' => false,
                    'message' => "Invalid password. $remaining attempts remaining."
                ]);
            }
        }

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Login error: ' . $e->getMessage()
        ]);
    }
    exit;
});

// ====================================
// UPDATE BOOKING PAYMENT GATEWAY
// ====================================
$router->post('/api/booking/update-payment-gateway', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        // ── Authentication ──────────────────────────────────────────────────
        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }

        $sessionUserId = (int) $_SESSION['user_id'];
        $isAdmin       = !empty($_SESSION['admin_logged_in'])
                      || (strtolower($_SESSION['user_role'] ?? '') === 'admin');

        // ── Input validation ─────────────────────────────────────────────────
        $input = json_decode(file_get_contents('php://input'), true);

        $invoiceId      = trim((string) ($input['invoice_id']      ?? ''));
        $paymentGateway = trim((string) ($input['payment_gateway'] ?? ''));

        if ($invoiceId === '' || $paymentGateway === '') {
            throw new Exception('Invoice ID and payment gateway are required');
        }

        // Reject obviously malformed invoice IDs
        if (!preg_match('/^[A-Za-z0-9\-_]+$/', $invoiceId)) {
            throw new Exception('Invalid invoice ID format');
        }

        // ── Validate gateway exists in DB (accepts gateway id or name) ──────
        $gatewayExists = $db->has('payment_gateways', ['name' => $paymentGateway])
            || (ctype_digit($paymentGateway) && $db->has('payment_gateways', ['id' => (int)$paymentGateway]));
        if (!$gatewayExists) {
            throw new Exception('Invalid payment gateway');
        }

        // ── Ownership check ──────────────────────────────────────────────────
        $booking = $db->get('bookings', ['user_id', 'invoice_id'], ['invoice_id' => $invoiceId]);

        if (!$booking) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Booking not found']);
            exit;
        }

        if (!$isAdmin && (int) $booking['user_id'] !== $sessionUserId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden']);
            exit;
        }

        // ── Update ───────────────────────────────────────────────────────────
        $result = $db->update('bookings', [
            'payment_gateway' => $paymentGateway
        ], [
            'invoice_id' => $invoiceId
        ]);

        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Payment gateway updated successfully']);
        } else {
            throw new Exception('Failed to update payment gateway');
        }

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});

// ====================================
// RESEND INVOICE ENDPOINT (UNIFIED)
// ====================================
$router->post('/api/booking/resend-invoice', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $invoiceId = trim($input['invoice_id'] ?? '');
        $emailOverride = trim($input['customer_email'] ?? '');

        if (empty($invoiceId)) {
            throw new Exception('Invoice ID is required');
        }

        // 1. Find Booking
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) {
            throw new Exception('Booking not found');
        }

        // 2. Identify Module
        $module = strtolower($booking['module_type'] ?? $booking['module'] ?? 'stays');
        if ($module === 'hotels') { $module = 'stays'; }
        if ($module === 'amadeus') { $module = 'flights'; }

        // 3. Prepare Customer Data
        $customerEmail = !empty($emailOverride) ? $emailOverride : $booking['email'];
        $customerData = [
            'email' => $customerEmail,
            'phone' => $booking['phone'] ?? '',
            'first_name' => $booking['first_name'] ?? '',
            'last_name' => $booking['last_name'] ?? '',
            'country_code' => $booking['phone_country_code'] ?? ''
        ];

        // 4. Generate PDF
        $pdfPath = GENERATE_BOOKING_PDF($invoiceId);

        // 5. Send Notification (Customer only)
        $notifData = [
            'invoice_id' => $invoiceId,
            'amount' => $booking['price_markup'] ?? $booking['price_original'] ?? 0,
            'currency' => $booking['currency_markup'] ?? $booking['currency'] ?? 'USD',
            'payment_status' => $booking['payment_status'] ?? 'unpaid',
            'pnr' => $booking['pnr'] ?? '',
            'first_name' => $booking['first_name'] ?? '',
            'last_name' => $booking['last_name'] ?? '',
            'adults' => (int)($booking['adults'] ?? 0),
            'childs' => (int)($booking['childs'] ?? 0),
        ];

        // Merge module-specific data if available
        $bDataDecoded = json_decode($booking['booking_data'] ?? '{}', true);
        if (!empty($bDataDecoded)) {
            $notifData = array_merge($bDataDecoded, $notifData);
        }

        NOTIFY::resend($module, $customerData, $notifData, $pdfPath);

        echo json_encode([
            'success' => true,
            'message' => 'Invoice resent successfully to ' . $customerEmail
        ]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
});


// ====================================
// GET USER WALLET BALANCE
// ====================================
$router->get('/api/user/wallet-balance', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        if (empty($_SESSION['user_id'])) {
            throw new Exception('User not logged in');
        }

        $userId = $_SESSION['user_id'];

        // Get user wallet balance
        $user = $db->get('users', ['balance', 'currency'], ['user_id' => $userId]);

        if ($user) {
            echo json_encode([
                'success' => true,
                'balance' => $user['balance'] ?? 0,
                'currency' => $user['currency'] ?? 'USD'
            ]);
        } else {
            throw new Exception('User not found');
        }

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
});

// ====================================
// GET USER CREDITS BALANCE
// ====================================
$router->get('/api/user/credits-balance', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        if (empty($_SESSION['user_id'])) {
            throw new Exception('User not logged in');
        }

        $userId = $_SESSION['user_id'];

        // Calculate available credits: SUM(credit) - SUM(debit)
        $creditSum = $db->sum('credits', 'credits', [
            'user_id' => $userId,
            'type' => 'credit'
        ]);

        $debitSum = $db->sum('credits', 'credits', [
            'user_id' => $userId,
            'type' => 'debit'
        ]);

        // Ensure values are properly converted to float, default to 0 if null/false/empty
        $creditSum = floatval($creditSum ?: 0);
        $debitSum = floatval($debitSum ?: 0);
        $availableCredits = $creditSum - $debitSum;

        // Get user's currency
        $user = $db->get('users', ['currency'], ['user_id' => $userId]);
        $currency = $user['currency'] ?? 'USD';

        echo json_encode([
            'success' => true,
            'credits' => floatval($availableCredits),
            'currency' => $currency
        ]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
});

// ====================================
// DEPOSIT REQUEST SUBMISSION
// ====================================
$router->post('/api/deposit/add', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        // ====================================
        // AUTHENTICATION CHECK
        // ====================================
        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'message' => T::login_required ?? 'Authentication required. Please log in.'
            ]);
            exit;
        }

        $userId = $_SESSION['user_id'];

        // ====================================
        // AUTHORIZATION CHECK - AGENT ONLY
        // ====================================
        $user = $db->get('users', ['id', 'user_id', 'role', 'email', 'first_name', 'last_name'], ['user_id' => $userId]);

        if (!$user || $user['role'] !== 'agent') {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => T::access_denied ?? 'Access Denied - Agents Only'
            ]);
            exit;
        }

        // ====================================
        // VALIDATE INPUT
        // ====================================
        $amount = floatval($_POST['amount'] ?? 0);
        $currency = trim($_POST['currency'] ?? '');
        $transactionId = trim($_POST['transaction_id'] ?? '');
        $paymentMethod = trim($_POST['payment_method'] ?? '');
        $details = trim($_POST['details'] ?? '');

        if ($amount <= 0) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => T::invalid_amount ?? 'Invalid amount. Please enter a valid amount.'
            ]);
            exit;
        }

        if (empty($currency) || empty($transactionId) || empty($paymentMethod)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => T::missing_fields ?? 'All required fields must be filled.'
            ]);
            exit;
        }

        // ====================================
        // VALIDATE FILE UPLOAD
        // ====================================
        if (empty($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => T::upload_required ?? 'Payment proof is required.'
            ]);
            exit;
        }

        $file = $_FILES['attachment'];
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
        $maxFileSize = 5 * 1024 * 1024; // 5MB

        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($fileExtension, $allowedExtensions)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => T::invalid_file_type ?? 'Invalid file type. Only JPG, PNG, and PDF files are allowed.'
            ]);
            exit;
        }

        if ($file['size'] > $maxFileSize) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => T::file_too_large ?? 'File size exceeds 5MB limit.'
            ]);
            exit;
        }

        // ====================================
        // CREATE UPLOAD DIRECTORY IF NOT EXISTS
        // ====================================
        $uploadDir = 'uploads/deposit/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // ====================================
        // GENERATE UNIQUE FILENAME
        // ====================================
        $uniqueId = uniqid('DEP_', true);
        $fileName = $uniqueId . '.' . $fileExtension;
        $uploadPath = $uploadDir . $fileName;

        // ====================================
        // MOVE UPLOADED FILE
        // ====================================
        if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => T::upload_failed ?? 'Failed to upload file. Please try again.'
            ]);
            exit;
        }

        // ====================================
        // GENERATE DEPOSIT ID
        // ====================================
        $depositId = 'TXN' . strtoupper(substr(uniqid(), -7));

        // ====================================
        // INSERT DEPOSIT RECORD
        // ====================================
        $result = $db->insert('deposit', [
            'id' => $depositId,
            'user_id' => $userId,
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
            // ====================================
            // LOG ACTIVITY (OPTIONAL)
            // ====================================
            $db->insert('logs_users', [
                'user_id' => $userId,
                'type' => 'deposit_request',
                'description' => 'Deposit request submitted: ' . $depositId . ' - ' . $currency . ' ' . $amount,
                'user_ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                'created_at' => date('Y-m-d H:i:s'),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);

            // ====================================
            // SEND NOTIFICATION
            // ====================================
            $depositData = [
                'id' => $depositId,
                'amount' => $amount,
                'currency' => $currency,
                'transaction_id' => $transactionId,
                'payment_method' => $paymentMethod,
                'details' => $details
            ];
            NOTIFY::deposit('new_request', $depositData, $user);

            echo json_encode([
                'status' => 'success',
                'message' => T::deposit_success ?? 'Your deposit request has been submitted successfully and is pending approval.',
                'deposit_id' => $depositId
            ]);
        } else {
            // Delete uploaded file if database insert fails
            if (file_exists($uploadPath)) {
                unlink($uploadPath);
            }

            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => T::database_error ?? 'Database error. Please try again later.'
            ]);
        }

    } catch (Exception $e) {
        // Delete uploaded file if it exists
        if (isset($uploadPath) && file_exists($uploadPath)) {
            unlink($uploadPath);
        }

        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Server error: ' . $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }
    exit;
});

// ============================================================================
// GET DEPOSIT DETAILS - For viewing deposit in modal
// ============================================================================
$router->get('/api/deposit/get', function () {
    global $db;

    try {
        // Check if user is logged in
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'message' => 'Unauthorized. Please login first.'
            ]);
            exit;
        }

        $depositId = $_GET['id'] ?? '';

        if (empty($depositId)) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Deposit ID is required'
            ]);
            exit;
        }

        // Fetch deposit record
        $deposit = $db->get('deposit', '*', [
            'id' => $depositId,
            'user_id' => $_SESSION['user_id']
        ]);

        if (!$deposit) {
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'Deposit not found'
            ]);
            exit;
        }

        echo json_encode([
            'status' => 'success',
            'deposit' => $deposit
        ]);

    } catch (Exception $e) {
        error_log("Get Deposit Error: " . $e->getMessage());

        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to load deposit details'
        ]);
    }
    exit;
});

// ============================================================================
// ADMIN: GET DEPOSIT DETAILS - For admin view/edit modal
// ============================================================================
$router->get('/api/admin/deposit/get', function () {
    global $db;

    header('Content-Type: application/json');

    try {
        // Check if admin is logged in
        if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Unauthorized access'
            ]);
            exit;
        }

        $depositId = $_GET['id'] ?? '';

        if (empty($depositId)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Deposit ID is required'
            ]);
            exit;
        }

        // Fetch deposit record
        $deposit = $db->get('deposit', '*', ['id' => $depositId]);

        if (!$deposit) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Deposit not found'
            ]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'deposit' => $deposit
        ]);

    } catch (Exception $e) {
        error_log("Admin Get Deposit Error: " . $e->getMessage());

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to load deposit details'
        ]);
    }
    exit;
});

// ============================================================================
// ADMIN: UPDATE DEPOSIT STATUS - Approve or Reject deposits
// ============================================================================
$router->post('/api/admin/deposit/update-status', function () {
    global $db;

    header('Content-Type: application/json');

    try {
        // ====================================
        // AUTHENTICATION CHECK - ADMIN ONLY
        // ====================================
        if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Unauthorized access'
            ]);
            exit;
        }

        // ====================================
        // VALIDATE INPUT
        // ====================================
        $input = json_decode(file_get_contents('php://input'), true);
        $depositId = trim($input['deposit_id'] ?? '');
        $newStatus = strtolower(trim($input['status'] ?? ''));

        if (empty($depositId)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Deposit ID is required'
            ]);
            exit;
        }

        if (!in_array($newStatus, ['approved', 'rejected'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid status. Must be "approved" or "rejected"'
            ]);
            exit;
        }

        // ====================================
        // FETCH DEPOSIT RECORD
        // ====================================
        $deposit = $db->get('deposit', '*', ['id' => $depositId]);

        if (!$deposit) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Deposit not found'
            ]);
            exit;
        }

        // ====================================
        // CHECK IF ALREADY PROCESSED
        // ====================================
        if (strtolower($deposit['status']) !== 'pending') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'This deposit has already been processed'
            ]);
            exit;
        }

        // ====================================
        // FETCH USER DETAILS
        // ====================================
        $user = $db->get('users', ['id', 'user_id', 'email', 'first_name', 'last_name', 'balance', 'currency'], [
            'user_id' => $deposit['user_id']
        ]);

        if (!$user) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'User not found'
            ]);
            exit;
        }

        // ====================================
        // START TRANSACTION
        // ====================================
        $db->pdo->beginTransaction();

        try {
            // ====================================
            // UPDATE DEPOSIT STATUS
            // ====================================
            $db->update('deposit', [
                'status' => $newStatus
            ], [
                'id' => $depositId
            ]);

            // ====================================
            // IF APPROVED - ADD TO TRANSACTIONS AND UPDATE BALANCE
            // ====================================
            if ($newStatus === 'approved') {
                $amount = floatval($deposit['amount']);
                $currentBalance = floatval($user['balance'] ?? 0);
                $newBalance = $currentBalance + $amount;

                // Generate unique transaction ID
                $transactionId = 'DEP-' . strtoupper(uniqid());

                // Insert into transactions table
                $db->insert('transactions', [
                    'user_id' => $deposit['user_id'],
                    'trx_id' => $transactionId,
                    'type' => 'credit',
                    'amount' => $amount,
                    'currency' => $deposit['currency'],
                    'description' => 'Deposit approved - ' . $deposit['payment_method'] . ' (Ref: ' . $deposit['transaction_id'] . ')',
                    'status' => 'approved',
                    'date' => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'),
                    'created_by' => $deposit['user_id'],
                    'client_email' => $user['email']
                ]);

                // Update user balance
                $userIntId = intval($user['id']);
                $db->update('users', [
                    'balance' => $newBalance
                ], [
                    'id' => $userIntId
                ]);
            }

            // ====================================
            // COMMIT TRANSACTION
            // ====================================
            $db->pdo->commit();

            // ====================================
            // SEND EMAIL NOTIFICATION
            // ====================================
            // Trigger deposit notification (Approved/Rejected)
            NOTIFY::deposit($newStatus, $deposit, $user);

            echo json_encode([
                'success' => true,
                'message' => $newStatus === 'approved'
                    ? 'Deposit approved successfully. User balance updated.'
                    : 'Deposit rejected successfully.'
            ]);

        } catch (Exception $e) {
            // Rollback on error
            $db->pdo->rollBack();
            throw $e;
        }

    } catch (Exception $e) {
        error_log("Update Deposit Status Error: " . $e->getMessage());

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update deposit status: ' . $e->getMessage()
        ]);
    }
    exit;
});

// Agency Update
$router->post('/api/agency/update', function() use ($db) {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    try {
        $user_id = $_SESSION['user_id'];
        
        // Validation
        $required_fields = ['agency_name', 'license_number', 'city', 'country', 'phone', 'address', 'email'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
                exit;
            }
        }

        // Email validation
        if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid email format']);
            exit;
        }

        // Handle logo upload
        $logo_path = null;
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png'];
            $max_size = 2 * 1024 * 1024; // 2MB

            if (!in_array($_FILES['logo']['type'], $allowed_types)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Only JPG and PNG files are allowed']);
                exit;
            }

            if ($_FILES['logo']['size'] > $max_size) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'File size must be less than 2MB']);
                exit;
            }

            $upload_dir = __DIR__ . '/../../uploads/agencies/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $extension = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            $filename = uniqid() . '_' . time() . '.' . $extension;
            $target_path = $upload_dir . $filename;

            if (move_uploaded_file($_FILES['logo']['tmp_name'], $target_path)) {
                $logo_path = 'uploads/agencies/' . $filename;
            }
        }

        // Prepare data
        $data = [
            'agency_name' => $_POST['agency_name'],
            'license_number' => $_POST['license_number'],
            'city' => $_POST['city'],
            'address' => $_POST['address'],
            'country' => $_POST['country'],
            'phone' => $_POST['phone'],
            'email' => $_POST['email'],
            'updated_at' => date('Y-m-d H:i:s')
        ];

        if ($logo_path) {
            $data['logo'] = $logo_path;
        }

        // Check if agency exists
        $existing = $db->get('agencies', '*', ['user_id' => $user_id]);

        if ($existing) {
            // Update existing
            $db->update('agencies', $data, ['user_id' => $user_id]);
        } else {
            // Insert new
            $data['user_id'] = $user_id;
            $data['created_at'] = date('Y-m-d H:i:s');
            $db->insert('agencies', $data);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Agency details updated successfully'
        ]);

    } catch (Exception $e) {
        error_log("Agency Update Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update agency details: ' . $e->getMessage()
        ]);
    }
    exit;
});

// ====================================
// SEARCH USERS - For ticket creation
// ====================================
$router->get('/api/users/search', function() use ($db) {
    header('Content-Type: application/json');

    try {
        // Check if admin/support is logged in
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }

        // Get search query
        $query = trim($_GET['q'] ?? '');
        
        if (strlen($query) < 2) {
            echo json_encode(['success' => true, 'users' => []]);
            exit;
        }

        // Search users by name or email
        $sql = "SELECT user_id, 
                       CONCAT(first_name, ' ', last_name) as name, 
                       email, 
                       phone 
                FROM users 
                WHERE (CONCAT(first_name, ' ', last_name) LIKE :query OR email LIKE :query) 
                AND status = 'active' 
                ORDER BY first_name ASC 
                LIMIT 20";
        
        $stmt = $db->pdo->prepare($sql);
        $stmt->execute(['query' => "%{$query}%"]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'users' => $users
        ]);

    } catch (Exception $e) {
        error_log("User Search Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Search failed: ' . $e->getMessage()
        ]);
    }
    exit;
});
<?php
// FILE: app/routes/users/profile.php
// User profile routes (GET & POST)

@$SECURE or die('Access Denied!');

// ====================================
// PROFILE ROUTES
// ====================================

$router->get('/profile', function () use ($SECURE,$db) {
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['login_error'] = 'required';
        header('Location: ' . root . 'login');
        exit;
    }

    // Get user data
    $user = $db->get('users', '*', ['user_id' => $_SESSION['user_id']]);

    if (!$user) {
        $_SESSION['login_error'] = 'required';
        header('Location: ' . root . 'login');
        exit;
    }

    // Get total bookings for sidebar
    $totalBookings = $db->count('bookings', ['user_id' => $_SESSION['user_id']]);

    // Prepare dashboard data for sidebar
    $dashboardData = [
        'user_name' => ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''),
        'user_email' => $user['email'] ?? '',
        'member_since' => $user['created_at'] ?? 'N/A',
        'account_status' => $user['status'] ?? 'active',
        'user_role' => $user['role'] ?? 'customer',
        'total_bookings' => $totalBookings
    ];

    // Get countries for dropdown
    $countries = $db->select('countries', ['iso', 'nicename', 'phonecode'], ['ORDER' => ['nicename' => 'ASC']]);

    // META DATA
    $title = "Profile";
    $description = "User profile";
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once views."auth/profile.php";
    require_once views."includes/footer.php";
});

$router->post('/profile', function () use ($SECURE,$db) {
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['login_error'] = 'required';
        header('Location: ' . root . 'login');
        exit;
    }

    // Verify CSRF token
    if (!CSRF::validateToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['profile_error'] = 'csrf';
        header('Location: ' . root . 'profile');
        exit;
    }

    // Handle change-password form separately (shares the same endpoint)
    if (($_POST['action'] ?? '') === 'change_password') {
        $current_password = trim($_POST['current_password'] ?? '');
        $new_password = trim($_POST['new_password'] ?? '');
        $confirm_password = trim($_POST['confirm_password'] ?? '');

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $_SESSION['profile_error'] = 'empty';
            header('Location: ' . root . 'profile');
            exit;
        }

        if ($new_password !== $confirm_password) {
            $_SESSION['profile_error'] = 'Passwords do not match';
            header('Location: ' . root . 'profile');
            exit;
        }

        if (strlen($new_password) < 6) {
            $_SESSION['profile_error'] = 'Password must be at least 6 characters';
            header('Location: ' . root . 'profile');
            exit;
        }

        $user = $db->get('users', ['password'], ['user_id' => $_SESSION['user_id']]);

        if (!$user || !password_verify($current_password, $user['password'])) {
            $_SESSION['profile_error'] = 'Current password is incorrect';
            header('Location: ' . root . 'profile');
            exit;
        }

        try {
            $db->update('users', [
                'password' => password_hash($new_password, PASSWORD_DEFAULT),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['user_id' => $_SESSION['user_id']]);

            $_SESSION['profile_success'] = 'Password updated successfully';
        } catch (Exception $e) {
            error_log('Password update error: ' . $e->getMessage());
            $_SESSION['profile_error'] = 'Database error: ' . $e->getMessage();
        }

        header('Location: ' . root . 'profile');
        exit;
    }

    // Get form data
    $title = trim($_POST['title'] ?? '');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone_country_code = trim($_POST['phone_country_code'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $po_box = trim($_POST['po_box'] ?? '');

    // Validate phone - only digits, no leading zero
    if (!empty($phone)) {
        $phone = preg_replace('/\D/', '', $phone);
        $phone = ltrim($phone, '0');
    }

    // Basic validation
    if (empty($first_name) || empty($last_name) || empty($email)) {
        $_SESSION['profile_error'] = 'empty';
        header('Location: ' . root . 'profile');
        exit;
    }

    try {
        // Get current user data to preserve email only
        $currentUser = $db->get('users', ['email'], ['user_id' => $_SESSION['user_id']]);

        // Build update array - allow first_name and last_name changes, protect email
        $updateData = [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $currentUser['email'],
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // Add optional fields - allow empty values to clear them
        $updateData['title'] = $title;
        $updateData['phone_country_code'] = getPhoneCode($phone_country_code, $db);
        $updateData['phone'] = $phone;
        $updateData['country'] = $country;
        $updateData['state'] = $state;
        $updateData['address'] = $address;
        $updateData['po_box'] = $po_box;

        $updated = $db->update('users', $updateData, ['user_id' => $_SESSION['user_id']]);

        if ($updated !== false) {
            // Get updated user data
            $updatedUser = $db->get('users', '*', ['user_id' => $_SESSION['user_id']]);
            
            // Trigger profile update webhook
            triggerWebhook('users/profile', 'profile.updated', [
                'user_id' => $_SESSION['user_id'],
                'email' => $updatedUser['email'],
                'first_name' => $first_name,
                'last_name' => $last_name,
                'phone' => $phone,
                'phone_country_code' => $phone_country_code,
                'address' => $address,
                'city' => $city ?? '',
                'state' => $state,
                'country' => $country ?? '',
                'po_box' => $po_box,
                'changed_fields' => array_keys($updateData),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            $_SESSION['profile_success'] = 'Profile updated successfully';
            $_SESSION['user_name'] = $first_name . ' ' . $last_name;
        } else {
            $_SESSION['profile_error'] = 'No changes were made';
        }

        header('Location: ' . root . 'profile');
        exit;

    } catch (Exception $e) {
        error_log('Profile update error: ' . $e->getMessage());
        $_SESSION['profile_error'] = 'Database error: ' . $e->getMessage();
        header('Location: ' . root . 'profile');
        exit;
    }
});

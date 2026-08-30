<?php
// FILE: app/routes/users/bookings.php
// User bookings route

@$SECURE or die('Access Denied!');

// ====================================
// BOOKINGS ROUTES
// ====================================

$router->get('/bookings', function () use ($SECURE,$db) {

    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['login_error'] = 'required';
        header('Location: ' . root . 'login');
        exit;
    }

    // Check if user is agent and has completed agency details
    $user = $db->get('users', '*', ['user_id' => $_SESSION['user_id']]);

    // CHECK AGENT AND REDRECTION IF NOT COMPLETED
    CHECK_AGENT($db);

    // Get user data and filter
    $user_id = $_SESSION['user_id'];
    $currentFilter = $_GET['filter'] ?? 'all';

    // Get user data for sidebar
    $user = $db->get('users', '*', ['user_id' => $user_id]);
    $dashboardData = [
        'user_name' => ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''),
        'user_email' => $user['email'] ?? '',
        'member_since' => $user['created_at'] ?? 'N/A',
        'account_status' => $user['status'] ?? 'active',
        'user_role' => $user['role'] ?? 'customer'
    ];

    // Fetch all user bookings
    $allBookings = $db->select('bookings', '*', [
        'user_id' => $user_id,
        'ORDER' => ['id' => 'DESC']
    ]);

    // Filter bookings based on module_type
    if ($currentFilter !== 'all') {
        $bookings = array_filter($allBookings, function($booking) use ($currentFilter) {
            return strtolower($booking['module_type'] ?? '') === strtolower($currentFilter);
        });
        $bookings = array_values($bookings); // Re-index array
    } else {
        $bookings = $allBookings;
    }

    // Calculate stats
    $totalAmount = array_sum(array_column($allBookings, 'price_markup'));
    $stats = [
        'total' => count($allBookings),
        'confirmed' => count(array_filter($allBookings, fn($b) => ($b['booking_status'] ?? '') === 'confirmed')),
        'pending' => count(array_filter($allBookings, fn($b) => ($b['booking_status'] ?? '') === 'pending')),
        'total_amount' => $totalAmount
    ];

    // Add total_bookings to dashboardData for sidebar
    $dashboardData['total_bookings'] = count($allBookings);

    // META DATA
    $title = "My Bookings";
    $description = "User bookings";

    require_once views."includes/header.php";
    require_once views."auth/bookings.php";
    require_once views."includes/footer.php";
});

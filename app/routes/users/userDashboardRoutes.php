<?php
// FILE: app/routes/users/dashboard.php
// User dashboard route

@$SECURE or die('Access Denied!');

// ====================================
// DASHBOARD ROUTES
// ====================================

$router->get('/dashboard', function () use ($SECURE,$db) {
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['login_error'] = 'required';
        header('Location: ' . root . 'login');
        exit;
    }

    // CHECK AGENT AND REDRECTION IF NOT COMPLETED
    CHECK_AGENT($db);
    
    // Get user data
    $user_id = $_SESSION['user_id'];
    $user = $db->get('users', '*', ['user_id' => $user_id]);

    // Fetch user bookings
    $allBookings = $db->select('bookings', '*', [
        'user_id' => $user_id,
        'ORDER' => ['id' => 'DESC']
    ]);

    // Calculate stats
    $totalBookings = count($allBookings);
    $confirmedBookings = count(array_filter($allBookings, fn($b) => ($b['booking_status'] ?? '') === 'confirmed'));
    $pendingBookings = count(array_filter($allBookings, fn($b) => ($b['booking_status'] ?? '') === 'pending'));
    $walletBalance = $user['balance'] ?? 0.00;
    $walletCurrency = $user['currency'] ?? 'USD';
    $isAgent = strtolower((string)($user['role'] ?? $_SESSION['user_role'] ?? '')) === 'agent';
    $totalAgentEarning = 0.0;
    if ($isAgent) {
        foreach ($allBookings as $b) {
            $totalAgentEarning += (float)($b['agent_earning'] ?? 0);
        }
    }

    // Initialize dashboard data
    $dashboardData = [
        'total_bookings' => $totalBookings,
        'pending_bookings' => $pendingBookings,
        'confirmed_bookings' => $confirmedBookings,
        'wallet_balance' => number_format($walletBalance, 2),
        'member_since' => $user['created_at'] ?? 'N/A',
        'recent_bookings' => array_slice($allBookings, 0, 5),
        'currency' => $walletCurrency,
        'is_agent' => $isAgent,
        'total_agent_earning' => $totalAgentEarning,
        'apply_markup' => $user['apply_markup'] ?? 'global',
        'markup_type' => $user['markup_type'] ?? 'percentage',
        'markup_value' => $user['markup_value'] ?? 0,
    ];

    // META DATA
    $title = "Dashboard";
    $description = "User dashboard";
    $header = true;
    $footer = true;
    require_once views."includes/header.php";
    require_once views."auth/dashboard.php";
    require_once views."includes/footer.php";
});

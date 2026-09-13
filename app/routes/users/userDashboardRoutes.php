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

    // LOYALTY (docs/MONEY-WALLET-AUDIT.md §C.4 step 5): current spendable points
    // + the scheme's redeem value, so the dashboard can offer "convert to wallet".
    $loyaltyEnabled = false;
    $loyaltyPoints = 0;
    $loyaltyRedeemValue = 0.0;
    if (function_exists('loyalty_config') && function_exists('loyalty_balance')) {
        $lc = loyalty_config($db);
        $loyaltyEnabled = !empty($lc['enabled']);
        $loyaltyRedeemValue = (float)($lc['redeem_value'] ?? 0);
        $loyaltyPoints = loyalty_balance($db, (string)$user_id);
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
        'loyalty_enabled' => $loyaltyEnabled,
        'loyalty_points' => $loyaltyPoints,
        'loyalty_redeem_value' => $loyaltyRedeemValue,
        'loyalty_worth' => number_format($loyaltyPoints * $loyaltyRedeemValue, 2),
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

// LOYALTY REDEEM → WALLET (docs/MONEY-WALLET-AUDIT.md §C.4 step 5)
// Customer or agent converts their loyalty points into wallet credit at the
// admin-configured redeem value. JSON endpoint, CSRF-guarded, login required.
$router->post('/loyalty/redeem', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Login required']); exit;
    }
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    if (!CSRF::validateToken($input['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']); exit;
    }
    if (!function_exists('loyalty_convert_to_wallet')) {
        echo json_encode(['success' => false, 'message' => 'Loyalty unavailable']); exit;
    }

    $userId = (string)$_SESSION['user_id'];
    $points = (int)($input['points'] ?? 0);
    if ($points <= 0) {
        echo json_encode(['success' => false, 'message' => 'Enter a positive number of points']); exit;
    }

    $currency = (string)($db->get('users', 'currency', ['user_id' => $userId]) ?: '');
    $res = loyalty_convert_to_wallet($db, $userId, $points, $currency);
    if (empty($res['ok'])) {
        echo json_encode(['success' => false, 'message' => $res['message'] ?? 'Conversion failed']); exit;
    }
    echo json_encode([
        'success'        => true,
        'points_left'    => $res['points_left'],
        'wallet_balance' => $res['wallet_balance'],
        'amount'         => $res['amount'],
    ]);
    exit;
});

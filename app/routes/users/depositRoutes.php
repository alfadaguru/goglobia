<?php
// app/routes/users/deposit.php
@$SECURE or die('Access Denied!');

// ============================================================================
// DEPOSIT PAGE - AGENT WALLET FUNDING
// ============================================================================
// PURPOSE: Allow agents to submit deposit requests with payment proof
// FEATURES:
// - Display deposit history using CRUD library
// - Modal form to submit new deposit requests
// - File upload for payment proof
// - Integration with payment gateways for bank details
// ============================================================================

$router->get('deposit', function () use ($SECURE, $db) {

    // ============================================================================
    // AUTHENTICATION CHECK
    // ============================================================================
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['error_message'] = T::login_required;
        REDIRECT(root . 'login');
        exit;
    }

    // CHECK AGENT AND REDRECTION IF NOT COMPLETED
    CHECK_AGENT($db);

    $userId = $_SESSION['user_id'];

    // ============================================================================
    // AUTHORIZATION CHECK - AGENT ONLY
    // ============================================================================
    $user = $db->get('users', ['id', 'role', 'first_name', 'last_name', 'email', 'created_at', 'status', 'currency'], ['user_id' => $userId]);

    if (!$user || $user['role'] !== 'agent') {
        $_SESSION['error_message'] = T::access_denied ?? 'Access Denied - Agents Only';
        REDIRECT(root . 'dashboard');
        exit;
    }

    // Store integer id for database queries
    $userIntId = $user['id'];

    // ============================================================================
    // GET DEFAULT CURRENCY FROM CURRENCIES TABLE
    // ============================================================================

    // ============================================================================
    // PREPARE DASHBOARD DATA FOR SIDEBAR
    // ============================================================================
    $dashboardData = [
        'user_name' => ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''),
        'user_email' => $user['email'] ?? '',
        'member_since' => $user['created_at'] ?? 'N/A',
        'account_status' => $user['status'] ?? 'active',
        'user_role' => $user['role'] ?? 'customer'
    ];

    // Get total bookings count for sidebar
    $totalBookings = $db->count('bookings', ['user_id' => $userId]);
    $dashboardData['total_bookings'] = $totalBookings;

    // ============================================================================
    // FETCH BANK TRANSFER GATEWAY DETAILS
    // ============================================================================
    $bankTransfer = $db->get('payment_gateways', [
        'name',
        'c1',
        'c2',
        'c3',
        'c4',
        'c5'
    ], [
        'type' => 'bank_transfer',
        'status' => 1
    ]);

    // ============================================================================
    // FETCH ENABLED PAYMENT GATEWAYS FOR DROPDOWN
    // ============================================================================
    $paymentGateways = $db->select('payment_gateways', [
        'name',
        'display_name',
        'type'
    ], [
        'status' => 1
    ]);

    // ============================================================================
    // FETCH USER'S DEPOSIT HISTORY
    // ============================================================================
    $deposits = $db->select('deposit', '*', [
        'user_id' => $userId,
        'ORDER' => ['created_at' => 'DESC']
    ]);

    // ============================================================================
    // META DETAILS FOR PAGE
    // ============================================================================
    $title = "My Deposits";
    $description = "User deposit requests and history";

    // ============================================================================
    // RENDER VIEW
    // ============================================================================
    require_once views."includes/header.php";
    require_once views."auth/deposit.php";
    require_once views."includes/footer.php";

});

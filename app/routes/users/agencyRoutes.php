<?php
// app/routes/users/agencyRoutes.php
@$SECURE or die('Access Denied!');

// ============================================================================
// AGENCY DETAILS PAGE - MANAGE AGENCY INFORMATION
// ============================================================================
// PURPOSE: Allow agents to view and update their agency details
// FEATURES:
// - View current agency information
// - Update agency details (name, license, contact info, logo)
// - File upload for agency logo
// ============================================================================

$router->get('agency-details', function () use ($SECURE, $db) {

    // ============================================================================
    // AUTHENTICATION CHECK
    // ============================================================================
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['error_message'] = T::login_required;
        REDIRECT(root . 'login');
        exit;
    }

    $userId = $_SESSION['user_id'];

    // ============================================================================
    // AUTHORIZATION CHECK - AGENT ONLY
    // ============================================================================
    $user = $db->get('users', ['id', 'role', 'first_name', 'last_name', 'email', 'created_at', 'status'], ['user_id' => $userId]);

    if (!$user || $user['role'] !== 'agent') {
        $_SESSION['error_message'] = T::access_denied ?? 'Access Denied - Agents Only';
        REDIRECT(root . 'dashboard');
        exit;
    }

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
    // FETCH OR CREATE AGENCY RECORD
    // ============================================================================
    $agency = $db->get('agencies', '*', ['user_id' => $userId]);

    // If no agency record exists, create one
    if (!$agency) {
        $db->insert('agencies', [
            'user_id' => $userId,
            'agency_name' => '',
            'license_number' => '',
            'city' => '',
            'address' => '',
            'country' => '',
            'phone' => '',
            'email' => $user['email'],
            'logo' => '',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        $agency = $db->get('agencies', '*', ['user_id' => $userId]);
    }

    // ============================================================================
    // META DETAILS FOR PAGE
    // ============================================================================
    $title = "Agency Details";
    $description = "Manage your agency information and contact details";

    // ============================================================================
    // RENDER VIEW
    // ============================================================================
    require_once views."includes/header.php";
    require_once views."auth/agency-details.php";
    require_once views."includes/footer.php";

});

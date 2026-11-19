<?php
// FILE: app/routes/users/loyaltyRoutes.php
// Customer loyalty page — real points balance, worth-in-wallet, full points
// ledger, and a redeem-to-wallet form (which POSTs to the existing, CSRF-guarded
// /loyalty/redeem JSON endpoint in userDashboardRoutes.php). Read + redeem only.

@$SECURE or die('Access Denied!');

$router->get('/loyalty', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['login_error'] = 'required';
        header('Location: ' . root . 'login');
        exit;
    }
    CHECK_AGENT($db);
    $user_id = $_SESSION['user_id'];
    $user    = $db->get('users', '*', ['user_id' => $user_id]);

    // Loyalty config + balance (same source as the dashboard).
    $enabled = false; $points = 0; $redeemValue = 0.0;
    if (function_exists('loyalty_config') && function_exists('loyalty_balance')) {
        $lc = loyalty_config($db);
        $enabled     = !empty($lc['enabled']);
        $redeemValue = (float) ($lc['redeem_value'] ?? 0);
        try { $points = (int) loyalty_balance($db, (string) $user_id); } catch (\Throwable $e) { $points = 0; }
    }

    // If loyalty is turned off entirely, send the customer back to the dashboard
    // rather than show an empty/confusing page (the menu item is hidden anyway).
    if (!$enabled) {
        header('Location: ' . root . 'dashboard');
        exit;
    }

    // Points ledger — paginated (read-only).
    $perPage = 20;
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $offset  = ($page - 1) * $perPage;
    $total = 0; $history = [];
    try {
        $total   = (int) $db->count('loyalty_ledger', ['user_id' => (string) $user_id]);
        $history = $db->select('loyalty_ledger',
            ['direction', 'points', 'balance_after', 'reason', 'ref_type', 'ref_id', 'note', 'created_at'],
            ['user_id' => (string) $user_id, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => [$offset, $perPage]]
        ) ?: [];
    } catch (\Throwable $e) { error_log('LOYALTY_VIEW: ' . $e->getMessage()); }

    $walletCurrency = strtoupper((string) ($user['currency'] ?? ($GLOBALS['app']['default_currency'] ?? 'USD')));

    $dashboardData = [
        'total_bookings' => (int) $db->count('bookings', ['user_id' => $user_id]),
        'member_since'   => $user['created_at'] ?? 'N/A',
        'currency'       => $walletCurrency,
        'is_agent'       => strtolower((string) ($user['role'] ?? '')) === 'agent',
    ];

    $loyaltyData = [
        'points'        => $points,
        'redeem_value'  => $redeemValue,
        'worth'         => number_format($points * $redeemValue, 2),
        'currency'      => $walletCurrency,
        'history'       => $history,
        'page'          => $page,
        'total_pages'   => max(1, (int) ceil($total / $perPage)),
        'total'         => $total,
    ];

    $title       = 'Loyalty';
    $description = 'Your loyalty points';
    $header      = true;
    $footer      = true;

    require_once views . 'includes/header.php';
    require_once views . 'auth/loyalty.php';
    require_once views . 'includes/footer.php';
});

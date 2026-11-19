<?php
// FILE: app/routes/users/savedCardsRoutes.php
// Customer "Payment Methods" page — a dedicated screen to view/add/remove saved
// cards. All mutations reuse the EXISTING, CSRF-guarded /api/cards/* endpoints
// (cardRoutes.php) and the same proven Stripe-SetupIntent / Paystack-top-up flow
// the dashboard already uses. This file only renders; it moves no money and
// stores no card data itself.

@$SECURE or die('Access Denied!');

$router->get('/my-cards', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['login_error'] = 'required';
        header('Location: ' . root . 'login');
        exit;
    }
    CHECK_AGENT($db);
    $user_id = $_SESSION['user_id'];
    $user    = $db->get('users', '*', ['user_id' => $user_id]);

    $walletCurrency = strtoupper((string) ($_SESSION['app_currency'] ?? ($user['currency'] ?? ($GLOBALS['app']['default_currency'] ?? 'USD'))));

    $dashboardData = [
        'total_bookings'   => (int) $db->count('bookings', ['user_id' => $user_id]),
        'member_since'     => $user['created_at'] ?? 'N/A',
        'currency'         => $walletCurrency,
        'display_currency' => $walletCurrency,
        'is_agent'         => strtolower((string) ($user['role'] ?? '')) === 'agent',
        // Display-only card list (brand/last4/exp/default) — never a token.
        'saved_cards'      => function_exists('cards_list') ? cards_list($db, (string) $user_id) : [],
    ];

    $title       = 'Payment Methods';
    $description = 'Your saved cards';
    $header      = true;
    $footer      = true;

    require_once views . 'includes/header.php';
    require_once views . 'auth/my-cards.php';
    require_once views . 'includes/footer.php';
});

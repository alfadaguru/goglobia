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

    // LOYALTY HISTORY (last 10 movements) so the user can see how they earned/spent.
    $loyaltyHistory = [];
    try {
        $loyaltyHistory = $db->select('loyalty_ledger',
            ['direction','points','balance_after','reason','ref_id','created_at'],
            ['user_id' => (string)$user_id, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 10]) ?: [];
    } catch (\Throwable $e) { $loyaltyHistory = []; }

    // AGENT MEMBERSHIP TIER (docs §C.4 step 4): show the agent their current tier,
    // its discount, lifetime top-up, and progress to the next tier.
    $tierInfo = null;
    if ($isAgent && function_exists('agent_lifetime_topup')) {
        $lifetime = (float) agent_lifetime_topup($db, (string)$user_id);
        $currentTier = null;
        if (!empty($user['agent_tier_id'])) {
            $currentTier = $db->get('agent_tiers', ['code','name','discount_percent','min_lifetime_topup'], ['id' => (int)$user['agent_tier_id']]);
        }
        // Next tier = the lowest active threshold strictly above lifetime.
        $nextTier = $db->get('agent_tiers', ['name','min_lifetime_topup','discount_percent'], [
            'active' => 1, 'min_lifetime_topup[>]' => $lifetime, 'ORDER' => ['min_lifetime_topup' => 'ASC']
        ]) ?: null;
        $tierInfo = [
            'current'        => $currentTier,
            'next'           => $nextTier,
            'lifetime_topup' => $lifetime,
        ];
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
        'loyalty_history' => $loyaltyHistory,
        'tier_info' => $tierInfo,
        // Paystack virtual account (NGN-only, customers). The button/panel in the
        // wallet card is gated on the DISPLAY currency being NGN.
        'display_currency' => strtoupper(trim((string)($_SESSION['app_currency'] ?? ''))) ?: strtoupper(trim((string)($user['currency'] ?? ''))),
        'dva_account_number' => (string)($user['dva_account_number'] ?? ''),
        'dva_bank_name' => (string)($user['dva_bank_name'] ?? ''),
        'dva_account_name' => (string)($user['dva_account_name'] ?? ''),
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

// ============================================================================
// CUSTOMER WALLET TOP-UP via gateway (step 4). Creates a synthetic
// "wallet_topup" booking, routes it to the currency-correct gateway
// (NGN->Paystack, else->Stripe), then hands off to the normal /payment/process
// flow. On confirmed payment, handle_payment_callback() credits the wallet.
//   POST /wallet/topup   { amount, csrf_token }
// ============================================================================
$router->post('/wallet/topup', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['login_error'] = 'required';
        header('Location: ' . root . 'login');
        exit;
    }
    // CSRF
    if (!CSRF::validateToken($_POST['csrf_token'] ?? ($_POST['_token'] ?? ''))) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invalid or expired form token. Please try again.'];
        header('Location: ' . root . 'dashboard');
        exit;
    }

    $userId = (string) $_SESSION['user_id'];
    $user = $db->get('users', ['user_id','role','first_name','last_name','email','phone','phone_country_code','currency'], ['user_id' => $userId]);
    if (!$user) { header('Location: ' . root . 'login'); exit; }

    // Agents fund the wallet via the deposit flow; this self-service gateway
    // top-up is for customers (the payment rule keeps agents wallet-only anyway).
    if (strtolower((string) ($user['role'] ?? '')) === 'agent') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Agents top up via the deposit page.'];
        header('Location: ' . root . 'dashboard');
        exit;
    }

    $amount = round((float) ($_POST['amount'] ?? 0), 2);
    if ($amount <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Enter a valid top-up amount.'];
        header('Location: ' . root . 'dashboard');
        exit;
    }

    // Top-up currency = the session/display currency (falls back to user's, then default).
    $currency = strtoupper(trim((string) ($_SESSION['app_currency'] ?? '')))
        ?: strtoupper(trim((string) ($user['currency'] ?? '')));
    if ($currency === '' && function_exists('wallet_default_currency')) { $currency = wallet_default_currency($db); }
    if ($currency === '') { $currency = 'NGN'; }

    // Pick the currency-correct EXTERNAL gateway (reuses step 2 routing):
    // the enabled+active non-wallet gateway allowed for this currency.
    $candidates = $db->select('payment_gateways', '*', [
        'status' => '1', 'active' => '1', 'type[!]' => 'internal_wallet',
        'ORDER' => ['default' => 'DESC', 'id' => 'ASC'],
    ]) ?: [];
    $gateway = null;
    foreach ($candidates as $g) {
        if (!function_exists('payment_gateway_allowed_for_currency')
            || payment_gateway_allowed_for_currency($db, $g, $currency)) {
            $gateway = $g; break;
        }
    }
    if (!$gateway) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'No payment method is available for ' . $currency . ' top-ups right now.'];
        header('Location: ' . root . 'dashboard');
        exit;
    }

    // Create the synthetic top-up booking (module=wallet_topup). Fills every
    // NOT-NULL bookings column. price_markup is the amount charged.
    $invoiceId = 'TOP' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 9));
    $ok = $db->insert('bookings', [
        'invoice_id'        => $invoiceId,
        'user_id'           => $userId,
        'module'            => 'wallet_topup',
        'module_type'       => 'wallet_topup',
        'booking_status'    => 'pending',
        'payment_status'    => 'unpaid',
        'price_original'    => $amount,
        'price_markup'      => $amount,
        'commission'        => 0,
        'agent_earning'     => 0,
        'tax'               => '0',
        'currency_markup'   => $currency,
        'payment_gateway'   => (string) $gateway['id'],
        'first_name'        => (string) ($user['first_name'] ?: 'Wallet'),
        'last_name'         => (string) ($user['last_name'] ?: 'Top-up'),
        'email'             => (string) ($user['email'] ?: ''),
        'phone'             => (string) ($user['phone'] ?: ''),
        'phone_country_code'=> (string) ($user['phone_country_code'] ?: ''),
        'country'           => '',
        'address'           => '',
        'child_ages'        => '[]',
        'travellers'        => '[]',
        'booking_date'      => date('Y-m-d'),
        'created_at'        => date('Y-m-d H:i:s'),
    ]);
    if (!$ok) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not start the top-up. Please try again.'];
        header('Location: ' . root . 'dashboard');
        exit;
    }

    // Hand off to the normal payment flow (same 3 steps /payment/process uses):
    // token -> log (returns a hash) -> redirect to the secure /payment/{hash}
    // page which renders the gateway (Paystack/Stripe) UI.
    require_once 'app/lib/payment-gateway.php';
    $bookingRow = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
    $token = create_payment_token($bookingRow, $gateway);
    $logResult = function_exists('log_payment_transaction')
        ? log_payment_transaction($bookingRow, $gateway, $token)
        : ['success' => true, 'hash' => null];

    if (empty($logResult['success']) || empty($logResult['hash'])) {
        // Fall back to the invoice-style payment page by invoice id.
        $_SESSION['message'] = ['type' => 'error', 'text' => $logResult['message'] ?? 'Could not start the top-up.'];
        header('Location: ' . root . 'dashboard');
        exit;
    }
    header('Location: ' . root . 'payment/' . $logResult['hash']);
    exit;
});

// ============================================================================
// CUSTOMER — activate a Paystack Dedicated Virtual Account (NUBAN) (step 5).
// NGN-only, customer-only. Creates a permanent bank account number via Paystack;
// money paid into it is credited to the wallet by the Paystack webhook.
//   POST /wallet/virtual-account   { csrf_token }
// ============================================================================
$router->post('/wallet/virtual-account', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['login_error'] = 'required';
        header('Location: ' . root . 'login');
        exit;
    }
    if (!CSRF::validateToken($_POST['csrf_token'] ?? ($_POST['_token'] ?? ''))) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invalid or expired form token. Please try again.'];
        header('Location: ' . root . 'dashboard');
        exit;
    }

    $userId = (string) $_SESSION['user_id'];
    $user = $db->get('users', ['user_id','role','currency'], ['user_id' => $userId]);
    if (!$user) { header('Location: ' . root . 'login'); exit; }

    // Virtual accounts are a customer feature (agents fund via deposit).
    if (strtolower((string) ($user['role'] ?? '')) === 'agent') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Virtual accounts are for customer wallets.'];
        header('Location: ' . root . 'dashboard');
        exit;
    }

    // NGN-only: the display currency the customer is transacting in must be NGN
    // (this is the same currency the button is gated on in the wallet card).
    $displayCur = strtoupper(trim((string) ($_SESSION['app_currency'] ?? '')))
        ?: strtoupper(trim((string) ($user['currency'] ?? '')));
    if ($displayCur !== 'NGN') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Virtual accounts are available on NGN only. Switch to Naira first.'];
        header('Location: ' . root . 'dashboard');
        exit;
    }

    require_once 'app/lib/wallet.php';
    $res = paystack_dva_activate($db, $userId);

    if (!empty($res['ok'])) {
        $_SESSION['message'] = ['type' => 'success', 'text' => !empty($res['already'])
            ? 'Your virtual account is ready.'
            : 'Virtual account created. You can now fund your wallet by bank transfer.'];
    } elseif (!empty($res['not_enabled'])) {
        // DVA not approved on this Paystack account yet — tell the customer plainly.
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Virtual accounts are not available yet. Please contact support.'];
    } elseif (!empty($res['needs_bvn'])) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Your bank requires BVN verification to create this account. Please contact support.'];
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => (string) ($res['message'] ?? 'Could not create a virtual account right now.')];
    }
    header('Location: ' . root . 'dashboard');
    exit;
});

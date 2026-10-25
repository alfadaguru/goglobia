<?php
// ============================================================================
// app/routes/users/agentWalletRoutes.php  — Agent ERP P0: wallet + DVA funding
//
//   GET  /agent/wallet                 → wallet screen (balance, NUBAN, credit,
//                                         statement). agent_can('wallet','view').
//   POST /agent/wallet/dva/activate    → create the agency's Paystack DVA (NUBAN)
//                                         by reusing paystack_dva_activate() — the
//                                         SAME role-agnostic function the customer
//                                         wallet uses (app/lib/wallet.php:1121).
//                                         NGN-only. agent_can('wallet','fund') + CSRF.
//
// Agents pay bookings ONLY from this wallet (enforced in payment-gateway.php:126).
// The wallet is funded by a bank transfer into the DVA; the Paystack webhook
// (app/routes/paymentPaystackRoutes.php:141-217) auto-credits the spine. No
// webhook change is needed — it matches users.paystack_customer_code regardless
// of role and promotes the agent's tier on credit.
// ============================================================================
@$SECURE or die('Access Denied!');

// ---- GET /agent/wallet ------------------------------------------------------
$router->get('/agent/wallet', function () use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    $ctx = agent_acting_context($db);
    if ($ctx === null || !empty($ctx['is_admin'])) {
        header('Location: ' . root . ($ctx && !empty($ctx['is_admin']) ? 'admin/dashboard' : 'login'));
        exit;
    }
    if (!agent_can($db, 'wallet', 'view')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'You do not have access to the wallet.'];
        header('Location: ' . root . 'agent/dashboard');
        exit;
    }

    // Everything shows the OWNER's money (staff view the agency wallet).
    $ownerId = (string) $ctx['owner'];
    $owner   = $db->get('users', ['user_id','first_name','last_name','email','currency','role','status',
                                  'credit_limits','dva_account_number','dva_bank_name','dva_account_name',
                                  'agent_tier_id','loyalty_points'], ['user_id' => $ownerId]);
    if (!$owner) { header('Location: ' . root . 'login'); exit; }

    // Default/display currency.
    $defaultCurrency = function_exists('wallet_default_currency') ? wallet_default_currency($db) : 'NGN';
    $displayCur = strtoupper(trim((string) ($_SESSION['app_currency'] ?? ''))) ?: $defaultCurrency;

    // Spine reads (authoritative). wallets row per-currency; ledger = statement.
    $wallets = [];
    $ledger  = [];
    try {
        $wallets = $db->select('wallets', ['currency','balance','kind','updated_at'], ['user_id' => $ownerId]) ?: [];
    } catch (\Throwable $e) { error_log('agent/wallet wallets: ' . $e->getMessage()); }
    try {
        $ledger = $db->select('wallet_ledger',
            ['direction','amount','balance_after','currency','reason','ref_type','ref_id','note','created_at'],
            ['user_id' => $ownerId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 100]) ?: [];
    } catch (\Throwable $e) { error_log('agent/wallet ledger: ' . $e->getMessage()); }

    $balance = function_exists('wallet_balance') ? (float) wallet_balance($db, $ownerId, $defaultCurrency) : 0.0;
    $creditLimit = (float) ($owner['credit_limits'] ?? 0);
    $lifetimeTopup = function_exists('agent_lifetime_topup') ? (float) agent_lifetime_topup($db, $ownerId) : 0.0;

    // DVA state + whether DVA is enabled for agents (admin toggle).
    $dvaEnabled = (string) ($GLOBALS['app']['agent_dva_enabled'] ?? '1') === '1';
    $hasDva = !empty($owner['dva_account_number']);

    // Tier (for the ribbon).
    $tier = null;
    if (!empty($owner['agent_tier_id'])) {
        try { $tier = $db->get('agent_tiers', ['code','name','discount_percent','min_lifetime_topup'],
                               ['id' => (int) $owner['agent_tier_id']]); } catch (\Throwable $e) {}
    }

    $viewingAsStaff = empty($ctx['is_owner']);
    $canFund = agent_can($db, 'wallet', 'fund');

    $title = 'Agency Wallet';
    require_once views."agent/layout-top.php";
    require_once views."agent/wallet.php";
    require_once views."agent/layout-bottom.php";
});

// ---- POST /agent/wallet/dva/activate ---------------------------------------
$router->post('/agent/wallet/dva/activate', function () use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    if (!CSRF::validateToken($_POST['csrf_token'] ?? ($_POST['_token'] ?? ''))) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invalid or expired form token. Please try again.'];
        header('Location: ' . root . 'agent/wallet');
        exit;
    }
    $ctx = agent_acting_context($db);
    if ($ctx === null || !empty($ctx['is_admin'])) { header('Location: ' . root . 'login'); exit; }
    if (!agent_can($db, 'wallet', 'fund')) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'You do not have permission to fund the wallet.'];
        header('Location: ' . root . 'agent/wallet');
        exit;
    }

    // Admin toggle gate.
    if ((string) ($GLOBALS['app']['agent_dva_enabled'] ?? '1') !== '1') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Virtual-account funding is currently disabled.'];
        header('Location: ' . root . 'agent/wallet');
        exit;
    }

    // DVA is minted on the OWNER's account (the agency wallet), even if a staff
    // member with the 'fund' capability triggers it.
    $ownerId = (string) $ctx['owner'];
    $owner = $db->get('users', ['user_id','role','currency','status'], ['user_id' => $ownerId]);
    if (!$owner) { header('Location: ' . root . 'login'); exit; }

    // Only an approved (active) agency may activate — mirrors the pending-owner
    // write guard; a pending agency shouldn't transact yet.
    if (($owner['status'] ?? '') !== 'active') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Your agency is pending approval. You can activate funding once approved.'];
        header('Location: ' . root . 'agent/wallet');
        exit;
    }

    // NGN-only (same gate as the customer wallet DVA).
    $displayCur = strtoupper(trim((string) ($_SESSION['app_currency'] ?? '')))
        ?: strtoupper(trim((string) ($owner['currency'] ?? '')));
    if ($displayCur !== 'NGN') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Virtual accounts are available on NGN only. Switch to Naira first.'];
        header('Location: ' . root . 'agent/wallet');
        exit;
    }

    require_once 'app/lib/wallet.php';
    $res = paystack_dva_activate($db, $ownerId);

    if (!empty($res['ok'])) {
        $_SESSION['message'] = ['type' => 'success', 'text' => !empty($res['already'])
            ? 'Your agency virtual account is ready.'
            : 'Virtual account created. Fund your wallet by bank transfer to the account shown.'];
        if (function_exists('agent_audit_log')) {
            agent_audit_log($db, $ownerId, (string) $ctx['actor'], 'wallet.dva.activate',
                'NUBAN ' . (string) ($res['account_number'] ?? ''));
        }
    } elseif (!empty($res['not_enabled'])) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Virtual accounts are not available yet. Please contact support.'];
    } elseif (!empty($res['needs_bvn'])) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Your bank requires BVN verification to create this account. Please contact support.'];
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => (string) ($res['message'] ?? 'Could not create a virtual account right now.')];
    }
    header('Location: ' . root . 'agent/wallet');
    exit;
});

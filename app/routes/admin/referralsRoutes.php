<?php
// app/routes/admin/referralsRoutes.php
// Admin → Marketing → Referral Program. Configure the program (enable, reward
// amounts, hold window, min booking, monthly cap) and review referrals + rewards.
// Mirrors the promo-codes / discounts admin conventions (ADMIN_AUTH first, CSRF on
// POST, render via the header/page/footer triple).
@$SECURE or die('Access Denied!');

// GET — config + audit dashboard
$router->get(admin.'/referrals', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('admin_require')) { admin_require($db, 'referrals', 'view'); }
    if (function_exists('ensureReferralSchema')) { ensureReferralSchema($db); }

    $cfg = function_exists('referral_config') ? referral_config($db) : [];

    // Stats for the dashboard.
    $stats = [
        'codes'     => (int) $db->count('referral_codes', '*'),
        'pending'   => (int) $db->count('referrals', ['status' => 'pending']),
        'qualified' => (int) $db->count('referrals', ['status' => 'qualified']),
        'rewarded'  => (int) $db->count('referrals', ['status' => 'rewarded']),
        'reversed'  => (int) $db->count('referrals', ['status' => 'reversed']),
        'held_amt'  => (float) ($db->sum('referral_rewards', 'referrer_amount', ['status' => 'hold']) ?: 0),
        'paid_amt'  => (float) ($db->sum('referral_rewards', 'referrer_amount', ['status' => 'rewarded']) ?: 0),
    ];

    // Recent referrals (audit).
    $recent = $db->select('referrals', '*', ['ORDER' => ['created_at' => 'DESC'], 'LIMIT' => 50]);
    // Recent rewards (audit) — joined would be nicer but keep it simple + robust.
    $rewards = $db->select('referral_rewards', '*', ['ORDER' => ['created_at' => 'DESC'], 'LIMIT' => 50]);

    require_once views."includes/header.php";
    require_once "app/views/admin/referrals/referrals.php";
    require_once views."includes/footer.php";
});

// POST — save config
$router->post(admin.'/referrals/save', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('admin_require')) { admin_require($db, 'referrals', 'edit'); }
    CSRF::verifyRequest();

    $enabled  = isset($_POST['referral_enabled']) && $_POST['referral_enabled'] ? 1 : 0;
    $referrer = max(0, round((float) ($_POST['referral_referrer_amount'] ?? 0), 2));
    $referee  = max(0, round((float) ($_POST['referral_referee_amount'] ?? 0), 2));
    $currency = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($_POST['referral_currency'] ?? '')));
    if ($currency === '') { $currency = function_exists('wallet_default_currency') ? wallet_default_currency($db) : 'NGN'; }
    $holdDays = max(0, min(90, (int) ($_POST['referral_hold_days'] ?? 14)));
    $minBook  = max(0, round((float) ($_POST['referral_min_booking_amount'] ?? 0), 2));
    $cap      = max(0, (int) ($_POST['referral_cap_per_month'] ?? 0));

    try {
        if (function_exists('ensureReferralSchema')) { ensureReferralSchema($db); }
        $db->update('settings', [
            'referral_enabled'            => $enabled,
            'referral_referrer_amount'    => $referrer,
            'referral_referee_amount'     => $referee,
            'referral_currency'           => $currency,
            'referral_hold_days'          => $holdDays,
            'referral_min_booking_amount' => $minBook,
            'referral_cap_per_month'      => $cap,
        ], ['id' => 1]);
        // Refresh the in-request cache so the dashboard reflects the save immediately.
        if (isset($GLOBALS['app']) && is_array($GLOBALS['app'])) {
            $GLOBALS['app'] = array_merge($GLOBALS['app'], [
                'referral_enabled' => $enabled, 'referral_referrer_amount' => $referrer,
                'referral_referee_amount' => $referee, 'referral_currency' => $currency,
                'referral_hold_days' => $holdDays, 'referral_min_booking_amount' => $minBook,
                'referral_cap_per_month' => $cap,
            ]);
        }
        $_SESSION['success'] = 'Referral settings saved.';
    } catch (\Throwable $e) {
        error_log('referral save: ' . $e->getMessage());
        $_SESSION['error'] = 'Could not save referral settings.';
    }
    header('Location: ' . root . admin . '/referrals');
    exit;
});

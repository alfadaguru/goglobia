<?php
// app/routes/users/referralRoutes.php
// Customer "Refer & Earn" page: their shareable code + link, how it works, and
// their invite history + earnings. Reuses the referral engine (app/lib/referrals.php).
@$SECURE or die('Access Denied!');

$router->get('/referrals', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['login_error'] = 'required';
        header('Location: ' . root . 'login');
        exit;
    }
    $userId = (string) $_SESSION['user_id'];

    if (function_exists('ensureReferralSchema')) { ensureReferralSchema($db); }
    $cfg = function_exists('referral_config') ? referral_config($db) : ['enabled' => false];

    // Program off → keep the page reachable but show a friendly "not available yet".
    $myCode = '';
    $myReferrals = [];
    $rewards = [];
    $pending = 0.0; $earned = 0.0;
    if (!empty($cfg['enabled']) && function_exists('referral_get_or_mint_code')) {
        $myCode = referral_get_or_mint_code($db, $userId); // mints on first visit (backfills existing users)
        $myReferrals = $db->select('referrals', ['referee_id', 'status', 'created_at'], [
            'referrer_id' => $userId, 'ORDER' => ['created_at' => 'DESC'], 'LIMIT' => 50,
        ]) ?: [];
        $rewards = $db->select('referral_rewards', ['invoice_id', 'referrer_amount', 'currency', 'status', 'hold_expires_at', 'rewarded_at'], [
            'referrer_id' => $userId, 'ORDER' => ['created_at' => 'DESC'], 'LIMIT' => 50,
        ]) ?: [];
        $pending = (float) ($db->sum('referral_rewards', 'referrer_amount', ['referrer_id' => $userId, 'status' => 'hold']) ?: 0);
        $earned  = (float) ($db->sum('referral_rewards', 'referrer_amount', ['referrer_id' => $userId, 'status' => 'rewarded']) ?: 0);
    }

    $referralData = [
        'cfg'        => $cfg,
        'code'       => $myCode,
        'link'       => rtrim(root, '/') . '/r/' . $myCode,
        'referrals'  => $myReferrals,
        'rewards'    => $rewards,
        'pending'    => $pending,
        'earned'     => $earned,
    ];

    require_once views."includes/header.php";
    require_once views."auth/referrals.php";
    require_once views."includes/footer.php";
});

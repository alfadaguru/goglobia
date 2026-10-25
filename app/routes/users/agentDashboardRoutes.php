<?php
// ============================================================================
// app/routes/users/agentDashboardRoutes.php — Agent ERP P1
//   GET /agent/dashboard  → the agency workspace home (KPIs + onboarding + nav)
//   GET /agent/onboarding → the guided setup wizard (full checklist)
// Mirrors the supplier dashboard (supplierDashboardRoutes.php): resolve the
// acting context (staff→owner), show the OWNER's agency + money, gate on role.
// ============================================================================
@$SECURE or die('Access Denied!');

if (!function_exists('agent_onboarding_state')) {
    /**
     * Data-driven onboarding checklist (computed fresh each load — no wizard-state
     * table), mirroring supplier_onboarding_state(). Steps reflect real data:
     *   1. Account approved (users.status === 'active')
     *   2. Agency profile complete (name + email + phone)
     *   3. Branding set (logo OR brand colour)
     *   4. Wallet funded (DVA activated OR balance > 0)
     *   5. First booking made (a bookings row for this agency)
     */
    function agent_onboarding_state($db, string $owner): array
    {
        $steps = [];
        $done = 0;

        // 1. approved
        $status = '';
        try { $status = (string) ($db->get('users', 'status', ['user_id' => $owner]) ?: ''); } catch (\Throwable $e) {}
        $s1 = ($status === 'active');
        $steps[] = ['key'=>'approved','label'=>'Account approved','done'=>$s1,'cta'=>null,'hint'=>'An admin approves new agencies.'];

        // 2. agency profile
        $agency = null;
        try { $agency = $db->get('agencies', ['agency_name','email','phone','logo','brand_primary','brand_accent'], ['user_id' => $owner]); } catch (\Throwable $e) {}
        $s2 = $agency && !empty($agency['agency_name']) && !empty($agency['email']) && !empty($agency['phone']);
        $steps[] = ['key'=>'profile','label'=>'Complete agency profile','done'=>$s2,'cta'=>($s2?null:root.'agency-details'),'hint'=>'Name, licence, contact.'];

        // 3. branding
        $s3 = $agency && (!empty($agency['logo']) || !empty($agency['brand_primary']));
        $steps[] = ['key'=>'branding','label'=>'Add your logo & brand colours','done'=>$s3,'cta'=>($s3?null:root.'agent/branding'),'hint'=>'Your tickets & invoices carry your brand.'];

        // 4. wallet funded
        $dva = '';
        try { $dva = (string) ($db->get('users', 'dva_account_number', ['user_id' => $owner]) ?: ''); } catch (\Throwable $e) {}
        $bal = function_exists('wallet_balance') ? (float) wallet_balance($db, $owner, function_exists('wallet_default_currency') ? wallet_default_currency($db) : 'NGN') : 0.0;
        $s4 = ($dva !== '' || $bal > 0);
        $steps[] = ['key'=>'wallet','label'=>'Fund your wallet','done'=>$s4,'cta'=>($s4?null:root.'agent/wallet'),'hint'=>'Agents pay bookings from the wallet.'];

        // 5. first booking
        $hasBooking = false;
        try { $hasBooking = (int) $db->count('bookings', ['OR' => ['agency_id' => $owner, 'user_id' => $owner]]) > 0; } catch (\Throwable $e) {}
        $steps[] = ['key'=>'booking','label'=>'Make your first booking','done'=>$hasBooking,'cta'=>($hasBooking?null:root.'agent/booking-centre'),'hint'=>'Book any service for a client.'];

        foreach ($steps as $st) { if (!empty($st['done'])) { $done++; } }
        $total = count($steps);
        $next = null;
        foreach ($steps as $st) { if (empty($st['done'])) { $next = $st; break; } }
        return ['steps'=>$steps,'total'=>$total,'completed'=>$done,
                'percent'=>$total>0?(int)round($done/$total*100):0,
                'complete'=>$done>=$total,'next'=>$next];
    }
}

// ---- GET /agent/dashboard ---------------------------------------------------
$router->get('/agent/dashboard', function () use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    $ctx = agent_acting_context($db);
    if ($ctx === null || !empty($ctx['is_admin'])) {
        header('Location: ' . root . (!empty($ctx['is_admin']) ? 'admin/dashboard' : 'login'));
        exit;
    }
    $ownerId = (string) $ctx['owner'];
    $owner = $db->get('users', ['user_id','first_name','last_name','email','status','currency',
                                'credit_limits','agent_tier_id','loyalty_points'], ['user_id' => $ownerId]);
    if (!$owner) { header('Location: ' . root . 'login'); exit; }

    $viewingAsStaff = empty($ctx['is_owner']);
    $pending = (($owner['status'] ?? '') !== 'active');
    $defaultCurrency = function_exists('wallet_default_currency') ? wallet_default_currency($db) : 'NGN';

    // KPIs — money reads from the spine; earnings PAID-ONLY (fixes the known bug
    // where the customer dashboard summed over all bookings).
    $balance = function_exists('wallet_balance') ? (float) wallet_balance($db, $ownerId, $defaultCurrency) : 0.0;
    $creditLimit = (float) ($owner['credit_limits'] ?? 0);

    $scope = ['OR' => ['agency_id' => $ownerId, 'user_id' => $ownerId]];
    $totalBookings = 0; $paidEarning = 0.0; $recent = [];
    try { $totalBookings = (int) $db->count('bookings', $scope); } catch (\Throwable $e) {}
    try {
        $paidRows = $db->select('bookings', ['agent_earning','agent_client_margin'],
            array_merge($scope, ['payment_status' => 'paid'])) ?: [];
        foreach ($paidRows as $r) {
            // Prefer the agent's own margin (self-markup) when present; else the
            // platform B2B earning recorded on the booking.
            $m = (float) ($r['agent_client_margin'] ?? 0);
            $paidEarning += $m > 0 ? $m : (float) ($r['agent_earning'] ?? 0);
        }
    } catch (\Throwable $e) {}
    try {
        $recent = $db->select('bookings', ['invoice_id','module_type','booking_status','payment_status','price_markup','currency_markup','created_at'],
            array_merge($scope, ['ORDER' => ['id' => 'DESC'], 'LIMIT' => 8])) ?: [];
    } catch (\Throwable $e) {}

    // Tier ribbon.
    $tier = null;
    if (!empty($owner['agent_tier_id'])) {
        try { $tier = $db->get('agent_tiers', ['name','discount_percent'], ['id' => (int) $owner['agent_tier_id']]); } catch (\Throwable $e) {}
    }

    // Staff online count (owner only).
    $staffCount = 0;
    if (!$viewingAsStaff) {
        try { $staffCount = (int) $db->count('agent_staff', ['owner_user_id' => $ownerId, 'status' => 'active']); } catch (\Throwable $e) {}
    }

    $onboarding = agent_onboarding_state($db, $ownerId);

    $title = 'Agent Dashboard';
    require_once views."includes/header.php";
    require_once views."agent/dashboard.php";
    require_once views."includes/footer.php";
});

// ---- GET /agent/onboarding --------------------------------------------------
$router->get('/agent/onboarding', function () use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    $ctx = agent_acting_context($db);
    if ($ctx === null || !empty($ctx['is_admin'])) { header('Location: ' . root . 'login'); exit; }
    $ownerId = (string) $ctx['owner'];
    $onboarding = agent_onboarding_state($db, $ownerId);
    $title = 'Get started';
    require_once views."includes/header.php";
    require_once views."agent/onboarding.php";
    require_once views."includes/footer.php";
});

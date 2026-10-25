<?php
// ============================================================================
// app/routes/users/agentBookingCentreRoutes.php — Agent ERP P3: Booking Centre
//
// Agents book any of the 11 services for their passengers by REUSING each
// service's existing search + booking flow (which already applies B2B markup via
// MARKUP() and captures travellers). The booking lands user_id=agent,
// payment_status='unpaid'. The agent then settles it from the WALLET here — the
// only payment path agents have (payment-gateway.php:126 enforces wallet-only).
//
//   GET  /agent/booking-centre        → hub: pick a service to search & book
//   GET  /agent/bookings              → the agency's bookings (list)
//   GET  /agent/booking/{inv}/pay     → wallet-settle confirm screen
//   POST /agent/booking/{inv}/pay     → debit wallet, attribute, mark paid, issue
//
// Settlement reuses the proven money primitive agent_api_charge_wallet()
// (functions.php:7305 → wallet_spend, idempotent) and the issue loopback
// bm_fire_supplier_issue() (booking_manager.php:88). Self-markup (M10/P5) layers
// a client price on top; until P5 the client price == what the agent pays.
// ============================================================================
@$SECURE or die('Access Denied!');

if (!function_exists('agent_bc_services')) {
    /** The 11 bookable services + display meta, filtered to those active in modules. */
    function agent_bc_services($db): array
    {
        $all = [
            ['key'=>'flights',  'label'=>'Flights',       'icon'=>'flight',          'url'=>'flights'],
            ['key'=>'stays',    'label'=>'Hotels / Stays','icon'=>'hotel',           'url'=>'stays'],
            ['key'=>'tours',    'label'=>'Tours',         'icon'=>'tour',            'url'=>'tours'],
            ['key'=>'cars',     'label'=>'Car rental',    'icon'=>'directions_car',  'url'=>'cars'],
            ['key'=>'umrah',    'label'=>'Umrah',         'icon'=>'mosque',          'url'=>'umrah'],
            ['key'=>'visa',     'label'=>'Visa',          'icon'=>'badge',           'url'=>'visa'],
            ['key'=>'bus',      'label'=>'Bus',           'icon'=>'directions_bus',  'url'=>'bus'],
            ['key'=>'rail',     'label'=>'Rail',          'icon'=>'train',           'url'=>'rail'],
            ['key'=>'ferries',  'label'=>'Ferries',       'icon'=>'directions_boat', 'url'=>'ferries'],
            ['key'=>'esim',     'label'=>'eSIM',          'icon'=>'sim_card',        'url'=>'esim'],
            ['key'=>'insurance','label'=>'Insurance',     'icon'=>'health_and_safety','url'=>'insurance'],
        ];
        // Active-module filter: a module row with active='1' status='1'. 'stays'
        // maps to module type 'stays' (a.k.a hotels). If the modules table can't
        // be read, show all (fail-open for the hub — booking itself is gated later).
        $active = null;
        try {
            $rows = $db->select('modules', ['type'], ['active' => '1', 'status' => '1']) ?: [];
            $active = [];
            foreach ($rows as $r) { $active[strtolower((string)$r['type'])] = true; }
        } catch (\Throwable $e) { $active = null; }
        if ($active === null) { return $all; }
        return array_values(array_filter($all, fn($s) => isset($active[$s['key']]) || ($s['key']==='stays' && isset($active['hotels']))));
    }
}

// ---- GET /agent/booking-centre ---------------------------------------------
$router->get('/agent/booking-centre', function () use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    $ctx = agent_acting_context($db);
    if ($ctx === null || !empty($ctx['is_admin'])) { header('Location: ' . root . 'login'); exit; }
    if (!agent_can($db, 'bookings', 'add')) {
        $_SESSION['message'] = ['type'=>'error','text'=>'You do not have permission to create bookings.'];
        header('Location: ' . root . 'agent/dashboard'); exit;
    }
    // Pending agencies cannot transact yet.
    $pending = false;
    if (!empty($ctx['is_owner']) && empty($ctx['owner_active'])) { $pending = true; }

    $services = agent_bc_services($db);
    // Agency name for the "booking as …" banner.
    $agencyName = 'your agency';
    try { $ag = $db->get('agencies', 'agency_name', ['user_id' => (string)$ctx['owner']]); if ($ag) { $agencyName = (string)$ag; } } catch (\Throwable $e) {}

    $title = 'Booking Centre';
    require_once views."includes/header.php";
    require_once views."agent/booking-centre.php";
    require_once views."includes/footer.php";
});

// ---- GET /agent/bookings ----------------------------------------------------
$router->get('/agent/bookings', function () use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    $ctx = agent_acting_context($db);
    if ($ctx === null || !empty($ctx['is_admin'])) { header('Location: ' . root . 'login'); exit; }
    if (!agent_can($db, 'bookings', 'view')) {
        $_SESSION['message'] = ['type'=>'error','text'=>'You do not have access to bookings.'];
        header('Location: ' . root . 'agent/dashboard'); exit;
    }
    $owner = (string) $ctx['owner'];
    $scope = ['OR' => ['agency_id' => $owner, 'user_id' => $owner]];
    $bookings = [];
    try {
        $bookings = $db->select('bookings',
            ['invoice_id','module_type','module','booking_status','payment_status','price_markup',
             'agent_client_price','agent_earning','agent_client_margin','currency_markup','first_name','last_name','created_at'],
            array_merge($scope, ['ORDER'=>['id'=>'DESC'], 'LIMIT'=>200])) ?: [];
    } catch (\Throwable $e) { error_log('agent/bookings: ' . $e->getMessage()); }

    $title = 'Bookings';
    require_once views."includes/header.php";
    require_once views."agent/bookings.php";
    require_once views."includes/footer.php";
});

// Shared loader: fetch an UNPAID booking that belongs to this agency (or was made
// by the agent before agency_id existed — match user_id too). Returns [booking,ctx]
// or redirects. $need = required capability action.
if (!function_exists('_agent_booking_for_settle')) {
    function _agent_booking_for_settle($db, string $invoiceId): array
    {
        $ctx = agent_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { header('Location: ' . root . 'login'); exit; }
        $owner = (string) $ctx['owner'];
        $b = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$b) {
            $_SESSION['message'] = ['type'=>'error','text'=>'Booking not found.'];
            header('Location: ' . root . 'agent/bookings'); exit;
        }
        // Ownership: the booking must belong to this agency (agency_id) OR have been
        // created by the agent owner/actor (user_id) before attribution was stamped.
        $ownBy = (string) ($b['agency_id'] ?? '');
        $byUser = (string) ($b['user_id'] ?? '');
        if ($ownBy !== $owner && $byUser !== $owner && $byUser !== (string)$ctx['actor']) {
            $_SESSION['message'] = ['type'=>'error','text'=>'That booking does not belong to your agency.'];
            header('Location: ' . root . 'agent/bookings'); exit;
        }
        return [$b, $ctx];
    }
}

// ---- GET /agent/booking/{inv}/pay  (confirm) -------------------------------
$router->get('/agent/booking/([A-Za-z0-9]+)/pay', function ($invoiceId) use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    if (!agent_can($db, 'bookings', 'add')) { header('Location: ' . root . 'agent/dashboard'); exit; }
    [$booking, $ctx] = _agent_booking_for_settle($db, (string)$invoiceId);

    $owner = (string) $ctx['owner'];
    $defaultCurrency = function_exists('wallet_default_currency') ? wallet_default_currency($db) : 'NGN';
    $balance = function_exists('wallet_balance') ? (float) wallet_balance($db, $owner, $defaultCurrency) : 0.0;
    $creditLimit = (float) ($db->get('users', 'credit_limits', ['user_id'=>$owner]) ?: 0);

    // What the agent PAYS us = price_markup (net + our B2B markup), in base currency.
    $agentCost = (float) ($booking['price_markup'] ?? $booking['price_original'] ?? 0);
    $alreadyPaid = (($booking['payment_status'] ?? '') === 'paid');

    $title = 'Pay for booking';
    require_once views."includes/header.php";
    require_once views."agent/booking-pay.php";
    require_once views."includes/footer.php";
});

// ---- POST /agent/booking/{inv}/pay  (settle from wallet) -------------------
$router->post('/agent/booking/([A-Za-z0-9]+)/pay', function ($invoiceId) use ($SECURE, $db) {
    AGENT_OR_STAFF_AUTH($db);
    CSRF::guard();
    if (!agent_can($db, 'bookings', 'add')) { header('Location: ' . root . 'agent/dashboard'); exit; }
    [$booking, $ctx] = _agent_booking_for_settle($db, (string)$invoiceId);
    $owner = (string) $ctx['owner'];
    $actor = (string) $ctx['actor'];
    $service = (string) ($booking['module_type'] ?? '');
    $back = root . 'agent/booking/' . urlencode((string)$invoiceId) . '/pay';

    // Idempotent: if already paid, just go to the booking.
    if (($booking['payment_status'] ?? '') === 'paid') {
        $_SESSION['message'] = ['type'=>'success','text'=>'This booking is already paid.'];
        header('Location: ' . root . 'agent/bookings'); exit;
    }
    // Pending agency cannot transact.
    if (!empty($ctx['is_owner']) && empty($ctx['owner_active'])) {
        $_SESSION['message'] = ['type'=>'error','text'=>'Your agency is pending approval.'];
        header('Location: ' . $back); exit;
    }

    $agentCost = round((float) ($booking['price_markup'] ?? $booking['price_original'] ?? 0), 2);
    $currency = (string) ($booking['currency_markup'] ?? (function_exists('wallet_default_currency') ? wallet_default_currency($db) : 'NGN'));

    // Attribute the booking to the agency + which staff booked it, BEFORE charging,
    // so reporting/ownership are correct even if a later step is retried.
    try {
        $db->update('bookings', [
            'agency_id'          => $owner,
            'booked_by_staff_id' => $actor,
            'booking_channel'    => 'agent',
        ], ['invoice_id' => $invoiceId]);
    } catch (\Throwable $e) { error_log('agent settle attribute: ' . $e->getMessage()); }

    // Agency self-markup (P5/M10): client price = agent cost + agency markup.
    // Stored for the branded invoice + agent margin reporting. Agent still PAYS
    // $agentCost to us; the client price is what their branded invoice shows.
    if (function_exists('agent_apply_self_markup')) {
        try { agent_apply_self_markup($db, $owner, (string)$invoiceId, $agentCost, $currency); }
        catch (\Throwable $e) { error_log('agent self-markup: ' . $e->getMessage()); }
    }

    // Charge the wallet (idempotent per invoice+service; draws on credit line).
    if (!function_exists('agent_api_charge_wallet')) { require_once 'app/lib/functions.php'; }
    $charge = agent_api_charge_wallet($db, $owner, $service ?: 'booking', $agentCost, (string)$invoiceId, $currency);
    if (empty($charge['ok'])) {
        $_SESSION['message'] = ['type'=>'error','text'=>
            ($charge['message'] ?? 'Insufficient wallet balance.')
            . (isset($charge['required'],$charge['balance']) ? ' (need '.$currency.' '.number_format((float)$charge['required'],2).', balance '.$currency.' '.number_format((float)$charge['balance'],2).')' : '')];
        header('Location: ' . $back); exit;
    }

    // Mark paid-from-wallet. Keep the supplier issue loopback for LIVE bookings
    // (an own-inventory/static service with no issue endpoint is just confirmed).
    try {
        $db->update('bookings', [
            'payment_status'  => 'paid',
            'payment_gateway' => 'Wallet',
            'paid_at'         => date('Y-m-d H:i:s'),
            'transaction_id'  => 'AGT-' . $invoiceId,
        ], ['invoice_id' => $invoiceId]);
    } catch (\Throwable $e) { error_log('agent settle mark-paid: ' . $e->getMessage()); }

    // Fire the supplier issue loopback where the module supports it (reuses the
    // audited booking-manager primitive). Non-fatal: a failed issue leaves the
    // booking paid+pending for admin follow-up, exactly like the admin flow.
    $issued = false;
    $module = (string) ($booking['module'] ?? '');
    if (function_exists('bm_module_has_issue_endpoint') && function_exists('bm_fire_supplier_issue')
        && $service !== '' && $module !== '' && bm_module_has_issue_endpoint($service, $module)) {
        try { $res = bm_fire_supplier_issue($db, (string)$invoiceId, $service, $module); $issued = !empty($res['ok']); }
        catch (\Throwable $e) { error_log('agent settle issue: ' . $e->getMessage()); }
    } else {
        // No supplier issue endpoint → confirm directly.
        try { $db->update('bookings', ['booking_status'=>'confirmed'], ['invoice_id'=>$invoiceId]); } catch (\Throwable $e) {}
    }

    if (function_exists('agent_audit_log')) {
        agent_audit_log($db, $owner, $actor, 'booking.pay', $service . ' ' . $invoiceId . ' ' . $currency . ' ' . $agentCost);
    }

    $_SESSION['message'] = ['type'=>'success','text'=>'Booking paid from wallet'.($issued?' and issued':'').'. Invoice '.$invoiceId.'.'];
    header('Location: ' . root . 'agent/bookings');
    exit;
});

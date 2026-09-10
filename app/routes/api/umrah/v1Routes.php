<?php
// app/routes/api/umrah/v1Routes.php
// UMRAH REDESIGN — Customer API v1 (docs/UMRAH-PHASE1-BUILD-PLAN.md §4/Step 6).
// Thin HTTP layer over the verified umrah service functions (app/lib/umrah/
// services.php). Server is the only price authority; the browser references a
// quote_ref and the server recomputes/validates at hold, booking and payment.
@$SECURE or die('Access Denied!');

// ---- small local helpers -------------------------------------------------
if (!function_exists('umrah_v1_json')) {
    function umrah_v1_json($payload, int $code = 200): void
    {
        if (!headers_sent()) { http_response_code($code); header('Content-Type: application/json'); }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
if (!function_exists('umrah_v1_body')) {
    function umrah_v1_body(): array
    {
        $raw = file_get_contents('php://input');
        $j = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($j) ? $j : $_POST;
    }
}
if (!function_exists('umrah_v1_user')) {
    /** Resolve the caller's user_id from JWT bearer or session (nullable). */
    function umrah_v1_user(): ?string
    {
        if (!empty($_SESSION['user_id'])) { return (string) $_SESSION['user_id']; }
        $headers = function_exists('getallheaders') ? array_change_key_case((array) getallheaders(), CASE_LOWER) : [];
        $auth = $headers['authorization'] ?? '';
        if (preg_match('/Bearer\s+(\S+)/i', $auth, $m) && class_exists('JWT')) {
            $tok = JWT::verify($m[1]);
            if ($tok && !empty($tok['user_id'])) { return (string) $tok['user_id']; }
        }
        return null;
    }
}

// ---- GET /api/v1/umrah/departures ---------------------------------------
// Published departures with their Standard (bookable) tier price + availability.
$router->get('/api/v1/umrah/departures', function () use ($db) {
    $rows = $db->select('umrah_departures', '*', [
        'status' => 'published',
        'ORDER'  => ['departure_date' => 'ASC'],
    ]) ?: [];
    $out = [];
    foreach ($rows as $d) {
        $dts = $db->select('umrah_departure_tiers', '*', ['departure_id' => $d['id'], 'status' => 'active']) ?: [];
        $tiers = [];
        foreach ($dts as $dt) {
            $priced = umrah_price_resolve($db, $dt);
            $cap = umrah_capacity_for($db, (int) $dt['id']);
            $tier = $db->get('umrah_tiers', ['code', 'public_label', 'room_sharing'], ['id' => $dt['tier_id']]);
            $tiers[] = [
                'departure_tier_id' => (int) $dt['id'],
                'tier_code'   => $tier['code'] ?? null,
                'tier_label'  => $tier['public_label'] ?? null,
                'room_sharing'=> $tier['room_sharing'] ?? null,
                'unit_price'  => $priced['unit'],
                'currency'    => $priced['currency'],
                'promo'       => $priced['promo'],
                'availability'=> ($cap['remaining'] <= 0) ? 'sold_out'
                                 : (($cap['remaining'] <= ($d['low_stock_threshold'] ?? 10)) ? 'limited' : 'available'),
                'remaining'   => !empty($d['display_inventory_count']) ? (int) $cap['remaining'] : null,
            ];
        }
        $out[] = [
            'departure_id'  => (int) $d['id'],
            'code'          => $d['code'],
            'departure_date'=> $d['departure_date'],
            'return_date'   => $d['return_date'],
            'month_bucket'  => $d['month_bucket'],
            'tiers'         => $tiers,
        ];
    }
    umrah_v1_json(['success' => true, 'departures' => $out]);
});

// ---- GET /api/v1/umrah/packages/{slug} ----------------------------------
$router->get('/api/v1/umrah/packages/([a-z0-9\-]+)', function ($slug) use ($db) {
    $tpl = $db->get('umrah_package_templates', '*', ['slug' => $slug, 'status' => 1]);
    if (!$tpl) { umrah_v1_json(['success' => false, 'message' => 'Package not found'], 404); }
    $tpl['itinerary_order'] = json_decode((string) $tpl['itinerary_order'], true);
    $tpl['inclusions'] = json_decode((string) $tpl['inclusions'], true);
    // Attach published departures for this template.
    $deps = $db->select('umrah_departures', ['id', 'code', 'departure_date', 'return_date', 'month_bucket'],
        ['template_id' => $tpl['id'], 'status' => 'published', 'ORDER' => ['departure_date' => 'ASC']]) ?: [];
    umrah_v1_json(['success' => true, 'package' => $tpl, 'departures' => $deps]);
});

// ---- GET /api/v1/umrah/departures/{id} ----------------------------------
$router->get('/api/v1/umrah/departures/([0-9]+)', function ($id) use ($db) {
    $d = $db->get('umrah_departures', '*', ['id' => (int) $id, 'status' => 'published']);
    if (!$d) { umrah_v1_json(['success' => false, 'message' => 'Departure not found'], 404); }
    $tpl = $db->get('umrah_package_templates', '*', ['id' => $d['template_id']]);
    if ($tpl) { $tpl['inclusions'] = json_decode((string) $tpl['inclusions'], true); }
    $dts = $db->select('umrah_departure_tiers', '*', ['departure_id' => $d['id'], 'status' => 'active']) ?: [];
    $tiers = [];
    foreach ($dts as $dt) {
        $priced = umrah_price_resolve($db, $dt);
        $cap = umrah_capacity_for($db, (int) $dt['id']);
        $tier = $db->get('umrah_tiers', ['code', 'public_label', 'default_occupancy', 'room_sharing'], ['id' => $dt['tier_id']]);
        $tiers[] = [
            'departure_tier_id' => (int) $dt['id'],
            'tier' => $tier, 'unit_price' => $priced['unit'], 'currency' => $priced['currency'],
            'promo' => $priced['promo'], 'booking_mode' => $dt['booking_mode'],
            'availability' => ($cap['remaining'] <= 0) ? 'sold_out' : (($cap['remaining'] <= ($d['low_stock_threshold'] ?? 10)) ? 'limited' : 'available'),
        ];
    }
    $plans = $db->select('umrah_payment_plans', ['code', 'name', 'deposit_percent', 'second_percent', 'final_percent'], ['active' => 1]) ?: [];
    umrah_v1_json(['success' => true, 'departure' => $d, 'template' => $tpl, 'tiers' => $tiers, 'payment_plans' => $plans]);
});

// ---- POST /api/v1/umrah/quotes ------------------------------------------
$router->post('/api/v1/umrah/quotes', function () use ($db) {
    $in = umrah_v1_body();
    $dtId = (int) ($in['departure_tier_id'] ?? 0);
    $pax  = (int) ($in['pax'] ?? 1);
    if ($dtId <= 0) { umrah_v1_json(['success' => false, 'message' => 'departure_tier_id required'], 422); }
    $q = umrah_price_quote($db, $dtId, $pax);
    if (empty($q['ok'])) { umrah_v1_json(['success' => false, 'message' => $q['message'] ?? 'Cannot quote'], 422); }
    umrah_v1_json(['success' => true, 'quote' => $q]);
});

// ---- POST /api/v1/umrah/holds -------------------------------------------
$router->post('/api/v1/umrah/holds', function () use ($db) {
    $in = umrah_v1_body();
    $quoteRef = trim((string) ($in['quote_ref'] ?? ''));
    if ($quoteRef === '') { umrah_v1_json(['success' => false, 'message' => 'quote_ref required'], 422); }
    $quote = $db->get('umrah_quotes', '*', ['quote_ref' => $quoteRef]);
    if (!$quote) { umrah_v1_json(['success' => false, 'message' => 'Quote not found'], 404); }
    if (strtotime($quote['expires_at']) < time()) { umrah_v1_json(['success' => false, 'message' => 'Quote expired'], 409); }
    $hold = umrah_hold_create($db, (int) $quote['departure_tier_id'], (int) $quote['pax'], (int) $quote['id'], umrah_v1_user());
    if (empty($hold['ok'])) { umrah_v1_json(['success' => false, 'message' => $hold['message'] ?? 'No capacity', 'remaining' => $hold['remaining'] ?? 0], 409); }
    umrah_v1_json(['success' => true, 'hold' => $hold]);
});

// ---- POST /api/v1/umrah/bookings ----------------------------------------
$router->post('/api/v1/umrah/bookings', function () use ($db) {
    $in = umrah_v1_body();
    $quoteRef = trim((string) ($in['quote_ref'] ?? ''));
    $holdId   = (int) ($in['hold_id'] ?? 0);
    $plan     = trim((string) ($in['payment_plan'] ?? 'PP-50-25-25'));
    if ($quoteRef === '' || $holdId <= 0) { umrah_v1_json(['success' => false, 'message' => 'quote_ref and hold_id required'], 422); }
    $lead = [
        'first_name' => trim((string) ($in['first_name'] ?? '')),
        'last_name'  => trim((string) ($in['last_name'] ?? '')),
        'email'      => trim((string) ($in['email'] ?? '')),
        'phone'      => trim((string) ($in['phone'] ?? '')),
        'phone_country_code' => trim((string) ($in['phone_country_code'] ?? '')),
        'user_id'    => umrah_v1_user(),
    ];
    if ($lead['email'] === '' && $lead['phone'] === '') { umrah_v1_json(['success' => false, 'message' => 'Lead contact (email or phone) required'], 422); }
    $b = umrah_booking_create($db, $quoteRef, $holdId, $lead, $plan);
    if (empty($b['ok'])) { umrah_v1_json(['success' => false, 'message' => $b['message'] ?? 'Booking failed'], 409); }
    umrah_v1_json(['success' => true, 'booking' => $b]);
});

// ---- GET /api/v1/umrah/bookings/{ref} -----------------------------------
$router->get('/api/v1/umrah/bookings/([A-Za-z0-9\-]+)', function ($ref) use ($db) {
    $ub = $db->get('umrah_bookings', '*', ['booking_ref' => $ref]);
    if (!$ub) { umrah_v1_json(['success' => false, 'message' => 'Booking not found'], 404); }
    // Authorisation: owner or admin only.
    $uid = umrah_v1_user();
    $isAdmin = (($_SESSION['user_role'] ?? '') === 'admin');
    if (!$isAdmin && (!$uid || (string) $ub['user_id'] !== (string) $uid)) {
        umrah_v1_json(['success' => false, 'message' => 'Unauthorized'], 403);
    }
    $installments = $db->select('umrah_installments', ['seq', 'percent', 'amount', 'due_at', 'status', 'paid_at'],
        ['umrah_booking_id' => $ub['id'], 'ORDER' => ['seq' => 'ASC']]) ?: [];
    $ub['snapshot'] = json_decode((string) $ub['snapshot'], true);
    umrah_v1_json(['success' => true, 'booking' => $ub, 'installments' => $installments]);
});

// ---- POST /api/v1/umrah/bookings/{ref}/payments -------------------------
// Phase 1: initiate payment for the next due installment. Returns the amount +
// invoice so the existing gateway flow (process_payment) can take over. The
// actual settlement/price-lock happens via umrah_settle_payment on confirmation.
$router->post('/api/v1/umrah/bookings/([A-Za-z0-9\-]+)/payments', function ($ref) use ($db) {
    $ub = $db->get('umrah_bookings', '*', ['booking_ref' => $ref]);
    if (!$ub) { umrah_v1_json(['success' => false, 'message' => 'Booking not found'], 404); }
    $uid = umrah_v1_user();
    $isAdmin = (($_SESSION['user_role'] ?? '') === 'admin');
    if (!$isAdmin && (!$uid || (string) $ub['user_id'] !== (string) $uid)) {
        umrah_v1_json(['success' => false, 'message' => 'Unauthorized'], 403);
    }
    $next = $db->get('umrah_installments', ['seq', 'amount', 'due_at'],
        ['umrah_booking_id' => $ub['id'], 'status' => ['pending', 'overdue'], 'ORDER' => ['seq' => 'ASC']]);
    if (!$next) { umrah_v1_json(['success' => false, 'message' => 'No payment due — booking is fully paid'], 409); }
    umrah_v1_json(['success' => true, 'payment' => [
        'invoice_id' => $ub['invoice_id'],
        'booking_ref'=> $ub['booking_ref'],
        'installment_seq' => (int) $next['seq'],
        'amount'     => (float) $next['amount'],
        'currency'   => $ub['currency'],
        'due_at'     => $next['due_at'],
        'pay_url'    => root . 'invoice/umrah/' . $ub['invoice_id'],
    ]]);
});

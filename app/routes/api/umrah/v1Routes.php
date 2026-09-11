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
if (!function_exists('umrah_v1_csrf_guard')) {
    /**
     * CSRF guard for state-mutating v1 endpoints (audit M1). A Bearer-token
     * (mobile/API) client is NOT cookie-authenticated and therefore not CSRF-
     * exploitable — it is exempt. Any request WITHOUT a Bearer token is treated
     * as a browser/session (cookie) request and MUST carry a valid CSRF token
     * (JSON body `csrf_token` or the X-CSRF-TOKEN header). Dies 403 otherwise.
     */
    function umrah_v1_csrf_guard(array $in): void
    {
        $headers = function_exists('getallheaders') ? array_change_key_case((array) getallheaders(), CASE_LOWER) : [];
        $hasBearer = !empty($headers['authorization']) && preg_match('/Bearer\s+\S+/i', (string) $headers['authorization']);
        if ($hasBearer) { return; } // token client — no cookies, no CSRF risk

        $token = $in['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($headers['x-csrf-token'] ?? ''));
        if (!class_exists('CSRF') || !CSRF::validateToken((string) $token)) {
            umrah_v1_json(['success' => false, 'message' => 'Invalid or missing security token'], 403);
        }
    }
}
if (!function_exists('umrah_v1_can_manage_booking')) {
    /**
     * True when the caller may manage this umrah booking's pilgrims/documents:
     *   - admin; OR
     *   - the account owner (session/JWT user_id === booking user_id); OR
     *   - the GUEST who created it (booking_ref in the session allow-list that
     *     POST /bookings stamps for guest bookings). This mirrors the web
     *     confirmation-page gate so a guest can complete their own pilgrim
     *     details in the seamless post-booking flow (audit M6 fix, Phase B).
     * @param array $ub umrah_bookings row (needs user_id, booking_ref)
     */
    function umrah_v1_can_manage_booking(array $ub): bool
    {
        if (($_SESSION['user_role'] ?? '') === 'admin') { return true; }
        $uid = umrah_v1_user();
        $owner = (string) ($ub['user_id'] ?? '');
        if ($uid && $owner !== '' && $owner === (string) $uid) { return true; }
        // Guest booking (no owner) — must hold the ref in their session allow-list.
        if ($owner === '' && !empty($ub['booking_ref'])) {
            $allow = (array) ($_SESSION['umrah_guest_bookings'] ?? []);
            if (in_array($ub['booking_ref'], $allow, true)) { return true; }
        }
        return false;
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
    umrah_v1_csrf_guard($in);
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
    umrah_v1_csrf_guard($in);
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
    umrah_v1_csrf_guard($in);
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

    // AGENT API — wallet settlement. No-op on normal web bookings; on an
    // authenticated agent-API request this charges the agent's wallet for the
    // FULL booking total + service fee and, on insufficient funds, deletes the
    // booking and emits a 402 + exits. Mirrors every other service's submit route.
    if (function_exists('agent_api_active') && agent_api_active() && !empty($b['booking_id'])) {
        $walletTotal = (float) ($b['total_price'] ?? 0);
        $walletTxn   = 'WALLET-' . (string) $b['invoice_id'];
        // 1) Charge the wallet + mark the generic bookings row paid (or 402+exit).
        agent_api_settle_booking($db, 'umrah', (int) $b['booking_id'], (string) $b['invoice_id'], $walletTotal);
        // 2) Reconcile the umrah domain row + installments to fully paid, consume
        //    the hold and price-lock — agents pay the full amount upfront by
        //    wallet, so the installment schedule is satisfied in one settlement.
        //    Idempotent (keyed on invoice+txn).
        if (function_exists('umrah_settle_payment')) {
            umrah_settle_payment($db, (string) $b['invoice_id'], $walletTotal, (string) ($b['currency'] ?? ''), $walletTxn);
        }
    }

    // Guest bookings have no owner user_id — record the ref in this session's
    // allow-list so the guest can view their own confirmation page (IDOR guard
    // in the web route relies on this). Owned bookings gate on user_id instead.
    if (empty($lead['user_id']) && !empty($b['booking_ref'])) {
        $_SESSION['umrah_guest_bookings'] = array_values(array_unique(array_merge(
            (array) ($_SESSION['umrah_guest_bookings'] ?? []),
            [$b['booking_ref']]
        )));
    }
    umrah_v1_json(['success' => true, 'booking' => $b]);
});

// ========================================================================
// MULTI-DEPARTURE CART (Phase B3) — a customer can add several Umrah
// departures and pay together, like buying tickets for multiple passengers.
// The cart lives in the session and is ALWAYS re-priced server-side (never
// trust client prices). Checkout creates one booking per line via the verified
// quote→hold→book engine (each with the 5-pax cap) and returns their refs.
// ========================================================================
if (!function_exists('umrah_cart_reprice')) {
    /** Rebuild the cart with authoritative prices + availability from the DB. */
    function umrah_cart_reprice($db): array
    {
        $cart = (array) ($_SESSION['umrah_cart'] ?? []);
        $out = []; $grand = 0.0; $currency = 'NGN';
        foreach ($cart as $key => $line) {
            $dtId = (int) ($line['departure_tier_id'] ?? 0);
            $pax  = max(1, (int) ($line['pax'] ?? 1));
            $dt = $db->get('umrah_departure_tiers', '*', ['id' => $dtId]);
            if (!$dt || !umrah_departure_bookable($db, (int) $dt['departure_id'], $dt)) { continue; }
            $priced = umrah_price_resolve($db, $dt);
            $unit = (float) ($priced['unit'] ?? 0);
            if ($unit <= 0) { continue; }
            $dep = $db->get('umrah_departures', ['code', 'origin_city', 'departure_date', 'return_date'], ['id' => (int) $dt['departure_id']]);
            $tier = $db->get('umrah_tiers', ['code', 'public_label'], ['id' => (int) $dt['tier_id']]);
            $lineTotal = round($unit * $pax, 2);
            $grand += $lineTotal; $currency = $priced['currency'] ?? $currency;
            $out[] = [
                'key' => $key,
                'departure_tier_id' => $dtId,
                'pax' => $pax,
                'city' => $dep['origin_city'] ?? 'Kano',
                'label' => $dep ? (date('d M', strtotime($dep['departure_date'])) . ' → ' . date('d M Y', strtotime($dep['return_date']))) : '',
                'tier_code' => $tier['code'] ?? 'standard',
                'tier_label' => $tier['public_label'] ?? 'Standard',
                'unit_price' => $unit,
                'line_total' => $lineTotal,
                'currency' => $priced['currency'] ?? 'NGN',
            ];
        }
        return ['items' => $out, 'grand_total' => round($grand, 2), 'currency' => $currency, 'count' => count($out)];
    }
}

// Add a departure-tier to the cart.
$router->post('/api/v1/umrah/cart/add', function () use ($db) {
    $in = umrah_v1_body();
    umrah_v1_csrf_guard($in);
    $dtId = (int) ($in['departure_tier_id'] ?? 0);
    $pax  = max(1, (int) ($in['pax'] ?? 1));
    if ($dtId <= 0) { umrah_v1_json(['success' => false, 'message' => 'departure_tier_id required'], 422); }
    // Cap per line (customers). Agents use groups, not the cart.
    if (!umrah_is_agent() && $pax > (defined('UMRAH_CUSTOMER_MAX_PAX') ? UMRAH_CUSTOMER_MAX_PAX : 5)) {
        umrah_v1_json(['success' => false, 'message' => 'Up to ' . (defined('UMRAH_CUSTOMER_MAX_PAX') ? UMRAH_CUSTOMER_MAX_PAX : 5) . ' pilgrims per departure'], 422);
    }
    $dt = $db->get('umrah_departure_tiers', ['id', 'departure_id'], ['id' => $dtId]);
    if (!$dt || !umrah_departure_bookable($db, (int) $dt['departure_id'], $dt)) {
        umrah_v1_json(['success' => false, 'message' => 'This departure is not open for booking'], 409);
    }
    if (!isset($_SESSION['umrah_cart']) || !is_array($_SESSION['umrah_cart'])) { $_SESSION['umrah_cart'] = []; }
    // One line per departure-tier; adding again updates the pax.
    $key = 'dt' . $dtId;
    $_SESSION['umrah_cart'][$key] = ['departure_tier_id' => $dtId, 'pax' => $pax];
    umrah_v1_json(['success' => true, 'cart' => umrah_cart_reprice($db)]);
});

// View the cart (server-repriced).
$router->get('/api/v1/umrah/cart', function () use ($db) {
    umrah_v1_json(['success' => true, 'cart' => umrah_cart_reprice($db)]);
});

// Remove a line.
$router->post('/api/v1/umrah/cart/remove', function () use ($db) {
    $in = umrah_v1_body();
    umrah_v1_csrf_guard($in);
    $key = (string) ($in['key'] ?? '');
    if ($key !== '' && isset($_SESSION['umrah_cart'][$key])) { unset($_SESSION['umrah_cart'][$key]); }
    umrah_v1_json(['success' => true, 'cart' => umrah_cart_reprice($db)]);
});

// Checkout the whole cart: create a booking per line (quote→hold→book).
$router->post('/api/v1/umrah/cart/checkout', function () use ($db) {
    $in = umrah_v1_body();
    umrah_v1_csrf_guard($in);
    $lead = [
        'first_name' => trim((string) ($in['first_name'] ?? '')),
        'last_name'  => trim((string) ($in['last_name'] ?? '')),
        'email'      => trim((string) ($in['email'] ?? '')),
        'phone'      => trim((string) ($in['phone'] ?? '')),
        'user_id'    => umrah_v1_user(),
    ];
    if ($lead['first_name'] === '') { umrah_v1_json(['success' => false, 'message' => 'Lead name required'], 422); }
    if ($lead['email'] === '' && $lead['phone'] === '') { umrah_v1_json(['success' => false, 'message' => 'Email or phone required'], 422); }
    $plan = trim((string) ($in['payment_plan'] ?? 'PP-50-25-25'));

    $cart = umrah_cart_reprice($db);
    if (empty($cart['items'])) { umrah_v1_json(['success' => false, 'message' => 'Your cart is empty'], 409); }

    $created = []; $errors = [];
    foreach ($cart['items'] as $line) {
        $dtId = (int) $line['departure_tier_id'];
        $pax  = (int) $line['pax'];
        $q = umrah_price_quote($db, $dtId, $pax);
        if (empty($q['ok'])) { $errors[] = $line['label'] . ': ' . ($q['message'] ?? 'quote failed'); continue; }
        $h = umrah_hold_create($db, $dtId, $pax, (int) $q['quote_id'], $lead['user_id']);
        if (empty($h['ok'])) { $errors[] = $line['label'] . ': ' . ($h['message'] ?? 'no capacity'); continue; }
        $b = umrah_booking_create($db, $q['quote_ref'], (int) $h['hold_id'], $lead, $plan);
        if (empty($b['ok'])) { $errors[] = $line['label'] . ': ' . ($b['message'] ?? 'booking failed'); continue; }
        // Guest allow-list so they can view/manage each booking.
        if (empty($lead['user_id']) && !empty($b['booking_ref'])) {
            $_SESSION['umrah_guest_bookings'] = array_values(array_unique(array_merge(
                (array) ($_SESSION['umrah_guest_bookings'] ?? []), [$b['booking_ref']]
            )));
        }
        $created[] = ['booking_ref' => $b['booking_ref'], 'invoice_id' => $b['invoice_id'], 'total_price' => $b['total_price'], 'label' => $line['label']];
    }
    if (!$created) { umrah_v1_json(['success' => false, 'message' => 'Could not create bookings', 'errors' => $errors], 409); }
    // Clear the cart on success; keep any lines that errored is overkill — we
    // clear fully and report errors so the customer re-adds if needed.
    $_SESSION['umrah_cart'] = [];
    umrah_v1_json(['success' => true, 'bookings' => $created, 'errors' => $errors]);
});

// ========================================================================
// AGENT GROUPS (Phase C). All CSRF-guarded; the service layer enforces the
// agent-only / owner checks and the tier group rules. Money moves ONLY at
// /groups/submit (wallet debit).
// ========================================================================
$router->post('/api/v1/umrah/groups', function () use ($db) {
    $in = umrah_v1_body(); umrah_v1_csrf_guard($in);
    if (!function_exists('umrah_group_create')) { umrah_v1_json(['success' => false, 'message' => 'Unavailable'], 500); }
    $r = umrah_group_create($db, $in);
    umrah_v1_json($r['ok'] ? ['success' => true, 'group_ref' => $r['group_ref'], 'group_id' => $r['group_id']] : ['success' => false, 'message' => $r['message'] ?? 'Failed'], $r['ok'] ? 200 : 422);
});

// List the agent's own groups (+ a summary).
$router->get('/api/v1/umrah/groups', function () use ($db) {
    if (!function_exists('umrah_group_actor')) { umrah_v1_json(['success' => false, 'message' => 'Unavailable'], 500); }
    $agent = umrah_group_actor();
    if ($agent === '') { umrah_v1_json(['success' => false, 'message' => 'Agents only'], 403); }
    $groups = $db->select('umrah_groups', '*', ['agent_user_id' => $agent, 'ORDER' => ['id' => 'DESC']]) ?: [];
    umrah_v1_json(['success' => true, 'groups' => $groups]);
});

// Group detail (owner/admin) + its members.
$router->get('/api/v1/umrah/groups/([0-9]+)', function ($gid) use ($db) {
    $g = function_exists('umrah_group_owned') ? umrah_group_owned($db, (int) $gid) : null;
    if (!$g) { umrah_v1_json(['success' => false, 'message' => 'Group not found'], 404); }
    $members = $db->select('umrah_group_members', '*', ['group_id' => (int) $gid, 'ORDER' => ['id' => 'ASC']]) ?: [];
    umrah_v1_json(['success' => true, 'group' => $g, 'members' => $members]);
});

// Update declared counts.
$router->post('/api/v1/umrah/groups/([0-9]+)/counts', function ($gid) use ($db) {
    $in = umrah_v1_body(); umrah_v1_csrf_guard($in);
    $r = umrah_group_set_counts($db, (int) $gid, (int) ($in['declared_male'] ?? 0), (int) ($in['declared_female'] ?? 0));
    umrah_v1_json($r['ok'] ? ['success' => true, 'group' => $r] : ['success' => false, 'message' => $r['message'] ?? 'Failed'], $r['ok'] ? 200 : 422);
});

// Add / update a member.
$router->post('/api/v1/umrah/groups/([0-9]+)/members', function ($gid) use ($db) {
    $in = umrah_v1_body(); umrah_v1_csrf_guard($in);
    $r = umrah_group_add_member($db, (int) $gid, $in);
    umrah_v1_json($r['ok'] ? ['success' => true, 'member_id' => $r['member_id']] : ['success' => false, 'message' => $r['message'] ?? 'Failed'], $r['ok'] ? 200 : 422);
});

// Drop a member.
$router->post('/api/v1/umrah/groups/([0-9]+)/members/([0-9]+)/drop', function ($gid, $mid) use ($db) {
    $in = umrah_v1_body(); umrah_v1_csrf_guard($in);
    $r = umrah_group_drop_member($db, (int) $gid, (int) $mid);
    umrah_v1_json($r['ok'] ? ['success' => true] : ['success' => false, 'message' => $r['message'] ?? 'Failed'], $r['ok'] ? 200 : 422);
});

// Submit the group (debits the agent wallet, materializes the booking).
$router->post('/api/v1/umrah/groups/([0-9]+)/submit', function ($gid) use ($db) {
    $in = umrah_v1_body(); umrah_v1_csrf_guard($in);
    $r = umrah_group_submit($db, (int) $gid, $in);
    if (!empty($r['ok'])) { umrah_v1_json(['success' => true, 'booking_ref' => $r['booking_ref'] ?? null, 'invoice_id' => $r['invoice_id'] ?? null, 'already' => !empty($r['already'])]); }
    $code = (($r['code'] ?? '') === 'insufficient_funds') ? 402 : 422;
    umrah_v1_json(['success' => false, 'message' => $r['message'] ?? 'Submit failed', 'required' => $r['required'] ?? null, 'balance' => $r['balance'] ?? null], $code);
});

// Cancel (agent, pre-payment).
$router->post('/api/v1/umrah/groups/([0-9]+)/cancel', function ($gid) use ($db) {
    $in = umrah_v1_body(); umrah_v1_csrf_guard($in);
    $r = umrah_group_set_status($db, (int) $gid, 'cancelled');
    umrah_v1_json($r['ok'] ? ['success' => true] : ['success' => false, 'message' => $r['message'] ?? 'Failed'], $r['ok'] ? 200 : 422);
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

// ---- POST /api/v1/umrah/bookings/{ref}/travellers -----------------------
// Add/update a pilgrim on the booking (owner or admin).
$router->post('/api/v1/umrah/bookings/([A-Za-z0-9\-]+)/travellers', function ($ref) use ($db) {
    $ub = $db->get('umrah_bookings', '*', ['booking_ref' => $ref]);
    if (!$ub) { umrah_v1_json(['success' => false, 'message' => 'Booking not found'], 404); }
    if (!umrah_v1_can_manage_booking($ub)) { umrah_v1_json(['success' => false, 'message' => 'Unauthorized'], 403); }
    $travIn = umrah_v1_body();
    umrah_v1_csrf_guard($travIn);
    if (!function_exists('umrah_traveller_add')) { umrah_v1_json(['success' => false, 'message' => 'Unavailable'], 500); }
    $r = umrah_traveller_add($db, (int) $ub['id'], $travIn);
    umrah_v1_json($r['ok'] ? ['success' => true, 'traveller_id' => $r['traveller_id']] : ['success' => false, 'message' => $r['message'] ?? 'Failed'], $r['ok'] ? 200 : 422);
});

// ---- POST /api/v1/umrah/travellers/{id}/documents -----------------------
// Secure document upload (multipart). Owner or admin.
$router->post('/api/v1/umrah/travellers/([0-9]+)/documents', function ($tid) use ($db) {
    $tr = $db->get('umrah_booking_travellers', ['id', 'umrah_booking_id'], ['id' => (int) $tid]);
    if (!$tr) { umrah_v1_json(['success' => false, 'message' => 'Traveller not found'], 404); }
    $ub = $db->get('umrah_bookings', ['user_id', 'booking_ref'], ['id' => $tr['umrah_booking_id']]);
    if (!$ub || !umrah_v1_can_manage_booking($ub)) { umrah_v1_json(['success' => false, 'message' => 'Unauthorized'], 403); }
    umrah_v1_csrf_guard($_POST); // multipart upload — token in POST field / header
    $docType = $_POST['doc_type'] ?? 'passport';
    if (!function_exists('umrah_document_upload')) { umrah_v1_json(['success' => false, 'message' => 'Unavailable'], 500); }
    $r = umrah_document_upload($db, (int) $tid, (string) $docType, 'file');
    umrah_v1_json($r['ok'] ? ['success' => true, 'document_id' => $r['document_id']] : ['success' => false, 'message' => $r['message'] ?? 'Upload failed'], $r['ok'] ? 200 : 422);
});

// ---- POST /api/v1/umrah/waitlist ----------------------------------------
$router->post('/api/v1/umrah/waitlist', function () use ($db) {
    $in = umrah_v1_body();
    umrah_v1_csrf_guard($in);
    // Audit (low): validate input so the table can't be flooded with junk.
    $name  = trim((string) ($in['name'] ?? ''));
    $email = trim((string) ($in['email'] ?? ''));
    $phone = trim((string) ($in['phone'] ?? ''));
    if ($name === '') { umrah_v1_json(['success' => false, 'message' => 'Name is required'], 422); }
    if ($email === '' && $phone === '') { umrah_v1_json(['success' => false, 'message' => 'Email or phone is required'], 422); }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { umrah_v1_json(['success' => false, 'message' => 'Invalid email'], 422); }
    // departure_id, if given, must reference a real departure.
    $depId = !empty($in['departure_id']) ? (int) $in['departure_id'] : null;
    if ($depId !== null && !$db->get('umrah_departures', 'id', ['id' => $depId])) {
        umrah_v1_json(['success' => false, 'message' => 'Unknown departure'], 422);
    }
    // Lightweight anti-spam: collapse duplicate waiting rows for same contact+departure.
    $dupWhere = ['status' => 'waiting', 'departure_id' => $depId];
    if ($email !== '') { $dupWhere['email'] = $email; } else { $dupWhere['phone'] = $phone; }
    if ($db->get('umrah_waitlist', 'id', $dupWhere)) {
        umrah_v1_json(['success' => true, 'message' => "You're already on the waitlist"]);
    }
    $db->insert('umrah_waitlist', [
        'departure_id' => $depId,
        'tier_code' => mb_substr((string) ($in['tier_code'] ?? 'standard'), 0, 32),
        'name' => mb_substr($name, 0, 160),
        'email' => mb_substr($email, 0, 160),
        'phone' => mb_substr($phone, 0, 64),
        'pax' => min(50, max(1, (int) ($in['pax'] ?? 1))),
        'alt_dates' => isset($in['alt_dates']) ? mb_substr((string) $in['alt_dates'], 0, 255) : null,
        'status' => 'waiting',
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    umrah_v1_json(['success' => true, 'message' => 'Added to waitlist']);
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

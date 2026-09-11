<?php
// app/routes/umrah/umrahV2Routes.php
// UMRAH REDESIGN — customer web (docs/UMRAH-PHASE1-BUILD-PLAN.md Step 7).
// New landing + stable package-detail + booking confirmation. Reads the verified
// umrah_* model via the service helpers. Registered BEFORE the legacy umrah
// routes so /umrah, /umrah/packages/* and the legacy redirect take precedence.
@$SECURE or die('Access Denied!');

// Shared loader: published departures, each summarised by its CHEAPEST active
// tier as the "from" price (so a departure card shows the entry price and the
// detail page shows every tier). Carries origin_city for the city+month search.
if (!function_exists('umrahV2PublishedDepartures')) {
    function umrahV2PublishedDepartures($db): array
    {
        $rows = $db->select('umrah_departures', '*', [
            'status' => 'published',
            'ORDER'  => ['departure_date' => 'ASC'],
        ]) ?: [];
        $out = [];
        foreach ($rows as $d) {
            $tiers = $db->select('umrah_departure_tiers', '*', [
                'departure_id' => $d['id'], 'status' => 'active',
                'ORDER' => ['id' => 'ASC'],
            ]) ?: [];
            if (!$tiers) { continue; }
            // Pick the cheapest priced tier as the "from" entry price.
            $best = null; $bestPriced = null;
            foreach ($tiers as $dt) {
                $priced = function_exists('umrah_price_resolve')
                    ? umrah_price_resolve($db, $dt)
                    : ['unit' => (float) ($dt['promo_price'] ?? 0), 'currency' => $dt['currency'] ?? 'NGN', 'promo' => null];
                if (($priced['unit'] ?? 0) <= 0) { continue; }
                if ($best === null || $priced['unit'] < $bestPriced['unit']) { $best = $dt; $bestPriced = $priced; }
            }
            if ($best === null) { continue; }
            $cap = function_exists('umrah_capacity_for') ? umrah_capacity_for($db, (int) $best['id']) : ['remaining' => 0];
            $tier = $db->get('umrah_tiers', ['code', 'public_label', 'room_sharing'], ['id' => $best['tier_id']]);
            $avail = ($cap['remaining'] <= 0) ? 'sold_out'
                : (($cap['remaining'] <= (int) ($d['low_stock_threshold'] ?? 10)) ? 'limited' : 'available');
            // Price range + tier codes across all active tiers (for the card + tier filter).
            $prices = []; $tierCodes = [];
            foreach ($tiers as $dt2) {
                $p2 = function_exists('umrah_price_resolve') ? umrah_price_resolve($db, $dt2) : ['unit' => (float) ($dt2['promo_price'] ?? 0)];
                if (($p2['unit'] ?? 0) > 0) { $prices[] = (float) $p2['unit']; }
                $tc = $db->get('umrah_tiers', ['code'], ['id' => $dt2['tier_id']]);
                if ($tc) { $tierCodes[] = $tc['code']; }
            }
            $out[] = [
                'departure_id'      => (int) $d['id'],
                'departure_tier_id' => (int) $best['id'],
                'code'              => $d['code'],
                'origin_city'       => $d['origin_city'] ?: 'Kano',
                'hero_image'        => $d['hero_image'] ?: '',
                'departure_date'    => $d['departure_date'],
                'return_date'       => $d['return_date'],
                'month_bucket'      => $d['month_bucket'] ?: date('F Y', strtotime($d['departure_date'])),
                'tier_code'         => $tier['code'] ?? 'standard',
                'tier_label'        => $tier['public_label'] ?? 'Standard Economy',
                'room_sharing'      => $tier['room_sharing'] ?? '4-5 sharing',
                'unit_price'        => $bestPriced['unit'],
                'price_min'         => $prices ? min($prices) : $bestPriced['unit'],
                'price_max'         => $prices ? max($prices) : $bestPriced['unit'],
                'currency'          => $bestPriced['currency'],
                'promo'             => $bestPriced['promo'],
                'availability'      => $avail,
                'tier_count'        => count($tiers),
                'tier_codes'        => $tierCodes,
            ];
        }
        return $out;
    }
}

// ---- NEW LANDING: GET /umrah  (and /umrah/) -----------------------------
$umrahV2Landing = function () use ($SECURE, $db) {
    $template = $db->get('umrah_package_templates', '*', ['slug' => 'normal-umrah-14-day']);
    if ($template) {
        $template['inclusions'] = json_decode((string) $template['inclusions'], true) ?: [];
        $template['itinerary_order'] = json_decode((string) $template['itinerary_order'], true) ?: [];
    }
    $departures = umrahV2PublishedDepartures($db);
    // group by month bucket for the cards section
    $byMonth = [];
    foreach ($departures as $d) { $byMonth[$d['month_bucket']][] = $d; }
    $tiers = $db->select('umrah_tiers', '*', ['status' => 1, 'ORDER' => ['sort_order' => 'ASC']]) ?: [];

    // Search facets: distinct departure cities + months (preserve chronological
    // order for months by keying on the first departure_date seen).
    $cities = [];
    $months = [];
    foreach ($departures as $d) {
        $cities[$d['origin_city']] = true;
        if (!isset($months[$d['month_bucket']])) { $months[$d['month_bucket']] = $d['departure_date']; }
    }
    $cities = array_keys($cities);
    asort($months); // chronological by first date
    $months = array_keys($months);

    $title = ($template['meta_title'] ?? null) ?: ('Umrah 2026 | ' . ($GLOBALS['app']['business_name'] ?? 'GoGlobia'));
    $description = ($template['meta_description'] ?? null) ?: 'GoGlobia Umrah 2026 — 14-day packages, flights, visa, hotels, transport and support.';

    require_once views . 'includes/header.php';
    require_once views . 'modules/umrah/v2/landing.php';
    require_once views . 'includes/footer.php';
};
$router->get('/umrah', $umrahV2Landing);
$router->get('/umrah/', $umrahV2Landing);

// ---- DEDICATED RESULTS PAGE (flight-style): GET /umrah/search[/{city}/{month}/{pax}]
// Mirrors the flights listing: a filter sidebar (city/month/price/tier/avail)
// + image result cards, one per departure (a "package"). Prefilters by the
// homepage widget's city/month/pax when present.
$umrahV2Results = function ($city = 'any', $month = 'any', $pax = '1') use ($SECURE, $db) {
    $departures = umrahV2PublishedDepartures($db);

    // Facets for the sidebar.
    $cities = []; $months = [];
    foreach ($departures as $d) {
        $cities[$d['origin_city']] = true;
        if (!isset($months[$d['month_bucket']])) { $months[$d['month_bucket']] = $d['departure_date']; }
    }
    $cities = array_keys($cities);
    asort($months);
    $months = array_keys($months);
    $tiers = $db->select('umrah_tiers', ['code', 'public_label', 'name', 'sort_order'], ['status' => 1, 'ORDER' => ['sort_order' => 'ASC']]) ?: [];

    // Prefill from the widget (URL-decoded; 'any' = no filter).
    $preCity  = ($city !== 'any' && $city !== '') ? urldecode($city) : '';
    $preMonth = ($month !== 'any' && $month !== '') ? urldecode($month) : '';
    $prePax   = max(1, min(5, (int) $pax));

    $title = 'Umrah packages' . ($preCity ? ' from ' . htmlspecialchars($preCity) : '') . ' | ' . ($GLOBALS['app']['business_name'] ?? 'GoGlobia');
    $description = 'Choose your GoGlobia Umrah departure — filter by city, month, price and comfort tier.';

    require_once views . 'includes/header.php';
    require_once views . 'modules/umrah/v2/results.php';
    require_once views . 'includes/footer.php';
};
$router->get('/umrah/search', $umrahV2Results);
$router->get('/umrah/search/([^/]+)/([^/]+)/([0-9]+)', $umrahV2Results);

// ---- STABLE PACKAGE DETAIL: GET /umrah/packages/{slug} ------------------
$router->get('/umrah/packages/([a-z0-9\-]+)', function ($slug) use ($SECURE, $db) {
    $template = $db->get('umrah_package_templates', '*', ['slug' => $slug, 'status' => 1]);
    if (!$template) {
        header('Location: ' . root . 'umrah');
        exit;
    }
    $template['inclusions'] = json_decode((string) $template['inclusions'], true) ?: [];
    $template['itinerary_order'] = json_decode((string) $template['itinerary_order'], true) ?: [];

    // Departures for this template (published).
    $all = umrahV2PublishedDepartures($db);
    $departures = array_values(array_filter($all, function ($d) use ($db, $template) {
        $dep = $db->get('umrah_departures', ['template_id'], ['id' => $d['departure_id']]);
        return $dep && (int) $dep['template_id'] === (int) $template['id'];
    }));

    $selectedDepartureId = isset($_GET['departure']) ? (int) $_GET['departure'] : ($departures[0]['departure_id'] ?? 0);
    $plans = $db->select('umrah_payment_plans', '*', ['active' => 1, 'ORDER' => ['deposit_percent' => 'DESC']]) ?: [];

    // Images per departure: hero + gallery (fall back to the template hero).
    $departureMedia = [];
    foreach ($departures as $d) {
        $depRow = $db->get('umrah_departures', ['hero_image', 'gallery'], ['id' => $d['departure_id']]);
        $hero = $depRow['hero_image'] ?: ($template['hero_image'] ?? '');
        $gallery = json_decode((string) ($depRow['gallery'] ?? ''), true) ?: [];
        if (!$gallery && $hero) { $gallery = [$hero]; }
        $departureMedia[(int) $d['departure_id']] = ['hero' => $hero, 'gallery' => $gallery];
    }

    // All active tiers per departure (for the tier selector on the detail page).
    // Priced via umrah_price_resolve (agent-aware: an agent sees their B2B net).
    // Each tier carries its own inclusions (overrides template when set).
    $templateInclusions = $template['inclusions'] ?: [];
    $departureTiers = [];
    foreach ($departures as $d) {
        $rows = $db->select('umrah_departure_tiers', '*', [
            'departure_id' => $d['departure_id'], 'status' => 'active',
        ]) ?: [];
        $list = [];
        foreach ($rows as $dt) {
            $priced = umrah_price_resolve($db, $dt);
            if (($priced['unit'] ?? 0) <= 0) { continue; }
            $tier = $db->get('umrah_tiers', ['code', 'name', 'public_label', 'room_sharing', 'sort_order'], ['id' => $dt['tier_id']]);
            $cap = umrah_capacity_for($db, (int) $dt['id']);
            $tierIncl = json_decode((string) ($dt['inclusions'] ?? ''), true);
            $list[] = [
                'departure_tier_id' => (int) $dt['id'],
                'tier_code'   => $tier['code'] ?? 'standard',
                'tier_label'  => $tier['public_label'] ?: ($tier['name'] ?? 'Standard'),
                'room_sharing'=> $tier['room_sharing'] ?? '',
                'sort_order'  => (int) ($tier['sort_order'] ?? 0),
                'unit_price'  => (float) $priced['unit'],
                'regular'     => $priced['promo']['regular'] ?? null,
                'currency'    => $priced['currency'],
                'inclusions'  => (is_array($tierIncl) && $tierIncl) ? $tierIncl : $templateInclusions,
                'availability'=> ($cap['remaining'] <= 0) ? 'sold_out'
                                  : (($cap['remaining'] <= (int) 10) ? 'limited' : 'available'),
            ];
        }
        usort($list, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);
        $departureTiers[(int) $d['departure_id']] = $list;
    }

    $title = ($template['name'] ?? 'Umrah Package') . ' | ' . ($GLOBALS['app']['business_name'] ?? 'GoGlobia');
    $description = $template['meta_description'] ?? '';
    $canonical = rtrim((string) ($GLOBALS['app']['site_url'] ?? root), '/') . '/umrah/packages/' . $slug;

    require_once views . 'includes/header.php';
    require_once views . 'modules/umrah/v2/detail.php';
    require_once views . 'includes/footer.php';
});

// ---- CUSTOMIZE / PERSONALIZE: GET /umrah/customize (wizard) -------------
$router->get('/umrah/customize', function () use ($SECURE, $db) {
    $template = $db->get('umrah_package_templates', '*', ['slug' => 'normal-umrah-14-day'])
        ?: $db->get('umrah_package_templates', '*', ['ORDER' => ['id' => 'ASC']]);
    if ($template) {
        $template['inclusions'] = json_decode((string) $template['inclusions'], true) ?: [];
    }
    // Facets to prefill the wizard.
    $departures = umrahV2PublishedDepartures($db);
    $cities = []; $months = [];
    foreach ($departures as $d) { $cities[$d['origin_city']] = true; if (!isset($months[$d['month_bucket']])) { $months[$d['month_bucket']] = $d['departure_date']; } }
    $cities = array_keys($cities); asort($months); $months = array_keys($months);
    $tiers = $db->select('umrah_tiers', ['code', 'public_label', 'name', 'room_sharing'], ['status' => 1, 'ORDER' => ['sort_order' => 'ASC']]) ?: [];
    $selectedDepartureId = isset($_GET['departure']) ? (int) $_GET['departure'] : 0;
    $submitted = $_SESSION['umrah_customize_done'] ?? null;
    unset($_SESSION['umrah_customize_done']);

    $title = 'Customize your Umrah | ' . ($GLOBALS['app']['business_name'] ?? 'GoGlobia');
    $description = 'Personalize your Umrah — choose your nights in Madinah and Makkah, extend your stay, add Ziyarah and request a tailored quote.';
    $robots = 'noindex, nofollow';

    require_once views . 'includes/header.php';
    require_once views . 'modules/umrah/v2/customize.php';
    require_once views . 'includes/footer.php';
});

// ---- CUSTOMIZE SUBMIT: POST /umrah/customize ---------------------------
$router->post('/umrah/customize', function () use ($SECURE, $db) {
    // CSRF (form post). CSRF::validateToken accepts field or X-CSRF-TOKEN header.
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!class_exists('CSRF') || !CSRF::validateToken($token)) {
        $_SESSION['umrah_customize_done'] = ['ok' => false, 'message' => 'Your session expired. Please try again.'];
        header('Location: ' . root . 'umrah/customize'); exit;
    }
    // Normalise checkbox arrays.
    $ziyarah = array_values(array_filter(array_map('trim', (array) ($_POST['ziyarah'] ?? []))));
    $addons  = array_values(array_filter(array_map('trim', (array) ($_POST['addons'] ?? []))));
    $in = [
        'user_id'        => $_SESSION['user_id'] ?? '',
        'template_id'    => $_POST['template_id'] ?? null,
        'departure_id'   => $_POST['departure_id'] ?? null,
        'origin_city'    => $_POST['origin_city'] ?? '',
        'preferred_month'=> $_POST['preferred_month'] ?? '',
        'preferred_date' => $_POST['preferred_date'] ?? '',
        'tier_code'      => $_POST['tier_code'] ?? '',
        'pax'            => $_POST['pax'] ?? 1,
        'madinah_nights' => ($_POST['madinah_nights'] ?? '') !== '' ? $_POST['madinah_nights'] : null,
        'makkah_nights'  => ($_POST['makkah_nights'] ?? '') !== '' ? $_POST['makkah_nights'] : null,
        'total_weeks'    => ($_POST['total_weeks'] ?? '') !== '' ? $_POST['total_weeks'] : null,
        'ziyarah'        => $ziyarah ?: null,
        'addons'         => $addons ?: null,
        'notes'          => $_POST['notes'] ?? '',
        'name'           => $_POST['name'] ?? '',
        'email'          => $_POST['email'] ?? '',
        'phone'          => $_POST['phone'] ?? '',
    ];
    $r = umrah_quote_request_create($db, $in);
    $_SESSION['umrah_customize_done'] = $r['ok']
        ? ['ok' => true, 'ref' => $r['request_ref']]
        : ['ok' => false, 'message' => $r['message'] ?? 'Could not submit'];
    header('Location: ' . root . 'umrah/customize'); exit;
});

// ---- BOOKING CONFIRMATION / VIEW: GET /umrah/booking/{ref} --------------
// GGU-XXXXXXXX reference (new). Legacy 16-hex booking route stays on the old file.
$router->get('/umrah/booking/(GGU-[A-Z0-9]+)', function ($ref) use ($SECURE, $db) {
    $ub = $db->get('umrah_bookings', '*', ['booking_ref' => $ref]);
    if (!$ub) { header('Location: ' . root . 'umrah'); exit; }

    // SECURITY (IDOR): only the booking owner, an admin, or the guest who just
    // created it (ref recorded in their session allow-list) may view it.
    $sessUid = (string) ($_SESSION['user_id'] ?? '');
    $isAdmin = (($_SESSION['user_role'] ?? '') === 'admin');
    $owner   = (string) ($ub['user_id'] ?? '');
    $guestAllow = in_array($ub['booking_ref'], (array) ($_SESSION['umrah_guest_bookings'] ?? []), true);
    $allowed = $isAdmin
        || ($owner !== '' && $sessUid !== '' && $owner === $sessUid)
        || ($owner === '' && $guestAllow);
    if (!$allowed) {
        if ($sessUid === '') { header('Location: ' . root . 'login?redirect=' . urlencode(root . 'umrah/booking/' . $ub['booking_ref'])); }
        else { header('Location: ' . root . 'umrah'); }
        exit;
    }

    $ub['snapshot'] = json_decode((string) $ub['snapshot'], true) ?: [];
    $installments = $db->select('umrah_installments', '*', ['umrah_booking_id' => $ub['id'], 'ORDER' => ['seq' => 'ASC']]) ?: [];

    // Phase B: pilgrim-details + document step on the confirmation page.
    $travellers = $db->select('umrah_booking_travellers', '*', ['umrah_booking_id' => $ub['id'], 'ORDER' => ['id' => 'ASC']]) ?: [];
    $docsByTraveller = [];
    $tIds = array_map(fn($t) => (int) $t['id'], $travellers);
    if ($tIds) {
        foreach (($db->select('umrah_documents', ['id', 'traveller_id', 'doc_type', 'verify_status'], ['traveller_id' => $tIds]) ?: []) as $dd) {
            $docsByTraveller[(int) $dd['traveller_id']][] = $dd;
        }
    }
    $countries = $db->select('countries', ['iso', 'nicename'], ['ORDER' => ['nicename' => 'ASC']]) ?: [];
    $umrahCsrf = class_exists('CSRF') ? CSRF::getToken() : ($_SESSION['csrf_token'] ?? '');

    $title = 'Booking ' . $ub['booking_ref'] . ' | ' . ($GLOBALS['app']['business_name'] ?? 'GoGlobia');
    $description = '';
    $robots = 'noindex, nofollow';

    require_once views . 'includes/header.php';
    require_once views . 'modules/umrah/v2/confirmation.php';
    require_once views . 'includes/footer.php';
});

// ---- AGENT GROUPS (Phase C) --------------------------------------------
// Agents only. Dashboard lists the agent's groups; builder manages one group.
$router->get('/umrah/groups', function () use ($SECURE, $db) {
    if (!(function_exists('umrah_is_agent') && umrah_is_agent())) {
        header('Location: ' . root . 'login?redirect=' . urlencode(root . 'umrah/groups')); exit;
    }
    $agent = (string) ($_SESSION['user_id'] ?? '');
    $groups = $db->select('umrah_groups', '*', ['agent_user_id' => $agent, 'ORDER' => ['id' => 'DESC']]) ?: [];
    $umrahCsrf = class_exists('CSRF') ? CSRF::getToken() : ($_SESSION['csrf_token'] ?? '');
    $title = 'My Umrah groups | ' . ($GLOBALS['app']['business_name'] ?? 'GoGlobia');
    $description = ''; $robots = 'noindex, nofollow';
    require_once views . 'includes/header.php';
    require_once views . 'modules/umrah/v2/groups.php';
    require_once views . 'includes/footer.php';
});

$router->get('/umrah/groups/([0-9]+)', function ($gid) use ($SECURE, $db) {
    if (!(function_exists('umrah_is_agent') && umrah_is_agent())) {
        header('Location: ' . root . 'login?redirect=' . urlencode(root . 'umrah/groups')); exit;
    }
    $group = function_exists('umrah_group_owned') ? umrah_group_owned($db, (int) $gid) : null;
    if (!$group) { header('Location: ' . root . 'umrah/groups'); exit; }
    $members = $db->select('umrah_group_members', '*', ['group_id' => (int) $gid, 'ORDER' => ['id' => 'ASC']]) ?: [];
    // Wallet balance for the affordability hint.
    $walletBalance = function_exists('agent_api_wallet_balance') ? agent_api_wallet_balance($db, (string) $group['agent_user_id']) : 0;
    $dep = $db->get('umrah_departures', ['origin_city', 'departure_date', 'return_date'], ['id' => (int) $group['departure_id']]);
    $countries = $db->select('countries', ['iso', 'nicename'], ['ORDER' => ['nicename' => 'ASC']]) ?: [];
    $umrahCsrf = class_exists('CSRF') ? CSRF::getToken() : ($_SESSION['csrf_token'] ?? '');
    $title = 'Group ' . $group['group_ref'] . ' | ' . ($GLOBALS['app']['business_name'] ?? 'GoGlobia');
    $description = ''; $robots = 'noindex, nofollow';
    require_once views . 'includes/header.php';
    require_once views . 'modules/umrah/v2/group-builder.php';
    require_once views . 'includes/footer.php';
});

// ---- MULTI-DEPARTURE CART PAGE: GET /umrah/cart (Phase B3) --------------
$router->get('/umrah/cart', function () use ($SECURE, $db) {
    $plans = $db->select('umrah_payment_plans', '*', ['active' => 1, 'ORDER' => ['deposit_percent' => 'DESC']]) ?: [];
    $umrahCsrf = class_exists('CSRF') ? CSRF::getToken() : ($_SESSION['csrf_token'] ?? '');
    $title = 'Your Umrah cart | ' . ($GLOBALS['app']['business_name'] ?? 'GoGlobia');
    $description = ''; $robots = 'noindex, nofollow';
    require_once views . 'includes/header.php';
    require_once views . 'modules/umrah/v2/cart.php';
    require_once views . 'includes/footer.php';
});

// ---- LEGACY REDIRECT: /umrah/detail/* → nearest package/landing ---------
// Replaces the fragile 6-part detail URL. If the package slug is recognised,
// redirect to the stable package page; otherwise to the landing.
$router->get('/umrah/detail/(.*)', function ($params) use ($SECURE, $db) {
    $parts = explode('/', trim((string) $params, '/'));
    $slug = $parts[0] ?? '';
    $tpl = $slug !== '' ? $db->get('umrah_package_templates', ['slug'], ['slug' => $slug]) : null;
    if ($tpl) {
        header('Location: ' . root . 'umrah/packages/' . $tpl['slug'], true, 301);
    } else {
        header('Location: ' . root . 'umrah', true, 301);
    }
    exit;
});

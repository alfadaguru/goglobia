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
            $out[] = [
                'departure_id'      => (int) $d['id'],
                'departure_tier_id' => (int) $best['id'],
                'code'              => $d['code'],
                'origin_city'       => $d['origin_city'] ?: 'Kano',
                'departure_date'    => $d['departure_date'],
                'return_date'       => $d['return_date'],
                'month_bucket'      => $d['month_bucket'] ?: date('F Y', strtotime($d['departure_date'])),
                'tier_code'         => $tier['code'] ?? 'standard',
                'tier_label'        => $tier['public_label'] ?? 'Standard Economy',
                'room_sharing'      => $tier['room_sharing'] ?? '4-5 sharing',
                'unit_price'        => $bestPriced['unit'],
                'currency'          => $bestPriced['currency'],
                'promo'             => $bestPriced['promo'],
                'availability'      => $avail,
                'tier_count'        => count($tiers),
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

    // All active tiers per departure (for the tier selector on the detail page).
    // Priced via umrah_price_resolve (agent-aware: an agent sees their B2B net).
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
            $list[] = [
                'departure_tier_id' => (int) $dt['id'],
                'tier_code'   => $tier['code'] ?? 'standard',
                'tier_label'  => $tier['public_label'] ?: ($tier['name'] ?? 'Standard'),
                'room_sharing'=> $tier['room_sharing'] ?? '',
                'sort_order'  => (int) ($tier['sort_order'] ?? 0),
                'unit_price'  => (float) $priced['unit'],
                'regular'     => $priced['promo']['regular'] ?? null,
                'currency'    => $priced['currency'],
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

    $title = 'Booking ' . $ub['booking_ref'] . ' | ' . ($GLOBALS['app']['business_name'] ?? 'GoGlobia');
    $description = '';
    $robots = 'noindex, nofollow';

    require_once views . 'includes/header.php';
    require_once views . 'modules/umrah/v2/confirmation.php';
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

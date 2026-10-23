<?php
// ============================================================================
// FILE: app/routes/seo/landingRoutes.php
// SEO LANDING PAGES (SEO-005) — crawlable, SSR, evergreen route/city pages that
// feed the Nigerian-travel "intent graph". Each page has real content, H1,
// breadcrumbs, metadata and schema in the INITIAL HTML; the live-fare widget is
// a normal client call layered on top (never replacing the crawlable content).
//
// These single-segment patterns do NOT collide with the dated search routes
// (/flights/{from}/{to}/{type}/... needs a trip-type 3rd segment; this is one
// segment). Registered BEFORE the catch-all listing routes in _routes.php.
//
// Honest-content rule: we show evergreen facts (airports, route context, tips)
// plus a LIVE search widget. We never print a fake/guaranteed fare — the
// "from" price only appears if real inventory returns one.
// ============================================================================
@$SECURE or die('Access Denied!');

// ---------------------------------------------------------------------------
// FLIGHT ROUTE LANDING:  /flights/{from}-to-{to}   e.g. /flights/lagos-to-abuja
// ---------------------------------------------------------------------------
$router->get('/flights/([a-z][a-z-]*-to-[a-z][a-z-]*)', function ($slug) use ($SECURE, $db) {
    // Parse "lagos-to-abuja" → from="lagos", to="abuja".
    if (!preg_match('/^([a-z-]+)-to-([a-z-]+)$/', $slug, $m)) {
        header('Location: ' . root . 'flights'); exit;
    }
    $fromSlug = $m[1];
    $toSlug   = $m[2];

    // Resolve each slug to a REAL airport (by city name or IATA code). A route
    // whose endpoints aren't real airports is not a landing page — send to search.
    $resolve = function (string $s) use ($db) {
        $name = ucwords(str_replace('-', ' ', $s));
        // Prefer an exact city match, then a code match; pick the busiest/first.
        $row = $db->get('flights_airports', ['code', 'city', 'country', 'airport'],
            ['AND' => ['status' => '1', 'OR' => ['city' => $name, 'code' => strtoupper($s)]]]);
        return $row ?: null;
    };
    $from = $resolve($fromSlug);
    $to   = $resolve($toSlug);
    if (!$from || !$to || strtoupper($from['code']) === strtoupper($to['code'])) {
        header('Location: ' . root . 'flights'); exit;
    }

    // Data the view needs.
    $GLOBALS['seo_route'] = [
        'kind'      => 'flight-route',
        'from'      => $from,
        'to'        => $to,
        'fromSlug'  => $fromSlug,
        'toSlug'    => $toSlug,
        'fromCity'  => $from['city'] ?: ucwords(str_replace('-', ' ', $fromSlug)),
        'toCity'    => $to['city'] ?: ucwords(str_replace('-', ' ', $toSlug)),
        'reverse'   => $toSlug . '-to-' . $fromSlug,
    ];

    $r = $GLOBALS['seo_route'];
    $title = 'Flights from ' . $r['fromCity'] . ' to ' . $r['toCity']
        . ' (' . strtoupper($from['code']) . '–' . strtoupper($to['code']) . ') — Compare Live Options';
    $description = 'Search and compare live ' . $r['fromCity'] . ' to ' . $r['toCity']
        . ' flights with GoGlobia. See airlines, travel tips, airport info (' . strtoupper($from['code']) . ' → '
        . strtoupper($to['code']) . ') and book securely with 24/7 support.';
    $header = true; $footer = true;

    require_once views . 'includes/header.php';
    require_once views . 'modules/seo/flight-route.php';
    require_once views . 'includes/footer.php';
});

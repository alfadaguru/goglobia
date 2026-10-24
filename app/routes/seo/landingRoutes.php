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

// Curated landing-page registries (pure data, no side effects).
require_once dirname(__DIR__, 2) . '/lib/seo_registry.php';

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
    //
    // Several city names map to MANY airports in the DB (e.g. "London" has YXU in
    // Canada and LOZ in the US alongside LHR; "Istanbul" has ISL/SAW alongside
    // IST; "Doha" has DIA/XOZ alongside DOH). A plain first-row match can pick the
    // wrong one (London, Canada!), putting a wrong airport on the page. For these
    // ambiguous cities we pin the canonical primary hub; everything else falls back
    // to the city/code match as before.
    $preferredHub = [
        'london'    => 'LHR',
        'istanbul'  => 'IST',
        'doha'      => 'DOH',
        'dubai'     => 'DXB',
        'cairo'     => 'CAI',
        'jeddah'    => 'JED',
        'madinah'   => 'MED',
        'abu-dhabi' => 'AUH',
    ];
    $resolve = function (string $s) use ($db, $preferredHub) {
        $slug = strtolower($s);
        if (isset($preferredHub[$slug])) {
            $row = $db->get('flights_airports', ['code', 'city', 'country', 'airport'],
                ['AND' => ['status' => '1', 'code' => $preferredHub[$slug]]]);
            if ($row) { return $row; }
        }
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

// ---------------------------------------------------------------------------
// HOTEL CITY LANDING:  /hotels/{city}   e.g. /hotels/abuja  /hotels/dubai
// Evergreen destination content + a LIVE stays search (real supplier inventory
// on click). We never claim a fake number of hotels — most stays inventory is
// fetched live from suppliers, so the page is a guide + a search front door.
// Limited to a CURATED city list so every page has genuine, specific content.
// ---------------------------------------------------------------------------
$router->get('/hotels/([a-z][a-z-]*)', function ($slug) use ($SECURE, $db) {
    // Curated, hand-written cities only — a slug not in this map is not a landing
    // page (sent to stays search), so we never ship thin auto-generated pages.
    $cities = seoHotelCities();
    $slug = strtolower($slug);
    if (!isset($cities[$slug])) {
        header('Location: ' . root . 'stays'); exit;
    }
    $GLOBALS['seo_city'] = array_merge(['slug' => $slug], $cities[$slug]);
    $c = $GLOBALS['seo_city'];

    $title = 'Hotels in ' . $c['name'] . ' — Search Stays & Compare Options';
    $description = 'Find and book hotels in ' . $c['name'] . ($c['country'] ? ', ' . $c['country'] : '')
        . ' with GoGlobia. Compare real rates, see neighbourhoods and amenities, and book securely with 24/7 support.';
    $header = true; $footer = true;

    require_once views . 'includes/header.php';
    require_once views . 'modules/seo/hotel-city.php';
    require_once views . 'includes/footer.php';
});

// ---------------------------------------------------------------------------
// TOUR DESTINATION LANDING:  /tours/{destination}   e.g. /tours/dubai
// Evergreen "things to do / tours in {destination}" guide + a LIVE tours search
// (real, dynamic tour inventory on click). No fake prices or tour counts — it is
// a guide + a search front door, mirroring the hotel-city pages. Curated list
// only. Registered BEFORE the greedy /tours/(.*) listing route (seo file loads
// first in _routes.php), and the single-segment regex plus the listing route's
// own "needs >=5 segments" guard mean there is no collision with live search.
// ---------------------------------------------------------------------------
$router->get('/tours/([a-z][a-z-]*)', function ($slug) use ($SECURE, $db) {
    $dests = seoTourDestinations();
    $slug = strtolower($slug);
    if (!isset($dests[$slug])) {
        header('Location: ' . root . 'tours'); exit;
    }
    $GLOBALS['seo_tour'] = array_merge(['slug' => $slug], $dests[$slug]);
    $t = $GLOBALS['seo_tour'];

    $title = 'Tours in ' . $t['name'] . ' — Things to Do & Book Experiences';
    $description = 'Discover the best tours and experiences in ' . $t['name'] . ($t['country'] ? ', ' . $t['country'] : '')
        . ' with GoGlobia. Browse popular activities, see travel tips, and book securely with 24/7 support.';
    $header = true; $footer = true;

    require_once views . 'includes/header.php';
    require_once views . 'modules/seo/tour-destination.php';
    require_once views . 'includes/footer.php';
});

// ---------------------------------------------------------------------------
// UMRAH ORIGIN LANDING:  /umrah/from-{city}   e.g. /umrah/from-kano
// Evergreen Umrah-from-city guide + a live link into the real Umrah v2 search
// filtered to that departure city. Only real NG departure cities get a page.
// Registered before the /umrah/(.*) catch-all (seo file loads first), so this
// matches first.
// ---------------------------------------------------------------------------
$router->get('/umrah/from-([a-z][a-z-]*)', function ($citySlug) use ($SECURE, $db) {
    $cities = seoUmrahOriginCities();
    $citySlug = strtolower($citySlug);
    if (!isset($cities[$citySlug])) {
        header('Location: ' . root . 'umrah'); exit;
    }
    $GLOBALS['seo_umrah'] = array_merge(['slug' => $citySlug], $cities[$citySlug]);
    $u = $GLOBALS['seo_umrah'];

    $title = 'Umrah Packages from ' . $u['name'] . ' — Dates, Inclusions & Prices';
    $description = 'Book Umrah from ' . $u['name'] . ' with GoGlobia — compare departure dates, comfort tiers, '
        . 'inclusions and transparent per-pilgrim pricing. Flights, visa, hotels in Makkah & Madinah, and transport handled.';
    $header = true; $footer = true;

    require_once views . 'includes/header.php';
    require_once views . 'modules/seo/umrah-origin.php';
    require_once views . 'includes/footer.php';
});

// ---------------------------------------------------------------------------
// DISCOVERY DIRECTORY:  /travel-directory
// A single crawlable hub that internally links to every landing page (routes,
// hotel cities, Umrah origins) with clean hrefs + breadcrumb — so Google can
// discover the whole moat through internal links, not only the sitemap.
// ---------------------------------------------------------------------------
$router->get('/travel-directory', function () use ($SECURE, $db) {
    $title = 'Travel Directory — Flight Routes, Hotels, Tours & Umrah';
    $description = 'Browse popular flight routes, hotel destinations, tours and Umrah departures from Nigeria. Find your trip and book securely with GoGlobia.';
    $header = true; $footer = true;
    require_once views . 'includes/header.php';
    require_once views . 'modules/seo/directory.php';
    require_once views . 'includes/footer.php';
});

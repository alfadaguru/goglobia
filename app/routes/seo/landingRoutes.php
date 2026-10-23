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

// Curated Umrah departure-city registry — only real NG origins we serve.
if (!function_exists('seoUmrahOriginCities')) {
    function seoUmrahOriginCities(): array
    {
        return [
            'kano' => [
                'name' => 'Kano', 'airport' => 'Mallam Aminu Kano International Airport (KAN)',
                'intro' => 'Kano is a major departure point for Umrah in northern Nigeria, with direct and connecting options to Jeddah and Madinah.',
                'note' => 'Most Kano departures route through Jeddah (JED) or Madinah (MED). Our packages bundle flights, visa, hotels and transport so you travel with peace of mind.',
            ],
            'abuja' => [
                'name' => 'Abuja', 'airport' => 'Nnamdi Azikiwe International Airport (ABV)',
                'intro' => 'Abuja, Nigeria’s capital, offers convenient Umrah departures for travellers across central Nigeria.',
                'note' => 'Abuja departures typically connect to Jeddah or Madinah. Compare comfort tiers and dates below, with everything handled end to end.',
            ],
            'lagos' => [
                'name' => 'Lagos', 'airport' => 'Murtala Muhammed International Airport (LOS)',
                'intro' => 'Lagos is Nigeria’s busiest gateway, with the widest choice of Umrah flight connections to Saudi Arabia.',
                'note' => 'Lagos offers frequent connections via Jeddah or Madinah. Our Umrah packages include flights, visa, accommodation near the Haram, and ground transport.',
            ],
        ];
    }
}

// Curated hotel-city content registry. Each entry is genuinely written (not
// auto-generated), so a page only exists where we have real, specific copy.
if (!function_exists('seoHotelCities')) {
    function seoHotelCities(): array
    {
        return [
            'lagos' => [
                'name' => 'Lagos', 'country' => 'Nigeria', 'nat' => 'NG',
                'intro' => 'Lagos is Nigeria’s largest city and commercial hub — a sprawling, energetic destination spanning the mainland and islands.',
                'areas' => ['Victoria Island — business hotels and upscale dining', 'Ikeja — convenient for Murtala Muhammed Airport (LOS)', 'Lekki — modern apartments, beaches and nightlife', 'Ikoyi — quiet, premium neighbourhoods'],
                'airport' => 'Murtala Muhammed International Airport (LOS)',
                'tip' => 'Traffic can be heavy — pick a hotel close to where you’ll spend most of your time, and allow extra travel time to the airport.',
            ],
            'abuja' => [
                'name' => 'Abuja', 'country' => 'Nigeria', 'nat' => 'NG',
                'intro' => 'Abuja is Nigeria’s capital — a planned, calmer city known for government institutions, business travel and good road access.',
                'areas' => ['Central Business District — close to offices and conference venues', 'Maitama & Asokoro — upscale, quiet districts', 'Wuse — shopping, restaurants and mid-range stays', 'Garki — budget-friendly and central'],
                'airport' => 'Nnamdi Azikiwe International Airport (ABV)',
                'tip' => 'Abuja is well-suited to business trips — choose the CBD or Maitama to stay near meetings and the main hotels.',
            ],
            'kano' => [
                'name' => 'Kano', 'country' => 'Nigeria', 'nat' => 'NG',
                'intro' => 'Kano is northern Nigeria’s largest city, a historic trade centre and a common departure point for Umrah travellers.',
                'areas' => ['City centre — close to markets and historic sites', 'Nassarawa GRA — quieter residential area', 'Near Mallam Aminu Kano Airport (KAN) — convenient for early flights'],
                'airport' => 'Mallam Aminu Kano International Airport (KAN)',
                'tip' => 'If you’re travelling for Umrah, book a stay near the airport for early-morning departures and check our Umrah packages from Kano.',
            ],
            'port-harcourt' => [
                'name' => 'Port Harcourt', 'country' => 'Nigeria', 'nat' => 'NG',
                'intro' => 'Port Harcourt is the heart of Nigeria’s oil and gas industry — a busy southern city with strong business travel demand.',
                'areas' => ['GRA (Old & New) — safe, central and well-serviced', 'Trans-Amadi — industrial area, close to many offices', 'Airport road — convenient for flights (PHC)'],
                'airport' => 'Port Harcourt International Airport (PHC)',
                'tip' => 'Business travellers usually base themselves in the GRA for easy access to offices and dining.',
            ],
            'dubai' => [
                'name' => 'Dubai', 'country' => 'United Arab Emirates', 'nat' => 'NG',
                'intro' => 'Dubai is a top international destination for Nigerian travellers — modern, safe, and packed with shopping, dining and attractions.',
                'areas' => ['Downtown Dubai — Burj Khalifa, Dubai Mall and luxury hotels', 'Dubai Marina — waterfront dining and nightlife', 'Deira — traditional souks and value stays', 'Near DXB Airport — convenient for connections'],
                'airport' => 'Dubai International Airport (DXB)',
                'tip' => 'Check visa requirements before you travel, and book early for peak shopping-festival seasons when rates rise.',
            ],
            'london' => [
                'name' => 'London', 'country' => 'United Kingdom', 'nat' => 'NG',
                'intro' => 'London is a classic destination for business, study and leisure — rich in history, culture and transport links.',
                'areas' => ['West End — theatres, shopping and central hotels', 'Kensington & Chelsea — upscale and elegant', 'City of London — business district', 'Near Heathrow (LHR) — convenient for arrivals'],
                'airport' => 'Heathrow Airport (LHR)',
                'tip' => 'Stay near an Underground station — London’s Tube makes getting around fast and cheap.',
            ],
            'jeddah' => [
                'name' => 'Jeddah', 'country' => 'Saudi Arabia', 'nat' => 'NG',
                'intro' => 'Jeddah is the main gateway for Umrah and Hajj travellers, and a coastal Saudi city with a historic old town.',
                'areas' => ['Al-Balad — historic old town', 'Corniche — seafront hotels and dining', 'Near King Abdulaziz Airport (JED) — convenient for pilgrimage arrivals'],
                'airport' => 'King Abdulaziz International Airport (JED)',
                'tip' => 'Many travellers transit Jeddah for Umrah — see our Umrah packages for flights, visa, hotels and transport handled together.',
            ],
            'istanbul' => [
                'name' => 'Istanbul', 'country' => 'Turkey', 'nat' => 'NG',
                'intro' => 'Istanbul bridges Europe and Asia — a vibrant city of history, markets and food, and an increasingly popular stop for Nigerian travellers.',
                'areas' => ['Sultanahmet — historic sites (Blue Mosque, Hagia Sophia)', 'Taksim & Beyoğlu — nightlife and shopping', 'Near IST Airport — convenient for connections'],
                'airport' => 'Istanbul Airport (IST)',
                'tip' => 'Stay in Sultanahmet if sightseeing is your priority, or Taksim for dining and nightlife.',
            ],
        ];
    }
}

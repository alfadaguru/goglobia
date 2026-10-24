<?php
// ============================================================================
// FILE: app/lib/seo_registry.php
// SEO landing-page registries (SEO-005). Pure data, NO side effects — safe to
// include from anywhere (route layer, sitemap generator, discovery directory).
//
// SOURCE OF TRUTH: the DB table `seo_landing_pages` (admin-managed). The public
// functions below read the DB FIRST (when $GLOBALS['db'] + rows exist) and fall
// back to the hardcoded *Defaults() sets otherwise. The defaults are what seeds
// the table on first deploy, so the shipped pages always exist even before any
// admin edit. Admin edits are never overwritten.
// ============================================================================

if (!function_exists('seoFlightRouteData')) {
    /** DB-first; falls back to the hardcoded defaults. @return array<string,array> */
    function seoFlightRouteData(): array
    {
        $db = $GLOBALS['db'] ?? null;
        if ($db && function_exists('seoLandingFromDb')) {
            $rows = seoLandingFromDb($db, 'flight_route');
            if (!empty($rows)) { return $rows; }
        }
        return function_exists('seoFlightRouteDataDefaults') ? seoFlightRouteDataDefaults() : [];
    }
}
if (!function_exists('seoHotelCities')) {
    /** DB-first; falls back to the hardcoded defaults. @return array<string,array> */
    function seoHotelCities(): array
    {
        $db = $GLOBALS['db'] ?? null;
        if ($db && function_exists('seoLandingFromDb')) {
            $rows = seoLandingFromDb($db, 'hotel_city');
            if (!empty($rows)) { return $rows; }
        }
        return function_exists('seoHotelCitiesDefaults') ? seoHotelCitiesDefaults() : [];
    }
}
if (!function_exists('seoUmrahOriginCities')) {
    /** DB-first; falls back to the hardcoded defaults. @return array<string,array> */
    function seoUmrahOriginCities(): array
    {
        $db = $GLOBALS['db'] ?? null;
        if ($db && function_exists('seoLandingFromDb')) {
            $rows = seoLandingFromDb($db, 'umrah_origin');
            if (!empty($rows)) { return $rows; }
        }
        return function_exists('seoUmrahOriginCitiesDefaults') ? seoUmrahOriginCitiesDefaults() : [];
    }
}
if (!function_exists('seoTourDestinations')) {
    /** DB-first; falls back to the hardcoded defaults. @return array<string,array> */
    function seoTourDestinations(): array
    {
        $db = $GLOBALS['db'] ?? null;
        if ($db && function_exists('seoLandingFromDb')) {
            $rows = seoLandingFromDb($db, 'tour_destination');
            if (!empty($rows)) { return $rows; }
        }
        return function_exists('seoTourDestinationsDefaults') ? seoTourDestinationsDefaults() : [];
    }
}

if (!function_exists('seoFlightRouteDataDefaults')) {
    /**
     * Per-route content registry — each entry carries GENUINELY DISTINCT, factual
     * copy (approx flight time, airlines known to serve the route, and a route-
     * specific note) so every landing page is unique and useful, not a template
     * clone. Airlines named are real carriers present in the system / that fly the
     * route; durations are approximate and framed as such. The page view falls
     * back to generic content for any route without an entry here.
     *
     * @return array<string,array{duration:string,airlines:string[],note:string}>
     */
    function seoFlightRouteDataDefaults(): array
    {
        // Common airline sets, reused where accurate.
        $ngDom   = ['Air Peace', 'Arik Air', 'Ibom Air', 'United Nigeria', 'Green Africa', 'Dana Air'];
        $gulf    = ['Emirates', 'Qatar Airways', 'Etihad Airways'];
        $toUK    = ['British Airways', 'Virgin Atlantic', 'Qatar Airways', 'Air France'];
        $toKSA   = ['Saudia', 'EgyptAir', 'Qatar Airways', 'Ethiopian Airlines'];
        $toTR    = ['Turkish Airlines', 'EgyptAir'];
        $africa  = ['Ethiopian Airlines', 'Kenya Airways', 'Air Peace', 'EgyptAir'];

        return [
            // ---- DOMESTIC (both directions) ----
            'lagos-to-abuja'        => ['duration' => 'about 1h 15m', 'airlines' => $ngDom, 'note' => 'The busiest domestic route in Nigeria, with frequent daily departures — ideal for business day-trips between the commercial and administrative capitals.'],
            'abuja-to-lagos'        => ['duration' => 'about 1h 15m', 'airlines' => $ngDom, 'note' => 'Frequent daily flights connect the capital back to Lagos; early-morning and evening slots fill up fastest around weekends.'],
            'lagos-to-kano'         => ['duration' => 'about 1h 30m', 'airlines' => ['Air Peace', 'Arik Air', 'Max Air', 'Azman Air'], 'note' => 'Links southern commercial Lagos with the north’s largest city; a common first leg for Kano-based Umrah travellers heading via Lagos.'],
            'kano-to-lagos'         => ['duration' => 'about 1h 30m', 'airlines' => ['Air Peace', 'Arik Air', 'Max Air', 'Azman Air'], 'note' => 'A key north–south link; book early around festive and Hajj/Umrah seasons when demand from Kano peaks.'],
            'lagos-to-port-harcourt'=> ['duration' => 'about 1h', 'airlines' => ['Air Peace', 'Ibom Air', 'United Nigeria'], 'note' => 'A short, high-frequency hop serving the oil-and-gas corridor — popular with business travellers to Rivers State.'],
            'port-harcourt-to-lagos'=> ['duration' => 'about 1h', 'airlines' => ['Air Peace', 'Ibom Air', 'United Nigeria'], 'note' => 'Regular daily departures back to Lagos; the GRA and Trans-Amadi business districts drive strong weekday demand.'],
            'abuja-to-kano'         => ['duration' => 'about 55m', 'airlines' => ['Air Peace', 'Max Air', 'Azman Air'], 'note' => 'A quick northern hop between the capital and Kano — one of the shortest scheduled sectors in the country.'],
            'kano-to-abuja'         => ['duration' => 'about 55m', 'airlines' => ['Air Peace', 'Max Air', 'Azman Air'], 'note' => 'Short and frequent; convenient for connecting onward from Abuja to the rest of Nigeria.'],
            'abuja-to-port-harcourt'=> ['duration' => 'about 1h 10m', 'airlines' => ['Air Peace', 'Ibom Air', 'United Nigeria'], 'note' => 'Connects the capital with the south-south business hub; useful for government-and-energy sector travel.'],
            'port-harcourt-to-abuja'=> ['duration' => 'about 1h 10m', 'airlines' => ['Air Peace', 'Ibom Air', 'United Nigeria'], 'note' => 'Daily services link Rivers State back to the capital for meetings and connections.'],
            'lagos-to-enugu'        => ['duration' => 'about 1h', 'airlines' => ['Air Peace', 'United Nigeria', 'Ibom Air'], 'note' => 'The main gateway to the south-east; demand spikes sharply around the December festive period.'],
            'abuja-to-enugu'        => ['duration' => 'about 50m', 'airlines' => ['Air Peace', 'United Nigeria'], 'note' => 'A short link from the capital into the south-east, popular for family and holiday travel.'],
            'lagos-to-kaduna'       => ['duration' => 'about 1h 25m', 'airlines' => ['Air Peace', 'United Nigeria'], 'note' => 'Serves northern Kaduna State from Lagos; a practical alternative to long-distance road travel.'],

            // ---- INTERNATIONAL (NG hubs → top destinations) ----
            'lagos-to-dubai'   => ['duration' => 'about 8h direct', 'airlines' => $gulf, 'note' => 'A top international route for Nigerian travellers — shopping, business and leisure. Direct and one-stop options are available; check UAE visa requirements before booking.'],
            'abuja-to-dubai'   => ['duration' => 'about 8–11h (often one stop)', 'airlines' => $gulf, 'note' => 'Usually routed with one connection via a Gulf hub; compare total journey time, not just price.'],
            'lagos-to-london'  => ['duration' => 'about 6h 30m direct', 'airlines' => $toUK, 'note' => 'One of the most-flown long-haul routes from Nigeria for business, study and family visits. Direct services run to London Heathrow.'],
            'abuja-to-london'  => ['duration' => 'about 7h (direct or one stop)', 'airlines' => $toUK, 'note' => 'Direct and connecting options serve Heathrow; book early for the summer and December peaks.'],
            'lagos-to-jeddah'  => ['duration' => 'about 7h (often one stop)', 'airlines' => $toKSA, 'note' => 'A principal gateway for Umrah and Hajj from Lagos. For a fully handled trip, see our Umrah packages which bundle flights, visa, hotels and transport.'],
            'kano-to-jeddah'   => ['duration' => 'about 6–9h (direct in season / one stop)', 'airlines' => ['Max Air', 'Saudia', 'FlyNas'], 'note' => 'Kano is a major northern departure point for Umrah; seasonal direct services run, otherwise connect via a hub. See Umrah from Kano.'],
            'abuja-to-jeddah'  => ['duration' => 'about 7–9h (usually one stop)', 'airlines' => $toKSA, 'note' => 'A convenient pilgrimage departure from the capital; most services connect through a regional hub.'],
            'abuja-to-istanbul'=> ['duration' => 'about 8h (often one stop)', 'airlines' => $toTR, 'note' => 'Turkish Airlines and connecting carriers link Abuja to Istanbul — a growing destination for trade, tourism and onward Europe connections.'],
            'lagos-to-istanbul'=> ['duration' => 'about 7h direct', 'airlines' => $toTR, 'note' => 'Direct services connect Lagos to Istanbul, a popular hub for shopping and onward travel into Europe and Asia.'],
            'lagos-to-accra'   => ['duration' => 'about 55m', 'airlines' => $africa, 'note' => 'A short, frequent regional hop to Ghana — among the busiest West African international routes.'],
            'lagos-to-cairo'   => ['duration' => 'about 5h 30m', 'airlines' => ['EgyptAir', 'Ethiopian Airlines'], 'note' => 'Connects Lagos with Cairo for tourism, trade and onward Middle East links.'],
            'lagos-to-johannesburg' => ['duration' => 'about 6h direct', 'airlines' => ['Air Peace', 'South African Airways', 'Ethiopian Airlines'], 'note' => 'Links Lagos with South Africa’s commercial hub; direct and one-stop options serve business and leisure travellers.'],
            'lagos-to-nairobi' => ['duration' => 'about 5h 30m', 'airlines' => ['Kenya Airways', 'Ethiopian Airlines'], 'note' => 'Connects Lagos with Nairobi, a key East African hub for business and safari tourism.'],

            // ---- MORE DOMESTIC (south-east/south-south returns + new hubs) ----
            'enugu-to-lagos'        => ['duration' => 'about 1h', 'airlines' => ['Air Peace', 'United Nigeria', 'Ibom Air'], 'note' => 'The return leg from the south-east to Lagos; demand peaks sharply after the December festive period.'],
            'enugu-to-abuja'        => ['duration' => 'about 50m', 'airlines' => ['Air Peace', 'United Nigeria'], 'note' => 'A short hop from the south-east to the capital for business, government and family travel.'],
            'kaduna-to-lagos'       => ['duration' => 'about 1h 25m', 'airlines' => ['Air Peace', 'United Nigeria'], 'note' => 'Links northern Kaduna State back to Lagos; a practical alternative to long road journeys.'],
            'lagos-to-benin'        => ['duration' => 'about 45m', 'airlines' => ['Air Peace', 'United Nigeria'], 'note' => 'A quick hop to Benin City in Edo State — one of the shorter domestic sectors from Lagos.'],
            'lagos-to-asaba'        => ['duration' => 'about 1h', 'airlines' => ['Air Peace', 'United Nigeria', 'Ibom Air'], 'note' => 'Serves Asaba and the Delta region; a convenient gateway to the south-south by air rather than road.'],
            'lagos-to-calabar'      => ['duration' => 'about 1h 15m', 'airlines' => ['Air Peace', 'Ibom Air', 'United Nigeria'], 'note' => 'Connects Lagos with Calabar, a popular destination around the Cross River Carnival in December.'],
            'lagos-to-uyo'          => ['duration' => 'about 1h', 'airlines' => ['Ibom Air', 'Air Peace', 'United Nigeria'], 'note' => 'Serves Akwa Ibom State; Ibom Air, the state carrier, operates frequent services on this route.'],
            'abuja-to-maiduguri'    => ['duration' => 'about 1h 30m', 'airlines' => ['Air Peace', 'Max Air'], 'note' => 'Links the capital with Borno State in the north-east; book ahead as frequency is lighter than the main trunk routes.'],
            'abuja-to-sokoto'       => ['duration' => 'about 1h 20m', 'airlines' => ['Air Peace', 'Max Air'], 'note' => 'Connects the capital with the north-west; demand rises around the Umrah and Hajj seasons.'],
            'lagos-to-ilorin'       => ['duration' => 'about 55m', 'airlines' => ['Air Peace', 'United Nigeria'], 'note' => 'A short link to Kwara State in north-central Nigeria, useful for business and university travel.'],

            // ---- INTERNATIONAL RETURN LEGS (rich content both ways) ----
            'dubai-to-lagos'   => ['duration' => 'about 8h direct', 'airlines' => $gulf, 'note' => 'The return leg from the UAE to Lagos; direct and one-stop options are available — compare total journey time, not just price.'],
            'london-to-lagos'  => ['duration' => 'about 6h 30m direct', 'airlines' => $toUK, 'note' => 'A heavily flown long-haul return from the UK to Lagos; summer and December are the busiest periods, so book early.'],
            'jeddah-to-lagos'  => ['duration' => 'about 7h (often one stop)', 'airlines' => $toKSA, 'note' => 'The return from Saudi Arabia to Lagos, commonly flown after Umrah or Hajj; most services connect through a hub.'],
            'istanbul-to-lagos'=> ['duration' => 'about 7h direct', 'airlines' => $toTR, 'note' => 'Direct services connect Istanbul back to Lagos; a popular hub for travellers returning from Europe and Asia.'],
            'accra-to-lagos'   => ['duration' => 'about 55m', 'airlines' => $africa, 'note' => 'The return hop from Ghana — among the busiest and most frequent West African international sectors.'],

            // ---- NEW INTERNATIONAL (matching the hotel-city destinations) ----
            'lagos-to-abu-dhabi'   => ['duration' => 'about 8–11h (often one stop)', 'airlines' => ['Etihad Airways', 'Qatar Airways', 'Emirates'], 'note' => 'Connects Lagos with the UAE capital, usually via a Gulf hub; check UAE visa requirements before booking.'],
            'lagos-to-doha'        => ['duration' => 'about 7h 30m direct', 'airlines' => ['Qatar Airways'], 'note' => 'Qatar Airways links Lagos to Doha, a major stopover hub for onward connections across the Gulf, Asia and beyond.'],
            'abuja-to-doha'        => ['duration' => 'about 8h (direct or one stop)', 'airlines' => ['Qatar Airways'], 'note' => 'Connects the capital with Doha for business, transit and onward travel; compare direct versus one-stop timings.'],
            'lagos-to-kuala-lumpur'=> ['duration' => 'about 15–18h (one or two stops)', 'airlines' => ['Qatar Airways', 'Emirates', 'Etihad Airways'], 'note' => 'A long-haul route to Malaysia, routed via a Gulf hub; popular for trade, study and halal-friendly travel.'],
            'abuja-to-cairo'       => ['duration' => 'about 6h (often one stop)', 'airlines' => ['EgyptAir', 'Ethiopian Airlines'], 'note' => 'Connects the capital with Cairo for tourism, trade and onward Middle East links.'],
            'abuja-to-accra'       => ['duration' => 'about 1h 30m (direct or one stop)', 'airlines' => $africa, 'note' => 'Links Abuja with Ghana’s capital; a short regional route served directly and via connections.'],
            'kano-to-madinah'      => ['duration' => 'about 6–9h (direct in season / one stop)', 'airlines' => ['Max Air', 'Saudia', 'FlyNas'], 'note' => 'Kano is a major northern pilgrimage gateway; seasonal direct services to Madinah run, otherwise connect via a hub. See Umrah from Kano.'],
        ];
    }
}

if (!function_exists('seoFlightRoutes')) {
    /** @return string[] curated {from}-to-{to} slugs (keys of the content registry). */
    function seoFlightRoutes(): array
    {
        return array_keys(seoFlightRouteData());
    }
}

if (!function_exists('seoHotelCitiesDefaults')) {
    /** @return array<string,array> curated hotel-city content registry. */
    function seoHotelCitiesDefaults(): array
    {
        return [
            'lagos' => [
                'name' => 'Lagos', 'country' => 'Nigeria', 'nat' => 'NG',
                'intro' => 'Lagos is Nigeria’s largest city and commercial hub — a sprawling, energetic destination spanning the mainland and islands.',
                'areas' => ['Victoria Island — business hotels and upscale dining', 'Ikeja — convenient for Murtala Muhammed Airport (LOS)', 'Lekki — modern apartments, beaches and nightlife', 'Ikoyi — quiet, premium neighbourhoods'],
                'airport' => 'Murtala Muhammed International Airport (LOS)',
                'tip' => 'Traffic can be heavy — pick a hotel close to where you’ll spend most of your time, and allow extra travel time to the airport.',
                'currency' => 'Nigerian Naira (NGN)', 'language' => 'English',
                'best_time' => 'November to March, when the weather is drier and events are plentiful; the rainy season (roughly May–September) brings heavier downpours.',
                'getting_around' => 'Ride-hailing apps and taxis are the easiest way to get around; allow generous time for traffic, especially between the mainland and the islands.',
                'highlights' => 'Lekki Conservation Centre, Nike Art Gallery, the beaches of Lekki and Victoria Island, and a lively nightlife and food scene.',
            ],
            'abuja' => [
                'name' => 'Abuja', 'country' => 'Nigeria', 'nat' => 'NG',
                'intro' => 'Abuja is Nigeria’s capital — a planned, calmer city known for government institutions, business travel and good road access.',
                'areas' => ['Central Business District — close to offices and conference venues', 'Maitama & Asokoro — upscale, quiet districts', 'Wuse — shopping, restaurants and mid-range stays', 'Garki — budget-friendly and central'],
                'airport' => 'Nnamdi Azikiwe International Airport (ABV)',
                'tip' => 'Abuja is well-suited to business trips — choose the CBD or Maitama to stay near meetings and the main hotels.',
                'currency' => 'Nigerian Naira (NGN)', 'language' => 'English',
                'best_time' => 'November to February offers the most comfortable, drier weather; the city stays pleasant year-round compared with the coast.',
                'getting_around' => 'The city is spread out and car-dependent — ride-hailing and hotel shuttles are the norm; roads are generally good and less congested than Lagos.',
                'highlights' => 'Aso Rock, the National Mosque and National Christian Centre, Millennium Park, and nearby Zuma Rock.',
            ],
            'kano' => [
                'name' => 'Kano', 'country' => 'Nigeria', 'nat' => 'NG',
                'intro' => 'Kano is northern Nigeria’s largest city, a historic trade centre and a common departure point for Umrah travellers.',
                'areas' => ['City centre — close to markets and historic sites', 'Nassarawa GRA — quieter residential area', 'Near Mallam Aminu Kano Airport (KAN) — convenient for early flights'],
                'airport' => 'Mallam Aminu Kano International Airport (KAN)',
                'tip' => 'If you’re travelling for Umrah, book a stay near the airport for early-morning departures and check our Umrah packages from Kano.',
                'currency' => 'Nigerian Naira (NGN)', 'language' => 'Hausa and English',
                'best_time' => 'November to February, the cooler, dry Harmattan months; March–May can be very hot.',
                'getting_around' => 'Taxis, tricycles (keke) and ride-hailing cover the city; the historic centre is best explored with a local guide.',
                'highlights' => 'Kurmi Market, the ancient city walls and Dala Hill, the Emir’s Palace and Gidan Makama Museum.',
            ],
            'port-harcourt' => [
                'name' => 'Port Harcourt', 'country' => 'Nigeria', 'nat' => 'NG',
                'intro' => 'Port Harcourt is the heart of Nigeria’s oil and gas industry — a busy southern city with strong business travel demand.',
                'areas' => ['GRA (Old & New) — safe, central and well-serviced', 'Trans-Amadi — industrial area, close to many offices', 'Airport road — convenient for flights (PHC)'],
                'airport' => 'Port Harcourt International Airport (PHC)',
                'tip' => 'Business travellers usually base themselves in the GRA for easy access to offices and dining.',
                'currency' => 'Nigerian Naira (NGN)', 'language' => 'English',
                'best_time' => 'The drier months of November to February are most comfortable; the area sees substantial rainfall for much of the year.',
                'getting_around' => 'Ride-hailing and hotel cars are the safest, simplest options; allow time between the airport and the GRA.',
                'highlights' => 'The Port Harcourt Pleasure Park, Isaac Boro Park, and a growing restaurant and events scene in the GRA.',
            ],
            'dubai' => [
                'name' => 'Dubai', 'country' => 'United Arab Emirates', 'nat' => 'NG',
                'intro' => 'Dubai is a top international destination for Nigerian travellers — modern, safe, and packed with shopping, dining and attractions.',
                'areas' => ['Downtown Dubai — Burj Khalifa, Dubai Mall and luxury hotels', 'Dubai Marina — waterfront dining and nightlife', 'Deira — traditional souks and value stays', 'Near DXB Airport — convenient for connections'],
                'airport' => 'Dubai International Airport (DXB)',
                'tip' => 'Check visa requirements before you travel, and book early for peak shopping-festival seasons when rates rise.',
                'currency' => 'UAE Dirham (AED)', 'language' => 'Arabic (English widely spoken)',
                'best_time' => 'November to March has the most pleasant weather; summer (June–September) is very hot, though hotel rates often fall.',
                'getting_around' => 'The Dubai Metro, taxis and ride-hailing make getting around easy and affordable; many attractions are a short ride apart.',
                'highlights' => 'Burj Khalifa, Dubai Mall and its fountains, the Dubai Frame, desert safaris, and the Palm Jumeirah.',
            ],
            'london' => [
                'name' => 'London', 'country' => 'United Kingdom', 'nat' => 'NG',
                'intro' => 'London is a classic destination for business, study and leisure — rich in history, culture and transport links.',
                'areas' => ['West End — theatres, shopping and central hotels', 'Kensington & Chelsea — upscale and elegant', 'City of London — business district', 'Near Heathrow (LHR) — convenient for arrivals'],
                'airport' => 'Heathrow Airport (LHR)',
                'tip' => 'Stay near an Underground station — London’s Tube makes getting around fast and cheap.',
                'currency' => 'Pound Sterling (GBP)', 'language' => 'English',
                'best_time' => 'May to September for the warmest weather and long days; spring and autumn are quieter and often better value.',
                'getting_around' => 'The Underground (Tube), buses and trains are extensive — a contactless card or Oyster card is all you need.',
                'highlights' => 'The British Museum, Buckingham Palace, the Tower of London, the London Eye, and world-class theatre and shopping.',
            ],
            'jeddah' => [
                'name' => 'Jeddah', 'country' => 'Saudi Arabia', 'nat' => 'NG',
                'intro' => 'Jeddah is the main gateway for Umrah and Hajj travellers, and a coastal Saudi city with a historic old town.',
                'areas' => ['Al-Balad — historic old town', 'Corniche — seafront hotels and dining', 'Near King Abdulaziz Airport (JED) — convenient for pilgrimage arrivals'],
                'airport' => 'King Abdulaziz International Airport (JED)',
                'tip' => 'Many travellers transit Jeddah for Umrah — see our Umrah packages for flights, visa, hotels and transport handled together.',
                'currency' => 'Saudi Riyal (SAR)', 'language' => 'Arabic (English in many hotels)',
                'best_time' => 'November to March for cooler weather; Umrah can be performed year-round, with Ramadan being the busiest period.',
                'getting_around' => 'Taxis and ride-hailing are widely available; for Umrah, many travellers use arranged transport to Makkah and Madinah.',
                'highlights' => 'The historic Al-Balad district (a UNESCO site), the Jeddah Corniche, King Fahd Fountain, and a vibrant seafront.',
            ],
            'istanbul' => [
                'name' => 'Istanbul', 'country' => 'Turkey', 'nat' => 'NG',
                'intro' => 'Istanbul bridges Europe and Asia — a vibrant city of history, markets and food, and an increasingly popular stop for Nigerian travellers.',
                'areas' => ['Sultanahmet — historic sites (Blue Mosque, Hagia Sophia)', 'Taksim & Beyoğlu — nightlife and shopping', 'Near IST Airport — convenient for connections'],
                'airport' => 'Istanbul Airport (IST)',
                'tip' => 'Stay in Sultanahmet if sightseeing is your priority, or Taksim for dining and nightlife.',
                'currency' => 'Turkish Lira (TRY)', 'language' => 'Turkish (English in tourist areas)',
                'best_time' => 'April to June and September to November offer mild weather and fewer crowds than mid-summer.',
                'getting_around' => 'Trams, the metro and ferries across the Bosphorus make getting around scenic and simple; an Istanbulkart covers public transport.',
                'highlights' => 'Hagia Sophia, the Blue Mosque, Topkapı Palace, the Grand Bazaar, and a Bosphorus cruise between two continents.',
            ],
            'makkah' => [
                'name' => 'Makkah', 'country' => 'Saudi Arabia', 'nat' => 'NG',
                'intro' => 'Makkah is the holiest city in Islam and the destination of Umrah and Hajj — most pilgrims choose a hotel by its distance from the Masjid al-Haram.',
                'areas' => ['Around the Clock Tower (Abraj Al Bait) — closest to the Haram', 'Ajyad — walking distance to the Haram', 'Aziziyah — more affordable, a short shuttle ride away'],
                'airport' => 'Served via Jeddah (JED) or Madinah (MED); the Haramain high-speed rail links Makkah, Jeddah and Madinah.',
                'tip' => 'Proximity to the Haram is the biggest factor in price — decide how far you are willing to walk, especially during Ramadan.',
                'currency' => 'Saudi Riyal (SAR)', 'language' => 'Arabic (English in many hotels)',
                'best_time' => 'Umrah can be performed year-round; Ramadan is the busiest and most expensive period, while the cooler months are more comfortable.',
                'getting_around' => 'Most pilgrims walk to the Haram or use hotel shuttles; the Haramain high-speed rail connects to Jeddah and Madinah.',
                'highlights' => 'The Masjid al-Haram and the Kaaba, the Clock Tower complex, and the holy sites visited during Umrah and Hajj.',
            ],
            'madinah' => [
                'name' => 'Madinah', 'country' => 'Saudi Arabia', 'nat' => 'NG',
                'intro' => 'Madinah is home to the Prophet’s Mosque (Al-Masjid an-Nabawi) and is a calm, revered city most Umrah travellers visit alongside Makkah.',
                'areas' => ['Central Zone — ringed around the Prophet’s Mosque, within walking distance', 'Outside the Central Zone — more affordable, a short shuttle away'],
                'airport' => 'Prince Mohammad bin Abdulaziz International Airport (MED)',
                'tip' => 'A hotel in the Central Zone puts the Prophet’s Mosque within walking distance — worth it for the daily prayers.',
                'currency' => 'Saudi Riyal (SAR)', 'language' => 'Arabic (English in many hotels)',
                'best_time' => 'Comfortable in the cooler months (November to March); busiest during Ramadan and the Hajj season.',
                'getting_around' => 'The Central Zone is walkable; taxis, ride-hailing and the Haramain high-speed rail cover longer trips.',
                'highlights' => 'Al-Masjid an-Nabawi (the Prophet’s Mosque), Quba Mosque, and the Ziyarah sites around the city.',
            ],
            'abu-dhabi' => [
                'name' => 'Abu Dhabi', 'country' => 'United Arab Emirates', 'nat' => 'NG',
                'intro' => 'Abu Dhabi, the UAE capital, pairs cultural landmarks and family attractions with a calmer pace than neighbouring Dubai.',
                'areas' => ['Corniche — waterfront hotels and promenades', 'Yas Island — theme parks and entertainment', 'Downtown / Al Maryah Island — business and upscale dining', 'Near AUH Airport — convenient for connections'],
                'airport' => 'Zayed International Airport (AUH)',
                'tip' => 'Yas Island suits families (theme parks); the Corniche suits a relaxed city-and-beach stay.',
                'currency' => 'UAE Dirham (AED)', 'language' => 'Arabic (English widely spoken)',
                'best_time' => 'November to March has the most pleasant weather; summer is very hot but hotel rates often fall.',
                'getting_around' => 'Taxis and ride-hailing are affordable and easy; many attractions are a short drive apart.',
                'highlights' => 'Sheikh Zayed Grand Mosque, Louvre Abu Dhabi, the Corniche, and the theme parks on Yas Island.',
            ],
            'doha' => [
                'name' => 'Doha', 'country' => 'Qatar', 'nat' => 'NG',
                'intro' => 'Doha is a modern Gulf capital of striking architecture, museums and souqs, and a popular stopover on connections through Hamad International.',
                'areas' => ['West Bay — skyline hotels and business', 'The Pearl — waterfront dining and marinas', 'Souq Waqif area — traditional markets and heritage stays', 'Near DOH Airport — convenient for transit'],
                'airport' => 'Hamad International Airport (DOH)',
                'tip' => 'If you are transiting on Qatar Airways, a short Doha stopover is easy to arrange and worth the museums and souqs.',
                'currency' => 'Qatari Riyal (QAR)', 'language' => 'Arabic (English widely spoken)',
                'best_time' => 'November to March is the most comfortable; summer is extremely hot.',
                'getting_around' => 'The Doha Metro is modern and cheap; taxis and ride-hailing fill the gaps.',
                'highlights' => 'The Museum of Islamic Art, Souq Waqif, the Corniche, Katara Cultural Village, and the National Museum of Qatar.',
            ],
            'cairo' => [
                'name' => 'Cairo', 'country' => 'Egypt', 'nat' => 'NG',
                'intro' => 'Cairo is Egypt’s sprawling capital and the gateway to the Pyramids of Giza — a dense, historic city on the Nile.',
                'areas' => ['Giza — closest to the Pyramids', 'Zamalek — leafy island district with hotels and dining', 'Downtown Cairo — central and historic', 'Near CAI Airport — convenient for connections'],
                'airport' => 'Cairo International Airport (CAI)',
                'tip' => 'Stay in Giza to wake up near the Pyramids, or Zamalek for a quieter, upscale base in the city.',
                'currency' => 'Egyptian Pound (EGP)', 'language' => 'Arabic (English in tourist areas)',
                'best_time' => 'October to April offers cooler, comfortable weather for sightseeing; summers are very hot.',
                'getting_around' => 'Ride-hailing apps are the easiest and safest way around; the metro is cheap but can be crowded.',
                'highlights' => 'The Pyramids of Giza and the Sphinx, the Egyptian Museum (and the new Grand Egyptian Museum), Islamic Cairo, and a Nile felucca ride.',
            ],
            'accra' => [
                'name' => 'Accra', 'country' => 'Ghana', 'nat' => 'NG',
                'intro' => 'Accra, Ghana’s coastal capital, is a short hop from Nigeria — popular for business, weekend trips and the Atlantic coastline.',
                'areas' => ['Airport Residential Area — upscale hotels near the airport', 'Osu — nightlife, dining and shopping', 'Cantonments / Labone — quiet, premium neighbourhoods', 'East Legon — modern residential area'],
                'airport' => 'Kotoka International Airport (ACC)',
                'tip' => 'The Airport Residential Area is handy for short trips; Osu is best if you want dining and nightlife on your doorstep.',
                'currency' => 'Ghanaian Cedi (GHS)', 'language' => 'English',
                'best_time' => 'The drier months (November to March) are most comfortable; the main rainy season runs roughly April to July.',
                'getting_around' => 'Ride-hailing apps are the easiest way around; allow time for traffic in the city centre.',
                'highlights' => 'Independence (Black Star) Square, Kwame Nkrumah Memorial Park, Jamestown, Labadi Beach, and the Osu nightlife.',
            ],
            'johannesburg' => [
                'name' => 'Johannesburg', 'country' => 'South Africa', 'nat' => 'NG',
                'intro' => 'Johannesburg is South Africa’s business hub and main air gateway — a common base for trade, connections and onward safari trips.',
                'areas' => ['Sandton — upscale business district, safe and central', 'Rosebank — hotels, shopping and the Gautrain', 'Melrose Arch — secure mixed-use precinct', 'Near OR Tambo (JNB) — convenient for connections'],
                'airport' => 'O. R. Tambo International Airport (JNB)',
                'tip' => 'Sandton is the usual choice for business travellers — safe, central and linked to the airport by the Gautrain.',
                'currency' => 'South African Rand (ZAR)', 'language' => 'English (among 11 official languages)',
                'best_time' => 'The dry winter months (May to September) are mild and sunny by day; summers bring afternoon thunderstorms.',
                'getting_around' => 'The Gautrain links the airport, Sandton and Pretoria; use ride-hailing apps for everything else.',
                'highlights' => 'The Apartheid Museum, Constitution Hill, nearby Soweto, and Maboneng’s arts and dining scene.',
            ],
            'nairobi' => [
                'name' => 'Nairobi', 'country' => 'Kenya', 'nat' => 'NG',
                'intro' => 'Nairobi is East Africa’s business and aviation hub — a green, high-altitude capital that doubles as the gateway to Kenyan safaris.',
                'areas' => ['Westlands — hotels, dining and nightlife', 'Upper Hill — business district', 'Kilimani — central and lively', 'Near JKIA (NBO) — convenient for connections'],
                'airport' => 'Jomo Kenyatta International Airport (NBO)',
                'tip' => 'Westlands and Upper Hill are the usual bases for business; allow extra time for Nairobi’s traffic.',
                'currency' => 'Kenyan Shilling (KES)', 'language' => 'English and Swahili',
                'best_time' => 'The drier spells (January to February and June to September) are best; these also align with the safari seasons.',
                'getting_around' => 'Ride-hailing apps are reliable and the easiest way around; traffic can be heavy at peak times.',
                'highlights' => 'Nairobi National Park (wildlife beside the city), the David Sheldrick elephant orphanage, the Giraffe Centre, and Karen Blixen’s house.',
            ],
            'kuala-lumpur' => [
                'name' => 'Kuala Lumpur', 'country' => 'Malaysia', 'nat' => 'NG',
                'intro' => 'Kuala Lumpur is a modern, affordable Asian capital — known for the Petronas Towers, great food and halal-friendly travel.',
                'areas' => ['KLCC — around the Petronas Towers, hotels and malls', 'Bukit Bintang — shopping, dining and nightlife', 'Chinatown / Bukit Bintang fringe — value stays', 'Near KLIA Airport — convenient for connections'],
                'airport' => 'Kuala Lumpur International Airport (KUL)',
                'tip' => 'KLCC and Bukit Bintang are linked by a free monorail-style walkway and are the most convenient bases for first-time visitors.',
                'currency' => 'Malaysian Ringgit (MYR)', 'language' => 'Malay (English widely spoken)',
                'best_time' => 'KL is warm and humid year-round; drier spells fall around May to July and December to February, with brief daily downpours common.',
                'getting_around' => 'The LRT, MRT and monorail are cheap and extensive; ride-hailing apps cover the rest.',
                'highlights' => 'The Petronas Twin Towers, Batu Caves, Bukit Bintang’s food and shopping, and KL Tower.',
            ],
        ];
    }
}

if (!function_exists('seoUmrahOriginCitiesDefaults')) {
    /** @return array<string,array> curated Umrah departure-city registry. */
    function seoUmrahOriginCitiesDefaults(): array
    {
        return [
            'kano' => [
                'name' => 'Kano', 'airport' => 'Mallam Aminu Kano International Airport (KAN)',
                'intro' => 'Kano is a major departure point for Umrah in northern Nigeria, with direct and connecting options to Jeddah and Madinah.',
                'note' => 'Most Kano departures route through Jeddah (JED) or Madinah (MED). Our packages bundle flights, visa, hotels and transport so you travel with peace of mind.',
                'carriers' => 'Max Air, Saudia and FlyNas commonly serve Kano–Jeddah, with seasonal direct services during peak Umrah periods.',
            ],
            'abuja' => [
                'name' => 'Abuja', 'airport' => 'Nnamdi Azikiwe International Airport (ABV)',
                'intro' => 'Abuja, Nigeria’s capital, offers convenient Umrah departures for travellers across central Nigeria.',
                'note' => 'Abuja departures typically connect to Jeddah or Madinah. Compare comfort tiers and dates below, with everything handled end to end.',
                'carriers' => 'Abuja departures usually connect via a Gulf or regional hub on carriers such as Saudia, EgyptAir, Qatar Airways or Ethiopian Airlines.',
            ],
            'lagos' => [
                'name' => 'Lagos', 'airport' => 'Murtala Muhammed International Airport (LOS)',
                'intro' => 'Lagos is Nigeria’s busiest gateway, with the widest choice of Umrah flight connections to Saudi Arabia.',
                'note' => 'Lagos offers frequent connections via Jeddah or Madinah. Our Umrah packages include flights, visa, accommodation near the Haram, and ground transport.',
                'carriers' => 'From Lagos you’ll find the widest choice — Saudia, EgyptAir, Qatar Airways, Ethiopian Airlines and others, with frequent connections to Jeddah and Madinah.',
            ],
            'kaduna' => [
                'name' => 'Kaduna', 'airport' => 'Kaduna International Airport (KAD)',
                'intro' => 'Kaduna is a central-northern departure point for Umrah, serving travellers across Kaduna State and the surrounding region.',
                'note' => 'Kaduna departures usually connect through Abuja, Kano or a Gulf hub to reach Jeddah (JED) or Madinah (MED). Our packages bundle flights, visa, hotels and transport end to end.',
                'carriers' => 'Expect a connection via Abuja, Kano or a Gulf hub on carriers such as Max Air, Saudia or a regional airline; direct charters appear during peak Umrah and Hajj seasons.',
            ],
            'sokoto' => [
                'name' => 'Sokoto', 'airport' => 'Sadiq Abubakar III International Airport (SKO)',
                'intro' => 'Sokoto, the seat of the Caliphate, is a historic northwestern departure point for pilgrims travelling for Umrah and Hajj.',
                'note' => 'Sokoto departures route through Jeddah (JED) or Madinah (MED), often via Kano, Abuja or a Gulf hub. We handle the flights, visa, accommodation near the Haram and ground transport.',
                'carriers' => 'Max Air and Saudia are commonly associated with Sokoto pilgrimage operations, with seasonal direct charters during the peak Umrah and Hajj periods.',
            ],
            'maiduguri' => [
                'name' => 'Maiduguri', 'airport' => 'Maiduguri International Airport (MIU)',
                'intro' => 'Maiduguri is the main departure point for Umrah travellers in Borno State and Nigeria’s north-east.',
                'note' => 'Maiduguri departures connect to Jeddah or Madinah, typically via Kano, Abuja or a Gulf hub. Our packages cover flights, visa, hotels and transport so you can focus on your pilgrimage.',
                'carriers' => 'Connections are usually via Kano, Abuja or a Gulf hub on carriers such as Max Air or Saudia; direct pilgrimage charters run during peak seasons.',
            ],
            'ilorin' => [
                'name' => 'Ilorin', 'airport' => 'Ilorin International Airport (ILR)',
                'intro' => 'Ilorin serves Umrah travellers across Kwara State and north-central Nigeria, with convenient connections onward to Saudi Arabia.',
                'note' => 'Ilorin departures connect via Lagos, Abuja or a Gulf hub to reach Jeddah (JED) or Madinah (MED). Everything — flights, visa, accommodation and transport — is handled for you.',
                'carriers' => 'Expect a connection through Lagos, Abuja or a Gulf hub on carriers such as Saudia, EgyptAir or a regional airline; seasonal charters operate in peak periods.',
            ],
            'port-harcourt' => [
                'name' => 'Port Harcourt', 'airport' => 'Port Harcourt International Airport (PHC)',
                'intro' => 'Port Harcourt is the main Umrah gateway for Rivers State and Nigeria’s south-south region.',
                'note' => 'Port Harcourt departures typically connect via Lagos, Abuja or a Gulf hub to Jeddah or Madinah. Our Umrah packages bundle flights, visa, hotels near the Haram and ground transport.',
                'carriers' => 'Connections are usually via Lagos, Abuja or a Gulf hub on carriers such as Qatar Airways, Ethiopian Airlines, EgyptAir or Saudia.',
            ],
            'enugu' => [
                'name' => 'Enugu', 'airport' => 'Akanu Ibiam International Airport (ENU)',
                'intro' => 'Enugu serves Umrah travellers across south-eastern Nigeria, with onward connections to Saudi Arabia.',
                'note' => 'Enugu departures connect via Lagos, Abuja or a Gulf hub to reach Jeddah (JED) or Madinah (MED). We arrange the flights, visa, accommodation and transport from start to finish.',
                'carriers' => 'Expect a connection through Lagos, Abuja or a Gulf hub on carriers such as Ethiopian Airlines, Qatar Airways, EgyptAir or Saudia.',
            ],
        ];
    }
}

if (!function_exists('seoTourDestinationsDefaults')) {
    /**
     * Curated tours/experiences destination registry. Each page is an evergreen
     * guide to the kinds of tours and experiences available in a destination plus
     * a live link into the real tours search. We do NOT claim fixed prices or a
     * fixed number of tours — tour inventory is dynamic — so the page is a guide +
     * a search front door, mirroring the hotel-city pages.
     *
     * Shape: name, country, intro, experiences[] (popular tour themes), tip,
     * best_time, getting_around, highlights.
     * @return array<string,array>
     */
    function seoTourDestinationsDefaults(): array
    {
        return [
            'dubai' => [
                'name' => 'Dubai', 'country' => 'United Arab Emirates',
                'intro' => 'Dubai packs desert adventure, modern landmarks and family attractions into one compact, easy-to-tour destination — a favourite for Nigerian travellers.',
                'experiences' => ['Desert safari with dune bashing, camel rides and a BBQ dinner', 'Burj Khalifa observation-deck tickets', 'Dubai Marina dhow cruise with dinner', 'Theme parks and the aquarium for families', 'City sightseeing and the Dubai Frame'],
                'tip' => 'Book desert safaris and Burj Khalifa slots ahead during peak shopping-festival season, when they sell out fastest.',
                'best_time' => 'November to March offers the most pleasant weather for outdoor tours; summer is very hot, though prices often ease.',
                'getting_around' => 'Most tours include hotel pick-up and drop-off; otherwise the Metro, taxis and ride-hailing make self-guided days easy.',
                'highlights' => 'Desert safaris, the Burj Khalifa, a Marina dhow cruise, theme parks and the Palm Jumeirah.',
            ],
            'istanbul' => [
                'name' => 'Istanbul', 'country' => 'Turkey',
                'intro' => 'Istanbul straddles two continents and layers Ottoman palaces, grand mosques, bustling bazaars and a scenic Bosphorus — rich territory for guided tours.',
                'experiences' => ['Old City walking tour (Hagia Sophia, Blue Mosque, Topkapı Palace)', 'Bosphorus cruise between Europe and Asia', 'Grand Bazaar and Spice Bazaar shopping tour', 'Turkish cooking class and food tours', 'Day trips to Princes’ Islands'],
                'tip' => 'Group the Sultanahmet sights into one guided day — they are walkable together — and save the Bosphorus cruise for the afternoon.',
                'best_time' => 'April to June and September to November bring mild weather and thinner crowds than mid-summer.',
                'getting_around' => 'Trams, the metro and Bosphorus ferries are cheap and scenic; many tours start in Sultanahmet.',
                'highlights' => 'Hagia Sophia, the Blue Mosque, Topkapı Palace, the Grand Bazaar and a Bosphorus cruise.',
            ],
            'cairo' => [
                'name' => 'Cairo', 'country' => 'Egypt',
                'intro' => 'Cairo is the gateway to the Pyramids of Giza and thousands of years of history — one of the world’s great destinations for guided cultural tours.',
                'experiences' => ['Pyramids of Giza and the Sphinx guided tour', 'Egyptian Museum (and the Grand Egyptian Museum) tours', 'Nile dinner cruise with entertainment', 'Islamic and Coptic Cairo walking tours', 'Day trips to Alexandria or Saqqara'],
                'tip' => 'Start Pyramids tours early to beat the heat and crowds, and use a reputable guide for context and hassle-free entry.',
                'best_time' => 'October to April is cooler and far more comfortable for sightseeing; summers are intensely hot.',
                'getting_around' => 'Most tours include transfers; for independent travel, ride-hailing apps are the easiest and safest option.',
                'highlights' => 'The Pyramids and Sphinx, the Egyptian Museum, a Nile cruise, and historic Islamic Cairo.',
            ],
            'nairobi' => [
                'name' => 'Nairobi', 'country' => 'Kenya',
                'intro' => 'Nairobi is the launchpad for Kenyan safaris and a wildlife destination in its own right — a national park sits on the city’s doorstep.',
                'experiences' => ['Nairobi National Park game drives', 'David Sheldrick elephant orphanage and the Giraffe Centre', 'Day and multi-day safaris to the Maasai Mara', 'Karen Blixen Museum and coffee-farm tours', 'Cultural and city tours'],
                'tip' => 'Combine the elephant orphanage and Giraffe Centre into one morning, then add a Maasai Mara safari extension if time allows.',
                'best_time' => 'The drier spells (January–February and June–September) give the best game viewing and road conditions.',
                'getting_around' => 'Safari tours include 4x4 transport and a guide; in the city, ride-hailing apps are reliable.',
                'highlights' => 'Nairobi National Park, the elephant orphanage, the Giraffe Centre and Maasai Mara safaris.',
            ],
            'cape-town' => [
                'name' => 'Cape Town', 'country' => 'South Africa',
                'intro' => 'Cape Town blends a dramatic mountain-and-ocean setting with winelands, beaches and history — one of Africa’s most tour-friendly cities.',
                'experiences' => ['Table Mountain cableway and hikes', 'Cape Peninsula and Cape of Good Hope day tour', 'Winelands tasting tours (Stellenbosch and Franschhoek)', 'Robben Island historical tour', 'Boulders Beach penguins and coastal drives'],
                'tip' => 'Table Mountain depends on the weather — keep it flexible and go on a clear, low-wind day.',
                'best_time' => 'The summer months (roughly November to March) are warm and dry and best for the outdoors.',
                'getting_around' => 'Guided tours cover the Peninsula and winelands with transport; ride-hailing works well in the city.',
                'highlights' => 'Table Mountain, the Cape Peninsula, the winelands, Robben Island and the penguins at Boulders Beach.',
            ],
            'zanzibar' => [
                'name' => 'Zanzibar', 'country' => 'Tanzania',
                'intro' => 'Zanzibar pairs turquoise Indian Ocean beaches with the spice-trade history of Stone Town — a relaxed island destination for beach and culture tours.',
                'experiences' => ['Stone Town heritage walking tour', 'Spice farm tours', 'Dolphin tours and snorkelling trips', 'Prison Island and the giant tortoises', 'Sunset dhow cruises'],
                'tip' => 'Pair a morning spice-farm tour with an afternoon at the beach, and save a sunset dhow cruise for your last evening.',
                'best_time' => 'June to October and December to February are drier and ideal for beaches and boat tours.',
                'getting_around' => 'Tours include transfers across the island; taxis cover shorter hops around Stone Town and the beaches.',
                'highlights' => 'Stone Town, spice farms, dolphin and snorkelling trips, and the island’s famous beaches.',
            ],
            'accra' => [
                'name' => 'Accra', 'country' => 'Ghana',
                'intro' => 'Accra offers history, culture and Atlantic coastline a short hop from Nigeria — easy for weekend tours and heritage trips.',
                'experiences' => ['City heritage tour (Independence Square, Jamestown)', 'Day trip to Cape Coast and Elmina castles', 'Kakum National Park canopy walk', 'Local food and market tours', 'Labadi Beach and coastal visits'],
                'tip' => 'The Cape Coast castles and Kakum canopy walk make a strong full-day trip — start early as it is a couple of hours each way.',
                'best_time' => 'The drier months (November to March) are most comfortable for touring.',
                'getting_around' => 'Guided day trips include transport; in the city, ride-hailing apps are the easiest way around.',
                'highlights' => 'Independence Square, Jamestown, the Cape Coast and Elmina castles, and the Kakum canopy walk.',
            ],
            'lagos' => [
                'name' => 'Lagos', 'country' => 'Nigeria',
                'intro' => 'Lagos is Nigeria’s cultural and nightlife capital — beaches, art, markets and a buzzing food-and-music scene make for lively city tours.',
                'experiences' => ['Lekki Conservation Centre and canopy walkway', 'Nike Art Gallery visit', 'Beach day trips (Lekki, Tarkwa Bay, Elegushi)', 'Food, market and nightlife tours', 'Boat cruises on the lagoon'],
                'tip' => 'Plan around traffic — group island attractions (Lekki, Victoria Island) into one day and the mainland into another.',
                'best_time' => 'November to March is drier and best for beaches and outdoor tours; the rainy season runs roughly May to September.',
                'getting_around' => 'Ride-hailing is the easiest way around; allow generous time for traffic between the mainland and the islands.',
                'highlights' => 'Lekki Conservation Centre, Nike Art Gallery, the beaches, and a vibrant food and nightlife scene.',
            ],
        ];
    }
}

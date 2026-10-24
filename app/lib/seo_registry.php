<?php
// ============================================================================
// FILE: app/lib/seo_registry.php
// SEO landing-page registries (SEO-005). Pure data, NO side effects — safe to
// include from anywhere (route layer, sitemap generator, discovery directory).
// Each list is CURATED/hand-written so a landing page only exists where we have
// real, specific content; anything not listed redirects (no thin auto-pages).
// ============================================================================

if (!function_exists('seoFlightRouteData')) {
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
    function seoFlightRouteData(): array
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

if (!function_exists('seoHotelCities')) {
    /** @return array<string,array> curated hotel-city content registry. */
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

if (!function_exists('seoUmrahOriginCities')) {
    /** @return array<string,array> curated Umrah departure-city registry. */
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

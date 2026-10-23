<?php
// ============================================================================
// FILE: app/lib/seo_registry.php
// SEO landing-page registries (SEO-005). Pure data, NO side effects — safe to
// include from anywhere (route layer, sitemap generator, discovery directory).
// Each list is CURATED/hand-written so a landing page only exists where we have
// real, specific content; anything not listed redirects (no thin auto-pages).
// ============================================================================

if (!function_exists('seoFlightRoutes')) {
    /** @return string[] curated {from}-to-{to} slugs (real high-intent routes). */
    function seoFlightRoutes(): array
    {
        return [
            // Domestic (both directions for the key hubs)
            'lagos-to-abuja', 'abuja-to-lagos',
            'lagos-to-kano', 'kano-to-lagos',
            'lagos-to-port-harcourt', 'port-harcourt-to-lagos',
            'abuja-to-kano', 'kano-to-abuja',
            'abuja-to-port-harcourt', 'port-harcourt-to-abuja',
            // International (NG hubs → top destinations)
            'lagos-to-dubai', 'lagos-to-london', 'lagos-to-jeddah',
            'abuja-to-dubai', 'abuja-to-london', 'abuja-to-istanbul',
            'kano-to-jeddah', 'lagos-to-accra',
        ];
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

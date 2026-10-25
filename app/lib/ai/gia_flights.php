<?php
// ============================================================================
// FILE: app/lib/ai/gia_flights.php
// GIA's OWN dedicated FLIGHTS flow. GIA is NOT the website and NOT the mobile
// app — so flights on GIA do NOT ride the shared web search service
// (aiSearchService) with its browser assumptions (default travel date,
// geolocation-only origin, $_SESSION currency). This module is the single home
// for everything GIA does with flights: search real fares, price-lock the chosen
// fare, collect passengers, create a held booking, pay, issue.
//
//   service  = flights
//   provider = whichever flight supplier(s) the admin has ACTIVE (supplier-
//              agnostic — resolved from the modules table, NEVER hardcoded)
//   product  = a specific fare/offer (carries a short-lived supplier token)
//
// It CALLS the shared, proven gateway + revalidate helpers READ-ONLY (the same
// ones the website's reprice uses) but NEVER edits them and NEVER touches any
// web route/view. Verified contracts (read from real code, not guessed):
//   - search:     POST {root}/modules/flights/<supplier>/search via aiTripHttpPost
//                 (mirrors gia_live_offers_flights, gia_live_offers.php:453)
//   - revalidate: aiTripRevalidatePackage($db,$draft) -> fail-closed price-lock
//                 (tripRevalidateHelper.php:3056 / aiTripRevalidateFlightItem:264)
// Both helpers live in routes/ai/tripRevalidateHelper.php and are loaded here the
// same guarded way gia_live_offers.php loads them.
// ============================================================================

namespace App\lib\ai;

if (!function_exists('App\\lib\\ai\\gia_flights_boot')) {
    /** Ensure the shared trip helpers (aiTripHttpPost, revalidate) are loaded. */
    function gia_flights_boot(): bool
    {
        if (!\function_exists('aiTripHttpPost') || !\function_exists('aiTripRevalidatePackage')) {
            $helper = \dirname(__DIR__, 2) . '/routes/ai/tripRevalidateHelper.php';
            if (\is_file($helper)) { require_once $helper; }
        }
        return \function_exists('aiTripHttpPost');
    }
}

if (!function_exists('App\\lib\\ai\\gia_flights_ensure_window_column')) {
    /**
     * Idempotently add modules.gia_pay_window_min — the admin-configurable payment
     * window (minutes) PER flight provider. Default 15. Self-healing (house pattern):
     * if the DB user lacks ALTER, the resolver below still falls back to 15.
     */
    function gia_flights_ensure_window_column($db): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $col = $db->query("SHOW COLUMNS FROM `modules` LIKE 'gia_pay_window_min'")->fetchAll();
            if (!$col) {
                $db->query("ALTER TABLE `modules` ADD COLUMN `gia_pay_window_min` SMALLINT NOT NULL DEFAULT 15");
            }
        } catch (\Throwable $e) {
            \error_log('[gia_flights] ensure window column: ' . $e->getMessage());
        }
    }
}

if (!function_exists('App\\lib\\ai\\gia_flights_pay_window')) {
    /**
     * The effective flight payment window (minutes) = the TIGHTER of:
     *   (a) the admin per-provider window (modules.gia_pay_window_min, default 15), and
     *   (b) the supplier's REAL fare expiry (fare_expires_at) converted to minutes-from-now.
     * We never promise longer than the fare actually lives — so a traveller can't pay
     * into a window that outlasts the fare and then fail to issue. Floors at 1 minute.
     *
     * @param string $supplier       the flight supplier slug
     * @param string $fareExpiresAt  supplier hard expiry (any strtotime-parseable) or ''
     * @return int minutes (>=1)
     */
    function gia_flights_pay_window($db, string $supplier, string $fareExpiresAt = ''): int
    {
        // (a) admin per-provider window.
        $adminMin = 15;
        try {
            gia_flights_ensure_window_column($db);
            $row = $db->get('modules', ['gia_pay_window_min'], ['type' => 'flights', 'name' => \strtolower(\trim($supplier))]);
            if (\is_array($row) && (int) ($row['gia_pay_window_min'] ?? 0) > 0) {
                $adminMin = (int) $row['gia_pay_window_min'];
            }
        } catch (\Throwable $e) { /* default 15 */ }
        $adminMin = \max(1, \min(1440, $adminMin));

        // (b) supplier fare expiry, as whole minutes from now (if provided + future).
        $effective = $adminMin;
        $fareExpiresAt = \trim($fareExpiresAt);
        if ($fareExpiresAt !== '') {
            $ts = \strtotime($fareExpiresAt);
            if ($ts !== false && $ts > \time()) {
                $fareMin = (int) \floor(($ts - \time()) / 60);
                if ($fareMin >= 1) { $effective = \min($effective, $fareMin); }
                // If the fare expires in <1 min, keep the admin floor of 1 — the
                // pre-pay re-revalidation in gia_flights_book will fail it anyway.
            }
        }
        return \max(1, $effective);
    }
}

if (!function_exists('App\\lib\\ai\\gia_flights_active_suppliers')) {
    /**
     * The flight suppliers the admin has ACTIVE, read from the modules table the
     * SAME way aiSearchService::activeSuppliers does (type=flights, status=1,
     * active=1). We exclude the generic parent 'flights' row — it is the module
     * umbrella, not a bookable supplier gateway. Supplier-agnostic by design:
     * 14 flight suppliers can be active; we never hardcode one.
     *
     * @return array<int,string> lowercase supplier slugs that have a search.php
     */
    function gia_flights_active_suppliers($db): array
    {
        $out = [];
        try {
            $rows = $db->select('modules', ['name'], ['type' => 'flights', 'status' => 1, 'active' => 1]) ?: [];
        } catch (\Throwable $e) {
            \error_log('[gia_flights] active_suppliers: ' . $e->getMessage());
            return [];
        }
        foreach ($rows as $r) {
            $n = \strtolower(\trim((string) ($r['name'] ?? '')));
            if ($n === '' || $n === 'flights') { continue; } // skip the umbrella row
            // Only suppliers that actually expose a search gateway are usable.
            if (\is_file(\dirname(__DIR__, 2) . '/../modules/flights/' . $n . '/search.php')
                || \is_file(\dirname(__DIR__, 2) . '/modules/flights/' . $n . '/search.php')) {
                $out[] = $n;
            }
        }
        return \array_values(\array_unique($out));
    }
}

if (!function_exists('App\\lib\\ai\\gia_flights_city_hub')) {
    /**
     * The PRIMARY international airport for a city name, or '' if unmapped. Needed
     * because flights_airports has no hub/size signal, so a generic city query picks
     * an arbitrary (often wrong) airport — "London"->STN, "New York"/"Paris"->a
     * heliport. Mirrors the web's curated hub list
     * (aiSearchService::cityAirportAliases) PLUS the Nigerian cities GIA serves, so
     * chat and web pick the same primary hub. Case/padding-insensitive.
     *
     * @return string 3-letter IATA (upper) or '' when the city isn't in the map
     */
    function gia_flights_city_hub(string $place): string
    {
        $key = \strtolower(\trim($place));
        if ($key === '') { return ''; }
        static $hubs = [
            // Nigeria (GIA's core market)
            'lagos' => 'LOS', 'abuja' => 'ABV', 'kano' => 'KAN', 'port harcourt' => 'PHC',
            'enugu' => 'ENU', 'kaduna' => 'KAD', 'benin' => 'BNI', 'ibadan' => 'IBA',
            'owerri' => 'QOW', 'calabar' => 'CBQ', 'maiduguri' => 'MIU', 'sokoto' => 'SKO',
            'yola' => 'YOL', 'ilorin' => 'ILR', 'asaba' => 'ABB', 'uyo' => 'QUO',
            // Major international hubs (mirror aiSearchService::cityAirportAliases)
            'london' => 'LHR', 'new york' => 'JFK', 'paris' => 'CDG', 'tokyo' => 'NRT',
            'dubai' => 'DXB', 'abu dhabi' => 'AUH', 'doha' => 'DOH', 'istanbul' => 'IST',
            'cairo' => 'CAI', 'jeddah' => 'JED', 'riyadh' => 'RUH', 'dammam' => 'DMM',
            'bangkok' => 'BKK', 'singapore' => 'SIN', 'kuala lumpur' => 'KUL',
            'madrid' => 'MAD', 'barcelona' => 'BCN', 'rome' => 'FCO', 'toronto' => 'YYZ',
            'manchester' => 'MAN', 'muscat' => 'MCT', 'sharjah' => 'SHJ', 'bahrain' => 'BAH',
            'kuwait' => 'KWI', 'tehran' => 'IKA', 'delhi' => 'DEL', 'mumbai' => 'BOM',
            'beijing' => 'PEK', 'orlando' => 'MCO', 'lahore' => 'LHE', 'karachi' => 'KHI',
            'islamabad' => 'ISB', 'accra' => 'ACC', 'nairobi' => 'NBO', 'johannesburg' => 'JNB',
            'amsterdam' => 'AMS', 'frankfurt' => 'FRA', 'washington' => 'IAD',
            'los angeles' => 'LAX', 'chicago' => 'ORD', 'atlanta' => 'ATL', 'houston' => 'IAH',
            'addis ababa' => 'ADD', 'addis' => 'ADD',
        ];
        return $hubs[$key] ?? '';
    }
}

if (!function_exists('App\\lib\\ai\\gia_flights_to_iata')) {
    /**
     * Resolve a place to a 3-letter IATA airport code — because the flight supplier
     * gateways need CODES, not city names (verified live: adivaha returns [] for
     * "Lagos" but real fares for "LOS"). Accepts an already-valid code as-is; else
     * consults the primary-hub map, then the flights_airports table. Returns '' if it
     * can't resolve (caller then asks the traveller to clarify the airport).
     */
    function gia_flights_to_iata($db, string $place): string
    {
        $place = \trim($place);
        if ($place === '') { return ''; }
        // Already a 3-letter code? use it.
        if (\preg_match('/^[A-Za-z]{3}$/', $place)) { return \strtoupper($place); }
        // PRIMARY-HUB MAP (checked before the generic city query). flights_airports has
        // NO hub/size signal — every row is type='airport' — so a bare city query picks
        // an arbitrary airport: "London" resolved to STN (Stansted), "New York" to JRA
        // and "Paris" to JPU (both HELIPORTS). A city with several airports should map
        // to its primary international hub. Mirrors the web's curated list
        // (aiSearchService::cityAirportAliases) so chat and web agree.
        $hub = gia_flights_city_hub($place);
        if ($hub !== '') { return $hub; }
        try {
            // Exact code match first (defensive), then city, then airport name.
            $row = $db->get('flights_airports', ['code'], ['code' => \strtoupper($place), 'status' => '1']);
            if (\is_array($row) && !empty($row['code'])) { return \strtoupper((string) $row['code']); }
            // Prefer a major (type=large/international) airport when a city has several.
            $row = $db->get('flights_airports', ['code'], [
                'city' => $place, 'status' => '1',
                'ORDER' => ['type' => 'ASC'],
            ]);
            if (\is_array($row) && !empty($row['code'])) { return \strtoupper((string) $row['code']); }
            // LIKE fallback on city/airport for a looser match ("Lagos", "Lagos Nigeria").
            $row = $db->get('flights_airports', ['code'], [
                'OR' => ['city[~]' => $place, 'airport[~]' => $place],
                'status' => '1',
            ]);
            if (\is_array($row) && !empty($row['code'])) { return \strtoupper((string) $row['code']); }
        } catch (\Throwable $e) {
            \error_log('[gia_flights] to_iata(' . $place . '): ' . $e->getMessage());
        }
        return '';
    }
}

if (!function_exists('App\\lib\\ai\\gia_flights_search')) {
    /**
     * Search REAL bookable flight fares for GIA, as chat-ready offer cards.
     * Requires explicit origin + destination + departure_date (NO default-date
     * injection, NO browser geolocation — the chat must have collected them). Tries
     * each active supplier until one returns fares; each card carries the stable
     * supplier payload + short-lived token so a later select can price-lock it.
     *
     * @param array $opts origin, destination, departure_date, return_date, type
     *                    (oneway|return), class, adults, children, infants, currency, limit
     * @return array{ok:bool, offers:array<int,array<string,mixed>>, message?:string}
     */
    function gia_flights_search($db, array $opts = []): array
    {
        if (!gia_flights_boot()) {
            \error_log('[gia_flights] trip helpers not loadable');
            return ['ok' => false, 'offers' => [], 'message' => 'Flight search is temporarily unavailable.'];
        }

        $class       = \strtolower(\trim((string) ($opts['class'] ?? 'economy'))) ?: 'economy';
        $adults      = \max(1, (int) ($opts['adults'] ?? 1));
        $children    = \max(0, (int) ($opts['children'] ?? 0));
        $infants     = \max(0, (int) ($opts['infants'] ?? 0));
        $currency    = \strtoupper(\trim((string) ($opts['currency'] ?? ''))) ?: 'USD';
        $limit       = (int) ($opts['limit'] ?? 8); if ($limit <= 0) { $limit = 8; }

        // MULTI-CITY: an explicit ordered list of legs (>=2), each
        // {origin,destination,date}. Each place is resolved to IATA; a leg that can't
        // resolve, or <2 valid legs, is rejected (never guess a route/date). The
        // gateway wants type='multicity' + routes=JSON[{from,to,date}]
        // (adivaha/search.php:80-179). Branch BEFORE the oneway/return path.
        $segmentsIn = \is_array($opts['segments'] ?? null) ? $opts['segments'] : [];
        $isMulti = \count($segmentsIn) >= 1 && (\strtolower((string) ($opts['type'] ?? '')) === 'multicity' || \count($segmentsIn) >= 2);
        if ($isMulti) {
            $routes = [];
            $legMissing = [];
            $firstOrigin = '';
            foreach ($segmentsIn as $i => $seg) {
                if (!\is_array($seg)) { continue; }
                $fromRaw = \trim((string) ($seg['origin'] ?? $seg['from'] ?? ''));
                $toRaw   = \trim((string) ($seg['destination'] ?? $seg['to'] ?? ''));
                $dateRaw = \trim((string) ($seg['date'] ?? $seg['depart_date'] ?? $seg['departure_date'] ?? ''));
                $from = gia_flights_to_iata($db, $fromRaw);
                $to   = gia_flights_to_iata($db, $toRaw);
                $ts   = $dateRaw !== '' ? \strtotime($dateRaw) : false;
                $n = $i + 1;
                if ($fromRaw === '' || $from === '') { $legMissing[] = 'a valid departure airport for leg ' . $n . ($fromRaw !== '' ? ' (couldn\'t match "' . $fromRaw . '")' : ''); }
                if ($toRaw === '' || $to === '')     { $legMissing[] = 'a valid destination for leg ' . $n . ($toRaw !== '' ? ' (couldn\'t match "' . $toRaw . '")' : ''); }
                if ($ts === false)                   { $legMissing[] = 'a date for leg ' . $n; }
                if ($from !== '' && $to !== '' && $from === $to) { $legMissing[] = 'different airports for leg ' . $n . ' (origin and destination are the same)'; }
                if ($from !== '' && $to !== '' && $ts !== false && $from !== $to) {
                    if ($firstOrigin === '') { $firstOrigin = $from; }
                    $routes[] = ['from' => $from, 'to' => $to, 'date' => \date('Y-m-d', $ts)];
                }
            }
            if ($legMissing !== []) {
                return ['ok' => false, 'offers' => [], 'need' => $legMissing,
                    'message' => 'For a multi-city trip I need ' . \implode('; ', $legMissing) . '.'];
            }
            if (\count($routes) < 2) {
                return ['ok' => false, 'offers' => [], 'need' => ['at least 2 valid legs'],
                    'message' => 'A multi-city trip needs at least 2 legs — tell me each stop and its date.'];
            }
            $suppliers = gia_flights_active_suppliers($db);
            if ($suppliers === []) {
                \error_log('[gia_flights] no active flight supplier with a search gateway');
                return ['ok' => false, 'offers' => [], 'message' => 'No flight supplier is available right now.'];
            }
            $body = [
                'type'      => 'multicity',
                'routes'    => \json_encode($routes), // gateway decodes this (search.php:82)
                'class'     => $class,
                'adults'    => (string) $adults,
                'childrens' => (string) $children,
                'infants'   => (string) $infants,
                'currency'  => $currency,
            ];
            $searchLabel = \implode(' → ', \array_merge([$firstOrigin], \array_map(static fn($r) => $r['to'], $routes)));
            \error_log('[gia_flights] MULTICITY search ' . $searchLabel . ' legs=' . \count($routes)
                . ' suppliers=' . \json_encode($suppliers));
            return gia_flights_collect($db, $suppliers, $body, $class, $adults, $children, $infants, $currency, $limit, $routes);
        }

        $originRaw      = \trim((string) ($opts['origin'] ?? ''));
        $destinationRaw = \trim((string) ($opts['destination'] ?? ''));
        // Suppliers need IATA CODES, not city names (verified: adivaha returns [] for
        // "Lagos", real fares for "LOS"). Resolve via flights_airports.
        $origin      = gia_flights_to_iata($db, $originRaw);
        $destination = gia_flights_to_iata($db, $destinationRaw);
        $departDate  = \trim((string) ($opts['departure_date'] ?? ''));
        $returnDate  = \trim((string) ($opts['return_date'] ?? ''));
        $type        = \strtolower(\trim((string) ($opts['type'] ?? ''))) ?: ($returnDate !== '' ? 'return' : 'oneway');

        // HARD requirement — never guess a route or a date (the web-coupling that
        // broke umrah). If any is missing OR a city couldn't be resolved to an
        // airport code, ask; do not invent one.
        $missing = [];
        if ($originRaw === '')      { $missing[] = 'departure city/airport'; }
        elseif ($origin === '')     { $missing[] = 'a valid departure airport (I couldn\'t match "' . $originRaw . '" — try the city or its 3-letter code)'; }
        if ($destinationRaw === '') { $missing[] = 'destination'; }
        elseif ($destination === '') { $missing[] = 'a valid destination airport (I couldn\'t match "' . $destinationRaw . '")'; }
        if ($departDate === '')     { $missing[] = 'departure date'; }
        if ($missing !== []) {
            return ['ok' => false, 'offers' => [], 'need' => $missing,
                'message' => 'I need ' . \implode(', ', $missing) . ' to search flights.'];
        }

        $suppliers = gia_flights_active_suppliers($db);
        if ($suppliers === []) {
            \error_log('[gia_flights] no active flight supplier with a search gateway');
            return ['ok' => false, 'offers' => [], 'message' => 'No flight supplier is available right now.'];
        }

        $body = [
            'origin'         => $origin,
            'destination'    => $destination,
            'departure_date' => $departDate,
            'return_date'    => $returnDate,
            'type'           => $type === 'return' ? 'return' : 'oneway',
            'class'          => $class,
            'adults'         => (string) $adults,
            'childrens'      => (string) $children, // gateway spells it 'childrens' (verified)
            'infants'        => (string) $infants,
            'currency'       => $currency,
        ];
        \error_log('[gia_flights] search ' . $origin . '->' . $destination . ' ' . $departDate
            . ' suppliers=' . \json_encode($suppliers));
        // Shared supplier loop + card build (oneway/return; $routes empty).
        return gia_flights_collect($db, $suppliers, $body, $class, $adults, $children, $infants, $currency, $limit, []);
    }
}

if (!function_exists('App\\lib\\ai\\gia_flights_collect')) {
    /**
     * Run the chosen supplier gateways with $body, normalise every returned offer
     * into a GIA card (full detail + round-trip return leg OR multi-city all-legs),
     * merge cheapest-first, cap to $limit, and report total_found for disclosure.
     * Used by BOTH oneway/return and multi-city so the card shape stays identical.
     *
     * @param array $routes multi-city legs [{from,to,date}] — [] for oneway/return.
     * @return array{ok:bool, offers:array, total_found:int, message?:string}
     */
    function gia_flights_collect($db, array $suppliers, array $body, string $class, int $adults, int $children, int $infants, string $currency, int $limit, array $routes = []): array
    {
        $offers = [];
        $lastMsg = 'No flights found for that route and date.';
        $base = \defined('root') ? \rtrim((string) \root, '/') : '';
        $isMulti = \count($routes) >= 2;
        // adivaha polls server-side (~70s oneway, up to ~90s multicity) before it
        // returns offers; the caller timeout must outlast that + the (large) transfer
        // or curl aborts mid-poll -> http=0/rows=0. Multi-city bodies are far bigger.
        $httpTimeout = $isMulti ? 120 : 90;
        foreach ($suppliers as $supplier) {
            try {
                $url = $base . '/modules/flights/' . \rawurlencode($supplier) . '/search';
                $response = \aiTripHttpPost($url, $body, false, $httpTimeout);
            } catch (\Throwable $e) {
                \error_log('[gia_flights] search ' . $supplier . ' threw: ' . $e->getMessage());
                continue;
            }
            $json = \is_array($response['json'] ?? null) ? $response['json'] : [];
            $rows = (isset($json[0]) && \is_array($json[0])) ? $json
                  : (\is_array($json['data'] ?? null) ? $json['data'] : []);
            // DIAGNOSTIC: so a live "no fares" names the supplier + row count + http,
            // instead of a blind guess (this is how the umrah 'no tiers' was cracked).
            \error_log('[gia_flights] search ' . $supplier . ' http=' . (int) ($response['http'] ?? 0)
                . ' rows=' . \count($rows));
            if ($rows === []) { continue; }

            foreach ($rows as $offer) {
                if (!\is_array($offer)) { continue; }
                // segments is an array of LEG groups: segments[0] = outbound, segments[1]
                // = return (round-trip package). Each group is [$flight]. Read the FULL
                // offer, not just segments[0][0] — the supplier already gives us stops,
                // duration, baggage, refundable, arrival time, seats and the return leg
                // (adivaha/search.php:334-385); GIA used to drop all of it.
                $firstSeg = $offer['segments'][0][0] ?? null;
                if (!\is_array($firstSeg)) { continue; }
                $returnSeg = $offer['segments'][1][0] ?? null; // round-trip inbound, if any
                $bookingData = \is_array($firstSeg['booking_data'] ?? null) ? $firstSeg['booking_data'] : [];
                $token = (string) ($bookingData['offer_id'] ?? ($bookingData['booking_token'] ?? ($bookingData['FareSourceCode'] ?? '')));
                if ($token === '') { continue; }

                $airline = (string) ($firstSeg['airline'] ?? '');
                $dep     = (string) ($firstSeg['departure_code'] ?? $firstSeg['departure_airport'] ?? '');
                $arr     = (string) ($firstSeg['arrival_code'] ?? $firstSeg['arrival_airport'] ?? '');
                $depTime = (string) ($firstSeg['departure_time'] ?? '');
                $price   = $firstSeg['price'] ?? ($bookingData['amount'] ?? null);
                if (\is_string($price)) { $price = (float) \str_replace(',', '', $price); }

                // Rich detail the supplier returns (previously discarded).
                $stops      = (int) ($firstSeg['stops'] ?? 0);
                $duration   = (string) ($firstSeg['duration_time'] ?? $firstSeg['total_duration'] ?? '');
                $arrTime    = (string) ($firstSeg['arrival_time'] ?? '');
                $cabin      = (string) ($firstSeg['class'] ?? $class);
                $baggage    = (string) ($firstSeg['baggage'] ?? '');
                $refundable = (string) ($firstSeg['refundable'] ?? '');
                $fareName   = (string) ($firstSeg['fare_name'] ?? '');
                $seatsLeft  = (int) ($firstSeg['seats_remaining'] ?? 0);
                $flightNo   = (string) ($firstSeg['flight_no'] ?? '');
                $isReturn   = \is_array($returnSeg);

                // FARE EXPIRY (critical for flights): suppliers set a hard expiry on
                // the offer/token (duffel revalidate.php:100 rejects an offer past
                // $offer['expires_at']). Capture it where present so GIA can tell the
                // traveller how long the price holds AND so the pre-payment / pre-issue
                // re-revalidation can fail fast instead of charging for a dead fare.
                $fareExpiresAt = '';
                foreach ([$offer['expires_at'] ?? null, $bookingData['expires_at'] ?? null, $firstSeg['expires_at'] ?? null] as $ex) {
                    if (\is_string($ex) && \trim($ex) !== '') { $fareExpiresAt = \trim($ex); break; }
                }

                $tripType = $isMulti ? 'multicity' : ($isReturn ? 'return' : 'oneway');

                if ($isMulti) {
                    // MULTI-CITY: title shows the WHOLE journey (A → B → C) and the
                    // detail/return_line enumerate each leg. Walk every leg group.
                    $legGroups = \is_array($offer['segments'] ?? null) ? $offer['segments'] : [];
                    $codes = []; $legLines = []; $totalStops = 0;
                    foreach ($legGroups as $li => $grp) {
                        $seg = \is_array($grp[0] ?? null) ? $grp[0] : null;
                        if ($seg === null) { continue; }
                        $lFrom = (string) ($seg['departure_code'] ?? $seg['departure_airport'] ?? '');
                        $lTo   = (string) ($seg['arrival_code'] ?? $seg['arrival_airport'] ?? '');
                        $lAir  = (string) ($seg['airline'] ?? '');
                        $lTime = (string) ($seg['departure_time'] ?? '');
                        $lStops = (int) ($seg['stops'] ?? 0);
                        $totalStops += $lStops;
                        if ($codes === [] && $lFrom !== '') { $codes[] = $lFrom; }
                        if ($lTo !== '') { $codes[] = $lTo; }
                        $legLines[] = \trim('Leg ' . ($li + 1) . ': ' . ($lAir !== '' ? $lAir . ' ' : '')
                            . ($lTime !== '' ? $lTime . ' ' : '') . $lFrom . ' → ' . $lTo
                            . ' · ' . ($lStops <= 0 ? 'Nonstop' : $lStops . ' stop' . ($lStops === 1 ? '' : 's')));
                    }
                    $route = \implode(' → ', $codes);
                    $label = \trim(($airline !== '' ? $airline : 'Multi-city') . ' · ' . $route);
                    $bits = [\count($legLines) . ' legs'];
                    if ($cabin !== '') { $bits[] = \ucwords(\str_replace('_', ' ', $cabin)); }
                    if ($refundable !== '') { $bits[] = $refundable; }
                    if ($seatsLeft > 0 && $seatsLeft <= 5) { $bits[] = 'only ' . $seatsLeft . ' left'; }
                    $detail = \implode(' · ', $bits);
                    $returnLine = \implode("\n", $legLines); // each leg on its own line
                } else {
                    // Put the DEPARTURE TIME in the title so otherwise-identical fares
                    // (same airline + route + price) are DISTINCT — otherwise the model
                    // can't tell which "first one" the traveller meant and loops back to
                    // search. e.g. "Air Peace 06:30 · LOS → ABV".
                    $label = \trim(($airline !== '' ? $airline : 'Flight')
                        . ($depTime !== '' ? ' ' . $depTime : '')
                        . ' · ' . $dep . ' → ' . $arr);

                    // Structured one-line summary the renderers show BELOW the title —
                    // stops, duration, cabin, baggage, scarcity — all real supplier data.
                    $bits = [];
                    $bits[] = $stops <= 0 ? 'Nonstop' : ($stops . ' stop' . ($stops === 1 ? '' : 's'));
                    if ($duration !== '') { $bits[] = $duration; }
                    if ($arrTime !== '') { $bits[] = 'arr ' . $arrTime; }
                    if ($isReturn) { $bits[] = 'round-trip'; }
                    if ($cabin !== '') { $bits[] = \ucwords(\str_replace('_', ' ', $cabin)); }
                    if ($refundable !== '') { $bits[] = $refundable; }
                    if ($baggage !== '' && \stripos($baggage, 'policy') === false) { $bits[] = $baggage . ' bag'; }
                    if ($seatsLeft > 0 && $seatsLeft <= 5) { $bits[] = 'only ' . $seatsLeft . ' left'; }
                    $detail = \implode(' · ', $bits);
                    // Return-leg one-liner (round-trip) so the traveller sees BOTH directions.
                    $returnLine = '';
                    if ($isReturn) {
                        $rAir = (string) ($returnSeg['airline'] ?? '');
                        $rDep = (string) ($returnSeg['departure_code'] ?? $returnSeg['departure_airport'] ?? $arr);
                        $rArr = (string) ($returnSeg['arrival_code'] ?? $returnSeg['arrival_airport'] ?? $dep);
                        $rTime = (string) ($returnSeg['departure_time'] ?? '');
                        $rStops = (int) ($returnSeg['stops'] ?? 0);
                        $returnLine = \trim('Return: ' . ($rAir !== '' ? $rAir . ' ' : '')
                            . ($rTime !== '' ? $rTime . ' ' : '') . $rDep . ' → ' . $rArr
                            . ' · ' . ($rStops <= 0 ? 'Nonstop' : $rStops . ' stop' . ($rStops === 1 ? '' : 's')));
                    }
                }

                $offers[] = [
                    'module'      => 'flights',
                    'kind'        => 'flights',
                    'supplier'    => $supplier,
                    // Stable ref the model/UI can pick; maps back to the cached offer.
                    'ref'         => \substr(\md5($supplier . '|' . $token), 0, 16),
                    'id'          => \substr(\md5($supplier . '|' . $token), 0, 16),
                    'name'        => $label,
                    'title'       => $label,
                    'flight_no'   => $flightNo,
                    'depart_time' => $depTime,
                    // subtitle = the structured detail line (stops/duration/cabin/bag/scarcity).
                    'subtitle'    => $detail !== '' ? $detail : \trim(($flightNo !== '' ? $flightNo . ' · ' : '') . \ucwords(\str_replace('_', ' ', $cabin))),
                    // Individual fields so each renderer can lay them out as it likes.
                    'stops'       => $stops,
                    'duration'    => $duration,
                    'arrival_time'=> $arrTime,
                    'cabin'       => $cabin,
                    'baggage'     => $baggage,
                    'refundable'  => $refundable,
                    'fare_name'   => $fareName,
                    'seats_left'  => $seatsLeft,
                    'trip_type'   => $tripType,
                    'return_line' => $returnLine,
                    'price'       => \is_numeric($price) ? \round((float) $price, 2) : null,
                    'currency'    => $currency,
                    'pax'         => ['adults' => $adults, 'children' => $children, 'infants' => $infants],
                    // Short-lived — the chosen fare MUST be revalidated right before pay.
                    'expires'     => true,
                    'fare_expires_at' => $fareExpiresAt, // supplier hard expiry when provided ('' = unknown)
                    // The WHOLE offer + token travel with the card so gia_flights_select
                    // can build the revalidation draft the shared engine expects.
                    'stable'      => [
                        'supplier'    => $supplier,
                        'raw'         => $offer,
                        'offer_token' => $token,
                    ],
                ];
                // Cap PER SUPPLIER so one chatty supplier can't crowd out the others,
                // but keep going to the next supplier (no global break).
                if (\count(\array_filter($offers, static fn($o) => $o['supplier'] === $supplier)) >= $limit) { break; }
            }
            // IMPORTANT: do NOT stop after the first supplier that has fares. Search
            // EVERY admin-enabled supplier and merge, so GIA shows the best across all
            // of them (and one supplier failing — e.g. http=0 — never zeroes the rest).
        }

        if ($offers === []) {
            return ['ok' => true, 'offers' => [], 'total_found' => 0, 'message' => $lastMsg];
        }
        // Merge across suppliers: cheapest first (priced offers ahead of unpriced),
        // then cap the combined list to $limit so the chat shows the best handful.
        \usort($offers, static function ($a, $b) {
            $pa = isset($a['price']) && \is_numeric($a['price']) ? (float) $a['price'] : \PHP_FLOAT_MAX;
            $pb = isset($b['price']) && \is_numeric($b['price']) ? (float) $b['price'] : \PHP_FLOAT_MAX;
            return $pa <=> $pb;
        });
        // DISCLOSE truncation (no silent caps): keep the real total so the chat can
        // say "showing the 8 cheapest of 23" and offer to refine — instead of
        // pretending the capped handful is everything.
        $totalFound = \count($offers);
        if ($totalFound > $limit) { $offers = \array_slice($offers, 0, $limit); }
        \error_log('[gia_flights] merged ' . $totalFound . ' offer(s) across ' . \count($suppliers)
            . ' supplier(s); showing ' . \count($offers));
        return ['ok' => true, 'offers' => $offers, 'total_found' => $totalFound];
    }
}

if (!function_exists('App\\lib\\ai\\gia_flights_select')) {
    /**
     * Price-lock a chosen fare by revalidating it with the supplier (fail-closed:
     * if the fare is gone, say so — never carry a stale/invented price into
     * booking). Thin wrapper over the SHARED aiTripRevalidatePackage, which the
     * website's reprice also uses. Takes the card's stable payload (raw offer +
     * supplier + token) produced by gia_flights_search.
     *
     * @param array $stable the offer card's 'stable' block (supplier, raw, offer_token)
     * @param array $pax    ['adults','children','infants']
     * @return array{ok:bool, price:float, currency:string, price_changed:bool,
     *                supplier:string, item:array, message?:string}
     */
    function gia_flights_select($db, array $stable, array $pax = [], string $currency = 'USD'): array
    {
        if (!gia_flights_boot() || !\function_exists('aiTripRevalidatePackage')) {
            return ['ok' => false, 'message' => 'Flight pricing is temporarily unavailable.'];
        }
        $supplier = \strtolower(\trim((string) ($stable['supplier'] ?? '')));
        $raw      = \is_array($stable['raw'] ?? null) ? $stable['raw'] : [];
        if ($supplier === '' || $raw === []) {
            return ['ok' => false, 'message' => 'Please pick a flight from the results first.'];
        }
        $currency = \strtoupper(\trim($currency)) ?: 'USD';

        // Build the draft item in the exact shape aiTripBuildFlightData expects
        // (item.raw = the whole supplier offer; supplier + token alongside).
        $item = [
            'module'   => 'flights',
            'kind'     => 'flights',
            'supplier' => $supplier,
            'price'    => 0.0, // reprice re-derives the authoritative price
            'currency' => $currency,
            'item'     => [
                'supplier'    => $supplier,
                'raw'         => $raw,
                'offer_token' => (string) ($stable['offer_token'] ?? ''),
            ],
        ];
        $draft = ['currency' => $currency, 'items' => [$item]];

        try {
            $res = \aiTripRevalidatePackage($db, $draft);
        } catch (\Throwable $e) {
            \error_log('[gia_flights] select revalidate threw: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not re-check that fare. Please try again.'];
        }

        if (empty($res['status'])) {
            return ['ok' => false,
                'message' => (string) ($res['message'] ?? 'That fare is no longer available. Please search again.')];
        }
        // The revalidated item (with refreshed token/price) is draft.items[0].
        $revItem = \is_array($draft['items'][0] ?? null) ? $draft['items'][0] : $item;
        $newPrice = (float) ($res['new_total'] ?? ($revItem['price'] ?? 0));

        return [
            'ok'            => true,
            'supplier'      => $supplier,
            'price'         => \round($newPrice, 2),
            'currency'      => (string) ($res['currency'] ?? $currency),
            'price_changed' => !empty($res['price_changed']),
            'item'          => $revItem, // carry forward into gia_flights_book
        ];
    }
}

if (!function_exists('App\\lib\\ai\\gia_flights_pax_fields')) {
    /**
     * The passenger fields GIA must collect for ONE ticket, as a per-supplier set
     * (owner: "each ticket has the kind of information to be collected"). The base
     * set is what every airline needs to name a ticket; some suppliers/routes also
     * require passport identity. We DON'T hardcode one global set — we return the
     * right set for the supplier (and international routes). Verified against the
     * duffel issue contract (modules/flights/duffel/actions/issue.php:342-384:
     * title, given_name/first_name, family_name/last_name, born_on/dob, gender,
     * email, phone — passport NOT required by duffel; other suppliers may need it).
     *
     * @return array{required:array<int,string>, optional:array<int,string>}
     */
    function gia_flights_pax_fields(string $supplier, bool $international = true): array
    {
        $supplier = \strtolower(\trim($supplier));
        // Base set every supplier needs to issue a named ticket.
        $required = ['title', 'first_name', 'last_name', 'dob'];
        $optional = ['email', 'phone'];

        // Suppliers that require full passport identity (GDS / international LCC).
        // Conservative: when unsure for an international route, collect passport so a
        // ticket can't fail to issue for missing docs. duffel does NOT require it.
        $passportSuppliers = ['amadeus', 'amadeus_enterprise', 'sabre', 'travelport', 'mystifly', 'pkfare', 'tbo', 'seeru'];
        if ($international && \in_array($supplier, $passportSuppliers, true)) {
            $required[] = 'nationality';
            $required[] = 'passport_number';
            $required[] = 'passport_expiry';
        }
        return ['required' => $required, 'optional' => $optional];
    }
}

if (!function_exists('App\\lib\\ai\\gia_flights_find_held')) {
    /**
     * Double-book guard. Return a book-shaped result for an EXISTING unpaid flights
     * booking THIS conversation is already holding, or null if none.
     *
     * The rule is deliberately NOT "same offer_token": the model's double-call often
     * RE-SEARCHES on a trailing "yes", so the supplier hands back the same seat under
     * a FRESH token — token-exact matching misses it. A single chat should hold at
     * most ONE unpaid flight at a time: during one pick->confirm flow there is exactly
     * one intended seat. So we match on gia_conv_key (ownership) + unpaid + a recent
     * created_at (within the longest plausible pay window) and ignore the token. Once
     * the old hold is paid, cancelled, or aged out, a genuinely new booking proceeds.
     *
     * @return array|null book-shaped array (invoice_id/supplier/amount/...) or null
     */
    function gia_flights_find_held($db, string $convKey, string $offerToken): ?array
    {
        if ($convKey === '') { return null; }
        $freshAfter = \date('Y-m-d H:i:s', \time() - 60 * 60); // ignore holds older than 60 min
        try {
            $rows = $db->select('bookings',
                ['invoice_id', 'price_markup', 'currency_markup', 'adults', 'childs', 'infants', 'booking_data', 'created_at'],
                [
                    'module_type' => 'ai_trip',
                    'payment_status[!]' => 'paid',
                    'booking_status[!]' => 'cancelled',
                    'created_at[>=]' => $freshAfter,
                    'ORDER' => ['id' => 'DESC'],
                    'LIMIT' => 10,
                ]
            );
        } catch (\Throwable $e) { return null; }
        if (!\is_array($rows)) { return null; }
        foreach ($rows as $r) {
            $bd = \json_decode((string) ($r['booking_data'] ?? ''), true);
            if (!\is_array($bd)) { continue; }
            if (\strtolower((string) ($bd['gia_service'] ?? '')) !== 'flights') { continue; }
            $bdConv = (string) ($bd['gia_conv_key'] ?? '');
            if ($bdConv === '' || !\hash_equals($bdConv, $convKey)) { continue; }
            $amount   = (float) ($r['price_markup'] ?? 0);
            $currency = (string) ($r['currency_markup'] ?? 'USD');
            return [
                'invoice_id'     => (string) ($r['invoice_id'] ?? ''),
                'supplier'       => (string) ($bd['supplier'] ?? ''),
                'module'         => 'flights',
                'amount'         => $amount,
                'amount_due_now' => $amount,
                'currency'       => $currency,
                'pax'            => [
                    'adults'   => (int) ($r['adults'] ?? 1),
                    'children' => (int) ($r['childs'] ?? 0),
                    'infants'  => (int) ($r['infants'] ?? 0),
                ],
                'pax_total'      => (int) ($r['adults'] ?? 1) + (int) ($r['childs'] ?? 0) + (int) ($r['infants'] ?? 0),
                'price_changed'  => false,
            ];
        }
        return null;
    }
}

if (!function_exists('App\\lib\\ai\\gia_flights_book')) {
    /**
     * Create a HELD, UNPAID flight booking from a PRICE-LOCKED select result, in the
     * exact `ai_trip` shape the proven issue path + payment callback already handle
     * (module_type='ai_trip', AITP invoice, payable_total in booking_data, the fare
     * payload in booking_data so modules/ai_trip/ai_trip/issue can read the token).
     * Passengers are added afterwards (gia_flights_add_passenger) BEFORE payment —
     * the manifest must be complete before the fare is paid/issued.
     *
     * Re-price-locks RIGHT HERE (fresh gia_flights_select) so a fare that expired
     * between the user's pick and this call fails NOW (before any money), never at
     * issue time. (Flights carry a short supplier expiry — see fare_expires_at.)
     *
     * @param array $stable the chosen offer card's 'stable' block (supplier, raw, offer_token)
     * @param array $pax    ['adults','children','infants']
     * @param array $lead   ['first_name','last_name','email','phone','user_id'?]
     * @return array ok + invoice_id/supplier/amount/currency/pax/needs_travellers, or ['ok'=>false,message]
     */
    function gia_flights_book($db, array $stable, array $pax, array $lead, string $currency = 'USD'): array
    {
        if (!gia_flights_boot()) {
            return ['ok' => false, 'message' => 'Flight booking is temporarily unavailable.'];
        }
        $adults   = \max(1, (int) ($pax['adults'] ?? 1));
        $children = \max(0, (int) ($pax['children'] ?? 0));
        $infants  = \max(0, (int) ($pax['infants'] ?? 0));

        // 0) IDEMPOTENCY — don't double-book. The model may call flights_book twice for
        //    the same pick (once on the choice, again on a trailing "yes") — often after
        //    RE-SEARCHING, so the fare returns under a fresh token. If THIS conversation
        //    already holds a recent unpaid flights booking, return that one instead of
        //    creating a second. Checked BEFORE the (expensive, ~40s) re-price-lock.
        $convKey = (string) ($lead['conv_key'] ?? '');
        if ($convKey !== '') {
            $existing = gia_flights_find_held($db, $convKey, (string) ($stable['offer_token'] ?? ''));
            if ($existing !== null) {
                if (\function_exists('gia_trace')) {
                    \gia_trace('flights_book_reused', __FILE__, 'conv held ' . (string) ($existing['invoice_id'] ?? '') . ' — skipped 2nd book');
                }
                return $existing + ['ok' => true, 'reused' => true, 'needs_travellers' => true];
            }
        }

        // 1) RE-PRICE-LOCK now (fail-closed). Catches a fare that expired since pick,
        //    so we never create a booking the supplier can no longer honour.
        $sel = gia_flights_select($db, $stable, $pax, $currency);
        if (empty($sel['ok'])) {
            return ['ok' => false, 'message' => (string) ($sel['message'] ?? 'That fare is no longer available. Please search again.')];
        }
        $supplier = (string) $sel['supplier'];
        $amount   = (float) $sel['price'];
        $currency = (string) ($sel['currency'] ?? $currency);
        if ($amount <= 0) {
            return ['ok' => false, 'message' => 'That fare could not be priced. Please search again.'];
        }
        $item = \is_array($sel['item'] ?? null) ? $sel['item'] : [];
        $raw  = \is_array($item['item']['raw'] ?? null) ? $item['item']['raw'] : (\is_array($stable['raw'] ?? null) ? $stable['raw'] : []);
        $offerToken = (string) ($item['item']['offer_token'] ?? ($stable['offer_token'] ?? ''));

        // 2) Build the AITP invoice + ai_trip booking_data (cart convention).
        try { $invoiceId = 'AITP' . \strtoupper(\bin2hex(\random_bytes(5))); }
        catch (\Throwable $e) { $invoiceId = 'AITP' . \strtoupper(\substr(\md5((string) \mt_rand()), 0, 10)); }

        $firstName = (string) ($lead['first_name'] ?? '');
        $lastName  = (string) ($lead['last_name'] ?? '');
        $email     = (string) ($lead['email'] ?? '');
        $phone     = (string) ($lead['phone'] ?? '');
        $userId    = (string) ($lead['user_id'] ?? '');

        // GA3: fill blank lead contact from the signed-in user's own record so a
        // returning customer isn't re-asked for email/phone already on file.
        if ($userId !== '' && ($email === '' || $phone === '')) {
            if (!function_exists('gia_contact_prefill')) { require_once __DIR__ . '/gia_travellers.php'; }
            if (function_exists('gia_contact_prefill')) { [$email, $phone] = gia_contact_prefill($db, $userId, $email, $phone); }
        }

        // The issue path reads the fare token from booking_data (several shapes —
        // issue.php:170-184). Store the whole revalidated offer so the token GIA
        // issues with is the FRESH one from the re-price-lock, not the stale search one.
        $bookingData = [
            'source'           => 'gia_chat',
            'gia_service'      => 'flights',
            'gia_conv_key'     => (string) ($lead['conv_key'] ?? ''), // ownership anchor for the pay gate
            'package_id'       => $invoiceId,
            'supplier'         => $supplier,
            'items'            => [[
                'module'   => 'flights',
                'kind'     => 'flights',
                'supplier' => $supplier,
                'price'    => $amount,
                'currency' => $currency,
                'item'     => ['supplier' => $supplier, 'raw' => $raw, 'offer_token' => $offerToken],
            ]],
            'items_count'      => 1,
            'payable_total'    => $amount,
            'final_total'      => $amount,
            'display_total'    => $amount,
            'base_currency'    => $currency,
            'display_currency' => $currency,
            // Also surface the fare payload at the top level for the flights issue
            // reader (it probes booking_data.booking_data / .segments / .flight_data).
            'flight_data'      => $raw,
            'booking_data'     => \is_array($raw['segments'][0][0]['booking_data'] ?? null) ? $raw['segments'][0][0]['booking_data'] : [],
            'segments'         => $raw['segments'] ?? null,
            'guest'            => ['first_name' => $firstName, 'last_name' => $lastName, 'email' => $email, 'phone' => $phone],
        ];

        try {
            $ok = $db->insert('bookings', [
                'invoice_id'        => $invoiceId,
                'language'          => \function_exists('getCurrentLanguage') ? \getCurrentLanguage() : 'en',
                'booking_status'    => 'pending',
                'payment_status'    => 'unpaid',
                'price_original'    => $amount,
                'price_markup'      => $amount,
                'agent_earning'     => '0',
                'tax'               => '0',
                'first_name'        => $firstName,
                'last_name'         => $lastName,
                'email'             => $email,
                'phone'             => $phone,
                'adults'            => (string) $adults,
                'childs'            => (string) $children,
                'infants'           => (string) $infants,
                'child_ages'        => '[]',
                'currency_markup'   => $currency,
                'booking_data'      => \json_encode($bookingData),
                'transaction_id'    => '',
                'user_id'           => $userId,
                // travellers filled by gia_flights_add_passenger as adult_N/child_N/infant_N
                'travellers'        => \json_encode(['primary_guest' => $bookingData['guest']]),
                'nationality'       => '',
                'payment_gateway'   => '',
                // CRITICAL: module_type/module = ai_trip so the EXISTING issue path
                // (modules/ai_trip/ai_trip/issue) + payment callback auto-issue +
                // _auto_refund_on_issue_failure all apply unchanged.
                'module_type'       => 'ai_trip',
                'module'            => 'ai_trip',
                'pnr'               => '',
                'booking_date'      => \date('Y-m-d H:i:s'),
                'created_at'        => \date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            \error_log('[gia_flights] book insert: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not create the flight booking. Please try again.'];
        }
        if (!$ok) {
            return ['ok' => false, 'message' => 'Could not create the flight booking. Please try again.'];
        }

        return [
            'ok'               => true,
            'invoice_id'       => $invoiceId,
            'supplier'         => $supplier,
            'module'           => 'flights',
            'amount'           => $amount,
            'amount_due_now'   => $amount, // flights pay in full (no installments)
            'currency'         => $currency,
            'pax'              => ['adults' => $adults, 'children' => $children, 'infants' => $infants],
            'pax_total'        => $adults + $children + $infants,
            'price_changed'    => !empty($sel['price_changed']),
            'needs_travellers' => true, // collect every passenger BEFORE payment
        ];
    }
}

if (!function_exists('App\\lib\\ai\\gia_flights_add_passenger')) {
    /**
     * Save ONE passenger onto a held flight booking's travellers map, keyed
     * adult_N / child_N / infant_N — the EXACT flat shape the flight issue readers
     * expect (modules/ai_trip/ai_trip/issue.php:410 "flat adult_0/child_0 keys").
     * The first adult is also mirrored to the booking's primary contact. Returns the
     * updated counts so the caller knows when the manifest is complete.
     *
     * @param array $p ['type'=>adult|child|infant, 'title','first_name','last_name',
     *                  'dob','gender'?,'nationality'?,'passport_number'?,'passport_expiry'?,'email'?,'phone'?]
     * @return array{ok:bool, collected:int, pax_total:int, done:bool, message?:string}
     */
    function gia_flights_add_passenger($db, string $invoiceId, array $p): array
    {
        $b = $db->get('bookings', ['adults', 'childs', 'infants', 'travellers', 'email', 'phone'],
            ['invoice_id' => $invoiceId, 'module_type' => 'ai_trip']);
        if (!\is_array($b)) {
            return ['ok' => false, 'collected' => 0, 'pax_total' => 0, 'done' => false, 'message' => 'That flight booking was not found.'];
        }
        $need = ['adult' => \max(1, (int) $b['adults']), 'child' => \max(0, (int) $b['childs']), 'infant' => \max(0, (int) $b['infants'])];
        $paxTotal = $need['adult'] + $need['child'] + $need['infant'];

        $trav = \json_decode((string) ($b['travellers'] ?? ''), true);
        if (!\is_array($trav)) { $trav = []; }

        $type = \strtolower(\trim((string) ($p['type'] ?? 'adult')));
        if (!\in_array($type, ['adult', 'child', 'infant'], true)) { $type = 'adult'; }
        // Next free index for this type (count existing adult_/child_/infant_ keys).
        $idx = 0;
        foreach ($trav as $k => $_) { if (\is_string($k) && \strpos($k, $type . '_') === 0) { $idx++; } }
        if ($idx >= $need[$type]) {
            return ['ok' => false, 'collected' => gia_flights_collected($trav), 'pax_total' => $paxTotal, 'done' => false,
                'message' => 'All ' . $type . ' passengers are already added.'];
        }

        $title = \strtolower(\trim((string) ($p['title'] ?? '')));
        if (!\in_array($title, ['mr', 'ms', 'mrs', 'miss', 'dr'], true)) { $title = ''; }
        $gender = \strtolower(\trim((string) ($p['gender'] ?? '')));
        if ($gender === '' && $title !== '') { $gender = \in_array($title, ['mrs', 'ms', 'miss'], true) ? 'f' : 'm'; }

        $row = \array_filter([
            'type'            => $type,
            'title'           => $title,
            'first_name'      => \trim((string) ($p['first_name'] ?? '')),
            'last_name'       => \trim((string) ($p['last_name'] ?? '')),
            'dob'             => \trim((string) ($p['dob'] ?? ($p['date_of_birth'] ?? ''))),
            'gender'          => $gender,
            'nationality'     => \trim((string) ($p['nationality'] ?? '')),
            'passport_number' => \trim((string) ($p['passport_number'] ?? '')),
            'passport_expiry' => \trim((string) ($p['passport_expiry'] ?? ($p['expiry_date'] ?? ''))),
            'email'           => \trim((string) ($p['email'] ?? '')) ?: (string) ($b['email'] ?? ''),
            'phone'           => \trim((string) ($p['phone'] ?? '')) ?: (string) ($b['phone'] ?? ''),
        ], static fn($v) => $v !== '' && $v !== null);

        $trav[$type . '_' . $idx] = $row;
        try {
            $db->update('bookings', ['travellers' => \json_encode($trav)], ['invoice_id' => $invoiceId]);
        } catch (\Throwable $e) {
            \error_log('[gia_flights] add_passenger: ' . $e->getMessage());
            return ['ok' => false, 'collected' => gia_flights_collected($trav) - 1, 'pax_total' => $paxTotal, 'done' => false, 'message' => 'Could not save that passenger. Please try again.'];
        }
        $collected = gia_flights_collected($trav);
        return ['ok' => true, 'collected' => $collected, 'pax_total' => $paxTotal, 'done' => $collected >= $paxTotal];
    }
}

if (!function_exists('App\\lib\\ai\\gia_flights_collected')) {
    /** Count real passenger rows (adult_/child_/infant_ keys) in a travellers map. */
    function gia_flights_collected(array $trav): int
    {
        $n = 0;
        foreach ($trav as $k => $_) {
            if (\is_string($k) && \preg_match('/^(adult|child|infant)_\d+$/', $k)) { $n++; }
        }
        return $n;
    }
}

if (!function_exists('App\\lib\\ai\\gia_flights_manifest_complete')) {
    /** True when every seat on the booking has a passenger row. Authoritative gate before pay. */
    function gia_flights_manifest_complete($db, string $invoiceId): bool
    {
        $b = $db->get('bookings', ['adults', 'childs', 'infants', 'travellers'],
            ['invoice_id' => $invoiceId, 'module_type' => 'ai_trip']);
        if (!\is_array($b)) { return false; }
        $need = \max(1, (int) $b['adults']) + \max(0, (int) $b['childs']) + \max(0, (int) $b['infants']);
        $trav = \json_decode((string) ($b['travellers'] ?? ''), true);
        return \is_array($trav) && gia_flights_collected($trav) >= $need;
    }
}

if (!function_exists('App\\lib\\ai\\gia_flights_missing_required')) {
    /**
     * Which required fields are missing for a passenger, given the supplier's field
     * set (gia_flights_pax_fields). Lets the chat ask precisely for what's needed.
     * @return array<int,string> human labels of missing fields
     */
    function gia_flights_missing_required(string $supplier, array $p, bool $international = true): array
    {
        $set = gia_flights_pax_fields($supplier, $international);
        $labels = [
            'title' => 'title (Mr/Ms/Mrs)', 'first_name' => 'first name', 'last_name' => 'last name',
            'dob' => 'date of birth', 'nationality' => 'nationality',
            'passport_number' => 'passport number', 'passport_expiry' => 'passport expiry',
        ];
        $missing = [];
        foreach ($set['required'] as $f) {
            $v = \trim((string) ($p[$f] ?? ($f === 'dob' ? ($p['date_of_birth'] ?? '') : ($f === 'passport_expiry' ? ($p['expiry_date'] ?? '') : ''))));
            if ($v === '') { $missing[] = $labels[$f] ?? $f; }
        }
        return $missing;
    }
}

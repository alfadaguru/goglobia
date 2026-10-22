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

        $origin      = \strtoupper(\trim((string) ($opts['origin'] ?? '')));
        $destination = \strtoupper(\trim((string) ($opts['destination'] ?? '')));
        $departDate  = \trim((string) ($opts['departure_date'] ?? ''));
        $returnDate  = \trim((string) ($opts['return_date'] ?? ''));
        $type        = \strtolower(\trim((string) ($opts['type'] ?? ''))) ?: ($returnDate !== '' ? 'return' : 'oneway');
        $class       = \strtolower(\trim((string) ($opts['class'] ?? 'economy'))) ?: 'economy';
        $adults      = \max(1, (int) ($opts['adults'] ?? 1));
        $children    = \max(0, (int) ($opts['children'] ?? 0));
        $infants     = \max(0, (int) ($opts['infants'] ?? 0));
        $currency    = \strtoupper(\trim((string) ($opts['currency'] ?? ''))) ?: 'USD';
        $limit       = (int) ($opts['limit'] ?? 6); if ($limit <= 0) { $limit = 6; }

        // HARD requirement — never guess a route or a date (the web-coupling that
        // broke umrah). If any is missing, ask; do not invent one.
        $missing = [];
        if ($origin === '')      { $missing[] = 'departure city/airport'; }
        if ($destination === '') { $missing[] = 'destination'; }
        if ($departDate === '')  { $missing[] = 'departure date'; }
        if ($missing !== []) {
            return ['ok' => false, 'offers' => [], 'need' => $missing,
                'message' => 'I need the ' . \implode(', ', $missing) . ' to search flights.'];
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

        // Try each active supplier until one returns fares (supplier-agnostic).
        $offers = [];
        $lastMsg = 'No flights found for that route and date.';
        $base = \defined('root') ? \rtrim((string) \root, '/') : '';
        foreach ($suppliers as $supplier) {
            try {
                $url = $base . '/modules/flights/' . \rawurlencode($supplier) . '/search';
                $response = \aiTripHttpPost($url, $body, false, 45);
            } catch (\Throwable $e) {
                \error_log('[gia_flights] search ' . $supplier . ' threw: ' . $e->getMessage());
                continue;
            }
            $json = \is_array($response['json'] ?? null) ? $response['json'] : [];
            $rows = (isset($json[0]) && \is_array($json[0])) ? $json
                  : (\is_array($json['data'] ?? null) ? $json['data'] : []);
            if ($rows === []) { continue; }

            foreach ($rows as $offer) {
                if (!\is_array($offer)) { continue; }
                $firstSeg = $offer['segments'][0][0] ?? null;
                if (!\is_array($firstSeg)) { continue; }
                $bookingData = \is_array($firstSeg['booking_data'] ?? null) ? $firstSeg['booking_data'] : [];
                $token = (string) ($bookingData['offer_id'] ?? ($bookingData['booking_token'] ?? ($bookingData['FareSourceCode'] ?? '')));
                if ($token === '') { continue; }

                $airline = (string) ($firstSeg['airline'] ?? '');
                $dep     = (string) ($firstSeg['departure_code'] ?? $origin);
                $arr     = (string) ($firstSeg['arrival_code'] ?? $destination);
                $depTime = (string) ($firstSeg['departure_time'] ?? '');
                $price   = $firstSeg['price'] ?? ($bookingData['amount'] ?? null);
                if (\is_string($price)) { $price = (float) \str_replace(',', '', $price); }

                // FARE EXPIRY (critical for flights): suppliers set a hard expiry on
                // the offer/token (duffel revalidate.php:100 rejects an offer past
                // $offer['expires_at']). Capture it where present so GIA can tell the
                // traveller how long the price holds AND so the pre-payment / pre-issue
                // re-revalidation can fail fast instead of charging for a dead fare.
                $fareExpiresAt = '';
                foreach ([$offer['expires_at'] ?? null, $bookingData['expires_at'] ?? null, $firstSeg['expires_at'] ?? null] as $ex) {
                    if (\is_string($ex) && \trim($ex) !== '') { $fareExpiresAt = \trim($ex); break; }
                }

                $label = \trim(($airline !== '' ? $airline : 'Flight') . ' · ' . $dep . ' → ' . $arr);
                $offers[] = [
                    'module'      => 'flights',
                    'kind'        => 'flights',
                    'supplier'    => $supplier,
                    // Stable ref the model/UI can pick; maps back to the cached offer.
                    'ref'         => \substr(\md5($supplier . '|' . $token), 0, 16),
                    'id'          => \substr(\md5($supplier . '|' . $token), 0, 16),
                    'name'        => $label,
                    'title'       => $label,
                    'subtitle'    => \trim(($depTime !== '' ? 'Departs ' . $depTime : '') . ' · ' . \strtoupper($class)),
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
                if (\count($offers) >= $limit) { break; }
            }
            if ($offers !== []) { break; } // got fares from this supplier; stop.
        }

        if ($offers === []) {
            return ['ok' => true, 'offers' => [], 'message' => $lastMsg];
        }
        return ['ok' => true, 'offers' => $offers];
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

        // 2) Build the AITP invoice + ai_trip booking_data (cart convention).
        try { $invoiceId = 'AITP' . \strtoupper(\bin2hex(\random_bytes(5))); }
        catch (\Throwable $e) { $invoiceId = 'AITP' . \strtoupper(\substr(\md5((string) \mt_rand()), 0, 10)); }

        $firstName = (string) ($lead['first_name'] ?? '');
        $lastName  = (string) ($lead['last_name'] ?? '');
        $email     = (string) ($lead['email'] ?? '');
        $phone     = (string) ($lead['phone'] ?? '');
        $userId    = (string) ($lead['user_id'] ?? '');

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
                'item'     => ['supplier' => $supplier, 'raw' => $raw, 'offer_token' => (string) ($item['item']['offer_token'] ?? ($stable['offer_token'] ?? ''))],
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

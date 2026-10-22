<?php
// path: modules/flights/adivaha/search.php
// Adivaha B2C Flights — search (async → sync bridge) + offer normalisation.
// POST flights/adivaha/search
//
// Adivaha search is ASYNCHRONOUS: POST /flights/search returns a trace_id, then
// you POLL GET /flights/search-poll?trace_id=... until done==true. The rest of
// this platform's flight pipeline is synchronous (one POST returns the offers
// array), so this file hides the async-ness: it starts the search, polls
// server-side until done (or a safety deadline), then returns the normalised
// offers array in the SAME shape every other provider emits (array of offers,
// each with a `segments` array of legs, each leg an array of flight objects
// carrying a nested booking_data with the opaque offer id for revalidate/issue).
//
// Round trips: the platform's booking pipeline is SINGLE-TOKEN end to end — the
// review page revalidates one offer_id, and the price the customer pays is that
// one offer's price. So a round trip is only emitted as a combined trip when
// Adivaha returns ONE offer that already carries BOTH sectors — a package/combined
// offer (meta.is_package / leg_count >= 2; one offer_id books the whole journey,
// spec L44-46). Adivaha "2x" single-sector offers (leg_count == 1, separate
// outbound/return offer_ids) are each surfaced as their OWN one-way offer, priced
// and revalidated correctly on their own; we do NOT synthesise a two-token
// combined round trip from them (the platform cannot re-price/settle two tokens
// as one booking — attempting it undercharged the customer). True combined 2x
// round trips are a future phase needing real multi-token platform support.

if (!function_exists('adivaha_getduration_from_minutes')) {
    /** "Hh Mm" minutes → "H:MM" display, matching the platform's duration fields. */
    function adivaha_getduration_from_minutes($minutes)
    {
        $minutes = (int) $minutes;
        if ($minutes <= 0) return '';
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $h . ':' . str_pad((string) $m, 2, '0', STR_PAD_LEFT);
    }
}

$router->post('flights/adivaha/search', function () use ($db) {

    // search_guard: release the session lock before the (potentially long) async
    // poll so other requests are not blocked; raise the time limit to cover it.
    @set_time_limit(120);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
        error_log('adivaha search_guard: session lock released');
    }
    if (connection_aborted()) {
        error_log('adivaha search_guard: request aborted early');
        exit;
    }

    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json');

    try {

    $module = $db->get('modules', '*', ['name' => 'adivaha', 'type' => 'flights']);
    if (!$module) {
        echo json_encode(['status' => false, 'message' => 'Adivaha module not configured', 'response' => []]);
        exit;
    }

    $credentials = getAdivahaCredentials($db);
    if (!$credentials || $credentials['api_key'] === '') {
        echo json_encode(['status' => false, 'message' => 'Adivaha API key not configured. Configure the module in admin.', 'response' => []]);
        exit;
    }

    $bearer = adivahaBearer($db, $credentials);
    if ($bearer === '') {
        echo json_encode(['status' => false, 'message' => 'Adivaha authentication unavailable.', 'response' => []]);
        exit;
    }

    $currency = strtoupper($_POST['currency'] ?? 'USD');
    $type     = $_POST['type'] ?? ($_SESSION['flight_type'] ?? 'oneway');

    // ---- Validate inputs (same contract as every other flight provider) -----
    if ($type === 'multicity') {
        if (isset($_POST['routes']) && trim($_POST['routes']) !== '') {
            $routes = json_decode($_POST['routes'], true);
        } elseif (!empty($_SESSION['multicity_routes'])) {
            $routes = $_SESSION['multicity_routes'];
        } else {
            echo json_encode(['status' => false, 'message' => 'routes - param or value missing for multicity search']);
            exit;
        }
        if (empty($routes) || !is_array($routes)) {
            echo json_encode(['status' => false, 'message' => 'routes list cannot be empty']);
            exit;
        }
    } else {
        foreach (['origin', 'destination', 'departure_date'] as $req) {
            if (!isset($_POST[$req]) || trim((string) $_POST[$req]) === '') {
                echo json_encode(['status' => false, 'message' => $req . ' - param or value missing']);
                exit;
            }
        }
        if ($type !== 'oneway' && (!isset($_POST['return_date']) || trim((string) $_POST['return_date']) === '')) {
            echo json_encode(['status' => false, 'message' => 'return_date - param or value missing for round trip']);
            exit;
        }
    }
    foreach (['adults', 'childrens', 'infants', 'currency', 'type', 'class'] as $req) {
        if (!isset($_POST[$req]) || trim((string) $_POST[$req]) === '') {
            echo json_encode(['status' => false, 'message' => $req . ' - param or value missing']);
            exit;
        }
    }

    $adults   = max(0, (int) $_POST['adults']);
    $children = max(0, (int) $_POST['childrens']);
    $infants  = max(0, (int) $_POST['infants']);

    // Guard against same origin & destination (Adivaha certification negative case).
    if ($type !== 'multicity') {
        if (strtoupper(trim((string) $_POST['origin'])) === strtoupper(trim((string) $_POST['destination']))) {
            echo json_encode(['status' => false, 'message' => 'Origin and destination cannot be the same.']);
            exit;
        }
    }

    // ---- Map the platform cabin class to Adivaha's cabin_class enum ----------
    // Adivaha: all | economy | premium_economy | business | first (spec L769).
    $cabinMap = [
        'economy'          => 'economy',
        'premium_economy'  => 'premium_economy',
        'premiumeconomy'   => 'premium_economy',
        'premium'          => 'premium_economy',
        'business'         => 'business',
        'first'            => 'first',
        'all'              => 'all',
    ];
    $rawClass = strtolower(trim((string) $_POST['class']));
    $cabinClass = $cabinMap[$rawClass] ?? 'economy';

    // ---- Build the Adivaha SearchRequest body --------------------------------
    // trip_type: oneway | round | multi (spec L748-751; accepts aliases). The
    // platform sends 'oneway' | 'return' | 'multicity'.
    $tripType = $type === 'multicity' ? 'multi' : ($type === 'oneway' ? 'oneway' : 'round');

    $searchBody = [
        'trip_type'   => $tripType,
        'cabin_class' => $cabinClass,
        'adults'      => max(1, $adults),
        'children'    => $children,
        'infants'     => $infants,
    ];

    if ($type === 'multicity') {
        $segments = [];
        foreach ($routes as $i => $route) {
            // Validate EACH route — a missing date would otherwise become
            // strtotime('')===false -> date(...,false) == today, silently
            // searching the wrong day; a missing/invalid O-D would 400 at Adivaha.
            $rFrom = strtoupper(trim((string) ($route['from'] ?? '')));
            $rTo   = strtoupper(trim((string) ($route['to'] ?? '')));
            $rDateRaw = trim((string) ($route['date'] ?? ''));
            $rTs = $rDateRaw !== '' ? strtotime($rDateRaw) : false;
            if ($rFrom === '' || $rTo === '' || $rTs === false) {
                echo json_encode(['status' => false, 'message' => 'Multicity route ' . ($i + 1) . ' is missing a valid origin, destination or date.']);
                exit;
            }
            if ($rFrom === $rTo) {
                echo json_encode(['status' => false, 'message' => 'Multicity route ' . ($i + 1) . ': origin and destination cannot be the same.']);
                exit;
            }
            $segments[] = [
                'origin'      => $rFrom,
                'destination' => $rTo,
                'depart_date' => date('Y-m-d', $rTs),
            ];
        }
        if (count($segments) < 2) {
            echo json_encode(['status' => false, 'message' => 'Multicity search needs at least 2 valid segments.']);
            exit;
        }
        $searchBody['segments'] = $segments;
    } else {
        $searchBody['origin']      = strtoupper(trim((string) $_POST['origin']));
        $searchBody['destination'] = strtoupper(trim((string) $_POST['destination']));
        $searchBody['depart_date'] = date('Y-m-d', strtotime((string) $_POST['departure_date']));
        if ($type !== 'oneway') {
            // Send only the four criteria; Adivaha derives the return leg and
            // returns BOTH directions (spec L28-53). Do NOT send `segments`.
            $searchBody['return_date'] = date('Y-m-d', strtotime((string) $_POST['return_date']));
        }
    }

    $base = $credentials['base_url'];

    // ---- Step 1: POST /flights/search → trace_id -----------------------------
    $startRes = adivahaApiRequest('POST', $base . '/flights/search', $searchBody, $bearer, [], 45);
    adivahaLog('search/start', $searchBody, $startRes['raw'], $startRes['http_code'], $startRes['curl_error']);

    // A 401 can mean an expired token — retry once with a freshly minted one.
    if ($startRes['http_code'] === 401 && $credentials['partner_id'] !== '') {
        $bearer = adivahaBearer($db, $credentials, true);
        $startRes = adivahaApiRequest('POST', $base . '/flights/search', $searchBody, $bearer, [], 45);
        adivahaLog('search/start-retry', $searchBody, $startRes['raw'], $startRes['http_code'], $startRes['curl_error']);
    }

    if (!$startRes['success'] || empty($startRes['data']['trace_id'])) {
        echo json_encode([
            'status'  => false,
            'message' => 'Adivaha search could not be started: ' . adivahaExtractError($startRes),
            'response' => [],
        ]);
        exit;
    }

    $traceId = (string) $startRes['data']['trace_id'];

    // ---- Step 2: poll GET /flights/search-poll until done (safety deadline) --
    // Trace is valid ~15 min; we bound polling well under the request timeout.
    $pollUrl = $base . '/flights/search-poll?trace_id=' . urlencode($traceId)
        . '&display_currency=' . urlencode($currency);

    $deadline  = microtime(true) + 70;  // hard cap; the front-end also re-queries
    $done      = false;
    $pollData  = null;
    $attempts  = 0;

    while (microtime(true) < $deadline) {
        if (connection_aborted()) { exit; }
        $attempts++;
        $pollRes = adivahaApiRequest('GET', $pollUrl, null, $bearer, [], 25);
        if ($pollRes['success'] && is_array($pollRes['data'])) {
            $pollData = $pollRes['data'];
            if (!empty($pollData['done'])) { $done = true; break; }
        } else {
            adivahaLog('search/poll-error', ['trace_id' => $traceId, 'attempt' => $attempts], $pollRes['raw'], $pollRes['http_code'], $pollRes['curl_error']);
            // Transient poll failure: brief backoff, keep trying until the deadline.
        }
        usleep(1200000); // 1.2s between polls
    }

    adivahaLog('search/poll-final', ['trace_id' => $traceId, 'attempts' => $attempts, 'done' => $done], isset($pollData['total_results']) ? ('results=' . $pollData['total_results']) : 'none', 200, null);

    if (!is_array($pollData) || empty($pollData['offers']) || !is_array($pollData['offers'])) {
        // No offers (either genuinely none, or still not done at the deadline).
        echo json_encode([]);
        exit;
    }

    // ---- Step 3: normalise offers into the canonical platform shape ----------
    // The platform represents a trip as ONE offer whose `segments` array holds one
    // leg-group per direction: segments[0] = outbound, segments[1] = return (the
    // frontend normaliser reads f.segments[0]/f.segments[1], and TBO emits exactly
    // this). Adivaha, however, prices a round trip in one of two ways (spec L38-46,
    // round_trip.mode):
    //   • package/combined (meta.is_package / meta.leg_count >= 2): ONE offer that
    //     already carries both sectors and ONE offer_id books the whole journey.
    //   • 2x (meta.leg_count == 1): SEPARATE single-sector offers (outbound leaves
    //     the search origin, return leaves the destination), each with its OWN
    //     offer_id; you book by sending BOTH ids in legs[] (spec L41-49).
    // So we: build a reusable "leg group" per Adivaha offer; emit package offers as
    // a single two-leg platform offer; and emit each 2x single-sector offer as its
    // OWN one-way offer (we do NOT synthesise a two-token combined round trip — the
    // platform pipeline is single-token, so pairing would undercharge; see the file
    // header). A one-way / multi-city offer is emitted as-is.

    // Build one platform "leg group" (array of flight objects) from an Adivaha
    // offer's per-leg summary(ies), plus the priced booking_data for that offer.
    // Returns ['legs' => [ [flightObj], ... ], 'price' => float, 'actual' => float,
    //          'offer_id' => string, 'meta' => array] or null.
    $buildLegGroups = function (array $offer) use ($db, $module, $currency, $traceId, $type, $rawClass) {
        $offerId = (string) ($offer['id'] ?? '');
        if ($offerId === '') return null;

        $meta = is_array($offer['meta'] ?? null) ? $offer['meta'] : [];

        // sell_base = what OUR tenant wallet is debited (our cost); mark it up for
        // the customer, consistent with every other provider (verified vs spec
        // L62/L443/L825-826: the wallet is debited the all-in `sell`).
        $sellBase     = (float) ($offer['sell_base'] ?? $offer['sell'] ?? 0);
        $baseCurrency = strtoupper((string) ($offer['base_currency'] ?? $offer['currency'] ?? $currency));

        $converted = CURRENCY_CONVERT($sellBase, $db, $baseCurrency, $currency);
        $markedUp  = MARKUP($sellBase, $module, $db, $baseCurrency, $currency);
        if (!is_array($converted) || !isset($converted['price'])) $converted = ['price' => $sellBase];
        if (!is_array($markedUp)  || !isset($markedUp['price']))  $markedUp  = ['price' => $sellBase];

        $priceMarked = number_format((float) $markedUp['price'], 2, '.', '');
        $priceActual = number_format((float) $converted['price'], 2, '.', '');

        // Per-leg summaries. meta.legs (0 = outbound, 1 = inbound) when present;
        // else synthesise a single leg from the top-level meta (one-way / 2x sector).
        $metaLegs = (isset($meta['legs']) && is_array($meta['legs']) && count($meta['legs']) > 0)
            ? $meta['legs']
            : [[
                'origin'           => $meta['origin']        ?? '',
                'destination'      => $meta['destination']   ?? '',
                'depart_date'      => $meta['depart_date']   ?? '',
                'depart_time'      => $meta['depart_time']   ?? '',
                'arrive_date'      => $meta['arrive_date']   ?? '',
                'arrive_time'      => $meta['arrive_time']   ?? '',
                'duration'         => $meta['duration']      ?? '',
                'duration_minutes' => $meta['duration_minutes'] ?? 0,
                'stops'            => $meta['stops']         ?? 0,
                'code'             => $meta['code']          ?? '',
                'airline'          => $meta['airline']       ?? '',
                'flight_number'    => $meta['flight_number'] ?? '',
            ]];

        $baggage = is_array($meta['baggage'] ?? null) ? $meta['baggage'] : [];
        $checkedBagDisplay = !empty($baggage['checkin']) ? (string) $baggage['checkin'] : '';
        $cabinBagDisplay   = !empty($baggage['cabin'])   ? (string) $baggage['cabin']   : '';

        $legGroups = [];
        foreach ($metaLegs as $legIdx => $leg) {
            if (!is_array($leg)) continue;

            $depAt = $leg['depart_at'] ?? null;
            $arrAt = $leg['arrive_at'] ?? null;

            $depDateDisplay = !empty($leg['depart_date'])
                ? date('d-m-Y', strtotime((string) $leg['depart_date']))
                : ($depAt ? date('d-m-Y', strtotime((string) $depAt)) : '');
            $arrDateDisplay = !empty($leg['arrive_date'])
                ? date('d-m-Y', strtotime((string) $leg['arrive_date']))
                : ($arrAt ? date('d-m-Y', strtotime((string) $arrAt)) : $depDateDisplay);
            $depTimeDisplay = !empty($leg['depart_time'])
                ? date('h:i a', strtotime((string) $leg['depart_time']))
                : ($depAt ? date('h:i a', strtotime((string) $depAt)) : '');
            $arrTimeDisplay = !empty($leg['arrive_time'])
                ? date('h:i a', strtotime((string) $leg['arrive_time']))
                : ($arrAt ? date('h:i a', strtotime((string) $arrAt)) : '');

            $durMinutes = (int) ($leg['duration_minutes'] ?? 0);
            $durDisplay = !empty($leg['duration']) ? (string) $leg['duration'] : adivaha_getduration_from_minutes($durMinutes);

            $flight = (object) [
                'img'               => (string) ($leg['code'] ?? $meta['code'] ?? ''),
                'flight_no'         => (string) ($leg['flight_number'] ?? $meta['flight_number'] ?? ''),
                'airline'           => (string) ($leg['airline'] ?? $meta['airline'] ?? ''),
                'class'             => (string) ($meta['cabin'] ?? $rawClass),
                'baggage'           => $checkedBagDisplay !== '' ? $checkedBagDisplay : 'As per airline policy',
                'cabin_baggage'     => $cabinBagDisplay !== '' ? $cabinBagDisplay : 'As per airline policy',
                'departure_airport' => (string) ($leg['origin'] ?? ''),
                'departure_time'    => $depTimeDisplay,
                'arrival_airport'   => (string) ($leg['destination'] ?? ''),
                'arrival_time'      => $arrTimeDisplay,
                'departure_date'    => $depDateDisplay,
                'arrival_date'      => $arrDateDisplay,
                'departure_code'    => (string) ($leg['origin'] ?? ''),
                'arrival_code'      => (string) ($leg['destination'] ?? ''),
                'currency'          => $currency,
                'price'             => $priceMarked,
                'actual_price'      => $priceActual,
                'duration_time'     => $durDisplay,
                'total_duration'    => $durDisplay,
                'stops'             => (int) ($leg['stops'] ?? $meta['stops'] ?? 0),
                'adult_price'       => $priceMarked,
                'child_price'       => $priceMarked,
                'infant_price'      => $priceMarked,
                'actual_adult_price'  => $priceActual,
                'actual_child_price'  => $priceActual,
                'actual_infant_price' => $priceActual,
                'options'           => '',
                'booking_data'      => [
                    'trace_id'      => $traceId,
                    'booking_token' => $offerId,
                    'offer_id'      => $offerId,
                    'supplier'      => 'adivaha',
                    'currency'      => $currency,
                    'base_currency' => $baseCurrency,
                    'amount'        => (float) $markedUp['price'],
                    'actual_amount' => (float) $converted['price'],
                    'sell_base'     => $sellBase,
                    'is_package'    => !empty($meta['is_package']),
                    'leg_index'     => (int) $legIdx,
                ],
                'redirect_url'    => '',
                'refundable'      => !empty($meta['refundable']) ? 'Refundable' : (string) ($offer['cancel_policy'] ?? ''),
                'supplier'        => 'adivaha',
                'type'            => $type,
                'has_ancillaries' => (int) ($module['ancillaries_enabled'] ?? 0) === 1,
                'fare_name'       => (string) ($meta['fare_name'] ?? ''),
                'is_series'       => !empty($meta['is_series']),
                'fare_label'      => (string) ($meta['fare_label'] ?? ''),
                'seats_remaining' => (int) ($meta['seats_remaining'] ?? $meta['seats_left'] ?? 0),
            ];
            $legGroups[] = [$flight];
        }
        if (empty($legGroups)) return null;

        return [
            'legs'      => $legGroups,
            'price'     => (float) $markedUp['price'],
            'actual'    => (float) $converted['price'],
            'offer_id'  => $offerId,
            'leg_count' => (int) ($meta['leg_count'] ?? count($legGroups)),
            'is_package' => !empty($meta['is_package']),
            'meta'      => $meta,
        ];
    };

    $final_array = [];

    $packagedRT = 0;  // round-trip package offers emitted (one token, both sectors)
    $twoXsurfaced = 0; // 2x single sectors surfaced as one-ways

    foreach ($pollData['offers'] as $offer) {
        if (connection_aborted()) { exit; }
        if (!is_array($offer)) continue;
        $g = $buildLegGroups($offer);
        if ($g === null) continue;

        $isRoundSearch = ($type !== 'oneway' && $type !== 'multicity');

        if (!$isRoundSearch) {
            // One-way or multi-city: one offer, its leg group(s) as-is.
            $final_array[] = ['segments' => $g['legs']];
            continue;
        }

        // ROUND SEARCH.
        //
        // The platform books exactly ONE offer_id per booking offer (the whole
        // review→revalidate→pay→issue pipeline is single-token: revalidate
        // re-prices one token, the charged price is that one offer's price). So a
        // round trip is only safe when Adivaha gives us ONE offer that already
        // carries BOTH sectors — a package/combined offer (meta.is_package /
        // leg_count >= 2, one offer_id books the whole journey, spec L44-46).
        if ($g['leg_count'] >= 2 || $g['is_package'] || count($g['legs']) >= 2) {
            $final_array[] = ['segments' => $g['legs']];  // one token, both legs
            $packagedRT++;
            continue;
        }

        // Adivaha "2x" single-sector offer (leg_count == 1): a separate outbound
        // OR return fare with its OWN offer_id. We deliberately DO NOT synthesise a
        // combined two-token round trip from these — the platform cannot re-price
        // or settle two tokens as one booking (doing so undercharged the customer
        // while both legs were booked). Instead we surface each sector as its own
        // one-way offer: each is fully revalidated and priced correctly on its own,
        // and the customer books each direction as a separate, correctly-priced
        // booking. (True combined 2x round trips are a deliberate future phase that
        // needs real multi-token support in the platform's revalidate/settle path.)
        $final_array[] = ['segments' => $g['legs']];
        $twoXsurfaced++;
    }

    if ($type !== 'oneway' && $type !== 'multicity') {
        error_log('adivaha search: round search -> ' . $packagedRT . ' package round-trip offer(s), '
            . $twoXsurfaced . ' single-sector (2x) offer(s) surfaced as one-ways.');
    }

    echo json_encode($final_array);
    exit;

    } catch (Throwable $e) {
        // Never surface a raw 500 to the search frontend — degrade to a clean
        // empty/false envelope, like the other providers do.
        error_log('ADIVAHA SEARCH ERROR: ' . $e->getMessage());
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['status' => false, 'message' => 'Adivaha search failed. Please try again.', 'response' => []]);
        exit;
    }
});

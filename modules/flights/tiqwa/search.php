<?php
// path: modules/flights/tiqwa/search.php
// Tiqwa Flights — offer search + normalisation.
// POST flights/tiqwa/search
//
// Tiqwa search is SYNCHRONOUS (unlike adivaha's async trace/poll): one GET
// /v1/flight/search returns the offers array directly; multi-city is a POST
// /v1/flight/multi_city/search. We normalise each offer into the SAME canonical
// shape every other provider emits: an array of { segments: [ [flightObj], ... ] },
// where segments[0] = outbound leg-group and segments[1] = inbound (round trip),
// each flightObj carrying a booking_data blob with the opaque Tiqwa `flight_id`
// (offer id) that revalidate/book reuse in the URL path.
//
// PRICING (audit decision A): our MARKUP() is the SINGLE source of truth. Tiqwa's
// own account-side markup must be left zero/deactivated (managed via the admin
// console in utilities.php) so we never mark up twice. Tiqwa offer.amount is the
// TOTAL for ALL passengers; the per-passenger fare we surface comes from
// travelers_price[] (per-type {adult/child/infant}), consistent with how the
// platform treats a per-pax offer price.

if (!function_exists('tiqwa_fmt_time')) {
    function tiqwa_fmt_time($iso) {
        $iso = trim((string) $iso);
        return $iso !== '' ? date('h:i a', strtotime($iso)) : '';
    }
}
if (!function_exists('tiqwa_fmt_date')) {
    function tiqwa_fmt_date($iso) {
        $iso = trim((string) $iso);
        return $iso !== '' ? date('d-m-Y', strtotime($iso)) : '';
    }
}
if (!function_exists('tiqwa_dur_display')) {
    /** minutes → "H:MM" (matches the platform's duration display). */
    function tiqwa_dur_display($minutes) {
        $minutes = (int) $minutes;
        if ($minutes <= 0) return '';
        return intdiv($minutes, 60) . ':' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }
}

$router->post('flights/tiqwa/search', function () use ($db) {

    @set_time_limit(90);
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }

    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json');

    try {
        $module = getTiqwaModule($db);
        if (!$module) {
            echo json_encode(['status' => false, 'message' => 'Tiqwa module not configured', 'response' => []]);
            exit;
        }
        $credentials = getTiqwaCredentials($db);
        if (!$credentials || $credentials['api_token'] === '') {
            echo json_encode(['status' => false, 'message' => 'Tiqwa access token not configured. Configure the module in admin.', 'response' => []]);
            exit;
        }
        $token = $credentials['api_token'];
        $base  = $credentials['flight_base'];

        $currency = strtoupper($_POST['currency'] ?? 'NGN');
        $type     = $_POST['type'] ?? ($_SESSION['flight_type'] ?? 'oneway');

        // ---- Map platform cabin class to Tiqwa's cabin enum --------------------
        // Tiqwa cabin values (Flight.v1.json /search `cabin`): economy / premium_economy
        // / business / first (lowercase).
        $cabinMap = [
            'economy' => 'economy', 'premium_economy' => 'premium_economy',
            'premiumeconomy' => 'premium_economy', 'premium' => 'premium_economy',
            'business' => 'business', 'first' => 'first',
        ];
        $rawClass = strtolower(trim((string) ($_POST['class'] ?? 'economy')));
        $cabin = $cabinMap[$rawClass] ?? 'economy';

        $adults   = max(1, (int) ($_POST['adults'] ?? 1));
        $children = max(0, (int) ($_POST['childrens'] ?? 0));
        $infants  = max(0, (int) ($_POST['infants'] ?? 0));

        // ================= MULTI-CITY: POST /multi_city/search =================
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
            $destinations = [];
            foreach ($routes as $i => $r) {
                $from = strtoupper(trim((string) ($r['from'] ?? '')));
                $to   = strtoupper(trim((string) ($r['to'] ?? '')));
                $dRaw = trim((string) ($r['date'] ?? ''));
                $ts   = $dRaw !== '' ? strtotime($dRaw) : false;
                if ($from === '' || $to === '' || $ts === false || $from === $to) {
                    echo json_encode(['status' => false, 'message' => 'Multicity route ' . ($i + 1) . ' is missing a valid origin, destination or date.']);
                    exit;
                }
                $destinations[] = [
                    'origin'         => $from,
                    'destination'    => $to,
                    'departure_date' => date('Y-m-d', $ts),
                ];
            }
            if (count($destinations) < 2) {
                echo json_encode(['status' => false, 'message' => 'Multicity needs at least 2 valid segments.']);
                exit;
            }
            if (count($destinations) > 6) {
                echo json_encode(['status' => false, 'message' => 'Tiqwa multicity supports at most 6 routes.']);
                exit;
            }

            $body = [
                'destinations' => $destinations,
                'cabin'        => $cabin,
                'adults'       => $adults,
                'children'     => $children,
                'infants'      => $infants,
            ];
            $res = tiqwaApiRequest('POST', $base . '/multi_city/search', $body, $token, [], 60);
            tiqwaLog('search/multi_city', $body, $res['raw'], $res['http_code'], $res['curl_error']);
            $offers = is_array($res['data']) ? $res['data'] : [];
        } else {
            // ================= ONE-WAY / ROUND: GET /search =================
            foreach (['origin', 'destination', 'departure_date'] as $req) {
                if (!isset($_POST[$req]) || trim((string) $_POST[$req]) === '') {
                    echo json_encode(['status' => false, 'message' => $req . ' - param or value missing']);
                    exit;
                }
            }
            $origin = strtoupper(trim((string) $_POST['origin']));
            $dest   = strtoupper(trim((string) $_POST['destination']));
            if ($origin === $dest) {
                echo json_encode(['status' => false, 'message' => 'Origin and destination cannot be the same.']);
                exit;
            }
            if ($type !== 'oneway' && (!isset($_POST['return_date']) || trim((string) $_POST['return_date']) === '')) {
                echo json_encode(['status' => false, 'message' => 'return_date - param or value missing for round trip']);
                exit;
            }

            $q = [
                'origin'         => $origin,
                'destination'    => $dest,
                'departure_date' => date('Y-m-d', strtotime((string) $_POST['departure_date'])),
                'cabin'          => $cabin,
                'adults'         => $adults,
            ];
            if ($children > 0) $q['children'] = $children;
            if ($infants > 0)  $q['infants']  = $infants;
            if ($type !== 'oneway') {
                $q['return_date'] = date('Y-m-d', strtotime((string) $_POST['return_date']));
            }
            $url = $base . '/search?' . http_build_query($q);
            $res = tiqwaApiRequest('GET', $url, null, $token, [], 60);
            tiqwaLog('search', $q, $res['raw'], $res['http_code'], $res['curl_error']);
            // Tiqwa search returns an array of offers (or {data:[...]}); tolerate both.
            $offers = is_array($res['data'])
                ? (isset($res['data'][0]) ? $res['data'] : ($res['data']['data'] ?? []))
                : [];
        }

        if (!$res['success']) {
            echo json_encode(['status' => false, 'message' => 'Tiqwa search failed: ' . tiqwaExtractError($res), 'response' => []]);
            exit;
        }
        if (empty($offers) || !is_array($offers)) {
            echo json_encode([]); // no results — clean empty array (same as other providers)
            exit;
        }

        // ---- Normalise each offer into the canonical platform shape ------------
        $buildLeg = function (array $seg, $priceMarked, $priceActual, $currency, $rawClass) {
            $airline = is_array($seg['airline_details'] ?? null) ? $seg['airline_details'] : [];
            $code = (string) ($airline['code'] ?? $seg['marketing_airline'] ?? $seg['operating_airline'] ?? '');
            $depIso = (string) ($seg['departure_time'] ?? '');
            $arrIso = (string) ($seg['arrival_time'] ?? '');
            return (object) [
                'img'               => $code,
                'flight_no'         => (string) ($seg['flight_number'] ?? ''),
                'airline'           => (string) ($airline['name'] ?? $code),
                'class'             => (string) ($seg['cabin_type'] ?? $rawClass),
                'baggage'           => (string) ($seg['baggage'] ?? 'As per airline policy'),
                'cabin_baggage'     => 'As per airline policy',
                'departure_airport' => (string) ($seg['airport_from'] ?? ''),
                'departure_time'    => tiqwa_fmt_time($depIso),
                'arrival_airport'   => (string) ($seg['airport_to'] ?? ''),
                'arrival_time'      => tiqwa_fmt_time($arrIso),
                'departure_date'    => tiqwa_fmt_date($depIso),
                'arrival_date'      => tiqwa_fmt_date($arrIso),
                'departure_iso'     => $depIso,
                'arrival_iso'       => $arrIso,
                'departure_code'    => (string) ($seg['airport_from'] ?? ''),
                'arrival_code'      => (string) ($seg['airport_to'] ?? ''),
                'currency'          => $currency,
                'price'             => $priceMarked,
                'actual_price'      => $priceActual,
                'duration_time'     => tiqwa_dur_display($seg['duration'] ?? 0),
                'total_duration'    => tiqwa_dur_display($seg['duration'] ?? 0),
                'stops'             => 0,
                'adult_price'       => $priceMarked,
                'child_price'       => $priceMarked,
                'infant_price'      => $priceMarked,
                'actual_adult_price'  => $priceActual,
                'actual_child_price'  => $priceActual,
                'actual_infant_price' => $priceActual,
                'options'           => '',
                'logo'              => (string) ($airline['logo'] ?? ''),
            ];
        };

        $final = [];
        foreach ($offers as $offer) {
            if (!is_array($offer)) continue;
            $flightId = (string) ($offer['id'] ?? '');
            if ($flightId === '') continue;

            $baseCurrency = strtoupper((string) ($offer['currency'] ?? $currency));

            // Per-PASSENGER adult fare (what the platform surfaces per result): prefer
            // travelers_price[].adult; fall back to amount / pax count.
            $adultNet = 0.0;
            if (!empty($offer['travelers_price']) && is_array($offer['travelers_price'])) {
                foreach ($offer['travelers_price'] as $tp) {
                    if (is_array($tp) && isset($tp['adult'])) { $adultNet = (float) $tp['adult']; break; }
                }
            }
            if ($adultNet <= 0) {
                $paxCount = max(1, $adults + $children + $infants);
                $adultNet = (float) ($offer['amount'] ?? 0) / $paxCount;
            }
            if ($adultNet <= 0) continue;

            // MARKUP() is our single source of truth (Tiqwa-side markup zeroed).
            $converted = CURRENCY_CONVERT($adultNet, $db, $baseCurrency, $currency);
            $markedUp  = MARKUP($adultNet, $module, $db, $baseCurrency, $currency);
            if (!is_array($converted) || !isset($converted['price'])) $converted = ['price' => $adultNet];
            if (!is_array($markedUp)  || !isset($markedUp['price']))  $markedUp  = ['price' => $adultNet];
            $priceMarked = number_format((float) $markedUp['price'], 2, '.', '');
            $priceActual = number_format((float) $converted['price'], 2, '.', '');

            $bookingData = [
                'flight_id'     => $flightId,     // Tiqwa opaque offer id (used in confirm/book URL)
                'booking_token' => $flightId,
                'offer_id'      => $flightId,
                'supplier'      => 'tiqwa',
                'currency'      => $currency,
                'base_currency' => $baseCurrency,
                'amount'        => (float) $markedUp['price'],
                'actual_amount' => (float) $converted['price'],
                'adult_net'     => $adultNet,
            ];

            // Build leg-groups: outbound[] (segments[0]) and inbound[] (segments[1]).
            $segments = [];
            $outbound = is_array($offer['outbound'] ?? null) ? $offer['outbound'] : [];
            $obGroup = [];
            foreach ($outbound as $seg) {
                if (!is_array($seg)) continue;
                $fl = $buildLeg($seg, $priceMarked, $priceActual, $currency, $rawClass);
                $fl->booking_data   = $bookingData;
                $fl->redirect_url   = '';
                $fl->refundable     = !empty($offer['document_required']) ? '' : (string) ($offer['refundable'] ?? '');
                $fl->supplier       = 'tiqwa';
                $fl->type           = $type;
                $fl->has_ancillaries = false;
                $fl->fare_name      = (string) ($offer['fare_basis'] ?? '');
                $fl->stops          = (int) ($offer['outbound_stops'] ?? 0);
                $obGroup[] = $fl;
            }
            if (!empty($obGroup)) $segments[] = $obGroup;

            $inbound = is_array($offer['inbound'] ?? null) ? $offer['inbound'] : [];
            if (!empty($inbound)) {
                $ibGroup = [];
                foreach ($inbound as $seg) {
                    if (!is_array($seg)) continue;
                    $fl = $buildLeg($seg, $priceMarked, $priceActual, $currency, $rawClass);
                    $fl->booking_data    = $bookingData;
                    $fl->redirect_url    = '';
                    $fl->refundable      = (string) ($offer['refundable'] ?? '');
                    $fl->supplier        = 'tiqwa';
                    $fl->type            = $type;
                    $fl->has_ancillaries = false;
                    $fl->stops           = (int) ($offer['inbound_stops'] ?? 0);
                    $ibGroup[] = $fl;
                }
                if (!empty($ibGroup)) $segments[] = $ibGroup;
            }

            if (!empty($segments)) {
                $final[] = ['segments' => $segments];
            }
        }

        echo json_encode($final);
        exit;

    } catch (Throwable $e) {
        error_log('TIQWA SEARCH ERROR: ' . $e->getMessage());
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['status' => false, 'message' => 'Tiqwa search failed. Please try again.', 'response' => []]);
        exit;
    }
});

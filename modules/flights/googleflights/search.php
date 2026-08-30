<?php

// ============================================================================
// GOOGLE FLIGHTS SEARCH — via google-flights2.p.rapidapi.com
// ============================================================================

function googleFlightsDuration(int $rawMinutes): string
{
    $h = (int) floor($rawMinutes / 60);
    $m = $rawMinutes % 60;
    return sprintf('%d:%02d', $h, $m);
}

$router->post('flights/googleflights/search', function () use ($db) {

    // Release session lock early so other requests are not blocked
    @set_time_limit(60);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (connection_aborted()) { exit; }

    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json');

    // ─── Module config ────────────────────────────────────────────────────
    $module = $db->get('modules', '*', ['name' => 'googleflights', 'type' => 'flights']);

    if (!$module) {
        echo json_encode(['status' => false, 'message' => 'Google Flights module not configured', 'response' => []]);
        exit;
    }

    $rapidapi_key  = trim($module['c1'] ?? '');
    $rapidapi_host = 'google-flights2.p.rapidapi.com';

    if (empty($rapidapi_key)) {
        echo json_encode(['status' => false, 'message' => 'RapidAPI key not configured', 'response' => []]);
        exit;
    }

    // ─── Request params ───────────────────────────────────────────────────
    $type        = $_POST['type'] ?? 'oneway';
    $isMulticity = ($type === 'multicity');
    $currency    = strtoupper($_POST['currency'] ?? 'USD');
    $adults      = max(1, (int) ($_POST['adults']    ?? 1));
    $children    = max(0, (int) ($_POST['childrens'] ?? 0));
    $infants     = max(0, (int) ($_POST['infants']   ?? 0));

    $cabinMap = [
        'economy'         => 'ECONOMY',
        'economy premium' => 'PREMIUM_ECONOMY',
        'business'        => 'BUSINESS',
        'first class'     => 'FIRST',
        'first'           => 'FIRST',
    ];
    $travelClass = $cabinMap[strtolower($_POST['class'] ?? 'economy')] ?? 'ECONOMY';

    // ─── Multicity routes ─────────────────────────────────────────────────
    $multicityRoutes = [];
    if ($isMulticity) {
        $routesRaw = $_POST['routes'] ?? '';
        $multicityRoutes = is_string($routesRaw) ? (json_decode($routesRaw, true) ?? []) : (array) $routesRaw;
        if (empty($multicityRoutes)) {
            echo json_encode(['status' => false, 'message' => 'Multicity routes missing or invalid']);
            exit;
        }
    }

    // ─── Helper: call the RapidAPI endpoint ──────────────────────────────
    $callApi = function (string $origin, string $destination, string $depDate, string $retDate = '') use (
        $rapidapi_key, $rapidapi_host, $adults, $children, $infants, $travelClass, $currency
    ): array {
        $params = [
            'departure_id'  => $origin,
            'arrival_id'    => $destination,
            'outbound_date' => $depDate,          // YYYY-MM-DD
            'travel_class'  => $travelClass,
            'adults'        => $adults,
            'currency'      => $currency,
            'language_code' => 'en-US',
            'country_code'  => 'US',
            'search_type'   => 'best',
            'show_hidden'   => 1,
        ];
        if ($children > 0) $params['children'] = $children;
        if ($infants  > 0) $params['infants_in_seat'] = $infants;
        if (!empty($retDate)) $params['return_date'] = $retDate;

        $url = 'https://' . $rapidapi_host . '/api/v1/searchFlights?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-rapidapi-host: ' . $rapidapi_host,
                'x-rapidapi-key: '  . $rapidapi_key,
            ],
        ]);

        $response = curl_exec($ch);
        $err      = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            return ['ok' => false, 'message' => 'Google Flights API request failed'];
        }
        if (empty($response)) {
            return ['ok' => false, 'message' => 'No response from Google Flights API'];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return ['ok' => false, 'message' => 'Invalid response from Google Flights API'];
        }

        if ($httpCode === 401 || $httpCode === 403) {
            return ['ok' => false, 'message' => 'Invalid or unauthorised RapidAPI key'];
        }

        if (!empty($data['message']) && stripos($data['message'], 'Invalid API key') !== false) {
            return ['ok' => false, 'message' => 'Invalid RapidAPI key'];
        }

        if (empty($data['status'])) {
            $apiMessage = trim((string) ($data['message'] ?? $data['error'] ?? ''));
            return ['ok' => false, 'message' => $apiMessage !== '' ? $apiMessage : 'Google Flights API returned an error'];
        }

        return ['ok' => true, 'data' => $data];
    };

    // ─── Helper: normalise one itinerary into the platform's flight object ─
    $normalise = function (array $itin, string $supplierCurrency, string $displayCurrency, array $module, $db) use ($type) {
        $flights = $itin['flights'] ?? [];
        if (empty($flights)) return null;

        $firstFlight = $flights[0];
        $lastFlight  = end($flights);

        $depAirport  = $firstFlight['departure_airport'] ?? [];
        $arrAirport  = $lastFlight['arrival_airport']    ?? [];
        $depCode     = $depAirport['airport_code'] ?? '';
        $arrCode     = $arrAirport['airport_code'] ?? '';
        $depName     = $depAirport['airport_name'] ?? $depCode;
        $arrName     = $arrAirport['airport_name'] ?? $arrCode;

        // Times
        $depRaw = $depAirport['time'] ?? '';   // "2026-6-18 16:20"
        $arrRaw = $arrAirport['time'] ?? '';
        $depTs  = strtotime($depRaw);
        $arrTs  = strtotime($arrRaw);

        // Duration: prefer itin-level raw minutes; fallback per-leg sum
        $durationRaw = (int) ($itin['duration']['raw'] ?? 0);
        if ($durationRaw <= 0) {
            foreach ($flights as $fl) { $durationRaw += (int) ($fl['duration']['raw'] ?? 0); }
        }
        $durationStr = googleFlightsDuration($durationRaw);

        // Airline — use first flight, extract IATA from flight_number e.g. "B6 824"
        $flightNumber   = $firstFlight['flight_number'] ?? '';
        $parts          = explode(' ', trim($flightNumber));
        $airlineIata    = count($parts) >= 2 ? strtoupper($parts[0]) : '';
        $airlineName    = $firstFlight['airline'] ?? $airlineIata;

        // Multi-segment: flight_no = first segment's number
        $flightNo = trim($flightNumber);

        // Baggage
        $bags        = $itin['bags'] ?? [];
        $checkedBags = (int) ($bags['checked'] ?? 0);
        $carryOn     = (int) ($bags['carry_on'] ?? 1);
        $baggage     = $checkedBags > 0 ? $checkedBags . ' x Checked Bag' : '0 PC';
        $cabinBag    = $carryOn    > 0 ? $carryOn    . ' x Cabin Bag'    : '0 PC';

        // Price
        $rawPrice = (float) ($itin['price'] ?? 0);
        if ($rawPrice <= 0) return null;

        $markedUp  = MARKUP($rawPrice,         $module, $db, $supplierCurrency, $displayCurrency);
        $converted = CURRENCY_CONVERT($rawPrice, $db,    $supplierCurrency, $displayCurrency);

        // Per-pax breakdown — Google Flights only gives total price, we distribute evenly
        $totalPax    = max(1, $module['adults'] ?? 1);
        $adultPrice  = $markedUp['price'];
        $childPrice  = 0.00;
        $infantPrice = 0.00;

        // Stops
        $stops = (int) ($itin['stops'] ?? max(0, count($flights) - 1));

        // Build segments array (one entry per leg)
        $segments = [];
        foreach ($flights as $fl) {
            $flDep     = $fl['departure_airport'] ?? [];
            $flArr     = $fl['arrival_airport']   ?? [];
            $flDepTs   = strtotime($flDep['time'] ?? '');
            $flArrTs   = strtotime($flArr['time'] ?? '');
            $flParts   = explode(' ', trim($fl['flight_number'] ?? ''));
            $flIata    = count($flParts) >= 2 ? strtoupper($flParts[0]) : $airlineIata;
            $flDurRaw  = (int) ($fl['duration']['raw'] ?? 0);

            $segments[] = (object) [
                'img'               => $flIata,
                'flight_no'         => trim($fl['flight_number'] ?? ''),
                'airline'           => $fl['airline'] ?? $flIata,
                'class'             => strtolower($type === 'multicity' ? 'economy' : 'economy'),
                'baggage'           => $baggage,
                'cabin_baggage'     => $cabinBag,
                'departure_airport' => $flDep['airport_name'] ?? ($flDep['airport_code'] ?? ''),
                'departure_time'    => $flDepTs ? date('h:i a', $flDepTs) : '',
                'arrival_airport'   => $flArr['airport_name'] ?? ($flArr['airport_code'] ?? ''),
                'arrival_time'      => $flArrTs ? date('h:i a', $flArrTs) : '',
                'departure_date'    => $flDepTs ? date('d-m-Y', $flDepTs) : '',
                'arrival_date'      => $flArrTs ? date('d-m-Y', $flArrTs) : '',
                'departure_code'    => $flDep['airport_code'] ?? '',
                'arrival_code'      => $flArr['airport_code'] ?? '',
                'duration_time'     => googleFlightsDuration($flDurRaw),
                'stops'             => 0,
            ];
        }

        return (object) [
            'img'               => $airlineIata,
            'flight_no'         => $flightNo,
            'airline'           => $airlineName,
            'class'             => 'economy',
            'baggage'           => $baggage,
            'cabin_baggage'     => $cabinBag,
            'departure_airport' => $depName,
            'departure_time'    => $depTs ? date('h:i a', $depTs) : '',
            'arrival_airport'   => $arrName,
            'arrival_time'      => $arrTs ? date('h:i a', $arrTs) : '',
            'departure_date'    => $depTs ? date('d-m-Y', $depTs) : '',
            'arrival_date'      => $arrTs ? date('d-m-Y', $arrTs) : '',
            'departure_code'    => $depCode,
            'arrival_code'      => $arrCode,
            'currency'          => $displayCurrency,
            'price'             => number_format((float) $markedUp['price'],  2, '.', ''),
            'actual_price'      => number_format((float) $converted['price'], 2, '.', ''),
            'duration_time'     => $durationStr,
            'total_duration'    => $durationStr,
            'adult_price'       => number_format((float) $adultPrice,  2, '.', ''),
            'child_price'       => number_format((float) $childPrice,  2, '.', ''),
            'infant_price'      => number_format((float) $infantPrice, 2, '.', ''),
            'actual_adult_price'  => number_format((float) CURRENCY_CONVERT($rawPrice, $db, $supplierCurrency, $displayCurrency)['price'], 2, '.', ''),
            'actual_child_price'  => '0.00',
            'actual_infant_price' => '0.00',
            'stops'             => $stops,
            'options'           => '',
            'booking_data'      => [
                'booking_token' => $itin['booking_token'] ?? '',
                'currency'      => $displayCurrency,
                'amount'        => $markedUp['price'],
                'actual_amount' => $converted['price'],
            ],
            'redirect_url'      => '',
            'refundable'        => 0,
            'supplier'          => 'googleflights',
            'type'              => $type,
            'segments'          => $segments,
        ];
    };

    // ─── Main logic ───────────────────────────────────────────────────────
    try {
        $finalArray  = [];
        $apiCurrency = 'USD'; // Google Flights prices are always in the requested currency

        // ── MULTICITY ─────────────────────────────────────────────────────
        if ($isMulticity) {
            $apiFailed = false;
            $lastApiError = 'Google Flights API returned an error';

            foreach ($multicityRoutes as $route) {
                $from = strtoupper($route['from'] ?? '');
                $to   = strtoupper($route['to']   ?? '');
                $date = date('Y-m-d', strtotime($route['date'] ?? ''));
                if (empty($from) || empty($to) || empty($date)) continue;

                $result = $callApi($from, $to, $date);
                if (!$result['ok']) {
                    $apiFailed = true;
                    $lastApiError = $result['message'];
                    continue;
                }

                $allItins = array_merge(
                    $result['data']['data']['itineraries']['topFlights']   ?? [],
                    $result['data']['data']['itineraries']['otherFlights']  ?? []
                );

                foreach ($allItins as $itin) {
                    $norm = $normalise($itin, $apiCurrency, $currency, $module, $db);
                    if (!$norm) continue;
                    $finalArray[] = ['segments' => [[$norm]]];
                }
            }

            if (empty($finalArray) && $apiFailed) {
                echo json_encode(['status' => false, 'message' => $lastApiError, 'response' => []]);
                exit;
            }

        // ── ONE-WAY ───────────────────────────────────────────────────────
        } elseif ($type === 'oneway') {
            $origin      = strtoupper($_POST['origin']         ?? '');
            $destination = strtoupper($_POST['destination']    ?? '');
            $depDate     = date('Y-m-d', strtotime($_POST['departure_date'] ?? ''));

            if (empty($origin) || empty($destination) || empty($depDate)) {
                echo json_encode(['status' => false, 'message' => 'Missing origin, destination or departure date']);
                exit;
            }

            $result = $callApi($origin, $destination, $depDate);
            if (!$result['ok']) {
                echo json_encode(['status' => false, 'message' => $result['message'], 'response' => []]);
                exit;
            }

            $allItins = array_merge(
                $result['data']['data']['itineraries']['topFlights']   ?? [],
                $result['data']['data']['itineraries']['otherFlights']  ?? []
            );

            foreach ($allItins as $itin) {
                $norm = $normalise($itin, $apiCurrency, $currency, $module, $db);
                if (!$norm) continue;
                $finalArray[] = ['segments' => [[$norm]]];
            }

        // ── ROUND-TRIP ────────────────────────────────────────────────────
        // The Google Flights API round-trip response only contains the outbound
        // leg per itinerary (return is fetched via next_token in a second step).
        // We make two independent one-way searches and pair them so both
        // segments / returnSegments are proper flight objects with booking_tokens.
        } else {
            $origin      = strtoupper($_POST['origin']         ?? '');
            $destination = strtoupper($_POST['destination']    ?? '');
            $depDate     = date('Y-m-d', strtotime($_POST['departure_date'] ?? ''));
            $retDate     = date('Y-m-d', strtotime($_POST['return_date']    ?? ''));

            if (empty($origin) || empty($destination) || empty($depDate) || empty($retDate)) {
                echo json_encode(['status' => false, 'message' => 'Missing required round-trip parameters']);
                exit;
            }

            // Two parallel one-way searches
            $outResult = $callApi($origin,      $destination, $depDate);
            $retResult = $callApi($destination, $origin,      $retDate);

            if (!$outResult['ok']) {
                echo json_encode(['status' => false, 'message' => $outResult['message'], 'response' => []]);
                exit;
            }

            $outItins = array_merge(
                $outResult['data']['data']['itineraries']['topFlights']   ?? [],
                $outResult['data']['data']['itineraries']['otherFlights'] ?? []
            );
            $retItins = $retResult['ok']
                ? array_merge(
                    $retResult['data']['data']['itineraries']['topFlights']   ?? [],
                    $retResult['data']['data']['itineraries']['otherFlights'] ?? []
                )
                : [];

            // Normalise — use booking_token (one-way results have it, not next_token)
            $outNorms = [];
            foreach ($outItins as $itin) {
                // One-way call gives booking_token; round-trip call gives next_token — use whichever exists
                if (empty($itin['booking_token']) && !empty($itin['next_token'])) {
                    $itin['booking_token'] = $itin['next_token'];
                }
                $n = $normalise($itin, $apiCurrency, $currency, $module, $db);
                if ($n) { $n->type = 'return'; $outNorms[] = $n; }
            }
            $retNorms = [];
            foreach ($retItins as $itin) {
                if (empty($itin['booking_token']) && !empty($itin['next_token'])) {
                    $itin['booking_token'] = $itin['next_token'];
                }
                $n = $normalise($itin, $apiCurrency, $currency, $module, $db);
                if ($n) { $n->type = 'return'; $retNorms[] = $n; }
            }

            // Pair: each outbound with its best-matched return (by airline first, then position)
            // Cap at 50 combinations to avoid flooding the listing
            $limit = 50;
            $count = 0;
            foreach ($outNorms as $i => $outNorm) {
                // Try to match by same airline first
                $matched = null;
                foreach ($retNorms as $retNorm) {
                    if ($retNorm->img === $outNorm->img) { $matched = $retNorm; break; }
                }
                // Fallback: same index, or first available
                if (!$matched) $matched = $retNorms[$i] ?? $retNorms[0] ?? null;

                // segments must be a 2D array: [0] = outbound array, [1] = return array
                // The listing page reads segments[0] for outbound and segments[1] for return
                $entry = ['segments' => [[$outNorm]]];
                if ($matched) {
                    $entry['segments'][] = [$matched];  // segments[1] = return leg
                }
                $finalArray[] = $entry;
                if (++$count >= $limit) break;
            }
        }

        if (!empty($finalArray)) {
            echo json_encode($finalArray);
        } else {
            echo json_encode([]);
        }

    } catch (Exception $e) {
        error_log('GoogleFlights search error: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred. Please try again later.']);
    }
});

<?php
// path: modules/flights/tbo/search.php
// TBO Air flight search route
// POST flights/tbo/search

global $router;

/** Map platform cabin class → TBO FlightCabinClass (1=All 2=Economy 3=PremiumEconomy 4=Business 6=First). */
function tboCabinClass($class)
{
    $class = strtolower(trim(str_replace(' ', '_', (string)$class)));
    $map = [
        'economy'         => 2,
        'economy_premium' => 3,
        'premium_economy' => 3,
        'business'        => 4,
        'first'           => 6,
        'first_class'     => 6,
    ];
    return $map[$class] ?? 2;
}

/** Map TBO CabinClass number back to the platform's class label. */
function tboCabinName($cabin)
{
    $map = [1 => 'economy', 2 => 'economy', 3 => 'premium_economy', 4 => 'business', 5 => 'business', 6 => 'first'];
    return $map[(int)$cabin] ?? 'economy';
}

/** Format minutes as H:MM. */
function tboFormatDuration($minutes)
{
    $minutes = max(0, (int)$minutes);
    return floor($minutes / 60) . ':' . str_pad($minutes % 60, 2, '0', STR_PAD_LEFT);
}

/**
 * Normalize one TBO segment group (an itinerary leg) into the platform's
 * segment array shape shared by every flights module.
 */
function tboProcessSegments($db, $module, array $segmentGroup, array $priceCtx, array $bookingData, $refundable, $type)
{
    $subArray = [];
    $lastIndex = count($segmentGroup) - 1;

    // Total duration: AccumulatedDuration of the last segment, else sum of Durations
    $totalMinutes = (int)($segmentGroup[$lastIndex]['AccumulatedDuration'] ?? 0);
    if ($totalMinutes <= 0) {
        foreach ($segmentGroup as $seg) {
            $totalMinutes += (int)($seg['Duration'] ?? 0);
        }
    }
    $totalDuration = tboFormatDuration($totalMinutes);

    // Layovers between consecutive segments
    $layoverCities = [];
    $layoverMinutes = 0;
    for ($i = 0; $i < $lastIndex; $i++) {
        $arr = strtotime($segmentGroup[$i]['ArrivalTime'] ?? '');
        $dep = strtotime($segmentGroup[$i + 1]['DepartureTime'] ?? '');
        if ($arr && $dep && $dep > $arr) {
            $layoverMinutes += (int)round(($dep - $arr) / 60);
        }
        $layoverCities[] = $segmentGroup[$i]['Destination']['AirportCode'] ?? '';
    }
    $layoverDuration = $layoverMinutes > 0
        ? sprintf('%d h %d m', floor($layoverMinutes / 60), $layoverMinutes % 60)
        : '';

    foreach ($segmentGroup as $seg) {
        $airline   = $seg['AirlineDetails'] ?? [];
        $depAt     = $seg['DepartureTime'] ?? '';
        $arrAt     = $seg['ArrivalTime'] ?? '';

        $subArray[] = (object)[
            'img'               => $airline['AirlineCode'] ?? '',
            'flight_no'         => $seg['FlightNumber'] ?? '',
            'airline'           => $airline['AirlineName'] ?? ($airline['AirlineCode'] ?? ''),
            'class'             => tboCabinName($seg['CabinClass'] ?? 2),
            'booking_class'     => $airline['FareClass'] ?? '',
            'baggage'           => ($seg['IncludedBaggage'] ?? '') !== '' ? $seg['IncludedBaggage'] : 'Check fare rules',
            'cabin_baggage'     => ($seg['CabinBaggage'] ?? '') !== '' ? $seg['CabinBaggage'] : 'Cabin bag included',
            'departure_airport' => $seg['Origin']['AirportName'] ?? ($seg['Origin']['AirportCode'] ?? ''),
            'departure_time'    => $depAt ? date('h:i a', strtotime($depAt)) : '',
            'departure_date'    => $depAt ? date('d-m-Y', strtotime($depAt)) : '',
            'departure_code'    => $seg['Origin']['AirportCode'] ?? '',
            'arrival_airport'   => $seg['Destination']['AirportName'] ?? ($seg['Destination']['AirportCode'] ?? ''),
            'arrival_time'      => $arrAt ? date('h:i a', strtotime($arrAt)) : '',
            'arrival_date'      => $arrAt ? date('d-m-Y', strtotime($arrAt)) : '',
            'arrival_code'      => $seg['Destination']['AirportCode'] ?? '',
            'stops'             => $lastIndex,
            'currency'          => $priceCtx['currency'],
            'price'             => $priceCtx['price'],
            'actual_price'      => $priceCtx['actual_price'],
            'duration_time'     => tboFormatDuration($seg['Duration'] ?? 0),
            'total_duration'    => $totalDuration,
            'adult_price'       => $priceCtx['adult_price'],
            'child_price'       => $priceCtx['child_price'],
            'infant_price'      => $priceCtx['infant_price'],
            'actual_adult_price'  => $priceCtx['actual_adult_price'],
            'actual_child_price'  => $priceCtx['actual_child_price'],
            'actual_infant_price' => $priceCtx['actual_infant_price'],
            'refundable'        => $refundable,
            'supplier'          => 'tbo',
            'module'            => 'tbo',
            'type'              => $type,
            'options'           => '',
            'redirect_url'      => '',
            'layover'           => ['city' => implode(',', array_filter($layoverCities)), 'duration' => $layoverDuration],
            'booking_data'      => $bookingData,
        ];
    }

    return $subArray;
}

$router->post('flights/tbo/search', function () use ($db) {

    // search_guard: release session lock before long supplier request
    @set_time_limit(120);
    $connectTimeout = defined('SUPPLIER_CONNECT_TIMEOUT') ? SUPPLIER_CONNECT_TIMEOUT : 10;
    $requestTimeout = defined('SUPPLIER_REQUEST_TIMEOUT') ? max(SUPPLIER_REQUEST_TIMEOUT, 60) : 60;

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (connection_aborted()) exit;

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');

    // -----------------------------------------------------------------------
    // Validate input
    // -----------------------------------------------------------------------
    $type = strtolower(trim($_POST['type'] ?? 'oneway'));
    $isMulticity = in_array($type, ['multicity', 'multiple'], true);
    $isRound     = in_array($type, ['round', 'return', 'roundtrip'], true);

    $multicityRoutes = [];
    if ($isMulticity) {
        $routesRaw = $_POST['routes'] ?? '[]';
        $multicityRoutes = is_array($routesRaw) ? $routesRaw : (json_decode((string)$routesRaw, true) ?: []);
        if (empty($multicityRoutes)) {
            echo json_encode(['status' => false, 'message' => 'Missing required parameter: routes', 'response' => []]);
            exit;
        }
        if (count($multicityRoutes) > 4) {
            echo json_encode(['status' => false, 'message' => 'You can only select up to 4 flight segments.', 'response' => []]);
            exit;
        }
        // Seed origin/destination/date from the first route for validation below
        $first = $multicityRoutes[0];
        $_POST['origin']         = $_POST['origin'] ?? ($first['from'] ?? '');
        $_POST['destination']    = $_POST['destination'] ?? ($first['to'] ?? '');
        $_POST['departure_date'] = $_POST['departure_date'] ?? ($first['date'] ?? '');
    }

    $required = ['origin', 'destination', 'departure_date', 'adults', 'childrens', 'infants', 'currency', 'type', 'class'];
    foreach ($required as $field) {
        if (!isset($_POST[$field]) || trim((string)$_POST[$field]) === '') {
            echo json_encode(['status' => false, 'message' => "$field is required", 'response' => []]);
            exit;
        }
    }
    if ($isRound && empty($_POST['return_date'])) {
        echo json_encode(['status' => false, 'message' => 'return_date is required', 'response' => []]);
        exit;
    }

    // -----------------------------------------------------------------------
    // Load credentials + token
    // -----------------------------------------------------------------------
    $credentials = getTboCredentials($db);
    if (!$credentials) {
        echo json_encode(['status' => false, 'message' => 'TBO module not configured', 'response' => []]);
        exit;
    }
    if (empty($credentials['username']) || empty($credentials['password'])) {
        echo json_encode(['status' => false, 'message' => 'TBO API credentials missing. Please add Username and Password in module settings.', 'response' => []]);
        exit;
    }

    $module = $credentials['module'];
    $urls   = getTboBaseUrls($credentials);

    $authError = null;
    $token = tboGetToken($db, $credentials, false, $authError);
    if (!$token) {
        echo json_encode([
            'status'   => false,
            'message'  => 'TBO authentication failed' . ($authError ? ': ' . $authError : '. Please check credentials.'),
            'response' => [],
        ]);
        exit;
    }

    // -----------------------------------------------------------------------
    // Build search payload
    // -----------------------------------------------------------------------
    $origin      = strtoupper(trim($_POST['origin']));
    $destination = strtoupper(trim($_POST['destination']));
    $currency    = strtoupper(trim($_POST['currency']));
    $adults      = max(1, (int)$_POST['adults']);
    $children    = max(0, (int)$_POST['childrens']);
    $infants     = max(0, (int)$_POST['infants']);
    $cabin       = tboCabinClass($_POST['class']);
    $clientIp    = tboClientIp();

    $segments = [];
    if ($isMulticity) {
        $journeyType = 3;
        foreach ($multicityRoutes as $route) {
            $from = strtoupper(trim($route['from'] ?? ''));
            $to   = strtoupper(trim($route['to'] ?? ''));
            $date = date('Y-m-d', strtotime(str_replace('-', '/', (string)($route['date'] ?? ''))));
            if ($from === '' || $to === '' || empty($route['date'])) {
                echo json_encode(['status' => false, 'message' => 'Invalid multicity route data', 'response' => []]);
                exit;
            }
            $segments[] = [
                'Origin'                 => $from,
                'Destination'            => $to,
                'FlightCabinClass'       => $cabin,
                'PreferredDepartureTime' => $date . 'T00:00:00',
                'PreferredArrivalTime'   => $date . 'T00:00:00',
                'PreferredAirlines'      => [],
            ];
        }
    } elseif ($isRound) {
        $journeyType   = 2;
        $departureDate = date('Y-m-d', strtotime($_POST['departure_date']));
        $returnDate    = date('Y-m-d', strtotime($_POST['return_date']));
        $segments[] = [
            'Origin'                 => $origin,
            'Destination'            => $destination,
            'FlightCabinClass'       => $cabin,
            'PreferredDepartureTime' => $departureDate . 'T00:00:00',
            'PreferredArrivalTime'   => $departureDate . 'T00:00:00',
            'PreferredAirlines'      => [],
        ];
        $segments[] = [
            'Origin'                 => $destination,
            'Destination'            => $origin,
            'FlightCabinClass'       => $cabin,
            'PreferredDepartureTime' => $returnDate . 'T00:00:00',
            'PreferredArrivalTime'   => $returnDate . 'T00:00:00',
            'PreferredAirlines'      => [],
        ];
    } else {
        $journeyType   = 1;
        $departureDate = date('Y-m-d', strtotime($_POST['departure_date']));
        $segments[] = [
            'Origin'                 => $origin,
            'Destination'            => $destination,
            'FlightCabinClass'       => $cabin,
            'PreferredDepartureTime' => $departureDate . 'T00:00:00',
            'PreferredArrivalTime'   => $departureDate . 'T00:00:00',
            'PreferredAirlines'      => [],
        ];
    }

    $buildPayload = function (array $tokenData) use ($clientIp, $origin, $destination, $journeyType, $adults, $children, $infants, $cabin, $segments) {
        return [
            'IPAddress'           => $clientIp,
            'TokenId'             => $tokenData['TokenId'],
            'EndUserBrowserAgent' => tboBrowserAgent(),
            'PointOfSale'         => $origin,
            'RequestOrigin'       => $destination,
            'UserData'            => null,
            'JourneyType'         => $journeyType,
            'AdultCount'          => $adults,
            'ChildCount'          => $children,
            'InfantCount'         => $infants,
            'FlightCabinClass'    => $cabin,
            'DirectFlight'        => false,
            'PreferredCarrier'    => null,
            'Segment'             => $segments,
        ];
    };

    // -----------------------------------------------------------------------
    // Call TBO Search (with one auth-refresh retry on token failure)
    // -----------------------------------------------------------------------
    if (connection_aborted()) exit;

    $searchUrl = $urls['search'] . '/api/v1/search/search';
    $result = tboApiPost($searchUrl, $buildPayload($token), $requestTimeout, $connectTimeout);

    if (($result['data'] === null || tboIsAuthError($result['data'])) && !$result['curl_error']) {
        $token = tboGetToken($db, $credentials, true);
        if ($token) {
            $result = tboApiPost($searchUrl, $buildPayload($token), $requestTimeout, $connectTimeout);
        }
    }

    tboLog($db, 'api/v1/search/search', ['JourneyType' => $journeyType, 'Segments' => $segments, 'TokenId' => '***'], $result['raw'], $result['http_code'], $result['curl_error']);

    if ($result['curl_error']) {
        echo json_encode(['status' => false, 'message' => 'Search request failed: ' . $result['curl_error'], 'response' => []]);
        exit;
    }

    $decoded = $result['data'];
    if (!is_array($decoded) || empty($decoded['Results']) || !is_array($decoded['Results'])) {
        echo json_encode([]);
        exit;
    }

    $responseTokenId    = $decoded['TokenId'] ?? $token['TokenId'];
    $responseTrackingId = $decoded['TrackingId'] ?? ($token['TrackingId'] ?? '');

    // -----------------------------------------------------------------------
    // Normalize results
    // TBO groups results as Results[][]; each item carries Segments[][] where
    // each inner group is one itinerary leg (outbound / return / multicity leg).
    // -----------------------------------------------------------------------
    $finalArray = [];
    $maxResults = 200;

    foreach ($decoded['Results'] as $resultGroup) {
        if (!is_array($resultGroup)) continue;

        foreach ($resultGroup as $value) {
            if (connection_aborted()) exit;
            if (count($finalArray) >= $maxResults) break 2;
            if (!is_array($value) || empty($value['Segments']) || !is_array($value['Segments'])) continue;

            $resultId = $value['ResultId'] ?? '';
            if ($resultId === '') continue;

            // ---------------- Fare ----------------
            $fare         = $value['Fare'] ?? [];
            $breakdowns   = $value['FareBreakdown'] ?? [];
            $fareCurrency = strtoupper($breakdowns[0]['Currency'] ?? ($fare['Currency'] ?? 'USD'));
            $totalFare    = (float)($fare['TotalFare'] ?? 0);
            if ($totalFare <= 0) continue;

            // Per-passenger-type totals (PassengerType 1=ADT 2=CHD 3=INF)
            $adtTotal = $chdTotal = $infTotal = 0;
            foreach ($breakdowns as $bd) {
                $ptype  = (int)($bd['PassengerType'] ?? 0);
                $ptotal = (float)($bd['TotalFare'] ?? 0);
                if ($ptype === 1) $adtTotal = $ptotal;
                if ($ptype === 2) $chdTotal = $ptotal;
                if ($ptype === 3) $infTotal = $ptotal;
            }
            if ($adtTotal <= 0) $adtTotal = $totalFare;

            $markedUpTotal  = MARKUP($totalFare, $module, $db, $fareCurrency, $currency);
            $convertedTotal = CURRENCY_CONVERT($totalFare, $db, $fareCurrency, $currency);

            $adtMarkup = MARKUP($adtTotal, $module, $db, $fareCurrency, $currency);
            $chdMarkup = $chdTotal > 0 ? MARKUP($chdTotal, $module, $db, $fareCurrency, $currency) : ['price' => 0];
            $infMarkup = $infTotal > 0 ? MARKUP($infTotal, $module, $db, $fareCurrency, $currency) : ['price' => 0];

            $adtConverted = CURRENCY_CONVERT($adtTotal, $db, $fareCurrency, $currency);
            $chdConverted = $chdTotal > 0 ? CURRENCY_CONVERT($chdTotal, $db, $fareCurrency, $currency) : ['price' => 0];
            $infConverted = $infTotal > 0 ? CURRENCY_CONVERT($infTotal, $db, $fareCurrency, $currency) : ['price' => 0];

            $priceCtx = [
                'currency'            => $currency,
                'price'               => number_format($markedUpTotal['price'], 2, '.', ''),
                'actual_price'        => number_format($convertedTotal['price'], 2, '.', ''),
                'adult_price'         => number_format($adtMarkup['price'], 2, '.', ''),
                'child_price'         => number_format($chdMarkup['price'], 2, '.', ''),
                'infant_price'        => number_format($infMarkup['price'], 2, '.', ''),
                'actual_adult_price'  => number_format($adtConverted['price'], 2, '.', ''),
                'actual_child_price'  => number_format($chdConverted['price'], 2, '.', ''),
                'actual_infant_price' => number_format($infConverted['price'], 2, '.', ''),
            ];

            // Refundable: prefer IsRefundable, else invert NonRefundable
            if (array_key_exists('IsRefundable', $value)) {
                $refundable = !empty($value['IsRefundable']) ? 1 : 0;
            } else {
                $refundable = empty($value['NonRefundable']) ? 1 : 0;
            }

            $bookingData = [
                'booking_token' => $resultId,
                'ResultId'      => $resultId,
                'TokenId'       => $responseTokenId,
                'TrackingId'    => $responseTrackingId,
                'IsLcc'         => !empty($value['IsLcc']),
                'ip'            => $clientIp,
                'PointOfSale'   => $origin,
                'RequestOrigin' => $destination,
                'source_currency' => $fareCurrency,
                'currency'      => $currency,
                'amount'        => $markedUpTotal['price'],
                'actual_amount' => $convertedTotal['price'],
                'supplier'      => 'tbo',
            ];

            $returnArray = [];
            foreach ($value['Segments'] as $segmentGroup) {
                if (!is_array($segmentGroup) || empty($segmentGroup)) continue;
                $returnArray['segments'][] = tboProcessSegments(
                    $db, $module, array_values($segmentGroup), $priceCtx, $bookingData, $refundable, $type
                );
            }

            if (!empty($returnArray['segments'])) {
                // Multicity results must return exactly one group per requested leg
                if ($isMulticity && count($returnArray['segments']) !== count($segments)) {
                    continue;
                }
                $finalArray[] = $returnArray;
            }
        }
    }

    echo json_encode(!empty($finalArray) ? $finalArray : []);
});

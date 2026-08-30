<?php
// path: modules/flights/mystifly/search.php
// Mystifly flight search route
// POST flights/mystifly/search

global $router;

$router->post('flights/mystifly/search', function () use ($db) {
    $type = $_POST['type'] ?? 'oneway';
    if ($type === 'multicity') {
        echo json_encode([]);
        exit;
    }


    // search_guard: release session lock before long supplier request
    // Mystifly recommends a 120-second cut-off for all API calls including BookFlight.
    // PHP execution time must exceed the cURL timeout to avoid premature termination.
    @set_time_limit(180);
    $connectTimeout = defined('SUPPLIER_CONNECT_TIMEOUT') ? SUPPLIER_CONNECT_TIMEOUT : 10;
    $requestTimeout = defined('SUPPLIER_REQUEST_TIMEOUT') ? SUPPLIER_REQUEST_TIMEOUT : 120;

    $searchSessionData = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $searchSessionData = $_SESSION;
        session_write_close();
    }

    if (connection_aborted()) exit;

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');

    // -----------------------------------------------------------------------
    // Validate required inputs
    // -----------------------------------------------------------------------
    $required = ['origin', 'destination', 'departure_date', 'adults', 'childrens', 'infants', 'currency', 'type', 'class'];
    foreach ($required as $field) {
        if (!isset($_POST[$field]) || trim($_POST[$field]) === '') {
            echo json_encode(['status' => false, 'message' => "$field is required", 'response' => []]);
            exit;
        }
    }

    // -----------------------------------------------------------------------
    // Load credentials
    // -----------------------------------------------------------------------
    $credentials = getMystiflyCredentials($db);
    if (!$credentials) {
        echo json_encode(['status' => false, 'message' => 'Mystifly module not configured', 'response' => []]);
        exit;
    }

    $module  = $credentials['module'];
    $baseUrl = getMystiflyBaseUrl($credentials);
    $token   = getMystiflyBearerToken($db, $credentials);

    if (!$token) {
        echo json_encode(['status' => false, 'message' => 'Mystifly API credentials are not configured.', 'response' => []]);
        exit;
    }

    // -----------------------------------------------------------------------
    // Build request
    // -----------------------------------------------------------------------
    $type        = strtolower(trim($_POST['type']));
    $origin      = strtoupper(trim($_POST['origin']));
    $destination = strtoupper(trim($_POST['destination']));
    $currency    = strtoupper(trim($_POST['currency']));
    $cabinClass  = trim($_POST['class']); // economy | business | first | premium_economy

    $departureDate = date('Y-m-d', strtotime($_POST['departure_date']));
    $returnDate    = !empty($_POST['return_date']) ? date('Y-m-d', strtotime($_POST['return_date'])) : null;

    $adults   = max(1, (int)$_POST['adults']);
    $children = max(0, (int)$_POST['childrens']);
    $infants  = max(0, (int)$_POST['infants']);

    // Map PHP Travels cabin class to Mystifly CabinType
    $cabinMap = [
        'economy'          => 'Y',
        'premium_economy'  => 'S',
        'business'         => 'C',
        'first'            => 'F',
    ];
    $mystiflyClass = $cabinMap[strtolower($cabinClass)] ?? 'Y';

    // Build OriginDestinationInformation
    $odInfo = [[
        'DepartureDateTime'          => $departureDate . 'T00:00:00',
        'DestinationLocationCode'    => $destination,
        'OriginLocationCode'         => $origin,
        'RPH'                        => '1',
        'ResBookDesigCode'           => $mystiflyClass,
    ]];

    if ($type === 'return' && $returnDate) {
        $odInfo[] = [
            'DepartureDateTime'       => $returnDate . 'T00:00:00',
            'DestinationLocationCode' => $origin,
            'OriginLocationCode'      => $destination,
            'RPH'                     => '2',
            'ResBookDesigCode'        => $mystiflyClass,
        ];
    }

    // Passenger type quantities
    $passengerTypeQuantity = [];
    if ($adults > 0)   $passengerTypeQuantity[] = ['Code' => 'ADT', 'Quantity' => $adults];
    if ($children > 0) $passengerTypeQuantity[] = ['Code' => 'CHD', 'Quantity' => $children];
    if ($infants > 0)  $passengerTypeQuantity[] = ['Code' => 'INF', 'Quantity' => $infants];

    $target = (strtolower($credentials['environment']) === 'live') ? 'Live' : 'Test';

    $airTripType = ($type === 'return' || $type === 'roundtrip') ? 'Return' : 'OneWay';

    $payload = [
        'OriginDestinationInformations' => $odInfo,
        'TravelPreferences' => [
            'MaxStopsQuantity' => 'All',
            'CabinPreference'  => $mystiflyClass,
            'Preferences'      => [
                'CabinClassPreference' => [
                    'CabinType'        => $mystiflyClass,
                    'PreferenceLevel'  => 'Preferred',
                ],
            ],
            'AirTripType' => $airTripType,
        ],
        'PricingSourceType'      => 'All',
        'IsRefundable'           => false,
        'PassengerTypeQuantities' => $passengerTypeQuantity,
        'RequestOptions'         => 'Fifty',
        'Target'                 => $target,
        'ConversationId'         => uniqid('mf_', true),
    ];

    // -----------------------------------------------------------------------
    // Call Mystifly Search V1
    // -----------------------------------------------------------------------
    if (connection_aborted()) exit;

    $ch = curl_init($baseUrl . '/api/v1/Search/Flight');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT        => $requestTimeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ],
    ]);

    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    mystiflyLog($db, 'api/v1/Search/Flight', $payload, $raw, $httpCode, $curlErr ?: null);

    if ($curlErr) {
        echo json_encode(['status' => false, 'message' => 'Search request failed: ' . $curlErr, 'response' => []]);
        exit;
    }

    $decoded = json_decode($raw, true);

    // v1 response: { "Success": true, "Data": { "PricedItineraries": [...] } }
    $data = $decoded['Data'] ?? $decoded;

    if (empty($data['PricedItineraries'])) {
        echo json_encode([]);
        exit;
    }

    // -----------------------------------------------------------------------
    // Normalize results from v1 response
    // In v1: FareSourceCode is inside AirItineraryPricingInfo, segments are
    // inline in OriginDestinationOptions.OriginDestinationOption[].FlightSegment[]
    // -----------------------------------------------------------------------
    $finalArray = [];

    foreach ($data['PricedItineraries'] as $itinerary) {
        if (connection_aborted()) exit;

        $priceInfo      = $itinerary['AirItineraryPricingInfo'] ?? [];
        $fareSourceCode = $priceInfo['FareSourceCode'] ?? '';
        if (empty($fareSourceCode)) continue;

        // Fare info
        $fareCurrency   = $priceInfo['ItinTotalFare']['TotalFare']['CurrencyCode'] ?? 'USD';
        $fareType       = $priceInfo['FareType'] ?? '';
        $isRefundableRaw = $priceInfo['IsRefundable'] ?? 'No';
        $isRefundable   = in_array(strtolower((string)$isRefundableRaw), ['yes', 'true', '1'], true);
        $nameCharLimit  = (int)($itinerary['PaxNameCharacterLimit'] ?? 26);
        $holdAllowedRaw = $itinerary['HoldAllowed'] ?? false;
        $holdAllowedFlight = is_string($holdAllowedRaw) ? (strtolower($holdAllowedRaw) === 'true') : (bool)$holdAllowedRaw;

        // Per-pax pricing from PTC_FareBreakdowns
        // v1: PTC_FareBreakdowns is a direct array (no 'PTC_FareBreakdown' sub-key)
        $ptcBreakdowns = $priceInfo['PTC_FareBreakdowns'] ?? [];
        if (!empty($ptcBreakdowns) && !isset($ptcBreakdowns[0])) {
            $ptcBreakdowns = [$ptcBreakdowns];
        }

        $totalAmount = 0;
        $adtFare = 0; $chdFare = 0; $infFare = 0;
        foreach ($ptcBreakdowns as $ptc) {
            $paxCode  = $ptc['PassengerTypeQuantity']['Code'] ?? 'ADT';
            $qty      = max(1, (int)($ptc['PassengerTypeQuantity']['Quantity'] ?? 1));
            $unitFare = (float)($ptc['PassengerFare']['TotalFare']['Amount'] ?? 0);
            $totalAmount += $unitFare * $qty;
            if ($paxCode === 'ADT') $adtFare = $unitFare;
            if ($paxCode === 'CHD') $chdFare = $unitFare;
            if ($paxCode === 'INF') $infFare = $unitFare;
        }
        // Fallback to ItinTotalFare if PTC breakdown gives nothing
        if ($totalAmount <= 0) {
            $totalAmount = (float)($priceInfo['ItinTotalFare']['TotalFare']['Amount'] ?? 0);
        }
        if ($totalAmount <= 0) continue;

        // Currency conversion + markup
        $convertedPrice = CURRENCY_CONVERT($totalAmount, $db, $fareCurrency, $currency);
        $markedUpPrice  = MARKUP($totalAmount, $module, $db, $fareCurrency, $currency);

        $adtConverted = $adtFare > 0 ? CURRENCY_CONVERT($adtFare, $db, $fareCurrency, $currency) : $convertedPrice;
        $chdConverted = $chdFare > 0 ? CURRENCY_CONVERT($chdFare, $db, $fareCurrency, $currency) : $convertedPrice;
        $infConverted = $infFare > 0 ? CURRENCY_CONVERT($infFare, $db, $fareCurrency, $currency) : $convertedPrice;
        $adtMarkup    = $adtFare > 0 ? MARKUP($adtFare, $module, $db, $fareCurrency, $currency) : $markedUpPrice;
        $chdMarkup    = $chdFare > 0 ? MARKUP($chdFare, $module, $db, $fareCurrency, $currency) : $markedUpPrice;
        $infMarkup    = $infFare > 0 ? MARKUP($infFare, $module, $db, $fareCurrency, $currency) : $markedUpPrice;

        // v1: OriginDestinationOptions is a direct array of OD objects
        $odOptions = $itinerary['OriginDestinationOptions'] ?? [];
        if (!empty($odOptions) && !isset($odOptions[0])) {
            $odOptions = [$odOptions];
        }

        $returnArray = [];

        foreach ($odOptions as $od) {
            $subArray = [];
            // v1: key is FlightSegments (plural)
            $segs     = $od['FlightSegments'] ?? [];
            if (!empty($segs) && !isset($segs[0])) $segs = [$segs];

            $segCount  = count($segs);
            $totalMins = 0;
            foreach ($segs as $seg) {
                $dur = $seg['JourneyDuration'] ?? '';
                if (is_numeric($dur)) {
                    $totalMins += (int)$dur;
                } elseif (preg_match('/(\d+):(\d+)/', (string)$dur, $m)) {
                    $totalMins += (int)$m[1] * 60 + (int)$m[2];
                }
            }
            $totalHours   = floor($totalMins / 60);
            $totalMinutes = $totalMins % 60;

            foreach ($segs as $seg) {
                $depAt   = $seg['DepartureDateTime'] ?? '';
                $arrAt   = $seg['ArrivalDateTime'] ?? '';
                $segDur  = $seg['JourneyDuration'] ?? '';
                $segMins = is_numeric($segDur) ? (int)$segDur : 0;
                if (!$segMins && preg_match('/(\d+):(\d+)/', (string)$segDur, $m)) {
                    $segMins = (int)$m[1] * 60 + (int)$m[2];
                }
                $segH = floor($segMins / 60);
                $segM = $segMins % 60;

                // v1 carrier: OperatingAirline.Code / FlightNumber
                $carrier   = $seg['OperatingAirline']['Code'] ?? $seg['MarketingAirlineLine']['Code'] ?? '';
                $flightNum = $seg['OperatingAirline']['FlightNumber'] ?? $seg['FlightNumber'] ?? '';

                // Baggage: v1 Search puts it in PTC_FareBreakdowns[0].BaggageInfo[0]
                $baggageRaw = $ptcBreakdowns[0]['BaggageInfo'][0] ?? ($seg['FreeBaggageAllowance'] ?? '');
                $baggage    = is_array($baggageRaw)
                    ? (($baggageRaw['Weight'] ?? '') . ($baggageRaw['Unit'] ?? '') ?: 'Check fare rules')
                    : ($baggageRaw ?: 'Check fare rules');

                $subArray[] = (object)[
                    'img'              => $carrier,
                    'flight_no'        => $carrier . $flightNum,
                    'airline'          => $carrier,
                    'class'            => $cabinClass,
                    'booking_class'    => $seg['ResBookDesigCode'] ?? '',
                    'baggage'          => $baggage,
                    'cabin_baggage'    => 'Cabin bag included',
                    'departure_airport'=> $seg['DepartureAirportLocationCode'] ?? '',
                    'departure_time'   => !empty($depAt) ? date('h:i a', strtotime($depAt)) : '',
                    'arrival_airport'  => $seg['ArrivalAirportLocationCode'] ?? '',
                    'arrival_time'     => !empty($arrAt) ? date('h:i a', strtotime($arrAt)) : '',
                    'departure_date'   => !empty($depAt) ? date('d-m-Y', strtotime($depAt)) : '',
                    'arrival_date'     => !empty($arrAt) ? date('d-m-Y', strtotime($arrAt)) : '',
                    'departure_code'   => $seg['DepartureAirportLocationCode'] ?? '',
                    'arrival_code'     => $seg['ArrivalAirportLocationCode'] ?? '',
                    'stops'            => $segCount - 1,
                    'currency'         => $currency,
                    'price'            => number_format($markedUpPrice['price'], 2, '.', ''),
                    'actual_price'     => number_format($convertedPrice['price'], 2, '.', ''),
                    'duration_time'    => $segH . ':' . str_pad($segM, 2, '0', STR_PAD_LEFT),
                    'total_duration'   => $totalHours . ':' . str_pad($totalMinutes, 2, '0', STR_PAD_LEFT),
                    'adult_price'      => number_format($adtMarkup['price'], 2, '.', ''),
                    'child_price'      => number_format($chdMarkup['price'], 2, '.', ''),
                    'infant_price'     => number_format($infMarkup['price'], 2, '.', ''),
                    'actual_adult_price'  => number_format($adtConverted['price'], 2, '.', ''),
                    'actual_child_price'  => number_format($chdConverted['price'], 2, '.', ''),
                    'actual_infant_price' => number_format($infConverted['price'], 2, '.', ''),
                    'refundable'       => $isRefundable,
                    'fare_type'        => $fareType,
                    'hold_allowed'     => $holdAllowedFlight,
                    'has_ancillaries'  => true,
                    'supplier'         => 'mystifly',
                    'module'           => 'mystifly',
                    'type'             => $type,
                    'options'          => '',
                    'redirect_url'     => '',
                    'booking_data'     => [
                        'booking_token'    => $fareSourceCode,
                        'FareSourceCode'   => $fareSourceCode,
                        'fare_source_code' => $fareSourceCode,
                        'hold_allowed'     => $holdAllowedFlight,
                        'fare_type'        => $fareType,
                        'currency'         => $currency,
                        'amount'           => $markedUpPrice['price'],
                        'actual_amount'    => $convertedPrice['price'],
                        'supplier'         => 'mystifly',
                    ],
                ];
            }

            $returnArray['segments'][] = $subArray;
        }

        if (!empty($returnArray)) {
            $finalArray[] = $returnArray;
        }
    }

    echo json_encode(!empty($finalArray) ? $finalArray : []);
});

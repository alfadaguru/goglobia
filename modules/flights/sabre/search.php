<?php

$router->post('flights/sabre/search', function () use ($db) {
    $type = $_POST['type'] ?? 'oneway';
    $typeNormalized = strtolower(trim($type));
    $isMulticity = in_array($typeNormalized, ['multicity', 'multiple'], true);

    // search_guard_v2: session lock release + configurable timeouts for long supplier requests
    @set_time_limit(30);
    $connectTimeout = defined('SUPPLIER_CONNECT_TIMEOUT') ? SUPPLIER_CONNECT_TIMEOUT : 10;
    $requestTimeout = defined('SUPPLIER_REQUEST_TIMEOUT') ? SUPPLIER_REQUEST_TIMEOUT : 30;
    $searchSessionData = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $searchSessionData = $_SESSION;
        session_write_close();
 if (defined('DEBUG_SEARCH_GUARD') && DEBUG_SEARCH_GUARD === true) {
    error_log('search_guard: session lock released');
}
    }

    if (connection_aborted()) {
 if (defined('DEBUG_SEARCH_GUARD') && DEBUG_SEARCH_GUARD === true) {
    error_log('search_guard: request aborted early');
}
        exit;
    }

    // DB QUERY
    $module = ($db->get("modules", "*", ["name" => "sabre", "type" => "flights"]));

    if (!$module) {
        echo json_encode([
            'status' => false,
            'message' => 'Sabre module not configured',
            'response' => []
        ]);
        exit;
    }

    $currency = strtoupper($_POST['currency'] ?? 'USD');

    try {

        // Validate required fields. For multicity require `routes` (POST or session)
        if ($isMulticity) {
            $routesRaw = $_POST['routes'] ?? null;
            $routesPresent = (!empty($routesRaw) && trim(is_string($routesRaw) ? $routesRaw : json_encode($routesRaw)) !== '') || (!empty($_SESSION['multicity_routes']) && is_array($_SESSION['multicity_routes']));
            if (!$routesPresent) {
                echo json_encode(['status' => false, 'message' => 'routes is required for multicity requests (POST routes or set in session)', 'response' => []]);
                exit;
            }
            \REQUIRED(['adults', 'childrens', 'infants', 'currency', 'type', 'class']);
        } else {
            \REQUIRED(['origin', 'destination', 'departure_date', 'adults', 'childrens', 'infants', 'currency', 'type', 'class']);
        }

        // Get credentials from database
        $pcc = $module['c1'];
        $epr = $module['c2'];
        $domain = $module['c3'];
        $password = $module['c4'];
        $dev_mode = $module['dev_mode'];

        // Build endpoint based on dev_mode (1 = test, 0 = production)
        $base_endpoint = ($dev_mode == 1)
            ? 'https://api.cert.platform.sabre.com'
            : 'https://api.platform.sabre.com';

        // ============================================================
        // GET ACCESS TOKEN
        // ============================================================
        $v1 = "V1:{$epr}:{$pcc}:{$domain}";
        $b_v1 = base64_encode($v1);
        $b_pwd = base64_encode($password);
        $auth = base64_encode($b_v1 . ':' . $b_pwd);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
        curl_setopt_array($ch, [
            CURLOPT_URL => $base_endpoint . '/v2/auth/token',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . $auth,
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $token_response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            throw new Exception('Authentication failed');
        }

        $token_data = json_decode($token_response, true);
        $access_token = $token_data['access_token'];

        // ============================================================
        // PREPARE SEARCH PARAMS
        // ============================================================
        $type = trim($_POST['type']);
        $class_code = 'Y'; // Default Economy

        $class_lower = strtolower(trim($_POST['class']));
        if ($class_lower == 'economy') {
            $class_code = 'Y';
        }
        if ($class_lower == 'economy premium') {
            $class_code = 'S';
        }
        if ($class_lower == 'business') {
            $class_code = 'C';
        }
        if ($class_lower == 'first class') {
            $class_code = 'F';
        }

        $departure_date = null;

        $adults = intval($_POST['adults']);
        $children = intval($_POST['childrens']);
        $infants = intval($_POST['infants']);

        // ============================================================
        // BUILD SLICES (support multicity via `routes` POST or session)
        // ============================================================
        $slices = [];
        if ($isMulticity) {
            $routesRaw = $_POST['routes'] ?? null;
            if (empty($routesRaw) && !empty($_SESSION['multicity_routes'])) {
                $routes = $_SESSION['multicity_routes'];
            } else {
                if (is_string($routesRaw)) {
                    $routes = json_decode($routesRaw, true) ?? [];
                } elseif (is_array($routesRaw)) {
                    $routes = $routesRaw;
                } else {
                    $routes = [];
                }
            }

            if (empty($routes) || !is_array($routes)) {
                echo json_encode(['status' => false, 'message' => 'Invalid or empty routes provided for multicity', 'response' => []]);
                exit;
            }

            if (count($routes) > 4) {
                echo json_encode(['status' => false, 'message' => 'You can only select up to 4 flight segments for multicity requests', 'response' => []]);
                exit;
            }

            foreach ($routes as $r) {
                $from = strtoupper(trim($r['from'] ?? $r->from ?? ''));
                $to = strtoupper(trim($r['to'] ?? $r->to ?? ''));
                $date_input = $r['date'] ?? $r->date ?? '';
                if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $date_input)) {
                    list($d,$m,$y) = explode('-', $date_input);
                    $date = $y . '-' . $m . '-' . $d;
                } else {
                    $date = date('Y-m-d', strtotime($date_input));
                }
                if (empty($from) || empty($to) || empty($date)) continue;
                $slices[] = [
                    'origin' => $from,
                    'destination' => $to,
                    'departure_date' => $date
                ];
            }

            if (empty($slices)) {
                echo json_encode(['status' => false, 'message' => 'No valid multicity legs could be built', 'response' => []]);
                exit;
            }
        } else {
            $departure_date = date('Y-m-d', strtotime($_POST['departure_date']));
            $slices = [
                [
                    'origin' => strtoupper(trim($_POST['origin'])),
                    'destination' => strtoupper(trim($_POST['destination'])),
                    'departure_date' => $departure_date
                ]
            ];

            if ($type != 'oneway' && isset($_POST['return_date'])) {
                $return_date = date('Y-m-d', strtotime($_POST['return_date']));
                $slices[] = [
                    'origin' => strtoupper(trim($_POST['destination'])),
                    'destination' => strtoupper(trim($_POST['origin'])),
                    'departure_date' => $return_date
                ];
            }
        }

        // ============================================================
        // BUILD PASSENGERS ARRAY
        // ============================================================
        $passengers = [];
        if ($adults > 0) {
            $passengers[] = ['type' => 'ADT', 'quantity' => $adults];
        }
        if ($children > 0) {
            $passengers[] = ['type' => 'CNN', 'quantity' => $children];
        }
        if ($infants > 0) {
            $passengers[] = ['type' => 'INF', 'quantity' => $infants];
        }

        // Build offer passengers list for ancillaries (each pax gets unique id)
        $offer_passengers = [];
        $pax_counter = 1;
        for ($i = 0; $i < $adults; $i++) {
            $offer_passengers[] = ['id' => 'pax_' . $pax_counter++, 'type' => 'ADT'];
        }
        for ($i = 0; $i < $children; $i++) {
            $offer_passengers[] = ['id' => 'pax_' . $pax_counter++, 'type' => 'CNN'];
        }
        for ($i = 0; $i < $infants; $i++) {
            $offer_passengers[] = ['id' => 'pax_' . $pax_counter++, 'type' => 'INF'];
        }

        // ============================================================
        // BUILD SEARCH PAYLOAD
        // ============================================================
        $payload = [
            'OTA_AirLowFareSearchRQ' => [
                'Version' => '1',
                'POS' => [
                    'Source' => [
                        [
                            'PseudoCityCode' => $pcc,
                            'RequestorID' => [
                                'Type' => '1',
                                'ID' => '1',
                                'CompanyName' => ['Code' => 'TN']
                            ]
                        ]
                    ]
                ],
                'OriginDestinationInformation' => [],
                'TravelPreferences' => [
                    'CabinPref' => [
                        [
                            'Cabin' => $class_code,
                            'PreferLevel' => 'Preferred'
                        ]
                    ]
                ],
                'TravelerInfoSummary' => [
                            'SeatsRequested' => [count($offer_passengers)],
                            'AirTravelerAvail' => []
                        ],
                'TPA_Extensions' => [
                    'IntelliSellTransaction' => [
                        'RequestType' => ['Name' => '200ITINS']
                    ]
                ]
            ]
        ];

        // Add origin/destination info
        foreach ($slices as $index => $slice) {
            $payload['OTA_AirLowFareSearchRQ']['OriginDestinationInformation'][] = [
                'RPH' => (string) ($index + 1),
                'DepartureDateTime' => $slice['departure_date'] . 'T00:00:00',
                'OriginLocation' => ['LocationCode' => $slice['origin']],
                'DestinationLocation' => ['LocationCode' => $slice['destination']],
                'TPA_Extensions' => ['SegmentType' => ['Code' => 'O']]
            ];
        }

        // Add passenger info
        foreach ($passengers as $passenger) {
            $payload['OTA_AirLowFareSearchRQ']['TravelerInfoSummary']['AirTravelerAvail'][] = [
                'PassengerTypeQuantity' => [
                    [
                        'Code' => $passenger['type'],
                        'Quantity' => $passenger['quantity']
                    ]
                ]
            ];
        }

        // ============================================================
        // MAKE SEARCH REQUEST
        // ============================================================
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
        curl_setopt_array($ch, [
            CURLOPT_URL => $base_endpoint . '/v4/offers/shop',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $access_token,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            throw new Exception('cURL Error: ' . curl_error($ch));
        }
        curl_close($ch);

        $decode = json_decode($result, true);

        if ($http_code !== 200 || empty($decode['groupedItineraryResponse'])) {
            // Do NOT leak Sabre HTTP codes / error internals to the client.
            error_log('SABRE SEARCH: no results (HTTP ' . $http_code . ') keys=' . implode(',', array_keys($decode ?? [])) . ' err=' . json_encode($decode['error'] ?? $decode['Errors'] ?? null));
            echo json_encode(['status' => false, 'message' => 'No flights found for this search. Please try again.']);
            return;
        }

        $grouped_response = $decode['groupedItineraryResponse'];

        $schedules = $grouped_response['scheduleDescs'] ?? [];
        $leg_descs = $grouped_response['legDescs'] ?? [];
        $itineraries = $grouped_response['itineraryGroups'][0]['itineraries'] ?? [];

        if (empty($itineraries)) {
            echo json_encode([]);
            return;
        }

        // ============================================================
        // BUILD LOOKUPS BY ID
        // ============================================================
        $schedule_lookup = [];
        foreach ($schedules as $schedule) {
            $schedule_lookup[$schedule['id']] = $schedule;
        }

        $leg_lookup = [];
        foreach ($leg_descs as $leg) {
            $leg_lookup[$leg['id']] = $leg;
        }

        // ============================================================
        // PROCESS ITINERARIES
        // ============================================================
        $final_array = [];

        foreach ($itineraries as $itin_index => $itinerary) {

            $pricing_info = $itinerary['pricingInformation'][0];

            // Get total price and actual currency from Sabre response
            $total_fare = floatval($pricing_info['fare']['totalFare']['totalPrice']);
            $actual_currency = $pricing_info['fare']['totalFare']['currency'];

            // Apply markup and conversion using helpers
            $marked_up_total = MARKUP($total_fare, $module, $db, $actual_currency, $currency);
            $converted_total = CURRENCY_CONVERT($total_fare, $db, $actual_currency, $currency);

            $adult_price_raw = 0;
            $child_price_raw = 0;
            $infant_price_raw = 0;
            $baggage_allowance = 0;

            foreach ($pricing_info['fare']['passengerInfoList'] as $pax) {
                $pax_info = $pax['passengerInfo'];
                $pax_type = $pax_info['passengerType'];
                $pax_total = floatval($pax_info['passengerTotalFare']['totalFare'] ?? 0);

                if ($pax_type === 'ADT') {
                    $adult_price_raw = $pax_total;

                    // Extract included baggage allowance from ADT fare
                    $baggage_info = $pax_info['baggageInformation'][0] ?? [];
                    if (!empty($baggage_info)) {
                        $baggage_allowance = $baggage_info['allowance']['pieces']
                            ?? $baggage_info['allowance']['weight']
                            ?? 0;
                    }
                } elseif ($pax_type === 'CNN') {
                    $child_price_raw = $pax_total;
                } elseif ($pax_type === 'INF') {
                    $infant_price_raw = $pax_total;
                }
            }

            // Apply markup and conversion to group totals
            $marked_up_adult = MARKUP($adult_price_raw * $adults, $module, $db, $actual_currency, $currency);
            $marked_up_child = MARKUP($child_price_raw * $children, $module, $db, $actual_currency, $currency);
            $marked_up_infant = MARKUP($infant_price_raw * $infants, $module, $db, $actual_currency, $currency);

            $converted_adult = CURRENCY_CONVERT($adult_price_raw * $adults, $db, $actual_currency, $currency);
            $converted_child = CURRENCY_CONVERT($child_price_raw * $children, $db, $actual_currency, $currency);
            $converted_infant = CURRENCY_CONVERT($infant_price_raw * $infants, $db, $actual_currency, $currency);

            $return_array = [];

            // ============================================================
            // PROCESS EACH LEG (outbound, inbound for roundtrip)
            // ============================================================
            foreach ($itinerary['legs'] as $leg) {
                $leg_ref = $leg['ref'];
                $leg_desc = $leg_lookup[$leg_ref];

                $sub_array = [];
                $total = [];

                // Collect all schedule IDs for this leg
                $schedule_ids = [];
                foreach ($leg_desc['schedules'] as $sched_ref) {
                    $schedule_ids[] = $sched_ref['ref'];
                }

                // Calculate total leg duration
                foreach ($schedule_ids as $sched_id) {
                    $schedule = $schedule_lookup[$sched_id];
                    $total[] = \timetodate(
                        $schedule['departure']['time'],
                        $schedule['arrival']['time']
                    );
                }

                $timesString = implode(":", $total);
                preg_match_all('/(\d{1,}):(\d{1,})/', $timesString, $matches);
                $totalHours = array_sum($matches[1]);
                $totalMinutes = array_sum($matches[2]);
                $totalHours += floor($totalMinutes / 60);
                $totalMinutes %= 60;

                // ============================================================
                // BUILD offer_id JSON for ancillaries & seat-map
                // Contains all segments in this leg so the ancillary endpoints
                // can reconstruct the full itinerary without any extra lookups.
                // ============================================================
                $offer_segments = [];
                foreach ($schedule_ids as $sched_id) {
                    $schedule = $schedule_lookup[$sched_id];
                    $offer_segments[] = [
                        'origin' => $schedule['departure']['airport'],
                        'destination' => $schedule['arrival']['airport'],
                        'carrier' => $schedule['carrier']['marketing'],
                        'operating_carrier' => $schedule['carrier']['operating'] ?? $schedule['carrier']['marketing'],
                        'flight_number' => $schedule['carrier']['marketingFlightNumber'],
                        'departure_date' => date('Y-m-d', strtotime($schedule['departure']['time'])),
                        'departure_time' => date('H:i:s', strtotime($schedule['departure']['time'])),
                        'arrival_time' => date('H:i:s', strtotime($schedule['arrival']['time'])),
                        'class' => $class_code,
                    ];
                }

                $offer_id_json = json_encode([
                    'segments' => $offer_segments,
                    'passengers' => $offer_passengers,
                    'currency' => $currency,
                    'pcc' => $pcc,
                    'itinerary_index' => $itin_index,
                    'leg_ref' => $leg_ref,
                    'included_baggage' => $baggage_allowance,
                ]);

                // ============================================================
                // PROCESS EACH SEGMENT IN THE LEG
                // ============================================================
                foreach ($schedule_ids as $sched_id) {
                    $schedule = $schedule_lookup[$sched_id];

                    $carrier = $schedule['carrier']['marketing'];
                    $flight_number = $schedule['carrier']['marketingFlightNumber'];
                    $dep_airport = $schedule['departure']['airport'];
                    $arr_airport = $schedule['arrival']['airport'];
                    $dep_time = $schedule['departure']['time'];
                    $arr_time = $schedule['arrival']['time'];
                    $cabin_class = strtolower($_POST['class']);

                    $sub_array[] = (object) [
                        'img' => $carrier,
                        'flight_no' => $flight_number,
                        'airline' => $carrier,
                        'class' => $cabin_class,
                        'baggage' => $baggage_allowance > 0 ? $baggage_allowance . 'PC' : '',
                        'cabin_baggage' => '',
                        'departure_airport' => $dep_airport,
                        'departure_time' => date('h:i a', strtotime($dep_time)),
                        'arrival_airport' => $arr_airport,
                        'arrival_time' => date('h:i a', strtotime($arr_time)),
                        'departure_date' => date('d-m-Y', strtotime($dep_time)),
                        'arrival_date' => date('d-m-Y', strtotime($arr_time)),
                        'departure_code' => $dep_airport,
                        'arrival_code' => $arr_airport,
                        'currency' => $currency,
                        'price' => number_format($marked_up_total['price'], 2, '.', ''),
                        'actual_price' => number_format($converted_total['price'], 2, '.', ''),
                        'duration_time' => \timetodate($dep_time, $arr_time),
                        'total_duration' => $totalHours . ':' . $totalMinutes,
                        'adult_price' => number_format($marked_up_adult['price'], 2, '.', ''),
                        'child_price' => number_format($marked_up_child['price'], 2, '.', ''),
                        'infant_price' => number_format($marked_up_infant['price'], 2, '.', ''),
                        'actual_adult_price' => number_format($converted_adult['price'], 2, '.', ''),
                        'actual_child_price' => number_format($converted_child['price'], 2, '.', ''),
                        'actual_infant_price' => number_format($converted_infant['price'], 2, '.', ''),
                        'options' => '',

                        // -------------------------------------------------------
                        // booking_data.offer_id is now a JSON string containing
                        // all segment details needed by:
                        //   POST flights/sabre/ancillaries
                        //   POST flights/sabre/seat-map
                        // -------------------------------------------------------
                        'booking_data' => [
                            'itinerary_index' => $itin_index,
                            'leg_ref' => $leg_ref,
                            'pcc' => $pcc,
                            'offer_id' => $offer_id_json,
                            'booking_token' => $offer_id_json,
                            'currency' => $currency,
                            'amount' => $marked_up_total['price'],
                            'actual_amount' => $converted_total['price'],
                        ],

                        'redirect_url' => '',
                        'refundable' => '1',
                        'supplier' => 'sabre',
                        'type' => $_POST['type'],
                    ];
                }

                $return_array['segments'][] = $sub_array;
            }

            $final_array[] = $return_array;
        }

        if (!empty($final_array)) {
            echo json_encode($final_array);
        } else {
            echo json_encode([]);
        }

    } catch (Exception $e) {
        echo json_encode([
            'status' => false,
            'message' => 'An error occurred: ' . $e->getMessage()
        ]);
    }
});

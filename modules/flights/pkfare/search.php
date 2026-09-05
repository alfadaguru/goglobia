<?php

$router->post('flights/pkfare/search', function() use ($db) {

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

    header('Access-Control-Allow-Origin: *');

    // Get module configuration
    $module = $db->get('modules', '*', [
        'name' => 'pkfare',
        'type' => 'flights'
    ]);

    if (!$module) {
        echo json_encode([
            'status'   => false,
            'message'  => 'PKFare module not configured',
            'response' => []
        ]);
        exit;
    }

    // Load credentials from modules table (c1/c2) instead of hardcoding
    $c1 = $module['c1'] ?? '';
    $c2 = $module['c2'] ?? '';
    if (empty($c1) || empty($c2)) {
        echo json_encode(['status' => false, 'message' => 'PKFare credentials missing']);
        exit;
    }
    $sign = md5($c1 . $c2);

    $type        = $_POST['type'] ?? 'oneway';
    $isMulticity = ($type === 'multicity');
    $currency    = strtoupper($_POST['currency'] ?? 'USD');

    // Parse multicity routes
    $multicityRoutes = [];
    if ($isMulticity) {
        $routesRaw = $_POST['routes'] ?? '';
        if (is_string($routesRaw)) {
            $multicityRoutes = json_decode($routesRaw, true) ?? [];
        } elseif (is_array($routesRaw)) {
            $multicityRoutes = $routesRaw;
        }
        if (empty($multicityRoutes)) {
            echo json_encode(['status' => false, 'message' => 'Multicity routes are missing or invalid']);
            exit;
        }
    }

    try {

        // PKFare cabin class mapping
        $cabinClass = 'Economy';
        $classLower = strtolower($_POST['class'] ?? 'economy');
        if ($classLower == "economy")                                                  { $cabinClass = "Economy"; }
        elseif ($classLower == "premium economy" || $classLower == "economy premium") { $cabinClass = "PremiumEconomy"; }
        elseif ($classLower == "business")                                             { $cabinClass = "Business"; }
        elseif ($classLower == "first class"     || $classLower == "first")            { $cabinClass = "First"; }

        $adults   = (int) ($_POST['adults']    ?? 1);
        $children = (int) ($_POST['childrens'] ?? 0);
        $infants  = (int) ($_POST['infants']   ?? 0);

        // ─────────────────────────────────────────────────────────────────
        // MULTICITY BRANCH
        // PKFare supports multi-leg natively via searchAirLegs array.
        // Each leg is a separate entry: journey_0, journey_1, journey_2…
        // ─────────────────────────────────────────────────────────────────
        if ($isMulticity) {

            $searchAirLegs = [];
            foreach ($multicityRoutes as $route) {
                $from = strtoupper($route['from'] ?? '');
                $to   = strtoupper($route['to']   ?? '');
                $date = $route['date'] ?? '';

                if (empty($from) || empty($to) || empty($date)) continue;

                $searchAirLegs[] = [
                    'cabinClass'    => $cabinClass,
                    'departureDate' => date('Y-m-d', strtotime($date)),
                    'destination'   => $to,
                    'origin'        => $from,
                    'airline'       => ''
                ];
            }

            if (empty($searchAirLegs)) {
                echo json_encode(['status' => false, 'message' => 'No valid multicity legs could be built']);
                exit;
            }

            $request = json_encode([
                'authentication' => [
                    'partnerId' => $c1,
                    'sign'      => $sign
                ],
                'search' => [
                    'adults'         => (string) $adults,
                    'children'       => (string) $children,
                    'infants'        => (string) $infants,
                    'nonstop'        => 0,
                    'airline'        => '',
                    'solutions'      => 50,
                    'searchAirLegs'  => $searchAirLegs
                ]
            ]);

            if (defined('DEBUG_PKFARE') && DEBUG_PKFARE === true) {
                @file_put_contents('/tmp/pkfare_multicity_debug.log', "--- REQUEST " . date('c') . "\n" . $request . "\n\n", FILE_APPEND);
            }

            if (connection_aborted()) { exit; }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
            curl_setopt($ch, CURLOPT_TIMEOUT,        $requestTimeout);
            curl_setopt($ch, CURLOPT_URL,            'https://api.pkfare.com/json/shoppingV4');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST,           1);
            curl_setopt($ch, CURLOPT_POSTFIELDS,     $request);
            curl_setopt($ch, CURLOPT_HTTPHEADER,     [
                'User-Agent: Apifox/1.0.0 (https://apifox.com)',
                'Content-Type: application/json'
            ]);
            $result = curl_exec($ch);

            if (curl_errno($ch)) {
                echo json_encode([]);
                curl_close($ch);
                return;
            }
            curl_close($ch);

            if (defined('DEBUG_PKFARE') && DEBUG_PKFARE === true) {
                @file_put_contents('/tmp/pkfare_multicity_debug.log', "--- RESPONSE " . date('c') . "\n" . substr($result, 0, 2000) . "\n\n", FILE_APPEND);
            }

            $decode = json_decode($result, true);

            if ($decode === null) {
                @error_log('PKFare Multicity: json_decode error: ' . json_last_error_msg() . ' -- raw: ' . substr($result, 0, 1000));
            }

            if (empty($decode['data']['solutions'])) {
                @error_log('PKFare Multicity: no solutions found. Response keys: ' . json_encode(is_array($decode) ? array_keys($decode) : []));
                @error_log('PKFare Multicity raw response start: ' . substr($result, 0, 1000));
                echo json_encode([]);
                return;
            }

            $solutions = $decode['data']['solutions'];
            $numLegs   = count($searchAirLegs);

            // ── Helper: extract segments for one journey_N key ────────────
            $extractJourneySegments = function(
                array $value, string $journeyKey, array $decode,
                string $currency, $module, $db, string $tripType, int $legCount
            ): array {
                if (!isset($value['journeys'][$journeyKey])) return [];

                $sub_array = [];
                $total     = [];

                // First pass: collect durations
                foreach ($value['journeys'][$journeyKey] as $journey_id) {
                    foreach ($decode['data']['flights'] as $flight) {
                        if ($flight['flightId'] !== $journey_id) continue;
                        foreach ($flight['segmentIds'] as $segment_id) {
                            foreach ($decode['data']['segments'] as $segment) {
                                if ($segment['segmentId'] !== $segment_id) continue;
                                $dep = new DateTime($segment['strDepartureTime']);
                                $arr = new DateTime($segment['strArrivalTime']);
                                if ($arr < $dep) $arr->modify('+1 day');
                                $int    = $dep->diff($arr);
                                $total[] = $int->h . ':' . $int->i;
                            }
                        }
                    }
                }

                $timesString  = implode(':', $total);
                preg_match_all('/(\d{1,}):(\d{1,})/', $timesString, $matches);
                $totalHours   = array_sum($matches[1]);
                $totalMinutes = array_sum($matches[2]);
                $totalHours  += floor($totalMinutes / 60);
                $totalMinutes %= 60;

                // Second pass: build segment objects
                foreach ($value['journeys'][$journeyKey] as $journey_id) {
                    foreach ($decode['data']['flights'] as $flight) {
                        if ($flight['flightId'] !== $journey_id) continue;
                        foreach ($flight['segmentIds'] as $segment_id) {
                            foreach ($decode['data']['segments'] as $segment) {
                                if ($segment['segmentId'] !== $segment_id) continue;

                                $dep = new DateTime($segment['strDepartureTime']);
                                $arr = new DateTime($segment['strArrivalTime']);
                                if ($arr < $dep) $arr->modify('+1 day');
                                $interval = $dep->diff($arr);

                                // Price — divide total by number of legs for per-leg display
                                $adult_total  = (float) $value['adtFare']  + (float) $value['adtTax'];
                                $child_total  = (float) $value['chdFare']  + (float) $value['chdTax'];
                                $infant_total = ((float) ($value['infFare'] ?? 0)) + ((float) ($value['infTax'] ?? 0));

                                $flightCurrency = $value['currency'];

                                $marked_up_adult  = MARKUP($adult_total,  $module, $db, $flightCurrency, $currency);
                                $marked_up_child  = MARKUP($child_total,  $module, $db, $flightCurrency, $currency);
                                $marked_up_infant = MARKUP($infant_total, $module, $db, $flightCurrency, $currency);

                                $converted_adult  = CURRENCY_CONVERT($adult_total,  $db, $flightCurrency, $currency);
                                $converted_child  = CURRENCY_CONVERT($child_total,  $db, $flightCurrency, $currency);
                                $converted_infant = CURRENCY_CONVERT($infant_total, $db, $flightCurrency, $currency);

                                $adults_count   = (int) ($value['adults']   ?? 1);
                                $children_count = (int) ($value['children'] ?? 0);
                                $infants_count  = (int) ($value['infants']  ?? 0);

                                $total_price = ($marked_up_adult['price']  * $adults_count)
                                             + ($marked_up_child['price']  * $children_count)
                                             + ($marked_up_infant['price'] * $infants_count);

                                $actual_total_price = ($converted_adult['price']  * $adults_count)
                                                    + ($converted_child['price']  * $children_count)
                                                    + ($converted_infant['price'] * $infants_count);

                                $refundable = 1;
                                if (isset($value['miniRuleMap']['ADT'][0]['miniRules'][0]['penaltyType'])) {
                                    $refundable = $value['miniRuleMap']['ADT'][0]['miniRules'][0]['penaltyType'] == 0 ? 1 : 0;
                                }

                                // Build journey keys list for booking_data
                                $allJourneys = [];
                                for ($j = 0; $j < $legCount; $j++) {
                                    $jk = 'journey_' . $j;
                                    if (isset($value['journeys'][$jk])) {
                                        $allJourneys[$jk] = $value['journeys'][$jk];
                                    }
                                }

                                $sub_array[] = (object) [
                                    'img'               => $segment['airline'],
                                    'flight_no'         => $segment['flightNum'],
                                    'airline'           => $segment['airline'],
                                    'class'             => $segment['cabinClass'],
                                    'baggage'           => $value['baggageMap']['ADT'][0]['baggageWeight']
                                                            ?? $value['baggageMap']['ADT'][0]['baggageAmount'] ?? '',
                                    'cabin_baggage'     => $value['baggageMap']['ADT'][0]['carryOnWeight']
                                                            ?? $value['baggageMap']['ADT'][0]['carryOnAmount'] ?? '',
                                    'departure_airport' => $segment['departure'],
                                    'departure_time'    => date('h:i a', strtotime($segment['strDepartureTime'])),
                                    'arrival_airport'   => $segment['arrival'],
                                    'arrival_time'      => date('h:i a', strtotime($segment['strArrivalTime'])),
                                    'departure_date'    => date('d-m-Y', strtotime($segment['strDepartureDate'])),
                                    'arrival_date'      => date('d-m-Y', strtotime($segment['strArrivalDate'])),
                                    'departure_code'    => $segment['departure'],
                                    'arrival_code'      => $segment['arrival'],
                                    'currency'          => $currency,
                                    'price'             => number_format((float) $total_price,        2, '.', ''),
                                    'actual_price'      => number_format((float) $actual_total_price, 2, '.', ''),
                                    'duration_time'     => $interval->h . ':' . $interval->i,
                                    'total_duration'    => $totalHours . ':' . $totalMinutes,
                                    'adult_price'       => number_format((float) $marked_up_adult['price'],  2, '.', ''),
                                    'child_price'       => number_format((float) $marked_up_child['price'],  2, '.', ''),
                                    'infant_price'      => number_format((float) $marked_up_infant['price'], 2, '.', ''),
                                    'actual_adult_price'  => number_format((float) $converted_adult['price'],  2, '.', ''),
                                    'actual_child_price'  => number_format((float) $converted_child['price'],  2, '.', ''),
                                    'actual_infant_price' => number_format((float) $converted_infant['price'], 2, '.', ''),
                                    'options'           => '',
                                    'booking_data'      => array_merge(
                                        ['solutionId' => $value['solutionId'], 'currency' => $currency,
                                         'amount' => $total_price, 'actual_amount' => $actual_total_price],
                                        $allJourneys
                                    ),
                                    'redirect_url'      => '',
                                    'refundable'        => $refundable,
                                    'supplier'          => 'pkfare',
                                    'type'              => $tripType,
                                ];
                            }
                        }
                    }
                }
                return $sub_array;
            };

            $final_array = [];
            foreach ($solutions as $value) {
                if (connection_aborted()) { exit; }

                $return_array = [];
                // Collect segments for each leg, preserving order (may be empty)
                for ($legIdx = 0; $legIdx < $numLegs; $legIdx++) {
                    $journeyKey = 'journey_' . $legIdx;
                    $segs = $extractJourneySegments(
                        $value, $journeyKey, $decode,
                        $currency, $module, $db, 'multicity', $numLegs
                    );
                    $return_array['segments'][] = $segs;
                }

                // Include only complete itineraries where every leg has at least one segment
                $hasAllLegs = true;
                foreach ($return_array['segments'] as $legSegments) {
                    if (empty($legSegments)) { $hasAllLegs = false; break; }
                }
                if ($hasAllLegs) {
                    $final_array[] = $return_array;
                }
            }

            header('Content-Type: application/json');
            echo json_encode(!empty($final_array) ? $final_array : []);
            return;
        }

        // ─────────────────────────────────────────────────────────────────
        // ONE-WAY / RETURN BRANCH  (original logic, unchanged)
        // ─────────────────────────────────────────────────────────────────

        // Validation
        if (isset($_POST['origin'])       && trim($_POST['origin'])       !== '') {} else { echo "origin : LHE - param or value missing ";       die; }
        if (isset($_POST['destination'])  && trim($_POST['destination'])  !== '') {} else { echo "destination : DXB - param or value missing ";   die; }
        if (isset($_POST['departure_date']) && trim($_POST['departure_date']) !== '') {} else { echo "departure_date : 10-10-2021 - param or value missing "; die; }
        if (isset($_POST['adults'])       && trim($_POST['adults'])       !== '') {} else { echo "adults : 1 - param or value missing ";          die; }
        if (isset($_POST['childrens'])    && trim($_POST['childrens'])    !== '') {} else { echo "childrens : 1 - param or value missing ";       die; }
        if (isset($_POST['infants'])      && trim($_POST['infants'])      !== '') {} else { echo "infants : 1 - param or value missing ";         die; }
        if (isset($_POST['currency'])     && trim($_POST['currency'])     !== '') {} else { echo "currency : USD - param or value missing ";      die; }
        if (isset($_POST['type'])         && trim($_POST['type'])         !== '') {} else { echo "type : oneway | return - param or value missing "; die; }
        if (isset($_POST['class'])        && trim($_POST['class'])        !== '') {} else { echo "class - param or value missing ";               die; }

        /*flight date & time*/
        $departureDate = date('Y-m-d', strtotime($_POST['departure_date']));
        $returnDate    = date('Y-m-d', strtotime($_POST['return_date']));
        $destination   = $_POST['destination'];
        $origin        = $_POST['origin'];
        /*end flight date & time*/

        if ($type == 'oneway') {
            $payload = json_encode([array(
                'cabinClass'    => $cabinClass,
                'departureDate' => $departureDate,
                'destination'   => $destination,
                'origin'        => $origin,
                'airline'       => ''
            )]);
        } else {
            $payload = json_encode([array(
                'cabinClass'    => $cabinClass,
                'departureDate' => $departureDate,
                'destination'   => $destination,
                'origin'        => $origin,
                'airline'       => ''
            ),
            array(
                'cabinClass'    => $cabinClass,
                'departureDate' => $returnDate,
                'destination'   => $origin,
                'origin'        => $destination,
                'airline'       => ''
            )]);
        }

        $request = '{
            "authentication": {
                "partnerId": "' . $c1 . '",
                "sign": "' . $sign . '"
            },
            "search": {
                "adults": "' . (int) ($_POST['adults'] ?? 1) . '",
                "children": "' . (int) ($_POST['childrens'] ?? 0) . '",
                "infants": "' . (int) ($_POST['infants'] ?? 0) . '",
                "nonstop": 0,
                "airline": "",
                "solutions": 50,
                "searchAirLegs": ' . $payload . '
            }
        }';

        // API Call
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT,        $requestTimeout);
        curl_setopt($ch, CURLOPT_URL,            'https://api.pkfare.com/json/shoppingV4');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST,           1);
        curl_setopt($ch, CURLOPT_POSTFIELDS,     $request);
        curl_setopt($ch, CURLOPT_HTTPHEADER,     array(
            'User-Agent: Apifox/1.0.0 (https://apifox.com)',
            'Content-Type: application/json'
        ));
        curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);

        $result = curl_exec($ch);

        if (curl_errno($ch)) {
            $errorMsg = 'Error:' . curl_error($ch);
            echo json_encode([]);
            return;
        }

        $decode = json_decode($result, true);

        // Check if response has data
        if (empty($decode['data'])) {
            echo json_encode([]);
            return;
        } else {
            $data = $decode['data']['solutions'];
        }

        $final_array = [];
        foreach ($data as $value) {
            $return_array = [];

            // Process outbound journey (journey_0)
            if (isset($value['journeys']['journey_0'])) {
                $sub_array = array();
                $total     = [];

                foreach ($value['journeys']['journey_0'] as $journey_id) {
                    foreach ($decode['data']['flights'] as $flight) {
                        if ($flight['flightId'] === $journey_id) {
                            foreach ($flight['segmentIds'] as $segment_id) {
                                foreach ($decode['data']['segments'] as $segment) {
                                    if ($segment['segmentId'] === $segment_id) {
                                        $departureTime = new DateTime($segment['strDepartureTime']);
                                        $arrivalTime   = new DateTime($segment['strArrivalTime']);
                                        if ($arrivalTime < $departureTime) {
                                            $arrivalTime->modify('+1 day');
                                        }
                                        $interval = $departureTime->diff($arrivalTime);
                                        $total[]  = $interval->h . ":" . $interval->i;
                                    }
                                }
                            }
                        }
                    }
                }

                $timesString  = implode(":", $total);
                preg_match_all('/(\d{1,}):(\d{1,})/', $timesString, $matches);
                $totalHours   = array_sum($matches[1]);
                $totalMinutes = array_sum($matches[2]);
                $totalHours  += floor($totalMinutes / 60);
                $totalMinutes %= 60;

                foreach ($value['journeys']['journey_0'] as $journey_id) {
                    foreach ($decode['data']['flights'] as $flight) {
                        if ($flight['flightId'] === $journey_id) {
                            foreach ($flight['segmentIds'] as $segment_id) {
                                foreach ($decode['data']['segments'] as $segment) {
                                    if ($segment['segmentId'] === $segment_id) {
                                        $departureTime = new DateTime($segment['strDepartureTime']);
                                        $arrivalTime   = new DateTime($segment['strArrivalTime']);
                                        if ($arrivalTime < $departureTime) {
                                            $arrivalTime->modify('+1 day');
                                        }
                                        $interval = $departureTime->diff($arrivalTime);

                                        // Price calculations
                                        $adult_fare   = (float) $value['adtFare'];
                                        $adult_tax    = (float) $value['adtTax'];
                                        $child_fare   = (float) $value['chdFare'];
                                        $child_tax    = (float) $value['chdTax'];
                                        $infant_fare  = isset($value['infFare']) ? (float) $value['infFare'] : 0;
                                        $infant_tax   = isset($value['infTax'])  ? (float) $value['infTax']  : 0;
                                        $adult_total  = $adult_fare  + $adult_tax;
                                        $child_total  = $child_fare  + $child_tax;
                                        $infant_total = $infant_fare + $infant_tax;

                                        // Get original currency from PKFare response
                                        $flightCurrency = $value['currency'];

                                        // Apply markup and conversion
                                        $marked_up_adult  = MARKUP($adult_total,  $module, $db, $flightCurrency, $currency);
                                        $marked_up_child  = MARKUP($child_total,  $module, $db, $flightCurrency, $currency);
                                        $marked_up_infant = MARKUP($infant_total, $module, $db, $flightCurrency, $currency);

                                        $converted_adult  = CURRENCY_CONVERT($adult_total,  $db, $flightCurrency, $currency);
                                        $converted_child  = CURRENCY_CONVERT($child_total,  $db, $flightCurrency, $currency);
                                        $converted_infant = CURRENCY_CONVERT($infant_total, $db, $flightCurrency, $currency);

                                        $total_price = ($marked_up_adult['price']  * $_POST['adults'])
                                                     + ($marked_up_child['price']  * $_POST['childrens'])
                                                     + ($marked_up_infant['price'] * $_POST['infants']);

                                        $actual_total_price = ($converted_adult['price']  * $_POST['adults'])
                                                            + ($converted_child['price']  * $_POST['childrens'])
                                                            + ($converted_infant['price'] * $_POST['infants']);

                                        $refundable = 1;
                                        if (isset($value['miniRuleMap']['ADT'][0]['miniRules'][0]['penaltyType'])) {
                                            $refundable = $value['miniRuleMap']['ADT'][0]['miniRules'][0]['penaltyType'] == 0 ? 1 : 0;
                                        }

                                        $sub_array[] = (object) [
                                            'img'               => $segment['airline'],
                                            'flight_no'         => $segment['flightNum'],
                                            'airline'           => $segment['airline'],
                                            'class'             => $segment['cabinClass'],
                                            'baggage'           => $value['baggageMap']['ADT'][0]['baggageWeight'] ?? $value['baggageMap']['ADT'][0]['baggageAmount'] ?? '',
                                            'cabin_baggage'     => $value['baggageMap']['ADT'][0]['carryOnWeight'] ?? $value['baggageMap']['ADT'][0]['carryOnAmount'] ?? '',
                                            'departure_airport' => $segment['departure'],
                                            'departure_time'    => date("h:i a", strtotime($segment['strDepartureTime'])),
                                            'arrival_airport'   => $segment['arrival'],
                                            'arrival_time'      => date("h:i a", strtotime($segment['strArrivalTime'])),
                                            'departure_date'    => date("d-m-Y", strtotime($segment['strDepartureDate'])),
                                            'arrival_date'      => date("d-m-Y", strtotime($segment['strArrivalDate'])),
                                            'departure_code'    => $segment['departure'],
                                            'arrival_code'      => $segment['arrival'],
                                            'currency'          => $currency,
                                            'price'             => number_format((float) $total_price,        2, '.', ''),
                                            'actual_price'      => number_format((float) $actual_total_price, 2, '.', ''),
                                            'duration_time'     => $interval->h . ":" . $interval->i,
                                            'total_duration'    => $totalHours . ":" . $totalMinutes,
                                            'adult_price'       => number_format((float) $marked_up_adult['price'],  2, '.', ''),
                                            'child_price'       => number_format((float) $marked_up_child['price'],  2, '.', ''),
                                            'infant_price'      => number_format((float) $marked_up_infant['price'], 2, '.', ''),
                                            'actual_adult_price'  => number_format((float) $converted_adult['price'],  2, '.', ''),
                                            'actual_child_price'  => number_format((float) $converted_child['price'],  2, '.', ''),
                                            'actual_infant_price' => number_format((float) $converted_infant['price'], 2, '.', ''),
                                            'options'           => '',
                                            'booking_data'      => array(
                                                'solutionId' => $value['solutionId'],
                                                'journey_0'  => $value['journeys']['journey_0'],
                                                'currency'   => $currency,
                                                'amount'     => $total_price,
                                                'actual_amount' => $actual_total_price
                                            ),
                                            'redirect_url'      => '',
                                            'refundable'        => $refundable,
                                            'supplier'          => 'pkfare',
                                            'type'              => $_POST['type'],
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }
                $return_array["segments"][] = $sub_array;
            }

            // Process return journey (journey_1) for round trips
            if (isset($value['journeys']['journey_1'])) {
                $sub_array = array();
                $total     = [];

                foreach ($value['journeys']['journey_1'] as $journey_id) {
                    foreach ($decode['data']['flights'] as $flight) {
                        if ($flight['flightId'] === $journey_id) {
                                foreach ($flight['segmentIds'] as $segment_id) {
                                foreach ($decode['data']['segments'] as $segment) {
                                    if ($segment['segmentId'] === $segment_id) {
                                        $departureTime = new DateTime($segment['strDepartureTime']);
                                        $arrivalTime   = new DateTime($segment['strArrivalTime']);
                                        if ($arrivalTime < $departureTime) {
                                            $arrivalTime->modify('+1 day');
                                        }
                                        $interval = $departureTime->diff($arrivalTime);
                                        $total[]  = $interval->h . ":" . $interval->i;
                                    }
                                }
                            }
                        }
                    }
                }

                $timesString  = implode(":", $total);
                preg_match_all('/(\d{1,}):(\d{1,})/', $timesString, $matches);
                $totalHours   = array_sum($matches[1]);
                $totalMinutes = array_sum($matches[2]);
                $totalHours  += floor($totalMinutes / 60);
                $totalMinutes %= 60;

                foreach ($value['journeys']['journey_1'] as $journey_id) {
                    foreach ($decode['data']['flights'] as $flight) {
                        if ($flight['flightId'] === $journey_id) {
                                foreach ($flight['segmentIds'] as $segment_id) {
                                foreach ($decode['data']['segments'] as $segment) {
                                    if ($segment['segmentId'] === $segment_id) {
                                        $departureTime = new DateTime($segment['strDepartureTime']);
                                        $arrivalTime   = new DateTime($segment['strArrivalTime']);
                                        if ($arrivalTime < $departureTime) {
                                            $arrivalTime->modify('+1 day');
                                        }
                                        $interval = $departureTime->diff($arrivalTime);

                                        // Price calculations for return journey
                                        $adult_fare   = (float) $value['adtFare'];
                                        $adult_tax    = (float) $value['adtTax'];
                                        $child_fare   = (float) $value['chdFare'];
                                        $child_tax    = (float) $value['chdTax'];
                                        $infant_fare  = isset($value['infFare']) ? (float) $value['infFare'] : 0;
                                        $infant_tax   = isset($value['infTax'])  ? (float) $value['infTax']  : 0;

                                        $adult_total  = $adult_fare  + $adult_tax;
                                        $child_total  = $child_fare  + $child_tax;
                                        $infant_total = $infant_fare + $infant_tax;

                                        // Get original currency from PKFare response
                                        $flightCurrency = $value['currency'];

                                        // Apply markup and conversion
                                        $marked_up_adult  = MARKUP($adult_total,  $module, $db, $flightCurrency, $currency);
                                        $marked_up_child  = MARKUP($child_total,  $module, $db, $flightCurrency, $currency);
                                        $marked_up_infant = MARKUP($infant_total, $module, $db, $flightCurrency, $currency);

                                        $converted_adult  = CURRENCY_CONVERT($adult_total,  $db, $flightCurrency, $currency);
                                        $converted_child  = CURRENCY_CONVERT($child_total,  $db, $flightCurrency, $currency);
                                        $converted_infant = CURRENCY_CONVERT($infant_total, $db, $flightCurrency, $currency);

                                        $total_price = ($marked_up_adult['price']  * $_POST['adults'])
                                                     + ($marked_up_child['price']  * $_POST['childrens'])
                                                     + ($marked_up_infant['price'] * $_POST['infants']);

                                        $actual_total_price = ($converted_adult['price']  * $_POST['adults'])
                                                            + ($converted_child['price']  * $_POST['childrens'])
                                                            + ($converted_infant['price'] * $_POST['infants']);

                                        $refundable = 1;
                                        if (isset($value['miniRuleMap']['ADT'][0]['miniRules'][0]['penaltyType'])) {
                                            $refundable = $value['miniRuleMap']['ADT'][0]['miniRules'][0]['penaltyType'] == 0 ? 1 : 0;
                                        }

                                        $sub_array[] = (object) [
                                            'img'               => $segment['airline'],
                                            'flight_no'         => $segment['flightNum'],
                                            'airline'           => $segment['airline'],
                                            'class'             => $segment['cabinClass'],
                                            'baggage'           => $value['baggageMap']['ADT'][0]['baggageWeight'] ?? $value['baggageMap']['ADT'][0]['baggageAmount'] ?? '',
                                            'cabin_baggage'     => $value['baggageMap']['ADT'][0]['carryOnWeight'] ?? $value['baggageMap']['ADT'][0]['carryOnAmount'] ?? '',
                                            'departure_airport' => $segment['departure'],
                                            'departure_time'    => date("h:i a", strtotime($segment['strDepartureTime'])),
                                            'arrival_airport'   => $segment['arrival'],
                                            'arrival_time'      => date("h:i a", strtotime($segment['strArrivalTime'])),
                                            'departure_date'    => date("d-m-Y", strtotime($segment['strDepartureDate'])),
                                            'arrival_date'      => date("d-m-Y", strtotime($segment['strArrivalDate'])),
                                            'departure_code'    => $segment['departure'],
                                            'arrival_code'      => $segment['arrival'],
                                            'currency'          => $currency,
                                            'price'             => number_format((float) $total_price,        2, '.', ''),
                                            'actual_price'      => number_format((float) $actual_total_price, 2, '.', ''),
                                            'duration_time'     => $interval->h . ":" . $interval->i,
                                            'total_duration'    => $totalHours . ":" . $totalMinutes,
                                            'adult_price'       => number_format((float) $marked_up_adult['price'],  2, '.', ''),
                                            'child_price'       => number_format((float) $marked_up_child['price'],  2, '.', ''),
                                            'infant_price'      => number_format((float) $marked_up_infant['price'], 2, '.', ''),
                                            'actual_adult_price'  => number_format((float) $converted_adult['price'],  2, '.', ''),
                                            'actual_child_price'  => number_format((float) $converted_child['price'],  2, '.', ''),
                                            'actual_infant_price' => number_format((float) $converted_infant['price'], 2, '.', ''),
                                            'options'           => '',
                                            'booking_data'      => array(
                                                'solutionId' => $value['solutionId'],
                                                'journey_1'  => $value['journeys']['journey_1'],
                                                'currency'   => $currency,
                                                'amount'     => $total_price,
                                                'actual_amount' => $actual_total_price
                                            ),
                                            'redirect_url'      => '',
                                            'refundable'        => $refundable,
                                            'supplier'          => 'pkfare',
                                            'type'              => $_POST['type'],
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }
                $return_array["segments"][] = $sub_array;
            }

            $final_array[] = $return_array;
        }

        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');
        if (!empty($final_array)) {
            echo json_encode($final_array);
        } else {
            echo json_encode([]);
        }

    } catch (Exception $e) {
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');
        echo json_encode(array("status" => false, "message" => "An error occurred. Please try again later."));
    }
});

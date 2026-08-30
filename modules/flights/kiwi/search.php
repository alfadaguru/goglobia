<?php

$router->post('flights/kiwi/search', function() use ($db) {

    // search_guard_v2: session lock release + configurable timeouts for long supplier requests
    @set_time_limit(30);
    $connectTimeout = defined('SUPPLIER_CONNECT_TIMEOUT') ? SUPPLIER_CONNECT_TIMEOUT : 10;
    $requestTimeout = defined('SUPPLIER_REQUEST_TIMEOUT') ? SUPPLIER_REQUEST_TIMEOUT : 30;
    $searchSessionData = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        $searchSessionData = $_SESSION;
        session_write_close();
        error_log('search_guard: session lock released');
    }

    if (connection_aborted()) {
        error_log('search_guard: request aborted early');
        exit;
    }
    @set_time_limit(30);
    if (session_status() === PHP_SESSION_ACTIVE) {
        $sessionData = $_SESSION;
        session_write_close();
        error_log('search_guard: session lock released');
    }

    if (connection_aborted()) {
        error_log('search_guard: request aborted early');
        exit;
    }

    // Determine trip type
    $type = $_POST['type'] ?? 'oneway';
    $isMulticity = ($type === 'multicity');

    // Parse multicity routes if needed
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

    $module = ($db->get("modules", "*", ["name" => "kiwi", "type" => "flights"]));

    if (!$module) {
        echo json_encode([
            'status'   => false,
            'message'  => 'Kiwi module not configured',
            'response' => []
        ]);
        exit;
    }

    $affil_id = $module['c1'] ?? '';  // AffilID
    $api_key  = $module['c2'] ?? '';  // API Key
    $currency = strtoupper($_POST['currency'] ?? 'USD');

    // Validate API credentials
    if (empty($api_key)) {
        echo json_encode([
            'status'   => false,
            'message'  => 'Kiwi API key not configured',
            'response' => []
        ]);
        exit;
    }

    try {

        $class_trip = '';
        if (strtolower($_POST['class'] ?? '') == "economy")         { $class_trip = 'M'; }
        if (strtolower($_POST['class'] ?? '') == "economy premium") { $class_trip = 'W'; }
        if (strtolower($_POST['class'] ?? '') == "business")        { $class_trip = 'C'; }
        if (strtolower($_POST['class'] ?? '') == "first class")     { $class_trip = 'F'; }

        $adults   = (int) ($_POST['adults']    ?? 1);
        $children = (int) ($_POST['childrens'] ?? 0);
        $infants  = (int) ($_POST['infants']   ?? 0);

        // ─────────────────────────────────────────────────────────────────
        // MULTICITY BRANCH — one /v2/search call per leg, results combined
        // (uses the standard search endpoint since /v2/flights_multi
        //  requires a special Kiwi partner tier)
        // ─────────────────────────────────────────────────────────────────
        if ($isMulticity) {

            // ── 1. Search each leg individually ──────────────────────────
            $legResults = [];   // one entry per leg: array of best itineraries

            foreach ($multicityRoutes as $legIndex => $route) {
                $from = strtoupper($route['from'] ?? '');
                $to   = strtoupper($route['to']   ?? '');
                $date = $route['date'] ?? '';

                if (empty($from) || empty($to) || empty($date)) continue;

                $ts    = strtotime($date);
                $kDate = date('d/m/Y', $ts);

                $legUrl = "https://api.tequila.kiwi.com/v2/search"
                    . "?fly_from={$from}&fly_to={$to}"
                    . "&date_from={$kDate}&date_to={$kDate}"
                    . "&adults={$adults}&children={$children}&infants={$infants}"
                    . "&curr={$currency}"
                    . "&limit=20";

                if (!empty($class_trip)) {
                    $legUrl .= "&selected_cabins={$class_trip}";
                }

                if (connection_aborted()) { exit; }

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $legUrl);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["apikey:{$api_key}", 'Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
                curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
                $legResult = curl_exec($ch);
                curl_close($ch);

                $legDecode = json_decode($legResult, true);

                if (!empty($legDecode['data'])) {
                    $legResults[$legIndex] = $legDecode['data'];
                } else {
                    // No results for this leg — return empty so UI shows 0
                    header('Access-Control-Allow-Origin: *');
                    header('Content-Type: application/json');
                    echo json_encode([]);
                    return;
                }
            }

            if (empty($legResults)) {
                header('Access-Control-Allow-Origin: *');
                header('Content-Type: application/json');
                echo json_encode([]);
                return;
            }

            // ── 2. Build helper to parse one Kiwi itinerary into segments ─
            $parseItinerary = function(array $value, string $flightCurrency, string $currency, string $tripType) use ($module, $db): array {
                $booking_token = $value['booking_token'] ?? '';
                $redirect_url  = $value['deep_link']     ?? '';
                $search_id     = $value['search_id']     ?? '';
                $baglimitHold  = isset($value['baglimit']['hold_weight']) ? $value['baglimit']['hold_weight'] . " KG" : "0 PC";
                $baglimitHand  = isset($value['baglimit']['hand_weight']) ? $value['baglimit']['hand_weight'] . " KG" : "0 PC";

                $total = [];
                foreach ($value['route'] as $duration) {
                    $total[] = \timetodate($duration['utc_departure'], $duration['utc_arrival']);
                }
                $timesString  = implode(":", $total);
                preg_match_all('/(\d{1,}):(\d{1,})/', $timesString, $matches);
                $totalHours   = array_sum($matches[1]);
                $totalMinutes = array_sum($matches[2]);
                $totalHours  += floor($totalMinutes / 60);
                $totalMinutes %= 60;

                $marked_up_total  = MARKUP($value['price'],             $module, $db, $flightCurrency, $currency);
                $converted_total  = CURRENCY_CONVERT($value['price'],   $db, $flightCurrency, $currency);
                $marked_up_adult  = MARKUP($value['fare']['adults'],    $module, $db, $flightCurrency, $currency);
                $marked_up_child  = MARKUP($value['fare']['children'],  $module, $db, $flightCurrency, $currency);
                $marked_up_infant = MARKUP($value['fare']['infants'],   $module, $db, $flightCurrency, $currency);
                $conv_adult       = CURRENCY_CONVERT($value['fare']['adults'],   $db, $flightCurrency, $currency);
                $conv_child       = CURRENCY_CONVERT($value['fare']['children'], $db, $flightCurrency, $currency);
                $conv_infant      = CURRENCY_CONVERT($value['fare']['infants'],  $db, $flightCurrency, $currency);

                $bookingData = [
                    'booking_token' => $booking_token,
                    'session_id'    => $search_id,
                    'currency'      => $currency,
                    'amount'        => $marked_up_total['price'],
                    'actual_amount' => $converted_total['price'],
                ];

                $sub_array = [];
                foreach ($value['route'] as $key) {
                    $class = '';
                    if ($key['fare_category'] == 'M') { $class = "economy"; }
                    if ($key['fare_category'] == 'W') { $class = "economy premium"; }
                    if ($key['fare_category'] == 'C') { $class = "business"; }
                    if ($key['fare_category'] == 'F') { $class = "first class"; }

                    $sub_array[] = (object) [
                        'img'               => $key['airline'],
                        'flight_no'         => $key['flight_no'],
                        'airline'           => $key['airline'],
                        'class'             => $class,
                        'baggage'           => $baglimitHold,
                        'cabin_baggage'     => $baglimitHand,
                        'departure_airport' => $key['cityFrom'],
                        'departure_time'    => date("h:i a", strtotime($key['utc_departure'])),
                        'arrival_airport'   => $key['cityTo'],
                        'arrival_time'      => date("h:i a", strtotime($key['utc_arrival'])),
                        'departure_date'    => date("d-m-Y",  strtotime($key['utc_departure'])),
                        'arrival_date'      => date("d-m-Y",  strtotime($key['utc_arrival'])),
                        'departure_code'    => $key['cityCodeFrom'],
                        'arrival_code'      => $key['cityCodeTo'],
                        'currency'          => $currency,
                        'price'             => number_format((float) $marked_up_total['price'],  2, '.', ''),
                        'actual_price'      => number_format((float) $converted_total['price'],  2, '.', ''),
                        'duration_time'     => \timetodate($key['utc_departure'], $key['utc_arrival']),
                        'total_duration'    => $totalHours . ":" . $totalMinutes,
                        'adult_price'       => number_format((float) $marked_up_adult['price'],  2, '.', ''),
                        'child_price'       => number_format((float) $marked_up_child['price'],  2, '.', ''),
                        'infant_price'      => number_format((float) $marked_up_infant['price'], 2, '.', ''),
                        'actual_adult_price'  => number_format((float) $conv_adult['price'],  2, '.', ''),
                        'actual_child_price'  => number_format((float) $conv_child['price'],  2, '.', ''),
                        'actual_infant_price' => number_format((float) $conv_infant['price'], 2, '.', ''),
                        'options'           => '',
                        'booking_data'      => $bookingData,
                        'redirect_url'      => $redirect_url,
                        'refundable'        => '1',
                        'supplier'          => 'kiwi',
                        'type'              => $tripType,
                    ];
                }
                return $sub_array;
            };

            // ── 3. Pick top results per leg and assemble multicity cards ──
            //    We pair: best of leg1 × best of leg2 × … (capped at 30 cards)
            $maxPerLeg = 5;
            $legKeys   = array_keys($legResults);
            $flightCurrency = $currency; // Kiwi returns local_currency but price is already in curr param

            $final_array = [];

            // Simple cartesian product of top N itineraries per leg
            $combinations = [[]];
            foreach ($legKeys as $lk) {
                $legSlice    = array_slice($legResults[$lk], 0, $maxPerLeg);
                $newCombos   = [];
                foreach ($combinations as $combo) {
                    foreach ($legSlice as $itinerary) {
                        $newCombos[] = array_merge($combo, [['leg' => $lk, 'data' => $itinerary]]);
                    }
                }
                $combinations = $newCombos;
            }

            foreach ($combinations as $combo) {
                if (connection_aborted()) { exit; }

                $combinationSegments = [];
                foreach ($combo as $item) {
                    $segs = $parseItinerary($item['data'], $flightCurrency, $currency, 'multicity');
                    if (!empty($segs)) {
                        $combinationSegments[] = $segs;
                    }
                }

                if (!empty($combinationSegments)) {
                    $final_array[] = ['segments' => $combinationSegments];
                }
            }

            header('Access-Control-Allow-Origin: *');
            header('Content-Type: application/json');
            echo json_encode(!empty($final_array) ? $final_array : []);
            return;
        }

        // ─────────────────────────────────────────────────────────────────
        // ONE-WAY / RETURN BRANCH — GET /v2/search  (unchanged logic)
        // ─────────────────────────────────────────────────────────────────

        REQUIRED(['origin', 'destination', 'departure_date', 'adults', 'childrens', 'infants', 'currency', 'type', 'class']);

        /*flight date & time*/
        $time1         = strtotime($_POST['departure_date']);
        $departureDate = date('d/m/Y', $time1);

        if ($type != 'oneway') {
            $time2      = strtotime($_POST['return_date']);
            $returnDate = date('d/m/Y', $time2);
        }

        $destination = $_POST['destination'];
        $origin      = $_POST['origin'];
        /*end flight date & time*/

        if ($type == 'oneway') {
            $url = "https://api.tequila.kiwi.com/v2/search?fly_from=" . strtoupper($origin) . "&fly_to=" . strtoupper($destination) . "&date_from=" . $departureDate . "&date_to=" . $departureDate . "&adults={$_POST['adults']}&children={$_POST['childrens']}&infants={$_POST['infants']}&curr=" . strtoupper($_POST['currency']) . "&selected_cabins={$class_trip}";
        } else {
            $url = "https://api.tequila.kiwi.com/v2/search?fly_from=" . strtoupper($origin) . "&fly_to=" . strtoupper($destination) . "&date_from=" . $departureDate . "&date_to=" . $departureDate . "&return_from=" . $returnDate . "&return_to=" . $returnDate . "&adults={$_POST['adults']}&children={$_POST['childrens']}&infants={$_POST['infants']}&curr=" . strtoupper($_POST['currency']) . "&selected_cabins={$class_trip}";
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["apikey:{$api_key}", 'content-Type: application/json']);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);

        if (connection_aborted()) {
            error_log('search_guard: request aborted before supplier call');
            exit;
        }
        $result = curl_exec($ch);

        if (curl_errno($ch)) {
            $errorMsg = 'CURL Error: ' . curl_error($ch);
            echo json_encode([
                'status'   => false,
                'message'  => $errorMsg,
                'response' => []
            ]);
            return;
        }

        $decode = json_decode($result, true);

        // Check for API errors
        if (isset($decode['error']) || isset($decode['error_code'])) {
            echo json_encode([
                'status'     => false,
                'message'    => 'Kiwi API Error: ' . ($decode['message'] ?? $decode['error'] ?? 'Unknown error'),
                'error_code' => $decode['error_code'] ?? $decode['code'] ?? 'UNKNOWN',
                'response'   => []
            ]);
            return;
        }

        if (empty($decode['data'])) {
            echo json_encode([
                'status'       => false,
                'message'      => 'No flights found for this route',
                'api_response' => $decode,
                'response'     => []
            ]);
            return;
        } else {
            $data = $decode['data'];
        }

        $final_array = [];
        foreach ($data as $value) {
            if (connection_aborted()) {
                error_log('search_guard: request aborted in response normalization');
                exit;
            }
            $booking_token = $value['booking_token'];
            $redirect_url  = $value['deep_link'];
            $return_array  = [];

            // Group segments by return flag (0 for departure, 1 for return)
            $departure_segments = [];
            $return_segments    = [];

            foreach ($value['route'] as $segment) {
                if ($segment['return'] == 0) {
                    $departure_segments[] = $segment;
                } else {
                    $return_segments[] = $segment;
                }
            }

            // Process departure segments
            if (!empty($departure_segments)) {
                $sub_array = array();
                $total     = [];

                foreach ($departure_segments as $duration) {
                    $total[] = \timetodate($duration['utc_departure'], $duration['utc_arrival']);
                }

                $timesString = implode(":", $total);
                preg_match_all('/(\d{1,}):(\d{1,})/', $timesString, $matches);
                $totalHours   = array_sum($matches[1]);
                $totalMinutes = array_sum($matches[2]);
                $totalHours  += floor($totalMinutes / 60);
                $totalMinutes %= 60;

                foreach ($departure_segments as $key) {
                    $class = '';
                    if ($key['fare_category'] == 'M') { $class = "economy"; }
                    if ($key['fare_category'] == 'W') { $class = "economy premium"; }
                    if ($key['fare_category'] == 'C') { $class = "business"; }
                    if ($key['fare_category'] == 'F') { $class = "first class"; }

                    $flightCurrency = $decode['currency'];

                    // Apply markup and conversion using helpers
                    $marked_up_total = MARKUP($value['price'],           $module, $db, $flightCurrency, $currency);
                    $converted_total = CURRENCY_CONVERT($value['price'], $db, $flightCurrency, $currency);

                    $marked_up_adult  = MARKUP($value['fare']['adults'],   $module, $db, $flightCurrency, $currency);
                    $marked_up_child  = MARKUP($value['fare']['children'], $module, $db, $flightCurrency, $currency);
                    $marked_up_infant = MARKUP($value['fare']['infants'],  $module, $db, $flightCurrency, $currency);

                    $converted_adult  = CURRENCY_CONVERT($value['fare']['adults'],   $db, $flightCurrency, $currency);
                    $converted_child  = CURRENCY_CONVERT($value['fare']['children'], $db, $flightCurrency, $currency);
                    $converted_infant = CURRENCY_CONVERT($value['fare']['infants'],  $db, $flightCurrency, $currency);

                    $sub_array[] = (object) [
                        'img'              => $key['airline'],
                        'flight_no'        => $key['flight_no'],
                        'airline'          => $key['airline'],
                        'class'            => $class,
                        'baggage'          => (isset($value['baglimit']['hold_weight'])) ? $value['baglimit']['hold_weight'] . " KG" : "0 PC",
                        'cabin_baggage'    => (isset($value['baglimit']['hand_weight'])) ? $value['baglimit']['hand_weight'] . " KG" : "0 PC",
                        'departure_airport'=> $key['cityFrom'],
                        'departure_time'   => date("h:i a", strtotime($key['utc_departure'])),
                        'arrival_airport'  => $key['cityTo'],
                        'arrival_time'     => date("h:i a", strtotime($key['utc_arrival'])),
                        'departure_date'   => date("d-m-Y",  strtotime($key['utc_departure'])),
                        'arrival_date'     => date("d-m-Y",  strtotime($key['utc_arrival'])),
                        'departure_code'   => $key['cityCodeFrom'],
                        'arrival_code'     => $key['cityCodeTo'],
                        'currency'         => $currency,
                        'price'            => number_format((float) $marked_up_total['price'],  2, '.', ''),
                        'actual_price'     => number_format((float) $converted_total['price'],  2, '.', ''),
                        'duration_time'    => \timetodate($key['utc_departure'], $key['utc_arrival']),
                        'total_duration'   => $totalHours . ":" . $totalMinutes,
                        'adult_price'      => number_format((float) $marked_up_adult['price'],  2, '.', ''),
                        'child_price'      => number_format((float) $marked_up_child['price'],  2, '.', ''),
                        'infant_price'     => number_format((float) $marked_up_infant['price'], 2, '.', ''),
                        'actual_adult_price'  => number_format((float) $converted_adult['price'],  2, '.', ''),
                        'actual_child_price'  => number_format((float) $converted_child['price'],  2, '.', ''),
                        'actual_infant_price' => number_format((float) $converted_infant['price'], 2, '.', ''),
                        'options'          => '',
                        'booking_data'     => array(
                            'booking_token' => $booking_token,
                            'session_id'    => $decode['search_id'],
                            'currency'      => $currency,
                            'amount'        => $marked_up_total['price'],
                            'actual_amount' => $converted_total['price']
                        ),
                        'redirect_url'     => $redirect_url,
                        'refundable'       => '1',
                        'supplier'         => 'kiwi',
                        'type'             => $_POST['type'],
                    ];
                }

                $return_array["segments"][] = $sub_array;
            }

            // Process return segments for round trips
            if (!empty($return_segments)) {
                $sub_array = array();
                $total     = [];

                foreach ($return_segments as $duration) {
                    $total[] = \timetodate($duration['utc_departure'], $duration['utc_arrival']);
                }

                $timesString = implode(":", $total);
                preg_match_all('/(\d{1,}):(\d{1,})/', $timesString, $matches);
                $totalHours   = array_sum($matches[1]);
                $totalMinutes = array_sum($matches[2]);
                $totalHours  += floor($totalMinutes / 60);
                $totalMinutes %= 60;

                foreach ($return_segments as $key) {
                    $class = '';
                    if ($key['fare_category'] == 'M') { $class = "economy"; }
                    if ($key['fare_category'] == 'W') { $class = "economy premium"; }
                    if ($key['fare_category'] == 'C') { $class = "business"; }
                    if ($key['fare_category'] == 'F') { $class = "first class"; }

                    $flightCurrency = $decode['currency'];

                    // Apply markup and conversion using helpers
                    $marked_up_total = MARKUP($value['price'],           $module, $db, $flightCurrency, $currency);
                    $converted_total = CURRENCY_CONVERT($value['price'], $db, $flightCurrency, $currency);

                    $marked_up_adult  = MARKUP($value['fare']['adults'],   $module, $db, $flightCurrency, $currency);
                    $marked_up_child  = MARKUP($value['fare']['children'], $module, $db, $flightCurrency, $currency);
                    $marked_up_infant = MARKUP($value['fare']['infants'],  $module, $db, $flightCurrency, $currency);

                    $converted_adult  = CURRENCY_CONVERT($value['fare']['adults'],   $db, $flightCurrency, $currency);
                    $converted_child  = CURRENCY_CONVERT($value['fare']['children'], $db, $flightCurrency, $currency);
                    $converted_infant = CURRENCY_CONVERT($value['fare']['infants'],  $db, $flightCurrency, $currency);

                    $sub_array[] = (object) [
                        'img'              => $key['airline'],
                        'flight_no'        => $key['flight_no'],
                        'airline'          => $key['airline'],
                        'class'            => $class,
                        'baggage'          => (isset($value['baglimit']['hold_weight'])) ? $value['baglimit']['hold_weight'] . " KG" : "0 PC",
                        'cabin_baggage'    => (isset($value['baglimit']['hand_weight'])) ? $value['baglimit']['hand_weight'] . " KG" : "0 PC",
                        'departure_airport'=> $key['cityFrom'],
                        'departure_time'   => date("h:i a", strtotime($key['utc_departure'])),
                        'arrival_airport'  => $key['cityTo'],
                        'arrival_time'     => date("h:i a", strtotime($key['utc_arrival'])),
                        'departure_date'   => date("d-m-Y",  strtotime($key['utc_departure'])),
                        'arrival_date'     => date("d-m-Y",  strtotime($key['utc_arrival'])),
                        'departure_code'   => $key['cityCodeFrom'],
                        'arrival_code'     => $key['cityCodeTo'],
                        'currency'         => $currency,
                        'price'            => number_format((float) $marked_up_total['price'],  2, '.', ''),
                        'actual_price'     => number_format((float) $converted_total['price'],  2, '.', ''),
                        'duration_time'    => \timetodate($key['utc_departure'], $key['utc_arrival']),
                        'total_duration'   => $totalHours . ":" . $totalMinutes,
                        'adult_price'      => number_format((float) $marked_up_adult['price'],  2, '.', ''),
                        'child_price'      => number_format((float) $marked_up_child['price'],  2, '.', ''),
                        'infant_price'     => number_format((float) $marked_up_infant['price'], 2, '.', ''),
                        'actual_adult_price'  => number_format((float) $converted_adult['price'],  2, '.', ''),
                        'actual_child_price'  => number_format((float) $converted_child['price'],  2, '.', ''),
                        'actual_infant_price' => number_format((float) $converted_infant['price'], 2, '.', ''),
                        'options'          => '',
                        'booking_data'     => array(
                            'booking_token' => $booking_token,
                            'session_id'    => $decode['search_id'],
                            'currency'      => $currency,
                            'amount'        => $marked_up_total['price'],
                            'actual_amount' => $converted_total['price']
                        ),
                        'redirect_url'     => $redirect_url,
                        'refundable'       => '1',
                        'supplier'         => 'kiwi',
                        'type'             => $_POST['type'],
                    ];
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
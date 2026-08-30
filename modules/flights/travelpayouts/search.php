<?php

function tp_getduration($duration)
{
    if (is_numeric($duration)) { $duration = "PT".$duration."M"; }
    $interval = new DateInterval($duration);
    $hours = $interval->h + ($interval->d * 24);
    $minutes = $interval->i;
    return ($hours.":".$minutes);
}

$router->post('flights/travelpayouts/search', function() use ($db) {
    $type = $_POST['type'] ?? 'oneway';
    // Convert multicity to multiple for internal use
    if ($type === 'multicity') {
        $type = 'multiple';
        $_POST['type'] = 'multiple';
    }

    if ($type === 'multiple') {
        $routesRaw = $_POST['routes'] ?? '[]';
        $routes = [];
        if (is_array($routesRaw)) {
            $routes = $routesRaw;
        } elseif (is_string($routesRaw) && trim($routesRaw) !== '') {
            $routes = json_decode($routesRaw);
        }
        
        if (is_array($routes) && !empty($routes)) {
            $firstRoute = $routes[0];
            $firstFrom = is_object($firstRoute) ? ($firstRoute->from ?? '') : ($firstRoute['from'] ?? '');
            $firstTo = is_object($firstRoute) ? ($firstRoute->to ?? '') : ($firstRoute['to'] ?? '');
            $firstDate = is_object($firstRoute) ? ($firstRoute->date ?? '') : ($firstRoute['date'] ?? '');
            
            if (empty($_POST['origin'])) {
                $_POST['origin'] = $firstFrom;
            }
            if (empty($_POST['destination'])) {
                $_POST['destination'] = $firstTo;
            }
            if (empty($_POST['departure_date'])) {
                $_POST['departure_date'] = $firstDate;
            }
        }
    }

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

    header('Access-Control-Allow-Origin: *');

    $module = $db->get('modules', '*', ['name' => 'travelpayouts', 'type' => 'flights']);

    if (!$module || $module['status'] == 0) {
        echo json_encode(['status' => false, 'message' => 'Travelpayouts module not enabled', 'response' => []]);
        exit;
    }

    $token  = $module['c1'] ?? '';
    $marker = $module['c2'] ?? '';

    if (empty($token) || empty($marker)) {
        echo json_encode(['status' => false, 'message' => 'Travelpayouts API credentials not configured (Token & Affiliate Marker required)', 'response' => []]);
        exit;
    }

    $currency = strtoupper($_POST['currency'] ?? 'USD');

    try {
        // Manual validation
        if(isset($_POST['origin']) && trim($_POST['origin']) !== "") {} else { echo "origin - param or value missing "; die; }
        if(isset($_POST['destination']) && trim($_POST['destination']) !== "") {} else { echo "destination - param or value missing "; die; }
        if(isset($_POST['departure_date']) && trim($_POST['departure_date']) !== "") {} else { echo "departure_date - param or value missing "; die; }
        if(isset($_POST['adults']) && trim($_POST['adults']) !== "") {} else { echo "adults - param or value missing "; die; }
        if(isset($_POST['childrens']) && trim($_POST['childrens']) !== "") {} else { echo "childrens - param or value missing "; die; }
        if(isset($_POST['infants']) && trim($_POST['infants']) !== "") {} else { echo "infants - param or value missing "; die; }
        if(isset($_POST['currency']) && trim($_POST['currency']) !== "") {} else { echo "currency - param or value missing "; die; }
        if(isset($_POST['type']) && trim($_POST['type']) !== "") {} else { echo "type - param or value missing "; die; }
        if(isset($_POST['class']) && trim($_POST['class']) !== "") {} else { echo "class - param or value missing "; die; }

        $type        = $_POST['type'];
        $origin      = strtoupper(trim($_POST['origin']));
        $destination = strtoupper(trim($_POST['destination']));
        $adults      = intval($_POST['adults']);
        $children    = intval($_POST['childrens'] ?? 0);
        $infants     = intval($_POST['infants'] ?? 0);
        $user_ip     = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $locale      = 'en';
        $host        = $_SERVER['HTTP_HOST'] ?? 'localhost';

        // Handle both dd-mm-yyyy and other date formats
        $departureDate = date('Y-m-d', strtotime($_POST['departure_date']));
        $returnDate    = !empty($_POST['return_date']) ? date('Y-m-d', strtotime($_POST['return_date'])) : '';

        // ── 1. BUILD SEGMENTS ────────────────────────────────────────────────
        $segments = [];
        if ($type == 'multiple') {
            // Multicity: parse routes from POST
            $routesRaw = $_POST['routes'] ?? '[]';
            $routes = [];
            if (is_array($routesRaw)) {
                $routes = $routesRaw;
            } elseif (is_string($routesRaw) && trim($routesRaw) !== '') {
                $routes = json_decode($routesRaw);
            }
            if (is_array($routes)) {
                foreach ($routes as $route) {
                    $route_from = strtoupper(is_object($route) ? ($route->from ?? '') : ($route['from'] ?? ''));
                    $route_to = strtoupper(is_object($route) ? ($route->to ?? '') : ($route['to'] ?? ''));
                    // Parse both dd-mm-yyyy and other formats
                    $route_date = is_object($route) ? ($route->date ?? '') : ($route['date'] ?? '');
                    if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $route_date)) {
                        // dd-mm-yyyy format
                        list($day, $month, $year) = explode('-', $route_date);
                        $route_date = "$year-$month-$day";
                    } else {
                        $route_date = date('Y-m-d', strtotime($route_date));
                    }
                    $segments[] = ['origin' => $route_from, 'destination' => $route_to, 'date' => $route_date];
                }
            }
        } else {
            // Oneway/Return: use main form fields
            $segments = [
                ['origin' => $origin, 'destination' => $destination, 'date' => $departureDate]
            ];
            if ($type != 'oneway' && !empty($returnDate)) {
                $segments[] = ['origin' => $destination, 'destination' => $origin, 'date' => $returnDate];
            }
        }

        $trip_class  = 'Y';
        $class_input = strtolower($_POST['class']);
        if ($class_input == 'business')        { $trip_class = 'C'; }
        if ($class_input == 'first class')     { $trip_class = 'F'; }
        if ($class_input == 'economy premium') { $trip_class = 'W'; }

        // ── 2. BUILD SIGNATURE ───────────────────────────────────────────────
        $signature_parts = [$token];
        $signature_parts[] = $currency;
        $signature_parts[] = $host;
        $signature_parts[] = $locale;
        $signature_parts[] = $marker;
        $signature_parts[] = $adults;
        $signature_parts[] = $children;
        $signature_parts[] = $infants;

        foreach ($segments as $segment) {
            $signature_parts[] = $segment['date'];
            $signature_parts[] = $segment['destination'];
            $signature_parts[] = $segment['origin'];
        }

        $signature_parts[] = $trip_class;
        $signature_parts[] = $user_ip;

        $signature = md5(implode(':', $signature_parts));

        $payload = [
            'signature'  => $signature,
            'marker'     => $marker,
            'host'       => $host,
            'user_ip'    => $user_ip,
            'currency'   => $currency,
            'locale'     => $locale,
            'trip_class' => $trip_class,
            'passengers' => ['adults' => $adults, 'children' => $children, 'infants' => $infants],
            'segments'   => $segments
        ];

        // ── 3. INITIATE SEARCH ───────────────────────────────────────────────
        $ch = curl_init('https://api.travelpayouts.com/v1/flight_search');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
        $init_response = curl_exec($ch);
        curl_close($ch);

        $init_data = json_decode($init_response, true);
        if (!isset($init_data['search_id'])) {
            echo json_encode([]);
            return;
        }

        $search_id = $init_data['search_id'];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // ── 4. POLL FOR RESULTS ──────────────────────────────────────────────
        $proposals    = [];
        $max_attempts = 12;
        $attempt      = 0;
        $finished     = false;

        while ($attempt < $max_attempts && !$finished) {
            sleep(1);
            $ch = curl_init("https://api.travelpayouts.com/v1/flight_search_results?uuid={$search_id}");
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
            $res_body = curl_exec($ch);
            curl_close($ch);

            $res_data = json_decode($res_body, true);
            if (!empty($res_data) && is_array($res_data)) {
                foreach ($res_data as $chunk) {
                    if (!empty($chunk['proposals']) && is_array($chunk['proposals'])) {
                        foreach ($chunk['proposals'] as $prop) { $proposals[] = $prop; }
                    }
                    if (!empty($chunk['meta']['finished'])) { $finished = true; }
                }
            }
            $attempt++;
            if ($finished || ($attempt >= 3 && !empty($proposals))) { break; }
        }

        // ── 5. NORMALIZE RESULTS ─────────────────────────────────────────────
        $final_array = [];

        foreach ($proposals as $item) {
            if (empty($item['terms'])) continue;

            reset($item['terms']);
            $gate_id    = key($item['terms']);
            $price_info = $item['terms'][$gate_id];
            $base_price = floatval($price_info['price'] ?? 0);

            if ($base_price <= 0) continue;

            $gate_currency = strtoupper($price_info['currency'] ?? 'USD');
            $booking_token = $item['sign'] ?? ($item['uuid'] ?? $search_id);
            $is_direct     = !empty($item['is_direct']);
            $max_stops     = intval($item['max_stops'] ?? 0);

            // ── Hit TP click URL → returns real OTA URL with affiliate marker ─
            // redirect_url in response is the direct OTA link — assign to Book Now button
            $url_value    = $price_info['url'] ?? '';

            $tp_click_url = "https://api.travelpayouts.com/v1/flight_searches/{$search_id}/clicks/{$url_value}.json";

            $redirect_url = $tp_click_url;

            // ── Baggage ───────────────────────────────────────────────────────
            $gate_baggage  = $price_info['flights_baggage']  ?? [];
            $gate_handbags = $price_info['flights_handbags'] ?? [];

            // ── Pricing ───────────────────────────────────────────────────────
            $converted_total = CURRENCY_CONVERT($base_price, $db, $gate_currency, $currency);
            $marked_up_total = MARKUP($base_price, $module, $db, $gate_currency, $currency);

            $pax_total    = max(1, $adults + $children + $infants);
            $per_pax_base = $base_price / $pax_total;

            $marked_up_adult  = MARKUP($per_pax_base * $adults,           $module, $db, $gate_currency, $currency);
            $marked_up_child  = MARKUP($per_pax_base * max(1, $children), $module, $db, $gate_currency, $currency);
            $marked_up_infant = MARKUP($per_pax_base * max(1, $infants),  $module, $db, $gate_currency, $currency);
            $converted_adult  = CURRENCY_CONVERT($per_pax_base * $adults,           $db, $gate_currency, $currency);
            $converted_child  = CURRENCY_CONVERT($per_pax_base * max(1, $children), $db, $gate_currency, $currency);
            $converted_infant = CURRENCY_CONVERT($per_pax_base * max(1, $infants),  $db, $gate_currency, $currency);

            $tp_segments  = $item['segment'] ?? $item['segments'] ?? [];
            $return_array = [];

            foreach ($tp_segments as $seg_idx => $tp_segment) {
                $flights   = $tp_segment['flight'] ?? [];
                $sub_array = [];

                $seg_duration_mins = $item['segment_durations'][$seg_idx]
                    ?? array_sum(array_column($flights, 'duration'));
                $total_duration = sprintf('%d:%02d', floor($seg_duration_mins / 60), $seg_duration_mins % 60);

                foreach ($flights as $fl_idx => $f) {
                    $marketing_carrier = $f['marketing_carrier'] ?? ($f['carrier'] ?? '');
                    $operating_carrier = $f['operating_carrier'] ?? $marketing_carrier;
                    $flight_no         = $f['number'] ?? '';

                    $dep_dt = !empty($f['local_departure_timestamp'])
                        ? $f['local_departure_timestamp']
                        : strtotime(($f['departure_date'] ?? '') . ' ' . ($f['departure_time'] ?? '00:00'));
                    $arr_dt = !empty($f['local_arrival_timestamp'])
                        ? $f['local_arrival_timestamp']
                        : strtotime(($f['arrival_date'] ?? '') . ' ' . ($f['arrival_time'] ?? '00:00'));

                    $raw_baggage = $gate_baggage[$seg_idx][$fl_idx]  ?? '';
                    $raw_handbag = $gate_handbags[$seg_idx][$fl_idx] ?? '';

                    $baggage_display = '';
                    $handbag_display = '';
                    if ($raw_baggage) {
                        if (preg_match('/^(\d+)PC(\d+)$/i', $raw_baggage, $m)) {
                            $baggage_display = $m[1] . ' PC / ' . $m[2] . ' KG';
                        } else {
                            $baggage_display = $raw_baggage;
                        }
                    }
                    if ($raw_handbag) {
                        if (preg_match('/^(\d+)PC([\dx]+)$/i', $raw_handbag, $m)) {
                            $dims = explode('x', $m[2]);
                            $handbag_display = $m[1] . ' PC / ' . $dims[0] . ' KG';
                        } else {
                            $handbag_display = $raw_handbag;
                        }
                    }

                    $sub_array[] = (object)[
                        'img'                 => $marketing_carrier,
                        'flight_no'           => $marketing_carrier . $flight_no,
                        'airline'             => $marketing_carrier,
                        'operating_airline'   => $operating_carrier,
                        'class'               => $class_input,
                        'baggage'             => $baggage_display,
                        'cabin_baggage'       => $handbag_display,
                        'departure_airport'   => $f['departure'] ?? '',
                        'departure_time'      => date("h:i a", $dep_dt),
                        'arrival_airport'     => $f['arrival']   ?? '',
                        'arrival_time'        => date("h:i a", $arr_dt),
                        'departure_date'      => date("d-m-Y",  $dep_dt),
                        'arrival_date'        => date("d-m-Y",  $arr_dt),
                        'departure_code'      => $f['departure'] ?? '',
                        'arrival_code'        => $f['arrival']   ?? '',
                        'currency'            => $currency,
                        'price'               => number_format($marked_up_total['price'],  2, '.', ''),
                        'actual_price'        => number_format($converted_total['price'],  2, '.', ''),
                        'duration_time'       => tp_getduration($f['duration']),
                        'total_duration'      => $total_duration,
                        'adult_price'         => number_format($marked_up_adult['price'],  2, '.', ''),
                        'child_price'         => number_format($marked_up_child['price'],  2, '.', ''),
                        'infant_price'        => number_format($marked_up_infant['price'], 2, '.', ''),
                        'actual_adult_price'  => number_format($converted_adult['price'],  2, '.', ''),
                        'actual_child_price'  => number_format($converted_child['price'],  2, '.', ''),
                        'actual_infant_price' => number_format($converted_infant['price'], 2, '.', ''),
                        'is_direct'           => $is_direct,
                        'stops'               => $max_stops,
                        'options'             => '',
                        'booking_data'        => [
                            'booking_token' => $booking_token,
                            'session_id'    => $search_id,
                            'gate_id'       => $gate_id,
                            'currency'      => $currency,
                            'amount'        => $marked_up_total['price'],
                            'actual_amount' => $converted_total['price'],
                            'redirect_url'  => $redirect_url,
                            'curl_enabled'  => 1,
                        ],
                        'redirect_url' => $redirect_url, // ← direct OTA URL, use on Book Now button
                        'curl_enabled'  => 1,
                        'refundable'   => '',
                        'supplier'     => 'travelpayouts',
                        'type'         => ($_POST['type'] === 'multiple' ? 'multicity' : $_POST['type']),
                    ];
                }
                $return_array["segments"][] = $sub_array;
            }
            $final_array[] = $return_array;
        }

        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');
        echo json_encode(!empty($final_array) ? $final_array : []);

    } catch (Exception $e) {
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');
        echo json_encode(['status' => false, 'message' => 'An error occurred. Please try again later.']);
    }
});

$router->post('flights/travelpayouts/handle-click', function() use ($db) {

    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json');

    $redirect_link = trim($_POST['redirect_link'] ?? '');

    if (empty($redirect_link)) {
        echo json_encode(['status' => false, 'message' => 'redirect_link is required']);
        return;
    }

    // Validate it's actually a Travelpayouts click URL (basic safety check)
    $allowed_host = 'api.travelpayouts.com';
    $parsed = parse_url($redirect_link);
    if (!isset($parsed['host']) || $parsed['host'] !== $allowed_host) {
        echo json_encode(['status' => false, 'message' => 'Invalid redirect_link host']);
        return;
    }

    // Execute click tracking curl on demand (user-triggered, policy-compliant)
    $ch = curl_init($redirect_link);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    $final_url = $data['url'] ?? null;

    if ($final_url) {
        echo json_encode(['status' => true, 'url' => $final_url]);
    } else {
        // Fallback: return the original click URL so the user isn't stuck
        echo json_encode(['status' => true, 'url' => $redirect_link, 'fallback' => true]);
    }
});

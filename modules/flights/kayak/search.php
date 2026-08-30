<?php

function getKayakDuration($minutes)
{
    $hours = floor($minutes / 60);
    $mins  = $minutes % 60;
    return sprintf("%d:%02d", $hours, $mins);
}

$router->post('flights/kayak/search', function() use ($db) {

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

    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json');

    // Get module configuration
    $module = $db->get('modules', '*', [
        'name' => 'kayak',
        'type' => 'flights'
    ]);

    if (!$module) {
        echo json_encode([
            'status'   => false,
            'message'  => 'Kayak module not configured',
            'response' => []
        ]);
        exit;
    }

    // Get API credentials from module config
    $api_key = $module['c1'] ?? '';
    $env     = $module['env'] ?? 'sandbox';

    if (empty($api_key)) {
        echo json_encode([
            'status'   => false,
            'message'  => 'Kayak API key not configured',
            'response' => []
        ]);
        exit;
    }

    // Set endpoint based on environment
    $api_endpoint = ($env === 'production' || $env === 'live')
        ? 'https://en-us.kayakaffiliates.com'
        : 'https://sandbox-en-us.kayakaffiliates.com';

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

        $cabinClass = strtolower($_POST['class'] ?? 'economy');

        // Build passengers array
        $passengers = [];
        for ($i = 0; $i < (int)($_POST['adults']    ?? 1); $i++) { $passengers[] = "ADT"; }
        for ($i = 0; $i < (int)($_POST['childrens'] ?? 0); $i++) { $passengers[] = "CHD"; }
        for ($i = 0; $i < (int)($_POST['infants']   ?? 0); $i++) { $passengers[] = "INF"; }

        // ─────────────────────────────────────────────────────────────────
        // BUILD LEGS
        // ─────────────────────────────────────────────────────────────────
        $legs = [];

        if ($isMulticity) {
            // One leg per multicity route
            foreach ($multicityRoutes as $route) {
                $from = strtoupper($route['from'] ?? '');
                $to   = strtoupper($route['to']   ?? '');
                $date = $route['date'] ?? '';

                if (empty($from) || empty($to) || empty($date)) continue;

                $legs[] = [
                    'origin'      => ['locationType' => 'airports', 'airports' => [$from]],
                    'destination' => ['locationType' => 'airports', 'airports' => [$to]],
                    'date'        => date('Y-m-d', strtotime($date)),
                    'flex'        => 'exact'
                ];
            }

            if (empty($legs)) {
                echo json_encode(['status' => false, 'message' => 'No valid multicity legs could be built']);
                exit;
            }

        } else {
            // Validate required params for oneway/return
            $requiredParams = [
                'origin'         => 'Origin airport code',
                'destination'    => 'Destination airport code',
                'departure_date' => 'Departure date (DD-MM-YYYY)',
                'adults'         => 'Number of adults',
                'childrens'      => 'Number of children',
                'infants'        => 'Number of infants',
                'currency'       => 'Currency code',
                'type'           => 'Trip type',
                'class'          => 'Cabin class'
            ];

            foreach ($requiredParams as $param => $description) {
                if (!isset($_POST[$param]) || trim($_POST[$param]) === '') {
                    echo json_encode([
                        'status'   => false,
                        'message'  => "$param is required - $description",
                        'response' => []
                    ]);
                    exit;
                }
            }

            $departureDate = date('Y-m-d', strtotime($_POST['departure_date']));
            $returnDate    = ($type === 'return') ? date('Y-m-d', strtotime($_POST['return_date'])) : null;
            $origin        = strtoupper($_POST['origin']);
            $destination   = strtoupper($_POST['destination']);

            $legs[] = [
                'origin'      => ['locationType' => 'airports', 'airports' => [$origin]],
                'destination' => ['locationType' => 'airports', 'airports' => [$destination]],
                'date'        => $departureDate,
                'flex'        => 'exact'
            ];

            if ($type === 'return' && $returnDate) {
                $legs[] = [
                    'origin'      => ['locationType' => 'airports', 'airports' => [$destination]],
                    'destination' => ['locationType' => 'airports', 'airports' => [$origin]],
                    'date'        => $returnDate,
                    'flex'        => 'exact'
                ];
            }
        }

        // ─────────────────────────────────────────────────────────────────
        // STEP 1 : Start search
        // ─────────────────────────────────────────────────────────────────
        $searchPayload = [
            'searchStartParameters' => [
                'cabin'      => $cabinClass,
                'passengers' => $passengers,
                'legs'       => $legs
            ]
        ];

        // Generate unique userTrackId
        $userTrackId = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $searchUrl = $api_endpoint . '/i/api/affiliate/search/flight/v1/poll?apiKey=' . urlencode($api_key) . '&userTrackId=' . urlencode($userTrackId);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $searchUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($searchPayload),
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT        => $requestTimeout,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'User-Agent: kayakaffiliateapp',
                'x-original-client-ip: ' . ($_SERVER['REMOTE_ADDR'] ?? '8.8.8.8')
            ],
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (empty($result)) {
            echo json_encode(['status' => false, 'message' => 'No response from Kayak API', 'response' => []]);
            exit;
        }

        $searchResponse = json_decode($result, true);

        if ($httpCode !== 200 || !isset($searchResponse['searchId'])) {
            echo json_encode([
                'status'   => false,
                'message'  => 'Search initiation failed',
                'response' => [],
                'debug'    => $searchResponse
            ]);
            exit;
        }

        $searchId = $searchResponse['searchId'];
        $cluster  = $searchResponse['cluster'] ?? '';

        // ─────────────────────────────────────────────────────────────────
        // STEP 2 : Poll for results
        // ─────────────────────────────────────────────────────────────────
        $maxAttempts  = 5;
        $attempt      = 0;
        $pollStart    = microtime(true);
        $maxWait      = 25;
        $pollPayload  = json_encode(['searchId' => $searchId]);
        $pollResponse = [];

        do {
            $attempt++;
            if (connection_aborted()) {
                error_log('search_guard: request aborted during polling');
                exit;
            }
            usleep(500000); // 500ms between polls

            $pollUrl = $api_endpoint . '/i/api/affiliate/search/flight/v1/poll?apiKey=' . urlencode($api_key) . '&userTrackId=' . urlencode($userTrackId);
            if (!empty($cluster)) {
                $pollUrl .= '&cluster=' . urlencode($cluster);
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $pollUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $pollPayload,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_TIMEOUT        => $requestTimeout,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'User-Agent: kayakaffiliateapp',
                    'x-original-client-ip: ' . ($_SERVER['REMOTE_ADDR'] ?? '8.8.8.8')
                ],
                CURLOPT_SSL_VERIFYPEER => false
            ]);

            $result       = curl_exec($ch);
            curl_close($ch);
            $pollResponse = json_decode($result, true);

            $status = $pollResponse['status'] ?? 'searching';

        } while ($status !== 'complete' && $attempt < $maxAttempts && (microtime(true) - $pollStart) < $maxWait);

        if (!isset($pollResponse['results']) || empty($pollResponse['results'])) {
            echo json_encode([]);
            exit;
        }

        // ─────────────────────────────────────────────────────────────────
        // STEP 3 : Transform results to standard format
        // ─────────────────────────────────────────────────────────────────
        $final_array  = [];
        $kayakCurrency = $pollResponse['currency'] ?? 'USD';
        $numLegs      = count($legs);

        foreach ($pollResponse['results'] as $result) {
            if (connection_aborted()) {
                error_log('search_guard: request aborted in response normalization');
                exit;
            }

            // For multicity: only include results that have all legs
            if ($isMulticity && count($result['legs'] ?? []) < $numLegs) continue;

            // Get the cheapest booking option
            $bookingOptions = $result['bookingOptions'] ?? [];
            if (empty($bookingOptions)) continue;

            usort($bookingOptions, function($a, $b) {
                return ($a['displayPrice']['price'] ?? 999999) - ($b['displayPrice']['price'] ?? 999999);
            });

            $cheapestOption = $bookingOptions[0];
            $basePrice      = (float) ($cheapestOption['displayPrice']['price'] ?? 0);

            $converted_price = CURRENCY_CONVERT($basePrice, $db, $kayakCurrency, $currency);
            $marked_up_price = MARKUP($basePrice, $module, $db, $kayakCurrency, $currency);

            $return_array = ['segments' => []];

            // Process each leg — works for oneway, return AND multicity
            foreach ($result['legs'] as $legRef) {
                $legId = $legRef['id'];
                $leg   = $pollResponse['legs'][$legId] ?? null;
                if (!$leg) continue;

                $sub_array = [];

                foreach ($leg['segments'] as $segmentRef) {
                    $segmentId = $segmentRef['id'];
                    $segment   = $pollResponse['segments'][$segmentId] ?? null;
                    if (!$segment) continue;

                    $airlineCode  = $segment['airline'];
                    $airline      = $pollResponse['airlines'][$airlineCode] ?? null;
                    $airlineName  = $airline['displayName'] ?? $airlineCode;

                    $originCode   = $segment['origin'];
                    $destCode     = $segment['destination'];
                    $originAirport = $pollResponse['airports'][$originCode] ?? null;
                    $destAirport   = $pollResponse['airports'][$destCode]   ?? null;

                    $segCabin = $segment['cabin']['displayName'] ?? 'Economy';

                    // Baggage
                    $baggage       = 'Not Included';
                    $cabin_baggage = 'Not Included';

                    if (!empty($cheapestOption['fees']['checkedBag'])) {
                        $baggage = ucfirst($cheapestOption['fees']['checkedBag'][0]['restriction'] ?? 'Not Included');
                        $count   = count($cheapestOption['fees']['checkedBag']);
                        if ($count > 1) { $baggage = $count . ' Bags (' . $baggage . ')'; }
                    }

                    if (!empty($cheapestOption['fees']['carryOnBag'])) {
                        $cabin_baggage = ucfirst($cheapestOption['fees']['carryOnBag'][0]['restriction'] ?? 'Not Included');
                        $count         = count($cheapestOption['fees']['carryOnBag']);
                        if ($count > 1) { $cabin_baggage = $count . ' Bags (' . $cabin_baggage . ')'; }
                    }

                    $sub_array[] = (object) [
                        'img'               => $airlineCode,
                        'flight_no'         => $segment['flightNumber'],
                        'airline'           => $airlineName,
                        'class'             => $segCabin,
                        'baggage'           => $baggage,
                        'cabin_baggage'     => $cabin_baggage,
                        'departure_airport' => $originAirport['displayName'] ?? $originCode,
                        'departure_time'    => date('h:i a', strtotime($segment['departureTime'])),
                        'arrival_airport'   => $destAirport['displayName']   ?? $destCode,
                        'arrival_time'      => date('h:i a', strtotime($segment['arrivalTime'])),
                        'departure_date'    => date('d-m-Y', strtotime($segment['departureTime'])),
                        'arrival_date'      => date('d-m-Y', strtotime($segment['arrivalTime'])),
                        'departure_code'    => $originCode,
                        'arrival_code'      => $destCode,
                        'currency'          => $currency,
                        'price'             => number_format($marked_up_price['price'],    2, '.', ''),
                        'actual_price'      => number_format($converted_price['price'],    2, '.', ''),
                        'duration_time'     => getKayakDuration($segment['duration']),
                        'total_duration'    => getKayakDuration($leg['duration']),
                        'adult_price'       => number_format($marked_up_price['price'],    2, '.', ''),
                        'child_price'       => number_format($marked_up_price['price'],    2, '.', ''),
                        'infant_price'      => number_format($marked_up_price['price'],    2, '.', ''),
                        'actual_adult_price'  => number_format($converted_price['price'],  2, '.', ''),
                        'actual_child_price'  => number_format($converted_price['price'],  2, '.', ''),
                        'actual_infant_price' => number_format($converted_price['price'],  2, '.', ''),
                        'options'           => '',
                        'booking_data'      => [
                            'result_id'     => $result['id'],
                            'search_id'     => $searchId,
                            'cluster'       => $cluster,
                            'provider_code' => $cheapestOption['providerCode'],
                            'booking_token' => $result['id'],
                            'currency'      => $currency,
                            'amount'        => $marked_up_price['price'],
                            'actual_amount' => $converted_price['price']
                        ],
                        'redirect_url'      => $cheapestOption['bookingUrl'] ?? '',
                        'refundable'        => '',
                        'supplier'          => 'kayak',
                        'type'              => $type,
                    ];
                }

                if (!empty($sub_array)) {
                    $return_array['segments'][] = $sub_array;
                }
            }

            // For multicity: only include if all legs returned segments
            if ($isMulticity && count($return_array['segments']) < $numLegs) continue;

            if (!empty($return_array['segments'])) {
                $final_array[] = $return_array;
            }
        }

        echo json_encode($final_array);

    } catch (Exception $e) {
        echo json_encode([
            'status'   => false,
            'message'  => 'An error occurred: ' . $e->getMessage(),
            'response' => []
        ]);
    }
});
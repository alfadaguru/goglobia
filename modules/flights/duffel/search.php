<?php

function getduration($duration)
{
    $interval = new DateInterval($duration);

    $hours = $interval->h;
    $minutes = $interval->i;
    return ($hours.":".$minutes);
}

$router->post('flights/duffel/search', function() use ($db) {
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

    // Get module configuration
    $module = $db->get('modules', '*', [
        'name' => 'duffel',
        'type' => 'flights'
    ]);

    if (!$module) {
        echo json_encode([
            'status' => false,
            'message' => 'Duffel module not configured',
            'response' => []
        ]);
        exit;
    }

    // Get credentials from database
    $api_token = null;
    if (!empty($module['credentials'])) {
        $credentials = json_decode($module['credentials'], true);
        $api_token = $credentials['c1'] ?? null;
    }

    // Fallback to individual credential columns if JSON credentials not found
    if (!$api_token && !empty($module['c1'])) {
        $api_token = $module['c1'];
    }

    if (!$api_token) {
        echo json_encode([
            'status' => false,
            'message' => 'Duffel API token not configured. Please configure the module in admin panel.',
            'response' => []
        ]);
        exit;
    }

    $currency = strtoupper($_POST['currency'] ?? 'USD');

    try {
        // Create logs directory if it doesn't exist
        // $logDir = __DIR__ . "/logs";
        // if (!is_dir($logDir)) {
        //     mkdir($logDir, 0777, true);
        // }
        // Log request
        // file_put_contents("logs/_REQ.log", date('Y-m-d H:i:s') . "\n" . print_r(json_encode($_REQUEST), true));
        $type = $_POST['type'] ?? $_SESSION['flight_type'] ?? 'oneway';

        if ($type === 'multicity') {
            if (isset($_POST['routes']) && trim($_POST['routes']) !== "") {
                $routes = json_decode($_POST['routes'], true);
            } elseif (isset($_SESSION['multicity_routes']) && !empty($_SESSION['multicity_routes'])) {
                $routes = $_SESSION['multicity_routes'];
            } else {
                echo "routes - param or value missing for multicity search";
                die;
            }
            if (empty($routes)) {
                echo "routes list cannot be empty";
                die;
            }
        } else {
            if(isset($_POST['origin']) && trim($_POST['origin']) !== "") {} else {  echo "origin : LHE - param or value missing "; die; }
            if(isset($_POST['destination']) && trim($_POST['destination']) !== "") {} else { echo "destination : DXB - param or value missing "; die; }
            if(isset($_POST['departure_date']) && trim($_POST['departure_date']) !== "") {} else {  echo "departure_date : 10-10-2021 - param or value missing "; die; }
        }

        if(isset($_POST['adults']) && trim($_POST['adults']) !== "") {} else {  echo "adults : 1 - param or value missing "; die; }
        if(isset($_POST['childrens']) && trim($_POST['childrens']) !== "") {} else {  echo "childrens : 1 - param or value missing "; die; }
        if(isset($_POST['infants']) && trim($_POST['infants']) !== "") {} else {  echo "infants : 1 - param or value missing "; die; }
        if(isset($_POST['currency']) && trim($_POST['currency']) !== "") {} else { echo "currency : USD - param or value missing "; die; }
        if(isset($_POST['type']) && trim($_POST['type']) !== "") {} else {  echo "type : oneway | return - param or value missing "; die; }
        if(isset($_POST['class']) && trim($_POST['class']) !== "") {} else { echo "class - param or value missing "; die; }

        $passengers = [];
        for ($i = 0; $i < $_POST['adults']; $i++) {
            $passengers[] = ["type" => "adult"];
        }
        for ($i = 0; $i < $_POST['childrens']; $i++) {
            $passengers[] = ["type" => "child"];
        }
        for ($i = 0; $i < $_POST['infants']; $i++) {
            $passengers[] = ["type" => "infant_without_seat"];
        }

        if ($type === 'multicity') {
            $slices = [];
            foreach ($routes as $route) {
                $depDate = strtoupper(date('Y-m-d', strtotime($route['date'])));
                $slices[] = [
                    "departure_date" => $depDate,
                    "destination" => strtoupper($route['to']),
                    "origin" => strtoupper($route['from'])
                ];
            }
            $payload = json_encode(array(
                'data' => array(
                    'cabin_class' => $_POST['class'],
                    'slices' => $slices,
                    "passengers" => $passengers
                )
            ));
        } else {
            $departureDate = strtoupper(date('Y-m-d', strtotime($_POST['departure_date'])));
            $destination = $_POST['destination'];
            $origin = $_POST['origin'];

            if ($type == 'oneway') {
                $payload = json_encode(array(
                    'data' => array(
                        'cabin_class' => $_POST['class'],
                        'slices' => [array(
                            "departure_date" => $departureDate,
                            "destination" => $destination,
                            "origin" => $origin
                        )],
                        "passengers" => $passengers
                    )
                ));
            } else {
                $returnDate = strtoupper(date('Y-m-d', strtotime($_POST['return_date'])));
                $payload = json_encode(array(
                    'data' => array(
                        'cabin_class' => $_POST['class'],
                        'slices' => [array(
                            "departure_date" => $departureDate,
                            "destination" => $destination,
                            "origin" => $origin
                        ),
                        array(
                            "departure_date" => $returnDate,
                            "destination" => $origin,
                            "origin" => $destination
                        )
                    ],
                        "passengers" => $passengers
                    )
                ));
            }
        }
        // file_put_contents("logs/_API_REQUEST.log", date('Y-m-d H:i:s') . "\n" . print_r($payload, true));
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
        curl_setopt($ch, CURLOPT_URL, 'https://api.duffel.com/air/offer_requests');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
        $headers = [
            "Accept-Encoding: gzip",
            "Accept: application/json",
            "Content-Type: application/json",
            "Duffel-Version: v2",
            "Authorization: Bearer " . $api_token
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if (connection_aborted()) {
            error_log('search_guard: request aborted before supplier call');
            exit;
        }
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            if (curl_errno($ch) === CURLE_OPERATION_TIMEDOUT) {
                error_log('search_guard: supplier timeout duffel');
            }
            $errorMsg = 'Error:' . curl_error($ch);
            error_log('DUFFEL SEARCH cURL error: ' . $errorMsg);
            // Must stop here: on a cURL failure $result is false and continuing
            // to json_decode(false) silently yields no results instead of
            // surfacing the network error.
            curl_close($ch);
            echo json_encode(['status' => false, 'message' => 'Flight search is temporarily unavailable. Please try again.']);
            exit;
        }
        $decode = json_decode($result, true);

        // print_r($decode);
        // die;

        // Log response
        // file_put_contents("logs/_Api_Response.log", date('Y-m-d H:i:s') . "\n" . print_r(json_encode($decode), true));
        if(empty($decode['data'])){
            // file_put_contents("logs/_Api_Error.log", date('Y-m-d H:i:s') . " - No data in response\n");
            echo json_encode([]);
            return;
        } else {
            $data = $decode['data']['offers'];
        }
        $final_array = [];
        foreach ($data as $value) {
            if (connection_aborted()) {
                error_log('search_guard: request aborted in response normalization');
                exit;
            }
            $booking_token = ($value['id']);
            $return_array = [];

            // Extract included baggage per passenger from offer
            $includedBaggage = [];
            foreach ($value['slices'] as $sliceData) {
                foreach ($sliceData['segments'] as $segData) {
                    if (!empty($segData['passengers'])) {
                        foreach ($segData['passengers'] as $paxData) {
                            if (!empty($paxData['baggages'])) {
                                foreach ($paxData['baggages'] as $bagData) {
                                    $includedBaggage[] = [
                                        'type' => $bagData['type'] ?? 'checked',
                                        'quantity' => $bagData['quantity'] ?? 0
                                    ];
                                }
                            }
                        }
                    }
                }
            }

            // Determine baggage display strings
            $checkedBagDisplay = '';
            $cabinBagDisplay = '';
            foreach ($includedBaggage as $ib) {
                if ($ib['type'] === 'checked' && $ib['quantity'] > 0) {
                    $checkedBagDisplay = $ib['quantity'] . ' x Checked Bag';
                } elseif ($ib['type'] === 'carry_on' && $ib['quantity'] > 0) {
                    $cabinBagDisplay = $ib['quantity'] . ' x Cabin Bag';
                }
            }

            // Check if ancillary services are available for this offer
            $hasAvailableServices = !empty($value['available_services']) || true; // Duffel offers always support ancillaries

            foreach ($value['slices'] as $segment){
                $sub_array = array();
                foreach ($segment['segments'] as $key){
                    $total=[];
                    foreach($segment['segments'] as $duration){
                        $total[] = \timetodate($duration['departing_at'], $duration['arriving_at']);
                    }
                    $timesString = implode(":", $total);
                    preg_match_all('/(\d{1,}):(\d{1,})/', $timesString, $matches);
                    $totalHours = array_sum($matches[1]);
                    $totalMinutes = array_sum($matches[2]);
                    $totalHours += floor($totalMinutes / 60);
                    $totalMinutes %= 60;

                    // Get original currency from Duffel response
                    $flightCurrency = $value['base_currency'];

                    // Apply markup and conversion
                    $adultPrice = (float)$value['total_amount'];

                    // 1. Currency conversion WITHOUT markup for actual price
                    $converted_price = CURRENCY_CONVERT($adultPrice, $db, $flightCurrency, $currency);

                    // 2. Apply markup with currency conversion
                    $marked_up_price = MARKUP($adultPrice, $module, $db, $flightCurrency, $currency);

                    // Per-passenger baggage from this segment
                    $segBaggage = [];
                    if (!empty($key['passengers'])) {
                        foreach ($key['passengers'] as $segPax) {
                            if (!empty($segPax['baggages'])) {
                                $segBaggage = $segPax['baggages'];
                            }
                        }
                    }

                    $sub_array[] = (object)[
                        'img' => $key['operating_carrier']['iata_code'],
                        'flight_no' => $key['operating_carrier_flight_number'],
                        'airline' => $key['operating_carrier']['name'],
                        'class' => $key['passengers'][0]['cabin_class'],
                        'baggage' => $checkedBagDisplay ?: 'No checked baggage',
                        'cabin_baggage' => $cabinBagDisplay ?: 'Cabin bag included',
                        'departure_airport' => $key['origin']['name'],
                        'departure_time' => date("h:i a", strtotime($key['departing_at'])),
                        'arrival_airport' => $key['destination']['name'],
                        'arrival_time' => date("h:i a", strtotime($key['arriving_at'])),
                        'departure_date' => date("d-m-Y", strtotime($key['departing_at'])),
                        'arrival_date' => date("d-m-Y", strtotime($key['arriving_at'])),
                        'departure_code' => $key['origin']['iata_city_code'],
                        'arrival_code' => $key['destination']['iata_city_code'],
                        'currency' => $currency,
                        'price' => number_format($marked_up_price['price'], 2, '.', ''),
                        'actual_price' => number_format($converted_price['price'], 2, '.', ''),
                        'duration_time' =>  getduration($key['duration']),
                        'total_duration' => $totalHours.":".$totalMinutes,
                        'adult_price' => number_format($marked_up_price['price'], 2, '.', ''),
                        'child_price' => number_format($marked_up_price['price'], 2, '.', ''),
                        'infant_price' => number_format($marked_up_price['price'], 2, '.', ''),
                        'actual_adult_price' => number_format($converted_price['price'], 2, '.', ''),
                        'actual_child_price' => number_format($converted_price['price'], 2, '.', ''),
                        'actual_infant_price' => number_format($converted_price['price'], 2, '.', ''),
                        'options' => '',
                        'booking_data' => array(
                            'svc_id' => $value['owner']['id'],
                            'passenger_id' => $key['passengers'][0]['passenger_id'],
                            'booking_token' => $booking_token,
                            'offer_id' => $booking_token,
                            'currency' => $currency,
                            'amount' => $marked_up_price['price'],
                            'actual_amount' => $converted_price['price']
                        ),
                        'redirect_url' => '',
                        'refundable' => '',
                        'supplier' => 'duffel',
                        'type' => $_POST['type'],
                        // Ancillary & EMD support data
                        'has_ancillaries' => $hasAvailableServices,
                        'included_baggage' => $includedBaggage,
                        'segment_baggage' => $segBaggage,
                    ];
                }
                $return_array["segments"][] = $sub_array;
            }
            $final_array[] = $return_array;
        }
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');
        if (!empty($final_array)) {
            // file_put_contents("logs/_RESPONSE_AGGREGATED.log", date('Y-m-d H:i:s') . "\n" . print_r(json_encode($final_array), true));
            echo json_encode($final_array);
        }else{
            echo json_encode([]);
        }
    } catch (Exception $e) {
        file_put_contents("logs/_Api_Error.log", date('Y-m-d H:i:s') . " - " . $e->getMessage());
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');
        echo json_encode(array("status" => false, "message" => "An error occurred. Please try again later."));
    }
});
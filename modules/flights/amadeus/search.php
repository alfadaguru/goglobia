<?php

$router->post('flights/amadeus/search', function() use ($db) {
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
    global $pdo;

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

    // Get module configuration
    $module = $db->get('modules', '*', [
        'name' => 'amadeus',
        'type' => 'flights'
    ]);

    if (!$module) {
        echo json_encode([
            'status' => false,
            'message' => 'Amadeus module not configured',
            'response' => []
        ]);
        exit;
    }

    $currency = strtoupper($_POST['currency'] ?? 'USD');

    // Get credentials from database
    $grant_type = "client_credentials";
    $client_id = $module['c1'] ?? '';
    $client_secret = $module['c2'] ?? '';
    $env = ($module['dev_mode'] ?? 0) ? 'test' : 'production';

    // Validate credentials
    if (empty($client_id) || empty($client_secret)) {
        echo json_encode([
            'status' => false,
            'message' => 'Amadeus API credentials not configured',
            'response' => []
        ]);
        exit;
    }

    // Set endpoints based on environment
    if ($env == 'pro' || $env == 'production' || $env == 'live') {
        $end_pointv1 = 'https://travel.api.amadeus.com/v1/';
        $end_pointv2 = 'https://travel.api.amadeus.com/v2/';
    } else {
        $end_pointv1 = 'https://test.api.amadeus.com/v1/';
        $end_pointv2 = 'https://test.api.amadeus.com/v2/';
    }
    try {
        $type = $_POST['type'] ?? 'oneway';
        if ($type === 'multicity') {
            $type = 'multiple';
        }

        if ($type === 'multiple') {
            if (isset($_POST['routes']) && trim($_POST['routes']) !== "") {
                $routes = json_decode($_POST['routes']);
            } else {
                echo "routes - param or value missing ";
                die;
            }
        } else {
            if(isset($_POST['origin']) && trim($_POST['origin']) !== "") {} else { echo "origin : LHE - param or value missing "; die; }
            if(isset($_POST['destination']) && trim($_POST['destination']) !== "") {} else { echo "destination : DXB - param or value missing "; die; }
            if(isset($_POST['departure_date']) && trim($_POST['origin']) !== "") {} else { echo "departure_date : 10-10-2021 - param or value missing "; die; }
        }
        if(isset($_POST['adults']) && trim($_POST['adults']) !== "") {} else { echo "adults : 1 - param or value missing "; die; }
        if(isset($_POST['childrens']) && trim($_POST['childrens']) !== "") {} else { echo "childrens : 1 - param or value missing "; die; }
        if(isset($_POST['infants']) && trim($_POST['infants']) !== "") {} else { echo "infants : 1 - param or value missing "; die; }
        if(isset($_POST['currency']) && trim($_POST['currency']) !== "") {} else { echo "currency : USD - param or value missing "; die; }
        if(isset($_POST['type']) && trim($_POST['type']) !== "") {} else { echo "type : oneway | return - param or value missing "; die; }

        /*flight date & time*/
        $departureDate = isset($_POST['departure_date']) ? strtoupper(date('Y-m-d',strtotime($_POST['departure_date']))) : '';
        $departureTime = isset($_POST['departure_date']) ? strtoupper(date('h:i:s',strtotime($_POST['departure_date']))) : '';
        $returnDate = isset($_POST['return_date']) ? strtoupper(date('Y-m-d',strtotime($_POST['return_date']))) : '';
        $returnTime = isset($_POST['return_date']) ? strtoupper(date('h:i:s',strtotime($_POST['return_date']))) : '';
        /*end flight date & time*/

        /*flight route oneway*/
        $route_data = [];
        if ($type == 'oneway') {
            $route_data[] = (object)array(
                "id" => "1",
                "originLocationCode" => strtoupper($_POST['origin']),
                "destinationLocationCode" => strtoupper($_POST['destination']),
                "departureDateTimeRange" => array(
                    'date' => $departureDate,
                    'time' => $departureTime
                ),
            );
        }
        /*end flight route oneway*/

        /*flight route round*/
        if ($type == 'round' || $type == 'return') {
            $route_data[] = (object)array(
                "id" => "1",
                "originLocationCode" => strtoupper($_POST['origin']),
                "destinationLocationCode" => strtoupper($_POST['destination']),
                "departureDateTimeRange" => array(
                    'date' => $departureDate,
                    'time' => $departureTime
                ),

            );

            $route_data[] = (object)array(
                "id" => "2",
                "originLocationCode" => strtoupper($_POST['destination']),
                "destinationLocationCode" => strtoupper($_POST['origin']),
                "departureDateTimeRange" => array(
                    'date' => $returnDate,
                    'time' => $returnTime
                ),
            );
        }
        /*end flight route round*/

        /*flight route multiple*/
        if ($type == 'multiple') {

            $i = 1;
            foreach ($routes as $key=>$value){
                $route_data[] = (object)array(
                "id" => $i,
                "originLocationCode" => strtoupper($value->from),
                "destinationLocationCode" => strtoupper($value->to),
                "departureDateTimeRange" => array(
                    'date' => strtoupper(date('Y-m-d',strtotime($value->date))),
                )
            );
                $i++;
            }
        }
        /*end flight route multiple*/

        $total_adults = $_POST['adults'];
        $total_childrens = $_POST['childrens'];
        $total_infants = $_POST['infants'];

        /*travelers details*/
        $travelers_details = [];
        if ($_POST['adults']) {
            for ($i=1; $i < $_POST['adults']+1; $i++) {
                $travelers_details[] = (object)array(
                    "id" => $i,
                    "travelerType" => 'ADULT',
                    "fareOptions" => array('STANDARD'),

                );
            }

        }

        if ($_POST['childrens']) {
            for ($i=1; $i < $_POST['childrens']+1; $i++) {
                $travelers_details[] = (object)array(
                    "id" => $i + $_POST['adults'],
                    "travelerType" => 'CHILD',
                    "fareOptions" => array('STANDARD'),

                );
            }

        }

        if ($_POST['infants']) {
            for ($i=1; $i < $_POST['infants']+1; $i++) {
                $travelers_details[] = (object)array(
                    "id" => $i + $_POST['adults'] + $_POST['childrens'],
                    "travelerType" => 'SEATED_INFANT',
                    "fareOptions" => array('STANDARD'),

                );
            }

        }
        /*end travelers details*/

        $dynamic_search_data = array(
                'currencyCode'=> strtoupper($_POST['currency']),
                'originDestinations'=>$route_data,
                'travelers'=>$travelers_details,
                'sources'=>array('GDS'),
                'searchCriteria'=>(object)array(
                    'maxFlightOffers'=>100,
                    'flightFilters'=>(object)array(
                        'cabinRestrictions'=>array(
                            (object)array(
                                'cabin'=>strtoupper($_POST['class']),
                                'coverage'=>'MOST_SEGMENTS',
                                'originDestinationIds'=>array('1')
                            )
                        ),
                        'carrierRestrictions'=>(object)array(
                            'excludedCarrierCodes'=>array(
                                'AA',
                                'TP',
                                'AZ'
                            )
                        )

                    )
                ),
            );

        //Create Request Log
        // file_put_contents("_Api_Request.log", print_r(json_encode($dynamic_search_data), true));

        /*token api*/
        $curls = curl_init();
        curl_setopt($curls, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($curls, CURLOPT_TIMEOUT, $requestTimeout);
        curl_setopt($curls, CURLOPT_URL, $end_pointv1.'security/oauth2/token');
        curl_setopt($curls, CURLOPT_POST, true);
        curl_setopt($curls, CURLOPT_POSTFIELDS, "grant_type=".$grant_type."&client_id=".$client_id."&client_secret=".$client_secret);
        curl_setopt($curls, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
        curl_setopt($curls, CURLOPT_RETURNTRANSFER, true);
        $token = curl_exec($curls);

        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');

        // print_r($token);
        // die;

        $data = json_decode($token, true);

        if (empty($data) || !isset($data['access_token'])) {
            $token_data = json_decode($token, true);
            echo json_encode([
                'status' => 'error',
                'msg' => 'authentication_failed',
                'error' => isset($token_data['error']) ? $token_data['error'] : 'invalid_credentials',
                'error_description' => isset($token_data['error_description']) ? $token_data['error_description'] : 'Client credentials are invalid',
                'code' => isset($token_data['code']) ? $token_data['code'] : null,
                'title' => isset($token_data['title']) ? $token_data['title'] : null
            ]);
            exit;
        }

        /*flights searching*/
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_TIMEOUT, $requestTimeout);
        curl_setopt_array($curl, array(
            CURLOPT_URL => $end_pointv2.'shopping/flight-offers',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $requestTimeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS =>json_encode($dynamic_search_data),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' .$data['access_token']
            ),
        ));

        if (connection_aborted()) {
            error_log('search_guard: request aborted before supplier call');
            exit;
        }
        $result = curl_exec($curl);

        // print_r($result);
        // die;

        $main_array = array();
        $object_array = array();
        // file_put_contents("_REQ_PARAMS.log", print_r($_REQUEST, true));
        // file_put_contents("_RESP.log", print_r($result, true));

        $result_data = json_decode($result);

        // Check if API returned an error
        if (!$result_data || !isset($result_data->data)) {
            $error_response = json_decode($result, true);
            echo json_encode([
                'status' => 'error',
                'msg' => 'api_error',
                'error' => isset($error_response['errors']) ? $error_response['errors'] : 'No flights found or invalid response',
                'raw_response' => $error_response
            ]);
            exit;
        }

        foreach ($result_data->data as $key) {

            if (!empty($key->price->currency)) {
                $currency_code = $key->price->currency;
            }else{$currency_code = ''; }

            foreach ($key->itineraries as $value) {
                $test_array = array();
                foreach ($value->segments as $seg2) {

                    $adult_price = 0;
                    $child_price = 0;
                    $infant_price = 0;
                    $bags = '';
                    $class_type = '';
                    foreach ($key->travelerPricings as $travelerPricings) {
                        if ($travelerPricings->travelerType == 'ADULT') {
                            $adult_price = $travelerPricings->price->total;
                            $class_type = $travelerPricings->fareDetailsBySegment[0]->cabin;
                            if (isset($travelerPricings->fareDetailsBySegment[0]->includedCheckedBags->weight)){
                                $bags = $travelerPricings->fareDetailsBySegment[0]->includedCheckedBags->weight ." ".$travelerPricings->fareDetailsBySegment[0]->includedCheckedBags->weightUnit;
                            }else{
                                $bags = "";
                            }
                        }
                        if($travelerPricings->travelerType == 'CHILD') {
                            $child_price = $travelerPricings->price->total;
                            $class_type = $travelerPricings->fareDetailsBySegment[0]->cabin;
                        }
                        if($travelerPricings->travelerType == 'SEATED_INFANT') {
                            $infant_price = $travelerPricings->price->total;
                            $class_type = $travelerPricings->fareDetailsBySegment[0]->cabin;
                        }
                    }
                    // SECURITY (H2): prepared statement — carrierCode is from
                    // the Amadeus API response; never interpolate into SQL.
                    $airlineStmt = $pdo->prepare("SELECT * FROM `flights_airlines` WHERE `code` = ? LIMIT 1");
                    $airlineStmt->execute([$seg2->carrierCode]);
                    $airline = $airlineStmt->fetch(\PDO::FETCH_OBJ);
                    if(!empty($airline)){
                        $airline_name = $airline->name;
                    }else{
                        $airline_name = '';
                    }

                    $departure_airport = $pdo->query("SELECT * FROM `flights_airports` WHERE `code` = '".$seg2->departure->iataCode."'")->fetch(\PDO::FETCH_OBJ);
                    $airport_name = !empty($departure_airport) ? $departure_airport->airport : $seg2->departure->iataCode;

                    $arrival_airport = $pdo->query("SELECT * FROM `flights_airports` WHERE `code` = '".$seg2->arrival->iataCode."'")->fetch(\PDO::FETCH_OBJ);
                    $airport_arrival = !empty($arrival_airport) ? $arrival_airport->airport : $seg2->arrival->iataCode;

                    // echo $seg2->duration; exit();
                    $start = new DateTime('@0');
                    $start->add(new DateInterval($seg2->duration));
                    $duration_time = $start->format('H:i');

                    $last_duration = new DateTime('@0');
                    $last_duration->add(new DateInterval($seg2->duration));
                    if(count($value->segments) >= 2){$last_duration->add(new DateInterval($value->segments[count($value->segments) - 1]->duration));}

                    if(count($value->segments) >= 3){$last_duration->add(new DateInterval($value->segments[count($value->segments) - 2]->duration));}

                    if(count($value->segments) >= 4){$last_duration->add(new DateInterval($value->segments[count($value->segments) - 3]->duration));}

                    if(count($value->segments) >= 5){$last_duration->add(new DateInterval($value->segments[count($value->segments) - 4]->duration));}

                    $duration_last = $last_duration->format('H:i');

                    $test_array[] = (object)array(
                        'img' => $seg2->carrierCode,
                        'flight_no' => $seg2->aircraft->code,
                        'airline' => $airline_name,
                        'class' => strtolower($class_type),
                        'baggage' => $bags,
                        'cabin_baggage' => '',
                        'departure_airport' => $airport_name,
                        'departure_time' => date('h:i a', strtotime($seg2->departure->at)),
                        'departure_date' => date('d-m-Y', strtotime($seg2->departure->at)),
                        'departure_code' => $seg2->departure->iataCode,
                        'arrival_airport' => $airport_arrival,
                        'arrival_date' => date('d-m-Y', strtotime($seg2->arrival->at)),
                        'arrival_time' => date('h:i a', strtotime($seg2->arrival->at)),
                        'arrival_code' => $seg2->arrival->iataCode,
                        'arrival_time' => date('h:i a', strtotime($seg2->arrival->at)),
                        'arrival_code' => $seg2->arrival->iataCode,
                        'duration_time' => $duration_time,
                        'total_duration' => $duration_last,
                        'currency' => $currency,
                        'price' => number_format(MARKUP($key->price->total, $module, $db, $currency_code, $currency)['price'], 2, '.', ''),
                        'actual_price' => number_format(CURRENCY_CONVERT($key->price->total, $db, $currency_code, $currency)['price'], 2, '.', ''),
                        'adult_price' => number_format(MARKUP($adult_price * $total_adults, $module, $db, $currency_code, $currency)['price'], 2, '.', ''),
                        'child_price' => number_format(MARKUP($child_price * $total_childrens, $module, $db, $currency_code, $currency)['price'], 2, '.', ''),
                        'infant_price' => number_format(MARKUP($infant_price * $total_infants, $module, $db, $currency_code, $currency)['price'], 2, '.', ''),
                        'actual_adult_price' => number_format(CURRENCY_CONVERT($adult_price * $total_adults, $db, $currency_code, $currency)['price'], 2, '.', ''),
                        'actual_child_price' => number_format(CURRENCY_CONVERT($child_price * $total_childrens, $db, $currency_code, $currency)['price'], 2, '.', ''),
                        'actual_infant_price' => number_format(CURRENCY_CONVERT($infant_price * $total_infants, $db, $currency_code, $currency)['price'], 2, '.', ''),
                        'options' => 'packages data in array',
                        "booking_data" => [
                            'key' => json_decode(json_encode($key), true),  // Convert object to array for proper serialization
                            'currency' => $currency,
                            'amount' => MARKUP($key->price->total, $module, $db, $currency_code, $currency)['price'],
                            'actual_amount' => CURRENCY_CONVERT($key->price->total, $db, $currency_code, $currency)['price']
                        ],
                        "redirect_url" => '',
                        "refundable" => 0,
                        'supplier' => "amadeus",
                        "type" => $_POST['type']
                    );
                }
                array_push($object_array, $test_array) ;

            }
            $main_array[]["segments"] = $object_array;
            $object_array = [];
        }

        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');
        /*end new api pattren*/
        if (!empty($main_array)) {
            $flight_data = array_slice($main_array,0,200);
            echo json_encode($flight_data);
            // file_put_contents("_RESPONSE_AGGREGATED.log", print_r(json_encode($flight_data), true));
        }else{
            echo json_encode([]);
        }
    } catch (\Throwable $e) {
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'msg' => 'internal_error',
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => explode("\n", $e->getTraceAsString())
        ]);
        exit;
    }
});
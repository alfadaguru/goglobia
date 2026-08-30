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
            'status' => false,
            'message' => 'PKFare module not configured',
            'response' => []
        ]);
        exit;
    }

    // Load PKFare credentials from DB
    $c1 = $module['c1'] ?? '';
    $c2 = $module['c2'] ?? '';
    if (empty($c1) || empty($c2)) {
        echo json_encode(['status' => false, 'message' => 'PKFare credentials missing']);
        exit;
    }
    $sign = md5($c1 . $c2);

    $currency = strtoupper($_POST['currency'] ?? 'USD');

    try {
        // Validation
        if(isset($_POST['origin']) && trim($_POST['origin']) !== "") {} else {  echo "origin : LHE - param or value missing "; die; }
        if(isset($_POST['destination']) && trim($_POST['destination']) !== "") {} else { echo "destination : DXB - param or value missing "; die; }
        if(isset($_POST['departure_date']) && trim($_POST['departure_date']) !== "") {} else {  echo "departure_date : 10-10-2021 - param or value missing "; die; }
        if(isset($_POST['adults']) && trim($_POST['adults']) !== "") {} else {  echo "adults : 1 - param or value missing "; die; }
        if(isset($_POST['childrens']) && trim($_POST['childrens']) !== "") {} else {  echo "childrens : 1 - param or value missing "; die; }
        if(isset($_POST['infants']) && trim($_POST['infants']) !== "") {} else {  echo "infants : 1 - param or value missing "; die; }
        if(isset($_POST['currency']) && trim($_POST['currency']) !== "") {} else { echo "currency : USD - param or value missing "; die; }
        if(isset($_POST['type']) && trim($_POST['type']) !== "") {} else {  echo "type : oneway | return - param or value missing "; die; }
        if(isset($_POST['class']) && trim($_POST['class']) !== "") {} else { echo "class - param or value missing "; die; }
        
        $type = $_POST['type'];
        
        // PKFare cabin class mapping
        $cabinClass = '';
        if(strtolower($_POST['class']) == "economy") {
            $cabinClass = "Economy";
        } elseif(strtolower($_POST['class']) == "premium economy" || strtolower($_POST['class']) == "economy premium") {
            $cabinClass = "PremiumEconomy";
        } elseif(strtolower($_POST['class']) == "business") {
            $cabinClass = "Business";
        } elseif(strtolower($_POST['class']) == "first class" || strtolower($_POST['class']) == "first") {
            $cabinClass = "First";
        } else {
            $cabinClass = "Economy"; // default
        }

        /*flight date & time*/
        $departureDate = date('Y-m-d',strtotime($_POST['departure_date']));
        $returnDate = date('Y-m-d',strtotime($_POST['return_date']));
        $destination = $_POST['destination'];
        $origin = $_POST['origin'];
        /*end flight date & time*/

        if($type == 'oneway'){
            $payload = json_encode([array(
                'cabinClass' => $cabinClass,
                "departureDate" => $departureDate,
                "destination" => $destination,
                "origin" => $origin,
                "airline" => ""
            )]);
        } else {
            $payload = json_encode([array(
                'cabinClass' => $cabinClass,
                "departureDate" => $departureDate,
                "destination" => $destination,
                "origin" => $origin,
                "airline" => ""
            ),
            array(
                'cabinClass' => $cabinClass,
                "departureDate" => $returnDate,
                "destination" => $origin,
                "origin" => $destination,
                "airline" => ""
            )]);
        }

        $request = '{
            "authentication": {
                "partnerId": "'.$c1.'",
                "sign": "'.$sign.'"
            },
            "search": {
                "adults": "'.$_POST['adults'].'",
                "children": "'.$_POST['childrens'].'",
                "infants": "'.$_POST['infants'].'",
                "nonstop": 0,
                "airline": "",
                "solutions": 50,
                "searchAirLegs": '.$payload.'
            }
        }';

        // API Call
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
        curl_setopt($ch, CURLOPT_URL, 'https://api.pkfare.com/json/shoppingV4');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
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
        if(empty($decode['data'])){
            echo json_encode([]);
            return;
        } else {
            $data = $decode['data']['solutions'];
        }
        
        $final_array = [];
        foreach ($data as $value) {
            $return_array = [];
            
            // Process outbound journey (journey_0)
            if(isset($value['journeys']['journey_0'])) {
                $sub_array = array();
                $total = [];
                
                foreach($value['journeys']['journey_0'] as $journey_id) {
                    foreach($decode['data']['flights'] as $flight) {
                        if($flight['flightId'] === $journey_id) {
                            foreach($flight['segmengtIds'] as $segment_id) {
                                foreach($decode['data']['segments'] as $segment) {
                                    if($segment['segmentId'] === $segment_id) {
                                        $departureTime = new DateTime($segment['strDepartureTime']);
                                        $arrivalTime = new DateTime($segment['strArrivalTime']);
                                        if ($arrivalTime < $departureTime) {
                                            $arrivalTime->modify('+1 day');
                                        }
                                        $interval = $departureTime->diff($arrivalTime);
                                        $total[] = $interval->h.":".$interval->i;
                                    }
                                }
                            }
                        }
                    }
                }
                
                $timesString = implode(":", $total);
                preg_match_all('/(\d{1,}):(\d{1,})/', $timesString, $matches);
                $totalHours = array_sum($matches[1]);
                $totalMinutes = array_sum($matches[2]);
                $totalHours += floor($totalMinutes / 60);
                $totalMinutes %= 60;
                
                foreach($value['journeys']['journey_0'] as $journey_id) {
                    foreach($decode['data']['flights'] as $flight) {
                        if($flight['flightId'] === $journey_id) {
                            foreach($flight['segmengtIds'] as $segment_id) {
                                foreach($decode['data']['segments'] as $segment) {
                                    if($segment['segmentId'] === $segment_id) {
                                        $departureTime = new DateTime($segment['strDepartureTime']);
                                        $arrivalTime = new DateTime($segment['strArrivalTime']);
                                        if ($arrivalTime < $departureTime) {
                                            $arrivalTime->modify('+1 day');
                                        }
                                        $interval = $departureTime->diff($arrivalTime);
                                        
                                        // Price calculations
                                        $adult_fare = (float)$value['adtFare'];
                                        $adult_tax = (float)$value['adtTax'];
                                        $child_fare = (float)$value['chdFare'];
                                        $child_tax = (float)$value['chdTax'];
                                        $infant_fare = isset($value['infFare']) ? (float)$value['infFare'] : 0;
                                        $infant_tax = isset($value['infTax']) ? (float)$value['infTax'] : 0;
                                                                                $adult_total = $adult_fare + $adult_tax;
                                         $child_total = $child_fare + $child_tax;
                                         $infant_total = $infant_fare + $infant_tax;
                                         
                                         // Get original currency from PKFare response
                                         $flightCurrency = $value['currency'];

                                         // Apply markup and conversion
                                         $marked_up_adult = MARKUP($adult_total, $module, $db, $flightCurrency, $currency);
                                         $marked_up_child = MARKUP($child_total, $module, $db, $flightCurrency, $currency);
                                         $marked_up_infant = MARKUP($infant_total, $module, $db, $flightCurrency, $currency);

                                         $converted_adult = CURRENCY_CONVERT($adult_total, $db, $flightCurrency, $currency);
                                         $converted_child = CURRENCY_CONVERT($child_total, $db, $flightCurrency, $currency);
                                         $converted_infant = CURRENCY_CONVERT($infant_total, $db, $flightCurrency, $currency);

                                         $total_price = ($marked_up_adult['price'] * $_POST['adults']) + 
                                                      ($marked_up_child['price'] * $_POST['childrens']) + 
                                                      ($marked_up_infant['price'] * $_POST['infants']);

                                         $actual_total_price = ($converted_adult['price'] * $_POST['adults']) + 
                                                             ($converted_child['price'] * $_POST['childrens']) + 
                                                             ($converted_infant['price'] * $_POST['infants']);

                                        
                                        $refundable = 1;
                                        if(isset($value['miniRuleMap']['ADT'][0]['miniRules'][0]['penaltyType'])) {
                                            $refundable = $value['miniRuleMap']['ADT'][0]['miniRules'][0]['penaltyType'] == 0 ? 1 : 0;
                                        }
                                        
                                        $sub_array[] = (object)[
                                            'img' => $segment['airline'],
                                            'flight_no' => $segment['flightNum'],
                                            'airline' => $segment['airline'],
                                            'class' => $segment['cabinClass'],
                                            'baggage' => $value['baggageMap']['ADT'][0]['baggageWeight'] ?? $value['baggageMap']['ADT'][0]['baggageAmount'] ?? "",
                                            'cabin_baggage' => $value['baggageMap']['ADT'][0]['carryOnWeight'] ?? $value['baggageMap']['ADT'][0]['carryOnAmount'] ?? "",
                                            'departure_airport' => $segment['departure'],
                                            'departure_time' => date("h:i a", strtotime($segment['strDepartureTime'])),
                                            'arrival_airport' => $segment['arrival'],
                                            'arrival_time' => date("h:i a", strtotime($segment['strArrivalTime'])),
                                            'departure_date' => date("d-m-Y", strtotime($segment['strDepartureDate'])),
                                            'arrival_date' => date("d-m-Y", strtotime($segment['strArrivalDate'])),
                                            'departure_code' => $segment['departure'],
                                            'arrival_code' => $segment['arrival'],
                                             'currency' => $currency,
                                             'price' => number_format((float)$total_price, 2, '.', ''),
                                             'actual_price' => number_format((float)$actual_total_price, 2, '.', ''),
                                             'duration_time' => $interval->h.":".$interval->i,
                                             'total_duration' => $totalHours.":".$totalMinutes,
                                             'adult_price' => number_format((float)$marked_up_adult['price'], 2, '.', ''),
                                             'child_price' => number_format((float)$marked_up_child['price'], 2, '.', ''),
                                             'infant_price' => number_format((float)$marked_up_infant['price'], 2, '.', ''),
                                             'actual_adult_price' => number_format((float)$converted_adult['price'], 2, '.', ''),
                                             'actual_child_price' => number_format((float)$converted_child['price'], 2, '.', ''),
                                             'actual_infant_price' => number_format((float)$converted_infant['price'], 2, '.', ''),
                                             'options' => '',
                                             'booking_data' => array(
                                                 'solutionId' => $value['solutionId'],
                                                 'journey_0' => $value['journeys']['journey_0'],
                                                 'currency' => $currency,
                                                 'amount' => $total_price,
                                                 'actual_amount' => $actual_total_price
                                             ),
                                            'redirect_url' => '',
                                            'refundable' => $refundable,
                                            'supplier' => 'pkfare',
                                            'type' => $_POST['type'],
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
            if(isset($value['journeys']['journey_1'])) {
                $sub_array = array();
                $total = [];
                
                foreach($value['journeys']['journey_1'] as $journey_id) {
                    foreach($decode['data']['flights'] as $flight) {
                        if($flight['flightId'] === $journey_id) {
                            foreach($flight['segmengtIds'] as $segment_id) {
                                foreach($decode['data']['segments'] as $segment) {
                                    if($segment['segmentId'] === $segment_id) {
                                        $departureTime = new DateTime($segment['strDepartureTime']);
                                        $arrivalTime = new DateTime($segment['strArrivalTime']);
                                        if ($arrivalTime < $departureTime) {
                                            $arrivalTime->modify('+1 day');
                                        }
                                        $interval = $departureTime->diff($arrivalTime);
                                        $total[] = $interval->h.":".$interval->i;
                                    }
                                }
                            }
                        }
                    }
                }
                
                $timesString = implode(":", $total);
                preg_match_all('/(\d{1,}):(\d{1,})/', $timesString, $matches);
                $totalHours = array_sum($matches[1]);
                $totalMinutes = array_sum($matches[2]);
                $totalHours += floor($totalMinutes / 60);
                $totalMinutes %= 60;
                
                foreach($value['journeys']['journey_1'] as $journey_id) {
                    foreach($decode['data']['flights'] as $flight) {
                        if($flight['flightId'] === $journey_id) {
                            foreach($flight['segmengtIds'] as $segment_id) {
                                foreach($decode['data']['segments'] as $segment) {
                                    if($segment['segmentId'] === $segment_id) {
                                        $departureTime = new DateTime($segment['strDepartureTime']);
                                        $arrivalTime = new DateTime($segment['strArrivalTime']);
                                        if ($arrivalTime < $departureTime) {
                                            $arrivalTime->modify('+1 day');
                                        }
                                        $interval = $departureTime->diff($arrivalTime);
                                        
                                        // Price calculations for return journey
                                        $adult_fare = (float)$value['adtFare'];
                                        $adult_tax = (float)$value['adtTax'];
                                        $child_fare = (float)$value['chdFare'];
                                        $child_tax = (float)$value['chdTax'];
                                        $infant_fare = isset($value['infFare']) ? (float)$value['infFare'] : 0;
                                        $infant_tax = isset($value['infTax']) ? (float)$value['infTax'] : 0;

                                        $adult_total = $adult_fare + $adult_tax;
                                        $child_total = $child_fare + $child_tax;
                                        $infant_total = $infant_fare + $infant_tax;

                                        // Get original currency from PKFare response
                                        $flightCurrency = $value['currency'];

                                        // Apply markup and conversion
                                        $marked_up_adult = MARKUP($adult_total, $module, $db, $flightCurrency, $currency);
                                        $marked_up_child = MARKUP($child_total, $module, $db, $flightCurrency, $currency);
                                        $marked_up_infant = MARKUP($infant_total, $module, $db, $flightCurrency, $currency);

                                        $converted_adult = CURRENCY_CONVERT($adult_total, $db, $flightCurrency, $currency);
                                        $converted_child = CURRENCY_CONVERT($child_total, $db, $flightCurrency, $currency);
                                        $converted_infant = CURRENCY_CONVERT($infant_total, $db, $flightCurrency, $currency);

                                        $total_price = ($marked_up_adult['price'] * $_POST['adults']) + 
                                                     ($marked_up_child['price'] * $_POST['childrens']) + 
                                                     ($marked_up_infant['price'] * $_POST['infants']);

                                        $actual_total_price = ($converted_adult['price'] * $_POST['adults']) + 
                                                            ($converted_child['price'] * $_POST['childrens']) + 
                                                            ($converted_infant['price'] * $_POST['infants']);

                                        $refundable = 1;
                                        if(isset($value['miniRuleMap']['ADT'][0]['miniRules'][0]['penaltyType'])) {
                                            $refundable = $value['miniRuleMap']['ADT'][0]['miniRules'][0]['penaltyType'] == 0 ? 1 : 0;
                                        }
                                        
                                        $sub_array[] = (object)[
                                            'img' => $segment['airline'],
                                            'flight_no' => $segment['flightNum'],
                                            'airline' => $segment['airline'],
                                            'class' => $segment['cabinClass'],
                                            'baggage' => $value['baggageMap']['ADT'][0]['baggageWeight'] ?? $value['baggageMap']['ADT'][0]['baggageAmount'] ?? "",
                                            'cabin_baggage' => $value['baggageMap']['ADT'][0]['carryOnWeight'] ?? $value['baggageMap']['ADT'][0]['carryOnAmount'] ?? "",
                                            'departure_airport' => $segment['departure'],
                                            'departure_time' => date("h:i a", strtotime($segment['strDepartureTime'])),
                                            'arrival_airport' => $segment['arrival'],
                                            'arrival_time' => date("h:i a", strtotime($segment['strArrivalTime'])),
                                            'departure_date' => date("d-m-Y", strtotime($segment['strDepartureDate'])),
                                            'arrival_date' => date("d-m-Y", strtotime($segment['strArrivalDate'])),
                                            'departure_code' => $segment['departure'],
                                            'arrival_code' => $segment['arrival'],
                                             'currency' => $currency,
                                             'price' => number_format((float)$total_price, 2, '.', ''),
                                             'actual_price' => number_format((float)$actual_total_price, 2, '.', ''),
                                             'duration_time' => $interval->h.":".$interval->i,
                                             'total_duration' => $totalHours.":".$totalMinutes,
                                             'adult_price' => number_format((float)$marked_up_adult['price'], 2, '.', ''),
                                             'child_price' => number_format((float)$marked_up_child['price'], 2, '.', ''),
                                             'infant_price' => number_format((float)$marked_up_infant['price'], 2, '.', ''),
                                             'actual_adult_price' => number_format((float)$converted_adult['price'], 2, '.', ''),
                                             'actual_child_price' => number_format((float)$converted_child['price'], 2, '.', ''),
                                             'actual_infant_price' => number_format((float)$converted_infant['price'], 2, '.', ''),
                                             'options' => '',
                                             'booking_data' => array(
                                                 'solutionId' => $value['solutionId'],
                                                 'journey_1' => $value['journeys']['journey_1'],
                                                 'currency' => $currency,
                                                 'amount' => $total_price,
                                                 'actual_amount' => $actual_total_price
                                             ),
                                            'redirect_url' => '',
                                            'refundable' => $refundable,
                                            'supplier' => 'pkfare',
                                            'type' => $_POST['type'],
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

<?php

$router->post('flights/flights/search', function() use ($db) {
    $type = $_POST['type'] ?? 'oneway';
    if ($type === 'multicity') {
        echo json_encode([]);
        exit;
    }

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

    header('Content-Type: application/json');

    try {
        // Validate required parameters
        $required = [
            'origin' => "origin (e.g., LHE)",
            'destination' => "destination (e.g., DXB)",
            'departure_date' => "departure_date (e.g., 10-10-2025)",
            'adults' => "adults",
            'type' => "type (oneway/return)",
            'class' => "class (economy/business/first)"
        ];

        foreach ($required as $key => $msg) {
            if (empty($_POST[$key]) && $_POST[$key] !== "0") {
                echo json_encode(["error" => "$msg - param or value missing"]);
                die;
            }
        }

        // Set defaults
        $childrens = intval($_POST['childrens'] ?? 0);
        $infants = intval($_POST['infants'] ?? 0);
        $currency = strtoupper($_POST['currency'] ?? 'USD');

        // ========================================
        // MODULE CONFIGURATION
        // ========================================
        $module = $db->get('modules', '*', [
            'name' => 'flights',
            'type' => 'flights'
        ]);

        if (!$module) {
            echo json_encode([
                'status' => false,
                'message' => 'Flights module not configured',
                'response' => []
            ]);
            exit;
        }

        // Session currency takes precedence
        $sessionCurrency = isset($searchSessionData['app_currency']) ? $searchSessionData['app_currency'] : $currency;

        // Clean and prepare inputs
        $type = $_POST['type'];
        $class = strtolower($_POST['class']);
        $adults = intval($_POST['adults']);

        $departureDate = date('Y-m-d', strtotime($_POST['departure_date']));
        $departureDayOfWeek = strtolower(date('l', strtotime($departureDate)));

        $origin = strtoupper($_POST['origin']);
        $destination = strtoupper($_POST['destination']);

        // Get airport information
        $originAirport = $db->select('flights_airports', '*', ['code' => $origin])[0] ?? null;
        $destAirport = $db->select('flights_airports', '*', ['code' => $destination])[0] ?? null;

        if (!$originAirport) {
            echo json_encode([
                "status" => false,
                "message" => "Origin airport ($origin) not found.",
                "response" => []
            ]);
            return;
        }

        if (!$destAirport) {
            echo json_encode([
                "status" => false,
                "message" => "Destination airport ($destination) not found.",
                "response" => []
            ]);
            return;
        }

        $originAirportId = $originAirport['id'];
        $destAirportId = $destAirport['id'];

        // Define columns to select
        $flightColumns = [
            "f.id", "f.user_id", "f.status", "f.flight_type", "f.flight_number",
            "f.airline_id", "f.from_airport_id", "f.to_airport_id",
            "f.departure_date", "f.departure_day", "f.departure_time",
            "f.arrival_time", "f.arrival_day", "f.duration",
            "f.total_journey_duration", "f.is_direct", "f.layover_count",
            "f.routes",
            "f.economy_adult_price", "f.economy_child_price", "f.economy_infant_price",
            "f.premium_economy_adult_price", "f.premium_economy_child_price",
            "f.premium_economy_infant_price",
            "f.business_adult_price", "f.business_child_price", "f.business_infant_price",
            "f.first_adult_price", "f.first_child_price", "f.first_infant_price",
            "f.currency",
            "f.checked_baggage", "f.cabin_baggage",
            "f.available_seats", "f.total_seats",
            "f.refundable", "f.cancellation_fee",
            "f.has_wifi", "f.has_meal", "f.meal_type",
            "f.has_entertainment", "f.has_power_outlet",
            "origin_airport.code(origin_code)",
            "origin_airport.airport(origin_name)",
            "origin_airport.city(origin_city)",
            "dest_airport.code(dest_code)",
            "dest_airport.airport(dest_name)",
            "dest_airport.city(dest_city)",
            "airline.name(airline_name)",
            "airline.iata(airline_code)"
        ];

        // Get fixed schedule flights
        $fixedFlights = $db->select("flights(f)", [
            "[>]flights_airports(origin_airport)" => ["f.from_airport_id" => "id"],
            "[>]flights_airports(dest_airport)"   => ["f.to_airport_id"   => "id"],
            "[>]flights_airlines(airline)"        => ["f.airline_id"      => "id"],
        ],
        $flightColumns,
        [
            "AND" => [
                "f.status"        => 1,
                "f.flight_type"   => "fixed",
                "f.from_airport_id" => $originAirportId,
                "f.to_airport_id"   => $destAirportId,
                "f.departure_date"  => $departureDate
            ]
        ]);

        // Get recurring flights that match the day
        $recurringFlights = $db->select("flights(f)", [
            "[>]flights_airports(origin_airport)" => ["f.from_airport_id" => "id"],
            "[>]flights_airports(dest_airport)"   => ["f.to_airport_id"   => "id"],
            "[>]flights_airlines(airline)"        => ["f.airline_id"      => "id"],
        ],
        $flightColumns,
        [
            "AND" => [
                "f.status"        => 1,
                "f.flight_type"   => "recurring",
                "f.from_airport_id" => $originAirportId,
                "f.to_airport_id"   => $destAirportId,
                "f.departure_day" => [$departureDayOfWeek, "daily"]
            ]
        ]);

        $outboundFlights = array_merge($fixedFlights, $recurringFlights);

        // Handle return flights if requested
        $returnFlights = [];

        if ($type === 'return' && !empty($_POST['return_date'])) {

            $returnDate = date('Y-m-d', strtotime($_POST['return_date']));
            $returnDayOfWeek = strtolower(date('l', strtotime($returnDate)));

            $fixedReturnFlights = $db->select("flights(f)", [
                "[>]flights_airports(origin_airport)" => ["f.from_airport_id" => "id"],
                "[>]flights_airports(dest_airport)"   => ["f.to_airport_id"   => "id"],
                "[>]flights_airlines(airline)"        => ["f.airline_id"      => "id"],
            ],
            $flightColumns,
            [
                "AND" => [
                    "f.status" => 1,
                    "f.flight_type" => "fixed",
                    "f.from_airport_id" => $destAirportId,
                    "f.to_airport_id"   => $originAirportId,
                    "f.departure_date"  => $returnDate
                ]
            ]);

            $recurringReturnFlights = $db->select("flights(f)", [
                "[>]flights_airports(origin_airport)" => ["f.from_airport_id" => "id"],
                "[>]flights_airports(dest_airport)"   => ["f.to_airport_id"   => "id"],
                "[>]flights_airlines(airline)"        => ["f.airline_id"      => "id"],
            ],
            $flightColumns,
            [
                "AND" => [
                    "f.status" => 1,
                    "f.flight_type" => "recurring",
                    "f.from_airport_id" => $destAirportId,
                    "f.to_airport_id"   => $originAirportId,
                    "f.departure_day" => [$returnDayOfWeek, "daily"]
                ]
            ]);

            $returnFlights = array_merge($fixedReturnFlights, $recurringReturnFlights);
        }

        // Format output based on trip type
        $final_array = [];

        if ($type === 'oneway') {

            foreach ($outboundFlights as $flight) {
                $formattedFlight = formatFlight(
                    $flight, $class, $adults, $childrens, $infants, $sessionCurrency, $departureDate, $module, $db
                );

                if ($formattedFlight) {
                    $final_array[] = $formattedFlight;
                }
            }

        } else {

            foreach ($outboundFlights as $outbound) {
                foreach ($returnFlights as $return) {

                    $formattedOutbound = formatFlightSegment(
                        $outbound, $class, $adults, $childrens, $infants, $sessionCurrency, $departureDate, $module, $db
                    );

                    $formattedReturn = formatFlightSegment(
                        $return, $class, $adults, $childrens, $infants, $sessionCurrency, $_POST['return_date'], $module, $db
                    );

                    if ($formattedOutbound && $formattedReturn) {

                        $final_array[] = [
                            'segments' => [
                                $formattedOutbound['segments'],
                                $formattedReturn['segments']
                            ],
                            'price' => $formattedOutbound['price'] + $formattedReturn['price'],
                            'actual_price' => $formattedOutbound['actual_price'] + $formattedReturn['actual_price'],
                            'currency' => $sessionCurrency
                        ];
                    }
                }
            }
        }

        if (empty($final_array)) {
            echo json_encode([
                "status" => false,
                "message" => "No flights found for this route and date.",
                "response" => []
            ]);
        } else {
            echo json_encode($final_array);
        }

    } catch (Exception $e) {
        echo json_encode([
            "error" => "An error occurred.",
            "message" => $e->getMessage(),
            "trace" => $e->getTraceAsString()
        ]);
    }
});

function formatFlight($flight, $class, $adults, $childrens, $infants, $currency, $departureDate, $module, $db) {
    $segment = formatFlightSegment($flight, $class, $adults, $childrens, $infants, $currency, $departureDate, $module, $db);

    if (!$segment) return null;

    return [
        'segments' => [$segment['segments']],
        'price' => $segment['price'],
        'actual_price' => $segment['actual_price'],
        'currency' => $currency
    ];
}

function formatFlightSegment($flight, $class, $adults, $childrens, $infants, $currency, $date, $module, $db) {

    // Determine price fields based on selected class
    $pricePrefix = match($class) {
        'business' => 'business_',
        'first' => 'first_',
        'premium_economy' => 'premium_economy_',
        default => 'economy_'
    };

    // Check if selected class is available
    $adultPrice = floatval($flight[$pricePrefix . 'adult_price'] ?? 0);
    if ($adultPrice <= 0) {
        return null;
    }

    $childPrice = floatval($flight[$pricePrefix . 'child_price'] ?? 0);
    $infantPrice = floatval($flight[$pricePrefix . 'infant_price'] ?? 0);

    // Get flight's original currency (default to USD)
    $flightCurrency = !empty($flight['currency']) ? $flight['currency'] : 'USD';

    // ========================================
    // CORRECTED PRICING LOGIC (MARKUP & CONVERSION)
    // ========================================
    // 1. Currency conversion WITHOUT markup for actual price
    $converted_adult_price = CURRENCY_CONVERT($adultPrice, $db, $flightCurrency, $currency);
    $converted_child_price = CURRENCY_CONVERT($childPrice, $db, $flightCurrency, $currency);
    $converted_infant_price = CURRENCY_CONVERT($infantPrice, $db, $flightCurrency, $currency);

    // 2. Apply markup with currency conversion
    $marked_up_adult = MARKUP($adultPrice, $module, $db, $flightCurrency, $currency);
    $marked_up_child = MARKUP($childPrice, $module, $db, $flightCurrency, $currency);
    $marked_up_infant = MARKUP($infantPrice, $module, $db, $flightCurrency, $currency);

    // 3. Calculate totals
    $totalDisplayPrice = ($marked_up_adult['price'] * $adults) +
                        ($marked_up_child['price'] * $childrens) +
                        ($marked_up_infant['price'] * $infants);

    $totalActualPrice = ($converted_adult_price['price'] * $adults) +
                       ($converted_child_price['price'] * $childrens) +
                       ($converted_infant_price['price'] * $infants);

    // Parse flight routes
    $routes = json_decode($flight['routes'], true);
    if (!is_array($routes) || empty($routes)) {
        return null;
    }

    // Build segments for each route
    $segments = [];
    foreach ($routes as $index => $route) {

        $airlineInfo = getAirlineInfo($route['airline_id'] ?? $flight['airline_id']);

        $segDepartureDate = $route['departure_date'] ?? $date;
        $segArrivalDate = $route['arrival_date'] ?? $segDepartureDate;

        // For recurring flights, calculate dates based on search date
        if ($flight['flight_type'] === 'recurring') {
            $segDepartureDate = $date;

            $depTime = strtotime($segDepartureDate . ' ' . $route['departure_time']);
            $arrTime = strtotime($segDepartureDate . ' ' . $route['arrival_time']);

            if ($arrTime < $depTime) {
                $segArrivalDate = date('Y-m-d', strtotime($segDepartureDate . ' +1 day'));
            } else {
                $segArrivalDate = $segDepartureDate;
            }
        }

        $fromAirport = getAirportInfo($route['from_airport_id']);
        $toAirport = getAirportInfo($route['to_airport_id']);

        $segments[] = [
            'img' => $airlineInfo['code'] ?? $airlineInfo['iata'] ?? $flight['airline_code'],
            'flight_no' => $route['flight_number'] ?? $flight['flight_number'],
            'airline' => $airlineInfo['code'] ?? $airlineInfo['iata'] ?? $flight['airline_code'],
            'class' => $class,
            'baggage' => $flight['checked_baggage'] ?? '30kg',
            'cabin_baggage' => $flight['cabin_baggage'] ?? '7',
            'departure_airport' => $fromAirport['airport'] ?? $flight['origin_name'],
            'departure_time' => date('h:i a', strtotime($route['departure_time'])),
            'arrival_airport' => $toAirport['airport'] ?? $flight['dest_name'],
            'arrival_time' => date('h:i a', strtotime($route['arrival_time'])),
            'departure_date' => date('d M Y, D', strtotime($segDepartureDate)),
            'arrival_date' => date('d M Y, D', strtotime($segArrivalDate)),
            'departure_code' => $fromAirport['code'] ?? $flight['origin_code'],
            'arrival_code' => $toAirport['code'] ?? $flight['dest_code'],
            'currency' => $currency,
            'price' => number_format($totalDisplayPrice, 2, '.', ''),
            'actual_price' => number_format($totalActualPrice, 2, '.', ''),
            'duration_time' => $route['duration'] ?? $flight['duration'],
            'adult_price' => number_format($marked_up_adult['price'], 2, '.', ''),
            'child_price' => number_format($marked_up_child['price'], 2, '.', ''),
            'infant_price' => number_format($marked_up_infant['price'], 2, '.', ''),
            'actual_adult_price' => number_format($converted_adult_price['price'], 2, '.', ''),
            'actual_child_price' => number_format($converted_child_price['price'], 2, '.', ''),
            'actual_infant_price' => number_format($converted_infant_price['price'], 2, '.', ''),
            'booking_data' => [
                'flight_id' => $flight['id'],
                'currency' => $currency,
                'amount' => $totalDisplayPrice,
                'actual_amount' => $totalActualPrice,
                'class' => $class
            ],
            'refundable' => $flight['refundable'] ? true : false,
            'supplier' => 'flights',
            'type' => isset($route['type']) ? $route['type'] : 'oneway',
        ];

        // Calculate layover time for connecting flights
        if ($index > 0 && isset($routes[$index - 1])) {
            $prevRoute = $routes[$index - 1];
            $prevArrival = strtotime($prevRoute['arrival_time']);
            $currentDep = strtotime($route['departure_time']);
            $layoverMinutes = ($currentDep - $prevArrival) / 60;

            if ($layoverMinutes > 0) {
                $layoverHours = floor($layoverMinutes / 60);
                $layoverMins = $layoverMinutes % 60;
                $segments[$index]['layover'] = [
                    'duration' => sprintf('%dh %dm', $layoverHours, $layoverMins),
                    'airport' => $fromAirport['code'] ?? $route['departure_code']
                ];
            }
        }
    }

    return [
        'segments' => $segments,
        'price' => $totalDisplayPrice,
        'actual_price' => $totalActualPrice
    ];
}

function getAirlineInfo($airlineId) {
    global $db;
    static $cache = [];

    if (!isset($cache[$airlineId])) {
        $airline = $db->select('flights_airlines', '*', ['id' => $airlineId])[0] ?? null;
        $cache[$airlineId] = $airline;
    }

    return $cache[$airlineId];
}

function getAirportInfo($airportId) {
    global $db;
    static $cache = [];

    if (!isset($cache[$airportId])) {
        $airport = $db->select('flights_airports', '*', ['id' => $airportId])[0] ?? null;
        $cache[$airportId] = $airport;
    }

    return $cache[$airportId];
}
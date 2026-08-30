<?php
// app/routes/admin/flightsRoutes.php
@$SECURE or die('Access Denied!');

/*===================================================================
FLIGHTS ROUTES START
===================================================================*/

// ================================ GET /flights - LIST ALL FLIGHTS
$router->get(admin.'/flights', function () use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // META DATA
    $title = T::flights_management;
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/flights/flights.php";
    require_once views."includes/footer.php";
});

// ================================ GET /flights/add - ADD NEW FLIGHT FORM
$router->get(admin.'/flights/add', function () use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // SET MODE
    $mode = 'add';

    // FETCH AIRLINES
    $airlines = $db->select('flights_airlines', ['id', 'name', 'code', 'iata'], ['status' => '1', 'ORDER' => ['name' => 'ASC']]);
    
    // FETCH AIRPORTS
    $airports = $db->select('flights_airports', ['id', 'airport', 'city', 'country', 'code'], ['status' => '1', 'ORDER' => ['city' => 'ASC']]);
    
    // GET CURRENCIES
    $currencies = $db->select('currencies', ['name'], ['status' => 1]);

    $owner = null;
    
    // Restore form data from session if validation failed
    $form_data = $_SESSION['form_data'] ?? null;
    unset($_SESSION['form_data']); // Clear after using

    // META DATA
    $title = T::add_flight;
    $description = '';
    $header = true;
    $footer = true;
    
    require_once views."includes/header.php";
    require_once "app/views/admin/flights/flight.php";
    require_once views."includes/footer.php";
});

// ================================ POST /flights/add - ADD NEW FLIGHT
$router->post(admin.'/flights/add', function () use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    $user_id = trim($_POST['user_id'] ?? 0);
    $flight_type = trim($_POST['flight_type'] ?? 'fixed');
    $trip_type = trim($_POST['trip_type'] ?? 'one_way'); // Only used for form logic, NOT saved to DB
    $routes = $_POST['routes'] ?? [];
    $return_routes = $_POST['return_routes'] ?? [];

    // VALIDATION
    $errors = [];

    if (empty($routes) || !is_array($routes)) {
        $errors[] = 'At least one flight route is required';
    }

    // Validate main routes
    $validated_routes = [];
    foreach ($routes as $index => $route) {
        $route_num = $index + 1;
        
        // Required fields validation
        if (empty($route['airline_id'])) {
            $errors[] = "Route $route_num: Airline is required";
        }
        if (empty($route['from_airport_id'])) {
            $errors[] = "Route $route_num: Origin airport is required";
        }
        if (empty($route['to_airport_id'])) {
            $errors[] = "Route $route_num: Destination airport is required";
        }
        if (empty($route['flight_number'])) {
            $errors[] = "Route $route_num: Flight number is required";
        }
        if (empty($route['departure_time'])) {
            $errors[] = "Route $route_num: Departure time is required";
        }
        if (empty($route['arrival_time'])) {
            $errors[] = "Route $route_num: Arrival time is required";
        }

        // Date/Day validation based on flight type
        if ($flight_type === 'fixed') {
            if (empty($route['departure_date'])) {
                $errors[] = "Route $route_num: Departure date is required";
            }
            if (empty($route['arrival_date'])) {
                $errors[] = "Route $route_num: Arrival date is required";
            }
        } else {
            if (empty($route['departure_day'])) {
                $errors[] = "Route $route_num: Departure day is required";
            }
            if (empty($route['arrival_day'])) {
                $errors[] = "Route $route_num: Arrival day is required";
            }
        }

        // Same airport check
        if (!empty($route['from_airport_id']) && !empty($route['to_airport_id']) && 
            $route['from_airport_id'] == $route['to_airport_id']) {
            $errors[] = "Route $route_num: Origin and destination cannot be the same";
        }

        // Store validated route data
        $validated_routes[] = [
            'airline_id' => intval($route['airline_id'] ?? 0),
            'from_airport_id' => intval($route['from_airport_id'] ?? 0),
            'to_airport_id' => intval($route['to_airport_id'] ?? 0),
            'flight_number' => trim($route['flight_number'] ?? ''),
            'departure_date' => $flight_type === 'fixed' ? trim($route['departure_date'] ?? '') : null,
            'departure_day' => $flight_type === 'recurring' ? trim($route['departure_day'] ?? '') : null,
            'departure_time' => trim($route['departure_time'] ?? ''),
            'arrival_date' => $flight_type === 'fixed' ? trim($route['arrival_date'] ?? '') : null,
            'arrival_day' => $flight_type === 'recurring' ? trim($route['arrival_day'] ?? '') : null,
            'arrival_time' => trim($route['arrival_time'] ?? ''),
            'duration' => trim($route['duration'] ?? '')
        ];
    }

    // Validate return routes if round trip
    $validated_return_routes = [];
    if ($trip_type === 'round_trip') {
        if (empty($return_routes) || !is_array($return_routes)) {
            $errors[] = 'Return flight routes are required for round trips';
        } else {
            foreach ($return_routes as $index => $route) {
                $route_num = $index + 1;
                
                // Required fields validation
                if (empty($route['airline_id'])) {
                    $errors[] = "Return Route $route_num: Airline is required";
                }
                if (empty($route['from_airport_id'])) {
                    $errors[] = "Return Route $route_num: Origin airport is required";
                }
                if (empty($route['to_airport_id'])) {
                    $errors[] = "Return Route $route_num: Destination airport is required";
                }
                if (empty($route['flight_number'])) {
                    $errors[] = "Return Route $route_num: Flight number is required";
                }
                if (empty($route['departure_time'])) {
                    $errors[] = "Return Route $route_num: Departure time is required";
                }
                if (empty($route['arrival_time'])) {
                    $errors[] = "Return Route $route_num: Arrival time is required";
                }

                // Date/Day validation based on flight type
                if ($flight_type === 'fixed') {
                    if (empty($route['departure_date'])) {
                        $errors[] = "Return Route $route_num: Departure date is required";
                    }
                    if (empty($route['arrival_date'])) {
                        $errors[] = "Return Route $route_num: Arrival date is required";
                    }
                } else {
                    if (empty($route['departure_day'])) {
                        $errors[] = "Return Route $route_num: Departure day is required";
                    }
                    if (empty($route['arrival_day'])) {
                        $errors[] = "Return Route $route_num: Arrival day is required";
                    }
                }

                // Same airport check
                if (!empty($route['from_airport_id']) && !empty($route['to_airport_id']) && 
                    $route['from_airport_id'] == $route['to_airport_id']) {
                    $errors[] = "Return Route $route_num: Origin and destination cannot be the same";
                }

                // Store validated return route data
                $validated_return_routes[] = [
                    'airline_id' => intval($route['airline_id'] ?? 0),
                    'from_airport_id' => intval($route['from_airport_id'] ?? 0),
                    'to_airport_id' => intval($route['to_airport_id'] ?? 0),
                    'flight_number' => trim($route['flight_number'] ?? ''),
                    'departure_date' => $flight_type === 'fixed' ? trim($route['departure_date'] ?? '') : null,
                    'departure_day' => $flight_type === 'recurring' ? trim($route['departure_day'] ?? '') : null,
                    'departure_time' => trim($route['departure_time'] ?? ''),
                    'arrival_date' => $flight_type === 'fixed' ? trim($route['arrival_date'] ?? '') : null,
                    'arrival_day' => $flight_type === 'recurring' ? trim($route['arrival_day'] ?? '') : null,
                    'arrival_time' => trim($route['arrival_time'] ?? ''),
                    'duration' => trim($route['duration'] ?? '')
                ];
            }
        }
    }

    // Price validation - at least economy adult price is required
    $economy_adult_price = floatval($_POST['economy_adult_price'] ?? 0);
    if ($economy_adult_price <= 0) {
        $errors[] = 'Economy adult price is required';
    }

    // IF VALIDATION ERRORS, SHOW THEM AND PRESERVE FORM DATA
    if (!empty($errors)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => implode('<br>', $errors)
        ];
        // Preserve ALL form data including trip_type
        $_SESSION['form_data'] = $_POST;
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/flights/add');
        return;
    }

    try {
        // PREPARE BASE FLIGHT DATA (common for both flights)
        $base_flight_data = [
            'user_id' => $user_id,
            'status' => isset($_POST['status']) ? intval($_POST['status']) : 1,
            'flight_type' => $flight_type,
            // NOTE: trip_type is NOT saved to database - only used for form logic
            'featured' => isset($_POST['featured']) ? 1 : 0,
            // Pricing
            'economy_adult_price' => floatval($_POST['economy_adult_price'] ?? 0),
            'economy_child_price' => floatval($_POST['economy_child_price'] ?? 0),
            'economy_infant_price' => floatval($_POST['economy_infant_price'] ?? 0),
            
            'premium_economy_adult_price' => floatval($_POST['premium_economy_adult_price'] ?? 0),
            'premium_economy_child_price' => floatval($_POST['premium_economy_child_price'] ?? 0),
            'premium_economy_infant_price' => floatval($_POST['premium_economy_infant_price'] ?? 0),
            
            'business_adult_price' => floatval($_POST['business_adult_price'] ?? 0),
            'business_child_price' => floatval($_POST['business_child_price'] ?? 0),
            'business_infant_price' => floatval($_POST['business_infant_price'] ?? 0),
            
            'first_adult_price' => floatval($_POST['first_adult_price'] ?? 0),
            'first_child_price' => floatval($_POST['first_child_price'] ?? 0),
            'first_infant_price' => floatval($_POST['first_infant_price'] ?? 0),
            
            'currency' => trim($_POST['currency'] ?? 'USD'),
            
            // Baggage
            'checked_baggage' => trim($_POST['checked_baggage'] ?? '') ?: null,
            'cabin_baggage' => trim($_POST['cabin_baggage'] ?? '') ?: null,
            
            // Seats
            'available_seats' => intval($_POST['available_seats'] ?? 0),
            'total_seats' => intval($_POST['total_seats'] ?? 0),
            
            // Flight options
            'refundable' => isset($_POST['refundable']) ? 1 : 0,
            'cancellation_fee' => isset($_POST['refundable']) ? floatval($_POST['cancellation_fee'] ?? 0) : null,
            
            // Amenities
            'has_wifi' => isset($_POST['has_wifi']) ? 1 : 0,
            'has_meal' => isset($_POST['has_meal']) ? 1 : 0,
            'meal_type' => isset($_POST['has_meal']) && !empty($_POST['meal_type']) ? trim($_POST['meal_type']) : null,
            'has_entertainment' => isset($_POST['has_entertainment']) ? 1 : 0,
            'has_power_outlet' => isset($_POST['has_power_outlet']) ? 1 : 0,
            
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // CREATE OUTBOUND FLIGHT
        $first_route = $validated_routes[0];
        $last_route = $validated_routes[count($validated_routes) - 1];
        
        $is_direct = count($validated_routes) === 1 ? 1 : 0;
        $layover_count = count($validated_routes) - 1;
        
        $outbound_flight_data = array_merge($base_flight_data, [
            'flight_number' => $first_route['flight_number'],
            'airline_id' => $first_route['airline_id'],
            'from_airport_id' => $first_route['from_airport_id'],
            'to_airport_id' => $last_route['to_airport_id'],
            
            'departure_date' => $flight_type === 'fixed' ? $first_route['departure_date'] : null,
            'departure_day' => $flight_type === 'recurring' ? $first_route['departure_day'] : null,
            'departure_time' => $first_route['departure_time'],
            'arrival_time' => $last_route['arrival_time'],
            'arrival_day' => $flight_type === 'recurring' ? $last_route['arrival_day'] : null,
            'duration' => !empty($first_route['duration']) ? $first_route['duration'] : null,
            
            'is_direct' => $is_direct,
            'layover_count' => $layover_count,
            'routes' => json_encode($validated_routes)
        ]);
        
        $result = $db->insert('flights', $outbound_flight_data);
        
        if (!$result) {
            throw new Exception('Failed to create outbound flight');
        }
        
        $outbound_flight_id = $db->id();
        $created_flight_id = $outbound_flight_id;

        // CREATE RETURN FLIGHT IF ROUND TRIP (as completely separate flight)
        $return_flight_id = null;
        if ($trip_type === 'round_trip' && !empty($validated_return_routes)) {
            $first_return_route = $validated_return_routes[0];
            $last_return_route = $validated_return_routes[count($validated_return_routes) - 1];
            
            $is_return_direct = count($validated_return_routes) === 1 ? 1 : 0;
            $return_layover_count = count($validated_return_routes) - 1;
            
            // Create COMPLETELY SEPARATE flight entry - no linking
            $return_flight_data = array_merge($base_flight_data, [
                'flight_number' => $first_return_route['flight_number'],
                'airline_id' => $first_return_route['airline_id'],
                'from_airport_id' => $first_return_route['from_airport_id'],
                'to_airport_id' => $last_return_route['to_airport_id'],
                
                'departure_date' => $flight_type === 'fixed' ? $first_return_route['departure_date'] : null,
                'departure_day' => $flight_type === 'recurring' ? $first_return_route['departure_day'] : null,
                'departure_time' => $first_return_route['departure_time'],
                'arrival_time' => $last_return_route['arrival_time'],
                'arrival_day' => $flight_type === 'recurring' ? $last_return_route['arrival_day'] : null,
                'duration' => !empty($first_return_route['duration']) ? $first_return_route['duration'] : null,
                
                'is_direct' => $is_return_direct,
                'layover_count' => $return_layover_count,
                'routes' => json_encode($validated_return_routes),
            ]);
            
            $return_result = $db->insert('flights', $return_flight_data);
            
            if (!$return_result) {
                throw new Exception('Failed to create return flight');
            }
            
            $return_flight_id = $db->id();
        }
        
        $_SESSION['message'] = [
            'type' => 'success',
            'text' => T::flight_added_successfully
        ];
        
        // Clear form data on success
        unset($_SESSION['form_data']);
        
        redirect(root . admin . '/flights/edit/' . $created_flight_id);
        
    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::database_error . ': ' . $e->getMessage()
        ];
        // Preserve form data on error
        $_SESSION['form_data'] = $_POST;
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/flights/add');
    }
});

// ================================ GET /flights/edit/{id} - EDIT FLIGHT FORM
$router->get(admin.'/flights/edit/(.*)', function ($id) use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // SET MODE
    $mode = 'edit';

    $flight_id = intval($id);
    
    // CHECK IF FLIGHT EXISTS
    $flight = $db->get('flights', '*', ['id' => $flight_id]);
    
    if (!$flight) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::flight_not_found
        ];
        redirect(root . admin . '/flights');
        return;
    }
    
    // DECODE ROUTES JSON
    if (!empty($flight['routes'])) {
        $flight['routes'] = json_decode($flight['routes'], true);
    }
    
    // GET AIRLINE DETAILS
    $airline = $db->get('flights_airlines', ['id', 'name', 'code', 'iata'], ['id' => $flight['airline_id']]);
    
    // GET AIRPORT DETAILS
    $from_airport = $db->get('flights_airports', ['id', 'airport', 'city', 'country', 'code'], ['id' => $flight['from_airport_id']]);
    $to_airport = $db->get('flights_airports', ['id', 'airport', 'city', 'country', 'code'], ['id' => $flight['to_airport_id']]);
    
    // GET ALL AIRLINES
    $airlines = $db->select('flights_airlines', ['id', 'name', 'code', 'iata'], ['status' => '1', 'ORDER' => ['name' => 'ASC']]);
    
    // GET ALL AIRPORTS
    $airports = $db->select('flights_airports', ['id', 'airport', 'city', 'country', 'code'], ['status' => '1', 'ORDER' => ['city' => 'ASC']]);
    
    // GET CURRENCIES
    $currencies = $db->select('currencies', ['name'], ['status' => 1]);

    // GET OWNER - IMPORTANT: ensure we have user data
    $owner = null;
    if (!empty($flight['user_id'])) {
        $owner = $db->get('users', ['user_id', 'first_name', 'last_name', 'email'], ['user_id' => $flight['user_id']]);
        
        if (!$owner && $flight['user_id']) {
            $owner = [
                'user_id' => $flight['user_id'],
                'first_name' => 'Unknown',
                'last_name' => 'User',
                'email' => 'user@example.com'
            ];
        }
    }
    
    // Restore form data from session if validation failed
    $form_data = $_SESSION['form_data'] ?? null;
    unset($_SESSION['form_data']); // Clear after using
    
    // META DATA
    $title = T::edit_flight;
    $description = '';
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once "app/views/admin/flights/flight.php";
    require_once views."includes/footer.php";
});

// ================================ POST /flights/edit/{id} - UPDATE FLIGHT
$router->post(admin.'/flights/edit/(.*)', function ($id) use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    $flight_id = intval($id);
    
    // CHECK IF FLIGHT EXISTS
    $flight = $db->get('flights', '*', ['id' => $flight_id]);
    
    if (!$flight) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::flight_not_found
        ];
        redirect(root . admin . '/flights');
        return;
    }

    // USER_ID IMPORTANT: use existing user_id 
    $user_id = trim($_POST['user_id'] ?? '');
    $current_user_id = trim($_POST['current_user_id'] ?? '');
        
    if (empty($user_id) && !empty($current_user_id)) {
        $user_id = $current_user_id;
    }

    $flight_type = trim($_POST['flight_type'] ?? 'fixed');
    // NO trip_type in edit mode
    $routes = $_POST['routes'] ?? [];
    
    // VALIDATION
    $errors = [];
    
    if (empty($routes) || !is_array($routes)) {
        $errors[] = 'At least one flight route is required';
    }

    // Validate main routes
    $validated_routes = [];
    foreach ($routes as $index => $route) {
        $route_num = $index + 1;
        
        // Required fields validation
        if (empty($route['airline_id'])) {
            $errors[] = "Route $route_num: Airline is required";
        }
        if (empty($route['from_airport_id'])) {
            $errors[] = "Route $route_num: Origin airport is required";
        }
        if (empty($route['to_airport_id'])) {
            $errors[] = "Route $route_num: Destination airport is required";
        }
        if (empty($route['flight_number'])) {
            $errors[] = "Route $route_num: Flight number is required";
        }
        if (empty($route['departure_time'])) {
            $errors[] = "Route $route_num: Departure time is required";
        }
        if (empty($route['arrival_time'])) {
            $errors[] = "Route $route_num: Arrival time is required";
        }

        // Date/Day validation based on flight type
        if ($flight_type === 'fixed') {
            if (empty($route['departure_date'])) {
                $errors[] = "Route $route_num: Departure date is required";
            }
            if (empty($route['arrival_date'])) {
                $errors[] = "Route $route_num: Arrival date is required";
            }
        } else {
            if (empty($route['departure_day'])) {
                $errors[] = "Route $route_num: Departure day is required";
            }
            if (empty($route['arrival_day'])) {
                $errors[] = "Route $route_num: Arrival day is required";
            }
        }

        // Same airport check
        if (!empty($route['from_airport_id']) && !empty($route['to_airport_id']) && 
            $route['from_airport_id'] == $route['to_airport_id']) {
            $errors[] = "Route $route_num: Origin and destination cannot be the same";
        }

        // Store validated route data
        $validated_routes[] = [
            'airline_id' => intval($route['airline_id'] ?? 0),
            'from_airport_id' => intval($route['from_airport_id'] ?? 0),
            'to_airport_id' => intval($route['to_airport_id'] ?? 0),
            'flight_number' => trim($route['flight_number'] ?? ''),
            'departure_date' => $flight_type === 'fixed' ? trim($route['departure_date'] ?? '') : null,
            'departure_day' => $flight_type === 'recurring' ? trim($route['departure_day'] ?? '') : null,
            'departure_time' => trim($route['departure_time'] ?? ''),
            'arrival_date' => $flight_type === 'fixed' ? trim($route['arrival_date'] ?? '') : null,
            'arrival_day' => $flight_type === 'recurring' ? trim($route['arrival_day'] ?? '') : null,
            'arrival_time' => trim($route['arrival_time'] ?? ''),
            'duration' => trim($route['duration'] ?? '')
        ];
    }

    // Price validation
    $economy_adult_price = floatval($_POST['economy_adult_price'] ?? 0);
    if ($economy_adult_price <= 0) {
        $errors[] = 'Economy adult price is required';
    }

    // IF VALIDATION ERRORS, SHOW THEM AND PRESERVE FORM DATA
    if (!empty($errors)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => implode('<br>', $errors)
        ];
        // Preserve form data
        $_SESSION['form_data'] = $_POST;
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/flights/edit/' . $flight_id);
        return;
    }

    // Determine is_direct and layover_count
    $is_direct = count($validated_routes) === 1 ? 1 : 0;
    $layover_count = count($validated_routes) - 1;

    // Use first route for main flight details
    $first_route = $validated_routes[0];
    $last_route = $validated_routes[count($validated_routes) - 1];

    // PREPARE FLIGHT DATA
    $flight_data = [
        'user_id' => $user_id,
        'status' => isset($_POST['status']) ? intval($_POST['status']) : 1,
        'flight_type' => $flight_type,
        // NO trip_type - not saved to database
         'featured' => isset($_POST['featured']) ? 1 : 0,
        // Main flight summary (from first route)
        'flight_number' => $first_route['flight_number'],
        'airline_id' => $first_route['airline_id'],
        'from_airport_id' => $first_route['from_airport_id'],
        'to_airport_id' => $last_route['to_airport_id'],
        
        // Date/Time from first route
        'departure_date' => $flight_type === 'fixed' ? $first_route['departure_date'] : null,
        'departure_day' => $flight_type === 'recurring' ? $first_route['departure_day'] : null,
        'departure_time' => $first_route['departure_time'],
        'arrival_time' => $last_route['arrival_time'],
        'arrival_day' => $flight_type === 'recurring' ? $last_route['arrival_day'] : null,
        'duration' => !empty($first_route['duration']) ? $first_route['duration'] : null,
        
        // Flight properties
        'is_direct' => $is_direct,
        'layover_count' => $layover_count,
        
        // Routes storage
        'routes' => json_encode($validated_routes),
        
        // Pricing
        'economy_adult_price' => floatval($_POST['economy_adult_price'] ?? 0),
        'economy_child_price' => floatval($_POST['economy_child_price'] ?? 0),
        'economy_infant_price' => floatval($_POST['economy_infant_price'] ?? 0),
        
        'premium_economy_adult_price' => floatval($_POST['premium_economy_adult_price'] ?? 0),
        'premium_economy_child_price' => floatval($_POST['premium_economy_child_price'] ?? 0),
        'premium_economy_infant_price' => floatval($_POST['premium_economy_infant_price'] ?? 0),
        
        'business_adult_price' => floatval($_POST['business_adult_price'] ?? 0),
        'business_child_price' => floatval($_POST['business_child_price'] ?? 0),
        'business_infant_price' => floatval($_POST['business_infant_price'] ?? 0),
        
        'first_adult_price' => floatval($_POST['first_adult_price'] ?? 0),
        'first_child_price' => floatval($_POST['first_child_price'] ?? 0),
        'first_infant_price' => floatval($_POST['first_infant_price'] ?? 0),
        
        'currency' => trim($_POST['currency'] ?? 'USD'),
        
        // Baggage
        'checked_baggage' => trim($_POST['checked_baggage'] ?? '') ?: null,
        'cabin_baggage' => trim($_POST['cabin_baggage'] ?? '') ?: null,
        
        // Seats
        'available_seats' => intval($_POST['available_seats'] ?? 0),
        'total_seats' => intval($_POST['total_seats'] ?? 0),
        
        // Flight options
        'refundable' => isset($_POST['refundable']) ? 1 : 0,
        'cancellation_fee' => isset($_POST['refundable']) ? floatval($_POST['cancellation_fee'] ?? 0) : null,
        
        // Amenities
        'has_wifi' => isset($_POST['has_wifi']) ? 1 : 0,
        'has_meal' => isset($_POST['has_meal']) ? 1 : 0,
        'meal_type' => isset($_POST['has_meal']) && !empty($_POST['meal_type']) ? trim($_POST['meal_type']) : null,
        'has_entertainment' => isset($_POST['has_entertainment']) ? 1 : 0,
        'has_power_outlet' => isset($_POST['has_power_outlet']) ? 1 : 0,
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    try {
        // UPDATE FLIGHTS TABLE
        $result = $db->update('flights', $flight_data, ['id' => $flight_id]);
        
        $_SESSION['message'] = [
            'type' => 'success',
            'text' => T::flight_updated_successfully
        ];
        
        // Clear form data on success
        unset($_SESSION['form_data']);
        
    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::database_error . ': ' . $e->getMessage()
        ];
        // Preserve form data on error
        $_SESSION['form_data'] = $_POST;
    }
    
    redirect(root . admin . '/flights/edit/' . $flight_id);
});

// ================================ POST /flights/delete - DELETE FLIGHT
$router->post(admin.'/flights/delete', function () use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    $flight_id = intval($_POST['id'] ?? 0);
    
    if ($flight_id <= 0) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::invalid_flight_id
        ];
        redirect(root . admin . '/flights');
        return;
    }
    
    // CHECK IF FLIGHT EXISTS
    $flight = $db->get('flights', ['id'], ['id' => $flight_id]);
    
    if (!$flight) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::flight_not_found
        ];
        redirect(root . admin . '/flights');
        return;
    }
    
    try {
        // DELETE FLIGHT FROM DATABASE
        $result = $db->delete('flights', ['id' => $flight_id]);
        
        if ($result) {
            $_SESSION['message'] = [
                'type' => 'success',
                'text' => T::flight_deleted_successfully
            ];
        } else {
            $_SESSION['message'] = [
                'type' => 'error',
                'text' => T::failed_to_delete_flight
            ];
        }
        
    } catch (Exception $e) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::database_error . ': ' . $e->getMessage()
        ];
    }
    
    redirect(root . admin . '/flights');
});

// ================================ GET /flights/view/{id} - VIEW FLIGHT DETAILS
$router->get(admin.'/flights/view/(.*)', function ($id) use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();

    // SET MODE
    $mode = 'view';

    $flight_id = intval($id);
    
    // FETCH FLIGHT DETAILS
    $flight = $db->get('flights', '*', ['id' => $flight_id]);
    
    if (empty($flight)) {
        $_SESSION['message'] = [
            'type' => 'error',
            'text' => T::flight_not_found
        ];
        redirect(root . admin . '/flights');
        return;
    }
    
    // Decode routes JSON
    if (!empty($flight['routes'])) {
        $flight['routes'] = json_decode($flight['routes'], true);
    }
    
    // Process outbound routes - Load airline and airport details
    $saved_routes = [];
    if (!empty($flight['routes']) && is_array($flight['routes'])) {
        foreach ($flight['routes'] as $route_data) {
            $route_airline = null;
            $route_from = null;
            $route_to = null;
            
            if (!empty($route_data['airline_id'])) {
                $route_airline = $db->get('flights_airlines', ['id', 'name', 'code', 'iata'], ['id' => $route_data['airline_id']]);
            }
            if (!empty($route_data['from_airport_id'])) {
                $route_from = $db->get('flights_airports', ['id', 'airport', 'city', 'country', 'code'], ['id' => $route_data['from_airport_id']]);
            }
            if (!empty($route_data['to_airport_id'])) {
                $route_to = $db->get('flights_airports', ['id', 'airport', 'city', 'country', 'code'], ['id' => $route_data['to_airport_id']]);
            }
            
            $saved_routes[] = [
                'airline' => $route_airline,
                'from_airport' => $route_from,
                'to_airport' => $route_to,
                'flight_number' => $route_data['flight_number'] ?? '',
                'departure_date' => $route_data['departure_date'] ?? '',
                'departure_day' => $route_data['departure_day'] ?? '',
                'departure_time' => $route_data['departure_time'] ?? '',
                'arrival_date' => $route_data['arrival_date'] ?? '',
                'arrival_day' => $route_data['arrival_day'] ?? '',
                'arrival_time' => $route_data['arrival_time'] ?? '',
                'duration' => $route_data['duration'] ?? ''
            ];
        }
    }
    
    $saved_return_routes = []; // No return routes in view/edit mode
    
    // GET OWNER
    $owner = null;
    if (!empty($flight['user_id'])) {
        $owner = $db->get('users', ['user_id', 'first_name', 'last_name', 'email'], ['user_id' => $flight['user_id']]);
    }
    
    // Legacy variables (not used in view but kept for compatibility)
    $airline = null;
    $from_airport = null;
    $to_airport = null;
    
    // META DATA
    $title = T::view_flight . ': ' . $flight['flight_number'];
    $description = '';
    $header = true;
    $footer = true;
    
    require_once views."includes/header.php";
    require_once "app/views/admin/flights/flight.php";
    require_once views."includes/footer.php";
});

// ================================ POST /flights/search-airlines - AJAX AIRLINE SEARCH
$router->post(admin.'/flights/search-airlines', function () use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();
    
    header('Content-Type: application/json');
    
    $search = trim($_POST['search'] ?? '');
    
    if (empty($search)) {
        echo json_encode(['success' => false, 'airlines' => []]);
        return;
    }
    
    // Search airlines by name or code
    $airlines = $db->select('flights_airlines', 
        ['id', 'name', 'code', 'iata'], 
        [
            'AND' => [
                'status' => '1',
                'OR' => [
                    'name[~]' => $search,
                    'code[~]' => $search,
                    'iata[~]' => $search
                ]
            ],
            'LIMIT' => 10
        ]
    );
    
    echo json_encode(['success' => true, 'airlines' => $airlines]);
});

// ================================ POST /flights/search-airports - AJAX AIRPORT SEARCH
$router->post(admin.'/flights/search-airports', function () use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();
    
    header('Content-Type: application/json');
    
    $search = trim($_POST['search'] ?? '');
    
    if (empty($search)) {
        echo json_encode(['success' => false, 'airports' => []]);
        return;
    }
    
    // Search airports by city, airport name, or IATA code
    $airports = $db->select('flights_airports', 
        ['id', 'airport', 'city', 'country', 'code'], 
        [
            'AND' => [
                'status' => '1',
                'OR' => [
                    'city[~]' => $search,
                    'airport[~]' => $search,
                    'country[~]' => $search,
                    'code[~]' => $search
                ]
            ],
            'LIMIT' => 10
        ]
    );
    
    echo json_encode(['success' => true, 'airports' => $airports]);
});

// ================================ POST /flights/search-users - AJAX USER SEARCH
$router->post(admin.'/flights/search-users', function () use ($SECURE,$db) {
    // ADMIN AUTH CHECK
    ADMIN_AUTH();
    
    header('Content-Type: application/json');
    
    $search = trim($_POST['search'] ?? '');
    
    if (empty($search)) {
        echo json_encode(['success' => false, 'users' => []]);
        return;
    }
    
    // Search users by name or email
    $users = $db->select('users', 
        ['user_id', 'first_name', 'last_name', 'email'], 
        [
            'OR' => [
                'first_name[~]' => $search,
                'last_name[~]' => $search,
                'email[~]' => $search
            ],
            'LIMIT' => 10,
            'ORDER' => ['first_name' => 'ASC']
        ]
    );
    
    echo json_encode(['success' => true, 'users' => $users]);
});

/*===================================================================
FLIGHTS ROUTES END
===================================================================*/
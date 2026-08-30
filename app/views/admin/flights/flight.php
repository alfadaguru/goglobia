<?php
@$SECURE or die('Access Denied!');

// Determine mode: add, edit, or view
$mode = $mode ?? 'add';
$isEdit = ($mode === 'edit');
$isView = ($mode === 'view');
$isAdd = ($mode === 'add');

// Set page title based on mode
if ($isView) {
    $pageTitle = T::view_flight . ': ' . htmlspecialchars($flight['flight_number'] ?? '');
} elseif ($isEdit) {
    $pageTitle = T::edit_flight . ': ' . htmlspecialchars($flight['flight_number'] ?? '');
} else {
    $pageTitle = T::add_flight;
}

// Initialize variables for add mode
if ($isAdd) {
    // Check if we have form data from validation error
    $form_data = $_SESSION['form_data'] ?? null;

    $flight = [
        'id' => 0,
        'flight_type' => $form_data['flight_type'] ?? 'fixed',
        'flight_number' => $form_data['routes'][0]['flight_number'] ?? '',
        'airline_id' => '',
        'from_airport_id' => '',
        'to_airport_id' => '',
        'departure_date' => '',
        'departure_day' => '',
        'departure_time' => '',
        'arrival_time' => '',
        'arrival_day' => '',
        'duration' => '',
        'economy_adult_price' => $form_data['economy_adult_price'] ?? '',
        'economy_child_price' => $form_data['economy_child_price'] ?? '',
        'economy_infant_price' => $form_data['economy_infant_price'] ?? '',
        'premium_economy_adult_price' => $form_data['premium_economy_adult_price'] ?? '',
        'premium_economy_child_price' => $form_data['premium_economy_child_price'] ?? '',
        'premium_economy_infant_price' => $form_data['premium_economy_infant_price'] ?? '',
        'business_adult_price' => $form_data['business_adult_price'] ?? '',
        'business_child_price' => $form_data['business_child_price'] ?? '',
        'business_infant_price' => $form_data['business_infant_price'] ?? '',
        'first_adult_price' => $form_data['first_adult_price'] ?? '',
        'first_child_price' => $form_data['first_child_price'] ?? '',
        'first_infant_price' => $form_data['first_infant_price'] ?? '',
        'currency' => $form_data['currency'] ?? $_SESSION['app_currency'],
        'checked_baggage' => $form_data['checked_baggage'] ?? '',
        'cabin_baggage' => $form_data['cabin_baggage'] ?? '',
        'available_seats' => $form_data['available_seats'] ?? 0,
        'total_seats' => $form_data['total_seats'] ?? 0,
        'refundable' => $form_data['refundable'] ?? 0,
        'cancellation_fee' => $form_data['cancellation_fee'] ?? '',
        'is_direct' => 1,
        'layover_count' => 0,
        'has_wifi' => $form_data['has_wifi'] ?? 0,
        'featured' => $form_data['featured'] ?? 0,
        'has_meal' => $form_data['has_meal'] ?? 0,
        'meal_type' => $form_data['meal_type'] ?? '',
        'has_entertainment' => $form_data['has_entertainment'] ?? 0,
        'has_power_outlet' => $form_data['has_power_outlet'] ?? 0,
        'status' => $form_data['status'] ?? 1,
        'routes' => null
    ];
    $airline = null;
    $from_airport = null;
    $to_airport = null;
}

// Load routes from JSON if editing
$saved_routes = [];
if (($isEdit || $isView) && !empty($flight['routes'])) {
    $decoded_routes = $flight['routes'];
    if (is_array($decoded_routes)) {
        foreach ($decoded_routes as $route_data) {
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
}

// Get default currency from settings or currencies table
$defaultCurrency = $_SESSION['app_currency'] ?? 'USD';
?>

<?php if (!$isView): ?>
    <script>
        function flightFormData() {
            return {
                // Form State
                loading: false,

                //FOR USER SEARCH
                userSearch: '<?php if ($isEdit && !empty($owner)):
                    echo htmlspecialchars(trim($owner['first_name'] ?? '') . ' ' . trim($owner['last_name'] ?? '') . ' - ' . ($owner['email'] ?? '')); endif; ?>',
                searchResults: [],
                showUserDropdown: false,
                selectedUser: <?php if ($isEdit && !empty($owner)):
                    // Add user_id property to match JavaScript expectation
                    $owner_fixed = $owner;
                    $owner_fixed['user_id'] = $owner['user_id'];
                    echo json_encode($owner_fixed);
                else:
                    echo 'null';
                endif; ?>,
                searchingUsers: false,

                // Flight Type (fixed or recurring)
                flightType: '<?= $flight['flight_type'] ?? 'fixed' ?>',

                tripType: '<?= $isAdd ? ($form_data['trip_type'] ?? 'one_way') : 'one_way' ?>',

                // Return Flight Data
                returnRoutes: [
                    <?php if ($isEdit && !empty($saved_return_routes)): ?>
                        <?php foreach ($saved_return_routes as $index => $saved_route): ?>
                            {
                                id: <?= $index ?>,
                                airlineSearch: '<?php if (!empty($saved_route['airline'])):
                                    echo htmlspecialchars($saved_route['airline']['name'] . ' (' . $saved_route['airline']['iata'] . ')'); endif; ?>',
                                airlineResults: [],
                                showAirlineDropdown: false,
                                selectedAirline: <?php if (!empty($saved_route['airline'])):
                                    echo json_encode($saved_route['airline']); else:
                                    echo 'null'; endif; ?>,
                                searchingAirlines: false,

                                fromSearch: '<?php if (!empty($saved_route['from_airport'])):
                                    echo htmlspecialchars($saved_route['from_airport']['city'] . ' (' . $saved_route['from_airport']['code'] . ')'); endif; ?>',
                                fromResults: [],
                                showFromDropdown: false,
                                selectedFrom: <?php if (!empty($saved_route['from_airport'])):
                                    echo json_encode($saved_route['from_airport']); else:
                                    echo 'null'; endif; ?>,
                                searchingFrom: false,

                                toSearch: '<?php if (!empty($saved_route['to_airport'])):
                                    echo htmlspecialchars($saved_route['to_airport']['city'] . ' (' . $saved_route['to_airport']['code'] . ')'); endif; ?>',
                                toResults: [],
                                showToDropdown: false,
                                selectedTo: <?php if (!empty($saved_route['to_airport'])):
                                    echo json_encode($saved_route['to_airport']); else:
                                    echo 'null'; endif; ?>,
                                searchingTo: false,

                                flightNumber: '<?= htmlspecialchars($saved_route['flight_number'] ?? '') ?>',
                                departureDate: '<?= $saved_route['departure_date'] ?? '' ?>',
                                departureDay: '<?= $saved_route['departure_day'] ?? '' ?>',
                                departureTime: '<?= $saved_route['departure_time'] ?? '' ?>',
                                arrivalDate: '<?= $saved_route['arrival_date'] ?? $saved_route['departure_date'] ?? '' ?>',
                                arrivalDay: '<?= $saved_route['arrival_day'] ?? $saved_route['departure_day'] ?? '' ?>',
                                arrivalTime: '<?= $saved_route['arrival_time'] ?? '' ?>',
                                duration: '<?= htmlspecialchars($saved_route['duration'] ?? '') ?>'
                            }<?= $index < count($saved_return_routes) - 1 ? ',' : '' ?>
                        <?php endforeach; ?>
                <?php endif; ?>
                ],

                // Refundable state
                isRefundable: <?= ($flight['refundable'] ?? 0) ? 'true' : 'false' ?>,

                // Direct flight state
                isDirect: <?= ($flight['is_direct'] ?? 1) ? 'true' : 'false' ?>,

                // Meal service state
                hasMeal: <?= ($flight['has_meal'] ?? 0) ? 'true' : 'false' ?>,

                // Routes Management (Multi-leg flights support)
                routes: [
                    <?php if ($isEdit && !empty($saved_routes)): ?>
                        <?php foreach ($saved_routes as $index => $saved_route): ?>
                            {
                                id: <?= $index ?>,
                                airlineSearch: '<?php if (!empty($saved_route['airline'])):
                                    echo htmlspecialchars($saved_route['airline']['name'] . ' (' . $saved_route['airline']['iata'] . ')'); endif; ?>',
                                airlineResults: [],
                                showAirlineDropdown: false,
                                selectedAirline: <?php if (!empty($saved_route['airline'])):
                                    echo json_encode($saved_route['airline']); else:
                                    echo 'null'; endif; ?>,
                                searchingAirlines: false,

                                fromSearch: '<?php if (!empty($saved_route['from_airport'])):
                                    echo htmlspecialchars($saved_route['from_airport']['city'] . ' (' . $saved_route['from_airport']['code'] . ')'); endif; ?>',
                                fromResults: [],
                                showFromDropdown: false,
                                selectedFrom: <?php if (!empty($saved_route['from_airport'])):
                                    echo json_encode($saved_route['from_airport']); else:
                                    echo 'null'; endif; ?>,
                                searchingFrom: false,

                                toSearch: '<?php if (!empty($saved_route['to_airport'])):
                                    echo htmlspecialchars($saved_route['to_airport']['city'] . ' (' . $saved_route['to_airport']['code'] . ')'); endif; ?>',
                                toResults: [],
                                showToDropdown: false,
                                selectedTo: <?php if (!empty($saved_route['to_airport'])):
                                    echo json_encode($saved_route['to_airport']); else:
                                    echo 'null'; endif; ?>,
                                searchingTo: false,

                                flightNumber: '<?= htmlspecialchars($saved_route['flight_number'] ?? '') ?>',
                                departureDate: '<?= $saved_route['departure_date'] ?? '' ?>',
                                departureDay: '<?= $saved_route['departure_day'] ?? '' ?>',
                                departureTime: '<?= $saved_route['departure_time'] ?? '' ?>',
                                arrivalDate: '<?= $saved_route['arrival_date'] ?? $saved_route['departure_date'] ?? '' ?>',
                                arrivalDay: '<?= $saved_route['arrival_day'] ?? $saved_route['departure_day'] ?? '' ?>',
                                arrivalTime: '<?= $saved_route['arrival_time'] ?? '' ?>',
                                duration: '<?= htmlspecialchars($saved_route['duration'] ?? '') ?>'
                            }<?= $index < count($saved_routes) - 1 ? ',' : '' ?>
                        <?php endforeach; ?>
                <?php else: ?>
                    {
                            id: 0,
                            airlineSearch: '<?php if ($isEdit && !empty($airline)):
                                echo htmlspecialchars($airline['name'] . ' (' . $airline['iata'] . ')'); endif; ?>',
                            airlineResults: [],
                            showAirlineDropdown: false,
                            selectedAirline: <?php if ($isEdit && !empty($airline)):
                                echo json_encode($airline); else:
                                echo 'null'; endif; ?>,
                            searchingAirlines: false,

                            fromSearch: '<?php if ($isEdit && !empty($from_airport)):
                                echo htmlspecialchars($from_airport['city'] . ' (' . $from_airport['code'] . ')'); endif; ?>',
                            fromResults: [],
                            showFromDropdown: false,
                            selectedFrom: <?php if ($isEdit && !empty($from_airport)):
                                echo json_encode($from_airport); else:
                                echo 'null'; endif; ?>,
                            searchingFrom: false,

                            toSearch: '<?php if ($isEdit && !empty($to_airport)):
                                echo htmlspecialchars($to_airport['city'] . ' (' . $to_airport['code'] . ')'); endif; ?>',
                            toResults: [],
                            showToDropdown: false,
                            selectedTo: <?php if ($isEdit && !empty($to_airport)):
                                echo json_encode($to_airport); else:
                                echo 'null'; endif; ?>,
                            searchingTo: false,

                            flightNumber: '<?= htmlspecialchars($flight['flight_number'] ?? '') ?>',
                            departureDate: '<?= $flight['departure_date'] ?? '' ?>',
                            departureDay: '<?= $flight['departure_day'] ?? '' ?>',
                            departureTime: '<?= $flight['departure_time'] ?? '' ?>',
                            arrivalDate: '<?= $flight['departure_date'] ?? '' ?>',
                            arrivalDay: '<?= $flight['departure_day'] ?? '' ?>',
                            arrivalTime: '<?= $flight['arrival_time'] ?? '' ?>',
                            duration: '<?= htmlspecialchars($flight['duration'] ?? '') ?>'
                        }
                <?php endif; ?>
                ],

                // Trip type change handler
                tripTypeChanged() {
                    if (this.tripType === 'round_trip' && this.returnRoutes.length === 0) {
                        this.copyToReturnRoutes();
                    }
                },

                // Copy routes to return routes in reverse order
                copyToReturnRoutes() {
                    if (this.routes.length === 0) return;

                    this.returnRoutes = [];

                    // Reverse the routes array
                    const reversedRoutes = [...this.routes].reverse();

                    reversedRoutes.forEach((route, index) => {
                        const reversedRoute = {
                            id: index,
                            airlineSearch: route.airlineSearch,
                            airlineResults: [],
                            showAirlineDropdown: false,
                            selectedAirline: route.selectedAirline,
                            searchingAirlines: false,

                            fromSearch: route.toSearch,
                            fromResults: [],
                            showFromDropdown: false,
                            selectedFrom: route.selectedTo,
                            searchingFrom: false,

                            toSearch: route.fromSearch,
                            toResults: [],
                            showToDropdown: false,
                            selectedTo: route.selectedFrom,
                            searchingTo: false,

                            flightNumber: '',
                            departureDate: route.departureDate,
                            departureDay: route.departureDay,
                            departureTime: '',
                            arrivalDate: route.departureDate,
                            arrivalDay: route.departureDay,
                            arrivalTime: '',
                            duration: ''
                        };

                        this.returnRoutes.push(reversedRoute);
                    });
                },

                // Route Management Methods
                addRoute(routeArray, isReturn = false) {
                    const arrayToUse = isReturn ? this.returnRoutes : this.routes;
                    const lastRoute = arrayToUse[arrayToUse.length - 1];

                    // Validate last route before adding new
                    if (!lastRoute.selectedTo || !lastRoute.arrivalTime) {
                        vt.error('<?= T::complete_current_leg ?>');
                        return;
                    }

                    // Calculate suggested departure time (1 hour after arrival)
                    let suggestedTime = '';
                    if (lastRoute.arrivalTime) {
                        const [hours, minutes] = lastRoute.arrivalTime.split(':').map(Number);
                        const newHours = (hours + 1) % 24;
                        suggestedTime = `${String(newHours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
                    }

                    const newRoute = {
                        id: arrayToUse.length,
                        airlineSearch: lastRoute.selectedAirline ? lastRoute.selectedAirline.name + ' (' + lastRoute.selectedAirline.iata + ')' : '',
                        airlineResults: [],
                        showAirlineDropdown: false,
                        selectedAirline: lastRoute.selectedAirline,
                        searchingAirlines: false,

                        fromSearch: lastRoute.selectedTo ? lastRoute.selectedTo.city + ' (' + lastRoute.selectedTo.code + ')' : '',
                        fromResults: [],
                        showFromDropdown: false,
                        selectedFrom: lastRoute.selectedTo,
                        searchingFrom: false,

                        toSearch: '',
                        toResults: [],
                        showToDropdown: false,
                        selectedTo: null,
                        searchingTo: false,

                        flightNumber: '',
                        departureDate: lastRoute.arrivalDate || lastRoute.departureDate,
                        departureDay: lastRoute.arrivalDay || lastRoute.departureDay,
                        departureTime: suggestedTime,
                        arrivalDate: lastRoute.arrivalDate || lastRoute.departureDate,
                        arrivalDay: lastRoute.arrivalDay || lastRoute.departureDay,
                        arrivalTime: '',
                        duration: ''
                    };

                    arrayToUse.push(newRoute);

                    if (!isReturn && arrayToUse.length > 1) {
                        this.isDirect = false;
                    }
                },

                validateRoute(routeIndex, isReturn = false) {
                    const routeArray = isReturn ? this.returnRoutes : this.routes;
                    const route = routeArray[routeIndex];
                    const errors = [];

                    if (route.selectedFrom && route.selectedTo && route.selectedFrom.id === route.selectedTo.id) {
                        errors.push('<?= T::origin_destination_same ?>');
                    }

                    if (routeIndex > 0) {
                        const prevRoute = routeArray[routeIndex - 1];
                        if (prevRoute.arrivalTime && route.departureTime) {
                            const layover = this.calculateLayover(prevRoute, route, this.flightType);
                            if (layover === '<?= T::invalid_layover_time ?>') {
                                errors.push('<?= T::departure_after_arrival ?>');
                            } else if (layover) {
                                const matches = layover.match(/\d+/g);
                                if (matches) {
                                    const totalMinutes = (parseInt(matches[0]) || 0) * 60 + (parseInt(matches[1]) || 0);
                                    if (totalMinutes < 30) {
                                        errors.push('<?= T::minimum_layover_required ?>');
                                    }
                                }
                            }
                        }
                    }

                    if (route.duration) {
                        const matches = route.duration.match(/\d+/g);
                        if (matches) {
                            const hours = parseInt(matches[0]) || 0;
                            if (hours > 20) {
                                errors.push('<?= T::duration_exceeds_20_hours ?>');
                            }
                        }
                    }

                    return errors;
                },

                removeRoute(index, isReturn = false) {
                    const routeArray = isReturn ? this.returnRoutes : this.routes;
                    if (routeArray.length > 1) {
                        routeArray.splice(index, 1);
                        if (!isReturn && routeArray.length === 1) {
                            this.isDirect = true;
                        }
                    }
                },

                // Airline Search Methods
                async searchAirlines(routeIndex, isReturn = false) {
                    const routeArray = isReturn ? this.returnRoutes : this.routes;
                    const route = routeArray[routeIndex];
                    if (route.airlineSearch.length < 2) {
                        route.airlineResults = [];
                        route.showAirlineDropdown = false;
                        return;
                    }

                    route.searchingAirlines = true;
                    try {
                        const formData = new FormData();
                        formData.append('search', route.airlineSearch);

                        const response = await fetch('<?= root ?>admin/flights/search-airlines', {
                            method: 'POST',
                            body: formData
                        });

                        const data = await response.json();
                        if (data.success) {
                            route.airlineResults = data.airlines;
                            route.showAirlineDropdown = route.airlineResults.length > 0;
                        }
                    } catch (error) {
                        console.error('Error searching airlines:', error);
                    } finally {
                        route.searchingAirlines = false;
                    }
                },

                selectAirline(routeIndex, airline, isReturn = false) {
                    const routeArray = isReturn ? this.returnRoutes : this.routes;
                    const route = routeArray[routeIndex];
                    route.selectedAirline = airline;
                    route.airlineSearch = airline.name + ' (' + airline.iata + ')';
                    route.showAirlineDropdown = false;
                    route.airlineResults = [];
                },

                clearAirlineSelection(routeIndex, isReturn = false) {
                    const routeArray = isReturn ? this.returnRoutes : this.routes;
                    const route = routeArray[routeIndex];
                    route.selectedAirline = null;
                    route.airlineSearch = '';
                    route.airlineResults = [];
                    route.showAirlineDropdown = false;
                },

                // From Airport Search Methods
                async searchFrom(routeIndex, isReturn = false) {
                    const routeArray = isReturn ? this.returnRoutes : this.routes;
                    const route = routeArray[routeIndex];
                    if (route.fromSearch.length < 2) {
                        route.fromResults = [];
                        route.showFromDropdown = false;
                        return;
                    }

                    route.searchingFrom = true;
                    try {
                        const formData = new FormData();
                        formData.append('search', route.fromSearch);

                        const response = await fetch('<?= root ?>admin/flights/search-airports', {
                            method: 'POST',
                            body: formData
                        });

                        const data = await response.json();
                        if (data.success) {
                            route.fromResults = data.airports;
                            route.showFromDropdown = route.fromResults.length > 0;
                        }
                    } catch (error) {
                        console.error('Error searching airports:', error);
                    } finally {
                        route.searchingFrom = false;
                    }
                },

                selectFrom(routeIndex, airport, isReturn = false) {
                    const routeArray = isReturn ? this.returnRoutes : this.routes;
                    const route = routeArray[routeIndex];
                    route.selectedFrom = airport;
                    route.fromSearch = airport.city + ' (' + airport.code + ')';
                    route.showFromDropdown = false;
                    route.fromResults = [];
                },

                clearFromSelection(routeIndex, isReturn = false) {
                    const routeArray = isReturn ? this.returnRoutes : this.routes;
                    const route = routeArray[routeIndex];
                    route.selectedFrom = null;
                    route.fromSearch = '';
                    route.fromResults = [];
                    route.showFromDropdown = false;
                },

                // To Airport Search Methods
                async searchTo(routeIndex, isReturn = false) {
                    const routeArray = isReturn ? this.returnRoutes : this.routes;
                    const route = routeArray[routeIndex];
                    if (route.toSearch.length < 2) {
                        route.toResults = [];
                        route.showToDropdown = false;
                        return;
                    }

                    route.searchingTo = true;
                    try {
                        const formData = new FormData();
                        formData.append('search', route.toSearch);

                        const response = await fetch('<?= root ?>admin/flights/search-airports', {
                            method: 'POST',
                            body: formData
                        });

                        const data = await response.json();
                        if (data.success) {
                            route.toResults = data.airports;
                            route.showToDropdown = route.toResults.length > 0;
                        }
                    } catch (error) {
                        console.error('Error searching airports:', error);
                    } finally {
                        route.searchingTo = false;
                    }
                },

                selectTo(routeIndex, airport, isReturn = false) {
                    const routeArray = isReturn ? this.returnRoutes : this.routes;
                    const route = routeArray[routeIndex];
                    route.selectedTo = airport;
                    route.toSearch = airport.city + ' (' + airport.code + ')';
                    route.showToDropdown = false;
                    route.toResults = [];
                },

                clearToSelection(routeIndex, isReturn = false) {
                    const routeArray = isReturn ? this.returnRoutes : this.routes;
                    const route = routeArray[routeIndex];
                    route.selectedTo = null;
                    route.toSearch = '';
                    route.toResults = [];
                    route.showToDropdown = false;
                },

                async searchUsers() {
                    if (this.userSearch.length < 2) {
                        this.searchResults = [];
                        this.showUserDropdown = false;
                        return;
                    }

                    this.searchingUsers = true;
                    try {
                        const formData = new FormData();
                        formData.append('search', this.userSearch);

                        const response = await fetch('<?= root ?>admin/flights/search-users', {
                            method: 'POST',
                            body: formData
                        });

                        const data = await response.json();
                        if (data.success) {
                            this.searchResults = data.users;
                            this.showUserDropdown = this.searchResults.length > 0;
                        }
                    } catch (error) {
                        console.error('Error searching users:', error);
                    } finally {
                        this.searchingUsers = false;
                    }
                },

                selectUser(user) {
                    this.selectedUser = user;
                    this.userSearch = user.first_name + ' ' + user.last_name + ' - ' + user.email;
                    this.showUserDropdown = false;
                    this.searchResults = [];
                    document.getElementById('user_id_input').value = user.user_id;
                },

                clearUserSelection() {
                    this.selectedUser = null;
                    this.userSearch = '';
                    this.searchResults = [];
                    this.showUserDropdown = false;
                    document.getElementById('user_id_input').value = '';
                },

                // ========== VALIDATION METHODS ==========
                validateForm() {
                    const errors = [];

                    // Clear previous error styles
                    document.querySelectorAll('.input-error, .select-error').forEach(el => {
                        el.classList.remove('input-error', 'select-error');
                    });
                    document.querySelectorAll('.error-message').forEach(el => el.remove());

                    // Validate main routes
                    this.routes.forEach((route, index) => {
                        const routeNum = index + 1;

                        // Validate From Airport
                        if (!route.selectedFrom) {
                            const routeElement = document.querySelector(`[data-route-index="${index}"] .from-input-container`);
                            if (routeElement) {
                                errors.push({
                                    field: 'from_airport_' + index,
                                    label: `<?= T::from_airport ?> (Route ${routeNum})`,
                                    element: routeElement
                                });
                            }
                        }

                        // Validate To Airport
                        if (!route.selectedTo) {
                            const routeElement = document.querySelector(`[data-route-index="${index}"] .to-input-container`);
                            if (routeElement) {
                                errors.push({
                                    field: 'to_airport_' + index,
                                    label: `<?= T::to_airport ?> (Route ${routeNum})`,
                                    element: routeElement
                                });
                            }
                        }

                        // Validate Airline
                        if (!route.selectedAirline) {
                            const routeElement = document.querySelector(`[data-route-index="${index}"] .airline-input-container`);
                            if (routeElement) {
                                errors.push({
                                    field: 'airline_' + index,
                                    label: `<?= T::airline ?> (Route ${routeNum})`,
                                    element: routeElement
                                });
                            }
                        }

                        // Validate Flight Number
                        if (!route.flightNumber.trim()) {
                            const input = document.querySelector(`input[name="routes[${index}][flight_number]"]`);
                            if (input) {
                                errors.push({
                                    field: 'flight_number_' + index,
                                    label: `<?= T::flight_number ?> (Route ${routeNum})`,
                                    element: input.closest('.form-control') || input.parentElement
                                });
                            }
                        }

                        // Validate Departure Time
                        if (!route.departureTime) {
                            const input = document.querySelector(`input[name="routes[${index}][departure_time]"]`);
                            if (input) {
                                errors.push({
                                    field: 'departure_time_' + index,
                                    label: `<?= T::departure_time ?> (Route ${routeNum})`,
                                    element: input.closest('.form-control') || input.parentElement
                                });
                            }
                        }

                        // Validate Arrival Time
                        if (!route.arrivalTime) {
                            const input = document.querySelector(`input[name="routes[${index}][arrival_time]"]`);
                            if (input) {
                                errors.push({
                                    field: 'arrival_time_' + index,
                                    label: `<?= T::arrival_time ?> (Route ${routeNum})`,
                                    element: input.closest('.form-control') || input.parentElement
                                });
                            }
                        }

                        // Validate Date/Day based on flight type
                        if (this.flightType === 'fixed') {
                            if (!route.departureDate) {
                                const input = document.querySelector(`input[name="routes[${index}][departure_date]"]`);
                                if (input) {
                                    errors.push({
                                        field: 'departure_date_' + index,
                                        label: `<?= T::departure_date ?> (Route ${routeNum})`,
                                        element: input.closest('.form-control') || input.parentElement
                                    });
                                }
                            }

                            if (!route.arrivalDate) {
                                const input = document.querySelector(`input[name="routes[${index}][arrival_date]"]`);
                                if (input) {
                                    errors.push({
                                        field: 'arrival_date_' + index,
                                        label: `<?= T::departure_date ?> (Route ${routeNum})`,
                                        element: input.closest('.form-control') || input.parentElement
                                    });
                                }
                            }
                        } else {
                            if (!route.departureDay) {
                                const select = document.querySelector(`select[name="routes[${index}][departure_day]"]`);
                                if (select) {
                                    errors.push({
                                        field: 'departure_day_' + index,
                                        label: `<?= T::departure_day ?> (Route ${routeNum})`,
                                        element: select.closest('.form-control') || select.parentElement
                                    });
                                }
                            }

                            if (!route.arrivalDay) {
                                const select = document.querySelector(`select[name="routes[${index}][arrival_day]"]`);
                                if (select) {
                                    errors.push({
                                        field: 'arrival_day_' + index,
                                        label: `<?= T::departure_day ?> (Route ${routeNum})`,
                                        element: select.closest('.form-control') || select.parentElement
                                    });
                                }
                            }
                        }

                        // Validate same airport
                        if (route.selectedFrom && route.selectedTo && route.selectedFrom.id === route.selectedTo.id) {
                            const fromElement = document.querySelector(`[data-route-index="${index}"] .from-input-container`);
                            if (fromElement) {
                                errors.push({
                                    field: 'same_airport_' + index,
                                    label: `Route ${routeNum}: <?= T::origin_destination_same ?>`,
                                    element: fromElement,
                                    customMessage: `Route ${routeNum}: <?= T::origin_destination_same ?>`
                                });
                            }
                        }
                    });

                    // Validate return routes if trip type is round trip
                    if (this.tripType === 'round_trip') {
                        this.returnRoutes.forEach((route, index) => {
                            const routeNum = index + 1;

                            // Validate From Airport
                            if (!route.selectedFrom) {
                                const routeElement = document.querySelector(`[data-return-route-index="${index}"] .from-input-container`);
                                if (routeElement) {
                                    errors.push({
                                        field: 'return_from_airport_' + index,
                                        label: `<?= T::return_from_airport ?> (Route ${routeNum})`,
                                        element: routeElement
                                    });
                                }
                            }

                            // Validate To Airport
                            if (!route.selectedTo) {
                                const routeElement = document.querySelector(`[data-return-route-index="${index}"] .to-input-container`);
                                if (routeElement) {
                                    errors.push({
                                        field: 'return_to_airport_' + index,
                                        label: `<?= T::return_to_airport ?> (Route ${routeNum})`,
                                        element: routeElement
                                    });
                                }
                            }

                            // Validate Airline
                            if (!route.selectedAirline) {
                                const routeElement = document.querySelector(`[data-return-route-index="${index}"] .airline-input-container`);
                                if (routeElement) {
                                    errors.push({
                                        field: 'return_airline_' + index,
                                        label: `<?= T::return_airline ?> (Route ${routeNum})`,
                                        element: routeElement
                                    });
                                }
                            }

                            // Validate Flight Number
                            if (!route.flightNumber.trim()) {
                                const input = document.querySelector(`input[name="return_routes[${index}][flight_number]"]`);
                                if (input) {
                                    errors.push({
                                        field: 'return_flight_number_' + index,
                                        label: `<?= T::return_flight_number ?> (Route ${routeNum})`,
                                        element: input.closest('.form-control') || input.parentElement
                                    });
                                }
                            }

                            // Validate Departure Time
                            if (!route.departureTime) {
                                const input = document.querySelector(`input[name="return_routes[${index}][departure_time]"]`);
                                if (input) {
                                    errors.push({
                                        field: 'return_departure_time_' + index,
                                        label: `<?= T::return_departure_time ?> (Route ${routeNum})`,
                                        element: input.closest('.form-control') || input.parentElement
                                    });
                                }
                            }

                            // Validate Arrival Time
                            if (!route.arrivalTime) {
                                const input = document.querySelector(`input[name="return_routes[${index}][arrival_time]"]`);
                                if (input) {
                                    errors.push({
                                        field: 'return_arrival_time_' + index,
                                        label: `<?= T::return_arrival_time ?> (Route ${routeNum})`,
                                        element: input.closest('.form-control') || input.parentElement
                                    });
                                }
                            }

                            // Validate Date/Day based on flight type
                            if (this.flightType === 'fixed') {
                                if (!route.departureDate) {
                                    const input = document.querySelector(`input[name="return_routes[${index}][departure_date]"]`);
                                    if (input) {
                                        errors.push({
                                            field: 'return_departure_date_' + index,
                                            label: `<?= T::return_departure_date ?> (Route ${routeNum})`,
                                            element: input.closest('.form-control') || input.parentElement
                                        });
                                    }
                                }

                                if (!route.arrivalDate) {
                                    const input = document.querySelector(`input[name="return_routes[${index}][arrival_date]"]`);
                                    if (input) {
                                        errors.push({
                                            field: 'return_arrival_date_' + index,
                                            label: `<?= T::return_arrival_date ?> (Route ${routeNum})`,
                                            element: input.closest('.form-control') || input.parentElement
                                        });
                                    }
                                }
                            } else {
                                if (!route.departureDay) {
                                    const select = document.querySelector(`select[name="return_routes[${index}][departure_day]"]`);
                                    if (select) {
                                        errors.push({
                                            field: 'return_departure_day_' + index,
                                            label: `<?= T::return_departure_day ?> (Route ${routeNum})`,
                                            element: select.closest('.form-control') || select.parentElement
                                        });
                                    }
                                }

                                if (!route.arrivalDay) {
                                    const select = document.querySelector(`select[name="return_routes[${index}][arrival_day]"]`);
                                    if (select) {
                                        errors.push({
                                            field: 'return_arrival_day_' + index,
                                            label: `<?= T::return_arrival_day ?> (Route ${routeNum})`,
                                            element: select.closest('.form-control') || select.parentElement
                                        });
                                    }
                                }
                            }
                        });
                    }

                    // Validate Economy Adult Price
                    const economyAdultPrice = document.querySelector('input[name="economy_adult_price"]');
                    if (!economyAdultPrice || parseFloat(economyAdultPrice.value) <= 0) {
                        if (economyAdultPrice) {
                            errors.push({
                                field: 'economy_adult_price',
                                label: '<?= T::economy_adult_price ?>',
                                element: economyAdultPrice.closest('.form-control') || economyAdultPrice.parentElement
                            });
                        }
                    }


                    const totalSeatsInput = document.querySelector('input[name="total_seats"]');
                    const availableSeatsInput = document.querySelector('input[name="available_seats"]');

                    if (totalSeatsInput && availableSeatsInput) {
                        const totalSeats = parseInt(totalSeatsInput.value) || 0;
                        const availableSeats = parseInt(availableSeatsInput.value) || 0;

                        if (availableSeats > totalSeats && totalSeats > 0) {
                            errors.push({
                                field: 'available_seats',
                                label: '<?= T::available_seats ?>',
                                element: availableSeatsInput.closest('.form-control') || availableSeatsInput.parentElement,
                                customMessage: '<?= T::available_seats ?> cannot exceed <?= T::total_seats ?>'
                            });
                        }
                    }

                    return errors;
                },

                showValidationErrors(errors) {
                    if (errors.length === 0) return;

                    // Scroll to first error
                    setTimeout(() => {
                        if (errors[0].element) {
                            errors[0].element.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }, 300);

                    errors.forEach(error => {
                        if (error.element) {
                            const inputElement = error.element.querySelector('input, select, textarea') || error.element;
                            if (inputElement) {
                                inputElement.classList.add('input-error');
                                inputElement.style.borderColor = '#EF4444';
                                inputElement.style.backgroundColor = '#FEF2F2';
                            }

                            const errorMsg = document.createElement('p');
                            errorMsg.className = 'error-message text-red-600 text-xs mt-1 flex items-center gap-1';
                            errorMsg.innerHTML = `
                        <span class="material-symbols-outlined text-sm">error</span>
                        <span>${error.customMessage || error.label + ' is required'}</span>
                    `;

                            if (inputElement && inputElement.parentElement) {
                                inputElement.parentElement.appendChild(errorMsg);
                            }
                        }
                    });

                    const errorList = errors.map(e => e.label).join(', ');
                    vt.error(`<?= T::please_fill_all_required_fields ?>: ${errorList}`);
                },

                // Calculate flight duration between two times
                calculateDuration(departureTime, arrivalTime, departureDate, arrivalDate, flightType) {
                    if (!departureTime || !arrivalTime) return '';

                    if (flightType === 'fixed' && departureDate && arrivalDate) {
                        const depDateTime = new Date(departureDate + 'T' + departureTime);
                        const arrDateTime = new Date(arrivalDate + 'T' + arrivalTime);

                        let diffMs = arrDateTime - depDateTime;
                        if (diffMs < 0) diffMs += 86400000;

                        return this.formatDuration(diffMs);
                    }

                    const [depHours, depMinutes] = departureTime.split(':').map(Number);
                    const [arrHours, arrMinutes] = arrivalTime.split(':').map(Number);

                    let totalMinutes = (arrHours * 60 + arrMinutes) - (depHours * 60 + depMinutes);
                    if (totalMinutes < 0) totalMinutes += 1440;

                    return this.formatDuration(totalMinutes * 60000);
                },

                formatDuration(ms) {
                    const totalMinutes = Math.floor(ms / 60000);
                    const hours = Math.floor(totalMinutes / 60);
                    const minutes = totalMinutes % 60;

                    if (hours === 0) return `${minutes}m`;
                    if (minutes === 0) return `${hours}h`;
                    return `${hours}h ${minutes}m`;
                },

                calculateLayover(route1, route2, flightType) {
                    // Add comprehensive null checks
                    if (!route1 || !route2) return '';
                    if (!route1.arrivalTime || !route2.departureTime) return '';

                    try {
                        if (flightType === 'fixed' && route1.arrivalDate && route2.departureDate) {
                            const arr1 = new Date(route1.arrivalDate + 'T' + route1.arrivalTime);
                            const dep2 = new Date(route2.departureDate + 'T' + route2.departureTime);

                            // Handle invalid dates
                            if (isNaN(arr1.getTime()) || isNaN(dep2.getTime())) {
                                return '<?= T::invalid_date_time ?>';
                            }

                            const diffMs = dep2 - arr1;
                            if (diffMs < 0) return '<?= T::invalid_layover_time ?>';

                            return this.formatDuration(diffMs);
                        }

                        // For recurring flights, just show that layover exists
                        if (flightType === 'recurring') {
                            return '<?= T::layover_available ?>';
                        }

                        return '';
                    } catch (error) {
                        console.error('Error calculating layover:', error);
                        return '<?= T::calculation_error ?>';
                    }
                },

                calculateTotalJourneyTime(routesArray) {
                    let totalMs = 0;

                    for (let i = 0; i < routesArray.length; i++) {
                        const route = routesArray[i];

                        if (route.departureTime && route.arrivalTime) {
                            const flightDuration = this.calculateDuration(
                                route.departureTime,
                                route.arrivalTime,
                                route.departureDate,
                                route.arrivalDate,
                                this.flightType
                            );

                            if (flightDuration) {
                                const [hours, minutes] = flightDuration.match(/\d+/g).map(Number);
                                totalMs += (hours || 0) * 3600000 + (minutes || 0) * 60000;
                            }
                        }

                        if (i < routesArray.length - 1) {
                            const nextRoute = routesArray[i + 1];
                            const layover = this.calculateLayover(route, nextRoute, this.flightType);

                            if (layover && layover !== '<?= T::invalid_layover_time ?>') {
                                const matches = layover.match(/\d+/g);
                                if (matches) {
                                    const [hours, minutes] = matches.map(Number);
                                    totalMs += (hours || 0) * 3600000 + (minutes || 0) * 60000;
                                }
                            }
                        }
                    }

                    return this.formatDuration(totalMs);
                },

                updateRouteDuration(routeIndex, isReturn = false) {
                    const routeArray = isReturn ? this.returnRoutes : this.routes;
                    const route = routeArray[routeIndex];
                    route.duration = this.calculateDuration(
                        route.departureTime,
                        route.arrivalTime,
                        route.departureDate,
                        route.arrivalDate,
                        this.flightType
                    );
                },

                copyRoute(routeIndex, isReturn = false) {
                    const routeArray = isReturn ? this.returnRoutes : this.routes;
                    const lastRoute = routeArray[routeArray.length - 1];

                    // Validate last route before adding new
                    if (!lastRoute.selectedTo || !lastRoute.arrivalTime) {
                        vt.error('<?= T::complete_current_leg ?>');
                        return;
                    }
                    const route = routeArray[routeIndex];
                    const newRoute = JSON.parse(JSON.stringify(route));
                    newRoute.id = routeArray.length;
                    newRoute.flightNumber = '';
                    routeArray.push(newRoute);
                },

                swapAirports(routeIndex, isReturn = false) {
                    const routeArray = isReturn ? this.returnRoutes : this.routes;
                    const lastRoute = routeArray[routeArray.length - 1];

                    // Validate last route before adding new
                    if (!lastRoute.selectedTo || !lastRoute.arrivalTime) {
                        vt.error('<?= T::complete_current_leg ?>');
                        return;
                    }
                    const route = routeArray[routeIndex];
                    [route.selectedFrom, route.selectedTo] = [route.selectedTo, route.selectedFrom];
                    [route.fromSearch, route.toSearch] = [route.toSearch, route.fromSearch];
                },

                validateDurations() {
                    const warnings = [];

                    this.routes.forEach((route, index) => {
                        if (route.duration) {
                            const matches = route.duration.match(/\d+/g);
                            if (matches) {
                                const hours = parseInt(matches[0]) || 0;
                                if (hours > 20) {
                                    warnings.push(`Route ${index + 1}: <?= T::duration_exceeds_20_hours ?>`);
                                }
                            }
                        }
                    });

                    return warnings;
                },

                // Form Submit
                submitForm(event) {
                    event.preventDefault();

                    // Ensure user_id is set
                    const userIdInput = document.getElementById('user_id_input');
                    const currentUserId = document.querySelector('input[name="current_user_id"]')?.value;

                    if (!userIdInput.value && currentUserId) {
                        userIdInput.value = currentUserId;
                    }

                    // Validate form
                    const errors = this.validateForm();
                    if (errors.length > 0) {
                        this.showValidationErrors(errors);
                        return false;
                    }

                    // Validate durations
                    const durationWarnings = this.validateDurations();
                    if (durationWarnings.length > 0) {
                        vt.warning(durationWarnings.join('<br>'));
                        // You can decide whether to stop submission or just warn
                        // return false; // Uncomment to stop submission
                    }

                    this.loading = true;
                    event.target.submit();
                },

                // Initialization
                init() {
                    <?php if ($isEdit && !empty($owner)): ?>
                        if (this.selectedUser) {
                            document.getElementById('user_id_input').value = this.selectedUser.user_id;
                        }
                    <?php endif; ?>

                    <?php if ($isEdit && !empty($flight['user_id'])): ?>
                        if (!document.getElementById('user_id_input').value && <?= json_encode($flight['user_id']) ?>) {
                            document.getElementById('user_id_input').value = <?= json_encode($flight['user_id']) ?>;
                        }
                    <?php endif; ?>
                }
            };
        }
    </script>
<?php endif; ?>

<div class="container my-4">
    <?php if (isset($_SESSION['message'])): ?>
        <div class="<?= $_SESSION['message']['type'] === 'success' ? 'alert-success' : 'alert-error' ?> mb-4">
            <span
                class="material-symbols-outlined"><?= $_SESSION['message']['type'] === 'success' ? 'check_circle' : 'error' ?></span>
            <div><?= $_SESSION['message']['text'] ?></div>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
        <div class="flex items-center gap-5">
            <a href="<?= root ?>admin/flights"
                class="btn secondary inline-flex items-center justify-center w-12 h-12 rounded-lg">
                <span class="material-symbols-outlined text-xl">arrow_back</span>
            </a>
            <div>
                <h2 class="text-lg font-semibold text-slate-800"><?= $pageTitle ?></h2>
            </div>
        </div>

        <?php if ($isView): ?>
            <a href="<?= root ?>admin/flights/edit/<?= $flight['id'] ?>" class="btn flex items-center gap-2">
                <span class="material-symbols-outlined">edit</span>
                <span><?= T::edit_flight ?></span>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($isView): ?>
        <!-- VIEW MODE -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
            <div class="p-6">
                <!-- Status & Flight Type Section -->
                <div class="bg-gray-50 rounded-lg p-4 mb-6">
                    <h3
                        class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                        <span class="material-symbols-outlined text-blue-600 text-lg">info</span>
                        <?= T::flight_information ?>
                    </h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <?php if (!empty($owner)): ?>
                            <div>
                                <label class="text-xs text-gray-500 block mb-1"><?= T::owner ?></label>
                                <div class="text-sm font-medium text-gray-900">
                                    <?= htmlspecialchars($owner['first_name'] . ' ' . $owner['last_name']) ?>
                                </div>
                                <div class="text-xs text-gray-500"><?= htmlspecialchars($owner['email']) ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if ($flight['featured']): ?>
                            <div class="flex items-center gap-2 text-sm text-gray-700">
                                <span class="material-symbols-outlined text-green-600 text-base">check_circle</span>
                                <span><?= T::featured ?></span>
                            </div>
                        <?php endif; ?>
                        <div>
                            <label class="text-xs text-gray-500 block mb-1"><?= T::status ?></label>
                            <span
                                class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?= $flight['status'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                                <?= $flight['status'] ? T::active : T::inactive ?>
                            </span>
                        </div>

                        <div>
                            <label class="text-xs text-gray-500 block mb-1"><?= T::flight_type ?></label>
                            <div class="text-sm font-medium text-gray-900">
                                <?= $flight['flight_type'] === 'fixed' ? T::fixed_date : T::recurring_flight ?>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Outbound Flight Routes -->
                <div class="bg-gray-50 rounded-lg p-4 mb-6">
                    <div class="flex items-center justify-between mb-4 pb-2 border-b border-gray-200">
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
                            <span class="material-symbols-outlined text-blue-600 text-lg">flight_takeoff</span>
                            <?= T::flight_information ?>
                        </h3>
                        <span
                            class="text-xs font-medium px-2 py-1 rounded-full <?= $flight['is_direct'] ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700' ?>">
                            <?= $flight['is_direct'] ? T::direct_flight : ($flight['layover_count'] + 1) . ' ' . T::stops ?>
                        </span>
                    </div>

                    <?php if (!empty($saved_routes)): ?>
                        <?php foreach ($saved_routes as $index => $route): ?>
                            <div class="mb-4 <?= $index < count($saved_routes) - 1 ? 'pb-4 border-b border-gray-200' : '' ?>">
                                <div class="flex items-center gap-2 mb-3">
                                    <span
                                        class="flex items-center justify-center w-6 h-6 rounded-full bg-blue-600 text-white text-xs font-bold"><?= $index + 1 ?></span>
                                    <span class="text-sm font-semibold text-gray-700">
                                        <?= $index === 0 ? T::flight_leg . ' ' . ($index + 1) : T::connecting_flight . ' ' . ($index + 1) ?>
                                    </span>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <!-- Departure -->
                                    <div class="bg-white rounded-lg border border-gray-200 p-3">
                                        <div class="flex items-center gap-2 mb-2 pb-2 border-b border-gray-100">
                                            <span class="material-symbols-outlined text-blue-600 text-sm">flight_takeoff</span>
                                            <span class="text-xs font-semibold text-gray-900"><?= T::departure ?></span>
                                        </div>
                                        <div class="space-y-2">
                                            <?php if (!empty($route['from_airport'])): ?>
                                                <div>
                                                    <div class="text-xs text-gray-500"><?= T::from_airport ?></div>
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?= htmlspecialchars($route['from_airport']['code']) ?> -
                                                        <?= htmlspecialchars($route['from_airport']['city']) ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500">
                                                        <?= htmlspecialchars($route['from_airport']['country']) ?></div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($route['airline'])): ?>
                                                <div>
                                                    <div class="text-xs text-gray-500"><?= T::airline ?></div>
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?= htmlspecialchars($route['airline']['name']) ?>
                                                        (<?= htmlspecialchars($route['airline']['iata']) ?>)
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <div>
                                                <div class="text-xs text-gray-500"><?= T::flight_number ?></div>
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?= htmlspecialchars($route['flight_number']) ?></div>
                                            </div>

                                            <div class="flex gap-3">
                                                <div>
                                                    <div class="text-xs text-gray-500">
                                                        <?= $flight['flight_type'] === 'fixed' ? T::date : T::day ?></div>
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?= $flight['flight_type'] === 'fixed' ? date('M d, Y', strtotime($route['departure_date'])) : ucfirst($route['departure_day']) ?>
                                                    </div>
                                                </div>
                                                <div>
                                                    <div class="text-xs text-gray-500"><?= T::time ?></div>
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?= htmlspecialchars($route['departure_time']) ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Arrival -->
                                    <div class="bg-white rounded-lg border border-gray-200 p-3">
                                        <div class="flex items-center gap-2 mb-2 pb-2 border-b border-gray-100">
                                            <span class="material-symbols-outlined text-blue-600 text-sm">flight_land</span>
                                            <span class="text-xs font-semibold text-gray-900"><?= T::arrival ?></span>
                                        </div>
                                        <div class="space-y-2">
                                            <?php if (!empty($route['to_airport'])): ?>
                                                <div>
                                                    <div class="text-xs text-gray-500"><?= T::to_airport ?></div>
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?= htmlspecialchars($route['to_airport']['code']) ?> -
                                                        <?= htmlspecialchars($route['to_airport']['city']) ?>
                                                    </div>
                                                    <div class="text-xs text-gray-500">
                                                        <?= htmlspecialchars($route['to_airport']['country']) ?></div>
                                                </div>
                                            <?php endif; ?>

                                            <div>
                                                <div class="text-xs text-gray-500"><?= T::flight_duration ?></div>
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?= htmlspecialchars($route['duration']) ?></div>
                                            </div>

                                            <div class="flex gap-3">
                                                <div>
                                                    <div class="text-xs text-gray-500">
                                                        <?= $flight['flight_type'] === 'fixed' ? T::date : T::day ?></div>
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?= $flight['flight_type'] === 'fixed' ? date('M d, Y', strtotime($route['arrival_date'])) : ucfirst($route['arrival_day']) ?>
                                                    </div>
                                                </div>
                                                <div>
                                                    <div class="text-xs text-gray-500"><?= T::time ?></div>
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?= htmlspecialchars($route['arrival_time']) ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Pricing Section -->
                <div class="bg-gray-50 rounded-lg p-4 mb-6">
                    <h3
                        class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                        <span class="material-symbols-outlined text-blue-600 text-lg">payments</span>
                        <?= T::pricing ?>
                    </h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Economy Class -->
                        <?php if ($flight['economy_adult_price'] > 0): ?>
                            <div class="bg-white rounded-lg border border-gray-200 p-3">
                                <h4 class="text-xs font-semibold text-gray-900 mb-2"><?= T::economy_class ?></h4>
                                <div class="space-y-1">
                                    <div class="flex justify-between text-xs">
                                        <span class="text-gray-500"><?= T::adult_price ?></span>
                                        <span class="font-medium text-gray-900"><?= $flight['currency'] ?>
                                            <?= number_format($flight['economy_adult_price'], 2) ?></span>
                                    </div>
                                    <?php if ($flight['economy_child_price'] > 0): ?>
                                        <div class="flex justify-between text-xs">
                                            <span class="text-gray-500"><?= T::child_price ?></span>
                                            <span class="font-medium text-gray-900"><?= $flight['currency'] ?>
                                                <?= number_format($flight['economy_child_price'], 2) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($flight['economy_infant_price'] > 0): ?>
                                        <div class="flex justify-between text-xs">
                                            <span class="text-gray-500"><?= T::infant_price ?></span>
                                            <span class="font-medium text-gray-900"><?= $flight['currency'] ?>
                                                <?= number_format($flight['economy_infant_price'], 2) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Premium Economy -->
                        <?php if ($flight['premium_economy_adult_price'] > 0): ?>
                            <div class="bg-white rounded-lg border border-gray-200 p-3">
                                <h4 class="text-xs font-semibold text-gray-900 mb-2"><?= T::premium_economy ?></h4>
                                <div class="space-y-1">
                                    <div class="flex justify-between text-xs">
                                        <span class="text-gray-500"><?= T::adult_price ?></span>
                                        <span class="font-medium text-gray-900"><?= $flight['currency'] ?>
                                            <?= number_format($flight['premium_economy_adult_price'], 2) ?></span>
                                    </div>
                                    <?php if ($flight['premium_economy_child_price'] > 0): ?>
                                        <div class="flex justify-between text-xs">
                                            <span class="text-gray-500"><?= T::child_price ?></span>
                                            <span class="font-medium text-gray-900"><?= $flight['currency'] ?>
                                                <?= number_format($flight['premium_economy_child_price'], 2) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($flight['premium_economy_infant_price'] > 0): ?>
                                        <div class="flex justify-between text-xs">
                                            <span class="text-gray-500"><?= T::infant_price ?></span>
                                            <span class="font-medium text-gray-900"><?= $flight['currency'] ?>
                                                <?= number_format($flight['premium_economy_infant_price'], 2) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Business Class -->
                        <?php if ($flight['business_adult_price'] > 0): ?>
                            <div class="bg-white rounded-lg border border-gray-200 p-3">
                                <h4 class="text-xs font-semibold text-gray-900 mb-2"><?= T::business_class ?></h4>
                                <div class="space-y-1">
                                    <div class="flex justify-between text-xs">
                                        <span class="text-gray-500"><?= T::adult_price ?></span>
                                        <span class="font-medium text-gray-900"><?= $flight['currency'] ?>
                                            <?= number_format($flight['business_adult_price'], 2) ?></span>
                                    </div>
                                    <?php if ($flight['business_child_price'] > 0): ?>
                                        <div class="flex justify-between text-xs">
                                            <span class="text-gray-500"><?= T::child_price ?></span>
                                            <span class="font-medium text-gray-900"><?= $flight['currency'] ?>
                                                <?= number_format($flight['business_child_price'], 2) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($flight['business_infant_price'] > 0): ?>
                                        <div class="flex justify-between text-xs">
                                            <span class="text-gray-500"><?= T::infant_price ?></span>
                                            <span class="font-medium text-gray-900"><?= $flight['currency'] ?>
                                                <?= number_format($flight['business_infant_price'], 2) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- First Class -->
                        <?php if ($flight['first_adult_price'] > 0): ?>
                            <div class="bg-white rounded-lg border border-gray-200 p-3">
                                <h4 class="text-xs font-semibold text-gray-900 mb-2"><?= T::first_class ?></h4>
                                <div class="space-y-1">
                                    <div class="flex justify-between text-xs">
                                        <span class="text-gray-500"><?= T::adult_price ?></span>
                                        <span class="font-medium text-gray-900"><?= $flight['currency'] ?>
                                            <?= number_format($flight['first_adult_price'], 2) ?></span>
                                    </div>
                                    <?php if ($flight['first_child_price'] > 0): ?>
                                        <div class="flex justify-between text-xs">
                                            <span class="text-gray-500"><?= T::child_price ?></span>
                                            <span class="font-medium text-gray-900"><?= $flight['currency'] ?>
                                                <?= number_format($flight['first_child_price'], 2) ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($flight['first_infant_price'] > 0): ?>
                                        <div class="flex justify-between text-xs">
                                            <span class="text-gray-500"><?= T::infant_price ?></span>
                                            <span class="font-medium text-gray-900"><?= $flight['currency'] ?>
                                                <?= number_format($flight['first_infant_price'], 2) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Baggage & Seats -->
                <div class="bg-gray-50 rounded-lg p-4 mb-6">
                    <h3
                        class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                        <span class="material-symbols-outlined text-blue-600 text-lg">luggage</span>
                        <?= T::baggage_seats ?>
                    </h3>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <?php if (!empty($flight['checked_baggage'])): ?>
                            <div>
                                <div class="text-xs text-gray-500 mb-1"><?= T::checked_baggage ?></div>
                                <div class="text-sm font-medium text-gray-900">
                                    <?= htmlspecialchars($flight['checked_baggage']) ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($flight['cabin_baggage'])): ?>
                            <div>
                                <div class="text-xs text-gray-500 mb-1"><?= T::cabin_baggage ?></div>
                                <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($flight['cabin_baggage']) ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div>
                            <div class="text-xs text-gray-500 mb-1"><?= T::total_seats ?></div>
                            <div class="text-sm font-medium text-gray-900"><?= $flight['total_seats'] ?></div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-500 mb-1"><?= T::available_seats ?></div>
                            <div class="text-sm font-medium text-gray-900"><?= $flight['available_seats'] ?></div>
                        </div>
                    </div>
                </div>
                <!-- Amenities -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <h3
                        class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                        <span class="material-symbols-outlined text-blue-600 text-lg">star</span>
                        <?= T::amenities ?>
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                        <?php if ($flight['has_wifi']): ?>
                            <div class="flex items-center gap-2 text-sm text-gray-700">
                                <span class="material-symbols-outlined text-green-600 text-base">check_circle</span>
                                <span><?= T::wifi_available ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if ($flight['has_meal']): ?>
                            <div class="flex items-center gap-2 text-sm text-gray-700">
                                <span class="material-symbols-outlined text-green-600 text-base">check_circle</span>
                                <span><?= T::meal_service ?></span>
                                <?php if (!empty($flight['meal_type'])): ?>
                                    <span class="text-xs text-gray-500">(<?= htmlspecialchars($flight['meal_type']) ?>)</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($flight['has_entertainment']): ?>
                            <div class="flex items-center gap-2 text-sm text-gray-700">
                                <span class="material-symbols-outlined text-green-600 text-base">check_circle</span>
                                <span><?= T::entertainment_system ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if ($flight['has_power_outlet']): ?>
                            <div class="flex items-center gap-2 text-sm text-gray-700">
                                <span class="material-symbols-outlined text-green-600 text-base">check_circle</span>
                                <span><?= T::power_outlet ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if ($flight['refundable']): ?>
                            <div class="flex items-center gap-2 text-sm text-gray-700">
                                <span class="material-symbols-outlined text-green-600 text-base">check_circle</span>
                                <span><?= T::refundable_ticket ?></span>
                                <?php if ($flight['cancellation_fee'] > 0): ?>
                                    <span class="text-xs text-gray-500">(<?= T::cancellation_fee ?>: <?= $flight['currency'] ?>
                                        <?= number_format($flight['cancellation_fee'], 2) ?>)</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- ADD/EDIT MODE - SINGLE FORM -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200" x-data="flightFormData()" x-init="init()">
            <!-- Form -->
            <form method="POST" action="<?= root . admin ?>/flights/<?= $isEdit ? 'edit/' . $flight['id'] : 'add' ?>"
                @submit="submitForm($event)" class="p-6">
                <?= CSRF::tokenField() ?>

                <div class="space-y-6">
                    <div class="flex items-center justify-between pb-2 mb-4 border-b border-gray-200">
                        <!-- LEFT SIDE : SETTINGS -->
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
                            <span class="material-symbols-outlined text-blue-600 text-lg">settings</span>
                            <?= T::status_configuration ?>
                        </h3>

                        <!-- RIGHT SIDE : FEATURED -->
                        <div class="form-control">
                            <label class="flex items-center gap-2">
                                <span class="text-sm"><?= T::featured ?></span>
                                <input type="checkbox" name="featured" class="checkbox" <?= ($flight['has_featured'] ?? 0) ? 'checked' : '' ?>>
                            </label>
                        </div>
                    </div>
                    <!-- Status & Flight Type Section -->
                    <div class="bg-gray-50 rounded-lg p-4">
                        <?php if ($isAdd): ?>
                            <div class="grid grid-cols-1 lg:grid-cols-4 gap-4 items-end">
                            <?php else: ?>
                                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 items-end">
                                <?php endif; ?>
                                <!-- Owner Field -->
                                <div class="form-control">
                                    <label class="text-sm block mb-1"><?= T::owner ?></label>
                                    <div class="relative" @click.away="showUserDropdown = false">
                                        <div x-show="selectedUser" class="input flex items-center gap-2">
                                            <div class="flex-1 overflow-hidden">
                                                <span class="font-medium text-gray-900 truncate text-sm"
                                                    x-text="selectedUser ? selectedUser.first_name + ' ' + selectedUser.last_name : ''"></span>
                                            </div>
                                            <button type="button" @click.stop="clearUserSelection()"
                                                class="flex-shrink-0 rounded p-1">
                                                <span class="material-symbols-outlined text-gray-500 text-base">close</span>
                                            </button>
                                        </div>

                                        <div x-show="!selectedUser">
                                            <input type="text" x-model="userSearch" @input.debounce.300ms="searchUsers()"
                                                @focus="searchUsers()" class="input"
                                                placeholder="<?= T::search_by_name_email ?>" autocomplete="off">

                                            <div x-show="searchingUsers"
                                                class="absolute right-3 top-1/2 transform -translate-y-1/2">
                                                <svg class="animate-spin h-4 w-4 text-gray-400" fill="none"
                                                    viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                                        stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor"
                                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                                    </path>
                                                </svg>
                                            </div>

                                            <div x-show="showUserDropdown"
                                                class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto">
                                                <template x-for="user in searchResults" :key="user.user_id">
                                                    <div @click="selectUser(user)"
                                                        class="px-3 py-2 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0">
                                                        <div class="font-medium text-gray-900 text-sm"
                                                            x-text="user.first_name + ' ' + user.last_name"></div>
                                                        <div class="text-xs text-gray-500" x-text="user.email"></div>
                                                    </div>
                                                </template>

                                                <div x-show="searchResults.length === 0 && userSearch.length >= 2 && !searchingUsers"
                                                    class="px-3 py-2 text-xs text-gray-500 text-center">
                                                    <?= T::no_users_found ?>
                                                </div>
                                            </div>
                                        </div>

                                        <input type="hidden" name="user_id" id="user_id_input" value="">
                                    </div>
                                </div>

                                <div class="form-control">
                                    <label class="text-sm block mb-1"><?= T::status ?></label>
                                    <select name="status" class="select">
                                        <option value="1" <?= $flight['status'] == 1 ? 'selected' : '' ?>><?= T::active ?>
                                        </option>
                                        <option value="0" <?= $flight['status'] == 0 ? 'selected' : '' ?>><?= T::inactive ?>
                                        </option>
                                    </select>
                                </div>

                                <div class="form-control">
                                    <label class="text-sm block mb-1"><?= T::flight_type ?></label>
                                    <select name="flight_type" class="select" x-model="flightType">
                                        <option value="fixed"><?= T::fixed_date ?></option>
                                        <option value="recurring"><?= T::recurring_flight ?></option>
                                    </select>
                                </div>

                                <?php if ($isAdd): ?>
                                    <div class="form-control">
                                        <label class="text-sm block mb-1"><?= T::trip_type ?></label>
                                        <select name="trip_type" class="select" x-model="tripType" @change="tripTypeChanged()">
                                            <option value="one_way"><?= T::one_way ?></option>
                                            <option value="round_trip"><?= T::round_trip ?></option>
                                        </select>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Main Flight Section -->
                        <div class="space-y-6">
                            <!-- Outbound Flight Information -->
                            <div class="bg-gray-50 rounded-lg p-4">
                                <div class="flex items-center justify-between mb-4 pb-2 border-b border-gray-200">
                                    <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
                                        <span class="material-symbols-outlined text-blue-600 text-lg">flight</span>
                                        <?= T::flight_information ?>
                                    </h3>
                                    <span
                                        x-text="routes.length === 1 ? '<?= T::direct_flight ?>' : routes.length + ' <?= T::stops ?>'"
                                        class="text-xs font-medium px-2 py-1 rounded-full"
                                        :class="routes.length === 1 ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700'"></span>
                                </div>

                                <!-- Loop Through All Routes -->
                                <template x-for="(route, routeIndex) in routes" :key="route.id">
                                    <div class="mb-4" data-route-index="<?php echo '{{ routeIndex }}'; ?>">
                                        <!-- Route Header -->
                                        <div class="flex items-center justify-between mb-3">
                                            <div class="flex items-center gap-2">
                                                <span
                                                    class="flex items-center justify-center w-6 h-6 rounded-full bg-blue-600 text-white text-xs font-bold"
                                                    x-text="routeIndex + 1"></span>
                                                <span class="text-sm font-semibold text-gray-700"
                                                    x-text="routeIndex === 0 ? '<?= T::flight_leg ?> ' + (routeIndex + 1) : '<?= T::connecting_flight ?> ' + (routeIndex + 1)"></span>
                                            </div>
                                            <button type="button" x-show="routes.length > 1"
                                                @click="removeRoute(routeIndex)"
                                                class="flex items-center gap-1 px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 rounded">
                                                <span class="material-symbols-outlined"
                                                    style="font-size: 16px;">delete</span>
                                                <span><?= T::remove ?></span>
                                            </button>
                                        </div>

                                        <!-- Departure and Arrival Cards in 50/50 Layout -->
                                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

                                            <!-- DEPARTURE CARD -->
                                            <div class="bg-white rounded-lg border border-gray-200 p-4">
                                                <div
                                                    class="flex flex-col sm:flex-row flex-end items-center gap-2 mb-3 pb-2 border-b border-gray-200">
                                                    <span
                                                        class="material-symbols-outlined text-blue-600">flight_takeoff</span>
                                                    <h4 class="text-sm font-semibold text-gray-900"><?= T::departure ?></h4>


                                                    <div class="flex gap-2">
                                                        <button type="button" @click="copyRoute(routeIndex)"
                                                            class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded flex items-center gap-1">
                                                            <span class="material-symbols-outlined"
                                                                style="font-size: 14px;">content_copy</span>
                                                            <span><?= T::copy_route ?></span>
                                                        </button>
                                                        <button type="button" @click="swapAirports(routeIndex)"
                                                            class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded flex items-center gap-1">
                                                            <span class="material-symbols-outlined"
                                                                style="font-size: 14px;">swap_horiz</span>
                                                            <span><?= T::swap_airports ?></span>
                                                        </button>
                                                    </div>
                                                </div>

                                                <div class="space-y-3">
                                                    <!-- From Airport - Full Row -->
                                                    <div class="form-control from-input-container">
                                                        <label
                                                            class="block text-sm font-medium text-gray-700 mb-1"><?= T::from_airport ?>
                                                            *</label>
                                                        <div class="relative" @click.away="route.showFromDropdown = false">
                                                            <div x-show="route.selectedFrom"
                                                                class="input flex items-center gap-1 pr-6">
                                                                <span class="font-medium"
                                                                    x-text="route.selectedFrom?.code"></span>
                                                                <span class="text-gray-500">-</span>
                                                                <span class="text-sm text-gray-600"
                                                                    x-text="route.selectedFrom?.city"></span>
                                                            </div>
                                                            <div x-show="!route.selectedFrom">
                                                                <input type="text" x-model="route.fromSearch"
                                                                    @input.debounce.300ms="searchFrom(routeIndex)"
                                                                    @focus="searchFrom(routeIndex)" class="input"
                                                                    placeholder="<?= T::search ?>" autocomplete="off">
                                                            </div>
                                                            <button type="button" x-show="route.selectedFrom"
                                                                @click.stop="clearFromSelection(routeIndex)"
                                                                class="absolute right-1 top-1/2 -translate-y-1/2 hover:bg-gray-100 rounded p-0.5">
                                                                <span class="material-symbols-outlined text-gray-500"
                                                                    style="font-size: 16px;">close</span>
                                                            </button>
                                                            <div x-show="route.showFromDropdown"
                                                                class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                                                <template x-for="airport in route.fromResults"
                                                                    :key="airport.id">
                                                                    <div @click="selectFrom(routeIndex, airport)"
                                                                        class="px-2 py-1.5 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0">
                                                                        <div class="font-medium text-gray-900 text-sm"
                                                                            x-text="airport.code + ' - ' + airport.city">
                                                                        </div>
                                                                        <div class="text-sm text-gray-500"
                                                                            x-text="airport.country"></div>
                                                                    </div>
                                                                </template>
                                                            </div>
                                                            <input type="hidden"
                                                                :name="'routes[' + routeIndex + '][from_airport_id]'"
                                                                x-bind:value="route.selectedFrom?.id">
                                                        </div>
                                                    </div>

                                                    <!-- Airline & Flight Number - 50/50 Row -->
                                                    <div class="grid grid-cols-2 gap-2">
                                                        <div class="form-control airline-input-container">
                                                            <label
                                                                class="block text-sm font-medium text-gray-700 mb-1"><?= T::airline ?>
                                                                *</label>
                                                            <div class="relative"
                                                                @click.away="route.showAirlineDropdown = false">
                                                                <div x-show="route.selectedAirline"
                                                                    class="input flex items-center gap-1 pr-6">
                                                                    <span class="font-medium text-sm"
                                                                        x-text="route.selectedAirline?.iata"></span>
                                                                </div>
                                                                <div x-show="!route.selectedAirline">
                                                                    <input type="text" x-model="route.airlineSearch"
                                                                        @input.debounce.300ms="searchAirlines(routeIndex)"
                                                                        @focus="searchAirlines(routeIndex)" class="input"
                                                                        placeholder="<?= T::search ?>" autocomplete="off">
                                                                </div>
                                                                <button type="button" x-show="route.selectedAirline"
                                                                    @click.stop="clearAirlineSelection(routeIndex)"
                                                                    class="absolute right-1 top-1/2 -translate-y-1/2 hover:bg-gray-100 rounded p-0.5">
                                                                    <span class="material-symbols-outlined text-gray-500"
                                                                        style="font-size: 16px;">close</span>
                                                                </button>
                                                                <div x-show="route.showAirlineDropdown"
                                                                    class="absolute z-50 w-64 mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                                                    <template x-for="airline in route.airlineResults"
                                                                        :key="airline.id">
                                                                        <div @click="selectAirline(routeIndex, airline)"
                                                                            class="px-2 py-1.5 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0">
                                                                            <div class="font-medium text-gray-900 text-sm"
                                                                                x-text="airline.name"></div>
                                                                            <div class="text-sm text-gray-500"
                                                                                x-text="airline.iata"></div>
                                                                        </div>
                                                                    </template>
                                                                </div>
                                                                <input type="hidden"
                                                                    :name="'routes[' + routeIndex + '][airline_id]'"
                                                                    x-bind:value="route.selectedAirline?.id">
                                                            </div>
                                                        </div>
                                                        <div class="form-control">
                                                            <label
                                                                class="block text-sm font-medium text-gray-700 mb-1"><?= T::flight_number ?>
                                                                *</label>
                                                            <input type="text"
                                                                :name="'routes[' + routeIndex + '][flight_number]'"
                                                                x-model="route.flightNumber" class="input"
                                                                placeholder="PK304">
                                                        </div>
                                                    </div>

                                                    <!-- Date & Time - 50/50 Row -->
                                                    <div class="grid grid-cols-2 gap-2">
                                                        <!-- Fixed Date -->
                                                        <div x-show="flightType === 'fixed'">
                                                            <label
                                                                class="block text-sm font-medium text-gray-700 mb-1"><?= T::date ?>
                                                                *</label>
                                                            <input type="date"
                                                                :name="'routes[' + routeIndex + '][departure_date]'"
                                                                x-model="route.departureDate" class="input">
                                                        </div>
                                                        <!-- Recurring - Day Selection -->
                                                        <div x-show="flightType === 'recurring'">
                                                            <label
                                                                class="block text-sm font-medium text-gray-700 mb-1"><?= T::day ?>
                                                                *</label>
                                                            <select :name="'routes[' + routeIndex + '][departure_day]'"
                                                                class="select" x-model="route.departureDay">
                                                                <option value=""><?= T::select_day ?></option>
                                                                <option value="monday"><?= T::monday ?></option>
                                                                <option value="tuesday"><?= T::tuesday ?></option>
                                                                <option value="wednesday"><?= T::wednesday ?></option>
                                                                <option value="thursday"><?= T::thursday ?></option>
                                                                <option value="friday"><?= T::friday ?></option>
                                                                <option value="saturday"><?= T::saturday ?></option>
                                                                <option value="sunday"><?= T::sunday ?></option>
                                                            </select>
                                                        </div>
                                                        <div class="form-control">
                                                            <label
                                                                class="block text-sm font-medium text-gray-700 mb-1"><?= T::time ?>
                                                                *</label>
                                                            <input type="time"
                                                                :name="'routes[' + routeIndex + '][departure_time]'"
                                                                x-model="route.departureTime" class="input">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- ARRIVAL CARD -->
                                            <div class="bg-white rounded-lg border border-gray-200 p-4">
                                                <div class="flex items-center gap-2 mb-3 pb-2 border-b border-gray-200">
                                                    <span class="material-symbols-outlined text-blue-600">flight_land</span>
                                                    <h4 class="text-sm font-semibold text-gray-900"><?= T::arrival ?></h4>
                                                </div>

                                                <div class="space-y-3">
                                                    <!-- To Airport - Full Row -->
                                                    <div class="form-control to-input-container">
                                                        <label
                                                            class="block text-sm font-medium text-gray-700 mb-1"><?= T::to_airport ?>
                                                            *</label>
                                                        <div class="relative" @click.away="route.showToDropdown = false">
                                                            <div x-show="route.selectedTo"
                                                                class="input flex items-center gap-1 pr-6">
                                                                <span class="font-medium"
                                                                    x-text="route.selectedTo?.code"></span>
                                                                <span class="text-gray-500">-</span>
                                                                <span class="text-sm text-gray-600"
                                                                    x-text="route.selectedTo?.city"></span>
                                                            </div>
                                                            <div x-show="!route.selectedTo">
                                                                <input type="text" x-model="route.toSearch"
                                                                    @input.debounce.300ms="searchTo(routeIndex)"
                                                                    @focus="searchTo(routeIndex)" class="input"
                                                                    placeholder="<?= T::search ?>" autocomplete="off">
                                                            </div>
                                                            <button type="button" x-show="route.selectedTo"
                                                                @click.stop="clearToSelection(routeIndex)"
                                                                class="absolute right-1 top-1/2 -translate-y-1/2 hover:bg-gray-100 rounded p-0.5">
                                                                <span class="material-symbols-outlined text-gray-500"
                                                                    style="font-size: 16px;">close</span>
                                                            </button>
                                                            <div x-show="route.showToDropdown"
                                                                class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                                                <template x-for="airport in route.toResults"
                                                                    :key="airport.id">
                                                                    <div @click="selectTo(routeIndex, airport)"
                                                                        class="px-2 py-1.5 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0">
                                                                        <div class="font-medium text-gray-900 text-sm"
                                                                            x-text="airport.code + ' - ' + airport.city">
                                                                        </div>
                                                                        <div class="text-sm text-gray-500"
                                                                            x-text="airport.country"></div>
                                                                    </div>
                                                                </template>
                                                            </div>
                                                            <input type="hidden"
                                                                :name="'routes[' + routeIndex + '][to_airport_id]'"
                                                                x-bind:value="route.selectedTo?.id">
                                                        </div>
                                                    </div>

                                                    <!-- Flight Duration - Full Row -->
                                                    <div>
                                                        <label class="block text-sm font-medium text-gray-700 mb-1">
                                                            <?= T::flight_duration ?>
                                                            <span
                                                                class="text-xs text-gray-500">(<?= T::auto_calculated ?>)</span>
                                                        </label>
                                                        <input type="text" :name="'routes[' + routeIndex + '][duration]'"
                                                            x-model="route.duration" class="input bg-gray-50"
                                                            placeholder="<?= T::auto_calculated ?>" readonly>
                                                    </div>

                                                    <!-- Date & Time - 50/50 Row -->
                                                    <div class="grid grid-cols-2 gap-2">
                                                        <!-- Fixed Date -->
                                                        <div x-show="flightType === 'fixed'">
                                                            <label
                                                                class="block text-sm font-medium text-gray-700 mb-1"><?= T::date ?>
                                                                *</label>
                                                            <input type="date"
                                                                :name="'routes[' + routeIndex + '][arrival_date]'"
                                                                x-model="route.arrivalDate" class="input">
                                                        </div>
                                                        <!-- Recurring - Day Selection -->
                                                        <div x-show="flightType === 'recurring'">
                                                            <label
                                                                class="block text-sm font-medium text-gray-700 mb-1"><?= T::day ?>
                                                                *</label>
                                                            <select :name="'routes[' + routeIndex + '][arrival_day]'"
                                                                class="select" x-model="route.arrivalDay">
                                                                <option value=""><?= T::select_day ?></option>
                                                                <option value="monday"><?= T::monday ?></option>
                                                                <option value="tuesday"><?= T::tuesday ?></option>
                                                                <option value="wednesday"><?= T::wednesday ?></option>
                                                                <option value="thursday"><?= T::thursday ?></option>
                                                                <option value="friday"><?= T::friday ?></option>
                                                                <option value="saturday"><?= T::saturday ?></option>
                                                                <option value="sunday"><?= T::sunday ?></option>
                                                            </select>
                                                        </div>
                                                        <div class="form-control">
                                                            <label
                                                                class="block text-sm font-medium text-gray-700 mb-1"><?= T::time ?>
                                                                *</label>
                                                            <input type="time"
                                                                :name="'routes[' + routeIndex + '][arrival_time]'"
                                                                x-model="route.arrivalTime"
                                                                @change="updateRouteDuration(routeIndex)" class="input">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Add Connecting Flight Button (only shows on last route) -->
                                        <div x-show="routeIndex === routes.length - 1"
                                            class="mt-3 pt-3 border-t border-gray-200">
                                            <button type="button" @click="addRoute(routes, false)"
                                                class="btn"><?= T::add_connecting_flight ?></button>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <!-- Outbound Journey Summary -->
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4" x-show="routes.length > 0">
                                <div class="flex items-center gap-2 mb-3">
                                    <span class="material-symbols-outlined text-blue-600">timeline</span>
                                    <h4 class="text-sm font-semibold text-gray-900"><?= T::journey_summary ?></h4>
                                </div>

                                <div class="space-y-3">
                                    <!-- Loop through routes for timeline -->
                                    <template x-for="(route, idx) in routes" :key="route.id">
                                        <div>
                                            <!-- Flight Leg -->
                                            <div class="flex items-center gap-2">
                                                <div class="flex flex-col items-center">
                                                    <div class="w-3 h-3 rounded-full bg-blue-600"></div>
                                                    <div class="w-0.5 h-8 bg-blue-300" x-show="idx < routes.length - 1">
                                                    </div>
                                                </div>
                                                <div class="flex-1 bg-white rounded-lg p-2 border border-blue-200">
                                                    <div class="flex items-center justify-between">
                                                        <div class="text-xs">
                                                            <span class="font-semibold"
                                                                x-text="route.selectedFrom?.code || '<?= T::origin ?>'"></span>
                                                            <span class="material-symbols-outlined text-blue-600 mx-1"
                                                                style="font-size: 14px;">trending_flat</span>
                                                            <span class="font-semibold"
                                                                x-text="route.selectedTo?.code || '<?= T::destination ?>'"></span>
                                                        </div>
                                                        <div class="text-xs font-medium text-blue-600"
                                                            x-text="route.duration || '<?= T::calculating ?>'"></div>
                                                    </div>
                                                    <div class="text-xs text-gray-600 mt-1">
                                                        <span x-text="route.departureTime || '--:--'"></span>
                                                        <span class="mx-1">→</span>
                                                        <span x-text="route.arrivalTime || '--:--'"></span>
                                                        <span class="ml-2 text-gray-500"
                                                            x-text="route.selectedAirline?.iata || ''"></span>
                                                        <span
                                                            x-text="route.flightNumber ? route.selectedAirline?.iata + route.flightNumber : ''"></span>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Layover Time (only show if not the last route) -->
                                            <template x-if="idx < routes.length - 1">
                                                <div class="flex items-center gap-2 my-2 ml-1.5">
                                                    <div class="flex flex-col items-center">
                                                        <div class="w-2 h-2 rounded-full bg-orange-400"></div>
                                                        <div class="w-0.5 h-6 bg-orange-300"></div>
                                                    </div>
                                                    <div
                                                        class="flex-1 text-xs text-orange-700 bg-orange-50 rounded px-2 py-1 border border-orange-200">
                                                        <span class="font-medium"><?= T::layover ?>:</span>
                                                        <span
                                                            x-text="calculateLayover(route, routes[idx + 1], flightType) || '<?= T::calculating ?>'"></span>
                                                        <span class="text-gray-600 ml-1">
                                                            <?= T::at ?> <span class="font-medium"
                                                                x-text="route.selectedTo?.code"></span>
                                                        </span>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </template>

                                    <!-- Total Journey Time -->
                                    <div class="pt-2 border-t border-blue-200">
                                        <div class="flex items-center justify-between">
                                            <span
                                                class="text-sm font-semibold text-gray-900"><?= T::total_journey_time ?>:</span>
                                            <span class="text-lg font-bold text-blue-600"
                                                x-text="calculateTotalJourneyTime(routes) || '<?= T::calculating ?>'"></span>
                                        </div>
                                        <div class="text-xs text-gray-600 mt-1">
                                            <span
                                                x-text="routes.length === 1 ? '<?= T::direct_flight ?>' : routes.length + ' <?= T::flights_with ?> ' + (routes.length - 1) + ' ' + (routes.length > 2 ? '<?= T::stops ?>' : '<?= T::stop ?>')"></span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Return Flight Section (Only for Round Trips) -->
                            <?php if ($isAdd): ?>
                                <div x-show="tripType === 'round_trip'" class="space-y-6">
                                    <!-- Return Flight Information -->
                                    <div class="bg-gray-50 rounded-lg p-4">
                                        <div class="flex items-center justify-between mb-4 pb-2 border-b border-gray-200">
                                            <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
                                                <span class="material-symbols-outlined text-primary-600 text-lg">flight</span>
                                                <?= T::return_flight_details ?>
                                            </h3>
                                            <span
                                                x-text="returnRoutes.length === 1 ? '<?= T::direct_flight ?>' : returnRoutes.length + ' <?= T::stops ?>'"
                                                class="text-xs font-medium px-2 py-1 rounded-full"
                                                :class="returnRoutes.length === 1 ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700'"></span>
                                        </div>

                                        <!-- Loop Through All Return Routes -->
                                        <template x-for="(route, routeIndex) in returnRoutes" :key="route.id">
                                            <div class="mb-4" data-return-route-index="<?php echo '{{ routeIndex }}'; ?>">
                                                <!-- Route Header -->
                                                <div class="flex items-center justify-between mb-3">
                                                    <div class="flex items-center gap-2">
                                                        <span
                                                            class="flex items-center justify-center w-6 h-6 rounded-full bg-primary-600 text-white text-xs font-bold"
                                                            x-text="routeIndex + 1"></span>
                                                        <span class="text-sm font-semibold text-gray-700"
                                                            x-text="routeIndex === 0 ? '<?= T::flight_leg ?> ' + (routeIndex + 1) : '<?= T::connecting_flight ?> ' + (routeIndex + 1)"></span>
                                                    </div>
                                                    <button type="button" x-show="returnRoutes.length > 1"
                                                        @click="removeRoute(routeIndex, true)"
                                                        class="flex items-center gap-1 px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 rounded">
                                                        <span class="material-symbols-outlined"
                                                            style="font-size: 16px;">delete</span>
                                                        <span><?= T::remove ?></span>
                                                    </button>
                                                </div>

                                                <!-- Departure and Arrival Cards in 50/50 Layout -->
                                                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

                                                    <!-- DEPARTURE CARD -->
                                                    <div class="bg-white rounded-lg border border-gray-200 p-4">
                                                        <div class="flex items-center gap-2 mb-3 pb-2 border-b border-gray-200">
                                                            <span
                                                                class="material-symbols-outlined text-primary-600">flight_takeoff</span>
                                                            <h4 class="text-sm font-semibold text-gray-900"><?= T::departure ?>
                                                            </h4>

                                                            <div class="flex gap-2">
                                                                <button type="button" @click="copyRoute(routeIndex, true)"
                                                                    class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded flex items-center gap-1">
                                                                    <span class="material-symbols-outlined"
                                                                        style="font-size: 14px;">content_copy</span>
                                                                    <span><?= T::copy_route ?></span>
                                                                </button>
                                                                <button type="button" @click="swapAirports(routeIndex, true)"
                                                                    class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded flex items-center gap-1">
                                                                    <span class="material-symbols-outlined"
                                                                        style="font-size: 14px;">swap_horiz</span>
                                                                    <span><?= T::swap_airports ?></span>
                                                                </button>
                                                            </div>
                                                        </div>

                                                        <div class="space-y-3">
                                                            <!-- From Airport - Full Row -->
                                                            <div class="form-control from-input-container">
                                                                <label
                                                                    class="block text-sm font-medium text-gray-700 mb-1"><?= T::from_airport ?>
                                                                    *</label>
                                                                <div class="relative"
                                                                    @click.away="route.showFromDropdown = false">
                                                                    <div x-show="route.selectedFrom"
                                                                        class="input flex items-center gap-1 pr-6">
                                                                        <span class="font-medium"
                                                                            x-text="route.selectedFrom?.code"></span>
                                                                        <span class="text-gray-500">-</span>
                                                                        <span class="text-sm text-gray-600"
                                                                            x-text="route.selectedFrom?.city"></span>
                                                                    </div>
                                                                    <div x-show="!route.selectedFrom">
                                                                        <input type="text" x-model="route.fromSearch"
                                                                            @input.debounce.300ms="searchFrom(routeIndex, true)"
                                                                            @focus="searchFrom(routeIndex, true)" class="input"
                                                                            placeholder="<?= T::search ?>" autocomplete="off">
                                                                    </div>
                                                                    <button type="button" x-show="route.selectedFrom"
                                                                        @click.stop="clearFromSelection(routeIndex, true)"
                                                                        class="absolute right-1 top-1/2 -translate-y-1/2 hover:bg-gray-100 rounded p-0.5">
                                                                        <span class="material-symbols-outlined text-gray-500"
                                                                            style="font-size: 16px;">close</span>
                                                                    </button>
                                                                    <div x-show="route.showFromDropdown"
                                                                        class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                                                        <template x-for="airport in route.fromResults"
                                                                            :key="airport.id">
                                                                            <div @click="selectFrom(routeIndex, airport, true)"
                                                                                class="px-2 py-1.5 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0">
                                                                                <div class="font-medium text-gray-900 text-sm"
                                                                                    x-text="airport.code + ' - ' + airport.city">
                                                                                </div>
                                                                                <div class="text-sm text-gray-500"
                                                                                    x-text="airport.country"></div>
                                                                            </div>
                                                                        </template>
                                                                    </div>
                                                                    <input type="hidden"
                                                                        :name="'return_routes[' + routeIndex + '][from_airport_id]'"
                                                                        x-bind:value="route.selectedFrom?.id">
                                                                </div>
                                                            </div>

                                                            <!-- Airline & Flight Number - 50/50 Row -->
                                                            <div class="grid grid-cols-2 gap-2">
                                                                <div class="form-control airline-input-container">
                                                                    <label
                                                                        class="block text-sm font-medium text-gray-700 mb-1"><?= T::airline ?>
                                                                        *</label>
                                                                    <div class="relative"
                                                                        @click.away="route.showAirlineDropdown = false">
                                                                        <div x-show="route.selectedAirline"
                                                                            class="input flex items-center gap-1 pr-6">
                                                                            <span class="font-medium text-sm"
                                                                                x-text="route.selectedAirline?.iata"></span>
                                                                        </div>
                                                                        <div x-show="!route.selectedAirline">
                                                                            <input type="text" x-model="route.airlineSearch"
                                                                                @input.debounce.300ms="searchAirlines(routeIndex, true)"
                                                                                @focus="searchAirlines(routeIndex, true)"
                                                                                class="input" placeholder="<?= T::search ?>"
                                                                                autocomplete="off">
                                                                        </div>
                                                                        <button type="button" x-show="route.selectedAirline"
                                                                            @click.stop="clearAirlineSelection(routeIndex, true)"
                                                                            class="absolute right-1 top-1/2 -translate-y-1/2 hover:bg-gray-100 rounded p-0.5">
                                                                            <span
                                                                                class="material-symbols-outlined text-gray-500"
                                                                                style="font-size: 16px;">close</span>
                                                                        </button>
                                                                        <div x-show="route.showAirlineDropdown"
                                                                            class="absolute z-50 w-64 mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                                                            <template x-for="airline in route.airlineResults"
                                                                                :key="airline.id">
                                                                                <div @click="selectAirline(routeIndex, airline, true)"
                                                                                    class="px-2 py-1.5 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0">
                                                                                    <div class="font-medium text-gray-900 text-sm"
                                                                                        x-text="airline.name"></div>
                                                                                    <div class="text-sm text-gray-500"
                                                                                        x-text="airline.iata"></div>
                                                                                </div>
                                                                            </template>
                                                                        </div>
                                                                        <input type="hidden"
                                                                            :name="'return_routes[' + routeIndex + '][airline_id]'"
                                                                            x-bind:value="route.selectedAirline?.id">
                                                                    </div>
                                                                </div>
                                                                <div class="form-control">
                                                                    <label
                                                                        class="block text-sm font-medium text-gray-700 mb-1"><?= T::flight_number ?>
                                                                        *</label>
                                                                    <input type="text"
                                                                        :name="'return_routes[' + routeIndex + '][flight_number]'"
                                                                        x-model="route.flightNumber" class="input"
                                                                        placeholder="PK305">
                                                                </div>
                                                            </div>

                                                            <!-- Date & Time - 50/50 Row -->
                                                            <div class="grid grid-cols-2 gap-2">
                                                                <!-- Fixed Date -->
                                                                <div x-show="flightType === 'fixed'">
                                                                    <label
                                                                        class="block text-sm font-medium text-gray-700 mb-1"><?= T::date ?>
                                                                        *</label>
                                                                    <input type="date"
                                                                        :name="'return_routes[' + routeIndex + '][departure_date]'"
                                                                        x-model="route.departureDate" class="input">
                                                                </div>
                                                                <!-- Recurring - Day Selection -->
                                                                <div x-show="flightType === 'recurring'">
                                                                    <label
                                                                        class="block text-sm font-medium text-gray-700 mb-1"><?= T::day ?>
                                                                        *</label>
                                                                    <select
                                                                        :name="'return_routes[' + routeIndex + '][departure_day]'"
                                                                        class="select" x-model="route.departureDay">
                                                                        <option value=""><?= T::select_day ?></option>
                                                                        <option value="monday"><?= T::monday ?></option>
                                                                        <option value="tuesday"><?= T::tuesday ?></option>
                                                                        <option value="wednesday"><?= T::wednesday ?></option>
                                                                        <option value="thursday"><?= T::thursday ?></option>
                                                                        <option value="friday"><?= T::friday ?></option>
                                                                        <option value="saturday"><?= T::saturday ?></option>
                                                                        <option value="sunday"><?= T::sunday ?></option>
                                                                    </select>
                                                                </div>
                                                                <div class="form-control">
                                                                    <label
                                                                        class="block text-sm font-medium text-gray-700 mb-1"><?= T::time ?>
                                                                        *</label>
                                                                    <input type="time"
                                                                        :name="'return_routes[' + routeIndex + '][departure_time]'"
                                                                        x-model="route.departureTime" class="input">
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <!-- ARRIVAL CARD -->
                                                    <div class="bg-white rounded-lg border border-gray-200 p-4">
                                                        <div class="flex items-center gap-2 mb-3 pb-2 border-b border-gray-200">
                                                            <span
                                                                class="material-symbols-outlined text-primary-600">flight_land</span>
                                                            <h4 class="text-sm font-semibold text-gray-900"><?= T::arrival ?>
                                                            </h4>
                                                        </div>

                                                        <div class="space-y-3">
                                                            <!-- To Airport - Full Row -->
                                                            <div class="form-control to-input-container">
                                                                <label
                                                                    class="block text-sm font-medium text-gray-700 mb-1"><?= T::to_airport ?>
                                                                    *</label>
                                                                <div class="relative"
                                                                    @click.away="route.showToDropdown = false">
                                                                    <div x-show="route.selectedTo"
                                                                        class="input flex items-center gap-1 pr-6">
                                                                        <span class="font-medium"
                                                                            x-text="route.selectedTo?.code"></span>
                                                                        <span class="text-gray-500">-</span>
                                                                        <span class="text-sm text-gray-600"
                                                                            x-text="route.selectedTo?.city"></span>
                                                                    </div>
                                                                    <div x-show="!route.selectedTo">
                                                                        <input type="text" x-model="route.toSearch"
                                                                            @input.debounce.300ms="searchTo(routeIndex, true)"
                                                                            @focus="searchTo(routeIndex, true)" class="input"
                                                                            placeholder="<?= T::search ?>" autocomplete="off">
                                                                    </div>
                                                                    <button type="button" x-show="route.selectedTo"
                                                                        @click.stop="clearToSelection(routeIndex, true)"
                                                                        class="absolute right-1 top-1/2 -translate-y-1/2 hover:bg-gray-100 rounded p-0.5">
                                                                        <span class="material-symbols-outlined text-gray-500"
                                                                            style="font-size: 16px;">close</span>
                                                                    </button>
                                                                    <div x-show="route.showToDropdown"
                                                                        class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                                                                        <template x-for="airport in route.toResults"
                                                                            :key="airport.id">
                                                                            <div @click="selectTo(routeIndex, airport, true)"
                                                                                class="px-2 py-1.5 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0">
                                                                                <div class="font-medium text-gray-900 text-sm"
                                                                                    x-text="airport.code + ' - ' + airport.city">
                                                                                </div>
                                                                                <div class="text-sm text-gray-500"
                                                                                    x-text="airport.country"></div>
                                                                            </div>
                                                                        </template>
                                                                    </div>
                                                                    <input type="hidden"
                                                                        :name="'return_routes[' + routeIndex + '][to_airport_id]'"
                                                                        x-bind:value="route.selectedTo?.id">
                                                                </div>
                                                            </div>

                                                            <!-- Flight Duration - Full Row -->
                                                            <div>
                                                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                                                    <?= T::flight_duration ?>
                                                                    <span
                                                                        class="text-xs text-gray-500">(<?= T::auto_calculated ?>)</span>
                                                                </label>
                                                                <input type="text"
                                                                    :name="'return_routes[' + routeIndex + '][duration]'"
                                                                    x-model="route.duration" class="input bg-gray-50"
                                                                    placeholder="<?= T::auto_calculated ?>" readonly>
                                                            </div>

                                                            <!-- Date & Time - 50/50 Row -->
                                                            <div class="grid grid-cols-2 gap-2">
                                                                <!-- Fixed Date -->
                                                                <div x-show="flightType === 'fixed'">
                                                                    <label
                                                                        class="block text-sm font-medium text-gray-700 mb-1"><?= T::date ?>
                                                                        *</label>
                                                                    <input type="date"
                                                                        :name="'return_routes[' + routeIndex + '][arrival_date]'"
                                                                        x-model="route.arrivalDate" class="input">
                                                                </div>
                                                                <!-- Recurring - Day Selection -->
                                                                <div x-show="flightType === 'recurring'">
                                                                    <label
                                                                        class="block text-sm font-medium text-gray-700 mb-1"><?= T::day ?>
                                                                        *</label>
                                                                    <select
                                                                        :name="'return_routes[' + routeIndex + '][arrival_day]'"
                                                                        class="select" x-model="route.arrivalDay">
                                                                        <option value=""><?= T::select_day ?></option>
                                                                        <option value="monday"><?= T::monday ?></option>
                                                                        <option value="tuesday"><?= T::tuesday ?></option>
                                                                        <option value="wednesday"><?= T::wednesday ?></option>
                                                                        <option value="thursday"><?= T::thursday ?></option>
                                                                        <option value="friday"><?= T::friday ?></option>
                                                                        <option value="saturday"><?= T::saturday ?></option>
                                                                        <option value="sunday"><?= T::sunday ?></option>
                                                                    </select>
                                                                </div>
                                                                <div class="form-control">
                                                                    <label
                                                                        class="block text-sm font-medium text-gray-700 mb-1"><?= T::time ?>
                                                                        *</label>
                                                                    <input type="time"
                                                                        :name="'return_routes[' + routeIndex + '][arrival_time]'"
                                                                        x-model="route.arrivalTime"
                                                                        @change="updateRouteDuration(routeIndex, true)"
                                                                        class="input">
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Add Connecting Flight Button (only shows on last route) -->
                                                <div x-show="routeIndex === returnRoutes.length - 1"
                                                    class="mt-3 pt-3 border-t border-gray-200">
                                                    <button type="button" @click="addRoute(returnRoutes, true)"
                                                        class="btn"><?= T::add_connecting_flight ?></button>
                                                </div>
                                            </div>
                                        </template>
                                    </div>

                                    <!-- Return Journey Summary -->
                                    <div class="bg-primary-50 border border-primary-200 rounded-lg p-4"
                                        x-show="returnRoutes.length > 0">
                                        <div class="flex items-center gap-2 mb-3">
                                            <span class="material-symbols-outlined text-primary-600">timeline</span>
                                            <h4 class="text-sm font-semibold text-gray-900"><?= T::journey_summary ?>
                                                (<?= T::return_flight ?>)</h4>
                                        </div>

                                        <div class="space-y-3">
                                            <!-- Loop through return routes for timeline -->
                                            <template x-for="(route, idx) in returnRoutes" :key="route.id">
                                                <div>
                                                    <!-- Flight Leg -->
                                                    <div class="flex items-center gap-2">
                                                        <div class="flex flex-col items-center">
                                                            <div class="w-3 h-3 rounded-full bg-primary-600"></div>
                                                            <div class="w-0.5 h-8 bg-primary-300"
                                                                x-show="idx < returnRoutes.length - 1 || (idx < returnRoutes.length - 1)">
                                                            </div>
                                                        </div>
                                                        <div class="flex-1 bg-white rounded-lg p-2 border border-primary-200">
                                                            <div class="flex items-center justify-between">
                                                                <div class="text-xs">
                                                                    <span class="font-semibold"
                                                                        x-text="route.selectedFrom?.code || '<?= T::origin ?>'"></span>
                                                                    <span
                                                                        class="material-symbols-outlined text-primary-600 mx-1"
                                                                        style="font-size: 14px;">trending_flat</span>
                                                                    <span class="font-semibold"
                                                                        x-text="route.selectedTo?.code || '<?= T::destination ?>'"></span>
                                                                </div>
                                                                <div class="text-xs font-medium text-primary-600"
                                                                    x-text="route.duration || '<?= T::calculating ?>'"></div>
                                                            </div>
                                                            <div class="text-xs text-gray-600 mt-1">
                                                                <span x-text="route.departureTime || '--:--'"></span>
                                                                <span class="mx-1">→</span>
                                                                <span x-text="route.arrivalTime || '--:--'"></span>
                                                                <span class="ml-2 text-gray-500"
                                                                    x-text="route.selectedAirline?.iata || ''"></span>
                                                                <span
                                                                    x-text="route.flightNumber ? route.selectedAirline?.iata + route.flightNumber : ''"></span>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <!-- Layover Time (if not last route) -->
                                                    <div x-show="idx < returnRoutes.length - 1"
                                                        class="flex items-center gap-2 my-2 ml-1.5">
                                                        <div class="flex flex-col items-center">
                                                            <div class="w-2 h-2 rounded-full bg-orange-400"></div>
                                                            <div class="w-0.5 h-6 bg-orange-300"></div>
                                                        </div>
                                                        <div
                                                            class="flex-1 text-xs text-orange-700 bg-orange-50 rounded px-2 py-1 border border-orange-200">
                                                            <span class="font-medium"><?= T::layover ?>:</span>
                                                            <span
                                                                x-text="calculateLayover(route, returnRoutes[idx + 1], flightType) || '<?= T::calculating ?>'"></span>
                                                            <span class="text-gray-600 ml-1">
                                                                <?= T::at ?> <span class="font-medium"
                                                                    x-text="route.selectedTo?.code"></span>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>

                                            <!-- Total Journey Time -->
                                            <div class="pt-2 border-t border-primary-200">
                                                <div class="flex items-center justify-between">
                                                    <span
                                                        class="text-sm font-semibold text-gray-900"><?= T::total_journey_time ?>:</span>
                                                    <span class="text-lg font-bold text-primary-600"
                                                        x-text="calculateTotalJourneyTime(returnRoutes) || '<?= T::calculating ?>'"></span>
                                                </div>
                                                <div class="text-xs text-gray-600 mt-1">
                                                    <span
                                                        x-text="returnRoutes.length === 1 ? '<?= T::direct_flight ?>' : returnRoutes.length + ' <?= T::flights_with ?> ' + (returnRoutes.length - 1) + ' ' + (returnRoutes.length > 2 ? '<?= T::stops ?>' : '<?= T::stop ?>')"></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Pricing Section -->
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h3
                                class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                                <span class="material-symbols-outlined text-blue-600 text-lg">payments</span>
                                <?= T::pricing ?>
                            </h3>

                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                <!-- Economy Class Card -->
                                <div class="bg-white rounded-lg border border-gray-200 p-4">
                                    <h4 class="text-sm font-semibold text-gray-900 mb-3 pb-2 border-b border-gray-200">
                                        <?= T::economy_class ?> *</h4>
                                    <div class="grid grid-cols-3 gap-3">
                                        <div class="form-control">
                                            <label class="text-sm"><?= T::adult_price ?> *</label>
                                            <div class="input-group">
                                                <span
                                                    class="input-icon-left text-xs font-semibold"><?= $defaultCurrency ?></span>
                                                <input type="number" name="economy_adult_price" class="input-with-icon"
                                                    min="0" step="0.01" value="<?= $flight['economy_adult_price'] ?>"
                                                    placeholder="0.00">
                                            </div>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-sm"><?= T::child_price ?></label>
                                            <div class="input-group">
                                                <span
                                                    class="input-icon-left text-xs font-semibold"><?= $defaultCurrency ?></span>
                                                <input type="number" name="economy_child_price" class="input-with-icon"
                                                    min="0" step="0.01" value="<?= $flight['economy_child_price'] ?>"
                                                    placeholder="0.00">
                                            </div>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-sm"><?= T::infant_price ?></label>
                                            <div class="input-group">
                                                <span
                                                    class="input-icon-left text-xs font-semibold"><?= $defaultCurrency ?></span>
                                                <input type="number" name="economy_infant_price" class="input-with-icon"
                                                    min="0" step="0.01" value="<?= $flight['economy_infant_price'] ?>"
                                                    placeholder="0.00">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Premium Economy Class Card -->
                                <div class="bg-white rounded-lg border border-gray-200 p-4">
                                    <h4 class="text-sm font-semibold text-gray-900 mb-3 pb-2 border-b border-gray-200">
                                        <?= T::premium_economy ?></h4>
                                    <div class="grid grid-cols-3 gap-3">
                                        <div class="form-control">
                                            <label class="text-sm"><?= T::adult_price ?></label>
                                            <div class="input-group">
                                                <span
                                                    class="input-icon-left text-xs font-semibold"><?= $defaultCurrency ?></span>
                                                <input type="number" name="premium_economy_adult_price"
                                                    class="input-with-icon" min="0" step="0.01"
                                                    value="<?= $flight['premium_economy_adult_price'] ?? '' ?>"
                                                    placeholder="0.00">
                                            </div>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-sm"><?= T::child_price ?></label>
                                            <div class="input-group">
                                                <span
                                                    class="input-icon-left text-xs font-semibold"><?= $defaultCurrency ?></span>
                                                <input type="number" name="premium_economy_child_price"
                                                    class="input-with-icon" min="0" step="0.01"
                                                    value="<?= $flight['premium_economy_child_price'] ?? '' ?>"
                                                    placeholder="0.00">
                                            </div>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-sm"><?= T::infant_price ?></label>
                                            <div class="input-group">
                                                <span
                                                    class="input-icon-left text-xs font-semibold"><?= $defaultCurrency ?></span>
                                                <input type="number" name="premium_economy_infant_price"
                                                    class="input-with-icon" min="0" step="0.01"
                                                    value="<?= $flight['premium_economy_infant_price'] ?? '' ?>"
                                                    placeholder="0.00">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Business Class Card -->
                                <div class="bg-white rounded-lg border border-gray-200 p-4">
                                    <h4 class="text-sm font-semibold text-gray-900 mb-3 pb-2 border-b border-gray-200">
                                        <?= T::business_class ?></h4>
                                    <div class="grid grid-cols-3 gap-3">
                                        <div class="form-control">
                                            <label class="text-sm"><?= T::adult_price ?></label>
                                            <div class="input-group">
                                                <span
                                                    class="input-icon-left text-xs font-semibold"><?= $defaultCurrency ?></span>
                                                <input type="number" name="business_adult_price" class="input-with-icon"
                                                    min="0" step="0.01" value="<?= $flight['business_adult_price'] ?>"
                                                    placeholder="0.00">
                                            </div>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-sm"><?= T::child_price ?></label>
                                            <div class="input-group">
                                                <span
                                                    class="input-icon-left text-xs font-semibold"><?= $defaultCurrency ?></span>
                                                <input type="number" name="business_child_price" class="input-with-icon"
                                                    min="0" step="0.01" value="<?= $flight['business_child_price'] ?>"
                                                    placeholder="0.00">
                                            </div>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-sm"><?= T::infant_price ?></label>
                                            <div class="input-group">
                                                <span
                                                    class="input-icon-left text-xs font-semibold"><?= $defaultCurrency ?></span>
                                                <input type="number" name="business_infant_price" class="input-with-icon"
                                                    min="0" step="0.01" value="<?= $flight['business_infant_price'] ?>"
                                                    placeholder="0.00">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- First Class Card -->
                                <div class="bg-white rounded-lg border border-gray-200 p-4">
                                    <h4 class="text-sm font-semibold text-gray-900 mb-3 pb-2 border-b border-gray-200">
                                        <?= T::first_class ?></h4>
                                    <div class="grid grid-cols-3 gap-3">
                                        <div class="form-control">
                                            <label class="text-sm"><?= T::adult_price ?></label>
                                            <div class="input-group">
                                                <span
                                                    class="input-icon-left text-xs font-semibold"><?= $defaultCurrency ?></span>
                                                <input type="number" name="first_adult_price" class="input-with-icon"
                                                    min="0" step="0.01" value="<?= $flight['first_adult_price'] ?>"
                                                    placeholder="0.00">
                                            </div>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-sm"><?= T::child_price ?></label>
                                            <div class="input-group">
                                                <span
                                                    class="input-icon-left text-xs font-semibold"><?= $defaultCurrency ?></span>
                                                <input type="number" name="first_child_price" class="input-with-icon"
                                                    min="0" step="0.01" value="<?= $flight['first_child_price'] ?>"
                                                    placeholder="0.00">
                                            </div>
                                        </div>
                                        <div class="form-control">
                                            <label class="text-sm"><?= T::infant_price ?></label>
                                            <div class="input-group">
                                                <span
                                                    class="input-icon-left text-xs font-semibold"><?= $defaultCurrency ?></span>
                                                <input type="number" name="first_infant_price" class="input-with-icon"
                                                    min="0" step="0.01" value="<?= $flight['first_infant_price'] ?>"
                                                    placeholder="0.00">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Baggage & Seats Section -->
                            <div class="mt-4">
                                <h3
                                    class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                                    <span class="material-symbols-outlined text-blue-600 text-lg">luggage</span>
                                    <?= T::baggage_seats ?>
                                </h3>

                                <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
                                    <div class="form-control">
                                        <label class="text-sm"><?= T::checked_baggage ?></label>
                                        <input type="text" name="checked_baggage" class="input"
                                            value="<?= htmlspecialchars($flight['checked_baggage'] ?? '') ?>"
                                            placeholder="<?= T::baggage_example_checked ?>">
                                    </div>

                                    <div class="form-control">
                                        <label class="text-sm"><?= T::cabin_baggage ?></label>
                                        <input type="text" name="cabin_baggage" class="input"
                                            value="<?= htmlspecialchars($flight['cabin_baggage'] ?? '') ?>"
                                            placeholder="<?= T::baggage_example_cabin ?>">
                                    </div>

                                    <div class="form-control">
                                        <label class="text-sm"><?= T::total_seats ?></label>
                                        <input type="number" name="total_seats" class="input" min="0"
                                            value="<?= $flight['total_seats'] ?>" placeholder="0">
                                    </div>

                                    <div class="form-control">
                                        <label class="text-sm"><?= T::available_seats ?></label>
                                        <input type="number" name="available_seats" class="input" min="0"
                                            value="<?= $flight['available_seats'] ?>" placeholder="0">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Amenities Section -->
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h3
                                class="text-sm font-semibold text-gray-900 mb-4 flex items-center gap-2 pb-2 border-b border-gray-200">
                                <span class="material-symbols-outlined text-blue-600 text-lg">star</span>
                                <?= T::amenities ?>
                            </h3>

                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                <div class="form-control">
                                    <label class="flex items-center gap-2">
                                        <input type="checkbox" name="has_wifi" class="checkbox" <?= $flight['has_wifi'] ? 'checked' : '' ?>>
                                        <span class="text-sm"><?= T::wifi_available ?></span>
                                    </label>
                                </div>


                                <div class="form-control">
                                    <label class="flex items-center gap-2">
                                        <input type="checkbox" name="has_meal" class="checkbox" <?= $flight['has_meal'] ? 'checked' : '' ?> x-model="hasMeal">
                                        <span class="text-sm"><?= T::meal_service ?></span>
                                    </label>
                                </div>

                                <div class="form-control">
                                    <label class="flex items-center gap-2">
                                        <input type="checkbox" name="has_entertainment" class="checkbox"
                                            <?= $flight['has_entertainment'] ? 'checked' : '' ?>>
                                        <span class="text-sm"><?= T::entertainment_system ?></span>
                                    </label>
                                </div>

                                <div class="form-control">
                                    <label class="flex items-center gap-2">
                                        <input type="checkbox" name="has_power_outlet" class="checkbox"
                                            <?= $flight['has_power_outlet'] ? 'checked' : '' ?>>
                                        <span class="text-sm"><?= T::power_outlet ?></span>
                                    </label>
                                </div>

                                <div class="form-control">
                                    <label class="flex items-center gap-2">
                                        <input type="checkbox" name="refundable" class="checkbox" <?= $flight['refundable'] ? 'checked' : '' ?> x-model="isRefundable">
                                        <span class="text-sm"><?= T::refundable_ticket ?></span>
                                    </label>
                                </div>

                                <div class="form-control" x-show="isRefundable">
                                    <label class="text-sm"><?= T::cancellation_fee ?></label>
                                    <input type="number" name="cancellation_fee" class="input" min="0" step="0.01"
                                        value="<?= $flight['cancellation_fee'] ?>" placeholder="0.00">
                                </div>
                            </div>

                            <!-- Meal Type - Full Width at Bottom -->
                            <div class="mt-4" x-show="hasMeal">
                                <div class="form-control">
                                    <label class="text-sm"><?= T::meal_type ?></label>
                                    <input type="text" name="meal_type" class="input"
                                        value="<?= htmlspecialchars($flight['meal_type'] ?? '') ?>"
                                        placeholder="<?= T::meal_type_example ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Current user id for edit mode -->
                    <?php if ($isEdit && isset($flight['user_id'])): ?>
                        <input type="hidden" name="current_user_id" value="<?= $flight['user_id'] ?>">
                    <?php endif; ?>

                    <!-- Form Actions -->
                    <div class="flex items-center justify-end gap-3 pt-4 mt-4 border-t border-gray-200">
                        <a href="<?= root ?>admin/flights" class="btn white text-sm"><?= T::cancel ?></a>
                        <button type="submit" class="btn text-sm" :disabled="loading">
                            <span :class="loading ? 'hidden' : 'flex'" class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-lg">save</span>
                                <span><?= $isEdit ? T::update_flight : T::add_flight ?></span>
                            </span>
                            <span :class="loading ? 'flex' : 'hidden'" class="flex items-center gap-2">
                                <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                        stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                    </path>
                                </svg>
                                <span><?= T::processing ?></span>
                            </span>
                        </button>
                    </div>
            </form>
        </div>
    <?php endif; ?>
</div>
<?php
// ============================================================================
// FEATURED CARS API
// ============================================================================

@$SECURE or die('Access Denied!');

$router->get('/api/cars/featured', function () use ($SECURE, $db) {

    header('Content-Type: application/json');

    try {

        // ========================================
        // DISPLAY CURRENCY (from query param)
        // ========================================
        $displayCurrency = strtoupper($_GET['currency'] ?? 'USD');

        // ========================================
        // 1. FETCH ALL FEATURED CARS
        // ========================================
        $all_cars = $db->select('cars', '*', ['status' => 1, 'featured' => 1]);

        if (empty($all_cars)) {
            echo json_encode([
                'success' => true,
                'message' => 'No featured cars available',
                'data' => [
                    'locations' => [],
                    'cars_by_location' => [],
                    'currency' => $displayCurrency
                ]
            ]);
            exit;
        }

        // ========================================
        // 2. BUILD LOCATIONS MAP — only the ids the featured cars reference
        //    (avoids loading the entire flights_airports + locations tables, ~16k rows)
        // ========================================
        $locations_map = [];
        $refAirportIds = [];
        $refCityIds    = [];
        foreach ($all_cars as $car) {
            $routes = json_decode($car['routes'] ?? '[]', true);
            if (!is_array($routes)) continue;
            foreach ($routes as $route) {
                $loc = $route['from_location_id'] ?? '';
                if ($loc === '' || $loc === null) continue;
                if (strpos((string) $loc, 'city_') === 0) {
                    $refCityIds[] = (int) substr((string) $loc, 5);
                } else {
                    $refAirportIds[] = (int) $loc;
                }
            }
        }
        $refAirportIds = array_values(array_unique(array_filter($refAirportIds)));
        $refCityIds    = array_values(array_unique(array_filter($refCityIds)));

        if ($refAirportIds) {
            foreach ($db->select('flights_airports', ['id', 'airport', 'city', 'country'], ['id' => $refAirportIds, 'status' => 1]) as $airport) {
                $locations_map[$airport['id']] = [
                    'id' => $airport['id'], 'name' => $airport['airport'],
                    'city' => $airport['city'], 'country' => $airport['country'], 'type' => 'airport'
                ];
            }
        }
        if ($refCityIds) {
            foreach ($db->select('locations', ['id', 'city', 'country'], ['id' => $refCityIds, 'status' => 1]) as $city) {
                $locations_map['city_' . $city['id']] = [
                    'id' => 'city_' . $city['id'], 'name' => $city['city'],
                    'city' => $city['city'], 'country' => $city['country'], 'type' => 'city'
                ];
            }
        }

        // ========================================
        // 3. FIXED CITY TAB ORDER
        // ========================================
        $cars_locations = ['Dubai', 'London', 'Paris', 'Barcelona', 'Bangkok', 'Rome', 'Antalya', 'Kuala Lumpur'];

        // ========================================
        // 4. CARS MODULE FOR MARKUP
        // ========================================
        $carsModule = $db->get('modules', '*', ['name' => 'cars', 'type' => 'cars', 'status' => 1]);

        // ========================================
        // 5. BUILD CAR → CITIES INDEX
        // ========================================
        $car_city_routes = [];
        foreach ($all_cars as $car) {
            $routes = json_decode($car['routes'] ?? '[]', true);
            if (!is_array($routes))
                continue;
            foreach ($routes as $route) {
                $loc_id = $route['from_location_id'] ?? 0;
                if (empty($loc_id) || !isset($locations_map[$loc_id]))
                    continue;
                $loc = $locations_map[$loc_id];
                $city_name = $loc['city'] ?? $loc['name'] ?? '';
                if (empty($city_name))
                    continue;
                $car_city_routes[$car['id']][$city_name] = $route;
            }
        }

        // ========================================
        // 6. ASSIGN CARS TO LOCATIONS
        // ========================================
        $location_cars = [];
        $globally_shown = [];

        foreach ($cars_locations as $location) {
            $location_cars[$location] = [];
            $available = [];

            foreach ($all_cars as $car) {
                if (!isset($car_city_routes[$car['id']][$location]))
                    continue;
                $route = $car_city_routes[$car['id']][$location];
                $car_with_route = $car;
                $car_with_route['price'] = $route['price'];
                $car_with_route['currency'] = $route['currency'];
                $car_with_route['location_name'] = $location;
                $car_with_route['location_city'] = $location;
                $car_with_route['is_refundable'] = !empty($car['is_refundable']);
                // Generate a consistent rating per car (seeded by car ID)
                $car_with_route['_rating'] = round(3.8 + (($car['id'] * 7 + 3) % 13) / 10, 1);
                $available[] = $car_with_route;
            }

            // Sort: prefer cars NOT yet shown globally, then by service_type diversity
            usort($available, function ($a, $b) use ($globally_shown) {
                $aShown = in_array($a['id'], $globally_shown) ? 1 : 0;
                $bShown = in_array($b['id'], $globally_shown) ? 1 : 0;
                if ($aShown !== $bShown)
                    return $aShown - $bShown;
                if ($a['service_type'] !== $b['service_type'])
                    return strcmp($a['service_type'], $b['service_type']);
                return $a['price'] <=> $b['price'];
            });

            // Take up to 4
            $selected = array_slice($available, 0, 4);
            foreach ($selected as $car) {
                $globally_shown[] = $car['id'];
            }
            $location_cars[$location] = $selected;
        }

        // ========================================
        // 7. BUILD RESPONSE — Process each location's cars
        // ========================================
        $response_locations = [];
        $response_cars_by_location = [];

        foreach ($cars_locations as $location) {
            $cars = $location_cars[$location] ?? [];
            $location_slug = strtolower(str_replace([' ', '/', ','], '-', $location));

            $response_locations[] = [
                'name' => $location,
                'slug' => $location_slug,
                'count' => count($cars)
            ];

            $processed_cars = [];
            foreach ($cars as $car) {
                // --- Image handling ---
                $images = json_decode($car['img'] ?? '[]', true);
                $imagePath = 'uploads/no_img.jpg';
                if (!empty($images) && is_array($images)) {
                    $defaultImg = array_filter($images, fn($i) => !empty($i['default']));
                    if (!empty($defaultImg)) {
                        $imagePath = reset($defaultImg)['url'];
                    } else {
                        $imagePath = $images[0]['url'];
                    }
                }
                $carImage = root . ltrim($imagePath, '/');

                // --- Price with MARKUP + currency conversion ---
                $basePrice = (float) ($car['price'] ?? 0);
                $carBaseCurrency = !empty($car['currency']) ? strtoupper((string) $car['currency']) : 'USD';

                $rental_days = 2; // Default for featured
                $price_per_day = $basePrice;
                $price_actual_per_day = $basePrice;
                $price_markup_per_day = 0;
                $price_markup = null;

                if ($basePrice > 0 && function_exists('MARKUP')) {
                    try {
                        $price_markup = MARKUP($basePrice, $carsModule ?: 'cars', $db, $carBaseCurrency, $displayCurrency);
                        $price_per_day = $price_markup['price'] ?? $basePrice;
                        $price_actual_per_day = $price_markup['converted_base_price'] ?? $basePrice;
                        $price_markup_per_day = $price_markup['markup'] ?? 0;
                    } catch (Exception $e) {
                    }
                }

                $displayPrice = $price_per_day * $rental_days;
                $actualPrice = $price_actual_per_day * $rental_days;
                $markupPrice = $price_markup_per_day * $rental_days;

                $processed_cars[] = [
                    'id' => (int) $car['id'],
                    'name' => $car['name'],
                    'brand' => $car['brand'] ?? '',
                    'model' => $car['model'] ?? '',
                    'year' => $car['year'] ?? '',
                    'service_type' => $car['service_type'] ?? 'rental',
                    'transmission' => $car['transmission'] ?? 'Automatic',
                    'fuel_type' => $car['fuel_type'] ?? 'Petrol',
                    'passengers' => (int) ($car['passengers'] ?? 4),
                    'baggage' => (int) ($car['baggage'] ?? 2),
                    'doors' => (int) ($car['doors'] ?? 4),
                    'image' => $carImage,
                    'location_name' => $car['location_name'] ?? $location,
                    'location_city' => $car['location_city'] ?? $location,
                    'is_refundable' => (bool) $car['is_refundable'],
                    'rating' => (float) ($car['_rating'] ?? 4.5),
                    'price' => round($displayPrice, 2),
                    'display_price' => round($displayPrice, 2),
                    'display_price_per_day' => round($price_per_day, 2),
                    'actual_price' => round($actualPrice, 2),
                    'actual_price_per_day' => round($price_actual_per_day, 2),
                    'price_markup' => round($markupPrice, 2),
                    'price_markup_per_day' => round($price_markup_per_day, 2),
                    'actual_price_details' => $price_markup,
                    'currency' => $displayCurrency,
                    'rental_days' => $rental_days,
                    'supplier' => 'cars',
                ];
            }

            $response_cars_by_location[$location_slug] = $processed_cars;
        }

        // ========================================
        // 8. FINAL RESPONSE
        // ========================================
        echo json_encode([
            'success' => true,
            'message' => 'Featured cars fetched successfully',
            'data' => [
                'locations' => $response_locations,
                'cars_by_location' => $response_cars_by_location,
                'currency' => $displayCurrency,
                'default_dates' => [
                    'pickup_date' => date('d-m-Y', strtotime('+1 day')),
                    'return_date' => date('d-m-Y', strtotime('+3 days')),
                    'pickup_time' => '10:00',
                    'return_time' => '10:00'
                ]
            ]
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to fetch featured cars: ' . $e->getMessage()
        ]);
        exit;
    }
});

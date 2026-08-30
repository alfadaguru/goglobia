<?php
// FILE: app/routes/cars/location-suggestion.php
// Car location autocomplete/suggestion route

@$SECURE or die('Access Denied!');

// ====================================
// CAR LOCATION AUTOCOMPLETE
// ====================================

$router->post('/cars-location-suggestion', function () use ($SECURE, $db) {
    
    header('Content-Type: application/json');
    
    $query = trim($_POST['query'] ?? '');
    
    try {
        $results = [];

    if (empty($query)) {
        // Return popular locations when query is empty
        $popular_airports = $db->select('flights_airports', [
            'id', 'airport', 'city', 'country', 'code', 'type'
        ], [
            'status' => 1,
            'LIMIT' => 10,
            'ORDER' => ['airport' => 'ASC']
        ]);
        
        foreach ($popular_airports as $location) {
            $display = $location['airport'];
            if (!empty($location['city']) && $location['city'] !== $location['airport']) {
                $display .= ', ' . $location['city'];
            }
            if (!empty($location['country'])) {
                $display .= ', ' . $location['country'];
            }
            if (!empty($location['code'])) {
                $display .= ' (' . $location['code'] . ')';
            }
            $results[] = [
                'id' => $location['id'],
                'name' => $location['airport'],
                'city' => $location['city'],
                'country' => $location['country'],
                'airport_code' => $location['code'] ?? '',
                'type' => 'airport',
                'display' => $display
            ];
        }
        
        $popular_cities = $db->select('locations', [
            'id', 'city', 'country', 'country_code'
        ], [
            'status' => 1,
            'LIMIT' => 10,
            'ORDER' => ['city' => 'ASC']
        ]);
        
        foreach ($popular_cities as $location) {
            $display = $location['city'] . ', ' . $location['country'];
            if (!empty($location['country_code'])) {
                $display .= ' (' . $location['country_code'] . ')';
            }
            $results[] = [
                'id' => 'city_' . $location['id'],
                'name' => $location['city'],
                'city' => $location['city'],
                'country' => $location['country'],
                'airport_code' => '',
                'type' => 'city',
                'display' => $display
            ];
        }
        
        echo json_encode(['success' => true, 'results' => $results]);
        exit;
    }
        
        // 1. Search in flights_airports table (airports)
        $airports = $db->select('flights_airports', [
            'id',
            'airport',
            'city',
            'country',
            'code',
            'type'
        ], [
            'OR' => [
                'airport[~]' => $query,
                'city[~]' => $query,
                'country[~]' => $query,
                'code[~]' => $query
            ],
            'status' => 1,
            'LIMIT' => 10,
            'ORDER' => ['airport' => 'ASC']
        ]);

        foreach ($airports as $location) {
            // Build display string for airports
            $display = $location['airport'];
            if (!empty($location['city']) && $location['city'] !== $location['airport']) {
                $display .= ', ' . $location['city'];
            }
            if (!empty($location['country'])) {
                $display .= ', ' . $location['country'];
            }
            if (!empty($location['code'])) {
                $display .= ' (' . $location['code'] . ')';
            }
            
            $results[] = [
                'id' => $location['id'],
                'name' => $location['airport'],
                'city' => $location['city'],
                'country' => $location['country'],
                'airport_code' => $location['code'] ?? '',
                'type' => 'airport',
                'display' => $display
            ];
        }
        
        // 2. Search in locations table (cities)
        $cities = $db->select('locations', [
            'id',
            'city',
            'country',
            'country_code'
        ], [
            'OR' => [
                'city[~]' => $query,
                'country[~]' => $query
            ],
            'status' => 1,
            'LIMIT' => 10,
            'ORDER' => ['city' => 'ASC']
        ]);

        foreach ($cities as $location) {
            // Build display string for cities
            $display = $location['city'] . ', ' . $location['country'];
            if (!empty($location['country_code'])) {
                $display .= ' (' . $location['country_code'] . ')';
            }
            
            $results[] = [
                'id' => 'city_' . $location['id'], // Prefix with 'city_' to distinguish from airports
                'name' => $location['city'],
                'city' => $location['city'],
                'country' => $location['country'],
                'airport_code' => '',
                'type' => 'city',
                'display' => $display
            ];
        }

        echo json_encode([
            'success' => true,
            'results' => $results
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'results' => []
        ]);
    }
    exit;
});

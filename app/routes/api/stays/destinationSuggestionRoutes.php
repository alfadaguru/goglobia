<?php
// ============================================================================
// FILE: app/routes/api/stays/destination-suggestion.php
// ============================================================================

// ========================================================= /api/stays/destination-suggestion
// Searches both locations (cities) AND hotels (stays table)
$router->post('/api/stays/destination-suggestion', function () use ($SECURE, $db) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $query = isset($input['query']) ? trim($input['query']) : '';

        if (empty($query) || strlen($query) < 2) {
            echo json_encode(['success' => false, 'message' => 'Query must be at least 2 characters', 'data' => []]);
            exit(0);
        }

        $searchQuery = '%' . $query . '%';
        $hotelResults = [];
        $locationResults = [];

        // ============================================================================
        // SEARCH 1: HOTELS FROM STAYS TABLE (shown first)
        // ============================================================================
        try {
            // Query stays table for hotels matching search by name only
            $hotels = $db->select(
                'stays',
                ['id', 'name', 'location'],
                [
                    'name[~]' => $searchQuery,
                    'ORDER' => ['name' => 'ASC'],
                    'LIMIT' => 10
                ]
            );

            if ($hotels && is_array($hotels)) {
                foreach ($hotels as $hotel) {
                    $hotelResults[] = [
                        'id' => $hotel['id'],
                        'type' => 'hotel',
                        'lc' => 'hotel',
                        'name' => $hotel['name'],
                        'airportname' => $hotel['name'],
                        'cityname' => $hotel['location'] ?? '',
                        'countryname' => '',
                        'supplier' => 'hotels',
                        'is_hotel' => true
                    ];
                }
            }
        } catch (Exception $e) {
            error_log('Hotels search error: ' . $e->getMessage());
        }

        // ============================================================================
        // SEARCH 2: LOCATIONS (CITIES) FROM LOCATIONS TABLE (shown after hotels)
        // ============================================================================
        try {
            $locations = $db->select(
                'locations',
                ['id', 'country', 'country_code', 'city', 'latitude', 'longitude'],
                [
                    'OR' => [
                        'city[~]' => $searchQuery,
                        'country[~]' => $searchQuery
                    ],
                    'status' => 1,
                    'ORDER' => [
                        'country' => 'ASC',
                        'city' => 'ASC'
                    ],
                    'LIMIT' => 10
                ]
            );

            if ($locations) {
                foreach ($locations as $location) {
                    $locationResults[] = [
                        'id' => $location['id'],
                        'type' => 'location',
                        'lc' => 'city',
                        'name' => $location['city'],
                        'airportname' => $location['city'],
                        'cityname' => $location['city'],
                        'countryname' => $location['country'],
                        'countrycode' => $location['country_code'],
                        'is_hotel' => false
                    ];
                }
            }
        } catch (Exception $e) {
            error_log('Locations search error: ' . $e->getMessage());
        }

        // ============================================================================
        // MERGE RESULTS: HOTELS FIRST, THEN CITIES
        // ============================================================================
        $formattedResults = array_merge($hotelResults, $locationResults);

        // ============================================================================
        // WEBHOOK: Destination Searched
        // ============================================================================
        triggerWebhook('stays/destination-suggestion', 'stays.destination.searched', [
            'query' => $query,
            'results_count' => count($formattedResults),
            'hotels_found' => count($hotelResults),
            'cities_found' => count($locationResults),
            'timestamp' => date('Y-m-d H:i:s'),
            'user_id' => $_SESSION['user_id'] ?? null,
            'session_id' => session_id()
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Destination suggestions fetched successfully',
            'data' => $formattedResults
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Internal server error', 'error' => $e->getMessage()]);
    }
});
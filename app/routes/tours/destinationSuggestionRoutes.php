<?php
// ============================================================================
// FILE: app/routes/tours/destination-suggestion.php
// ============================================================================

// API endpoint for tour destination suggestions
$router->post('/tours-destination-suggestion', function () use ($SECURE, $db) {
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    $query = isset($_POST['query']) ? trim($_POST['query']) : '';

    if (empty($query) || strlen($query) < 2) {
        echo json_encode([]);
        exit(0);
    }

    // Search query for database
    $searchQuery = '%' . $query . '%';

    $tourResults = [];
    $locationResults = [];

    // ============================================================================
    // SEARCH 1: LOCATIONS (CITIES)
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

        if ($locations && is_array($locations)) {
            foreach ($locations as $location) {
                $locationResults[] = [
                    'id' => $location['id'],
                    'type' => 'location',
                    'lc' => 'city',
                    'name' => $location['city'],
                    'airportname' => $location['city'],
                    'cityname' => $location['city'],
                    'country' => $location['country'],
                    'countryname' => $location['country'],
                    'countrycode' => $location['country_code'],
                    'is_tour' => false
                ];
            }
        }
    } catch (Exception $e) {
        error_log('Locations search error: ' . $e->getMessage());
    }

    // ============================================================================
    // SEARCH 2: TOURS FROM TOURS TABLE (local tours)
    // ============================================================================
    try {
        $tours = $db->select(
            'tours',
            ['id', 'name', 'location', 'img'],
            [
                'AND' => [
                    'name[~]' => $searchQuery,
                    'status' => 1
                ],
                'ORDER' => ['name' => 'ASC'],
                'LIMIT' => 10
            ]
        );

        if ($tours && is_array($tours)) {
            foreach ($tours as $tour) {
                // Extract first image if exists
                $tourImage = '';
                if (!empty($tour['img'])) {
                    $imgArr = json_decode($tour['img'], true);
                    if (is_array($imgArr) && !empty($imgArr[0]['url'])) {
                        $tourImage = dirname(root) . $imgArr[0]['url'];
                    }
                }
                $tourResults[] = [
                    'id' => $tour['id'],
                    'type' => 'tour',
                    'lc' => 'tour',
                    'name' => $tour['name'],
                    'airportname' => $tour['name'],
                    'cityname' => $tour['location'] ?? '',
                    'country' => '',
                    'countryname' => '',
                    'supplier' => 'tours',
                    'is_tour' => true,
                    'image' => $tourImage
                ];
            }
        }
    } catch (Exception $e) {
        error_log('Local tours search error: ' . $e->getMessage());
    }

    // Merge: Cities first, then Tours
    $formattedResults = array_merge($locationResults, $tourResults);

    echo json_encode($formattedResults);
    exit(0);
});

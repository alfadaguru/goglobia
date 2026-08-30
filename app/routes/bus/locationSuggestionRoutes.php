<?php
// ============================================================================
// FILE: app/routes/bus/locationSuggestionRoutes.php
// BUS LOCATION AUTOCOMPLETE — returns distinct origin/destination cities from
// local bus_routes (active). Later, live API suppliers can contribute too.
// ============================================================================

$router->post('/bus-location-suggestion', function () use ($SECURE, $db) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    // VALIDATE QUERY (MIN 3 CHARS)
    $query = isset($_POST['query']) ? trim((string)$_POST['query']) : '';
    if (mb_strlen($query) < 3) {
        echo json_encode([]);
        exit(0);
    }

    try {
        // CITIES FROM THE locations TABLE (city / country match), ACTIVE ONLY
        $rows = $db->select('locations', ['city', 'country', 'country_code'], [
            'status'   => '1',
            'OR'       => [
                'city[~]'    => $query,
                'country[~]' => $query,
            ],
            'ORDER'    => ['city' => 'ASC'],
            'LIMIT'    => 30,
        ]);

        $seen = [];
        $out = [];
        foreach ((is_array($rows) ? $rows : []) as $r) {
            $city    = trim((string)($r['city'] ?? ''));
            $country = trim((string)($r['country'] ?? ''));
            if ($city === '') {
                continue;
            }
            $key = mb_strtolower($city . '|' . $country);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'name'    => $country !== '' ? "{$city}, {$country}" : $city,
                'city'    => $city,
                'country' => $country,
                'code'    => (string)($r['country_code'] ?? ''),
            ];
            if (count($out) >= 15) {
                break;
            }
        }

        echo json_encode($out);
        exit(0);
    } catch (\Throwable $e) {
        error_log('BUS_LOCATION_SUGGESTION ERROR: ' . $e->getMessage());
        echo json_encode([]);
        exit(0);
    }
});

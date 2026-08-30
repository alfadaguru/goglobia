<?php
// ============================================================================
// FILE: app/routes/umrah/destinationSuggestionRoutes.php
// ============================================================================

if (!function_exists('normalizeUmrahSaudiCity')) {
    function normalizeUmrahSaudiCity(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $parts = preg_split('/,/', $value);
        $value = trim($parts[0] ?? $value);
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9\s]/', '', $value);
        $value = preg_replace('/\s+/', ' ', $value);

        $aliases = [
            'makka' => 'makkah',
            'makkah' => 'makkah',
            'mecca' => 'makkah',
            'makkah al mukarramah' => 'makkah',
            'makkah almukarramah' => 'makkah',
            'madina' => 'madinah',
            'madinah' => 'madinah',
            'medina' => 'madinah',
            'al madinah' => 'madinah',
            'al madinah al munawwarah' => 'madinah',
            'riyad' => 'riyadh',
        ];

        return $aliases[$value] ?? $value;
    }
}

$router->post('/umrah-destination-suggestion', function () use ($SECURE, $db) {
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    $query = trim($_POST['query'] ?? '');
    if ($query === '' || strlen($query) < 2) {
        echo json_encode([]);
        exit(0);
    }

    $saudiLocations = $db->select(
        'locations',
        ['id', 'city', 'country', 'country_code'],
        [
            'country_code' => 'SA',
            'status' => 1,
            'ORDER' => ['city' => 'ASC']
        ]
    );

    $saudiCityMap = [];
    foreach ($saudiLocations as $location) {
        $normalized = normalizeUmrahSaudiCity((string)($location['city'] ?? ''));
        if ($normalized === '') {
            continue;
        }

        $saudiCityMap[$normalized] = [
            'id' => $location['id'],
            'type' => 'location',
            'lc' => 'city',
            'name' => $location['city'],
            'airportname' => $location['city'],
            'cityname' => $location['city'],
            'countryname' => $location['country'] ?? 'Saudi Arabia',
            'countrycode' => $location['country_code'] ?? 'SA',
        ];
    }

    $normalizedQuery = normalizeUmrahSaudiCity($query);
    $results = [];

    foreach ($saudiCityMap as $key => $destination) {
        $country = strtolower((string)($destination['countryname'] ?? ''));
        if (
            str_contains($key, $normalizedQuery) ||
            ($country !== '' && str_contains($country, $normalizedQuery))
        ) {
            $results[] = $destination;
        }
    }

    usort($results, static function ($a, $b) {
        return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    echo json_encode(array_slice($results, 0, 20));
    exit(0);
});

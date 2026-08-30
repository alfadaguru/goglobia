<?php
@$SECURE or die('Access Denied!');

$router->post('/api/tours/destination-suggestion', function () use ($db) {

    header('Content-Type: application/json');

    $query = trim($_POST['query'] ?? '');

    if (strlen($query) < 2) {
        echo json_encode([]);
        exit;
    }

    $locations = $db->select('locations', [
        'id','country','country_code','city'
    ], [
        'OR' => [
            'city[~]' => "%$query%",
            'country[~]' => "%$query%"
        ],
        'status' => 1,
        'LIMIT' => 20
    ]);

    $result = [];

    foreach ($locations as $loc) {
        $result[] = [
            'id' => $loc['id'],
            'name' => $loc['city'],
            'cityname' => $loc['city'],
            'countryname' => $loc['country'],
            'countrycode' => $loc['country_code']
        ];
    }

    echo json_encode($result);
    exit;
});

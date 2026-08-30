<?php
@$SECURE or die('Access Denied!');


// ====================================
// TOURS LISTING / SEARCH API
// GET /api/tours/search
// ====================================
$router->get('/api/tours', function () use ($SECURE, $db) {

    header('Content-Type: application/json');

    // ================= GET SEARCH PARAMETERS =================
    $destination = $_GET['destination'] ?? null;
    $start_date  = $_GET['start_date'] ?? null;
    $end_date    = $_GET['end_date'] ?? null;
    $adults      = (int)($_GET['adults'] ?? 1);
    $children    = (int)($_GET['children'] ?? 0);
    $category    = $_GET['category'] ?? null;

    // ================= TOUR TYPE MAP =================
    $tourTypeMap = [];
    $tourTypes = $db->select('tours_settings', '*', [
        'setting_type' => 'tour_type',
        'status' => 1
    ]);

    foreach ($tourTypes as $type) {
        $tourTypeMap[$type['id']] = $type['setting_label'];
    }

    // ================= BUILD FILTER =================
    $where = [
        'status' => 1
    ];

    if (!empty($destination)) {
        $where['location[~]'] = $destination;
    }

    if (!empty($category)) {
        $where['tour_type_id'] = (int)$category;
    }

    if ($adults > 0) {
        $where['max_adults[>=]'] = $adults;
    }

    if ($children > 0) {
        $where['max_children[>=]'] = $children;
    }

    // ================= MODULE CONFIG & CURRENCY =================
    $module = $db->get('modules', '*', [
        'type' => 'tours',
        'status' => 1
    ]);
    $selectedCurrency = $db->get('currencies', ['name', 'rate'], ['default' => '1']);
    $targetCurrency = strtoupper($_GET['currency'] ?? $_SESSION['app_currency'] ?? $selectedCurrency['name'] ?? 'USD');

    // ================= FETCH TOURS =================
    $rows = $db->select('tours', '*', $where);

    $tours = [];

    foreach ($rows as $tour) {
        $tourCurrency = strtoupper((string)($tour['currency'] ?? 'USD'));
        $adultBase = (float)($tour['adult_price'] ?? 0);
        $childBase = (float)($tour['child_price'] ?? 0);

        $markedAdult = MARKUP($adultBase, $module ?: [], $db, $tourCurrency, $targetCurrency);
        $markedChild = MARKUP($childBase, $module ?: [], $db, $tourCurrency, $targetCurrency);

        $tours[] = [
            'id' => $tour['id'],
            'name' => $tour['name'],
            'slug' => $tour['slug'],
            'location' => $tour['location'],
            'days' => $tour['days'],
            'nights' => $tour['nights'],
            'currency' => $targetCurrency,
            'original_currency' => $tourCurrency,
            'adult_price' => round((float)($markedAdult['price'] ?? 0), 2),
            'adult_price_actual' => round((float)($markedAdult['converted_base_price'] ?? 0), 2),
            'adult_price_markup' => round((float)($markedAdult['markup'] ?? 0), 2),
            'child_price' => round((float)($markedChild['price'] ?? 0), 2),
            'child_price_actual' => round((float)($markedChild['converted_base_price'] ?? 0), 2),
            'child_price_markup' => round((float)($markedChild['markup'] ?? 0), 2),
            'discount_percentage' => $tour['discount_percentage'],
            'rating' => $tour['rating_average'],
            'tour_type' => $tourTypeMap[$tour['tour_type_id']] ?? null
        ];
    }

    // ================= RESPONSE =================
    $response = [
        'status'  => 'success',
        'message' => 'Tour search completed successfully.',
        'data'    => [
            'search_params' => [
                'destination' => $destination,
                'start_date'  => $start_date,
                'end_date'    => $end_date,
                'adults'      => $adults,
                'children'    => $children,
                'category'    => $category
            ],
            'tours' => $tours,
            'total_results' => count($tours)
        ]
    ];

    echo json_encode($response);
});

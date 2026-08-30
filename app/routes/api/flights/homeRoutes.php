<?php
@$SECURE or die('Access Denied!');

if (!function_exists('applyFlightMarkup')) {
    function applyFlightMarkup(&$flight, $db, $targetCurrency = 'USD') {
        if (!$flight) return;

        // Get flights module configuration
        $module = $db->get('modules', '*', [
            'name' => 'flights',
            'type' => 'flights'
        ]);

        if (!function_exists('MARKUP')) {
            require_once dirname(__DIR__, 4) . '/modules/helpers.php';
        }

        $flightCurrency = !empty($flight['currency']) ? $flight['currency'] : 'USD';
        
        $priceFields = [
            'economy_adult_price', 'economy_child_price', 'economy_infant_price',
            'premium_economy_adult_price', 'premium_economy_child_price', 'premium_economy_infant_price',
            'business_adult_price', 'business_child_price', 'business_infant_price',
            'first_adult_price', 'first_child_price', 'first_infant_price'
        ];

        foreach ($priceFields as $field) {
            $basePrice = floatval($flight[$field] ?? 0);
            if ($basePrice > 0) {
                // Apply markup and convert to target currency
                $markedUp = MARKUP($basePrice, $module, $db, $flightCurrency, $targetCurrency);
                // Also get the actual base price converted without markup
                $convertedBase = CURRENCY_CONVERT($basePrice, $db, $flightCurrency, $targetCurrency);
                
                // We update the price to be the marked up price in the target currency
                $flight[$field] = number_format($markedUp['price'], 2, '.', '');
                // Attach actual (base) price and markup amount
                $flight[$field . '_actual'] = number_format($convertedBase['price'], 2, '.', '');
                $flight[$field . '_markup'] = number_format($markedUp['markup'], 2, '.', '');
            } else {
                $flight[$field] = "0.00";
                $flight[$field . '_actual'] = "0.00";
                $flight[$field . '_markup'] = "0.00";
            }
        }
        
        // Update flight currency field to show target currency
        $flight['currency'] = $targetCurrency;
    }
}

// ================= SINGLE FLIGHT =================
$router->get('/api/flights/([0-9]+)', function ($id) use ($SECURE, $db) {
    header('Content-Type: application/json');

    $flight = $db->get('flights', '*', ['id' => $id]);

    if (!$flight) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Flight not found'
        ]);
        exit;
    }

    $targetCurrency = strtoupper($_GET['currency'] ?? 'USD');
    applyFlightMarkup($flight, $db, $targetCurrency);

    echo json_encode([
        'success' => true,
        'flight' => $flight
    ]);
});

// ================= FLIGHT SEARCH =================
$router->get('/api/flights/search', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    $fromAirport = $_GET['from_airport_id'] ?? '';
    $toAirport   = $_GET['to_airport_id'] ?? '';
    $date        = $_GET['departure_date'] ?? '';

    if (!$fromAirport || !$toAirport || !$date) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'from_airport_id, to_airport_id and departure_date are required'
        ]);
        exit;
    }

    $flights = $db->select('flights', '*', [
        'from_airport_id' => $fromAirport,
        'to_airport_id'   => $toAirport,
        'departure_date'  => $date,
        'ORDER' => ['departure_time' => 'ASC']
    ]);

    $targetCurrency = strtoupper($_GET['currency'] ?? 'USD');
    foreach ($flights as &$flight) {
        applyFlightMarkup($flight, $db, $targetCurrency);
    }
    unset($flight);

    echo json_encode([
        'success' => true,
        'total' => count($flights),
        'flights' => $flights
    ]);
});

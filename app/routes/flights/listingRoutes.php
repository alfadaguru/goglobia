<?php
// ============================================================================
// FILE: app/routes/flights/listing.php
// ============================================================================

// ===================== FLIGHTS SEARCH WITH PARAMS (MOST SPECIFIC) =======================
// Pattern: /flights/{FROM}/{TO}/{TYPE}/{CLASS}/{DATE}/{ADDITIONAL}
// Using ([^/]+) to match everything except slashes (stops at next /)
// ===================== FLIGHTS MULTI-CITY SEARCH =======================
// Pattern: /flights/multicity/{CLASS}/{ROUTES_SLICES}/{ADULTS}/{CHILDREN}/{INFANTS}
$router->get('/flights/multicity/(economy|premium_economy|business|first)/(.*)', function ($class, $additional) use ($SECURE, $db) {
    $parts = explode('/', trim($additional, '/'));
    $count = count($parts);
    if ($count < 4) {
        die("Invalid multicity route structure");
    }

    $infants = intval($parts[$count - 1]);
    $children = intval($parts[$count - 2]);
    $adults = intval($parts[$count - 3]);

    $routesParts = array_slice($parts, 0, $count - 3);

    $parsedRoutes = [];
    foreach ($routesParts as $r) {
        $rParts = explode('-', $r);
        if (count($rParts) >= 3) {
            $from = strtoupper($rParts[0]);
            $to = strtoupper($rParts[1]);
            $date = implode('-', array_slice($rParts, 2));
            $parsedRoutes[] = [
                'from' => $from,
                'to' => $to,
                'date' => $date
            ];
        }
    }

    if (empty($parsedRoutes)) {
        die("No valid multicity routes found in path");
    }

    // Set common session values
    $_SESSION['flight_type'] = 'multicity';
    $_SESSION['class'] = $class;
    $_SESSION['adults'] = $adults;
    $_SESSION['children'] = $children;
    $_SESSION['infants'] = $infants;
    $_SESSION['multicity_routes'] = $parsedRoutes;

    // Fallbacks for legacy/general compatibility
    $_SESSION['from_airport'] = $parsedRoutes[0]['from'];
    $_SESSION['to_airport'] = $parsedRoutes[0]['to'];
    $_SESSION['flights_departure_date'] = $parsedRoutes[0]['date'];
    $_SESSION['flights_return_date'] = '';

    // Trigger search webhook with first & last airport code
    $from = $parsedRoutes[0]['from'];
    $to = end($parsedRoutes)['to'];

    triggerWebhook('flights/search', 'flights.search.initiated', [
        'from' => $from,
        'to' => $to,
        'type' => 'multicity',
        'class' => $class,
        'departure_date' => $parsedRoutes[0]['date'],
        'adults' => $adults,
        'children' => $children,
        'infants' => $infants,
        'user_id' => $_SESSION['user_data']['id'] ?? null,
        'timestamp' => date('Y-m-d H:i:s'),
        'session_id' => session_id()
    ]);

    // META DATA
    $title = $GLOBALS['app']['home_title'];
    $description = $GLOBALS['app']['meta_description'];
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once views."modules/flights/listing/flights.php";
    require_once views."includes/footer.php";
});

$router->get('/flights/([^/]+)/([^/]+)/(oneway|roundtrip|multicity)/(economy|premium_economy|business|first)/([^/]+)/(.*)', function ($from, $to, $type, $class, $departure, $additional) use ($SECURE,$db) {

// Convert to uppercase for airport codes
$from = strtoupper(trim($from));
$to = strtoupper(trim($to));

// Parse additional params
$additionalParts = explode('/', trim($additional, '/'));

// Set common session values
$_SESSION['from_airport'] = $from;
$_SESSION['to_airport'] = $to;
$_SESSION['flight_type'] = $type;
$_SESSION['class'] = $class;

// Set session based on flight type
if ($type === 'oneway') {
    // Oneway URL structure: /FROM/TO/oneway/CLASS/DEPARTURE_DATE/ADULTS/CHILDREN/INFANTS
    $_SESSION['flights_departure_date'] = $departure;
    $_SESSION['flights_return_date'] = ''; // Clear return date for oneway
    $_SESSION['adults'] = isset($additionalParts[0]) && $additionalParts[0] !== '' ? intval($additionalParts[0]) : 1;
    $_SESSION['children'] = isset($additionalParts[1]) && $additionalParts[1] !== '' ? intval($additionalParts[1]) : 0;
    $_SESSION['infants'] = isset($additionalParts[2]) && $additionalParts[2] !== '' ? intval($additionalParts[2]) : 0;
} elseif ($type === 'roundtrip') {
    // Roundtrip URL structure: /FROM/TO/roundtrip/CLASS/DEPARTURE_DATE/RETURN_DATE/ADULTS/CHILDREN/INFANTS
    $_SESSION['flights_departure_date'] = $departure;
    $_SESSION['flights_return_date'] = isset($additionalParts[0]) && $additionalParts[0] !== '' ? $additionalParts[0] : '';
    $_SESSION['adults'] = isset($additionalParts[1]) && $additionalParts[1] !== '' ? intval($additionalParts[1]) : 1;
    $_SESSION['children'] = isset($additionalParts[2]) && $additionalParts[2] !== '' ? intval($additionalParts[2]) : 0;
    $_SESSION['infants'] = isset($additionalParts[3]) && $additionalParts[3] !== '' ? intval($additionalParts[3]) : 0;
} elseif ($type === 'multicity') {
    // Multicity URL structure: /FROM/TO/multicity/CLASS/DEPARTURE_DATE/ADULTS/CHILDREN/INFANTS
    // Note: Multi-city will have its own route handler with different structure
    $_SESSION['flights_departure_date'] = $departure;
    $_SESSION['flights_return_date'] = '';
    $_SESSION['adults'] = isset($additionalParts[0]) && $additionalParts[0] !== '' ? intval($additionalParts[0]) : 1;
    $_SESSION['children'] = isset($additionalParts[1]) && $additionalParts[1] !== '' ? intval($additionalParts[1]) : 0;
    $_SESSION['infants'] = isset($additionalParts[2]) && $additionalParts[2] !== '' ? intval($additionalParts[2]) : 0;
}

// Trigger search webhook
triggerWebhook('flights/search', 'flights.search.initiated', [
    'from' => $from,
    'to' => $to,
    'type' => $type,
    'class' => $class,
    'departure_date' => $departure,
    'adults' => $_SESSION['adults'] ?? 1,
    'children' => $_SESSION['children'] ?? 0,
    'infants' => $_SESSION['infants'] ?? 0,
    'user_id' => $_SESSION['user_data']['id'] ?? null,
    'timestamp' => date('Y-m-d H:i:s'),
    'session_id' => session_id()
]);

// META DATA
$title = $GLOBALS['app']['home_title'];
$description = $GLOBALS['app']['meta_description'];
$header = true;
$footer = true;

require_once views."includes/header.php";
require_once views."modules/flights/listing/flights.php";
require_once views."includes/footer.php";

});

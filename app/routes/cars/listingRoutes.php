<?php
// ============================================================================
// FILE: app/routes/cars/listingRoutes.php
// CARS LISTING/SEARCH ROUTES - SEO-FRIENDLY URL STRUCTURE
// ============================================================================

@$SECURE or die('Access Denied!');

// ============================================================================
// LOAD CAR SEARCH MODULE ROUTES
// ============================================================================
$cars_search_modules = glob("modules/cars/*/search.php");
if (!empty($cars_search_modules)) {
    foreach ($cars_search_modules as $module) {
        if (file_exists($module)) {
            require_once $module;
        }
    }
}

// ============================================================================
// CARS RENTAL LISTING ROUTE
// Format: /cars/rental/{pickup}/{dropoff}/{pickup_date}/{return_date}/{pickup_time}/{return_time}/{driver_age}
// Example: /cars/rental/dubai/dubai/15-02-2026/19-02-2026/10:00/10:00/30
// ============================================================================
$router->get('/cars/rental/(.*)', function ($params) use ($SECURE, $db) {
    
    // ============================================================================
    // EXTRACT AND PARSE URL PARAMETERS
    // ============================================================================
    if (!empty($params)) {
        $params = urldecode($params);
        $urlParts = explode('/', trim($params, '/'));
        
        if (count($urlParts) >= 4) {
            // ============================================================================
            // PARSE RENTAL PARAMETERS
            // Format: {pickup}/{dropoff}/{pickup_date}/{return_date}/{pickup_time}/{return_time}/{driver_age}
            // ============================================================================
            $pickup_location = str_replace('-', ' ', $urlParts[0] ?? '');
            $dropoff_location = str_replace('-', ' ', $urlParts[1] ?? '');
            $pickup_date = $urlParts[2] ?? '';
            $return_date = $urlParts[3] ?? '';
            
            // Detect if time params are present (HH:MM format)
            $pickup_time = '10:00';
            $return_time = '10:00';
            $driver_age = '30';
            
            if (isset($urlParts[4]) && preg_match('/^\d{1,2}:\d{2}$/', $urlParts[4])) {
                $pickup_time = $urlParts[4];
                if (isset($urlParts[5]) && preg_match('/^\d{1,2}:\d{2}$/', $urlParts[5])) {
                    $return_time = $urlParts[5];
                }
                $driver_age = $urlParts[6] ?? '30';
            } else {
                $driver_age = $urlParts[4] ?? '30';
            }
            
            // ============================================================================
            // SAVE TO SESSION FOR PERSISTENCE
            // ============================================================================
            $_SESSION['car_service_type'] = 'rental';
            $_SESSION['car_pickup_location'] = ucwords($pickup_location);
            $_SESSION['car_dropoff_location'] = ucwords($dropoff_location);
            $_SESSION['cars_pickup_date'] = $pickup_date;
            $_SESSION['cars_return_date'] = $return_date;
            $_SESSION['cars_pickup_time'] = $pickup_time;
            $_SESSION['cars_return_time'] = $return_time;
            $_SESSION['driver_age'] = $driver_age;
            unset($_SESSION['cars_hourly_duration']);
            
            // ============================================================================
            // WEBHOOK: RENTAL SEARCH INITIATED
            // ============================================================================
            triggerWebhook('cars/search', 'cars.rental.search.initiated', [
                'service_type' => 'rental',
                'pickup_location' => $_SESSION['car_pickup_location'],
                'dropoff_location' => $_SESSION['car_dropoff_location'],
                'pickup_date' => $pickup_date,
                'return_date' => $return_date,
                'pickup_time' => $pickup_time,
                'return_time' => $return_time,
                'driver_age' => $driver_age,
                'timestamp' => date('Y-m-d H:i:s'),
                'user_id' => $_SESSION['user_id'] ?? null,
                'session_id' => session_id(),
                'search_source' => 'rental_listing'
            ]);
        }
    }
    
    // ============================================================================
    // META DATA
    // ============================================================================
    $title = T::search.' '.T::car.' '.T::rental.' - '.$GLOBALS['app']['home_title'];
    $description = "Find the best car rental deals";
    
    require_once views."includes/header.php";
    require_once views."modules/cars/listing/cars.php";
    require_once views."includes/footer.php";
});

// ============================================================================
// CARS TRANSFER LISTING ROUTE
// Format: /cars/transfer/{pickup}/{dropoff}/{pickup_date}/{return_date}/{pickup_time}/{return_time}/{travellers}/{driver_age}
// Example: /cars/transfer/dubai-airport/downtown-dubai/15-02-2026/15-02-2026/10:00/10:00/4/30
// ============================================================================
$router->get('/cars/transfer/(.*)', function ($params) use ($SECURE, $db) {
    
    // ============================================================================
    // EXTRACT AND PARSE URL PARAMETERS
    // ============================================================================
    if (!empty($params)) {
        $params = urldecode($params);
        $urlParts = explode('/', trim($params, '/'));
        
        if (count($urlParts) >= 3) {
            // ============================================================================
            // PARSE TRANSFER PARAMETERS
            // Format: {pickup}/{dropoff}/{pickup_date}/{return_date}/{pickup_time}/{return_time}/{travellers}/{driver_age}
            // ============================================================================
            $pickup_location = str_replace('-', ' ', $urlParts[0] ?? '');
            $dropoff_location = str_replace('-', ' ', $urlParts[1] ?? '');
            $pickup_date = $urlParts[2] ?? '';
            // Empty/missing segment = one-way (default). Only an explicit
            // return date (round-trip toggle on in the search widget) sets it.
            $return_date = $urlParts[3] ?? '';

            // Detect if time params are present (HH:MM format)
            $pickup_time = '10:00';
            $return_time = '10:00';
            $travellers = '2';
            $driver_age = '30';
            
            if (isset($urlParts[4]) && preg_match('/^\d{1,2}:\d{2}$/', $urlParts[4])) {
                $pickup_time = $urlParts[4];
                $return_time = (isset($urlParts[5]) && preg_match('/^\d{1,2}:\d{2}$/', $urlParts[5])) ? $urlParts[5] : '10:00';
                $travellers = $urlParts[6] ?? '2';
                $driver_age = $urlParts[7] ?? '30';
            } else {
                $travellers = $urlParts[4] ?? '2';
                $driver_age = $urlParts[5] ?? '30';
            }
            
            // ============================================================================
            // SAVE TO SESSION FOR PERSISTENCE
            // ============================================================================
            $_SESSION['car_service_type'] = 'transfer';
            $_SESSION['car_pickup_location'] = ucwords($pickup_location);
            $_SESSION['car_dropoff_location'] = ucwords($dropoff_location);
            $_SESSION['cars_pickup_date'] = $pickup_date;
            $_SESSION['cars_return_date'] = $return_date;
            $_SESSION['cars_pickup_time'] = $pickup_time;
            $_SESSION['cars_return_time'] = $return_time;
            $_SESSION['cars_trip_type'] = !empty($return_date) ? 'round_trip' : 'one_way';
            $_SESSION['driver_age'] = $driver_age;
            $_SESSION['transfer_travellers'] = $travellers;
            unset($_SESSION['cars_hourly_duration']);
            
            // ============================================================================
            // WEBHOOK: TRANSFER SEARCH INITIATED
            // ============================================================================
            triggerWebhook('cars/search', 'cars.transfer.search.initiated', [
                'service_type' => 'transfer',
                'pickup_location' => $_SESSION['car_pickup_location'],
                'dropoff_location' => $_SESSION['car_dropoff_location'],
                'pickup_date' => $pickup_date,
                'return_date' => $return_date,
                'pickup_time' => $pickup_time,
                'return_time' => $return_time,
                'travellers' => $travellers,
                'driver_age' => $driver_age,
                'timestamp' => date('Y-m-d H:i:s'),
                'user_id' => $_SESSION['user_id'] ?? null,
                'session_id' => session_id(),
                'search_source' => 'transfer_listing'
            ]);
        }
    }
    
    // ============================================================================
    // META DATA
    // ============================================================================
    $title = T::search.' '.T::car.' '.T::airport.' '.T::transfer.' - '.$GLOBALS['app']['home_title'];
    $description = "Book reliable airport transfers";

    require_once views."includes/header.php";
    require_once views."modules/cars/listing/cars.php";
    require_once views."includes/footer.php";
});

// ============================================================================
// CARS HOURLY (CHAUFFEUR BY THE HOUR) LISTING ROUTE
// Format: /cars/hourly/{pickup}/{pickup_date}/{pickup_time}/{duration}/{travellers}
// Example: /cars/hourly/dubai-airport/15-02-2026/10:00/2/4
// No dropoff location — a single pickup point + a duration in hours.
// ============================================================================
$router->get('/cars/hourly/(.*)', function ($params) use ($SECURE, $db) {

    if (!empty($params)) {
        $params = urldecode($params);
        $urlParts = explode('/', trim($params, '/'));

        if (count($urlParts) >= 2) {
            // Format: {pickup}/{pickup_date}/{pickup_time}/{duration}/{travellers}
            $pickup_location = str_replace('-', ' ', $urlParts[0] ?? '');
            $pickup_date     = $urlParts[1] ?? '';

            $pickup_time = '10:00';
            $duration    = '2';
            $travellers  = '2';

            if (isset($urlParts[2]) && preg_match('/^\d{1,2}:\d{2}$/', $urlParts[2])) {
                $pickup_time = $urlParts[2];
                $duration    = $urlParts[3] ?? '2';
                $travellers  = $urlParts[4] ?? '2';
            } else {
                $duration   = $urlParts[2] ?? '2';
                $travellers = $urlParts[3] ?? '2';
            }

            $duration = max(1, min(12, (int)$duration));

            // ============================================================================
            // SAVE TO SESSION FOR PERSISTENCE
            // ============================================================================
            $_SESSION['car_service_type']       = 'hourly';
            $_SESSION['car_pickup_location']    = ucwords($pickup_location);
            $_SESSION['car_dropoff_location']   = '';
            $_SESSION['cars_pickup_date']       = $pickup_date;
            $_SESSION['cars_return_date']       = '';
            $_SESSION['cars_pickup_time']       = $pickup_time;
            $_SESSION['cars_return_time']       = '';
            $_SESSION['cars_hourly_duration']   = $duration;
            $_SESSION['transfer_travellers']    = $travellers;

            // ============================================================================
            // WEBHOOK: HOURLY SEARCH INITIATED
            // ============================================================================
            triggerWebhook('cars/search', 'cars.hourly.search.initiated', [
                'service_type' => 'hourly',
                'pickup_location' => $_SESSION['car_pickup_location'],
                'pickup_date' => $pickup_date,
                'pickup_time' => $pickup_time,
                'hourly_duration' => $duration,
                'travellers' => $travellers,
                'timestamp' => date('Y-m-d H:i:s'),
                'user_id' => $_SESSION['user_id'] ?? null,
                'session_id' => session_id(),
                'search_source' => 'hourly_listing'
            ]);
        }
    }

    // ============================================================================
    // META DATA
    // ============================================================================
    $title = T::search.' '.T::car.' '.($GLOBALS['app']['home_title'] ?? '');
    $description = "Book a car and driver by the hour";

    require_once views."includes/header.php";
    require_once views."modules/cars/listing/cars.php";
    require_once views."includes/footer.php";
});

<?php
// ============================================================================
// FILE: app/routes/umrah/listingRoutes.php
// ============================================================================
@$SECURE or die('Access Denied!');

// ============================================================================
// WEB AJAX — package cards for AI Trip (and similar).
// POST /api/umrah/listing  (CSRF required)
// NOT mobile app/routes/api/umrah/
// ============================================================================
$router->post('/api/umrah/listing', function () use ($SECURE, $db) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    try {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw ?: '', true);
        if (!is_array($input) || $input === []) {
            $input = $_POST;
        }
        if (!is_array($input)) {
            $input = [];
        }

        $csrf = (string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        if (!class_exists('CSRF') || !CSRF::validateToken($csrf)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }

        // Card-building logic lives in a reusable helper so GIA's search tool can
        // obtain the exact same real, priced cards without an internal HTTP call.
        require_once dirname(__DIR__, 2) . '/lib/umrah/listing.php';
        $built = umrahListingCards($input, $db);
        $cards = $built['cards'];

        echo json_encode([
            'success' => true,
            'message' => count($cards) ? 'Umrah packages ready.' : 'No Umrah packages match this search.',
            'data' => ['cards' => $cards],
        ]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});

// ============================================================================
// UMRAH LISTING ROUTE
// SEO-Friendly URL Format: /umrah/{destination}/{start_date}/{duration}/{services}/{umrah_type}
// Example: /umrah/makkah/15-12-2025/4-7/2,1/any
// ============================================================================
$router->get('/umrah/(.*)', function ($params) use ($SECURE,$db) {

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!empty($params) && $params !== 'listing') {
        $urlParts = explode('/', trim($params, '/'));

        if (count($urlParts) >= 5) {
            $destination = str_replace('-', ' ', $urlParts[0] ?? 'any');
            $startDate = $urlParts[1] ?? '';
            $duration = $urlParts[2] ?? 'any';
            $services = $urlParts[3] ?? 'any';
            $umrahType = str_replace('-', ' ', $urlParts[4] ?? 'any');

            $adults = 1;
            $children = 0;
            $totalTravelers = 1;

            if ($destination === 'any') {
                $_SESSION['umrah_destination'] = '';
                $_SESSION['umrah_destination_code'] = '';
            } else {
                $cleanDestination = preg_replace('/-+/', ' ', $destination);
                $cleanDestination = trim($cleanDestination);
                $_SESSION['umrah_destination_code'] = '';
                $_SESSION['umrah_destination'] = ucwords($cleanDestination);
            }

            $_SESSION['umrah_origin'] = $_SESSION['umrah_destination'];
            $_SESSION['umrah_origin_code'] = $_SESSION['umrah_destination_code'];

            $_SESSION['umrah_start_date'] = ($startDate === 'any') ? '' : $startDate;
            $_SESSION['umrah_duration'] = ($duration === 'any') ? '' : $duration;
            $_SESSION['umrah_services'] = ($services === 'any') ? '' : $services;
            $_SESSION['umrah_type'] = ($umrahType === 'any') ? '' : $umrahType;

            $_SESSION['umrah_adults'] = $adults;
            $_SESSION['umrah_children'] = $children;
            $_SESSION['umrah_travelers'] = $totalTravelers;

            $serviceList = explode(',', $services);
            $_SESSION['umrah_service_flights'] = in_array('2', $serviceList) || in_array('flights', $serviceList);
            $_SESSION['umrah_service_cars'] = in_array('4', $serviceList) || in_array('traveling', $serviceList) || in_array('cars', $serviceList);
            $_SESSION['umrah_service_stays'] = in_array('1', $serviceList) || in_array('stays', $serviceList);
        }
    }

    $title = (T::search ?? 'Search') . ' ' . (T::umrah ?? 'Umrah') . ' ' . $GLOBALS['app']['home_title'];
    $description = "Find the best Umrah packages at great prices";

    require_once views."includes/header.php";
    if (file_exists(views."modules/umrah/listing/umrah.php")) {
        require_once views."modules/umrah/listing/umrah.php";
    } else {
        require_once views."modules/umrah/listing.php";
    }
    require_once views."includes/footer.php";
});

$router->get('/umrah/listing', function () use ($SECURE,$db) {
    $title = T::umrah_packages ?? 'Umrah Packages';
    require_once views."includes/header.php";
    if (file_exists(views."modules/umrah/listing/umrah.php")) {
        require_once views."modules/umrah/listing/umrah.php";
    } else {
        require_once views."modules/umrah/listing.php";
    }
    require_once views."includes/footer.php";
});

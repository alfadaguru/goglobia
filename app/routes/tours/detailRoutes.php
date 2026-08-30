<?php
// ============================================================================
// FILE: app/routes/tours/detail.php
// ============================================================================

// ============================================================================
// TOUR DETAIL ROUTE
// SEO-Friendly Format: /tour/{name}/{id}/{supplier}/{departure_date}/{duration}/{travelers}/{tour_type}
// Example: /tour/paris-city-explorer-5-days/1/tours/18-12-2025/any/2/private
// ============================================================================
$router->get('/tour/(.*)', function ($params) use ($SECURE,$db) {

    $urlParts = explode('/', trim($params, '/'));

    // Minimum required: name/id/supplier/departure_date/duration/travelers/tour_type
    if (count($urlParts) < 6) { 
        header('Location: ' . root . 'tours');
        exit;
    }

    // Extract URL parameters
    $tourName = str_replace('-', ' ', $urlParts[0]);
    $tourId = $urlParts[1];
    $supplier = $urlParts[2];
    $departureDate = $urlParts[3];
    $duration = $urlParts[4];
    $travelersStr = $urlParts[5]; // Format: "2-2" or "2"
    $tourType = $urlParts[6] ?? 'any';

    // Parse travelers string (adults-children format)
    $totalAdults = 1;
    $totalChildren = 0;

    if (strpos($travelersStr, '-') !== false) {
        // Format: "adults-children"
        list($totalAdults, $totalChildren) = explode('-', $travelersStr);
        $totalAdults = intval($totalAdults);
        $totalChildren = intval($totalChildren);
    } else {
        // Format: just adults
        $totalAdults = intval($travelersStr);
        $totalChildren = 0;
    }

    // Store in session
    $_SESSION['tour_detail'] = [
        'tour_name' => $tourName,
        'tour_id' => $tourId,
        'supplier' => $supplier,
        'departure_date' => $departureDate === 'any' ? '' : $departureDate,
        'duration' => $duration === 'any' ? 'any' : $duration,
        'total_adults' => $totalAdults,
        'total_children' => $totalChildren,
        'travelers_str' => $travelersStr, // Keep original string
        'url_parts' => $urlParts
    ];

    // Render page
    $title = ucwords($tourName) . ' - ' . $GLOBALS['app']['home_title'];
    $description = "Book " . ucwords($tourName) . " Tour";
    require_once views."includes/header.php";
    require_once views."modules/tours/details/tour.php";
    require_once views."includes/footer.php";
});

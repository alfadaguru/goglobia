<?php
// app/routes/umrah/detailRoutes.php
// SUPERSEDED by the Umrah redesign — /umrah/detail/* is now handled by
// umrahV2Routes.php (redirects legacy URLs to the stable package page). This
// legacy handler is disabled to avoid a same-key route collision; the `return`
// makes the rest of the file (kept for reference) inert.
return;

$router->get('/umrah/detail/(.*)', function ($params) use ($SECURE,$db) {

    $urlParts = explode('/', trim($params, '/'));

    // Minimum required: slug/id/supplier/departure_date/duration/travelers
    if (count($urlParts) < 6) { 
        header('Location: ' . root . 'umrah');
        exit;
    }

    // Extract URL parameters
    $slug = $urlParts[0];
    $umrahId = $urlParts[1];
    $supplier = $urlParts[2];
    $departureDate = $urlParts[3];
    $duration = $urlParts[4];
    $travelersStr = $urlParts[5]; // Format: "2-2" or "2"
    $umrahType = $urlParts[6] ?? 'any';

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
    $_SESSION['umrah_detail'] = [
        'slug' => $slug,
        'umrah_id' => $umrahId,
        'supplier' => $supplier,
        'departure_date' => $departureDate === 'any' ? '' : $departureDate,
        'duration' => $duration === 'any' ? 'any' : $duration,
        'total_adults' => $totalAdults,
        'total_children' => $totalChildren,
        'travelers_str' => $travelersStr, // Keep original string
        'url_parts' => $urlParts
    ];

    $umrah = $db->get('umrah', ['name'], ['id' => $umrahId]);
    $title = ($umrah ? $umrah['name'] : 'Umrah') . ' - ' . $GLOBALS['app']['home_title'];
    $description = "Book " . ($umrah ? $umrah['name'] : 'Umrah');

    require_once views."includes/header.php";
    require_once views."modules/umrah/details/umrah.php";
    require_once views."includes/footer.php";
});

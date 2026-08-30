<?php
// ============================================================================
// FILE: app/routes/tours/listing.php
// ============================================================================

// ============================================================================
// TOURS LISTING ROUTE
// SEO-Friendly URL Format: /tours/{destination}/{start_date}/{duration}/{travelers}/{tour_type}
// Example: /tours/dubai/15-12-2025/4-7/2/adventure
// ============================================================================
$router->get('/tours/(.*)', function ($params) use ($SECURE,$db) {

    // ============================================================================
    // SESSION START - Ensure session is started
    // ============================================================================
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!empty($params)) {
        $urlParts = explode('/', trim($params, '/'));

        if (count($urlParts) >= 5) {
            // ============================================================================
            // EXTRACT URL PARAMETERS
            // ============================================================================
            $destination = str_replace('-', ' ', $urlParts[0] ?? '');
            $startDate = $urlParts[1] ?? '';
            $duration = $urlParts[2] ?? '';
            $travelersStr = $urlParts[3] ?? '1'; // Format: "2-2" or "2"
            $tourType = str_replace('-', ' ', $urlParts[4] ?? '');

            // ============================================================================
            // PARSE TRAVELERS STRING (adults-children format)
            // ============================================================================
            $adults = 1;
            $children = 0;
            
            if (strpos($travelersStr, '-') !== false) {
                // Format: "adults-children" (e.g., "2-2")
                list($adults, $children) = explode('-', $travelersStr);
                $adults = intval($adults);
                $children = intval($children);
            } else {
                // Format: just adults (e.g., "2" means 2 adults, 0 children)
                $adults = intval($travelersStr);
                $children = 0;
            }

            // Ensure minimum 1 adult
            $adults = max(1, $adults);
            $children = max(0, $children);
            $totalTravelers = $adults + $children;

            // ============================================================================
            // SAVE TO SESSION
            // ============================================================================
            // BASIC INFO
            $_SESSION['tour_destination'] = ucwords($destination);
            $_SESSION['tour_start_date'] = $startDate;
            $_SESSION['tour_duration'] = $duration;
            $_SESSION['tour_travelers'] = $totalTravelers;
            $_SESSION['tour_type'] = $tourType;

            // ADDITIONAL PARAMS
            $_SESSION['tour_destination_code'] = $destination;
            $_SESSION['tour_adults'] = $adults;
            $_SESSION['tour_children'] = $children;

            // SIMPLE TRAVELERS DATA (no child ages)
            $_SESSION['tour_travelers_data'] = [
                'adults' => $adults,
                'children' => $children,
                'total' => $totalTravelers
            ];
        }
    }

    // Trigger search webhook if parameters exist
    if (!empty($params) && count($urlParts) >= 5) {
        triggerWebhook('tours/search', 'tours.search.initiated', [
            'destination' => $_SESSION['tour_destination'] ?? '',
            'start_date' => $_SESSION['tour_start_date'] ?? '',
            'duration' => $_SESSION['tour_duration'] ?? '',
            'adults' => $_SESSION['tour_adults'] ?? 1,
            'children' => $_SESSION['tour_children'] ?? 0,
            'tour_type' => $_SESSION['tour_type'] ?? '',
            'user_id' => $_SESSION['user_data']['id'] ?? null,
            'timestamp' => date('Y-m-d H:i:s'),
            'session_id' => session_id()
        ]);
    }

    // META DATA
    $title = T::search.' '.T::tours.' '. $GLOBALS['app']['home_title'];
    $description = "Find the best tours at great prices";
    require_once views."includes/header.php";
    require_once views."modules/tours/listing/tours.php";
    require_once views."includes/footer.php";

});

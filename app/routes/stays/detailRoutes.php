<?php
// ============================================================================
// FILE: app/routes/stays/detail.php
// ============================================================================

// ============================================================================
// STAY DETAIL ROUTE
// SEO-Friendly Format: /stay/{name}/{id}/{supplier}/{checkin}/{checkout}/{nationality}/{rooms}/{room_configs}
// Example: /stay/hilton-dubai/12345/bookingcom/23-12-2025/27-12-2025/ax/1/2-3-1-2-3
// ============================================================================
$router->get('/stay/(.*)', function ($params) use ($SECURE,$db) {

    $urlParts = explode('/', trim($params, '/'));

    // Minimum required: name/id/supplier/checkin}/checkout/nationality/rooms/room_config
    if (count($urlParts) < 8) {
        header('Location: ' . root . 'stays');
        exit;
    }

    // Extract URL parameters
    if (count($urlParts) < 9) {
        header('Location: ' . root . 'stays');
        exit;
    }

    // Extract URL parameters with chain
    $hotelName = str_replace('-', ' ', $urlParts[0]);
    $hotelId = $urlParts[1];
    $supplier = strtolower($urlParts[2]);
    $hotelChain = $urlParts[3]; //Extract chain (will be '_' for non-Travelport)
    $checkin = $urlParts[4];
    $checkout = $urlParts[5];
    $nationality = strtoupper($urlParts[6]);
    $totalRooms = intval($urlParts[7]);
    $roomConfigsStr = implode('/', array_slice($urlParts, 8));

    if ($hotelChain === '_' || $hotelChain === 'null' || empty($hotelChain)) {
        $hotelChain = '';
    }

    // Cap hand-typed URLs at the portal stay limit before any supplier call.
    if (function_exists('staysClampCheckoutToMaxNights')) {
        $cappedCheckout = staysClampCheckoutToMaxNights($checkin, $checkout);
        if ($cappedCheckout !== $checkout) {
            $checkout = $cappedCheckout;
            $_SESSION['stays_search_notice'] = 'max_stay_nights';
        }
    }

    // Validate nationality against countries table
    $invalidNationality = false;
    if ($nationality === 'NULL' || empty($nationality)) {
        $invalidNationality = true;
    } else {
        $countryExists = $db->has('countries', ['iso' => $nationality]);
        if (!$countryExists) {
            $invalidNationality = true;
        }
    }

    // Get all countries for the modal dropdown
    $countries = $db->select('countries', ['iso', 'nicename', 'phonecode'], ['ORDER' => ['nicename' => 'ASC']]);

    // Parse room configurations (same logic as stays listing)
    $roomConfigs = explode('/', $roomConfigsStr);
    $roomsData = [];
    $totalAdults = 0;
    $totalChildren = 0;

    foreach ($roomConfigs as $config) {
        $parts = explode('-', $config);
        $adults = intval($parts[0] ?? 2);
        $children = intval($parts[1] ?? 0);
        $childAges = [];

        if ($children > 0 && count($parts) > 2) {
            // Age 0 is valid (infant) — do not use empty() which treats "0" as empty
            for ($i = 2; $i < count($parts); $i++) {
                if (isset($parts[$i]) && $parts[$i] !== '' && is_numeric($parts[$i])) {
                    $age = intval($parts[$i]);
                    if ($age >= 0 && $age <= 17) {
                        $childAges[] = $age;
                    }
                }
            }
            while (count($childAges) < $children) $childAges[] = 1;
            if (count($childAges) > $children) $childAges = array_slice($childAges, 0, $children);
        }

        $roomsData[] = ['adults' => $adults, 'children' => $children, 'childAges' => $childAges];
        $totalAdults += $adults;
        $totalChildren += $children;
    }

    if (function_exists('hotelbedsParseStayDates')) {
        $parsedStayDates = hotelbedsParseStayDates($checkin, $checkout);
        if ($parsedStayDates) {
            $checkin = $parsedStayDates['checkin_dmY'];
            $checkout = $parsedStayDates['checkout_dmY'];
        }
    }

    // Store in session for details page access
    $_SESSION['stay_detail'] = [
        'hotel_name' => $hotelName,
        'hotel_id' => $hotelId,
        'supplier' => $supplier,
        'hotel_chain' => $hotelChain,
        'checkin' => $checkin,
        'checkout' => $checkout,
        'nationality' => $nationality,
        'total_rooms' => $totalRooms,
        'total_adults' => $totalAdults,
        'total_children' => $totalChildren,
        'rooms_data' => $roomsData,
        'invalid_nationality' => $invalidNationality,
        'url_parts' => $urlParts,
        'countries' => $countries,
        'destination' => $_SESSION['hotel_destination'] ?? $hotelName,  // For API calls in details endpoint
    ];

    // ============================================================================
    // WEBHOOK: Hotel Viewed
    // ============================================================================
    $nights = 0;
    if (!empty($checkin) && !empty($checkout)) {
        try {
            $checkinDate = DateTime::createFromFormat('d-m-Y', $checkin);
            $checkoutDate = DateTime::createFromFormat('d-m-Y', $checkout);
            if ($checkinDate && $checkoutDate) {
                $nights = $checkinDate->diff($checkoutDate)->days;
            }
        } catch (Exception $e) {
            // Ignore date calculation errors
        }
    }

    triggerWebhook('stays/booking', 'stays.booking.hotel_viewed', [
        'hotel_name' => $hotelName,
        'hotel_id' => $hotelId,
        'supplier' => $supplier,
        'destination' => $hotelName, // Hotel name serves as location reference
        'checkin' => $checkin,
        'checkout' => $checkout,
        'nights' => $nights,
        'rooms' => $totalRooms,
        'adults' => $totalAdults,
        'children' => $totalChildren,
        'user_id' => $_SESSION['user_id'] ?? null,
        'timestamp' => date('Y-m-d H:i:s'),
        'session_id' => session_id(),
        'referrer' => $_SERVER['HTTP_REFERER'] ?? ''
    ]);

    // Render page
    $title = ucwords($hotelName) . ' - ' . $GLOBALS['app']['home_title'];
    $description = "Book " . ucwords($hotelName);
    require_once views."includes/header.php";
    require_once views."modules/stays/details/stay.php";
    require_once views."includes/footer.php";
});

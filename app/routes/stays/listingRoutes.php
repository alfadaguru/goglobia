<?php
// ============================================================================
// FILE: app/routes/stays/listing.php
// ============================================================================

// ============================================================================
// STAYS LISTING ROUTE
// SEO-Friendly Format: /stays/{destination}/{checkin}/{checkout}/{nationality}/{rooms}/{room_configs}
// Example: /stays/dubai/15-12-2025/19-12-2025/PK/2/2-0|2-1-5
// ============================================================================
$router->get('/stays/(.*)', function ($params) use ($SECURE,$db) {

    // =======================================================================================================================================================
    if (!empty($params)) {
        $urlParts = explode('/', trim($params, '/'));

        if (count($urlParts) >= 6) {
            // ============================================================================
            // EXTRACT URL PARAMETERS
            // ============================================================================
            $destination = str_replace('-', ' ', $urlParts[0] ?? '');
            $checkin = $urlParts[1] ?? '';
            $checkout = $urlParts[2] ?? '';
            $nationality = $urlParts[3] ?? 'US';
            $totalRooms = $urlParts[4] ?? '1';
            // No URL decoding needed - using clean separators
            // Get all parts from index 5 onwards and rejoin them (since rooms are separated by /)
            $roomConfigsStr = implode('/', array_slice($urlParts, 5)) ?: '2-0'; // Default: 2 adults, 0 children

            // ============================================================================
            // PARSE ROOM CONFIGURATIONS
            // Format: 2-0/1-2-10-5 (Room1: 2 adults 0 children / Room2: 1 adult 2 children aged 10 and 5)
            // Structure: adults-children-age1-age2-age3 (separated by / for multiple rooms)
            // ============================================================================
            $roomConfigs = explode('/', $roomConfigsStr);
            $roomsData = [];
            $totalAdults = 0;
            $totalChildren = 0;

            foreach ($roomConfigs as $config) {
                // Split room configuration: adults-children-age1-age2-age3
                $parts = explode('-', $config);
                $adults = intval($parts[0] ?? 2);
                $children = intval($parts[1] ?? 0);
                $childAges = [];

                // ============================================================================
                // EXTRACT CHILD AGES - All parts after index 1 are child ages
                // Example: 2-3-5-7-10 = 2 adults, 3 children with ages 5, 7, 10
                // ============================================================================
                if ($children > 0 && count($parts) > 2) {
                    // Get all remaining parts as child ages (starting from index 2)
                    // Note: age 0 is valid (infant) — do not use empty() which treats "0" as empty
                    for ($i = 2; $i < count($parts); $i++) {
                        if (isset($parts[$i]) && $parts[$i] !== '' && is_numeric($parts[$i])) {
                            $age = intval($parts[$i]);
                            if ($age >= 0 && $age <= 17) {
                                $childAges[] = $age;
                            }
                        }
                    }

                    // Ensure we have exactly the right number of ages
                    // If fewer ages than children, fill with default age 1
                    while (count($childAges) < $children) {
                        $childAges[] = 1;
                    }

                    // If more ages than children, trim to match children count
                    if (count($childAges) > $children) {
                        $childAges = array_slice($childAges, 0, $children);
                    }
                }

                $roomsData[] = [
                    'adults' => $adults,
                    'children' => $children,
                    'childAges' => $childAges
                ];

                $totalAdults += $adults;
                $totalChildren += $children;
            }

            // Normalize stay dates (dd-mm-yyyy) before session + API consumers
            if (function_exists('hotelbedsParseStayDates')) {
                $parsedStayDates = hotelbedsParseStayDates($checkin, $checkout);
                if ($parsedStayDates) {
                    $checkin = $parsedStayDates['checkin_dmY'];
                    $checkout = $parsedStayDates['checkout_dmY'];
                }
            }

            // Cap hand-typed URLs at the portal stay limit before any supplier call.
            if (function_exists('staysClampCheckoutToMaxNights')) {
                $cappedCheckout = staysClampCheckoutToMaxNights($checkin, $checkout);
                if ($cappedCheckout !== $checkout) {
                    $checkout = $cappedCheckout;
                    $_SESSION['stays_search_notice'] = 'max_stay_nights';
                }
            }

            // ============================================================================
            // SAVE TO SESSION for persistence across pages
            // ============================================================================

            // BASIC INFO
            // Country of the destination the guest picked from the suggestions. Same
            // city name exists in several countries, so this is what keeps "Bali" out
            // of Rajasthan and "Syracuse" out of Sicily. It is stored in the session
            // by /stays/destination-context (never in the URL, which stays SEO-clean)
            // and only applies to the destination it was actually chosen for — a
            // pasted or edited URL falls back to resolving by name.
            $destinationSlug = strtolower(trim((string) ($urlParts[0] ?? '')));
            if (strtolower(trim((string) ($_SESSION['hotel_destination_country_for'] ?? ''))) !== $destinationSlug) {
                unset($_SESSION['hotel_destination_country'], $_SESSION['hotel_destination_country_for']);
            }

            $_SESSION['hotel_destination'] = ucwords($destination);      // Destination name (e.g., "Dubai")
            $_SESSION['hotels_checkin_date'] = $checkin;                 // Check-in date (format: dd-mm-yyyy)
            $_SESSION['hotels_checkout_date'] = $checkout;               // Check-out date (format: dd-mm-yyyy)
            $_SESSION['hotel_nationality'] = $nationality;               // Nationality ISO code (e.g., "PK")
            $_SESSION['hotel_rooms'] = $totalRooms;                      // Total number of rooms (integer)

            // GUEST COUNTS
            $_SESSION['hotel_adults'] = $totalAdults;                    // Total adults across all rooms (integer)
            $_SESSION['hotel_children'] = $totalChildren;                // Total children across all rooms (integer)

            // DETAILED ROOM CONFIGURATION
            // $_SESSION['hotel_rooms_data'] = Array of room objects
            // Structure: [
            //   ['adults' => 2, 'children' => 1, 'childAges' => [5]],
            //   ['adults' => 1, 'children' => 2, 'childAges' => [3, 7]]
            // ]
            // Access: $_SESSION['hotel_rooms_data'][0]['adults'] = first room adult count
            //         $_SESSION['hotel_rooms_data'][0]['childAges'][0] = first child age in first room
            $_SESSION['hotel_rooms_data'] = $roomsData;

            // ============================================================================
            // WEBHOOK: Search Initiated
            // ============================================================================
            triggerWebhook('stays/search', 'stays.search.initiated', [
                'destination' => $_SESSION['hotel_destination'] ?? '',
                'checkin' => $_SESSION['hotels_checkin_date'] ?? '',
                'checkout' => $_SESSION['hotels_checkout_date'] ?? '',
                'nationality' => $_SESSION['hotel_nationality'] ?? '',
                'rooms' => $_SESSION['hotel_rooms'] ?? 1,
                'adults' => $totalAdults,
                'children' => $totalChildren,
                'rooms_data' => $roomsData,
                'timestamp' => date('Y-m-d H:i:s'),
                'user_id' => $_SESSION['user_id'] ?? null,
                'session_id' => session_id(),
                'search_source' => 'listing_page'
            ]);
        }
    }

    // META DATA
    $title = T::search.' '.T::stays.' '. $GLOBALS['app']['home_title'];
    $description = "Find the best hotels at great prices";
    require_once views."includes/header.php";
    require_once views."modules/stays/listing/stays.php";
    require_once views."includes/footer.php";

});

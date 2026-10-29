<?php

$router->post('stays/hotels/rooms', function() use ($db) {

    // Suppress errors for clean JSON output
    error_reporting(0);
    ini_set('display_errors', 0);

    // The modules gateway does NOT load app/lib/functions.php (see modules/helpers.php),
    // so stays_room_option_ids() — needed to key calendar prices on the STABLE option_id
    // like the detail API + inventory spine — isn't defined here. Load the self-contained
    // inventory lib (idempotent: its functions are function_exists-guarded).
    if (!function_exists('stays_room_option_ids')) {
        $__inv = __DIR__ . '/../../../app/lib/stays_inventory.php';
        if (is_file($__inv)) { require_once $__inv; }
    }
    // MODULE 06 yield rate-rules live in supplier_rates.php (self-contained, functions
    // are function_exists-guarded, no app/lib/functions.php dependency). The AUTHORITATIVE
    // pricer (stays_recompute_net) applies these per night when charging; the customer
    // display MUST apply the identical adjustment or an upward rule overcharges vs the
    // shown price (audit #5). Load it here so the display and the charge agree.
    if (!function_exists('rate_rules_adjust_nightly')) {
        $__rates = __DIR__ . '/../../../app/lib/supplier_rates.php';
        if (is_file($__rates)) { require_once $__rates; }
    }

    // Start output buffering
    ob_start();

    // Set JSON header
    header('Content-Type: application/json');

    try {
        // Parse JSON request body
        $input = json_decode(file_get_contents('php://input'), true);

        $hotelId = $input['hotel_id'] ?? '';
        $supplier = $input['supplier'] ?? '';
        $checkin = $input['checkin'] ?? '';
        $checkout = $input['checkout'] ?? '';
        $nationality = $input['nationality'] ?? '';
        $rooms = $input['rooms'] ?? [];

        // Validate required parameters
        if (empty($hotelId)) {
            echo json_encode([
                'success' => false,
                'message' => 'Hotel ID is required'
            ]);
            exit;
        }

        // Calculate number of nights
        $nights = 1;
        if (!empty($checkin) && !empty($checkout)) {
            try {
                $checkin_parts = explode('-', $checkin);
                $checkout_parts = explode('-', $checkout);

                if (count($checkin_parts) === 3 && count($checkout_parts) === 3) {
                    $checkin_date = "{$checkin_parts[2]}-{$checkin_parts[1]}-{$checkin_parts[0]}";
                    $checkout_date = "{$checkout_parts[2]}-{$checkout_parts[1]}-{$checkout_parts[0]}";
                    $date1 = new DateTime($checkin_date);
                    $date2 = new DateTime($checkout_date);
                    $interval = $date1->diff($date2);
                    $nights = max(1, (int)$interval->days);
                }
            } catch (Exception $e) {
                $nights = 1;
            }
        }

        // Get currency
        $currency = isset($_SESSION['app_currency']) ? $_SESSION['app_currency'] : 'USD';

        // Fetch hotel to get base currency. listing_status='approved' REQUIRED on
        // the public rooms/pricing path — a non-approved property must not serve
        // bookable room options (admin stays are stamped 'approved' at creation).
        $hotel = $db->get('stays', ['currency'], [
            'id' => $hotelId,
            'status' => '1',
            'listing_status' => 'approved'
        ]);

        // Non-approved / non-existent property → no rooms to sell. Match the
        // normal success envelope ({success,data:{...,rooms:[]}}) and the output
        // buffering used at the end of this handler so the frontend parses cleanly.
        if (!$hotel) {
            if (function_exists('ob_get_level') && ob_get_level()) { ob_clean(); }
            echo json_encode([
                'success' => true,
                'data' => [
                    'hotel_id' => $hotelId,
                    'nights'   => $nights,
                    'currency' => $currency,
                    'rooms'    => []
                ]
            ]);
            if (function_exists('ob_get_level') && ob_get_level()) { ob_end_flush(); }
            exit;
        }

        $hotelCurrency = !empty($hotel['currency']) ? $hotel['currency'] : 'USD';

        // ── Build list of stay dates (checkin night .. checkout-1) ──────────
        $stayDates = [];
        if (!empty($checkin) && !empty($checkout)) {
            try {
                $ci_parts = explode('-', $checkin);
                $co_parts = explode('-', $checkout);
                if (count($ci_parts) === 3 && count($co_parts) === 3) {
                    $ci = new DateTime("{$ci_parts[2]}-{$ci_parts[1]}-{$ci_parts[0]}");
                    $co = new DateTime("{$co_parts[2]}-{$co_parts[1]}-{$co_parts[0]}");
                    $cur = clone $ci;
                    while ($cur < $co) {
                        $stayDates[] = $cur->format('Y-m-d');
                        $cur->modify('+1 day');
                    }
                }
            } catch (Exception $e) {
                $stayDates = [];
            }
        }

        // ── Fetch calendar (dynamic) rates for those dates ────────────────
        $calendarRates = [];
        if (!empty($stayDates)) {
            try {
                $calRows = $db->select(
                    'stays_rooms_calendar',
                    ['room_id', 'option_id', 'date', 'price'],
                    ['stay_id' => (int)$hotelId, 'date' => $stayDates]
                );
                foreach ($calRows as $cr) {
                    $calendarRates[$cr['room_id'] . '_' . $cr['option_id'] . '_' . $cr['date']] = (float)$cr['price'];
                }
            } catch (Exception $e) {
                $calendarRates = []; // table may not exist yet
            }
        }

        // ── MODULE 06 yield rate-rules for the displayed price (audit #5) ──────
        // The same rules + context stays_recompute_net uses to CHARGE, so the shown
        // price matches the charged price. Empty rules → no change (legacy pricing
        // preserved exactly). los/lead_days computed identically to the pricer.
        $rateRules = [];
        $losNights = count($stayDates);
        $leadDays  = !empty($stayDates)
            ? max(0, (int) floor((strtotime($stayDates[0]) - strtotime(date('Y-m-d'))) / 86400))
            : 0;
        if (!empty($stayDates) && function_exists('rate_rules_for_stay')) {
            try { $rateRules = rate_rules_for_stay($db, (int) $hotelId); }
            catch (Exception $e) { $rateRules = []; }
        }

        // Fetch all rooms for this hotel
        $hotelRooms = $db->select('stays_rooms', '*', [
            'stay_id' => $hotelId,
            'status' => '1'
        ]);

        $roomsResponse = [];
        foreach ($hotelRooms as $room) {
            // Get room type name
            $roomTypeData = $db->get('stays_settings', 'name', [
                'id' => $room['room_type_id'],
                'setting_type' => 'room_type',
                'status' => 1
            ]);
            // Prefer the supplier's guest-facing display name; fall back to the category.
            $roomDisplay = trim((string) ($room['room_name'] ?? ''));
            $roomName = $roomDisplay !== '' ? $roomDisplay
                : ($roomTypeData ? $roomTypeData : 'Room Type ' . $room['room_type_id']);

            // Parse room images
            $roomImages = [];
            $roomMainImage = '';
            if (!empty($room['room_images'])) {
                $imagesArray = json_decode($room['room_images'], true);
                if (is_array($imagesArray)) {
                    foreach ($imagesArray as $img) {
                        if (!empty($img['url'])) {
                            $imageUrl = dirname(root) . $img['url'];
                            $roomImages[] = $imageUrl;
                            if ((isset($img['default']) && $img['default'] === true) && empty($roomMainImage)) {
                                $roomMainImage = $imageUrl;
                            }
                        }
                    }
                    if (empty($roomMainImage) && !empty($roomImages)) {
                        $roomMainImage = $roomImages[0];
                    }
                }
            }

            // Parse room amenities
            $roomAmenities = [];
            if (!empty($room['amenities'])) {
                $amenityIds = json_decode($room['amenities'], true);
                if (!is_array($amenityIds)) {
                    $cleaned = trim($room['amenities'], '[]');
                    $amenityIds = array_map('intval', array_filter(explode(',', $cleaned)));
                }

                if (!empty($amenityIds)) {
                    $amenitiesList = $db->select('stays_settings', ['id', 'name'], [
                        'id' => $amenityIds,
                        'setting_type' => 'room_amenity',
                        'status' => '1'
                    ]);
                    foreach ($amenitiesList as $amenity) {
                        $roomAmenities[] = [
                            'id' => $amenity['id'],
                            'name' => $amenity['name']
                        ];
                    }
                }
            }

            // Parse room options and apply pricing
            $roomOptions = [];
            if (!empty($room['room_options'])) {
                // Use the STABLE option_id (stays_room_option_ids) for calendar lookups —
                // the same key the supplier calendar WRITES and the inventory/holds spine
                // + detail API use. The old positional ($index+1) key silently missed
                // (→ base price) once a supplier deleted/reordered options. Fall back to
                // $index+1 only for legacy rooms with no stable id yet.
                if (function_exists('stays_room_option_ids')) {
                    $options = stays_room_option_ids($db, (int) $room['id'], (int) $hotelId);
                    if (empty($options)) { $options = json_decode($room['room_options'], true); }
                } else {
                    $options = json_decode($room['room_options'], true);
                }
                if (is_array($options)) {
                    foreach ($options as $index => $option) {
                        if (isset($option['price']) && $option['price'] > 0) {
                            $basePrice = $option['price'];
                            // Stable id when present; positional fallback otherwise.
                            $optionId  = isset($option['option_id']) && (int) $option['option_id'] > 0
                                ? (int) $option['option_id'] : ($index + 1);

                            // ── Apply per-night calendar pricing (falls back to base price) ──
                            if (!empty($stayDates)) {
                                $totalRaw    = 0.0;
                                $totalMarked = 0.0;
                                foreach ($stayDates as $stayDate) {
                                    $calKey   = $room['id'] . '_' . $optionId . '_' . $stayDate;
                                    $nightPx  = isset($calendarRates[$calKey]) ? $calendarRates[$calKey] : $basePrice;
                                    // Apply the same yield rules the charge uses, so the
                                    // displayed price == the charged price (audit #5). Same
                                    // context keys as stays_recompute_net; occupancy-based
                                    // rules no-op here (occupancy_pct=null) exactly as they
                                    // do in the pricer's cheap path, keeping them consistent.
                                    if (!empty($rateRules) && function_exists('rate_rules_adjust_nightly')) {
                                        $nightPx = rate_rules_adjust_nightly($rateRules, (float) $nightPx, [
                                            'room_id' => (int) $room['id'], 'option_id' => (int) $optionId,
                                            'los' => $losNights, 'lead_days' => $leadDays,
                                            'weekday' => (string) (int) date('w', strtotime($stayDate)),
                                            'occupancy_pct' => null,
                                        ]);
                                    }
                                    $totalRaw += $nightPx;
                                    $nightMk   = MARKUP($nightPx, $module, $db, $hotelCurrency, $currency);
                                    $totalMarked += $nightMk['price'];
                                }
                                $avgNightly       = $totalRaw / count($stayDates);
                                $basePriceConverted = CURRENCY_CONVERT($avgNightly, $db, $hotelCurrency, $currency);
                                $pricePerNight    = ['price' => $totalMarked / count($stayDates)];
                                $totalPrice       = $totalMarked;
                            } else {
                                // Fallback when dates are unavailable
                                $basePriceConverted = CURRENCY_CONVERT($basePrice, $db, $hotelCurrency, $currency);
                                $pricePerNight = MARKUP($basePrice, $module, $db, $hotelCurrency, $currency);
                                $totalPrice    = $pricePerNight['price'] * $nights;
                            }

                            $roomOptions[] = [
                                'option_index' => $index,
                                // STABLE option id (stays_room_option_ids) — the key the
                                // inventory/holds spine and the calendar use. Exposed so the
                                // booking draft's selected_rooms carries it and the submit
                                // path can place a real no-oversell hold (previously only the
                                // positional option_index reached the client, so holds could
                                // never be keyed correctly). Positional fallback for legacy
                                // rooms with no stable id yet.
                                'option_id' => $optionId,
                                'max_adults' => $option['max_adults'] ?? 2,
                                'max_children' => $option['max_children'] ?? 0,
                                'price_per_night' => $pricePerNight['price'],
                                'total_price' => $totalPrice,
                                'base_price' => $basePriceConverted['price'],  // CONVERTED TO DISPLAY CURRENCY
                                'original_price' => $basePriceConverted['price'],  // ALIAS FOR CONSISTENCY WITH RATEHAWK
                                'currency' => $currency,
                                'discount_percentage' => $option['discount_percentage'] ?? 0,
                                'extra_bed_available' => $option['extra_bed_available'] ?? 0,
                                'extra_bed_charge' => $option['extra_bed_charge'] ?? 0,
                                'breakfast_included' => $option['breakfast_included'] ?? 0,
                                'cancellation_free' => $option['cancellation_free'] ?? 0,
                                'refundable' => $option['refundable'] ?? 0,
                                'available_quantity' => $option['available_quantity'] ?? 1,
                                'board_id' => $option['board_id'] ?? null
                            ];
                        }
                    }
                }
            }

            // Only add rooms that have available options
            if (!empty($roomOptions)) {
                $roomsResponse[] = [
                    'room_id' => $room['id'],
                    'room_type_id' => $room['room_type_id'],
                    'room_name' => $roomName,
                    'room_images' => $roomImages,
                    'room_main_image' => $roomMainImage,
                    'amenities' => $roomAmenities,
                    'max_adults' => max(array_column($roomOptions, 'max_adults')),
                    'max_children' => max(array_column($roomOptions, 'max_children')),
                    'options' => $roomOptions
                ];
            }
        }

        // Build response
        $response = [
            'success' => true,
            'data' => [
                'hotel_id' => $hotelId,
                'nights' => $nights,
                'currency' => $currency,
                'rooms' => $roomsResponse
            ]
        ];

        // Clear buffer and output JSON
        ob_clean();
        echo json_encode($response);
        ob_end_flush();

    } catch (Exception $e) {
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Server error: ' . $e->getMessage()
        ]);
        ob_end_flush();
    }

});

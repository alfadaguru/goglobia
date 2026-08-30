<?php

$router->post('stays/hotels/rooms', function() use ($db) {

    // Suppress errors for clean JSON output
    error_reporting(0);
    ini_set('display_errors', 0);

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

        // Fetch hotel to get base currency
        $hotel = $db->get('stays', ['currency'], [
            'id' => $hotelId,
            'status' => '1'
        ]);

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
            $roomName = $roomTypeData ? $roomTypeData : 'Room Type ' . $room['room_type_id'];

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
                $options = json_decode($room['room_options'], true);
                if (is_array($options)) {
                    foreach ($options as $index => $option) {
                        if (isset($option['price']) && $option['price'] > 0) {
                            $basePrice = $option['price'];
                            $optionId  = $index + 1; // calendar keys use 1-based option index

                            // ── Apply per-night calendar pricing (falls back to base price) ──
                            if (!empty($stayDates)) {
                                $totalRaw    = 0.0;
                                $totalMarked = 0.0;
                                foreach ($stayDates as $stayDate) {
                                    $calKey   = $room['id'] . '_' . $optionId . '_' . $stayDate;
                                    $nightPx  = isset($calendarRates[$calKey]) ? $calendarRates[$calKey] : $basePrice;
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

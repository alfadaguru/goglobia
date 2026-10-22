<?php
// path: modules/stays/adivaha/rooms.php
// Adivaha B2C Hotels — room list + rates for ONE hotel.
// POST stays/adivaha/rooms   (JSON body)
//
// Maps to Adivaha POST /hotels/rooms (hotel_id + dates/occupancy → rooms[], each
// with rates). Returns { success, data:{ rooms:[...], currency, nights } } in the
// platform room-picker shape. CRITICAL: each rate option carries a complete
// booking_data block (supplier, offer_id, rate_key, room_code, room_name, board,
// refundable, property_id, dates, occupancy) — this is what the checkout persists
// into the booking draft and what issue.php replays to /hotels/offer + /hotels/book
// (the room-selection note in the spec: pass supplier/offer_id/rate_key/room_code/
// room_name/board back exactly as received).

global $router;

$router->post('/stays/adivaha/rooms', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) $input = $_POST;

        $hotelId = (string) ($input['hotel_id'] ?? '');
        if (stripos($hotelId, 'adivaha_') === 0) $hotelId = substr($hotelId, 8);
        $hotelId = trim($hotelId);
        if ($hotelId === '' || !ctype_digit($hotelId)) {
            echo json_encode(['success' => false, 'message' => 'A valid hotel_id is required.']);
            exit;
        }

        $checkinRaw  = (string) ($input['checkin'] ?? '');
        $checkoutRaw = (string) ($input['checkout'] ?? '');
        $ciTs = strtotime($checkinRaw);
        $coTs = strtotime($checkoutRaw);
        if ($ciTs === false || $coTs === false || $coTs <= $ciTs) {
            echo json_encode(['success' => false, 'message' => 'Invalid check-in / check-out dates.']);
            exit;
        }
        $checkIn  = date('Y-m-d', $ciTs);
        $checkOut = date('Y-m-d', $coTs);
        $nights   = max(1, (int) round(($coTs - $ciTs) / 86400));

        $nationality = trim((string) ($input['nationality'] ?? ''));
        $currency    = strtoupper((string) ($input['currency'] ?? 'USD'));

        // Occupancy. The frontend sends rooms_data [{adults, children, childAges}]
        // and/or rooms/adults/children. Build Adivaha room_occupancy when we have
        // per-room detail, else fall back to rooms+adults+child_ages.
        $roomsData = $input['rooms_data'] ?? [];
        if (is_string($roomsData)) { $roomsData = json_decode($roomsData, true); }
        if (!is_array($roomsData)) $roomsData = [];

        $roomOccupancy = [];
        foreach ($roomsData as $rd) {
            if (!is_array($rd)) continue;
            $occ = ['adults' => max(1, (int) ($rd['adults'] ?? 2))];
            $ca = $rd['childAges'] ?? $rd['child_ages'] ?? [];
            if (is_array($ca) && !empty($ca)) {
                $occ['child_ages'] = array_map(fn($a) => max(0, min(17, (int) $a)), $ca);
            }
            $roomOccupancy[] = $occ;
        }

        $body = [
            'hotel_id'  => (int) $hotelId,
            'check_in'  => $checkIn,
            'check_out' => $checkOut,
        ];
        if (!empty($roomOccupancy)) {
            $body['room_occupancy'] = $roomOccupancy;
        } else {
            $body['rooms']  = max(1, (int) ($input['rooms'] ?? 1));
            $body['adults'] = max(1, (int) ($input['adults'] ?? 2));
            $kids = (int) ($input['children'] ?? 0);
            if ($kids > 0) { $body['child_ages'] = array_fill(0, $kids, 8); }
        }
        if ($nationality !== '') $body['nationality'] = $nationality;

        $module = $db->get('modules', '*', ['name' => 'adivaha', 'type' => 'stays']);
        $credentials = getAdivahaHotelCredentials($db);
        if (!$module || !$credentials || $credentials['api_key'] === '') {
            echo json_encode(['success' => false, 'message' => 'Adivaha hotels module/credentials not configured.']);
            exit;
        }
        $bearer = adivahaHBearer($db, $credentials);
        $base   = $credentials['base_url'];

        $url = $base . '/hotels/rooms?display_currency=' . urlencode($currency);
        $res = adivahaHApiRequest('POST', $url, $body, $bearer, [], 60);
        if ($res['http_code'] === 401 && $credentials['partner_id'] !== '') {
            $bearer = adivahaHBearer($db, $credentials, true);
            $res = adivahaHApiRequest('POST', $url, $body, $bearer, [], 60);
        }
        adivahaHLog('rooms', $body, $res['raw'], $res['http_code'], $res['curl_error']);

        if (!$res['success'] || !is_array($res['data'])) {
            echo json_encode(['success' => false, 'message' => 'Could not load rooms: ' . adivahaHExtractError($res), 'data' => ['rooms' => []]]);
            exit;
        }

        $apiRooms = is_array($res['data']['rooms'] ?? null) ? $res['data']['rooms'] : [];
        $baseCurrencyResp = strtoupper((string) ($res['data']['base_currency'] ?? $currency));

        $rooms = [];
        foreach ($apiRooms as $rIdx => $room) {
            if (!is_array($room)) continue;

            // Gather this room's rate list (the spec: room.rates[], cheapest first;
            // room.cheapest is the lead rate).
            $rates = is_array($room['rates'] ?? null) ? $room['rates'] : [];
            if (empty($rates) && !empty($room['cheapest']) && is_array($room['cheapest'])) {
                $rates = [$room['cheapest']];
            }

            $options = [];
            foreach ($rates as $optIdx => $rate) {
                if (!is_array($rate)) continue;

                // Price: the rate's `price` is the customer price BEFORE GST for the
                // whole stay in the display currency — but to apply THIS platform's
                // markup consistently we mark up our net (sell_base equivalent). The
                // rooms endpoint returns `price` already marked by Adivaha; we treat
                // it as our cost base and re-apply the platform markup + conversion
                // to keep parity with search (which marks up sell_base).
                $ratePrice = (float) ($rate['price'] ?? $rate['total'] ?? 0);
                if ($ratePrice <= 0) continue;

                // The rooms response prices are in the display currency already;
                // apply platform markup on that figure (no re-conversion needed).
                $markedUp = MARKUP($ratePrice, $module, $db, $currency, $currency);
                $total    = is_array($markedUp) && isset($markedUp['price']) ? (float) $markedUp['price'] : $ratePrice;
                $perNight = $nights > 0 ? round($total / $nights, 2) : $total;

                $refundable = $rate['refundable'] ?? null;

                // Per-rate booking handle — everything offer/book need, pass-through.
                $optBookingData = [
                    'supplier'    => (string) ($rate['supplier'] ?? 'adivaha_hotels'),
                    'offer_id'    => (string) ($rate['offer_id'] ?? ''),
                    'rate_key'    => (string) ($rate['rate_key'] ?? ''),
                    'room_code'   => (string) ($rate['room_code'] ?? ''),
                    'room_name'   => (string) ($rate['room_name'] ?? $room['name'] ?? ''),
                    'board'       => (string) ($rate['board'] ?? ''),
                    'refundable'  => $refundable,
                    'property_id' => (int) $hotelId,
                    'check_in'    => $checkIn,
                    'check_out'   => $checkOut,
                    'nationality' => $nationality,
                    'destination' => (string) ($input['destination'] ?? ''),
                    'base_price'  => $ratePrice,
                ];

                $options[] = [
                    'option_index'       => $optIdx,
                    'id'                 => (string) ($rate['rate_key'] ?? ($rate['offer_id'] ?? ('opt_' . $rIdx . '_' . $optIdx))),
                    'rate_key'           => (string) ($rate['rate_key'] ?? ''),
                    'room_code'          => (string) ($rate['room_code'] ?? ''),
                    'price_per_night'    => $perNight,
                    'total_price'        => round($total, 2),
                    'base_price'         => $ratePrice,
                    'currency'           => $currency,
                    'base_currency'      => $baseCurrencyResp,
                    'board_id'           => (string) ($rate['board'] ?? ''),
                    'board_name'         => (string) ($rate['board'] ?? 'Room Only'),
                    'breakfast_included' => (stripos((string) ($rate['board'] ?? ''), 'breakfast') !== false) ? 1 : 0,
                    'refundable'         => ($refundable === null) ? null : (bool) $refundable,
                    'cancellation_free'  => !empty($refundable) ? 1 : 0,
                    'cancellation_text'  => (string) ($rate['cancel_summary'] ?? ''),
                    'cancellation_policies' => is_array($rate['cancel_policy'] ?? null) ? $rate['cancel_policy'] : [],
                    'rate_comments'      => (string) ($rate['rate_remarks'] ?? ''),
                    'payable_at_hotel'   => is_array($rate['payable_at_hotel'] ?? null) ? $rate['payable_at_hotel'] : [],
                    'available_quantity' => 1,
                    'room_selectable'    => !empty($rate['room_selectable']),
                    'rate_type'          => 'BOOKABLE',
                    'booking_data'       => $optBookingData,
                ];
            }

            if (empty($options)) continue;

            // Sort this room's options cheapest first.
            usort($options, fn($a, $b) => $a['total_price'] <=> $b['total_price']);

            $roomImages = [];
            if (!empty($room['images']) && is_array($room['images'])) {
                foreach ($room['images'] as $im) {
                    if (is_string($im)) $roomImages[] = $im;
                    elseif (is_array($im)) $roomImages[] = (string) ($im['url'] ?? '');
                }
                $roomImages = array_values(array_filter($roomImages, fn($u) => $u !== ''));
            }

            $rooms[] = [
                'room_id'      => 'adv_room_' . $rIdx,
                'room_type_id' => 'adv_room_' . $rIdx,
                'room_name'    => (string) ($room['name'] ?? 'Room'),
                'room_images'  => $roomImages,
                'room_main_image' => $roomImages[0] ?? '',
                'amenities'    => [],
                // Adivaha's room schema exposes only min/max OCCUPANCY, not a
                // separate adult/child cap. We surface max_occupancy as max_adults
                // and leave max_children 0 (unknown) rather than inventing a split.
                'max_adults'   => (int) ($room['max_occupancy'] ?? 0),
                'max_children' => 0,
                'max_occupancy' => (int) ($room['max_occupancy'] ?? 0),
                'bed_config'   => (string) ($room['bed_config'] ?? ''),
                'options'      => $options,
                'options_count' => count($options),
                'min_price_per_night' => $options[0]['price_per_night'] ?? 0,
            ];
        }

        // Rooms cheapest first.
        usort($rooms, function ($a, $b) {
            $pa = $a['options'][0]['total_price'] ?? PHP_INT_MAX;
            $pb = $b['options'][0]['total_price'] ?? PHP_INT_MAX;
            return $pa <=> $pb;
        });

        echo json_encode([
            'success' => true,
            'data'    => [
                'hotel_id'       => 'adivaha_' . $hotelId,
                'nights'         => $nights,
                'currency'       => $currency,
                'searched_rooms' => count($roomOccupancy) > 0 ? count($roomOccupancy) : max(1, (int) ($input['rooms'] ?? 1)),
                'rooms'          => $rooms,
                'total_rooms'    => count($rooms),
            ],
        ]);
        exit;

    } catch (Throwable $e) {
        error_log('ADIVAHA HOTEL ROOMS ERROR: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An error occurred loading rooms.', 'data' => ['rooms' => []]]);
        exit;
    }
});

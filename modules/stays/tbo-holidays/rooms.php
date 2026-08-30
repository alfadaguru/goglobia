<?php
/**
 * TBO Holidays rooms / live rates
 * POST stays/tbo-holidays/rooms
 */

require_once __DIR__ . '/api.php';

$router->post('stays/tbo-holidays/rooms', function () use ($db) {

    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || empty($input)) {
            $input = $_POST;
        }

        $hotelId = (string) ($input['hotel_id'] ?? '');
        $checkin = trim($input['checkin'] ?? '');
        $checkout = trim($input['checkout'] ?? '');
        $requestedNationality = trim((string) ($input['nationality'] ?? ''));
        $currency = $input['currency'] ?? ($_SESSION['app_currency'] ?? 'USD');
        $sessionCurrency = $_SESSION['app_currency'] ?? $currency;
        $roomsData = $input['rooms_data'] ?? [];

        // Details page sends room count in `rooms` and occupancy in `rooms_data`.
        // Older callers may send the occupancy array in `rooms`, or only adults/children totals.
        if (is_string($roomsData)) {
            $decodedRooms = json_decode($roomsData, true);
            $roomsData = is_array($decodedRooms) ? $decodedRooms : [];
        }
        if (empty($roomsData) && isset($input['rooms']) && is_array($input['rooms'])) {
            $roomsData = $input['rooms'];
        }
        if (!is_array($roomsData)) {
            $roomsData = [];
        } elseif (!empty($roomsData) && !isset($roomsData[0])) {
            $roomsData = [$roomsData];
        }

        // Fallback for payloads that only send totals (common on older frontend builds).
        if (empty($roomsData)) {
            $roomCount = max(1, (int) ($input['rooms'] ?? 1));
            $adults = max(1, (int) ($input['adults'] ?? 2));
            $children = max(0, (int) ($input['children'] ?? $input['childs'] ?? 0));
            $childAges = $input['child_ages'] ?? $input['childAges'] ?? [];
            if (is_string($childAges)) {
                $decodedAges = json_decode($childAges, true);
                $childAges = is_array($decodedAges) ? $decodedAges : [];
            }
            if (!is_array($childAges)) {
                $childAges = [];
            }

            // Distribute adults across rooms as evenly as possible.
            $baseAdults = intdiv($adults, $roomCount);
            $extraAdults = $adults % $roomCount;
            $remainingChildren = $children;
            $remainingAges = array_values($childAges);

            for ($i = 0; $i < $roomCount; $i++) {
                $roomAdults = max(1, $baseAdults + ($i < $extraAdults ? 1 : 0));
                $roomChildren = 0;
                $roomAges = [];
                if ($remainingChildren > 0 && $i === $roomCount - 1) {
                    $roomChildren = $remainingChildren;
                    $roomAges = array_slice($remainingAges, 0, $roomChildren);
                } elseif ($remainingChildren > 0) {
                    $roomChildren = 1;
                    $roomAges = array_slice($remainingAges, 0, 1);
                    $remainingAges = array_slice($remainingAges, 1);
                    $remainingChildren--;
                }
                while (count($roomAges) < $roomChildren) {
                    $roomAges[] = 1;
                }
                $roomsData[] = [
                    'adults' => $roomAdults,
                    'children' => $roomChildren,
                    'childAges' => $roomAges,
                ];
            }
        }

        if (empty($roomsData)) {
            throw new Exception('Room occupancy details are required.');
        }

        if ($hotelId === '') {
            echo json_encode(['success' => false, 'message' => 'Missing required parameter: hotel_id']);
            exit;
        }
        if ($checkin === '' || $checkout === '') {
            echo json_encode(['success' => false, 'message' => 'Missing required parameters: checkin and checkout']);
            exit;
        }

        $module = tboHolidaysGetModule($db);
        if (!$module || empty($module['c1']) || empty($module['c2'])) {
            echo json_encode(['success' => false, 'message' => 'TBO Holidays module not configured']);
            exit;
        }
        $nationality = tboHolidaysResolveNationality($db, $module, $requestedNationality);
        $filters = tboHolidaysSearchFilters($input);

        $moduleCurrency = !empty($module['currency']) ? $module['currency'] : 'USD';
        $nights = tboHolidaysNights($checkin, $checkout);
        $paxRooms = tboHolidaysBuildPaxRooms($roomsData, count($roomsData), 0, 0);

        $payload = [
            'CheckIn' => tboHolidaysParseDate($checkin),
            'CheckOut' => tboHolidaysParseDate($checkout),
            'HotelCodes' => $hotelId,
            'GuestNationality' => $nationality,
            'PaxRooms' => $paxRooms,
            'ResponseTime' => 23.0,
            'IsDetailedResponse' => false,
        ];
        if (!empty($filters)) {
            $payload['Filters'] = $filters;
        }

        $result = tboHolidaysCall($module, 'Search', $payload, 'POST', 23);
        $statusCode = (int) ($result['status_code'] ?? 0);
        if (((int) log_setting($db, 'tbo-holidays')) === 1) {
            logApiCall(
                'RoomsSearch',
                $payload,
                $result['data'] ?? ['error' => $result['error'] ?? null],
                (int) ($result['http_code'] ?? 0),
                __DIR__ . '/logs',
                'search_' . preg_replace('/[^A-Za-z0-9_-]/', '', session_id())
            );
        }

        if ($statusCode === 201) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'hotel_id' => $hotelId,
                    'nights' => $nights,
                    'currency' => $sessionCurrency,
                    'rooms' => []
                ]
            ]);
            exit;
        }

        if (!$result['success'] || $statusCode !== 200) {
            throw new Exception(tboHolidaysStatusMessage(
                $statusCode,
                (string) ($result['error'] ?? $result['data']['Status']['Description'] ?? 'Failed to fetch rooms from TBO Holidays')
            ));
        }

        $hotelResult = $result['data']['HotelResult'][0] ?? null;
        if (!$hotelResult) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'hotel_id' => $hotelId,
                    'nights' => $nights,
                    'currency' => $sessionCurrency,
                    'rooms' => []
                ]
            ]);
            exit;
        }

        $apiCurrency = $hotelResult['Currency'] ?? $moduleCurrency;
        $grouped = [];

        foreach (($hotelResult['Rooms'] ?? []) as $idx => $room) {
            $names = $room['Name'] ?? ['Room'];
            if (!is_array($names)) {
                $names = [$names];
            }
            $roomName = $names[0] ?? 'Room';
            $roomIds = $room['RoomID'] ?? $room['RoomId'] ?? [];
            if (!is_array($roomIds)) {
                $roomIds = [$roomIds];
            }
            $roomId = (string) ($roomIds[0] ?? md5($roomName));

            $totalFare = (float) ($room['TotalFare'] ?? 0);
            $meal = tboHolidaysMealLabel((string) ($room['MealType'] ?? 'Room_Only'));
            $markedTotal = MARKUP($totalFare, $module, $db, $apiCurrency, $sessionCurrency);
            $markedNight = MARKUP($totalFare / $nights, $module, $db, $apiCurrency, $sessionCurrency);

            $cancelPolicies = [];
            foreach (($room['CancelPolicies'] ?? []) as $policy) {
                $cancelPolicies[] = [
                    'amount' => (float) ($policy['CancellationCharge'] ?? 0),
                    'from' => $policy['FromDate'] ?? '',
                    'charge_type' => $policy['ChargeType'] ?? '',
                ];
            }

            $supplements = tboHolidaysNormalizeSupplements($room['Supplements'] ?? [], $apiCurrency);
            $cancellationText = tboHolidaysFormatCancellationPolicies($cancelPolicies, $apiCurrency);

            if (!isset($grouped[$roomId])) {
                $grouped[$roomId] = [
                    'room_id' => $roomId,
                    'room_type_id' => $roomId,
                    'room_name' => $roomName,
                    'room_images' => [],
                    'room_main_image' => '',
                    'amenities' => [],
                    'max_adults' => (int) ($paxRooms[0]['Adults'] ?? 2),
                    'max_children' => (int) ($paxRooms[0]['Children'] ?? 0),
                    'options' => []
                ];
            }

            $grouped[$roomId]['options'][] = [
                'option_index' => count($grouped[$roomId]['options']),
                'max_adults' => (int) ($paxRooms[0]['Adults'] ?? 2),
                'max_children' => (int) ($paxRooms[0]['Children'] ?? 0),
                'price_per_night' => $markedNight['price'],
                'total_price' => $markedTotal['price'],
                'base_price' => $totalFare,
                'currency' => $sessionCurrency,
                'discount_percentage' => 0,
                'breakfast_included' => $meal['breakfast_included'],
                'cancellation_free' => (!empty($room['IsRefundable']) && empty($cancelPolicies)) ? 1 : 0,
                'refundable' => !empty($room['IsRefundable']) ? 1 : 0,
                'available_quantity' => 1,
                'board_id' => $meal['board_id'],
                'board_name' => $meal['board_name'],
                'rate_key' => $room['BookingCode'] ?? '',
                'booking_code' => $room['BookingCode'] ?? '',
                'rate_type' => !empty($room['IsRefundable']) ? 'REFUNDABLE' : 'NON_REFUNDABLE',
                'inclusion' => $room['Inclusion'] ?? '',
                'cancellation_policies' => $cancelPolicies,
                'cancellation_text' => $cancellationText,
                'recommended_selling_rate' => $room['RecommendedSellingRate'] ?? null,
                'supplements' => $supplements,
                'rate_conditions' => array_values((array) ($room['RateConditions'] ?? [])),
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'hotel_id' => $hotelId,
                'nights' => $nights,
                'currency' => $sessionCurrency,
                'original_currency' => $apiCurrency,
                'rooms' => array_values($grouped)
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
});

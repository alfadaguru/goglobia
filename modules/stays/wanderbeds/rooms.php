<?php
/**
 * Wanderbeds rooms / live rates
 * POST stays/wanderbeds/rooms
 * Search → Offers (via productid) → platform room groups
 */

require_once __DIR__ . '/api.php';

$router->post('stays/wanderbeds/rooms', function () use ($db) {

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
                    $roomAges[] = 6;
                }
                $roomsData[] = [
                    'adults' => $roomAdults,
                    'children' => $roomChildren,
                    'childAges' => $roomAges,
                ];
            }
        }

        if ($hotelId === '') {
            echo json_encode(['success' => false, 'message' => 'Missing required parameter: hotel_id']);
            exit;
        }
        if ($checkin === '' || $checkout === '') {
            echo json_encode(['success' => false, 'message' => 'Missing required parameters: checkin and checkout']);
            exit;
        }

        $module = wanderbedsGetModule($db);
        if (!$module || empty($module['c1']) || empty($module['c2'])) {
            echo json_encode(['success' => false, 'message' => 'Wanderbeds module not configured']);
            exit;
        }

        $nationality = wanderbedsResolveNationality($db, $module, $requestedNationality);
        $moduleCurrency = !empty($module['currency']) ? $module['currency'] : 'USD';
        $nights = wanderbedsNights($checkin, $checkout);
        $wbRooms = wanderbedsBuildRooms($roomsData, count($roomsData), 0, 0);

        $searchPayload = [
            'hotels' => [(int) $hotelId],
            'checkin' => wanderbedsParseDate($checkin),
            'checkout' => wanderbedsParseDate($checkout),
            'rooms' => $wbRooms,
            'nationality' => $nationality,
            'timout' => '20',
            'cheapestonly' => false,
        ];

        $search = wanderbedsCall($module, 'hotel/search', $searchPayload, 'POST', 30);
        if (((int) log_setting($db, 'wanderbeds')) === 1) {
            logApiCall(
                'RoomsSearch',
                $searchPayload,
                $search['data'] ?? ['error' => $search['error'] ?? null],
                (int) ($search['http_code'] ?? 0),
                __DIR__ . '/logs',
                'search_' . preg_replace('/[^A-Za-z0-9_-]/', '', session_id())
            );
        }

        if (!$search['success']) {
            throw new Exception((string) ($search['error'] ?? 'Failed to fetch rooms from Wanderbeds'));
        }

        $token = $search['token'] ?? null;
        $hotelResult = $search['data']['hotels'][0] ?? null;
        if (!$hotelResult) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'hotel_id' => $hotelId,
                    'nights' => $nights,
                    'currency' => $sessionCurrency,
                    'rooms' => [],
                ],
            ]);
            exit;
        }

        $productId = (string) ($hotelResult['productid'] ?? '');
        // Search returns a reduced room set; hotel details must prefer hotel/offers.
        $offers = is_array($hotelResult['rooms'] ?? null) ? $hotelResult['rooms'] : [];
        $offersSource = 'search';

        if ($productId !== '' && $token) {
            $offersResult = wanderbedsCall(
                $module,
                'hotel/offers',
                ['productid' => $productId],
                'POST',
                30,
                $token
            );
            if (((int) log_setting($db, 'wanderbeds')) === 1) {
                logApiCall(
                    'RoomsOffers',
                    ['productid' => $productId],
                    $offersResult['data'] ?? ['error' => $offersResult['error'] ?? null],
                    (int) ($offersResult['http_code'] ?? 0),
                    __DIR__ . '/logs',
                    'search_' . preg_replace('/[^A-Za-z0-9_-]/', '', session_id())
                );
            }
            if ($offersResult['success']) {
                if (!empty($offersResult['token'])) {
                    $token = $offersResult['token'];
                }
                $offersList = wanderbedsExtractOffersList($offersResult['data'] ?? null);
                // Replace search rooms with the full offers list (do not merge — that duplicates).
                if (!empty($offersList)) {
                    $offers = $offersList;
                    $offersSource = 'offers';
                }
            }
        }

        if (!is_array($offers) || empty($offers)) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'hotel_id' => $hotelId,
                    'nights' => $nights,
                    'currency' => $sessionCurrency,
                    'rooms' => [],
                    'wb_token' => $token,
                    'product_id' => $productId,
                    'offers_source' => $offersSource,
                ],
            ]);
            exit;
        }

        $apiCurrency = $moduleCurrency;
        $grouped = [];
        $seenOfferIds = [];
        $searchedRoomCount = max(1, count($wbRooms));
        $maxAdults = (int) ($wbRooms[0]['adt'] ?? 2);
        $maxChildren = (int) ($wbRooms[0]['chd'] ?? 0);

        // Index offers by group + roomindex for multi-room sibling lookup.
        $offersByGroup = [];
        foreach ($offers as $room) {
            if (!is_array($room) || empty($room['offerid'])) {
                continue;
            }
            $g = (int) ($room['group'] ?? 0);
            $ri = (int) ($room['roomindex'] ?? 0);
            if ($g <= 0) {
                continue;
            }
            if ($ri <= 0) {
                $ri = 1;
            }
            $offersByGroup[$g][$ri][] = $room;
        }

        // Wanderbeds rates have no room photos — reuse hotel gallery like Travelport.
        $hotelImages = wanderbedsResolveHotelImages($module, $hotelId, 15);
        $imageIndex = 0;

        foreach ($offers as $idx => $room) {
            if (!is_array($room)) {
                continue;
            }

            $wbGroup = (int) ($room['group'] ?? 0);
            $wbRoomIndex = (int) ($room['roomindex'] ?? 0);
            // Multi-room search returns one offer per roomindex — only show primary slot
            // (roomindex 1) so the hotel page does not list duplicate cards.
            if ($searchedRoomCount > 1 && $wbRoomIndex > 1) {
                continue;
            }

            $roomType = is_array($room['roomtype'] ?? null) ? $room['roomtype'] : [];
            $rawName = trim((string) ($room['name'] ?? ($roomType['name'] ?? 'Room')));
            if ($rawName === '') {
                $rawName = 'Room';
            }
            // Group by specific offer name (not coarse catalog roomtype.code).
            // Catalog codes like "Suite" / "Deluxe Room" collapse distinct products into one card.
            $roomKey = strtolower(preg_replace('/\s+/', ' ', $rawName));
            $roomTypeCode = (string) ($roomType['code'] ?? '');
            $roomId = $roomTypeCode !== ''
                ? ($roomTypeCode . ':' . md5($roomKey))
                : md5($roomKey);

            $price = is_array($room['price'] ?? null) ? $room['price'] : [];
            $priceBreakdown = wanderbedsNormalizePriceBreakdown($price, $apiCurrency);
            $totalFare = (float) ($priceBreakdown['total'] > 0 ? $priceBreakdown['total'] : $priceBreakdown['baseprice']);
            $apiCurrency = (string) ($priceBreakdown['currency'] !== '' ? $priceBreakdown['currency'] : $apiCurrency);
            $priceBreakdown['currency'] = $apiCurrency;
            $meal = wanderbedsMealLabel((array) ($room['meal'] ?? []));
            $mealName = (string) ($room['meal']['name'] ?? $meal['board_name'] ?? '');

            // Build sibling offer IDs for the same group (one per searched roomindex).
            $groupOfferIds = [];
            $packageNetTotal = 0.0;
            if ($searchedRoomCount > 1 && $wbGroup > 0 && !empty($offersByGroup[$wbGroup])) {
                for ($ri = 1; $ri <= $searchedRoomCount; $ri++) {
                    $pool = $offersByGroup[$wbGroup][$ri] ?? [];
                    $sibling = null;
                    foreach ($pool as $cand) {
                        if (!is_array($cand)) {
                            continue;
                        }
                        $candName = trim((string) ($cand['name'] ?? ''));
                        $candMeal = (string) ($cand['meal']['name'] ?? '');
                        if (
                            strcasecmp($candName, $rawName) === 0
                            || ($mealName !== '' && strcasecmp($candMeal, $mealName) === 0)
                        ) {
                            $sibling = $cand;
                            break;
                        }
                    }
                    if ($sibling === null && !empty($pool[0]) && is_array($pool[0])) {
                        $sibling = $pool[0];
                    }
                    if ($sibling === null) {
                        $groupOfferIds = [];
                        $packageNetTotal = 0.0;
                        break;
                    }
                    $groupOfferIds[] = (string) $sibling['offerid'];
                    $sp = is_array($sibling['price'] ?? null) ? $sibling['price'] : [];
                    $packageNetTotal += (float) ($sp['total'] ?? $sp['baseprice'] ?? 0);
                }
            }

            // Display price: for multi-room complete groups use package total / rooms as unit,
            // so qty selector × searched rooms equals package net (before markup).
            $unitFare = $totalFare;
            if ($searchedRoomCount > 1 && count($groupOfferIds) === $searchedRoomCount && $packageNetTotal > 0) {
                $unitFare = $packageNetTotal / $searchedRoomCount;
            }
            $markedTotal = MARKUP($unitFare, $module, $db, $apiCurrency, $sessionCurrency);
            $markedNight = MARKUP($unitFare / max(1, $nights), $module, $db, $apiCurrency, $sessionCurrency);

            $cancelPolicy = $room['cancelpolicy'] ?? [];
            $cancelPolicies = [];
            if (is_array($cancelPolicy) && !empty($cancelPolicy)) {
                $cancelPolicies[] = [
                    'amount' => (float) ($cancelPolicy['amount'] ?? 0),
                    'from' => $cancelPolicy['from'] ?? '',
                    'currency' => $cancelPolicy['currency'] ?? $apiCurrency,
                ];
            }
            $cancellationText = wanderbedsFormatCancellation($cancelPolicy, $apiCurrency);
            $isFreeCancel = wanderbedsIsCancellationFree($room);
            $offerId = (string) ($room['offerid'] ?? ($hotelId . '_' . $idx));
            $viewName = wanderbedsViewLabel($room['view'] ?? null);
            $supplements = wanderbedsNormalizeAdditionalFees($room['additionalfees'] ?? null, $apiCurrency);
            $inclusion = wanderbedsOfferInclusion($room);

            // Skip exact offerid repeats (can happen if search + offers were merged historically).
            if ($offerId !== '' && isset($seenOfferIds[$offerId])) {
                continue;
            }
            if ($offerId !== '') {
                $seenOfferIds[$offerId] = true;
            }

            if (!isset($grouped[$roomKey])) {
                $roomImages = wanderbedsAssignRoomImages($hotelImages, $imageIndex, 3);
                $grouped[$roomKey] = [
                    'room_id' => $roomId,
                    'room_type_id' => $roomTypeCode !== '' ? $roomTypeCode : $roomId,
                    'room_name' => $rawName,
                    'room_images' => $roomImages,
                    'room_main_image' => $roomImages[0] ?? '',
                    'amenities' => [],
                    'max_adults' => $maxAdults,
                    'max_children' => $maxChildren,
                    'options' => [],
                    '_option_keys' => [],
                ];
            } else {
                // Prefer a title-cased / mixed-case label over ALL CAPS duplicates.
                $existingName = (string) $grouped[$roomKey]['room_name'];
                $existingIsAllCaps = $existingName === strtoupper($existingName) && preg_match('/[A-Z]/', $existingName);
                $incomingIsAllCaps = $rawName === strtoupper($rawName) && preg_match('/[A-Z]/', $rawName);
                if ($existingIsAllCaps && !$incomingIsAllCaps) {
                    $grouped[$roomKey]['room_name'] = $rawName;
                }
            }

            // Dedup identical rates (do not key on roomindex — multi-room slots are hidden above).
            $optionKey = implode('|', [
                (string) $meal['board_id'],
                number_format($unitFare, 2, '.', ''),
                !empty($room['refundable']) ? '1' : '0',
                (string) ($cancelPolicies[0]['from'] ?? ''),
                number_format((float) ($cancelPolicies[0]['amount'] ?? 0), 2, '.', ''),
                strtolower($viewName),
                (string) $wbGroup,
            ]);
            if (isset($grouped[$roomKey]['_option_keys'][$optionKey])) {
                continue;
            }
            $grouped[$roomKey]['_option_keys'][$optionKey] = true;

            $availableQty = 1;
            if ($searchedRoomCount > 1 && count($groupOfferIds) === $searchedRoomCount) {
                $availableQty = $searchedRoomCount;
            }

            $grouped[$roomKey]['options'][] = [
                'option_index' => count($grouped[$roomKey]['options']),
                'max_adults' => $maxAdults,
                'max_children' => $maxChildren,
                'price_per_night' => $markedNight['price'],
                'total_price' => $markedTotal['price'],
                'base_price' => $unitFare,
                'price_breakdown' => $priceBreakdown,
                'currency' => $sessionCurrency,
                'discount_percentage' => 0,
                'breakfast_included' => $meal['breakfast_included'],
                'cancellation_free' => $isFreeCancel ? 1 : 0,
                'refundable' => !empty($room['refundable']) ? 1 : 0,
                'available_quantity' => $availableQty,
                'board_id' => $meal['board_id'],
                'board_name' => $meal['board_name'],
                'view_name' => $viewName,
                'package' => !empty($room['package']) ? 1 : 0,
                'rate_key' => $offerId,
                'offer_id' => $offerId,
                'group_offer_ids' => $groupOfferIds,
                'product_id' => $productId,
                'wb_token' => $token,
                'wb_group' => $wbGroup,
                'wb_roomindex' => $wbRoomIndex > 0 ? $wbRoomIndex : 1,
                'group' => $wbGroup,
                'roomindex' => $wbRoomIndex > 0 ? $wbRoomIndex : 1,
                'rate_type' => !empty($room['refundable']) ? 'REFUNDABLE' : 'NON_REFUNDABLE',
                'inclusion' => $inclusion,
                'cancellation_policies' => $cancelPolicies,
                'cancellation_text' => $cancellationText,
                'supplements' => $supplements,
                'rate_conditions' => wanderbedsNormalizeRemarks($room['remarks'] ?? [], (string) ($meal['board_name'] ?? '')),
                'remarks' => $room['remarks'] ?? [],
            ];
        }

        // Multi-room: expose valid same-group combinations (one offer per roomindex).
        $offerGroups = [];
        if ($searchedRoomCount > 1) {
            foreach ($offersByGroup as $g => $slots) {
                $complete = true;
                $combo = [];
                for ($ri = 1; $ri <= $searchedRoomCount; $ri++) {
                    if (empty($slots[$ri][0])) {
                        $complete = false;
                        break;
                    }
                    $o = $slots[$ri][0];
                    $combo[] = [
                        'offer_id' => (string) $o['offerid'],
                        'roomindex' => $ri,
                        'name' => (string) ($o['name'] ?? ''),
                        'meal' => (string) ($o['meal']['name'] ?? ''),
                        'price' => (float) ($o['price']['total'] ?? 0),
                    ];
                }
                if ($complete) {
                    $offerGroups[] = [
                        'group' => (int) $g,
                        'rooms' => $combo,
                    ];
                }
            }
        }

        $roomsOut = [];
        foreach ($grouped as $roomGroup) {
            unset($roomGroup['_option_keys']);
            usort($roomGroup['options'], static function ($a, $b) {
                return ($a['total_price'] <=> $b['total_price']);
            });
            foreach ($roomGroup['options'] as $i => &$opt) {
                $opt['option_index'] = $i;
            }
            unset($opt);
            $roomsOut[] = $roomGroup;
        }
        usort($roomsOut, static function ($a, $b) {
            $aMin = isset($a['options'][0]['total_price']) ? (float) $a['options'][0]['total_price'] : PHP_FLOAT_MAX;
            $bMin = isset($b['options'][0]['total_price']) ? (float) $b['options'][0]['total_price'] : PHP_FLOAT_MAX;
            return $aMin <=> $bMin;
        });

        echo json_encode([
            'success' => true,
            'data' => [
                'hotel_id' => $hotelId,
                'nights' => $nights,
                'currency' => $sessionCurrency,
                'original_currency' => $apiCurrency,
                'wb_token' => $token,
                'product_id' => $productId,
                'offers_source' => $offersSource,
                'searched_rooms' => $searchedRoomCount,
                'offer_groups' => $offerGroups,
                'rooms' => $roomsOut,
            ],
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
});

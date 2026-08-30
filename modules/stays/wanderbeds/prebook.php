<?php
/**
 * Wanderbeds checkout prebook (Search → Offers → Avail)
 * POST stays/wanderbeds/prebook
 *
 * Pulls final cancellation, hotel-payable fees, Avail summary/required,
 * and rematches offers by group + roomindex (never silent first-offer).
 */

require_once __DIR__ . '/api.php';

$router->post('stays/wanderbeds/prebook', function () use ($db) {
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

        $offerId = trim((string) ($input['offer_id'] ?? $input['rate_key'] ?? ''));
        $hotelId = trim((string) ($input['hotel_id'] ?? ''));
        $checkin = trim((string) ($input['checkin'] ?? ''));
        $checkout = trim((string) ($input['checkout'] ?? ''));
        $bookingHash = trim((string) ($input['booking_hash'] ?? ''));
        $requestedNationality = trim((string) ($input['nationality'] ?? ''));
        $sessionCurrency = $input['currency'] ?? ($_SESSION['app_currency'] ?? 'USD');
        $roomsData = $input['rooms_data'] ?? [];
        $draftData = [];

        if ($bookingHash !== '') {
            $draft = $db->get('logs_bookings', '*', ['hash' => $bookingHash]);
            $draftData = $draft ? json_decode($draft['data'] ?? '{}', true) : null;
            if (is_array($draftData) && ($draftData['supplier'] ?? '') === 'wanderbeds') {
                $selected = $draftData['selected_rooms'][0] ?? [];
                if ($offerId === '') {
                    $offerId = (string) ($selected['offer_id']
                        ?? $selected['rate_key']
                        ?? ($selected['option']['offer_id'] ?? null)
                        ?? ($selected['option']['rate_key'] ?? ''));
                }
                if ($hotelId === '') {
                    $hotelId = (string) ($draftData['hotel_id'] ?? '');
                }
                if ($checkin === '') {
                    $checkin = (string) ($draftData['checkin'] ?? '');
                }
                if ($checkout === '') {
                    $checkout = (string) ($draftData['checkout'] ?? '');
                }
                if ($requestedNationality === '') {
                    $requestedNationality = (string) ($draftData['nationality'] ?? '');
                }
                if (empty($roomsData) && !empty($draftData['rooms_data']) && is_array($draftData['rooms_data'])) {
                    $roomsData = $draftData['rooms_data'];
                }
            } else {
                $draftData = [];
            }
        }

        if ($hotelId === '' || $checkin === '' || $checkout === '') {
            throw new Exception('Hotel and stay dates are required for Wanderbeds prebook.');
        }

        $module = wanderbedsGetModule($db);
        if (!$module || empty($module['c1']) || empty($module['c2'])) {
            throw new Exception('Wanderbeds module not configured.');
        }

        if (!is_array($roomsData) || empty($roomsData)) {
            $roomsData = [['adults' => 2, 'children' => 0]];
        } elseif (!isset($roomsData[0])) {
            $roomsData = [$roomsData];
        }

        $bookingContext = is_array($draftData) ? $draftData : [];
        if ($offerId !== '' && empty($bookingContext['selected_rooms'])) {
            $bookingContext['offer_id'] = $offerId;
        }
        if (!empty($input['selected_rooms']) && is_array($input['selected_rooms'])) {
            $bookingContext['selected_rooms'] = $input['selected_rooms'];
        }
        $hints = wanderbedsCollectSelectedOfferHints($bookingContext);
        if ($hints === [] && $offerId !== '') {
            $hints[] = [
                'offer_id' => $offerId,
                'room_name' => '',
                'board_name' => '',
                'group' => 0,
                'roomindex' => 0,
            ];
        }
        if ($hints === []) {
            throw new Exception('Missing Wanderbeds offer ID.');
        }

        $wbRooms = wanderbedsBuildRooms($roomsData, count($roomsData), 0, 0);
        $roomCount = max(1, count($wbRooms));
        $resolvedNat = wanderbedsResolveNationality($db, $module, $requestedNationality);
        $natCandidates = wanderbedsCollectIssueNationalities(
            ['nationality' => $requestedNationality],
            [],
            $resolvedNat
        );

        $logsPath = __DIR__ . '/logs';
        $logRef = preg_replace('/[^A-Za-z0-9_-]/', '', $bookingHash ?: ('prebook_' . $hotelId));

        $search = null;
        $searchPayload = null;
        $usedNat = $resolvedNat;
        foreach ($natCandidates as $candidateNat) {
            $usedNat = $candidateNat;
            $searchPayload = [
                'hotels' => [(int) $hotelId],
                'checkin' => wanderbedsParseDate($checkin),
                'checkout' => wanderbedsParseDate($checkout),
                'rooms' => $wbRooms,
                'nationality' => $candidateNat,
                'timout' => '25',
                'cheapestonly' => false,
            ];
            $search = wanderbedsCall($module, 'hotel/search', $searchPayload, 'POST', 40);
            if ($search['success'] && !empty($search['token']) && !empty($search['data']['hotels'][0])) {
                break;
            }
            if (!wanderbedsIsNoResultsError($search['error'] ?? '')) {
                break;
            }
        }

        logApiCall(
            'PrebookSearch',
            wanderbedsSanitizeForLog($searchPayload),
            wanderbedsSanitizeForLog($search['data'] ?? ['error' => $search['error'] ?? null]),
            (int) ($search['http_code'] ?? 0),
            $logsPath,
            'booking_' . $logRef . '_prebook_search'
        );

        if (!$search || !$search['success'] || empty($search['token']) || empty($search['data']['hotels'][0])) {
            throw new Exception(wanderbedsFormatError($search['error'] ?? 'Wanderbeds prebook search failed.'));
        }

        $token = $search['token'];
        $productId = (string) ($search['data']['hotels'][0]['productid'] ?? '');
        $allOffers = $search['data']['hotels'][0]['rooms'] ?? [];

        if ($productId !== '') {
            $offersResult = wanderbedsCall(
                $module,
                'hotel/offers',
                ['productid' => $productId],
                'POST',
                30,
                $token
            );
            logApiCall(
                'PrebookOffers',
                ['productid' => $productId],
                wanderbedsSanitizeForLog($offersResult['data'] ?? ['error' => $offersResult['error'] ?? null]),
                (int) ($offersResult['http_code'] ?? 0),
                $logsPath,
                'booking_' . $logRef . '_prebook_offers'
            );
            if ($offersResult['success'] && !empty($offersResult['token'])) {
                $token = $offersResult['token'];
            }
            $offersList = wanderbedsExtractOffersList($offersResult['data'] ?? null);
            if (!empty($offersList)) {
                $allOffers = $offersList;
            }
        }

        $resolved = wanderbedsResolveOffersForAvail((array) $allOffers, $hints, $roomCount);
        $offerIds = $resolved['offer_ids'];
        $offerId = $offerIds[0];

        $availPayload = ['rooms' => array_map('strval', $offerIds)];
        $avail = wanderbedsCall($module, 'hotel/avail', $availPayload, 'POST', 40, $token);
        logApiCall(
            'PrebookAvail',
            $availPayload,
            wanderbedsSanitizeForLog($avail['data'] ?? ['error' => $avail['error'] ?? null]),
            (int) ($avail['http_code'] ?? 0),
            $logsPath,
            'booking_' . $logRef . '_prebook_avail'
        );

        if (!$avail['success']) {
            throw new Exception(wanderbedsFormatError($avail['error'] ?? 'Wanderbeds availability check failed.'));
        }
        if (!empty($avail['token'])) {
            $token = $avail['token'];
        }

        $parsed = wanderbedsParseAvailPayload($avail['data'] ?? []);
        if (!$parsed['success']) {
            throw new Exception(wanderbedsDescribeSoftFailure('hotel/avail', $avail['data']['data'] ?? ($avail['data'] ?? [])));
        }
        if (empty($parsed['rooms'][0])) {
            throw new Exception('Wanderbeds Avail returned no room details.');
        }

        $moduleCurrency = !empty($module['currency']) ? $module['currency'] : 'USD';
        $nights = max(1, wanderbedsNights($checkin, $checkout));
        $summary = $parsed['summary'];
        $summaryNorm = $parsed['summary_normalized'] ?? wanderbedsNormalizeSummary($summary);
        $hotelRemarks = $parsed['hotel_remarks'] ?? [];
        $apiCurrency = (string) ($summaryNorm['currency'] !== '' ? $summaryNorm['currency'] : $moduleCurrency);
        $netTotal = (float) ($summaryNorm['total'] > 0
            ? $summaryNorm['total']
            : ($summaryNorm['nettotal'] > 0 ? $summaryNorm['nettotal'] : 0));

        $roomPayloads = [];
        $totalFare = 0.0;
        foreach ($parsed['rooms'] as $room) {
            $price = is_array($room['price'] ?? null) ? $room['price'] : [];
            $priceBreakdown = wanderbedsNormalizePriceBreakdown($price, $apiCurrency);
            $apiCurrency = (string) ($priceBreakdown['currency'] !== '' ? $priceBreakdown['currency'] : $apiCurrency);
            $priceBreakdown['currency'] = $apiCurrency;
            $fare = (float) ($priceBreakdown['total'] > 0 ? $priceBreakdown['total'] : $priceBreakdown['baseprice']);
            $totalFare += $fare;
            $meal = wanderbedsMealLabel((array) ($room['meal'] ?? []));
            $cancelPolicy = $room['cancelpolicy'] ?? [];
            $cancelPolicies = [];
            if (is_array($cancelPolicy) && !empty($cancelPolicy)) {
                $cancelPolicies[] = [
                    'amount' => (float) ($cancelPolicy['amount'] ?? 0),
                    'from' => $cancelPolicy['from'] ?? '',
                    'currency' => $cancelPolicy['currency'] ?? $apiCurrency,
                ];
            }
            $roomPayloads[] = [
                'offer_id' => (string) ($room['offerid'] ?? ''),
                'room_name' => (string) ($room['name'] ?? ''),
                'wb_group' => (int) ($room['group'] ?? $resolved['group']),
                'wb_roomindex' => (int) ($room['roomindex'] ?? 0),
                'board_name' => $meal['board_name'],
                'board_id' => $meal['board_id'],
                'breakfast_included' => $meal['breakfast_included'],
                'refundable' => !empty($room['refundable']) ? 1 : 0,
                'package' => !empty($room['package']) ? 1 : 0,
                'cancellation_free' => wanderbedsIsCancellationFree($room) ? 1 : 0,
                'base_price' => $fare,
                'price_breakdown' => $priceBreakdown,
                'cancellation_policies' => $cancelPolicies,
                'cancellation_text' => wanderbedsFormatCancellation($cancelPolicy, $apiCurrency),
                'supplements' => wanderbedsNormalizeAdditionalFees($room['additionalfees'] ?? null, $apiCurrency),
                'rate_conditions' => wanderbedsNormalizeRemarks($room['remarks'] ?? [], (string) ($meal['board_name'] ?? '')),
                'remarks' => array_values((array) ($room['remarks'] ?? [])),
                'inclusion' => wanderbedsOfferInclusion($room),
                'view_name' => wanderbedsViewLabel($room['view'] ?? null),
            ];
        }

        if ($netTotal <= 0) {
            $netTotal = $totalFare;
        }
        $markedTotal = MARKUP($netTotal, $module, $db, $apiCurrency, $sessionCurrency);
        $markedNight = MARKUP($netTotal / $nights, $module, $db, $apiCurrency, $sessionCurrency);
        $primary = $roomPayloads[0];

        echo json_encode([
            'success' => true,
            'data' => [
                'offer_id' => $primary['offer_id'] !== '' ? $primary['offer_id'] : $offerId,
                'offer_ids' => $offerIds,
                'rate_key' => $primary['offer_id'] !== '' ? $primary['offer_id'] : $offerId,
                'product_id' => $productId,
                'wb_token' => $token,
                'wb_group' => (int) $resolved['group'],
                'rematched' => !empty($resolved['rematched']),
                'rematch_message' => (string) ($resolved['message'] ?? ''),
                'nationality' => $usedNat,
                'room_name' => $primary['room_name'],
                'board_name' => $primary['board_name'],
                'board_id' => $primary['board_id'],
                'breakfast_included' => $primary['breakfast_included'],
                'refundable' => $primary['refundable'],
                'package' => $primary['package'],
                'cancellation_free' => $primary['cancellation_free'],
                'total_price' => $markedTotal['price'],
                'price_per_night' => $markedNight['price'],
                'base_price' => $netTotal,
                'price_breakdown' => $primary['price_breakdown'],
                'currency' => $sessionCurrency,
                'original_currency' => $apiCurrency,
                'cancellation_policies' => $primary['cancellation_policies'],
                'cancellation_text' => $primary['cancellation_text'],
                'supplements' => $primary['supplements'],
                'rate_conditions' => $primary['rate_conditions'],
                'remarks' => $primary['remarks'],
                'hotel_remarks' => $hotelRemarks,
                'inclusion' => $primary['inclusion'],
                'view_name' => $primary['view_name'],
                'rooms' => $roomPayloads,
                'summary' => $summary,
                'summary_normalized' => $summaryNorm,
                'required' => $parsed['required'],
                'required_fields' => $parsed['required_fields'],
            ],
        ]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
});

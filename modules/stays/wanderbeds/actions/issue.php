<?php
/**
 * Wanderbeds booking issue (Search → Offers → Avail → Book)
 * POST stays/wanderbeds/issue
 *
 * Always writes a per-invoice timeline log:
 * modules/stays/wanderbeds/logs/booking_{invoice}_timeline_YYYY-MM-DD.json
 */

require_once __DIR__ . '/../api.php';

$router->post('stays/wanderbeds/issue', function () use ($db) {

    if (function_exists('set_time_limit')) {
        @set_time_limit(360);
    }
    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    $invoiceId = '';
    $booking = null;
    $timelineFile = '';
    $lastStep = 'init';

    try {
        if (!$db) {
            throw new Exception('Database connection not available');
        }

        $invoiceId = (string) ($_POST['invoice_id'] ?? '');
        if ($invoiceId === '') {
            throw new Exception('Missing invoice_id parameter');
        }

        $timelineFile = wanderbedsBookingLogPath($invoiceId);
        wanderbedsLogBookingStep($invoiceId, 'start', [
            'invoice_id' => $invoiceId,
        ], ['note' => 'Wanderbeds issue started'], 0, true, 'Issue flow started');

        $booking = $db->get('bookings', '*', [
            'invoice_id' => $invoiceId,
            'module' => 'wanderbeds',
        ]);
        if (!$booking) {
            throw new Exception('Booking not found for invoice_id: ' . $invoiceId);
        }

        $moduleData = wanderbedsGetModule($db);
        if (!$moduleData || empty($moduleData['c1']) || empty($moduleData['c2'])) {
            throw new Exception('Wanderbeds module credentials not configured');
        }

        $bookingData = json_decode($booking['booking_data'] ?? '{}', true);
        $travellers = json_decode($booking['travellers'] ?? '[]', true);
        if (!is_array($bookingData)) {
            throw new Exception('Invalid booking_data');
        }
        if (!is_array($travellers)) {
            $travellers = [];
        }

        $offerId = $bookingData['offer_id']
            ?? $bookingData['rate_key']
            ?? null;
        if (!$offerId && !empty($bookingData['selected_rooms']) && is_array($bookingData['selected_rooms'])) {
            $first = reset($bookingData['selected_rooms']);
            $offerId = $first['offer_id']
                ?? $first['rate_key']
                ?? ($first['option']['offer_id'] ?? null)
                ?? ($first['option']['rate_key'] ?? null);
        }
        $offerHints = wanderbedsCollectSelectedOfferHints($bookingData);
        if ($offerHints === [] && !empty($offerId)) {
            $offerHints[] = [
                'offer_id' => (string) $offerId,
                'room_name' => '',
                'board_name' => '',
                'group' => 0,
                'roomindex' => 0,
            ];
        }
        if ($offerHints === []) {
            throw new Exception('Missing offer ID. Please search again and select a room rate.');
        }
        $offerId = (string) ($offerHints[0]['offer_id'] ?? $offerId);

        $hotelId = (string) ($bookingData['hotel_id']
            ?? $bookingData['hotel']['hotel_id']
            ?? $booking['hotel_id']
            ?? '');
        $checkin = (string) ($bookingData['checkin'] ?? $booking['checkin'] ?? '');
        $checkout = (string) ($bookingData['checkout'] ?? $booking['checkout'] ?? '');
        if ($hotelId === '' || $checkin === '' || $checkout === '') {
            throw new Exception('Hotel and stay dates are required for Wanderbeds booking.');
        }

        $roomsData = $bookingData['rooms_data'] ?? [];
        if (!is_array($roomsData) || empty($roomsData)) {
            $roomsData = [['adults' => 2, 'children' => 0]];
        }
        $wbRooms = wanderbedsBuildRooms($roomsData, count($roomsData), 0, 0);
        $nationality = wanderbedsResolveNationality(
            $db,
            $moduleData,
            $bookingData['nationality'] ?? null
        );

        $logsPath = __DIR__ . '/../logs';
        $logBase = 'booking_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $invoiceId);
        $logStep = static function (string $step) use ($logBase): string {
            return $logBase . '_' . $step;
        };

        $recordStep = static function (
            string $step,
            $request,
            array $call,
            string $fallbackMessage = ''
        ) use ($invoiceId, $logsPath, $logStep, &$lastStep, &$timelineFile): void {
            $lastStep = $step;
            $success = !empty($call['success']);
            $httpCode = (int) ($call['http_code'] ?? 0);
            if (!empty($call['error'])) {
                $message = wanderbedsFormatError($call['error']);
            } elseif ($success) {
                $message = 'OK';
            } else {
                $message = wanderbedsFormatError($fallbackMessage !== '' ? $fallbackMessage : 'Failed');
            }

            logApiCall(
                $step,
                wanderbedsSanitizeForLog($request),
                wanderbedsSanitizeForLog($call['data'] ?? ['error' => $call['error'] ?? null]),
                $httpCode,
                $logsPath,
                $logStep(strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $step)))
            );

            $timelineFile = wanderbedsLogBookingStep(
                $invoiceId,
                $step,
                $request,
                $call['data'] ?? ['error' => $call['error'] ?? null],
                $httpCode,
                $success,
                $message
            );
        };

        // Fresh search to obtain a live Token + matching offer.
        // Retry nationality fallbacks when Wanderbeds returns Code 100 No results
        // (some ISO codes like AX have no inventory even for available hotels).
        $lastStep = 'IssueSearch';
        $nationalityCandidates = wanderbedsCollectIssueNationalities($bookingData, $travellers, $nationality);
        $search = null;
        $searchPayload = null;
        $usedNationality = $nationality;

        foreach ($nationalityCandidates as $index => $candidateNat) {
            $usedNationality = $candidateNat;
            $searchPayload = [
                'hotels' => [(int) $hotelId],
                'checkin' => wanderbedsParseDate($checkin),
                'checkout' => wanderbedsParseDate($checkout),
                'rooms' => $wbRooms,
                'nationality' => $candidateNat,
                'timout' => '25',
                'cheapestonly' => false,
            ];
            $search = wanderbedsCall($moduleData, 'hotel/search', $searchPayload, 'POST', 40);
            $stepName = $index === 0 ? 'IssueSearch' : ('IssueSearchRetry_' . $candidateNat);
            $recordStep($stepName, $searchPayload, $search, 'Wanderbeds search failed before booking.');

            if ($search['success'] && !empty($search['token']) && !empty($search['data']['hotels'][0])) {
                $nationality = $candidateNat;
                break;
            }

            $canRetry = wanderbedsIsNoResultsError($search['error'] ?? '')
                || empty($search['data']['hotels'][0]);
            if (!$canRetry) {
                break;
            }
        }

        if (!$search || !$search['success'] || empty($search['token']) || empty($search['data']['hotels'][0])) {
            $err = wanderbedsFormatError($search['error'] ?? 'Wanderbeds search failed before booking.');
            throw new Exception(
                $err . ' (hotel ' . $hotelId . ', dates '
                . wanderbedsParseDate($checkin) . ' → ' . wanderbedsParseDate($checkout)
                . ', nationality tried: ' . implode(', ', $nationalityCandidates) . ')'
            );
        }
        $token = $search['token'];
        $productId = (string) ($search['data']['hotels'][0]['productid'] ?? '');
        $bookingData['wb_search_nationality'] = $usedNationality;
        $roomCount = max(1, count($wbRooms));
        $allOffers = $search['data']['hotels'][0]['rooms'] ?? [];

        if ($productId !== '') {
            $lastStep = 'IssueOffers';
            $offersPayload = ['productid' => $productId];
            $offersResult = wanderbedsCall(
                $moduleData,
                'hotel/offers',
                $offersPayload,
                'POST',
                30,
                $token
            );
            $recordStep('IssueOffers', $offersPayload, $offersResult, 'Wanderbeds offers failed.');
            if ($offersResult['success'] && !empty($offersResult['token'])) {
                $token = $offersResult['token'];
            }
            $offersList = wanderbedsExtractOffersList($offersResult['data'] ?? null);
            if (!empty($offersList)) {
                $allOffers = $offersList;
            }
        }

        try {
            $resolved = wanderbedsResolveOffersForAvail((array) $allOffers, $offerHints, $roomCount);
        } catch (Exception $resolveEx) {
            wanderbedsLogBookingStep(
                $invoiceId,
                'OfferRematch',
                ['hints' => $offerHints, 'room_count' => $roomCount],
                ['error' => $resolveEx->getMessage()],
                0,
                false,
                $resolveEx->getMessage()
            );
            throw $resolveEx;
        }

        $offerIds = $resolved['offer_ids'];
        $offerId = $offerIds[0];
        if (!empty($resolved['rematched'])) {
            wanderbedsLogBookingStep(
                $invoiceId,
                'OfferRematch',
                ['hints' => $offerHints, 'room_count' => $roomCount],
                [
                    'matched' => true,
                    'offer_ids' => $offerIds,
                    'group' => $resolved['group'],
                    'message' => $resolved['message'],
                ],
                0,
                true,
                $resolved['message'] !== '' ? $resolved['message'] : 'Offer rematched'
            );
        }

        $lastStep = 'Avail';
        $availPayload = ['rooms' => array_map('strval', $offerIds)];
        $avail = wanderbedsCall($moduleData, 'hotel/avail', $availPayload, 'POST', 40, $token);
        $recordStep('Avail', $availPayload, $avail, 'Wanderbeds availability check failed.');
        if (!$avail['success']) {
            throw new Exception(wanderbedsFormatError($avail['error'] ?? 'Wanderbeds availability check failed.'));
        }
        if (!empty($avail['token'])) {
            $token = $avail['token'];
        }
        $parsedAvail = wanderbedsParseAvailPayload($avail['data'] ?? []);
        if (!$parsedAvail['success']) {
            throw new Exception(wanderbedsDescribeSoftFailure('hotel/avail', $avail['data']['data'] ?? ($avail['data'] ?? [])));
        }
        if (!empty($parsedAvail['required_fields'])) {
            $bookingData['wb_avail_required'] = $parsedAvail['required_fields'];
        }
        if (!empty($parsedAvail['summary'])) {
            $bookingData['wb_avail_summary'] = $parsedAvail['summary'];
            $bookingData['wb_avail_summary_normalized'] = $parsedAvail['summary_normalized']
                ?? wanderbedsNormalizeSummary($parsedAvail['summary']);
        }
        if (!empty($parsedAvail['hotel_remarks'])) {
            $bookingData['wb_hotel_remarks'] = $parsedAvail['hotel_remarks'];
        }

        $requireNationality = in_array('nationality', (array) ($parsedAvail['required_fields'] ?? []), true)
            || !empty($parsedAvail['required']['nationality']);
        $requireChildDob = in_array('chdbirthdate', (array) ($parsedAvail['required_fields'] ?? []), true)
            || !empty($parsedAvail['required']['chdbirthdate']);

        // Build passengers
        $passengers = [];
        $travellerRooms = is_array($travellers['travelers'] ?? null) ? $travellers['travelers'] : [];
        $primaryGuest = is_array($travellers['primary_guest'] ?? null) ? $travellers['primary_guest'] : [];

        foreach ($roomsData as $roomIndex => $roomCfg) {
            $needAdults = (int) ($roomCfg['adults'] ?? $roomCfg['adt'] ?? 1);
            $needChildren = (int) ($roomCfg['children'] ?? $roomCfg['childs'] ?? $roomCfg['chd'] ?? 0);
            $roomTravellers = is_array($travellerRooms['room_' . $roomIndex] ?? null)
                ? $travellerRooms['room_' . $roomIndex]
                : [];
            $group = (string) ($roomIndex + 1);

            for ($i = 0; $i < $needAdults; $i++) {
                $guest = $roomTravellers['adult_' . $i] ?? (($roomIndex === 0 && $i === 0) ? $primaryGuest : null);
                if (!is_array($guest) && is_array($travellers[0] ?? null) && $roomIndex === 0 && $i === 0) {
                    $guest = $travellers[0];
                }
                if (!is_array($guest)) {
                    throw new Exception('Adult traveller details are missing for room ' . ($roomIndex + 1) . '.');
                }
                $guestNat = strtoupper(trim((string) ($guest['nationality'] ?? $nationality)));
                $passengers[] = [
                    'type' => 'adt',
                    'group' => $group,
                    'title' => wanderbedsNormalizeTitle($guest['title'] ?? 'Mr'),
                    'firstname' => trim((string) ($guest['first_name'] ?? $guest['firstname'] ?? '')),
                    'lastname' => trim((string) ($guest['last_name'] ?? $guest['lastname'] ?? '')),
                    'nationality' => $guestNat,
                    'birthdate' => (string) ($guest['birthdate'] ?? $guest['dob'] ?? ''),
                ];
            }
            for ($i = 0; $i < $needChildren; $i++) {
                $guest = $roomTravellers['child_' . $i] ?? null;
                if (!is_array($guest)) {
                    throw new Exception('Child traveller details are missing for room ' . ($roomIndex + 1) . '.');
                }
                $guestNat = strtoupper(trim((string) ($guest['nationality'] ?? $nationality)));
                $passengers[] = [
                    'type' => 'chd',
                    'group' => $group,
                    'title' => wanderbedsNormalizeTitle($guest['title'] ?? 'Ms'),
                    'firstname' => trim((string) ($guest['first_name'] ?? $guest['firstname'] ?? '')),
                    'lastname' => trim((string) ($guest['last_name'] ?? $guest['lastname'] ?? '')),
                    'nationality' => $guestNat,
                    'birthdate' => (string) ($guest['birthdate'] ?? $guest['dob'] ?? ''),
                ];
            }
        }

        foreach ($passengers as $p) {
            if ($p['firstname'] === '' || $p['lastname'] === '') {
                throw new Exception('Valid first and last name are required for every traveller.');
            }
            if ($requireNationality && $p['nationality'] === '') {
                throw new Exception('Nationality is required for every traveller (Wanderbeds Avail required.nationality).');
            }
            if ($p['type'] === 'chd' && $requireChildDob && $p['birthdate'] === '') {
                throw new Exception('Birthdate is required for every child traveller (Wanderbeds Avail required.chdbirthdate).');
            }
        }

        $clientRef = preg_replace('/[^A-Za-z0-9]/', '', (string) $invoiceId);
        if ($clientRef === '') {
            $clientRef = 'WB' . time();
        }

        $bookingData['wb_client_reference'] = $clientRef;
        $bookingData['wb_offer_id'] = $offerId;
        $bookingData['wb_offer_ids'] = $offerIds;
        $bookingData['wb_group'] = $resolved['group'] ?? 0;
        $bookingData['wb_product_id'] = $productId;
        $bookingData['wb_token'] = $token;
        $db->update('bookings', [
            'booking_data' => json_encode($bookingData),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $booking['id']]);

        $lastStep = 'Book';
        $bookPayload = [
            'client_reference' => $clientRef,
            'passengers' => $passengers,
        ];
        $book = wanderbedsCall($moduleData, 'hotel/book', $bookPayload, 'POST', 120, $token);
        $recordStep('Book', $bookPayload, $book, 'Wanderbeds booking failed.');

        if (!$book['success']) {
            $bookErrorRaw = $book['error']
                ?? ($book['data']['error'] ?? null)
                ?? ($book['data']['message'] ?? null)
                ?? 'Wanderbeds booking failed.';
            $bookHttp = (int) ($book['http_code'] ?? 0);

            // Only recover via BookInfo on timeouts / ambiguous failures.
            // Code 120 (insufficient credit) never creates a booking — no PNR exists.
            if (!wanderbedsIsFatalBookError($bookErrorRaw, $bookHttp)) {
                sleep(5);
                $lastStep = 'BookInfoRecovery';
                $bookInfoPayload = ['client_reference' => $clientRef];
                $detail = wanderbedsCall($moduleData, 'hotel/bookinfo', $bookInfoPayload, 'POST', 60);
                $recordStep('BookInfoRecovery', $bookInfoPayload, $detail, 'BookInfo recovery failed.');
                $refs = wanderbedsExtractBookingRefs($detail['data'] ?? []);
                if (($detail['http_code'] ?? 0) === 200 && ($refs['booking_reference'] !== '' || $refs['pnr'] !== '')) {
                    $book = $detail;
                } else {
                    throw new Exception(wanderbedsFormatError($bookErrorRaw));
                }
            } else {
                throw new Exception(wanderbedsFormatError($bookErrorRaw));
            }
        }

        $refs = wanderbedsExtractBookingRefs($book['data'] ?? []);
        $pnr = (string) ($refs['pnr'] ?? '');
        $platformStatus = (string) ($refs['platform_status'] ?? 'processing');
        $wbStatus = (string) ($refs['primary_status'] ?? '');

        if ($refs['booking_reference'] !== '') {
            $lastStep = 'BookInfoConfirm';
            $confirmPayload = ['booking_reference' => $refs['booking_reference']];
            $confirm = wanderbedsCall($moduleData, 'hotel/bookinfo', $confirmPayload, 'POST', 60);
            $recordStep('BookInfoConfirm', $confirmPayload, $confirm, 'BookInfo confirm failed.');
            if ($confirm['success']) {
                $confirmRefs = wanderbedsExtractBookingRefs($confirm['data'] ?? []);
                if ($confirmRefs['pnr'] !== '') {
                    $refs = $confirmRefs;
                    $pnr = $confirmRefs['pnr'];
                    $platformStatus = $confirmRefs['platform_status'];
                    $wbStatus = $confirmRefs['primary_status'];
                }
            }
        }

        if ($pnr === '') {
            throw new Exception('Booking succeeded but booking_reference missing (client_ref: ' . $clientRef . ')');
        }

        if (in_array($platformStatus, ['failed'], true)) {
            throw new Exception(
                'Wanderbeds booking status is ' . ($wbStatus !== '' ? $wbStatus : 'failed')
                . '. Reference: ' . $pnr
            );
        }

        $bookingData['wb_book'] = $book['data'] ?? null;
        $bookingData['wb_booking_reference'] = $refs['booking_reference'];
        $bookingData['wb_reference'] = $refs['reference'];
        $bookingData['wb_confirmation'] = $refs['confirmation_number'];
        $bookingData['wb_client_reference'] = $clientRef !== '' ? $clientRef : $refs['client_reference'];
        $bookingData['wb_status'] = $wbStatus;
        $bookingData['wb_room_refs'] = $refs['room_refs'];
        $bookingData['supplier_pnr'] = $pnr;

        $dbStatus = $platformStatus === 'pending' ? 'pending' : 'confirmed';
        $db->update('bookings', [
            'booking_data' => json_encode($bookingData),
            'pnr' => $pnr,
            'booking_status' => $dbStatus,
            'error_response' => null,
            'booking_payment_issue' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $booking['id']]);

        wanderbedsLogBookingStep(
            $invoiceId,
            'complete',
            ['client_reference' => $clientRef],
            [
                'pnr' => $pnr,
                'booking_reference' => $refs['booking_reference'],
                'confirmation_number' => $refs['confirmation_number'],
                'wb_status' => $wbStatus,
                'platform_status' => $platformStatus,
            ],
            200,
            true,
            $platformStatus === 'pending' ? 'Booking on request (RQ)' : 'Booking confirmed'
        );

        echo json_encode([
            'success' => true,
            'message' => $platformStatus === 'pending'
                ? 'Booking placed on request with Wanderbeds'
                : 'Booking confirmed with Wanderbeds',
            'pnr' => $pnr,
            'confirmation_number' => $refs['confirmation_number'],
            'booking_reference' => $refs['booking_reference'],
            'reference' => $refs['reference'],
            'wb_status' => $wbStatus,
            'booking_status' => $dbStatus,
            'invoice_id' => $invoiceId,
            'log_file' => $timelineFile,
            'data' => $book['data'] ?? null,
        ]);
    } catch (Exception $e) {
        $message = $e->getMessage();
        error_log('Wanderbeds issue error [' . $invoiceId . '][' . $lastStep . ']: ' . $message);

        if ($invoiceId !== '') {
            $timelineFile = wanderbedsLogBookingStep(
                $invoiceId,
                'error',
                ['step' => $lastStep],
                [
                    'message' => $message,
                    'exception' => get_class($e),
                ],
                400,
                false,
                $message
            );
        }

        if (!empty($booking['id'])) {
            $db->update('bookings', [
                'error_response' => json_encode([
                    'action' => 'issue',
                    'step' => $lastStep,
                    'message' => $message,
                    'log_file' => $timelineFile,
                    'timestamp' => date('Y-m-d H:i:s'),
                ]),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $booking['id']]);
        }

        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'step' => $lastStep,
            'invoice_id' => $invoiceId,
            'log_file' => $timelineFile !== '' ? $timelineFile : wanderbedsBookingLogPath($invoiceId),
        ]);
    }
});

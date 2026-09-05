<?php
// ============================================================================
// HOTELBEDS HOTEL BOOKING API ENDPOINT - COMPLETE DOCUMENTATION (V10)
// ============================================================================
//
// PURPOSE:
// Process hotel bookings via Hotelbeds API with support for MULTIPLE ROOMS
// in a single booking, proper guest data handling, mTLS certificate support,
// and comprehensive error logging.
//
// ENDPOINT: POST /api/v1/booking
//
// ============================================================================
// MULTI-ROOM BOOKING SUPPORT
// ============================================================================
//
// Hotelbeds allows booking multiple rooms (even different room types) in a
// single booking request. Each room can have:
// - Different rate keys (from availability search)
// - Different guest configurations (adults/children)
// - Different meal plans (board codes)
//
// Example: Book 2x Suite + 1x Deluxe + 1x Executive in one transaction
//
// ============================================================================
// REQUEST PARAMETERS
// ============================================================================
//
// 1. booking_data (JSON string)    - Hotel and rooms information
//    - hotel_id: Hotelbeds hotel code
//    - selected_rooms: Array of room selections
//      * room_id: Room type identifier
//      * room_name: Room type name
//      * quantity: Number of this room type to book
//      * option: Selected rate option with rate_key
//    - rooms_data: Room occupancy configuration
//      * adults: Number of adults per room
//      * children: Number of children per room
//      * childAges: Array of child ages
//    - currency: Booking currency code
//    - total_rooms: Total number of rooms
//    - total_adults: Total number of adults
//    - total_children: Total number of children
//
// 2. user_data (JSON string)       - Primary contact/holder information
//    - first_name: Holder's first name
//    - last_name: Holder's last name
//    - email: Contact email
//    - phone: Contact phone number
//
// 3. guest (JSON string)           - Array of all guests/passengers
//    - traveller_type: 'adults' or 'child'
//    - first_name: Guest first name
//    - last_name: Guest last name
//    - age: Required for children
//
// 4. hotel_id (integer)            - Hotelbeds hotel code
// 5. checkin (string)              - Check-in date (DD-MM-YYYY or YYYY-MM-DD)
// 6. checkout (string)             - Check-out date (DD-MM-YYYY or YYYY-MM-DD)
//
// 7. c1 (string)                   - Hotelbeds API Key
// 8. c2 (string)                   - Hotelbeds API Secret
// 9. env (string)                  - Environment: 'dev' (test) or 'live' (production)
//
// 10. use_mtls (string)            - Enable mTLS: 'yes' or 'no'
// 11. mtls_user (string)           - User folder name for mTLS certificates
//
// ============================================================================
// BOOKING FLOW - MULTI-ROOM SUPPORT
// ============================================================================
//
// STEP 1: Pre-booking Validation
// - availability() - Ensures hotel/rates are still available
// - roomrate() - Validates ALL rate keys for ALL selected rooms
//
// STEP 2: Guest Data Processing
// - Distribute guests across ALL rooms based on rooms_data configuration
// - Each room gets its own set of paxes (adults + children)
// - Room IDs are 1-indexed (Room 1, Room 2, Room 3, etc.)
//
// STEP 3: Build Multi-Room API Payload
// - Create separate room entry for EACH selected room
// - Each room has its own rate_key and paxes array
// - Support for booking multiple quantities of same room type
//
// STEP 4: API Request Submission
// - Single API call books ALL rooms together
// - All rooms share same booking reference (PNR)
// - Transaction is atomic (all succeed or all fail)
//
// ============================================================================

// ============================================================================
// HOTELBEDS HOTEL BOOKING API ENDPOINT
// ============================================================================

$router->post('stays/hotelbeds/issue', function() use ($db) {

    // Hotelbeds booking confirmation can exceed 30s (guide §37 — allow ≥60s)
    @set_time_limit(90);

    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();

    // ========================================
    // INITIALIZATION
    // ========================================
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    // Enable error logging
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    
    // Initialize invoice_id variable
    $invoice_id = '';

    try {
        // Verify database connection
        if (!$db) {
            throw new Exception('Database connection not available');
        }

        // ========================================
        // STEP 1: VALIDATE AND FETCH BOOKING FROM DATABASE
        // ========================================
        $invoice_id = $_POST['invoice_id'] ?? '';

        if (empty($invoice_id)) {
            throw new Exception('Missing invoice_id parameter in POST data');
        }
        

        // Get booking from database
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoice_id]);

        if (!$booking) {
            throw new Exception('Booking not found for invoice_id: ' . $invoice_id);
        }

        // A cancelled/voided booking must never be re-issued — cancel/void clears the
        // PNR, so nothing downstream would stop a duplicate booking at the supplier.
        if (in_array(strtolower((string) ($booking['booking_status'] ?? '')), ['cancelled', 'voided'], true)) {
            throw new Exception('Booking is already ' . $booking['booking_status'] . ' and cannot be issued again');
        }

        if (function_exists('hotelbedsBookingIssueBlocked') && hotelbedsBookingIssueBlocked($booking)) {
            ob_clean();
            echo json_encode([
                'status' => false,
                'message' => 'Issue blocked — booking may already exist at Hotelbeds after a timeout. Run Reconcile first; do not blind-retry POST /bookings.',
                'needs_reconcile' => true,
                'invoice_id' => $invoice_id,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
        

        // ========================================
        // STEP 2: GET MODULE CREDENTIALS
        // ========================================
        $module = $booking['module'] ?? 'hotelbeds';
        $moduleType = $booking['module_type'] ?? 'stays';

        $moduleData = $db->get('modules', '*', [
            'name' => $module,
            'type' => $moduleType
        ]);

        if (!$moduleData) {
            throw new Exception("Module '{$module}' not found in database");
        }
        
        error_log("HOTELBEDS ISSUE: Module data retrieved");

        // Extract API credentials
        $apiKey = $moduleData['c1'] ?? '';
        $apiSecret = $moduleData['c2'] ?? '';
        $environment = (($moduleData['dev_mode'] ?? '1') == '0') ? 'live' : 'dev';
        $hotelbedsSettings = function_exists('readHotelbedsSettings')
            ? readHotelbedsSettings()
            : ['use_mtls' => 0];
        $transport = function_exists('hotelbedsResolveBookingTransport')
            ? hotelbedsResolveBookingTransport($moduleData, $hotelbedsSettings)
            : [
                'environment' => ($environment === 'live' ? 'live' : 'test'),
                'use_mtls' => (($hotelbedsSettings['use_mtls'] ?? 0) == 1),
                'error' => null,
            ];
        $useMtls = $transport['use_mtls'];

        if (!empty($transport['error'])) {
            throw new Exception($transport['error']);
        }

        if (empty($apiKey) || empty($apiSecret)) {
            throw new Exception("API credentials not configured for module: {$module}");
        }
        

        // ========================================
        // STEP 3: PARSE BOOKING DATA
        // ========================================
        
        $bookingData = json_decode($booking['booking_data'], true);
        $travellersData = json_decode($booking['travellers'], true);

        if (empty($bookingData)) {
            throw new Exception('Invalid booking_data in database');
        }
        

    // Extract essential booking information
    $hotelId = $bookingData['hotel_id'] ?? $bookingData['hotelCode'] ?? null;
    $checkin = $bookingData['checkin'] ?? null;
    $checkout = $bookingData['checkout'] ?? null;
    $currency = $booking['currency_markup'] ?? $bookingData['currency'] ?? 'USD';

    // Validate essential data
    if (empty($hotelId) || empty($checkin) || empty($checkout)) {
        throw new Exception('Missing essential booking data: hotel_id, checkin, or checkout');
    }
    

    // ========================================
    // STEP 4: PROCESS ROOMS DATA
    // ========================================
    
    $selectedRooms = [];
    $requestedRoomsData = !empty($bookingData['rooms_data']) && is_array($bookingData['rooms_data'])
        ? array_values($bookingData['rooms_data'])
        : [];
    $isMultiOccupancyBooking = count($requestedRoomsData) > 1;
    $selectedOccupancyIndexes = [];

    $rateKeyOccupancy = static function ($rateKey): array {
        $occupancy = ['adults' => 0, 'children' => 0, 'child_ages' => []];
        $parts = explode('|', trim((string) $rateKey));
        $counts = explode('~', (string) ($parts[9] ?? ''));
        if (count($counts) < 3) {
            return $occupancy;
        }

        $occupancy['adults'] = max(0, (int) $counts[1]);
        $occupancy['children'] = max(0, (int) $counts[2]);
        if ($occupancy['children'] > 0 && !empty($parts[10])) {
            $ages = preg_split('/[~,]/', (string) $parts[10]) ?: [];
            $occupancy['child_ages'] = array_values(array_map('intval', array_filter(
                $ages,
                static function ($age) {
                    return $age !== '';
                }
            )));
            sort($occupancy['child_ages']);
        }

        return $occupancy;
    };

    // Extract rooms information from selected_rooms
    if (!empty($bookingData['selected_rooms'])) {
        foreach ($bookingData['selected_rooms'] as $room) {
            // Get rate_key from option
            $rateKey = '';
            if (isset($room['option']['rate_key'])) {
                $rateKey = $room['option']['rate_key'];
            } elseif (isset($room['option']['id'])) {
                $rateKey = $room['option']['id'];
            } elseif (isset($room['rate_key'])) {
                $rateKey = $room['rate_key'];
            }

            // A multi-room search must retain the requested room index. Do not
            // assign rooms by click order: Hotelbeds rateKeys are occupancy-specific.
            $roomDataIndex = count($selectedRooms);
            if ($isMultiOccupancyBooking) {
                if (!isset($room['searched_room_index']) || !is_numeric($room['searched_room_index'])) {
                    throw new Exception('Missing requested-room reference. Please search again and select one rate for each room.');
                }

                $roomDataIndex = (int) $room['searched_room_index'];
                if (!array_key_exists($roomDataIndex, $requestedRoomsData)) {
                    throw new Exception('Invalid requested-room reference. Please search again.');
                }
                if (isset($selectedOccupancyIndexes[$roomDataIndex])) {
                    throw new Exception('More than one rate was selected for the same requested room.');
                }
                if ((int) ($room['quantity'] ?? 1) !== 1) {
                    throw new Exception('Each different-occupancy room must use one matching rate.');
                }

                $selectedOccupancyIndexes[$roomDataIndex] = true;
            }

            // Get occupancy from its original rooms_data entry or use defaults.
            $adults = 2;
            $children = 0;
            $childAges = [];

            if (isset($requestedRoomsData[$roomDataIndex])) {
                $roomData = $requestedRoomsData[$roomDataIndex];
                $adults = (int)($roomData['adults'] ?? 2);
                $children = (int)($roomData['children'] ?? 0);
                $childAges = is_array($roomData['childAges'] ?? null) ? $roomData['childAges'] : [];
            }

            if ($isMultiOccupancyBooking) {
                $quotedOccupancy = $rateKeyOccupancy($rateKey);
                $expectedChildAges = array_map('intval', $childAges);
                sort($expectedChildAges);

                if (
                    $quotedOccupancy['adults'] !== $adults
                    || $quotedOccupancy['children'] !== $children
                    || (
                        $children > 0
                        && !empty($quotedOccupancy['child_ages'])
                        && $quotedOccupancy['child_ages'] !== $expectedChildAges
                    )
                ) {
                    throw new Exception(
                        'Selected rate occupancy does not match requested room '
                        . ($roomDataIndex + 1) . '. Please search again.'
                    );
                }
            }

            $rateType = strtoupper(trim((string)($room['option']['rate_type'] ?? '')));
            if ($rateType === '' && !empty($room['option']['needs_recheck'])) {
                $rateType = 'RECHECK';
            }
            if ($rateType === '') {
                $rateType = 'BOOKABLE';
            }

            $selectedRooms[] = [
                'room_code' => $room['room_id'] ?? '',
                'room_name' => $room['room_name'] ?? '',
                'rate_key' => $rateKey,
                'rate_type' => $rateType,
                'quantity' => (int)($room['quantity'] ?? 1),
                'searched_room_index' => $roomDataIndex,
                'adults' => $adults,
                'children' => $children,
                'child_ages' => $childAges,
                // Supplier-currency, no-markup net as originally quoted — needed to revalidate
                // RECHECK rates against a like-for-like basis (see STEP 7).
                'supplier_net' => (float)($room['option']['supplier_net'] ?? 0),
            ];
        }
    }

    if (empty($selectedRooms)) {
        throw new Exception('No rooms found in booking data');
    }

    if ($isMultiOccupancyBooking && count($selectedOccupancyIndexes) !== count($requestedRoomsData)) {
        throw new Exception('Select exactly one matching rate for each requested room.');
    }

    usort($selectedRooms, static function ($a, $b) {
        return ((int) ($a['searched_room_index'] ?? 0)) <=> ((int) ($b['searched_room_index'] ?? 0));
    });

    // Validate rate keys
    foreach ($selectedRooms as $room) {
        if (empty($room['rate_key'])) {
            throw new Exception('Missing rate_key for room: ' . $room['room_name']);
        }
    }
    

    // ========================================
    // STEP 5: PROCESS GUEST/TRAVELLER DATA
    // ========================================
    
    $allGuests = [];
    $guestsByRoomIndex = [];

    $normalizeGuestName = static function (array $guest, $fallback = null): array {
        $firstName = trim((string) ($guest['first_name'] ?? ''));
        $lastName = trim((string) ($guest['last_name'] ?? ''));
        if (($firstName === '' || $lastName === '') && is_array($fallback)) {
            $firstName = $firstName !== '' ? $firstName : trim((string) ($fallback['first_name'] ?? ''));
            $lastName = $lastName !== '' ? $lastName : trim((string) ($fallback['last_name'] ?? ''));
        }

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
        ];
    };

    if (!empty($travellersData)) {
        error_log("HOTELBEDS ISSUE: Travellers data exists");

        $primaryGuest = is_array($travellersData['primary_guest'] ?? null)
            ? $travellersData['primary_guest']
            : [];
        $travelersByRoom = is_array($travellersData['travelers'] ?? null)
            ? $travellersData['travelers']
            : [];

        $roomCountForGuests = max(count($selectedRooms), count($requestedRoomsData), 1);
        for ($roomIndex = 0; $roomIndex < $roomCountForGuests; $roomIndex++) {
            $roomGuests = $travelersByRoom['room_' . $roomIndex]
                ?? $travelersByRoom[$roomIndex]
                ?? [];
            if (!is_array($roomGuests)) {
                $roomGuests = [];
            }

            $adultsForRoom = (int) ($selectedRooms[$roomIndex]['adults'] ?? ($requestedRoomsData[$roomIndex]['adults'] ?? 0));
            $childrenForRoom = (int) ($selectedRooms[$roomIndex]['children'] ?? ($requestedRoomsData[$roomIndex]['children'] ?? 0));
            $childAgesForRoom = $selectedRooms[$roomIndex]['child_ages']
                ?? ($requestedRoomsData[$roomIndex]['childAges'] ?? []);
            if (!is_array($childAgesForRoom)) {
                $childAgesForRoom = [];
            }

            $roomPaxList = [];
            for ($adultIndex = 0; $adultIndex < $adultsForRoom; $adultIndex++) {
                $slot = is_array($roomGuests['adult_' . $adultIndex] ?? null)
                    ? $roomGuests['adult_' . $adultIndex]
                    : [];
                $fallback = ($roomIndex === 0 && $adultIndex === 0) ? $primaryGuest : null;
                $names = $normalizeGuestName($slot, $fallback);
                $roomPaxList[] = [
                    'type' => 'AD',
                    'first_name' => $names['first_name'],
                    'last_name' => $names['last_name'],
                ];
            }

            for ($childIndex = 0; $childIndex < $childrenForRoom; $childIndex++) {
                $slot = is_array($roomGuests['child_' . $childIndex] ?? null)
                    ? $roomGuests['child_' . $childIndex]
                    : [];
                $names = $normalizeGuestName($slot);
                $age = isset($slot['age']) && $slot['age'] !== ''
                    ? (int) $slot['age']
                    : (isset($childAgesForRoom[$childIndex]) ? (int) $childAgesForRoom[$childIndex] : null);
                $roomPaxList[] = [
                    'type' => 'CH',
                    'first_name' => $names['first_name'],
                    'last_name' => $names['last_name'],
                    'age' => $age,
                ];
            }

            $guestsByRoomIndex[$roomIndex] = $roomPaxList;
            foreach ($roomPaxList as $pax) {
                $allGuests[] = $pax;
            }
        }

        error_log("HOTELBEDS ISSUE: Travelers processed, total guests: " . count($allGuests));
    }
    

    // ========================================
    // STEP 6: BUILD API ROOMS ARRAY WITH PAXES
    // Hotelbeds Ex4 (different room types): each rooms[] entry has its own rateKey;
    // all paxes in that entry use roomId 1. Ex3 (same type × qty in one rateKey) uses 1,2,…
    // within a single entry — we use separate entries per instance (Ex4) with roomId 1 each.
    // ========================================
    
    $apiRooms = [];
    $roomLabelCounter = 1;

    // Calculate total guests needed
    $totalGuestsNeeded = 0;
    foreach ($selectedRooms as $room) {
        $totalGuestsNeeded += ($room['adults'] + $room['children']) * $room['quantity'];
    }
    
    error_log("HOTELBEDS ISSUE: Total guests needed: $totalGuestsNeeded");

    if (empty($allGuests)) {
        $allGuests[] = [
            'type' => 'AD',
            'first_name' => $booking['first_name'] ?? '',
            'last_name' => $booking['last_name'] ?? '',
        ];
    }

    $providedGuests = count($allGuests);
    if ($providedGuests < $totalGuestsNeeded) {
        throw new Exception(
            "Not enough guests collected ({$providedGuests}/{$totalGuestsNeeded}). "
            . "Occupancy must match search — do not reuse names."
        );
    }

    $guestIndex = 0;
    error_log("HOTELBEDS ISSUE: Unique guests available: $providedGuests");

    foreach ($selectedRooms as $selectedRoomIndex => $selectedRoom) {
        
        $quantity = $selectedRoom['quantity'];
        $adultsPerRoom = $selectedRoom['adults'];
        $childrenPerRoom = $selectedRoom['children'];
        $childAges = $selectedRoom['child_ages'] ?? [];
        
        // Ensure childAges is always an array
        if (!is_array($childAges)) {
            $childAges = [];
        }

        $roomSlotIndex = (int) ($selectedRoom['searched_room_index'] ?? $selectedRoomIndex);
        $assignedRoomGuests = $guestsByRoomIndex[$roomSlotIndex]
            ?? $guestsByRoomIndex[$selectedRoomIndex]
            ?? [];

        // Create entry for each quantity of this room type
        for ($q = 0; $q < $quantity; $q++) {
            error_log("HOTELBEDS ISSUE: Building room instance $q of $quantity");
            
            $roomPaxes = [];
            // Per Hotelbeds bookingRQ Ex4: roomId is always 1 within each rooms[] object
            $paxRoomId = 1;
            $assignedGuestCursor = 0;

            // Add adults for this room
            error_log("HOTELBEDS ISSUE: Adding $adultsPerRoom adults");
            for ($a = 0; $a < $adultsPerRoom; $a++) {
                $guest = $assignedRoomGuests[$assignedGuestCursor] ?? null;
                if (!is_array($guest) || ($guest['type'] ?? '') !== 'AD') {
                    if ($guestIndex >= count($allGuests)) {
                        throw new Exception('Not enough guest names for adults in room ' . $roomLabelCounter);
                    }
                    $guest = $allGuests[$guestIndex];
                    $guestIndex++;
                } else {
                    $assignedGuestCursor++;
                    $guestIndex++;
                }

                $firstName = trim((string)($guest['first_name'] ?? ''));
                $lastName = trim((string)($guest['last_name'] ?? ''));

                if ($firstName === '' || $lastName === '') {
                    throw new Exception('Guest first name and surname are required for each adult');
                }

                // Hotelbeds: name = first name, surname = last name (do not concatenate)
                $roomPaxes[] = [
                    'roomId' => $paxRoomId,
                    'type' => 'AD',
                    'name' => $firstName,
                    'surname' => $lastName
                ];
            }
            
            error_log("HOTELBEDS ISSUE: Added adults, now adding $childrenPerRoom children");

            // Add children for this room
            for ($c = 0; $c < $childrenPerRoom; $c++) {
                if (!isset($childAges[$c]) || $childAges[$c] === '' || $childAges[$c] === null) {
                    throw new Exception(
                        'Missing age for child #' . ($c + 1) . ' in room ' . $roomLabelCounter . '. Re-search required.'
                    );
                }
                $childAge = (int)$childAges[$c];

                $guest = $assignedRoomGuests[$assignedGuestCursor] ?? null;
                if (!is_array($guest) || ($guest['type'] ?? '') !== 'CH') {
                    if ($guestIndex >= count($allGuests)) {
                        throw new Exception('Not enough guest names for children in room ' . $roomLabelCounter);
                    }
                    $guest = $allGuests[$guestIndex];
                    $guestIndex++;
                } else {
                    $assignedGuestCursor++;
                    $guestIndex++;
                }

                $firstName = trim((string)($guest['first_name'] ?? ''));
                $lastName = trim((string)($guest['last_name'] ?? ''));

                if ($firstName === '' || $lastName === '') {
                    throw new Exception('Guest first name and surname are required for each child');
                }

                $roomPaxes[] = [
                    'roomId' => $paxRoomId,
                    'type' => 'CH',
                    'age' => $childAge,
                    'name' => $firstName,
                    'surname' => $lastName
                ];
            }

            // Add room to API payload
            error_log("HOTELBEDS ISSUE: Adding room to API payload");
            
            $apiRooms[] = [
                'rateKey' => $selectedRoom['rate_key'],
                'paxes' => $roomPaxes,
                '_rate_type' => $selectedRoom['rate_type'] ?? 'BOOKABLE',
                '_supplier_net' => (float)($selectedRoom['supplier_net'] ?? 0),
            ];
            
            error_log("HOTELBEDS ISSUE: Room added, incrementing counter");

            $roomLabelCounter++;
        }
    }
    
    error_log("HOTELBEDS ISSUE: All rooms processed, building booking payload");

    // CheckRate runs on Make Payment only. After payment, book the rateKeys
    // already saved on the draft/invoice (BOOKABLE and RECHECK).
    foreach ($apiRooms as $roomIndex => $apiRoom) {
        if (empty($apiRoom['rateKey'])) {
            throw new Exception('Missing rate key for a selected room. Please search again.');
        }
        unset($apiRooms[$roomIndex]['_rate_type'], $apiRooms[$roomIndex]['_supplier_net']);
    }

    // ========================================
    // STEP 8: BUILD COMPLETE BOOKING PAYLOAD
    // ========================================
    $bookingPayload = [
        'holder' => [
            'name' => trim((string)($booking['first_name'] ?? '')),
            'surname' => trim((string)($booking['last_name'] ?? ''))
        ],
        'rooms' => $apiRooms,
        'clientReference' => 'INV-' . $invoice_id,
        'remark' => 'Multi-room booking via invoice: ' . $invoice_id . ' | ' . count($apiRooms) . ' rooms',
        'tolerance' => 2.00
    ];

    if ($bookingPayload['holder']['name'] === '' || $bookingPayload['holder']['surname'] === '') {
        throw new Exception('Booking holder first name and surname are required');
    }
    
    error_log("HOTELBEDS ISSUE: Booking payload created, encoding JSON");

    $payloadJson = json_encode($bookingPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
    if ($payloadJson === false) {
        throw new Exception('Failed to encode booking payload to JSON: ' . json_last_error_msg());
    }
    

    // ========================================
    // STEP 8: DETERMINE API ENDPOINT
    // ========================================
    
    $baseUrl = function_exists('hotelbedsBookingApiBaseUrl')
        ? hotelbedsBookingApiBaseUrl($environment, $useMtls)
        : ($useMtls
            ? ($environment === 'live' ? 'https://api-mtls.hotelbeds.com/hotel-api/1.0' : 'https://api-mtls.test.hotelbeds.com/hotel-api/1.0')
            : ($environment === 'live' ? 'https://api.hotelbeds.com/hotel-api/1.0' : 'https://api.test.hotelbeds.com/hotel-api/1.0'));

    $bookingUrl = $baseUrl . '/bookings';
    
    error_log("HOTELBEDS ISSUE: API URL: $bookingUrl");

    // ========================================
    // STEP 9: GENERATE X-SIGNATURE
    // ========================================
    error_log("HOTELBEDS ISSUE: Generating X-Signature");
    
    $timestamp = time();
    $xSignature = hash('sha256', $apiKey . $apiSecret . $timestamp);
    
    error_log("HOTELBEDS ISSUE: Signature generated");

    // ========================================
    // STEP 10: CONFIGURE cURL REQUEST
    // ========================================
    error_log("HOTELBEDS ISSUE: Initializing cURL");
    
    $curl = curl_init();
    
    if ($curl === false) {
        throw new Exception('Failed to initialize cURL');
    }

    // Apply mTLS if enabled
    if (function_exists('hotelbedsApplyMtlsCurlOptions')) {
        hotelbedsApplyMtlsCurlOptions($curl, $useMtls);
    }

    error_log("HOTELBEDS ISSUE: Setting cURL options");
    
    curl_setopt_array($curl, [
        CURLOPT_URL => $bookingUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $payloadJson,
        CURLOPT_HTTPHEADER => [
            'Api-key: ' . $apiKey,
            'X-Signature: ' . $xSignature,
            'Accept: application/json',
            'Accept-Encoding: gzip',
            'Content-Type: application/json'
        ]
    ]);
    

    // ========================================
    // STEP 11: EXECUTE API REQUEST
    // ========================================
    $clientReference = function_exists('hotelbedsClientReference')
        ? hotelbedsClientReference($invoice_id)
        : ('INV-' . $invoice_id);
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    
    error_log("HOTELBEDS ISSUE: API request completed, HTTP code: $httpCode");

    // Transport timeout / no response — request may have reached Hotelbeds; do not blind-retry POST
    if ($response === false || $curlError !== '') {
        $reconcileError = [
            'type' => 'BOOKING_TIMEOUT_OR_TRANSPORT',
            'curl_error' => $curlError,
            'http_code' => $httpCode,
            'clientReference' => $clientReference,
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => 'issue',
        ];

        $db->update('bookings', [
            'booking_status' => 'pending',
            'error_response' => json_encode($reconcileError),
        ], ['invoice_id' => $invoice_id]);

        if (log_setting($db, 'hotelbeds') == '1') {
            $logPath = function_exists('hotelbedsLogDir') ? hotelbedsLogDir() : (dirname(__DIR__) . '/logs');
            logApiCall(
                'hotelbeds_booking',
                $payloadJson,
                $reconcileError,
                $httpCode ?: 0,
                $logPath,
                'Hotelbeds_Booking'
            );
        }

        ob_clean();
        echo json_encode([
            'status' => false,
            'message' => 'Booking confirmation timed out. Do not retry blindly — reconcile with Hotelbeds.',
            'needs_reconcile' => true,
            'clientReference' => $clientReference,
            'invoice_id' => $invoice_id,
            'http_code' => $httpCode,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }


    $log_setting = log_setting($db,'hotelbeds');

    if($log_setting == '1'){
        $apiResponseDecoded = !empty($response) ? json_decode($response, true) : ['error' => 'Empty response'];

        $path = function_exists('hotelbedsLogDir') ? hotelbedsLogDir() : (dirname(__DIR__) . '/logs');
        $type = 'Hotelbeds_Booking';
        logApiCall('hotelbeds_booking', $payloadJson, $apiResponseDecoded, $httpCode, $path, $type);
    }


    // ========================================
    // STEP 12: PROCESS RESPONSE & UPDATE DATABASE
    // ========================================
    
    $responseData = json_decode($response, true);
    
    // Log the response for debugging
    
    // Check if response is valid JSON
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON response from API: ' . json_last_error_msg());
    }
    
    error_log("HOTELBEDS ISSUE: Checking for booking reference");

    if (!empty($responseData['booking']['reference'])) {
        
        // SUCCESS: Update booking in database
        $updateData = [
            'booking_status' => 'confirmed',
            'pnr' => $responseData['booking']['reference'],
            'booking_response' => $response,
            'error_response' => null
        ];

        $db->update('bookings', $updateData, ['invoice_id' => $invoice_id]);

        // Build response array (use array instead of object for consistency)
        $successResponse = [
            'status' => true,
            'Prn' => $responseData['booking']['reference'],
            'booking_reference' => $responseData['booking']['reference'],
            'reference' => $responseData['booking']['reference'],
            'response' => $responseData,
            'response_error' => '',
            'invoice_id' => $invoice_id,
            'total_rooms_booked' => count($apiRooms),
            'booking_details' => [
                'hotel_id' => $hotelId,
                'checkin' => $checkin,
                'checkout' => $checkout,
                'total_rooms' => count($apiRooms),
                'rooms' => array_map(function($room, $index) {
                    return [
                        'room_number' => ($index + 1),
                        'rate_key' => substr($room['rateKey'], 0, 50) . '...',
                        'adults' => count(array_filter($room['paxes'], function($pax) {
                            return $pax['type'] === 'AD';
                        })),
                        'children' => count(array_filter($room['paxes'], function($pax) {
                            return $pax['type'] === 'CH';
                        }))
                    ];
                }, $apiRooms, array_keys($apiRooms))
            ]
        ];

        // Clean output buffer and send response
        ob_clean();
        echo json_encode($successResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;

    } else {
        // FAILURE: Update booking with error
        
        $errorMessage = '';
        $errorCode = '';
        
        if (isset($responseData['error'])) {
            $errorBlock = $responseData['error'];

            if (is_string($errorBlock)) {
                $errorCode = 'ERROR';
                $errorMessage = $errorBlock;
            } else {
                $nestedErrors = [];
                if (is_array($errorBlock)) {
                    $nestedErrors = $errorBlock['errors'] ?? [];
                }

                $firstNestedError = (is_array($nestedErrors) && !empty($nestedErrors) && is_array($nestedErrors[0]))
                    ? $nestedErrors[0]
                    : [];

                $errorCode = $errorBlock['code']
                    ?? $firstNestedError['code']
                    ?? $firstNestedError['errorCode']
                    ?? 'UNKNOWN';

                $errorMessage = $errorBlock['message']
                    ?? $errorBlock['description']
                    ?? $firstNestedError['message']
                    ?? $firstNestedError['description']
                    ?? $firstNestedError['detail']
                    ?? 'No error message provided';
            }

            // Hotelbeds returns this when the same rateKey(s) are booked again after a
            // prior confirmation failure (price tolerance). Retrying those keys always fails.
            $errorMessageLower = strtolower((string) $errorMessage);
            if (
                strpos($errorMessageLower, 'do not retry') !== false
                || strpos($errorMessageLower, 'first request was unsuccessful') !== false
            ) {
                $fullErrorMessage = 'Hotelbeds rejected these rates because they were already used in a failed booking attempt (price moved beyond the 2% tolerance). Do not retry the same rooms — start a new search and book with fresh rate keys.';
            } elseif (
                strpos($errorMessageLower, 'price has changed') !== false
                || strpos($errorMessageLower, 'allowed tolerance') !== false
            ) {
                $fullErrorMessage = 'Hotelbeds price changed beyond the allowed 2% tolerance between CheckRate and confirmation. Please search again for fresh rates (do not retry the same booking).';
            } else {
                // Make error message more descriptive
                $fullErrorMessage = "Hotelbeds API Error [$errorCode]: $errorMessage";
            }
            
            // Add audit data if available for debugging
            if (isset($responseData['auditData']['token'])) {
                $fullErrorMessage .= " (Token: " . $responseData['auditData']['token'] . ")";
            }
            
            error_log("HOTELBEDS ISSUE: API Error - Code: $errorCode, Message: $errorMessage, HTTP: $httpCode");
        } elseif (isset($responseData['errors'])) {
            $firstError = (is_array($responseData['errors']) && !empty($responseData['errors']) && is_array($responseData['errors'][0]))
                ? $responseData['errors'][0]
                : [];
            $errorCode = $firstError['code'] ?? $firstError['errorCode'] ?? 'UNKNOWN';
            $errorMessage = $firstError['message'] ?? $firstError['description'] ?? $firstError['detail'] ?? json_encode($responseData['errors']);
            $fullErrorMessage = "Hotelbeds API Error [$errorCode]: $errorMessage";
            error_log("HOTELBEDS ISSUE: API Errors array: " . json_encode($responseData['errors']));
        } elseif ($curlError) {
            $fullErrorMessage = "Connection Error: $curlError";
            error_log("HOTELBEDS ISSUE: cURL Error: " . $curlError);
        } elseif ($httpCode >= 400) {
            $fullErrorMessage = "Hotelbeds API returned HTTP $httpCode error. Please contact support or try again later.";
            error_log("HOTELBEDS ISSUE: HTTP Error $httpCode");
        } else {
            $fullErrorMessage = "Unknown booking error occurred. HTTP Code: $httpCode";
            error_log("HOTELBEDS ISSUE: Unknown error");
        }

        $publicIssueFallback = 'One or more rates are no longer available. Please search again.';
        $userIssueMessage = function_exists('hotelbedsUserFacingError')
            ? hotelbedsUserFacingError($fullErrorMessage, $publicIssueFallback)
            : $publicIssueFallback;
        
        error_log("HOTELBEDS ISSUE: Updating database with error");

        $updateData = [
            'error_response' => $response
        ];

        $db->update('bookings', $updateData, ['invoice_id' => $invoice_id]);
        

        // Build error response array
        $errorResponse = [
            'status' => false,
            'Prn' => '',
            'booking_reference' => '',
            'reference' => '',
            'response' => $responseData,
            'response_error' => $fullErrorMessage,
            'error' => $userIssueMessage,
            'message' => $userIssueMessage,
            'invoice_id' => $invoice_id,
            'http_code' => $httpCode
        ];
        

        // Clean output buffer and send response
        ob_clean();
        echo json_encode($errorResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    } catch (Exception $e) {
        // Handle any exceptions thrown during processing
        $errorMessage = $e->getMessage();
        $errorFile = $e->getFile();
        $errorLine = $e->getLine();
        $errorTrace = $e->getTraceAsString();
        
        // Log detailed error information
        error_log("HOTELBEDS ISSUE ERROR: " . $errorMessage);
        error_log("File: " . $errorFile . " Line: " . $errorLine);
        error_log("Trace: " . $errorTrace);
        
        $publicIssueFallback = 'One or more rates are no longer available. Please search again.';
        $userIssueMessage = function_exists('hotelbedsUserFacingError')
            ? hotelbedsUserFacingError($errorMessage, $publicIssueFallback)
            : $publicIssueFallback;

        $errorResponse = [
            'status' => false,
            'Prn' => '',
            'booking_reference' => '',
            'reference' => '',
            'response' => '',
            'response_error' => $errorMessage,
            'error' => $userIssueMessage,
            'message' => $userIssueMessage,
            'invoice_id' => $invoice_id ?? '',
            'http_code' => 500,
        ];
        if (function_exists('hotelbedsViewerIsAdmin') && hotelbedsViewerIsAdmin()) {
            $errorResponse['debug'] = [
                'file' => basename($errorFile),
                'line' => $errorLine,
                'trace' => explode("\n", $errorTrace)
            ];
        }
        
        // Update booking error_response if we have invoice_id
        if (!empty($invoice_id)) {
            try {
                $db->update('bookings', [
                    'error_response' => json_encode($errorResponse)
                ], [
                    'invoice_id' => $invoice_id
                ]);
            } catch (Exception $dbError) {
                // Log database error but don't interrupt response
                error_log("Failed to save error response: " . $dbError->getMessage());
            }
        }
        
        // Clean output buffer and send response
        ob_clean();
        echo json_encode($errorResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        // Catch PHP 7+ errors (like TypeError, ArgumentCountError, etc.)
        $errorMessage = $e->getMessage();
        $errorFile = $e->getFile();
        $errorLine = $e->getLine();
        
        error_log("HOTELBEDS ISSUE PHP ERROR: " . $errorMessage . " in " . $errorFile . " line " . $errorLine);
        
        $errorResponse = [
            'status' => false,
            'Prn' => '',
            'booking_reference' => '',
            'reference' => '',
            'response' => '',
            'response_error' => 'PHP Error: ' . $errorMessage,
            'error' => function_exists('hotelbedsUserFacingError')
                ? hotelbedsUserFacingError('PHP Error: ' . $errorMessage)
                : 'One or more rates are no longer available. Please search again.',
            'message' => function_exists('hotelbedsUserFacingError')
                ? hotelbedsUserFacingError('PHP Error: ' . $errorMessage)
                : 'One or more rates are no longer available. Please search again.',
            'invoice_id' => $invoice_id ?? '',
            'http_code' => 500,
        ];
        if (function_exists('hotelbedsViewerIsAdmin') && hotelbedsViewerIsAdmin()) {
            $errorResponse['debug'] = [
                'file' => basename($errorFile),
                'line' => $errorLine,
                'type' => get_class($e)
            ];
        }
        
        ob_clean();
        echo json_encode($errorResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
});

// ============================================================================
// HELPER FUNCTIONS - PRE-BOOKING VALIDATION
// ============================================================================

/**
 * CHECK HOTEL AVAILABILITY VIA HOTELBEDS API
 */
function availability($parms) {
    global $db;

    $booking_data = json_decode($parms['booking_data']);
    $hotelbedsSettings = readHotelbedsSettings();
    $moduleStub = ['dev_mode' => (($parms['env'] ?? '') === 'live') ? '0' : '1'];
    $transport = function_exists('hotelbedsResolveBookingTransport')
        ? hotelbedsResolveBookingTransport($moduleStub, $hotelbedsSettings)
        : [
            'use_mtls' => (($hotelbedsSettings['use_mtls'] ?? 0) == 1),
            'error' => null,
        ];
    $useMtls = $transport['use_mtls'];

    if (!empty($transport['error'])) {
        return json_encode(['error' => ['message' => $transport['error']]]);
    }

    $url = (function_exists('hotelbedsBookingApiBaseUrl')
        ? hotelbedsBookingApiBaseUrl($parms['env'] ?? 'test', $useMtls)
        : (($useMtls
            ? (($parms['env'] == 'live')
                ? 'https://api-mtls.hotelbeds.com/hotel-api/1.0'
                : 'https://api-mtls.test.hotelbeds.com/hotel-api/1.0')
            : (($parms['env'] == 'live')
                ? 'https://api.hotelbeds.com/hotel-api/1.0'
                : 'https://api.test.hotelbeds.com/hotel-api/1.0')))) . '/hotels';

    // BUILD PAXES ARRAY FROM rooms_data STRUCTURE
    $paxes = [];

    if (!empty($booking_data->rooms_data) && is_array($booking_data->rooms_data)) {
        // Use the structured rooms_data (NEW FORMAT - ACTUAL STRUCTURE)
        foreach ($booking_data->rooms_data as $room) {
            // Add adults
            $adults = (int)($room->adults ?? 2);
            for ($i = 0; $i < $adults; $i++) {
                $paxes[] = ["type" => "AD"];
            }

            // Add children with ages
            if (!empty($room->children) && !empty($room->childAges)) {
                foreach ($room->childAges as $age) {
                    $paxes[] = ["type" => "CH", "age" => (int)$age];
                }
            }
        }
    } else {
        // Fallback to simple totals
        $adults = (int)($booking_data->total_adults ?? 2);
        $children = (int)($booking_data->total_children ?? 0);

        for ($i = 0; $i < $adults; $i++) {
            $paxes[] = ["type" => "AD"];
        }

        for ($i = 0; $i < $children; $i++) {
            $paxes[] = ["type" => "CH", "age" => 10]; // Default age if not specified
        }
    }

    $params = [
        "stay" => [
            "checkIn" => date("Y-m-d", strtotime($parms['checkin'])),
            "checkOut" => date("Y-m-d", strtotime($parms['checkout']))
        ],
        "currency" => $booking_data->currency ?? 'USD',
        "occupancies" => [
            [
                "rooms" => (int)($booking_data->total_rooms ?? 1),
                "adults" => (int)($booking_data->total_adults ?? 2),
                "children" => (int)($booking_data->total_children ?? 0),
                "paxes" => $paxes
            ]
        ],
        "hotels" => [
            "hotel" => [(int)$parms['hotel_id']]
        ]
    ];

    if (function_exists('hotelbedsApplyAvailabilityMarketFields')) {
        global $db;
        $guestNationality = $booking_data->nationality ?? '';
        $params = hotelbedsApplyAvailabilityMarketFields($params, $db ?? null, $guestNationality);
    }

    $secret = $parms['c2'];
    $apikey = $parms['c1'];
    $xSignature = hash('sha256', $apikey . $secret . time());

    $ch = curl_init();

    if (function_exists('hotelbedsApplyMtlsCurlOptions')) {
        hotelbedsApplyMtlsCurlOptions($ch, $useMtls);
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Api-key: ' . $apikey,
        'X-Signature: ' . $xSignature,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        echo 'Error:' . curl_error($ch);
    }

    $log_setting = log_setting($db,'hotelbeds');

    if($log_setting == '1'){
        $apiResponseDecoded = !empty($response) ? json_decode($response, true) : ['error' => 'Empty response'];

        $path = function_exists('hotelbedsLogDir') ? hotelbedsLogDir() : (dirname(__DIR__) . '/logs');
        $type = 'Hotelbeds_Availibility';
        logApiCall('hotelbeds_availibility', $params, $apiResponseDecoded, $httpCode, $path, $type);
    }

    return $response;
}

/**
 * VALIDATE ROOM RATES VIA HOTELBEDS API
 */
function roomrate($parms) {
    global $db;

    $booking_data = json_decode($parms['booking_data']);
    $hotelbedsSettings = readHotelbedsSettings();
    $moduleStub = ['dev_mode' => (($parms['env'] ?? '') === 'live') ? '0' : '1'];
    $transport = function_exists('hotelbedsResolveBookingTransport')
        ? hotelbedsResolveBookingTransport($moduleStub, $hotelbedsSettings)
        : [
            'use_mtls' => (($hotelbedsSettings['use_mtls'] ?? 0) == 1),
            'error' => null,
        ];
    $useMtls = $transport['use_mtls'];

    if (!empty($transport['error'])) {
        return json_encode(['error' => ['message' => $transport['error']]]);
    }

    $url = (function_exists('hotelbedsBookingApiBaseUrl')
        ? hotelbedsBookingApiBaseUrl($parms['env'] ?? 'test', $useMtls)
        : (($useMtls
            ? (($parms['env'] == 'live')
                ? 'https://api-mtls.hotelbeds.com/hotel-api/1.0'
                : 'https://api-mtls.test.hotelbeds.com/hotel-api/1.0')
            : (($parms['env'] == 'live')
                ? 'https://api.hotelbeds.com/hotel-api/1.0'
                : 'https://api.test.hotelbeds.com/hotel-api/1.0')))) . '/checkrates';

    $secret = $parms['c2'];
    $apikey = $parms['c1'];
    $xSignature = hash('sha256', $apikey . $secret . time());

    // BUILD ROOMS ARRAY FROM selected_rooms (ACTUAL STRUCTURE)
    $rooms = [];

    if (!empty($booking_data->selected_rooms) && is_array($booking_data->selected_rooms)) {
        foreach ($booking_data->selected_rooms as $selected_room) {
            // Extract rate_key from the option
            $rateKey = '';

            if (isset($selected_room->option->rate_key)) {
                $rateKey = $selected_room->option->rate_key;
            } elseif (isset($selected_room->rate_key)) {
                $rateKey = $selected_room->rate_key;
            }

            if (!empty($rateKey)) {
                $rooms[] = ["rateKey" => $rateKey];
            }
        }
    }

    // If no rate keys found, return error
    if (empty($rooms)) {
        $errorResponse = json_encode([
            'error' => [
                'code' => 'NO_RATE_KEYS',
                'message' => 'No rate keys found in booking_data'
            ]
        ]);

        return $errorResponse;
    }

    $params = ["rooms" => $rooms];

    $curl = curl_init();

    if (function_exists('hotelbedsApplyMtlsCurlOptions')) {
        hotelbedsApplyMtlsCurlOptions($curl, $useMtls);
    }

    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => [
            'Api-key: ' . $apikey,
            'X-Signature: ' . $xSignature,
            'Accept: application/json',
            'Accept-Encoding: gzip',
            'Content-Type: application/json'
        ],
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);


    $log_setting = log_setting($db,'hotelbeds');

    if($log_setting == '1'){
        $apiResponseDecoded = !empty($response) ? json_decode($response, true) : ['error' => 'Empty response'];

        $path = function_exists('hotelbedsLogDir') ? hotelbedsLogDir() : (dirname(__DIR__) . '/logs');
        $type = 'Hotelbeds_RoomRate';
        logApiCall('hotelbeds_rate', $params, $apiResponseDecoded, $httpCode, $path, $type);
    }

    return $response;
}

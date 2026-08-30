<?php
// path: modules/flights/mystifly/ancillaries.php
// Mystifly seat map and ancillaries routes
// POST flights/mystifly/seat-map
// POST flights/mystifly/ancillaries

global $router;

// ===========================================================================
// POST /flights/mystifly/seat-map
// Returns seat map in flat_elements format compatible with seats.php modal
// ===========================================================================
$router->post('flights/mystifly/seat-map', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $fareSourceCode = trim(
            $_POST['FareSourceCode'] ??
            $_POST['fare_source_code'] ??
            $_POST['booking_token'] ?? ''
        );
        if (empty($fareSourceCode)) {
            echo json_encode(['status' => false, 'message' => 'FareSourceCode is required']);
            exit;
        }

        $credentials = getMystiflyCredentials($db);
        $target      = (strtolower($credentials['environment'] ?? 'demo') === 'live') ? 'Live' : 'Test';
        $debugMode   = $credentials['debug'] ?? false;

        $result = mystiflyApiRequest($db, 'api/v1/SeatMap/Flight', [
            'FareSourceCode' => $fareSourceCode,
            'Target'         => $target,
            'ConversationId' => 'mf_seatmap_' . time(),
        ]);

        if (!$result['success']) {
            echo json_encode(['status' => false, 'message' => 'Seat map not available.', 'data' => ['segments' => []]]);
            exit;
        }

        $raw    = $result['data'] ?? [];
        // Handle both flat and Data-wrapped responses
        $data   = $raw['Data'] ?? $raw;

        // Mystifly uses Success boolean (not Status.Code)
        $success = $data['Success'] ?? $raw['Success'] ?? false;
        if (!$success) {
            $errors = $data['Errors'] ?? $raw['Errors'] ?? [];
            $errMsg = !empty($errors[0]['Message']) ? $errors[0]['Message'] : 'Seat map not available for this fare.';
            echo json_encode(['status' => false, 'message' => $errMsg, 'data' => ['segments' => []]]);
            exit;
        }

        // Actual key is SeatMaps (plural)
        $seatMaps = $data['SeatMaps'] ?? $raw['SeatMaps'] ?? [];
        $segments = [];

        foreach ($seatMaps as $segIndex => $segmentMap) {
            $segOrigin  = $segmentMap['DepartureAirport'] ?? '';
            $segDest    = $segmentMap['ArrivalAirport']   ?? '';
            $segDepDate = $segmentMap['DepartureDateTime'] ?? $segmentMap['DepartureDate'] ?? '';
            $segmentId  = $segmentMap['FlightNumber']     ?? (string)$segIndex;

            if ($segDepDate) {
                $ts = strtotime($segDepDate);
                if ($ts) $segDepDate = date('Y-m-d', $ts);
            }

            $cabins = [];

            // Mystifly uses "Cabins" (not CabinTypes), "ClassName" (not CabinClass)
            foreach ($segmentMap['Cabins'] ?? [] as $cabin) {
                $cabinClass = strtolower($cabin['ClassName'] ?? 'economy');

                // ---------------------------------------------------------------
                // Pass 1: determine column order + aisle positions
                // Use SeatConfigurationLetters from the widest row.
                // e.g. "FED,CBA" → columns in order: F,E,D,C,B,A  aisle after D
                // ---------------------------------------------------------------
                $widestConfig = '';
                $widestCount  = 0;
                foreach ($cabin['SeatRows'] ?? [] as $row) {
                    $raw_letters = preg_replace('/[^A-Za-z,]/', '', $row['SeatConfigurationLetters'] ?? '');
                    $cnt = strlen(str_replace(',', '', $raw_letters));
                    if ($cnt > $widestCount) {
                        $widestCount  = $cnt;
                        $widestConfig = $raw_letters;
                    }
                }

                $orderedLetters  = []; // physical left-to-right column letters
                $aisleAfterIndex = []; // index positions in $orderedLetters after which aisle goes

                if ($widestConfig !== '') {
                    $groups = explode(',', $widestConfig);
                    foreach ($groups as $gi => $group) {
                        for ($i = 0; $i < strlen($group); $i++) {
                            $orderedLetters[] = strtoupper($group[$i]);
                        }
                        // Aisle between groups (not after last group)
                        if ($gi < count($groups) - 1) {
                            $aisleAfterIndex[] = count($orderedLetters) - 1;
                        }
                    }
                }

                // Fallback: collect letters from seats, sort alphabetically, use AisleToTheRight
                $useFallbackAisle = empty($orderedLetters);
                if ($useFallbackAisle) {
                    $allLetters = [];
                    foreach ($cabin['SeatRows'] ?? [] as $row) {
                        foreach ($row['Seats'] ?? [] as $seat) {
                            $l = strtoupper($seat['SeatLetter'] ?? '');
                            if ($l && !in_array($l, $allLetters, true)) $allLetters[] = $l;
                        }
                    }
                    sort($allLetters);
                    $orderedLetters = $allLetters;
                }

                // Build flat_layout (column header row for the modal)
                $flatLayout = [];
                foreach ($orderedLetters as $i => $letter) {
                    $flatLayout[] = ['type' => 'header', 'label' => $letter];
                    if (in_array($i, $aisleAfterIndex, true)) {
                        $flatLayout[] = ['type' => 'aisle', 'label' => ''];
                    }
                }

                // ---------------------------------------------------------------
                // Pass 2: build one flat_elements row per SeatRow
                // ---------------------------------------------------------------
                $rows = [];

                foreach ($cabin['SeatRows'] ?? [] as $row) {
                    $rowNum = (int)($row['RowNumber'] ?? 0);

                    // Index seats by letter for O(1) lookup
                    $seatsInRow = [];
                    foreach ($row['Seats'] ?? [] as $seat) {
                        $l = strtoupper($seat['SeatLetter'] ?? '');
                        if ($l) $seatsInRow[$l] = $seat;
                    }

                    $flatElements   = [];
                    $fallbackAisles = []; // letters after which AisleToTheRight was set

                    foreach ($orderedLetters as $ci => $letter) {
                        if (isset($seatsInRow[$letter])) {
                            $seat       = $seatsInRow[$letter];
                            // ValidSeat=true means the seat is bookable/available
                            $available  = !empty($seat['ValidSeat']);
                            $designator = $seat['SeatNumber'] ?? ($rowNum . $letter);
                            $serviceId  = $seat['SeatMapID'] ?? null;
                            $price      = (float)($seat['Amount'] ?? 0);
                            $isExitRow  = !empty($seat['ExitRow']) || !empty($row['ExitRow']);

                            $elType  = $isExitRow ? 'exit_row' : 'seat';
                            $element = [
                                'type'               => $elType,
                                'designator'         => $designator,
                                'available_services' => $available ? [[
                                    'id'           => $serviceId ?? ('MF_' . $designator),
                                    'total_amount' => $price,
                                    'passenger_id' => null,
                                ]] : [],
                                'disclosures'        => [],
                            ];

                            // Fallback aisle detection via AisleToTheRight flag
                            if ($useFallbackAisle && !empty($seat['AisleToTheRight'])) {
                                $fallbackAisles[] = $ci;
                            }
                        } else {
                            // Column not present in this row → empty placeholder
                            $element = [
                                'type'               => 'seat',
                                'designator'         => null,
                                'available_services' => [],
                                'disclosures'        => [],
                            ];
                        }

                        $flatElements[] = $element;

                        // Insert aisle marker after this column if needed
                        $aisleCheck = $useFallbackAisle ? $fallbackAisles : $aisleAfterIndex;
                        if (in_array($ci, $aisleCheck, true)) {
                            $flatElements[] = ['type' => 'aisle', 'row_number' => $rowNum];
                        }
                    }

                    $rows[] = [
                        'row_number'    => $rowNum,
                        'flat_elements' => $flatElements,
                    ];
                }

                $cabins[] = [
                    'cabin_class' => $cabinClass,
                    'flat_layout' => $flatLayout,
                    'rows'        => $rows,
                ];
            }

            $segments[] = [
                'segment_id'       => $segmentId,
                'origin'           => $segOrigin,
                'destination'      => $segDest,
                'destination_city' => $segDest,
                'departure_date'   => $segDepDate,
                'cabins'           => $cabins,
            ];
        }

        $response = [
            'status'  => true,
            'message' => 'Seat map retrieved successfully.',
            'data'    => ['segments' => $segments],
        ];

        if ($debugMode) {
            $response['raw_response'] = $data;
        }

        echo json_encode($response);

    } catch (Throwable $e) {
        error_log('MYSTIFLY SEATMAP ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred retrieving seat map.']);
    }
});

// ===========================================================================
// POST /flights/mystifly/ancillaries
// Returns passenger list + available ancillary services (meals, extra baggage).
// If the Mystifly API returns no data, a synthetic passenger list is built from
// the adults/children/infants counts posted by the frontend.
// ===========================================================================
$router->post('flights/mystifly/ancillaries', function () use ($db) {
    header('Content-Type: application/json');

    try {
        $fareSourceCode = trim(
            $_POST['FareSourceCode'] ??
            $_POST['fare_source_code'] ??
            $_POST['booking_token'] ?? ''
        );
        $bookingHash = trim($_POST['booking_hash'] ?? '');

        $adultCount  = max(0, (int)($_POST['adults']   ?? 1));
        $childCount  = max(0, (int)($_POST['children'] ?? $_POST['childs'] ?? 0));
        $infantCount = max(0, (int)($_POST['infants']  ?? 0));

        $credentials = getMystiflyCredentials($db);
        $debugMode   = $credentials['debug'] ?? false;

        $services      = [];
        $rawExtraServices = [];
        $rawPassengers = [];
        $apiSuccess    = false;

        if (!empty($bookingHash)) {
            $draft = $db->get('logs_bookings', ['data'], ['hash' => $bookingHash]);
            if ($draft && !empty($draft['data'])) {
                $draftData = json_decode($draft['data'], true) ?? [];
                $bookingData = $draftData['flight_data']['booking_data'] ?? [];
                $rawExtraServices = $bookingData['extra_services'] ?? [];
            }
        }

        // Build passenger list — from API or synthetically
        $passengers = [];

        if (!empty($rawPassengers)) {
            foreach ($rawPassengers as $pax) {
                $paxRef  = $pax['PassengerRef'] ?? '';
                $paxType = strtolower($pax['PassengerType'] ?? 'adt');
                $paxType = ($paxType === 'adt') ? 'adult' : (($paxType === 'chd') ? 'child' : (($paxType === 'inf') ? 'infant' : $paxType));

                $baggageServices = array_values(array_filter($services, function ($s) use ($paxRef) {
                    return $s['service_type'] === 'baggage'
                        && (empty($s['passenger_refs']) || in_array($paxRef, $s['passenger_refs'], true));
                }));

                $availBaggage = array_map(function ($s) {
                    return [
                        'id'               => $s['service_id'],
                        'total_amount'     => $s['amount'],
                        'maximum_quantity' => 1,
                        'metadata'         => ['name' => $s['service_name'], 'maximum_weight_kg' => null],
                    ];
                }, $baggageServices);

                $passengers[] = [
                    'id'                => $paxRef ?: ($paxType . '_' . count($passengers)),
                    'passenger_ref'     => $paxRef,
                    'type'              => $paxType,
                    'given_name'        => $pax['FirstName'] ?? '',
                    'family_name'       => $pax['LastName']  ?? '',
                    'available_baggage' => array_values($availBaggage),
                ];
            }
        }

        // Synthetic fallback
        if (count($passengers) < ($adultCount + $childCount + $infantCount)) {
            $passengers = [];
            for ($i = 0; $i < $adultCount; $i++) {
                $passengers[] = ['id' => "adult_{$i}", 'passenger_ref' => "adult_{$i}", 'type' => 'adult', 'given_name' => '', 'family_name' => '', 'available_baggage' => []];
            }
            for ($i = 0; $i < $childCount; $i++) {
                $passengers[] = ['id' => "child_{$i}", 'passenger_ref' => "child_{$i}", 'type' => 'child', 'given_name' => '', 'family_name' => '', 'available_baggage' => []];
            }
            for ($i = 0; $i < $infantCount; $i++) {
                $passengers[] = ['id' => "infant_{$i}", 'passenger_ref' => "infant_{$i}", 'type' => 'infant', 'given_name' => '', 'family_name' => '', 'available_baggage' => []];
            }
        }

        $services = [];
        foreach ($rawExtraServices as $svc) {
            if (!is_array($svc)) {
                continue;
            }

            $description = trim((string)($svc['Description'] ?? 'Extra service'));
            $type = strtolower((string)($svc['Type'] ?? 'other'));
            $amount = (float)($svc['ServiceCost']['Amount'] ?? 0);
            $currency = $svc['ServiceCost']['CurrencyCode'] ?? 'USD';
            $serviceId = (string)($svc['ServiceId'] ?? md5($type . $description . $amount));
            $nameNumber = (int)($svc['NameNumber'] ?? 0);
            $maxWeightKg = null;
            if (preg_match('/(\d+(?:\.\d+)?)\s*kg/i', $description, $matches)) {
                $maxWeightKg = (float)$matches[1];
            }

            $services[] = [
                'service_type'   => $type,
                'service_id'     => $serviceId,
                'service_name'   => $description,
                'amount'         => $amount,
                'currency'       => $currency,
                'passenger_refs' => $nameNumber > 0 ? ['pax_' . $nameNumber] : [],
                'segment_refs'   => [],
                'metadata'       => [
                    'maximum_weight_kg' => $maxWeightKg,
                    'behavior'          => $svc['Behavior'] ?? '',
                    'check_in_type'     => $svc['CheckInType'] ?? '',
                ],
            ];
        }

        $availableBaggage = array_map(function ($s) {
            return [
                'id'               => $s['service_id'],
                'total_amount'     => $s['amount'],
                'maximum_quantity' => 1,
                'metadata'         => [
                    'name'              => $s['service_name'],
                    'maximum_weight_kg' => $s['metadata']['maximum_weight_kg'] ?? null,
                ],
            ];
        }, array_values(array_filter($services, function ($s) {
            return $s['service_type'] === 'baggage';
        })));

        $availableMeals = array_map(function ($s) {
            return [
                'id'               => $s['service_id'],
                'total_amount'     => $s['amount'],
                'maximum_quantity' => 99,
                'metadata'         => [
                    'name'        => $s['service_name'],
                    'description' => $s['service_name'],
                ],
            ];
        }, array_values(array_filter($services, function ($s) {
            return $s['service_type'] === 'meal';
        })));

        foreach ($passengers as &$passenger) {
            if ($passenger['type'] !== 'infant') {
                $passenger['available_baggage'] = $availableBaggage;
                $passenger['available_meals'] = $availableMeals;
            }
        }
        unset($passenger);

        $response = [
            'status'  => true,
            'message' => !empty($services) ? 'Ancillaries retrieved from revalidation.' : 'Passenger list generated.',
            'data'    => ['passengers' => $passengers, 'services' => $services],
        ];

        if ($debugMode) {
            $response['raw_response'] = ['extra_services' => $rawExtraServices];
        }

        echo json_encode($response);

    } catch (Throwable $e) {
        error_log('MYSTIFLY ANCILLARIES ERROR: ' . $e->getMessage());
        echo json_encode(['status' => false, 'message' => 'An error occurred retrieving ancillaries.']);
    }
});

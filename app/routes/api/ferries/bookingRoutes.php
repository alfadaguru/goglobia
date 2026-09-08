<?php
// ============================================================================
// FERRIES API — BOOKING ROUTES
// ============================================================================
// POST /api/ferries/booking/draft     — save booking draft to logs_bookings
// GET  /api/ferries/booking/{hash}    — retrieve draft by hash
// POST /api/ferries/booking/submit    — confirm booking with Kikoto + save to bookings
// POST /api/ferries/cancel            — cancel confirmed booking by locator
// ============================================================================

@$SECURE or die('Access Denied!');

require_once 'app/lib/jwt.php';
require_once dirname(__DIR__, 4) . '/modules/ferries/kikoto/api.php';

// ----------------------------------------------------------------------------
// SAVE DRAFT — stores search + selection in logs_bookings, returns hash
// Front-end calls this when user clicks "Book Now" on a sailing card.
// Body: {departure_port_id, destination_port_id, date, selected_sailing,
//        passengers:[{ticket_type_id,...}], revalidated_price, currency}
// ----------------------------------------------------------------------------
$router->post('/api/ferries/booking/draft', function () use ($SECURE, $db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        if (!is_array($input)) $input = $_POST;

        // VALIDATE MINIMUM REQUIRED FIELDS
        if (empty($input['selected_sailing']) || empty($input['passengers'])) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'selected_sailing and passengers are required.']);
            return;
        }

        // ENSURE PASSENGERS HAVE VALID ticket_type_id FROM SAILING'S SHIPPING COMPANY
        $selectedSailing = $input['selected_sailing'];
        if (is_string($selectedSailing)) {
            $selectedSailing = json_decode($selectedSailing, true) ?? [];
        }

        $cfg = _kikoto_cfg($db);
        if (!empty($cfg)) {
            _kikoto_enrich_sailing_ticket_types(
                $selectedSailing,
                $cfg,
                (int)($input['departure_port_id'] ?? $selectedSailing['departure_port_id'] ?? 0),
                (int)($input['destination_port_id'] ?? $selectedSailing['destination_port_id'] ?? 0)
            );
        }
        
        $validTicketTypes = $selectedSailing['shipping_company']['ticket_types'] ?? [];
        $validTicketTypeIds = array_column($validTicketTypes, 'id');
        $firstValidTypeId = $validTicketTypeIds[0] ?? 10;  // Fallback to 10
        
        // Ensure all passengers have a valid ticket_type_id
        $passengers = $input['passengers'] ?? [];
        if (!empty($passengers) && !empty($validTicketTypeIds)) {
            foreach ($passengers as &$p) {
                $ticketTypeId = (int)($p['ticket_type_id'] ?? 0);
                // If no ticket_type_id or it's not in valid list, assign the first valid one
                if ($ticketTypeId <= 0 || !in_array($ticketTypeId, $validTicketTypeIds)) {
                    $p['ticket_type_id'] = $firstValidTypeId;
                }
            }
            unset($p);
        }

        // Search-time type preference (Tourism/Van/Motorcycle/... and Carrier/cage size) —
        // used to pick the closest-matching operator ticket type instead of always the first.
        $vehicleTypeHint = trim((string)($input['vehicle_type_hint'] ?? ''));
        $petTypeHint     = trim((string)($input['pet_type_hint']     ?? ''));

        // Remap vehicles/pets to operator ticket types (avoid FRS-only IDs like pet 22)
        $vehicles = $input['vehicles'] ?? [];
        $vehicleTypeIds = _kikoto_vehicle_type_ids($validTicketTypes);
        if (!empty($vehicles) && is_array($vehicles) && !empty($vehicleTypeIds)) {
            foreach ($vehicles as &$v) {
                if (!is_array($v)) continue;
                $typeId = (int)($v['ticket_type_id'] ?? 0);
                if ($typeId <= 0 || !in_array($typeId, $vehicleTypeIds, true)) {
                    $v['ticket_type_id'] = _kikoto_resolve_vehicle_type_id($validTicketTypes, $vehicleTypeHint);
                }
            }
            unset($v);
        }

        $pets = $input['pets'] ?? [];
        if (!empty($pets) && is_array($pets)) {
            foreach ($pets as &$p) {
                if (!is_array($p)) continue;
                $p['ticket_type_id'] = _kikoto_resolve_pet_type_id($validTicketTypes, (int)($p['ticket_type_id'] ?? 0), $petTypeHint);
            }
            unset($p);
        }

        // GENERATE UNIQUE 16-CHAR HEX HASH
        $hash = bin2hex(random_bytes(8));

        // STORE DRAFT DATA AS JSON — resolve bonus labels from catalog (never store id-only stubs)
        $draftArr = [
            'module'              => 'ferries',
            'supplier'            => 'kikoto',
            'departure_port_id'   => (int)($input['departure_port_id']   ?? 0),
            'destination_port_id' => (int)($input['destination_port_id'] ?? 0),
            'date'                => $input['date']                ?? '',
            'return_date'         => $input['return_date']         ?? '',
            'trip_type'           => $input['trip_type']           ?? 'oneway',
            'selected_sailing'    => $selectedSailing,
            'return_sailing'      => $input['return_sailing']      ?? null,
            'passengers'          => $passengers,
            'vehicles'            => $vehicles,
            'pets'                => $pets,
            'vehicle_type_hint'   => $vehicleTypeHint,
            'pet_type_hint'       => $petTypeHint,
            'bonuses'             => _kikoto_normalize_bonus_ids($input['bonuses'] ?? []),
            'coupon'              => trim((string)($input['coupon'] ?? '')),
            'revalidated_price'   => $input['revalidated_price']   ?? null,
            'currency'            => $input['currency']            ?? 'EUR',
            'created_at'          => date('Y-m-d H:i:s'),
        ];
        if (empty($draftArr['bonuses'])) {
            $draftArr['bonuses'] = _kikoto_extract_bonuses_from_draft($draftArr);
        }
        $draftArr['bonus_details'] = _kikoto_resolve_bonus_labels(
            $draftArr['bonuses'],
            is_array($input['bonus_details'] ?? null) ? $input['bonus_details'] : []
        );
        $draftData = json_encode($draftArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $db->insert('logs_bookings', [
            'hash' => $hash,
            'data' => $draftData,
        ]);

        echo json_encode(['success' => true, 'hash' => $hash, 'redirect' => root . 'ferries/booking/' . $hash]);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
});

// ----------------------------------------------------------------------------
// GET DRAFT — retrieve draft by hash (used by booking page on load)
// ----------------------------------------------------------------------------
$router->get('/api/ferries/booking/([a-f0-9]{16})', function ($hash) use ($SECURE, $db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $booking = $db->get('logs_bookings', ['hash', 'data'], ['hash' => $hash]);
        if (!$booking || empty($booking['data'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Draft not found.']);
            return;
        }
        $data = json_decode($booking['data'], true);
        echo json_encode(['success' => true, 'hash' => $hash, 'booking_data' => $data]);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
});

// ----------------------------------------------------------------------------
// VALIDATE BOOKING — validate contact & passenger details before submission
// Body: {contact:{title,name,first_surname,email,phone,...},
//        passengers:[{name,first_surname,nationality,identity_number,...}]}
// Returns: {valid:true/false, errors:[{field,message}]}
// ----------------------------------------------------------------------------
$router->post('/api/ferries/booking/validate', function () use ($SECURE, $db) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $errors = [];

        // VALIDATE CONTACT
        $contact = $input['contact'] ?? [];

        if (empty($contact['name']) || trim($contact['name']) === '') {
            $errors[] = ['field' => 'contact.name', 'message' => 'Guest first name is required'];
        } elseif (preg_match('/[0-9]/', $contact['name'])) {
            $errors[] = ['field' => 'contact.name', 'message' => 'Guest first name must contain letters only, no numbers'];
        }
        if (empty($contact['first_surname']) || trim($contact['first_surname']) === '') {
            $errors[] = ['field' => 'contact.first_surname', 'message' => 'Guest last name is required'];
        } elseif (preg_match('/[0-9]/', $contact['first_surname'])) {
            $errors[] = ['field' => 'contact.first_surname', 'message' => 'Guest last name must contain letters only, no numbers'];
        }
        if (empty($contact['email']) || !filter_var($contact['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = ['field' => 'contact.email', 'message' => 'Valid email address is required'];
        }
        if (empty($contact['phone_country_code']) || trim($contact['phone_country_code']) === '') {
            $errors[] = ['field' => 'contact.phone_country_code', 'message' => 'Phone country code is required'];
        }
        if (empty($contact['phone']) || trim($contact['phone']) === '') {
            $errors[] = ['field' => 'contact.phone', 'message' => 'Phone number is required'];
        }

        // VALIDATE PASSENGERS
        $passengers = $input['passengers'] ?? [];
        if (empty($passengers) || !is_array($passengers)) {
            $errors[] = ['field' => 'passengers', 'message' => 'At least one passenger is required'];
        } else {
            foreach ($passengers as $idx => $p) {
                if (empty($p['name']) || trim($p['name']) === '') {
                    $errors[] = ['field' => "passengers.{$idx}.name", 'message' => 'Passenger first name is required'];
                } elseif (preg_match('/[0-9]/', $p['name'])) {
                    $errors[] = ['field' => "passengers.{$idx}.name", 'message' => 'Passenger first name must contain letters only, no numbers'];
                }
                if (empty($p['first_surname']) || trim($p['first_surname']) === '') {
                    $errors[] = ['field' => "passengers.{$idx}.first_surname", 'message' => 'Passenger last name is required'];
                } elseif (preg_match('/[0-9]/', $p['first_surname'])) {
                    $errors[] = ['field' => "passengers.{$idx}.first_surname", 'message' => 'Passenger last name must contain letters only, no numbers'];
                }
                if (empty($p['nationality']) || trim($p['nationality']) === '') {
                    $errors[] = ['field' => "passengers.{$idx}.nationality", 'message' => 'Passenger nationality is required'];
                }
                if (empty($p['birthdate']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['birthdate'])) {
                    $errors[] = ['field' => "passengers.{$idx}.birthdate", 'message' => 'Valid date of birth is required (YYYY-MM-DD)'];
                }
                if (empty($p['identity_type']) || !in_array($p['identity_type'], ['passport', 'national_id', 'driver_license'])) {
                    $errors[] = ['field' => "passengers.{$idx}.identity_type", 'message' => 'Valid identity type is required'];
                }
                if (empty($p['identity_number']) || strlen(trim($p['identity_number'])) < 3) {
                    $errors[] = ['field' => "passengers.{$idx}.identity_number", 'message' => 'Valid identity document number is required'];
                }
                if (empty($p['identity_expiry']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $p['identity_expiry'])) {
                    $errors[] = ['field' => "passengers.{$idx}.identity_expiry", 'message' => 'Valid identity expiry date is required (YYYY-MM-DD)'];
                } else {
                    // Date-only compare — do not use time() (midnight of today would fail after 00:00)
                    if ($p['identity_expiry'] < date('Y-m-d')) {
                        $errors[] = ['field' => "passengers.{$idx}.identity_expiry", 'message' => 'Identity document has expired'];
                    }
                }
            }
        }

        // VALIDATE VEHICLES
        $vehicles = $input['vehicles'] ?? [];
        if (!empty($vehicles) && is_array($vehicles)) {
            foreach ($vehicles as $idx => $v) {
                if (empty($v['ticket_type_id'])) {
                    $errors[] = ['field' => "vehicles.{$idx}.ticket_type_id", 'message' => 'Vehicle type is required'];
                }
                if (empty($v['passenger_id']) || (int)$v['passenger_id'] <= 0) {
                    $errors[] = ['field' => "vehicles.{$idx}.passenger_id", 'message' => 'Linked passenger (driver) is required'];
                }
                if (empty($v['license_plate']) || trim($v['license_plate']) === '') {
                    $errors[] = ['field' => "vehicles.{$idx}.license_plate", 'message' => 'Vehicle license plate is required'];
                }
            }
        }

        // VALIDATE PETS
        $pets = $input['pets'] ?? [];
        if (!empty($pets) && is_array($pets)) {
            foreach ($pets as $idx => $p) {
                if (empty($p['ticket_type_id'])) {
                    $errors[] = ['field' => "pets.{$idx}.ticket_type_id", 'message' => 'Pet type is required'];
                }
                if (empty($p['passenger_id']) || (int)$p['passenger_id'] <= 0) {
                    $errors[] = ['field' => "pets.{$idx}.passenger_id", 'message' => 'Linked passenger (owner) is required'];
                }
            }
        }

        echo json_encode([
            'valid' => empty($errors),
            'errors' => $errors,
        ]);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['valid' => false, 'errors' => [['field' => 'general', 'message' => $e->getMessage()]]]);
    }
});

// ----------------------------------------------------------------------------
// SUBMIT BOOKING — full booking: Kikoto create+confirm → save to bookings table
// Body: {hash, contact:{title,name,first_surname,...,email,phone,...},
//        passengers:[full passenger objects], vehicles, pets}
// ----------------------------------------------------------------------------
$router->post('/api/ferries/booking/submit', function () use ($SECURE, $db) {
    @set_time_limit(120);
    header('Content-Type: application/json; charset=utf-8');
    
    // Temporarily catch ALL errors with full trace
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    });
    
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        if (!is_array($input)) $input = $_POST;

        $hash = trim((string)($input['hash'] ?? ''));
        if (!preg_match('/^[a-f0-9]{16}$/', $hash)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Invalid booking hash.']);
            return;
        }

        // LOAD DRAFT DATA
        $draft = $db->get('logs_bookings', ['hash', 'data'], ['hash' => $hash]);
        if (!$draft || empty($draft['data'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Booking draft not found or expired.']);
            return;
        }
        $draftData = json_decode($draft['data'], true);

        // LOAD MODULE CONFIG
        $cfg = _kikoto_cfg($db);
        if (empty($cfg) || ($cfg['status'] ?? '0') === '0') {
            http_response_code(503);
            echo json_encode(['success' => false, 'message' => 'Ferries module is not enabled.']);
            return;
        }

        // MERGE CONTACT FROM DRAFT OR SUBMISSION
        $contact    = $input['contact']    ?? $input;
        $passengers = $input['passengers'] ?? $draftData['passengers'] ?? [];
        $vehicles   = $input['vehicles']   ?? $draftData['vehicles']   ?? [];
        $pets       = $input['pets']       ?? $draftData['pets']       ?? [];

        // VALIDATE REQUIRED CONTACT FIELDS
        foreach (['title', 'name', 'first_surname', 'email', 'phone_country_code', 'phone'] as $f) {
            if (empty($contact[$f])) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => "Missing required field: $f"]);
                return;
            }
        }
        if (!filter_var($contact['email'], FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
            return;
        }
        if (preg_match('/[0-9]/', $contact['name']) || preg_match('/[0-9]/', $contact['first_surname'])) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Guest name must contain letters only, no numbers.']);
            return;
        }

        if (empty($draftData['selected_sailing'])) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'No sailing selected.']);
            return;
        }

        // FIX: Decode selected_sailing / return_sailing if double-encoded as JSON strings.
        // PHP 8 throws "Cannot access offset of type string on string" when you do
        // $string['accommodations'] — this guard prevents that.
        if (is_string($draftData['selected_sailing'])) {
            $draftData['selected_sailing'] = json_decode($draftData['selected_sailing'], true);
            if (!is_array($draftData['selected_sailing'])) {
                $draftData['selected_sailing'] = [];
            }
        } elseif (!is_array($draftData['selected_sailing'])) {
            $draftData['selected_sailing'] = [];
        }

        if (!empty($draftData['return_sailing']) && is_string($draftData['return_sailing'])) {
            $draftData['return_sailing'] = json_decode($draftData['return_sailing'], true);
            if (!is_array($draftData['return_sailing'])) {
                $draftData['return_sailing'] = null;
            }
        } elseif (isset($draftData['return_sailing']) && !is_array($draftData['return_sailing']) && !empty($draftData['return_sailing'])) {
            $draftData['return_sailing'] = null;
        }

        if (isset($draftData['passengers']) && is_string($draftData['passengers'])) {
            $draftData['passengers'] = json_decode($draftData['passengers'], true);
            if (!is_array($draftData['passengers'])) {
                $draftData['passengers'] = [];
            }
        } elseif (!isset($draftData['passengers']) || !is_array($draftData['passengers'])) {
            $draftData['passengers'] = [];
        }

        if (isset($draftData['vehicles']) && is_string($draftData['vehicles'])) {
            $draftData['vehicles'] = json_decode($draftData['vehicles'], true);
        }
        if (isset($draftData['pets']) && is_string($draftData['pets'])) {
            $draftData['pets'] = json_decode($draftData['pets'], true);
        }

        // REFRESH SAILING FROM LIVE KIKOTO DATA (stale sailings cause reservation errors)
        $draftData['selected_sailing'] = _kikoto_refresh_selected_sailing(
            $cfg,
            $draftData['selected_sailing'],
            (string)($draftData['date'] ?? '')
        );
        if (!empty($draftData['return_sailing']) && is_array($draftData['return_sailing'])) {
            $draftData['return_sailing'] = _kikoto_refresh_selected_sailing(
                $cfg,
                $draftData['return_sailing'],
                (string)($draftData['return_date'] ?? '')
            );
        }

        // RESOLVE ISO2 → ISO3 NATIONALITY FOR EACH PASSENGER
        $iso3Cache = [];
        $resolveIso3 = function (string $iso2) use ($db, &$iso3Cache): string {
            $iso2 = strtoupper(trim($iso2));
            if ($iso2 === '') {
                return '';
            }
            if (strlen($iso2) === 3 && ctype_alpha($iso2)) {
                return $iso2;
            }
            if (!isset($iso3Cache[$iso2])) {
                $row = $db->get('countries', ['iso3'], ['iso' => $iso2]);
                $iso3Cache[$iso2] = strtoupper(trim((string)($row['iso3'] ?? '')));
            }
            return $iso3Cache[$iso2];
        };

        // GET VALID TICKET TYPES FROM SAILING'S SHIPPING COMPANY
        _kikoto_enrich_sailing_ticket_types(
            $draftData['selected_sailing'],
            $cfg,
            (int)($draftData['departure_port_id'] ?? $draftData['selected_sailing']['departure_port_id'] ?? 0),
            (int)($draftData['destination_port_id'] ?? $draftData['selected_sailing']['destination_port_id'] ?? 0)
        );
        $validTicketTypes = $draftData['selected_sailing']['shipping_company']['ticket_types'] ?? [];
        $validTicketTypeIds = array_column($validTicketTypes, 'id');
        $firstValidTypeId = 10;
        foreach ($validTicketTypes as $t) {
            if (is_array($t) && ($t['group'] ?? '') === 'passenger' && stripos((string)($t['name'] ?? ''), 'adult') !== false) {
                $firstValidTypeId = (int)$t['id'];
                break;
            }
        }
        if ($firstValidTypeId === 10 && !empty($validTicketTypeIds)) {
            foreach ($validTicketTypes as $t) {
                if (is_array($t) && ($t['group'] ?? '') === 'passenger') {
                    $firstValidTypeId = (int)$t['id'];
                    break;
                }
            }
        }
        $vehicleTypeIds = _kikoto_vehicle_type_ids($validTicketTypes);
        $petTypeIds     = _kikoto_pet_type_ids($validTicketTypes);

        // BUILD KIKOTO PASSENGERS (sequential id, iso3 nationality, identity_expiration)
        $kikotoPassengers = [];
        foreach ($passengers as $i => $p) {
            $ticketTypeId = (int)($p['ticket_type_id'] ?? 0);
            
            // IF TICKET TYPE IS INVALID OR MISSING, USE FIRST VALID PASSENGER TYPE FROM SAILING
            if ($ticketTypeId <= 0 || (!empty($validTicketTypeIds) && !in_array($ticketTypeId, $validTicketTypeIds))) {
                $ticketTypeId = $firstValidTypeId;
            }

            if (preg_match('/[0-9]/', (string)($p['name'] ?? '')) || preg_match('/[0-9]/', (string)($p['first_surname'] ?? ''))) {
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'message' => 'Passenger ' . ($i + 1) . ': name must contain letters only, no numbers.',
                ]);
                return;
            }

            $nationalityIso3 = $resolveIso3($p['nationality'] ?? '');
            if ($nationalityIso3 === '' || strlen($nationalityIso3) !== 3) {
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'message' => 'Passenger ' . ($i + 1) . ': nationality is required (select a country).',
                ]);
                return;
            }

            $identityNumber = trim($p['identity_number'] ?? '');
            if ($identityNumber === '' || strlen($identityNumber) < 5) {
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'message' => 'Passenger ' . ($i + 1) . ': a valid passport / ID number is required.',
                ]);
                return;
            }

            $row = [
                'id'                  => $i + 1,
                'ticket_type_id'      => $ticketTypeId,
                'title'               => (strtolower(trim($p['title'] ?? '')) === 'mrs') ? 'mrs' : 'mr',
                'name'                => trim($p['name'] ?? ''),
                'first_surname'       => trim($p['first_surname'] ?? ''),
                'birthdate'           => $p['birthdate'] ?? '',
                'nationality'         => $nationalityIso3,
                'identity_type'       => _kikoto_map_identity_type((string)($p['identity_type'] ?? 'passport')),
                'identity_number'     => $identityNumber,
                'identity_expiration' => $p['identity_expiry'] ?? '',
            ];
            $secondSurname = trim($p['second_surname'] ?? '');
            if ($secondSurname !== '') {
                $row['second_surname'] = $secondSurname;
            }
            $kikotoPassengers[] = $row;
        }

        // BUILD KIKOTO VEHICLES & PETS (passenger_id links to passengers[].id — required by Kikoto)
        $passengerCount = count($kikotoPassengers);
        $kikotoVehicles = [];
        foreach ((array)$vehicles as $i => $v) {
            if (!is_array($v)) continue;
            $plate = trim((string)($v['license_plate'] ?? ''));
            if ($plate === '') {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Vehicle license plate is required for booking.']);
                return;
            }
            $passengerId = max(1, (int)($v['passenger_id'] ?? 1));
            if ($passengerCount > 0 && $passengerId > $passengerCount) {
                $passengerId = 1;
            }
            $typeId = (int)($v['ticket_type_id'] ?? 14);
            if (!empty($vehicleTypeIds) && !in_array($typeId, $vehicleTypeIds, true)) {
                $typeId = (int)$vehicleTypeIds[0];
            }
            $row = [
                'id'             => $i + 1,
                'ticket_type_id' => $typeId,
                'passenger_id'   => $passengerId,
                'license_plate'  => strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $plate)),
            ];
            $brand = trim((string)($v['brand'] ?? ''));
            if ($brand !== '') $row['brand'] = $brand;
            $kikotoVehicles[] = $row;
        }

        $kikotoPets = [];
        foreach ((array)$pets as $i => $p) {
            if (!is_array($p)) continue;
            $passengerId = max(1, (int)($p['passenger_id'] ?? 1));
            if ($passengerCount > 0 && $passengerId > $passengerCount) {
                $passengerId = 1;
            }
            $requestedPetType = (int)($p['ticket_type_id'] ?? 0);
            $petTypeId = _kikoto_resolve_pet_type_id($validTicketTypes, $requestedPetType);
            if ($petTypeId <= 0) {
                http_response_code(422);
                echo json_encode([
                    'success' => false,
                    'message' => 'This sailing does not offer pet tickets for the selected operator. Try another departure or search without pets.',
                ]);
                return;
            }
            $row = [
                'id'             => $i + 1,
                'ticket_type_id' => $petTypeId,
                'passenger_id'   => $passengerId,
            ];
            $name = trim((string)($p['name'] ?? ''));
            if ($name !== '') {
                $row['name'] = mb_substr($name, 0, 40);
            }
            $kikotoPets[] = $row;
        }

        $selectedBonusIds = _kikoto_normalize_bonus_ids($draftData['bonuses'] ?? $input['bonuses'] ?? []);
        if (empty($selectedBonusIds)) {
            $selectedBonusIds = _kikoto_extract_bonuses_from_draft(is_array($draftData) ? $draftData : []);
        }
        $passengerRefs = _kikoto_passenger_refs($kikotoPassengers, $selectedBonusIds);
        $coupon = trim((string)($input['coupon'] ?? $draftData['coupon'] ?? ''));

        $kikotoSailingsForPrices = [_kikoto_build_prices_sailing($draftData['selected_sailing'], $passengerRefs, $coupon ?: null)];
        $kikotoSailingsForBooking = [_kikoto_build_booking_sailing($draftData['selected_sailing'], $passengerRefs, $coupon ?: null)];
        if (!empty($draftData['return_sailing']) && is_array($draftData['return_sailing'])) {
            $kikotoSailingsForPrices[]  = _kikoto_build_prices_sailing($draftData['return_sailing'], $passengerRefs, $coupon ?: null);
            $kikotoSailingsForBooking[] = _kikoto_build_booking_sailing($draftData['return_sailing'], $passengerRefs, $coupon ?: null);
        }

        // Resolve phone country code → numeric string Kikoto accepts (e.g. "44", "92")
        $rawPhone = trim($contact['phone_country_code']);
        $phoneCode = preg_replace('/[^0-9]/', '', $rawPhone);
        // If looks like ISO2 (all letters) — look up phonecode
        if ($phoneCode === '' || preg_match('/^[A-Za-z]{2}$/', $rawPhone)) {
            $phoneRow = $db->get('countries', ['phonecode'], ['iso' => strtoupper($rawPhone)]);
            $phoneCode = (string)($phoneRow['phonecode'] ?? '');
        }
        // Fallback: if still empty or "01" (bad default), try to infer from contact email domain or default to "1"
        if ($phoneCode === '' || $phoneCode === '01' || $phoneCode === '0') {
            $phoneCode = '1';
        }

        // STEP 0: VALIDATE PRICE VIA KIKOTO /prices (required before /bookings, especially with vehicles)
        $priceCheck = _kikoto_validate_booking_prices(
            $cfg,
            $kikotoSailingsForPrices,
            $kikotoPassengers,
            $kikotoVehicles,
            $kikotoPets,
            $vehicleTypeIds,
            $petTypeIds
        );

        // Some bonuses exist globally (e.g. Greek student ID 22) but are not valid on this operator/route.
        // Drop unavailable bonus IDs and retry /prices once (keep coupon + remaining bonuses).
        if (!$priceCheck['ok'] && !empty($selectedBonusIds)) {
            $priceMsg = (string)($priceCheck['message'] ?? '');
            $badBonusIds = _kikoto_unavailable_bonus_ids_from_error($priceMsg);
            if (empty($badBonusIds) && stripos($priceMsg, 'bonus') !== false && stripos($priceMsg, 'not available') !== false) {
                $badBonusIds = $selectedBonusIds;
            }
            if (!empty($badBonusIds)) {
                $selectedBonusIds = array_values(array_diff($selectedBonusIds, $badBonusIds));
                $passengerRefs = _kikoto_passenger_refs($kikotoPassengers, $selectedBonusIds);
                $kikotoSailingsForPrices = [_kikoto_build_prices_sailing($draftData['selected_sailing'], $passengerRefs, $coupon ?: null)];
                $kikotoSailingsForBooking = [_kikoto_build_booking_sailing($draftData['selected_sailing'], $passengerRefs, $coupon ?: null)];
                if (!empty($draftData['return_sailing']) && is_array($draftData['return_sailing'])) {
                    $kikotoSailingsForPrices[]  = _kikoto_build_prices_sailing($draftData['return_sailing'], $passengerRefs, $coupon ?: null);
                    $kikotoSailingsForBooking[] = _kikoto_build_booking_sailing($draftData['return_sailing'], $passengerRefs, $coupon ?: null);
                }
                $priceCheck = _kikoto_validate_booking_prices(
                    $cfg,
                    $kikotoSailingsForPrices,
                    $kikotoPassengers,
                    $kikotoVehicles,
                    $kikotoPets,
                    $vehicleTypeIds,
                    $petTypeIds
                );
                if (!$priceCheck['ok']) {
                    http_response_code(422);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Price validation failed: The selected bonus is not available on this sailing/operator. '
                            . 'For Balearia Balearic routes try Resident (Balearic Islands) or Large Family, or clear Bonus/Discount and book again. '
                            . '(' . ($priceCheck['message'] ?? $priceMsg) . ')',
                    ]);
                    return;
                }
                // Persist only bonuses that actually priced
                $draftData['bonuses'] = $selectedBonusIds;
                $draftData['bonus_details'] = _kikoto_resolve_bonus_labels($selectedBonusIds);
            }
        }

        if (!$priceCheck['ok']) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Price validation failed: ' . ($priceCheck['message'] ?? 'Unable to price this sailing with the selected vehicle.')]);
            return;
        }
        $kikotoVehicles = $priceCheck['vehicles'];
        $kikotoPets     = $priceCheck['pets'];

        // STEP 1: CREATE DRAFT BOOKING WITH KIKOTO
        // Kikoto's contact "title" only accepts mr/mrs (confirmed via API: "ms"/"miss"/"dr"
        // are rejected with "title is invalid") — map any other title down to that binary set,
        // same as the per-passenger title mapping above.
        $bookBody = [
            'title'              => in_array(strtolower(trim($contact['title'] ?? '')), ['mrs', 'ms', 'miss'], true) ? 'mrs' : 'mr',
            'name'               => trim($contact['name']),
            'first_surname'      => trim($contact['first_surname']),
            'email'              => trim($contact['email']),
            'phone_country_code' => $phoneCode,
            'phone'              => preg_replace('/[^0-9]/', '', trim($contact['phone'])),
            'sailings'           => $kikotoSailingsForBooking,
            'passengers'         => $kikotoPassengers,
        ];
        $contactSecondSurname = trim($contact['second_surname'] ?? '');
        if ($contactSecondSurname !== '') {
            $bookBody['second_surname'] = $contactSecondSurname;
        }
        if (!empty($kikotoVehicles)) $bookBody['vehicles'] = $kikotoVehicles;
        if (!empty($kikotoPets))     $bookBody['pets']     = $kikotoPets;

        $createRes = _kikoto_request('POST', '/bookings', ['cfg' => $cfg, 'body' => $bookBody, 'timeout' => 30]);
        _kikoto_log_exchange('POST /bookings', $bookBody, $createRes['raw'] ?? $createRes['data']);

        // Some Balearia sailings reject Car but accept Motorcycle/Bicycle — retry other priced vehicle types
        if ((!$createRes['ok'] || empty($createRes['data']['data']['reference']))
            && !empty($kikotoVehicles)
            && !empty($vehicleTypeIds)
        ) {
            $errText = strtolower(_kikoto_format_error($createRes));
            $isGenericVehicleFail = (
                strpos($errText, 'an error occurred while making the reservation') !== false
                || strpos($errText, 'unknown error') !== false
                || $errText === ''
            );
            if ($isGenericVehicleFail) {
                $triedType = (int)($kikotoVehicles[0]['ticket_type_id'] ?? 0);
                $retryTypes = array_values(array_filter(
                    array_unique(array_map('intval', $vehicleTypeIds)),
                    fn($id) => $id > 0 && $id !== $triedType
                ));
                // Prefer compact types known to work on Balearia day sailings
                $prefer = [32, 31, 30, 28, 27, 34];
                usort($retryTypes, function ($a, $b) use ($prefer) {
                    $pa = array_search($a, $prefer, true);
                    $pb = array_search($b, $prefer, true);
                    return ($pa === false ? 999 : $pa) <=> ($pb === false ? 999 : $pb);
                });

                foreach ($retryTypes as $altType) {
                    $altVehicles = $kikotoVehicles;
                    foreach ($altVehicles as &$veh) {
                        $veh['ticket_type_id'] = (int)$altType;
                    }
                    unset($veh);

                    // Must re-price successfully before retrying /bookings
                    $reprice = _kikoto_validate_booking_prices(
                        $cfg,
                        $kikotoSailingsForPrices,
                        $kikotoPassengers,
                        $altVehicles,
                        $kikotoPets,
                        [$altType],
                        $petTypeIds
                    );
                    if (!$reprice['ok']) {
                        continue;
                    }
                    $altVehicles = $reprice['vehicles'];
                    $altPets     = $reprice['pets'];

                    $retryBody = $bookBody;
                    $retryBody['vehicles'] = $altVehicles;
                    if (!empty($altPets)) {
                        $retryBody['pets'] = $altPets;
                    } else {
                        unset($retryBody['pets']);
                    }

                    $retryRes = _kikoto_request('POST', '/bookings', ['cfg' => $cfg, 'body' => $retryBody, 'timeout' => 30]);
                    _kikoto_log_exchange('POST /bookings (vehicle-type retry ' . $altType . ')', $retryBody, $retryRes['raw'] ?? $retryRes['data']);
                    if ($retryRes['ok'] && !empty($retryRes['data']['data']['reference'])) {
                        $createRes      = $retryRes;
                        $kikotoVehicles = $altVehicles;
                        $kikotoPets     = $altPets;
                        $bookBody       = $retryBody;
                        break;
                    }
                }
            }
        }

        if (!$createRes['ok'] || empty($createRes['data']['data']['reference'])) {
            http_response_code(502);
            $errMsg = _kikoto_format_error($createRes);
            $hint = '';
            if (stripos($errMsg, 'pet') !== false) {
                $hint = ' Try booking without a pet, or pick another sailing that lists pets.';
            } elseif (!empty($kikotoVehicles)) {
                $hint = ' This departure may not have space for that vehicle type — try Motorcycle/Bicycle, another sailing, or remove the vehicle.';
            }
            echo json_encode(['success' => false, 'message' => 'Booking creation failed: ' . $errMsg . $hint]);
            return;
        }

        

        $reference = $createRes['data']['data']['reference'];

        // --------------------------------------------------
        // USER RESOLUTION (Supports Session for Web and JWT for Mobile)
        // --------------------------------------------------
        $agentCtx = _kikoto_resolve_agent_context($db);
        $userId   = $agentCtx['user_id'] ?? ($_SESSION['user_id'] ?? null);
        $userData = null;

        if (!empty($userId)) {
            $userData = $db->get('users', '*', ['user_id' => (string)$userId]);
            if (!$userData && is_numeric($userId)) {
                $userData = $db->get('users', '*', ['id' => (int)$userId]);
            }
        }

        if (empty($userId) || !$userData) {
            // Try to find existing user by email
            $existingUser = $db->get('users', '*', [
                'email' => $contact['email']
            ]);

            if ($existingUser) {
                $userId   = $existingUser['user_id'];
                $userData = $existingUser;
            } else {
                // Auto-create new user
                $generatedPassword = bin2hex(random_bytes(4));
                $hashedPassword    = password_hash($generatedPassword, PASSWORD_DEFAULT);
                $newUserId         = 'USR' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

                $db->insert('users', [
                    'user_id'            => $newUserId,
                    'first_name'         => $contact['name'],
                    'last_name'          => $contact['first_surname'],
                    'email'              => $contact['email'],
                    'password'           => $hashedPassword,
                    'phone'              => $contact['phone'] ?? '',
                    'phone_country_code' => $contact['phone_country_code'] ?? '',
                    'role'               => 'user',
                    'status'             => 'active',
                    'email_verified'     => 0,
                    'created_at'         => date('Y-m-d H:i:s'),
                    'updated_at'         => date('Y-m-d H:i:s')
                ]);

                $userId   = $newUserId;
                $userData = $db->get('users', '*', ['user_id' => $newUserId]);
            }
        }

        // VALIDATE selected_sailing IS ARRAY WITH ACCOMMODATIONS
        if (!is_array($draftData['selected_sailing']) || empty($draftData['selected_sailing']['accommodations'])) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Invalid sailing data: accommodations not found.']);
            return;
        }

        // PRICE: prefer live Kikoto /prices net, then draft original_price (not already-marked price)
        $draftAccomm = $draftData['selected_sailing']['accommodations'][0] ?? [];
        $basePrice   = 0.0;

        if (!empty($priceCheck['data']['sailings']) && is_array($priceCheck['data']['sailings'])) {
            foreach ($priceCheck['data']['sailings'] as $pricedSailing) {
                $basePrice += (float)($pricedSailing['price'] ?? 0);
            }
        }

        if ($basePrice <= 0) {
            $basePrice = (float)($draftAccomm['original_price'] ?? 0);
        }
        if ($basePrice <= 0) {
            // Last resort: marked draft/revalidated price (legacy drafts without original_price)
            $basePrice = (float)($draftAccomm['price'] ?? $draftData['revalidated_price'] ?? 0);
        }

        // Re-resolve agent after user account is known (guest email may match agent)
        $role = strtolower(trim((string)($userData['role'] ?? '')));
        $isAgent = ($role === 'agent')
            || !empty($agentCtx['is_agent'])
            || (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'b2b')
            || (strtolower((string)($_SESSION['user_role'] ?? '')) === 'agent');
        $channel = $isAgent ? 'b2b' : 'b2c';
        $customMarkup = $agentCtx['custom_markup'] ?? null;
        if ($isAgent && $customMarkup === null && is_array($userData) && ($userData['apply_markup'] ?? 'global') === 'custom') {
            $customMarkup = [
                'type'  => $userData['markup_type'] ?? 'percentage',
                'value' => (float)($userData['markup_value'] ?? 0),
            ];
        }

        $finalPrice   = _kikoto_apply_markup($basePrice, $cfg, $channel, $customMarkup);

        // PROMO CODE HANDLING
        $promoCodeStr = trim((string)($input['promo_code'] ?? $draftData['coupon'] ?? ''));
        $promoDiscount = (float)($input['promo_discount'] ?? 0);
        $promoCodeJson = null;
        $promoData = null;
        if (!empty($promoCodeStr) && $promoDiscount > 0) {
            $promoData = $db->get('promo_codes', '*', ['code' => $promoCodeStr]);
            if ($promoData) {
                $promoCodeJson = json_encode([
                    'code' => $promoData['code'],
                    'discount_type' => $promoData['discount_type'],
                    'discount_value' => floatval($promoData['discount_value']),
                    'discount_amount' => $promoDiscount,
                    'max_discount_amount' => $promoData['max_discount_amount'] ? floatval($promoData['max_discount_amount']) : null,
                    'description' => $promoData['description'],
                    'module' => $promoData['module']
                ]);
                $finalPrice = max(0, round($finalPrice - $promoDiscount, 2));
                $db->update('promo_codes', ['used_count[+]' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $promoData['id']]);
            }
        }

        $commission   = round(max(0, $finalPrice - $basePrice), 2);
        $agentEarning = $isAgent ? $commission : 0;
        $totalPrice   = $basePrice; // price_original = supplier net

        // GENERATE INVOICE ID
        $invoiceId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        // VALIDATE PASSENGERS IS ARRAY
        if (!is_array($passengers) || empty($passengers)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Invalid passengers data.']);
            return;
        }

        // Persist confirmed vehicles/pets/bonuses onto draft so invoice / PDF can read them
        $draftData['vehicles'] = $kikotoVehicles;
        $draftData['pets']     = $kikotoPets;
        $draftData['bonuses']  = !empty($selectedBonusIds)
            ? $selectedBonusIds
            : _kikoto_normalize_bonus_ids($draftData['bonuses'] ?? $input['bonuses'] ?? []);
        if (empty($draftData['bonuses'])) {
            $draftData['bonuses'] = _kikoto_extract_bonuses_from_draft($draftData);
        }
        $draftData['coupon']   = $coupon;
        $bonusDetails = _kikoto_resolve_bonus_labels(
            $draftData['bonuses'],
            is_array($draftData['bonus_details'] ?? null) ? $draftData['bonus_details'] : [],
            'en'
        );
        $draftData['bonus_details'] = $bonusDetails;

        $baseCurrency = resolveBaseCurrency($db, $cfg['currency'] ?? null, 'EUR');
        $displayCurrency = requireAppDisplayCurrency($db, $input);
        $conversionRate = getCurrencyConversionRate($db, $baseCurrency, $displayCurrency);
        $displayFinalTotal = convertCurrencyAmount($db, $finalPrice, $baseCurrency, $displayCurrency);
        $draftData['base_currency'] = $baseCurrency;
        $draftData['display_currency'] = $displayCurrency;
        $draftData['conversion_rate'] = $conversionRate;
        $draftData['final_total_display'] = $displayFinalTotal;

        // BUILD TRAVELLERS JSON
        $travellersData = [];
        $ticketTypesById = [];
        foreach ($validTicketTypes as $tt) {
            if (is_array($tt) && isset($tt['id'])) {
                $ticketTypesById[(int)$tt['id']] = $tt;
            }
        }
        foreach ($kikotoPassengers as $i => $p) {
            $inputP = $passengers[$i] ?? [];
            $tid = (int)($p['ticket_type_id'] ?? 0);
            $categorySource = [
                'passenger_category' => $inputP['passenger_category'] ?? '',
                'ticket_type_id'     => $tid,
                'birthdate'          => $p['birthdate'] ?? ($inputP['birthdate'] ?? ''),
            ];
            $travellersData[] = [
                'id'                 => (int)($p['id'] ?? $i + 1),
                'passenger_category' => _kikoto_passenger_category($categorySource, $ticketTypesById),
                'title'              => $p['title']           ?? '',
                'first_name'         => $p['name']             ?? '',
                'last_name'          => $p['first_surname']    ?? '',
                'birthdate'          => $p['birthdate']        ?? '',
                'nationality'        => $p['nationality']      ?? '',
                'identity_type'      => $p['identity_type']    ?? '',
                'identity_number'    => $p['identity_number']  ?? '',
                'ticket_type_id'     => $tid,
            ];
        }

        // Resolve adult/child counts from operator ticket groups (not hard-coded FRS IDs)
        $adultCount = 0;
        $childCount = 0;
        foreach ($kikotoPassengers as $kp) {
            $tid = (int)($kp['ticket_type_id'] ?? 0);
            $group = 'passenger';
            $name  = '';
            foreach ($validTicketTypes as $tt) {
                if ((int)($tt['id'] ?? 0) === $tid) {
                    $group = strtolower((string)($tt['group'] ?? 'passenger'));
                    $name  = strtolower((string)($tt['name'] ?? '') . ' ' . (string)($tt['description'] ?? ''));
                    break;
                }
            }
            if (preg_match('/baby|infant|child|niño|nina|bebe/', $name) || in_array($tid, [11, 12, 13, 25, 26], true)) {
                $childCount++;
            } else {
                $adultCount++;
            }
        }
        if ($adultCount + $childCount === 0) {
            $adultCount = count($kikotoPassengers);
        }

        // SAVE TO bookings TABLE — pending until payment received
        $db->insert('bookings', [
            'invoice_id'        => $invoiceId,
            'language'          => getCurrentLanguage(),
            'booking_status'    => 'pending',
            'payment_status'    => 'unpaid',
            'price_original'    => $totalPrice,
            'price_markup'      => $finalPrice,
            'tax'               => 0,
            'tax_type'          => 'percentage',
            'first_name'        => trim($contact['name']),
            'last_name'         => trim($contact['first_surname']),
            'email'             => trim($contact['email']),
            'phone_country_code'=> trim($contact['phone_country_code']),
            'phone'             => trim($contact['phone']),
            'country'           => '',
            'address'           => '',
            'adults'            => $adultCount,
            'childs'            => $childCount,
            'child_ages'        => '',
            'module_type'       => 'ferries',
            'module'            => 'kikoto',
            'payment_gateway'   => (int)($input['payment_gateway'] ?? 0) ?: null,
            'pnr'               => '',
            'booking_response'  => '',
            'booking_data'      => json_encode([
                'draft'          => $draftData,
                'reference'      => $reference,
                'locators'       => [],
                'vehicles'       => $kikotoVehicles,
                'pets'           => $kikotoPets,
                'bonuses'        => _kikoto_normalize_bonus_ids($draftData['bonuses'] ?? []),
                'bonus_details'  => $bonusDetails,
                'coupon'         => trim((string)($draftData['coupon'] ?? $coupon ?? '')),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'travellers'        => json_encode($travellersData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'user_id'           => $userId,
            'user_data'         => json_encode($userData),
            'currency_markup'   => $cfg['currency'] ?? 'EUR',
            'commission'        => $commission,
            'agent_earning'     => $agentEarning,
            'promo_codes'       => $promoCodeJson,
            'booking_date'      => $draftData['date'] ?? date('Y-m-d'),
            'created_at'        => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        $bookingId = $db->id();

        // AGENT API — wallet settlement (no-op unless agent-API request).
        agent_api_settle_booking($db, 'ferries', $bookingId, $invoiceId, (float) $finalPrice);

        echo json_encode([
            'success'    => true,
            'message'    => 'Booking pending payment.',
            'invoice_id' => $invoiceId,
            'booking_id' => $bookingId,
            'reference'  => $reference,
            'price'      => $displayFinalTotal,
            'amount'     => $displayFinalTotal,
            'amount_base'=> $finalPrice,
            'currency'   => $displayCurrency,
            'base_currency' => $baseCurrency,
            'display_currency' => $displayCurrency,
            'conversion_rate' => $conversionRate,
            'redirect'   => root . 'invoice/ferries/' . $invoiceId . '?currency=' . urlencode($displayCurrency),
            'redirect_url' => root . 'invoice/ferries/' . $invoiceId . '?currency=' . urlencode($displayCurrency),
        ]);

    } catch (Throwable $e) {
        http_response_code(500);
        $errorMsg = $e->getMessage();
        // For "Cannot access offset of type string on string" errors, be more specific
        if (strpos($errorMsg, 'Cannot access offset') !== false) {
            $errorMsg = 'Data format error: ' . $errorMsg . ' [File: ' . basename($e->getFile()) . ', Line: ' . $e->getLine() . ']';
        }
        echo json_encode(['success' => false, 'message' => $errorMsg]);
    }
});

// ----------------------------------------------------------------------------
// CANCEL BOOKING — cancel using locator (post-confirm PNR)
// Body: {locator, booking_id, invoice_id}
// ----------------------------------------------------------------------------
$router->post('/api/ferries/cancel', function () use ($SECURE, $db) {
    @set_time_limit(30);
    header('Content-Type: application/json; charset=utf-8');
    try {
        $input   = json_decode(file_get_contents('php://input'), true) ?: [];
        $locator = trim((string)($input['locator']    ?? ''));
        $invId   = trim((string)($input['invoice_id'] ?? ''));

        if ($locator === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'locator is required.']);
            return;
        }
        if (!preg_match('/^[A-Z0-9]{4,20}$/i', $locator)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Invalid locator format.']);
            return;
        }

        $cfg = _kikoto_cfg($db);
        if (empty($cfg)) {
            http_response_code(503);
            echo json_encode(['success' => false, 'message' => 'Ferries module not configured.']);
            return;
        }

        // CALL KIKOTO CANCEL
        $res = _kikoto_request('POST', '/bookings/' . urlencode($locator) . '/cancel', ['cfg' => $cfg, 'timeout' => 20]);
        if (!$res['ok']) {
            http_response_code(502);
            echo json_encode(['success' => false, 'message' => 'Cancellation failed: ' . ($res['error'] ?? '')]);
            return;
        }

        $fee = (float)($res['data']['data']['fee'] ?? 0);

        // UPDATE bookings TABLE IF INVOICE ID PROVIDED
        if ($invId !== '') {
            $db->update('bookings', [
                'booking_status'        => 'cancelled',
                'cancellation_status'   => 1,
                'cancellation_response' => json_encode(['fee' => $fee, 'locator' => $locator]),
                'updated_at'            => date('Y-m-d H:i:s'),
            ], ['invoice_id' => $invId, 'module_type' => 'ferries']);
        }

        echo json_encode([
            'success'          => true,
            'message'          => 'Booking cancelled successfully.',
            'locator'          => $locator,
            'cancellation_fee' => $fee,
            'currency'         => $cfg['currency'] ?? 'EUR',
        ]);

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
});

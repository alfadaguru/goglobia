<?php
// FILE: app/routes/cars/booking.php
// All car booking-related routes (GET & POST)

@$SECURE or die('Access Denied!');

// ====================================
// CAR BOOKING PAGE (GET)
// ====================================

$router->get('/cars/booking/([a-f0-9]{16})', function ($hash) use ($SECURE, $db) {

    // Validate hash and fetch booking draft
    // Use 'logs_bookings' table for drafts, similar to flights
    $booking = $db->get('logs_bookings', '*', [
        'hash' => $hash
    ]);

    if (!$booking) {
        $_SESSION['error'] = 'Booking not found';
        header('Location: ' . root . 'cars');
        exit;
    }

    // Decode booking data
    $bookingData = json_decode($booking['data'], true);
    
    // Store in session for booking page
    $_SESSION['booking_data'] = $bookingData;
    $_SESSION['booking_hash'] = $hash;

    // META DATA
    $title = "Car Booking";
    $description = "Complete your car rental booking";
    $header = true;
    $footer = true;

    require_once views."includes/header.php";
    require_once views."modules/cars/booking/index.php";
    require_once views."includes/footer.php";
});

// ============================================================================
// POST: Save booking draft
// POST /cars/booking/save-draft
// ============================================================================
$router->post('/cars/booking/save-draft', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || empty($input['car_data'])) {
            throw new Exception('Invalid booking data');
        }

        // Generate secure hash (16 characters)
        $hash = bin2hex(random_bytes(8));

        // Save to database
        $result = $db->insert('logs_bookings', [
            'hash' => $hash,
            'data' => json_encode($input),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        if ($result) {
            echo json_encode([
                'success' => true,
                'hash' => $hash,
                'redirect' => root . 'cars/booking/' . $hash,
                'message' => 'Booking draft saved successfully'
            ]);
        } else {
            throw new Exception('Failed to save booking data');
        }

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});

// ============================================================================
// POST: Submit Booking
// POST /cars/booking/submit
// ============================================================================
$router->post('/cars/booking/submit', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);

        // Validate required fields
        if (!$input || empty($input['booking_hash'])) {
            throw new Exception('Invalid booking data');
        }

        // Get draft booking
        $draft = $db->get('logs_bookings', ['data'], ['hash' => $input['booking_hash']]);
        if (!$draft || empty($draft['data'])) {
            throw new Exception('Booking draft not found');
        }

        $draftData = json_decode($draft['data'], true);
        $carData = $draftData['car_data'] ?? [];
        
        // Extract form data
        $formData = $input['guest_details'] ?? [];
        $primaryContact = $formData['primary_guest'] ?? [];
        $paymentGateway = $formData['selected_payment'] ?? '';
        $specialRequests = $formData['special_requests'] ?? '';
        $transferDetails = is_array($input['transfer_details'] ?? null) ? $input['transfer_details'] : [];
        $serviceType = $input['service_type'] ?? ($draftData['search_params']['service_type'] ?? 'rental');

        // Server-side seat-count check — the passenger dropdown is capped to
        // the vehicle's capacity client-side, but that's just UX. Enforce it
        // here too so a hand-crafted request can't book more people than the
        // selected vehicle actually seats.
        $requestedPassengers = (int)($transferDetails['passengers'] ?? 0);
        $vehicleCapacity = (int)($carData['passengers'] ?? $carData['max_passengers'] ?? 0);
        if ($requestedPassengers > 0 && $vehicleCapacity > 0 && $requestedPassengers > $vehicleCapacity) {
            throw new Exception("This vehicle seats {$vehicleCapacity} passenger(s); {$requestedPassengers} were requested.");
        }

        // Phone: digits only, length from countries.min_length/max_length, capped at bookings.phone varchar(15)
        $phoneRaw = preg_replace('/\D+/', '', (string)($primaryContact['phone'] ?? ''));
        $phoneCountryIso = strtoupper(trim((string)($primaryContact['country_code'] ?? $primaryContact['phone_country_code'] ?? '')));
        $phoneDbMax = 15;
        $phoneMin = 6;
        $phoneMax = $phoneDbMax;
        if ($phoneCountryIso !== '') {
            $countryPhone = $db->get('countries', ['min_length', 'max_length'], [
                'iso' => $phoneCountryIso,
                'status' => 'active',
            ]);
            if ($countryPhone) {
                $phoneMin = max(1, (int)($countryPhone['min_length'] ?? 6));
                $phoneMax = (int)($countryPhone['max_length'] ?? $phoneDbMax);
                if ($phoneMax < 1) {
                    $phoneMax = $phoneDbMax;
                }
                $phoneMax = min($phoneMax, $phoneDbMax);
                if ($phoneMin > $phoneMax) {
                    $phoneMin = $phoneMax;
                }
            }
        }
        $phoneLen = strlen($phoneRaw);
        if ($phoneLen < $phoneMin || $phoneLen > $phoneMax) {
            throw new Exception("Please enter a valid phone number ({$phoneMin}–{$phoneMax} digits) for the selected country.");
        }
        $primaryContact['phone'] = $phoneRaw;

        // Flight number: letters/numbers only, max 20 (Mozio transfer/hourly fields)
        $sanitizeFlight = static function ($value): string {
            return substr(preg_replace('/[^A-Za-z0-9]/', '', (string)$value), 0, 20);
        };
        if (!empty($transferDetails['airline'])) {
            $transferDetails['airline'] = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string)$transferDetails['airline']), 0, 2));
        }
        if (!empty($transferDetails['return_airline'])) {
            $transferDetails['return_airline'] = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string)$transferDetails['return_airline']), 0, 2));
        }
        if (!empty($transferDetails['flight_number'])) {
            $transferDetails['flight_number'] = $sanitizeFlight($transferDetails['flight_number']);
            if ($transferDetails['flight_number'] === '' || strlen($transferDetails['flight_number']) > 20) {
                throw new Exception('Flight number: letters and numbers only, max 20 characters.');
            }
        }
        if (!empty($transferDetails['return_flight_number'])) {
            $transferDetails['return_flight_number'] = $sanitizeFlight($transferDetails['return_flight_number']);
            if ($transferDetails['return_flight_number'] === '' || strlen($transferDetails['return_flight_number']) > 20) {
                throw new Exception('Return flight number: letters and numbers only, max 20 characters.');
            }
        }

        // Mozio: airline + flight required when search result flagged flight_info_required
        // (applies to transfer and hourly airport pickups alike).
        $isMozioBooking = strtolower((string)($carData['supplier'] ?? $carData['supplier_name'] ?? '')) === 'mozio';
        $mozioRaw = is_array($carData['_raw'] ?? null) ? $carData['_raw'] : [];
        $mozioFlightRequired = $isMozioBooking && (
            !empty($carData['flight_info_required']) || !empty($mozioRaw['flight_info_required'])
        );
        if ($mozioFlightRequired) {
            $airline = trim((string)($transferDetails['airline'] ?? ''));
            $flightNo = trim((string)($transferDetails['flight_number'] ?? ''));
            if ($airline === '' || $flightNo === '') {
                throw new Exception('Airline (IATA) and flight number are required for this booking.');
            }
            $tripType = (string)($carData['trip_type'] ?? $mozioRaw['trip_type'] ?? 'one_way');
            if ($tripType === 'round_trip') {
                $retAirline = trim((string)($transferDetails['return_airline'] ?? ''));
                $retFlight = trim((string)($transferDetails['return_flight_number'] ?? ''));
                if ($retAirline === '' || $retFlight === '') {
                    throw new Exception('Return airline and flight number are required for this round-trip booking.');
                }
            }
        }

        // Generate invoice ID
        $invoiceId = str_pad(rand(0, 99999999), 8, '0', STR_PAD_LEFT);

        // Pricing
        $basePrice = (float)($input['base_price'] ?? 0);
        $finalTotal = (float)($input['final_total'] ?? 0);
        $taxAmount = (float)($input['tax_amount'] ?? 0);
        $markupAmount = (float)($input['markup_amount'] ?? 0);
        $extrasAmount = (float)($input['extras_amount'] ?? 0);
        $currency = $input['base_currency'] ?? 'USD';

        // Recover markup from draft car_data when frontend sent 0
        // (common when search wrongly set actual_price = marked price)
        if ($markupAmount <= 0) {
            $details = $carData['actual_price_details'] ?? ($carData['markup_details'] ?? null);
            if (is_array($details) && isset($details['markup'])) {
                $markupAmount = max(0, (float)$details['markup']);
            }
            if ($markupAmount <= 0 && !empty($carData['markup_amount'])) {
                $markupAmount = max(0, (float)$carData['markup_amount']);
            }
            $displayPrice = (float)($carData['display_price'] ?? $carData['price'] ?? $finalTotal);
            $actualFromDraft = (float)($details['converted_base_price'] ?? $details['base_price'] ?? $carData['actual_price'] ?? 0);
            if ($markupAmount <= 0 && $displayPrice > 0 && $actualFromDraft > 0 && $displayPrice > $actualFromDraft) {
                $markupAmount = max(0, round($displayPrice - $actualFromDraft, 2));
            }
            if ($basePrice <= 0 && $actualFromDraft > 0) {
                $basePrice = $actualFromDraft;
            }
        }

        // Last resort: re-run MARKUP() for the logged-in agent
        if ($markupAmount <= 0 && function_exists('MARKUP')) {
            $supplierPrice = $basePrice > 0
                ? $basePrice
                : (float)($carData['original_price'] ?? $carData['actual_price'] ?? 0);
            $fromCur = $carData['original_currency'] ?? ($carData['currency'] ?? $currency);
            if ($supplierPrice > 0) {
                $moduleRow = $db->get('modules', '*', [
                    'name' => strtolower((string)($carData['supplier'] ?? $carData['supplier_name'] ?? 'cars')),
                    'type' => 'cars',
                    'status' => '1'
                ]) ?: 'cars';
                $marked = MARKUP($supplierPrice, $moduleRow, $db, $fromCur, $currency);
                if (is_array($marked)) {
                    $markupAmount = max(0, (float)($marked['markup'] ?? 0));
                    if ($basePrice <= 0) {
                        $basePrice = (float)($marked['converted_base_price'] ?? $supplierPrice);
                    }
                    if ($finalTotal <= 0) {
                        $finalTotal = (float)($marked['price'] ?? 0) + $taxAmount;
                    }
                }
            }
        }

        // Fallback commission if frontend didn't send markup_amount
        if ($markupAmount <= 0 && $finalTotal > $basePrice) {
            $markupAmount = max(0, round($finalTotal - $taxAmount - $basePrice, 2));
        }
        if ($markupAmount <= 0 && $finalTotal > $basePrice) {
            $markupAmount = max(0, round($finalTotal - $basePrice, 2));
        }

        // ====================================================================
        // AUTHORITATIVE SERVER-SIDE PRICING (audit money-integrity — price
        // tampering). The block above could still trust a client-sent
        // base_price / markup_amount / final_total, so a POST of
        // base_price=1&final_total=1 would be charged $1. Re-derive the sell
        // price from the TRUSTED draft cost via MARKUP() (agent b2b + tier +
        // custom applied server-side) and floor base_price at 90% of the trusted
        // supplier cost. Mirrors the flights/stays/tours charge paths.
        // Extras (add-ons) pass through at cost — never marked up.
        // ====================================================================
        $trustedCost = 0.0;
        $mkDetails = $carData['actual_price_details'] ?? ($carData['markup_details'] ?? null);
        if (is_array($mkDetails)) {
            $trustedCost = (float)($mkDetails['converted_base_price'] ?? $mkDetails['base_price'] ?? 0);
        }
        if ($trustedCost <= 0) {
            $trustedCost = (float)($carData['actual_price'] ?? $carData['original_price'] ?? 0);
        }
        if ($trustedCost > 0) {
            // Floor: the client cost figure must cover >=90% of the trusted cost.
            if ($basePrice + 0.01 < ($trustedCost * 0.90)) {
                error_log(sprintf('CARS PRICE TAMPER BLOCKED | invoice=%s | client_base=%.2f trusted=%.2f', $invoiceId, $basePrice, $trustedCost));
                throw new Exception('The car price could not be verified. Please search again and retry your booking.');
            }
            if (function_exists('MARKUP')) {
                $carModuleRow = $db->get('modules', '*', [
                    'name' => strtolower((string)($carData['supplier'] ?? $carData['supplier_name'] ?? 'cars')),
                    'type' => 'cars', 'status' => '1'
                ]) ?: 'cars';
                $fromCur = $carData['original_currency'] ?? ($carData['currency'] ?? $currency);
                $mk = MARKUP($trustedCost, $carModuleRow, $db, $fromCur, $currency);
                if (is_array($mk) && !empty($mk['price'])) {
                    $basePrice    = round((float)($mk['converted_base_price'] ?? $trustedCost), 2);
                    $markupAmount = round((float)($mk['markup'] ?? 0), 2);
                    // Sell = marked base + tax + extras (extras at cost).
                    $finalTotal   = round((float)$mk['price'] + $taxAmount + $extrasAmount, 2);
                }
            }
        }

        // Agent identity + earning (same pattern as flights/stays)
        $userId = (string)($_SESSION['user_id'] ?? '');
        $userData = null;
        $isAgent = false;
        if ($userId !== '') {
            $userData = $db->get('users', '*', ['user_id' => $userId]);
            $isAgent = is_array($userData) && strtolower((string)($userData['role'] ?? '')) === 'agent';
            if (!$isAgent) {
                $isAgent = strtolower((string)($_SESSION['user_role'] ?? '')) === 'agent';
            }
        }
        $agentEarning = $isAgent ? max(0, $markupAmount) : 0;

        // Prepare booking data JSON
        $bookingDataArr = [
            'car_data' => $carData,
            'search_params' => $draftData['search_params'] ?? [],
            'service_type' => $serviceType,
            'guest_details' => $primaryContact,
            'transfer_details' => $transferDetails,
            'special_requests' => $specialRequests,
            'pricing' => [
                'base_price' => $basePrice,
                'final_total' => $finalTotal,
                'tax_amount' => $taxAmount,
                'markup_amount' => $markupAmount,
                'extras_amount' => $extrasAmount,
                'currency' => $currency
            ]
        ];
        $bookingDataJson = json_encode($bookingDataArr);

        // Insert into bookings table
        $db->insert('bookings', [
            'invoice_id' => $invoiceId,
            'language' => getCurrentLanguage(),
            'booking_status' => 'pending',
            'payment_status' => 'unpaid',
            'price_original' => $basePrice,
            'price_markup' => $finalTotal,
            'agent_earning' => $agentEarning,
            'tax' => $taxAmount,
            'currency_markup' => $currency,
            'first_name' => $primaryContact['first_name'] ?? '',
            'last_name' => $primaryContact['last_name'] ?? '',
            'email' => $primaryContact['email'] ?? '',
            'phone' => $primaryContact['phone'] ?? '',
            'module_type' => 'cars',
            'module' => $carData['supplier'] ?? 'cars',
            'booking_data' => $bookingDataJson,
            'payment_gateway' => $paymentGateway,
            'special_requests' => $specialRequests,
            'commission' => $markupAmount,
            'user_id' => $userId !== '' ? $userId : null,
            'user_data' => $userData ? json_encode($userData) : null,
            'created_at' => date('Y-m-d H:i:s'),
            'booking_date' => date('Y-m-d')
        ]);

        $bookingId = $db->id();

        if ($bookingId) {
            // Send Notification (Email/SMS) using NOTIFY library
            if (class_exists('NOTIFY')) {
                $customerData = [
                    'email' => $primaryContact['email'] ?? '',
                    'phone' => $primaryContact['phone'] ?? '',
                    'first_name' => $primaryContact['first_name'] ?? '',
                    'last_name' => $primaryContact['last_name'] ?? '',
                    'country_code' => $formData['country_code'] ?? ''
                ];

                $notifyData = [
                    'invoice_id' => $invoiceId,
                    'amount' => $finalTotal,
                    'currency' => $currency,
                    'payment_status' => 'unpaid',
                    'car_name' => $carData['name'] ?? 'Car Booking',
                    'location' => $draftData['search_params']['pickup_location'] ?? '',
                    'pickup_date' => $draftData['search_params']['pickup_date'] ?? '',
                    'return_date' => $draftData['search_params']['return_date'] ?? '',
                    'module_type' => 'Cars'
                ];

                NOTIFY::booking('cars', $customerData, $notifyData);
            }

            echo json_encode([
                'success' => true, 
                'invoice_id' => $invoiceId, 
                'redirect_url' => urlOnCurrentHost('invoice/cars/' . $invoiceId)
            ]);
        } else {
            throw new Exception('Failed to create booking record');
        }

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});
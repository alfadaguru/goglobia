<?php
// ============================================================================
// FILE: app/routes/api/flights/booking.php
// ============================================================================

@$SECURE or die('Access Denied!');

require_once 'app/lib/jwt.php';


// ============================================================================
// GET: Booking draft by hash
// GET /api/flights/booking/{hash}
// ============================================================================
$router->get('/api/flights/booking/([a-f0-9]{16})', function ($hash) use ($db) {

    header('Content-Type: application/json');

    $booking = $db->get('logs_bookings', ['hash','data','created_at'], ['hash'=>$hash]);

    if (!$booking || empty($booking['data'])) {
        http_response_code(404);
        echo json_encode(['success'=>false,'message'=>'Booking not found']);
        exit;
    }

    echo json_encode([
        'success'=>true,
        'hash'=>$booking['hash'],
        'booking_data'=>json_decode($booking['data'], true),
        'created_at'=>$booking['created_at']
    ]);
    exit;
});



// ============================================================================
// POST: Save booking draft
// POST /api/flights/booking/draft
// Supports ALL suppliers: internal (flights), kayak, duffel, amadeus, etc.
// ============================================================================
$router->post('/api/flights/booking/draft', function () use ($db) {
    header('Content-Type: application/json');

    try {
        // --------------------------------------------------
        // OPTIONAL JWT AUTH
        // --------------------------------------------------
        $userId = null;
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization']
            ?? $headers['authorization']
            ?? $_SERVER['HTTP_AUTHORIZATION']
            ?? '';

        if ($authHeader && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $tokenData = JWT::verify($matches[1]);
            if ($tokenData && !empty($tokenData['user_id'])) {
                $userId = $tokenData['user_id'];
                
                // Fetch user role
                $userData = $db->get('users', ['role'], ['user_id' => $userId]);
                if ($userData) {
                    $userRole = $userData['role'];
                }
            }
        }

        // --------------------------------------------------
        // PARSE INPUT
        // --------------------------------------------------
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }
        if (!is_array($input)) {
            throw new Exception('This flight is unavailable at the moment, please book another.');
        }

        // --------------------------------------------------
        // VALIDATE: flight_data is required
        // --------------------------------------------------
        $flightData = $input['flight_data'] ?? null;
        if (!is_array($flightData) || empty($flightData)) {
            throw new Exception('This flight is unavailable at the moment, please book another.');
        }

        $searchParams    = $input['search_params'] ?? [];
        $displayCurrency = strtoupper(trim((string)($input['currency'] ?? ($flightData['currency'] ?? 'USD'))));

        // --------------------------------------------------
        // DETERMINE SUPPLIER
        // --------------------------------------------------
        // Supplier comes from search results (flights, kayak, duffel, amadeus, kiwi, etc.)
        $supplier = trim((string)($flightData['supplier'] ?? 'flights'));
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $supplier)) {
            throw new Exception('Invalid supplier name');
        }

        // --------------------------------------------------
        // VALIDATE CURRENCY
        // --------------------------------------------------
        $validCurrency = $db->get('currencies', 'name', ['name' => $displayCurrency, 'status' => 1]);
        if (!$validCurrency) {
            throw new Exception('Unsupported currency: ' . $displayCurrency);
        }

        // --------------------------------------------------
        // VALIDATE SEGMENTS / PRICE DATA
        // --------------------------------------------------
        // Flight data from search MUST have segments and price info
        $segments = $flightData['segments'] ?? [];
        $price = (float)($flightData['price'] ?? 0);
        $actualPrice = (float)($flightData['actual_price'] ?? $price);

        if (empty($segments) && empty($flightData['flight_number']) && empty($flightData['flight_no'])) {
            throw new Exception('This flight is unavailable at the moment, please book another.');
        }
        if ($price <= 0 && $actualPrice <= 0) {
            throw new Exception('This flight is unavailable at the moment, please book another.');
        }

        // --------------------------------------------------
        // EXTRACT KEY FIELDS FROM FLIGHT DATA
        // Build consistent field names for the draft
        // --------------------------------------------------
        $firstSegment = null;
        $lastSegment = null;
        if (!empty($segments)) {
            if (is_array($segments[0]) && !isset($segments[0]['flight_no'])) {
                $firstLeg = $segments[0];
                $firstSegment = is_array($firstLeg) ? ($firstLeg[0] ?? null) : null;
                
                // Determine if it is a multicity flight
                $isMultiCity = (($flightData['type'] ?? '') === 'multicity' || ($searchParams['type'] ?? '') === 'multicity');
                if ($isMultiCity) {
                    $lastLeg = end($segments);
                    $lastSegment = is_array($lastLeg) ? end($lastLeg) : $lastLeg;
                } else {
                    $lastSegment = is_array($firstLeg) ? end($firstLeg) : null;
                }
                
                if (count($segments) > 1 && !$isMultiCity) {
                    $flightData['type'] = 'return';
                }
            } else {
                $firstSegment = $segments[0] ?? null;
                $lastSegment = end($segments);
            }
        }

        // Extract primary flight info
        $flightNo       = (string)($flightData['flight_no'] ?? ($flightData['flight_number'] ?? ($firstSegment['flight_no'] ?? '')));
        $airlineName    = (string)($flightData['airline_name'] ?? ($flightData['airline'] ?? ($firstSegment['airline'] ?? ($firstSegment['airline_name'] ?? ''))));
        $airlineCode    = (string)($flightData['airline_code'] ?? ($flightData['img'] ?? ($firstSegment['img'] ?? ($firstSegment['airline'] ?? ''))));
        $departureCode  = (string)($flightData['departure_code'] ?? ($flightData['from'] ?? ($firstSegment['departure_code'] ?? '')));
        $arrivalCode    = (string)($flightData['arrival_code'] ?? ($flightData['to'] ?? ($lastSegment['arrival_code'] ?? '')));
        $departureDate  = (string)($flightData['departure_date'] ?? ($firstSegment['departure_date'] ?? ($searchParams['departure_date'] ?? ($searchParams['flights_departure_date'] ?? ''))));
        $departureTime  = (string)($flightData['departure_time'] ?? ($firstSegment['departure_time'] ?? ''));
        $arrivalTime    = (string)($flightData['arrival_time'] ?? ($lastSegment['arrival_time'] ?? ''));
        $durationTime   = (string)($flightData['duration_time'] ?? ($flightData['duration'] ?? ($firstSegment['duration_time'] ?? ($firstSegment['total_duration'] ?? ''))));
        $cabinClass     = (string)($flightData['class'] ?? ($flightData['cabin_class'] ?? ($firstSegment['class'] ?? ($searchParams['class'] ?? 'economy'))));
        $baggage        = $flightData['baggage'] ?? ($firstSegment['baggage'] ?? null);
        $cabinBaggage   = $flightData['cabin_baggage'] ?? ($firstSegment['cabin_baggage'] ?? null);
        $refundable     = $flightData['refundable'] ?? ($firstSegment['refundable'] ?? false);
        $tripType       = (string)($flightData['type'] ?? ($searchParams['type'] ?? ($searchParams['flight_type'] ?? 'oneway')));
        $redirectUrl    = (string)($flightData['redirect_url'] ?? ($firstSegment['redirect_url'] ?? ''));

        // Passenger counts from search params
        $adults   = max(1, (int)($searchParams['adults'] ?? 1));
        $children = max(0, (int)($searchParams['children'] ?? ($searchParams['childrens'] ?? 0)));
        $infants  = max(0, (int)($searchParams['infants'] ?? 0));

        // Per-passenger pricing from flight data
        $adultPrice  = (float)($flightData['adult_price'] ?? ($firstSegment['adult_price'] ?? $price));
        $childPrice  = (float)($flightData['child_price'] ?? ($firstSegment['child_price'] ?? 0));
        $infantPrice = (float)($flightData['infant_price'] ?? ($firstSegment['infant_price'] ?? 0));
        $actualAdultPrice  = (float)($flightData['actual_adult_price'] ?? ($firstSegment['actual_adult_price'] ?? $actualPrice));
        $actualChildPrice  = (float)($flightData['actual_child_price'] ?? ($firstSegment['actual_child_price'] ?? 0));
        $actualInfantPrice = (float)($flightData['actual_infant_price'] ?? ($firstSegment['actual_infant_price'] ?? 0));

        // Booking data (search-specific, e.g. booking token, flight_id, etc.)
        $bookingDataMeta = $flightData['booking_data'] ?? ($firstSegment['booking_data'] ?? []);

        // Return date
        $returnDate = (string)($flightData['return_date'] ?? ($searchParams['return_date'] ?? ($searchParams['flights_return_date'] ?? '')));

        // --------------------------------------------------
        // FOR INTERNAL SUPPLIER: OPTIONALLY VERIFY FLIGHT EXISTS
        // --------------------------------------------------
        if ($supplier === 'flights' && !empty($bookingDataMeta['flight_id'])) {
            $internalFlightId = (int)$bookingDataMeta['flight_id'];
            $flightCheck = $db->get('flights', 'id', ['id' => $internalFlightId, 'status' => 1]);
            if (!$flightCheck) {
                throw new Exception('This flight is unavailable at the moment, please book another.');
            }
        }

        // --------------------------------------------------
        // BUILD DRAFT DATA
        // --------------------------------------------------
        // Merge full data (preserving seats, ancillaries, etc.)
        $bookingDraftData = array_merge($input, [
            'flight_data'   => $flightData,
            'search_params' => $searchParams,
            'type'          => 'flight',
            'supplier'      => $supplier,
            'currency'      => $displayCurrency,

            // Summary fields for quick reference
            'flight_number'     => $flightNo,
            'airline_name'      => $airlineName,
            'departure_code'    => $departureCode,
            'arrival_code'      => $arrivalCode,
            'departure_date'    => $departureDate,
            'return_date'       => $returnDate,
            'departure_time'    => $departureTime,
            'arrival_time'      => $arrivalTime,
            'duration'          => $durationTime,
            'cabin_class'       => $cabinClass,
            'trip_type'         => $tripType,
            'baggage'           => $baggage,
            'cabin_baggage'     => $cabinBaggage,
            'refundable'        => $refundable,
            'redirect_url'      => $redirectUrl,

            // Passengers
            'adults'   => $adults,
            'children' => $children,
            'infants'  => $infants,

            // Pricing 
            'price'              => round($price, 2),
            'actual_price'       => round($actualPrice, 2),
            'adult_price'        => round($adultPrice, 2),
            'child_price'        => round($childPrice, 2),
            'infant_price'       => round($infantPrice, 2),
            'actual_adult_price' => round($actualAdultPrice, 2),
            'actual_child_price' => round($actualChildPrice, 2),
            'actual_infant_price'=> round($actualInfantPrice, 2),

            // Meta
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+30 minutes')),
            'client_ip'  => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_id'    => $userId,
        ]);

        // --------------------------------------------------
        // STORE DRAFT
        // --------------------------------------------------
        $hash = bin2hex(random_bytes(8));
        if (strlen($hash) !== 16) {
            throw new Exception('Failed to generate valid booking hash');
        }

        $result = $db->insert('logs_bookings', [
            'hash'       => $hash,
            'data'       => json_encode($bookingDraftData),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        if (!$result) {
            throw new Exception('Failed to save booking data');
        }

        // --------------------------------------------------
        // WEBHOOK
        // --------------------------------------------------
        triggerWebhook('flights/booking', 'flights.booking.draft_created', [
            'booking_hash'   => $hash,
            'flight_number'  => $flightNo,
            'supplier'       => $supplier,
            'origin'         => $departureCode,
            'destination'    => $arrivalCode,
            'price'          => round($price, 2),
            'currency'       => $displayCurrency,
            'timestamp'      => date('Y-m-d H:i:s'),
            'user_id'        => $userId,
        ]);

        // --------------------------------------------------
        // RESPONSE 
        // --------------------------------------------------
        echo json_encode([
            'success'  => true,
            'hash'     => $hash,
            'currency' => $displayCurrency,
            'message'  => 'Booking draft saved successfully'
        ]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
});


// ============================================================================
// POST: Submit final booking
// POST /api/flights/booking/submit
// ============================================================================

$router->post('/api/flights/booking/submit', function () use ($db) {

    header('Content-Type: application/json');

    try {
        // --------------------------------------------------
        // OPTIONAL JWT AUTH (NOT REQUIRED)
        // --------------------------------------------------
        $userId = null;
        $userData = null;

        $allHeaders = function_exists('getallheaders') ? getallheaders() : [];
        $headersLower = [];
        foreach ($allHeaders as $k => $v) {
            $headersLower[strtolower($k)] = $v;
        }
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $hKey = strtolower(str_replace('_', '-', substr($k, 5)));
                $headersLower[$hKey] = $v;
            }
        }
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headersLower['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $authHeader = $headersLower['authorization'] ?? '';
        $token = '';
        if (!empty($authHeader)) {
            if (preg_match('/Bearer\s(\S+)/i', $authHeader, $matches)) {
                $token = $matches[1];
            } else {
                $token = trim($authHeader);
            }
        }

        if (empty($token)) {
            $token = $headersLower['token'] ?? $headersLower['jwt'] ?? $headersLower['x-access-token'] ?? '';
        }
        if (empty($token)) {
            $token = $_POST['token'] ?? $_POST['access_token'] ?? $_POST['jwt']
                  ?? $_GET['token'] ?? $_GET['access_token'] ?? $_GET['jwt']
                  ?? '';
        }

        if (!empty($token)) {
            try {
                $tokenData = JWT::verify($token);
                if (!$tokenData && method_exists('JWT', 'decode')) {
                    $tokenData = JWT::decode($token, false);
                }
                if ($tokenData && !empty($tokenData['user_id'])) {
                    $userId = $tokenData['user_id'];
                    $whereClause = ['user_id' => (string)$userId];
                    if (is_numeric($userId)) {
                        $whereClause = ['OR' => ['user_id' => (string)$userId, 'id' => (int)$userId]];
                    }
                    $userData = $db->get('users', '*', $whereClause);
                    if ($userData) {
                        $userRole = $userData['role'] ?? 'user';
                        $_SESSION['user_id'] = $userId;
                        $_SESSION['user_role'] = $userRole;
                    }
                }
            } catch (Exception $e) {
                // Silent fail
            }
        }

        $input = json_decode(file_get_contents('php://input'), true);

        // --------------------------------------------------
        // VALIDATIONS
        // --------------------------------------------------
        if (!$input || empty($input['booking_hash'])) {
            throw new Exception('Invalid booking data');
        }

        // --------------------------------------------------
        // GET DRAFT
        // --------------------------------------------------
        $draft = $db->get('logs_bookings', ['data', 'created_at'], ['hash' => $input['booking_hash']]);
        if (!$draft || empty($draft['data'])) {
            throw new Exception('Booking draft not found');
        }

        $draftData  = json_decode($draft['data'], true);
        if (!is_array($draftData)) {
            $draftData = [];
        }

        $flightData = [];
        if (!empty($draftData['flight_data']) && is_array($draftData['flight_data'])) {
            $flightData = $draftData['flight_data'];
        } else {
            $flightData = $draftData;
        }

        // --------------------------------------------------
        // DRAFT EXPIRY CHECK (30 minutes)
        // --------------------------------------------------
        $expiresAt = $draftData['expires_at'] ?? ($flightData['expires_at'] ?? null);
        if ($expiresAt && strtotime($expiresAt) < time()) {
            $db->delete('logs_bookings', ['hash' => $input['booking_hash']]);
            throw new Exception('Booking draft has expired. Please create a new draft.');
        }

        $supplierName = $flightData['supplier'] ?? 'flights';

        // --------------------------------------------------
        // PRE-PAYMENT REVALIDATION (mirror web /api/flight/booking/submit)
        // Mobile may call /api/flight/booking/revalidate first; when the draft is
        // already revalidated, ignore stale base_price/subtotal from the submit body.
        // --------------------------------------------------
        $alreadyRevalidated = !empty($flightData['revalidated'])
            || !empty($flightData['booking_data']['revalidated_at']);

        if ($alreadyRevalidated) {
            unset($input['base_price'], $input['subtotal'], $input['markup_amount'], $input['tax_amount']);
        } else {
            $revalidateFile = dirname(__DIR__, 4) . '/modules/flights/' . strtolower($supplierName) . '/revalidate.php';
            if (file_exists($revalidateFile)) {
                $oldPrice = (float)($flightData['actual_price'] ?? $flightData['price'] ?? 0);
                $currency = strtoupper(trim((string)(
                    $input['base_currency']
                    ?? $draftData['currency']
                    ?? $flightData['currency']
                    ?? 'USD'
                )));
                $revalidateResult = flightCallSupplierRevalidate($db, $flightData, $supplierName, $oldPrice, $currency);

                if (empty($revalidateResult['status'])) {
                    throw new Exception($revalidateResult['message'] ?? 'Selected fare is no longer available. Please search again.');
                }

                if (!empty($revalidateResult['data']['price_changed'])) {
                    if (!empty($revalidateResult['data']['actual_price'])) {
                        $flightData['actual_price'] = (float)$revalidateResult['data']['actual_price'];
                    }
                    if (!empty($revalidateResult['data']['new_price'])) {
                        $flightData['price'] = (float)$revalidateResult['data']['new_price'];
                    }
                }

                $flightData['revalidated'] = true;
                if (!isset($flightData['booking_data']) || !is_array($flightData['booking_data'])) {
                    $flightData['booking_data'] = [];
                }
                $flightData['booking_data']['revalidated_at'] = date('c');
                $draftData['flight_data'] = $flightData;
                unset($input['base_price'], $input['subtotal'], $input['markup_amount'], $input['tax_amount']);
            }
        }

        // --------------------------------------------------
        // FORM DATA
        // --------------------------------------------------
        $formData         = $input['guest_details'] ?? [];
        $primaryGuest     = $formData['primary_guest'] ?? [];
        $passengers       = $formData['passengers'] ?? $formData['travelers'] ?? [];
        
        // Extract Ancillaries from root or form data
        $baggage          = $input['baggage'] ?? $formData['baggage'] ?? [];
        $selectedSeat     = $input['seat'] ?? $input['selectedSeat'] ?? $formData['selectedSeat'] ?? null;
        $ancillaryData    = $input['ancillary_data'] ?? [];
        
        $fareType         = $formData['fareType'] ?? 'standard';
        $paymentGateway   = $formData['selected_payment'] ?? '';
        $specialRequests  = $formData['special_requests'] ?? '';

        // --------------------------------------------------
        // GUEST VALIDATION
        // --------------------------------------------------
        if (empty($primaryGuest['first_name']) || empty($primaryGuest['last_name']) || empty($primaryGuest['email'])) {
            throw new Exception('Please fill in all required guest details.');
        }

        // Sanitize inputs (XSS prevention)
        $primaryGuest['first_name'] = htmlspecialchars(strip_tags(trim($primaryGuest['first_name'])), ENT_QUOTES, 'UTF-8');
        $primaryGuest['last_name']  = htmlspecialchars(strip_tags(trim($primaryGuest['last_name'])), ENT_QUOTES, 'UTF-8');
        $primaryGuest['email']      = filter_var(trim($primaryGuest['email']), FILTER_SANITIZE_EMAIL);
        $primaryGuest['phone']      = preg_replace('/[^0-9+\-\s]/', '', $primaryGuest['phone'] ?? '');

        // Email format validation
        if (!filter_var($primaryGuest['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address format.');
        }

        // --------------------------------------------------
        // COUNT PASSENGERS
        // --------------------------------------------------
        $adultsCount   = 0;
        $childrenCount = 0;
        $infantsCount  = 0;
        $childAges     = [];

        // Detect format: keyed (adult_0, child_0)
        $isKeyedFormat = isset($passengers['adult_0']);

        if ($isKeyedFormat) {
            foreach ($passengers as $key => $pax) {
                // Ensure each passenger has an 'id' that matches the key (adult_0, etc.) for ancillary mapping
                $passengers[$key]['id'] = $key;

                if (strpos($key, 'adult_') === 0) $adultsCount++;
                if (strpos($key, 'child_') === 0) {
                    $childrenCount++;
                    $childAges[] = $pax['age'] ?? 0;
                }
                if (strpos($key, 'infant_') === 0) $infantsCount++;

                // Construct Date of Birth for database save
                if (isset($pax['dob_year']) && isset($pax['dob_month']) && isset($pax['dob_day'])) {
                    $passengers[$key]['dob'] = $pax['dob_year'] . '-' . $pax['dob_month'] . '-' . $pax['dob_day'];
                }

                // Construct Passport Expiry Date for database save
                if (isset($pax['passport_expiry_year']) && isset($pax['passport_expiry_month']) && isset($pax['passport_expiry_day'])) {
                    $passengers[$key]['passport_expiry'] = $pax['passport_expiry_year'] . '-' . $pax['passport_expiry_month'] . '-' . $pax['passport_expiry_day'];
                }
            }
        } else {
            // Convert list format to keyed format
            $newPassengers = [];
            $a=0; $c=0; $i=0;
            foreach ($passengers as $pax) {
                $type = strtolower($pax['type'] ?? 'adult');
                $key = 'adult_0';
                if ($type === 'adult') { $key = 'adult_' . $a++; $adultsCount++; }
                if ($type === 'child') { $key = 'child_' . $c++; $childrenCount++; $childAges[] = $pax['age'] ?? 0; }
                if ($type === 'infant') { $key = 'infant_' . $i++; $infantsCount++; }
                
                $pax['id'] = $key;

                // Construct Date of Birth
                if (isset($pax['dob_year']) && isset($pax['dob_month']) && isset($pax['dob_day'])) {
                    $pax['dob'] = $pax['dob_year'] . '-' . $pax['dob_month'] . '-' . $pax['dob_day'];
                }
                // Construct Passport Expiry
                if (isset($pax['passport_expiry_year']) && isset($pax['passport_expiry_month']) && isset($pax['passport_expiry_day'])) {
                    $pax['passport_expiry'] = $pax['passport_expiry_year'] . '-' . $pax['passport_expiry_month'] . '-' . $pax['passport_expiry_day'];
                }

                $newPassengers[$key] = $pax;
            }
            $passengers = $newPassengers;
        }

        // ============================================================================
        // PRICING
        // ============================================================================
        // All amounts in BASE CURRENCY
        // Fallback to draft data if missing from input to support minimal payloads
        $pricing = $draftData['pricing_breakdown'] ?? ($flightData['pricing_breakdown'] ?? []);

        $actualPriceBase = (float)($input['base_price']    ?? $pricing['actual_total'] ?? $flightData['actual_price'] ?? 0);
        $markupAmount    = (float)($input['markup_amount']  ?? $pricing['markup_amount'] ?? (($flightData['price'] ?? 0) - ($flightData['actual_price'] ?? 0)) ?? 0);
        $subtotal        = (float)($input['subtotal']       ?? $pricing['markup_total'] ?? $flightData['price'] ?? 0);
        $taxAmountBase   = (float)($input['tax_amount']    ?? 0);

        // SECURITY (H3): reject a client base_price below the trusted supplier
        // price from the server-side draft (10% tolerance). See web submit path.
        $trustedBaseApi = (float)($flightData['actual_price'] ?? $flightData['price'] ?? 0);
        if ($trustedBaseApi > 0 && ($actualPriceBase + 0.01) < ($trustedBaseApi * 0.90)) {
            error_log(sprintf('PRICE TAMPER BLOCKED (api) | client_base=%.2f trusted_base=%.2f', $actualPriceBase, $trustedBaseApi));
            throw new Exception('The fare price could not be verified. Please search again and retry your booking.');
        }

        // Re-evaluate MARKUP based on logged-in user / agent token
        if (!function_exists('MARKUP')) {
            require_once dirname(__DIR__, 4) . '/modules/helpers.php';
        }

        if ($actualPriceBase > 0 && function_exists('MARKUP')) {
            $fltModule = $db->get('modules', '*', ['name' => $supplierName, 'type' => 'flights', 'status' => '1']);
            if (!$fltModule) {
                $fltModule = $db->get('modules', '*', ['name' => 'flights', 'type' => 'flights', 'status' => '1']);
            }
            $baseCurr = $input['base_currency'] ?? $draftData['currency'] ?? 'USD';
            $markedInfo = MARKUP($actualPriceBase, $fltModule ?: null, $db, $baseCurr, $baseCurr);
            if (!empty($markedInfo['price']) && $markedInfo['price'] > 0) {
                $subtotal = round((float)$markedInfo['price'], 2);
                $markupAmount = round((float)($markedInfo['price'] - $actualPriceBase), 2);
            }
        }

        // Add Ancillary total to the base price and subtotal
        $ancillaryTotal = (float)($ancillaryData['total_base'] ?? 0);
        $actualPriceBase += $ancillaryTotal;
        $subtotal += $ancillaryTotal;

        // Calculate tax if missing but we have subtotal
        if ($taxAmountBase <= 0 && $subtotal > 0) {
            $taxCalc = calculateTax($subtotal, $supplierName, $db);
            $taxAmountBase = (float)($taxCalc['tax_amount'] ?? 0);
        }

        // Final total reconstruction
        $finalTotalBase = round($subtotal + $taxAmountBase, 2);

        // --------------------------------------------------
        // PROMO CODE HANDLING
        // --------------------------------------------------
        $promoCodeStr = trim($input['promo_code'] ?? '');
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
            }
        }

        // APPLY PROMO DISCOUNT TO FINAL TOTAL
        if ($promoDiscount > 0 && $promoData) {
            $finalTotalBase = round($finalTotalBase - $promoDiscount, 2);
        }

        // BASE = booking/supplier currency (stored amounts). DISPLAY = app-selected
        // currency from submit payload — REQUIRED so invoice can convert like web.
        $baseCurrency = strtoupper(trim((string)(
            $input['base_currency']
            ?? $draftData['base_currency']
            ?? $draftData['currency']
            ?? $flightData['base_currency']
            ?? $flightData['currency']
            ?? 'USD'
        )));
        $displayCurrency = strtoupper(trim((string)(
            $input['currency']
            ?? $input['display_currency']
            ?? ''
        )));
        if ($displayCurrency === '') {
            throw new Exception('currency is required. Send the app display currency (e.g. PKR, USD, EUR).');
        }
        $validDisplayCurrency = $db->get('currencies', ['name', 'rate'], ['name' => $displayCurrency, 'status' => 1]);
        if (!$validDisplayCurrency) {
            throw new Exception('Unsupported currency: ' . $displayCurrency);
        }
        $validBaseCurrency = $db->get('currencies', ['name', 'rate'], ['name' => $baseCurrency, 'status' => 1]);
        if (!$validBaseCurrency) {
            $baseCurrency = 'USD';
            $validBaseCurrency = $db->get('currencies', ['name', 'rate'], ['name' => 'USD', 'status' => 1]);
        }

        // Server-side conversion (do NOT trust client display totals)
        $fromRate = (float)($validBaseCurrency['rate'] ?? 1);
        $toRate   = (float)($validDisplayCurrency['rate'] ?? 1);
        $conversionRate = ($baseCurrency === $displayCurrency || $fromRate <= 0)
            ? 1.0
            : ($toRate / $fromRate);
        $convert = static function ($amount) use ($conversionRate) {
            return round((float)$amount * $conversionRate, 2);
        };

        // Calculate commission (markup amount)
        $commissionBase = $markupAmount;

        // Validate pricing data
        if ($subtotal <= 0) {
            throw new Exception('Invalid booking price. Please try again.');
        }

        $displayBasePrice  = $convert($actualPriceBase);
        $displayMarkupAmt  = $convert($commissionBase);
        $displaySubtotal   = $convert($subtotal);
        $displayTaxAmount  = $convert($taxAmountBase);
        $displayFinalTotal = $convert($finalTotalBase);

        // --------------------------------------------------
        // MODULE & TAX CONFIG
        // --------------------------------------------------
        $moduleData = $db->get('modules', ['tax_type'], [
            'name'   => $supplierName,
            'type'   => 'flights',
            'status' => '1'
        ]);

        if (!$moduleData && $supplierName !== 'flights') {
            $moduleData = $db->get('modules', ['tax_type'], [
                'name'   => 'flights',
                'type'   => 'flights',
                'status' => '1'
            ]);
        }

        $taxType = $moduleData['tax_type'] ?? 'percentage';

        // --------------------------------------------------
        // USER RESOLUTION
        // --------------------------------------------------
        if (!$userId) {
            // Try to find existing user by email
            $existingUser = $db->get('users', '*', [
                'email' => $primaryGuest['email']
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
                    'first_name'         => $primaryGuest['first_name'],
                    'last_name'          => $primaryGuest['last_name'],
                    'email'              => $primaryGuest['email'],
                    'password'           => $hashedPassword,
                    'phone'              => $primaryGuest['phone'] ?? '',
                    'phone_country_code' => $primaryGuest['country_code'] ?? '',
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

        // --------------------------------------------------
        // AGENT EARNING
        // --------------------------------------------------
        $isAgent = false;
        if ($userData) {
            $isAgent = ($userData['role'] ?? '') === 'agent';
        }
        $agentEarning = $isAgent ? $commissionBase : 0;

        // --------------------------------------------------
        // JSON DATA FOR STORAGE & INVOICE COMPATIBILITY
        // --------------------------------------------------
        if (empty($flightData['airline']) && !empty($flightData['segments'])) {
            $segments = $flightData['segments'];
            $firstSeg = null;
            $lastSeg  = null;
            
            // Handle nested vs flat segments
            if (isset($segments[0]) && is_array($segments[0]) && !isset($segments[0]['flight_no'])) {
                $firstLeg = $segments[0];
                $firstSeg = is_array($firstLeg) ? ($firstLeg[0] ?? null) : null;
                
                // Correctly resolve last segment for multicity flights
                $isMultiCity = (($flightData['type'] ?? '') === 'multicity');
                if ($isMultiCity) {
                    $lastLeg = end($segments);
                    $lastSeg = is_array($lastLeg) ? end($lastLeg) : $lastLeg;
                } else {
                    $lastSeg = is_array($firstLeg) ? end($firstLeg) : null;
                }

                if (count($segments) > 1 && !$isMultiCity) {
                    $flightData['type'] = 'return';
                }
            } else {
                $firstSeg = $segments[0] ?? null;
                $lastSeg  = end($segments);
            }
            
            if ($firstSeg) {
                $flightData['img']               = $flightData['img'] ?? $firstSeg['img'] ?? '';
                $flightData['airline']           = $flightData['airline'] ?? $firstSeg['airline'] ?? '';
                $flightData['airline_name']      = $flightData['airline_name'] ?? $firstSeg['airline_name'] ?? '';
                $flightData['flight_no']         = $flightData['flight_no'] ?? $firstSeg['flight_no'] ?? '';
                $flightData['departure_code']    = $flightData['departure_code'] ?? $firstSeg['departure_code'] ?? '';
                $flightData['departure_airport'] = $flightData['departure_airport'] ?? $firstSeg['departure_airport'] ?? '';
                $flightData['departure_time']    = $flightData['departure_time'] ?? $firstSeg['departure_time'] ?? '';
                $flightData['departure_date']    = $flightData['departure_date'] ?? $firstSeg['departure_date'] ?? '';
                $flightData['duration_time']     = $flightData['duration_time'] ?? $firstSeg['duration_time'] ?? ($firstSeg['total_duration'] ?? '');
                $flightData['baggage']           = $flightData['baggage'] ?? $firstSeg['baggage'] ?? '';
                $flightData['cabin_baggage']     = $flightData['cabin_baggage'] ?? $firstSeg['cabin_baggage'] ?? '';
            }
            
            if ($lastSeg) {
                $flightData['arrival_code']      = $flightData['arrival_code'] ?? $lastSeg['arrival_code'] ?? '';
                $flightData['arrival_airport']   = $flightData['arrival_airport'] ?? $lastSeg['arrival_airport'] ?? '';
                $flightData['arrival_time']      = $flightData['arrival_time'] ?? $lastSeg['arrival_time'] ?? '';
                $flightData['arrival_date']      = $flightData['arrival_date'] ?? $lastSeg['arrival_date'] ?? '';
            }
        }

        $baggage = $input['baggage'] ?? $formData['baggage'] ?? [];
        
        if (is_array($baggage)) {
            foreach ($baggage as &$bag) {
                if (isset($bag['name']) && isset($bag['description']) && strtolower(trim($bag['name'])) === strtolower(trim($bag['description']))) {
                    $bag['description'] = '';
                }
            }
        }
        $normalizedBaggage = $baggage;
        
        if (empty($normalizedBaggage) && !empty($ancillaryData['services'])) {
            foreach ($ancillaryData['services'] as $svc) {
                if (($svc['type'] ?? '') === 'baggage') {
                    $description = $svc['description'] ?? '';
                    if (($svc['name'] ?? '') === $description) { $description = ''; }

                    $normalizedBaggage[] = [
                        'passengerId' => $svc['passenger_id'] ?? $svc['passengerId'] ?? 'adult_0',
                        'name'        => $svc['name'] ?? 'Extra Bag',
                        'quantity'    => $svc['quantity'] ?? 1,
                        'description' => $description
                    ];
                }
            }
        }

        // --- Normalize Seats (For Passenger Icons - Segment Based) ---
        $normalizedSeats = [];
        
        // Handle single seat object from mobile
        if ($selectedSeat) {
            $pId = $selectedSeat['passenger_id'] ?? $selectedSeat['passengerId'] ?? 'adult_0';
            $segIdx = $selectedSeat['segment_index'] ?? 0;
            $normalizedSeats[$segIdx][$pId] = [
                'designator' => $selectedSeat['designator'] ?? $selectedSeat['id'] ?? 'N/A'
            ];
        }

        // Scan ancillary services for more seats
        if (!empty($ancillaryData['services'])) {
            foreach ($ancillaryData['services'] as $svc) {
                if (($svc['type'] ?? '') === 'seat') {
                    $pId = $svc['passenger_id'] ?? $svc['passengerId'] ?? 'adult_0';
                    $segIdx = $svc['segment_index'] ?? 0;
                    $normalizedSeats[$segIdx][$pId] = [
                        'designator' => $svc['designator'] ?? $svc['name'] ?? 'N/A'
                    ];
                }
            }
        }
        
        // Final sanity check on flight_data root fields for invoice
        $flightData['type']       = $flightData['type'] ?? 'oneway';
        $flightData['refundable'] = isset($flightData['refundable']) ? (bool)$flightData['refundable'] : false;
        $flightData['supplier']   = $flightData['supplier'] ?? $supplierName;

        $bookingDataJson = json_encode([
            'flight_data'   => $flightData,
            'key'           => $draftData['key'] ?? null,
            'baggage'       => $normalizedBaggage,
            'seat'          => $normalizedSeats,
            'ancillary_data'=> $ancillaryData,
            'fare_type'     => $fareType,
            'passengers'    => $passengers,

            // Pricing breakdown (BASE currency — source of truth)
            'base_price'    => $actualPriceBase,
            'markup_amount' => $commissionBase,
            'subtotal'      => $subtotal,
            'tax_amount'    => $taxAmountBase,
            'final_total'   => $finalTotalBase,

            // Display amounts (converted server-side from app `currency`)
            'base_price_display'    => $displayBasePrice,
            'markup_amount_display' => $displayMarkupAmt,
            'subtotal_display'      => $displaySubtotal,
            'tax_amount_display'    => $displayTaxAmount,
            'final_total_display'   => $displayFinalTotal,

            // Currency info
            'base_currency'         => $baseCurrency,
            'display_currency'      => $displayCurrency,
            'conversion_rate'       => $conversionRate,
        ]);

        // Travellers JSON
        $travellersJson = json_encode($passengers);

        // --------------------------------------------------
        // INSERT BOOKING
        // --------------------------------------------------
        $invoiceId = str_pad(rand(0, 99999999), 8, '0', STR_PAD_LEFT);

        $db->insert('bookings', [
            'invoice_id'            => $invoiceId,
            'language'              => getCurrentLanguage(),
            'booking_status'        => 'pending',
            'payment_status'        => 'unpaid',

            // Pricing (all in BASE currency)
            'price_original'        => $actualPriceBase,
            'price_markup'          => $finalTotalBase,
            'agent_earning'         => $agentEarning,
            'tax_type'              => $taxType,
            'tax'                   => $taxAmountBase,

            // Guest details
            'first_name'            => $primaryGuest['first_name'] ?? '',
            'last_name'             => $primaryGuest['last_name'] ?? '',
            'email'                 => $primaryGuest['email'] ?? '',
            'phone_country_code'    => getPhoneCode($primaryGuest['country_code'] ?? '', $db),
            'phone'                 => $primaryGuest['phone'] ?? '',
            'country'               => '',
            'address'               => '',

            // Passengers
            'adults'                => $adultsCount,
            'infants'               => (string)$infantsCount,
            'childs'                => $childrenCount,
            'child_ages'            => implode(',', $childAges),

            // Currency & payment
            'currency_markup'       => $baseCurrency,
            'paid_at'               => null,
            'cancellation_request'  => 0,
            'cancellation_status'   => 0,
            'cancellation_response' => null,

            // Booking data & travellers
            'booking_data'          => $bookingDataJson,
            'transaction_id'        => '',
            'user_id'               => $userId,
            'user_data'             => '',
            'travellers'            => $travellersJson,
            'nationality'           => '',
            'payment_gateway'       => $paymentGateway,

            // Module info
            'module_type'           => 'flights',
            'pnr'                   => '',
            'booking_response'      => null,
            'error_response'        => null,
            'commission'            => $commissionBase,
            'module'                => $supplierName,
            'special_requests'      => $specialRequests,
            'created_at'            => date('Y-m-d H:i:s'),
            'booking_date'          => date('Y-m-d'),
            'promo_codes'           => $promoCodeJson
        ]);

        $bookingId = $db->id();

        if (!$bookingId) {
            throw new Exception('Failed to create booking');
        }

        // Record promo code usage
        if (!empty($promoCodeStr) && $promoDiscount > 0 && $promoData) {
            $db->update('promo_codes', ['used_count[+]' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $promoData['id']]);
        }

        // --------------------------------------------------
        // DELETE DRAFT
        // --------------------------------------------------
        $db->delete('logs_bookings', ['hash' => $input['booking_hash']]);

        // --------------------------------------------------
        // WEBHOOKS & PDF 
        // --------------------------------------------------
        triggerWebhook('flights/booking', 'flights.booking.confirmed', [
            'booking_id'      => $bookingId,
            'invoice_id'      => $invoiceId,
            'user_id'         => $userId,
            'customer_name'   => ($primaryGuest['first_name'] ?? '') . ' ' . ($primaryGuest['last_name'] ?? ''),
            'customer_email'  => $primaryGuest['email'] ?? '',
            'customer_phone'  => $primaryGuest['phone'] ?? '',
            'total_amount'    => $finalTotalBase,
            'currency'        => $baseCurrency,
            'adults'          => $adultsCount,
            'children'        => $childrenCount,
            'infants'         => $infantsCount,
            'payment_gateway' => $paymentGateway,
            'from'            => $flightData['from'] ?? $flightData['departure_code'] ?? '',
            'to'              => $flightData['to'] ?? $flightData['arrival_code'] ?? '',
            'departure_date'  => $flightData['departure_date'] ?? '',
            'return_date'     => $flightData['return_date'] ?? '',
            'timestamp'       => date('Y-m-d H:i:s')
        ]);

        $pdfPath = GENERATE_BOOKING_PDF($invoiceId);
        if ($pdfPath && file_exists($pdfPath)) {
            triggerWebhook('flights/invoice', 'flights.invoice.pdf_generated', [
                'invoice_id' => $invoiceId,
                'booking_id' => $bookingId,
                'pdf_path'   => $pdfPath,
                'timestamp'  => date('Y-m-d H:i:s')
            ]);
        }

        // --------------------------------------------------
        // NOTIFICATIONS 
        // --------------------------------------------------
        $notifyBookingData = [
            'invoice_id'      => $invoiceId,
            'amount'          => $displayFinalTotal,
            'currency'        => $displayCurrency,
            'payment_status'  => 'unpaid',
            'module_type'     => 'Flight',
            'from'            => $flightData['from'] ?? $flightData['departure_code'] ?? '',
            'to'              => $flightData['to'] ?? $flightData['arrival_code'] ?? '',
            'date'            => $flightData['departure_date'] ?? date('Y-m-d'),
            'departure_date'  => $flightData['departure_date'] ?? date('Y-m-d'),
            'flight_number'   => $flightData['flight_number'] ?? ($flightData['routes'][0]['flight_number'] ?? ''),
            'airline_name'    => $flightData['airline_name'] ?? ($flightData['routes'][0]['airline_name'] ?? ''),
            'booking_data'    => $bookingDataJson,
            'booking_status'  => 'pending',
            'currency_markup' => $baseCurrency,
            'price_markup'    => $finalTotalBase
        ];

        // Notify flight owner
        try {
            $flightId = $flightData['id'] ?? $flightData['flight_id'] ?? 0;
            if ($flightId > 0) {
                $flightInfo = $db->get('flights', ['user_id'], ['id' => $flightId]);
                if (!empty($flightInfo['user_id'])) {
                    $ownerDetails = $db->get('users', ['first_name', 'last_name', 'email', 'phone', 'phone_country_code'], ['user_id' => $flightInfo['user_id']]);
                    if ($ownerDetails) {
                        NOTIFY::vendor('flights', $ownerDetails, $notifyBookingData);
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Failed to send flight owner notification: " . $e->getMessage());
        }

        ob_clean();
        echo json_encode([
            'success'          => true,
            'booking_id'       => $bookingId,
            'invoice_id'       => $invoiceId,
            'amount'           => $displayFinalTotal,
            'amount_base'      => $finalTotalBase,
            'currency'         => $displayCurrency,
            'base_currency'    => $baseCurrency,
            'display_currency' => $displayCurrency,
            'conversion_rate'  => $conversionRate,
            'redirect_url'     => root . 'invoice/flights/' . $invoiceId . '?currency=' . urlencode($displayCurrency),
            'message'          => 'Booking created successfully'
        ]);

    } catch (Exception $e) {
        error_log("FLIGHT BOOKING SUBMIT ERROR (MOBILE): " . $e->getMessage());
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
});

// ============================================================================
// GET: Download invoice PDF
// GET /api/flight/booking/download-invoice/{invoiceId}
// ============================================================================
$router->get('/api/flights/booking/download-invoice/([A-Z0-9]{8})', function ($invoiceId) {

    $pdf = GENERATE_BOOKING_PDF($invoiceId);

    if (!$pdf || !file_exists($pdf)) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="flight_invoice_'.$invoiceId.'.pdf"');
    readfile($pdf);
    exit;
});


// ============================================================================
// POST: Resend invoice
// POST /api/flight/booking/resend-invoice
// ============================================================================
$router->post('/api/flights/booking/resend-invoice', function () use ($db) {

    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);
    $invoiceId = $input['invoice_id'] ?? '';

    if (!$invoiceId) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Invoice ID required']);
        exit;
    }

    $booking = $db->get('bookings','*',['invoice_id'=>$invoiceId]);
    if (!$booking) {
        http_response_code(404);
        echo json_encode(['success'=>false,'message'=>'Booking not found']);
        exit;
    }

    $pdf = GENERATE_BOOKING_PDF($invoiceId);

    NOTIFY::resend('flights', [
        'email'=>$booking['email'],
        'phone'=>$booking['phone'],
        'first_name'=>$booking['first_name'],
        'last_name'=>$booking['last_name'],
        'country_code'=>$booking['phone_country_code']
    ], [
        'invoice_id'=>$invoiceId,
        'amount'=>$booking['price_markup'],
        'currency'=>$booking['currency_markup'],
        'payment_status'=>$booking['payment_status']
    ], $pdf);

    echo json_encode(['success'=>true,'message'=>'Invoice resent successfully']);
    exit;
});


// ============================================================================
// POST: Request cancellation
// POST /api/flight/booking/request-cancellation
// ============================================================================
$router->post('/api/flights/booking/request-cancellation', function () use ($db) {

    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);
    $invoiceId = $input['invoice_id'] ?? '';

    if (!$invoiceId) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Invoice ID required']);
        exit;
    }

    $booking = $db->get('bookings','*',['invoice_id'=>$invoiceId]);
    if (!$booking) {
        http_response_code(404);
        echo json_encode(['success'=>false,'message'=>'Booking not found']);
        exit;
    }

    if ($booking['cancellation_request']) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Cancellation already requested']);
        exit;
    }

    $db->update('bookings',['cancellation_request'=>1],['invoice_id'=>$invoiceId]);

    NOTIFY::cancellation('flights', [
        'email'=>$booking['email'],
        'phone'=>$booking['phone'],
        'first_name'=>$booking['first_name'],
        'last_name'=>$booking['last_name'],
        'country_code'=>$booking['phone_country_code']
    ], [
        'invoice_id'=>$invoiceId,
        'amount'=>$booking['price_markup'],
        'currency'=>$booking['currency_markup'],
        'module_type'=>'Flight'
    ]);

    echo json_encode(['success'=>true,'message'=>'Cancellation request submitted']);
    exit;
});

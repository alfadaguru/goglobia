<?php
// ============================================================================
// FILE: app/routes/api/cars/bookingRoutes.php
// CAR BOOKING API
// ============================================================================

@$SECURE or die('Access Denied!');

require_once 'app/lib/jwt.php';

// ============================================================================
// POST: SAVE DRAFT
// POST /api/cars/booking/draft
// ============================================================================
$router->post('/api/cars/booking/draft', function () use ($SECURE, $db) {

    header('Content-Type: application/json');

    try {

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }
        if (!is_array($input)) {
            throw new Exception('Invalid booking data');
        }

        // ========================================
        // VALIDATE REQUIRED FIELDS
        // ========================================
        $supplier = $input['supplier'] ?? 'cars';
        $carIdInput = $input['car_id'] ?? ($input['car_data']['id'] ?? ($input['car_data']['vehicle_id'] ?? ''));

        $serviceType = $input['service_type'] ?? 'rental';
        $pickupLocation = $input['pickup_location'] ?? '';
        $dropoffLocation = $input['dropoff_location'] ?? '';
        $pickupDate = $input['pickup_date'] ?? '';
        $returnDate = $input['return_date'] ?? '';
        $carType = $input['car_type'] ?? 'any';
        $driverAge = $input['driver_age'] ?? '25+';
        $travellers = $input['travellers'] ?? '2';
        $pickupTime = $input['pickup_time'] ?? '10:00';
        $returnTime = $input['return_time'] ?? '10:00';
        $adults = (int) ($input['adults'] ?? 1);
        $children = (int) ($input['childrens'] ?? $input['children'] ?? 0);
        $infants = (int) ($input['infants'] ?? 0);
        $displayCurrency = strtoupper($input['currency'] ?? 'USD');

        if (!in_array($serviceType, ['rental', 'transfer'], true)) {
            throw new Exception('Invalid service_type. Allowed: rental, transfer');
        }
        if (empty($pickupLocation)) {
            throw new Exception('Pickup location is required');
        }
        if (empty($dropoffLocation)) {
            throw new Exception('Dropoff location is required');
        }
        if (empty($pickupDate)) {
            throw new Exception('Pickup date is required');
        }
        if (empty($returnDate)) {
            throw new Exception('Return date is required');
        }

        // ========================================
        // VALIDATE DATES
        // ========================================
        $pickupDateObj = DateTime::createFromFormat('d-m-Y', $pickupDate);
        if (!$pickupDateObj) {
            $pickupDateObj = DateTime::createFromFormat('Y-m-d', $pickupDate);
        }
        if (!$pickupDateObj) {
            throw new Exception('Invalid pickup_date format. Use DD-MM-YYYY or YYYY-MM-DD');
        }

        $returnDateObj = DateTime::createFromFormat('d-m-Y', $returnDate);
        if (!$returnDateObj) {
            $returnDateObj = DateTime::createFromFormat('Y-m-d', $returnDate);
        }
        if (!$returnDateObj) {
            throw new Exception('Invalid return_date format. Use DD-MM-YYYY or YYYY-MM-DD');
        }

        $pickupDateFormatted = $pickupDateObj->format('Y-m-d');
        $returnDateFormatted = $returnDateObj->format('Y-m-d');

        // ========================================
        // CALCULATE RENTAL DAYS
        // ========================================
        $interval = $pickupDateObj->diff($returnDateObj);
        $rental_days = (int) $interval->days;
        if ($rental_days < 1)
            $rental_days = 1;

        // ========================================
        // VALIDATE CURRENCY
        // ========================================
        $validCurrency = $db->get('currencies', 'name', ['name' => $displayCurrency, 'status' => 1]);
        if (!$validCurrency) {
            throw new Exception('Unsupported currency: ' . $displayCurrency);
        }

        // --------------------------------------------------
        // RESOLVE LOGGED-IN USER / AGENT FROM JWT TOKEN
        // --------------------------------------------------
        if (!function_exists('MARKUP')) {
            require_once dirname(__DIR__, 4) . '/modules/helpers.php';
        }

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
                  ?? $input['token'] ?? $input['access_token'] ?? '';
        }

        if (!empty($token)) {
            try {
                $tokenData = JWT::verify($token);
                if (!$tokenData && method_exists('JWT', 'decode')) {
                    $tokenData = JWT::decode($token, false);
                }
                if ($tokenData && !empty($tokenData['user_id'])) {
                    $_SESSION['user_id'] = $tokenData['user_id'];
                    $_SESSION['user_role'] = $tokenData['role'] ?? null;
                }
            } catch (Exception $e) {
                // Silent fail
            }
        }

        // Apply MARKUP to car_data price
        if (!empty($input['car_data']) && is_array($input['car_data'])) {
            $actualAmt = floatval(
                $input['car_data']['actual_price']
                ?? $input['car_data']['base_price']
                ?? $input['car_data']['price']
                ?? $input['car_data']['display_price']
                ?? 0
            );
            $curr = $input['car_data']['currency'] ?? $displayCurrency;

            if ($actualAmt > 0 && function_exists('MARKUP')) {
                $carsModule = $db->get('modules', '*', ['name' => $supplier, 'type' => 'cars', 'status' => '1']);
                if (!$carsModule && $supplier !== 'cars') {
                    $carsModule = $db->get('modules', '*', ['name' => 'cars', 'type' => 'cars', 'status' => '1']);
                }
                $marked = MARKUP($actualAmt, $carsModule ?: null, $db, $curr, $curr);
                if (!empty($marked['price']) && $marked['price'] > 0) {
                    $input['car_data']['display_price'] = round((float)$marked['price'], 2);
                    $input['car_data']['price'] = round((float)$marked['price'], 2);
                    $input['car_data']['actual_price'] = round($actualAmt, 2);
                    $input['car_data']['base_price'] = round($actualAmt, 2);
                    $input['car_data']['markup_amount'] = round((float)($marked['price'] - $actualAmt), 2);
                }
            }
        }

        // ========================================
        // BUILD COMPLETE BOOKING DATA
        // ========================================
        $bookingDraftData = [
            'car_data' => $input['car_data'],
            'search_params' => [
                'service_type' => $serviceType,
                'pickup_location' => $pickupLocation,
                'dropoff_location' => $dropoffLocation,
                'pickup_date' => $pickupDateFormatted,
                'return_date' => $returnDateFormatted,
                'pickup_time' => $pickupTime,
                'return_time' => $returnTime,
                'dropoff_time' => $returnTime,
                'car_type' => $carType,
                'driver_age' => $driverAge,
                'travellers' => $travellers,
                'adults' => $adults,
                'childrens' => $children,
                'children' => $children,
                'infants' => $infants,
                'currency' => $displayCurrency
            ]
        ];

        // ========================================
        // STORE DRAFT
        // ========================================
        $hash = bin2hex(random_bytes(8));
        if (strlen($hash) !== 16) {
            throw new Exception('Failed to generate valid booking hash');
        }

        $result = $db->insert('logs_bookings', [
            'hash' => $hash,
            'data' => json_encode($bookingDraftData),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if (!$result) {
            throw new Exception('Failed to save booking data');
        }

        echo json_encode([
            'success' => true,
            'hash' => $hash,
            'message' => 'Booking draft saved successfully'
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

// ====================================
// GET BOOKING DRAFT (GET)
// ====================================

$router->get('/api/cars/booking/draft/([a-f0-9]{16})', function ($hash) use ($db) {
    header('Content-Type: application/json');

    $booking = $db->get('logs_bookings', ['hash', 'data', 'created_at'], ['hash' => $hash]);

    if (!$booking || empty($booking['data'])) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Draft not found']);
        exit;
    }

    $bookingData = json_decode($booking['data'], true);
    if (!$bookingData) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid booking data']);
        exit;
    }

    // Resolve JWT token if passed
    if (!function_exists('MARKUP')) {
        require_once dirname(__DIR__, 4) . '/modules/helpers.php';
    }

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
            if (is_array($tokenData) && !empty($tokenData['user_id'])) {
                $_SESSION['user_id'] = $tokenData['user_id'];
                $_SESSION['user_role'] = $tokenData['role'] ?? null;
            }
        } catch (Exception $e) {
            // Silent fail
        }
    }

    // Reapply markup dynamically for response
    if (!empty($bookingData['car_data']) && is_array($bookingData['car_data'])) {
        $actualAmt = floatval(
            $bookingData['car_data']['actual_price']
            ?? $bookingData['car_data']['base_price']
            ?? $bookingData['car_data']['price']
            ?? $bookingData['car_data']['display_price']
            ?? 0
        );
        $curr = $bookingData['car_data']['currency'] ?? ($bookingData['search_params']['currency'] ?? 'USD');
        $supplier = $bookingData['car_data']['supplier'] ?? 'cars';

        if ($actualAmt > 0 && function_exists('MARKUP')) {
            $carsModule = $db->get('modules', '*', ['name' => $supplier, 'type' => 'cars', 'status' => '1']);
            if (!$carsModule && $supplier !== 'cars') {
                $carsModule = $db->get('modules', '*', ['name' => 'cars', 'type' => 'cars', 'status' => '1']);
            }
            $marked = MARKUP($actualAmt, $carsModule ?: null, $db, $curr, $curr);
            if (!empty($marked['price']) && $marked['price'] > 0) {
                $bookingData['car_data']['display_price'] = round((float)$marked['price'], 2);
                $bookingData['car_data']['price'] = round((float)$marked['price'], 2);
                $bookingData['car_data']['actual_price'] = round($actualAmt, 2);
                $bookingData['car_data']['base_price'] = round($actualAmt, 2);
                $bookingData['car_data']['markup_amount'] = round((float)($marked['price'] - $actualAmt), 2);
            }
        }
    }

    echo json_encode([
        'success' => true,
        'hash' => $hash,
        'booking_data' => $bookingData,
        'created_at' => $booking['created_at']
    ]);
    exit;
});

// ====================================
// SUBMIT BOOKING (POST)
// ====================================

$router->post('/api/cars/booking/submit', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        // ============================================================================
        // OPTIONAL JWT AUTHENTICATION
        // ============================================================================
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
            $token = $headersLower['token']
                  ?? $headersLower['jwt']
                  ?? $headersLower['x-access-token']
                  ?? '';
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
                        $whereClause = [
                            'OR' => [
                                'user_id' => (string)$userId,
                                'id'      => (int)$userId
                            ]
                        ];
                    }
                    $userData = $db->get('users', '*', $whereClause);
                    if ($userData) {
                        $_SESSION['user_id'] = $userId;
                        $_SESSION['user_role'] = $userData['role'] ?? null;
                    }
                }
            } catch (Exception $e) {
                // Silent fail
            }
        }

        // ============================================================================
        // VALIDATE INPUT
        // ============================================================================
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        $bookingHashInput = $input['booking_hash'] ?? ($input['hash'] ?? '');
        if (!is_array($input) || $bookingHashInput === '') {
            throw new Exception('Invalid booking submission');
        }

        $bookingHash = trim((string) $bookingHashInput);
        if (!preg_match('/^[a-f0-9]{16}$/', $bookingHash)) {
            throw new Exception('Invalid booking hash');
        }

        // ============================================================================
        // EXTRACT GUEST DETAILS 
        // ============================================================================
        $guestDetails = $input['guest_details'] ?? [];
        if (!is_array($guestDetails)) {
            throw new Exception('Invalid guest_details payload');
        }

        $baseCurrency = strtoupper(trim((string) ($input['base_currency'] ?? 'USD')));
        $bookingType = trim((string) ($guestDetails['booking_type'] ?? ($input['booking_type'] ?? 'guest')));

        if (empty($guestDetails['terms_accepted'])) {
            throw new Exception('Terms and conditions must be accepted');
        }

        $primaryGuest = $guestDetails['primary_guest'] ?? [];
        if (!is_array($primaryGuest)) {
            throw new Exception('Primary guest details are required');
        }

        // Sanitize guest inputs
        $firstName = htmlspecialchars(strip_tags(trim((string) ($primaryGuest['first_name'] ?? ''))), ENT_QUOTES, 'UTF-8');
        $lastName = htmlspecialchars(strip_tags(trim((string) ($primaryGuest['last_name'] ?? ''))), ENT_QUOTES, 'UTF-8');
        $email = filter_var(trim((string) ($primaryGuest['email'] ?? '')), FILTER_SANITIZE_EMAIL);
        $phone = preg_replace('/[^0-9+\-\s]/', '', (string) ($primaryGuest['phone'] ?? ''));
        $countryCode = trim((string) ($primaryGuest['country_code'] ?? ''));

        if ($firstName === '' || $lastName === '' || $email === '') {
            throw new Exception('Please fill in all required guest details');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format');
        }

        // ============================================================================
        // GET DRAFT BOOKING
        // ============================================================================
        $booking = $db->get('logs_bookings', ['data'], ['hash' => $bookingHash]);
        if (!$booking || empty($booking['data'])) {
            throw new Exception('Booking not found');
        }

        $bookingData = json_decode($booking['data'], true);
        if (!is_array($bookingData)) {
            throw new Exception('Invalid booking draft data');
        }

        $carData = $bookingData['car_data'] ?? [];
        $searchParams = $bookingData['search_params'] ?? [];

        // Extract supplier name from car data
        $supplierName = $carData['supplier'] ?? 'cars';
        $serviceType = $input['service_type'] ?? ($searchParams['service_type'] ?? 'rental');

        // ============================================================================
        // PRICING FROM DRAFT DATA & RE-APPLY USER MARKUP
        // ============================================================================
        $actualPriceBase = floatval(
            $carData['actual_price']
            ?? $carData['base_price']
            ?? $carData['price']
            ?? $carData['display_price']
            ?? 0
        );
        $markupPrice = floatval(
            $carData['display_price']
            ?? $carData['price']
            ?? $actualPriceBase
        );
        $currency = $carData['currency'] ?? ($searchParams['currency'] ?? 'USD');

        if (!function_exists('MARKUP')) {
            require_once dirname(__DIR__, 4) . '/modules/helpers.php';
        }

        // Enforce user/role-based MARKUP on actual base cost
        if ($actualPriceBase > 0 && function_exists('MARKUP')) {
            $carsModule = $db->get('modules', '*', ['name' => $supplierName, 'type' => 'cars', 'status' => '1']);
            if (!$carsModule && $supplierName !== 'cars') {
                $carsModule = $db->get('modules', '*', ['name' => 'cars', 'type' => 'cars', 'status' => '1']);
            }
            $markedInfo = MARKUP($actualPriceBase, $carsModule ?: null, $db, $currency, $currency);
            if (!empty($markedInfo['price']) && $markedInfo['price'] > 0) {
                $markupPrice = round((float)$markedInfo['price'], 2);
            }
        }

        $subtotal = $markupPrice;
        $taxInfo = calculateTax($subtotal, $supplierName, $db);

        // Fallback to generic cars module if supplier-specific tax not found
        if (empty($taxInfo['tax_amount']) && $supplierName !== 'cars') {
            $taxInfo = calculateTax($subtotal, 'cars', $db);
        }

        $taxAmountBase = (float) ($taxInfo['tax_amount'] ?? 0);
        $taxType = $taxInfo['tax_type'] ?? 'percentage';

        // Reconstruct final total from components
        $finalTotalBase = $subtotal + $taxAmountBase;

        // Calculate commission 
        $commissionBase = max(0, round($markupPrice - $actualPriceBase, 2));

        // Validate currency
        $currencyRow = $db->get('currencies', 'name', ['name' => $baseCurrency, 'status' => 1]);
        if (!$currencyRow) {
            throw new Exception('Unsupported base currency');
        }

        // ============================================================================
        // PASSENGER DETAILS
        // ============================================================================
        $passengers = $guestDetails['passengers'] ?? [];
        $transferDetails = $guestDetails['transfer_details'] ?? ($input['transfer_details'] ?? null);
        $specialRequests = $guestDetails['special_requests'] ?? '';

        // Get passenger counts from search_params 
        $totalAdults = (int) ($searchParams['adults'] ?? 1);
        $totalChildren = (int) ($searchParams['childrens'] ?? $searchParams['children'] ?? 0);
        $totalInfants = (int) ($searchParams['infants'] ?? 0);

        // ============================================================================
        // GENERATE INVOICE ID
        // ============================================================================
        $invoiceId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        // ============================================================================
        // AGENT DETECTION & EARNING
        // ============================================================================
        $isAgent = false;
        if (!empty($userData) && strtolower((string)($userData['role'] ?? '')) === 'agent') {
            $isAgent = true;
        } elseif (strtolower((string)($_SESSION['user_role'] ?? '')) === 'agent') {
            $isAgent = true;
            if (empty($userId) && !empty($_SESSION['user_id'])) {
                $userId = $_SESSION['user_id'];
                $userData = $db->get('users', '*', ['user_id' => $userId]) ?: $userData;
            }
        }

        // B2B agents earn the markup as commission
        $agentEarning = $isAgent ? $commissionBase : 0;

        // ============================================================================
        // PROMO CODE HANDLING
        // ============================================================================
        $promoCodeStr = trim($input['promo_code'] ?? '');
        $promoDiscount = (float) ($input['promo_discount'] ?? 0);
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

        $baseCurrency = resolveBaseCurrency($db, $baseCurrency, $carData['currency'] ?? null, $searchParams['currency'] ?? null);
        $displayCurrency = requireAppDisplayCurrency($db, $input);
        $conversionRate = getCurrencyConversionRate($db, $baseCurrency, $displayCurrency);
        $displayFinalTotal = convertCurrencyAmount($db, $finalTotalBase, $baseCurrency, $displayCurrency);

        // ============================================================================
        // PREPARE BOOKING DATA JSON 
        // ============================================================================
        $bookingDataJson = json_encode([
            'car_data' => $carData,
            'search_params' => $searchParams,
            'service_type' => $serviceType,
            'guest_details' => $primaryGuest,
            'passengers' => $passengers,
            'transfer_details' => ($serviceType === 'transfer' && $transferDetails) ? $transferDetails : null,
            'pricing' => [
                'base_price' => $actualPriceBase,
                'final_total' => $finalTotalBase,
                'tax_amount' => $taxAmountBase,
                'currency' => $baseCurrency
            ],
            // Flat keys for API compatibility
            'base_price' => $actualPriceBase,
            'markup_amount' => $commissionBase,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmountBase,
            'final_total' => $finalTotalBase,
            'base_currency' => $baseCurrency,
            'display_currency' => $displayCurrency,
            'conversion_rate' => $conversionRate,
            'final_total_display' => $displayFinalTotal,
            'subtotal_display' => convertCurrencyAmount($db, $subtotal, $baseCurrency, $displayCurrency),
            'tax_amount_display' => convertCurrencyAmount($db, $taxAmountBase, $baseCurrency, $displayCurrency),
        ]);

        // ============================================================================
        // TRAVELLERS DATA
        // ============================================================================
        $travellersData = [
            'primary_guest' => [
                'title' => trim((string) ($primaryGuest['title'] ?? '')),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
                'country_code' => $countryCode
            ],
            'travelers' => $guestDetails['travelers'] ?? ($guestDetails['passengers'] ?? []),
            'booking_for_someone_else' => $guestDetails['booking_for_someone_else'] ?? false,
            'booking_type' => $bookingType
        ];

        // Include transfer details for transfer bookings
        if ($serviceType === 'transfer' && $transferDetails) {
            $travellersData['transfer_details'] = $transferDetails;
        }

        // ============================================================================
        // AUTO-ACCOUNT CREATION FOR GUEST BOOKINGS
        // ============================================================================
        if ($userId) {
            $userData = $db->get('users', '*', ['user_id' => $userId]);
        } else {
            $existingUser = $db->get('users', '*', ['email' => $email]);
            if ($existingUser) {
                $userId = $existingUser['user_id'];
                $userData = $existingUser;
            } else {
                $newUserId = 'USR' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                $generatedPassword = bin2hex(random_bytes(4));
                $created = $db->insert('users', [
                    'user_id' => $newUserId,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email,
                    'password' => password_hash($generatedPassword, PASSWORD_DEFAULT),
                    'phone' => $phone,
                    'phone_country_code' => $countryCode,
                    'status' => 'active',
                    'role' => 'user',
                    'created_at' => date('Y-m-d H:i:s'),
                    'email_verified' => 0
                ]);
                if ($created) {
                    $userId = $newUserId;
                    $userData = $db->get('users', '*', ['user_id' => $newUserId]);
                }
            }
        }

        // ============================================================================
        // PAYMENT GATEWAY NORMALIZATION
        // ============================================================================
        $paymentGateway = trim((string) ($guestDetails['selected_payment'] ?? ''));

        // ============================================================================
        // INSERT INTO BOOKINGS TABLE
        // ============================================================================
        $db->insert('bookings', [
            'invoice_id' => $invoiceId,
            'language' => getCurrentLanguage(),
            'booking_status' => 'pending',
            'payment_status' => 'unpaid',

            // Pricing breakdown
            'price_original' => $actualPriceBase,
            'price_markup' => $finalTotalBase,
            'agent_earning' => $agentEarning,
            'tax_type' => $taxType,
            'tax' => $taxAmountBase,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone_country_code' => getPhoneCode($countryCode, $db),
            'phone' => $phone,
            'country' => $countryCode,
            'address' => '',
            'adults' => $totalAdults,
            'infants' => (string) $totalInfants,
            'childs' => $totalChildren,
            'child_ages' => json_encode([]),
            'currency_markup' => $carData['currency'] ?? $baseCurrency,
            'paid_at' => null,
            'cancellation_request' => 0,
            'cancellation_status' => 0,
            'cancellation_response' => null,
            'booking_data' => $bookingDataJson,
            'transaction_id' => '',
            'user_id' => $userId,
            'user_data' => $userData ? json_encode($userData) : '',
            'travellers' => json_encode($travellersData),
            'nationality' => '',
            'payment_gateway' => $paymentGateway,
            'module_type' => 'cars',
            'pnr' => '',
            'booking_response' => null,
            'error_response' => null,
            'commission' => $commissionBase,
            'module' => $supplierName,
            'special_requests' => $specialRequests,
            'created_at' => date('Y-m-d H:i:s'),
            'booking_date' => date('Y-m-d'),
            'promo_codes' => $promoCodeJson
        ]);

        $bookingId = $db->id();

        if ($bookingId) {
            // AGENT API — wallet settlement (no-op unless agent-API request).
            agent_api_settle_booking($db, 'cars', $bookingId, $invoiceId, (float) $finalTotalBase);

            // Record promo code usage
            if (!empty($promoCodeStr) && $promoDiscount > 0 && $promoData) {
                $db->update('promo_codes', ['used_count[+]' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $promoData['id']]);
            }

            // ============================================================================
            // SEND BOOKING NOTIFICATION     
            // ============================================================================
            if (class_exists('NOTIFY')) {
                $customerData = [
                    'email' => $email,
                    'phone' => $phone,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'country_code' => $guestDetails['country_code'] ?? $countryCode
                ];

                $notifyData = [
                    'invoice_id' => $invoiceId,
                    'amount' => $finalTotalBase,
                    'currency' => $baseCurrency,
                    'payment_status' => 'unpaid',
                    'car_name' => $carData['name'] ?? 'Car Booking',
                    'location' => $searchParams['pickup_location'] ?? '',
                    'pickup_date' => $searchParams['pickup_date'] ?? '',
                    'return_date' => $searchParams['return_date'] ?? '',
                    'module_type' => 'Cars'
                ];

                NOTIFY::booking('cars', $customerData, $notifyData);
            }


            // ============================================================================
            // SEND NOTIFICATION TO CAR OWNER (Vendor)
            // ============================================================================
            // For external suppliers (cartrawler, kiwitaxi etc.) there is no local car record
            if ($supplierName === 'cars') {
                $notifyBookingData = [
                    'invoice_id' => $invoiceId,
                    'amount' => $displayFinalTotal,
                    'currency' => $displayCurrency,
                    'payment_status' => 'unpaid',
                    'module_type' => 'Car',
                    'car_name' => $carData['name'] ?? '',
                    'service_type' => $serviceType,
                    'pickup_location' => $searchParams['pickup_location'] ?? '',
                    'dropoff_location' => $searchParams['dropoff_location'] ?? '',
                    'pickup_date' => $searchParams['pickup_date'] ?? date('Y-m-d'),
                    'return_date' => $searchParams['return_date'] ?? '',
                    'date' => $searchParams['pickup_date'] ?? date('Y-m-d'),
                    'booking_data' => $bookingDataJson,
                    'booking_status' => 'pending',
                    'currency_markup' => $baseCurrency,
                    'price_markup' => $finalTotalBase
                ];

                try {
                    $carId = $carData['id'] ?? $carData['supplier_id'] ?? 0;

                    if ($carId > 0 && is_numeric($carId)) {
                        $carInfo = $db->get('cars', ['user_id'], ['id' => (int) $carId]);

                        if (!empty($carInfo['user_id'])) {
                            $ownerDetails = $db->get('users', ['first_name', 'last_name', 'email', 'phone', 'phone_country_code'], ['user_id' => $carInfo['user_id']]);

                            if ($ownerDetails) {
                                NOTIFY::vendor('cars', $ownerDetails, $notifyBookingData);
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log("Failed to send car owner notification: " . $e->getMessage());
                }
            }

            // DELETE TEMPORARY BOOKING DATA
            $db->delete('logs_bookings', ['hash' => $bookingHash]);

            ob_clean();
            echo json_encode([
                'success' => true,
                'booking_id' => $bookingId,
                'invoice_id' => $invoiceId,
                'amount' => $displayFinalTotal,
                'amount_base' => $finalTotalBase,
                'currency' => $displayCurrency,
                'base_currency' => $baseCurrency,
                'display_currency' => $displayCurrency,
                'conversion_rate' => $conversionRate,
                'message' => 'Booking created successfully',
                'redirect_url' => root . 'invoice/cars/' . $invoiceId . '?currency=' . urlencode($displayCurrency)
            ]);
        } else {
            throw new Exception('Failed to create booking');
        }

    } catch (Exception $e) {

        http_response_code(400);
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
});


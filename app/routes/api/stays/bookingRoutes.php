<?php
// ============================================================================
// FILE: app/routes/api/stays/bookingRoutes.php
// Supports ALL suppliers: hotels, ratehawk, hotelbeds, agoda, stuba, travelport, amadeus, hotelston
// ============================================================================

@$SECURE or die('Access Denied!');

require_once 'app/lib/jwt.php';


// ============================================================================
// GET: BOOKING DRAFT BY HASH
// GET /api/stays/booking/{hash}
// ============================================================================
$router->get('/api/stays/booking/([a-f0-9]{16})', function ($hash) use ($db) {

    header('Content-Type: application/json');

    try {

        $booking = $db->get('logs_bookings', ['hash', 'data'], [
            'hash' => $hash
        ]);

        if (!$booking || empty($booking['data'])) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Booking not found'
            ]);
            exit;
        }

        $bookingData = json_decode($booking['data'], true);

        if (!$bookingData) {
            throw new Exception('Invalid booking data');
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
                if ($tokenData && !empty($tokenData['user_id'])) {
                    $_SESSION['user_id'] = $tokenData['user_id'];
                    $_SESSION['user_role'] = $tokenData['role'] ?? null;
                }
            } catch (Exception $e) {
                // Silent fail
            }
        }

        // Apply MARKUP to draft payload for response
        $actualAmt = floatval(
            $bookingData['actual_amount']
            ?? $bookingData['actual_price']
            ?? $bookingData['base_price']
            ?? $bookingData['price']
            ?? $bookingData['total_amount']
            ?? $bookingData['amount']
            ?? 0
        );
        $curr = $bookingData['currency'] ?? 'USD';

        if ($actualAmt > 0 && function_exists('MARKUP')) {
            $staysModule = $db->get('modules', '*', ['name' => 'hotels', 'type' => 'stays', 'status' => '1']);
            $marked = MARKUP($actualAmt, $staysModule ?: null, $db, $curr, $curr);
            if (!empty($marked['price']) && $marked['price'] > 0) {
                $bookingData['total_amount'] = round((float)$marked['price'], 2);
                $bookingData['actual_amount'] = round($actualAmt, 2);
                $bookingData['price'] = round((float)$marked['price'], 2);
                $bookingData['actual_price'] = round($actualAmt, 2);
                $bookingData['markup_amount'] = round((float)($marked['price'] - $actualAmt), 2);
            }
        }

        echo json_encode([
            'success' => true,
            'hash' => $hash,
            'booking_data' => $bookingData
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
// POST: SAVE BOOKING DRAFT
// POST /api/stays/booking/draft
// ============================================================================
$router->post('/api/stays/booking/draft', function () use ($db) {

    header('Content-Type: application/json');

    try {

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }
        if (!is_array($input)) {
            throw new Exception('Invalid booking data');
        }

        // Normalize 'id' to 'hotel_id'
        if (empty($input['hotel_id']) && !empty($input['id'])) {
            $input['hotel_id'] = $input['id'];
        }

        if (empty($input['hotel_id'])) {
            throw new Exception('Invalid booking data: hotel_id is required');
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

        // Apply MARKUP according to resolved user role / custom markup
        $actualAmt = floatval(
            $input['actual_amount']
            ?? $input['actual_price']
            ?? $input['base_price']
            ?? $input['price']
            ?? $input['total_amount']
            ?? $input['amount']
            ?? $input['subtotal']
            ?? 0
        );
        $curr = $input['currency'] ?? 'USD';

        if ($actualAmt > 0 && function_exists('MARKUP')) {
            $staysModule = $db->get('modules', '*', ['name' => 'hotels', 'type' => 'stays', 'status' => '1']);
            $marked = MARKUP($actualAmt, $staysModule ?: null, $db, $curr, $curr);
            if (!empty($marked['price']) && $marked['price'] > 0) {
                $input['total_amount'] = round((float)$marked['price'], 2);
                $input['actual_amount'] = round($actualAmt, 2);
                $input['price'] = round((float)$marked['price'], 2);
                $input['actual_price'] = round($actualAmt, 2);
                $input['markup_amount'] = round((float)($marked['price'] - $actualAmt), 2);
            }
        }

        // Generate secure hash (16 characters)
        $hash = bin2hex(random_bytes(8));
        if (strlen($hash) !== 16) {
            throw new Exception('Failed to generate valid booking hash');
        }

        // Save to database
        $result = $db->insert('logs_bookings', [
            'hash' => $hash,
            'data' => json_encode($input),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        if (!$result) {
            throw new Exception('Failed to save booking data');
        }

        // ============================================================================
        // WEBHOOK: Booking Draft Created
        // ============================================================================
        triggerWebhook('stays/booking', 'stays.booking.draft_created', [
            'booking_hash' => $hash,
            'hotel_data' => $input['hotelData'] ?? [
                'name' => $input['hotel_name'] ?? '',
                'address' => $input['hotel_address'] ?? '',
                'stars' => $input['hotel_stars'] ?? 0
            ],
            'booking_details' => $input['bookingData'] ?? [
                'checkin' => $input['checkin'] ?? '',
                'checkout' => $input['checkout'] ?? '',
                'nights' => $input['nights'] ?? 1,
                'adults' => $input['adults'] ?? 2
            ],
            'user_data' => $input['userData'] ?? [],
            'total_amount' => $input['total_amount'] ?? 0,
            'timestamp' => date('Y-m-d H:i:s'),
            'user_id' => null
        ]);

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


// ============================================================================
// POST: SUBMIT FINAL BOOKING
// POST /api/stays/booking/submit
// ============================================================================
$router->post('/api/stays/booking/submit', function () use ($db) {

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
                }
            } catch (Exception $e) {
                // Silent fail
            }
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        if (!$input || empty($input['booking_hash'])) {
            throw new Exception('Invalid booking submission');
        }

        $bookingHash = $input['booking_hash'];

        $guestDetails = $input['guest_details'] ?? [];
        if (!is_array($guestDetails)) {
            throw new Exception('Invalid guest_details payload');
        }

        // VALIDATE TERMS ACCEPTANCE
        if (empty($guestDetails['terms_accepted'])) {
            throw new Exception('Terms and conditions must be accepted');
        }

        $primaryGuest = $guestDetails['primary_guest'] ?? [];
        if (!is_array($primaryGuest)) {
            throw new Exception('Primary guest details are required');
        }

        // Sanitize inputs (XSS prevention)
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

        $finalTotal = $input['final_total'] ?? 0;
        $displayTotal = $input['display_total'] ?? 0;
        $subtotal = $input['subtotal'] ?? 0;
        $taxAmount = $input['tax_amount'] ?? 0;
        $baseCurrency = $input['base_currency'] ?? 'USD';
        $displayCurrency = $input['display_currency'] ?? 'USD';

        // --------------------------------------------------
        // GET ORIGINAL BOOKING DATA FROM TEMP TABLE
        // --------------------------------------------------
        $booking = $db->get('logs_bookings', ['data'], ['hash' => $bookingHash]);
        if (!$booking || empty($booking['data'])) {
            throw new Exception('Booking not found');
        }

        $bookingData = json_decode($booking['data'], true);
        if (!is_array($bookingData)) {
            throw new Exception('Invalid booking draft data');
        }

        // --------------------------------------------------
        // GET PRICES FROM BOOKING DATA & APPLY USER MARKUP
        // --------------------------------------------------
        $actualPrice = floatval(
            $bookingData['actual_amount']
            ?? $bookingData['actual_price']
            ?? $bookingData['base_price']
            ?? $bookingData['price']
            ?? $bookingData['total_amount']
            ?? $bookingData['amount']
            ?? 0
        );
        $markupPrice = floatval(
            $bookingData['total_amount']
            ?? $bookingData['price']
            ?? $bookingData['amount']
            ?? $actualPrice
        );
        $currency = $bookingData['currency'] ?? 'USD';

        // Set session user context for MARKUP() calculation
        if ($userId) {
            $_SESSION['user_id'] = $userId;
            if (!empty($userData['role'])) {
                $_SESSION['user_role'] = $userData['role'];
            }
        }

        if (!function_exists('MARKUP')) {
            require_once dirname(__DIR__, 4) . '/modules/helpers.php';
        }

        // Enforce user/role-based MARKUP on actual base cost
        if ($actualPrice > 0 && function_exists('MARKUP')) {
            $staysModule = $db->get('modules', '*', ['name' => 'hotels', 'type' => 'stays', 'status' => '1']);
            $markedInfo = MARKUP($actualPrice, $staysModule ?: null, $db, $currency, $currency);
            if (!empty($markedInfo['price']) && $markedInfo['price'] > 0) {
                $markupPrice = round((float)$markedInfo['price'], 2);
            }
        }

        // USE PRICE FROM FRONTEND IF PROVIDED, OTHERWISE FROM BOOKING DATA
        if ($subtotal == 0) {
            $subtotal = $markupPrice;
        }

        // GENERATE 8-CHARACTER UUID FOR INVOICE
        $invoiceId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        // GET NATIONALITY FULL NAME FROM COUNTRIES TABLE
        $nationalityData = $db->get('countries', 'nicename', ['iso' => $bookingData['nationality'] ?? '']);
        $nationalityName = $nationalityData ?? ($bookingData['nationality'] ?? '');

        // --------------------------------------------------
        // COUNT ADULTS AND CHILDREN FROM BOOKING DATA
        // --------------------------------------------------
        $totalAdults = 0;
        $totalChildren = 0;
        $childAges = [];
        foreach (($bookingData['rooms_data'] ?? []) as $room) {
            $totalAdults += $room['adults'] ?? 0;
            $totalChildren += $room['children'] ?? 0;
            if (!empty($room['childAges'])) {
                $childAges = array_merge($childAges, $room['childAges']);
            }
        }

        // --------------------------------------------------
        // CALCULATE TAX DETAILS
        // --------------------------------------------------
        $supplierName = $bookingData['supplier'] ?? 'hotels';
        $taxInfo = calculateTax($subtotal, $supplierName, $db);
        $calculatedTaxAmount = $taxInfo['tax_amount'] ?? 0;

        // USE TAX AMOUNT FROM FRONTEND IF PROVIDED
        if ($taxAmount == 0) {
            $taxAmount = $calculatedTaxAmount;
        }

        // CALCULATE COMMISSION (MARKUP MINUS ACTUAL PRICE)
        $commission = round($markupPrice - $actualPrice, 2);

        // CALCULATE FINAL TOTAL WITH TAX (MARKUP PRICE + TAX)
        $finalTotalWithTax = round($markupPrice + $taxAmount, 2);

        // --------------------------------------------------
        // PRICES ARE ALREADY IN BASE CURRENCY (FROM DRAFT)
        // --------------------------------------------------
        $actualPriceBase = round($actualPrice, 2);
        $markupPriceBase = round($markupPrice, 2);
        $taxAmountBase = round($taxAmount, 2);
        $commissionBase = $commission;
        $finalTotalWithTaxBase = $finalTotalWithTax;

        // --------------------------------------------------
        // PROMO CODE HANDLING — recompute the discount SERVER-SIDE (never trust
        // the client's promo_discount). Enforces module/targeting/usage/per-user.
        // --------------------------------------------------
        $promoCodeStr = trim($input['promo_code'] ?? '');
        $promoDiscount = 0.0;
        $promoCodeJson = null;
        $promoData = null;
        if ($promoCodeStr !== '' && function_exists('promoResolveForBooking')) {
            $pr = promoResolveForBooking($db, $promoCodeStr, (float) $finalTotalWithTaxBase, 'stays', (string) $baseCurrency, [
                'item_id'    => (int) ($input['hotel_id'] ?? 0),
                'user_id'    => $userId ?? ($_SESSION['user_id'] ?? null),
                'user_email' => $email ?? null,
            ]);
            $promoDiscount = (float) $pr['discount'];
            $promoData     = $pr['promo'];
            $promoCodeJson = $pr['json'];
        }

        // APPLY PROMO DISCOUNT TO FINAL TOTAL
        if ($promoDiscount > 0 && $promoData) {
            $finalTotalWithTaxBase = round($finalTotalWithTaxBase - $promoDiscount, 2);
        }

        $baseCurrency = resolveBaseCurrency($db, $bookingData['currency'] ?? null, $baseCurrency);
        $displayCurrency = requireAppDisplayCurrency($db, $input);
        $conversionRate = getCurrencyConversionRate($db, $baseCurrency, $displayCurrency);
        $displayFinalTotal = convertCurrencyAmount($db, $finalTotalWithTaxBase, $baseCurrency, $displayCurrency);
        $bookingData['base_currency'] = $baseCurrency;
        $bookingData['display_currency'] = $displayCurrency;
        $bookingData['conversion_rate'] = $conversionRate;
        $bookingData['final_total_display'] = $displayFinalTotal;
        $bookingData['subtotal_display'] = convertCurrencyAmount($db, $markupPriceBase, $baseCurrency, $displayCurrency);
        $bookingData['tax_amount_display'] = convertCurrencyAmount($db, $taxAmountBase, $baseCurrency, $displayCurrency);

        // --------------------------------------------------
        // PREPARE TRAVELLERS
        // --------------------------------------------------
        $travellersData = [
            'primary_guest' => [
                'title' => trim((string) ($primaryGuest['title'] ?? '')),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
                'country_code' => getPhoneCode($countryCode, $db)
            ],
            'travelers' => $guestDetails['travelers'] ?? [],
            'booking_for_someone_else' => $guestDetails['booking_for_someone_else'] ?? false
        ];

        // --------------------------------------------------
        // USER RESOLUTION
        // --------------------------------------------------
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
                    'phone_country_code' => getPhoneCode($countryCode, $db),
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


        // --------------------------------------------------
        // INSERT INTO BOOKINGS TABLE
        // --------------------------------------------------
        $db->insert('bookings', [
            'invoice_id' => $invoiceId,
            'language' => getCurrentLanguage(),
            'booking_date' => date('Y-m-d H:i:s'),
            'booking_status' => 'pending',
            'price_original' => $actualPriceBase,
            'price_markup' => $finalTotalWithTaxBase,
            'agent_earning' => 0,
            'tax' => $taxAmountBase,
            'tax_type' => $taxInfo['tax_type'],
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'address' => '',
            'phone_country_code' => getPhoneCode($countryCode, $db),
            'phone' => $phone,
            'country' => $countryCode,
            'adults' => $totalAdults,
            'infants' => 0,
            'childs' => $totalChildren,
            'child_ages' => json_encode($childAges),
            'currency_markup' => $baseCurrency,
            'cancellation_request' => 0,
            'cancellation_status' => 0,
            'booking_data' => json_encode($bookingData),
            'payment_status' => 'unpaid',
            'transaction_id' => null,
            'user_id' => $userId,
            'user_data' => $userData ? json_encode($userData) : null,
            'travellers' => json_encode($travellersData),
            'nationality' => $nationalityName,
            'payment_gateway' => $guestDetails['selected_payment'] ?? '',
            'module_type' => 'stays',
            'pnr' => null,
            'booking_response' => null,
            'error_response' => null,
            'commission' => $commissionBase,
            'module' => $bookingData['supplier'] ?? 'stays',
            'special_requests' => $guestDetails['special_requests'] ?? null,
            'promo_codes' => $promoCodeJson
        ]);

        $bookingResult = $db->id();

        if (!$bookingResult) {
            throw new Exception('Failed to save booking');
        }

        // AGENT API — wallet settlement (no-op unless agent-API request).
        agent_api_settle_booking($db, 'stays', $bookingResult, $invoiceId, (float) $finalTotalWithTaxBase);

        // Record promo code usage (idempotent per invoice; bumps used_count +
        // writes the per-user ledger row that enforces per_user_limit).
        if (!empty($promoCodeStr) && $promoDiscount > 0 && $promoData && function_exists('recordPromoUsage')) {
            recordPromoUsage($db, $promoData, (string) $invoiceId, $userId ?? null, $email ?? null, (float) $promoDiscount, 'stays', (string) $baseCurrency);
        }

        // DELETE TEMPORARY BOOKING DATA
        $db->delete('logs_bookings', ['hash' => $bookingHash]);

        // ============================================================================
        // WEBHOOK: Booking Confirmed
        // ============================================================================
        triggerWebhook('stays/booking', 'stays.booking.confirmed', [
            'booking_id' => $bookingResult,
            'invoice_id' => $invoiceId,
            'user_id' => $userId,
            'hotel_data' => [
                'name' => $bookingData['hotel_name'] ?? '',
                'address' => $bookingData['address'] ?? ($bookingData['hotel_address'] ?? ''),
                'city' => $bookingData['city'] ?? '',
                'country' => $bookingData['country'] ?? '',
                'stars' => $bookingData['stars'] ?? ($bookingData['hotel_stars'] ?? 3),
                'images' => $bookingData['images'] ?? ($bookingData['hotel_images'] ?? [])
            ],
            'booking_details' => [
                'checkin' => $bookingData['checkin'] ?? '',
                'checkout' => $bookingData['checkout'] ?? '',
                'nights' => $bookingData['nights'] ?? 1,
                'rooms' => $bookingData['rooms_count'] ?? 1,
                'adults' => $totalAdults,
                'children' => $totalChildren,
                'room_type' => $bookingData['room_type'] ?? 'Standard Room',
                'board_type' => $bookingData['board_type'] ?? 'Room Only'
            ],
            'pricing' => [
                'subtotal' => $subtotal,
                'markup' => 0,
                'tax' => $taxAmount,
                'commission' => $commission,
                'final_total' => $finalTotal,
                'currency' => $baseCurrency
            ],
            'customer_data' => [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
                'country' => $countryCode
            ],
            'payment' => [
                'gateway' => $guestDetails['selected_payment'] ?? '',
                'status' => 'unpaid'
            ],
            'module' => 'stays',
            'supplier' => $bookingData['supplier'] ?? 'stays',
            'timestamp' => date('Y-m-d H:i:s'),
            'invoice_url' => root . 'invoice/stays/' . $invoiceId
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Booking confirmed successfully',
            'booking_id' => $invoiceId,
            'amount' => $displayFinalTotal,
            'amount_base' => $finalTotalWithTaxBase,
            'currency' => $displayCurrency,
            'base_currency' => $baseCurrency,
            'display_currency' => $displayCurrency,
            'conversion_rate' => $conversionRate,
            'redirect_url' => root . 'invoice/stays/' . $invoiceId . '?currency=' . urlencode($displayCurrency)
        ]);

    } catch (Exception $e) {

        // ============================================================================
        // WEBHOOK: Booking Failed
        // ============================================================================
        triggerWebhook('stays/booking', 'stays.booking.failed', [
            'booking_hash' => $bookingHash ?? null,
            'error_message' => $e->getMessage(),
            'error_code' => $e->getCode(),
            'user_data' => $guestDetails ?? [],
            'booking_details' => $bookingData ?? [],
            'timestamp' => date('Y-m-d H:i:s'),
            'failure_stage' => 'booking_submission'
        ]);

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
// GET /api/Stays/booking/download-invoice/{invoiceId}
// ============================================================================

$router->get('/api/stays/booking/download-invoice/([A-Z0-9]{8})', function ($invoiceId) use ($SECURE, $db) {

    try {

        // --------------------------------------------------
        // CHECK BOOKING EXISTS
        // --------------------------------------------------
        $booking = $db->get('bookings', '*', [
            'invoice_id' => $invoiceId
        ]);

        if (!$booking) {
            http_response_code(404);
            die('Booking not found');
        }

        // IDOR GUARD: the PDF contains customer PII + pricing. Restrict to
        // admin / owner / creating session / valid payment token. Was
        // previously unauthenticated. enforceInvoiceAccess() exits on denial.
        if (function_exists('enforceInvoiceAccess')) {
            enforceInvoiceAccess($db, $booking);
        }

        // --------------------------------------------------
        // GENERATE / REFRESH PDF
        // --------------------------------------------------
        $pdfPath = GENERATE_BOOKING_PDF($invoiceId);

        if ($pdfPath && file_exists($pdfPath)) {

            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="invoice_' . $invoiceId . '.pdf"');
            header('Content-Length: ' . filesize($pdfPath));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');

            readfile($pdfPath);
            exit;
        }

        throw new Exception('Failed to generate or find invoice PDF');

    } catch (Exception $e) {
        http_response_code(500);
        die('Error downloading invoice');
    }
});


/*
|--------------------------------------------------------------------------
| RESEND INVOICE
| POST /api/stays/invoice/resend
|--------------------------------------------------------------------------
*/
$router->post('/api/stays/invoice/resend', function () use ($db) {

    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $invoiceId = $input['invoice_id'] ?? '';

        if (!$invoiceId) {
            throw new Exception('Invoice ID required');
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) {
            throw new Exception('Booking not found');
        }

        // SECURITY: resend is owner/admin only (was unauthenticated). Sends to the
        // booking's own email. enforceInvoiceAccess() emits 403 JSON + exit for a
        // non-owner on an /api/ route.
        if (function_exists('enforceInvoiceAccess')) {
            enforceInvoiceAccess($db, $booking);
        }

        $pdfPath = GENERATE_BOOKING_PDF($invoiceId);

        NOTIFY::resend('stays', [
            'email' => $booking['email'],
            'phone' => $booking['phone'],
            'first_name' => $booking['first_name'],
            'last_name' => $booking['last_name'],
            'country_code' => $booking['phone_country_code']
        ], [
            'invoice_id' => $invoiceId,
            'amount' => $booking['price_markup'],
            'currency' => $booking['currency_markup'],
            'payment_status' => $booking['payment_status']
        ], $pdfPath);

        echo json_encode(['success' => true, 'message' => 'Invoice resent']);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
});


/*
|--------------------------------------------------------------------------
| REQUEST CANCELLATION
| POST /api/stays/booking/cancel
|--------------------------------------------------------------------------
*/
$router->post('/api/stays/booking/cancel', function () use ($db) {

    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $invoiceId = $input['invoice_id'] ?? '';

        if (!$invoiceId) {
            throw new Exception('Invoice ID required');
        }

        $booking = $db->get('bookings', '*', [
            'invoice_id' => $invoiceId,
            'module_type' => 'stays'
        ]);

        if (!$booking) {
            throw new Exception('Booking not found');
        }

        // OWNERSHIP GUARD (IDOR): only the invoice owner / creating session /
        // admin may flag a booking for cancellation. Was unauthenticated — anyone
        // who knew an invoice id could flag another customer's booking and trigger
        // a cancellation notification to them. enforceInvoiceAccess() auto-responds
        // 403 JSON on an /api/ route and exits for a non-owner.
        if (function_exists('enforceInvoiceAccess')) {
            enforceInvoiceAccess($db, $booking);
        }

        $db->update('bookings', ['cancellation_request' => 1], [
            'invoice_id' => $invoiceId
        ]);

        NOTIFY::cancellation('stays', [
            'email' => $booking['email'] ?? '',
            'phone' => $booking['phone'] ?? '',
            'first_name' => $booking['first_name'] ?? '',
            'last_name' => $booking['last_name'] ?? '',
            'country_code' => $booking['phone_country_code'] ?? ''
        ], [
            'invoice_id' => $invoiceId,
            'amount' => $booking['price_markup'] ?? 0,
            'currency' => $booking['currency_markup'] ?? 'USD',
            'payment_status' => $booking['payment_status'] ?? 'unpaid'
        ]);

        echo json_encode(['success' => true, 'message' => 'Cancellation requested']);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
});

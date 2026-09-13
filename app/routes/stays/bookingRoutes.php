<?php
// ============================================================================
// FILE: app/routes/stays/booking.php
// STAYS BOOKING ROUTES - All booking-related GET and POST endpoints
// ============================================================================
@$SECURE or die('Access Denied!');

// ============================================================================
// GET: Booking page with hash
// GET /stays/booking/{hash}
// ============================================================================
// ============================================================================
// NET (COST) AMOUNT GUARD
// ----------------------------------------------------------------------------
// price_original feeds commission and agent_earning, so a net amount arriving
// from the browser is only accepted when it is a plausible cost for this sale:
// positive and not above the selling total. A net in the wrong currency — the
// classic cause of a commission equal to the whole price, or of an earning
// clamped to 0.00 — is rejected and the draft value is kept instead.
// ============================================================================
if (!function_exists('staysAcceptableNetAmount')) {
    function staysAcceptableNetAmount($candidate, $total, $fallback)
    {
        if (!is_numeric($candidate)) {
            return $fallback;
        }

        $candidate = round((float) $candidate, 2);
        $total = (float) $total;

        if ($candidate <= 0) {
            error_log('Stays: rejected non-positive net amount ' . $candidate . ', keeping ' . $fallback);
            return $fallback;
        }

        // Allow a cent of rounding above the total; anything more is a currency
        // mismatch, not a zero-margin sale.
        if ($total > 0 && $candidate > $total + 0.01) {
            error_log('Stays: rejected net amount ' . $candidate . ' above selling total ' . $total . ', keeping ' . $fallback);
            return $fallback;
        }

        return $candidate;
    }
}

$router->get('/stays/booking/([a-f0-9]{16})', function ($hash) use ($SECURE, $db) {
    // Get booking data from database
    $booking = $db->get('logs_bookings', ['hash', 'data'], ['hash' => $hash]);

    if (!$booking || empty($booking['data'])) {
        header('Location: ' . root . 'stays');
        exit;
    }

    // Decode booking data
    $bookingData = json_decode($booking['data'], true);

    if (!$bookingData) {
        header('Location: ' . root . 'stays');
        exit;
    }

    // Store in session for booking page
    $_SESSION['booking_data'] = $bookingData;
    $_SESSION['booking_hash'] = $hash;

    // Render booking page
    $title = 'Complete Booking - ' . $GLOBALS['app']['home_title'];
    $description = "Complete your hotel booking";
    require_once views."includes/header.php";
    require_once views."modules/stays/booking/index.php";
    require_once views."includes/footer.php";
});
    
// ============================================================================
// POST: Save booking draft
// POST /api/stay/booking/save-draft
// ============================================================================
$router->post('/api/stay/booking/save-draft', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || empty($input['hotel_id'])) {
            throw new Exception('Invalid booking data');
        }

        // Last line of defence for the portal stay limit — a draft posted
        // straight to this endpoint never passes through the page routes.
        if (function_exists('staysStayNights')) {
            $draftNights = staysStayNights($input['checkin'] ?? '', $input['checkout'] ?? '');
            $maxNights = staysMaxStayNights();
            if ($draftNights > $maxNights) {
                throw new Exception('Bookings are limited to a maximum of ' . $maxNights . ' nights.');
            }
        }

        // Generate secure hash (16 characters)
        $hash = bin2hex(random_bytes(8));

        // Save to database
        $result = $db->insert('logs_bookings', [
            'hash' => $hash,
            'data' => json_encode($input),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        if ($result) {
            // ============================================================================
            // WEBHOOK: Booking Draft Created
            // ============================================================================
            triggerWebhook('stays/booking', 'stays.booking.draft_created', [
                'booking_hash' => $hash,
                'hotel_data' => $input['hotelData'] ?? [],
                'booking_details' => $input['bookingData'] ?? [],
                'user_data' => $input['userData'] ?? [],
                'total_amount' => $input['bookingData']['finalTotal'] ?? 0,
                'timestamp' => date('Y-m-d H:i:s'),
                'user_id' => $_SESSION['user_id'] ?? null,
                'session_id' => session_id()
            ]);

            echo json_encode([
                'success' => true,
                'hash' => $hash,
                'message' => 'Booking draft saved successfully'
            ]);
        } else {
            throw new Exception('Failed to save booking data');
        }

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
// POST: Update existing booking draft after CheckRate (Make Payment)
// POST /api/stay/booking/update-draft
// ============================================================================
$router->post('/api/stay/booking/update-draft', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['booking_hash'])) {
            throw new Exception('Invalid draft update');
        }

        $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!CSRF::validateToken($csrfToken)) {
            throw new Exception('Invalid security token');
        }

        $bookingHash = (string) $input['booking_hash'];
        $booking = $db->get('logs_bookings', ['data'], ['hash' => $bookingHash]);
        if (!$booking) {
            throw new Exception('Booking draft not found');
        }

        $bookingData = json_decode($booking['data'], true);
        if (!is_array($bookingData)) {
            throw new Exception('Invalid booking draft data');
        }

        if (!empty($input['selected_rooms']) && is_array($input['selected_rooms'])) {
            $bookingData['selected_rooms'] = $input['selected_rooms'];
        }
        if (isset($input['total_amount']) && is_numeric($input['total_amount'])) {
            $bookingData['total_amount'] = round((float) $input['total_amount'], 2);
        }
        if (isset($input['actual_amount'])) {
            $bookingData['actual_amount'] = staysAcceptableNetAmount(
                $input['actual_amount'],
                $bookingData['total_amount'] ?? 0,
                $bookingData['actual_amount'] ?? 0
            );
        }
        if (!empty($input['checkrate_accepted_at'])) {
            $bookingData['checkrate_accepted_at'] = $input['checkrate_accepted_at'];
        }

        $updated = $db->update('logs_bookings', [
            'data' => json_encode($bookingData),
        ], ['hash' => $bookingHash]);

        if ($updated === false) {
            throw new Exception('Failed to save updated rate');
        }

        $_SESSION['booking_data'] = $bookingData;
        $_SESSION['booking_hash'] = $bookingHash;

        echo json_encode([
            'success' => true,
            'hash' => $bookingHash,
            'message' => 'Updated rate saved to draft',
        ]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
    }
    exit;
});

// ============================================================================
// GET: Download invoice PDF
// GET /api/stay/booking/download-invoice/{invoiceId}
// ============================================================================
$router->get('/api/stay/booking/download-invoice/([A-Z0-9]{8})', function ($invoiceId) use ($SECURE, $db) {
    try {
        // Check if booking exists
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) {
            http_response_code(404);
            die('Booking not found');
        }

        // Always generate/refresh PDF before download
        $pdfPath = GENERATE_BOOKING_PDF($invoiceId);

        if ($pdfPath && file_exists($pdfPath)) {
            // Serve PDF
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

// ============================================================================
// POST: Resend invoice email
// POST /api/stay/booking/resend-invoice
// ============================================================================
$router->post('/api/stay/booking/resend-invoice', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['invoice_id'])) {
            throw new Exception('Invoice ID is required');
        }

        $invoiceId = $input['invoice_id'];

        // Fetch booking details
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);

        if (!$booking) {
            throw new Exception('Booking not found');
        }

        // Fetch payment gateway name
        $paymentGatewayName = $booking['payment_gateway'];
        if (!empty($booking['payment_gateway']) && is_numeric($booking['payment_gateway'])) {
            $gateway = $db->get('payment_gateways', 'name', ['id' => $booking['payment_gateway']]);
            if ($gateway) {
                $paymentGatewayName = $gateway;
            }
        }

        // Get customer email
        $customerEmail = $booking['email'];
        $customerName = $booking['first_name'] . ' ' . $booking['last_name'];

        // Generate or get existing PDF
        $pdfPath = GENERATE_BOOKING_PDF($invoiceId);

        // Fetch booking data for additional info if needed
        $bookingData = json_decode($booking['booking_data'], true);

        // Send consolidated notification (Customer only)
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

        echo json_encode([
            'status' => true,
            'message' => 'Invoice has been resent successfully to ' . $booking['email']
        ]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'status' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
});

// ============================================================================
// POST: Request cancellation
// POST /api/stay/booking/request-cancellation
// ============================================================================
$router->post('/api/stay/booking/request-cancellation', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['invoice_id'])) {
            throw new Exception('Invoice ID is required');
        }

        $invoiceId = $input['invoice_id'];

        // Check if booking exists
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);

        if (!$booking) {
            throw new Exception('Booking not found');
        }

        if ($booking['cancellation_request'] == 1) {
            throw new Exception('Cancellation already requested');
        }

        // Update cancellation request
        $result = $db->update('bookings', [
            'cancellation_request' => 1
        ], [
            'invoice_id' => $invoiceId
        ]);

        if ($result) {
            // Send notification using NOTIFY library
            NOTIFY::cancellation('stays', [
                'email' => $booking['email'] ?? '',
                'phone' => $booking['phone'] ?? '',
                'first_name' => $booking['first_name'] ?? '',
                'last_name' => $booking['last_name'] ?? '',
                'country_code' => $booking['phone_country_code'] ?? ''
            ], [
                'invoice_id' => $invoiceId,
                'amount' => $booking['price_markup'] ?? 0,
                'currency' => $booking['currency'] ?? 'USD',
                'module_type' => 'Stay'
            ]);

            ob_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Cancellation request submitted successfully'
            ]);
        } else {
            throw new Exception('Failed to update cancellation request');
        }

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
// POST: Submit final booking (complex - includes email, PDF, user creation)
// POST /api/stay/booking/submit
// ============================================================================
$router->post('/api/stay/booking/submit', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || empty($input['booking_hash'])) {
            throw new Exception('Invalid booking submission');
        }

        // VALIDATE CSRF TOKEN
        $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!CSRF::validateToken($csrfToken)) {
            throw new Exception('Invalid security token');
        }

        $bookingHash = $input['booking_hash'];
        $guestDetails = $input['guest_details'] ?? [];
        $finalTotal = $input['final_total'] ?? 0;
        $displayTotal = $input['display_total'] ?? 0;
        $subtotal = $input['subtotal'] ?? 0;
        $taxAmount = $input['tax_amount'] ?? 0;
        $baseCurrency = $input['base_currency'] ?? 'USD';
        $displayCurrency = $input['display_currency'] ?? 'USD';

        // VALIDATE TERMS ACCEPTANCE
        if (empty($guestDetails['terms_accepted'])) {
            throw new Exception('Terms and conditions must be accepted');
        }

        // GET ORIGINAL BOOKING DATA FROM TEMP TABLE
        $booking = $db->get('logs_bookings', ['data'], ['hash' => $bookingHash]);
        if (!$booking) {
            throw new Exception('Booking not found');
        }
        $bookingData = json_decode($booking['data'], true);
        if (!is_array($bookingData)) {
            throw new Exception('Invalid booking draft data');
        }

        // Hotelbeds Make Payment: store CheckRate-refreshed rooms from the
        // payment button. Do not CheckRate again here or after payment.
        $supplierName = strtolower(trim((string)($bookingData['supplier'] ?? '')));
        if ($supplierName === 'hotelbeds') {
            if (!empty($input['selected_rooms']) && is_array($input['selected_rooms'])) {
                $bookingData['selected_rooms'] = $input['selected_rooms'];
            }
            if (isset($input['total_amount']) && is_numeric($input['total_amount'])) {
                $bookingData['total_amount'] = round((float)$input['total_amount'], 2);
            }
            if (isset($input['actual_amount'])) {
                $bookingData['actual_amount'] = staysAcceptableNetAmount(
                    $input['actual_amount'],
                    $bookingData['total_amount'] ?? 0,
                    $bookingData['actual_amount'] ?? 0
                );
            }
            if (!empty($input['checkrate_accepted_at'])) {
                $bookingData['checkrate_accepted_at'] = $input['checkrate_accepted_at'];
            }
        }

        // Persist guest display currency so invoice PDF can convert cancellation fees (e.g. PKR)
        if (!empty($displayCurrency)) {
            $bookingData['display_currency'] = strtoupper(trim((string) $displayCurrency));
        }
        if (!empty($baseCurrency)) {
            $bookingData['currency'] = strtoupper(trim((string) $baseCurrency));
        }
        
        // GET PRICES FROM BOOKING DATA
        $actualPrice = $bookingData['actual_amount'] ?? 0;
        $markupPrice = $bookingData['total_amount'] ?? 0;
        $currency = $bookingData['currency'] ?? 'USD';

        // MARKUP NORMALISATION (docs/MONEY-WALLET-AUDIT.md §C.4 step 6): re-derive
        // the sell price SERVER-SIDE via MARKUP() from the trusted supplier net
        // ($actualPrice from the server draft), so the agent's b2b rate + member-
        // tier discount + any custom user markup are honoured at checkout instead
        // of trusting the search-time total_amount. Only when we have a real net.
        if ((float)$actualPrice > 0) {
            if (!function_exists('MARKUP')) {
                require_once dirname(__DIR__, 3) . '/modules/helpers.php';
            }
            if (function_exists('MARKUP')) {
                $staysSupplier = $bookingData['supplier'] ?? 'hotels';
                $staysModuleRow = $db->get('modules', '*', ['name' => $staysSupplier, 'type' => 'stays', 'status' => '1'])
                    ?: $db->get('modules', '*', ['type' => 'stays', 'status' => '1']);
                $mk = MARKUP((float)$actualPrice, $staysModuleRow ?: null, $db, $currency, $currency);
                if (!empty($mk['price']) && (float)$mk['price'] > 0) {
                    $markupPrice = round((float)$mk['price'], 2);
                }
            }
        }

        // USE PRICE FROM FRONTEND IF PROVIDED, OTHERWISE FROM BOOKING DATA
        // (now the server-recomputed markupPrice is authoritative)
        $subtotal = $markupPrice;

        // GENERATE 8-CHARACTER UUID FOR INVOICE
        $invoiceId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        // GET NATIONALITY FULL NAME FROM COUNTRIES TABLE
        $nationalityData = $db->get('countries', 'nicename', ['iso' => $bookingData['nationality']]);
        $nationalityName = $nationalityData ?? $bookingData['nationality'];

        // EXTRACT PRIMARY GUEST DETAILS
        $primaryGuest = $guestDetails['primary_guest'] ?? [];

        // SERVER-SIDE VALIDATION FOR PRIMARY GUEST & TRAVELERS
        if (empty($primaryGuest['title']) || empty(trim($primaryGuest['first_name'] ?? '')) || empty(trim($primaryGuest['last_name'] ?? '')) || empty(trim($primaryGuest['email'] ?? '')) || empty(trim($primaryGuest['phone'] ?? ''))) {
            throw new Exception('Please fill in all primary guest details (Title, First Name, Last Name, Email, Phone)');
        }

        if (!filter_var($primaryGuest['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please enter a valid email address');
        }

        if (!empty($guestDetails['travelers']) && is_array($guestDetails['travelers'])) {
            foreach ($guestDetails['travelers'] as $roomKey => $roomTravelers) {
                if (!is_array($roomTravelers)) continue;
                $roomNum = (int)str_replace('room_', '', $roomKey) + 1;
                foreach ($roomTravelers as $guestKey => $guest) {
                    if (!is_array($guest)) continue;
                    if (strpos($guestKey, 'adult_') === 0) {
                        $adultNum = (int)str_replace('adult_', '', $guestKey) + 1;
                        if ($roomNum === 1 && $adultNum === 1 && empty($guestDetails['booking_for_someone_else'])) {
                            continue; // Lead traveler synced with primary guest
                        }
                        if (empty(trim($guest['title'] ?? '')) || empty(trim($guest['first_name'] ?? '')) || empty(trim($guest['last_name'] ?? ''))) {
                            throw new Exception("Please enter title, first name, and last name for Room {$roomNum}, Adult {$adultNum}");
                        }
                    } elseif (strpos($guestKey, 'child_') === 0) {
                        $childNum = (int)str_replace('child_', '', $guestKey) + 1;
                        if (empty(trim($guest['first_name'] ?? '')) || empty(trim($guest['last_name'] ?? ''))) {
                            throw new Exception("Please enter first name and last name for Room {$roomNum}, Child {$childNum}");
                        }
                    }
                }
            }
        }

        // COUNT ADULTS AND CHILDREN FROM BOOKING DATA
        $totalAdults = 0;
        $totalChildren = 0;
        $childAges = [];
        foreach ($bookingData['rooms_data'] as $room) {
            $totalAdults += $room['adults'];
            $totalChildren += $room['children'];
            if (!empty($room['childAges'])) {
                $childAges = array_merge($childAges, $room['childAges']);
            }
        }

        // CALCULATE TAX DETAILS
        $supplier = $bookingData['supplier'] ?? 'hotels';
        $taxInfo = calculateTax($subtotal, $supplier, $db);
        $calculatedTaxAmount = $taxInfo['tax_amount'] ?? 0;

        // USE TAX AMOUNT FROM FRONTEND IF PROVIDED
        if ($taxAmount == 0) {
            $taxAmount = $calculatedTaxAmount;
        }

        // CALCULATE COMMISSION (MARKUP MINUS ACTUAL PRICE)
        $commission = round($markupPrice - $actualPrice, 2);

        // CALCULATE FINAL TOTAL WITH TAX (MARKUP PRICE + TAX)
        $finalTotalWithTax = round($markupPrice + $taxAmount, 2);

        // ============================================================================
        // PRICES ARE ALREADY IN BASE CURRENCY (FROM FRONTEND/DRAFT)
        // ============================================================================
        $actualPriceBase = round($actualPrice, 2);
        $markupPriceBase = round($markupPrice, 2);
        $taxAmountBase = round($taxAmount, 2);
        $commissionBase = $commission;
        $finalTotalWithTaxBase = $finalTotalWithTax;

        // PROMO CODE HANDLING
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
            $finalTotalWithTaxBase = round($finalTotalWithTaxBase - $promoDiscount, 2);
        }

        // PREPARE TRAVELLERS JSON (GUEST + TRAVELLERS)
        $travellersData = [
            'primary_guest' => $primaryGuest,
            'travelers' => $guestDetails['travelers'] ?? [],
            'booking_for_someone_else' => $guestDetails['booking_for_someone_else'] ?? false
        ];

        // AUTO-ACCOUNT CREATION FOR GUEST BOOKINGS
        $userId = $_SESSION['user_id'] ?? null;
        $userData = null;
        $accountCreated = false;
        $generatedPassword = null;

        if ($userId) {
            $userData = $db->get('users', '*', ['user_id' => $userId]);
        } else {
            // Check if user exists by email
            $existingUser = $db->get('users', '*', ['email' => $primaryGuest['email']]);
            
            if ($existingUser) {
                // User exists, use existing account
                $userId = $existingUser['user_id'];
                $userData = $existingUser;
                error_log("Associated booking with existing user: $userId");
            } else {
                // Create new user account
                $generatedPassword = bin2hex(random_bytes(4)); // 8-character password
                $hashedPassword = password_hash($generatedPassword, PASSWORD_DEFAULT);
                
                $newUserId = 'USR' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                
                $userInsert = $db->insert('users', [
                    'user_id' => $newUserId,
                    'first_name' => $primaryGuest['first_name'],
                    'last_name' => $primaryGuest['last_name'],
                    'email' => $primaryGuest['email'],
                    'password' => $hashedPassword,
                    'phone' => $primaryGuest['phone'],
                    'phone_country_code' => $primaryGuest['country_code'] ?? '',
                    'status' => 'active',
                    'role' => 'user',
                    'created_at' => date('Y-m-d H:i:s'),
                    'email_verified' => 0
                ]);
                
                if ($userInsert) {
                    $userId = $newUserId;
                    $userData = $db->get('users', '*', ['user_id' => $newUserId]);
                    $accountCreated = true;
                    error_log("Auto-created user account for booking: $userId");
                    
                    // ============================================================================
                    // WEBHOOK: User Auto-Created from Booking
                    // ============================================================================
                    triggerWebhook('stays/booking', 'stays.booking.user_created', [
                        'user_id' => $newUserId,
                        'email' => $primaryGuest['email'],
                        'first_name' => $primaryGuest['first_name'],
                        'last_name' => $primaryGuest['last_name'],
                        'phone' => $primaryGuest['phone'],
                        'country' => $primaryGuest['country_code'] ?? '',
                        'created_via' => 'stays_booking_guest',
                        'booking_hash' => $bookingHash,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]);
                } else {
                    error_log("Failed to create user account from booking");
                }
            }
        }

        // INSERT INTO BOOKINGS TABLE
        $isAgent = is_array($userData) && strtolower((string)($userData['role'] ?? '')) === 'agent';
        if (!$isAgent && strtolower((string)($_SESSION['user_role'] ?? '')) === 'agent') {
            $isAgent = true;
        }
        $agentEarning = $isAgent ? max(0, (float)$commissionBase) : 0;

        $db->insert('bookings', [
            'invoice_id' => $invoiceId,
            'language' => getCurrentLanguage(),
            'booking_date' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'booking_status' => 'pending',
            'price_original' => $actualPriceBase,
            'price_markup' => $finalTotalWithTaxBase,
            'agent_earning' => $agentEarning,
            'tax' => $taxAmountBase,
            'tax_type' => $taxInfo['tax_type'],
            'first_name' => $primaryGuest['first_name'],
            'last_name' => $primaryGuest['last_name'],
            'email' => $primaryGuest['email'],
            'address' => '',
            'phone_country_code' => getPhoneCode($primaryGuest['country_code'] ?? '', $db),
            'phone' => $primaryGuest['phone'],
            'country' => $primaryGuest['country_code'],
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

        if ($bookingResult) {
            // Record promo code usage
            if (!empty($promoCodeStr) && $promoDiscount > 0 && $promoData) {
                $db->update('promo_codes', ['used_count[+]' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $promoData['id']]);
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
                    'name' => $bookingData['hotel_name'],
                    'address' => $bookingData['address'] ?? '',
                    'city' => $bookingData['city'] ?? '',
                    'country' => $bookingData['country'] ?? '',
                    'stars' => $bookingData['stars'] ?? 3,
                    'images' => $bookingData['images'] ?? []
                ],
                'booking_details' => [
                    'checkin' => $bookingData['checkin'],
                    'checkout' => $bookingData['checkout'],
                    'nights' => $bookingData['nights'],
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
                    'first_name' => $primaryGuest['first_name'],
                    'last_name' => $primaryGuest['last_name'],
                    'email' => $primaryGuest['email'],
                    'phone' => $primaryGuest['phone'],
                    'country' => $primaryGuest['country_code']
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

            // SEND NOTIFICATIONS USING NOTIFY LIBRARY
            // SEND NOTIFICATIONS USING NOTIFY LIBRARY
            $notifyBookingData = [
                'invoice_id' => $invoiceId,
                'amount' => $finalTotalWithTaxBase,
                'currency' => $baseCurrency,
                'payment_status' => 'unpaid',
                'hotel_name' => $bookingData['hotel_name'] ?? '',
                'hotelAddress' => $bookingData['address'] ?? '',
                'hotelStars' => $bookingData['stars'] ?? 3,
                'checkin' => $bookingData['checkin'] ?? '',
                'checkout' => $bookingData['checkout'] ?? '',
                'nights' => $bookingData['nights'] ?? 1,
                'adults' => $totalAdults,
                'children' => $totalChildren,
                'roomType' => $bookingData['room_type'] ?? 'Standard Room',
                'boardType' => $bookingData['board_type'] ?? 'Room Only',
                'selectedRooms' => $bookingData['rooms_data'] ?? [],
                'subtotal' => $markupPrice,
                'tax' => $taxAmount,
                'specialRequests' => $guestDetails['special_requests'] ?? '',
                'paymentMethod' => $guestDetails['selected_payment'] ?? 'N/A',
                'module_type' => 'Stay',
                'date' => $bookingData['checkin'] ?? date('Y-m-d')
            ];

            // 1. Notify Customer & Admins - HANDLED BY WEBHOOK
            // NOTIFY::booking('stays', [
            //     'email' => $primaryGuest['email'] ?? '',
            //     'phone' => $primaryGuest['phone'] ?? '',
            //     'first_name' => $primaryGuest['first_name'] ?? '',
            //     'last_name' => $primaryGuest['last_name'] ?? '',
            //     'country_code' => $primaryGuest['country_code'] ?? $primaryGuest['phone_country_code'] ?? ''
            // ], $notifyBookingData);

            // 2. Notify Hotel Owner (Vendor)
            try {
                $debugLog = "--- New Booking ---\n";
                $hotelIdForOwner = $bookingData['hotel_id'] ?? $bookingData['id'] ?? 0;
                $debugLog .= "Hotel ID: " . $hotelIdForOwner . "\n";
                
                // HANDLED BY WEBHOOK - Disabled here to prevent duplicates
                if (false && $hotelIdForOwner) {
                    $hotelRecord = $db->get('stays', ['id', 'user_id', 'email'], ['id' => $hotelIdForOwner]);
                    $debugLog .= "Hotel Record: " . json_encode($hotelRecord) . "\n";
                    
                    // If hotel has a linked user (Owner/Vendor)
                    if ($hotelRecord && !empty($hotelRecord['user_id'])) {
                        $owner = $db->get('users', ['email', 'phone', 'phone_country_code', 'first_name', 'last_name'], ['user_id' => $hotelRecord['user_id']]);
                        $debugLog .= "Owner Record: " . json_encode($owner) . "\n";
                        
                        if ($owner && !empty($owner['email'])) {
                            NOTIFY::vendor('stays', [
                                'email' => $owner['email'],
                                'phone' => $owner['phone'] ?? '',
                                'first_name' => $owner['first_name'] ?? 'Vendor',
                                'last_name' => $owner['last_name'] ?? '',
                                'country_code' => $owner['phone_country_code'] ?? ''
                            ], $notifyBookingData);
                            $debugLog .= "CALLED NOTIFY::vendor for Owner: " . $owner['email'] . "\n";
                        } else {
                            $debugLog .= "Owner found but email empty or owner missing.\n";
                        }
                    } 
                    // Fallback: If no user linked but hotel has email (generic hotel email)
                    elseif ($hotelRecord && !empty($hotelRecord['email'])) {
                         NOTIFY::vendor('stays', [
                            'email' => $hotelRecord['email'],
                            'phone' => '', 
                            'first_name' => 'Hotel', 
                            'last_name' => 'Admin',
                            'country_code' => ''
                        ], $notifyBookingData);
                         $debugLog .= "CALLED NOTIFY::vendor for Hotel Email: " . $hotelRecord['email'] . "\n";
                    } else {
                        $debugLog .= "No owner and no hotel email found.\n";
                    }
                } else {
                    $debugLog .= "No Hotel ID found in booking data. Keys: " . implode(',', array_keys($bookingData)) . "\n";
                }
                
            } catch (Exception $e) {
                
            }

            ob_clean();

            echo json_encode([
                'success' => true,
                'message' => 'Booking confirmed successfully',
                'booking_id' => $invoiceId,
                'invoice_id' => $invoiceId,
                'redirect_url' => root . 'invoice/stays/' . $invoiceId,
                'countdown' => true
            ]);
        } else {
            throw new Exception('Failed to save booking');
        }

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

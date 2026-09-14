<?php
// ============================================================================
// FILE: app/routes/tours/booking.php
// TOURS BOOKING ROUTES - All booking-related GET and POST endpoints
// ============================================================================
@$SECURE or die('Access Denied!');

// ============================================================================
// GET: Booking page with hash
// GET /tours/booking/{hash}
// ============================================================================
$router->get('/tours/booking/([a-f0-9]{16})', function ($hash) use ($SECURE, $db) {

    // Get booking data from database
    $booking = $db->get('logs_bookings', ['hash', 'data'], ['hash' => $hash]);

    if (!$booking || empty($booking['data'])) {
        header('Location: ' . root . 'tours');
        exit;
    }

    // Decode booking data
    $bookingData = json_decode($booking['data'], true);

    if (!$bookingData) {
        header('Location: ' . root . 'tours');
        exit;
    }

    // Store in session for booking page
    $_SESSION['tour_booking_data'] = $bookingData;
    $_SESSION['tour_booking_hash'] = $hash;

    // Render booking page
    $title = 'Complete Booking - ' . $GLOBALS['app']['home_title'];
    $description = "Complete your tour booking";
    require_once views."includes/header.php";
    require_once views."modules/tours/booking/index.php";
    require_once views."includes/footer.php";
});

// ============================================================================
// POST: Save booking draft
// POST /api/tour/booking/save-draft
// ============================================================================
$router->post('/api/tour/booking/save-draft', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || empty($input['tour_id'])) {
            throw new Exception('Invalid booking data');
        }

        // Generate secure hash (16 characters)
        $hash = bin2hex(random_bytes(8));

        // Save to database
        $result = $db->insert('logs_bookings', [
            'hash' => $hash,
            'data' => json_encode($input),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($result) {
            // Trigger draft created webhook
            triggerWebhook('tours/booking', 'tours.booking.draft_created', [
                'booking_hash' => $hash,
                'tour_id' => $input['tour_id'] ?? null,
                'tour_name' => $input['tour_name'] ?? '',
                'timestamp' => date('Y-m-d H:i:s'),
                'user_id' => $_SESSION['user_data']['id'] ?? null,
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
// POST: Resend invoice email
// ============================================================================
// GET: Download invoice PDF
// GET /api/tour/booking/download-invoice/{invoiceId}
// ============================================================================
$router->get('/api/tour/booking/download-invoice/([A-Z0-9]{8})', function ($invoiceId) use ($SECURE, $db) {
    try {
        // Check if it's a tour booking
        $booking = $db->get('bookings', ['invoice_id', 'module_type'], ['invoice_id' => $invoiceId]);

        if (!$booking) {
            http_response_code(404);
            die('Invoice not found');
        }

        // Always generate/refresh PDF before download
        $pdfPath = GENERATE_BOOKING_PDF($invoiceId);

        if ($pdfPath && file_exists($pdfPath)) {
            // Serve PDF
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="tour_invoice_' . $invoiceId . '.pdf"');
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
// POST /api/tour/booking/resend-invoice
// ============================================================================
$router->post('/api/tour/booking/resend-invoice', function () use ($SECURE, $db) {
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
        NOTIFY::resend('tours', [
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
// POST /api/tour/booking/request-cancellation
// ============================================================================
$router->post('/api/tour/booking/request-cancellation', function () use ($SECURE, $db) {
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

        // OWNERSHIP GUARD: only the invoice owner / creating session / admin may
        // request cancellation. Was unauthenticated — any anon could flag any
        // invoice (and trigger its cancellation notification). enforceInvoiceAccess
        // auto-responds 403 JSON on an /api/ route and exits for a non-owner.
        if (function_exists('enforceInvoiceAccess')) {
            enforceInvoiceAccess($db, $booking);
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
            NOTIFY::cancellation('tours', [
                'email' => $booking['email'] ?? '',
                'phone' => $booking['phone'] ?? '',
                'first_name' => $booking['first_name'] ?? '',
                'last_name' => $booking['last_name'] ?? '',
                'country_code' => $booking['phone_country_code'] ?? ''
            ], [
                'invoice_id' => $invoiceId,
                'amount' => $booking['price_markup'] ?? 0,
                'currency' => $booking['currency'] ?? 'USD',
                'module_type' => 'Tour'
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Tour cancellation request submitted successfully'
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
// TOUR BOOKING SUBMISSION ROUTE
// ============================================================================
$router->post('/api/tour/booking/submit', function () use ($SECURE, $db) {
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

        // GET PRICES FROM BOOKING DATA
        $actualPrice = $bookingData['actual_total_tour_price'] ?? 0;
        $markupPrice = $bookingData['markup_total_tour_price'] ?? 0;
        $currency = $bookingData['currency'] ?? 'USD';

        // MARKUP NORMALISATION (docs/MONEY-WALLET-AUDIT.md §C.4 step 6): re-derive
        // the sell price SERVER-SIDE via MARKUP() from the trusted supplier net
        // ($actualPrice from the server draft) so the agent's b2b rate + member-
        // tier discount + custom user markup are honoured at checkout, instead of
        // trusting the search-time markup_total_tour_price. The module markup is a
        // single rate, so applying it to the aggregate net equals the per-pax sum.
        if ((float)$actualPrice > 0) {
            if (!function_exists('MARKUP')) {
                require_once dirname(__DIR__, 3) . '/modules/helpers.php';
            }
            if (function_exists('MARKUP')) {
                $toursModuleRow = $db->get('modules', '*', ['type' => 'tours', 'status' => '1']);
                $mk = MARKUP((float)$actualPrice, $toursModuleRow ?: 'tours', $db, $currency, $currency);
                if (!empty($mk['price']) && (float)$mk['price'] > 0) {
                    $markupPrice = round((float)$mk['price'], 2);
                }
            }
        }

        // The server-recomputed markupPrice is authoritative.
        $subtotal = $markupPrice;
        $finalTotal = $markupPrice + $taxAmount;

        // GENERATE 8-CHARACTER UUID FOR INVOICE
        $invoiceId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        // GET NATIONALITY FULL NAME FROM COUNTRIES TABLE
        $nationalityData = $db->get('countries', 'nicename', ['iso' => $bookingData['nationality'] ?? '']);
        $nationalityName = $nationalityData ?: ($bookingData['nationality'] ?? '');

        // EXTRACT PRIMARY GUEST DETAILS
        $primaryGuest = $guestDetails['primary_guest'];

        // COUNT ADULTS AND CHILDREN FROM BOOKING DATA
        $totalAdults = $bookingData['total_adults'] ?? 1;
        $totalChildren = $bookingData['total_children'] ?? 0;

        // CALCULATE TAX DETAILS
        $taxInfo = calculateTax($markupPrice, 'tours', $db);
        $calculatedTaxAmount = $taxInfo['tax_amount'] ?? 0;

        // USE TAX AMOUNT FROM FRONTEND IF PROVIDED
        if ($taxAmount == 0) {
            $taxAmount = $calculatedTaxAmount;
        }

        // CALCULATE COMMISSION (MARKUP MINUS ACTUAL PRICE)
        $commission = $markupPrice - $actualPrice;

        // CALCULATE FINAL TOTAL WITH TAX (MARKUP PRICE + TAX)
        $finalTotalWithTax = $markupPrice + $taxAmount;

        // ============================================================================
        // PRICES ARE ALREADY IN BASE CURRENCY (FROM FRONTEND)
        // ============================================================================
        // The frontend now sends 'subtotal', 'tax_amount', 'final_total' in Base Currency.
        // We do NOT need to convert them again.

        $actualPriceBase = $actualPrice;
        $markupPriceBase = $markupPrice;
        $taxAmountBase = $taxAmount;
        $commissionBase = $commission;
        $finalTotalWithTaxBase = $finalTotalWithTax;

        // PROMO CODE HANDLING (audit money-integrity): recompute the discount
        // SERVER-SIDE from the promo row — never trust the client's promo_discount.
        $promoCodeStr = trim($input['promo_code'] ?? '');
        $promoDiscount = 0.0;
        $promoCodeJson = null;
        $promoData = null;
        if ($promoCodeStr !== '' && function_exists('validatePromoCode')) {
            $pv = validatePromoCode($db, $promoCodeStr, (float) $markupPriceBase, 'tours', (string) $baseCurrency, [
                'item_id'    => (int) ($input['tour_id'] ?? 0),
                'user_id'    => $_SESSION['user_id'] ?? null,
                'user_email' => $primaryGuest['email'] ?? null,
            ]);
            if (!empty($pv['ok'])) {
                $promoDiscount = (float) $pv['discount'];
                $promoData = $pv['promo'] ?? null;
            }
        }
        if ($promoDiscount > 0 && $promoData) {
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

        // APPLY PROMO DISCOUNT TO FINAL TOTAL
        if ($promoDiscount > 0 && $promoData) {
            $finalTotalWithTaxBase = round($finalTotalWithTaxBase - $promoDiscount, 2);
        }

        // PREPARE TRAVELLERS JSON
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
            'booking_status' => 'pending',
            'price_original' => $actualPriceBase,           // ACTUAL PRICE IN BASE CURRENCY
            'price_markup' => $finalTotalWithTaxBase,       // MARKUP PRICE + TAX IN BASE CURRENCY
            'agent_earning' => $agentEarning,
            'tax' => $taxAmountBase,                        // TAX IN BASE CURRENCY
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
            'child_ages' => json_encode([]),
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
            'module_type' => 'tours',
            'pnr' => null,
            'booking_response' => null,
            'error_response' => null,
            'commission' => $commissionBase,                // COMMISSION IN BASE CURRENCY
            'module' => $bookingData['supplier'] ?? 'tours',
            'special_requests' => $guestDetails['special_requests'] ?? null,
            'promo_codes' => $promoCodeJson
        ]);

        $bookingResult = $db->id();

        if ($bookingResult) {
            // Record promo code usage (idempotent per invoice; bumps used_count +
            // writes the per-user ledger row that enforces per_user_limit).
            if (!empty($promoCodeStr) && $promoDiscount > 0 && $promoData && function_exists('recordPromoUsage')) {
                recordPromoUsage($db, $promoData, (string) $invoiceId, $userId ?? null, $primaryGuest['email'] ?? null, (float) $promoDiscount, 'tours', (string) $baseCurrency);
            }
            
            // Trigger booking confirmed webhook
            triggerWebhook('tours/booking', 'tours.booking.confirmed', [
                'booking_id' => $bookingResult,
                'invoice_id' => $invoiceId,
                'user_id' => $userId,
                'customer_name' => $primaryGuest['first_name'] . ' ' . $primaryGuest['last_name'],
                'customer_email' => $primaryGuest['email'],
                'customer_phone' => $primaryGuest['phone'],
                'tour_name' => $bookingData['tour_name'] ?? '',
                'tour_location' => $bookingData['tour_location'] ?? '',
                'start_date' => $bookingData['start_date'] ?? '',
                'duration' => $bookingData['duration'] ?? '',
                'total_amount' => $finalTotalWithTaxBase,  // BASE CURRENCY
                'currency' => $baseCurrency,
                'adults' => $totalAdults,
                'children' => $totalChildren,
                'payment_gateway' => $guestDetails['selected_payment'] ?? '',
                'timestamp' => date('Y-m-d H:i:s')
            ]);

            // DELETE TEMPORARY BOOKING DATA
            $db->delete('logs_bookings', ['hash' => $bookingHash]);

            // SEND NOTIFICATIONS USING NOTIFY LIBRARY - HANDLED BY WEBHOOK
            // NOTIFY::booking('tours', [
            //     'email' => $primaryGuest['email'] ?? '',
            //     'phone' => $primaryGuest['phone'] ?? '',
            //     'first_name' => $primaryGuest['first_name'] ?? '',
            //     'last_name' => $primaryGuest['last_name'] ?? '',
            //     'country_code' => $primaryGuest['country_code'] ?? $primaryGuest['phone_country_code'] ?? ''
            // ], [
            //     'invoice_id' => $invoiceId,
            //     'amount' => $finalTotalWithTaxBase,
            //     'currency' => $baseCurrency,
            //     'payment_status' => 'unpaid',
            //     'tour_name' => $bookingData['tour_name'] ?? '',
            //     'location' => $bookingData['tour_location'] ?? '',
            //     'startDate' => $bookingData['start_date'] ?? '',
            //     'duration' => $bookingData['duration'] ?? '',
            //     'adults' => $totalAdults,
            //     'children' => $totalChildren,
            //     'subtotal' => $markupPrice,
            //     'tax' => $taxAmount,
            //     'special_requests' => $guestDetails['special_requests'] ?? '',
            //     'payment_method' => $guestDetails['selected_payment'] ?? 'N/A',
            //     'module_type' => 'Tour',
            //     'date' => $bookingData['start_date'] ?? date('Y-m-d')
            // ]);

            // 2. Notify Tour Owner (Vendor)
            // 2. Notify Tour Owner (Vendor) - HANDLED BY WEBHOOK
            // try {
            //     $tourIdForOwner = $bookingData['tour_id'] ?? 0;
            //     if ($tourIdForOwner) {
            //         $tourRecord = $db->get('tours', ['id', 'user_id', 'email'], ['id' => $tourIdForOwner]);
            //         if ($tourRecord && !empty($tourRecord['user_id'])) {
            //             $owner = $db->get('users', ['email', 'phone', 'phone_country_code', 'first_name', 'last_name'], ['user_id' => $tourRecord['user_id']]);
            //             if ($owner && !empty($owner['email'])) {
            //                 NOTIFY::vendor('tours', [
            //                     'email' => $owner['email'],
            //                     'phone' => $owner['phone'] ?? '',
            //                     'first_name' => $owner['first_name'] ?? 'Vendor',
            //                     'last_name' => $owner['last_name'] ?? '',
            //                     'country_code' => $owner['phone_country_code'] ?? ''
            //                 ], [
            //                     'invoice_id' => $invoiceId,
            //                     'amount' => $finalTotalWithTaxBase,
            //                     'currency' => $baseCurrency,
            //                     'payment_status' => 'unpaid',
            //                     'tour_name' => $bookingData['tour_name'] ?? '',
            //                     'location' => $bookingData['tour_location'] ?? '',
            //                     'startDate' => $bookingData['start_date'] ?? '',
            //                     'duration' => $bookingData['duration'] ?? '',
            //                     'adults' => $totalAdults,
            //                     'children' => $totalChildren,
            //                     'subtotal' => $markupPrice,
            //                     'tax' => $taxAmount,
            //                     'special_requests' => $guestDetails['special_requests'] ?? '',
            //                     'payment_method' => $guestDetails['selected_payment'] ?? 'N/A',
            //                     'module_type' => 'Tour',
            //                     'date' => $bookingData['start_date'] ?? date('Y-m-d')
            //                 ]);
            //             }
            //         }
            //     }
            // } catch (Exception $e) {
            //     // Silently skip if notification fails - we don't want to break the booking flow
            // }

            ob_clean();

            echo json_encode([
                'success' => true,
                'message' => 'Tour booking confirmed successfully',
                'booking_id' => $invoiceId,
                'invoice_id' => $invoiceId,
                'redirect_url' => root . 'invoice/tours/' . $invoiceId,
                'countdown' => true
            ]);
        } else {
            throw new Exception('Failed to save tour booking');
        }

    } catch (Exception $e) {
        // Trigger booking failed webhook
        triggerWebhook('tours/booking', 'tours.booking.failed', [
            'error_message' => $e->getMessage(),
            'booking_data' => $input ?? [],
            'user_id' => $_SESSION['user_data']['id'] ?? null,
            'timestamp' => date('Y-m-d H:i:s')
        ]);

        ob_clean();
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    ob_end_flush();
    exit;
});


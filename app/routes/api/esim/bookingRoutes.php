<?php
// ============================================================================
// eSIM BOOKING SUBMIT API
// ============================================================================

@$SECURE or die('Access Denied!');

require_once 'app/lib/jwt.php';

// Submit eSIM booking
$router->post('/api/esim/booking/submit', function () use ($SECURE, $db) {

    header('Content-Type: application/json');

    try {
        // Parse input (handles both JSON and form POST payloads)
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        if (!$input || empty($input['country']) || empty($input['selected_package'])) {
            throw new Exception('Invalid booking data');
        }

        // --------------------------------------------------
        // RESOLVE LOGGED-IN USER / AGENT FROM JWT TOKEN
        // --------------------------------------------------
        $userId = null;
        $userData = null;
        $isAgent = false;
        $agentCustomMarkup = false;
        $agentMarkupType = 'percentage';
        $agentMarkupValue = 0.0;

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
                        if (strtolower((string)($userData['role'] ?? '')) === 'agent') {
                            $isAgent = true;
                            if (($userData['apply_markup'] ?? 'global') === 'custom') {
                                $agentCustomMarkup = true;
                                $agentMarkupValue  = floatval($userData['markup_value'] ?? 0);
                                $agentMarkupType   = strtolower((string)($userData['markup_type'] ?? 'percentage'));
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // Silent fail
            }
        }

        $countryIso = strtoupper(trim((string) $input['country']));
        $selectedPackage = (array) $input['selected_package'];
        $guestDetails = (array) ($input['guest_details'] ?? []);
        $primaryGuest = (array) ($guestDetails['primary_guest'] ?? $input['primary_guest'] ?? $input['guest_details'] ?? []);
        $airaloOrder = (array) ($input['airalo_order'] ?? $input['order'] ?? []);

        if (empty($selectedPackage['id']) || empty($selectedPackage['title']) || !isset($selectedPackage['price'])) {
            throw new Exception('Selected package is incomplete');
        }

        if (empty($primaryGuest['first_name']) || empty($primaryGuest['last_name']) || empty($primaryGuest['email']) || empty($primaryGuest['phone'])) {
            throw new Exception('Guest details (first name, last name, email, phone) are required');
        }

        // Fetch eSIM/Airalo module status to verify enabled
        $airaloModule = $db->get('modules', '*', [
            'name' => 'airalo',
            'type' => 'esim',
            'status' => 1,
        ]);

        if (!$airaloModule) {
            throw new Exception('eSIM module is not enabled');
        }

        $moduleId = (int) $airaloModule['id'];

        // Verify country is active/enabled in airalo_countries
        $countryRow = $db->get('airalo_countries', ['iso', 'nicename', 'status'], [
            'iso' => $countryIso,
        ]);
        if (!$countryRow || (int) ($countryRow['status'] ?? 0) !== 1) {
            throw new Exception('Selected country is not available');
        }

        // Validate Quantity
        $orderQuantity = (int) ($airaloOrder['quantity'] ?? 1);
        if ($orderQuantity < 1 || $orderQuantity > 50) {
            throw new Exception('Quantity must be between 1 and 50');
        }

        $orderType = strtolower(trim((string) ($airaloOrder['type'] ?? 'sim')));
        if ($orderType === '') {
            $orderType = 'sim';
        }

        $topupTargetType = strtolower(trim((string) ($airaloOrder['topup_target_type'] ?? 'sim_iccid')));
        if (!in_array($topupTargetType, ['sim_iccid', 'sim_id'], true)) {
            $topupTargetType = 'sim_iccid';
        }

        $topupTarget = trim((string) ($airaloOrder['topup_target'] ?? ''));
        if ($orderType === 'topup' && $topupTarget === '') {
            throw new Exception('Topup target number or ID is required');
        }

        // Payment method validation
        $selectedPayment = trim((string) ($input['selected_payment'] ?? $input['payment_gateway'] ?? ''));
        if ($selectedPayment === '') {
            throw new Exception('Please select a payment method');
        }

        $paymentGateway = $db->get('payment_gateways', ['id', 'name', 'type', 'active', 'status'], [
            'id' => $selectedPayment,
            'status' => 1,
        ]);
        if (!$paymentGateway || (int) ($paymentGateway['active'] ?? 0) !== 1) {
            throw new Exception('Selected payment method is not available');
        }

        $termsAccepted = !empty($input['terms_accepted']) || !empty($guestDetails['terms_accepted']);
        if (!$termsAccepted) {
            throw new Exception('Please accept terms and conditions');
        }

        // PRICE INTEGRITY (price-trust workstream): re-derive the authoritative
        // sell price from Airalo's live catalog server-side; never trust the
        // client's selected_package.price. Reject if it can't be priced.
        require_once dirname(__DIR__, 4) . '/modules/esim/airalo/api.php';
        $esimModuleRow = $db->get('modules', '*', ['id' => (int) ($input['module_id'] ?? 0)])
            ?: $db->get('modules', '*', ['type' => 'esim', 'status' => '1']);
        $packageId = (string) ($selectedPackage['id'] ?? $selectedPackage['package_id'] ?? '');
        $authPrice = function_exists('airalo_authoritative_price')
            ? airalo_authoritative_price($db, (array) $esimModuleRow, $countryIso, $packageId)
            : null;
        if (!$authPrice || ($authPrice['price'] ?? 0) <= 0) {
            throw new Exception('This eSIM package is no longer available. Please reselect.');
        }
        $price = (float) $authPrice['price'];
        $basePrice = (float) $authPrice['base_price'];
        $selectedPackage['price'] = $price;
        $selectedPackage['base_price'] = $basePrice;

        // Package list (/api/esim/packages) already applies markup + currency conversion.
        // Use the same price the user saw — do not recalculate markup on submit.
        $displayCurrency = strtoupper(trim((string) ($selectedPackage['currency'] ?? '')));
        if ($displayCurrency === '') {
            $displayCurrency = requireAppDisplayCurrency($db, $input);
        } else {
            $inputCurrency = strtoupper(trim((string) ($input['currency'] ?? $input['display_currency'] ?? '')));
            if ($inputCurrency !== '' && $inputCurrency !== $displayCurrency) {
                throw new Exception('Currency mismatch with selected package. Please refresh packages and try again.');
            }
        }

        $currency = $displayCurrency;
        $baseCurrency = $displayCurrency;
        $conversionRate = 1.0;

        // Secure Promo Code / Coupon Validation
        $couponCodeStr = strtoupper(trim((string) ($input['coupon_code'] ?? $input['promo_code'] ?? '')));
        $couponDiscount = 0.0;
        $promoData = null;

        if (!empty($couponCodeStr)) {
            if ($couponCodeStr === 'ESIM10') {
                $discountPercent = 10.0;
                $couponDiscount = round($price * ($discountPercent / 100), 2);
            } else {
                // Look up in promo_codes table
                $promoData = $db->get('promo_codes', '*', ['code' => $couponCodeStr]);
                if (!$promoData) {
                    throw new Exception('Invalid coupon code');
                }

                // Enforce eligibility (targeting + per_user_limit + all standard
                // checks) via the shared validator before trusting $promoData. The
                // bespoke discount math below is left intact and only runs when the
                // coupon is eligible; an ineligible coupon yields ZERO discount.
                $pv = validatePromoCode(
                    $db,
                    $couponCodeStr,
                    (float) $price,
                    'esim',
                    (string) $displayCurrency,
                    [
                        'user_id'    => $userId ?? ($_SESSION['user_id'] ?? null),
                        'user_email' => (string) ($primaryGuest['email'] ?? ''),
                    ]
                );
                if (empty($pv['ok'])) {
                    throw new Exception((string) ($pv['message'] ?? 'This coupon code is not valid'));
                }

                if ((int)($promoData['status'] ?? 0) !== 1) {
                    throw new Exception('This coupon code is no longer active');
                }

                if ($promoData['module'] !== 'all' && $promoData['module'] !== 'esim') {
                    throw new Exception('This coupon code is not valid for eSIM bookings');
                }

                if (!empty($promoData['start_date']) && strtotime($promoData['start_date']) > time()) {
                    throw new Exception('This coupon code is not yet active');
                }

                if (!empty($promoData['end_date']) && strtotime($promoData['end_date']) < time()) {
                    throw new Exception('This coupon code has expired');
                }

                if (!empty($promoData['usage_limit']) && (int)$promoData['used_count'] >= (int)$promoData['usage_limit']) {
                    throw new Exception('This coupon code usage limit has been reached');
                }

                // Verify minimum order amount (convert if currencies differ)
                if (!empty($promoData['min_order_amount'])) {
                    $promoCurrency = strtoupper($promoData['currency'] ?? 'USD');
                    $minAmount = floatval($promoData['min_order_amount']);

                    if ($promoCurrency !== $displayCurrency && function_exists('CURRENCY_CONVERT')) {
                        $converted = CURRENCY_CONVERT($minAmount, $db, $promoCurrency, $displayCurrency);
                        $minAmount = $converted['price'];
                    }

                    if ($price < $minAmount) {
                        throw new Exception('Minimum order amount of ' . $displayCurrency . ' ' . number_format($minAmount, 2) . ' is required to use this coupon');
                    }
                }

                // Calculate discount
                $promoCurrency = strtoupper($promoData['currency'] ?? 'USD');
                if ($promoData['discount_type'] === 'percentage') {
                    $couponDiscount = round($price * (floatval($promoData['discount_value']) / 100), 2);
                    // Apply max discount cap if set
                    if (!empty($promoData['max_discount_amount'])) {
                        $maxCap = floatval($promoData['max_discount_amount']);
                        if ($promoCurrency !== $displayCurrency && function_exists('CURRENCY_CONVERT')) {
                            $converted = CURRENCY_CONVERT($maxCap, $db, $promoCurrency, $displayCurrency);
                            $maxCap = $converted['price'];
                        }
                        if ($couponDiscount > $maxCap) {
                            $couponDiscount = $maxCap;
                        }
                    }
                } else {
                    $fixedAmount = floatval($promoData['discount_value']);
                    if ($promoCurrency !== $displayCurrency && function_exists('CURRENCY_CONVERT')) {
                        $converted = CURRENCY_CONVERT($fixedAmount, $db, $promoCurrency, $displayCurrency);
                        $fixedAmount = $converted['price'];
                    }
                    $couponDiscount = min($fixedAmount, $price);
                }
            }
        }

        $totalPrice = $price - $couponDiscount;
        if ($totalPrice < 0) {
            $totalPrice = 0;
        }

        $displayFinalTotal = round($totalPrice, 2);

        // Earning / commission = markup amount minus coupon discount (price with markup - supplier net - coupon discount)
        $commission = round($price - $basePrice - $couponDiscount, 2);
        if ($commission < 0) {
            $commission = 0;
        }

        // B2B Agent Earning
        $agentEarning = $isAgent ? $commission : 0.0;

        // Prepare promo code JSON for database storage
        $promoCodeJson = null;
        if (!empty($couponCodeStr) && $couponDiscount > 0) {
            if ($promoData) {
                $promoCodeJson = json_encode([
                    'code' => $promoData['code'],
                    'discount_type' => $promoData['discount_type'],
                    'discount_value' => floatval($promoData['discount_value']),
                    'discount_amount' => $couponDiscount,
                    'max_discount_amount' => $promoData['max_discount_amount'] ? floatval($promoData['max_discount_amount']) : null,
                    'description' => $promoData['description'],
                    'module' => $promoData['module']
                ]);
            } else if ($couponCodeStr === 'ESIM10') {
                $promoCodeJson = json_encode([
                    'code' => 'ESIM10',
                    'discount_type' => 'percentage',
                    'discount_value' => 10.0,
                    'discount_amount' => $couponDiscount,
                    'max_discount_amount' => null,
                    'description' => '10% discount for eSIM',
                    'module' => 'esim'
                ]);
            }
        }

        $invoiceId = str_pad((string) rand(0, 99999999), 8, '0', STR_PAD_LEFT);

        if (!$userId) {
            // Check if user exists by email, otherwise auto-create guest user
            $existingUser = $db->get('users', '*', ['email' => $primaryGuest['email']]);
            if ($existingUser) {
                $userId = $existingUser['user_id'];
            } else {
                $userId = 'USR' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                $generatedPassword = bin2hex(random_bytes(4));
                $db->insert('users', [
                    'user_id' => $userId,
                    'first_name' => (string) ($primaryGuest['first_name'] ?? ''),
                    'last_name' => (string) ($primaryGuest['last_name'] ?? ''),
                    'email' => (string) ($primaryGuest['email'] ?? ''),
                    'password' => password_hash($generatedPassword, PASSWORD_DEFAULT),
                    'phone' => (string) ($primaryGuest['phone'] ?? ''),
                    'role' => 'user',
                    'status' => 'active',
                    'email_verified' => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        $bookingData = [
            'module' => [
                'id' => $moduleId,
                'name' => (string) ($airaloModule['name'] ?? 'airalo'),
                'type' => 'esim',
            ],
            'country' => [
                'iso' => $countryIso,
                'name' => (string) ($countryRow['nicename'] ?? $countryIso),
            ],
            'selected_package' => [
                'id' => (string) ($selectedPackage['id'] ?? ''),
                'title' => (string) ($selectedPackage['title'] ?? ''),
                'country' => (string) ($selectedPackage['country'] ?? $countryIso),
                'data_limit' => (string) ($selectedPackage['data_limit'] ?? ''),
                'duration' => (string) ($selectedPackage['duration'] ?? ''),
                'package_type' => (string) ($selectedPackage['package_type'] ?? 'local'),
                'currency' => $currency,
                'price' => round($price, 2),
            ],
            'guest_details' => $primaryGuest,
            'airalo_order' => [
                'package_id' => (string) ($selectedPackage['id'] ?? ''),
                'quantity' => $orderQuantity,
                'type' => $orderType,
                'topup_target_type' => $orderType === 'topup' ? $topupTargetType : '',
                'topup_target' => $orderType === 'topup' ? $topupTarget : '',
            ],
            'traveler_details' => [],
            'payment' => [
                'gateway_id' => (string) ($paymentGateway['id'] ?? $selectedPayment),
                'gateway_name' => (string) ($paymentGateway['name'] ?? ''),
                'gateway_type' => (string) ($paymentGateway['type'] ?? ''),
            ],
            'pricing' => [
                'subtotal' => round($price, 2),
                'base_price' => round($basePrice, 2),
                'commission' => $commission,
                'coupon_code' => $couponCodeStr,
                'coupon_discount' => round($couponDiscount, 2),
                'total' => round($totalPrice, 2),
                'currency' => $baseCurrency,
            ],
            'base_currency' => $baseCurrency,
            'display_currency' => $displayCurrency,
            'conversion_rate' => $conversionRate,
            'final_total_display' => $displayFinalTotal,
            'special_requests' => (string) ($input['special_requests'] ?? ''),
            'reservation_status' => 'pending_preparation',
            'created_from' => 'esim_checkout_api',
        ];

        // Insert booking into Medoo
        $db->insert('bookings', [
            'invoice_id' => $invoiceId,
            'language' => getCurrentLanguage(),
            'booking_status' => 'pending',
            'payment_status' => 'unpaid',
            'price_original' => round($basePrice, 2),
            'price_markup' => round($totalPrice, 2),
            'agent_earning' => round($agentEarning, 2),
            'commission' => $commission,
            'tax' => 0,
            'currency_markup' => $currency,
            'first_name' => (string) ($primaryGuest['first_name'] ?? ''),
            'last_name' => (string) ($primaryGuest['last_name'] ?? ''),
            'email' => (string) ($primaryGuest['email'] ?? ''),
            'phone' => (string) ($primaryGuest['phone'] ?? ''),
            'module_type' => 'esim',
            'module' => (string) ($airaloModule['name'] ?? 'airalo'),
            'booking_data' => json_encode($bookingData),
            'payment_gateway' => (string) ($paymentGateway['id'] ?? $selectedPayment),
            'special_requests' => (string) ($input['special_requests'] ?? ''),
            'user_id' => $userId,
            'created_at' => date('Y-m-d H:i:s'),
            'booking_date' => date('Y-m-d'),
            'promo_codes' => $promoCodeJson,
        ]);

        $bookingId = $db->id();
        if (!$bookingId) {
            throw new Exception('Failed to create booking');
        }

        // AGENT API — wallet settlement (no-op unless agent-API request).
        agent_api_settle_booking($db, 'esim', $bookingId, $invoiceId, (float) round($totalPrice, 2));

        // Record promo code usage if applicable (idempotent per invoice; bumps
        // used_count + writes the per-user ledger for per_user_limit enforcement).
        // Placed after the booking insert so $invoiceId and the finalized $userId
        // (guest may have just been auto-created above) are both in scope.
        if ($promoData && $couponDiscount > 0) {
            if (function_exists('recordPromoUsage')) {
                recordPromoUsage(
                    $db,
                    $promoData,
                    (string) $invoiceId,
                    ($userId ?? null) !== null ? (string) $userId : null,
                    (string) ($primaryGuest['email'] ?? ''),
                    (float) $couponDiscount,
                    'esim',
                    (string) $displayCurrency
                );
            }
        }

        // Notifications
        if (class_exists('NOTIFY')) {
            $customerData = [
                'email' => (string) ($primaryGuest['email'] ?? ''),
                'phone' => (string) ($primaryGuest['phone'] ?? ''),
                'first_name' => (string) ($primaryGuest['first_name'] ?? ''),
                'last_name' => (string) ($primaryGuest['last_name'] ?? ''),
                'country_code' => (string) ($primaryGuest['country_code'] ?? ''),
            ];

            $notifyData = [
                'invoice_id' => $invoiceId,
                'amount' => $displayFinalTotal,
                'currency' => $displayCurrency,
                'payment_status' => 'unpaid',
                'package_name' => (string) ($selectedPackage['title'] ?? 'eSIM Package'),
                'country' => (string) ($countryRow['nicename'] ?? $countryIso),
                'module_type' => 'eSIM',
            ];

            NOTIFY::booking('esim', $customerData, $notifyData);
        }

        // Let the (possibly guest) session that created this invoice view it —
        // otherwise enforceInvoiceAccess() bounces them to /login on their own
        // fresh invoice (see grantInvoiceSessionOwnership()).
        if (function_exists('grantInvoiceSessionOwnership')) {
            grantInvoiceSessionOwnership($invoiceId);
        }

        echo json_encode([
            'success' => true,
            'invoice_id' => $invoiceId,
            'amount' => $displayFinalTotal,
            'amount_base' => round($totalPrice, 2),
            'currency' => $displayCurrency,
            'base_currency' => $baseCurrency,
            'display_currency' => $displayCurrency,
            'conversion_rate' => $conversionRate,
            'redirect_url' => root . 'invoice/esim/' . $invoiceId . '?currency=' . urlencode($displayCurrency),
            'message' => 'Booking confirmed successfully. Proceed to payment.',
            'pricing' => [
                'subtotal' => round($price, 2),
                'coupon_code' => $couponCodeStr,
                'coupon_discount' => round($couponDiscount, 2),
                'total' => $displayFinalTotal,
                'currency' => $displayCurrency,
            ]
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ]);
        exit;
    }
});

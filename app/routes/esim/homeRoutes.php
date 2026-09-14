<?php

// app/routes/esim/homeRoutes.php
@$SECURE or die('Access Denied!');

require_once dirname(__DIR__, 3) . '/modules/esim/airalo/api.php';

$router->post('/esim/booking/submit', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['module_id']) || empty($input['country']) || empty($input['selected_package'])) {
            throw new Exception('Invalid booking data');
        }

        $moduleId = (int) $input['module_id'];
        $countryIso = strtoupper(trim((string) $input['country']));
        $module = $db->get('modules', '*', [
            'id' => $moduleId,
            'type' => 'esim',
            'status' => 1,
        ]);

        if (!$module) {
            throw new Exception('eSIM module not found');
        }

        $countryRow = $db->get('airalo_countries', ['iso', 'nicename', 'status'], [
            'iso' => $countryIso,
        ]);
        if (!$countryRow || (int) ($countryRow['status'] ?? 0) !== 1) {
            throw new Exception('Selected country is not available');
        }

        $selectedPackage = (array) $input['selected_package'];
        $guestDetails = (array) ($input['guest_details'] ?? []);
        $primaryGuest = (array) ($guestDetails['primary_guest'] ?? []);
        $airaloOrder = (array) ($input['airalo_order'] ?? []);

        if (empty($selectedPackage['id']) || empty($selectedPackage['title']) || !isset($selectedPackage['price'])) {
            throw new Exception('Selected package is incomplete');
        }

        if (empty($primaryGuest['first_name']) || empty($primaryGuest['last_name']) || empty($primaryGuest['email']) || empty($primaryGuest['phone'])) {
            throw new Exception('Guest details are required');
        }

        // Airalo order preparation requires package_id, quantity, and type.
        // For topups, we also capture the target SIM identifier for reservation prep.
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

        $selectedPayment = (string) ($input['selected_payment'] ?? '');
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

        $termsAccepted = !empty($input['terms_accepted']);
        if (!$termsAccepted) {
            throw new Exception('Please accept terms and conditions');
        }

        $price = (float) $selectedPackage['price'];
        if ($price < 0) {
            $price = 0;
        }

        // Supplier net price (before our markup).
        // Priority:
        // 1) explicit base_price from package payload
        // 2) reverse-calculate from commission settings
        // 3) fallback to marked-up price
        $basePrice = isset($selectedPackage['base_price']) ? (float) $selectedPackage['base_price'] : null;

        if ($basePrice === null) {
            $commissionType = strtolower((string) ($selectedPackage['commission_type'] ?? ''));
            $commissionValue = isset($selectedPackage['commission_value']) ? (float) $selectedPackage['commission_value'] : null;

            if ($commissionType === '' || $commissionValue === null) {
                $pkgType = strtolower((string) ($selectedPackage['package_type'] ?? ($input['package_type'] ?? 'all')));
                if (!in_array($pkgType, ['all', 'global', 'local'], true)) {
                    $pkgType = 'all';
                }

                $rule = $db->get('airalo_packages', ['commission_type', 'value'], [
                    'country' => $countryIso,
                    'package_type' => $pkgType,
                    'status' => 1,
                ]);
                if (!$rule && $pkgType !== 'all') {
                    $rule = $db->get('airalo_packages', ['commission_type', 'value'], [
                        'country' => $countryIso,
                        'package_type' => 'all',
                        'status' => 1,
                    ]);
                }

                $commissionType = strtolower((string) ($rule['commission_type'] ?? 'fixed'));
                $commissionValue = (float) ($rule['value'] ?? 0);
            }

            if ($commissionType === 'percentage') {
                $denominator = 1 + ($commissionValue / 100);
                $basePrice = $denominator > 0 ? ($price / $denominator) : $price;
            } else {
                $basePrice = $price - $commissionValue;
            }
        }

        if ($basePrice < 0) {
            $basePrice = 0;
        }
        if ($basePrice > $price) {
            $basePrice = $price;
        }

        // Secure Promo Code / Coupon Validation
        $couponCodeStr = strtoupper(trim((string) ($input['coupon_code'] ?? $input['promo_code'] ?? '')));
        $couponDiscount = 0.0;
        $promoData = null;
        $currency = (string) ($selectedPackage['currency'] ?? ($module['currency'] ?? 'USD'));

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
                    (string) $currency,
                    [
                        'user_id'    => $_SESSION['user_id'] ?? null,
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

                    if ($promoCurrency !== strtoupper($currency) && function_exists('CURRENCY_CONVERT')) {
                        $converted = CURRENCY_CONVERT($minAmount, $db, $promoCurrency, $currency);
                        $minAmount = $converted['price'];
                    }

                    if ($price < $minAmount) {
                        throw new Exception('Minimum order amount of ' . $currency . ' ' . number_format($minAmount, 2) . ' is required to use this coupon');
                    }
                }

                // Calculate discount
                $promoCurrency = strtoupper($promoData['currency'] ?? 'USD');
                if ($promoData['discount_type'] === 'percentage') {
                    $couponDiscount = round($price * (floatval($promoData['discount_value']) / 100), 2);
                    // Apply max discount cap if set
                    if (!empty($promoData['max_discount_amount'])) {
                        $maxCap = floatval($promoData['max_discount_amount']);
                        if ($promoCurrency !== strtoupper($currency) && function_exists('CURRENCY_CONVERT')) {
                            $converted = CURRENCY_CONVERT($maxCap, $db, $promoCurrency, $currency);
                            $maxCap = $converted['price'];
                        }
                        if ($couponDiscount > $maxCap) {
                            $couponDiscount = $maxCap;
                        }
                    }
                } else {
                    $fixedAmount = floatval($promoData['discount_value']);
                    if ($promoCurrency !== strtoupper($currency) && function_exists('CURRENCY_CONVERT')) {
                        $converted = CURRENCY_CONVERT($fixedAmount, $db, $promoCurrency, $currency);
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

        // Earning / commission = markup amount minus coupon discount (price with markup - supplier net - coupon discount)
        $commission = round($price - $basePrice - $couponDiscount, 2);
        if ($commission < 0) {
            $commission = 0;
        }

        $userId = (string)($_SESSION['user_id'] ?? '');
        $isAgent = false;
        if ($userId !== '') {
            $userData = $db->get('users', ['role'], ['user_id' => $userId]);
            $isAgent = is_array($userData) && strtolower((string)($userData['role'] ?? '')) === 'agent';
            if (!$isAgent) {
                $isAgent = strtolower((string)($_SESSION['user_role'] ?? '')) === 'agent';
            }
        }
        $agentEarning = $isAgent ? $commission : 0;

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

        $currency = (string) ($selectedPackage['currency'] ?? ($module['currency'] ?? 'USD'));
        $invoiceId = str_pad((string) rand(0, 99999999), 8, '0', STR_PAD_LEFT);

        $bookingData = [
            'module' => [
                'id' => $moduleId,
                'name' => (string) ($module['name'] ?? 'airalo'),
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
            // Kept for backward compatibility with any existing readers.
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
                'coupon_code' => (string) ($input['coupon_code'] ?? ''),
                'coupon_discount' => round($couponDiscount, 2),
                'total' => round($totalPrice, 2),
                'currency' => $currency,
            ],
            'special_requests' => (string) ($input['special_requests'] ?? ''),
            'reservation_status' => 'pending_preparation',
            'created_from' => 'esim_checkout',
        ];

        $db->insert('bookings', [
            'invoice_id' => $invoiceId,
            'language' => getCurrentLanguage(),
            'booking_status' => 'pending',
            'payment_status' => 'unpaid',
            'price_original' => round($basePrice, 2),
            'price_markup' => round($totalPrice, 2),
            'commission' => $commission,
            'agent_earning' => round($agentEarning, 2),
            'tax' => 0,
            'currency_markup' => $currency,
            'first_name' => (string) ($primaryGuest['first_name'] ?? ''),
            'last_name' => (string) ($primaryGuest['last_name'] ?? ''),
            'email' => (string) ($primaryGuest['email'] ?? ''),
            'phone' => (string) ($primaryGuest['phone'] ?? ''),
            'module_type' => 'esim',
            'module' => (string) ($module['name'] ?? 'airalo'),
            'booking_data' => json_encode($bookingData),
            'payment_gateway' => (string) ($paymentGateway['id'] ?? $selectedPayment),
            'special_requests' => (string) ($input['special_requests'] ?? ''),
            'user_id' => $_SESSION['user_id'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'booking_date' => date('Y-m-d'),
            'promo_codes' => $promoCodeJson,
        ]);

        $bookingId = $db->id();
        if (!$bookingId) {
            throw new Exception('Failed to create booking');
        }

        // Record promo code usage if applicable (idempotent per invoice; bumps
        // used_count + writes the per-user ledger for per_user_limit enforcement).
        if ($promoData && $couponDiscount > 0) {
            if (function_exists('recordPromoUsage')) {
                recordPromoUsage(
                    $db,
                    $promoData,
                    (string) $invoiceId,
                    ($_SESSION['user_id'] ?? null) !== null ? (string) $_SESSION['user_id'] : null,
                    (string) ($primaryGuest['email'] ?? ''),
                    (float) $couponDiscount,
                    'esim',
                    (string) $currency
                );
            }
        }

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
                'amount' => round($totalPrice, 2),
                'currency' => $currency,
                'payment_status' => 'unpaid',
                'package_name' => (string) ($selectedPackage['title'] ?? 'eSIM Package'),
                'country' => (string) ($countryRow['nicename'] ?? $countryIso),
                'module_type' => 'eSIM',
            ];

            NOTIFY::booking('esim', $customerData, $notifyData);
        }

        echo json_encode([
            'success' => true,
            'invoice_id' => $invoiceId,
            'redirect_url' => root . 'invoice/esim/' . $invoiceId,
            'message' => 'Booking confirmed. Proceed to payment.',
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
// AJAX endpoint to fetch packages as JSON
$router->get('/esim/(\d+)/([a-z]{2})/([a-z]+)/packages/?', function ($moduleId, $country, $type) use ($SECURE, $db) {
    header('Content-Type: application/json');

    $moduleId = (int) $moduleId;
    $country = strtolower(trim((string) $country));
    $type = strtolower(trim((string) $type));

    $module = $db->get('modules', '*', [
        'id' => $moduleId,
        'type' => 'esim',
    ]);

    if (!$module) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Module not found']);
        exit;
    }

    $countryRow = $db->get('airalo_countries', ['iso', 'nicename', 'status'], [
        'iso' => strtoupper($country),
    ]);

    if (!$countryRow || (int) ($countryRow['status'] ?? 0) !== 1) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Country not found']);
        exit;
    }

    $environment = (!empty($module['dev_mode']) && (string) $module['dev_mode'] === '1') ? 'sandbox' : 'production';
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = 24;
    $notice = '';

    $flattenPackages = static function ($responseData, $fallbackCountryLabel) {
        $countryItems = $responseData['data'] ?? [];
        $flat = [];
        foreach ((array) $countryItems as $countryItem) {
            $countryTitle = (string) ($countryItem['title'] ?? $fallbackCountryLabel);
            foreach ((array) ($countryItem['operators'] ?? []) as $operator) {
                $opType = strtolower((string) ($operator['type'] ?? 'local'));
                $coverageIsos = [];
                foreach ((array) ($operator['countries'] ?? []) as $c) {
                    if (!empty($c['code'])) {
                        $coverageIsos[] = strtoupper((string) $c['code']);
                    }
                }
                foreach ((array) ($operator['packages'] ?? []) as $pkg) {
                    $pkg['_country'] = $countryTitle;
                    $pkg['_op_type'] = $opType;
                    $pkg['_coverage_isos'] = $coverageIsos;
                    $flat[] = $pkg;
                }
            }
        }
        return $flat;
    };

    try {
        // Helper: fetch ALL global packages and filter those covering $country
        $fetchGlobalForCountry = function () use ($db, $environment, $country) {
            $res = _airalo_request_with_token($db, 'GET', '/v2/packages', [
                'env'   => $environment,
                'query' => ['limit' => 200, 'page' => 1, 'filter[type]' => 'global'],
                'timeout' => 60,
            ]);

            if (empty($res['ok']) || empty($res['data']['data'])) {
                return $res; // return as-is so caller can detect failure
            }

            $targetCountry = strtoupper($country);
            $filtered = [];
            foreach ((array) ($res['data']['data'] ?? []) as $cItem) {
                if (!isset($cItem['operators'])) {
                    continue;
                }
                foreach ((array) $cItem['operators'] as $op) {
                    if (!isset($op['countries'])) {
                        continue;
                    }
                    foreach ((array) $op['countries'] as $c) {
                        $code = $c['code'] ?? $c['country_code'] ?? '';
                        if ($code !== '' && strcasecmp($code, $targetCountry) === 0) {
                            $filtered[] = $cItem;
                            break 2;
                        }
                    }
                }
            }

            return [
                'ok'     => true,
                'status' => $res['status'],
                'data'   => ['data' => $filtered],
                'error'  => null,
            ];
        };

        if ($type === '' || $type === 'all') {
            // Fetch local packages for this country
            $resLocal = _airalo_request_with_token($db, 'GET', '/v2/packages', [
                'env'   => $environment,
                'query' => [
                    'limit'           => $limit,
                    'page'            => $page,
                    'filter[country]' => strtoupper($country),
                    'filter[type]'    => 'local',
                ],
                'timeout' => 45,
            ]);

            // Fetch global packages that cover this country
            $resGlobal = $fetchGlobalForCountry();

            $combinedData = [];
            if (!empty($resLocal['ok']) && !empty($resLocal['data']['data'])) {
                $combinedData = array_merge($combinedData, (array) $resLocal['data']['data']);
            }
            if (!empty($resGlobal['ok']) && !empty($resGlobal['data']['data'])) {
                $combinedData = array_merge($combinedData, (array) $resGlobal['data']['data']);
            }

            $response = [
                'ok'   => true,
                'data' => ['data' => $combinedData],
            ];

            if (empty($combinedData) && empty($resLocal['ok']) && empty($resGlobal['ok'])) {
                $response = $resLocal;
            }

        } elseif ($type === 'global') {
            // Only global packages that cover this country
            $response = $fetchGlobalForCountry();

        } else {
            $response = _airalo_request_with_token($db, 'GET', '/v2/packages', [
                'env'   => $environment,
                'query' => [
                    'limit'           => $limit,
                    'page'            => $page,
                    'filter[country]' => strtoupper($country),
                    'filter[type]'    => $type,
                ],
                'timeout' => 45,
            ]);
        }

        if (empty($response['ok'])) {
            $errMsg = $response['error'] ?? ('API error: HTTP ' . ($response['status'] ?? '?'));
            echo json_encode(['success' => false, 'message' => $errMsg]);
            exit;
        }

        $rawPackages = $flattenPackages((array) ($response['data'] ?? []), strtoupper($country));

        if (empty($rawPackages)) {
            echo json_encode(['success' => true, 'packages' => [], 'message' => 'No packages found for this country and type.']);
            exit;
        }

        $extractPrice = static function ($pkg) {
            foreach (['price', 'net_price', 'retail_price', 'sale_price', 'amount'] as $k) {
                if (isset($pkg[$k]) && is_numeric($pkg[$k]) && (float) $pkg[$k] > 0) {
                    return (float) $pkg[$k];
                }
            }
            return 0.0;
        };

        $extractDuration = static function ($pkg) {
            foreach (['day', 'validity', 'duration', 'duration_days'] as $k) {
                if (!empty($pkg[$k])) {
                    if (is_numeric($pkg[$k])) {
                        $d = (int) $pkg[$k];
                        return $d . ' day' . ($d === 1 ? '' : 's');
                    }
                    return (string) $pkg[$k];
                }
            }
            return 'N/A';
        };

        $extractData = static function ($pkg) {
            foreach (['data', 'data_limit', 'gb'] as $k) {
                if (!empty($pkg[$k])) {
                    return (string) $pkg[$k];
                }
            }
            return 'N/A';
        };

        $localRules = $db->select('airalo_packages', '*', [
            'country' => strtoupper($country),
            'status' => 1,
        ]);

        $rulesByType = [];
        foreach ((array) $localRules as $rule) {
            $rt = strtolower((string) ($rule['package_type'] ?? 'all'));
            if (in_array($rt, ['all', 'global', 'local'], true) && !isset($rulesByType[$rt])) {
                $rulesByType[$rt] = $rule;
            }
        }

        $expandedPackages = [];
        foreach ((array) $rawPackages as $pkg) {
            $basePrice = $extractPrice($pkg);
            $pkgType = (string) ($pkg['_op_type'] ?? 'local');
            $rule = $rulesByType[$pkgType] ?? $rulesByType['all'] ?? ['commission_type' => 'fixed', 'value' => 1];
            $value = (float) ($rule['value'] ?? 1);
            $commType = strtolower((string) ($rule['commission_type'] ?? 'fixed'));

            $markupAmount = $commType === 'percentage'
                ? ($basePrice * $value / 100)
                : $value;
            $finalPrice = $basePrice + $markupAmount;

            $expandedPackages[] = [
                'id' => (string) ($pkg['id'] ?? uniqid('pkg_', true)),
                'title' => (string) ($pkg['title'] ?? 'Package'),
                'country' => (string) ($pkg['_country'] ?? strtoupper($country)),
                'data_limit' => $extractData($pkg),
                'duration' => $extractDuration($pkg),
                'base_price' => round(max(0, $basePrice), 2),
                'commission' => round(max(0, $markupAmount), 2),
                'price' => round(max(0, $finalPrice), 2),
                'currency' => (string) ($module['currency'] ?? 'USD'),
                'package_type' => $pkgType,
                'commission_type' => $commType,
                'commission_value' => $value,
            ];
        }

        echo json_encode([
            'success' => true,
            'packages' => $expandedPackages,
            'message' => $notice,
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
});

// eSIM Landing Page
$router->get('/esim', function () use ($SECURE, $db) {
    $title = 'eSIM Booking';
    $description = 'Browse eSIM packages';
    $header = true;
    $footer = true;

    require_once views . 'includes/header.php';
    require_once views . 'modules/esim/index.php';
    require_once views . 'includes/footer.php';
});

// Page load route (returns HTML skeleton instantly)
$router->get('/esim/(\d+)/([a-z]{2})/([a-z]+)/?', function ($moduleId, $country, $type) use ($SECURE, $db) {
    $moduleId = (int) $moduleId;
    $country = strtolower(trim((string) $country));
    $type = strtolower(trim((string) $type));

    $module = $db->get('modules', '*', [
        'id' => $moduleId,
        'type' => 'esim',
    ]);

    if (!$module) {
        header('Location: ' . root);
        exit;
    }

    // Reject countries the admin has disabled in airalo_countries.
    $countryRow = $db->get('airalo_countries', ['iso', 'nicename', 'status'], [
        'iso' => strtoupper($country),
    ]);
    if (!$countryRow || (int) ($countryRow['status'] ?? 0) !== 1) {
        header('Location: ' . root);
        exit;
    }

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = 24;

    $countries = $db->select('countries', ['iso', 'nicename', 'phonecode'], [
        'status' => 'active',
        'ORDER' => ['nicename' => 'ASC']
    ]);

    $paymentGateways = $db->select('payment_gateways', '*', [
        'status' => '1',
        'ORDER' => ['order' => 'ASC']
    ]);

    if (empty($paymentGateways)) {
        $paymentGateways = [];
    }

    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $paymentGateways = array_filter($paymentGateways, function ($gateway) {
            if (!empty($gateway['type']) && $gateway['type'] === 'internal_wallet') {
                return false;
            }
            return stripos((string) ($gateway['name'] ?? ''), 'wallet') === false;
        });
    }

    // Get module info for the view
    $countryName = $countryRow['nicename'] ?? strtoupper($country);
    $currency = $module['currency'] ?? 'USD';
    
    $title = 'eSIM Booking';
    $description = 'Browse eSIM packages';
    $header = true;
    $footer = true;

    require_once views . 'includes/header.php';
    require_once views . 'modules/esim/booking/index.php';
    require_once views . 'includes/footer.php';
});

// Legacy - kept for reference but not used
if (false) {
    $baseQuery = [
        'limit' => 24,
        'page' => 1,
    ];

    $requestVariants = [];
    if ($country !== '') {
        $withCountry = $baseQuery;
        $withCountry['filter[country]'] = strtoupper($country);
        if ($type !== '' && $type !== 'all') {
            $withCountry['filter[type]'] = $type;
        }
        $requestVariants[] = $withCountry;
    }

    if ($type === 'global') {
        $globalOnly = $baseQuery;
        $globalOnly['filter[type]'] = 'global';
        $requestVariants[] = $globalOnly;
    }

    $countryOnly = $baseQuery;
    $countryOnly['filter[country]'] = strtoupper($country);
    $requestVariants[] = $countryOnly;

    if ($type !== '' && $type !== 'all') {
        $typeOnly = $baseQuery;
        $typeOnly['filter[type]'] = $type;
        $requestVariants[] = $typeOnly;
    }

    $requestVariants[] = $baseQuery;
    $requestVariants = array_values(array_unique(array_map('serialize', $requestVariants)));
    $requestVariants = array_map('unserialize', $requestVariants);

    $packagesRes = ['ok' => false, 'data' => []];
    foreach ($requestVariants as $queryParams) {
        $attempt = _airalo_request_with_token($db, 'GET', '/v2/packages', [
            'env' => $environment,
            'query' => $queryParams,
            'accept_language' => $language,
            'timeout' => 45,
        ]);

        if (!empty($attempt['ok']) && !empty($attempt['data']['data'])) {
            $packagesRes = $attempt;
            break;
        }

        if (!empty($attempt['ok']) && empty($packagesRes['ok'])) {
            $packagesRes = $attempt;
        }
    }

    $packages = [];
    $pagination = [
        'current_page' => $page,
        'total_pages' => 1,
        'per_page' => $limit,
        'total' => 0,
    ];

    if (!empty($packagesRes['ok'])) {
        $packages = $packagesRes['data']['data'] ?? [];
        $meta = $packagesRes['data']['meta'] ?? [];
        $pagination = [
            'current_page' => $meta['current_page'] ?? $page,
            'total_pages' => $meta['total_pages'] ?? 1,
            'per_page' => $meta['per_page'] ?? $limit,
            'total' => $meta['total'] ?? count($packages),
        ];
    }

    // Build display packages by combining each Airalo package with admin pricing rules.
    $countryIso = strtoupper($country);
    $rules = $db->select('airalo_packages', '*', [
        'country' => $countryIso,
        'status' => 1,
        'package_type' => ['all', 'global', 'local'],
        'ORDER' => ['id' => 'ASC'],
    ]);

    $rulesByType = [];
    foreach ((array) $rules as $rule) {
        $ruleType = strtolower((string) ($rule['package_type'] ?? 'all'));
        if (!in_array($ruleType, ['all', 'global', 'local'], true)) {
            continue;
        }
        if (!isset($rulesByType[$ruleType])) {
            $rulesByType[$ruleType] = $rule;
        }
    }

    // Fallback defaults if a country is missing one of the 3 rules.
    foreach (['all', 'global', 'local'] as $ruleType) {
        if (!isset($rulesByType[$ruleType])) {
            $rulesByType[$ruleType] = [
                'package_type' => $ruleType,
                'commission_type' => 'fixed',
                'value' => 1,
                'status' => 1,
            ];
        }
    }

    $parsePrice = static function ($pkg) {
        $candidates = [
            $pkg['price'] ?? null,
            $pkg['retail_price'] ?? null,
            $pkg['sale_price'] ?? null,
            $pkg['amount'] ?? null,
            $pkg['net_price'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            if (is_numeric($candidate)) {
                $value = (float) $candidate;
                if ($value > 0) {
                    return $value;
                }
            }
            if (is_string($candidate) && preg_match('/-?\d+(?:\.\d+)?/', $candidate, $m)) {
                $value = (float) $m[0];
                if ($value > 0) {
                    return $value;
                }
            }
        }

        return 0.0;
    };

    $extractDuration = static function ($pkg) {
        $candidates = [
            $pkg['validity'] ?? null,
            $pkg['duration'] ?? null,
            $pkg['duration_days'] ?? null,
        ];
        foreach ($candidates as $c) {
            if (!empty($c)) {
                return (string) $c;
            }
        }
        return 'N/A';
    };

    $extractData = static function ($pkg) {
        $candidates = [
            $pkg['data_limit'] ?? null,
            $pkg['data'] ?? null,
            $pkg['gb'] ?? null,
        ];
        foreach ($candidates as $c) {
            if (!empty($c)) {
                return (string) $c;
            }
        }
        return 'N/A';
    };

    $expandedPackages = [];
    foreach ((array) $packages as $pkg) {
        $sourceId = (string) ($pkg['id'] ?? $pkg['package_id'] ?? uniqid('pkg_', true));
        $title = (string) ($pkg['title'] ?? $pkg['short_title'] ?? 'eSIM Package');
        $duration = $extractDuration($pkg);
        $dataLimit = $extractData($pkg);
        $currency = (string) ($pkg['currency'] ?? 'USD');
        $basePrice = $parsePrice($pkg);

        $variantTypes = $type === 'all' ? ['all', 'global', 'local'] : [in_array($type, ['global', 'local'], true) ? $type : 'all'];

        foreach ($variantTypes as $variantType) {
            $rule = $rulesByType[$variantType] ?? $rulesByType['all'];
            $commissionType = strtolower((string) ($rule['commission_type'] ?? 'fixed'));
            $commissionValue = (float) ($rule['value'] ?? 0);

            $finalPrice = $basePrice;
            if ($commissionType === 'percentage') {
                $finalPrice += ($basePrice * $commissionValue / 100);
            } else {
                $finalPrice += $commissionValue;
            }

            if ($finalPrice < 0) {
                $finalPrice = 0;
            }

            $expandedPackages[] = [
                'id' => $sourceId . '|' . $variantType,
                'source_id' => $sourceId,
                'title' => $title . ' - ' . strtoupper($variantType),
                'country' => (string) ($pkg['country'] ?? $countryIso),
                'data_limit' => $dataLimit,
                'duration' => $duration,
                'base_price' => $basePrice,
                'price' => round($finalPrice, 2),
                'currency' => $currency,
                'package_type' => $variantType,
                'commission_type' => $commissionType,
                'commission_value' => $commissionValue,
            ];
        }
    }

    $packages = $expandedPackages;
    $pagination['total'] = count($packages);
    $pagination['per_page'] = count($packages);
    $pagination['total_pages'] = 1;

    $title = 'eSIM Booking';
    $description = 'Browse eSIM packages';
    $header = true;
    $footer = true;

    require_once views . 'includes/header.php';
    require_once views . 'modules/esim/booking/index.php';
    require_once views . 'includes/footer.php';
}
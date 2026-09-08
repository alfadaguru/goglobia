<?php
// ============================================================================
// FILE: app/routes/api/bus/bookingRoutes.php
// BUS BOOKING — MOBILE APP JSON API (mirrors app/routes/api/cars/bookingRoutes.php)
// Fully self-contained — does not depend on or modify the web checkout at
// app/routes/bus/bookingRoutes.php. Uses its own URLs so nothing there is
// touched or overwritten:
//   POST /api/bus/booking/draft         → validate + server-price + cache draft
//   GET  /api/bus/booking/draft/{hash}  → read cached draft (live re-priced)
//   POST /api/bus/booking/app-submit    → create booking (tax/promo/agent/notify)
// ============================================================================
@$SECURE or die('Access Denied!');

if (!function_exists('busApiApplyMarkup')) {
    // A NON-ZERO OPERATOR markup_b2c OVERRIDES THE MODULE-LEVEL markup_b2c —
    // MIRRORS app/routes/api/bus/listingRoutes.php'S PRICING LOGIC
    function busApiApplyMarkup($db, $price, $operatorId = 0) {
        $price = (float)$price;
        $module = $db->get('modules', ['markup_b2c', 'markup_type_b2c'], ['type' => 'bus', 'name' => 'bus']);
        $markup     = (float)($module['markup_b2c'] ?? 0);
        $markupType = $module['markup_type_b2c'] ?? 'percentage';

        if ($operatorId) {
            $op = $db->get('bus_operators', ['markup_b2c', 'markup_type_b2c'], ['id' => (int)$operatorId]);
            if ($op && (float)($op['markup_b2c'] ?? 0) > 0) {
                $markup     = (float)$op['markup_b2c'];
                $markupType = $op['markup_type_b2c'] ?? 'percentage';
            }
        }

        if ($markup <= 0) return round($price, 2);
        return round($markupType === 'fixed' ? $price + $markup : $price * (1 + $markup / 100), 2);
    }
}

if (!function_exists('busApiResolveDisplayCurrency')) {
    /** Match listing currency resolution — mobile must send currency, else trip/session fallback. */
    function busApiResolveDisplayCurrency(array $input, array $trip = []): string
    {
        $code = strtoupper(trim((string)(
            $input['currency']
            ?? $input['display_currency']
            ?? $trip['currency']
            ?? $_SESSION['app_currency']
            ?? 'USD'
        )));
        return $code !== '' ? $code : 'USD';
    }
}

if (!function_exists('busApiRouteBaseCurrency')) {
    function busApiRouteBaseCurrency($db, int $routeId, string $moduleCurrency = 'USD'): string
    {
        if ($routeId <= 0) {
            return strtoupper(trim($moduleCurrency ?: 'USD'));
        }
        $route = $db->get('bus_routes', [
            '[>]bus' => ['bus_id' => 'id'],
        ], ['bus.currency'], ['bus_routes.id' => $routeId]);
        return strtoupper(trim((string)($route['currency'] ?? $moduleCurrency ?: 'USD')));
    }
}

if (!function_exists('busApiGetRouteDisplayPrices')) {
    /** Per-route marked-up adult/child fares in display currency (mirrors listing). */
    function busApiGetRouteDisplayPrices($db, int $routeId, string $dateRaw, string $displayCurrency): ?array
    {
        $dateObj = DateTime::createFromFormat('d-m-Y', $dateRaw);
        if ($routeId <= 0 || !$dateObj) {
            return null;
        }

        $module = $db->get('modules', ['currency'], ['type' => 'bus', 'name' => 'bus']);
        $moduleCurrency = $module['currency'] ?? 'USD';

        $route = $db->get('bus_routes', [
            '[>]bus' => ['bus_id' => 'id'],
        ], [
            'bus_routes.adult_price', 'bus_routes.child_price', 'bus_routes.base_price',
            'bus.operator_id', 'bus.currency',
        ], ['bus_routes.id' => $routeId, 'bus_routes.status' => '1']);

        $cal = $db->get('bus_routes_calendar', ['price'], [
            'route_id' => $routeId,
            'date'     => $dateObj->format('Y-m-d'),
        ]);
        if (!$route || !$cal) {
            return null;
        }

        $operatorId = (int)($route['operator_id'] ?? 0);
        $rawAdult = (float)($route['adult_price'] ?: ($cal['price'] ?? $route['base_price']));
        $rawChild = (float)($route['child_price'] ?: 0);
        $baseCurrency = strtoupper(trim((string)($route['currency'] ?: $moduleCurrency)));
        $markedAdult = busApiApplyMarkup($db, $rawAdult, $operatorId);
        $markedChild = busApiApplyMarkup($db, $rawChild, $operatorId);
        $displayAdult = convertCurrencyAmount($db, $markedAdult, $baseCurrency, $displayCurrency);
        $displayChild = convertCurrencyAmount($db, $markedChild, $baseCurrency, $displayCurrency);

        return [
            'base_currency'      => $baseCurrency,
            'currency'           => $displayCurrency,
            'display_currency'   => $displayCurrency,
            'price'              => $displayAdult,
            'adult_price'        => $displayAdult,
            'child_price'        => $displayChild,
            'adult_price_base'   => $markedAdult,
            'child_price_base'   => $markedChild,
        ];
    }
}

if (!function_exists('busApiEnrichDraftDisplayPrices')) {
    /** Sync trip / journeys[].trip unit fares to the active display currency for mobile UI. */
    function busApiEnrichDraftDisplayPrices($db, array &$draft, array $journeys, string $displayCurrency): void
    {
        $enrichedJourneys = [];
        foreach ($journeys as $journey) {
            if (!is_array($journey)) {
                continue;
            }
            $routeId = (int)($journey['route_id'] ?? 0);
            $dateRaw = (string)($journey['date'] ?? '');
            $legPrices = busApiGetRouteDisplayPrices($db, $routeId, $dateRaw, $displayCurrency);
            if ($legPrices) {
                $journey['trip'] = array_merge(is_array($journey['trip'] ?? null) ? $journey['trip'] : [], $legPrices);
            } elseif (is_array($journey['trip'] ?? null)) {
                $journey['trip']['currency'] = $displayCurrency;
                $journey['trip']['display_currency'] = $displayCurrency;
            }
            $enrichedJourneys[] = $journey;
        }

        if ($enrichedJourneys) {
            $draft['journeys'] = $enrichedJourneys;
            foreach ($enrichedJourneys as $journey) {
                if (($journey['type'] ?? 'outbound') === 'outbound' && !empty($journey['trip'])) {
                    $draft['trip'] = $journey['trip'];
                    break;
                }
            }
            if (empty($draft['trip']) && !empty($enrichedJourneys[0]['trip'])) {
                $draft['trip'] = $enrichedJourneys[0]['trip'];
            }
        } elseif (is_array($draft['trip'] ?? null)) {
            $routeId = (int)($draft['route_id'] ?? 0);
            $dateRaw = (string)($draft['date'] ?? '');
            $legPrices = busApiGetRouteDisplayPrices($db, $routeId, $dateRaw, $displayCurrency);
            if ($legPrices) {
                $draft['trip'] = array_merge($draft['trip'], $legPrices);
            } else {
                $draft['trip']['currency'] = $displayCurrency;
                $draft['trip']['display_currency'] = $displayCurrency;
            }
        }
    }
}

if (!function_exists('busApiNormalizeJourneys')) {
    // ACCEPTS EITHER A FLAT {route_id, date} PAYLOAD OR A journeys[] ARRAY (RETURN TRIPS)
    function busApiNormalizeJourneys(array $input) {
        $journeys = $input['journeys'] ?? [];
        if (!$journeys) {
            $journeys = [[
                'type'     => 'outbound',
                'route_id' => (int)($input['route_id'] ?? 0),
                'date'     => $input['date'] ?? '',
            ]];
        }
        if (($input['trip_type'] ?? 'oneway') === 'return' && count($journeys) !== 2) {
            throw new Exception('Both outbound and return buses are required');
        }
        return $journeys;
    }
}

if (!function_exists('busApiPriceJourneys')) {
    // RE-DERIVES base + markup TOTALS FROM THE DB FOR A journeys[] ARRAY — NEVER
    // TRUSTS A CLIENT-SUPPLIED PRICE. THROWS IF A LEG IS INVALID OR SOLD OUT.
    function busApiPriceJourneys($db, array $journeys, int $adults, int $children) {
        $paxCount    = max(1, $adults + $children);
        $baseTotal   = 0.0;
        $markupTotal = 0.0;
        foreach ($journeys as $journey) {
            $routeId = (int)($journey['route_id'] ?? 0);
            $dateObj = DateTime::createFromFormat('d-m-Y', (string)($journey['date'] ?? ''));
            if (!$routeId || !$dateObj) throw new Exception('Invalid journey details');

            $route = $db->get('bus_routes', [
                '[>]bus' => ['bus_id' => 'id'],
            ], [
                'bus_routes.adult_price', 'bus_routes.child_price', 'bus_routes.base_price',
                'bus.operator_id',
            ], ['bus_routes.id' => $routeId, 'bus_routes.status' => '1']);
            $cal = $db->get('bus_routes_calendar', ['price', 'seats_available'], [
                'route_id' => $routeId,
                'date'     => $dateObj->format('Y-m-d'),
            ]);
            if (!$route || !$cal || (int)$cal['seats_available'] < $paxCount) {
                throw new Exception('One of the selected buses no longer has enough seats');
            }

            $operatorId = (int)($route['operator_id'] ?? 0);
            $rawAdult   = (float)($route['adult_price'] ?: ($cal['price'] ?? $route['base_price']));
            $rawChild   = (float)($route['child_price'] ?: 0);
            $baseTotal   += ($adults * $rawAdult) + ($children * $rawChild);
            $markupTotal += ($adults * busApiApplyMarkup($db, $rawAdult, $operatorId)) + ($children * busApiApplyMarkup($db, $rawChild, $operatorId));
        }
        return ['base_total' => round($baseTotal, 2), 'markup_total' => round($markupTotal, 2)];
    }
}

// ---------------------------------------------------------------- POST: SAVE DRAFT (VALIDATED + PRICED)
$router->post('/api/bus/booking/draft', function () use ($SECURE, $db) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) $input = $_POST;
        if (!is_array($input) || (empty($input['route_id']) && empty($input['journeys']))) {
            throw new Exception('Invalid booking data');
        }

        $adults   = max(1, (int)($input['adults'] ?? 1));
        $children = max(0, (int)($input['children'] ?? 0));
        $journeys = busApiNormalizeJourneys($input);

        // VALIDATE + PRICE FROM THE DB (IGNORES ANY CLIENT-SUPPLIED PRICE)
        $priced = busApiPriceJourneys($db, $journeys, $adults, $children);
        $trip = $input['trip'] ?? [];
        $busModule = $db->get('modules', ['currency'], ['type' => 'bus', 'name' => 'bus']);
        $moduleCurrency = $busModule['currency'] ?? 'USD';
        $firstRouteId = (int)($journeys[0]['route_id'] ?? $input['route_id'] ?? 0);
        $routeBaseCurrency = busApiRouteBaseCurrency($db, $firstRouteId, $moduleCurrency);
        $baseCurrency = resolveBaseCurrency($db,
            $input['base_currency'] ?? null,
            $trip['base_currency'] ?? null,
            $routeBaseCurrency,
            $moduleCurrency
        );
        $displayCurrency = busApiResolveDisplayCurrency($input, is_array($trip) ? $trip : []);
        $validCurrency = $db->get('currencies', 'name', ['name' => $displayCurrency, 'status' => 1]);
        if (!$validCurrency) {
            throw new Exception('Unsupported currency: ' . $displayCurrency);
        }

        $totalBase = $priced['markup_total'];
        $taxInfo = calculateTax($totalBase, 'bus', $db);
        $taxAmountBase = (float)($taxInfo['tax_amount'] ?? 0);
        $finalTotalBase = round($totalBase + $taxAmountBase, 2);
        $conversionRate = getCurrencyConversionRate($db, $baseCurrency, $displayCurrency);
        $totalDisplay = convertCurrencyAmount($db, $totalBase, $baseCurrency, $displayCurrency);
        $taxAmountDisplay = convertCurrencyAmount($db, $taxAmountBase, $baseCurrency, $displayCurrency);
        $finalTotalDisplay = convertCurrencyAmount($db, $finalTotalBase, $baseCurrency, $displayCurrency);

        $input['base_currency'] = $baseCurrency;
        $input['display_currency'] = $displayCurrency;
        $input['currency'] = $displayCurrency;
        $input['conversion_rate'] = $conversionRate;
        $input['total'] = $totalBase;
        $input['tax_amount'] = $taxAmountBase;
        $input['final_total'] = $finalTotalBase;
        $input['total_display'] = $totalDisplay;
        $input['tax_amount_display'] = $taxAmountDisplay;
        $input['final_total_display'] = $finalTotalDisplay;
        busApiEnrichDraftDisplayPrices($db, $input, $journeys, $displayCurrency);

        $hash = bin2hex(random_bytes(8));
        $db->insert('logs_bookings', [
            'hash'       => $hash,
            'data'       => json_encode($input),
            'user_id'    => $_SESSION['user_id'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        echo json_encode([
            'success'            => true,
            'hash'               => $hash,
            'total'              => $finalTotalDisplay,
            'amount_base'        => $finalTotalBase,
            'subtotal'           => $totalDisplay,
            'subtotal_base'      => $totalBase,
            'tax_amount'         => $taxAmountDisplay,
            'tax_amount_base'    => $taxAmountBase,
            'currency'           => $displayCurrency,
            'base_currency'      => $baseCurrency,
            'display_currency'   => $displayCurrency,
            'conversion_rate'    => $conversionRate,
            'booking_data'       => $input,
        ]);
        exit(0);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit(0);
    }
});

// ---------------------------------------------------------------- GET: DRAFT (LIVE RE-PRICED)
$router->get('/api/bus/booking/draft/([a-f0-9]{16})', function ($hash) use ($SECURE, $db) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    try {
        $row = $db->get('logs_bookings', ['hash', 'data', 'created_at'], ['hash' => $hash]);
        if (!$row || empty($row['data'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Booking draft not found or expired']);
            exit(0);
        }

        $draft = json_decode($row['data'], true);
        if (!is_array($draft)) throw new Exception('Invalid booking draft data');

        $trip = $draft['trip'] ?? [];
        $busModule = $db->get('modules', ['currency'], ['type' => 'bus', 'name' => 'bus']);
        $moduleCurrency = $busModule['currency'] ?? 'USD';
        $journeys = busApiNormalizeJourneys($draft);
        $firstRouteId = (int)($journeys[0]['route_id'] ?? $draft['route_id'] ?? 0);
        $routeBaseCurrency = busApiRouteBaseCurrency($db, $firstRouteId, $moduleCurrency);
        $baseCurrency = resolveBaseCurrency($db,
            $draft['base_currency'] ?? null,
            $trip['base_currency'] ?? null,
            $routeBaseCurrency,
            $moduleCurrency
        );
        $displayCurrency = strtoupper(trim((string)(
            $_GET['currency']
            ?? $draft['display_currency']
            ?? $draft['currency']
            ?? $trip['currency']
            ?? $_SESSION['app_currency']
            ?? 'USD'
        )));
        $validCurrency = $db->get('currencies', 'name', ['name' => $displayCurrency, 'status' => 1]);
        if (!$validCurrency) {
            throw new Exception('Unsupported currency: ' . $displayCurrency);
        }
        $conversionRate = getCurrencyConversionRate($db, $baseCurrency, $displayCurrency);

        try {
            $adults   = max(1, (int)($draft['adults'] ?? 1));
            $children = max(0, (int)($draft['children'] ?? 0));
            $priced   = busApiPriceJourneys($db, $journeys, $adults, $children);
            $totalBase = $priced['markup_total'];
            $taxInfo = calculateTax($totalBase, 'bus', $db);
            $taxAmountBase = (float)($taxInfo['tax_amount'] ?? 0);
            $finalTotalBase = round($totalBase + $taxAmountBase, 2);

            $draft['total'] = $totalBase;
            $draft['tax_amount'] = $taxAmountBase;
            $draft['final_total'] = $finalTotalBase;
            $draft['total_display'] = convertCurrencyAmount($db, $totalBase, $baseCurrency, $displayCurrency);
            $draft['tax_amount_display'] = convertCurrencyAmount($db, $taxAmountBase, $baseCurrency, $displayCurrency);
            $draft['final_total_display'] = convertCurrencyAmount($db, $finalTotalBase, $baseCurrency, $displayCurrency);
        } catch (\Throwable $e) {
            // ROUTE MAY HAVE SOLD OUT SINCE THE DRAFT WAS SAVED — KEEP STORED TOTALS, LET app-submit RE-VALIDATE
            $finalTotalBase = (float)($draft['final_total'] ?? $draft['total'] ?? 0);
            $draft['final_total_display'] = convertCurrencyAmount($db, $finalTotalBase, $baseCurrency, $displayCurrency);
        }

        $draft['base_currency'] = $baseCurrency;
        $draft['display_currency'] = $displayCurrency;
        $draft['currency'] = $displayCurrency;
        $draft['conversion_rate'] = $conversionRate;
        busApiEnrichDraftDisplayPrices($db, $draft, $journeys, $displayCurrency);

        echo json_encode([
            'success'            => true,
            'hash'               => $hash,
            'booking_data'       => $draft,
            'amount'             => $draft['final_total_display'] ?? $draft['total_display'] ?? 0,
            'amount_base'        => $draft['final_total'] ?? $draft['total'] ?? 0,
            'subtotal'           => $draft['total_display'] ?? 0,
            'subtotal_base'      => $draft['total'] ?? 0,
            'tax_amount'         => $draft['tax_amount_display'] ?? 0,
            'tax_amount_base'    => $draft['tax_amount'] ?? 0,
            'currency'           => $displayCurrency,
            'base_currency'      => $baseCurrency,
            'display_currency'   => $displayCurrency,
            'conversion_rate'    => $conversionRate,
            'created_at'         => $row['created_at'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit(0);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit(0);
    }
});

// ---------------------------------------------------------------- POST: APP SUBMIT (FULL PARITY WITH CARS)
$router->post('/api/bus/booking/app-submit', function () use ($SECURE, $db) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) $input = $_POST;
        $hash  = trim((string)($input['hash'] ?? ''));
        $guest = $input['guest'] ?? [];
        if ($hash === '' || !preg_match('/^[a-f0-9]{16}$/', $hash)) throw new Exception('Invalid booking reference');
        if (!is_array($guest)) throw new Exception('Invalid guest details');

        $row = $db->get('logs_bookings', ['data'], ['hash' => $hash]);
        if (!$row || empty($row['data'])) throw new Exception('Booking session expired');
        $draft = json_decode($row['data'], true);
        if (!is_array($draft)) throw new Exception('Invalid booking data');

        $firstName   = htmlspecialchars(strip_tags(trim((string)($guest['first_name'] ?? ''))), ENT_QUOTES, 'UTF-8');
        $lastName    = htmlspecialchars(strip_tags(trim((string)($guest['last_name'] ?? ''))), ENT_QUOTES, 'UTF-8');
        $email       = filter_var(trim((string)($guest['email'] ?? '')), FILTER_SANITIZE_EMAIL);
        $phone       = preg_replace('/[^0-9+\-\s]/', '', (string)($guest['phone'] ?? ''));
        $countryCode = trim((string)($guest['phone_country_code'] ?? ''));

        if ($firstName === '' || $lastName === '' || $email === '') throw new Exception('Passenger name and email are required');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email format');

        $trip     = $draft['trip'] ?? [];
        $adults   = max(1, (int)($draft['adults'] ?? 1));
        $children = max(0, (int)($draft['children'] ?? 0));
        $journeys = busApiNormalizeJourneys($draft);

        // PRICE INTEGRITY — RE-DERIVE FROM THE DB, NEVER TRUST THE STORED/CLIENT TOTAL
        $priced      = busApiPriceJourneys($db, $journeys, $adults, $children);
        $baseTotal   = $priced['base_total'];
        $markupTotal = $priced['markup_total'];

        // TAX — SAME MODULE-LEVEL CONFIG AS SETTINGS → MODULES → MARKUP & TAX
        $taxInfo    = calculateTax($markupTotal, 'bus', $db);
        $taxAmount  = (float)($taxInfo['tax_amount'] ?? 0);
        $finalTotal = round($markupTotal + $taxAmount, 2);
        $commission = max(0, round($markupTotal - $baseTotal, 2));

        // PROMO CODE (OPTIONAL — INERT UNLESS THE CLIENT SENDS ONE)
        $promoCodeStr  = trim((string)($input['promo_code'] ?? ''));
        $promoDiscount = (float)($input['promo_discount'] ?? 0);
        $promoCodeJson = null;
        $promoData     = null;
        if ($promoCodeStr !== '' && $promoDiscount > 0) {
            $promoData = $db->get('promo_codes', '*', ['code' => $promoCodeStr]);
            if ($promoData) {
                $promoCodeJson = json_encode([
                    'code'                => $promoData['code'],
                    'discount_type'       => $promoData['discount_type'],
                    'discount_value'      => (float)$promoData['discount_value'],
                    'discount_amount'     => $promoDiscount,
                    'max_discount_amount' => $promoData['max_discount_amount'] ? (float)$promoData['max_discount_amount'] : null,
                    'description'         => $promoData['description'],
                    'module'              => $promoData['module'],
                ]);
                $finalTotal = round($finalTotal - $promoDiscount, 2);
            }
        }

        $busModule = $db->get('modules', ['currency'], ['type' => 'bus', 'name' => 'bus']);
        $moduleCurrency = $busModule['currency'] ?? 'USD';
        $baseCurrency = resolveBaseCurrency($db,
            $input['base_currency'] ?? null,
            $draft['base_currency'] ?? null,
            $trip['base_currency'] ?? null,
            $moduleCurrency
        );
        $displayCurrency = requireAppDisplayCurrency($db, $input);
        $conversionRate = getCurrencyConversionRate($db, $baseCurrency, $displayCurrency);
        $displayFinalTotal = convertCurrencyAmount($db, $finalTotal, $baseCurrency, $displayCurrency);
        $currency = $baseCurrency;
        $draft['base_currency'] = $baseCurrency;
        $draft['display_currency'] = $displayCurrency;
        $draft['conversion_rate'] = $conversionRate;
        $draft['final_total_display'] = $displayFinalTotal;
        $draft['total'] = $markupTotal;
        $draft['final_total'] = $finalTotal;
        $draft['tax_amount'] = $taxAmount;
        $draft['total_display'] = convertCurrencyAmount($db, $markupTotal, $baseCurrency, $displayCurrency);
        $draft['tax_amount_display'] = convertCurrencyAmount($db, $taxAmount, $baseCurrency, $displayCurrency);

        // USER RESOLUTION — WEB SESSION OR MOBILE "Authorization: Bearer <jwt>", ELSE MATCH BY
        // EMAIL, ELSE AUTO-CREATE A GUEST ACCOUNT (SAME PATTERN AS api/cars/bookingRoutes.php)
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            require_once 'app/lib/jwt.php';
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            $headersLower = [];
            foreach ($headers as $k => $v) { $headersLower[strtolower($k)] = $v; }
            if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $headersLower['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            }
            $authHeader = $headersLower['authorization'] ?? '';
            if ($authHeader !== '' && preg_match('/Bearer\s(\S+)/i', $authHeader, $m)) {
                $tokenData = JWT::verify($m[1]);
                if ($tokenData && !empty($tokenData['user_id'])) $userId = $tokenData['user_id'];
            }
        }
        $userData = $userId ? $db->get('users', '*', ['user_id' => $userId]) : null;
        if (!$userData) {
            $existingUser = $db->get('users', '*', ['email' => $email]);
            if ($existingUser) {
                $userId   = $existingUser['user_id'];
                $userData = $existingUser;
            } else {
                $newUserId = 'USR' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                $created = $db->insert('users', [
                    'user_id'            => $newUserId,
                    'first_name'         => $firstName,
                    'last_name'          => $lastName,
                    'email'              => $email,
                    'password'           => password_hash(bin2hex(random_bytes(4)), PASSWORD_DEFAULT),
                    'phone'              => $phone,
                    'phone_country_code' => getPhoneCode($countryCode, $db),
                    'status'             => 'active',
                    'role'               => 'user',
                    'created_at'         => date('Y-m-d H:i:s'),
                    'email_verified'     => 0,
                ]);
                if ($created) {
                    $userId   = $newUserId;
                    $userData = $db->get('users', '*', ['user_id' => $newUserId]);
                }
            }
        }
        $isAgent      = !empty($userData['role']) && $userData['role'] === 'agent';
        $agentEarning = $isAgent ? $commission : 0;

        $invoiceId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        $db->insert('bookings', [
            'invoice_id'      => $invoiceId,
            'language'        => getCurrentLanguage(),
            'booking_date'    => date('Y-m-d H:i:s'),
            'booking_status'  => 'pending',
            'payment_status'  => 'unpaid',
            'price_original'  => $baseTotal,
            'price_markup'    => $finalTotal,
            'tax'             => $taxAmount,
            'tax_type'        => $taxInfo['tax_type'] ?? 'percentage',
            'commission'      => $commission,
            'agent_earning'   => $agentEarning,
            'first_name'      => $firstName,
            'last_name'       => $lastName,
            'email'           => $email,
            'phone'           => $phone,
            'phone_country_code' => getPhoneCode($countryCode, $db),
            'country'         => $guest['country'] ?? '',
            'adults'          => $adults,
            'childs'          => $children,
            'infants'         => 0,
            'currency_markup' => $currency,
            'module'          => 'bus',
            'module_type'     => 'bus',
            'payment_gateway' => $input['payment_gateway'] ?? null,
            'booking_data'    => json_encode($draft),
            'travellers'      => json_encode($input['travellers'] ?? []),
            'special_requests'=> $guest['special_requests'] ?? '',
            'user_id'         => $userId,
            'user_data'       => $userData ? json_encode($userData) : null,
            'promo_codes'     => $promoCodeJson,
            'cancellation_request' => 0,
            'cancellation_status'  => 0,
        ]);

        // AGENT API — wallet settlement (no-op unless agent-API request).
        $busBookingId = $db->id();
        agent_api_settle_booking($db, 'bus', $busBookingId, $invoiceId, (float) $finalTotal);

        if ($promoCodeStr !== '' && $promoDiscount > 0 && $promoData) {
            $db->update('promo_codes', ['used_count[+]' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $promoData['id']]);
        }

        $db->delete('logs_bookings', ['hash' => $hash]);

        // BOOKING CONFIRMATION NOTIFICATION (EMAIL/SMS)
        if (class_exists('NOTIFY')) {
            try {
                NOTIFY::booking('bus', [
                    'email'        => $email,
                    'phone'        => $phone,
                    'first_name'   => $firstName,
                    'last_name'    => $lastName,
                    'country_code' => $countryCode,
                ], [
                    'invoice_id'     => $invoiceId,
                    'amount'         => $displayFinalTotal,
                    'currency'       => $displayCurrency,
                    'payment_status' => 'unpaid',
                    'module_type'    => 'Bus',
                ]);
            } catch (\Throwable $e) {
                error_log('BUS_APP_SUBMIT_NOTIFY: ' . $e->getMessage());
            }
        }

        echo json_encode([
            'success'          => true,
            'invoice_id'       => $invoiceId,
            'amount'           => $displayFinalTotal,
            'amount_base'      => $finalTotal,
            'currency'         => $displayCurrency,
            'base_currency'    => $baseCurrency,
            'display_currency' => $displayCurrency,
            'conversion_rate'  => $conversionRate,
            'subtotal_display' => $draft['total_display'],
            'tax_amount_display' => $draft['tax_amount_display'],
            'redirect_url'     => root . 'invoice/bus/' . $invoiceId . '?currency=' . urlencode($displayCurrency),
        ]);
        exit(0);
    } catch (\Throwable $e) {
        error_log('BUS_APP_SUBMIT: ' . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit(0);
    }
});

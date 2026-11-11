<?php
// ============================================================================
// FILE: app/routes/api/umrah/bookingRoutes.php
// UMRAH MOBILE API - BOOKING
// ============================================================================

@$SECURE or die('Access Denied!');

require_once 'app/lib/jwt.php';

// ============================================================================
// GET: UMRAH BOOKING DATA BY HASH
// ============================================================================
// ============================================================================
// GET: UMRAH BOOKING DATA BY HASH
// ============================================================================
$router->get('/api/umrah/booking/([a-f0-9]{16})', function ($hash) use ($db) {
    header('Content-Type: application/json');
    try {
        $booking = $db->get('logs_bookings', ['hash', 'data'], ['hash' => $hash]);
        if (!$booking || empty($booking['data'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Booking draft not found']);
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
            $bookingData['actual_total_umrah_price']
            ?? $bookingData['actual_price']
            ?? $bookingData['base_price']
            ?? $bookingData['price']
            ?? $bookingData['markup_total_umrah_price']
            ?? $bookingData['amount']
            ?? 0
        );
        $curr = $bookingData['currency'] ?? 'USD';

        if ($actualAmt > 0 && function_exists('MARKUP')) {
            $umrahModule = $db->get('modules', '*', ['name' => 'umrah', 'type' => 'umrah', 'status' => '1']);
            $marked = MARKUP($actualAmt, $umrahModule ?: null, $db, $curr, $curr);
            if (!empty($marked['price']) && $marked['price'] > 0) {
                $bookingData['markup_total_umrah_price'] = round((float)$marked['price'], 2);
                $bookingData['actual_total_umrah_price'] = round($actualAmt, 2);
                $bookingData['price'] = round((float)$marked['price'], 2);
                $bookingData['actual_price'] = round($actualAmt, 2);
                $bookingData['markup_amount'] = round((float)($marked['price'] - $actualAmt), 2);
            }
        }

        if (function_exists('sanitizeCustomerResponse')) { $bookingData = sanitizeCustomerResponse($bookingData); }
        echo json_encode(['success' => true, 'hash' => $hash, 'booking_data' => $bookingData]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});

// ============================================================================
// POST: SAVE UMRAH BOOKING DRAFT
// ============================================================================
$router->post('/api/umrah/booking/draft', function () use ($db) {
    header('Content-Type: application/json');
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        // CSRF (U-08): the draft is non-authoritative for price (submit re-derives
        // server-side, U-02), but still require a token for cookie/session callers
        // to stop unauthenticated draft spam. Bearer/JWT API clients are exempt.
        if (function_exists('umrah_v1_csrf_guard')) {
            umrah_v1_csrf_guard(is_array($input) ? $input : []);
        }

        if (!$input || empty($input['umrah_id'])) {
            throw new Exception('umrah_id is required');
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

        $userId = null;
        if (!empty($token)) {
            try {
                $tokenData = JWT::verify($token);
                if (!$tokenData && method_exists('JWT', 'decode')) {
                    $tokenData = JWT::decode($token, false);
                }
                if ($tokenData && !empty($tokenData['user_id'])) {
                    $userId = $tokenData['user_id'];
                    $_SESSION['user_id'] = $userId;
                    $_SESSION['user_role'] = $tokenData['role'] ?? null;
                }
            } catch (Exception $e) {
                // Silent fail
            }
        }

        // Apply MARKUP according to resolved user role / custom markup
        $actualAmt = floatval(
            $input['actual_price']
            ?? $input['actual_total_umrah_price']
            ?? $input['base_price']
            ?? $input['price']
            ?? $input['markup_total_umrah_price']
            ?? $input['amount']
            ?? 0
        );
        $curr = $input['currency'] ?? 'USD';

        if ($actualAmt > 0 && function_exists('MARKUP')) {
            $umrahModule = $db->get('modules', '*', ['name' => 'umrah', 'type' => 'umrah', 'status' => '1']);
            $marked = MARKUP($actualAmt, $umrahModule ?: null, $db, $curr, $curr);
            if (!empty($marked['price']) && $marked['price'] > 0) {
                $input['price'] = round((float)$marked['price'], 2);
                $input['markup_total_umrah_price'] = round((float)$marked['price'], 2);
                $input['actual_price'] = round($actualAmt, 2);
                $input['actual_total_umrah_price'] = round($actualAmt, 2);
                $input['markup_amount'] = round((float)($marked['price'] - $actualAmt), 2);
            }
        }

        // ========================================
        // MAP SEARCH DATA TO STANDARD DRAFT OBJECT
        // ========================================
        $draftData = [
            'umrah_id' => $input['umrah_id'],
            'umrah_name' => $input['name'] ?? $input['umrah_name'] ?? '',
            'umrah_slug' => $input['slug'] ?? $input['umrah_slug'] ?? '',
            'umrah_location' => $input['location'] ?? $input['umrah_location'] ?? '',
            'umrah_city' => $input['city'] ?? $input['umrah_city'] ?? '',
            'umrah_description' => $input['description'] ?? $input['umrah_description'] ?? '',
            'start_date' => $input['start_date'] ?? date('d-m-Y'),
            'days' => (int) ($input['days'] ?? 0),
            'nights' => (int) ($input['nights'] ?? 0),
            'duration' => ($input['days'] ?? 0) . " Days / " . ($input['nights'] ?? 0) . " Nights",
            'img' => $input['img'] ?? $input['image'] ?? '',
            'umrah_image' => $input['img'] ?? $input['image'] ?? '',
            'images' => $input['images'] ?? [],
            'inclusions' => $input['inclusions'] ?? [],
            'total_adults' => (int) ($input['total_adults'] ?? 1),
            'total_children' => (int) ($input['total_children'] ?? 0),
            'currency' => $input['currency'] ?? 'USD',
            'adult_price' => (float) ($input['adult_price'] ?? 0),
            'child_price' => (float) ($input['child_price'] ?? 0),
            'actual_total_umrah_price' => (float) ($input['actual_price'] ?? ($input['actual_total_umrah_price'] ?? 0)),
            'markup_total_umrah_price' => (float) ($input['price'] ?? ($input['markup_total_umrah_price'] ?? 0)),
            'markup_total_price_persons' => (float) (($input['adult_price'] ?? 0) * (int) ($input['total_adults'] ?? 1)),
            'markup_total_price_childrens' => (float) (($input['child_price'] ?? 0) * (int) ($input['total_children'] ?? 0)),
            'supplier' => $input['supplier'] ?? 'umrah',
            'nationality' => $input['nationality'] ?? '',
            'stays' => $input['stays'] ?? [],
            'travelings' => $input['travelings'] ?? [],
            'transfers' => $input['travelings'] ?? [],
            'flights' => [],
        ];

        // Complex Flight Grouping
        $flightsInput = $input['flights'] ?? [];
        $hasReturn = false;
        $combinedFlight = ['segments' => [], 'returnSegments' => []];

        foreach ($flightsInput as $f) {
            $type = strtolower($f['type'] ?? '');
            $segs = $f['segments'] ?? [$f];
            if ($type === 'return') {
                $combinedFlight['returnSegments'] = array_merge($combinedFlight['returnSegments'], $segs);
                $hasReturn = true;
            } else {
                $combinedFlight['segments'] = array_merge($combinedFlight['segments'], $segs);
            }
        }

        if (!empty($combinedFlight['segments'])) {
            $draftData['flights'][] = $combinedFlight;
        }

        // Normalizing final total to sum of passengers if adults/children provided
        if ($draftData['markup_total_price_persons'] > 0) {
            $draftData['markup_total_umrah_price'] = $draftData['markup_total_price_persons'] + $draftData['markup_total_price_childrens'];
        }

        // Generate 16-character hash
        $hash = bin2hex(random_bytes(8));

        // Save normalized data to database
        $db->insert('logs_bookings', [
            'hash' => $hash,
            'data' => json_encode($draftData),
            'created_at' => date('Y-m-d H:i:s')
        ]);

        triggerWebhook('umrah/booking', 'umrah.booking.draft_created', [
            'booking_hash' => $hash,
            'umrah_id' => $input['umrah_id'] ?? null,
            'umrah_name' => $input['umrah_name'] ?? '',
            'timestamp' => date('Y-m-d H:i:s'),
            'user_id' => $userId
        ]);

        echo json_encode(['success' => true, 'hash' => $hash, 'message' => 'Umrah Booking draft saved']);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});

// ============================================================================
// POST: SUBMIT UMRAH BOOKING
// ============================================================================
$router->post('/api/umrah/bookings/submit', function () use ($db) {
    header('Content-Type: application/json');
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

        // CSRF (U-02): this endpoint confirms a booking (state change). Require a
        // valid CSRF token for cookie/session (web-client) callers. Bearer/JWT API
        // clients are exempt (no cookies → no CSRF risk), matching the umrah v1
        // guard used across the rest of the umrah API.
        if (function_exists('umrah_v1_csrf_guard')) {
            umrah_v1_csrf_guard(is_array($input) ? $input : []);
        } else {
            $__h = function_exists('getallheaders') ? array_change_key_case((array) getallheaders(), CASE_LOWER) : [];
            $__hasBearer = !empty($__h['authorization']) && preg_match('/Bearer\s+\S+/i', (string) $__h['authorization']);
            if (!$__hasBearer) {
                $__tok = $input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($__h['x-csrf-token'] ?? ''));
                if (!class_exists('CSRF') || !CSRF::validateToken((string) $__tok)) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Invalid or missing security token']);
                    return;
                }
            }
        }

        if (!$input || empty($input['booking_hash'])) {
            throw new Exception('Booking hash required');
        }

        $bookingHash = $input['booking_hash'];
        $guestDetails = $input['guest_details'] ?? [];
        $primaryGuest = $guestDetails['primary_guest'] ?? [];

        if (empty($primaryGuest['email'])) {
            throw new Exception('Customer email is required');
        }

        // 1. GET ORIGINAL BOOKING DATA FROM TEMP TABLE
        $draft = $db->get('logs_bookings', ['data'], ['hash' => $bookingHash]);
        if (!$draft) {
            throw new Exception('Booking draft not found');
        }
        $bookingData = json_decode($draft['data'], true);

        // 1.1 GET NATIONALITY FULL NAME FROM COUNTRIES TABLE
        $nationalityData = $db->get('countries', 'nicename', ['iso' => $bookingData['nationality'] ?? '']);
        $nationalityName = $nationalityData ?: ($bookingData['nationality'] ?? '');

        // 2. EXTRACT PRICES & USER CONTEXT
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
                        $_SESSION['user_id'] = $userId;
                        $_SESSION['user_role'] = $userData['role'] ?? null;
                    }
                }
            } catch (Exception $e) {
                // Silent fail
            }
        }

        if (!function_exists('MARKUP')) {
            require_once dirname(__DIR__, 4) . '/modules/helpers.php';
        }

        // PRICE INTEGRITY (U-02): NEVER trust any client-supplied price. The draft
        // ($bookingData) and the request body ($input) are both attacker-seeded
        // (the /api/umrah/booking/draft endpoint stores whatever the client sends,
        // and the old code then read actual_price/markup_total/final_total/tax_amount
        // straight from them). Re-derive the authoritative price SERVER-SIDE from the
        // `umrah` product row by umrah_id + pax — identical to the hardened singular
        // /api/umrah/booking/submit path (umrahLegacyServerPrice). The client's
        // final_total/tax_amount/actual_price are ignored entirely.
        $umrahIdForPrice = (int) ($bookingData['umrah_id'] ?? $input['umrah_id'] ?? 0);
        $paxAdults   = (int) ($bookingData['total_adults']   ?? $input['total_adults']   ?? 1);
        $paxChildren = (int) ($bookingData['total_children'] ?? $input['total_children'] ?? 0);
        $paxInfants  = (int) ($bookingData['total_infants']  ?? $input['total_infants']  ?? 0);

        if (!function_exists('umrahLegacyServerPrice')) {
            // Defensive: the helper lives in the web umrah bookingRoutes (loaded
            // before the API routes). Load it explicitly if this route is reached
            // in isolation so pricing can never silently fall back to client data.
            require_once dirname(__DIR__, 3) . '/umrah/bookingRoutes.php';
        }
        $priced = umrahLegacyServerPrice($db, $umrahIdForPrice, $paxAdults, $paxChildren, $paxInfants);
        if (empty($priced['ok'])) {
            throw new Exception($priced['message'] ?? 'This umrah package is not available for booking.');
        }

        $actualPrice = (float) $priced['actual'];                 // net (server)
        $markupPrice = (float) $priced['markup'];                 // customer sell (server, agent-aware)
        $currency    = (string) $priced['currency'];              // from the product row, not the client
        $taxAmount   = (float) $priced['tax'];
        $taxInfo     = ['tax_amount' => $taxAmount, 'tax_type' => $priced['tax_type'] ?? 'percentage'];
        $commission  = round($markupPrice - $actualPrice, 2);
        $finalTotal  = round($markupPrice + $taxAmount, 2);

        // 3.1 PROMO CODE HANDLING — recompute the discount SERVER-SIDE (never trust
        // the client's promo_discount). Enforces module/targeting/usage/per-user.
        $promoCodeStr = trim($input['promo_code'] ?? '');
        $promoDiscount = 0.0;
        $promoCodeJson = null;
        $promoData = null;
        if ($promoCodeStr !== '' && function_exists('promoResolveForBooking')) {
            $pr = promoResolveForBooking($db, $promoCodeStr, (float) $finalTotal, 'umrah', (string) $currency, [
                'item_id'    => (int) ($input['umrah_id'] ?? 0),
                'user_id'    => $userId ?? ($_SESSION['user_id'] ?? null),
                'user_email' => $primaryGuest['email'] ?? null,
            ]);
            $promoDiscount = (float) $pr['discount'];
            $promoData     = $pr['promo'];
            $promoCodeJson = $pr['json'];
        }

        // APPLY PROMO DISCOUNT TO FINAL TOTAL
        if ($promoDiscount > 0 && $promoData) {
            $finalTotal = round($finalTotal - $promoDiscount, 2);
        }

        $baseCurrency = resolveBaseCurrency($db, $bookingData['base_currency'] ?? null, $currency);
        $displayCurrency = requireAppDisplayCurrency($db, $input);
        $conversionRate = getCurrencyConversionRate($db, $baseCurrency, $displayCurrency);
        $displayFinalTotal = convertCurrencyAmount($db, $finalTotal, $baseCurrency, $displayCurrency);
        $currency = $baseCurrency;
        $bookingData['base_currency'] = $baseCurrency;
        $bookingData['display_currency'] = $displayCurrency;
        $bookingData['conversion_rate'] = $conversionRate;
        $bookingData['final_total_display'] = $displayFinalTotal;
        $bookingData['markup_total_umrah_price_display'] = convertCurrencyAmount($db, $markupPrice, $baseCurrency, $displayCurrency);

        // 4. USER MANAGEMENT (IF NOT LOGGED IN VIA JWT)
        if (!$userId) {
            $existingUser = $db->get('users', '*', ['email' => $primaryGuest['email']]);
            if ($existingUser) {
                $userId = $existingUser['user_id'];
                $userData = $existingUser;
            } else {
                $userId = 'USR' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                $generatedPassword = bin2hex(random_bytes(4));
                $db->insert('users', [
                    'user_id' => $userId,
                    'first_name' => $primaryGuest['first_name'] ?? '',
                    'last_name' => $primaryGuest['last_name'] ?? '',
                    'email' => $primaryGuest['email'],
                    'password' => password_hash($generatedPassword, PASSWORD_DEFAULT),
                    'phone' => $primaryGuest['phone'] ?? '',
                    'status' => 'active',
                    'role' => 'user',
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                $userData = $db->get('users', '*', ['user_id' => $userId]);
            }
        }

        // Agent Earning
        $isAgent = false;
        if ($userData) {
            $isAgent = ($userData['role'] ?? '') === 'agent';
        }
        $agentEarning = $isAgent ? $commission : 0;

        // 5. INVOICE ID
        $invoiceId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        // 5.1 PREPARE TRAVELLERS JSON
        $travellersData = [
            'primary_guest' => $primaryGuest,
            'travelers' => $guestDetails['travelers'] ?? [],
            'booking_for_someone_else' => $guestDetails['booking_for_someone_else'] ?? false
        ];

        // 6. INSERT BOOKING
        $db->insert('bookings', [
            'invoice_id' => $invoiceId,
            'language' => getCurrentLanguage(),
            'booking_date' => date('Y-m-d H:i:s'),
            'booking_status' => 'pending',
            'price_original' => $actualPrice,
            'price_markup' => $finalTotal,
            'agent_earning' => $agentEarning,
            'tax' => $taxAmount,
            'tax_type' => $taxInfo['tax_type'] ?? 'percentage',
            'first_name' => $primaryGuest['first_name'] ?? '',
            'last_name' => $primaryGuest['last_name'] ?? '',
            'email' => $primaryGuest['email'],
            'address' => '',
            'phone_country_code' => getPhoneCode($primaryGuest['country_code'] ?? '', $db),
            'phone' => $primaryGuest['phone'] ?? '',
            'country' => $primaryGuest['country_code'] ?? '',
            'adults' => (int) ($bookingData['total_adults'] ?? 1),
            'infants' => 0,
            'childs' => (int) ($bookingData['total_children'] ?? 0),
            'child_ages' => json_encode([]),
            'currency_markup' => $currency,
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
            'module_type' => 'umrah',
            'pnr' => null,
            'booking_response' => null,
            'error_response' => null,
            'commission' => $commission,
            'module' => $bookingData['supplier'] ?? 'umrah',
            'special_requests' => $guestDetails['special_requests'] ?? null,
            'promo_codes' => $promoCodeJson
        ]);

        $bookingResultId = $db->id();

        if ($bookingResultId) {
            // AGENT API — wallet settlement (no-op unless agent-API request).
            agent_api_settle_booking($db, 'umrah', $bookingResultId, $invoiceId, (float) $finalTotal);

            // 7. NOTIFICATIONS & WEBHOOKS
            triggerWebhook('umrah/booking', 'umrah.booking.confirmed', [
                'booking_id' => $bookingResultId,
                'invoice_id' => $invoiceId,
                'total_amount' => $displayFinalTotal,
                'currency' => $displayCurrency,
                'customer_email' => $primaryGuest['email']
            ]);

            NOTIFY::resend('umrah', [
                'email' => $primaryGuest['email'],
                'first_name' => $primaryGuest['first_name'] ?? ''
            ], [
                'invoice_id' => $invoiceId,
                'amount' => $displayFinalTotal,
                'currency' => $displayCurrency,
                'payment_status' => 'unpaid'
            ]);

            // 7.1 PROMO CODE USAGE UPDATE — idempotent per invoice; bumps used_count +
            // writes the per-user ledger row that enforces per_user_limit.
            if (!empty($promoCodeStr) && $promoDiscount > 0 && $promoData && function_exists('recordPromoUsage')) {
                recordPromoUsage($db, $promoData, (string) $invoiceId, $userId ?? null, $primaryGuest['email'] ?? null, (float) $promoDiscount, 'umrah', (string) $currency);
            }

            // 8. CLEANUP DRAFT
            $db->delete('logs_bookings', ['hash' => $bookingHash]);

            if (ob_get_length())
                ob_clean();

            echo json_encode([
                'success' => true,
                'message' => 'Umrah booking confirmed',
                'invoice_id' => $invoiceId,
                'amount' => $displayFinalTotal,
                'amount_base' => $finalTotal,
                'currency' => $displayCurrency,
                'base_currency' => $baseCurrency,
                'display_currency' => $displayCurrency,
                'conversion_rate' => $conversionRate,
                'redirect_url' => root . 'invoice/umrah/' . $invoiceId . '?currency=' . urlencode($displayCurrency)
            ]);
        } else {
            throw new Exception('Failed to save booking');
        }

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
});

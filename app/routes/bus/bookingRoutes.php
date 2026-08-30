<?php
// ============================================================================
// FILE: app/routes/bus/bookingRoutes.php
// BUS BOOKING — follows the platform cache-booking structure:
//   1. POST /api/bus/booking/save-draft  → cache selection in logs_bookings, return hash
//   2. GET  /bus/booking/{hash}          → load cached draft, render booking page
//   3. POST /api/bus/booking/submit      → create real bookings row, clear cache, redirect
// ============================================================================
@$SECURE or die('Access Denied!');

// ---------------------------------------------------------------- SAVE DRAFT (CACHE)
$router->post('/api/bus/booking/save-draft', function () use ($SECURE, $db) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['route_id'])) {
            throw new Exception('Invalid booking data');
        }
        $journeys = $input['journeys'] ?? [];
        if (($input['trip_type'] ?? 'oneway') === 'return' && count($journeys) !== 2) {
            throw new Exception('Both outbound and return buses are required');
        }

        $hash = bin2hex(random_bytes(8)); // 16 hex chars
        $db->insert('logs_bookings', [
            'hash'       => $hash,
            'data'       => json_encode($input),
            'user_id'    => $_SESSION['user_id'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        echo json_encode(['success' => true, 'hash' => $hash]);
        exit(0);
    } catch (\Throwable $e) {
        error_log('BUS_BOOKING_SAVE_DRAFT: ' . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit(0);
    }
});

// ---------------------------------------------------------------- BOOKING PAGE (CACHE URL)
$router->get('/bus/booking/([a-f0-9]{16})', function ($hash) use ($SECURE, $db) {
    $row = $db->get('logs_bookings', ['hash', 'data'], ['hash' => $hash]);
    if (!$row || empty($row['data'])) { redirect(root . 'bus/'); return; }
    $draft = json_decode($row['data'], true);
    if (!$draft) { redirect(root . 'bus/'); return; }

    $bookingHash  = $hash;
    $booking      = $draft;
    $title        = (T::complete_booking ?? 'Complete Booking') . ' - ' . ($GLOBALS['app']['home_title'] ?? 'Bus');
    $header = true; $footer = true;
    require_once views . 'includes/header.php';
    require_once views . 'modules/bus/booking/index.php';
    require_once views . 'includes/footer.php';
});

// ---------------------------------------------------------------- DOWNLOAD INVOICE PDF
$router->get('/api/bus/booking/download-invoice/([A-Z0-9]{8})', function ($invoiceId) use ($SECURE, $db) {
    try {
        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'bus']);
        if (!$booking) { http_response_code(404); die('Booking not found'); }

        $pdfPath = GENERATE_BOOKING_PDF($invoiceId);
        if ($pdfPath && file_exists($pdfPath)) {
            while (ob_get_level()) { ob_end_clean(); } // DROP ANY BUFFERED OUTPUT (e.g. BOM) THAT WOULD CORRUPT THE PDF
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="invoice_' . $invoiceId . '.pdf"');
            header('Content-Length: ' . filesize($pdfPath));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            readfile($pdfPath);
            exit;
        }
        throw new Exception('Failed to generate or find invoice PDF');
    } catch (\Throwable $e) {
        http_response_code(500);
        die('Error downloading invoice');
    }
});

// ---------------------------------------------------------------- REQUEST CANCELLATION
$router->post('/api/bus/booking/request-cancellation', function () use ($SECURE, $db) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($input['invoice_id'])) throw new Exception('Invoice ID required');

        $booking = $db->get('bookings', ['id'], ['invoice_id' => $input['invoice_id'], 'module_type' => 'bus']);
        if (!$booking) throw new Exception('Booking not found');

        $db->update('bookings', ['cancellation_request' => 1], ['invoice_id' => $input['invoice_id']]);
        echo json_encode(['success' => true, 'message' => 'Cancellation request submitted']);
        exit(0);
    } catch (\Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit(0);
    }
});

// ---------------------------------------------------------------- SUBMIT (CREATE BOOKING)
$router->post('/api/bus/booking/submit', function () use ($SECURE, $db) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $hash  = $input['hash'] ?? '';
        $guest = $input['guest'] ?? [];
        if (!$hash) throw new Exception('Missing booking reference');

        $row = $db->get('logs_bookings', ['data'], ['hash' => $hash]);
        if (!$row || empty($row['data'])) throw new Exception('Booking session expired');
        $draft = json_decode($row['data'], true);
        if (!$draft) throw new Exception('Invalid booking data');

        if (empty($guest['first_name']) || empty($guest['email'])) {
            throw new Exception('Passenger name and email are required');
        }

        $trip     = $draft['trip'] ?? [];
        $adults   = (int)($draft['adults'] ?? 1);
        $children = (int)($draft['children'] ?? 0);
        $journeys = $draft['journeys'] ?? [[
            'type' => 'outbound',
            'route_id' => (int)($draft['route_id'] ?? 0),
            'date' => $draft['date'] ?? '',
        ]];
        if (($draft['trip_type'] ?? 'oneway') === 'return' && count($journeys) !== 2) {
            throw new Exception('Return journey is incomplete');
        }

        // PRICE INTEGRITY — RE-DERIVE FROM DB (SAME PATTERN AS MOBILE app-submit)
        $paxCount = max(1, $adults + $children);
        $baseTotal = 0.0;
        $markupTotal = 0.0;
        $busModule = $db->get('modules', ['markup_b2c', 'markup_type_b2c', 'currency'], ['type' => 'bus', 'name' => 'bus']);
        $moduleMarkup = (float)($busModule['markup_b2c'] ?? 0);
        $moduleMarkupType = $busModule['markup_type_b2c'] ?? 'percentage';
        $moduleCurrency = $busModule['currency'] ?? 'USD';
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
                'date' => $dateObj->format('Y-m-d'),
            ]);
            if (!$route || !$cal || (int)$cal['seats_available'] < $paxCount) {
                throw new Exception('One of the selected buses no longer has enough seats');
            }

            $operatorId = (int)($route['operator_id'] ?? 0);
            $markup = $moduleMarkup;
            $markupType = $moduleMarkupType;
            if ($operatorId) {
                $op = $db->get('bus_operators', ['markup_b2c', 'markup_type_b2c'], ['id' => $operatorId]);
                if ($op && (float)($op['markup_b2c'] ?? 0) > 0) {
                    $markup = (float)$op['markup_b2c'];
                    $markupType = $op['markup_type_b2c'] ?? 'percentage';
                }
            }
            $applyLegMarkup = function ($price) use ($markup, $markupType) {
                $price = (float)$price;
                if ($markup <= 0) return round($price, 2);
                return round($markupType === 'fixed' ? $price + $markup : $price * (1 + $markup / 100), 2);
            };

            $rawAdult = (float)($route['adult_price'] ?: ($cal['price'] ?? $route['base_price']));
            $rawChild = (float)($route['child_price'] ?: 0);
            $baseTotal += ($adults * $rawAdult) + ($children * $rawChild);
            $markupTotal += ($adults * $applyLegMarkup($rawAdult)) + ($children * $applyLegMarkup($rawChild));
        }
        $baseTotal = round($baseTotal, 2);
        $markupTotal = round($markupTotal, 2);
        $commission = max(0, round($markupTotal - $baseTotal, 2));
        $finalTotal = $markupTotal;

        $baseCurrency = resolveBaseCurrency($db,
            $draft['base_currency'] ?? null,
            $trip['base_currency'] ?? null,
            $moduleCurrency
        );
        $displayCurrency = resolveDisplayCurrency($db, $input['currency'] ?? $_SESSION['app_currency'] ?? 'USD');
        $conversionRate = getCurrencyConversionRate($db, $baseCurrency, $displayCurrency);
        $displayFinalTotal = convertCurrencyAmount($db, $finalTotal, $baseCurrency, $displayCurrency);
        $currency = $baseCurrency;

        $draft['base_currency'] = $baseCurrency;
        $draft['display_currency'] = $displayCurrency;
        $draft['conversion_rate'] = $conversionRate;
        $draft['final_total_display'] = $displayFinalTotal;
        $draft['total'] = $finalTotal;

        $invoiceId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

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

        $db->insert('bookings', [
            'invoice_id'      => $invoiceId,
            'language'        => getCurrentLanguage(),
            'booking_date'    => date('Y-m-d H:i:s'),
            'booking_status'  => 'pending',
            'payment_status'  => 'unpaid',
            'price_original'  => $baseTotal,
            'price_markup'    => $finalTotal,
            'commission'      => $commission,
            'agent_earning'   => $agentEarning,
            'first_name'      => $guest['first_name'] ?? '',
            'last_name'       => $guest['last_name'] ?? '',
            'email'           => $guest['email'] ?? '',
            'phone'           => $guest['phone'] ?? '',
            'phone_country_code' => $guest['phone_country_code'] ?? '',
            'country'         => $guest['country'] ?? '',
            'adults'          => (int)($draft['adults'] ?? 1),
            'childs'          => (int)($draft['children'] ?? 0),
            'infants'         => 0,
            'currency_markup' => $currency,
            'module'          => 'bus',
            'module_type'     => 'bus',
            'payment_gateway' => $input['payment_gateway'] ?? null,
            'booking_data'    => json_encode($draft),
            'travellers'      => json_encode($input['travellers'] ?? []),
            'special_requests'=> $guest['special_requests'] ?? '',
            'user_id'         => $_SESSION['user_id'] ?? null,
            'cancellation_request' => 0,
            'cancellation_status'  => 0,
        ]);

        $db->delete('logs_bookings', ['hash' => $hash]);

        echo json_encode([
            'success'      => true,
            'invoice_id'   => $invoiceId,
            'amount'       => $displayFinalTotal,
            'currency'     => $displayCurrency,
            'redirect_url' => root . 'invoice/bus/' . $invoiceId . '?currency=' . urlencode($displayCurrency),
        ]);
        exit(0);
    } catch (\Throwable $e) {
        error_log('BUS_BOOKING_SUBMIT: ' . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit(0);
    }
});

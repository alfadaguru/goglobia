<?php
// ============================================================================
// FILE: app/routes/cruises/bookingRoutes.php
// CRUISE BOOKING (own-inventory — Phase 2). POST /api/cruise/booking/submit.
// Mirrors the hardened insurance booking path (app/routes/insurance/bookingRoutes.php):
// server-authoritative price (never trust the client), MARKUP + discounts via the
// own-inventory 'cruises/goglobia' module, create a bookings row with module='goglobia'
// (so pg_confirms_on_payment_module() confirms on payment and NEVER tries a nonexistent
// supplier issue endpoint — the auto-refund bug class), then redirect to the invoice to
// pay. Guests supported via grantInvoiceSessionOwnership().
// ============================================================================

@$SECURE or die('Access Denied!');

$router->post('/api/cruise/booking/submit', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        if (function_exists('ensureCruisesSchema')) { ensureCruisesSchema($db); }

        // CSRF (JSON body or X-CSRF-TOKEN header).
        if (class_exists('CSRF') && !CSRF::validateToken($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            throw new Exception('Invalid or expired session token. Please refresh and try again.');
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) { $input = $_POST; }

        $cruiseId = (int) ($input['cruise_id'] ?? 0);
        if ($cruiseId <= 0) {
            throw new Exception('Please choose a cruise.');
        }

        // SERVER-AUTHORITATIVE: read the active own-inventory cruise straight from the DB.
        $cruise = $db->get('cruises', '*', ['id' => $cruiseId, 'status' => 1]);
        if (!$cruise) {
            throw new Exception('That cruise is no longer available.');
        }

        // Lead guest / contact details.
        $firstName = trim((string) ($input['first_name'] ?? ''));
        $lastName  = trim((string) ($input['last_name'] ?? ''));
        $email     = trim((string) ($input['email'] ?? ''));
        $phone     = trim((string) ($input['phone'] ?? ''));
        $phoneCc   = trim((string) ($input['phone_country_code'] ?? ''));
        $country   = trim((string) ($input['country'] ?? ''));
        if ($firstName === '' || $lastName === '' || $email === '') {
            throw new Exception('Please provide the lead guest name and email.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please provide a valid email address.');
        }
        if (empty($input['terms_accepted'])) {
            throw new Exception('Please accept the terms and conditions.');
        }

        // Party size, clamped to the cruise's capacity.
        $adults   = max(1, (int) ($input['adults'] ?? 1));
        $children = max(0, (int) ($input['children'] ?? 0));
        $maxGuests = max(1, (int) ($cruise['max_guests'] ?? 4));
        if (($adults + $children) > $maxGuests) {
            throw new Exception('This cruise seats up to ' . $maxGuests . ' guests.');
        }

        $cruiseCurrency = strtoupper((string) ($cruise['currency'] ?? 'NGN'));
        $adultUnit = (float) ($cruise['price'] ?? 0);
        $childUnit = (float) ($cruise['child_price'] ?? 0);
        $netTotal  = round(($adultUnit * $adults) + ($childUnit * $children), 2);
        if ($netTotal <= 0) {
            throw new Exception('This cruise is not priced for online booking yet.');
        }

        // Display currency the customer pays in (session/app default, validated).
        $displayCurrency = strtoupper(trim((string) (
            $input['currency'] ?? $input['display_currency'] ?? ($_SESSION['app_currency'] ?? '') ?? ''
        )));
        if ($displayCurrency === '') {
            $displayCurrency = strtoupper((string) ($GLOBALS['app']['default_currency'] ?? $cruiseCurrency));
        }
        $__ccyOk = $db->get('currencies', ['name'], ['name' => $displayCurrency, 'status' => 1]);
        if (!$__ccyOk) {
            $displayCurrency = $cruiseCurrency;
        }

        // MARKUP via the own-inventory 'cruises/goglobia' module (convert + b2c/b2b).
        $moduleRow = $db->get('modules', '*', ['type' => 'cruises', 'name' => 'goglobia', 'status' => '1']) ?: 'cruises';
        $sellTotal     = $netTotal;
        $markupAmt     = 0.0;
        $baseConverted = $netTotal;
        if (function_exists('MARKUP')) {
            $mk = MARKUP($netTotal, $moduleRow, $db, $cruiseCurrency, $displayCurrency);
            if (is_array($mk) && !empty($mk['price'])) {
                $sellTotal     = round((float) $mk['price'], 2);
                $markupAmt     = round((float) ($mk['markup'] ?? 0), 2);
                $baseConverted = round((float) ($mk['converted_base_price'] ?? $netTotal), 2);
            }
        }

        // TAX (audit HIGH) — the detail/listing DISPLAY price comes from
        // calculateCompletePrice($base,'cruises',...) = MARKUP THEN calculateTax, so the
        // quoted price is tax-inclusive. The booking previously hardcoded tax=0 and never
        // called calculateTax, so a configured cruise tax was shown to the customer but
        // never charged (undercharge + display-vs-charge mismatch, and the refund base
        // price_markup was pre-tax). Compute tax on the marked-up sell price with the SAME
        // module key the display uses ('cruises'), fold it into $sellTotal BEFORE discounts
        // (matching the display order), and persist the real tax on the booking.
        $cruiseTaxAmount = 0.0;
        $cruiseTaxType   = 'percentage';
        if (function_exists('calculateTax')) {
            // Pass ($cruiseCurrency, $displayCurrency) — the SAME from/to the display's
            // calculateCompletePrice() feeds calculateTax(markupResult['price'], 'cruises',
            // $db, $from, $to) (functions.php:7922). $sellTotal here == that markup price
            // (both MARKUP($netTotal, ..., $cruiseCurrency, $displayCurrency)), so the tax
            // amount is byte-for-byte what the customer was quoted on the detail page.
            $cruiseTaxInfo   = calculateTax($sellTotal, 'cruises', $db, $cruiseCurrency, $displayCurrency);
            $cruiseTaxAmount = round((float) ($cruiseTaxInfo['tax_amount'] ?? 0), 2);
            $cruiseTaxType   = (string) ($cruiseTaxInfo['tax_type'] ?? 'percentage');
            if ($cruiseTaxAmount > 0) {
                $sellTotal = round($sellTotal + $cruiseTaxAmount, 2);
            }
        }

        // Promo/discount (unified engine), server-side.
        if (function_exists('apply_booking_discounts')) {
            try {
                $disc = apply_booking_discounts($db, [
                    'module'       => 'cruises',
                    'provider'     => 'goglobia',
                    'base'         => $sellTotal,
                    'supplier_net' => $baseConverted,
                    'currency'     => $displayCurrency,
                    'promo_code'   => trim((string) ($input['promo_code'] ?? '')),
                    'user_id'      => $_SESSION['user_id'] ?? null,
                    'user_email'   => $email,
                ]);
                if (is_array($disc) && isset($disc['total']) && (float) $disc['total'] > 0) {
                    $sellTotal = round((float) $disc['total'], 2);
                }
            } catch (\Throwable $e) {
                error_log('cruise booking discount: ' . $e->getMessage());
            }
        }

        $invoiceId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $userId = $_SESSION['user_id'] ?? null;

        $bookingData = [
            'cruise_id'      => (int) $cruise['id'],
            'cruise_name'    => (string) $cruise['name'],
            'cruise_slug'    => (string) $cruise['slug'],
            'cruise_line'    => (string) ($cruise['cruise_line'] ?? ''),
            'ship_name'      => (string) ($cruise['ship_name'] ?? ''),
            'destination'    => (string) ($cruise['destination'] ?? ''),
            'departure_port' => (string) ($cruise['departure_port'] ?? ''),
            'departure_date' => (string) ($cruise['departure_date'] ?? ''),
            'nights'         => (int) ($cruise['nights'] ?? 0),
            'room_type'      => (string) ($cruise['room_type'] ?? ''),
            'adults'         => $adults,
            'children'       => $children,
            'supplier'       => 'goglobia',
            'adult_unit'     => $adultUnit,
            'child_unit'     => $childUnit,
            'net_total'      => $netTotal,
            'cruise_currency'=> $cruiseCurrency,
        ];

        $db->insert('bookings', [
            'invoice_id'      => $invoiceId,
            'booking_date'    => date('Y-m-d H:i:s'),
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
            'booking_status'  => 'pending',
            'payment_status'  => 'unpaid',
            'price_original'  => $baseConverted,          // net, display currency
            'price_markup'    => $sellTotal,              // sell (what the gateway charges)
            'currency_markup' => $displayCurrency,
            'tax'             => $cruiseTaxAmount,
            'tax_type'        => $cruiseTaxType,
            'first_name'      => $firstName,
            'last_name'       => $lastName,
            'email'           => $email,
            'phone'           => $phone,
            'phone_country_code' => $phoneCc,
            'country'         => $country,
            'address'         => '',
            'adults'          => $adults,
            'childs'          => $children,
            'infants'         => 0,
            'child_ages'      => '',
            'travellers'      => json_encode([[
                'first_name' => $firstName, 'last_name' => $lastName,
                'email' => $email, 'phone' => $phone, 'country' => $country,
            ]], JSON_UNESCAPED_SLASHES),
            'module_type'     => 'cruises',
            'module'          => 'goglobia',   // own-inventory -> confirm-on-payment
            'booking_data'    => json_encode($bookingData, JSON_UNESCAPED_SLASHES),
            'user_id'         => $userId,
            'pnr'             => null,
            'booking_response'=> null,
            'error_response'  => null,
        ]);
        $bookingId = $db->id();

        // AGENT API — wallet settlement (no-op unless an agent-API request).
        if (function_exists('agent_api_settle_booking')) {
            agent_api_settle_booking($db, 'cruises', $bookingId, $invoiceId, (float) $sellTotal);
        }

        // Let the (possibly guest) creating session view + pay this invoice.
        if (function_exists('grantInvoiceSessionOwnership')) {
            grantInvoiceSessionOwnership($invoiceId);
        }

        echo json_encode([
            'status'       => true,
            'success'      => true,
            'invoice_id'   => $invoiceId,
            'booking_id'   => $bookingId,
            'amount'       => $sellTotal,
            'currency'     => $displayCurrency,
            'redirect'     => root . 'invoice/cruises/' . $invoiceId,
            'redirect_url' => root . 'invoice/cruises/' . $invoiceId,
        ], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        // Log the real error; return a safe message (audit LOW — don't leak DB/internals).
        error_log('CRUISE_BOOKING_SUBMIT: ' . $e->getMessage());
        http_response_code(400);
        echo json_encode(['status' => false, 'success' => false, 'message' => 'We could not complete your cruise booking. Please check your details and try again.']);
    }
});

// ----------------------------------------------------------------------------
// CUSTOMER CANCELLATION REQUEST — POST /api/cruise/booking/request-cancellation.
// Mirrors every other service (bus/stays/tours/...): the customer REQUESTS a
// cancellation (sets cancellation_request=1); the actual refund is staff-decided
// via the admin refund route (money-safe — the customer never self-refunds).
// Ownership-guarded so a stranger cannot flag someone else's booking.
// ----------------------------------------------------------------------------
$router->post('/api/cruise/booking/request-cancellation', function () use ($SECURE, $db) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) { $input = $_POST; }
        $invoiceId = trim((string) ($input['invoice_id'] ?? ''));
        if ($invoiceId === '') { throw new Exception('Invoice ID required'); }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId, 'module_type' => 'cruises']);
        if (!$booking) { throw new Exception('Booking not found'); }

        // OWNERSHIP GUARD: only the invoice owner / creating session / admin.
        // enforceInvoiceAccess auto-responds 403 JSON on an /api/ route for a non-owner.
        if (function_exists('enforceInvoiceAccess')) {
            enforceInvoiceAccess($db, $booking);
        }

        // Already cancelled/voided → nothing to request.
        if (in_array(strtolower((string) ($booking['booking_status'] ?? '')), ['cancelled', 'voided'], true)) {
            throw new Exception('This booking is already cancelled.');
        }

        $db->update('bookings', [
            'cancellation_request' => 1,
            'updated_at'           => date('Y-m-d H:i:s'),
        ], ['invoice_id' => $invoiceId]);

        echo json_encode(['success' => true, 'message' => 'Cancellation request submitted']);
        exit(0);
    } catch (\Throwable $e) {
        error_log('CRUISE_REQUEST_CANCELLATION: ' . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Could not submit your cancellation request. Please try again.']);
        exit(0);
    }
});

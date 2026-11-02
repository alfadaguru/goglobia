<?php

// app/routes/insurance/bookingRoutes.php
// Create a FREE flight-compensation-claim "booking" and register it with AirHelp.
// No payment gateway: the customer is not charged (AirHelp pays the platform a
// commission on won claims). The booking is a zero-price record whose lifecycle
// is: submit -> register with AirHelp -> track status.
@$SECURE or die('Access Denied!');

require_once dirname(__DIR__, 3) . '/modules/insurance/airhelp/index.php';

$router->post('/insurance/claim/submit', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        // CSRF (JSON): accepts token in body or X-CSRF-TOKEN header.
        if (class_exists('CSRF') && !CSRF::validateToken($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            throw new Exception('Invalid or expired session token. Please refresh and try again.');
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            // fall back to form post
            $input = $_POST;
        }

        // Insurance module must be active.
        $module = $db->get('modules', '*', ['name' => 'airhelp', 'type' => 'insurance', 'status' => 1]);
        if (!$module) {
            throw new Exception('Flight compensation service is not available right now.');
        }

        // --- Required fields ---
        $flight = [
            'airline_code'        => strtoupper(trim((string) ($input['airline_code'] ?? ''))),
            'flight_number'       => trim((string) ($input['flight_number'] ?? '')),
            'departure_airport'   => strtoupper(trim((string) ($input['departure_airport'] ?? ''))),
            'arrival_airport'     => strtoupper(trim((string) ($input['arrival_airport'] ?? ''))),
            'departure_datetime'  => trim((string) ($input['departure_datetime'] ?? '')),
            'arrival_datetime'    => trim((string) ($input['arrival_datetime'] ?? '')),
            'pnr'                 => trim((string) ($input['pnr'] ?? '')),
            'booking_reference'   => trim((string) ($input['booking_reference'] ?? ($input['pnr'] ?? ''))),
        ];
        $passenger = [
            'first_name' => trim((string) ($input['first_name'] ?? '')),
            'last_name'  => trim((string) ($input['last_name'] ?? '')),
            'email'      => trim((string) ($input['email'] ?? '')),
            'phone'      => trim((string) ($input['phone'] ?? '')),
            'country'    => strtoupper(trim((string) ($input['country'] ?? ''))),
            'language'   => $_SESSION['app_language'] ?? 'en',
        ];

        $missing = [];
        foreach (['airline_code', 'flight_number', 'departure_airport', 'arrival_airport', 'departure_datetime'] as $k) {
            if ($flight[$k] === '') { $missing[] = $k; }
        }
        foreach (['first_name', 'last_name', 'email'] as $k) {
            if ($passenger[$k] === '') { $missing[] = $k; }
        }
        if ($missing) {
            throw new Exception('Please complete: ' . implode(', ', $missing));
        }
        if (!filter_var($passenger['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please enter a valid email address.');
        }

        // --- Create the zero-price booking record ---
        $invoiceId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $bookingData = [
            'supplier'    => 'airhelp',
            'flight'      => $flight,
            'passenger'   => $passenger,
            'claim_type'  => 'flight_compensation',
        ];

        $db->insert('bookings', [
            'invoice_id'      => $invoiceId,
            'booking_date'    => date('Y-m-d H:i:s'),
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
            'booking_status'  => 'pending',
            'price_original'  => 0,
            'price_markup'    => 0,
            'currency_markup' => strtoupper((string) ($module['currency'] ?? 'USD')),
            'first_name'      => $passenger['first_name'],
            'last_name'       => $passenger['last_name'],
            'email'           => $passenger['email'],
            'phone'           => $passenger['phone'],
            'country'         => $passenger['country'],
            'adults'          => 1,
            'infants'         => 0,
            'childs'          => 0,
            'booking_data'    => json_encode($bookingData),
            // payment_status ENUM = paid|unpaid|refunded. A compensation claim is
            // free (no charge), so 'unpaid' with price 0 represents "nothing due".
            'payment_status'  => 'unpaid',
            'user_id'         => $_SESSION['user_id'] ?? null,
            'module_type'     => 'insurance',
            'module'          => 'airhelp',
            'pnr'             => null,
            'booking_response'=> null,
            'error_response'  => null,
        ]);

        // --- Register the claim with AirHelp (safe no-op if unconfigured) ---
        $result = airhelp_register_claim($db, $invoiceId);

        // customer-flow audit #19: send the claimant an emailed confirmation with
        // their claim reference. Previously the ONLY record was the on-screen page
        // keyed on the 8-char invoice_id — a guest (user_id null, no dashboard) who
        // closed the tab lost the sole way back to /insurance/claim/{id}. Deferred to
        // shutdown so the (SMTP) send never blocks the submit response under mod_php
        // (the eSIM/ferries inline-notify-at-submit hang class). Non-fatal.
        if (function_exists('SENDEMAIL') && !empty($passenger['email'])) {
            $__claimEmail   = (string) $passenger['email'];
            $__claimName    = trim(($passenger['first_name'] ?? '') . ' ' . ($passenger['last_name'] ?? ''));
            $__claimRef     = $invoiceId;
            $__claimUrl     = root . 'insurance/claim/' . $invoiceId;
            $__brand        = (string) ($GLOBALS['app']['name'] ?? 'GoGlobia');
            register_shutdown_function(static function () use ($__claimEmail, $__claimName, $__claimRef, $__claimUrl, $__brand) {
                try {
                    $subject = "Your flight compensation claim ({$__claimRef})";
                    $body = "<p>Hi " . htmlspecialchars($__claimName ?: 'there') . ",</p>"
                        . "<p>We've received your flight compensation claim. Your claim reference is "
                        . "<strong>" . htmlspecialchars($__claimRef) . "</strong>.</p>"
                        . "<p>You can view the status of your claim any time here:<br>"
                        . "<a href=\"" . htmlspecialchars($__claimUrl) . "\">" . htmlspecialchars($__claimUrl) . "</a></p>"
                        . "<p>Keep this reference safe — you'll need it to track your claim.</p>"
                        . "<p>— " . htmlspecialchars($__brand) . "</p>";
                    SENDEMAIL($__claimEmail, $__claimName, $subject, $body);
                } catch (\Throwable $e) { error_log('insurance claim confirm email: ' . $e->getMessage()); }
            });
        }

        echo json_encode([
            'status'      => true,
            'invoice_id'  => $invoiceId,
            'claim'       => $result,
            'redirect'    => root . 'insurance/claim/' . $invoiceId,
            'message'     => $result['pending']
                ? 'Your claim has been submitted and is being reviewed.'
                : 'Your flight compensation claim has been registered.',
        ], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        echo json_encode(['status' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
    }
});

// ===========================================================================
// PAID TRAVEL-INSURANCE — OWN-INVENTORY booking create (customer-flow audit #12)
// Admin-defined plans in `insurance_plans` sold as internal inventory. The PRICE
// is read server-side from the plan row (never trusted from the client), marked
// up via the 'goglobia' insurance module, and the booking confirms on payment
// (pg_confirms_on_payment_module('goglobia') — no external supplier issue).
// Mirrors the own-inventory tours submit path.
// ===========================================================================
$router->post('/api/insurance/booking/submit', function () use ($SECURE, $db) {
    header('Content-Type: application/json');

    try {
        // CSRF (JSON): token in body or X-CSRF-TOKEN header (same as the claim route).
        if (class_exists('CSRF') && !CSRF::validateToken($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            throw new Exception('Invalid or expired session token. Please refresh and try again.');
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) { $input = $_POST; }

        $planId = (int) ($input['plan_id'] ?? 0);
        if ($planId <= 0) {
            throw new Exception('Please choose an insurance plan.');
        }

        // SERVER-AUTHORITATIVE PRICE: read the plan straight from the DB — never the
        // client. An active own-inventory plan only.
        $plan = $db->get('insurance_plans', '*', ['id' => $planId, 'status' => 1]);
        if (!$plan) {
            throw new Exception('That insurance plan is no longer available.');
        }

        // Traveller / contact details.
        $firstName = trim((string) ($input['first_name'] ?? ''));
        $lastName  = trim((string) ($input['last_name'] ?? ''));
        $email     = trim((string) ($input['email'] ?? ''));
        $phone     = trim((string) ($input['phone'] ?? ''));
        $phoneCc   = trim((string) ($input['phone_country_code'] ?? ''));
        $country   = trim((string) ($input['country'] ?? ''));
        if ($firstName === '' || $lastName === '' || $email === '') {
            throw new Exception('Please provide the traveller name and email.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please provide a valid email address.');
        }
        if (empty($input['terms_accepted'])) {
            throw new Exception('Please accept the terms and conditions.');
        }

        $travellers = max(1, (int) ($input['travellers'] ?? 1));
        $planCurrency = strtoupper((string) ($plan['currency'] ?? 'NGN'));
        $netUnit = (float) $plan['price'];
        $netTotal = round($netUnit * $travellers, 2);

        // Display currency the customer pays in (so the gateway/guard agree). Resolve
        // from the active session/app currency rather than REQUIRING the client to
        // send it — fall back to the app default, then the plan currency. Validate it
        // against the currencies table so an unsupported code can't slip through.
        $displayCurrency = strtoupper(trim((string) (
            $input['currency']
            ?? $input['display_currency']
            ?? ($_SESSION['currency'] ?? '')
            ?? ''
        )));
        if ($displayCurrency === '') {
            $displayCurrency = strtoupper((string) ($GLOBALS['app']['default_currency'] ?? $planCurrency));
        }
        $__ccyOk = $db->get('currencies', ['name'], ['name' => $displayCurrency, 'status' => 1]);
        if (!$__ccyOk) {
            // Unsupported/unknown -> fall back to the plan's own currency (always valid).
            $displayCurrency = $planCurrency;
        }

        // MARKUP via the own-inventory 'goglobia' insurance module (converts
        // plan currency -> display currency + applies b2c/b2b markup server-side).
        $moduleRow = $db->get('modules', '*', ['type' => 'insurance', 'name' => 'goglobia', 'status' => '1']) ?: 'insurance';
        $sellTotal = $netTotal;
        $markupAmt = 0.0;
        $baseConverted = $netTotal;
        if (function_exists('MARKUP')) {
            $mk = MARKUP($netTotal, $moduleRow, $db, $planCurrency, $displayCurrency);
            if (is_array($mk) && !empty($mk['price'])) {
                $sellTotal     = round((float) $mk['price'], 2);
                $markupAmt     = round((float) ($mk['markup'] ?? 0), 2);
                $baseConverted = round((float) ($mk['converted_base_price'] ?? $netTotal), 2);
            }
        }

        // Promo/discount (unified engine), server-side — never trust a client amount.
        // Returns 'total' = the final charge after auto + promo + referral stacking.
        if (function_exists('apply_booking_discounts')) {
            try {
                $disc = apply_booking_discounts($db, [
                    'module'      => 'insurance',
                    'provider'    => 'goglobia',
                    'base'        => $sellTotal,
                    'supplier_net'=> $baseConverted,
                    'currency'    => $displayCurrency,
                    'promo_code'  => trim((string) ($input['promo_code'] ?? '')),
                    'user_id'     => $_SESSION['user_id'] ?? null,
                    'user_email'  => $email,
                ]);
                if (is_array($disc) && isset($disc['total']) && (float) $disc['total'] > 0) {
                    $sellTotal = round((float) $disc['total'], 2);
                }
            } catch (\Throwable $e) {
                error_log('insurance booking discount: ' . $e->getMessage());
            }
        }

        $invoiceId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $userId = $_SESSION['user_id'] ?? null;

        $bookingData = [
            'plan_id'          => (int) $plan['id'],
            'plan_name'        => (string) $plan['name'],
            'plan_slug'        => (string) $plan['slug'],
            'provider_label'   => (string) ($plan['provider_label'] ?? ''),
            'coverage_amount'  => $plan['coverage_amount'] ?? null,
            'coverage_details' => (string) ($plan['coverage_details'] ?? ''),
            'duration_days'    => $plan['duration_days'] ?? null,
            'region'           => (string) ($plan['region'] ?? ''),
            'travellers'       => $travellers,
            'supplier'         => 'goglobia',
            'net_unit'         => $netUnit,
            'net_total'        => $netTotal,
            'plan_currency'    => $planCurrency,
        ];

        $db->insert('bookings', [
            'invoice_id'      => $invoiceId,
            'booking_date'    => date('Y-m-d H:i:s'),
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
            'booking_status'  => 'pending',
            'payment_status'  => 'unpaid',
            'price_original'  => $baseConverted,          // net, in display currency
            'price_markup'    => $sellTotal,              // sell (what the gateway charges)
            'currency_markup' => $displayCurrency,
            'tax'             => 0,
            'tax_type'        => 'percentage',
            'first_name'      => $firstName,
            'last_name'       => $lastName,
            'email'           => $email,
            'phone'           => $phone,
            'phone_country_code' => $phoneCc,
            'country'         => $country,
            'address'         => '',
            'adults'          => $travellers,
            'childs'          => 0,
            'infants'         => 0,
            'child_ages'      => '',
            'travellers'      => json_encode([[
                'first_name' => $firstName, 'last_name' => $lastName,
                'email' => $email, 'phone' => $phone, 'country' => $country,
            ]], JSON_UNESCAPED_SLASHES),
            'module_type'     => 'insurance',
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
            agent_api_settle_booking($db, 'insurance', $bookingId, $invoiceId, (float) $sellTotal);
        }

        // Let the (possibly guest) session that created this invoice view + pay it,
        // so enforceInvoiceAccess doesn't bounce them to /login (mirrors tours/umrah).
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
            'redirect'     => root . 'invoice/insurance/' . $invoiceId,
            'redirect_url' => root . 'invoice/insurance/' . $invoiceId,
        ], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['status' => false, 'success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
    }
});

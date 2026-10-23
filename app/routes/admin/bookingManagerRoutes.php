<?php
// ============================================================================
// app/routes/admin/bookingManagerRoutes.php
// Admin BOOKING MANAGER — staff create bookings for walk-in customers.
// Covers flights + hotels (shared logic). Two create modes:
//   • LIVE  — staff search a real supplier and book (reuses the module search +
//             the guarded issue loopback via bm_settle_booking_payment).
//   • MANUAL— trip booked elsewhere; staff type the details (airline/PNR/
//             segments/price or hotel/room/dates) → a Goglobia-branded voucher,
//             NO supplier call (module sentinel 'manual' has no issue endpoint).
// Payment per booking: Paystack DVA, or manual bank transfer. The company manual
// account + the manual-transfer option are visible ONLY to senior admins
// (admin_level >= 1); a level-0 staffer's manual-transfer booking is parked in
// 'awaiting_verification' until a senior admin verifies it.
//
// All routes ADMIN_AUTH() first; every mutation is CSRF-guarded. Money settles
// ONLY through bm_settle_booking_payment() (app/lib/booking_manager.php).
// ============================================================================

@$SECURE or die('Access Denied!');

if (!function_exists('bmAdminJson')) {
    function bmAdminJson($payload, int $code = 200): void
    {
        if (!headers_sent()) { http_response_code($code); header('Content-Type: application/json'); }
        echo json_encode($payload);
        exit;
    }
}
if (!function_exists('bmAdminCsrfOk')) {
    function bmAdminCsrfOk(): bool
    {
        $t = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        return class_exists('CSRF') && CSRF::validateToken($t);
    }
}
if (!function_exists('bmGenInvoiceId')) {
    /** 8-char uppercase hex invoice id, matching the web flow's format. */
    function bmGenInvoiceId($db): string
    {
        for ($i = 0; $i < 6; $i++) {
            $id = strtoupper(bin2hex(random_bytes(4))); // 8 hex chars
            if (!$db->has('bookings', ['invoice_id' => $id])) return $id;
        }
        return strtoupper(bin2hex(random_bytes(6)));
    }
}
if (!function_exists('bmCompanyBankAccount')) {
    /** The company manual-bank-transfer account (a bank_transfer gateway row). */
    function bmCompanyBankAccount($db): ?array
    {
        $g = $db->get('payment_gateways', '*', ['type' => 'bank_transfer', 'status' => '1']);
        if (!$g) { $g = $db->get('payment_gateways', '*', ['type' => 'bank_transfer']); }
        if (!$g) return null;
        return [
            'account_holder' => (string) ($g['c1'] ?? ''),
            'routing'        => (string) ($g['c2'] ?? ''),
            'account_number' => (string) ($g['c3'] ?? ''),
            'bank_address'   => (string) ($g['c4'] ?? ''),
            'iban_swift'     => (string) ($g['c5'] ?? ''),
            'gateway_id'     => (int) ($g['id'] ?? 0),
            'gateway_name'   => (string) ($g['name'] ?? 'bank_transfer'),
        ];
    }
}

// ---------------------------------------------------------------------------
// GET /admin/booking-manager/flights  and  /admin/booking-manager/hotels
// The manager console. Lists the active suppliers (for live mode), the company
// bank account (senior admins only), and recent admin-created bookings.
// ---------------------------------------------------------------------------
$bmRenderManager = function ($svc) use ($SECURE, $db) {
    ADMIN_AUTH();
    $serviceType = ($svc === 'hotels') ? 'stays' : 'flights';

    // Active suppliers for LIVE mode (data-driven, same as the storefront fan-out).
    $suppliers = $db->select('modules', ['name'], ['type' => $serviceType, 'status' => 1, 'active' => 1]) ?: [];
    $supplierNames = array_values(array_unique(array_map(fn($m) => strtolower($m['name']), $suppliers)));

    $isSenior = bm_is_senior_admin($db);
    $company  = $isSenior ? bmCompanyBankAccount($db) : null;

    // Recent admin-created bookings of this service.
    $recent = $db->select('bookings',
        ['invoice_id', 'first_name', 'last_name', 'email', 'module', 'booking_channel', 'booking_status', 'payment_status', 'price_markup', 'currency_markup', 'pnr', 'created_at'],
        ['module_type' => $serviceType, 'booking_channel[~]' => 'admin', 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 100]
    ) ?: [];

    $bmData = [
        'service'        => $svc,                 // 'flights' | 'hotels'
        'service_type'   => $serviceType,         // 'flights' | 'stays'
        'suppliers'      => $supplierNames,
        'is_senior'      => $isSenior,
        'admin_level'    => bm_current_admin_level($db),
        'company_bank'   => $company,
        'recent'         => $recent,
        'base_currency'  => (function () use ($db) {
            $c = $db->get('currencies', ['name'], ['default' => 1]);
            return $c['name'] ?? 'NGN';
        })(),
    ];

    $title = ucfirst($svc) . ' Booking Manager';
    $description = '';
    $header = true; $footer = true;
    $GLOBALS['bmData'] = $bmData;
    require_once views . 'includes/header.php';
    require_once views . 'admin/booking-manager/manager.php';
    require_once views . 'includes/footer.php';
};

$router->get(admin . '/booking-manager/flights', function () use ($bmRenderManager) { $bmRenderManager('flights'); });
$router->get(admin . '/booking-manager/hotels',  function () use ($bmRenderManager) { $bmRenderManager('hotels'); });

// ---------------------------------------------------------------------------
// POST /admin/booking-manager/search — proxy a LIVE supplier search for staff.
// Server-side loopback to the module search (so staff get the same offers the
// website would). Returns the raw module JSON for the console to render.
// ---------------------------------------------------------------------------
$router->post(admin . '/booking-manager/search', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!bmAdminCsrfOk()) { bmAdminJson(['status' => false, 'message' => 'Security token expired — refresh and retry.'], 403); }

    $serviceType = (strtolower((string) ($_POST['service'] ?? 'flights')) === 'hotels') ? 'stays' : 'flights';
    $supplier    = strtolower(trim((string) ($_POST['supplier'] ?? '')));
    if ($supplier === '') { bmAdminJson(['status' => false, 'message' => 'Choose a supplier to search.'], 422); }

    // Only a real, enabled supplier with a search endpoint.
    $mod = $db->get('modules', ['name'], ['name' => $supplier, 'type' => $serviceType, 'status' => 1, 'active' => 1]);
    $searchFile = dirname(__DIR__, 3) . '/modules/' . $serviceType . '/' . $supplier . '/search.php';
    if (!$mod || !is_file($searchFile)) { bmAdminJson(['status' => false, 'message' => 'That supplier is not available for live search.'], 422); }

    // Forward the search params to the module search over an internal loopback.
    $params = $_POST;
    unset($params['csrf_token'], $params['service'], $params['supplier']);
    $url = rtrim(root, '/') . '/modules/' . $serviceType . '/' . $supplier . '/search';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 0 || $raw === false) { bmAdminJson(['status' => false, 'message' => 'Supplier search did not respond.'], 502); }
    // Pass the module response straight through (console knows how to render it).
    if (!headers_sent()) { header('Content-Type: application/json'); }
    echo $raw;
    exit;
});

// ---------------------------------------------------------------------------
// POST /admin/booking-manager/create — create a booking for a walk-in customer.
// Mode MANUAL: build the booking_data + travellers from typed fields, store a
//   non-supplier sentinel module so the issue-gate skips any supplier call, and
//   produce a confirmed, branded booking.
// Mode LIVE: persist a draft booking row against the chosen supplier offer
//   (booking_data carries the offer handle) so the subsequent issue loopback can
//   book it. (Issue only fires after payment, via bm_settle_booking_payment.)
// Returns the invoice_id; the console then starts the payment step.
// ---------------------------------------------------------------------------
$router->post(admin . '/booking-manager/create', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!bmAdminCsrfOk()) { bmAdminJson(['status' => false, 'message' => 'Security token expired — refresh and retry.'], 403); }

    $mode        = strtolower(trim((string) ($_POST['mode'] ?? 'manual')));          // 'manual' | 'live'
    $service     = strtolower(trim((string) ($_POST['service'] ?? 'flights')));       // 'flights' | 'hotels'
    $serviceType = ($service === 'hotels') ? 'stays' : 'flights';
    $adminId     = (string) ($_SESSION['user_id'] ?? '');

    // Submit-idempotency: the console sends a stable client token per attempt so a
    // double-submit cannot create two bookings (there is no natural dedup here).
    $clientToken = trim((string) ($_POST['client_token'] ?? ''));
    if ($clientToken !== '') {
        $dupe = $db->get('bookings', ['invoice_id'], ['transaction_id' => 'BMCT-' . $clientToken]);
        if ($dupe) { bmAdminJson(['status' => true, 'invoice_id' => $dupe['invoice_id'], 'deduped' => true, 'message' => 'Booking already created.']); }
    }

    // ---- Customer / contact (collected for every booking) -------------------
    $firstName = trim((string) ($_POST['first_name'] ?? ''));
    $lastName  = trim((string) ($_POST['last_name'] ?? ''));
    $email     = trim((string) ($_POST['email'] ?? ''));
    $phone     = trim((string) ($_POST['phone'] ?? ''));
    $phoneCc   = trim((string) ($_POST['phone_country_code'] ?? ''));
    if ($firstName === '' || $lastName === '' || $email === '') {
        bmAdminJson(['status' => false, 'message' => 'Customer first name, last name and email are required.'], 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        bmAdminJson(['status' => false, 'message' => 'Enter a valid customer email.'], 422);
    }

    // ---- Pricing (staff enter the selling price; base currency) -------------
    $baseCurrency = (function () use ($db) { $c = $db->get('currencies', ['name'], ['default' => 1]); return $c['name'] ?? 'NGN'; })();
    $currency     = strtoupper(trim((string) ($_POST['currency'] ?? $baseCurrency))) ?: $baseCurrency;
    $sellPrice    = round((float) ($_POST['price'] ?? 0), 2);           // all-in selling price
    $costPrice    = round((float) ($_POST['cost_price'] ?? $sellPrice), 2); // optional net cost
    if ($sellPrice <= 0) { bmAdminJson(['status' => false, 'message' => 'Enter a selling price greater than zero.'], 422); }

    // ---- Passengers / guests (shape mirrors the web travellers JSON) --------
    $paxRaw = $_POST['passengers'] ?? '[]';
    if (is_string($paxRaw)) { $paxRaw = json_decode($paxRaw, true); }
    if (!is_array($paxRaw)) { $paxRaw = []; }

    $travellers = [];
    $adults = 0; $children = 0; $infants = 0;
    foreach ($paxRaw as $i => $p) {
        if (!is_array($p)) continue;
        $type = strtolower((string) ($p['type'] ?? 'adult'));
        $key  = ($type === 'child' ? 'child_' : ($type === 'infant' ? 'infant_' : 'adult_'))
              . (($type === 'child' ? $children : ($type === 'infant' ? $infants : $adults)));
        $entry = [
            'title'      => trim((string) ($p['title'] ?? 'Mr')),
            'first_name' => trim((string) ($p['first_name'] ?? '')),
            'last_name'  => trim((string) ($p['last_name'] ?? '')),
        ];
        // Optional identity — only when provided (domestic = name/contact only).
        foreach (['dob', 'nationality', 'passport_number', 'passport_expiry'] as $k) {
            if (!empty($p[$k])) $entry[$k] = trim((string) $p[$k]);
        }
        if ($entry['first_name'] === '' || $entry['last_name'] === '') {
            bmAdminJson(['status' => false, 'message' => 'Every passenger needs a first and last name.'], 422);
        }
        $travellers[$key] = $entry;
        if ($type === 'child') $children++; elseif ($type === 'infant') $infants++; else $adults++;
    }
    if ($adults + $children + $infants === 0) {
        // At least the lead passenger from the contact.
        $travellers['adult_0'] = ['title' => 'Mr', 'first_name' => $firstName, 'last_name' => $lastName];
        $adults = 1;
    }
    $travellers['primary_guest'] = ['title' => 'Mr', 'first_name' => $firstName, 'last_name' => $lastName, 'email' => $email, 'phone' => $phone];

    // ---- Build booking_data + module per mode -------------------------------
    if ($mode === 'live') {
        // LIVE: the console passes the chosen offer's booking_data (from the
        // module search result) as JSON; we persist it so the issue loopback can
        // book it. The module is the real supplier (must have an issue endpoint).
        $supplier = strtolower(trim((string) ($_POST['supplier'] ?? '')));
        $offerBd  = $_POST['offer_booking_data'] ?? '';
        if (is_string($offerBd)) { $offerBd = json_decode($offerBd, true); }
        if ($supplier === '' || !is_array($offerBd) || empty($offerBd)) {
            bmAdminJson(['status' => false, 'message' => 'Live booking needs a selected supplier offer.'], 422);
        }
        if (!bm_module_has_issue_endpoint($serviceType, $supplier)) {
            bmAdminJson(['status' => false, 'message' => 'That supplier cannot be live-booked.'], 422);
        }
        $moduleName  = $supplier;
        $bookingChannel = 'admin_live';
        $bookingData = ['flight_data' => $offerBd, 'booking_data' => $offerBd, 'admin_created' => true];
        if ($serviceType === 'stays') {
            // Hotels: the console sends the CHOSEN room rate (carries rate_key) as
            // offer_booking_data + selected_rooms=[room], plus a hotel_context blob.
            // Persist both the raw room (so the supplier issue reads its rate_key)
            // AND a voucher-friendly selected_rooms[].option shape + hotel fields.
            $rooms = $_POST['selected_rooms'] ?? [];
            if (is_string($rooms)) { $rooms = json_decode($rooms, true) ?: []; }
            $ctx = $_POST['hotel_context'] ?? '';
            if (is_string($ctx)) { $ctx = json_decode($ctx, true) ?: []; }
            $room0 = (is_array($rooms) && isset($rooms[0]) && is_array($rooms[0])) ? $rooms[0] : [];
            $voucherRooms = [[
                'room_name'  => (string) ($room0['room_id'] ?? $room0['room_name'] ?? 'Room'),
                'quantity'   => 1,
                'booking_data' => $offerBd,
                'option'     => [
                    'board_name'    => (string) ($room0['board_name'] ?? ''),
                    'rate_comments' => (string) ($room0['rate_comments'] ?? ''),
                    'booking_data'  => $offerBd,
                ],
            ]];
            $bookingData = [
                'admin_created'  => true,
                'booking_data'   => $offerBd,
                'selected_rooms' => $voucherRooms,
                'raw_room'       => $room0,
                'hotel_name'     => (string) ($ctx['name'] ?? ''),
                'hotel_stars'    => (int) ($ctx['stars'] ?? 0),
                'hotel_address'  => (string) ($ctx['address'] ?? ''),
                'hotel_city'     => (string) ($ctx['location'] ?? ''),
                'checkin'        => (string) ($ctx['checkin'] ?? ''),
                'checkout'       => (string) ($ctx['checkout'] ?? ''),
            ];
        }
    } else {
        // MANUAL: typed-in details → branded voucher, NO supplier call. The
        // sentinel module 'manual' has no actions/issue.php, so the issue-gate
        // (and bm_settle) skip any supplier booking.
        $moduleName  = 'manual';
        $bookingChannel = 'admin_manual';
        $bookingData = bmBuildManualBookingData($serviceType, $_POST, $currency, $travellers);
    }

    $pnr        = trim((string) ($_POST['pnr'] ?? ''));           // manual: typed PNR; live: filled at issue
    $special    = trim((string) ($_POST['special_requests'] ?? ''));
    $invoiceId  = bmGenInvoiceId($db);

    // Optional uploaded booking slip (manual mode).
    $manualDoc = null;
    if ($mode === 'manual' && !empty($_FILES['manual_document']['name'])) {
        $manualDoc = bmStoreManualDocument($_FILES['manual_document'], $invoiceId);
    }

    // For a MANUAL booking the commission is sell - cost; live bookings compute it
    // at issue from the supplier net, so leave it on the sell/cost the staff gave.
    $commission = max(0, $sellPrice - $costPrice);

    $db->insert('bookings', [
        'invoice_id'          => $invoiceId,
        'language'            => function_exists('getCurrentLanguage') ? getCurrentLanguage() : 'en',
        'booking_status'      => ($mode === 'manual') ? 'confirmed' : 'pending',
        'payment_status'      => 'unpaid',
        'price_original'      => $costPrice,
        'price_markup'        => $sellPrice,
        'tax'                 => 0,
        'tax_type'            => 'fixed',
        'first_name'          => $firstName,
        'last_name'           => $lastName,
        'email'               => $email,
        'phone'               => $phone,
        'phone_country_code'  => $phoneCc,
        'country'             => '',
        'address'             => '',
        'adults'              => $adults,
        'childs'              => $children,
        'infants'             => (string) $infants,
        'child_ages'          => '',
        'currency_markup'     => $currency,
        'paid_at'             => null,
        'cancellation_request'=> 0,
        'cancellation_status' => 0,
        'booking_data'        => json_encode($bookingData),
        'transaction_id'      => $clientToken !== '' ? ('BMCT-' . $clientToken) : '',
        'user_id'             => null,                 // walk-in: no platform account
        'travellers'          => json_encode($travellers),
        'nationality'         => '',
        'payment_gateway'     => '',
        'module_type'         => $serviceType,
        'module'              => $moduleName,
        'pnr'                 => ($mode === 'manual') ? $pnr : '',
        'commission'          => $commission,
        'special_requests'    => $special,
        'booking_channel'     => $bookingChannel,
        'created_by_admin'    => $adminId,
        'manual_document'     => $manualDoc,
        'created_at'          => date('Y-m-d H:i:s'),
        'booking_date'        => date('Y-m-d'),
    ]);

    bmAdminJson([
        'status'     => true,
        'invoice_id' => $invoiceId,
        'mode'       => $mode,
        'amount'     => $sellPrice,
        'currency'   => $currency,
        'message'    => ($mode === 'manual')
            ? 'Manual booking created. Choose how the customer will pay.'
            : 'Live booking drafted. Choose how the customer will pay; the ticket is issued after payment.',
    ]);
});

// ---------------------------------------------------------------------------
// POST /admin/booking-manager/pay — start the payment step for a created booking.
// method = 'dva'  → create/return a per-booking Paystack DVA (reuses gia_bp_create_dva).
// method = 'manual_transfer' → SENIOR ADMINS ONLY see the company account; the
//   booking is parked in 'awaiting_verification' (staff) for a senior admin to
//   verify later. For an OFFLINE/manual booking, 'mark_paid_offline' settles it
//   directly (money handled outside the platform).
// ---------------------------------------------------------------------------
$router->post(admin . '/booking-manager/pay', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!bmAdminCsrfOk()) { bmAdminJson(['status' => false, 'message' => 'Security token expired — refresh and retry.'], 403); }

    $invoiceId = trim((string) ($_POST['invoice_id'] ?? ''));
    $method    = strtolower(trim((string) ($_POST['method'] ?? '')));
    $booking   = $invoiceId !== '' ? $db->get('bookings', '*', ['invoice_id' => $invoiceId]) : null;
    if (!$booking) { bmAdminJson(['status' => false, 'message' => 'Booking not found.'], 404); }
    if (strtolower((string) ($booking['payment_status'] ?? '')) === 'paid') {
        bmAdminJson(['status' => true, 'already_paid' => true, 'message' => 'This booking is already paid.']);
    }

    $isManualBooking = strtolower((string) ($booking['module'] ?? '')) === 'manual';

    if ($method === 'dva') {
        require_once dirname(__DIR__, 3) . '/app/lib/ai/gia_booking_payment.php';
        if (!function_exists('gia_bp_create_dva')) { bmAdminJson(['status' => false, 'message' => 'Bank-transfer (virtual account) is unavailable.'], 503); }
        $dva = gia_bp_create_dva($db, $invoiceId, 'admin', (string) ($_SESSION['user_id'] ?? ''));
        if (empty($dva['ok'])) {
            bmAdminJson(['status' => false, 'message' => $dva['message'] ?? 'Could not create a virtual account.', 'not_ngn' => !empty($dva['not_ngn'])], 422);
        }
        bmAdminJson(['status' => true, 'method' => 'dva', 'dva' => $dva,
            'message' => 'Share this virtual account with the customer. The booking confirms automatically once the transfer lands.']);
    }

    if ($method === 'manual_transfer') {
        // Company account is SENIOR-ADMIN only; a staffer may still START a manual
        // transfer, but the booking is parked for senior verification.
        $isSenior = bm_is_senior_admin($db);
        $company  = $isSenior ? bmCompanyBankAccount($db) : null;

        $db->update('bookings', [
            'payment_status'  => 'awaiting_verification',
            'payment_gateway' => (string) ($company['gateway_id'] ?? ''),
        ], ['invoice_id' => $invoiceId]);

        bmAdminJson([
            'status'  => true,
            'method'  => 'manual_transfer',
            'company_bank' => $company,                 // null for level-0 staff (hidden)
            'awaiting_verification' => true,
            'message' => $isSenior
                ? 'Give the customer the company account below. After the transfer lands, verify the payment to confirm the booking.'
                : 'Manual transfer recorded. A senior admin must confirm the company account details and verify the payment.',
        ]);
    }

    if ($method === 'mark_paid_offline') {
        // OFFLINE/manual booking the office already settled outside the platform.
        // Only meaningful for a manual (no-supplier) booking.
        if (!$isManualBooking) { bmAdminJson(['status' => false, 'message' => 'Offline mark-paid is only for manual bookings.'], 422); }
        $res = bm_settle_booking_payment($db, $invoiceId, 'offline', [
            'verified_by' => $_SESSION['user_id'] ?? '', 'amount' => (float) $booking['price_markup'], 'currency' => $booking['currency_markup'] ?? 'NGN',
            'gateway_name' => 'manual_payment',
        ]);
        bmAdminJson(['status' => !empty($res['ok']), 'settle' => $res, 'message' => $res['message'] ?? '']);
    }

    bmAdminJson(['status' => false, 'message' => 'Choose a payment method.'], 422);
});

// ---------------------------------------------------------------------------
// POST /admin/booking-manager/verify — SENIOR ADMIN verifies a manual transfer.
// Settles the booking (marks paid + issues a live booking / confirms a manual
// one) via the single money primitive.
// ---------------------------------------------------------------------------
$router->post(admin . '/booking-manager/verify', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!bmAdminCsrfOk()) { bmAdminJson(['status' => false, 'message' => 'Security token expired — refresh and retry.'], 403); }
    if (!bm_is_senior_admin($db)) { bmAdminJson(['status' => false, 'message' => 'Only an admin or super-admin can verify a manual payment.'], 403); }

    $invoiceId = trim((string) ($_POST['invoice_id'] ?? ''));
    $txnRef    = trim((string) ($_POST['transaction_id'] ?? ''));
    $booking   = $invoiceId !== '' ? $db->get('bookings', '*', ['invoice_id' => $invoiceId]) : null;
    if (!$booking) { bmAdminJson(['status' => false, 'message' => 'Booking not found.'], 404); }
    if (strtolower((string) ($booking['payment_status'] ?? '')) === 'paid') {
        bmAdminJson(['status' => true, 'already_paid' => true, 'message' => 'Already verified/paid.']);
    }
    if (strtolower((string) ($booking['payment_status'] ?? '')) !== 'awaiting_verification') {
        bmAdminJson(['status' => false, 'message' => 'This booking is not awaiting verification.'], 422);
    }

    $res = bm_settle_booking_payment($db, $invoiceId, 'manual_verified', [
        'verified_by'   => $_SESSION['user_id'] ?? '',
        'transaction_id'=> $txnRef,
        'gateway_name'  => 'manual_payment',
        'amount'        => (float) $booking['price_markup'],
        'currency'      => $booking['currency_markup'] ?? 'NGN',
    ]);
    bmAdminJson(['status' => !empty($res['ok']), 'settle' => $res, 'message' => $res['message'] ?? '']);
});

// ---------------------------------------------------------------------------
// GET /admin/booking-manager/verify — the verification queue (senior only).
// ---------------------------------------------------------------------------
$router->get(admin . '/booking-manager/verify', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    $isSenior = bm_is_senior_admin($db);
    $pending = $isSenior ? ($db->select('bookings',
        ['invoice_id', 'first_name', 'last_name', 'email', 'module', 'module_type', 'booking_status', 'payment_status', 'price_markup', 'currency_markup', 'created_by_admin', 'created_at'],
        ['payment_status' => 'awaiting_verification', 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 200]
    ) ?: []) : [];

    $GLOBALS['bmVerify'] = ['is_senior' => $isSenior, 'pending' => $pending];
    $title = 'Payment Verification'; $description = ''; $header = true; $footer = true;
    require_once views . 'includes/header.php';
    require_once views . 'admin/booking-manager/verify.php';
    require_once views . 'includes/footer.php';
});

// ===========================================================================
// Helpers for MANUAL bookings (build the branded-voucher booking_data).
// ===========================================================================
if (!function_exists('bmBuildManualBookingData')) {
    /** Build booking_data matching the voucher template keys for a typed-in booking. */
    function bmBuildManualBookingData(string $serviceType, array $post, string $currency, array $travellers): array
    {
        if ($serviceType === 'stays') {
            return [
                'admin_created' => true,
                'hotel_name'    => trim((string) ($post['hotel_name'] ?? '')),
                'hotel_address' => trim((string) ($post['hotel_address'] ?? '')),
                'hotel_city'    => trim((string) ($post['hotel_city'] ?? '')),
                'hotel_country' => trim((string) ($post['hotel_country'] ?? '')),
                'hotel_stars'   => (int) ($post['hotel_stars'] ?? 0),
                'checkin'       => trim((string) ($post['checkin'] ?? '')),
                'checkout'      => trim((string) ($post['checkout'] ?? '')),
                'nights'        => (int) ($post['nights'] ?? 0),
                'board_type'    => trim((string) ($post['board_type'] ?? '')),
                'currency'      => $currency,
                'selected_rooms' => [[
                    'room_name' => trim((string) ($post['room_type'] ?? 'Room')),
                    'quantity'  => max(1, (int) ($post['rooms'] ?? 1)),
                    'option'    => [
                        'board_name'   => trim((string) ($post['board_type'] ?? '')),
                        'rate_comments'=> trim((string) ($post['rate_comments'] ?? '')),
                    ],
                ]],
                'total_amount'  => round((float) ($post['price'] ?? 0), 2),
            ];
        }

        // FLIGHT manual booking → the voucher reads flight_data.routes[0].segments.
        $segsRaw = $post['segments'] ?? '[]';
        if (is_string($segsRaw)) { $segsRaw = json_decode($segsRaw, true); }
        if (!is_array($segsRaw)) { $segsRaw = []; }

        $segments = [];
        foreach ($segsRaw as $s) {
            if (!is_array($s)) continue;
            $segments[] = [
                'from_airport'       => strtoupper(trim((string) ($s['from'] ?? ''))),
                'to_airport'         => strtoupper(trim((string) ($s['to'] ?? ''))),
                'departure_datetime' => trim((string) ($s['depart'] ?? '')),
                'arrival_datetime'   => trim((string) ($s['arrive'] ?? '')),
                'airline_name'       => trim((string) ($s['airline'] ?? '')),
                'airline_code'       => strtoupper(trim((string) ($s['airline_code'] ?? ''))),
                'flight_number'      => trim((string) ($s['flight_number'] ?? '')),
                'arrival_code'       => strtoupper(trim((string) ($s['to'] ?? ''))),
            ];
        }
        $first = $segments[0] ?? [];
        $last  = $segments[count($segments) - 1] ?? [];

        // passengers array for the voucher (mirrors the web 'passengers' key).
        $pax = [];
        foreach ($travellers as $k => $t) {
            if ($k === 'primary_guest' || !is_array($t)) continue;
            $pax[] = [
                'id'         => $k,
                'title'      => $t['title'] ?? 'Mr',
                'first_name' => $t['first_name'] ?? '',
                'last_name'  => $t['last_name'] ?? '',
                'type'       => (strpos($k, 'child') === 0 ? 'Child' : (strpos($k, 'infant') === 0 ? 'Infant' : 'Adult')),
            ];
        }

        return [
            'admin_created' => true,
            'flight_data'   => [
                'supplier'       => 'manual',
                'from'           => $first['from_airport'] ?? '',
                'to'             => $last['to_airport'] ?? '',
                'departure_date' => !empty($first['departure_datetime']) ? date('Y-m-d', strtotime($first['departure_datetime'])) : '',
                'airline_name'   => $first['airline_name'] ?? '',
                'flight_number'  => $first['flight_number'] ?? '',
                'currency'       => $currency,
                'routes'         => [['segments' => $segments]],
                'segments'       => $segments,
            ],
            'passengers'    => $pax,
        ];
    }
}

if (!function_exists('bmStoreManualDocument')) {
    /** Store an uploaded manual booking slip under uploads/, return the stored path. */
    function bmStoreManualDocument(array $file, string $invoiceId): ?string
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return null;
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'webp'];
        if (!in_array($ext, $allowed, true)) return null;
        if ((int) ($file['size'] ?? 0) > 8 * 1024 * 1024) return null; // 8 MB cap
        $dir = dirname(__DIR__, 3) . '/uploads/booking_manager';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $name = 'slip_' . $invoiceId . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (!@move_uploaded_file($file['tmp_name'], $dest)) return null;
        return 'uploads/booking_manager/' . $name;
    }
}

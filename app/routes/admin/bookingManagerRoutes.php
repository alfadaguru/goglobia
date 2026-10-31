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
if (!function_exists('bm_service_catalogue')) {
    /**
     * The Booking Manager's service catalogue: UI service name => booking row
     * module_type. Single source of truth for which services the manager can book
     * and how each maps onto the bookings table / the ticket + invoice templates.
     *   - 'hotels' is the UI label for module_type 'stays' (historic).
     *   - every other service's UI name equals its module_type.
     * $live tells whether LIVE (real supplier search+issue) is possible; visa and
     * bus have NO supplier with an issue endpoint (verified) so they are
     * MANUAL-ONLY — the console must not offer them a live tab.
     */
    function bm_service_catalogue(): array
    {
        return [
            'flights' => ['type' => 'flights', 'label' => 'Flights', 'live' => true],
            'hotels'  => ['type' => 'stays',   'label' => 'Hotels',  'live' => true],
            'tours'   => ['type' => 'tours',   'label' => 'Tours',   'live' => true],
            'cars'    => ['type' => 'cars',    'label' => 'Car Hire', 'live' => true],
            // Umrah is own-inventory v2 (quote -> hold -> umrah_booking_create ->
            // umrah_bookings + installments), NOT a supplier search/issue. Real
            // inventory bookings live in the dedicated admin umrah-manager; here the
            // BM only records an off-system umrah as a branded voucher. So: manual-only.
            'umrah'   => ['type' => 'umrah',   'label' => 'Umrah',   'live' => false],
            // Rail IS a real external supplier, but its booking CREATION is tightly
            // coupled to the /rail/order money path (server fare re-validation that
            // overwrites price_total_limit, markup, promo) which creates its own
            // customer booking row — not safely bolt-on-able to the BM without
            // duplicating money logic. MANUAL-only here until /rail/order is
            // refactored into a shared BM-callable money path (planned). Manual
            // records an off-system rail booking as a branded voucher.
            'rail'    => ['type' => 'rail',    'label' => 'Rail',    'live' => true],
            'ferries' => ['type' => 'ferries', 'label' => 'Ferries', 'live' => true],
            'esim'    => ['type' => 'esim',    'label' => 'eSIM',    'live' => true],
            'visa'    => ['type' => 'visa',    'label' => 'Visa',    'live' => false],
            'bus'     => ['type' => 'bus',     'label' => 'Bus',     'live' => false],
        ];
    }
}
if (!function_exists('bm_service_to_type')) {
    /** Map a UI service name to its bookings.module_type. Unknown => 'flights'
     *  (safe default, matching the previous behaviour). */
    function bm_service_to_type(string $service): string
    {
        $service = strtolower(trim($service));
        $cat = bm_service_catalogue();
        return $cat[$service]['type'] ?? 'flights';
    }
}
if (!function_exists('bm_service_is_valid')) {
    /** True if $service is a known Booking Manager service. */
    function bm_service_is_valid(string $service): bool
    {
        return array_key_exists(strtolower(trim($service)), bm_service_catalogue());
    }
}

// ---------------------------------------------------------------------------
// GET /admin/booking-manager/flights  and  /admin/booking-manager/hotels
// The manager console. Lists the active suppliers (for live mode), the company
// bank account (senior admins only), and recent admin-created bookings.
// ---------------------------------------------------------------------------
$bmRenderManager = function ($svc) use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'bookings', 'view');
    if (!bm_service_is_valid($svc)) { $svc = 'flights'; }
    $serviceType = bm_service_to_type($svc);

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

    $catalogue = bm_service_catalogue();
    $bmData = [
        'service'        => $svc,                 // any bm_service_catalogue() key
        'service_type'   => $serviceType,         // bookings.module_type
        'service_label'  => $catalogue[$svc]['label'] ?? ucfirst($svc),
        'service_live'   => !empty($catalogue[$svc]['live']), // false => manual-only (visa/bus)
        'catalogue'      => $catalogue,           // drives the service picker
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

    // eSIM: give the LIVE panel the same searchable country list the customer
    // storefront uses (app/views/modules/esim/esim-search.php) instead of a bare
    // free-text box — so "search countries" actually has countries to search.
    if ($svc === 'esim') {
        $bmData['esim_countries'] = $db->select(
            'airalo_countries',
            ['iso', 'nicename'],
            ['status' => 1, 'ORDER' => ['nicename' => 'ASC']]
        ) ?: [];
    }

    $title = ($catalogue[$svc]['label'] ?? ucfirst($svc)) . ' Booking Manager';
    $description = '';
    $header = true; $footer = true;
    $GLOBALS['bmData'] = $bmData;
    require_once views . 'includes/header.php';
    require_once views . 'admin/booking-manager/manager.php';
    require_once views . 'includes/footer.php';
};

// Explicit per-service manager screens for all 10 services. (A single
// parameterised catch-all would shadow the specific /booking-manager/verify,
// /search etc GET routes defined later, so each service is listed explicitly.)
foreach (array_keys(bm_service_catalogue()) as $__bmSvc) {
    $router->get(admin . '/booking-manager/' . $__bmSvc, function () use ($bmRenderManager, $__bmSvc) { $bmRenderManager($__bmSvc); });
}
unset($__bmSvc);
// Bare /booking-manager → the manager (defaults to flights / the unified picker).
$router->get(admin . '/booking-manager', function () use ($bmRenderManager) { $bmRenderManager('flights'); });

// ---------------------------------------------------------------------------
// POST /admin/booking-manager/search — proxy a LIVE supplier search for staff.
// Server-side loopback to the module search (so staff get the same offers the
// website would). Returns the raw module JSON for the console to render.
// ---------------------------------------------------------------------------
$router->post(admin . '/booking-manager/search', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'bookings', 'view');
    if (!bmAdminCsrfOk()) { bmAdminJson(['status' => false, 'message' => 'Security token expired — refresh and retry.'], 403); }

    $serviceType = bm_service_to_type((string) ($_POST['service'] ?? 'flights'));
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
    admin_require($db, 'bookings', 'add');
    if (!bmAdminCsrfOk()) { bmAdminJson(['status' => false, 'message' => 'Security token expired — refresh and retry.'], 403); }

    $mode        = strtolower(trim((string) ($_POST['mode'] ?? 'manual')));          // 'manual' | 'live'
    $service     = strtolower(trim((string) ($_POST['service'] ?? 'flights')));       // any of bm_service_catalogue()
    if (!bm_service_is_valid($service)) { $service = 'flights'; }
    $serviceType = bm_service_to_type($service);
    // LIVE is only valid for services with a real supplier/issue endpoint;
    // visa + bus are manual-only (no supplier). Force manual for those.
    $svcCat = bm_service_catalogue();
    if ($mode === 'live' && empty($svcCat[$service]['live'])) {
        $mode = 'manual';
    }
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
    // PASSENGER / TRAVELLER email — where the TICKET is delivered. Staff/agents may
    // book on behalf of a client, so the ticket must reach the TRAVELLER, not the
    // booker. If left blank, falls back to the contact email above. This becomes
    // bookings.email, which is the recipient NOTIFY sends the ticket PDF to.
    $ticketEmail = trim((string) ($_POST['ticket_email'] ?? ''));
    if ($ticketEmail !== '' && !filter_var($ticketEmail, FILTER_VALIDATE_EMAIL)) {
        bmAdminJson(['status' => false, 'message' => 'Enter a valid passenger email (or leave it blank to use the contact email).'], 422);
    }
    $recipientEmail = $ticketEmail !== '' ? $ticketEmail : $email;

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
        // ticket_number is the airline-issued flight ticket (e-ticket) number the
        // admin types for a MANUAL flight — the ticket PDF shows it per passenger
        // instead of "On ticketing". Stored under both keys the ticket template
        // reads ('ticket_number' and 'eticket') so either lookup resolves it.
        foreach (['dob', 'nationality', 'passport_number', 'passport_expiry', 'ticket_number'] as $k) {
            if (!empty($p[$k])) $entry[$k] = trim((string) $p[$k]);
        }
        if (!empty($p['ticket_number'])) { $entry['eticket'] = trim((string) $p['ticket_number']); }
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
        // RAIL is special: its booking CREATION is the money-critical order flow
        // (server fare re-validation + markup + promo + its own insert). Route it
        // through the SHARED rail_create_order() so there is ONE audited rail money
        // path — never a reimplementation. It inserts + returns here.
        if ($serviceType === 'rail') {
            $railInput = $_POST['rail_order'] ?? '';
            if (is_string($railInput)) { $railInput = json_decode($railInput, true); }
            if (!is_array($railInput) || empty($railInput['journey']) || empty($railInput['passengers'])) {
                bmAdminJson(['status' => false, 'message' => 'Select a train and seat class, and add at least one passenger.'], 422);
            }
            $railInput['contact_name']  = trim($firstName . ' ' . $lastName);
            $railInput['contact_email'] = $recipientEmail;  // ticket goes to the passenger
            $railInput['contact_phone'] = $phone;
            if (!function_exists('rail_create_order')) {
                require_once dirname(__DIR__) . '/rail/bookingRoutes.php';
            }
            if (!function_exists('rail_create_order')) {
                bmAdminJson(['status' => false, 'message' => 'Rail booking is unavailable right now.'], 500);
            }
            $railRes = rail_create_order($db, $railInput, [
                'user_id'          => '',
                'user_data'        => null,
                'sell_price'       => $sellPrice,
                'created_by_admin' => $adminId,
                'booking_channel'  => 'admin_live',
                'grant_session'    => false,
                'is_agent'         => false,
            ]);
            if (empty($railRes['ok'])) {
                bmAdminJson(['status' => false, 'message' => (string) ($railRes['msg'] ?? 'Could not create the rail booking.')], 422);
            }
            bmAdminJson([
                'status'     => true,
                'invoice_id' => $railRes['invoice_id'],
                'mode'       => 'live',
                'amount'     => $sellPrice,
                'currency'   => $currency,
                'message'    => 'Rail booking drafted. Choose how the customer will pay; the ticket is issued after payment.',
            ]);
        }
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
            // Hotels: the console sends the CHOSEN rate as selected_rooms=
            //   [{room_name, quantity, option:{<handle>, total_price, board_name,...}}]
            // plus offer_booking_data (the same option) and a hotel_context blob.
            // We persist the option VERBATIM so each supplier's issue.php finds the
            // booking handle under the key IT uses — it is supplier-specific:
            //   hotelbeds/hotelston -> option.rate_key
            //   ratehawk            -> option.book_hash
            //   tbo-holidays        -> option.booking_code
            //   wanderbeds          -> option.offer_id
            //   stuba/adivaha       -> option.booking_data.{room_id,rate_key,...}
            // So we must NOT require a specific key — only that SOME handle exists.
            $rooms = $_POST['selected_rooms'] ?? [];
            if (is_string($rooms)) { $rooms = json_decode($rooms, true) ?: []; }
            $ctx = $_POST['hotel_context'] ?? '';
            if (is_string($ctx)) { $ctx = json_decode($ctx, true) ?: []; }
            if (!is_array($rooms) || !isset($rooms[0]) || !is_array($rooms[0])
                || empty($rooms[0]['option']) || !is_array($rooms[0]['option'])) {
                bmAdminJson(['status' => false, 'message' => 'Live hotel booking needs a selected room rate.'], 422);
            }
            $opt = $rooms[0]['option'];
            // Accept ANY of the known supplier handle keys (flat or nested under
            // booking_data). Reject only when NONE is present — that means the
            // console sent a room group instead of a bookable rate, which would
            // strand the booking at issue after payment.
            $bd = (isset($opt['booking_data']) && is_array($opt['booking_data'])) ? $opt['booking_data'] : [];
            $handle = '';
            foreach (['rate_key', 'book_hash', 'booking_code', 'offer_id', 'id'] as $k) {
                if ((string) ($opt[$k] ?? ($bd[$k] ?? '')) !== '') { $handle = (string) ($opt[$k] ?? $bd[$k]); break; }
            }
            if ($handle === '' && empty($bd)) {
                bmAdminJson(['status' => false, 'message' => 'That room rate is missing its booking reference — reselect the rate and try again.'], 422);
            }
            $voucherRooms = [[
                'room_name'    => (string) ($rooms[0]['room_name'] ?? 'Room'),
                'quantity'     => max(1, (int) ($rooms[0]['quantity'] ?? 1)),
                'booking_data' => $opt,                 // carries rate_key
                'option'       => $opt,                 // issue.php reads option.rate_key
            ]];
            $bookingData = [
                'admin_created'  => true,
                'booking_data'   => $opt,
                'selected_rooms' => $voucherRooms,
                'hotel_name'     => (string) ($ctx['name'] ?? ''),
                'hotel_stars'    => (int) ($ctx['stars'] ?? 0),
                'hotel_address'  => (string) ($ctx['address'] ?? ''),
                'hotel_city'     => (string) ($ctx['location'] ?? ''),
                'checkin'        => (string) ($ctx['checkin'] ?? ''),
                'checkout'       => (string) ($ctx['checkout'] ?? ''),
            ];
        } elseif ($serviceType === 'tours') {
            // LIVE TOURS: the console sends the chosen offer's opaque handle as
            // offer_booking_data (carries offer_id/destination/activity_date that
            // tours/<supplier>/issue reads under booking_data['booking_data']),
            // plus a tour_context blob for the display keys the ticket/invoice
            // render. Supplier identity stays out of the displayed fields.
            $ctx = $_POST['tour_context'] ?? '';
            if (is_string($ctx)) { $ctx = json_decode($ctx, true) ?: []; }
            if (!is_array($ctx)) { $ctx = []; }
            // The handle must identify the offer, or issue cannot book it.
            if (empty($offerBd['offer_id']) && empty($offerBd['tour_id']) && empty($offerBd['original_id'])) {
                bmAdminJson(['status' => false, 'message' => 'That tour is missing its booking reference — reselect the tour and try again.'], 422);
            }
            $bookingData = [
                'admin_created'   => true,
                'booking_data'    => $offerBd,            // issue reads booking_data['booking_data']['offer_id']
                'tour_name'       => (string) ($ctx['name'] ?? ''),
                'destination'     => (string) ($ctx['location'] ?? $ctx['city'] ?? ''),
                'tour_type'       => (string) ($ctx['tour_type'] ?? 'Activity'),
                'start_date'      => (string) ($ctx['activity_date'] ?? $offerBd['activity_date'] ?? ''),
                'duration'        => (string) ($ctx['duration'] ?? ''),
                'pickup_location' => (string) ($ctx['location'] ?? ''),
                'adults'          => (int) ($offerBd['adults'] ?? $adults),
                'children'        => (int) ($offerBd['children'] ?? $children),
            ];
        } elseif ($serviceType === 'cars') {
            // LIVE CARS: the chosen offer card is the handle. cartrawler/issue reads
            // booking_data.car_data.reference_id; mozio/issue reads search_id/
            // result_id — both live inside the offer card — so we persist the whole
            // card under car_data (mirrors the web cars persist) + search_params +
            // the display keys the ticket/invoice render.
            $ctx = $_POST['car_context'] ?? '';
            if (is_string($ctx)) { $ctx = json_decode($ctx, true) ?: []; }
            if (!is_array($ctx)) { $ctx = []; }
            // The card must carry SOME supplier handle, or issue can't book it.
            $hasHandle = !empty($offerBd['reference_id']) || !empty($offerBd['result_id'])
                      || !empty($offerBd['search_id']) || !empty($offerBd['id']);
            if (!$hasHandle) {
                bmAdminJson(['status' => false, 'message' => 'That car offer is missing its booking reference — reselect and try again.'], 422);
            }
            $bookingData = [
                'admin_created'    => true,
                'car_data'         => $offerBd,           // issue reads car_data.reference_id / search_id
                'search_params'    => is_array($ctx['search_params'] ?? null) ? $ctx['search_params'] : $ctx,
                'car_name'         => (string) ($offerBd['name'] ?? $offerBd['car_name'] ?? ($ctx['car_name'] ?? '')),
                'transmission'     => (string) ($offerBd['transmission'] ?? ($ctx['transmission'] ?? '')),
                'vehicle_type'     => (string) ($offerBd['vehicle_type'] ?? ($ctx['vehicle_type'] ?? '')),
                'pickup_location'  => (string) ($ctx['pickup_location'] ?? ''),
                'dropoff_location' => (string) ($ctx['dropoff_location'] ?? ($ctx['pickup_location'] ?? '')),
                'pickup_date'      => (string) ($ctx['pickup_date'] ?? ''),
                'pickup_time'      => (string) ($ctx['pickup_time'] ?? ''),
                'dropoff_date'     => (string) ($ctx['dropoff_date'] ?? ''),
                'dropoff_time'     => (string) ($ctx['dropoff_time'] ?? ''),
                'driver_age'       => (int) ($ctx['driver_age'] ?? 0),
            ];
        } elseif ($serviceType === 'esim') {
            // LIVE ESIM: the chosen package IS the handle. airalo/issue reads
            // booking_data.airalo_order.package_id — mirror the web esim persist.
            $ctx = $_POST['esim_context'] ?? '';
            if (is_string($ctx)) { $ctx = json_decode($ctx, true) ?: []; }
            if (!is_array($ctx)) { $ctx = []; }
            $packageId = (string) ($offerBd['id'] ?? $offerBd['package_id'] ?? '');
            if ($packageId === '') {
                bmAdminJson(['status' => false, 'message' => 'That eSIM package is missing its id — reselect and try again.'], 422);
            }
            $bookingData = [
                'admin_created' => true,
                'airalo_order'  => ['package_id' => $packageId, 'quantity' => 1, 'type' => 'sim'],
                'package_name'  => (string) ($offerBd['title'] ?? ($ctx['package_name'] ?? '')),
                'country'       => (string) ($offerBd['country'] ?? ($ctx['country'] ?? '')),
                'data_amount'   => (string) ($offerBd['data'] ?? ($ctx['data_amount'] ?? '')),
                'validity'      => (string) ($offerBd['validity'] ?? ($ctx['validity'] ?? '')),
            ];
        } elseif ($serviceType === 'ferries') {
            // LIVE FERRIES: persist the chosen sailing + passenger ticket-types.
            // kikoto/issue re-prices at issue (reconcilePostPaymentPrice) and books,
            // so here we only persist selected_sailing + passengers + the display
            // ports/date. $offerBd is the chosen sailing offer card.
            $ctx = $_POST['ferry_context'] ?? '';
            if (is_string($ctx)) { $ctx = json_decode($ctx, true) ?: []; }
            if (!is_array($ctx)) { $ctx = []; }
            $ferryPax = $_POST['ferry_passengers'] ?? '';
            if (is_string($ferryPax)) { $ferryPax = json_decode($ferryPax, true) ?: []; }
            if (empty($offerBd) || empty($ferryPax)) {
                bmAdminJson(['status' => false, 'message' => 'Select a sailing and at least one passenger ticket type.'], 422);
            }
            $bookingData = [
                'admin_created'     => true,
                'confirmed'         => false,
                'selected_sailing'  => $offerBd,
                'passengers'        => $ferryPax,
                'revalidated_price' => (float) ($ctx['price'] ?? 0),
                'departure_port_id' => (int) ($ctx['departure_port_id'] ?? 0),
                'destination_port_id' => (int) ($ctx['destination_port_id'] ?? 0),
                // Display keys for the ticket/invoice (ferries templates read draft.*).
                'draft' => [
                    'from_name'      => (string) ($ctx['from_name'] ?? ''),
                    'to_name'        => (string) ($ctx['to_name'] ?? ''),
                    'date'           => (string) ($ctx['date'] ?? ''),
                    'departure_time' => (string) ($ctx['departure_time'] ?? ''),
                ],
                'reference' => '',
            ];
        }
    } else {
        // MANUAL: typed-in details → branded voucher, NO supplier call. The
        // sentinel module 'manual' has no actions/issue.php, so the issue-gate
        // (and bm_settle) skip any supplier booking.
        //
        // eSIM GUARD: an eSIM cannot be hand-fabricated — a manual eSIM is only
        // valid when it RECORDS one provisioned elsewhere (e.g. on the Airalo
        // dashboard). So require a real credential: an ICCID or an activation
        // reference. Without one there is nothing the customer can install, and
        // the voucher would falsely read "Active/Ready". Reject here.
        if ($serviceType === 'esim') {
            $mIccid = trim((string) ($_POST['iccid'] ?? ''));
            $mRef   = trim((string) ($_POST['reference'] ?? ''));
            if ($mIccid === '' && $mRef === '') {
                bmAdminJson(['status' => false, 'message' => 'A manual eSIM needs a real credential — paste the ICCID or the activation reference of an eSIM you already provisioned (e.g. on the Airalo dashboard). To issue a brand-new eSIM automatically, use Live mode instead.'], 422);
            }
        }
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
        'email'               => $recipientEmail,  // ticket recipient = passenger email (fallback: contact)
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
    admin_require($db, 'bookings', 'edit');
    if (!bmAdminCsrfOk()) { bmAdminJson(['status' => false, 'message' => 'Security token expired — refresh and retry.'], 403); }

    $invoiceId = trim((string) ($_POST['invoice_id'] ?? ''));
    $method    = strtolower(trim((string) ($_POST['method'] ?? '')));
    $booking   = $invoiceId !== '' ? $db->get('bookings', '*', ['invoice_id' => $invoiceId]) : null;
    if (!$booking) { bmAdminJson(['status' => false, 'message' => 'Booking not found.'], 404); }
    if (strtolower((string) ($booking['payment_status'] ?? '')) === 'paid') {
        bmAdminJson(['status' => true, 'already_paid' => true, 'message' => 'This booking is already paid.']);
    }

    $isManualBooking = strtolower((string) ($booking['module'] ?? '')) === 'manual';

    // MUTUAL EXCLUSION OF TENDERS (money-safety). A booking must carry only ONE
    // live payment method at a time, or the customer could be given both a virtual
    // account AND the company bank details and pay twice (the DVA webhook and the
    // senior-verify settler key on different columns, so only one gets reconciled).
    //   - If a manual transfer is already parked (awaiting_verification), refuse a
    //     new tender; a senior must verify or it must be reset.
    //   - If a DVA already exists for this invoice, refuse manual_transfer.
    $pstatus = strtolower((string) ($booking['payment_status'] ?? ''));
    if (in_array($method, ['dva', 'manual_transfer'], true) && $pstatus === 'awaiting_verification') {
        bmAdminJson(['status' => false, 'message' => 'A manual transfer is already awaiting verification for this booking. Verify (or reset) it before starting another payment method.'], 409);
    }
    if ($method === 'manual_transfer') {
        require_once dirname(__DIR__, 3) . '/app/lib/ai/gia_booking_payment.php';
        $existingDva = function_exists('gia_bp_get') ? gia_bp_get($db, $invoiceId) : null;
        if (is_array($existingDva) && in_array(strtolower((string) ($existingDva['status'] ?? '')), ['pending', 'paid'], true)) {
            bmAdminJson(['status' => false, 'message' => 'A virtual account was already issued for this booking. Use that, or wait for it to expire, before taking a manual transfer.'], 409);
        }
    }

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
    admin_require($db, 'bookings', 'edit');
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
// POST /admin/booking-manager/retry-issue — senior re-fires the supplier issue
// for a PAID-but-unissued live booking (issue failed after payment). Safe: the
// money primitive's issue-only recovery path re-issues without re-charging.
// ---------------------------------------------------------------------------
$router->post(admin . '/booking-manager/retry-issue', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'bookings', 'edit');
    if (!bmAdminCsrfOk()) { bmAdminJson(['status' => false, 'message' => 'Security token expired — refresh and retry.'], 403); }
    if (!bm_is_senior_admin($db)) { bmAdminJson(['status' => false, 'message' => 'Only an admin or super-admin can retry an issue.'], 403); }

    $invoiceId = trim((string) ($_POST['invoice_id'] ?? ''));
    $booking   = $invoiceId !== '' ? $db->get('bookings', '*', ['invoice_id' => $invoiceId]) : null;
    if (!$booking) { bmAdminJson(['status' => false, 'message' => 'Booking not found.'], 404); }
    if (strtolower((string) ($booking['payment_status'] ?? '')) !== 'paid') {
        bmAdminJson(['status' => false, 'message' => 'Only a PAID booking can be re-issued.'], 422);
    }
    if (!empty($booking['pnr'])) {
        bmAdminJson(['status' => true, 'already_issued' => true, 'message' => 'This booking already has a PNR.']);
    }
    // method is cosmetic here — bm_settle takes the issue-only recovery path for a
    // paid live booking with no PNR, and never re-charges / re-audits.
    // refund_if_fail=1 = the senior's explicit "give up and refund if it fails
    // again" decision (e.g. a package permanently withdrawn). Without it, a failed
    // retry stays paid+pending for another retry (and the stranded cron keeps
    // trying) — the refund is a deliberate human choice, never automatic on a
    // transient glitch. Gateway-paid only; a stuck wallet booking is refunded via
    // its own agent path.
    $refundIfFail = in_array((string) ($_POST['refund_if_fail'] ?? ''), ['1', 'true', 'yes', 'on'], true);
    $res = bm_settle_booking_payment($db, $invoiceId, 'manual_verified', [
        'amount'         => (float) $booking['price_markup'],
        'currency'       => $booking['currency_markup'] ?? 'NGN',
        'refund_on_fail' => $refundIfFail,
    ]);
    bmAdminJson([
        'status'   => !empty($res['ok']) || !empty($res['refunded']),
        'issued'   => !empty($res['issued']),
        'refunded' => !empty($res['refunded']),
        'settle'   => $res,
        'message'  => $res['message'] ?? '',
    ]);
});

// ---------------------------------------------------------------------------
// GET /admin/booking-manager/verify — the verification queue (senior only).
// ---------------------------------------------------------------------------
$router->get(admin . '/booking-manager/verify', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    admin_require($db, 'bookings', 'view');
    $isSenior = bm_is_senior_admin($db);
    $cols = ['invoice_id', 'first_name', 'last_name', 'email', 'module', 'module_type', 'booking_status', 'payment_status', 'price_markup', 'currency_markup', 'created_by_admin', 'pnr', 'created_at'];
    $pending = $isSenior ? ($db->select('bookings', $cols,
        ['payment_status' => 'awaiting_verification', 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 200]
    ) ?: []) : [];

    // Ops queue: PAID live bookings that never got a PNR (issue failed after
    // payment). Money is collected but the trip was not bought — surface it so a
    // senior can retry the issue or refund, instead of it hiding in the error log.
    // A live booking that issued becomes booking_status='confirmed' (+ a PNR), so
    // "paid AND not confirmed AND admin_live" is the reliable stranded signal —
    // no fragile pnr null-vs-'' test needed.
    $stranded = $isSenior ? ($db->select('bookings', $cols, [
        'payment_status' => 'paid',
        'booking_status[!]' => 'confirmed',
        'booking_channel[~]' => 'admin_live',
        'ORDER' => ['id' => 'DESC'], 'LIMIT' => 200,
    ]) ?: []) : [];

    $GLOBALS['bmVerify'] = ['is_senior' => $isSenior, 'pending' => $pending, 'stranded' => $stranded];
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

        // ---- The 8 other services: typed-in booking_data matching each service's
        //      ticket + invoice template keys (verified against the real templates).
        if ($serviceType === 'tours') {
            return [
                'admin_created'   => true,
                'tour_name'       => trim((string) ($post['tour_name'] ?? '')),
                'destination'     => trim((string) ($post['destination'] ?? '')),
                'start_date'      => trim((string) ($post['start_date'] ?? '')),
                'duration'        => trim((string) ($post['duration'] ?? '')),
                'tour_type'       => trim((string) ($post['tour_type'] ?? '')),
                'pickup_location' => trim((string) ($post['pickup_location'] ?? '')),
                'adults'          => (int) ($post['adults'] ?? 0),
                'children'        => (int) ($post['children'] ?? 0),
            ];
        }
        if ($serviceType === 'cars') {
            return [
                'admin_created'    => true,
                'car_name'         => trim((string) ($post['car_name'] ?? '')),
                'transmission'     => trim((string) ($post['transmission'] ?? '')),
                'vehicle_type'     => trim((string) ($post['vehicle_type'] ?? '')),
                'pickup_location'  => trim((string) ($post['pickup_location'] ?? '')),
                'dropoff_location' => trim((string) ($post['dropoff_location'] ?? ($post['pickup_location'] ?? ''))),
                'pickup_date'      => trim((string) ($post['pickup_date'] ?? '')),
                'pickup_time'      => trim((string) ($post['pickup_time'] ?? '')),
                'dropoff_date'     => trim((string) ($post['dropoff_date'] ?? '')),
                'dropoff_time'     => trim((string) ($post['dropoff_time'] ?? '')),
                'driver_age'       => (int) ($post['driver_age'] ?? 0),
            ];
        }
        if ($serviceType === 'visa') {
            // Applicants = the passengers the staff entered.
            $applicants = [];
            foreach ($travellers as $k => $t) {
                if ($k === 'primary_guest' || !is_array($t)) continue;
                $applicants[] = [
                    'first_name'      => $t['first_name'] ?? '',
                    'last_name'       => $t['last_name'] ?? '',
                    'nationality'     => $t['nationality'] ?? '',
                    'passport_number' => $t['passport_number'] ?? '',
                ];
            }
            return [
                'admin_created'         => true,
                'visa_type_name'        => trim((string) ($post['visa_type_name'] ?? '')),
                'from_country_name'     => trim((string) ($post['from_country_name'] ?? '')),
                'to_country_name'       => trim((string) ($post['to_country_name'] ?? '')),
                'entry_date'            => trim((string) ($post['entry_date'] ?? '')),
                'processing_speed_name' => trim((string) ($post['processing_speed_name'] ?? 'Standard')),
                'travelers'             => $applicants,
                'travelers_count'       => count($applicants),
            ];
        }
        if ($serviceType === 'umrah') {
            return [
                'admin_created'   => true,
                'umrah_name'      => trim((string) ($post['umrah_name'] ?? '')),
                'umrah_location'  => trim((string) ($post['umrah_location'] ?? '')),
                'start_date'      => trim((string) ($post['start_date'] ?? '')),
                'duration'        => trim((string) ($post['duration'] ?? '')),
                'total_adults'    => (int) ($post['adults'] ?? ($post['total_adults'] ?? 0)),
                'total_children'  => (int) ($post['children'] ?? ($post['total_children'] ?? 0)),
                // Component counts optional — a manual umrah may be a package summary.
                'flights'         => [],
                'stays'           => [],
                'transfers'       => [],
            ];
        }
        if ($serviceType === 'rail') {
            $depTs = !empty($post['depart']) ? strtotime((string) $post['depart']) : 0;
            $arrTs = !empty($post['arrive']) ? strtotime((string) $post['arrive']) : 0;
            $runMin = ($depTs > 0 && $arrTs > $depTs) ? (int) round(($arrTs - $depTs) / 60) : (int) ($post['run_time'] ?? 0);
            return [
                'admin_created' => true,
                'journey' => [[
                    'from_station_code'    => strtoupper(trim((string) ($post['from_station_code'] ?? ''))),
                    'to_station_code'      => strtoupper(trim((string) ($post['to_station_code'] ?? ''))),
                    'from_station_english' => trim((string) ($post['from_station'] ?? '')),
                    'to_station_english'   => trim((string) ($post['to_station'] ?? '')),
                    'from_date_time'       => $depTs,
                    'to_date_time'         => $arrTs,
                    'run_time'             => $runMin,
                    'seat_name'            => trim((string) ($post['seat_name'] ?? '')),
                    'traffic_no'           => trim((string) ($post['traffic_no'] ?? '')),
                ]],
            ];
        }
        if ($serviceType === 'bus') {
            return [
                'admin_created' => true,
                'date'  => trim((string) ($post['date'] ?? '')),
                'trip'  => [
                    'service_name'   => trim((string) ($post['service_name'] ?? '')),
                    'operator'       => trim((string) ($post['operator'] ?? '')),
                    'origin'         => trim((string) ($post['origin'] ?? '')),
                    'destination'    => trim((string) ($post['destination'] ?? '')),
                    'departure_time' => trim((string) ($post['departure_time'] ?? '')),
                    'arrival_time'   => trim((string) ($post['arrival_time'] ?? '')),
                    'bus_type'       => trim((string) ($post['bus_type'] ?? '')),
                    'seat_class'     => trim((string) ($post['seat_class'] ?? '')),
                ],
            ];
        }
        if ($serviceType === 'ferries') {
            $vehicles = max(0, (int) ($post['vehicles'] ?? 0));
            $pets     = max(0, (int) ($post['pets'] ?? 0));
            return [
                'admin_created' => true,
                'confirmed'     => true,
                'reference'     => trim((string) ($post['reference'] ?? '')),
                'draft' => [
                    'from_name'      => trim((string) ($post['from_name'] ?? '')),
                    'to_name'        => trim((string) ($post['to_name'] ?? '')),
                    'date'           => trim((string) ($post['date'] ?? '')),
                    'departure_time' => trim((string) ($post['departure_time'] ?? '')),
                ],
                // Counts as placeholder arrays so the ticket's count() reads right.
                'vehicles' => array_fill(0, $vehicles, ['type' => 'vehicle']),
                'pets'     => array_fill(0, $pets, ['type' => 'pet']),
                'locators' => [],
            ];
        }
        if ($serviceType === 'esim') {
            return [
                'admin_created' => true,
                'package_name'  => trim((string) ($post['package_name'] ?? '')),
                'country'       => trim((string) ($post['country'] ?? '')),
                'data_amount'   => trim((string) ($post['data_amount'] ?? '')),
                'validity'      => trim((string) ($post['validity'] ?? '')),
                // Manual eSIM: staff may paste the activation/ICCID if they have it.
                'airalo_provision' => [
                    'order_id' => trim((string) ($post['reference'] ?? '')),
                    'sims'     => trim((string) ($post['iccid'] ?? '')) !== ''
                        ? [['iccid' => trim((string) ($post['iccid'] ?? ''))]]
                        : [],
                ],
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

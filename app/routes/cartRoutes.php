<?php
// ============================================================================
// GENERAL CART — step 6b (docs: cart design artifact)
// ----------------------------------------------------------------------------
// A session cart for the CART-ELIGIBLE, static-priced modules: tours, visa,
// esim. (Live-priced modules — flights/stays/cars/ferries/rail/bus — stay
// instant-purchase and never appear here. Umrah keeps its own v2 cart.)
//
// The cart holds one entry per line; each line carries the module's own booking
// DRAFT payload (the exact shape that module's /save-draft stores) so checkout
// can hand each item to the existing per-module booking logic unchanged. Prices
// are ALWAYS recomputed server-side on every view/checkout — the session value
// is a cache, never trusted (same rule as the Umrah v2 cart + step-6a coupons).
//
// Endpoints (public, CSRF-guarded for browser callers):
//   POST /cart/add     { module, draft, csrf_token }
//   GET  /cart/data    → repriced cart JSON
//   POST /cart/remove  { key, csrf_token }
//   POST /cart/clear   { csrf_token }
// The cart PAGE (GET /cart) and checkout live in their own files.
// ============================================================================

@$SECURE or die('Access Denied!');

// ---- small local helpers (guarded; mirror the umrah v1 cart conventions) ----
if (!function_exists('cart_json')) {
    function cart_json($payload, int $code = 200): void
    {
        if (!headers_sent()) { http_response_code($code); header('Content-Type: application/json'); }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
if (!function_exists('cart_body')) {
    function cart_body(): array
    {
        $raw = file_get_contents('php://input');
        $j = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($j) ? $j : $_POST;
    }
}
if (!function_exists('cart_csrf_guard')) {
    /**
     * Browser (cookie/session) callers MUST carry a valid CSRF token. A Bearer
     * token client is not cookie-authenticated and is exempt (same policy as the
     * umrah v1 cart). Dies 403 otherwise.
     */
    function cart_csrf_guard(array $in): void
    {
        $headers = function_exists('getallheaders') ? array_change_key_case((array) getallheaders(), CASE_LOWER) : [];
        $hasBearer = !empty($headers['authorization']) && preg_match('/Bearer\s+\S+/i', (string) $headers['authorization']);
        if ($hasBearer) { return; }
        $token = $in['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($headers['x-csrf-token'] ?? ''));
        if (!class_exists('CSRF') || !CSRF::validateToken((string) $token)) {
            cart_json(['success' => false, 'message' => 'Invalid or missing security token'], 403);
        }
    }
}
if (!function_exists('cart_eligible_modules')) {
    /** Modules that may be added to the general cart. Data-driven if you extend. */
    function cart_eligible_modules(): array
    {
        return ['tours', 'visa', 'esim'];
    }
}
if (!function_exists('cart_base_currency')) {
    /** The platform base currency (NGN here) used to price + total the cart. */
    function cart_base_currency($db): string
    {
        if (function_exists('wallet_default_currency')) {
            $c = strtoupper(trim((string) wallet_default_currency($db)));
            if ($c !== '') { return $c; }
        }
        $c = $db->get('currencies', 'name', ['default' => '1']);
        return strtoupper(trim((string) ($c ?: 'NGN')));
    }
}

if (!function_exists('cart_reprice_line')) {
    /**
     * Recompute ONE cart line's authoritative price server-side from the source
     * record (never the stored/client price). Returns a normalized display line
     * plus the base-currency amounts used for totals + checkout.
     *
     * @param array $line ['module','ref','title','qty','pax','draft', ...]
     * @return array|null null when the line is no longer bookable (drop it)
     */
    function cart_reprice_line($db, array $line, string $baseCurrency): ?array
    {
        $module = strtolower((string) ($line['module'] ?? ''));
        if (!in_array($module, cart_eligible_modules(), true)) { return null; }
        $draft = is_array($line['draft'] ?? null) ? $line['draft'] : [];

        $subtotalBase = 0.0; $taxBase = 0.0; $netBase = 0.0;
        $title = (string) ($line['title'] ?? ucfirst($module));
        $meta = [];

        if ($module === 'tours') {
            $tourId = (int) ($line['ref'] ?? $draft['tour_id'] ?? 0);
            $tour = $tourId > 0 ? $db->get('tours', ['id','name','adult_price','child_price','currency','status'], ['id' => $tourId]) : null;
            if (!$tour || (int) ($tour['status'] ?? 0) !== 1) { return null; }
            $adults = max(1, (int) ($line['pax']['adults'] ?? $draft['adults'] ?? 1));
            $children = max(0, (int) ($line['pax']['children'] ?? $draft['children'] ?? 0));
            $tourCur = strtoupper((string) ($tour['currency'] ?? $baseCurrency)) ?: $baseCurrency;
            $adultNet = (float) ($tour['adult_price'] ?? 0);
            $childNet = (float) ($tour['child_price'] ?? 0);
            $module_row = $db->get('modules', '*', ['name' => 'tours', 'type' => 'tours', 'status' => '1']);
            // MARKUP() converts tourCur → baseCurrency and applies the sell markup.
            $adultSell = cart_marked($db, $adultNet, $module_row, $tourCur, $baseCurrency);
            $childSell = $childNet > 0 ? cart_marked($db, $childNet, $module_row, $tourCur, $baseCurrency) : 0.0;
            $adultNetB = cart_convert($db, $adultNet, $tourCur, $baseCurrency);
            $childNetB = $childNet > 0 ? cart_convert($db, $childNet, $tourCur, $baseCurrency) : 0.0;
            $subtotalBase = round($adultSell * $adults + $childSell * $children, 2);
            $netBase = round($adultNetB * $adults + $childNetB * $children, 2);
            if (function_exists('calculateTax')) {
                $t = calculateTax($subtotalBase, 'tours', $db);
                $taxBase = round((float) ($t['tax_amount'] ?? 0), 2);
            }
            $title = (string) ($tour['name'] ?? $title);
            $meta = ['adults' => $adults, 'children' => $children];

        } elseif ($module === 'visa') {
            // Visa price = (govt_fee + service_fee) per traveller from visa.prices
            // JSON; no markup/tax (matches app/routes/visa/bookingRoutes.php).
            $visaId = (int) ($line['ref'] ?? $draft['visa_id'] ?? 0);
            $variantKey = (string) ($line['variant'] ?? $draft['variant'] ?? '');
            $travelers = max(1, (int) ($line['pax']['travelers'] ?? $draft['travelers'] ?? $draft['applicants'] ?? 1));
            $visa = $visaId > 0 ? $db->get('visa', ['id','prices','currency','status'], ['id' => $visaId]) : null;
            if (!$visa || (int) ($visa['status'] ?? 1) !== 1) { return null; }
            $visaCur = strtoupper((string) ($visa['currency'] ?? $baseCurrency)) ?: $baseCurrency;
            $unit = 0.0;
            $prices = json_decode((string) ($visa['prices'] ?? ''), true);
            if (is_array($prices)) {
                foreach ($prices as $vk => $variant) {
                    if (!is_array($variant)) { continue; }
                    if ($variantKey !== '' && (string) $vk !== $variantKey && (string) ($variant['key'] ?? '') !== $variantKey) { continue; }
                    $unit = (float) ($variant['govt_fee'] ?? 0) + (float) ($variant['service_fee'] ?? 0);
                    break;
                }
            }
            if ($unit <= 0) { return null; }
            $unitBase = cart_convert($db, $unit, $visaCur, $baseCurrency);
            $subtotalBase = round($unitBase * $travelers, 2);
            $netBase = $subtotalBase; // no markup on visa
            $meta = ['travelers' => $travelers];

        } elseif ($module === 'esim') {
            // eSIM = Airalo catalog price. Re-derive the authoritative sell price
            // SERVER-SIDE from Airalo by package id (price-trust workstream); never
            // trust the line's captured selected_package.price. Drop the line if
            // the country is inactive or the package can no longer be priced.
            $pkg = is_array($draft['selected_package'] ?? null) ? $draft['selected_package'] : [];
            $iso = strtoupper((string) ($draft['country'] ?? $line['ref'] ?? ''));
            if ($iso === '') { return null; }
            $country = $db->get('airalo_countries', ['status'], ['iso' => $iso]);
            if (!$country || (int) ($country['status'] ?? 0) !== 1) { return null; }
            $qty = max(1, (int) ($line['qty'] ?? 1));
            $packageId = (string) ($pkg['id'] ?? $pkg['package_id'] ?? '');
            if (!function_exists('airalo_authoritative_price')) {
                $airaloApi = dirname(__DIR__, 1) . '/../modules/esim/airalo/api.php';
                if (is_file($airaloApi)) { require_once $airaloApi; }
            }
            $esimModuleRow = $db->get('modules', '*', ['type' => 'esim', 'status' => '1']);
            $auth = (function_exists('airalo_authoritative_price') && $esimModuleRow)
                ? airalo_authoritative_price($db, (array) $esimModuleRow, $iso, $packageId)
                : null;
            if (!$auth || ($auth['price'] ?? 0) <= 0) { return null; }
            $pkgCur = strtoupper((string) ($auth['currency'] ?? $baseCurrency)) ?: $baseCurrency;
            $unitBase = cart_convert($db, (float) $auth['price'], $pkgCur, $baseCurrency);
            $subtotalBase = round($unitBase * $qty, 2);
            $netBase = (float) ($auth['base_price'] ?? 0) > 0
                ? round(cart_convert($db, (float) $auth['base_price'], $pkgCur, $baseCurrency) * $qty, 2)
                : $subtotalBase;
            $title = (string) ($auth['title'] ?? $pkg['title'] ?? $title);
            $meta = ['qty' => $qty];
        } else {
            return null;
        }

        $finalBase = round($subtotalBase + $taxBase, 2);
        if ($finalBase <= 0) { return null; }

        return [
            'key'           => (string) ($line['key'] ?? ''),
            'module'        => $module,
            'ref'           => (string) ($line['ref'] ?? ''),
            'title'         => $title,
            'image'         => (string) ($line['image'] ?? ''),
            'meta'          => $meta,
            'subtotal_base' => $subtotalBase,
            'tax_base'      => $taxBase,
            'net_base'      => $netBase,
            'final_base'    => $finalBase,
            'currency'      => $baseCurrency,
            'draft'         => $draft,
        ];
    }
}

if (!function_exists('cart_marked')) {
    /** Sell price for a net amount via MARKUP(), converted fromCur→toCur. */
    function cart_marked($db, float $net, $moduleRow, string $fromCur, string $toCur): float
    {
        if ($net <= 0) { return 0.0; }
        if (function_exists('MARKUP')) {
            $m = MARKUP($net, $moduleRow ?: null, $db, $fromCur, $toCur);
            if ((float) ($m['price'] ?? 0) > 0) { return (float) $m['price']; }
        }
        return cart_convert($db, $net, $fromCur, $toCur);
    }
}
if (!function_exists('cart_convert')) {
    /** Currency conversion helper (returns amount unchanged when equal/failed). */
    function cart_convert($db, float $amount, string $fromCur, string $toCur): float
    {
        $fromCur = strtoupper(trim($fromCur)); $toCur = strtoupper(trim($toCur));
        if ($amount <= 0 || $fromCur === '' || $toCur === '' || $fromCur === $toCur) { return $amount; }
        if (function_exists('CURRENCY_CONVERT')) {
            $c = CURRENCY_CONVERT($amount, $db, $fromCur, $toCur);
            if (isset($c['price'])) { return (float) $c['price']; }
        }
        return $amount;
    }
}

if (!function_exists('cart_reprice')) {
    /**
     * Rebuild the whole cart with authoritative prices. Drops any line that is no
     * longer bookable. Returns items + base-currency + display totals.
     */
    function cart_reprice($db): array
    {
        $base = cart_base_currency($db);
        $raw = (array) ($_SESSION['cart'] ?? []);
        $items = []; $grandBase = 0.0; $changed = false;
        foreach ($raw as $key => $line) {
            if (!is_array($line)) { $changed = true; continue; }
            $line['key'] = (string) $key;
            $priced = cart_reprice_line($db, $line, $base);
            if ($priced === null) { unset($_SESSION['cart'][$key]); $changed = true; continue; }
            $items[] = $priced;
            $grandBase += $priced['final_base'];
        }
        $grandBase = round($grandBase, 2);

        // Display total in the visitor's selected currency.
        $displayCur = strtoupper(trim((string) ($_SESSION['app_currency'] ?? ''))) ?: $base;
        $grandDisplay = $displayCur === $base ? $grandBase : cart_convert($db, $grandBase, $base, $displayCur);

        return [
            'items'         => $items,
            'count'         => count($items),
            'base_currency' => $base,
            'grand_base'    => $grandBase,
            'currency'      => $displayCur,
            'grand_total'   => round($grandDisplay, 2),
            'changed'       => $changed,
        ];
    }
}

if (!function_exists('cart_count')) {
    /** Number of lines in the cart (cheap; for the header badge). */
    function cart_count(): int
    {
        return is_array($_SESSION['cart'] ?? null) ? count($_SESSION['cart']) : 0;
    }
}

// ---- routes -----------------------------------------------------------------

// Add a line. The client sends the module + that module's booking draft payload
// (same shape its /save-draft accepts). We store it; price is server-derived.
$router->post('/cart/add', function () use ($SECURE, $db) {
    $in = cart_body();
    cart_csrf_guard($in);

    $module = strtolower(trim((string) ($in['module'] ?? '')));
    if (!in_array($module, cart_eligible_modules(), true)) {
        cart_json(['success' => false, 'message' => 'This item cannot be added to the cart.'], 422);
    }
    $draft = is_array($in['draft'] ?? null) ? $in['draft'] : [];
    if (!$draft) {
        cart_json(['success' => false, 'message' => 'Missing item details.'], 422);
    }

    // Stable line key per (module, ref[, variant]) so re-adding updates qty/pax.
    $ref = (string) ($in['ref'] ?? $draft['tour_id'] ?? $draft['visa_id'] ?? $draft['country'] ?? '');
    $variant = (string) ($in['variant'] ?? $draft['variant'] ?? '');
    $key = $module . ':' . ($ref !== '' ? $ref : substr(md5(json_encode($draft)), 0, 8)) . ($variant !== '' ? ':' . $variant : '');

    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) { $_SESSION['cart'] = []; }
    $_SESSION['cart'][$key] = [
        'module'  => $module,
        'ref'     => $ref,
        'variant' => $variant,
        'title'   => (string) ($in['title'] ?? ''),
        'image'   => (string) ($in['image'] ?? ''),
        'qty'     => max(1, (int) ($in['qty'] ?? 1)),
        'pax'     => is_array($in['pax'] ?? null) ? $in['pax'] : [],
        'draft'   => $draft,
        'added_at'=> date('Y-m-d H:i:s'),
    ];

    // Reprice immediately; if this exact line is not bookable, reject the add.
    $cart = cart_reprice($db);
    $found = false;
    foreach ($cart['items'] as $it) { if ($it['key'] === $key) { $found = true; break; } }
    if (!$found) {
        unset($_SESSION['cart'][$key]);
        cart_json(['success' => false, 'message' => 'This item is not available for booking right now.'], 409);
    }
    cart_json(['success' => true, 'cart' => $cart]);
});

// View the cart (server-repriced JSON).
$router->get('/cart/data', function () use ($SECURE, $db) {
    cart_json(['success' => true, 'cart' => cart_reprice($db)]);
});

// Remove a line.
$router->post('/cart/remove', function () use ($SECURE, $db) {
    $in = cart_body();
    cart_csrf_guard($in);
    $key = (string) ($in['key'] ?? '');
    if ($key !== '' && isset($_SESSION['cart'][$key])) { unset($_SESSION['cart'][$key]); }
    cart_json(['success' => true, 'cart' => cart_reprice($db)]);
});

// Clear the whole cart.
$router->post('/cart/clear', function () use ($SECURE, $db) {
    $in = cart_body();
    cart_csrf_guard($in);
    $_SESSION['cart'] = [];
    cart_json(['success' => true, 'cart' => cart_reprice($db)]);
});

// ---------------------------------------------------------------------------
// CHECKOUT — one payment for the whole cart.
// Builds ONE ai_trip-style package booking (module_type='ai_trip') whose
// booking_data.items[] are the cart lines, so the existing invoice + per-item
// issue machinery (modules/ai_trip/ai_trip/issue.php) pays and confirms it
// unchanged. The coupon applies to the whole-cart base total. Everything is
// recomputed SERVER-SIDE here again — the session is never trusted.
//   POST /cart/checkout { first_name, last_name, email, phone, promo_code, csrf_token }
// ---------------------------------------------------------------------------
$router->post('/cart/checkout', function () use ($SECURE, $db) {
    $in = cart_body();
    cart_csrf_guard($in);

    $cart = cart_reprice($db);
    if (empty($cart['items'])) {
        cart_json(['success' => false, 'message' => 'Your cart is empty.'], 409);
    }

    $firstName = trim((string) ($in['first_name'] ?? ''));
    $lastName  = trim((string) ($in['last_name'] ?? ''));
    $email     = trim((string) ($in['email'] ?? ''));
    $phone     = trim((string) ($in['phone'] ?? ''));
    if ($firstName === '') { cart_json(['success' => false, 'message' => 'Please enter your name.'], 422); }
    if ($email === '' && $phone === '') { cart_json(['success' => false, 'message' => 'Enter your email or phone.'], 422); }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { cart_json(['success' => false, 'message' => 'Enter a valid email.'], 422); }

    $baseCurrency = (string) $cart['base_currency'];
    $userId = $_SESSION['user_id'] ?? null;

    // Build the package items[] from the repriced lines. Each entry carries what
    // ai_trip/issue.php needs: module, supplier, price_base, currency, pnr='',
    // and the module draft as `detail`.
    $packageItems = [];
    $subtotalBase = 0.0; $taxBase = 0.0; $netBase = 0.0;
    foreach ($cart['items'] as $it) {
        $module = (string) $it['module'];
        $draft  = is_array($it['draft'] ?? null) ? $it['draft'] : [];
        $supplier = (string) ($draft['supplier'] ?? $module);
        $packageItems[] = [
            'module'      => $module,
            'supplier'    => $supplier,
            'title'       => (string) $it['title'],
            'image'       => (string) ($it['image'] ?? ''),
            'ref'         => (string) $it['ref'],
            'meta'        => $it['meta'] ?? [],
            'pnr'         => '',
            'issue_status'=> 'pending',
            'price_base'  => (float) $it['final_base'],
            'price'       => (float) $it['final_base'],
            'net_base'    => (float) $it['net_base'],
            'tax_base'    => (float) $it['tax_base'],
            'currency'    => $baseCurrency,
            'detail'      => $draft,
        ];
        $subtotalBase += (float) $it['subtotal_base'];
        $taxBase      += (float) $it['tax_base'];
        $netBase      += (float) $it['net_base'];
    }
    $subtotalBase = round($subtotalBase, 2);
    $taxBase = round($taxBase, 2);
    $netBase = round($netBase, 2);
    $grandBase = round($cart['grand_base'], 2); // subtotal + tax across lines

    // Coupon — recompute server-side against the whole-cart base total (module 'cart').
    $promoCodeStr = trim((string) ($in['promo_code'] ?? ''));
    $promoDiscount = 0.0; $promoData = null; $promoCodeJson = null;
    if ($promoCodeStr !== '' && function_exists('promoResolveForBooking')) {
        $pr = promoResolveForBooking($db, $promoCodeStr, $grandBase, 'cart', $baseCurrency, [
            'user_id'    => $userId,
            'user_email' => $email ?: null,
        ]);
        $promoDiscount = (float) $pr['discount'];
        $promoData     = $pr['promo'];
        $promoCodeJson = $pr['json'];
    }

    $payableBase = round(max(0, $grandBase - $promoDiscount), 2);
    if ($payableBase <= 0) { cart_json(['success' => false, 'message' => 'Cart total is invalid.'], 409); }
    $commission = round(max(0, $payableBase - $netBase - $taxBase), 2);

    // One package booking row. module_type='ai_trip' so the existing invoice +
    // issue path handles it. AITP prefix matches the AI package convention.
    $packageId = 'AITP' . strtoupper(bin2hex(random_bytes(5)));
    $invoiceId = $packageId;

    $primaryGuest = ['first_name' => $firstName, 'last_name' => $lastName, 'email' => $email, 'phone' => $phone];
    $bookingData = [
        'source'             => 'cart',
        'package_id'         => $packageId,
        'ai_trip_package_id' => $packageId,
        'items'              => $packageItems,
        'items_count'        => count($packageItems),
        'subtotal'           => $subtotalBase,
        'base_price'         => $netBase,
        'tax_amount'         => $taxBase,
        'promo_discount'     => $promoDiscount,
        // payable_total is the authoritative charged amount the ai_trip invoice
        // reads FIRST (app/views/ai/invoice.php:251). Set it to the coupon-
        // discounted total so the invoice never re-sums line items (which would
        // drop the discount and mishandle visa lines). Must equal price_markup.
        'payable_total'      => $payableBase,
        'final_total'        => $payableBase,
        'final_total_base'   => $payableBase,
        'display_total'      => round($cart['grand_total'], 2),
        'base_currency'      => $baseCurrency,
        'display_currency'   => (string) $cart['currency'],
        'guest'              => $primaryGuest,
    ];

    $userData = null;
    if ($userId) { $userData = $db->get('users', '*', ['user_id' => $userId]); }

    $ok = $db->insert('bookings', [
        'invoice_id'           => $invoiceId,
        'language'             => function_exists('getCurrentLanguage') ? getCurrentLanguage() : 'en',
        'booking_status'       => 'pending',
        'payment_status'       => 'unpaid',
        'price_original'       => $netBase,
        'price_markup'         => $payableBase,
        'agent_earning'        => '0',
        'tax_type'             => '',
        'tax'                  => (string) $taxBase,
        'first_name'           => $firstName,
        'last_name'            => $lastName,
        'email'                => $email,
        'phone_country_code'   => '',
        'phone'                => $phone,
        'country'              => '',
        'address'              => '',
        'adults'               => 1,
        'infants'              => '0',
        'childs'               => 0,
        'child_ages'           => '[]',
        'currency_markup'      => $baseCurrency,
        'cancellation_request' => 0,
        'cancellation_status'  => 0,
        'booking_data'         => json_encode($bookingData),
        'transaction_id'       => $packageId,
        'user_id'              => (string) ($userId ?? ''),
        'user_data'            => $userData ? json_encode($userData) : null,
        'travellers'           => json_encode(['primary_guest' => $primaryGuest]),
        'nationality'          => '',
        'payment_gateway'      => '',
        'module_type'          => 'ai_trip',
        'pnr'                  => '',
        'commission'           => (string) $commission,
        'module'               => 'ai_trip',
        'special_requests'     => '',
        'promo_codes'          => $promoCodeJson,
        'booking_date'         => date('Y-m-d H:i:s'),
        'created_at'           => date('Y-m-d H:i:s'),
    ]);
    if (!$ok) {
        cart_json(['success' => false, 'message' => 'Could not start checkout. Please try again.'], 500);
    }

    // Record coupon usage idempotently (per invoice) — bumps used_count + ledger.
    if ($promoCodeStr !== '' && $promoDiscount > 0 && $promoData && function_exists('recordPromoUsage')) {
        recordPromoUsage($db, $promoData, (string) $invoiceId, $userId ?: null, $email ?: null, (float) $promoDiscount, 'cart', $baseCurrency);
    }

    // Success: clear the cart and send the customer to the package invoice, where
    // the existing payment flow charges the single price_markup total.
    $_SESSION['cart'] = [];
    // Guest allow-list so they can view/pay this package without an account.
    if (!$userId) {
        $_SESSION['cart_guest_bookings'] = array_values(array_unique(array_merge(
            (array) ($_SESSION['cart_guest_bookings'] ?? []), [$invoiceId]
        )));
    }

    cart_json([
        'success'      => true,
        'invoice_id'   => $invoiceId,
        'redirect_url' => root . 'invoice/ai_trip/' . $invoiceId,
    ]);
});

// The cart PAGE (HTML). Lists lines, applies a coupon, and checks out.
$router->get('/cart', function () use ($SECURE, $db) {
    $cartCsrf = class_exists('CSRF') ? CSRF::getToken() : '';
    $title = 'Your Cart';
    $description = '';
    $header = true;
    $footer = true;
    require_once views . 'includes/header.php';
    require_once views . 'cart/index.php';
    require_once views . 'includes/footer.php';
});

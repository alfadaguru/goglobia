<?php
// ============================================================================
// SAVED CARDS — customer endpoints (login + CSRF + IDOR on every route).
// PCI: no endpoint here ever accepts a card number/CVV/expiry. The browser
// (Stripe.js / Paystack Inline) sends card data straight to the provider; we only
// receive a provider token. See app/lib/cards.php.
// ============================================================================
@$SECURE or die('Access Denied!');

if (!function_exists('cards_route_user')) {
    /** Session-authenticated customer id, or emit 401 JSON + exit. */
    function cards_route_user(): string
    {
        if (empty($_SESSION['user_id'])) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Please log in']);
            exit;
        }
        return (string) $_SESSION['user_id'];
    }
}
if (!function_exists('cards_route_csrf')) {
    /** Validate CSRF (body csrf_token or X-CSRF-TOKEN); 403 + exit on failure. */
    function cards_route_csrf(array $in): void
    {
        $tok = $in['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!class_exists('CSRF') || !CSRF::validateToken((string) $tok)) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid security token']);
            exit;
        }
    }
}
if (!function_exists('cards_route_body')) {
    function cards_route_body(): array
    {
        $raw = file_get_contents('php://input');
        $j = json_decode((string) $raw, true);
        return is_array($j) ? $j : $_POST;
    }
}

// ---- GET /api/cards : list my saved cards (display data only) ---------------
$router->get('/api/cards', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    $uid = cards_route_user();
    echo json_encode(['success' => true, 'cards' => cards_list($db, $uid)]);
    exit;
});

// ---- POST /api/cards/setup : begin add-a-card (Stripe SetupIntent) ----------
// Returns client_secret + publishable key for Stripe.js to confirm in-browser.
$router->post('/api/cards/setup', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    $uid = cards_route_user();
    $in  = cards_route_body();
    cards_route_csrf($in);
    // Phase 2 supports Stripe (USD). Paystack cards are saved on first top-up.
    $res = cards_stripe_setup_intent($db, $uid);
    if (empty($res['ok'])) { http_response_code(400); echo json_encode(['success' => false, 'message' => $res['message'] ?? 'Could not start setup']); exit; }
    echo json_encode([
        'success' => true,
        'provider' => 'stripe',
        'client_secret'   => $res['client_secret'],
        'publishable_key' => $res['publishable_key'],
        'setup_intent_id' => $res['setup_intent_id'],
    ]);
    exit;
});

// ---- POST /api/cards/save : store the confirmed card -----------------------
// Body: { setup_intent_id }. Server resolves the real payment_method from the
// SetupIntent (never trusts a client-sent pm_ id).
$router->post('/api/cards/save', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    $uid = cards_route_user();
    $in  = cards_route_body();
    cards_route_csrf($in);
    $sid = trim((string) ($in['setup_intent_id'] ?? ''));
    $res = cards_stripe_save_from_setup_intent($db, $uid, $sid);
    if (empty($res['ok'])) { http_response_code(400); echo json_encode(['success' => false, 'message' => $res['message'] ?? 'Could not save card']); exit; }
    echo json_encode(['success' => true, 'card_id' => $res['id'] ?? null, 'cards' => cards_list($db, $uid)]);
    exit;
});

// ---- POST /api/cards/{id}/default : make a card the default ----------------
$router->post('/api/cards/([0-9]+)/default', function ($id) use ($SECURE, $db) {
    header('Content-Type: application/json');
    $uid = cards_route_user();
    $in  = cards_route_body();
    cards_route_csrf($in);
    $res = cards_set_default($db, (int) $id, $uid);
    if (empty($res['ok'])) { http_response_code(400); echo json_encode(['success' => false, 'message' => $res['message'] ?? 'Failed']); exit; }
    echo json_encode(['success' => true, 'cards' => cards_list($db, $uid)]);
    exit;
});

// ---- POST /api/cards/{id}/charge : pay with a saved card -------------------
// Body: { amount, currency?, purpose? }. Off-session charge -> wallet top-up.
$router->post('/api/cards/([0-9]+)/charge', function ($id) use ($SECURE, $db) {
    header('Content-Type: application/json');
    $uid = cards_route_user();
    $in  = cards_route_body();
    cards_route_csrf($in);
    $amount = round((float) ($in['amount'] ?? 0), 2);
    if ($amount <= 0) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Enter an amount']); exit; }
    if (!function_exists('cards_charge')) { http_response_code(500); echo json_encode(['success' => false, 'message' => 'Card charging unavailable']); exit; }
    // Per-attempt idempotency id (audit #42): the client sends one stable id per click so
    // a double-submit reuses the same provider idempotency key (no double charge).
    $attemptId = (string) ($in['attempt_id'] ?? '');
    $res = cards_charge($db, (int) $id, $uid, $amount, $attemptId);
    if (empty($res['ok'])) {
        http_response_code(($res['requires_action'] ?? false) ? 200 : 400);
        echo json_encode(array_merge(['success' => false], $res));
        exit;
    }
    echo json_encode(['success' => true, 'balance' => $res['balance'] ?? null, 'message' => 'Wallet topped up.']);
    exit;
});

// ---- POST /api/cards/{id}/charge/complete : finish a 3-DS/SCA charge --------
// Body: { payment_intent }. After the browser confirms the PaymentIntent via
// Stripe.js, this verifies it succeeded server-side and credits the wallet (audit #41).
$router->post('/api/cards/([0-9]+)/charge/complete', function ($id) use ($SECURE, $db) {
    header('Content-Type: application/json');
    $uid = cards_route_user();
    $in  = cards_route_body();
    cards_route_csrf($in);
    $pi  = (string) ($in['payment_intent'] ?? '');
    if ($pi === '') { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Missing payment reference']); exit; }
    if (!function_exists('cards_complete_sca')) { http_response_code(500); echo json_encode(['success' => false, 'message' => 'Card completion unavailable']); exit; }
    $res = cards_complete_sca($db, (int) $id, $uid, $pi);
    if (empty($res['ok'])) { http_response_code(400); echo json_encode(array_merge(['success' => false], $res)); exit; }
    echo json_encode(['success' => true, 'balance' => $res['balance'] ?? null, 'message' => 'Wallet topped up.']);
    exit;
});

// ---- DELETE /api/cards/{id} : remove a saved card --------------------------
$router->route(['POST', 'DELETE'], '/api/cards/([0-9]+)/delete', function ($id) use ($SECURE, $db) {
    header('Content-Type: application/json');
    $uid = cards_route_user();
    $in  = cards_route_body();
    cards_route_csrf($in);
    $res = cards_delete($db, (int) $id, $uid);
    if (empty($res['ok'])) { http_response_code(400); echo json_encode(['success' => false, 'message' => $res['message'] ?? 'Failed']); exit; }
    echo json_encode(['success' => true, 'cards' => cards_list($db, $uid)]);
    exit;
});

// ---- POST /api/cards/{id}/pay-booking : pay a BOOKING with a saved card -----
// Body: { invoice_id, attempt_id? }. Charges the saved card for the booking's
// amount (NOT a wallet top-up) and settles the BOOKING through the normal
// confirm-before-issue callback: we mint the booking payment token, charge the
// card bound to the invoice, then return a redirect to
//   invoice/{type}/{id}?token=..&payment_status=success&transaction_id={ref}
// so handle_payment_callback -> verify_gateway_payment confirms the charge and
// issues exactly like any gateway payment (no duplicated settlement here).
$router->post('/api/cards/([0-9]+)/pay-booking', function ($id) use ($SECURE, $db) {
    header('Content-Type: application/json');
    $uid = cards_route_user();
    $in  = cards_route_body();
    cards_route_csrf($in);

    require_once 'app/lib/payment-gateway.php';
    require_once 'app/lib/wallet.php';

    $invoiceId = trim((string) ($in['invoice_id'] ?? ''));
    if ($invoiceId === '') { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Missing invoice']); exit; }

    $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
    if (!$booking) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Booking not found']); exit; }

    // OWNERSHIP: the booking must belong to this logged-in customer (IDOR).
    if ((string) ($booking['user_id'] ?? '') !== $uid) {
        http_response_code(403); echo json_encode(['success' => false, 'message' => 'This booking is not on your account.']); exit;
    }
    // Same money-safety guards process_payment() enforces.
    if (($booking['payment_status'] ?? '') === 'paid') {
        http_response_code(400); echo json_encode(['success' => false, 'message' => 'This booking is already paid.']); exit;
    }
    if (function_exists('payment_supplier_is_unbookable') && payment_supplier_is_unbookable($booking)) {
        http_response_code(400); echo json_encode(['success' => false, 'message' => 'This item is booked directly with the provider and cannot be paid here.']); exit;
    }

    // Resolve the card and the currency-correct gateway (NGN->Paystack / else->Stripe).
    $card = cards_get_owned($db, (int) $id, $uid);
    if (!$card) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Card not found']); exit; }
    $bookingCur = strtoupper((string) ($booking['currency_markup'] ?? ''));
    $cardCur    = strtoupper((string) $card['currency']);
    if ($bookingCur !== '' && $cardCur !== '' && $bookingCur !== $cardCur) {
        http_response_code(400); echo json_encode(['success' => false, 'message' => 'This card cannot pay a ' . $bookingCur . ' booking.']); exit;
    }
    $gateway = $db->get('payment_gateways', '*', ['id' => (int) ($card['gateway_id'] ?? 0)])
        ?: cards_gateway_for_currency($db, $bookingCur ?: $cardCur);
    if (!$gateway) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Payment gateway not available']); exit; }
    if (function_exists('payment_gateway_apply_service_creds')) {
        payment_gateway_apply_service_creds($db, $gateway, $booking);
    }

    // Mint the booking payment token (stores gateway_id/amount/currency the callback reads).
    $token = create_payment_token($booking, $gateway);
    if (!$token) { http_response_code(500); echo json_encode(['success' => false, 'message' => 'Could not start payment']); exit; }

    if (!function_exists('cards_charge_booking')) { http_response_code(500); echo json_encode(['success' => false, 'message' => 'Saved-card payment unavailable']); exit; }
    $attemptId = (string) ($in['attempt_id'] ?? '');
    $res = cards_charge_booking($db, (int) $id, $uid, $booking, (string) $token, $attemptId);

    // Module-aware invoice callback prefix (same map the pay routes use).
    $mt = strtolower((string) ($booking['module_type'] ?? ''));
    $prefixes = ['ferries','tours','flights','stays','cars','visa','esim','bus','rail','insurance'];
    $iPrefix = in_array($mt, $prefixes, true) ? ('invoice/' . $mt . '/') : 'invoice/';
    $callbackBase = root . $iPrefix . rawurlencode($invoiceId) . '?token=' . urlencode((string) $token) . '&payment_status=success';

    if (!empty($res['ok']) && !empty($res['reference'])) {
        $redirect = $callbackBase . '&transaction_id=' . urlencode((string) $res['reference']);
        echo json_encode(['success' => true, 'redirect' => $redirect]);
        exit;
    }
    if (!empty($res['requires_action'])) {
        // 3-DS / SCA: hand the browser the client_secret + the account's PUBLISHABLE
        // key (c1, browser-safe) so Stripe.js can confirm the card in-page, then it
        // calls /pay-booking/complete which returns the same success redirect.
        $pubKey = function_exists('cards_publishable_key') ? cards_publishable_key($gateway) : '';
        echo json_encode([
            'success' => false,
            'requires_action' => true,
            'provider' => $res['provider'] ?? 'stripe',
            'client_secret' => $res['client_secret'] ?? '',
            'publishable_key' => $pubKey,
            'payment_intent' => $res['reference'] ?? '',
            'token' => (string) $token,
            'message' => $res['message'] ?? 'This card needs extra verification.',
        ]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $res['message'] ?? 'Card was declined']);
    exit;
});

// ---- POST /api/cards/{id}/pay-booking/complete : finish a 3-DS booking pay ---
// Body: { invoice_id, payment_intent, token }. After the browser confirms the
// PaymentIntent via Stripe.js, we hand back the success redirect; the callback's
// verify step (verify_gateway_payment, pi_ branch) re-retrieves the PI, requires
// succeeded + amount/currency + invoice binding, and settles — so we never trust
// the browser's word here, the callback does the authoritative check.
$router->post('/api/cards/([0-9]+)/pay-booking/complete', function ($id) use ($SECURE, $db) {
    header('Content-Type: application/json');
    $uid = cards_route_user();
    $in  = cards_route_body();
    cards_route_csrf($in);

    $invoiceId = trim((string) ($in['invoice_id'] ?? ''));
    $pi        = trim((string) ($in['payment_intent'] ?? ''));
    $token     = trim((string) ($in['token'] ?? ''));
    if ($invoiceId === '' || $pi === '' || $token === '') {
        http_response_code(400); echo json_encode(['success' => false, 'message' => 'Missing payment reference']); exit;
    }

    $booking = $db->get('bookings', ['user_id', 'module_type'], ['invoice_id' => $invoiceId]);
    if (!$booking || (string) ($booking['user_id'] ?? '') !== $uid) {
        http_response_code(403); echo json_encode(['success' => false, 'message' => 'This booking is not on your account.']); exit;
    }

    $mt = strtolower((string) ($booking['module_type'] ?? ''));
    $prefixes = ['ferries','tours','flights','stays','cars','visa','esim','bus','rail','insurance'];
    $iPrefix = in_array($mt, $prefixes, true) ? ('invoice/' . $mt . '/') : 'invoice/';
    $redirect = root . $iPrefix . rawurlencode($invoiceId) . '?token=' . urlencode($token)
        . '&payment_status=success&transaction_id=' . urlencode($pi);
    echo json_encode(['success' => true, 'redirect' => $redirect]);
    exit;
});

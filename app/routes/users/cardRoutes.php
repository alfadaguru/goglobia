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
    $res = cards_charge($db, (int) $id, $uid, $amount);
    if (empty($res['ok'])) {
        http_response_code(($res['requires_action'] ?? false) ? 200 : 400);
        echo json_encode(array_merge(['success' => false], $res));
        exit;
    }
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

<?php
// FILE: app/routes/users/offersRoutes.php
// Customer "Offers" page — check a promo code's validity ahead of booking, and
// browse the active public offers available to them. Validation is SERVER-SIDE
// (reuses promoResolveForBooking / validatePromoCode); this page never applies a
// discount to money — the real discount is recomputed at checkout as always.

@$SECURE or die('Access Denied!');

// ---- GET /offers : the page ----
$router->get('/offers', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        $_SESSION['login_error'] = 'required';
        header('Location: ' . root . 'login');
        exit;
    }
    CHECK_AGENT($db);
    $user_id = $_SESSION['user_id'];
    $user    = $db->get('users', '*', ['user_id' => $user_id]);

    $walletCurrency = strtoupper((string) ($_SESSION['app_currency'] ?? ($user['currency'] ?? ($GLOBALS['app']['default_currency'] ?? 'USD'))));

    // Public, currently-valid promo codes the customer could use. We only surface
    // codes with a non-empty, public-looking `description` (admin's way of marking
    // a code as "advertised") and a live date window — never dump every internal
    // code. Read-only, best-effort.
    $now = date('Y-m-d H:i:s');
    $offers = [];
    try {
        $rows = $db->select('promo_codes',
            ['code', 'description', 'discount_type', 'discount_value', 'currency', 'min_order_amount', 'module', 'end_date'],
            ['status' => 1, 'description[!]' => '', 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 50]
        ) ?: [];
        foreach ($rows as $r) {
            $start = (string) ($r['start_date'] ?? '');
            $end   = (string) ($r['end_date'] ?? '');
            if ($end !== '' && $end < $now) { continue; }   // expired — hide
            $offers[] = $r;
        }
    } catch (\Throwable $e) { error_log('OFFERS_VIEW: ' . $e->getMessage()); }

    $dashboardData = [
        'total_bookings' => (int) $db->count('bookings', ['user_id' => $user_id]),
        'member_since'   => $user['created_at'] ?? 'N/A',
        'currency'       => $walletCurrency,
        'is_agent'       => strtolower((string) ($user['role'] ?? '')) === 'agent',
    ];

    $offersData = [
        'offers'   => $offers,
        'currency' => $walletCurrency,
    ];

    $title       = 'Offers';
    $description = 'Promo codes and offers';
    $header      = true;
    $footer      = true;

    require_once views . 'includes/header.php';
    require_once views . 'auth/offers.php';
    require_once views . 'includes/footer.php';
});

// ---- POST /offers/check : validate a promo code (JSON, CSRF-guarded) ----
$router->post('/offers/check', function () use ($SECURE, $db) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        echo json_encode(['ok' => false, 'message' => 'Login required']); exit;
    }
    $input = json_decode((string) file_get_contents('php://input'), true) ?: $_POST;
    if (!class_exists('CSRF') || !CSRF::validateToken($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        echo json_encode(['ok' => false, 'message' => 'Invalid security token']); exit;
    }

    $code = strtoupper(trim((string) ($input['code'] ?? '')));
    if ($code === '') { echo json_encode(['ok' => false, 'message' => 'Enter a code']); exit; }

    if (!function_exists('promoResolveForBooking')) {
        echo json_encode(['ok' => false, 'message' => 'Promo validation unavailable']); exit;
    }

    $currency = strtoupper((string) ($_SESSION['app_currency'] ?? ($db->get('users', 'currency', ['user_id' => $_SESSION['user_id']]) ?: 'USD')));
    // Eligibility check against a nominal order so the customer sees whether the
    // code is valid for them (exists / active / in-window / per-user limit). The
    // real, authoritative discount is always recomputed at checkout against the
    // actual order — this is advisory only.
    $nominal = 100000.0; // high nominal so a min-order threshold doesn't false-fail the check
    $ctx = ['user_id' => (string) $_SESSION['user_id'], 'user_email' => (string) ($db->get('users', 'email', ['user_id' => $_SESSION['user_id']]) ?: '')];

    $res = promoResolveForBooking($db, $code, $nominal, 'all', $currency, $ctx);
    if (empty($res['ok'])) {
        echo json_encode(['ok' => false, 'message' => $res['message'] ?: 'That code is not valid or has expired.']);
        exit;
    }
    echo json_encode([
        'ok'      => true,
        'code'    => $code,
        'message' => 'This code is valid — it will be applied at checkout on eligible bookings.',
    ]);
    exit;
});

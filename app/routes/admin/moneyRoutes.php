<?php
// ============================================================================
// FILE: app/routes/admin/moneyRoutes.php
// ADMIN — AGENT MEMBER TIERS + LOYALTY POINTS SETTINGS
// docs/MONEY-WALLET-AUDIT.md §C.4 steps 4 & 5.
//
//   GET  /admin/money/tiers              → manage agent tiers + loyalty scheme
//   POST /admin/money/tiers/save         → create / update / toggle one tier
//   POST /admin/money/tiers/delete       → delete a tier (JSON)
//   POST /admin/money/loyalty/save       → save loyalty scheme (earn/redeem)
//
// All handlers are ADMIN_AUTH() gated and CSRF verified (form posts via
// CSRF::verifyRequest(), JSON via CSRF::validateToken()). Schema for the
// agent_tiers / loyalty_* columns is guaranteed by ensureAgentApiSchema()
// (config.php boot); we call it defensively here too so a fresh admin visit
// never 500s on a missing column.
// ============================================================================
@$SECURE or die('Access Denied!');

$router->get(admin.'/finance/tiers', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureAgentApiSchema')) { ensureAgentApiSchema($db); }

    $tiers = $db->select('agent_tiers', '*', ['ORDER' => ['sort_order' => 'ASC', 'min_lifetime_topup' => 'ASC']]) ?: [];
    $settings = $db->get('settings', [
        'loyalty_enabled', 'loyalty_earn_customer', 'loyalty_earn_agent', 'loyalty_redeem_value'
    ], ['id' => 1]) ?: [];

    // Currency label for the UI (default currency name), purely cosmetic.
    $defaultCurrency = $db->get('currencies', 'name', ['default' => '1']) ?: ($db->get('currencies', 'name', []) ?: 'USD');

    require_once views."includes/header.php";
    require_once "app/views/admin/money/tiers.php";
    require_once views."includes/footer.php";
});

$router->post(admin.'/finance/tiers/save', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::verifyRequest();
    if (function_exists('ensureAgentApiSchema')) { ensureAgentApiSchema($db); }

    $id        = (int)($_POST['id'] ?? 0);
    $code      = strtolower(trim((string)($_POST['code'] ?? '')));
    $name      = trim((string)($_POST['name'] ?? ''));
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $minTopup  = (float)($_POST['min_lifetime_topup'] ?? 0);
    $discount  = (float)($_POST['discount_percent'] ?? 0);
    $active    = isset($_POST['active']) && (string)$_POST['active'] === '1' ? 1 : 0;

    // Validate. code/name required; discount is a percentage 0..100; min >= 0.
    if ($code === '' || $name === '') {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Tier code and name are required.'];
        redirect(root . admin . '/finance/tiers');
    }
    if (!preg_match('/^[a-z0-9_]{2,32}$/', $code)) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Tier code must be 2–32 chars: a–z, 0–9, underscore.'];
        redirect(root . admin . '/finance/tiers');
    }
    if ($discount < 0 || $discount > 100) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Discount percent must be between 0 and 100.'];
        redirect(root . admin . '/finance/tiers');
    }
    if ($minTopup < 0) { $minTopup = 0; }

    // Enforce unique code (excluding self on edit).
    $clashWhere = ['code' => $code];
    if ($id > 0) { $clashWhere['id[!]'] = $id; }
    if ((int)$db->count('agent_tiers', $clashWhere) > 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'A tier with that code already exists.'];
        redirect(root . admin . '/finance/tiers');
    }

    $row = [
        'code'               => $code,
        'name'               => $name,
        'sort_order'         => $sortOrder,
        'min_lifetime_topup' => round($minTopup, 2),
        'discount_percent'   => round($discount, 2),
        'active'             => $active,
        'updated_at'         => date('Y-m-d H:i:s'),
    ];

    try {
        if ($id > 0) {
            $db->update('agent_tiers', $row, ['id' => $id]);
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Tier updated.'];
        } else {
            $row['created_at'] = date('Y-m-d H:i:s');
            $db->insert('agent_tiers', $row);
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Tier created.'];
        }
    } catch (\Throwable $e) {
        error_log('money/tiers/save: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not save tier.'];
    }

    redirect(root . admin . '/finance/tiers');
});

$router->post(admin.'/finance/tiers/delete', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    if (!CSRF::validateToken($input['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']); exit;
    }
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid tier']); exit; }

    // Detach any agents currently on this tier so we never leave a dangling
    // agent_tier_id pointing at a deleted row (their next top-up recomputes it).
    try {
        $db->update('users', ['agent_tier_id' => null], ['agent_tier_id' => $id]);
        $db->delete('agent_tiers', ['id' => $id]);
        echo json_encode(['success' => true]);
    } catch (\Throwable $e) {
        error_log('money/tiers/delete: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Delete failed']);
    }
    exit;
});

$router->post(admin.'/finance/loyalty/save', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    CSRF::verifyRequest();
    if (function_exists('ensureAgentApiSchema')) { ensureAgentApiSchema($db); }

    $enabled       = isset($_POST['loyalty_enabled']) && (string)$_POST['loyalty_enabled'] === '1' ? 1 : 0;
    $earnCustomer  = max(0.0, (float)($_POST['loyalty_earn_customer'] ?? 0));
    $earnAgent     = max(0.0, (float)($_POST['loyalty_earn_agent'] ?? 0));
    $redeemValue   = max(0.0, (float)($_POST['loyalty_redeem_value'] ?? 0));

    try {
        $db->update('settings', [
            'loyalty_enabled'       => $enabled,
            'loyalty_earn_customer' => round($earnCustomer, 4),
            'loyalty_earn_agent'    => round($earnAgent, 4),
            'loyalty_redeem_value'  => round($redeemValue, 4),
        ], ['id' => 1]);
        // Keep the in-request settings cache honest for the redirect target.
        if (isset($GLOBALS['app']) && is_array($GLOBALS['app'])) {
            $GLOBALS['app']['loyalty_enabled']       = $enabled;
            $GLOBALS['app']['loyalty_earn_customer'] = $earnCustomer;
            $GLOBALS['app']['loyalty_earn_agent']    = $earnAgent;
            $GLOBALS['app']['loyalty_redeem_value']  = $redeemValue;
        }
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Loyalty scheme saved.'];
    } catch (\Throwable $e) {
        error_log('money/loyalty/save: ' . $e->getMessage());
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Could not save loyalty scheme.'];
    }

    redirect(root . admin . '/finance/tiers');
});

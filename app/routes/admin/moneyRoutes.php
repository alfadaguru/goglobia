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

// ============================================================================
// TRANSACTION JOURNEYS (docs/MONEY-WALLET-AUDIT.md §A.2) — read-only admin view
// of the money spine: every money_transactions row and its ordered journey
// (born pending → sent → success/failed/reversed), plus the wallet_ledger
// impact. This is the "open one transaction and trace its whole life" screen.
//   GET /admin/finance/journeys            → filterable, paginated list
//   GET /admin/finance/journeys/{id}       → one transaction's full timeline
// ============================================================================
$router->get(admin.'/finance/journeys/([0-9]+)', function ($id) use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureAgentApiSchema')) { ensureAgentApiSchema($db); }

    $view = 'detail';
    $txn = $db->get('money_transactions', '*', ['id' => (int) $id]);
    $journey = [];
    $ledger = [];
    $ownerName = '';
    if ($txn) {
        $journey = $db->select('transaction_journey', '*', [
            'transaction_id' => (int) $txn['id'], 'ORDER' => ['id' => 'ASC']
        ]) ?: [];
        $ledger = $db->select('wallet_ledger', '*', [
            'transaction_id' => (int) $txn['id'], 'ORDER' => ['id' => 'ASC']
        ]) ?: [];
        if (!empty($txn['user_id'])) {
            $u = $db->get('users', ['first_name', 'last_name', 'email'], ['user_id' => $txn['user_id']]);
            if ($u) { $ownerName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: ($u['email'] ?? ''); }
        }
    }

    require_once views."includes/header.php";
    require_once "app/views/admin/money/journeys.php";
    require_once views."includes/footer.php";
});

$router->get(admin.'/finance/journeys', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureAgentApiSchema')) { ensureAgentApiSchema($db); }

    $view = 'list';

    // Filters (all optional, whitelisted against the enum columns).
    $fStatus    = in_array(($_GET['status'] ?? ''),    ['pending','sent','success','failed','cancelled','reversed'], true) ? $_GET['status'] : '';
    $fDirection = in_array(($_GET['direction'] ?? ''), ['credit','debit'], true) ? $_GET['direction'] : '';
    $fReason    = in_array(($_GET['reason'] ?? ''),    ['wallet_topup','booking_payment','wallet_spend','refund','reversal','fee','loyalty_convert','adjustment'], true) ? $_GET['reason'] : '';
    $fMethod    = in_array(($_GET['method'] ?? ''),    ['gateway','wallet','manual'], true) ? $_GET['method'] : '';
    $q          = trim((string) ($_GET['q'] ?? ''));

    $where = [];
    if ($fStatus !== '')    { $where['status'] = $fStatus; }
    if ($fDirection !== '') { $where['direction'] = $fDirection; }
    if ($fReason !== '')    { $where['reason'] = $fReason; }
    if ($fMethod !== '')    { $where['method'] = $fMethod; }
    if ($q !== '') {
        $where['OR'] = [
            'txn_ref[~]'     => $q,
            'invoice_id[~]'  => $q,
            'user_id[~]'     => $q,
            'provider_trx_id[~]' => $q,
            'description[~]' => $q,
        ];
    }

    // Pagination.
    $perPage = 25;
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $total = (int) $db->count('money_transactions', $where ?: ['id[>]' => 0]);
    $pages = max(1, (int) ceil($total / $perPage));
    if ($page > $pages) { $page = $pages; }
    $offset = ($page - 1) * $perPage;

    $listWhere = $where ?: [];
    $listWhere['ORDER'] = ['id' => 'DESC'];
    $listWhere['LIMIT'] = [$offset, $perPage];
    $txns = $db->select('money_transactions',
        ['id','txn_ref','user_id','actor_kind','direction','reason','amount','currency','method','status','invoice_id','provider_trx_id','created_at'],
        $listWhere
    ) ?: [];

    // Summary tiles (respect active filters for the count of matching rows).
    $summary = [
        'total'   => $total,
        'success' => (int) $db->count('money_transactions', array_merge($where, ['status' => 'success'])),
        'pending' => (int) $db->count('money_transactions', array_merge($where, ['status' => ['pending','sent']])),
        'failed'  => (int) $db->count('money_transactions', array_merge($where, ['status' => ['failed','cancelled']])),
    ];

    require_once views."includes/header.php";
    require_once "app/views/admin/money/journeys.php";
    require_once views."includes/footer.php";
});

// ============================================================================
// MEMBERS — admin visibility into tier assignments + loyalty balances, and a
// manual points award/deduct. Answers "who is on which tier / who has how many
// points", which tier CRUD alone did not.
//   GET  /admin/finance/members            → agents-by-tier + loyalty balances
//   POST /admin/finance/members/points     → award/deduct points for a user
// ============================================================================
$router->get(admin.'/finance/members', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureAgentApiSchema')) { ensureAgentApiSchema($db); }

    $tiers = $db->select('agent_tiers', '*', ['ORDER' => ['sort_order' => 'ASC', 'min_lifetime_topup' => 'ASC']]) ?: [];
    foreach ($tiers as &$t) {
        $t['agent_count'] = (int) $db->count('users', ['role' => 'agent', 'agent_tier_id' => (int) $t['id']]);
    }
    unset($t);

    $agents = $db->select('users', ['user_id','first_name','last_name','email','agent_tier_id','loyalty_points'], [
        'role' => 'agent', 'ORDER' => ['loyalty_points' => 'DESC'], 'LIMIT' => 200
    ]) ?: [];
    $tierName = [];
    foreach ($tiers as $t) { $tierName[(int)$t['id']] = $t['name']; }
    foreach ($agents as &$a) {
        $a['tier_name'] = $tierName[(int)($a['agent_tier_id'] ?? 0)] ?? '—';
        $a['lifetime_topup'] = function_exists('agent_lifetime_topup') ? (float) agent_lifetime_topup($db, (string)$a['user_id']) : 0.0;
    }
    unset($a);

    $customers = $db->select('users', ['user_id','first_name','last_name','email','loyalty_points'], [
        'role' => 'customer', 'loyalty_points[>]' => 0, 'ORDER' => ['loyalty_points' => 'DESC'], 'LIMIT' => 200
    ]) ?: [];

    $defaultCurrency = $db->get('currencies', 'name', ['default' => '1']) ?: 'USD';

    require_once views."includes/header.php";
    require_once "app/views/admin/money/members.php";
    require_once views."includes/footer.php";
});

$router->post(admin.'/finance/members/points', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    if (!CSRF::validateToken($_POST['csrf_token'] ?? ($_POST['_token'] ?? ''))) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Invalid or expired form token.'];
        redirect($_SERVER['HTTP_REFERER'] ?? root . admin . '/finance/members');
        return;
    }
    if (function_exists('ensureAgentApiSchema')) { ensureAgentApiSchema($db); }

    $uid    = (string) ($_POST['user_id'] ?? '');
    $points = (int) ($_POST['points'] ?? 0);
    $action = ($_POST['action'] ?? 'award') === 'deduct' ? 'redeem' : 'earn';
    $note   = trim((string) ($_POST['note'] ?? ''));

    if ($uid === '' || $points <= 0) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'Pick a user and a positive number of points.'];
        redirect(root . admin . '/finance/members');
        return;
    }
    if (!$db->get('users', 'user_id', ['user_id' => $uid])) {
        $_SESSION['message'] = ['type' => 'error', 'text' => 'User not found.'];
        redirect(root . admin . '/finance/members');
        return;
    }

    if (!function_exists('loyalty_apply')) {
        require_once dirname(__DIR__, 2) . '/lib/wallet.php';
    }
    $r = loyalty_apply($db, $uid, $points, $action, [
        'reason' => 'adjust', 'ref_type' => 'admin', 'note' => $note !== '' ? $note : ('Admin ' . ($action === 'earn' ? 'award' : 'deduction')),
    ]);
    if (!empty($r['ok'])) {
        $_SESSION['message'] = ['type' => 'success', 'text' => ($action === 'earn' ? 'Awarded ' : 'Deducted ') . number_format($points) . ' points. New balance: ' . number_format((int)($r['balance'] ?? 0)) . '.'];
    } else {
        $_SESSION['message'] = ['type' => 'error', 'text' => $r['message'] ?? 'Could not adjust points.'];
    }
    redirect(root . admin . '/finance/members');
});

// ============================================================================
// MEMBER DRILL-DOWN — one member's full money picture in one place.
//   GET /admin/finance/members/{user_id}
// Profile + tier, wallet balance(s), spine transactions, loyalty ledger.
// (GET only; the POST /finance/members/points route is unaffected.)
// ============================================================================
$router->get(admin.'/finance/members/([A-Za-z0-9_\-]+)', function ($uid) use ($SECURE, $db) {
    ADMIN_AUTH();
    if (function_exists('ensureAgentApiSchema')) { ensureAgentApiSchema($db); }

    $uid = (string) $uid;
    $member = $db->get('users', [
        'user_id','first_name','last_name','email','phone','role','status','currency',
        'balance','credit_limits','agent_tier_id','loyalty_points','created_at','last_login'
    ], ['user_id' => $uid]);

    $tier = null; $lifetimeTopup = 0.0; $nextTier = null;
    $wallets = []; $txns = []; $ledger = []; $spend = 0.0; $topups = 0.0; $refunds = 0.0;

    if ($member) {
        // Tier + lifetime top-up (agents).
        if (!empty($member['agent_tier_id'])) {
            $tier = $db->get('agent_tiers', ['code','name','discount_percent','min_lifetime_topup'], ['id' => (int) $member['agent_tier_id']]);
        }
        if (strtolower((string) $member['role']) === 'agent' && function_exists('agent_lifetime_topup')) {
            $lifetimeTopup = (float) agent_lifetime_topup($db, $uid);
            $nextTier = $db->get('agent_tiers', ['name','min_lifetime_topup','discount_percent'], [
                'active' => 1, 'min_lifetime_topup[>]' => $lifetimeTopup, 'ORDER' => ['min_lifetime_topup' => 'ASC']
            ]) ?: null;
        }

        // Wallet balance(s) — spine.
        $wallets = $db->select('wallets', ['currency','balance','kind','updated_at'], ['user_id' => $uid]) ?: [];

        // Spine transactions (latest 100) + running totals.
        $txns = $db->select('money_transactions',
            ['id','txn_ref','direction','reason','amount','currency','method','status','invoice_id','provider_trx_id','created_at'],
            ['user_id' => $uid, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 100]
        ) ?: [];
        // Totals from successful movements only.
        $topups  = (float) ($db->sum('money_transactions', 'amount', ['user_id' => $uid, 'status' => 'success', 'reason' => 'wallet_topup']) ?: 0);
        $spend   = (float) ($db->sum('money_transactions', 'amount', ['user_id' => $uid, 'status' => 'success', 'direction' => 'debit', 'reason' => ['booking','booking_payment','wallet_spend','fee']]) ?: 0);
        $refunds = (float) ($db->sum('money_transactions', 'amount', ['user_id' => $uid, 'status' => 'success', 'direction' => 'credit', 'reason' => ['refund','reversal']]) ?: 0);

        // Loyalty ledger (latest 100).
        $ledger = $db->select('loyalty_ledger',
            ['direction','points','balance_after','reason','ref_type','ref_id','note','created_at'],
            ['user_id' => $uid, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 100]
        ) ?: [];
    }

    $defaultCurrency = $db->get('currencies', 'name', ['default' => '1']) ?: 'USD';

    require_once views."includes/header.php";
    require_once "app/views/admin/money/member-detail.php";
    require_once views."includes/footer.php";
});

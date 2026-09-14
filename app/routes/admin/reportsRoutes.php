<?php
// ============================================================================
// ADMIN REPORTS ROUTES
// ============================================================================
@$SECURE or die('Access Denied!');

// Booking Logs
$router->get(admin.'/reports/booking-logs(.*)', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== admin) {
        header('Location: ' . root);
        exit;
    }

    $title = 'Booking Logs - ' . $GLOBALS['app']['home_title'];
    $description = 'View all booking logs';

    require_once views."includes/header.php";
    require_once views."admin/reports/booking-logs.php";
    require_once views."includes/footer.php";
});

// Search Logs
$router->get(admin.'/reports/search-logs(.*)', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== admin) {
        header('Location: ' . root);
        exit;
    }

    $title = 'Search Logs - ' . $GLOBALS['app']['home_title'];
    $description = 'View all search logs';

    require_once views."includes/header.php";
    require_once views."admin/reports/search-logs.php";
    require_once views."includes/footer.php";
});

// Webhook Logs
$router->get(admin.'/reports/webhook-logs(.*)', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== admin) {
        header('Location: ' . root);
        exit;
    }

    $title = 'Webhook Logs - ' . $GLOBALS['app']['home_title'];
    $description = 'View all webhook execution logs';

    require_once views."includes/header.php";
    require_once views."admin/reports/webhook-logs.php";
    require_once views."includes/footer.php";
});

// Booking Reports
$router->get(admin.'/reports/bookings(.*)', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== admin) {
        header('Location: ' . root);
        exit;
    }

    $title = 'Booking Reports - ' . $GLOBALS['app']['home_title'];
    $description = 'View booking analytics and reports';

    require_once views."includes/header.php";
    require_once views."admin/reports/bookings.php";
    require_once views."includes/footer.php";
});

// Users Reports
$router->get(admin.'/reports/users(.*)', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== admin) {
        header('Location: ' . root);
        exit;
    }

    $title = 'Users Reports - ' . $GLOBALS['app']['home_title'];
    $description = 'View user analytics and reports';

    require_once views."includes/header.php";
    require_once views."admin/reports/users.php";
    require_once views."includes/footer.php";
});

// transactions Reports
$router->get(admin.'/reports/transactions(.*)', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== admin) {
        header('Location: ' . root);
        exit;
    }

    $title = 'Transactions Reports - ' . $GLOBALS['app']['home_title'];
    $description = 'View transaction analytics and reports';

    require_once views."includes/header.php";
    require_once views."admin/reports/transactions-logs.php";
    require_once views."includes/footer.php";
});

// Deposit Reports
$router->get(admin.'/reports/deposit(.*)', function () use ($SECURE, $db) {
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== admin) {
        header('Location: ' . root);
        exit;
    }

    $title = 'Deposit Reports - ' . $GLOBALS['app']['home_title'];
    $description = 'View deposit analytics and reports';

    require_once views."includes/header.php";
    require_once views."admin/reports/deposit.php";
    require_once views."includes/footer.php";
});

// ============================================================================
// FINANCE REPORT (docs/MONEY-WALLET-AUDIT.md) — revenue / cost / profit /
// agent earnings by module over a date range, plus spine cash-flow (top-ups,
// wallet payments, refunds). Read-only, ADMIN_AUTH.
//   GET /admin/reports/finance?from=YYYY-MM-DD&to=YYYY-MM-DD&module=...
// ============================================================================
$router->get(admin.'/reports/finance', function () use ($SECURE, $db) {
    ADMIN_AUTH();

    // Date range — default: last 30 days (inclusive).
    $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d', strtotime($to . ' -30 days'));
    if ($from > $to) { [$from, $to] = [$to, $from]; }
    $start = $from . ' 00:00:00';
    $end   = $to   . ' 23:59:59';

    $moduleFilter = trim((string)($_GET['module'] ?? ''));

    // ---- REVENUE from bookings (PAID only). Money columns are stored as
    // varchar/text on this schema, so cast per row in PHP — never trust SUM()
    // on a varchar column. Uses paid_at when present, else created_at. ----
    $where = [
        'payment_status' => 'paid',
        'OR' => [
            'AND #paid' => ['paid_at[>=]' => $start, 'paid_at[<=]' => $end],
            // rows with no paid_at fall back to created_at within range
            'AND #created' => ['paid_at' => null, 'created_at[>=]' => $start, 'created_at[<=]' => $end],
        ],
    ];
    if ($moduleFilter !== '') { $where['module_type'] = $moduleFilter; }

    $rows = $db->select('bookings',
        ['module_type','currency_markup','price_original','price_markup','commission','agent_earning','tax'],
        $where
    ) ?: [];

    // Aggregate by module.
    $byModule = [];
    $tot = ['revenue' => 0.0, 'cost' => 0.0, 'commission' => 0.0, 'agent_earning' => 0.0, 'tax' => 0.0, 'count' => 0, 'net_profit' => 0.0];
    foreach ($rows as $r) {
        $m = $r['module_type'] ?: 'other';
        if (!isset($byModule[$m])) {
            $byModule[$m] = ['module' => $m, 'count' => 0, 'revenue' => 0.0, 'cost' => 0.0, 'commission' => 0.0, 'agent_earning' => 0.0, 'tax' => 0.0, 'net_profit' => 0.0];
        }
        $revenue = (float) $r['price_markup'];
        $cost    = (float) $r['price_original'];
        $comm    = (float) $r['commission'];
        $agent   = (float) $r['agent_earning'];
        $tax     = (float) $r['tax'];
        // Net platform profit = margin kept after paying the agent their share.
        $net = max(0.0, ($revenue - $cost) - $agent);

        $byModule[$m]['count']++;
        $byModule[$m]['revenue']       += $revenue;
        $byModule[$m]['cost']          += $cost;
        $byModule[$m]['commission']    += $comm;
        $byModule[$m]['agent_earning'] += $agent;
        $byModule[$m]['tax']           += $tax;
        $byModule[$m]['net_profit']    += $net;

        $tot['count']++; $tot['revenue'] += $revenue; $tot['cost'] += $cost;
        $tot['commission'] += $comm; $tot['agent_earning'] += $agent; $tot['tax'] += $tax; $tot['net_profit'] += $net;
    }
    // Sort modules by revenue desc.
    uasort($byModule, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

    // ---- CASH FLOW from the money spine (successful transactions in range) ----
    $spine = ['topups' => 0.0, 'wallet_payments' => 0.0, 'refunds' => 0.0, 'gateway_payments' => 0.0];
    $spineAvailable = false;
    try {
        if ((int)$db->count('money_transactions', ['LIMIT' => 1]) >= 0) {
            $spineAvailable = true;
            $rangeWhere = ['status' => 'success', 'created_at[>=]' => $start, 'created_at[<=]' => $end];
            $spine['topups']           = (float) ($db->sum('money_transactions', 'amount', array_merge($rangeWhere, ['reason' => 'wallet_topup'])) ?: 0);
            $spine['wallet_payments']  = (float) ($db->sum('money_transactions', 'amount', array_merge($rangeWhere, ['method' => 'wallet', 'direction' => 'debit', 'reason' => ['booking_payment','wallet_spend','fee']])) ?: 0);
            $spine['gateway_payments'] = (float) ($db->sum('money_transactions', 'amount', array_merge($rangeWhere, ['method' => 'gateway', 'direction' => 'debit', 'reason' => 'booking_payment'])) ?: 0);
            $spine['refunds']          = (float) ($db->sum('money_transactions', 'amount', array_merge($rangeWhere, ['direction' => 'credit', 'reason' => ['refund','reversal']])) ?: 0);
        }
    } catch (\Throwable $e) { $spineAvailable = false; }

    // Module list for the filter dropdown.
    $modules = $db->select('modules', ['type'], ['status' => '1', 'GROUP' => 'type']) ?: [];
    $moduleTypes = array_values(array_filter(array_map(fn($x) => $x['type'] ?? '', $modules)));

    $defaultCurrency = $db->get('currencies', 'name', ['default' => '1']) ?: 'USD';

    $title = 'Finance Report - ' . ($GLOBALS['app']['home_title'] ?? 'Admin');
    $description = 'Revenue, cost, profit and cash flow';

    require_once views."includes/header.php";
    require_once views."admin/reports/finance.php";
    require_once views."includes/footer.php";
});

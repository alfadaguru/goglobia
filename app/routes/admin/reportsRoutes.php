<?php
// ============================================================================
// ADMIN REPORTS ROUTES
// ============================================================================
@$SECURE or die('Access Denied!');

// Shared CSV streamer for report exports. Sets download headers, writes a header
// row then each data row, with a formula-injection guard (a cell starting with
// = + - @ or tab/CR becomes a live formula in Excel/Sheets — prefix it with a
// quote so it renders as text). Ends the request.
if (!function_exists('_report_stream_csv')) {
    function _report_stream_csv(string $filename, array $header, array $rows): void {
        while (ob_get_level()) { ob_end_clean(); }
        $safeName = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $filename);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Cache-Control: no-store');
        $csvSafe = static function ($v): string {
            $v = (string) $v;
            if ($v !== '' && preg_match('/^[=+\-@\t\r]/', $v)) { return "'" . $v; }
            return $v;
        };
        $out = fopen('php://output', 'w');
        // Excel-friendly UTF-8 BOM.
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map($csvSafe, $header));
        foreach ($rows as $r) {
            fputcsv($out, array_map($csvSafe, $r));
        }
        fclose($out);
        exit;
    }
}

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

    // CSV export (same aggregation, no view). ?format=csv
    if (($_GET['format'] ?? '') === 'csv') {
        $csvRows = [];
        foreach ($byModule as $row) {
            $csvRows[] = [
                ucfirst((string)$row['module']), (int)$row['count'],
                number_format((float)$row['revenue'], 2, '.', ''),
                number_format((float)$row['cost'], 2, '.', ''),
                number_format((float)$row['agent_earning'], 2, '.', ''),
                number_format((float)$row['tax'], 2, '.', ''),
                number_format((float)$row['net_profit'], 2, '.', ''),
            ];
        }
        $csvRows[] = ['TOTAL', (int)$tot['count'],
            number_format((float)$tot['revenue'], 2, '.', ''),
            number_format((float)$tot['cost'], 2, '.', ''),
            number_format((float)$tot['agent_earning'], 2, '.', ''),
            number_format((float)$tot['tax'], 2, '.', ''),
            number_format((float)$tot['net_profit'], 2, '.', ''),
        ];
        // Blank line, then wallet cash-flow summary.
        $csvRows[] = [];
        $csvRows[] = ['Wallet cash flow (' . $defaultCurrency . ')'];
        $csvRows[] = ['Wallet top-ups', number_format((float)$spine['topups'], 2, '.', '')];
        $csvRows[] = ['Paid from wallet', number_format((float)$spine['wallet_payments'], 2, '.', '')];
        $csvRows[] = ['Paid by gateway', number_format((float)$spine['gateway_payments'], 2, '.', '')];
        $csvRows[] = ['Refunds', number_format((float)$spine['refunds'], 2, '.', '')];
        _report_stream_csv(
            'finance-report_' . $from . '_to_' . $to . '.csv',
            ['Module', 'Bookings', 'Revenue (' . $defaultCurrency . ')', 'Cost', 'Agent earnings', 'Tax', 'Net profit'],
            $csvRows
        );
    }

    $title = 'Finance Report - ' . ($GLOBALS['app']['home_title'] ?? 'Admin');
    $description = 'Revenue, cost, profit and cash flow';

    require_once views."includes/header.php";
    require_once views."admin/reports/finance.php";
    require_once views."includes/footer.php";
});

// ============================================================================
// PER-AGENT COMMISSION STATEMENTS (docs/MONEY-WALLET-AUDIT.md)
//   GET /admin/reports/agent-commissions?from=&to=            → all agents summary
//   GET /admin/reports/agent-commissions/{user_id}?from=&to=  → one statement
// What each agent earned (bookings.agent_earning) on their PAID bookings in the
// period. Read-only, ADMIN_AUTH. Money columns are varchar/text -> cast per row.
// ============================================================================

// Shared: resolve the date range from the query (defaults to last 30 days).
if (!function_exists('_agentcomm_range')) {
    function _agentcomm_range(): array {
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d', strtotime($to . ' -30 days'));
        if ($from > $to) { [$from, $to] = [$to, $from]; }
        return [$from, $to, $from . ' 00:00:00', $to . ' 23:59:59'];
    }
    // A paid booking's WHERE clause for a given agent (or all), within range.
    function _agentcomm_where(string $start, string $end, ?array $agentIds = null): array {
        $w = [
            'payment_status' => 'paid',
            'OR' => [
                'AND #paid'    => ['paid_at[>=]' => $start, 'paid_at[<=]' => $end],
                'AND #created' => ['paid_at' => null, 'created_at[>=]' => $start, 'created_at[<=]' => $end],
            ],
        ];
        if ($agentIds !== null) { $w['user_id'] = $agentIds; }
        return $w;
    }
}

// ---- Summary: all agents ----
$router->get(admin.'/reports/agent-commissions', function () use ($SECURE, $db) {
    ADMIN_AUTH();
    [$from, $to, $start, $end] = _agentcomm_range();

    // Agents keyed by user_id.
    $agents = $db->select('users', ['user_id','first_name','last_name','email'], ['role' => 'agent']) ?: [];
    $agentIds = array_map(fn($a) => (string)$a['user_id'], $agents);
    $byAgent = [];
    foreach ($agents as $a) {
        $byAgent[(string)$a['user_id']] = [
            'user_id' => (string)$a['user_id'],
            'name' => trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')) ?: ($a['email'] ?? $a['user_id']),
            'email' => $a['email'] ?? '',
            'count' => 0, 'sales' => 0.0, 'earning' => 0.0,
        ];
    }

    $tot = ['count' => 0, 'sales' => 0.0, 'earning' => 0.0, 'agents' => 0];
    if (!empty($agentIds)) {
        $rows = $db->select('bookings',
            ['user_id','currency_markup','price_markup','agent_earning'],
            _agentcomm_where($start, $end, $agentIds)
        ) ?: [];
        foreach ($rows as $r) {
            $uid = (string)$r['user_id'];
            if (!isset($byAgent[$uid])) { continue; }
            $byAgent[$uid]['count']++;
            $byAgent[$uid]['sales']   += (float)$r['price_markup'];
            $byAgent[$uid]['earning'] += (float)$r['agent_earning'];
            $tot['count']++; $tot['sales'] += (float)$r['price_markup']; $tot['earning'] += (float)$r['agent_earning'];
        }
    }
    // Only show agents with activity first, ranked by earning; keep zero-activity agents below.
    uasort($byAgent, fn($x, $y) => ($y['earning'] <=> $x['earning']) ?: ($y['count'] <=> $x['count']));
    $tot['agents'] = count(array_filter($byAgent, fn($a) => $a['count'] > 0));

    $defaultCurrency = $db->get('currencies', 'name', ['default' => '1']) ?: 'USD';
    $view = 'summary';

    // CSV export (per-agent). ?format=csv
    if (($_GET['format'] ?? '') === 'csv') {
        $csvRows = [];
        foreach ($byAgent as $a) {
            $csvRows[] = [
                (string)$a['name'], (string)$a['email'], (string)$a['user_id'],
                (int)$a['count'],
                number_format((float)$a['sales'], 2, '.', ''),
                number_format((float)$a['earning'], 2, '.', ''),
            ];
        }
        $csvRows[] = ['TOTAL', '', '', (int)$tot['count'],
            number_format((float)$tot['sales'], 2, '.', ''),
            number_format((float)$tot['earning'], 2, '.', ''),
        ];
        _report_stream_csv(
            'agent-commissions_' . $from . '_to_' . $to . '.csv',
            ['Agent', 'Email', 'User ID', 'Paid bookings', 'Sales (' . $defaultCurrency . ')', 'Commission'],
            $csvRows
        );
    }

    $title = 'Agent Commissions - ' . ($GLOBALS['app']['home_title'] ?? 'Admin');
    $description = 'Per-agent commission statements';
    require_once views."includes/header.php";
    require_once views."admin/reports/agent-commissions.php";
    require_once views."includes/footer.php";
});

// ---- Statement: one agent ----
$router->get(admin.'/reports/agent-commissions/([A-Za-z0-9_\-]+)', function ($uid) use ($SECURE, $db) {
    ADMIN_AUTH();
    [$from, $to, $start, $end] = _agentcomm_range();
    $uid = (string) $uid;

    $agent = $db->get('users', ['user_id','first_name','last_name','email','phone'], ['user_id' => $uid, 'role' => 'agent']);
    $lines = []; $tot = ['count' => 0, 'sales' => 0.0, 'cost' => 0.0, 'earning' => 0.0];

    if ($agent) {
        $rows = $db->select('bookings',
            ['invoice_id','module_type','currency_markup','price_original','price_markup','agent_earning','commission','paid_at','created_at'],
            array_merge(_agentcomm_where($start, $end, [$uid]), ['ORDER' => ['id' => 'DESC']])
        ) ?: [];
        foreach ($rows as $r) {
            $lines[] = [
                'invoice_id' => $r['invoice_id'],
                'module'     => $r['module_type'],
                'currency'   => $r['currency_markup'],
                'sale'       => (float)$r['price_markup'],
                'cost'       => (float)$r['price_original'],
                'earning'    => (float)$r['agent_earning'],
                'when'       => $r['paid_at'] ?: $r['created_at'],
            ];
            $tot['count']++;
            $tot['sales']   += (float)$r['price_markup'];
            $tot['cost']    += (float)$r['price_original'];
            $tot['earning'] += (float)$r['agent_earning'];
        }
    }

    $defaultCurrency = $db->get('currencies', 'name', ['default' => '1']) ?: 'USD';
    $view = 'statement';

    // CSV export (per-booking statement). ?format=csv
    if (($_GET['format'] ?? '') === 'csv') {
        $csvRows = [];
        foreach ($lines as $l) {
            $csvRows[] = [
                (string)$l['invoice_id'], ucfirst((string)$l['module']), (string)$l['when'],
                number_format((float)$l['sale'], 2, '.', ''),
                number_format((float)$l['cost'], 2, '.', ''),
                number_format((float)$l['earning'], 2, '.', ''),
            ];
        }
        $csvRows[] = ['TOTAL', '', (int)$tot['count'] . ' bookings',
            number_format((float)$tot['sales'], 2, '.', ''),
            number_format((float)$tot['cost'], 2, '.', ''),
            number_format((float)$tot['earning'], 2, '.', ''),
        ];
        $agentSlug = $agent ? preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$agent['user_id']) : 'agent';
        _report_stream_csv(
            'agent-statement_' . $agentSlug . '_' . $from . '_to_' . $to . '.csv',
            ['Invoice', 'Module', 'Paid', 'Sale (' . $defaultCurrency . ')', 'Cost', 'Commission'],
            $csvRows
        );
    }

    $title = 'Agent Statement - ' . ($GLOBALS['app']['home_title'] ?? 'Admin');
    $description = 'Agent commission statement';
    require_once views."includes/header.php";
    require_once views."admin/reports/agent-commissions.php";
    require_once views."includes/footer.php";
});

<?php
// ============================================================================
// app/routes/users/agentReportsRoutes.php — Agent ERP P7b: reports & statements.
// Agency-scoped versions of the proven admin report queries (reportsRoutes.php
// _agentcomm_*), filtered to this agency and PAID-only (the correct basis — the
// customer dashboard's all-bookings sum was a bug we avoid here).
//   GET /agent/reports              → sales + earnings by service (date range)
//   GET /agent/reports/export       → CSV of the per-booking statement
//   GET /agent/reports/statement    → wallet statement (CSV) from wallet_ledger
// Owner-scoped; agent_can('reports', view|export).
// ============================================================================
@$SECURE or die('Access Denied!');

if (!function_exists('_agent_report_range')) {
    function _agent_report_range(): array {
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? ''))   ? $_GET['to']   : date('Y-m-d');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d', strtotime($to . ' -30 days'));
        if ($from > $to) { [$from, $to] = [$to, $from]; }
        return [$from, $to, $from . ' 00:00:00', $to . ' 23:59:59'];
    }
    // Paid bookings for ONE agency within range. Top-level keys are AND-ed by
    // Medoo, so the two OR groups (scope + date-window), each given a unique
    // #suffix, combine as (scope) AND (date) AND payment_status='paid'. This
    // mirrors the proven reportsRoutes.php grouping, one group added.
    function _agent_report_where(string $owner, string $start, string $end): array {
        return [
            'payment_status' => 'paid',
            'OR #scope' => ['agency_id' => $owner, 'user_id' => $owner],
            'OR #when' => [
                'AND #paid'    => ['paid_at[>=]' => $start, 'paid_at[<=]' => $end],
                'AND #created' => ['paid_at' => null, 'created_at[>=]' => $start, 'created_at[<=]' => $end],
            ],
            'ORDER' => ['id' => 'DESC'],
        ];
    }
    // Minimal CSV streamer (formula-injection safe), mirroring the admin one.
    function _agent_csv(string $filename, array $header, array $rows): void {
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
        }
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM
        $san = function ($v) {
            $v = (string) $v;
            if ($v === '') { return $v; }
            // A genuine number (incl. negative like -10.00 or 1,234.50) must stay
            // numeric so spreadsheet columns still sum — don't prefix those. Only
            // guard NON-numeric cells that begin with a formula-trigger char,
            // including tab/CR which bypass the naive first-char check (audit).
            $isNumber = preg_match('/^-?[\d,]*\.?\d+$/', $v) === 1;
            if (!$isNumber && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                $v = "'" . $v;
            }
            return $v;
        };
        fputcsv($out, array_map($san, $header));
        foreach ($rows as $r) { fputcsv($out, array_map($san, $r)); }
        fclose($out);
    }
}

if (!function_exists('_agent_reports_ctx')) {
    function _agent_reports_ctx($db): array {
        AGENT_OR_STAFF_AUTH($db);
        $ctx = agent_acting_context($db);
        if ($ctx === null || !empty($ctx['is_admin'])) { header('Location: ' . root . 'login'); exit; }
        return [$ctx, (string) $ctx['owner']];
    }
}

// ---- GET /agent/reports -----------------------------------------------------
$router->get('/agent/reports', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_reports_ctx($db);
    if (!agent_can($db, 'reports', 'view')) {
        $_SESSION['message'] = ['type'=>'error','text'=>'You do not have access to reports.'];
        header('Location: ' . root . 'agent/dashboard'); exit;
    }
    [$from, $to, $start, $end] = _agent_report_range();

    // GAP-3: fetch the attribution columns too (booked_by_staff_id, agent_client_id)
    // so the SAME paid-bookings result set drives the by-service, per-staff and
    // per-client breakdowns — one query, three aggregations (no extra round-trips).
    $rows = [];
    try {
        $rows = $db->select('bookings',
            ['module_type','price_markup','price_original','agent_earning','agent_client_price','agent_client_margin','currency_markup','booked_by_staff_id','agent_client_id'],
            _agent_report_where($owner, $start, $end)) ?: [];
    } catch (\Throwable $e) { error_log('agent/reports: ' . $e->getMessage()); }

    // Aggregate by service + by staff + by client from the one result set.
    $byService = []; $byStaff = []; $byClient = [];
    $totSales = 0.0; $totClient = 0.0; $totMargin = 0.0; $count = 0;
    foreach ($rows as $r) {
        $svc = (string)($r['module_type'] ?? 'other');
        $cost = (float)($r['price_markup'] ?? 0);                 // what the agent paid us
        $client = (float)($r['agent_client_price'] ?? 0) ?: $cost; // client price (self-markup) or cost
        $margin = (float)($r['agent_client_margin'] ?? 0);         // agent's own margin
        if (!isset($byService[$svc])) { $byService[$svc] = ['service'=>$svc,'count'=>0,'cost'=>0.0,'client'=>0.0,'margin'=>0.0]; }
        $byService[$svc]['count']++; $byService[$svc]['cost'] += $cost;
        $byService[$svc]['client'] += $client; $byService[$svc]['margin'] += $margin;

        // Per-staff attribution (booked_by_staff_id = the acting user_id; the owner's
        // own bookings carry the owner's id — resolved to a name below). Empty = legacy.
        $sid = (string)($r['booked_by_staff_id'] ?? '');
        if ($sid !== '') {
            if (!isset($byStaff[$sid])) { $byStaff[$sid] = ['staff_id'=>$sid,'count'=>0,'cost'=>0.0,'client'=>0.0,'margin'=>0.0]; }
            $byStaff[$sid]['count']++; $byStaff[$sid]['cost'] += $cost;
            $byStaff[$sid]['client'] += $client; $byStaff[$sid]['margin'] += $margin;
        }

        // Per-client lifetime value (agent_client_id, resolved from CRM by email at
        // settlement). Null = walk-up / no CRM match; bucket those separately.
        $cid = (int)($r['agent_client_id'] ?? 0);
        $ckey = $cid > 0 ? (string)$cid : '0';
        if (!isset($byClient[$ckey])) { $byClient[$ckey] = ['client_id'=>$cid,'count'=>0,'cost'=>0.0,'client'=>0.0,'margin'=>0.0]; }
        $byClient[$ckey]['count']++; $byClient[$ckey]['cost'] += $cost;
        $byClient[$ckey]['client'] += $client; $byClient[$ckey]['margin'] += $margin;

        $totSales += $cost; $totClient += $client; $totMargin += $margin; $count++;
    }
    ksort($byService);

    // Resolve staff names (booked_by_staff_id → users). The owner's own id resolves
    // too and is labelled "(you / owner)" in the view. One lookup per distinct id.
    foreach ($byStaff as $sid => &$s) {
        $s['name'] = $sid; $s['is_owner'] = ($sid === $owner);
        try {
            $u = $db->get('users', ['first_name','last_name','email'], ['user_id' => $sid]);
            if ($u) { $s['name'] = trim(($u['first_name']??'').' '.($u['last_name']??'')) ?: ($u['email'] ?? $sid); }
        } catch (\Throwable $e) {}
    } unset($s);
    // Sort staff by margin desc (top performers first).
    uasort($byStaff, fn($a,$b) => $b['margin'] <=> $a['margin']);

    // Resolve client names (agent_client_id → agent_clients, scoped to this agency).
    foreach ($byClient as $ckey => &$c) {
        if ($c['client_id'] > 0) {
            $c['name'] = 'Client #' . $c['client_id'];
            try {
                $cc = $db->get('agent_clients', ['first_name','last_name','email'], ['id' => $c['client_id'], 'owner_user_id' => $owner]);
                if ($cc) { $c['name'] = trim(($cc['first_name']??'').' '.($cc['last_name']??'')) ?: ($cc['email'] ?? ('Client #'.$c['client_id'])); }
            } catch (\Throwable $e) {}
        } else {
            $c['name'] = 'Walk-up / no CRM match';
        }
    } unset($c);
    uasort($byClient, fn($a,$b) => $b['margin'] <=> $a['margin']);

    $defaultCurrency = function_exists('wallet_default_currency') ? wallet_default_currency($db) : 'NGN';
    $canExport = agent_can($db, 'reports', 'export');

    // ---- AGING / RECEIVABLES (UNPAID bookings) ------------------------------
    // A SEPARATE query from the paid report: the agency's bookings that are NOT
    // yet paid to us — what the agency still owes. Scope mirrors the bookings-list
    // (agency_id OR user_id = owner) since agency_id is only stamped at settlement,
    // so an unpaid booking is matched by the agent's own user_id. NOT date-ranged
    // (receivables are current, not period-bound). Aged by payment_due_at if set,
    // else created_at. Buckets: not-due, 0-7, 8-30, 31+ days overdue.
    $aging = ['buckets' => ['current'=>0.0,'d0_7'=>0.0,'d8_30'=>0.0,'d31'=>0.0],
              'counts'  => ['current'=>0,  'd0_7'=>0,  'd8_30'=>0,  'd31'=>0],
              'total'   => 0.0, 'count' => 0, 'rows' => []];
    try {
        $due = $db->select('bookings',
            ['invoice_id','module_type','price_markup','currency_markup','payment_status','booking_status','payment_due_at','created_at'],
            [
                'OR #scope' => ['agency_id' => $owner, 'user_id' => $owner],
                'payment_status' => ['unpaid', 'awaiting_verification', 'partially_paid'],
                // Exclude dead bookings — a cancelled/voided unpaid booking is not a receivable.
                'booking_status[!]' => ['cancelled', 'voided', 'refunded'],
                'ORDER' => ['id' => 'DESC'],
                'LIMIT' => 500,
            ]) ?: [];
        $nowTs = time();
        foreach ($due as $d) {
            $amt = round((float)($d['price_markup'] ?? 0), 2);
            if ($amt <= 0) { continue; }
            $dueStr = (string)($d['payment_due_at'] ?? '');
            $refTs  = $dueStr !== '' ? strtotime($dueStr) : strtotime((string)($d['created_at'] ?? 'now'));
            $overdueDays = $refTs ? (int) floor(($nowTs - $refTs) / 86400) : 0;
            if ($dueStr !== '' && $refTs && $refTs > $nowTs) { $bucket = 'current'; $overdueDays = 0; }
            elseif ($overdueDays <= 7)  { $bucket = 'd0_7'; }
            elseif ($overdueDays <= 30) { $bucket = 'd8_30'; }
            else { $bucket = 'd31'; }
            $aging['buckets'][$bucket] += $amt; $aging['counts'][$bucket]++;
            $aging['total'] += $amt; $aging['count']++;
            if (count($aging['rows']) < 100) {
                $aging['rows'][] = [
                    'invoice_id' => $d['invoice_id'] ?? '', 'service' => $d['module_type'] ?? '',
                    'amount' => $amt, 'currency' => $d['currency_markup'] ?? $defaultCurrency,
                    'status' => $d['payment_status'] ?? '', 'overdue_days' => max(0, $overdueDays),
                    'due' => $dueStr ?: ($d['created_at'] ?? ''),
                ];
            }
        }
    } catch (\Throwable $e) { error_log('agent/reports aging: ' . $e->getMessage()); }

    $title = 'Reports';
    require_once views."agent/layout-top.php";
    require_once views."agent/reports.php";
    require_once views."agent/layout-bottom.php";
});

// ---- GET /agent/reports/export  (per-booking statement CSV) ----------------
$router->get('/agent/reports/export', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_reports_ctx($db);
    if (!agent_can($db, 'reports', 'export')) { header('Location: ' . root . 'agent/reports'); exit; }
    [$from, $to, $start, $end] = _agent_report_range();
    $rows = [];
    try {
        $rows = $db->select('bookings',
            ['invoice_id','module_type','currency_markup','price_original','price_markup','agent_client_price','agent_client_margin','paid_at','created_at'],
            _agent_report_where($owner, $start, $end)) ?: [];
    } catch (\Throwable $e) { error_log('agent/reports export: ' . $e->getMessage()); }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            $r['invoice_id'] ?? '', $r['module_type'] ?? '', $r['currency_markup'] ?? '',
            number_format((float)($r['price_original'] ?? 0), 2, '.', ''),
            number_format((float)($r['price_markup'] ?? 0), 2, '.', ''),
            number_format((float)($r['agent_client_price'] ?? 0), 2, '.', ''),
            number_format((float)($r['agent_client_margin'] ?? 0), 2, '.', ''),
            $r['paid_at'] ?: ($r['created_at'] ?? ''),
        ];
    }
    _agent_csv('agent-statement-' . $from . '_' . $to . '.csv',
        ['Invoice','Service','Currency','Net cost','You paid','Client price','Your margin','When'], $out);
    exit;
});

// ---- GET /agent/reports/statement  (wallet ledger CSV) ---------------------
$router->get('/agent/reports/statement', function () use ($SECURE, $db) {
    [$ctx, $owner] = _agent_reports_ctx($db);
    if (!agent_can($db, 'reports', 'export')) { header('Location: ' . root . 'agent/reports'); exit; }
    $ledger = [];
    try {
        $ledger = $db->select('wallet_ledger',
            ['created_at','direction','amount','balance_after','currency','reason','note'],
            ['user_id' => $owner, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 5000]) ?: [];
    } catch (\Throwable $e) { error_log('agent/reports statement: ' . $e->getMessage()); }
    $out = [];
    foreach ($ledger as $l) {
        $out[] = [
            $l['created_at'] ?? '', $l['direction'] ?? '',
            number_format((float)($l['amount'] ?? 0), 2, '.', ''),
            number_format((float)($l['balance_after'] ?? 0), 2, '.', ''),
            $l['currency'] ?? '', $l['reason'] ?? '', $l['note'] ?? '',
        ];
    }
    _agent_csv('agent-wallet-statement-' . date('Ymd') . '.csv',
        ['Date','Direction','Amount','Balance after','Currency','Reason','Note'], $out);
    exit;
});

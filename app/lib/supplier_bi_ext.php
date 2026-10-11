<?php
// FILE: app/lib/supplier_bi_ext.php
// BI / BUSINESS-INTELLIGENCE EXTENSIONS (PMS #56 deepen). READ-ONLY analytics +
// a bounded, whitelist-only custom report builder + internal scheduled-report
// RECORDS. Builds on the existing supplier_bi.php (bi_dashboard et al) WITHOUT
// touching it. No new facts are invented: every figure maps to a real table.
//
//   portfolio KPI rollup           → stays_night_audits (S25) + supplier_earnings (S18)
//   per-property / department P&L   → journal_entries.property_id + journal_lines (S34)
//   custom report datasets          → bookings / stays_night_audits / pos_orders / GL
//   scheduled-report records         → supplier_report_schedules (NEW, DB-GATED)
//
// MONEY: all P&L figures are DERIVED from already-posted GL (gl_* helpers). This file
// NEVER posts GL, never writes a folio, never trusts a client amount. The only writes
// are schedule METADATA rows (name / dataset / cadence) — no money, no GL.
//
// DELIVERY STUB (honest): scheduled reports are stored but NOT delivered — there is no
// mailer/cron wired to them yet. supplier_report_delivery_status() reports
// 'not_connected' so the UI never implies a live email integration. See deferred[].

// ---------------------------------------------------------------------------
// 1. PORTFOLIO / EXECUTIVE ROLLUP — blended KPIs across ALL owned properties.
//    The existing bi_dashboard trend is single-property; this is the org view.
// ---------------------------------------------------------------------------
if (!function_exists('bi_ext_portfolio_rollup')) {
    /**
     * Blended operating KPIs across every owned property, from the latest night-audit
     * snapshot per property (not a time-series — a point-in-time portfolio board).
     * Returns ['properties'=>int,'with_audit'=>int,'rooms_available'=>int,
     *          'rooms_sold'=>int,'occupancy_pct'=>float,'adr'=>float,'revpar'=>float,
     *          'room_revenue'=>float,'rows'=>[per-property]]. Read-only; non-fatal.
     */
    function bi_ext_portfolio_rollup($db, array $stayIds, array $propMap = []): array
    {
        $out = [
            'properties' => count($stayIds), 'with_audit' => 0,
            'rooms_available' => 0, 'rooms_sold' => 0, 'occupancy_pct' => 0.0,
            'adr' => 0.0, 'revpar' => 0.0, 'room_revenue' => 0.0, 'rows' => [],
        ];
        if (empty($stayIds)) { return $out; }
        foreach ($stayIds as $sid) {
            $sid = (int) $sid;
            $latest = null;
            try {
                $latest = $db->get('stays_night_audits',
                    ['business_date', 'rooms_sold', 'rooms_available', 'room_revenue', 'adr', 'revpar', 'occupancy_pct'],
                    ['stay_id' => $sid, 'ORDER' => ['business_date' => 'DESC'], 'LIMIT' => 1]);
            } catch (\Throwable $e) { error_log('bi_ext_portfolio_rollup: ' . $e->getMessage()); }
            $row = [
                'stay_id' => $sid,
                'name' => (string) ($propMap[$sid] ?? ('#' . $sid)),
                'business_date' => $latest['business_date'] ?? null,
                'rooms_sold' => (int) ($latest['rooms_sold'] ?? 0),
                'rooms_available' => (int) ($latest['rooms_available'] ?? 0),
                'room_revenue' => round((float) ($latest['room_revenue'] ?? 0), 2),
                'adr' => round((float) ($latest['adr'] ?? 0), 2),
                'revpar' => round((float) ($latest['revpar'] ?? 0), 2),
                'occupancy_pct' => $latest && $latest['occupancy_pct'] !== null ? round((float) $latest['occupancy_pct'], 1) : null,
            ];
            if ($latest) {
                $out['with_audit']++;
                $out['rooms_available'] += $row['rooms_available'];
                $out['rooms_sold'] += $row['rooms_sold'];
                $out['room_revenue'] = round($out['room_revenue'] + $row['room_revenue'], 2);
            }
            $out['rows'][] = $row;
        }
        // Blended ratios from portfolio totals (not averages of averages).
        if ($out['rooms_available'] > 0) {
            $out['occupancy_pct'] = round($out['rooms_sold'] / $out['rooms_available'] * 100, 1);
            $out['revpar'] = round($out['room_revenue'] / $out['rooms_available'], 2);
        }
        if ($out['rooms_sold'] > 0) {
            $out['adr'] = round($out['room_revenue'] / $out['rooms_sold'], 2);
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// 2. DEPARTMENT / PER-PROPERTY P&L — GL-derived, from journal_entries.property_id.
//    gl_financial_summary() is ORG-wide only; this splits the SAME GL by property.
// ---------------------------------------------------------------------------
if (!function_exists('bi_ext_property_pl')) {
    /**
     * Per-property P&L derived from posted GL: for each property_id on the org's
     * journal_entries, sum revenue/expense account balances from their lines. Entries
     * with NULL property_id roll into an 'unallocated' bucket (honest — shared postings
     * aren't department-tagged). Read-only; reuses the account TYPE classification the
     * ledger already owns. Returns:
     *   ['rows'=>[ ['property_id','name','revenue','expense','net'] ... ],
     *    'totals'=>['revenue','expense','net'], 'org_id'=>int].
     * NOTE: a single org here owns all the supplier's properties, so property_id is the
     * department axis. Signs follow gl_account_normal_side (revenue=credit, expense=debit).
     */
    function bi_ext_property_pl($db, int $orgId, array $propMap = []): array
    {
        $out = ['rows' => [], 'totals' => ['revenue' => 0.0, 'expense' => 0.0, 'net' => 0.0], 'org_id' => $orgId];
        if ($orgId <= 0) { return $out; }
        try {
            // Revenue/expense account ids for this org, keyed by id → type.
            $accts = $db->select('chart_of_accounts', ['id', 'type'],
                ['org_id' => $orgId, 'type' => ['revenue', 'expense']]) ?: [];
            if (empty($accts)) { return $out; }
            $acctType = [];
            foreach ($accts as $a) { $acctType[(int) $a['id']] = (string) $a['type']; }
            $acctIds = array_keys($acctType);

            // Join lines → entries to carry property_id onto each P&L line. Medoo join.
            $rows = $db->select('journal_lines',
                ['[>]journal_entries' => ['entry_id' => 'id']],
                ['journal_lines.account_id(account_id)',
                 'journal_lines.debit(debit)', 'journal_lines.credit(credit)',
                 'journal_entries.property_id(property_id)'],
                ['journal_lines.org_id' => $orgId, 'journal_lines.account_id' => $acctIds]) ?: [];

            $byProp = []; // property_id (0=unallocated) => ['revenue','expense']
            foreach ($rows as $r) {
                $aid = (int) $r['account_id'];
                $type = $acctType[$aid] ?? null;
                if ($type === null) { continue; }
                $pid = ($r['property_id'] === null || $r['property_id'] === '') ? 0 : (int) $r['property_id'];
                if (!isset($byProp[$pid])) { $byProp[$pid] = ['revenue' => 0.0, 'expense' => 0.0]; }
                $d = (float) $r['debit']; $c = (float) $r['credit'];
                if ($type === 'revenue') { $byProp[$pid]['revenue'] += ($c - $d); } // credit-normal
                else { $byProp[$pid]['expense'] += ($d - $c); }                       // debit-normal
            }
            foreach ($byProp as $pid => $v) {
                $rev = round($v['revenue'], 2); $exp = round($v['expense'], 2);
                $net = round($rev - $exp, 2);
                $name = $pid === 0 ? 'Unallocated' : (string) ($propMap[$pid] ?? ('Property #' . $pid));
                $out['rows'][] = ['property_id' => $pid, 'name' => $name,
                                  'revenue' => $rev, 'expense' => $exp, 'net' => $net];
                $out['totals']['revenue'] = round($out['totals']['revenue'] + $rev, 2);
                $out['totals']['expense'] = round($out['totals']['expense'] + $exp, 2);
            }
            $out['totals']['net'] = round($out['totals']['revenue'] - $out['totals']['expense'], 2);
            // Property first, unallocated last, by net desc.
            usort($out['rows'], function ($a, $b) {
                if (($a['property_id'] === 0) !== ($b['property_id'] === 0)) { return $a['property_id'] === 0 ? 1 : -1; }
                return $b['net'] <=> $a['net'];
            });
        } catch (\Throwable $e) { error_log('bi_ext_property_pl: ' . $e->getMessage()); }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// 3. CUSTOM REPORT BUILDER — WHITELIST ONLY. No client SQL, no client table/column
//    names reach the DB. The client picks a dataset key + a date range; the builder
//    runs a fixed, owner-scoped query and returns labelled columns + rows.
// ---------------------------------------------------------------------------
if (!function_exists('bi_ext_report_datasets')) {
    /** The report catalogue the builder offers. Each: label + column headers. The
     *  actual query lives in bi_ext_run_report() — this is just the UI/whitelist. */
    function bi_ext_report_datasets(): array
    {
        return [
            'reservations' => ['label' => 'Reservations (by day)',
                'columns' => ['Date', 'Confirmed', 'Pending', 'Cancelled', 'Total']],
            'night_audit'  => ['label' => 'Night audit (occupancy/ADR/RevPAR)',
                'columns' => ['Date', 'Property', 'Rooms sold', 'Occ %', 'ADR', 'RevPAR', 'Room revenue']],
            'fnb'          => ['label' => 'F&B settled orders (by tender)',
                'columns' => ['Tender', 'Orders', 'Amount']],
            'pl'           => ['label' => 'Profit & loss (per property, GL)',
                'columns' => ['Property', 'Revenue', 'Expense', 'Net']],
        ];
    }
}

if (!function_exists('bi_ext_clamp_range')) {
    /** Validate + clamp a client date range to a sane, bounded window (<= 400 days,
     *  default last 30). Returns ['from'=>'Y-m-d','to'=>'Y-m-d']. Never trusts raw input. */
    function bi_ext_clamp_range($from, $to): array
    {
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        $to   = (is_string($to)   && preg_match($re, $to))   ? $to   : date('Y-m-d');
        $from = (is_string($from) && preg_match($re, $from)) ? $from : date('Y-m-d', strtotime('-29 days', strtotime($to)));
        if ($from > $to) { [$from, $to] = [$to, $from]; }
        // Cap the span at 400 days to bound the scan.
        if ((strtotime($to) - strtotime($from)) > 400 * 86400) {
            $from = date('Y-m-d', strtotime('-400 days', strtotime($to)));
        }
        return ['from' => $from, 'to' => $to];
    }
}

if (!function_exists('bi_ext_run_report')) {
    /**
     * Run a whitelisted report for the OWNER's properties over a bounded date range.
     * $dataset MUST be a key of bi_ext_report_datasets() (caller validates too). All
     * queries are owner-scoped via $stayIds / $orgId — a report can NEVER read another
     * supplier's data. Read-only. Returns ['dataset','label','columns','rows'=>[[...]],
     * 'range'=>['from','to']]. Non-fatal on any dataset error (returns empty rows).
     */
    function bi_ext_run_report($db, string $dataset, array $stayIds, int $orgId,
                               array $range, array $propMap = []): array
    {
        $cat = bi_ext_report_datasets();
        $meta = $cat[$dataset] ?? null;
        $out = ['dataset' => $dataset, 'label' => $meta['label'] ?? $dataset,
                'columns' => $meta['columns'] ?? [], 'rows' => [], 'range' => $range];
        if ($meta === null) { return $out; }
        $from = $range['from']; $to = $range['to'];
        try {
            switch ($dataset) {
                case 'reservations':
                    $out['rows'] = _bi_ext_report_reservations($db, $stayIds, $from, $to);
                    break;
                case 'night_audit':
                    if (!empty($stayIds)) {
                        $rows = $db->select('stays_night_audits',
                            ['stay_id', 'business_date', 'rooms_sold', 'occupancy_pct', 'adr', 'revpar', 'room_revenue'],
                            ['stay_id' => $stayIds, 'business_date[<>]' => [$from, $to],
                             'ORDER' => ['business_date' => 'ASC'], 'LIMIT' => 2000]) ?: [];
                        foreach ($rows as $r) {
                            $out['rows'][] = [
                                (string) $r['business_date'],
                                (string) ($propMap[(int) $r['stay_id']] ?? ('#' . (int) $r['stay_id'])),
                                (int) $r['rooms_sold'],
                                $r['occupancy_pct'] === null ? '' : number_format((float) $r['occupancy_pct'], 1),
                                number_format((float) $r['adr'], 2),
                                number_format((float) $r['revpar'], 2),
                                number_format((float) $r['room_revenue'], 2),
                            ];
                        }
                    }
                    break;
                case 'fnb':
                    // Settled POS orders in range → sum by tender. Owner-scoped by stay_id.
                    if (!empty($stayIds)) {
                        $orders = $db->select('pos_orders', ['id'],
                            ['stay_id' => $stayIds, 'status' => 'settled',
                             'created_at[<>]' => [$from . ' 00:00:00', $to . ' 23:59:59'], 'LIMIT' => 20000]) ?: [];
                        $byTender = [];
                        if (!empty($orders)) {
                            $ids = array_map(fn($o) => (int) $o['id'], $orders);
                            $pays = $db->select('pos_payments', ['tender', 'amount'], ['order_id' => $ids]) ?: [];
                            foreach ($pays as $p) {
                                $t = (string) $p['tender'];
                                if (!isset($byTender[$t])) { $byTender[$t] = ['orders' => 0, 'amount' => 0.0]; }
                                $byTender[$t]['orders']++;
                                $byTender[$t]['amount'] += round((float) $p['amount'], 2);
                            }
                        }
                        foreach ($byTender as $t => $v) {
                            $out['rows'][] = [ucfirst($t), (int) $v['orders'], number_format($v['amount'], 2)];
                        }
                    }
                    break;
                case 'pl':
                    $pl = bi_ext_property_pl($db, $orgId, $propMap);
                    foreach ($pl['rows'] as $r) {
                        $out['rows'][] = [$r['name'], number_format($r['revenue'], 2),
                                          number_format($r['expense'], 2), number_format($r['net'], 2)];
                    }
                    break;
            }
        } catch (\Throwable $e) { error_log('bi_ext_run_report(' . $dataset . '): ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('_bi_ext_report_reservations')) {
    /** Reservations-by-day helper: bounded, decoded-verified own-inventory scan grouped
     *  by created date. Mirrors bi_reservations_by_status' verify-not-just-LIKE pattern. */
    function _bi_ext_report_reservations($db, array $stayIds, string $from, string $to): array
    {
        if (empty($stayIds)) { return []; }
        $set = array_fill_keys(array_map('intval', $stayIds), true);
        $likeValues = [];
        foreach ($stayIds as $sid) { $likeValues[] = '"hotel_id":' . (int) $sid; }
        $byDay = [];
        $rows = $db->select('bookings', ['booking_status', 'booking_data', 'date'],
            ['module_type' => ['stays', 'hotels'], 'booking_data[~]' => $likeValues,
             'date[<>]' => [$from . ' 00:00:00', $to . ' 23:59:59'],
             'ORDER' => ['id' => 'DESC'], 'LIMIT' => 5000]) ?: [];
        foreach ($rows as $r) {
            $bd = json_decode((string) ($r['booking_data'] ?? ''), true);
            if (!is_array($bd) || empty($set[(int) ($bd['hotel_id'] ?? 0)])) { continue; }
            $day = substr((string) ($r['date'] ?? ''), 0, 10);
            if ($day === '') { continue; }
            if (!isset($byDay[$day])) { $byDay[$day] = ['confirmed' => 0, 'pending' => 0, 'cancelled' => 0, 'total' => 0]; }
            $st = strtolower((string) ($r['booking_status'] ?? ''));
            if (isset($byDay[$day][$st])) { $byDay[$day][$st]++; }
            $byDay[$day]['total']++;
        }
        ksort($byDay);
        $out = [];
        foreach ($byDay as $day => $c) {
            $out[] = [$day, $c['confirmed'], $c['pending'], $c['cancelled'], $c['total']];
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// 4. SCHEDULED REPORTS — internal RECORDS only. DB-GATED. Delivery is a stub.
// ---------------------------------------------------------------------------
if (!function_exists('bi_ext_schedules_ensure')) {
    /** Idempotent self-heal for supplier_report_schedules (follows the house ensure-fn
     *  pattern: SHOW COLUMNS-style guard via CREATE IF NOT EXISTS, swallow failures). */
    function bi_ext_schedules_ensure($db): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `supplier_report_schedules` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) NOT NULL,
                `owner_user_id` varchar(155) NOT NULL,
                `name` varchar(150) NOT NULL,
                `dataset` varchar(40) NOT NULL,
                `cadence` varchar(20) NOT NULL DEFAULT 'weekly',
                `recipient_email` varchar(190) DEFAULT NULL,
                `last_run_at` datetime DEFAULT NULL,
                `delivery_status` varchar(30) NOT NULL DEFAULT 'not_connected',
                `active` tinyint(1) NOT NULL DEFAULT 1,
                `created_by` varchar(155) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `idx_org` (`org_id`),
                KEY `idx_owner` (`owner_user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) { error_log('bi_ext_schedules_ensure: ' . $e->getMessage()); }
    }
}

if (!function_exists('bi_ext_schedules_list')) {
    /** List the owner's saved report schedules (newest first). Read-only; owner-scoped. */
    function bi_ext_schedules_list($db, string $owner): array
    {
        bi_ext_schedules_ensure($db);
        try {
            return $db->select('supplier_report_schedules',
                ['id', 'name', 'dataset', 'cadence', 'recipient_email', 'last_run_at', 'delivery_status', 'active', 'created_at'],
                ['owner_user_id' => $owner, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 200]) ?: [];
        } catch (\Throwable $e) { error_log('bi_ext_schedules_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('bi_ext_schedule_create')) {
    /**
     * Create a report-schedule RECORD (no money, no GL, no delivery). Validates the
     * dataset against the whitelist + cadence against a fixed set. Owner-scoped: the
     * row is stamped with the acting owner + org. Returns ['ok'=>bool,'id'|'message'].
     */
    function bi_ext_schedule_create($db, int $orgId, string $owner, array $in): array
    {
        bi_ext_schedules_ensure($db);
        $name = trim((string) ($in['name'] ?? ''));
        $dataset = (string) ($in['dataset'] ?? '');
        $cadence = (string) ($in['cadence'] ?? 'weekly');
        $email = trim((string) ($in['recipient_email'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) { return ['ok' => false, 'message' => 'A report name (1–150 chars) is required']; }
        if (!array_key_exists($dataset, bi_ext_report_datasets())) { return ['ok' => false, 'message' => 'Unknown report dataset']; }
        if (!in_array($cadence, ['daily', 'weekly', 'monthly'], true)) { return ['ok' => false, 'message' => 'Invalid cadence']; }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { return ['ok' => false, 'message' => 'Recipient email is not valid']; }
        try {
            $db->insert('supplier_report_schedules', [
                'org_id' => $orgId,
                'owner_user_id' => $owner,
                'name' => $name,
                'dataset' => $dataset,
                'cadence' => $cadence,
                'recipient_email' => $email !== '' ? $email : null,
                'delivery_status' => 'not_connected', // honest: no deliverer wired
                'active' => 1,
                'created_by' => (string) ($_SESSION['user_id'] ?? ''),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $id = (int) $db->id();
            return $id > 0 ? ['ok' => true, 'id' => $id] : ['ok' => false, 'message' => 'Could not save schedule'];
        } catch (\Throwable $e) { error_log('bi_ext_schedule_create: ' . $e->getMessage()); return ['ok' => false, 'message' => 'Save failed']; }
    }
}

if (!function_exists('bi_ext_schedule_delete')) {
    /** Delete a schedule RECORD, owner-scoped (the WHERE includes owner_user_id so a
     *  supplier can only remove their own). Returns ['ok'=>bool]. */
    function bi_ext_schedule_delete($db, string $owner, int $id): array
    {
        if ($id <= 0) { return ['ok' => false, 'message' => 'Bad id']; }
        bi_ext_schedules_ensure($db);
        try {
            $db->delete('supplier_report_schedules', ['AND' => ['id' => $id, 'owner_user_id' => $owner]]);
            return ['ok' => true];
        } catch (\Throwable $e) { error_log('bi_ext_schedule_delete: ' . $e->getMessage()); return ['ok' => false, 'message' => 'Delete failed']; }
    }
}

if (!function_exists('bi_ext_delivery_status')) {
    /**
     * HONEST integration status for scheduled-report DELIVERY. There is no cron/mailer
     * wired to supplier_report_schedules yet, so this always reports 'not_connected'
     * with a plain explanation the UI surfaces verbatim. Do NOT fake a live status.
     */
    function bi_ext_delivery_status(): array
    {
        return [
            'connected' => false,
            'status' => 'not_connected',
            'message' => 'Scheduled delivery is not connected. Schedules are saved, but reports '
                       . 'are not emailed automatically yet — run and export them manually from the '
                       . 'report builder. Automated delivery requires a cron + mailer binding (DB-gated).',
        ];
    }
}

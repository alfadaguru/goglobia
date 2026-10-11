<?php
// FILE: app/lib/supplier_gl_ext.php
// MODULE 34 (general ledger & financial accounting) EXTENSION — docs 01b §2.
//
// Builds the genuine GAPS on top of app/lib/supplier_ledger.php (which already
// owns gl_post / gl_trial_balance / gl_financial_summary / gl_period_is_open /
// fiscal_periods / cashier shifts / ar_subledger / tax_summary). We deliberately
// REUSE gl_post() for every money write — this file NEVER writes journal_entries
// or journal_lines directly, so the "an unbalanced entry can never exist" and the
// closed-period guarantees in the base poster hold for everything here too.
//
// New sub-features (all read-model derivations + one balanced poster):
//   - MANUAL JOURNAL ENTRY        glx_post_manual()        -> reuses gl_post()
//   - JOURNAL BROWSE / DRILL-DOWN glx_journal_list/entry()
//   - ACCOUNTING PERIODS CLOSE    glx_period_create/close/reopen/list()
//   - COST CENTRES                glx_cc_* (+ optional tag on a posted entry)
//   - CASH-FLOW STATEMENT         glx_cash_flow()
//   - BUDGET vs ACTUAL            glx_budget_* + glx_budget_vs_actual()
//   - AUDIT-TRAIL REPORT          glx_audit_report() (reuses audit_events)
//
// DB-GATED: this file assumes the two NEW additive tables (gl_cost_centers,
// gl_budget_lines) and the two NEW additive columns on journal_entries
// (cost_center_id, reversed_by) exist — see the ensure-fn below + the patch
// spec returned to the lead. Every table/column touch is try/caught & non-fatal.
// There is NO external/gateway edge in this module (books are internal), so
// nothing is stubbed — it is all real internal records.

if (!function_exists('glx_ensure_schema')) {
    /**
     * Idempotent self-healing schema for the GL extension (house pattern:
     * SHOW COLUMNS / SHOW TABLES then ALTER/CREATE, swallow failures). Safe to
     * call on every GL-ext page load. DB-GATED: a DB user without CREATE/ALTER
     * rights just degrades to read-only on the new sub-features.
     */
    function glx_ensure_schema($db): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            // cost centres (per-org label used to tag entries for departmental P&L)
            $db->query("CREATE TABLE IF NOT EXISTS `gl_cost_centers` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) NOT NULL,
                `code` varchar(20) NOT NULL,
                `name` varchar(120) NOT NULL,
                `active` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_org_cc_code` (`org_id`,`code`),
                KEY `idx_org` (`org_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // budget lines (per org / fiscal period / account / optional cost centre)
            $db->query("CREATE TABLE IF NOT EXISTS `gl_budget_lines` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) NOT NULL,
                `period_id` int(11) NOT NULL,
                `account_id` int(11) NOT NULL,
                `cost_center_id` int(11) DEFAULT NULL,
                `amount` decimal(16,2) NOT NULL DEFAULT 0.00,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_budget` (`org_id`,`period_id`,`account_id`,`cost_center_id`),
                KEY `idx_org_period` (`org_id`,`period_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // additive columns on journal_entries: cost-centre tag + reversal link
            $cols = [];
            foreach ($db->query("SHOW COLUMNS FROM `journal_entries`")->fetchAll() as $c) {
                $cols[(string) ($c['Field'] ?? '')] = true;
            }
            if (!isset($cols['cost_center_id'])) {
                $db->query("ALTER TABLE `journal_entries` ADD COLUMN `cost_center_id` int(11) DEFAULT NULL");
            }
            if (!isset($cols['reversed_by'])) {
                // id of the entry that reverses THIS one (set on the original when reversed)
                $db->query("ALTER TABLE `journal_entries` ADD COLUMN `reversed_by` bigint(20) DEFAULT NULL");
            }
        } catch (\Throwable $e) {
            error_log('glx_ensure_schema: ' . $e->getMessage());
        }
    }
}

// ---------------------------------------------------------------------------
// COST CENTRES
// ---------------------------------------------------------------------------
if (!function_exists('glx_cost_centers')) {
    /** All cost centres for an org (optionally active-only). Read-only. */
    function glx_cost_centers($db, int $orgId, bool $activeOnly = false): array
    {
        if ($orgId <= 0) { return []; }
        try {
            $where = ['org_id' => $orgId, 'ORDER' => ['code' => 'ASC']];
            if ($activeOnly) { $where['active'] = 1; }
            return $db->select('gl_cost_centers', ['id', 'code', 'name', 'active'], $where) ?: [];
        } catch (\Throwable $e) { error_log('glx_cost_centers: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('glx_cost_center_add')) {
    /** Create a cost centre. Idempotent on (org,code). Returns ['ok','id'|'message']. */
    function glx_cost_center_add($db, int $orgId, string $code, string $name): array
    {
        $code = strtoupper(trim(substr($code, 0, 20)));
        $name = trim(substr($name, 0, 120));
        if ($orgId <= 0 || $code === '' || $name === '') { return ['ok' => false, 'message' => 'Code and name required']; }
        if (!preg_match('/^[A-Z0-9\-]{1,20}$/', $code)) { return ['ok' => false, 'message' => 'Code must be letters/digits/dash']; }
        try {
            if ($db->has('gl_cost_centers', ['org_id' => $orgId, 'code' => $code])) {
                return ['ok' => false, 'message' => 'A cost centre with that code already exists'];
            }
            $db->insert('gl_cost_centers', [
                'org_id' => $orgId, 'code' => $code, 'name' => $name,
                'active' => 1, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return ['ok' => true, 'id' => (int) $db->id()];
        } catch (\Throwable $e) { error_log('glx_cost_center_add: ' . $e->getMessage()); return ['ok' => false, 'message' => 'Save error']; }
    }
}

if (!function_exists('glx_cost_center_set_active')) {
    /** Toggle a cost centre active flag (org-scoped). Returns bool. */
    function glx_cost_center_set_active($db, int $orgId, int $ccId, bool $active): bool
    {
        if ($orgId <= 0 || $ccId <= 0) { return false; }
        try {
            $db->update('gl_cost_centers', ['active' => $active ? 1 : 0], ['id' => $ccId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('glx_cost_center_set_active: ' . $e->getMessage()); return false; }
    }
}

// ---------------------------------------------------------------------------
// CHART-OF-ACCOUNTS helper for the manual-entry form (reuse base COA table).
// ---------------------------------------------------------------------------
if (!function_exists('glx_accounts')) {
    /** Active accounts for an org, as select options. Read-only. Auto-seeds the
     *  default COA if the org has none yet (reusing gl_seed_accounts). */
    function glx_accounts($db, int $orgId): array
    {
        if ($orgId <= 0) { return []; }
        try {
            if ((int) $db->count('chart_of_accounts', ['org_id' => $orgId]) === 0
                && function_exists('gl_seed_accounts')) {
                gl_seed_accounts($db, $orgId);
            }
            return $db->select('chart_of_accounts', ['id', 'code', 'name', 'type'],
                ['org_id' => $orgId, 'active' => 1, 'ORDER' => ['code' => 'ASC']]) ?: [];
        } catch (\Throwable $e) { error_log('glx_accounts: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// MANUAL JOURNAL ENTRY — reuses gl_post() (the only balanced poster).
// ---------------------------------------------------------------------------
if (!function_exists('glx_post_manual')) {
    /**
     * Post a MANUAL journal entry from UI-supplied lines. Delegates entirely to
     * gl_post() so the balance check, period-open check, and atomic write are the
     * SAME code path as automated postings. We never trust a client "total" — the
     * amount is derived by gl_post from the lines. The optional cost-centre tag is
     * stamped on the header AFTER a successful post (non-fatal).
     *
     *   $rawLines  [ ['account_id'=>, 'debit'=>, 'credit'=>], ... ] (from the form)
     *   $opts      ['date','memo','currency','property_id','cost_center_id']
     * Returns gl_post()'s result array.
     */
    function glx_post_manual($db, int $orgId, array $rawLines, array $opts = []): array
    {
        if (!function_exists('gl_post')) { return ['ok' => false, 'message' => 'GL poster unavailable']; }
        $lines = [];
        foreach ($rawLines as $ln) {
            $acct = (int) ($ln['account_id'] ?? 0);
            $debit = round((float) ($ln['debit'] ?? 0), 2);
            $credit = round((float) ($ln['credit'] ?? 0), 2);
            if ($acct <= 0) { continue; }
            if ($debit <= 0 && $credit <= 0) { continue; } // skip empty rows
            $lines[] = ['account_id' => $acct, 'debit' => $debit, 'credit' => $credit, 'memo' => (string) ($ln['memo'] ?? '')];
        }
        if (count($lines) < 2) { return ['ok' => false, 'message' => 'A journal entry needs at least two non-empty lines']; }

        // Guard: every account must belong to THIS org (gl_post resolves by id but
        // does not re-check org ownership when an account_id is passed directly).
        try {
            $ids = array_map(fn($l) => $l['account_id'], $lines);
            $owned = $db->select('chart_of_accounts', ['id'], ['org_id' => $orgId, 'id' => $ids, 'active' => 1]) ?: [];
            $ownedSet = [];
            foreach ($owned as $o) { $ownedSet[(int) $o['id']] = true; }
            foreach ($lines as $l) {
                if (empty($ownedSet[$l['account_id']])) { return ['ok' => false, 'message' => 'An account does not belong to your books']; }
            }
        } catch (\Throwable $e) { error_log('glx_post_manual acct check: ' . $e->getMessage()); return ['ok' => false, 'message' => 'Account check failed']; }

        $postOpts = [
            'date'     => $opts['date'] ?? date('Y-m-d'),
            'memo'     => (string) ($opts['memo'] ?? ''),
            'source'   => 'manual',
            'currency' => $opts['currency'] ?? null,
        ];
        if (!empty($opts['property_id'])) { $postOpts['property_id'] = (int) $opts['property_id']; }

        $res = gl_post($db, $orgId, $lines, $postOpts);

        // Stamp the cost-centre tag on the fresh header (non-fatal, additive column).
        if (!empty($res['ok']) && !empty($res['entry_id']) && empty($res['duplicate']) && !empty($opts['cost_center_id'])) {
            try {
                $cc = (int) $opts['cost_center_id'];
                if ($db->has('gl_cost_centers', ['id' => $cc, 'org_id' => $orgId])) {
                    $db->update('journal_entries', ['cost_center_id' => $cc], ['id' => (int) $res['entry_id'], 'org_id' => $orgId]);
                }
            } catch (\Throwable $e) { error_log('glx_post_manual cc tag: ' . $e->getMessage()); }
        }
        return $res;
    }
}

if (!function_exists('glx_reverse_entry')) {
    /**
     * Reverse a posted entry by posting its MIRROR (debit<->credit) via gl_post,
     * then linking the two. Honours closed periods (the reversal dates to today by
     * default, so a reversal into a closed period is refused by gl_post). Never
     * mutates the original's lines — immutability is preserved; reversal is a new,
     * balanced, offsetting entry. Returns gl_post()'s result.
     */
    function glx_reverse_entry($db, int $orgId, int $entryId, string $memo = ''): array
    {
        if (!function_exists('gl_post')) { return ['ok' => false, 'message' => 'GL poster unavailable']; }
        if ($orgId <= 0 || $entryId <= 0) { return ['ok' => false, 'message' => 'Bad entry']; }
        try {
            $hdr = $db->get('journal_entries', ['id', 'org_id', 'currency', 'property_id', 'reversed_by', 'memo'],
                ['id' => $entryId, 'org_id' => $orgId]);
            if (!$hdr) { return ['ok' => false, 'message' => 'Entry not found']; }
            if (!empty($hdr['reversed_by'])) { return ['ok' => false, 'message' => 'This entry is already reversed']; }
            $lines = $db->select('journal_lines', ['account_id', 'debit', 'credit', 'memo'], ['entry_id' => $entryId]) ?: [];
            if (count($lines) < 2) { return ['ok' => false, 'message' => 'Original entry has no lines']; }
            $mirror = [];
            foreach ($lines as $l) {
                // swap sides
                $mirror[] = [
                    'account_id' => (int) $l['account_id'],
                    'debit'      => round((float) $l['credit'], 2),
                    'credit'     => round((float) $l['debit'], 2),
                    'memo'       => (string) ($l['memo'] ?? ''),
                ];
            }
            $opts = [
                'date'     => date('Y-m-d'),
                'memo'     => $memo !== '' ? substr($memo, 0, 255) : ('Reversal of #' . $entryId),
                'source'   => 'reversal',
                'reference'=> 'rev-' . $entryId, // idempotent: one reversal per entry
                'currency' => $hdr['currency'] ?? null,
            ];
            if (!empty($hdr['property_id'])) { $opts['property_id'] = (int) $hdr['property_id']; }
            $res = gl_post($db, $orgId, $mirror, $opts);
            if (!empty($res['ok']) && !empty($res['entry_id'])) {
                $db->update('journal_entries', ['reversed_by' => (int) $res['entry_id']], ['id' => $entryId, 'org_id' => $orgId]);
            }
            return $res;
        } catch (\Throwable $e) { error_log('glx_reverse_entry: ' . $e->getMessage()); return ['ok' => false, 'message' => 'Reverse error']; }
    }
}

// ---------------------------------------------------------------------------
// JOURNAL BROWSE / DRILL-DOWN
// ---------------------------------------------------------------------------
if (!function_exists('glx_journal_list')) {
    /**
     * List journal entry HEADERS for an org with optional filters. Read-only.
     *   $filters ['from','to','source','cost_center_id']
     * Returns ['rows'=>[...], 'count'=>int].
     */
    function glx_journal_list($db, int $orgId, array $filters = [], int $limit = 200): array
    {
        $out = ['rows' => [], 'count' => 0];
        if ($orgId <= 0) { return $out; }
        try {
            $where = ['org_id' => $orgId];
            if (!empty($filters['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['from'])) { $where['entry_date[>=]'] = $filters['from']; }
            if (!empty($filters['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['to'])) { $where['entry_date[<=]'] = $filters['to']; }
            if (!empty($filters['source'])) { $where['source'] = substr((string) $filters['source'], 0, 40); }
            if (!empty($filters['cost_center_id'])) { $where['cost_center_id'] = (int) $filters['cost_center_id']; }
            $out['count'] = (int) $db->count('journal_entries', $where);
            $sel = $where + ['ORDER' => ['entry_date' => 'DESC', 'id' => 'DESC'], 'LIMIT' => max(1, min(1000, $limit))];
            $out['rows'] = $db->select('journal_entries',
                ['id', 'entry_date', 'memo', 'source', 'reference', 'currency', 'amount', 'cost_center_id', 'reversed_by', 'posted_by', 'created_at'],
                $sel) ?: [];
        } catch (\Throwable $e) { error_log('glx_journal_list: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('glx_journal_entry')) {
    /** One journal entry with its lines (account code/name joined). Read-only,
     *  org-scoped. Returns ['header'=>?array,'lines'=>[...]] or null if not owned. */
    function glx_journal_entry($db, int $orgId, int $entryId): ?array
    {
        if ($orgId <= 0 || $entryId <= 0) { return null; }
        try {
            $hdr = $db->get('journal_entries', '*', ['id' => $entryId, 'org_id' => $orgId]);
            if (!$hdr) { return null; }
            $lines = $db->select('journal_lines',
                ['[>]chart_of_accounts' => ['account_id' => 'id']],
                ['journal_lines.id', 'journal_lines.account_id', 'journal_lines.debit', 'journal_lines.credit', 'journal_lines.memo',
                 'chart_of_accounts.code(acct_code)', 'chart_of_accounts.name(acct_name)'],
                ['journal_lines.entry_id' => $entryId, 'ORDER' => ['journal_lines.id' => 'ASC']]) ?: [];
            return ['header' => $hdr, 'lines' => $lines];
        } catch (\Throwable $e) { error_log('glx_journal_entry: ' . $e->getMessage()); return null; }
    }
}

// ---------------------------------------------------------------------------
// ACCOUNTING PERIODS (fiscal_periods table + base gl_period_is_open). The base
// enforces closed periods on post; here we add the create/close/reopen lifecycle.
// ---------------------------------------------------------------------------
if (!function_exists('glx_periods')) {
    /** All fiscal periods for an org, newest first. Read-only. */
    function glx_periods($db, int $orgId): array
    {
        if ($orgId <= 0) { return []; }
        try {
            return $db->select('fiscal_periods', ['id', 'name', 'start_date', 'end_date', 'status', 'closed_at'],
                ['org_id' => $orgId, 'ORDER' => ['start_date' => 'DESC']]) ?: [];
        } catch (\Throwable $e) { error_log('glx_periods: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('glx_period_create')) {
    /**
     * Create a fiscal period. Refuses a range that OVERLAPS an existing period for
     * the org (so gl_period_is_open never sees two covering a date). Returns
     * ['ok','id'|'message'].
     */
    function glx_period_create($db, int $orgId, string $name, string $start, string $end): array
    {
        $name = trim(substr($name, 0, 60));
        if ($orgId <= 0 || $name === '') { return ['ok' => false, 'message' => 'Name required']; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            return ['ok' => false, 'message' => 'Valid start and end dates required'];
        }
        if ($start > $end) { return ['ok' => false, 'message' => 'Start must be on or before end']; }
        try {
            // overlap test: existing.start <= new.end AND existing.end >= new.start
            $clash = $db->has('fiscal_periods',
                ['org_id' => $orgId, 'start_date[<=]' => $end, 'end_date[>=]' => $start]);
            if ($clash) { return ['ok' => false, 'message' => 'That range overlaps an existing period']; }
            $db->insert('fiscal_periods', [
                'org_id' => $orgId, 'name' => $name,
                'start_date' => $start, 'end_date' => $end,
                'status' => 'open', 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return ['ok' => true, 'id' => (int) $db->id()];
        } catch (\Throwable $e) { error_log('glx_period_create: ' . $e->getMessage()); return ['ok' => false, 'message' => 'Save error']; }
    }
}

if (!function_exists('glx_period_close')) {
    /**
     * Close a fiscal period. The CLOSE is the control: once closed, gl_post() (base)
     * refuses any entry dated inside the range. Idempotent (closing a closed period
     * is a no-op success). Returns ['ok','message'].
     */
    function glx_period_close($db, int $orgId, int $periodId): array
    {
        if ($orgId <= 0 || $periodId <= 0) { return ['ok' => false, 'message' => 'Bad period']; }
        try {
            $p = $db->get('fiscal_periods', ['id', 'status'], ['id' => $periodId, 'org_id' => $orgId]);
            if (!$p) { return ['ok' => false, 'message' => 'Period not found']; }
            if ($p['status'] === 'closed') { return ['ok' => true, 'message' => 'Already closed']; }
            $db->update('fiscal_periods',
                ['status' => 'closed', 'closed_at' => date('Y-m-d H:i:s')],
                ['id' => $periodId, 'org_id' => $orgId, 'status' => 'open']);
            return ['ok' => true, 'message' => 'Period closed'];
        } catch (\Throwable $e) { error_log('glx_period_close: ' . $e->getMessage()); return ['ok' => false, 'message' => 'Close error']; }
    }
}

if (!function_exists('glx_period_reopen')) {
    /** Reopen a closed period (an owner control; audited by the caller). Returns array. */
    function glx_period_reopen($db, int $orgId, int $periodId): array
    {
        if ($orgId <= 0 || $periodId <= 0) { return ['ok' => false, 'message' => 'Bad period']; }
        try {
            $db->update('fiscal_periods', ['status' => 'open', 'closed_at' => null],
                ['id' => $periodId, 'org_id' => $orgId]);
            return ['ok' => true, 'message' => 'Period reopened'];
        } catch (\Throwable $e) { error_log('glx_period_reopen: ' . $e->getMessage()); return ['ok' => false, 'message' => 'Reopen error']; }
    }
}

// ---------------------------------------------------------------------------
// CASH-FLOW STATEMENT — derived from movements on the Cash/Bank account(s).
// ---------------------------------------------------------------------------
if (!function_exists('glx_cash_flow')) {
    /**
     * A SIMPLE (direct-method) cash-flow view over a date window: net change in the
     * cash/bank account(s), split by the OTHER leg's account TYPE of each entry that
     * touched cash. This is a derivation, not a separate ledger — the numbers reconcile
     * to the movement on account code 1000. Read-only.
     *
     * Classification of each cash-touching entry by the counter-leg type:
     *   revenue/expense           -> operating
     *   asset (non-cash)/liability-> we keep it simple: liability->financing,
     *                                asset->investing, equity->financing.
     * Returns ['opening','closing','net','operating','investing','financing','rows'=>[...]].
     */
    function glx_cash_flow($db, int $orgId, string $from, string $to): array
    {
        $z = ['opening' => 0.0, 'closing' => 0.0, 'net' => 0.0,
              'operating' => 0.0, 'investing' => 0.0, 'financing' => 0.0, 'rows' => []];
        if ($orgId <= 0) { return $z; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) { return $z; }
        try {
            // cash/bank accounts for the org (code 1000 by default; any asset named cash/bank)
            $cash = $db->select('chart_of_accounts', ['id'],
                ['org_id' => $orgId, 'OR' => ['code' => '1000', 'name[~]' => ['Cash', 'Bank']]]) ?: [];
            $cashIds = array_map(fn($r) => (int) $r['id'], $cash);
            if (empty($cashIds)) { return $z; }

            // opening = net cash movement strictly BEFORE $from (join-select, not cross-table sum)
            $opening = glx__cash_net($db, $orgId, $cashIds, null, $from, '<');
            $within  = glx__cash_entries($db, $orgId, $cashIds, $from, $to);

            $net = 0.0; $op = 0.0; $inv = 0.0; $fin = 0.0; $rows = [];
            foreach ($within as $e) {
                $delta = round((float) $e['cash_debit'] - (float) $e['cash_credit'], 2); // + inflow / - outflow
                $net += $delta;
                switch ($e['counter_type']) {
                    case 'revenue': case 'expense': $op += $delta; break;
                    case 'asset':   $inv += $delta; break;
                    case 'liability': case 'equity': $fin += $delta; break;
                    default: $op += $delta;
                }
                $rows[] = [
                    'entry_id' => (int) $e['entry_id'], 'date' => $e['entry_date'],
                    'memo' => (string) ($e['memo'] ?? ''), 'source' => (string) ($e['source'] ?? ''),
                    'delta' => $delta, 'class' => $e['counter_type'],
                ];
            }
            $z['opening'] = round($opening, 2);
            $z['net'] = round($net, 2);
            $z['closing'] = round($opening + $net, 2);
            $z['operating'] = round($op, 2);
            $z['investing'] = round($inv, 2);
            $z['financing'] = round($fin, 2);
            $z['rows'] = $rows;
        } catch (\Throwable $e) { error_log('glx_cash_flow: ' . $e->getMessage()); }
        return $z;
    }
}

if (!function_exists('glx__cash_net')) {
    /** Net cash movement (debit-credit) on $cashIds relative to a $date boundary.
     *  $op is '<' or '<=' etc. Internal helper for glx_cash_flow. */
    function glx__cash_net($db, int $orgId, array $cashIds, $unused, string $date, string $op): float
    {
        try {
            $rows = $db->select('journal_lines',
                ['[>]journal_entries' => ['entry_id' => 'id']],
                ['journal_lines.debit', 'journal_lines.credit'],
                ['journal_lines.org_id' => $orgId, 'journal_lines.account_id' => $cashIds,
                 'journal_entries.entry_date[' . $op . ']' => $date]) ?: [];
            $n = 0.0;
            foreach ($rows as $r) { $n += (float) $r['debit'] - (float) $r['credit']; }
            return $n;
        } catch (\Throwable $e) { error_log('glx__cash_net: ' . $e->getMessage()); return 0.0; }
    }
}

if (!function_exists('glx__cash_entries')) {
    /** Entries within [from,to] that touch cash, with the cash delta and the
     *  dominant counter-leg account TYPE. Internal helper for glx_cash_flow. */
    function glx__cash_entries($db, int $orgId, array $cashIds, string $from, string $to): array
    {
        $out = [];
        try {
            $hdrs = $db->select('journal_entries', ['id', 'entry_date', 'memo', 'source'],
                ['org_id' => $orgId, 'entry_date[>=]' => $from, 'entry_date[<=]' => $to,
                 'ORDER' => ['entry_date' => 'ASC', 'id' => 'ASC']]) ?: [];
            foreach ($hdrs as $h) {
                $eid = (int) $h['id'];
                $cashDebit = 0.0; $cashCredit = 0.0; $counterType = 'operating';
                $maxCounter = -1.0;
                $lines = $db->select('journal_lines',
                    ['[>]chart_of_accounts' => ['account_id' => 'id']],
                    ['journal_lines.account_id', 'journal_lines.debit', 'journal_lines.credit', 'chart_of_accounts.type'],
                    ['journal_lines.entry_id' => $eid]) ?: [];
                $touchesCash = false;
                foreach ($lines as $l) {
                    $aid = (int) $l['account_id'];
                    if (in_array($aid, $cashIds, true)) {
                        $cashDebit += (float) $l['debit']; $cashCredit += (float) $l['credit'];
                        $touchesCash = true;
                    } else {
                        $amt = (float) $l['debit'] + (float) $l['credit'];
                        if ($amt > $maxCounter) { $maxCounter = $amt; $counterType = (string) $l['type']; }
                    }
                }
                if (!$touchesCash) { continue; }
                $out[] = [
                    'entry_id' => $eid, 'entry_date' => $h['entry_date'], 'memo' => $h['memo'],
                    'source' => $h['source'], 'cash_debit' => round($cashDebit, 2),
                    'cash_credit' => round($cashCredit, 2), 'counter_type' => $counterType,
                ];
            }
        } catch (\Throwable $e) { error_log('glx__cash_entries: ' . $e->getMessage()); }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// BUDGET vs ACTUAL
// ---------------------------------------------------------------------------
if (!function_exists('glx_budget_set')) {
    /**
     * Upsert a budget line for (org, period, account[, cost_centre]). Amount is the
     * planned figure for that account in that period. Returns ['ok','message'].
     */
    function glx_budget_set($db, int $orgId, int $periodId, int $accountId, float $amount, ?int $costCenterId = null): array
    {
        if ($orgId <= 0 || $periodId <= 0 || $accountId <= 0) { return ['ok' => false, 'message' => 'Period and account required']; }
        try {
            // validate ownership of period + account
            if (!$db->has('fiscal_periods', ['id' => $periodId, 'org_id' => $orgId])) { return ['ok' => false, 'message' => 'Unknown period']; }
            if (!$db->has('chart_of_accounts', ['id' => $accountId, 'org_id' => $orgId])) { return ['ok' => false, 'message' => 'Unknown account']; }
            if ($costCenterId !== null && $costCenterId > 0 && !$db->has('gl_cost_centers', ['id' => $costCenterId, 'org_id' => $orgId])) {
                return ['ok' => false, 'message' => 'Unknown cost centre'];
            }
            $key = ['org_id' => $orgId, 'period_id' => $periodId, 'account_id' => $accountId,
                    'cost_center_id' => ($costCenterId && $costCenterId > 0) ? $costCenterId : null];
            $amount = round($amount, 2);
            if ($db->has('gl_budget_lines', $key)) {
                $db->update('gl_budget_lines', ['amount' => $amount, 'updated_at' => date('Y-m-d H:i:s')], $key);
            } else {
                $db->insert('gl_budget_lines', $key + ['amount' => $amount, 'created_at' => date('Y-m-d H:i:s')]);
            }
            return ['ok' => true, 'message' => 'Budget saved'];
        } catch (\Throwable $e) { error_log('glx_budget_set: ' . $e->getMessage()); return ['ok' => false, 'message' => 'Save error']; }
    }
}

if (!function_exists('glx_budget_vs_actual')) {
    /**
     * Budget-vs-actual for a period: per budgeted account, the planned amount vs the
     * ACTUAL movement in that account WITHIN the period's date range (from posted
     * journal lines). Variance = actual - budget (+ = over for expenses). Read-only.
     * Returns ['period'=>?, 'rows'=>[['code','name','type','budget','actual','variance']], 'totals'=>...].
     */
    function glx_budget_vs_actual($db, int $orgId, int $periodId): array
    {
        $out = ['period' => null, 'rows' => [], 'totals' => ['budget' => 0.0, 'actual' => 0.0, 'variance' => 0.0]];
        if ($orgId <= 0 || $periodId <= 0) { return $out; }
        try {
            $period = $db->get('fiscal_periods', ['id', 'name', 'start_date', 'end_date', 'status'],
                ['id' => $periodId, 'org_id' => $orgId]);
            if (!$period) { return $out; }
            $out['period'] = $period;
            $from = (string) $period['start_date']; $to = (string) $period['end_date'];

            $budgets = $db->select('gl_budget_lines', ['account_id', 'cost_center_id', 'amount'],
                ['org_id' => $orgId, 'period_id' => $periodId]) ?: [];
            // aggregate budgets by account (sum across cost centres for the account row)
            $byAcct = [];
            foreach ($budgets as $b) {
                $aid = (int) $b['account_id'];
                $byAcct[$aid] = ($byAcct[$aid] ?? 0.0) + (float) $b['amount'];
            }
            if (empty($byAcct)) { return $out; }

            $accts = $db->select('chart_of_accounts', ['id', 'code', 'name', 'type'],
                ['org_id' => $orgId, 'id' => array_keys($byAcct)]) ?: [];
            $meta = [];
            foreach ($accts as $a) { $meta[(int) $a['id']] = $a; }

            foreach ($byAcct as $aid => $budget) {
                $m = $meta[$aid] ?? null;
                if (!$m) { continue; }
                // actual = net movement in the account during the period, on the account's normal side
                $d = glx__acct_movement($db, $orgId, $aid, 'debit', $from, $to);
                $c = glx__acct_movement($db, $orgId, $aid, 'credit', $from, $to);
                $normal = function_exists('gl_account_normal_side') ? gl_account_normal_side((string) $m['type']) : 'debit';
                $actual = $normal === 'debit' ? round($d - $c, 2) : round($c - $d, 2);
                $variance = round($actual - $budget, 2);
                $out['rows'][] = [
                    'code' => (string) $m['code'], 'name' => (string) $m['name'], 'type' => (string) $m['type'],
                    'budget' => round((float) $budget, 2), 'actual' => $actual, 'variance' => $variance,
                ];
                $out['totals']['budget'] += (float) $budget;
                $out['totals']['actual'] += $actual;
                $out['totals']['variance'] += $variance;
            }
            usort($out['rows'], fn($a, $b) => strcmp($a['code'], $b['code']));
            foreach ($out['totals'] as $k => $v) { $out['totals'][$k] = round((float) $v, 2); }
        } catch (\Throwable $e) { error_log('glx_budget_vs_actual: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('glx__acct_movement')) {
    /** Sum of one side (debit|credit) on an account within [from,to]. Internal. */
    function glx__acct_movement($db, int $orgId, int $accountId, string $side, string $from, string $to): float
    {
        $side = $side === 'credit' ? 'credit' : 'debit';
        try {
            $rows = $db->select('journal_lines',
                ['[>]journal_entries' => ['entry_id' => 'id']],
                ['journal_lines.' . $side],
                ['journal_lines.org_id' => $orgId, 'journal_lines.account_id' => $accountId,
                 'journal_entries.entry_date[>=]' => $from, 'journal_entries.entry_date[<=]' => $to]) ?: [];
            $n = 0.0;
            foreach ($rows as $r) { $n += (float) $r[$side]; }
            return $n;
        } catch (\Throwable $e) { error_log('glx__acct_movement: ' . $e->getMessage()); return 0.0; }
    }
}

// ---------------------------------------------------------------------------
// AUDIT-TRAIL REPORT — reuses the existing audit_events table (audit_log writer).
// ---------------------------------------------------------------------------
if (!function_exists('glx_audit_report')) {
    /**
     * Org-scoped audit trail, optionally filtered by event prefix / date window.
     * Reuses audit_events (written by audit_log()). Read-only. We also surface
     * GL-relevant events by default (anything, but the UI highlights gl.* events).
     * Returns ['rows'=>[...]].
     */
    function glx_audit_report($db, int $orgId, array $filters = [], int $limit = 300): array
    {
        if ($orgId <= 0) { return ['rows' => []]; }
        try {
            $where = ['org_id' => $orgId];
            if (!empty($filters['event'])) { $where['event[~]'] = substr((string) $filters['event'], 0, 60); }
            if (!empty($filters['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['from'])) { $where['created_at[>=]'] = $filters['from'] . ' 00:00:00'; }
            if (!empty($filters['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['to'])) { $where['created_at[<=]'] = $filters['to'] . ' 23:59:59'; }
            $where['ORDER'] = ['id' => 'DESC'];
            $where['LIMIT'] = max(1, min(1000, $limit));
            $rows = $db->select('audit_events',
                ['id', 'actor_user_id', 'event', 'subject_type', 'subject_id', 'meta', 'ip', 'created_at'],
                $where) ?: [];
            return ['rows' => $rows];
        } catch (\Throwable $e) { error_log('glx_audit_report: ' . $e->getMessage()); return ['rows' => []]; }
    }
}

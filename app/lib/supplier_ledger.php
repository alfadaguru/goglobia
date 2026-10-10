<?php
// FILE: app/lib/supplier_ledger.php
// HOSPITALITY GENERAL LEDGER + cashier (Phase 1 inc S20; docs 01a §2/§3).
//
// This is the PROPERTY'S OWN double-entry books — a THIRD ledger, deliberately
// DISTINCT from:
//   - the customer wallet spine  (wallets / wallet_ledger / money_transactions), and
//   - the supplier payout spine   (supplier_earnings / supplier_payouts, S18/S19).
// 01a §2 is explicit: keep them bounded. Nothing here reads or writes those tables.
//
// Scope of S20 = the FOUNDATIONS, modelled correctly now, consumed later:
//   - chart_of_accounts  (per-org COA; seeded with a minimal hospitality set)
//   - journal_entries + journal_lines  (BALANCED + immutable once posted)
//   - fiscal_periods     (open/closed; a posting into a closed period is refused)
//   - cashier_shifts + cash_movements  (front-desk/POS cash, feeds night audit + GL)
//
// The one piece of real logic is gl_post(): a double-entry poster that REFUSES any
// unbalanced entry (sum(debit) !== sum(credit)) and writes atomically. No automatic
// posting from folios/bookings yet — that is the PMS folio work (Stage D). All
// functions are defensive / non-fatal and org-scoped.

if (!function_exists('gl_default_accounts')) {
    /** Minimal hospitality chart of accounts (code => [name,type]). Seeded per org.
     *  type ∈ asset|liability|equity|revenue|expense. Normal balance derives from type. */
    function gl_default_accounts(): array
    {
        return [
            '1000' => ['Cash / Bank',        'asset'],
            '1100' => ['Guest Ledger (AR)',  'asset'],
            '1200' => ['City Ledger (AR)',   'asset'],   // companies/agents
            '2000' => ['Accounts Payable',   'liability'],
            '2100' => ['Tax Payable',        'liability'],
            '2200' => ['Deposits / Advance', 'liability'],
            '3000' => ['Owner Equity',       'equity'],
            '4000' => ['Room Revenue',       'revenue'],
            '4100' => ['F&B Revenue',        'revenue'],
            '4900' => ['Other Revenue',      'revenue'],
            '5000' => ['Cost of Sales',      'expense'],
            '6000' => ['Operating Expense',  'expense'],
        ];
    }
}

if (!function_exists('gl_account_normal_side')) {
    /** The normal (increasing) side for an account type. */
    function gl_account_normal_side(string $type): string
    {
        return in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit';
    }
}

if (!function_exists('gl_seed_accounts')) {
    /** Ensure an org has the default COA. Idempotent (unique org+code). Returns count. */
    function gl_seed_accounts($db, int $orgId): int
    {
        if ($orgId <= 0) { return 0; }
        $n = 0;
        try {
            foreach (gl_default_accounts() as $code => $meta) {
                if ($db->has('chart_of_accounts', ['org_id' => $orgId, 'code' => $code])) { continue; }
                $db->insert('chart_of_accounts', [
                    'org_id'     => $orgId,
                    'code'       => $code,
                    'name'       => $meta[0],
                    'type'       => $meta[1],
                    'active'     => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $n++;
            }
        } catch (\Throwable $e) {
            error_log('gl_seed_accounts: ' . $e->getMessage());
        }
        return $n;
    }
}

if (!function_exists('gl_account_id')) {
    /** Resolve an org's account id by code (0 if missing). */
    function gl_account_id($db, int $orgId, string $code): int
    {
        try {
            $a = $db->get('chart_of_accounts', ['id'], ['org_id' => $orgId, 'code' => $code]);
            return $a ? (int) $a['id'] : 0;
        } catch (\Throwable $e) {
            error_log('gl_account_id: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('gl_period_is_open')) {
    /** True if the fiscal period covering $date (Y-m-d) is open (or none defined —
     *  no periods configured means posting is unrestricted). */
    function gl_period_is_open($db, int $orgId, string $date): bool
    {
        try {
            $any = (int) $db->count('fiscal_periods', ['org_id' => $orgId]);
            if ($any === 0) { return true; } // no periods defined → unrestricted
            $p = $db->get('fiscal_periods', ['status'],
                ['org_id' => $orgId, 'start_date[<=]' => $date, 'end_date[>=]' => $date]);
            if (!$p) { return true; } // date outside any defined period → allow
            return (string) $p['status'] === 'open';
        } catch (\Throwable $e) {
            error_log('gl_period_is_open: ' . $e->getMessage());
            return false; // fail-closed when uncertain
        }
    }
}

if (!function_exists('gl_post')) {
    /**
     * Post a BALANCED double-entry journal entry. THE core guarantee: the sum of
     * debit lines must equal the sum of credit lines (to the cent) or the entry is
     * REFUSED — an unbalanced entry can never be written. Atomic (header + lines in
     * one transaction). Entries are immutable once posted (no update path here).
     *
     *   $orgId    the org whose books this posts to
     *   $lines    [ ['code'=>'1000','debit'=>100.00] , ['code'=>'4000','credit'=>100.00] , ... ]
     *             (each line is debit XOR credit, positive)
     *   $opts     ['date'=>Y-m-d, 'memo'=>, 'source'=>'folio'|'pos'|'manual'|...,
     *              'reference'=>, 'property_id'=>, 'currency'=>]
     * Returns ['ok'=>bool,'entry_id'=>?int,'message'=>?].
     */
    function gl_post($db, int $orgId, array $lines, array $opts = []): array
    {
        if ($orgId <= 0) { return ['ok' => false, 'message' => 'No org']; }
        if (count($lines) < 2) { return ['ok' => false, 'message' => 'An entry needs at least two lines']; }

        $date = $opts['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) { $date = date('Y-m-d'); }
        if (!gl_period_is_open($db, $orgId, $date)) {
            return ['ok' => false, 'message' => 'The fiscal period for ' . $date . ' is closed'];
        }

        // Validate + normalise lines; resolve account ids; sum both sides.
        $totalDebit = 0.0; $totalCredit = 0.0;
        $norm = [];
        foreach ($lines as $ln) {
            $debit  = round((float) ($ln['debit'] ?? 0), 2);
            $credit = round((float) ($ln['credit'] ?? 0), 2);
            if ($debit < 0 || $credit < 0) { return ['ok' => false, 'message' => 'Negative amounts not allowed']; }
            if (($debit > 0) === ($credit > 0)) {
                // both zero, or both set → invalid (a line is debit XOR credit)
                return ['ok' => false, 'message' => 'Each line must be exactly one of debit or credit'];
            }
            $acctId = isset($ln['account_id']) ? (int) $ln['account_id'] : gl_account_id($db, $orgId, (string) ($ln['code'] ?? ''));
            if ($acctId <= 0) { return ['ok' => false, 'message' => 'Unknown account: ' . ($ln['code'] ?? '?')]; }
            $totalDebit  = round($totalDebit + $debit, 2);
            $totalCredit = round($totalCredit + $credit, 2);
            $norm[] = ['account_id' => $acctId, 'debit' => $debit, 'credit' => $credit, 'memo' => (string) ($ln['memo'] ?? '')];
        }

        // THE balance check — refuse anything that doesn't net to zero.
        if (abs($totalDebit - $totalCredit) > 0.001) {
            return ['ok' => false, 'message' => 'Entry is not balanced (debit ' . $totalDebit . ' != credit ' . $totalCredit . ')'];
        }
        if ($totalDebit <= 0) { return ['ok' => false, 'message' => 'Entry total must be positive']; }

        $source = isset($opts['source']) ? substr((string) $opts['source'], 0, 40) : 'manual';
        $reference = isset($opts['reference']) ? substr((string) $opts['reference'], 0, 100) : null;

        // The actual write — header + lines. Idempotent: when a (source, reference) is
        // given, a prior entry with the same key is treated as an already-posted no-op
        // (returns its id), so a double-click / retry can never double-post. The DB
        // UNIQUE(org_id, source, reference) backs this up if two writers race the check.
        $doPost = function ($db) use ($orgId, $opts, $date, $norm, $totalDebit, $source, $reference) {
            if ($reference !== null && $reference !== '') {
                $existing = $db->get('journal_entries', ['id'],
                    ['org_id' => $orgId, 'source' => $source, 'reference' => $reference]);
                if ($existing) {
                    return ['ok' => true, 'entry_id' => (int) $existing['id'], 'duplicate' => true];
                }
            }
            $db->insert('journal_entries', [
                'org_id'      => $orgId,
                'property_id' => isset($opts['property_id']) ? (int) $opts['property_id'] : null,
                'entry_date'  => $date,
                'memo'        => isset($opts['memo']) ? substr((string) $opts['memo'], 0, 255) : null,
                'source'      => $source,
                'reference'   => $reference,
                'currency'    => isset($opts['currency']) ? strtoupper(substr((string) $opts['currency'], 0, 3)) : null,
                'amount'      => $totalDebit,
                'posted_by'   => (string) ($_SESSION['user_id'] ?? ''),
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            $entryId = (int) $db->id();
            if ($entryId <= 0) { return ['ok' => false, 'message' => 'Header insert failed']; }
            foreach ($norm as $ln) {
                $db->insert('journal_lines', [
                    'entry_id'   => $entryId,
                    'org_id'     => $orgId,
                    'account_id' => $ln['account_id'],
                    'debit'      => $ln['debit'],
                    'credit'     => $ln['credit'],
                    'memo'       => $ln['memo'] !== '' ? substr($ln['memo'], 0, 255) : null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
            return ['ok' => true, 'entry_id' => $entryId];
        };

        // Nesting-aware: Medoo's action() calls beginTransaction() unconditionally and
        // has NO savepoint support, so opening a nested action inside a caller's
        // transaction throws "already an active transaction". When a transaction is
        // already open (e.g. folio_checkout / group completion locking a row FOR UPDATE
        // then posting), JOIN it — run the write directly so it commits/rolls back with
        // the caller. Otherwise wrap in our own action for atomicity.
        $result = ['ok' => false, 'message' => 'Post failed'];
        try {
            if ($db->pdo->inTransaction()) {
                $r = $doPost($db);
                if (empty($r['ok'])) { throw new \RuntimeException($r['message'] ?? 'Post failed'); }
                $result = $r;
            } else {
                $db->action(function ($db) use ($doPost, &$result) {
                    $r = $doPost($db);
                    $result = $r;
                    return !empty($r['ok']); // false → rollback
                });
            }
        } catch (\Throwable $e) {
            error_log('gl_post: ' . $e->getMessage());
            // Re-throw when inside a caller's transaction so THEIR action() rolls back
            // the whole unit (the status flip must not commit if the GL post failed).
            if ($db->pdo->inTransaction()) { throw $e; }
            return ['ok' => false, 'message' => 'Post error'];
        }
        return $result;
    }
}

if (!function_exists('gl_trial_balance')) {
    /**
     * Trial balance for an org: [code => ['name','type','debit','credit','balance']].
     * Read-only. A healthy ledger has sum(debit) === sum(credit) across all accounts.
     */
    function gl_trial_balance($db, int $orgId): array
    {
        $out = [];
        if ($orgId <= 0) { return $out; }
        try {
            $accts = $db->select('chart_of_accounts', ['id', 'code', 'name', 'type'],
                ['org_id' => $orgId, 'ORDER' => ['code' => 'ASC']]) ?: [];
            foreach ($accts as $a) {
                $aid = (int) $a['id'];
                $d = (float) $db->sum('journal_lines', 'debit',  ['org_id' => $orgId, 'account_id' => $aid]);
                $c = (float) $db->sum('journal_lines', 'credit', ['org_id' => $orgId, 'account_id' => $aid]);
                $normal = gl_account_normal_side((string) $a['type']);
                $balance = $normal === 'debit' ? round($d - $c, 2) : round($c - $d, 2);
                $out[(string) $a['code']] = [
                    'name' => $a['name'], 'type' => $a['type'],
                    'debit' => round($d, 2), 'credit' => round($c, 2), 'balance' => $balance,
                ];
            }
        } catch (\Throwable $e) {
            error_log('gl_trial_balance: ' . $e->getMessage());
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// CASHIER SHIFTS (01a §3) — front-desk / POS cash, feeds night audit + GL.
// ---------------------------------------------------------------------------
if (!function_exists('cashier_shift_open')) {
    /** Open a cashier shift. Returns shift id (0 on failure). One open shift per
     *  (org, user, station) is enforced by refusing a new open if one exists. */
    function cashier_shift_open($db, int $orgId, string $userId, string $station, float $openingFloat = 0.0): int
    {
        if ($orgId <= 0 || trim($userId) === '') { return 0; }
        try {
            if ($db->has('cashier_shifts', ['org_id' => $orgId, 'user_id' => $userId, 'station' => $station, 'status' => 'open'])) {
                return 0; // already an open shift for this station+user
            }
            $db->insert('cashier_shifts', [
                'org_id'        => $orgId,
                'user_id'       => $userId,
                'station'       => substr($station, 0, 60),
                'opening_float' => round($openingFloat, 2),
                'status'        => 'open',
                'opened_at'     => date('Y-m-d H:i:s'),
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) {
            error_log('cashier_shift_open: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('cash_movement_add')) {
    /** Record a cash movement against an open shift. type ∈ received|paid_out|refund|
     *  safe_drop. Non-fatal; returns bool. */
    function cash_movement_add($db, int $shiftId, string $type, float $amount, array $opts = []): bool
    {
        $type = in_array($type, ['received', 'paid_out', 'refund', 'safe_drop'], true) ? $type : '';
        if ($shiftId <= 0 || $type === '' || $amount <= 0) { return false; }
        try {
            $shift = $db->get('cashier_shifts', ['id', 'status', 'org_id'], ['id' => $shiftId]);
            if (!$shift || $shift['status'] !== 'open') { return false; }
            $db->insert('cash_movements', [
                'shift_id'   => $shiftId,
                'org_id'     => (int) $shift['org_id'],
                'type'       => $type,
                'amount'     => round($amount, 2),
                'reference'  => isset($opts['reference']) ? substr((string) $opts['reference'], 0, 100) : null,
                'memo'       => isset($opts['memo']) ? substr((string) $opts['memo'], 0, 255) : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('cash_movement_add: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('cashier_shift_close')) {
    /** Close a shift with a counted amount; stores expected vs actual + over/short.
     *  Expected = opening_float + received + refund_in? − paid_out − safe_drop.
     *  Returns ['ok'=>bool,'expected'=>,'actual'=>,'over_short'=>]. */
    function cashier_shift_close($db, int $shiftId, float $countedAmount): array
    {
        try {
            $shift = $db->get('cashier_shifts', '*', ['id' => $shiftId]);
            if (!$shift || $shift['status'] !== 'open') { return ['ok' => false, 'message' => 'No open shift']; }
            $received = (float) $db->sum('cash_movements', 'amount', ['shift_id' => $shiftId, 'type' => 'received']);
            $paidOut  = (float) $db->sum('cash_movements', 'amount', ['shift_id' => $shiftId, 'type' => 'paid_out']);
            $refund   = (float) $db->sum('cash_movements', 'amount', ['shift_id' => $shiftId, 'type' => 'refund']);
            $safeDrop = (float) $db->sum('cash_movements', 'amount', ['shift_id' => $shiftId, 'type' => 'safe_drop']);
            $expected = round((float) $shift['opening_float'] + $received - $paidOut - $refund - $safeDrop, 2);
            $actual   = round($countedAmount, 2);
            $overShort = round($actual - $expected, 2);
            $db->update('cashier_shifts', [
                'closing_counted' => $actual,
                'expected_amount' => $expected,
                'over_short'      => $overShort,
                'status'          => 'closed',
                'closed_at'       => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ], ['id' => $shiftId, 'status' => 'open']);
            return ['ok' => true, 'expected' => $expected, 'actual' => $actual, 'over_short' => $overShort];
        } catch (\Throwable $e) {
            error_log('cashier_shift_close: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Close error'];
        }
    }
}

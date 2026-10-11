<?php
// FILE: app/lib/supplier_treasury_ext.php
// CASH, BANK & TREASURY (PMS module #37; docs 01b §2 #37) — the INTERNAL register.
//
// This is the property's OWN cash/bank book, built on top of the hospitality GENERAL
// LEDGER (app/lib/supplier_ledger.php). It is deliberately the SAME third ledger as
// gl_post()/trial-balance — NOT the customer wallet spine and NOT supplier_earnings.
// Every money movement here posts a BALANCED double-entry via gl_post(), so the cash
// book can never drift from the GL: a treasury account's running balance is simply the
// GL balance of the COA account it maps to, cross-checked against its own movement log.
//
// What this adds over the bare GL:
//   - treasury_accounts   — a named registry of bank / cash / petty-cash accounts, each
//                           MAPPED to a GL chart-of-accounts asset code (default 1000).
//                           New accounts auto-create a dedicated COA sub-account so each
//                           bank/till has its own GL balance.
//   - treasury_movements  — the per-account register line (deposit / withdrawal /
//                           transfer / petty-expense / petty-topup / adjustment), each
//                           stamped with the gl entry_id it posted and a reconciled flag.
//   - cash transfers      — one balanced GL entry moving money between two treasury
//                           accounts (debit destination cash, credit source cash), both
//                           register lines + the GL post wrapped in $db->action().
//   - petty cash          — top-up (from a bank/cash account) + expense (to an expense
//                           account); both are register movements that post GL.
//   - reconciliation      — INTERNAL only: tick register lines as cleared and compare the
//                           cleared book balance to a declared statement balance. There is
//                           NO live bank feed — treasury_bank_import_status() is an honest
//                           'not connected' stub (DB-GATED).
//   - cash-position       — a read-only roll-up of every account's book balance by
//                           currency + kind, for the treasury dashboard and forecasting.
//
// Money rules (house style): NEVER trust a client amount beyond the posted figure; every
// write re-derives its GL lines server-side; multi-step writes use $db->action(); and a
// movement is only recorded if its GL post succeeded (fail-closed — no orphan register
// line without a balanced journal entry).

if (!function_exists('treasury_account_kinds')) {
    /** Supported treasury account kinds (value => label). Config, not DB. */
    function treasury_account_kinds(): array
    {
        return [
            'bank'       => 'Bank account',
            'cash'       => 'Cash / till',
            'petty_cash' => 'Petty cash',
        ];
    }
}

if (!function_exists('treasury_movement_types')) {
    /** Register movement types (value => [label, direction]). direction: +1 increases the
     *  account's cash, -1 decreases it. Transfers are recorded as a pair (one each side). */
    function treasury_movement_types(): array
    {
        return [
            'deposit'       => ['Deposit / receipt',   +1],
            'withdrawal'    => ['Withdrawal / payment', -1],
            'transfer_in'   => ['Transfer in',          +1],
            'transfer_out'  => ['Transfer out',         -1],
            'petty_topup'   => ['Petty-cash top-up',    +1],
            'petty_expense' => ['Petty-cash expense',   -1],
            'adjustment'    => ['Adjustment',            0], // signed amount carries direction
        ];
    }
}

if (!function_exists('treasury_next_coa_code')) {
    /** Allocate the next free dedicated cash/bank COA code for an org in the 1000-1099
     *  band (1000 is the shared default; per-account sub-accounts get 1001..1099).
     *  Returns a 4-char code string, or '1000' as a safe fallback. Read-only. */
    function treasury_next_coa_code($db, int $orgId): string
    {
        if ($orgId <= 0) { return '1000'; }
        try {
            $rows = $db->select('chart_of_accounts', ['code'],
                ['org_id' => $orgId, 'code[~]' => '10%']) ?: [];
            $used = [];
            foreach ($rows as $r) { $used[(string) $r['code']] = true; }
            for ($n = 1001; $n <= 1099; $n++) {
                if (empty($used[(string) $n])) { return (string) $n; }
            }
        } catch (\Throwable $e) {
            error_log('treasury_next_coa_code: ' . $e->getMessage());
        }
        return '1000';
    }
}

if (!function_exists('treasury_account_create')) {
    /**
     * Create a treasury (bank / cash / petty-cash) account for an org, auto-creating a
     * dedicated GL asset sub-account so the account has its own GL balance. Idempotent on
     * (org_id, name): a name collision returns the existing id. Returns
     * ['ok'=>bool,'id'=>?int,'coa_code'=>?,'message'=>?]. Non-fatal.
     *
     *   $opts  ['kind'=>bank|cash|petty_cash, 'currency'=>'USD',
     *           'bank_name'=>, 'account_number_last4'=>, 'opening_balance'=>float]
     * opening_balance, when positive, posts a balanced opening entry
     * (debit the new cash account, credit 3000 Owner Equity) so the book starts truthful.
     */
    function treasury_account_create($db, int $orgId, string $name, array $opts = []): array
    {
        $name = trim($name);
        if ($orgId <= 0) { return ['ok' => false, 'message' => 'No org']; }
        if ($name === '') { return ['ok' => false, 'message' => 'Account name is required']; }

        $kind = (string) ($opts['kind'] ?? 'bank');
        if (!array_key_exists($kind, treasury_account_kinds())) { $kind = 'bank'; }
        $currency = strtoupper(substr(trim((string) ($opts['currency'] ?? 'USD')), 0, 3)) ?: 'USD';
        $opening  = round((float) ($opts['opening_balance'] ?? 0), 2);
        if ($opening < 0) { $opening = 0.0; }

        try {
            // Idempotency: same name under the org → return existing.
            $exists = $db->get('treasury_accounts', ['id', 'coa_code'], ['org_id' => $orgId, 'name' => $name]);
            if ($exists) {
                return ['ok' => true, 'id' => (int) $exists['id'], 'coa_code' => (string) $exists['coa_code'], 'message' => 'Account already exists'];
            }

            // Ensure the org has its base COA before allocating a sub-account.
            if (function_exists('gl_seed_accounts')) { gl_seed_accounts($db, $orgId); }
            $coaCode = treasury_next_coa_code($db, $orgId);
            $coaName = substr($name, 0, 60);

            $result = ['ok' => false, 'message' => 'Create failed'];
            $db->action(function ($db) use ($orgId, $name, $kind, $currency, $opening, $coaCode, $coaName, $opts, &$result) {
                // Create the dedicated COA asset sub-account (unless we fell back to 1000).
                if ($coaCode !== '1000' && !$db->has('chart_of_accounts', ['org_id' => $orgId, 'code' => $coaCode])) {
                    $db->insert('chart_of_accounts', [
                        'org_id'     => $orgId,
                        'code'       => $coaCode,
                        'name'       => $coaName,
                        'type'       => 'asset',
                        'active'     => 1,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
                $db->insert('treasury_accounts', [
                    'org_id'               => $orgId,
                    'name'                 => substr($name, 0, 120),
                    'kind'                 => $kind,
                    'currency'             => $currency,
                    'coa_code'             => $coaCode,
                    'bank_name'            => isset($opts['bank_name']) ? substr((string) $opts['bank_name'], 0, 120) : null,
                    'account_number_last4' => isset($opts['account_number_last4']) ? substr(preg_replace('/\D/', '', (string) $opts['account_number_last4']), -4) : null,
                    'status'               => 'active',
                    'created_at'           => date('Y-m-d H:i:s'),
                ]);
                $acctId = (int) $db->id();
                if ($acctId <= 0) { $result = ['ok' => false, 'message' => 'Insert failed']; return false; }

                // Opening balance: debit the new cash account, credit Owner Equity (3000).
                if ($opening > 0 && function_exists('gl_post')) {
                    $post = gl_post($db, $orgId, [
                        ['code' => $coaCode, 'debit' => $opening],
                        ['code' => '3000',   'credit' => $opening],
                    ], [
                        'source'    => 'treasury',
                        'reference' => 'open-' . $acctId,
                        'memo'      => 'Opening balance: ' . $coaName,
                        'currency'  => $currency,
                    ]);
                    if (empty($post['ok'])) { $result = ['ok' => false, 'message' => $post['message'] ?? 'Opening entry failed']; return false; }
                    $db->insert('treasury_movements', [
                        'org_id'      => $orgId,
                        'account_id'  => $acctId,
                        'type'        => 'deposit',
                        'amount'      => $opening,
                        'currency'    => $currency,
                        'description' => 'Opening balance',
                        'gl_entry_id' => (int) ($post['entry_id'] ?? 0),
                        'reconciled'  => 0,
                        'created_by'  => (string) ($_SESSION['user_id'] ?? ''),
                        'created_at'  => date('Y-m-d H:i:s'),
                    ]);
                }
                $result = ['ok' => true, 'id' => $acctId, 'coa_code' => $coaCode];
                return true;
            });
            return $result;
        } catch (\Throwable $e) {
            error_log('treasury_account_create: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Create error'];
        }
    }
}

if (!function_exists('treasury_accounts_list')) {
    /** All treasury accounts for an org with a derived book balance (from the GL account
     *  the account maps to) + a reconciled (cleared) balance from the register. Read-only.
     *  Returns a list of
     *    ['id','name','kind','currency','coa_code','bank_name','account_number_last4',
     *     'status','book_balance','cleared_balance','uncleared'] . */
    function treasury_accounts_list($db, int $orgId): array
    {
        $out = [];
        if ($orgId <= 0) { return $out; }
        try {
            $accts = $db->select('treasury_accounts', '*',
                ['org_id' => $orgId, 'ORDER' => ['id' => 'ASC']]) ?: [];
            foreach ($accts as $a) {
                $acctId = (int) $a['id'];
                // Book balance = GL balance of the mapped asset account (asset: debit - credit).
                $book = 0.0;
                $coaId = function_exists('gl_account_id') ? gl_account_id($db, $orgId, (string) $a['coa_code']) : 0;
                if ($coaId > 0) {
                    $d = (float) $db->sum('journal_lines', 'debit',  ['org_id' => $orgId, 'account_id' => $coaId]);
                    $c = (float) $db->sum('journal_lines', 'credit', ['org_id' => $orgId, 'account_id' => $coaId]);
                    $book = round($d - $c, 2);
                }
                // Cleared balance = signed sum of reconciled register movements.
                $cleared = treasury_register_balance($db, $acctId, true);
                $out[] = [
                    'id'                   => $acctId,
                    'name'                 => (string) $a['name'],
                    'kind'                 => (string) $a['kind'],
                    'currency'             => (string) $a['currency'],
                    'coa_code'             => (string) $a['coa_code'],
                    'bank_name'            => (string) ($a['bank_name'] ?? ''),
                    'account_number_last4' => (string) ($a['account_number_last4'] ?? ''),
                    'status'               => (string) ($a['status'] ?? 'active'),
                    'book_balance'         => $book,
                    'cleared_balance'      => $cleared,
                    'uncleared'            => round($book - $cleared, 2),
                ];
            }
        } catch (\Throwable $e) { error_log('treasury_accounts_list: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('treasury_account_get')) {
    /** Fetch one treasury account scoped to an org (null if missing / not this org). */
    function treasury_account_get($db, int $orgId, int $accountId): ?array
    {
        if ($orgId <= 0 || $accountId <= 0) { return null; }
        try {
            $a = $db->get('treasury_accounts', '*', ['id' => $accountId, 'org_id' => $orgId]);
            return $a ?: null;
        } catch (\Throwable $e) { error_log('treasury_account_get: ' . $e->getMessage()); return null; }
    }
}

if (!function_exists('treasury_register_balance')) {
    /** Signed running balance of an account from its OWN register movements.
     *  $clearedOnly=true sums only reconciled lines (the cleared/statement balance).
     *  Deposits/in/topup add; withdrawals/out/expense subtract; adjustment uses its
     *  signed stored amount. Read-only. */
    function treasury_register_balance($db, int $accountId, bool $clearedOnly = false): float
    {
        if ($accountId <= 0) { return 0.0; }
        $bal = 0.0;
        try {
            $where = ['account_id' => $accountId];
            if ($clearedOnly) { $where['reconciled'] = 1; }
            $rows = $db->select('treasury_movements', ['type', 'amount'], $where) ?: [];
            $types = treasury_movement_types();
            foreach ($rows as $r) {
                $t = (string) $r['type'];
                $amt = (float) $r['amount'];
                if ($t === 'adjustment') { $bal += $amt; continue; } // signed
                $dir = isset($types[$t]) ? (int) $types[$t][1] : 0;
                $bal += $dir * abs($amt);
            }
        } catch (\Throwable $e) { error_log('treasury_register_balance: ' . $e->getMessage()); }
        return round($bal, 2);
    }
}

if (!function_exists('treasury_record_movement')) {
    /**
     * Record a single-account cash movement (deposit / withdrawal / petty-topup is NOT
     * here — use treasury_transfer / treasury_petty_topup for the two-sided ones). This
     * covers the one-sided GL postings:
     *   - 'deposit'       : money IN from a revenue/other source. Debit this cash acct,
     *                       credit $opts['counter_code'] (default 4900 Other Revenue).
     *   - 'withdrawal'    : money OUT to an expense/payable. Credit this cash acct,
     *                       debit  $opts['counter_code'] (default 6000 Operating Expense).
     *   - 'petty_expense' : an expense paid from a petty-cash account. Credit this cash
     *                       acct, debit 6000 (or $opts['counter_code']).
     * The amount is the server-validated positive figure; the GL is re-derived here, never
     * taken from the client. Both the GL post and the register line commit together via
     * $db->action() — no register line without a balanced entry. Returns
     * ['ok'=>bool,'movement_id'=>?,'entry_id'=>?,'message'=>?].
     */
    function treasury_record_movement($db, int $orgId, int $accountId, string $type, float $amount, array $opts = []): array
    {
        if ($orgId <= 0) { return ['ok' => false, 'message' => 'No org']; }
        $amount = round((float) $amount, 2);
        if ($amount <= 0) { return ['ok' => false, 'message' => 'Amount must be positive']; }
        if (!in_array($type, ['deposit', 'withdrawal', 'petty_expense'], true)) {
            return ['ok' => false, 'message' => 'Unsupported movement type'];
        }
        $acct = treasury_account_get($db, $orgId, $accountId);
        if (!$acct) { return ['ok' => false, 'message' => 'Account not found']; }
        $cashCode = (string) $acct['coa_code'];
        $currency = (string) $acct['currency'];

        // Counter account: a sane default per direction; a caller may override with a code
        // that must exist in the org COA (gl_post refuses an unknown code).
        $counter = isset($opts['counter_code']) && $opts['counter_code'] !== ''
            ? (string) $opts['counter_code']
            : ($type === 'deposit' ? '4900' : '6000');

        if ($type === 'deposit') {
            $lines = [['code' => $cashCode, 'debit' => $amount], ['code' => $counter, 'credit' => $amount]];
        } else { // withdrawal / petty_expense
            $lines = [['code' => $counter, 'debit' => $amount], ['code' => $cashCode, 'credit' => $amount]];
        }

        $desc = isset($opts['description']) ? substr((string) $opts['description'], 0, 255) : '';
        try {
            $result = ['ok' => false, 'message' => 'Post failed'];
            $db->action(function ($db) use ($orgId, $accountId, $type, $amount, $currency, $lines, $desc, &$result) {
                $post = gl_post($db, $orgId, $lines, [
                    'source'   => 'treasury',
                    'memo'     => $desc !== '' ? $desc : ucfirst(str_replace('_', ' ', $type)),
                    'currency' => $currency,
                ]);
                if (empty($post['ok'])) { $result = ['ok' => false, 'message' => $post['message'] ?? 'GL post failed']; return false; }
                $db->insert('treasury_movements', [
                    'org_id'      => $orgId,
                    'account_id'  => $accountId,
                    'type'        => $type,
                    'amount'      => $amount,
                    'currency'    => $currency,
                    'description' => $desc !== '' ? $desc : null,
                    'gl_entry_id' => (int) ($post['entry_id'] ?? 0),
                    'reconciled'  => 0,
                    'created_by'  => (string) ($_SESSION['user_id'] ?? ''),
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);
                $result = ['ok' => true, 'movement_id' => (int) $db->id(), 'entry_id' => (int) ($post['entry_id'] ?? 0)];
                return true;
            });
            return $result;
        } catch (\Throwable $e) {
            error_log('treasury_record_movement: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Movement error'];
        }
    }
}

if (!function_exists('treasury_transfer')) {
    /**
     * Move cash between two treasury accounts of the SAME org. Posts ONE balanced GL
     * entry (debit destination cash, credit source cash) and writes BOTH register lines
     * (transfer_out on source, transfer_in on dest) inside one $db->action(). The amount
     * is server-validated; the client never supplies GL lines. Refuses a transfer to the
     * same account, a cross-currency transfer (FX is a later feature), or an amount that
     * would overdraw the source book balance. Returns
     * ['ok'=>bool,'entry_id'=>?,'message'=>?].
     */
    function treasury_transfer($db, int $orgId, int $fromId, int $toId, float $amount, array $opts = []): array
    {
        if ($orgId <= 0) { return ['ok' => false, 'message' => 'No org']; }
        $amount = round((float) $amount, 2);
        if ($amount <= 0) { return ['ok' => false, 'message' => 'Amount must be positive']; }
        if ($fromId === $toId) { return ['ok' => false, 'message' => 'Source and destination must differ']; }

        $from = treasury_account_get($db, $orgId, $fromId);
        $to   = treasury_account_get($db, $orgId, $toId);
        if (!$from || !$to) { return ['ok' => false, 'message' => 'Account not found']; }
        if (strtoupper((string) $from['currency']) !== strtoupper((string) $to['currency'])) {
            return ['ok' => false, 'message' => 'Cross-currency transfers are not supported yet (both accounts must share a currency).'];
        }
        $currency = (string) $from['currency'];
        $fromCode = (string) $from['coa_code'];
        $toCode   = (string) $to['coa_code'];
        $desc = isset($opts['description']) ? substr((string) $opts['description'], 0, 255) : '';

        try {
            $result = ['ok' => false, 'message' => 'Transfer failed'];
            $db->action(function ($db) use ($orgId, $fromId, $toId, $fromCode, $toCode, $amount, $currency, $desc, $from, $to, &$result) {
                // Overdraft guard: re-derive the source GL book balance under the txn.
                $coaId = function_exists('gl_account_id') ? gl_account_id($db, $orgId, $fromCode) : 0;
                $srcBook = 0.0;
                if ($coaId > 0) {
                    $d = (float) $db->sum('journal_lines', 'debit',  ['org_id' => $orgId, 'account_id' => $coaId]);
                    $c = (float) $db->sum('journal_lines', 'credit', ['org_id' => $orgId, 'account_id' => $coaId]);
                    $srcBook = round($d - $c, 2);
                }
                if ($amount > $srcBook + 1e-9) {
                    $result = ['ok' => false, 'message' => 'Insufficient funds in ' . $from['name'] . ' (book balance ' . number_format($srcBook, 2) . ')'];
                    return false;
                }
                $memo = $desc !== '' ? $desc : ('Transfer ' . $from['name'] . ' → ' . $to['name']);
                $post = gl_post($db, $orgId, [
                    ['code' => $toCode,   'debit'  => $amount],
                    ['code' => $fromCode, 'credit' => $amount],
                ], ['source' => 'treasury', 'memo' => $memo, 'currency' => $currency]);
                if (empty($post['ok'])) { $result = ['ok' => false, 'message' => $post['message'] ?? 'GL post failed']; return false; }
                $entryId = (int) ($post['entry_id'] ?? 0);
                $now = date('Y-m-d H:i:s'); $actor = (string) ($_SESSION['user_id'] ?? '');
                $db->insert('treasury_movements', [
                    'org_id' => $orgId, 'account_id' => $fromId, 'type' => 'transfer_out', 'amount' => $amount,
                    'currency' => $currency, 'description' => $memo, 'gl_entry_id' => $entryId,
                    'counter_account_id' => $toId, 'reconciled' => 0, 'created_by' => $actor, 'created_at' => $now,
                ]);
                $db->insert('treasury_movements', [
                    'org_id' => $orgId, 'account_id' => $toId, 'type' => 'transfer_in', 'amount' => $amount,
                    'currency' => $currency, 'description' => $memo, 'gl_entry_id' => $entryId,
                    'counter_account_id' => $fromId, 'reconciled' => 0, 'created_by' => $actor, 'created_at' => $now,
                ]);
                $result = ['ok' => true, 'entry_id' => $entryId];
                return true;
            });
            return $result;
        } catch (\Throwable $e) {
            error_log('treasury_transfer: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Transfer error'];
        }
    }
}

if (!function_exists('treasury_petty_topup')) {
    /** Top up a petty-cash account FROM a bank/cash account. This is just a cash transfer
     *  (money moves between two real cash accounts), so it reuses treasury_transfer — one
     *  balanced GL entry, overdraft-guarded, both register lines. Validates that the
     *  destination is a petty_cash account. Returns treasury_transfer's result. */
    function treasury_petty_topup($db, int $orgId, int $fromAccountId, int $pettyAccountId, float $amount, array $opts = []): array
    {
        $petty = treasury_account_get($db, $orgId, $pettyAccountId);
        if (!$petty) { return ['ok' => false, 'message' => 'Petty-cash account not found']; }
        if ((string) $petty['kind'] !== 'petty_cash') { return ['ok' => false, 'message' => 'Destination is not a petty-cash account']; }
        $opts['description'] = isset($opts['description']) && $opts['description'] !== ''
            ? (string) $opts['description'] : ('Petty-cash top-up: ' . $petty['name']);
        return treasury_transfer($db, $orgId, $fromAccountId, $pettyAccountId, $amount, $opts);
    }
}

if (!function_exists('treasury_movements_list')) {
    /** Register movements for an account (newest first), joined to the GL entry memo.
     *  Read-only; org-scoped via the account. Returns a bounded list. */
    function treasury_movements_list($db, int $orgId, int $accountId, int $limit = 200): array
    {
        $out = [];
        if ($orgId <= 0 || $accountId <= 0) { return $out; }
        try {
            // Scope-check: account must belong to the org.
            if (!$db->has('treasury_accounts', ['id' => $accountId, 'org_id' => $orgId])) { return $out; }
            $out = $db->select('treasury_movements', '*',
                ['account_id' => $accountId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, min(1000, $limit))]) ?: [];
        } catch (\Throwable $e) { error_log('treasury_movements_list: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('treasury_reconcile_toggle')) {
    /** Tick / untick a register movement as cleared against the bank/cash statement
     *  (INTERNAL reconciliation — no external feed). Org-scoped + account-scoped so a
     *  movement can only be toggled within its own org. Returns bool. */
    function treasury_reconcile_toggle($db, int $orgId, int $movementId, bool $cleared): bool
    {
        if ($orgId <= 0 || $movementId <= 0) { return false; }
        try {
            $m = $db->get('treasury_movements', ['id', 'org_id'], ['id' => $movementId, 'org_id' => $orgId]);
            if (!$m) { return false; }
            $db->update('treasury_movements',
                ['reconciled' => $cleared ? 1 : 0, 'reconciled_at' => $cleared ? date('Y-m-d H:i:s') : null],
                ['id' => $movementId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('treasury_reconcile_toggle: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('treasury_reconciliation_summary')) {
    /** Reconciliation view for one account: cleared (book-per-register) balance vs a
     *  declared statement balance, and the difference that still needs explaining.
     *  Read-only. Returns
     *    ['book'=>float,'cleared'=>float,'uncleared'=>float,'statement'=>float,
     *     'difference'=>float,'reconciled'=>bool]. */
    function treasury_reconciliation_summary($db, int $orgId, int $accountId, ?float $statementBalance = null): array
    {
        $out = ['book' => 0.0, 'cleared' => 0.0, 'uncleared' => 0.0, 'statement' => 0.0, 'difference' => 0.0, 'reconciled' => false];
        $acct = treasury_account_get($db, $orgId, $accountId);
        if (!$acct) { return $out; }
        $coaId = function_exists('gl_account_id') ? gl_account_id($db, $orgId, (string) $acct['coa_code']) : 0;
        $book = 0.0;
        if ($coaId > 0) {
            try {
                $d = (float) $db->sum('journal_lines', 'debit',  ['org_id' => $orgId, 'account_id' => $coaId]);
                $c = (float) $db->sum('journal_lines', 'credit', ['org_id' => $orgId, 'account_id' => $coaId]);
                $book = round($d - $c, 2);
            } catch (\Throwable $e) { error_log('treasury_reconciliation_summary: ' . $e->getMessage()); }
        }
        $cleared = treasury_register_balance($db, $accountId, true);
        $stmt = $statementBalance !== null ? round((float) $statementBalance, 2) : $cleared;
        $out['book']       = $book;
        $out['cleared']    = $cleared;
        $out['uncleared']  = round($book - $cleared, 2);
        $out['statement']  = $stmt;
        $out['difference'] = round($stmt - $cleared, 2);
        $out['reconciled'] = abs($out['difference']) < 0.01;
        return $out;
    }
}

if (!function_exists('treasury_cash_position')) {
    /**
     * Cash-position report (PMS #37 'cash-position reporting'): every account's book
     * balance, rolled up by currency and by kind. Read-only, derived entirely from the GL
     * via treasury_accounts_list — the single source of truth. Returns
     *   ['accounts'=>[...], 'by_currency'=>[cur=>total], 'by_kind'=>[kind=>[cur=>total]],
     *    'total_cleared'=>[cur=>total], 'as_of'=>datetime].
     */
    function treasury_cash_position($db, int $orgId): array
    {
        $out = ['accounts' => [], 'by_currency' => [], 'by_kind' => [], 'total_cleared' => [], 'as_of' => date('Y-m-d H:i:s')];
        if ($orgId <= 0) { return $out; }
        $accts = treasury_accounts_list($db, $orgId);
        $out['accounts'] = $accts;
        foreach ($accts as $a) {
            $cur = (string) $a['currency']; $kind = (string) $a['kind'];
            $out['by_currency'][$cur] = round(($out['by_currency'][$cur] ?? 0) + (float) $a['book_balance'], 2);
            if (!isset($out['by_kind'][$kind])) { $out['by_kind'][$kind] = []; }
            $out['by_kind'][$kind][$cur] = round(($out['by_kind'][$kind][$cur] ?? 0) + (float) $a['book_balance'], 2);
            $out['total_cleared'][$cur] = round(($out['total_cleared'][$cur] ?? 0) + (float) $a['cleared_balance'], 2);
        }
        return $out;
    }
}

if (!function_exists('treasury_cash_forecast')) {
    /**
     * A SIMPLE, HONEST cash-flow forecast (PMS #37 'cash-flow forecasts'). We do NOT invent
     * predictive magic: the forecast = today's cash position, plus known AR (money due in
     * from open guest folios, via ar_subledger) minus known AP (money due out to vendors,
     * via ap_summary) when those helpers are available. Each driver is labelled so the view
     * can show WHERE the number comes from. Read-only. Returns
     *   ['opening'=>[cur=>bal], 'ar_in'=>float, 'ap_out'=>float, 'projected'=>[cur=>bal],
     *    'drivers'=>[['label','direction','amount']...], 'note'=>string].
     * NOTE: AR/AP are summed into the org's primary currency bucket without FX conversion —
     * labelled as an approximation, not a multi-currency projection.
     */
    function treasury_cash_forecast($db, int $orgId): array
    {
        $pos = treasury_cash_position($db, $orgId);
        $opening = $pos['by_currency'] ?? [];
        $primary = array_key_first($opening) ?: 'USD';

        $arIn = 0.0; $apOut = 0.0; $drivers = [];
        if (function_exists('ar_subledger')) {
            $ar = ar_subledger($db, $orgId);
            $arIn = round((float) ($ar['total'] ?? 0), 2);
            if ($arIn > 0) { $drivers[] = ['label' => 'Open guest folios (AR due in)', 'direction' => 'in', 'amount' => $arIn]; }
        }
        if (function_exists('ap_summary')) {
            $ap = ap_summary($db, $orgId);
            $apOut = round((float) ($ap['total'] ?? 0), 2);
            if ($apOut > 0) { $drivers[] = ['label' => 'Received purchase orders (AP due out)', 'direction' => 'out', 'amount' => $apOut]; }
        }
        $projected = $opening;
        $projected[$primary] = round(($opening[$primary] ?? 0) + $arIn - $apOut, 2);

        return [
            'opening'   => $opening,
            'ar_in'     => $arIn,
            'ap_out'    => $apOut,
            'projected' => $projected,
            'drivers'   => $drivers,
            'note'      => 'Projection = current cash + open AR − open AP. AR/AP are applied to the primary currency ('
                . htmlspecialchars($primary) . ') without FX conversion; treat cross-currency figures as approximate.',
        ];
    }
}

if (!function_exists('treasury_bank_import_status')) {
    /**
     * HONEST STUB — bank-transaction import / live bank feed (PMS #37 'bank-transaction
     * imports'). There is NO live bank-feed/aggregator integration wired in this build.
     * This reports 'not connected' so the UI can state the truth instead of faking a feed.
     * A real integration (Plaid / Mono / Okra / Nordigen / a bank API) is DB-GATED: it
     * needs credentials in settings + a per-account external id, which this install does
     * not carry. When built, it would create treasury_movements with reconciled=0 for
     * operators to match — never auto-posting GL without review. Returns
     *   ['connected'=>false,'provider'=>null,'message'=>...,'manual_entry'=>true].
     */
    function treasury_bank_import_status($db, int $orgId): array
    {
        return [
            'connected'    => false,
            'provider'     => null,
            'message'      => 'No bank feed is connected. This build keeps an internal register only — enter bank and cash movements manually, then reconcile against your statement. A live bank-feed import (Plaid/Mono/Okra/Nordigen) is not configured.',
            'manual_entry' => true,
        ];
    }
}

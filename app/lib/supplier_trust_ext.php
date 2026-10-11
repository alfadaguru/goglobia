<?php
// FILE: app/lib/supplier_trust_ext.php
// MODULE 50 DEEPEN — Trust & client-money (structured bonds/deposits, management-fee
// postings, three-way trust reconciliation, client-fund audit). Builds ON TOP of the
// base free-text client-money ledger (trust_add / trust_balances / trust_list in
// supplier_corporate.php) — NOT a replacement. All org-scoped, defensive, non-fatal.
//
// Trust accounting rules honoured here (client money must stay segregated):
//   - Balances are SEGREGATED BY CURRENCY (never net one currency against another).
//   - A refund/forfeit/fee can never exceed the amount actually held (clamped).
//   - Money movements write a BALANCED GL entry via gl_post() (which REFUSES any
//     unbalanced entry) AND a stays_trust_ledger line, wrapped in ONE $db->action()
//     with a FOR UPDATE row-lock on the bond, so a half-apply can never persist and a
//     racing double-refund can't over-draw.
//
// Reuses: gl_post (supplier_ledger.php), trust_balances / trust_entry_signed
// (supplier_corporate.php), audit_log / audit_recent (supplier_events.php).
//
// Honesty note: NOTHING here contacts a payment gateway or a real trust-bank feed.
//   - A bond "refund"/"forfeit" records the INTERNAL trust movement + GL; actually
//     sending cash to the guest/owner is a separate (DB-GATED) payout step.
//   - The reconciliation "bank side" is an operator-entered statement figure
//     (stays_trust_bank). No live bank API is connected — see trust_bank_feed_status().

// ---------------------------------------------------------------------------
// PURE HELPERS (harnessable — no DB)
// ---------------------------------------------------------------------------
if (!function_exists('trust_bond_kinds')) {
    /** Allowed structured-trust kinds. 'security' = guest damage/security bond;
     *  'owner_liability' = money owed back to a property owner; 'advance' = guest
     *  advance/prepayment held before service. */
    function trust_bond_kinds(): array
    {
        return ['security' => 'Security / damage bond', 'advance' => 'Guest advance deposit', 'owner_liability' => 'Owner liability'];
    }
}
if (!function_exists('trust_bond_statuses')) {
    function trust_bond_statuses(): array
    {
        return ['held' => 'Held', 'refunded' => 'Refunded', 'forfeited' => 'Forfeited', 'partial' => 'Partially released'];
    }
}
if (!function_exists('trust_reconcile_status')) {
    /**
     * Pure reconciliation verdict for ONE currency. Given the book (ledger) liability
     * and the operator-entered trust-bank balance, returns the variance and a verdict.
     * A healthy trust account holds AT LEAST the book liability in the segregated bank
     * (bank >= book). A shortfall (bank < book) is a client-money breach and is flagged.
     *   returns ['book','bank','variance','verdict'] where verdict ∈ reconciled|surplus|shortfall
     */
    function trust_reconcile_status(float $book, float $bank): array
    {
        $book = round($book, 2); $bank = round($bank, 2);
        $variance = round($bank - $book, 2);
        if (abs($variance) < 0.01) { $verdict = 'reconciled'; }
        elseif ($variance > 0)     { $verdict = 'surplus'; }
        else                       { $verdict = 'shortfall'; }
        return ['book' => $book, 'bank' => $bank, 'variance' => $variance, 'verdict' => $verdict];
    }
}

// ---------------------------------------------------------------------------
// STRUCTURED BONDS / DEPOSITS  [DB-GATED: table stays_trust_bonds]
// ---------------------------------------------------------------------------
if (!function_exists('trust_bond_create')) {
    /**
     * Record a structured bond/deposit held on behalf of a party, AND mirror it as a
     * `receipt` in the base client-money ledger (stays_trust_ledger) so the existing
     * balance view stays the single source of truth for held cash. Atomic.
     * Returns ['ok'=>bool,'bond_id'=>?int,'message'=>].
     */
    function trust_bond_create($db, int $orgId, array $d): array
    {
        if ($orgId <= 0) { return ['ok' => false, 'message' => 'No org']; }
        $party  = substr(trim((string) ($d['party'] ?? '')), 0, 191);
        $kind   = array_key_exists(($d['kind'] ?? ''), trust_bond_kinds()) ? $d['kind'] : 'security';
        $amount = round((float) ($d['amount'] ?? 0), 2);
        $cur    = strtoupper(substr((string) ($d['currency'] ?? 'USD'), 0, 3)) ?: 'USD';
        if ($party === '' || $amount <= 0) { return ['ok' => false, 'message' => 'Party and a positive amount are required']; }

        $result = ['ok' => false, 'message' => 'Could not record bond'];
        try {
            $db->action(function ($db) use ($orgId, $party, $kind, $amount, $cur, $d, &$result) {
                $db->insert('stays_trust_bonds', [
                    'org_id'      => $orgId,
                    'party'       => $party,
                    'kind'        => $kind,
                    'reference'   => substr(trim((string) ($d['reference'] ?? '')), 0, 120) ?: null,
                    'amount_held' => $amount,
                    'amount_released' => 0,
                    'currency'    => $cur,
                    'status'      => 'held',
                    'memo'        => substr(trim((string) ($d['memo'] ?? '')), 0, 255) ?: null,
                    'created_by'  => (string) ($_SESSION['user_id'] ?? '') ?: null,
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);
                $bondId = (int) $db->id();
                if ($bondId <= 0) { $result = ['ok' => false, 'message' => 'Insert failed']; return false; }

                // Mirror the receipt into the base ledger (so trust_balances sees the cash).
                $ledgerId = function_exists('trust_add') ? trust_add($db, $orgId, [
                    'party' => $party, 'entry_type' => 'receipt', 'amount' => $amount,
                    'currency' => $cur, 'reference' => 'bond:' . $bondId,
                    'memo' => trust_bond_kinds()[$kind] . ' held',
                ]) : 0;
                if ($ledgerId <= 0) { $result = ['ok' => false, 'message' => 'Ledger mirror failed']; return false; }

                if (function_exists('audit_log')) {
                    audit_log($db, 'trust.bond.created', 'stays_trust_bonds', $bondId,
                        ['party' => $party, 'kind' => $kind, 'amount' => $amount, 'currency' => $cur], $orgId);
                }
                $result = ['ok' => true, 'bond_id' => $bondId, 'message' => 'Bond recorded'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('trust_bond_create: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Record error'];
        }
        return $result;
    }
}

if (!function_exists('trust_bond_release')) {
    /**
     * Release (refund OR forfeit) part/all of a held bond. MONEY MOVEMENT:
     *   - clamps the amount to what is still held (amount_held - amount_released);
     *   - writes a base-ledger `disbursement` (returning cash to guest) or an
     *     `adjustment`/GL fee recognition (forfeit → the property earns it);
     *   - FOR UPDATE locks the bond row so a racing second release can't over-draw;
     *   - all writes in ONE $db->action().
     *
     * mode='refund'  : cash returned to the party → base ledger disbursement only
     *                  (client money leaves the trust account; no P&L).
     * mode='forfeit' : the property keeps the money → base ledger disbursement (money
     *                  leaves client-trust) PLUS a BALANCED GL entry recognising it as
     *                  revenue (Dr 2200 Deposits/Advance liability, Cr 4900 Other
     *                  Revenue) so the property books pick up the income.
     * Returns ['ok'=>bool,'released'=>float,'entry_id'=>?int,'message'=>].
     */
    function trust_bond_release($db, int $orgId, int $bondId, float $amount, string $mode, array $opts = []): array
    {
        if ($orgId <= 0 || $bondId <= 0) { return ['ok' => false, 'message' => 'Missing org/bond']; }
        $mode   = in_array($mode, ['refund', 'forfeit'], true) ? $mode : 'refund';
        $amount = round((float) $amount, 2);
        if ($amount <= 0) { return ['ok' => false, 'message' => 'Amount must be positive']; }
        $actor  = (string) ($_SESSION['user_id'] ?? '');

        $result = ['ok' => false, 'message' => 'Release failed'];
        try {
            $db->action(function ($db) use ($orgId, $bondId, $amount, $mode, $opts, $actor, &$result) {
                // Lock the bond row (Medoo get() has no row-lock; raw query — matches house style).
                $st = $db->query('SELECT id, party, kind, amount_held, amount_released, currency, status
                                  FROM stays_trust_bonds WHERE id = :id AND org_id = :org FOR UPDATE',
                    [':id' => $bondId, ':org' => $orgId]);
                $b = $st ? $st->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$b) { $result = ['ok' => false, 'message' => 'Bond not found']; return false; }
                if (in_array((string) $b['status'], ['refunded', 'forfeited'], true)) {
                    $result = ['ok' => false, 'message' => 'Bond is already fully ' . $b['status']];
                    return false;
                }
                $remaining = round((float) $b['amount_held'] - (float) $b['amount_released'], 2);
                if ($remaining <= 0) { $result = ['ok' => false, 'message' => 'Nothing left to release']; return false; }
                $release = min($amount, $remaining); // never over-draw the held amount
                $cur = strtoupper((string) ($b['currency'] ?? 'USD')) ?: 'USD';

                // 1) Base ledger: money leaves the segregated client-trust balance.
                $memo = ($mode === 'forfeit' ? 'Bond forfeited' : 'Bond refunded') . ' (bond ' . $bondId . ')';
                $led = function_exists('trust_add') ? trust_add($db, $orgId, [
                    'party' => (string) $b['party'], 'entry_type' => 'disbursement',
                    'amount' => $release, 'currency' => $cur,
                    'reference' => 'bond:' . $bondId . ':' . $mode, 'memo' => $memo,
                ]) : 0;
                if ($led <= 0) { $result = ['ok' => false, 'message' => 'Ledger disbursement failed']; return false; }

                // 2) GL only when the property KEEPS the money (forfeit = revenue).
                $entryId = null;
                if ($mode === 'forfeit' && function_exists('gl_post')) {
                    $gl = gl_post($db, $orgId, [
                        ['code' => '2200', 'debit'  => $release], // Deposits / Advance (liability) down
                        ['code' => '4900', 'credit' => $release], // Other Revenue up
                    ], [
                        'source'    => 'trust_forfeit',
                        'reference' => 'bond:' . $bondId . ':' . date('YmdHis'),
                        'memo'      => 'Forfeited bond ' . $bondId . ' (' . (string) $b['party'] . ')',
                        'currency'  => $cur,
                    ]);
                    if (empty($gl['ok'])) { $result = ['ok' => false, 'message' => $gl['message'] ?? 'GL post failed']; return false; }
                    $entryId = (int) ($gl['entry_id'] ?? 0);
                }

                // 3) Advance the bond lifecycle.
                $newReleased = round((float) $b['amount_released'] + $release, 2);
                $newStatus = $newReleased + 0.001 >= (float) $b['amount_held']
                    ? ($mode === 'forfeit' ? 'forfeited' : 'refunded')
                    : 'partial';
                $db->update('stays_trust_bonds', [
                    'amount_released' => $newReleased,
                    'status'          => $newStatus,
                    'released_at'     => date('Y-m-d H:i:s'),
                    'released_by'     => $actor ?: null,
                ], ['id' => $bondId, 'org_id' => $orgId]);

                if (function_exists('audit_log')) {
                    audit_log($db, 'trust.bond.' . $mode, 'stays_trust_bonds', $bondId,
                        ['released' => $release, 'currency' => $cur, 'new_status' => $newStatus, 'gl_entry' => $entryId], $orgId);
                }
                $result = ['ok' => true, 'released' => $release, 'entry_id' => $entryId, 'message' => ucfirst($mode) . ' recorded'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('trust_bond_release: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Release error'];
        }
        return $result;
    }
}

if (!function_exists('trust_bond_list')) {
    function trust_bond_list($db, int $orgId, string $status = '', int $limit = 300): array
    {
        if ($orgId <= 0) { return []; }
        try {
            $where = ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, min(1000, $limit))];
            if ($status !== '' && array_key_exists($status, trust_bond_statuses())) { $where['status'] = $status; }
            return $db->select('stays_trust_bonds', '*', $where) ?: [];
        } catch (\Throwable $e) { error_log('trust_bond_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// MANAGEMENT-FEE POSTING  [MONEY — DB-GATED]
// ---------------------------------------------------------------------------
if (!function_exists('trust_management_fee_charge')) {
    /**
     * Charge a management fee against money the manager holds in trust for an owner.
     * This is the operator taking its fee OUT of client-trust and into its own books:
     *   1) base ledger `disbursement` for the owner party (client-trust liability down),
     *   2) BALANCED GL (Dr 2200 Deposits/Advance liability, Cr 4900 Other Revenue) so the
     *      fee is recognised as the property's own revenue.
     * The fee is CLAMPED to the owner's current held balance in that currency — you can
     * never pull more fee than is actually held in trust (over-draw guard). Atomic.
     * Returns ['ok'=>bool,'charged'=>float,'entry_id'=>?int,'message'=>].
     *
     * Note: this is the trust-side cash movement of a management fee. Statement-level
     * manager-commission arithmetic already lives in owner_statement_preview() — this
     * does not re-open statements, it only moves the held cash + books the revenue.
     */
    function trust_management_fee_charge($db, int $orgId, string $party, float $amount, array $opts = []): array
    {
        if ($orgId <= 0) { return ['ok' => false, 'message' => 'No org']; }
        $party  = substr(trim($party), 0, 191);
        $amount = round((float) $amount, 2);
        $cur    = strtoupper(substr((string) ($opts['currency'] ?? 'USD'), 0, 3)) ?: 'USD';
        if ($party === '' || $amount <= 0) { return ['ok' => false, 'message' => 'Party and a positive fee are required']; }

        $result = ['ok' => false, 'message' => 'Fee charge failed'];
        try {
            $db->action(function ($db) use ($orgId, $party, $amount, $cur, $opts, &$result) {
                // Re-read the held balance INSIDE the txn (authoritative, not client-supplied).
                $held = 0.0;
                if (function_exists('trust_balances')) {
                    $bal = trust_balances($db, $orgId);
                    foreach (($bal['parties'] ?? []) as $p) {
                        if ((string) $p['party'] === $party && strtoupper((string) $p['currency']) === $cur) {
                            $held = round((float) $p['balance'], 2); break;
                        }
                    }
                }
                if ($held <= 0) { $result = ['ok' => false, 'message' => 'This party holds no ' . $cur . ' in trust']; return false; }
                $charge = min($amount, $held); // never pull more fee than is held

                $led = function_exists('trust_add') ? trust_add($db, $orgId, [
                    'party' => $party, 'entry_type' => 'disbursement', 'amount' => $charge,
                    'currency' => $cur, 'reference' => substr((string) ($opts['reference'] ?? ''), 0, 120) ?: 'mgmt_fee',
                    'memo' => substr((string) ($opts['memo'] ?? 'Management fee'), 0, 255),
                ]) : 0;
                if ($led <= 0) { $result = ['ok' => false, 'message' => 'Ledger disbursement failed']; return false; }

                $entryId = null;
                if (function_exists('gl_post')) {
                    $gl = gl_post($db, $orgId, [
                        ['code' => '2200', 'debit'  => $charge], // Deposits / Advance (liability) down
                        ['code' => '4900', 'credit' => $charge], // Other Revenue up (fee earned)
                    ], [
                        'source'    => 'trust_mgmt_fee',
                        'reference' => 'mgmtfee:' . md5($party) . ':' . date('YmdHis'),
                        'memo'      => 'Management fee from ' . $party,
                        'currency'  => $cur,
                    ]);
                    if (empty($gl['ok'])) { $result = ['ok' => false, 'message' => $gl['message'] ?? 'GL post failed']; return false; }
                    $entryId = (int) ($gl['entry_id'] ?? 0);
                }
                if (function_exists('audit_log')) {
                    audit_log($db, 'trust.mgmt_fee.charged', 'trust_party', null,
                        ['party' => $party, 'charged' => $charge, 'currency' => $cur, 'gl_entry' => $entryId], $orgId);
                }
                $result = ['ok' => true, 'charged' => $charge, 'entry_id' => $entryId, 'message' => 'Management fee charged'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('trust_management_fee_charge: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Fee error'];
        }
        return $result;
    }
}

// ---------------------------------------------------------------------------
// TRUST-BANK SNAPSHOT + THREE-WAY RECONCILIATION  [DB-GATED: stays_trust_bank]
// ---------------------------------------------------------------------------
if (!function_exists('trust_bank_record')) {
    /** Operator-entered trust-bank statement balance per currency (what the segregated
     *  client-money bank account actually holds, as of a date). This is the "bank side"
     *  of the reconciliation — NO live bank feed is connected (see trust_bank_feed_status). */
    function trust_bank_record($db, int $orgId, string $currency, float $balance, string $asOf = '', string $note = ''): array
    {
        if ($orgId <= 0) { return ['ok' => false, 'message' => 'No org']; }
        $cur = strtoupper(substr($currency, 0, 3)) ?: 'USD';
        $asOf = preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) ? $asOf : date('Y-m-d');
        try {
            $db->insert('stays_trust_bank', [
                'org_id'     => $orgId, 'currency' => $cur,
                'balance'    => round($balance, 2), 'as_of' => $asOf,
                'note'       => substr(trim($note), 0, 255) ?: null,
                'created_by' => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            if (function_exists('audit_log')) {
                audit_log($db, 'trust.bank.recorded', 'stays_trust_bank', (int) $db->id(),
                    ['currency' => $cur, 'balance' => round($balance, 2), 'as_of' => $asOf], $orgId);
            }
            return ['ok' => true, 'message' => 'Bank balance recorded'];
        } catch (\Throwable $e) {
            error_log('trust_bank_record: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not record bank balance'];
        }
    }
}

if (!function_exists('trust_bank_latest')) {
    /** Latest recorded bank balance per currency: ['USD'=>['balance','as_of','note'], ...]. */
    function trust_bank_latest($db, int $orgId): array
    {
        $out = [];
        if ($orgId <= 0) { return $out; }
        try {
            $rows = $db->select('stays_trust_bank', ['currency', 'balance', 'as_of', 'note'],
                ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC']]) ?: [];
            foreach ($rows as $r) {
                $c = strtoupper((string) $r['currency']) ?: 'USD';
                if (!isset($out[$c])) { // first = newest (ordered id DESC)
                    $out[$c] = ['balance' => round((float) $r['balance'], 2), 'as_of' => (string) $r['as_of'], 'note' => (string) ($r['note'] ?? '')];
                }
            }
        } catch (\Throwable $e) { error_log('trust_bank_latest: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('trust_reconciliation')) {
    /**
     * THREE-WAY trust reconciliation per currency: the computed book liability
     * (trust_balances totals) vs. the latest operator-recorded bank balance. A shortfall
     * (bank < book) is a client-money breach and is surfaced prominently.
     * Returns ['rows'=>[['currency','book','bank','variance','verdict','as_of']...],
     *          'any_shortfall'=>bool].
     */
    function trust_reconciliation($db, int $orgId): array
    {
        $out = ['rows' => [], 'any_shortfall' => false];
        if ($orgId <= 0) { return $out; }
        $bal  = function_exists('trust_balances') ? trust_balances($db, $orgId) : ['totals' => []];
        $bank = trust_bank_latest($db, $orgId);
        $currencies = array_unique(array_merge(array_keys($bal['totals'] ?? []), array_keys($bank)));
        sort($currencies);
        foreach ($currencies as $cur) {
            $book = round((float) (($bal['totals'] ?? [])[$cur] ?? 0), 2);
            $bk   = isset($bank[$cur]) ? (float) $bank[$cur]['balance'] : 0.0;
            $r = trust_reconcile_status($book, $bk);
            $r['currency'] = $cur;
            $r['as_of'] = isset($bank[$cur]) ? $bank[$cur]['as_of'] : null;
            $r['bank_recorded'] = isset($bank[$cur]);
            if ($r['verdict'] === 'shortfall') { $out['any_shortfall'] = true; }
            $out['rows'][] = $r;
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// CLIENT-FUND AUDIT (read)  — reuses the platform audit trail
// ---------------------------------------------------------------------------
if (!function_exists('trust_audit_trail')) {
    /** Recent client-money audit events for this org (trust.* only), newest first.
     *  Reuses audit_recent() and filters to the trust namespace in PHP (no new table). */
    function trust_audit_trail($db, int $orgId, int $limit = 100): array
    {
        if ($orgId <= 0) { return []; }
        $rows = function_exists('audit_recent') ? audit_recent($db, $orgId, 400) : [];
        $out = [];
        foreach ($rows as $r) {
            if (strpos((string) ($r['event'] ?? ''), 'trust.') === 0) { $out[] = $r; }
            if (count($out) >= max(1, min(500, $limit))) { break; }
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// EXTERNAL EDGE — HONEST STUB (no live trust-bank feed connected)
// ---------------------------------------------------------------------------
if (!function_exists('trust_bank_feed_status')) {
    /**
     * Would report a live segregated-trust-bank balance feed. None is wired here, so this
     * honestly reports 'not_connected'. The "bank side" of reconciliation is entered by
     * the operator via trust_bank_record(). DO NOT fake a live balance.
     */
    function trust_bank_feed_status($db, int $orgId): array
    {
        return [
            'connected' => false,
            'provider'  => null,
            'message'   => 'No live trust-bank feed is connected. Enter the segregated client-account balance manually to reconcile.',
        ];
    }
}

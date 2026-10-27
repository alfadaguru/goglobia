<?php
// FILE: app/lib/supplier_contact_wallet.php
// FRONT-DESK OVERHAUL — Phase 4b: the CUSTOMER WALLET (stored value).
//
// A deposit a HOTEL holds for one of its Customer-Book contacts (company / NGO /
// individual), drawn down on stays. Keyed by (org_id, contact_id, currency) and kept
// DELIBERATELY SEPARATE from the platform `wallets`/`users` spine — a hotel customer is
// a stays_contacts row, not a platform user. This is the same separation the hotel GL/AR
// already keep from platform funds.
//
// Safety mirrors the proven platform wallet (wallet.php):
//   - the balance row is locked FOR UPDATE for the check+update (no lost updates),
//   - a debit can NEVER take the balance negative (no overdraft — a wallet is prepaid),
//   - the ledger carries a UNIQUE (org_id, ref) so a retried top-up / draw-down with the
//     same ref is a safe no-op (never double-applies), and the whole thing is one
//     $db->action() so a torn write rolls back.
// It does NOT mirror users.balance or the agent `credits` ledger — those are platform-
// only concerns that don't apply to a hotel's own customer.

if (!function_exists('cwallet_get_or_create')) {
    /** The wallet row for (org, contact, currency), creating it at 0 if absent. */
    function cwallet_get_or_create($db, int $orgId, int $contactId, string $currency): ?array
    {
        $currency = strtoupper(trim($currency)) ?: 'USD';
        if ($orgId <= 0 || $contactId <= 0) { return null; }
        try {
            $w = $db->get('stays_contact_wallet', '*', ['org_id' => $orgId, 'contact_id' => $contactId, 'currency' => $currency]);
            if ($w) { return $w; }
            $db->insert('stays_contact_wallet', [
                'org_id' => $orgId, 'contact_id' => $contactId, 'currency' => $currency,
                'balance' => 0.00, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return $db->get('stays_contact_wallet', '*', ['id' => (int) $db->id()]) ?: null;
        } catch (\Throwable $e) { error_log('cwallet_get_or_create: ' . $e->getMessage()); return null; }
    }
}

if (!function_exists('cwallet_balance')) {
    /** Current balance for (org, contact, currency). 0.0 if none. */
    function cwallet_balance($db, int $orgId, int $contactId, string $currency = 'USD'): float
    {
        $currency = strtoupper(trim($currency)) ?: 'USD';
        if ($orgId <= 0 || $contactId <= 0) { return 0.0; }
        try {
            $b = $db->get('stays_contact_wallet', 'balance', ['org_id' => $orgId, 'contact_id' => $contactId, 'currency' => $currency]);
            return $b === null ? 0.0 : round((float) $b, 2);
        } catch (\Throwable $e) { error_log('cwallet_balance: ' . $e->getMessage()); return 0.0; }
    }
}

if (!function_exists('cwallet_balances')) {
    /** All currency balances for a contact: ['USD'=>f, 'NGN'=>f, ...]. */
    function cwallet_balances($db, int $orgId, int $contactId): array
    {
        $out = [];
        if ($orgId <= 0 || $contactId <= 0) { return $out; }
        try {
            foreach ($db->select('stays_contact_wallet', ['currency', 'balance'], ['org_id' => $orgId, 'contact_id' => $contactId]) ?: [] as $r) {
                $out[(string) $r['currency']] = round((float) $r['balance'], 2);
            }
        } catch (\Throwable $e) { error_log('cwallet_balances: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('cwallet_apply')) {
    /**
     * Apply a credit (deposit) or debit (draw-down) to a contact's wallet. Row-locked,
     * idempotent on $opts['ref'] (same ref = no-op returning current balance), and a
     * debit can never take the balance negative. Returns
     * ['ok'=>bool,'balance'=>float,'message'=>?,'already'=>?bool].
     */
    function cwallet_apply($db, int $orgId, int $contactId, float $amount, string $direction, string $currency = 'USD', array $opts = []): array
    {
        $amount = round((float) $amount, 2);
        if ($orgId <= 0 || $contactId <= 0) { return ['ok' => false, 'message' => 'Bad account.']; }
        if ($amount <= 0) { return ['ok' => false, 'message' => 'Amount must be positive.']; }
        $direction = $direction === 'credit' ? 'credit' : 'debit';
        $currency = strtoupper(trim($currency)) ?: 'USD';
        $ref = isset($opts['ref']) ? substr((string) $opts['ref'], 0, 120) : null;
        if ($ref === '') { $ref = null; }
        cwallet_get_or_create($db, $orgId, $contactId, $currency); // ensure exists before locking

        $result = ['ok' => false, 'message' => 'Wallet update failed.'];
        try {
            $db->action(function ($db) use ($orgId, $contactId, $amount, $direction, $currency, $ref, $opts, &$result) {
                // Idempotency: a ledger row with this (org, ref) means the money already
                // moved — return the current balance, do NOT apply again.
                if ($ref !== null && $db->has('stays_contact_wallet_ledger', ['org_id' => $orgId, 'ref' => $ref])) {
                    $bal = (float) ($db->get('stays_contact_wallet', 'balance', ['org_id' => $orgId, 'contact_id' => $contactId, 'currency' => $currency]) ?? 0);
                    $result = ['ok' => true, 'balance' => round($bal, 2), 'already' => true];
                    return true;
                }
                // Lock the balance row for the check+update.
                $locked = $db->query(
                    'SELECT id, balance FROM stays_contact_wallet WHERE org_id = :o AND contact_id = :c AND currency = :cur FOR UPDATE',
                    [':o' => $orgId, ':c' => $contactId, ':cur' => $currency]
                );
                $w = $locked ? $locked->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$w) { $result = ['ok' => false, 'message' => 'Wallet not found.']; return false; }
                $balance = round((float) $w['balance'], 2);
                $walletId = (int) $w['id'];

                if ($direction === 'debit' && $balance < $amount) {
                    // Prepaid wallet: no overdraft. (Credit terms are the separate
                    // city-ledger/credit-limit mechanism, not the wallet.)
                    $result = ['ok' => false, 'message' => 'Insufficient wallet balance.', 'balance' => $balance, 'required' => $amount];
                    return false; // rollback
                }

                $newBalance = round($direction === 'credit' ? $balance + $amount : $balance - $amount, 2);
                $db->update('stays_contact_wallet', ['balance' => $newBalance, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $walletId]);
                $db->insert('stays_contact_wallet_ledger', [
                    'org_id'        => $orgId,
                    'contact_id'    => $contactId,
                    'wallet_id'     => $walletId,
                    'direction'     => $direction,
                    'amount'        => $amount,
                    'balance_after' => $newBalance,
                    'currency'      => $currency,
                    'reason'        => in_array(($opts['reason'] ?? ''), ['topup', 'deposit', 'stay', 'refund', 'adjustment', 'reversal'], true) ? $opts['reason'] : 'adjustment',
                    'ref'           => $ref,
                    'note'          => isset($opts['note']) ? substr((string) $opts['note'], 0, 255) : null,
                    'created_by'    => (string) ($_SESSION['user_id'] ?? '') ?: null,
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
                $result = ['ok' => true, 'balance' => $newBalance];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('cwallet_apply: ' . $e->getMessage());
            // A duplicate-ref race hits the UNIQUE key → treat as already-applied no-op.
            if ($ref !== null) {
                $bal = cwallet_balance($db, $orgId, $contactId, $currency);
                return ['ok' => true, 'balance' => $bal, 'already' => true];
            }
            return ['ok' => false, 'message' => 'Wallet update error.'];
        }
        return $result;
    }
}

if (!function_exists('cwallet_topup')) {
    /** Record a deposit the hotel received for this contact (cash/transfer at the desk). */
    function cwallet_topup($db, int $orgId, int $contactId, float $amount, string $currency = 'USD', array $opts = []): array
    {
        $opts['reason'] = $opts['reason'] ?? 'topup';
        return cwallet_apply($db, $orgId, $contactId, $amount, 'credit', $currency, $opts);
    }
}

if (!function_exists('cwallet_drawdown')) {
    /** Draw down the wallet to pay toward a stay (fails if insufficient — no overdraft). */
    function cwallet_drawdown($db, int $orgId, int $contactId, float $amount, string $currency = 'USD', array $opts = []): array
    {
        $opts['reason'] = $opts['reason'] ?? 'stay';
        return cwallet_apply($db, $orgId, $contactId, $amount, 'debit', $currency, $opts);
    }
}

if (!function_exists('cwallet_ledger')) {
    /** Recent wallet ledger rows for a contact (newest first), capped. */
    function cwallet_ledger($db, int $orgId, int $contactId, int $limit = 50): array
    {
        if ($orgId <= 0 || $contactId <= 0) { return []; }
        $limit = max(1, min(200, $limit));
        try {
            return $db->select('stays_contact_wallet_ledger',
                ['direction', 'amount', 'balance_after', 'currency', 'reason', 'note', 'created_by', 'created_at'],
                ['org_id' => $orgId, 'contact_id' => $contactId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => $limit]) ?: [];
        } catch (\Throwable $e) { error_log('cwallet_ledger: ' . $e->getMessage()); return []; }
    }
}

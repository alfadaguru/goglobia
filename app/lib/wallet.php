<?php
// ============================================================================
// MONEY SPINE — the single source of truth for wallets & transactions.
// docs/MONEY-WALLET-AUDIT.md §A/§C.
//
// Four tables (created by ensureAgentApiSchema in functions.php):
//   wallets              — one spendable balance per (user, currency)
//   wallet_ledger        — every change to a wallet (with running balance_after)
//   money_transactions   — ONE record of every money movement (credit|debit)
//   transaction_journey  — ordered state trail for a transaction
//
// Model (owner's spec):
//   * A transaction is BORN 'pending', is 'sent' to a gateway, then resolves to
//     'success' | 'failed' | 'cancelled' | 'reversed' — every step is dumped to
//     transaction_journey so the whole flow is traceable.
//   * Everyone (customer + agent) has ONE wallet, funded by any enabled gateway.
//   * Customer pays wallet OR gateway; agent pays wallet ONLY (enforced by the
//     checkout layer, not here — this file is the money engine only).
//
// SAFE BRIDGE: for AGENTS this engine ALSO mirrors the legacy `credits` ledger
// (credit/debit rows) so the existing agent_api_* balance/charge code keeps
// working unchanged. Nothing here drops or rewrites `credits`.
// ============================================================================

if (!function_exists('wallet_default_currency')) {
    /** Site default currency (currencies.default = 1), else NGN. Never guesses. */
    function wallet_default_currency($db): string
    {
        try {
            $c = strtoupper(trim((string) ($db->get('currencies', 'name', ['default' => 1]) ?: '')));
            if ($c !== '') { return $c; }
        } catch (\Throwable $e) { /* ignore */ }
        return 'NGN';
    }
}

if (!function_exists('wallet_kind_for_user')) {
    /** 'agent' when the user's role is agent, else 'customer'. Reads users.role. */
    function wallet_kind_for_user($db, string $userId): string
    {
        try {
            $role = strtolower((string) ($db->get('users', 'role', ['user_id' => $userId]) ?: ''));
            return $role === 'agent' ? 'agent' : 'customer';
        } catch (\Throwable $e) { return 'customer'; }
    }
}

if (!function_exists('wallet_get_or_create')) {
    /**
     * Return the wallet row for (user, currency), creating it at balance 0 if
     * absent. Currency defaults to the site default. Returns the row array.
     */
    function wallet_get_or_create($db, string $userId, string $currency = ''): array
    {
        $currency = strtoupper(trim($currency)) ?: wallet_default_currency($db);
        $w = $db->get('wallets', '*', ['user_id' => $userId, 'currency' => $currency]);
        if ($w) { return $w; }
        $db->insert('wallets', [
            'user_id'    => $userId,
            'kind'       => wallet_kind_for_user($db, $userId),
            'currency'   => $currency,
            'balance'    => 0.00,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $db->get('wallets', '*', ['id' => (int) $db->id()]);
    }
}

if (!function_exists('wallet_balance')) {
    /** Spendable wallet balance for (user, currency). Reads the cached wallets row. */
    function wallet_balance($db, string $userId, string $currency = ''): float
    {
        $currency = strtoupper(trim($currency)) ?: wallet_default_currency($db);
        $w = $db->get('wallets', 'balance', ['user_id' => $userId, 'currency' => $currency]);
        return $w === null ? 0.0 : round((float) $w, 2);
    }
}

if (!function_exists('wallet_txn_ref')) {
    function wallet_txn_ref(): string
    {
        try { $r = strtoupper(bin2hex(random_bytes(6))); }
        catch (\Throwable $e) { $r = strtoupper(substr(md5(uniqid('', true)), 0, 12)); }
        return 'TXN-' . $r;
    }
}

if (!function_exists('txn_journey_add')) {
    /** Append one state-change row to a transaction's journey. */
    function txn_journey_add($db, int $transactionId, ?string $from, string $to, string $note = '', $context = null, ?string $actor = null): void
    {
        $db->insert('transaction_journey', [
            'transaction_id' => $transactionId,
            'from_status'    => $from,
            'to_status'      => $to,
            'note'           => mb_substr($note, 0, 255),
            'context'        => $context === null ? null : (is_string($context) ? $context : json_encode($context, JSON_UNESCAPED_SLASHES)),
            'actor'          => $actor ?? (string) ($_SESSION['user_id'] ?? 'system'),
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
    }
}

if (!function_exists('txn_create')) {
    /**
     * Create a money_transactions row, BORN 'pending', and open its journey.
     * $in keys: user_id, direction('credit'|'debit'), reason, amount, currency,
     *   method('gateway'|'wallet'|'manual'), gateway_id?, invoice_id?,
     *   idempotency_key?, description?, actor_kind?.
     * If idempotency_key already exists, returns the EXISTING transaction (no dup).
     * @return array the transaction row
     */
    function txn_create($db, array $in): array
    {
        $idem = isset($in['idempotency_key']) ? trim((string) $in['idempotency_key']) : '';
        if ($idem !== '') {
            $exist = $db->get('money_transactions', '*', ['idempotency_key' => $idem]);
            if ($exist) { return $exist; }
        }
        $userId   = (string) ($in['user_id'] ?? '');
        $currency = strtoupper(trim((string) ($in['currency'] ?? ''))) ?: wallet_default_currency($db);
        $ref      = wallet_txn_ref();
        $db->insert('money_transactions', [
            'txn_ref'         => $ref,
            'user_id'         => $userId,
            'actor_kind'      => in_array(($in['actor_kind'] ?? ''), ['customer','agent','admin','system'], true) ? $in['actor_kind'] : wallet_kind_for_user($db, $userId),
            'direction'       => ($in['direction'] ?? 'debit') === 'credit' ? 'credit' : 'debit',
            'reason'          => (string) ($in['reason'] ?? 'adjustment'),
            'amount'          => round((float) ($in['amount'] ?? 0), 2),
            'currency'        => $currency,
            'method'          => in_array(($in['method'] ?? ''), ['gateway','wallet','manual'], true) ? $in['method'] : 'wallet',
            'gateway_id'      => $in['gateway_id'] ?? null,
            'invoice_id'      => $in['invoice_id'] ?? null,
            'status'          => 'pending',
            'idempotency_key' => $idem !== '' ? $idem : null,
            'description'     => isset($in['description']) ? mb_substr((string) $in['description'], 0, 255) : null,
            'created_by'      => (string) ($_SESSION['user_id'] ?? $userId),
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
        $id = (int) $db->id();
        txn_journey_add($db, $id, null, 'pending', 'created (' . ($in['reason'] ?? '') . ')');
        return $db->get('money_transactions', '*', ['id' => $id]);
    }
}

if (!function_exists('txn_advance')) {
    /**
     * Move a transaction to a new status and record it in the journey.
     * Valid: pending -> sent -> success|failed|cancelled ; success -> reversed.
     * @return bool
     */
    function txn_advance($db, int $transactionId, string $toStatus, string $note = '', $context = null, array $extra = []): bool
    {
        $valid = ['pending','sent','success','failed','cancelled','reversed'];
        if (!in_array($toStatus, $valid, true)) { return false; }
        $t = $db->get('money_transactions', ['id','status'], ['id' => $transactionId]);
        if (!$t) { return false; }
        $from = (string) $t['status'];
        $upd = array_merge(['status' => $toStatus, 'updated_at' => date('Y-m-d H:i:s')], $extra);
        $db->update('money_transactions', $upd, ['id' => $transactionId]);
        txn_journey_add($db, $transactionId, $from, $toStatus, $note, $context);
        return true;
    }
}

if (!function_exists('wallet_apply')) {
    /**
     * Apply a credit or debit to a wallet ATOMICALLY: lock the wallet row, check
     * funds on debit, update the cached balance, and write ONE wallet_ledger row
     * (with running balance_after). For AGENT wallets it also mirrors a matching
     * `credits` row so legacy agent_api_* reads stay correct.
     *
     * @param string $direction 'credit'|'debit'
     * @param array  $opts  reason, ref_type, ref_id, note, transaction_id, allow_credit_line(bool)
     * @return array ['ok'=>bool,'balance'=>float,'message'=>?]
     */
    function wallet_apply($db, string $userId, float $amount, string $direction, string $currency = '', array $opts = []): array
    {
        $amount = round((float) $amount, 2);
        if ($amount <= 0) { return ['ok' => false, 'message' => 'Amount must be positive']; }
        $direction = $direction === 'credit' ? 'credit' : 'debit';
        $currency = strtoupper(trim($currency)) ?: wallet_default_currency($db);
        wallet_get_or_create($db, $userId, $currency); // ensure exists before locking

        $result = ['ok' => false, 'message' => 'Wallet update failed'];
        try {
            $db->action(function ($db) use ($userId, $amount, $direction, $currency, $opts, &$result) {
                // Lock the wallet row for the check+update.
                $locked = $db->query(
                    'SELECT id, balance, kind FROM wallets WHERE user_id = :u AND currency = :c FOR UPDATE',
                    [':u' => $userId, ':c' => $currency]
                );
                $w = $locked ? $locked->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$w) { $result = ['ok' => false, 'message' => 'Wallet not found']; return false; }
                $balance = (float) $w['balance'];
                $walletId = (int) $w['id'];

                if ($direction === 'debit') {
                    // Optional agent credit-line headroom (users.credit_limits).
                    $headroom = 0.0;
                    if (!empty($opts['allow_credit_line'])) {
                        try { $headroom = (float) ($db->get('users', 'credit_limits', ['user_id' => $userId]) ?: 0); } catch (\Throwable $e) {}
                    }
                    if (($balance + $headroom) < $amount) {
                        $result = ['ok' => false, 'message' => 'Insufficient wallet balance', 'balance' => round($balance, 2), 'required' => $amount];
                        return false; // rollback
                    }
                }

                $newBalance = round($direction === 'credit' ? $balance + $amount : $balance - $amount, 2);
                $db->update('wallets', ['balance' => $newBalance, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $walletId]);
                $db->insert('wallet_ledger', [
                    'wallet_id'      => $walletId,
                    'user_id'        => $userId,
                    'transaction_id' => $opts['transaction_id'] ?? null,
                    'direction'      => $direction,
                    'amount'         => $amount,
                    'balance_after'  => $newBalance,
                    'currency'       => $currency,
                    'reason'         => in_array(($opts['reason'] ?? ''), ['topup','booking','fee','refund','reversal','loyalty_convert','adjustment'], true) ? $opts['reason'] : 'adjustment',
                    'ref_type'       => $opts['ref_type'] ?? null,
                    'ref_id'         => $opts['ref_id'] ?? null,
                    'note'           => isset($opts['note']) ? mb_substr((string) $opts['note'], 0, 255) : null,
                    'created_at'     => date('Y-m-d H:i:s'),
                ]);

                // LEGACY MIRROR for agent wallets: keep the `credits` ledger in
                // step so existing agent_api_* balance/charge code stays correct.
                if (($w['kind'] ?? '') === 'agent') {
                    $db->insert('credits', [
                        'user_id'     => $userId,
                        'type'        => $direction, // credits.type is enum('credit','debit')
                        'credits'     => $amount,
                        'currency'    => $currency,
                        'description' => (string) ($opts['note'] ?? ('wallet ' . $direction)),
                        'created_at'  => date('Y-m-d H:i:s'),
                    ]);
                }

                $result = ['ok' => true, 'balance' => $newBalance];
                return true; // commit
            });
        } catch (\Throwable $e) {
            error_log('wallet_apply: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Wallet update failed'];
        }
        return $result;
    }
}

if (!function_exists('wallet_topup_success')) {
    /**
     * Finalise a successful top-up: mark the transaction success and credit the
     * wallet, linked. Idempotent via the transaction's status (won't double-credit).
     * @return array ['ok'=>bool,'balance'=>float]
     */
    function wallet_topup_success($db, int $transactionId, string $providerTrxId = '', $gatewayContext = null): array
    {
        $t = $db->get('money_transactions', '*', ['id' => $transactionId]);
        if (!$t) { return ['ok' => false, 'message' => 'Transaction not found']; }
        if ($t['status'] === 'success') { return ['ok' => true, 'balance' => wallet_balance($db, $t['user_id'], $t['currency']), 'already' => true]; }
        if ($t['direction'] !== 'credit') { return ['ok' => false, 'message' => 'Not a credit transaction']; }

        txn_advance($db, $transactionId, 'success', 'gateway confirmed top-up', $gatewayContext,
            $providerTrxId !== '' ? ['provider_trx_id' => $providerTrxId] : []);
        $r = wallet_apply($db, (string) $t['user_id'], (float) $t['amount'], 'credit', (string) $t['currency'], [
            'reason' => 'topup', 'ref_type' => 'transaction', 'ref_id' => (string) $t['id'],
            'transaction_id' => (int) $t['id'], 'note' => 'Wallet top-up ' . $t['txn_ref'],
        ]);
        return $r;
    }
}

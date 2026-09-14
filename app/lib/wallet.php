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

        $kind = wallet_kind_for_user($db, $userId);

        // SEED the opening balance from the legacy store so existing money is
        // never lost when a user's wallet row is first materialised (audit
        // money-integrity): a CUSTOMER's money lives in users.balance today; an
        // AGENT's lives in the `credits` ledger. Seed only when the new wallet's
        // currency matches the user's own currency (no cross-currency guess).
        $opening = 0.00;
        try {
            $u = $db->get('users', ['role', 'currency', 'balance'], ['user_id' => $userId]);
            $userCur = strtoupper(trim((string) ($u['currency'] ?? '')));
            if ($u && ($userCur === '' || $userCur === $currency)) {
                if ($kind === 'customer') {
                    $opening = round((float) ($u['balance'] ?? 0), 2);
                } else {
                    // agent: opening = current derived credits balance
                    if (function_exists('agent_api_wallet_balance')) {
                        $opening = round((float) agent_api_wallet_balance($db, $userId), 2);
                    }
                }
                if ($opening < 0) { $opening = 0.00; }
            }
        } catch (\Throwable $e) { /* seed 0 on any error */ }

        $db->insert('wallets', [
            'user_id'    => $userId,
            'kind'       => $kind,
            'currency'   => $currency,
            'balance'    => $opening,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $walletId = (int) $db->id();

        // OPENING LEDGER ENTRY (audit money-integrity): when we seed a non-zero
        // opening balance from the legacy store, write a matching wallet_ledger
        // row so the wallet's statement always ties out to its balance
        // (sum(ledger) == balance) and the migrated money is itself traceable.
        // NOT mirrored to credits/users.balance — the money already lives there;
        // this only records the opening on the spine's own statement.
        if ($opening > 0) {
            try {
                $db->insert('wallet_ledger', [
                    'wallet_id'     => $walletId,
                    'user_id'       => $userId,
                    'transaction_id'=> null,
                    'direction'     => 'credit',
                    'amount'        => $opening,
                    'balance_after' => $opening,
                    'currency'      => $currency,
                    'reason'        => 'adjustment',
                    'ref_type'      => 'opening_balance',
                    'ref_id'        => null,
                    'note'          => 'Opening balance migrated from legacy store',
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e) { error_log('wallet_get_or_create opening ledger: ' . $e->getMessage()); }
        }

        return $db->get('wallets', '*', ['id' => $walletId]);
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

                // LEGACY MIRROR (audit money-integrity): keep the pre-spine store
                // in step so ALL existing readers stay correct and the two systems
                // never drift.
                //   * AGENT   -> the `credits` ledger (agent_api_* reads this)
                //   * CUSTOMER -> users.balance (wallet_balance.php & dashboards read this)
                if (($w['kind'] ?? '') === 'agent') {
                    $db->insert('credits', [
                        'user_id'     => $userId,
                        'type'        => $direction, // credits.type is enum('credit','debit')
                        'credits'     => $amount,
                        'currency'    => $currency,
                        'description' => (string) ($opts['note'] ?? ('wallet ' . $direction)),
                        'created_at'  => date('Y-m-d H:i:s'),
                    ]);
                } else {
                    // Customer: users.balance is authoritative-mirrored to the
                    // wallet's new balance (same currency only — the wallet row is
                    // per-currency and was seeded from users.balance).
                    try {
                        $uCur = strtoupper(trim((string) ($db->get('users', 'currency', ['user_id' => $userId]) ?: '')));
                        if ($uCur === '' || $uCur === $currency) {
                            $db->update('users', ['balance' => $newBalance], ['user_id' => $userId]);
                        }
                    } catch (\Throwable $e) { error_log('wallet_apply customer mirror: ' . $e->getMessage()); }
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

// ============================================================================
// AGENT MEMBER TIERS (docs §C.4 step 4)
// ============================================================================
if (!function_exists('agent_lifetime_topup')) {
    /** Sum of an agent's successful wallet top-ups (drives tier). */
    function agent_lifetime_topup($db, string $userId): float
    {
        try {
            $s = $db->sum('money_transactions', 'amount', [
                'user_id' => $userId, 'direction' => 'credit',
                'reason' => 'wallet_topup', 'status' => 'success',
            ]);
            return round((float) ($s ?: 0), 2);
        } catch (\Throwable $e) { return 0.0; }
    }
}

if (!function_exists('agent_tier_for_topup')) {
    /** The highest active tier whose min_lifetime_topup is met by $topup. */
    function agent_tier_for_topup($db, float $topup): ?array
    {
        $tiers = $db->select('agent_tiers', '*', ['active' => 1, 'ORDER' => ['min_lifetime_topup' => 'DESC']]) ?: [];
        foreach ($tiers as $t) {
            if ($topup + 0.001 >= (float) $t['min_lifetime_topup']) { return $t; }
        }
        return null;
    }
}

if (!function_exists('agent_recompute_tier')) {
    /**
     * Recompute + persist an agent's tier from their lifetime top-ups. Returns
     * the tier row (or null). Called after a successful top-up.
     */
    function agent_recompute_tier($db, string $userId): ?array
    {
        if (wallet_kind_for_user($db, $userId) !== 'agent') { return null; }
        $tier = agent_tier_for_topup($db, agent_lifetime_topup($db, $userId));
        $tierId = $tier ? (int) $tier['id'] : null;
        try { $db->update('users', ['agent_tier_id' => $tierId], ['user_id' => $userId]); } catch (\Throwable $e) { /* col may lag */ }
        return $tier;
    }
}

if (!function_exists('agent_tier_discount_percent')) {
    /** The agent's current tier discount %, 0 if none / not an agent. */
    function agent_tier_discount_percent($db, string $userId): float
    {
        try {
            $tid = $db->get('users', 'agent_tier_id', ['user_id' => $userId]);
            if (!$tid) { return 0.0; }
            $t = $db->get('agent_tiers', ['discount_percent', 'active'], ['id' => (int) $tid]);
            if (!$t || (int) $t['active'] !== 1) { return 0.0; }
            return (float) $t['discount_percent'];
        } catch (\Throwable $e) { return 0.0; }
    }
}

// ============================================================================
// LOYALTY POINTS (docs §C.4 step 5) — one ledger, two schemes (customer/agent)
// ============================================================================
if (!function_exists('loyalty_config')) {
    /** Admin-editable loyalty config from settings (with safe defaults). */
    function loyalty_config($db): array
    {
        $s = $GLOBALS['app'] ?? ($db->get('settings', '*', ['id' => 1]) ?: []);
        return [
            'enabled'       => (int) ($s['loyalty_enabled'] ?? 0) === 1,
            'earn_customer' => (float) ($s['loyalty_earn_customer'] ?? 0.01), // points per 1 currency
            'earn_agent'    => (float) ($s['loyalty_earn_agent'] ?? 0.005),
            'redeem_value'  => (float) ($s['loyalty_redeem_value'] ?? 1.0),   // currency per 1 point
        ];
    }
}
if (!function_exists('loyalty_balance')) {
    function loyalty_balance($db, string $userId): int
    {
        try { return (int) ($db->get('users', 'loyalty_points', ['user_id' => $userId]) ?: 0); }
        catch (\Throwable $e) { return 0; }
    }
}
if (!function_exists('loyalty_apply')) {
    /**
     * Apply a points movement (earn|redeem|adjust|expire) atomically: update the
     * cached users.loyalty_points and append a loyalty_ledger row with running
     * balance. Idempotent when an idempotency_key is given. Never goes negative.
     * @return array ['ok'=>bool,'balance'=>int,'message'=>?]
     */
    function loyalty_apply($db, string $userId, int $points, string $direction, array $opts = []): array
    {
        $direction = in_array($direction, ['earn', 'redeem', 'adjust', 'expire'], true) ? $direction : 'adjust';
        $points = (int) abs($points);
        if ($points <= 0) { return ['ok' => false, 'message' => 'Points must be positive']; }
        $idem = isset($opts['idempotency_key']) ? trim((string) $opts['idempotency_key']) : '';
        if ($idem !== '') {
            $dup = $db->get('loyalty_ledger', 'id', ['idempotency_key' => $idem]);
            if ($dup) { return ['ok' => true, 'balance' => loyalty_balance($db, $userId), 'already' => true]; }
        }
        $result = ['ok' => false, 'message' => 'Loyalty update failed'];
        try {
            $db->action(function ($db) use ($userId, $points, $direction, $opts, $idem, &$result) {
                $locked = $db->query('SELECT loyalty_points, role FROM users WHERE user_id = :u FOR UPDATE', [':u' => $userId]);
                $u = $locked ? $locked->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$u) { $result = ['ok' => false, 'message' => 'User not found']; return false; }
                $bal = (int) ($u['loyalty_points'] ?? 0);
                $isEarn = in_array($direction, ['earn', 'adjust'], true);
                if (!$isEarn && $bal < $points) { $result = ['ok' => false, 'message' => 'Not enough points', 'balance' => $bal]; return false; }
                $newBal = $isEarn ? $bal + $points : $bal - $points;
                $db->update('users', ['loyalty_points' => $newBal], ['user_id' => $userId]);
                $db->insert('loyalty_ledger', [
                    'user_id' => $userId,
                    'actor_kind' => (strtolower((string) ($u['role'] ?? '')) === 'agent') ? 'agent' : 'customer',
                    'direction' => $direction, 'points' => $points, 'balance_after' => $newBal,
                    'reason' => $opts['reason'] ?? null, 'ref_type' => $opts['ref_type'] ?? null, 'ref_id' => $opts['ref_id'] ?? null,
                    'idempotency_key' => $idem !== '' ? $idem : null, 'note' => $opts['note'] ?? null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $result = ['ok' => true, 'balance' => $newBal];
                return true;
            });
        } catch (\Throwable $e) { error_log('loyalty_apply: ' . $e->getMessage()); return ['ok' => false, 'message' => 'Loyalty update failed']; }
        return $result;
    }
}
if (!function_exists('loyalty_earn_for_payment')) {
    /** Earn points for a paid amount, per actor rate. Idempotent on invoice. */
    function loyalty_earn_for_payment($db, string $userId, float $amountPaid, string $invoiceId): array
    {
        $cfg = loyalty_config($db);
        if (!$cfg['enabled'] || $amountPaid <= 0) { return ['ok' => false, 'skipped' => true]; }
        $isAgent = wallet_kind_for_user($db, $userId) === 'agent';
        $rate = $isAgent ? $cfg['earn_agent'] : $cfg['earn_customer'];
        $pts = (int) floor($amountPaid * $rate);
        if ($pts <= 0) { return ['ok' => false, 'skipped' => true]; }
        return loyalty_apply($db, $userId, $pts, 'earn', [
            'reason' => 'booking', 'ref_type' => 'invoice', 'ref_id' => $invoiceId,
            'idempotency_key' => 'LOYALTY-EARN-' . $invoiceId, 'note' => 'Earned on payment ' . $invoiceId,
        ]);
    }
}
if (!function_exists('loyalty_convert_to_wallet')) {
    /**
     * Redeem points into wallet money: debit points, credit the wallet by
     * points * redeem_value. Atomic-ish (points first, then wallet credit).
     * @return array ['ok'=>bool,'points_left'=>int,'wallet_balance'=>float,'amount'=>float]
     */
    function loyalty_convert_to_wallet($db, string $userId, int $points, string $currency = ''): array
    {
        $cfg = loyalty_config($db);
        if (!$cfg['enabled']) { return ['ok' => false, 'message' => 'Loyalty is disabled']; }
        $points = (int) abs($points);
        if ($points <= 0) { return ['ok' => false, 'message' => 'Points must be positive']; }
        $amount = round($points * $cfg['redeem_value'], 2);
        if ($amount <= 0) { return ['ok' => false, 'message' => 'Nothing to convert']; }
        $red = loyalty_apply($db, $userId, $points, 'redeem', ['reason' => 'convert_to_wallet', 'note' => 'Convert ' . $points . ' pts to wallet']);
        if (empty($red['ok'])) { return $red; }
        $currency = strtoupper(trim($currency)) ?: wallet_default_currency($db);
        $cr = wallet_apply($db, $userId, $amount, 'credit', $currency, ['reason' => 'loyalty_convert', 'note' => 'Loyalty points converted (' . $points . ' pts)']);
        if (empty($cr['ok'])) {
            // Roll the points back so we never take points without giving money.
            loyalty_apply($db, $userId, $points, 'earn', ['reason' => 'convert_rollback', 'note' => 'Rollback failed wallet credit']);
            return ['ok' => false, 'message' => 'Wallet credit failed; points restored'];
        }
        return ['ok' => true, 'points_left' => $red['balance'], 'wallet_balance' => $cr['balance'], 'amount' => $amount];
    }
}

if (!function_exists('payment_actor_is_agent')) {
    /** True when the current session user is an agent. Single source of the rule. */
    function payment_actor_is_agent($db): bool
    {
        $uid = (string) ($_SESSION['user_id'] ?? '');
        if ($uid === '') { return false; }
        // Prefer an already-resolved role in session; else read users.role.
        $role = strtolower((string) ($_SESSION['user_role'] ?? ''));
        if ($role !== '') { return $role === 'agent'; }
        return wallet_kind_for_user($db, $uid) === 'agent';
    }
}

if (!function_exists('payment_gateway_allowed_for_actor')) {
    /**
     * THE PAYMENT RULE (docs/MONEY-WALLET-AUDIT.md §A.3), one place:
     *   - AGENT  -> wallet ONLY  (only an internal_wallet gateway is allowed)
     *   - CUSTOMER -> wallet OR any enabled gateway
     *   - guest (not logged in) -> external gateways only (no wallet)
     * $gateway is a payment_gateways row (needs 'type').
     */
    function payment_gateway_allowed_for_actor($db, array $gateway): bool
    {
        $type = (string) ($gateway['type'] ?? '');
        $isWallet = ($type === 'internal_wallet');
        $uid = (string) ($_SESSION['user_id'] ?? '');
        if ($uid === '') {
            // Guest: no wallet, external gateways only.
            return !$isWallet;
        }
        if (payment_actor_is_agent($db)) {
            // Agent: wallet only.
            return $isWallet;
        }
        // Customer: anything enabled (wallet or gateway).
        return true;
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
        // A top-up may promote the agent to a higher member tier (deposit-driven).
        if (!empty($r['ok']) && function_exists('agent_recompute_tier')) {
            agent_recompute_tier($db, (string) $t['user_id']);
        }
        return $r;
    }
}

if (!function_exists('wallet_spend')) {
    /**
     * Spend from a wallet through the SPINE — the single entry point every
     * checkout/booking debit must use (docs/MONEY-WALLET-AUDIT.md §A/§C):
     *   1. create a money_transactions debit (born 'pending', journey "created")
     *   2. atomically debit the wallet via wallet_apply() (FOR UPDATE lock,
     *      wallet_ledger row, legacy mirror to credits/users.balance)
     *   3. advance the transaction to 'success' (or 'failed' on insufficient funds)
     * Idempotent when opts['idempotency_key'] is given: a retried spend for the
     * same key returns the original result and never double-debits.
     *
     * @param array $opts reason('booking'|'fee'|'wallet_spend'|...), invoice_id,
     *                    gateway_id, method('wallet'), note, idempotency_key,
     *                    ref_type, ref_id, allow_credit_line(bool)
     * @return array ['ok'=>bool,'balance'=>float,'transaction'=>array,'message'=>?]
     */
    function wallet_spend($db, string $userId, float $amount, string $currency = '', array $opts = []): array
    {
        $amount = round((float) $amount, 2);
        if ($amount <= 0) { return ['ok' => false, 'message' => 'Amount must be positive']; }
        $currency = strtoupper(trim($currency)) ?: wallet_default_currency($db);

        // Idempotency: if a transaction with this key already reached success,
        // return it unchanged (no second debit).
        $idem = isset($opts['idempotency_key']) ? trim((string) $opts['idempotency_key']) : '';
        if ($idem !== '') {
            $exist = $db->get('money_transactions', '*', ['idempotency_key' => $idem]);
            if ($exist && $exist['status'] === 'success') {
                return ['ok' => true, 'balance' => wallet_balance($db, $userId, $currency), 'transaction' => $exist, 'already' => true];
            }
        }

        $reason = in_array(($opts['reason'] ?? ''), ['booking','fee','wallet_spend','adjustment','reversal'], true) ? $opts['reason'] : 'wallet_spend';
        $txn = txn_create($db, [
            'user_id'         => $userId,
            'direction'       => 'debit',
            'reason'          => $reason,
            'amount'          => $amount,
            'currency'        => $currency,
            'method'          => in_array(($opts['method'] ?? ''), ['gateway','wallet','manual'], true) ? $opts['method'] : 'wallet',
            'gateway_id'      => $opts['gateway_id'] ?? null,
            'invoice_id'      => $opts['invoice_id'] ?? null,
            'idempotency_key' => $idem !== '' ? $idem : null,
            'description'     => $opts['note'] ?? ('Wallet spend' . (isset($opts['invoice_id']) ? ' ' . $opts['invoice_id'] : '')),
        ]);
        if (!$txn || empty($txn['id'])) { return ['ok' => false, 'message' => 'Could not create transaction']; }

        // If this txn was already applied (idempotent create returned an existing
        // success row), don't debit again.
        if (($txn['status'] ?? 'pending') === 'success') {
            return ['ok' => true, 'balance' => wallet_balance($db, $userId, $currency), 'transaction' => $txn, 'already' => true];
        }

        $apply = wallet_apply($db, $userId, $amount, 'debit', $currency, [
            'reason'           => in_array($reason, ['booking','fee','reversal','adjustment'], true) ? $reason : 'booking',
            'ref_type'         => $opts['ref_type'] ?? 'invoice',
            'ref_id'           => (string) ($opts['ref_id'] ?? ($opts['invoice_id'] ?? '')),
            'transaction_id'   => (int) $txn['id'],
            'note'             => $opts['note'] ?? ('Wallet spend ' . $txn['txn_ref']),
            'allow_credit_line'=> !empty($opts['allow_credit_line']),
        ]);

        if (empty($apply['ok'])) {
            txn_advance($db, (int) $txn['id'], 'failed', (string) ($apply['message'] ?? 'wallet debit failed'));
            return ['ok' => false, 'balance' => (float) ($apply['balance'] ?? wallet_balance($db, $userId, $currency)),
                    'transaction' => $db->get('money_transactions', '*', ['id' => (int) $txn['id']]),
                    'message' => $apply['message'] ?? 'Insufficient wallet balance'];
        }

        txn_advance($db, (int) $txn['id'], 'success', 'wallet debited', null,
            isset($opts['provider_trx_id']) ? ['provider_trx_id' => $opts['provider_trx_id']] : []);
        return ['ok' => true, 'balance' => (float) $apply['balance'],
                'transaction' => $db->get('money_transactions', '*', ['id' => (int) $txn['id']])];
    }
}

if (!function_exists('wallet_refund')) {
    /**
     * Refund money back to a wallet through the SPINE (idempotent). Mirrors
     * wallet_spend on the credit side: creates a money_transactions credit
     * (reason 'refund'/'reversal'), applies the wallet credit (ledger + legacy
     * mirror), and marks the transaction success. A repeated call with the same
     * idempotency_key never double-credits.
     * @return array ['ok'=>bool,'balance'=>float,'transaction'=>array,'message'=>?]
     */
    function wallet_refund($db, string $userId, float $amount, string $currency = '', array $opts = []): array
    {
        $amount = round((float) $amount, 2);
        if ($amount <= 0) { return ['ok' => false, 'message' => 'Amount must be positive']; }
        $currency = strtoupper(trim($currency)) ?: wallet_default_currency($db);

        $idem = isset($opts['idempotency_key']) ? trim((string) $opts['idempotency_key']) : '';
        if ($idem !== '') {
            $exist = $db->get('money_transactions', '*', ['idempotency_key' => $idem]);
            if ($exist && $exist['status'] === 'success') {
                return ['ok' => true, 'balance' => wallet_balance($db, $userId, $currency), 'transaction' => $exist, 'already' => true];
            }
        }

        $reason = ($opts['reason'] ?? 'refund') === 'reversal' ? 'reversal' : 'refund';
        $txn = txn_create($db, [
            'user_id'         => $userId,
            'direction'       => 'credit',
            'reason'          => $reason,
            'amount'          => $amount,
            'currency'        => $currency,
            'method'          => 'wallet',
            'invoice_id'      => $opts['invoice_id'] ?? null,
            'idempotency_key' => $idem !== '' ? $idem : null,
            'description'     => $opts['note'] ?? ('Refund' . (isset($opts['invoice_id']) ? ' ' . $opts['invoice_id'] : '')),
        ]);
        if (!$txn || empty($txn['id'])) { return ['ok' => false, 'message' => 'Could not create transaction']; }
        if (($txn['status'] ?? 'pending') === 'success') {
            return ['ok' => true, 'balance' => wallet_balance($db, $userId, $currency), 'transaction' => $txn, 'already' => true];
        }

        $apply = wallet_apply($db, $userId, $amount, 'credit', $currency, [
            'reason'         => $reason,
            'ref_type'       => $opts['ref_type'] ?? 'invoice',
            'ref_id'         => (string) ($opts['ref_id'] ?? ($opts['invoice_id'] ?? '')),
            'transaction_id' => (int) $txn['id'],
            'note'           => $opts['note'] ?? ('Refund ' . $txn['txn_ref']),
        ]);
        if (empty($apply['ok'])) {
            txn_advance($db, (int) $txn['id'], 'failed', (string) ($apply['message'] ?? 'wallet credit failed'));
            return ['ok' => false, 'message' => $apply['message'] ?? 'Refund failed'];
        }
        txn_advance($db, (int) $txn['id'], 'success', 'wallet refunded');
        return ['ok' => true, 'balance' => (float) $apply['balance'],
                'transaction' => $db->get('money_transactions', '*', ['id' => (int) $txn['id']])];
    }
}

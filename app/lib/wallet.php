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

        // ENUM-SAFE reason (audit money-integrity): money_transactions.reason is an
        // ENUM. An out-of-enum value (e.g. 'booking', which callers pass) is
        // SILENTLY stored by MySQL as '' — making the movement uncategorised and
        // invisible to reason-filtered totals/reports. Normalise + map aliases so
        // a valid enum value is ALWAYS stored.
        $reasonIn  = (string) ($in['reason'] ?? 'adjustment');
        $reasonMap = ['booking' => 'booking_payment', 'topup' => 'wallet_topup', 'spend' => 'wallet_spend', 'convert' => 'loyalty_convert', '' => 'adjustment'];
        $reasonIn  = $reasonMap[$reasonIn] ?? $reasonIn;
        $reasonEnum = ['wallet_topup','booking_payment','wallet_spend','refund','reversal','fee','loyalty_convert','adjustment'];
        $reason    = in_array($reasonIn, $reasonEnum, true) ? $reasonIn : 'adjustment';

        $db->insert('money_transactions', [
            'txn_ref'         => $ref,
            'user_id'         => $userId,
            'actor_kind'      => in_array(($in['actor_kind'] ?? ''), ['customer','agent','admin','system'], true) ? $in['actor_kind'] : wallet_kind_for_user($db, $userId),
            'direction'       => ($in['direction'] ?? 'debit') === 'credit' ? 'credit' : 'debit',
            'reason'          => $reason,
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
        txn_journey_add($db, $id, null, 'pending', 'created (' . $reason . ')');
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

        // SPINE COMPLETENESS (audit money-integrity): record the wallet credit in
        // money_transactions too (reason=loyalty_convert), linked to the ledger
        // row via transaction_id — the same shape as wallet_refund/topup. Without
        // this the conversion moved money in wallet_ledger + wallets but left NO
        // money_transactions row, so loyalty redemptions were invisible in the
        // unified money ledger used for audit/reporting (every other wallet move
        // — topup, spend, refund, admin adjust — has one). Best-effort: if the
        // txn row can't be created we still credit the wallet (never take points
        // without giving money), we just miss the audit row.
        $convTxnId = null;
        if (function_exists('txn_create')) {
            try {
                $convTxn = txn_create($db, [
                    'user_id'     => $userId,
                    'direction'   => 'credit',
                    'reason'      => 'loyalty_convert',
                    'amount'      => $amount,
                    'currency'    => $currency,
                    'method'      => 'wallet',
                    'description' => 'Loyalty points converted (' . $points . ' pts)',
                ]);
                if ($convTxn && !empty($convTxn['id'])) { $convTxnId = (int) $convTxn['id']; }
            } catch (\Throwable $e) { error_log('loyalty_convert txn_create: ' . $e->getMessage()); }
        }

        $applyOpts = ['reason' => 'loyalty_convert', 'note' => 'Loyalty points converted (' . $points . ' pts)'];
        if ($convTxnId !== null) { $applyOpts['transaction_id'] = $convTxnId; }
        $cr = wallet_apply($db, $userId, $amount, 'credit', $currency, $applyOpts);
        if (empty($cr['ok'])) {
            if ($convTxnId !== null && function_exists('txn_advance')) {
                try { txn_advance($db, $convTxnId, 'failed', (string) ($cr['message'] ?? 'wallet credit failed')); } catch (\Throwable $e) {}
            }
            // Roll the points back so we never take points without giving money.
            loyalty_apply($db, $userId, $points, 'earn', ['reason' => 'convert_rollback', 'note' => 'Rollback failed wallet credit']);
            return ['ok' => false, 'message' => 'Wallet credit failed; points restored'];
        }
        if ($convTxnId !== null && function_exists('txn_advance')) {
            try { txn_advance($db, $convTxnId, 'success', 'loyalty points converted to wallet'); } catch (\Throwable $e) {}
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

if (!function_exists('payment_gateway_allowed_for_currency')) {
    /**
     * CURRENCY ROUTING (step 2): which EXTERNAL gateway may process a given
     * payment currency. Owner's rule: NGN -> Paystack (the NGN gateway); every
     * other currency -> Stripe (the designated foreign-currency gateway).
     *
     * Implemented data-driven, not by hardcoded name:
     *   - internal_wallet gateways are currency-agnostic (the wallet is already
     *     in the user's own currency) -> always allowed here.
     *   - For an external gateway, it is allowed for $payCurrency when its own
     *     configured `currency` equals $payCurrency (exact match). This makes
     *     Paystack(NGN) handle NGN and Stripe(USD) handle USD.
     *   - Fallback so "all other currencies -> Stripe" holds even for currencies
     *     no gateway is explicitly tagged with (EUR, GBP, ...): if NO enabled
     *     external gateway matches $payCurrency exactly, allow the non-NGN
     *     external gateway(s) (i.e. Stripe) and block the NGN one (Paystack).
     *
     * @param array  $gateway     a payment_gateways row (needs type, currency)
     * @param string $payCurrency the currency the payment will be made in
     */
    function payment_gateway_allowed_for_currency($db, array $gateway, string $payCurrency): bool
    {
        $type = (string) ($gateway['type'] ?? '');
        if ($type === 'internal_wallet') { return true; } // wallet: currency-agnostic

        $payCurrency = strtoupper(trim($payCurrency));
        if ($payCurrency === '') { return true; } // no currency context: don't filter

        $gwCurrency = strtoupper(trim((string) ($gateway['currency'] ?? '')));

        // Exact match always wins (Paystack=NGN for NGN, Stripe=USD for USD).
        if ($gwCurrency === $payCurrency) { return true; }

        // No exact-match gateway for this currency? Route to the non-NGN gateway.
        // (Paystack only supports NGN here; Stripe handles all other currencies.)
        // Determine if ANY enabled+active external gateway matches exactly.
        static $exactByCur = [];
        if (!array_key_exists($payCurrency, $exactByCur)) {
            $exactByCur[$payCurrency] = (int) $db->count('payment_gateways', [
                'status'      => '1',
                'active'      => '1',
                'type[!]'     => 'internal_wallet',
                'currency'    => $payCurrency,
            ]) > 0;
        }
        if ($exactByCur[$payCurrency]) {
            // Some gateway matches this currency exactly, and it isn't this one.
            return false;
        }
        // Fallback: allow any NON-NGN external gateway (Stripe), block the NGN one.
        return $gwCurrency !== 'NGN';
    }
}

if (!function_exists('payment_gateway_resolve_supplier')) {
    /**
     * Resolve a raw booking `module` value into a REAL registered supplier name,
     * or '' when it isn't one. bookings.module is client-sourced (from the saved
     * draft) and only soft-validated at booking time, so a per-service rule must
     * never trust it blindly — it is confirmed here against the modules registry
     * (matched by name+type). A generic value (e.g. 'flights' == the module name)
     * or an unknown string resolves to '' → the caller then falls back to
     * module-level / global scope. Verified against the real insert paths:
     * flights/stays/tours/cars/umrah/esim write the supplier; visa/rail/bus write
     * a generic literal (which correctly resolves to '' here).
     */
    function payment_gateway_resolve_supplier($db, string $moduleType, string $rawModule): string
    {
        $moduleType = strtolower(trim($moduleType));
        $rawModule  = strtolower(trim($rawModule));
        if ($moduleType === '' || $rawModule === '' || $rawModule === $moduleType) { return ''; }
        try {
            $hit = $db->get('modules', ['name'], ['name' => $rawModule, 'type' => $moduleType]);
            return $hit ? (string) $hit['name'] : '';
        } catch (\Throwable $e) { return ''; }
    }
}

if (!function_exists('payment_gateway_scope_allowlist')) {
    /**
     * The set of gateway ids allowed for a scope, resolving SERVICE → MODULE →
     * GLOBAL. Returns:
     *   - an array of allowed gateway ids  → an explicit allow-list is in force
     *   - null                             → NO scope rows at any level → INHERIT
     *                                        (i.e. fall back to the plain global
     *                                        status/active list; nothing filtered)
     *
     * Precedence: if the SERVICE level (module_type+supplier) has ANY rows, it
     * wins outright. Else if the MODULE level (module_type, supplier='') has any
     * rows, it wins. Else null (inherit global). This makes the feature additive:
     * an install with an empty payment_gateway_scopes table filters nothing.
     *
     * Per-request memoised — the chooser calls it once per gateway.
     */
    function payment_gateway_scope_allowlist($db, string $moduleType, string $supplier): ?array
    {
        static $memo = [];
        $moduleType = strtolower(trim($moduleType));
        $supplier   = strtolower(trim($supplier));
        $key = $moduleType . '|' . $supplier;
        if (array_key_exists($key, $memo)) { return $memo[$key]; }

        $resolve = static function ($scopeType, $mt, $sup) use ($db): ?array {
            try {
                $rows = $db->select('payment_gateway_scopes', ['gateway_id', 'enabled'], [
                    'scope_type'  => $scopeType,
                    'module_type' => $mt,
                    'supplier'    => $sup,
                ]);
            } catch (\Throwable $e) { return null; }
            if (!$rows) { return null; } // no rows at this level → inherit
            $allow = [];
            foreach ($rows as $r) {
                if ((int) ($r['enabled'] ?? 0) === 1) { $allow[(int) $r['gateway_id']] = true; }
            }
            return array_keys($allow); // may be [] → "explicitly none allowed here"
        };

        // SERVICE level first (only when we have a real supplier), then MODULE.
        $out = null;
        if ($moduleType !== '' && $supplier !== '') {
            $out = $resolve('service', $moduleType, $supplier);
        }
        if ($out === null && $moduleType !== '') {
            $out = $resolve('module', $moduleType, '');
        }
        return $memo[$key] = $out; // null = inherit global
    }
}

if (!function_exists('payment_gateway_allowed_for_scope')) {
    /**
     * Per-gateway predicate for the checkout chooser: is this gateway allowed for
     * the current module/supplier scope? True when there is no scope in force
     * (inherit) OR the gateway id is on the resolved allow-list. Pass the raw
     * booking module as $supplierRaw — it is resolved to a real supplier here.
     */
    function payment_gateway_allowed_for_scope($db, array $gateway, string $moduleType, string $supplierRaw): bool
    {
        $moduleType = strtolower(trim($moduleType));
        if ($moduleType === '') { return true; } // no module context → don't filter
        $supplier = payment_gateway_resolve_supplier($db, $moduleType, $supplierRaw);
        $allow = payment_gateway_scope_allowlist($db, $moduleType, $supplier);
        if ($allow === null) { return true; } // inherit → allow (global list governs)
        return in_array((int) ($gateway['id'] ?? 0), $allow, true);
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

// ============================================================================
// PAYSTACK DEDICATED VIRTUAL ACCOUNTS (DVA / NUBAN) — step 5
// ----------------------------------------------------------------------------
// A Nigerian (NGN) customer can activate a permanent bank account number from
// Paystack in their wallet. Money paid into that account arrives asynchronously
// via the Paystack webhook (app/routes/gateways/paystack.php) and is credited to
// the wallet through the SPINE, exactly like a card top-up.
//
// Paystack credentials live on the enabled Paystack payment_gateways row:
//   c1 = SECRET key (sk_...), c2 = public key. (See app/routes/gateways/paystack.php.)
//
// This module only CREATES the account (customer + dedicated account). No BVN is
// collected: we send only the identity we already have (name/email/phone). If a
// LIVE Paystack account requires BVN/validation, Paystack's exact message is
// surfaced back to the caller (result['message']) so the wallet can show it and
// we add a BVN field then — driven by the real API response, not a guess.
// ============================================================================

if (!function_exists('paystack_dva_gateway')) {
    /**
     * The enabled+active Paystack gateway row (the NGN external gateway), or null.
     * Data-driven: prefers an exact name match, then any enabled NGN external gw.
     */
    function paystack_dva_gateway($db): ?array
    {
        // Exact-name match first (the canonical Paystack row).
        $g = $db->get('payment_gateways', '*', [
            'name'   => 'Paystack',
            'status' => '1',
            'active' => '1',
        ]);
        if ($g) { return $g; }
        // Fallback: an enabled NGN external gateway (step-2 routing puts Paystack here).
        $g = $db->get('payment_gateways', '*', [
            'status'   => '1',
            'active'   => '1',
            'type[!]'  => 'internal_wallet',
            'currency' => 'NGN',
            'ORDER'    => ['default' => 'DESC', 'id' => 'ASC'],
        ]);
        return $g ?: null;
    }
}

if (!function_exists('paystack_dva_secret')) {
    /** Secret key for a Paystack gateway row: c1 (see paystack credential route). */
    function paystack_dva_secret(array $gateway): string
    {
        return trim((string) ($gateway['c1'] ?? ''));
    }
}

if (!function_exists('paystack_dva_http')) {
    /**
     * Minimal Paystack JSON call. Returns ['http'=>int,'json'=>array|null,'error'=>string].
     * GET when $payload is null, POST (JSON body) otherwise.
     */
    function paystack_dva_http(string $method, string $url, string $secret, ?array $payload = null): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $secret,
            'Content-Type: application/json',
            'Cache-Control: no-cache',
        ]);
        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload ?? []));
        }
        $body  = curl_exec($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err   = curl_error($ch);
        curl_close($ch);
        $json = is_string($body) ? json_decode($body, true) : null;
        return ['http' => $http, 'json' => is_array($json) ? $json : null, 'error' => $err];
    }
}

if (!function_exists('paystack_dva_activate')) {
    /**
     * Activate (create) a Paystack dedicated virtual account for a customer.
     * NGN-only, customer-only — the caller (route) enforces both before calling.
     *
     * Idempotent: if the user already has an account number, it is returned as-is.
     *
     * @return array {
     *   ok:bool, already?:bool, not_enabled?:bool, needs_bvn?:bool,
     *   account_number?:string, bank_name?:string, account_name?:string,
     *   message?:string
     * }
     */
    function paystack_dva_activate($db, string $userId): array
    {
        $user = $db->get('users', [
            'user_id', 'email', 'first_name', 'last_name', 'phone', 'phone_country_code',
            'currency', 'role', 'paystack_customer_code', 'dva_account_number',
            'dva_bank_name', 'dva_account_name',
        ], ['user_id' => $userId]);
        if (!$user) {
            return ['ok' => false, 'message' => 'Account not found.'];
        }

        // Already activated → return the stored NUBAN (idempotent).
        if (!empty($user['dva_account_number'])) {
            return [
                'ok' => true, 'already' => true,
                'account_number' => (string) $user['dva_account_number'],
                'bank_name'      => (string) ($user['dva_bank_name'] ?? ''),
                'account_name'   => (string) ($user['dva_account_name'] ?? ''),
            ];
        }

        $gateway = paystack_dva_gateway($db);
        if (!$gateway) {
            return ['ok' => false, 'not_enabled' => true,
                    'message' => 'Virtual accounts are not available right now.'];
        }
        $secret = paystack_dva_secret($gateway);
        if ($secret === '') {
            error_log('paystack_dva_activate: no secret (c1) on gateway ' . ($gateway['id'] ?? '?'));
            return ['ok' => false, 'not_enabled' => true,
                    'message' => 'Virtual accounts are not available right now.'];
        }

        $email = trim((string) ($user['email'] ?? ''));
        if ($email === '') {
            return ['ok' => false, 'message' => 'Your account has no email on file.'];
        }

        // ---- Step 1: ensure a Paystack customer exists for this user. ----------
        $customerCode = trim((string) ($user['paystack_customer_code'] ?? ''));
        if ($customerCode === '') {
            $phone = trim((string) ($user['phone'] ?? ''));
            $cc    = trim((string) ($user['phone_country_code'] ?? ''));
            if ($phone !== '' && $cc !== '' && strpos($phone, '+') !== 0 && strpos($phone, $cc) !== 0) {
                $phone = '+' . ltrim($cc, '+') . $phone;
            }
            $r = paystack_dva_http('POST', 'https://api.paystack.co/customer', $secret, [
                'email'      => $email,
                'first_name' => (string) ($user['first_name'] ?? ''),
                'last_name'  => (string) ($user['last_name'] ?? ''),
                'phone'      => $phone,
            ]);
            if ($r['http'] === 200 && !empty($r['json']['status']) && !empty($r['json']['data']['customer_code'])) {
                $customerCode = (string) $r['json']['data']['customer_code'];
            } else {
                $msg = $r['json']['message'] ?? ($r['error'] ?: 'Could not create your Paystack customer profile.');
                error_log('paystack_dva_activate customer: http=' . $r['http'] . ' msg=' . $msg);
                return ['ok' => false, 'message' => $msg];
            }
            $db->update('users', ['paystack_customer_code' => $customerCode], ['user_id' => $userId]);
        }

        // ---- Step 2: create the dedicated account for that customer. -----------
        // Optional preferred_bank from gateway config c5 (e.g. wema-bank / titan-paystack);
        // on test mode Paystack accepts 'test-bank'. Only send it if configured or dev.
        $preferredBank = trim((string) ($gateway['c5'] ?? ''));
        $devMode = !empty($gateway['dev_mode']);
        $payload = ['customer' => $customerCode];
        if ($preferredBank !== '') {
            $payload['preferred_bank'] = $preferredBank;
        } elseif ($devMode) {
            $payload['preferred_bank'] = 'test-bank';
        }

        $r = paystack_dva_http('POST', 'https://api.paystack.co/dedicated_account', $secret, $payload);

        if ($r['http'] === 200 && !empty($r['json']['status']) && !empty($r['json']['data']['account_number'])) {
            $d    = $r['json']['data'];
            $acct = (string) $d['account_number'];
            $bank = (string) ($d['bank']['name'] ?? '');
            $name = (string) ($d['account_name'] ?? '');
            $db->update('users', [
                'dva_account_number' => $acct,
                'dva_bank_name'      => $bank,
                'dva_account_name'   => $name,
                'dva_status'         => 'active',
                'dva_created_at'     => date('Y-m-d H:i:s'),
            ], ['user_id' => $userId]);
            return ['ok' => true, 'account_number' => $acct, 'bank_name' => $bank, 'account_name' => $name];
        }

        // ---- Graceful failure classification. ----------------------------------
        $msg = (string) ($r['json']['message'] ?? ($r['error'] ?: 'Could not create a virtual account right now.'));
        $low = strtolower($msg);
        // BVN / customer-validation required (a LIVE-account KYC requirement).
        if (strpos($low, 'bvn') !== false || strpos($low, 'validate') !== false || strpos($low, 'identification') !== false) {
            error_log('paystack_dva_activate: BVN/validation required — http=' . $r['http'] . ' msg=' . $msg);
            return ['ok' => false, 'needs_bvn' => true, 'message' => $msg];
        }
        // DVA feature not enabled / not approved on this Paystack account.
        if ($r['http'] === 400 || $r['http'] === 401 || $r['http'] === 403 || $r['http'] === 404
            || strpos($low, 'not enabled') !== false
            || strpos($low, 'not available') !== false
            || (strpos($low, 'dedicated') !== false && strpos($low, 'enable') !== false)
            || (strpos($low, 'contact') !== false && strpos($low, 'support') !== false)) {
            error_log('paystack_dva_activate: DVA not enabled — http=' . $r['http'] . ' msg=' . $msg);
            return ['ok' => false, 'not_enabled' => true, 'message' => $msg];
        }

        error_log('paystack_dva_activate: dedicated_account failed http=' . $r['http'] . ' msg=' . $msg);
        return ['ok' => false, 'message' => $msg];
    }
}

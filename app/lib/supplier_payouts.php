<?php
// FILE: app/lib/supplier_payouts.php
// SUPPLIER PAYOUTS TO BANK (Phase 1 inc S19). THE HIGHEST-RISK CODE IN THE PROJECT —
// this is the only path that sends money OUT. Read this header fully before editing.
//
// Model (owner-chosen): ADMIN-APPROVED, MANUAL trigger + a master kill-switch.
//   1. Supplier saves bank details and REQUESTS a payout from their 'available'
//      earnings. This reserves the covering earnings (payout_id set) inside a locked
//      transaction so the same money can't be requested twice.
//   2. An admin REVIEWS and APPROVES (or rejects). Only on approval is a transfer
//      attempted — and ONLY if settings.supplier_payouts_live === '1' (ships '0',
//      so nothing can leave until an operator explicitly enables it on the server).
//   3. On transfer success the reserved earnings flip 'available' -> 'paid'. On
//      failure/reject the reservation is released (payout_id cleared) so the money
//      is requestable again.
//
// Outbound idempotency: every payout carries a unique `reference` (our idempotency
// key, also sent to Paystack). A transfer is only initiated once; a retry reuses the
// same reference. The earnings debit is COMMITTED before the transfer call, and the
// transfer result only advances state — we never send without a committed reservation.
//
// Reuses the existing Paystack primitives (wallet.php): paystack_dva_gateway($db),
// paystack_dva_secret($g), paystack_dva_http(). Secret = payment_gateways.c1.
//
// 7 risks (payout-grounding) addressed inline: (1) double-pay on retry → unique
// reference + state guards; (2) debit-then-send limbo → reserve first, send after,
// reconcile on failure; (3) concurrent-withdrawal race → SELECT…FOR UPDATE on the
// earnings; (4) legacy-mirror corruption → never touches the wallet spine; (5) float
// → integer kobo for Paystack, DECIMAL in DB; (6) server-derived amount → amount is
// re-validated against the locked available balance, never trusted from the client
// beyond an upper request; (7) authz → request is owner-only, approve/transfer is
// ADMIN-only (enforced by the route), and the kill-switch gates the live call.

if (!function_exists('supplier_payouts_live')) {
    /** Master kill-switch. Transfers only fire when this is '1' (ships '0'). */
    function supplier_payouts_live($db): bool
    {
        try {
            $v = $db->get('settings', ['supplier_payouts_live'], ['id' => 1]);
            return $v && (string) ($v['supplier_payouts_live'] ?? '0') === '1';
        } catch (\Throwable $e) {
            error_log('supplier_payouts_live: ' . $e->getMessage());
            return false; // fail-closed: unknown → do not send
        }
    }
}

if (!function_exists('supplier_payable_available')) {
    /**
     * The owner's UNRESERVED available balance per currency:
     *   [currency => float]   (state='available' AND payout_id IS NULL, not void).
     * This is the only balance a new payout can draw from.
     */
    function supplier_payable_available($db, string $owner): array
    {
        $owner = trim($owner);
        if ($owner === '') { return []; }
        $out = [];
        try {
            $rows = $db->select('supplier_earnings', ['currency', 'net_amount'],
                ['owner_user_id' => $owner, 'state' => 'available', 'payout_id' => null]) ?: [];
            foreach ($rows as $r) {
                $cur = strtoupper((string) ($r['currency'] ?? 'USD'));
                $out[$cur] = round(($out[$cur] ?? 0) + (float) $r['net_amount'], 2);
            }
        } catch (\Throwable $e) {
            error_log('supplier_payable_available: ' . $e->getMessage());
        }

        // NET CARRIED CHARGEBACK DEBT (audit #9): when a disputed/reversed booking was
        // already settled or reserved, the clawback is recorded as a 'debt' chargeback
        // (mode='debt', state='applied') that must reduce what the supplier can withdraw —
        // otherwise the platform eats the loss. Subtract un-netted applied debt per
        // currency, clamped at 0 (never show negative; the remainder carries forward).
        // Guarded: if the column set isn't present the read is skipped, not fatal.
        try {
            $debts = $db->select('supplier_chargebacks', ['currency', 'amount'],
                ['owner_user_id' => $owner, 'state' => 'applied', 'mode' => 'debt', 'netted_at' => null]) ?: [];
            foreach ($debts as $d) {
                $cur = strtoupper((string) ($d['currency'] ?? 'USD'));
                if (isset($out[$cur])) {
                    $out[$cur] = round(max(0, $out[$cur] - (float) $d['amount']), 2);
                }
            }
        } catch (\Throwable $e) {
            // Narrow-schema install (pre-reconcile) or table absent — skip netting.
            error_log('supplier_payable_available debt-net: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('supplier_payout_reference')) {
    /** A unique, idempotent payout reference. No Date/random ban here (runtime). */
    function supplier_payout_reference(): string
    {
        return 'SPOUT-' . date('Ymd') . '-' . bin2hex(random_bytes(8));
    }
}

if (!function_exists('supplier_payout_request')) {
    /**
     * Create a payout request for $amount in $currency from the owner's UNRESERVED
     * available earnings. Atomically (SELECT…FOR UPDATE) verifies the balance and
     * RESERVES the covering earnings (sets payout_id) so the same money can't be
     * requested twice. Returns ['ok'=>bool,'payout_id'=>?int,'message'=>?].
     * State starts 'requested' — NO money moves here; an admin must approve.
     *
     * $opts (all optional — defaults preserve the original behaviour):
     *   'account' => a supplier_bank_accounts snapshot [bank_code, account_number,
     *                account_name, bank_name, recipient_code, id] to use as the payout
     *                destination INSTEAD of the legacy users.payout_* columns. When
     *                omitted, the users.payout_* columns are used (backward compatible).
     *   'fee'     => the fee (same currency) deducted from this payout; recorded on the
     *                row as fee_amount. Default 0. The reserved earnings = $amount (the
     *                gross); the supplier receives $amount - fee at transfer time.
     */
    function supplier_payout_request($db, string $owner, float $amount, string $currency, array $opts = []): array
    {
        $owner = trim($owner);
        $currency = strtoupper(trim($currency)) ?: 'USD';
        $amount = round($amount, 2);
        if ($owner === '') { return ['ok' => false, 'message' => 'No owner']; }
        if ($amount <= 0) { return ['ok' => false, 'message' => 'Amount must be positive']; }

        // Resolve the destination: a provided bank-account snapshot wins; else fall back
        // to the legacy single account on the users row.
        $acct = (isset($opts['account']) && is_array($opts['account'])) ? $opts['account'] : null;
        // NOTE: do NOT select users.org_id here — that column does not exist on `users`
        // (it's not in install/db.sql and no ensure* adds it), so selecting it made this
        // whole query throw 'Unknown column org_id' and broke every payout request. The
        // org used below comes from supplier_org_for_owner(), not the users row.
        $u = $db->get('users', ['payout_bank_code', 'payout_account_number', 'payout_account_name'], ['user_id' => $owner]);
        if ($acct !== null) {
            $bankCode = trim((string) ($acct['bank_code'] ?? ''));
            $acctNo   = trim((string) ($acct['account_number'] ?? ''));
            $acctName = trim((string) ($acct['account_name'] ?? '')) ?: trim((string) ($acct['verified_name'] ?? ''));
            $bankName = trim((string) ($acct['bank_name'] ?? ''));
            $recipient= trim((string) ($acct['recipient_code'] ?? ''));
            $bankAcctId = (int) ($acct['id'] ?? 0);
        } else {
            $bankCode = trim((string) ($u['payout_bank_code'] ?? ''));
            $acctNo   = trim((string) ($u['payout_account_number'] ?? ''));
            $acctName = trim((string) ($u['payout_account_name'] ?? ''));
            $bankName = '';
            $recipient= '';
            $bankAcctId = 0;
        }
        if ($bankCode === '' || $acctNo === '') {
            return ['ok' => false, 'message' => 'Add your bank details before requesting a payout.'];
        }
        $fee = round(max(0.0, (float) ($opts['fee'] ?? 0)), 2);
        if ($fee > $amount) { $fee = $amount; } // never exceed the gross
        $orgId = function_exists('supplier_org_for_owner') ? supplier_org_for_owner($db, $owner) : 0;
        // Daily cap (0 = no cap). Checked AGAIN inside the locked transaction below so two
        // concurrent requests cannot both pass a pre-check and jointly exceed the cap.
        $dailyCap = function_exists('supplier_payout_rule')
            ? (float) supplier_payout_rule($db, $currency, 'daily_cap', 0) : 0.0;
        $today = date('Y-m-d');

        $result = ['ok' => false, 'message' => 'Could not create the payout request'];
        try {
            $db->action(function ($db) use ($owner, $amount, $currency, $bankCode, $acctNo, $acctName, $bankName, $recipient, $bankAcctId, $fee, $orgId, $dailyCap, $today, &$result) {
                // Lock this owner's unreserved available earnings in this currency.
                $locked = $db->query(
                    "SELECT id, net_amount FROM supplier_earnings
                     WHERE owner_user_id = :o AND currency = :c AND state = 'available'
                       AND payout_id IS NULL
                     ORDER BY id ASC FOR UPDATE",
                    [':o' => $owner, ':c' => $currency]
                );
                $rows = $locked ? $locked->fetchAll(\PDO::FETCH_ASSOC) : [];
                $avail = 0.0;
                foreach ($rows as $r) { $avail = round($avail + (float) $r['net_amount'], 2); }

                // NET APPLIED CHARGEBACK DEBT inside the AUTHORITATIVE locked check (audit
                // #9/#17). The advisory precheck (supplier_payable_available, un-locked)
                // subtracted this, but the committing reservation did not — so concurrent
                // requests could reserve/pay out more than the debt-netted balance and the
                // platform ate the clawback. Lock the owner's un-netted applied 'debt'
                // chargebacks in this currency FOR UPDATE, subtract them, and (on a
                // successful reservation) stamp netted_at so the same debt can't re-subtract
                // forever (the other half of #17: netted_at was never set anywhere).
                $debtRows = [];
                $debtTotal = 0.0;
                try {
                    $dLock = $db->query(
                        "SELECT id, amount FROM supplier_chargebacks
                         WHERE owner_user_id = :o AND currency = :c AND state = 'applied'
                           AND mode = 'debt' AND netted_at IS NULL
                         ORDER BY id ASC FOR UPDATE",
                        [':o' => $owner, ':c' => $currency]
                    );
                    $debtRows = $dLock ? $dLock->fetchAll(\PDO::FETCH_ASSOC) : [];
                    foreach ($debtRows as $d) { $debtTotal = round($debtTotal + (float) $d['amount'], 2); }
                } catch (\Throwable $e) {
                    // Narrow-schema / table absent — no debt to net (parity with the advisory read).
                    $debtRows = []; $debtTotal = 0.0;
                }
                $netAvail = round($avail - $debtTotal, 2);
                if ($amount > $netAvail + 1e-9) {
                    $result = ['ok' => false, 'message' => 'Requested amount exceeds your available balance'
                        . ($debtTotal > 0 ? ' (after deducting ' . $currency . ' ' . number_format($debtTotal, 2) . ' in chargeback debt).' : '.')];
                    return false; // rollback
                }

                // Authoritative daily-cap enforcement INSIDE the transaction. Because both
                // concurrent requests hold the earnings row locks above, their cap reads
                // here are serialized — the second sees the first's just-inserted row and
                // is correctly rejected if it would breach the cap. (The precheck outside
                // is advisory UX only.) Counts today's non-terminal + paid payouts.
                if ($dailyCap > 0) {
                    $todayRows = $db->select('supplier_payouts', ['amount', 'created_at'],
                        ['owner_user_id' => $owner, 'currency' => $currency,
                         'state' => ['requested', 'approved', 'processing', 'awaiting_otp', 'paid']]) ?: [];
                    $usedToday = 0.0;
                    foreach ($todayRows as $tr) {
                        if (substr((string) ($tr['created_at'] ?? ''), 0, 10) === $today) {
                            $usedToday = round($usedToday + (float) $tr['amount'], 2);
                        }
                    }
                    if ($usedToday + $amount > $dailyCap + 1e-9) {
                        $remaining = max(0.0, round($dailyCap - $usedToday, 2));
                        $result = ['ok' => false, 'message' => 'Daily payout limit is ' . $currency . ' '
                            . number_format($dailyCap, 2) . '. You can still withdraw ' . $currency . ' '
                            . number_format($remaining, 2) . ' today.'];
                        return false; // rollback
                    }
                }

                // Create the payout row (requested). The destination + fee are snapshotted
                // here so later changes to the supplier's accounts never alter an in-flight
                // payout. Columns fee_amount/bank_name/bank_account_id/recipient_code are
                // ensured by supplier_payouts_ext.php (idempotent) and present in db.sql.
                $ref = supplier_payout_reference();
                $db->insert('supplier_payouts', [
                    'org_id'          => $orgId > 0 ? $orgId : null,
                    'owner_user_id'   => $owner,
                    'currency'        => $currency,
                    'amount'          => $amount,
                    'fee_amount'      => $fee,
                    'state'           => 'requested',
                    'bank_code'       => $bankCode,
                    'account_number'  => $acctNo,
                    'account_name'    => $acctName !== '' ? $acctName : null,
                    'bank_name'       => $bankName !== '' ? $bankName : null,
                    'bank_account_id' => $bankAcctId > 0 ? $bankAcctId : null,
                    'recipient_code'  => $recipient !== '' ? $recipient : null,
                    'reference'       => $ref,
                    'requested_at'    => date('Y-m-d H:i:s'),
                    'created_at'      => date('Y-m-d H:i:s'),
                ]);
                $payoutId = (int) $db->id();
                if ($payoutId <= 0) { $result = ['ok' => false, 'message' => 'Insert failed']; return false; }

                // RESERVE covering earnings (oldest first) up to EXACTLY the amount by
                // setting payout_id. The boundary row is SPLIT so no overshoot is reserved
                // (audit #6: reserving a whole row then flipping it 'paid' on settle used to
                // lose the supplier the remainder). They stay 'available' (reserved) until
                // the transfer succeeds. $rows are already locked FOR UPDATE above.
                if (!supplier_payout_reserve_rows($db, $payoutId, $rows, (float) $amount)) {
                    $result = ['ok' => false, 'message' => 'Could not reserve the earnings for this payout.'];
                    return false; // rollback
                }

                // CONSUME chargeback debt by actually VOIDING covering earnings (audit
                // N7/#17 fix). The earlier version merely stamped netted_at when the
                // remaining available dropped to <= the debt — but that remaining available
                // stayed 'available' and re-withdrawable, so once the debt was netted (and
                // thus no longer subtracted by supplier_payable_available) the supplier could
                // withdraw it anyway → the platform lost the full debt. Correct behaviour:
                // the debt is money the platform already paid out on a reversed booking; to
                // recover it we must permanently remove debt-worth of the supplier's
                // UNRESERVED available earnings (state → 'void'), then retire the debt. The
                // request was already capped at (avail − debt) above, so there is always at
                // least $debtTotal of unreserved available left to void here.
                if ($debtTotal > 0 && !empty($debtRows)) {
                    // Re-read the owner's still-unreserved available rows (excludes the ones
                    // we just reserved for THIS payout, which now carry payout_id). Oldest
                    // first; they are inside the same locked transaction.
                    $freeRows = $db->select('supplier_earnings', ['id', 'net_amount'],
                        ['owner_user_id' => $owner, 'currency' => $currency, 'state' => 'available',
                         'payout_id' => null, 'ORDER' => ['id' => 'ASC']]) ?: [];
                    $toVoid = $debtTotal;
                    $voided = 0.0;
                    foreach ($freeRows as $fr) {
                        if ($toVoid <= 1e-9) { break; }
                        $rid = (int) $fr['id'];
                        $rnet = round((float) $fr['net_amount'], 2);
                        if ($rnet <= 0) { continue; }
                        if ($rnet <= $toVoid + 1e-9) {
                            // Void the whole row as clawback.
                            $upd = $db->update('supplier_earnings',
                                ['state' => 'void', 'updated_at' => date('Y-m-d H:i:s')],
                                ['id' => $rid, 'state' => 'available', 'payout_id' => null]);
                            if ($upd && $upd->rowCount() > 0) { $voided = round($voided + $rnet, 2); $toVoid = round($toVoid - $rnet, 2); }
                        } else {
                            // Row larger than the remaining debt — split: shrink it to the
                            // residual the supplier keeps, and void a $toVoid clawback slice.
                            $residual = round($rnet - $toVoid, 2);
                            $shrunk = $db->update('supplier_earnings',
                                ['net_amount' => $residual, 'updated_at' => date('Y-m-d H:i:s')],
                                ['id' => $rid, 'state' => 'available', 'payout_id' => null]);
                            if ($shrunk && $shrunk->rowCount() > 0) {
                                $src = $db->get('supplier_earnings',
                                    ['org_id', 'owner_user_id', 'invoice_id', 'stay_id', 'currency', 'available_at'],
                                    ['id' => $rid]);
                                if (is_array($src)) {
                                    $db->insert('supplier_earnings', [
                                        'org_id' => $src['org_id'], 'owner_user_id' => $src['owner_user_id'],
                                        'invoice_id' => substr((string) $src['invoice_id'] . '#cbvoid' . (int) ($debtRows[0]['id'] ?? 0), 0, 255),
                                        'stay_id' => $src['stay_id'], 'currency' => $src['currency'],
                                        'gross_amount' => 0.00, 'commission_amount' => 0.00, 'net_amount' => $toVoid,
                                        'state' => 'void', 'payout_id' => null,
                                        'available_at' => $src['available_at'] ?: date('Y-m-d H:i:s'),
                                        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                                    ]);
                                    $voided = round($voided + $toVoid, 2); $toVoid = 0.0;
                                }
                            }
                        }
                    }
                    // Retire debt rows in order, up to what we actually voided — so a debt is
                    // netted ONLY to the extent its clawback earnings were truly removed. Any
                    // uncovered remainder keeps netted_at NULL and keeps capping future payouts.
                    $coverLeft = $voided;
                    foreach ($debtRows as $d) {
                        $damt = round((float) $d['amount'], 2);
                        if ($coverLeft + 1e-9 >= $damt) {
                            $db->update('supplier_chargebacks',
                                ['netted_at' => date('Y-m-d H:i:s')],
                                ['id' => (int) $d['id'], 'netted_at' => null]);
                            $coverLeft = round($coverLeft - $damt, 2);
                        } else {
                            break; // not enough voided to fully retire this (and later) debt rows
                        }
                    }
                }

                $result = ['ok' => true, 'payout_id' => $payoutId, 'message' => 'Payout requested.'];
                return true; // commit
            });
        } catch (\Throwable $e) {
            error_log('supplier_payout_request: ' . $e->getMessage());
        }
        return $result;
    }
}

if (!function_exists('supplier_payout_reserve_rows')) {
    /**
     * Reserve covering earnings for a payout up to EXACTLY $amount, SPLITTING the boundary
     * row so no overshoot is ever reserved (audit #6). $lockedRows are the owner's
     * 'available', payout_id=NULL rows, oldest first, ALREADY SELECT ... FOR UPDATE'd by
     * the caller inside its transaction (so this must run inside that same transaction).
     *
     * For each row until $amount is covered:
     *   - if the row fits within the remaining need → stamp payout_id on the whole row;
     *   - if the row OVERSHOOTS the remaining need → shrink it to exactly the need and
     *     stamp payout_id, then INSERT a residual 'available' row (payout_id NULL) for the
     *     leftover so the supplier keeps it. The residual carries a derived, unique
     *     invoice_id ("<invoice>#r<payoutId>") because supplier_earnings has
     *     UNIQUE(invoice_id); the RESERVED piece keeps the real invoice_id so the paid
     *     portion stays traceable and settle/void match it unchanged.
     *
     * Returns true iff the full $amount was reserved. Pure DB writes; no transaction of
     * its own (relies on the caller's).
     */
    function supplier_payout_reserve_rows($db, int $payoutId, array $lockedRows, float $amount): bool
    {
        $need = round($amount, 2);
        if ($need <= 0) { return false; }
        $now = date('Y-m-d H:i:s');
        foreach ($lockedRows as $r) {
            if ($need <= 1e-9) { break; }
            $rowId = (int) $r['id'];
            $rowNet = round((float) $r['net_amount'], 2);
            if ($rowNet <= 0) { continue; }
            if ($rowNet <= $need + 1e-9) {
                // Whole row fits — reserve it entirely.
                $db->update('supplier_earnings',
                    ['payout_id' => $payoutId, 'updated_at' => $now],
                    ['id' => $rowId, 'state' => 'available', 'payout_id' => null]);
                $need = round($need - $rowNet, 2);
            } else {
                // Row overshoots — split. Reserved piece = $need; residual = rowNet-$need.
                $residual = round($rowNet - $need, 2);
                // 1) shrink the original to exactly the needed amount + reserve it (state
                //    guard so a concurrent writer can't race the split).
                $shrunk = $db->update('supplier_earnings',
                    ['net_amount' => $need, 'payout_id' => $payoutId, 'updated_at' => $now],
                    ['id' => $rowId, 'state' => 'available', 'payout_id' => null]);
                if (!$shrunk || $shrunk->rowCount() < 1) {
                    // The row changed under us — bail; caller's txn will roll back.
                    return false;
                }
                // 2) insert the residual as a fresh 'available' row the supplier still owns.
                $src = $db->get('supplier_earnings',
                    ['org_id', 'owner_user_id', 'invoice_id', 'stay_id', 'currency',
                     'gross_amount', 'commission_amount', 'available_at'],
                    ['id' => $rowId]);
                if (is_array($src)) {
                    $db->insert('supplier_earnings', [
                        'org_id'            => $src['org_id'],
                        'owner_user_id'     => $src['owner_user_id'],
                        'invoice_id'        => substr((string) $src['invoice_id'] . '#r' . $payoutId, 0, 255),
                        'stay_id'           => $src['stay_id'],
                        'currency'          => $src['currency'],
                        'gross_amount'      => 0.00,     // financial split is on NET only;
                        'commission_amount' => 0.00,     // gross/commission stay on the paid piece
                        'net_amount'        => $residual,
                        'state'             => 'available',
                        'payout_id'         => null,
                        'available_at'      => $src['available_at'] ?: $now,
                        'created_at'        => $now,
                        'updated_at'        => $now,
                    ]);
                }
                $need = 0.0;
                break;
            }
        }
        return $need <= 1e-9;
    }
}

if (!function_exists('supplier_payout_release_reservation')) {
    /** Release a payout's reserved earnings (reject/fail/cancel): clear payout_id so
     *  the money becomes requestable again. Only affects still-'available' rows (a
     *  'paid' row is settled and must never be un-reserved here). */
    function supplier_payout_release_reservation($db, int $payoutId): void
    {
        if ($payoutId <= 0) { return; }
        try {
            $db->update('supplier_earnings',
                ['payout_id' => null, 'updated_at' => date('Y-m-d H:i:s')],
                ['payout_id' => $payoutId, 'state' => 'available']);
        } catch (\Throwable $e) {
            error_log('supplier_payout_release_reservation: ' . $e->getMessage());
        }
    }
}

if (!function_exists('supplier_payout_reserve_earnings')) {
    /**
     * Reserve covering earnings for a payout (used on a failed-retry, whose original
     * reservation was released). Locks the owner's unreserved 'available' earnings in
     * the currency FOR UPDATE, oldest first, and stamps payout_id up to $amount. Returns
     * true only if the full amount is covered; otherwise rolls back and returns false.
     * Atomic — mirrors the reservation logic in supplier_payout_request.
     */
    function supplier_payout_reserve_earnings($db, int $payoutId, string $owner, string $currency, float $amount): bool
    {
        if ($payoutId <= 0 || $owner === '' || $amount <= 0) { return false; }
        $ok = false;
        try {
            $db->action(function ($db) use ($payoutId, $owner, $currency, $amount, &$ok) {
                $locked = $db->query(
                    "SELECT id, net_amount FROM supplier_earnings
                     WHERE owner_user_id = :o AND currency = :c AND state = 'available'
                       AND payout_id IS NULL
                     ORDER BY id ASC FOR UPDATE",
                    [':o' => $owner, ':c' => $currency]
                );
                $rows = $locked ? $locked->fetchAll(\PDO::FETCH_ASSOC) : [];
                $avail = 0.0;
                foreach ($rows as $r) { $avail = round($avail + (float) $r['net_amount'], 2); }
                if ($amount > $avail + 1e-9) { $ok = false; return false; } // rollback — can't cover

                // Split-aware reservation (audit #6) — same no-overshoot logic as the
                // initial request, so a failed-retry re-reservation never loses the
                // remainder either. $rows are locked FOR UPDATE above.
                if (!supplier_payout_reserve_rows($db, $payoutId, $rows, (float) $amount)) {
                    $ok = false; return false; // rollback
                }
                $ok = true;
                return true;
            });
        } catch (\Throwable $e) { error_log('supplier_payout_reserve_earnings: ' . $e->getMessage()); return false; }
        return $ok;
    }
}

if (!function_exists('supplier_payout_resolve_stuck')) {
    /**
     * HIGH-3: resolve a payout frozen in 'processing' (crash/timeout between the
     * 'processing' flip and the transfer response) OR 'awaiting_otp' (an OTP-gated
     * transfer the admin abandoned, or whose OTP was completed/expired out of band).
     * Admin-invoked. Queries Paystack for the transfer by its reference and finalises
     * deterministically via the shared, atomic+idempotent finaliser:
     *   - transfer success/pending  -> debit, state 'paid'
     *   - transfer still 'otp'       -> (awaiting_otp only) leave it; OTP not submitted yet
     *   - transfer failed / not found -> state 'failed', release the reservation
     * Safe to call repeatedly. Returns ['ok','state','message'].
     */
    function supplier_payout_resolve_stuck($db, int $payoutId): array
    {
        $p = $db->get('supplier_payouts', '*', ['id' => $payoutId]);
        if (!$p) { return ['ok' => false, 'message' => 'Payout not found']; }
        $state = (string) $p['state'];
        if (!in_array($state, ['processing', 'awaiting_otp'], true)) {
            return ['ok' => false, 'message' => 'Only a stuck "processing" or "awaiting_otp" payout can be resolved (state: ' . $state . ').'];
        }
        $gw = function_exists('paystack_dva_gateway') ? paystack_dva_gateway($db) : null;
        $secret = $gw ? paystack_dva_secret($gw) : '';
        if ($secret === '') { return ['ok' => false, 'message' => 'Paystack gateway not configured; cannot verify.']; }

        // Verify by reference (Paystack: GET /transfer/verify/{reference}).
        $vres = paystack_dva_http('GET', 'https://api.paystack.co/transfer/verify/' . rawurlencode((string) $p['reference']), $secret, null);
        $status = strtolower((string) ($vres['json']['data']['status'] ?? ''));
        $httpOk = $vres['http'] >= 200 && $vres['http'] < 300;
        $transferCode = (string) ($vres['json']['data']['transfer_code'] ?? '');

        // Still awaiting OTP at Paystack (status 'otp') and our row is awaiting_otp:
        // the money has NOT moved — do nothing, the admin must submit the OTP.
        if ($httpOk && $status === 'otp' && $state === 'awaiting_otp') {
            return ['ok' => false, 'state' => 'awaiting_otp',
                'message' => 'Still awaiting OTP at Paystack — submit the OTP to complete this transfer.'];
        }

        // Accepted (success/pending) — finalise 'paid' via the shared atomic/idempotent
        // path. For 'processing' a Paystack 'otp' status is also treated as accepted
        // (legacy behaviour); for 'awaiting_otp' we already handled 'otp' above.
        $accepted = $state === 'processing'
            ? in_array($status, ['success', 'pending', 'otp'], true)
            : in_array($status, ['success', 'pending'], true);

        if ($httpOk && $accepted && function_exists('supplier_payout_finalize_transfer')) {
            $r = supplier_payout_finalize_transfer($db, $payoutId, 'success', $transferCode, 'resolved from ' . $state);
            return ['ok' => !empty($r['ok']), 'state' => $r['state'] ?? 'paid',
                'message' => 'Transfer confirmed with Paystack; marked paid.'];
        }

        // TRANSIENT-ERROR GUARD (audit #14): a non-2xx verify response (network blip,
        // Paystack 5xx/429) is NOT a definitive "transfer didn't happen". Failing the
        // payout + releasing the reservation here would strand a LIVE transfer (money
        // gone from the float, earnings freed and re-requestable). Only proceed to fail
        // on a DEFINITIVE negative answer (a 2xx response that reports failed/not-found).
        // On a transient error, leave the payout untouched and ask the admin to retry.
        if (!$httpOk) {
            return ['ok' => false, 'state' => $state,
                'message' => 'Could not reach Paystack to verify this transfer (transient error). It was NOT marked failed — please try again shortly.'];
        }

        // Not found / failed → it did not go through. Fail it and free the money, via the
        // shared finaliser (handles the paid-unwind edge + event + decided_by).
        if (function_exists('supplier_payout_finalize_transfer')) {
            $r = supplier_payout_finalize_transfer($db, $payoutId, 'failed', $transferCode, 'resolve: ' . ($status ?: 'not found'));
            return ['ok' => !empty($r['ok']), 'state' => 'failed',
                'message' => 'Transfer not confirmed; marked failed and released the reservation.'];
        }
        // Fallback (finaliser unavailable): original inline behaviour.
        $db->update('supplier_payouts',
            ['state' => 'failed', 'failure_reason' => substr('resolve: ' . ($status ?: 'not found'), 0, 255)],
            ['id' => $payoutId]);
        supplier_payout_release_reservation($db, $payoutId);
        return ['ok' => true, 'state' => 'failed', 'message' => 'Transfer not confirmed; marked failed and released the reservation.'];
    }
}

if (!function_exists('supplier_payout_reject')) {
    /** Admin rejects a requested/approved payout (before it is paid). Releases the
     *  reservation. Never affects a 'paid' payout. */
    function supplier_payout_reject($db, int $payoutId, string $reason = ''): bool
    {
        try {
            $p = $db->get('supplier_payouts', ['id', 'state'], ['id' => $payoutId]);
            // Never reject a payout whose transfer may be LIVE at the gateway:
            //  - 'processing' : transfer initiated, outcome pending (webhook will finalise)
            //  - 'awaiting_otp' (audit #13): transfer_code is live at Paystack awaiting OTP.
            //    Rejecting it here would release the earnings reservation while the transfer
            //    can still complete (OTP submitted/resent) → the money pays out with no
            //    backing (double-pay). It must be resolved via Paystack verify first
            //    (supplier_payout_resolve_stuck), which finalises paid or fails+releases.
            if (!$p || in_array($p['state'], ['paid', 'processing', 'awaiting_otp'], true)) { return false; }
            $db->update('supplier_payouts',
                ['state' => 'rejected', 'failure_reason' => substr($reason, 0, 255), 'decided_at' => date('Y-m-d H:i:s')],
                ['id' => $payoutId]);
            supplier_payout_release_reservation($db, $payoutId);
            return true;
        } catch (\Throwable $e) {
            error_log('supplier_payout_reject: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('supplier_payout_approve_and_send')) {
    /**
     * Admin approves a REQUESTED payout and (if the kill-switch is live) sends it via
     * Paystack Transfer. Returns ['ok'=>bool,'state'=>,'message'=>]. The money debit
     * (earnings 'available'->'paid') is only committed on a confirmed/queued transfer;
     * on failure the payout is 'failed' and the reservation released.
     *
     * Idempotency + no-double-send: a state guard ensures only a 'requested' (or a
     * retryable 'failed') payout proceeds; the unique `reference` is reused so Paystack
     * dedupes a retried transfer; 'processing'/'paid' are never re-sent.
     */
    function supplier_payout_approve_and_send($db, int $payoutId): array
    {
        $p = $db->get('supplier_payouts', '*', ['id' => $payoutId]);
        if (!$p) { return ['ok' => false, 'message' => 'Payout not found']; }
        $state = (string) $p['state'];
        if (!in_array($state, ['requested', 'failed'], true)) {
            return ['ok' => false, 'message' => 'Payout is not in a sendable state (' . $state . ').'];
        }

        // HIGH-2 (no-double-send): atomically claim the payout by flipping the sendable
        // state -> 'approved' and REQUIRING exactly one row changed. Two concurrent
        // approvals both read 'requested', but only one UPDATE matches; the loser aborts
        // here, before any money moves. (Previously the state read + approve were
        // unconditional, so both callers reached the transfer.)
        try {
            $claim = $db->update('supplier_payouts',
                ['state' => 'approved', 'decided_at' => date('Y-m-d H:i:s'), 'decided_by' => (string) ($_SESSION['user_id'] ?? '')],
                ['id' => $payoutId, 'state' => $state]);
            if (!$claim || $claim->rowCount() !== 1) {
                return ['ok' => false, 'message' => 'Payout was already being processed.'];
            }
        } catch (\Throwable $e) {
            error_log('supplier_payout claim: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not claim the payout for sending.'];
        }

        // HIGH-1 (failed-retry double-spend): a 'failed' payout already had its earnings
        // reservation RELEASED (payout_id cleared). Re-reserve covering earnings under
        // lock BEFORE sending, else a successful retry would debit zero rows — money
        // leaves the bank while the earnings stay 'available' and can be requested again.
        if ($state === 'failed') {
            $reserved = supplier_payout_reserve_earnings($db, $payoutId, (string) $p['owner_user_id'],
                (string) $p['currency'], (float) $p['amount']);
            if (!$reserved) {
                // Not enough available now (e.g. reserved by another request) — revert to
                // 'failed' and refuse, rather than send money we can't back with earnings.
                $db->update('supplier_payouts', ['state' => 'failed'], ['id' => $payoutId, 'state' => 'approved']);
                return ['ok' => false, 'message' => 'Cannot retry: the covering earnings are no longer available. Create a fresh payout request.'];
            }
        }

        // KILL-SWITCH: if payouts aren't enabled, stop here — approved but NOT sent.
        if (!supplier_payouts_live($db)) {
            return ['ok' => true, 'state' => 'approved', 'message' => 'Approved. Transfers are disabled (settings.supplier_payouts_live = 0) — nothing was sent.'];
        }

        // NON-NGN: there is NO automated cross-border disbursement rail in this install
        // (the only wired transfer provider is Paystack, which pays NGN only). We do NOT
        // treat a non-NGN payout as an error — the earnings reservation is already in
        // place, so we leave the payout 'approved' (recorded, reservation intact) and
        // tell the operator to disburse it out of band, then confirm via the admin
        // "mark manually paid" action (supplier_payout_finalize_transfer, currency-
        // agnostic). This is the honest end state: accept + record in-code; the real
        // foreign bank transfer is an external-credential step, never faked here.
        $currency = strtoupper((string) $p['currency']);
        if ($currency !== 'NGN') {
            return ['ok' => true, 'state' => 'approved', 'manual' => true,
                'message' => 'Approved and recorded in ' . $currency . '. No automated payout rail exists for ' . $currency
                    . ' — disburse it manually, then mark it paid.'];
        }

        // Resolve Paystack creds (NGN gateway). Only NGN transfers are supported here.
        $gw = function_exists('paystack_dva_gateway') ? paystack_dva_gateway($db) : null;
        $secret = $gw ? paystack_dva_secret($gw) : '';
        if ($secret === '') {
            return ['ok' => false, 'message' => 'Paystack NGN gateway not configured for payouts.'];
        }

        // 1) Ensure a transfer recipient. The recipient_code is snapshotted on the payout
        //    at request time (from the chosen bank account); if absent we create one and
        //    cache it back onto BOTH the payout and the originating bank account so future
        //    payouts to that account reuse it.
        $recipient = trim((string) ($p['recipient_code'] ?? ''));
        if ($recipient === '') {
            $rres = paystack_dva_http('POST', 'https://api.paystack.co/transferrecipient', $secret, [
                'type'           => 'nuban',
                'name'           => (string) ($p['account_name'] ?: $p['owner_user_id']),
                'account_number' => (string) $p['account_number'],
                'bank_code'      => (string) $p['bank_code'],
                'currency'       => 'NGN',
            ]);
            $recipient = (string) ($rres['json']['data']['recipient_code'] ?? '');
            if ($recipient === '') {
                $msg = $rres['json']['message'] ?? ($rres['error'] ?: 'Recipient creation failed');
                $db->update('supplier_payouts', ['failure_reason' => substr('recipient: ' . $msg, 0, 255)], ['id' => $payoutId]);
                return ['ok' => false, 'message' => 'Could not create transfer recipient: ' . $msg];
            }
            $db->update('supplier_payouts', ['recipient_code' => $recipient], ['id' => $payoutId]);
            // Cache on the originating bank account (if this payout came from one).
            $baId = (int) ($p['bank_account_id'] ?? 0);
            if ($baId > 0) {
                try {
                    $db->update('supplier_bank_accounts',
                        ['recipient_code' => $recipient, 'updated_at' => date('Y-m-d H:i:s')],
                        ['id' => $baId]);
                } catch (\Throwable $e) { error_log('payout recipient cache: ' . $e->getMessage()); }
            }
        }

        // 1b) FLOAT PREFLIGHT (PMS #40, guard #5): check the platform's Paystack NGN
        //     balance covers this transfer BEFORE initiating it. Without this, a
        //     transfer fired against insufficient platform float fails at Paystack,
        //     consuming the reference and leaving the payout to bounce to 'failed' and
        //     re-reserve — noisy and operationally confusing. If the balance can't be
        //     read we DO NOT block (fail-open on the check only: Paystack itself is the
        //     ultimate authority and will reject if truly short); we only hard-stop when
        //     we positively know the float is short. The payout stays 'approved' with
        //     its reservation intact.
        // NET to bank = gross reserved amount MINUS the fee the platform keeps (owner-
        // chosen: fee is DEDUCTED from the supplier). The reserved earnings stay at the
        // gross; the supplier's bank receives (amount - fee). fee_amount is a column on
        // the payout row (0 for legacy rows / when no fee is configured).
        $feeAmt = round(max(0.0, (float) ($p['fee_amount'] ?? 0)), 2);
        $gross  = round((float) $p['amount'], 2);
        if ($feeAmt > $gross) { $feeAmt = $gross; }
        $netAmount = round($gross - $feeAmt, 2);
        if ($netAmount <= 0) {
            // A fee that consumes the whole payout would mean sending zero — refuse rather
            // than fire a non-sensical transfer. Revert the claim so it can be fixed.
            $db->update('supplier_payouts',
                ['state' => $state, 'failure_reason' => 'fee >= amount (nothing to send)'],
                ['id' => $payoutId, 'state' => 'approved']);
            return ['ok' => false, 'message' => 'Payout not sent: the fee leaves nothing to transfer.'];
        }
        $kobo = (int) round($netAmount * 100);
        $balRes = paystack_dva_http('GET', 'https://api.paystack.co/balance', $secret, null);
        if (!empty($balRes['json']['data']) && is_array($balRes['json']['data'])) {
            $ngnAvail = null;
            foreach ($balRes['json']['data'] as $bEntry) {
                if (strtoupper((string) ($bEntry['currency'] ?? '')) === 'NGN') {
                    $ngnAvail = (int) ($bEntry['balance'] ?? 0); // kobo
                    break;
                }
            }
            if ($ngnAvail !== null && $ngnAvail < $kobo) {
                // Revert the claim so it can be retried once the float is topped up.
                $db->update('supplier_payouts', [
                    'state' => $state, // back to 'requested'/'failed'
                    'failure_reason' => substr('float preflight: platform NGN balance ' . number_format($ngnAvail / 100, 2) . ' < payout ' . number_format($kobo / 100, 2), 0, 255),
                ], ['id' => $payoutId, 'state' => 'approved']);
                return ['ok' => false, 'message' => 'Payout not sent: the platform Paystack balance is currently too low to cover this transfer. Top up the Paystack float and retry.'];
            }
        }

        // 2) Mark 'processing' BEFORE the transfer call (so a crash mid-call never
        //    leaves a 'requested' row that could be re-sent; recovery inspects
        //    'processing' rows against Paystack by reference).
        $db->update('supplier_payouts', ['state' => 'processing'], ['id' => $payoutId, 'state' => 'approved']);

        // 3) Initiate the transfer. amount in KOBO (integer, computed above) — never float.
        $tres = paystack_dva_http('POST', 'https://api.paystack.co/transfer', $secret, [
            'source'    => 'balance',
            'amount'    => $kobo,
            'recipient' => $recipient,
            'currency'  => 'NGN',
            'reason'    => 'Supplier payout ' . (string) $p['reference'],
            'reference' => (string) $p['reference'], // idempotency key (reused on retry)
        ]);
        $tstatus = strtolower((string) ($tres['json']['data']['status'] ?? ''));
        $transferCode = (string) ($tres['json']['data']['transfer_code'] ?? '');
        $httpOk = $tres['http'] >= 200 && $tres['http'] < 300;

        // Paystack transfer 'status':
        //   success | pending  = ACCEPTED — money is moving; finalised by the transfer
        //                        webhook (transfer.success/failed/reversed).
        //   otp                = NOT sent yet. The account has per-transfer OTP enabled;
        //                        the money does NOT move until we POST /transfer/finalize_transfer
        //                        with the OTP. We must NOT debit earnings or mark paid here.
        //   otherwise          = failed.
        if ($httpOk && $tstatus === 'otp') {
            // Park awaiting OTP: keep the reservation (earnings stay 'available', payout_id
            // set), store the transfer_code for finalize, and surface it to the admin to
            // enter the OTP. NO earnings debit — the transfer has not happened.
            $db->update('supplier_payouts', [
                'state'         => 'awaiting_otp',
                'transfer_code' => $transferCode ?: null,
                'failure_reason'=> null,
            ], ['id' => $payoutId, 'state' => 'processing']);
            return ['ok' => true, 'state' => 'awaiting_otp',
                'message' => 'Paystack requires an OTP to authorise this transfer. Enter the OTP sent to the business phone to complete it.'];
        }

        if ($httpOk && in_array($tstatus, ['success', 'pending'], true)) {
            // COMMIT atomically: reserved earnings 'available' -> 'paid' AND payout 'paid'
            // in one transaction, so a crash can't debit earnings without finalising the
            // payout (or vice-versa).
            // Medoo::action() returns void — it commits unless the callback returns false
            // or throws. Track commit via a reference set as the LAST statement inside the
            // callback: if any update throws, the exception propagates (rollback) and
            // $committed stays false; if it runs to the end, it is set true then committed.
            $committed = false;
            try {
                $db->action(function ($db) use ($payoutId, $transferCode, &$committed) {
                    $db->update('supplier_earnings',
                        ['state' => 'paid', 'updated_at' => date('Y-m-d H:i:s')],
                        ['payout_id' => $payoutId, 'state' => 'available']);
                    $db->update('supplier_payouts', [
                        'state'         => 'paid',
                        'transfer_code' => $transferCode ?: null,
                        'paid_at'       => date('Y-m-d H:i:s'),
                    ], ['id' => $payoutId, 'state' => 'processing']);
                    $committed = true; // reached the end without throwing → will commit
                    return true;
                });
            } catch (\Throwable $e) { error_log('payout debit: ' . $e->getMessage()); $committed = false; }

            // DO NOT report 'paid' if the DB transaction rolled back (audit #15). The money
            // DID leave Paystack (success response), but our books didn't update — the
            // payout is in limbo. Leave it 'processing' so the transfer webhook / an admin
            // Resolve reconciles it against Paystack, rather than claiming paid on books
            // that never committed (which would re-free the earnings on the next read).
            if (!$committed) {
                // Ensure the row at least carries the transfer_code for later reconciliation.
                try { $db->update('supplier_payouts', ['transfer_code' => $transferCode ?: null], ['id' => $payoutId, 'state' => 'processing']); } catch (\Throwable $e) {}
                return ['ok' => false, 'state' => 'processing',
                    'message' => 'Transfer was initiated at Paystack but the local settlement did not commit. Left as processing — use Resolve to reconcile.'];
            }

            if (function_exists('emit_event')) {
                emit_event($db, 'payout.paid', ['payout_id' => $payoutId, 'amount' => (float) $p['amount'], 'currency' => $p['currency']],
                    'supplier_payout', $payoutId, $p['org_id'] !== null ? (int) $p['org_id'] : null);
            }
            return ['ok' => true, 'state' => 'paid', 'message' => 'Transfer initiated.'];
        }

        // Failed → mark failed + release the reservation so the money is requestable again.
        $msg = $tres['json']['message'] ?? ($tres['error'] ?: 'Transfer failed');
        $db->update('supplier_payouts',
            ['state' => 'failed', 'failure_reason' => substr((string) $msg, 0, 255)],
            ['id' => $payoutId]);
        supplier_payout_release_reservation($db, $payoutId);
        return ['ok' => false, 'state' => 'failed', 'message' => 'Transfer failed: ' . $msg];
    }
}

if (!function_exists('supplier_payout_submit_otp')) {
    /**
     * Complete a transfer that is parked in 'awaiting_otp' by POSTing the OTP to
     * Paystack's /transfer/finalize_transfer. Admin-invoked with the OTP the business
     * received on its phone. On a confirmed transfer the earnings are debited and the
     * payout finalised 'paid' (via supplier_payout_finalize_transfer, atomic + idempotent).
     *
     * Only acts on an 'awaiting_otp' payout; any other state is refused. On a bad/expired
     * OTP the payout STAYS 'awaiting_otp' (the admin can retry or resend) — we never fail
     * it on a wrong OTP, because the transfer is still pending at Paystack.
     * Returns ['ok'=>bool,'state'=>,'message'=>].
     */
    function supplier_payout_submit_otp($db, int $payoutId, string $otp): array
    {
        $otp = trim($otp);
        $p = $db->get('supplier_payouts', '*', ['id' => $payoutId]);
        if (!$p) { return ['ok' => false, 'message' => 'Payout not found']; }
        if ((string) $p['state'] !== 'awaiting_otp') {
            return ['ok' => false, 'message' => 'This payout is not awaiting an OTP (state: ' . $p['state'] . ').'];
        }
        $transferCode = trim((string) ($p['transfer_code'] ?? ''));
        if ($transferCode === '') {
            return ['ok' => false, 'message' => 'No transfer code on this payout; cannot finalise. Reject and re-request.'];
        }
        if ($otp === '') { return ['ok' => false, 'message' => 'Enter the OTP.']; }

        $gw = function_exists('paystack_dva_gateway') ? paystack_dva_gateway($db) : null;
        $secret = $gw ? paystack_dva_secret($gw) : '';
        if ($secret === '') { return ['ok' => false, 'message' => 'Paystack gateway not configured.']; }

        $fres = paystack_dva_http('POST', 'https://api.paystack.co/transfer/finalize_transfer', $secret, [
            'transfer_code' => $transferCode,
            'otp'           => $otp,
        ]);
        $httpOk = ($fres['http'] ?? 0) >= 200 && ($fres['http'] ?? 0) < 300;
        $fstatus = strtolower((string) ($fres['json']['data']['status'] ?? ''));

        if ($httpOk && in_array($fstatus, ['success', 'pending'], true)) {
            // Transfer authorised — finalise exactly like the webhook would (atomic debit
            // + payout 'paid', idempotent). We move it to 'processing' first so the shared
            // finaliser (which expects processing/approved) applies cleanly.
            $db->update('supplier_payouts', ['state' => 'processing'], ['id' => $payoutId, 'state' => 'awaiting_otp']);
            if (function_exists('supplier_payout_finalize_transfer')) {
                $r = supplier_payout_finalize_transfer($db, $payoutId, 'success', $transferCode, 'otp finalised');
                return ['ok' => !empty($r['ok']), 'state' => $r['state'] ?? 'paid',
                        'message' => 'OTP accepted — transfer authorised and marked paid.'];
            }
            return ['ok' => true, 'state' => 'processing', 'message' => 'OTP accepted; awaiting confirmation.'];
        }

        // Bad/expired OTP, or Paystack declined: stay 'awaiting_otp' for a retry/resend.
        $msg = $fres['json']['message'] ?? ($fres['error'] ?: 'OTP not accepted');
        return ['ok' => false, 'state' => 'awaiting_otp', 'message' => 'OTP not accepted: ' . $msg];
    }
}

if (!function_exists('supplier_payout_resend_otp')) {
    /**
     * Ask Paystack to resend the transfer OTP for a payout parked in 'awaiting_otp'
     * (POST /transfer/resend_otp with the transfer_code). Admin-invoked. Does not change
     * payout state. Returns ['ok'=>bool,'message'=>].
     */
    function supplier_payout_resend_otp($db, int $payoutId): array
    {
        $p = $db->get('supplier_payouts', ['id', 'state', 'transfer_code'], ['id' => $payoutId]);
        if (!$p) { return ['ok' => false, 'message' => 'Payout not found']; }
        if ((string) $p['state'] !== 'awaiting_otp') {
            return ['ok' => false, 'message' => 'Not awaiting an OTP.'];
        }
        $transferCode = trim((string) ($p['transfer_code'] ?? ''));
        if ($transferCode === '') { return ['ok' => false, 'message' => 'No transfer code on this payout.']; }

        $gw = function_exists('paystack_dva_gateway') ? paystack_dva_gateway($db) : null;
        $secret = $gw ? paystack_dva_secret($gw) : '';
        if ($secret === '') { return ['ok' => false, 'message' => 'Paystack gateway not configured.']; }

        $res = paystack_dva_http('POST', 'https://api.paystack.co/transfer/resend_otp', $secret, [
            'transfer_code' => $transferCode,
            'reason'        => 'resend_otp',
        ]);
        $httpOk = ($res['http'] ?? 0) >= 200 && ($res['http'] ?? 0) < 300;
        $msg = $res['json']['message'] ?? ($res['error'] ?: ($httpOk ? 'OTP resent.' : 'Could not resend OTP.'));
        return ['ok' => $httpOk, 'message' => $msg];
    }
}

if (!function_exists('supplier_payout_finalize_transfer')) {
    /**
     * Apply an AUTHORITATIVE transfer outcome from Paystack (webhook event or a verify
     * lookup) to a payout, idempotently and state-guarded. $outcome is one of:
     *   'success'  -> debit reserved earnings 'available'->'paid', payout 'paid'
     *   'failed'   -> payout 'failed', release the reservation (money is requestable again)
     *   'reversed' -> a previously-accepted transfer bounced back: payout 'failed',
     *                 and if it had already been debited to 'paid', the paid earnings are
     *                 returned to 'available' (reservation released) so the money is not lost.
     *
     * Matching is by the payout row (already resolved by the caller, usually via
     * `reference`). Safe to call repeatedly — a payout already in the target terminal
     * state is a no-op. Returns ['ok'=>bool,'state'=>,'changed'=>bool,'message'=>].
     *
     * WHY this exists: approve_and_send flips pending->paid on the SYNCHRONOUS response,
     * but Paystack's authoritative result arrives by webhook. A later 'failed'/'reversed'
     * must be able to correct an optimistic 'paid', and a 'success' must finalise a
     * payout still sitting in 'processing' (e.g. the sync response was 'pending').
     */
    function supplier_payout_finalize_transfer($db, int $payoutId, string $outcome, string $transferCode = '', string $note = ''): array
    {
        $outcome = strtolower(trim($outcome));
        $p = $db->get('supplier_payouts', '*', ['id' => $payoutId]);
        if (!$p) { return ['ok' => false, 'changed' => false, 'message' => 'Payout not found']; }
        $state = (string) $p['state'];
        $tc = $transferCode !== '' ? $transferCode : (string) ($p['transfer_code'] ?? '');

        if ($outcome === 'success') {
            if ($state === 'paid') {
                return ['ok' => true, 'state' => 'paid', 'changed' => false, 'message' => 'Already paid.'];
            }
            // 'awaiting_otp' IS finalisable on success: an OTP-gated transfer can be
            // confirmed by the webhook (transfer.success) even if the admin hasn't typed
            // the OTP yet — the money moved at Paystack, so we MUST settle, not strand it.
            if (!in_array($state, ['processing', 'approved', 'awaiting_otp'], true)) {
                // 'failed'/'rejected'/'requested' shouldn't receive a success; don't force it.
                return ['ok' => false, 'state' => $state, 'changed' => false, 'message' => 'Not in a finalisable state (' . $state . ').'];
            }
            // Atomic: debit the reserved earnings AND mark the payout paid in one
            // transaction, so a crash between them can't leave earnings paid with the
            // payout unfinalised (or vice-versa). State-guarded on both sides.
            // (audit #15) Track commit via a reference so we never report 'paid' on a
            // rolled-back transaction — leave it unchanged for the caller/webhook to retry.
            $committed = false;
            try {
                $db->action(function ($db) use ($payoutId, $tc, $state, &$committed) {
                    $db->update('supplier_earnings',
                        ['state' => 'paid', 'updated_at' => date('Y-m-d H:i:s')],
                        ['payout_id' => $payoutId, 'state' => 'available']);
                    $db->update('supplier_payouts',
                        ['state' => 'paid', 'transfer_code' => $tc ?: null, 'paid_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
                        ['id' => $payoutId, 'state' => $state]); // guard: only from the state we read
                    $committed = true;
                    return true;
                });
            } catch (\Throwable $e) { error_log('finalize debit: ' . $e->getMessage()); $committed = false; }
            if (!$committed) {
                return ['ok' => false, 'state' => $state, 'changed' => false,
                    'message' => 'Settlement did not commit; left unchanged for retry.'];
            }
            if (function_exists('emit_event')) {
                emit_event($db, 'payout.paid', ['payout_id' => $payoutId, 'amount' => (float) $p['amount'], 'currency' => $p['currency']],
                    'supplier_payout', $payoutId, $p['org_id'] !== null ? (int) $p['org_id'] : null);
            }
            return ['ok' => true, 'state' => 'paid', 'changed' => true, 'message' => 'Transfer confirmed; marked paid.'];
        }

        if ($outcome === 'failed' || $outcome === 'reversed') {
            if (in_array($state, ['failed', 'rejected', 'cancelled'], true)) {
                return ['ok' => true, 'state' => $state, 'changed' => false, 'message' => 'Already terminal.'];
            }
            // A 'failed' outcome must NOT reverse an already-PAID payout (audit M8). Paystack
            // does not emit transfer.failed after a success; a 'failed' arriving on a 'paid'
            // payout is a duplicate/out-of-order/replayed event. Reversing it would flip the
            // settled earnings 'paid'->'available' and make the already-disbursed money
            // re-withdrawable (double-pay). Only a genuine transfer.REVERSED legitimately
            // un-settles a paid payout; a 'failed' on a paid payout is ignored (no-op).
            if ($outcome === 'failed' && $state === 'paid') {
                error_log('finalize: ignoring transfer.failed on an already-paid payout #' . $payoutId . ' (M8 guard)');
                return ['ok' => true, 'state' => 'paid', 'changed' => false, 'message' => 'Already paid; failed event ignored.'];
            }
            $reason = $outcome === 'reversed' ? 'transfer reversed' : 'transfer failed';
            if ($note !== '') { $reason .= ': ' . $note; }
            // Atomic: if it had been (optimistically or really) debited to 'paid', return
            // those earnings 'paid'->'available' so the money is not lost, AND mark the
            // payout failed — in one transaction. decided_by records the webhook as the
            // actor for the audit trail. The payout UPDATE is STATE-GUARDED (only flips a
            // non-terminal/paid row, and only from the state we read) so a racing/stale event
            // can't transition an already-terminal payout. Then release the reservation.
            $didChange = false;
            try {
                $db->action(function ($db) use ($payoutId, $state, $tc, $reason, &$didChange) {
                    if ($state === 'paid') {
                        $db->update('supplier_earnings',
                            ['state' => 'available', 'updated_at' => date('Y-m-d H:i:s')],
                            ['payout_id' => $payoutId, 'state' => 'paid']);
                    }
                    $upd = $db->update('supplier_payouts',
                        ['state' => 'failed', 'transfer_code' => $tc ?: null,
                         'failure_reason' => substr($reason, 0, 255),
                         'decided_by' => 'paystack_webhook', 'updated_at' => date('Y-m-d H:i:s')],
                        ['id' => $payoutId, 'state' => $state]); // guard: only from the state we read
                    $didChange = $upd && $upd->rowCount() > 0;
                });
            } catch (\Throwable $e) { error_log('finalize fail/reverse: ' . $e->getMessage()); }
            if (!$didChange) {
                // Another finalizer moved the payout first — don't release/notify on a stale race.
                return ['ok' => true, 'state' => $state, 'changed' => false, 'message' => 'Payout already resolved by another process.'];
            }
            supplier_payout_release_reservation($db, $payoutId);
            // Notify so a failed/reversed payout is never silent (supplier + admin ops).
            if (function_exists('emit_event')) {
                emit_event($db, 'payout.' . $outcome,
                    ['payout_id' => $payoutId, 'amount' => (float) $p['amount'], 'currency' => $p['currency'], 'reason' => $reason],
                    'supplier_payout', $payoutId, $p['org_id'] !== null ? (int) $p['org_id'] : null);
            }
            return ['ok' => true, 'state' => 'failed', 'changed' => true, 'message' => ucfirst($reason) . '; reservation released.'];
        }

        return ['ok' => false, 'state' => $state, 'changed' => false, 'message' => 'Unknown outcome: ' . $outcome];
    }
}

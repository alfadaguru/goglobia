<?php
// FILE: app/lib/supplier_settlement_ext.php
// GOGLOBIA EARNINGS & SETTLEMENT — deepen lib (PMS catalogue §2 #40). Builds the
// genuine gaps ON TOP of the existing settlement spine:
//   base lib: app/lib/supplier_earnings.php  (accrue/release/void/summary)
//             app/lib/supplier_payouts.php   (request/approve/send, float preflight)
// This ext ONLY adds surfaces the base does not cover. It NEVER re-implements the
// accrual, reservation or transfer logic — it reuses those functions directly.
//
// Gaps built here (all additive, all if_not_exists-guarded):
//   1. Payout destination verification — resolve the owner's bank account name with
//      the gateway (DB-GATED: needs a configured Paystack NGN gateway) and persist a
//      verification record. If no gateway is configured this HONESTLY reports
//      'not connected' and verifies nothing — never a faked pass.
//   2. Payout statements — a period statement (opening/closing, releases in, payouts
//      out, chargebacks) for one owner+currency, read-only, CSV-exportable.
//   3. Release-due cron surface + run log — thin wrapper around the existing
//      supplier_earning_release_due() that records each run for an ops audit trail.
//   4. Chargeback deduction (clawback) — the base deliberately defers clawing back a
//      *paid* earning as "a separate, manual process". This builds that record: an
//      admin/owner-initiated chargeback that reverses the supplier's share of a
//      disputed booking. Reuses gl_post() for a BALANCED GL reversal when the owner
//      has books; never posts an unbalanced entry and never trusts a client amount.
//   5. Marketplace-fee report — the commission the PLATFORM kept (gross - net) per
//      owner+period, straight from the existing supplier_earnings columns.
//
// MONEY SAFETY: every clawback runs inside $db->action() with a FOR UPDATE lock on
// the target earning; amounts are the server-stored net/commission, never the client.
// All reads are owner-scoped. DB-GATED items are labelled; nothing fakes a gateway.

if (!function_exists('settlement_ext_tables_ready')) {
    /**
     * Idempotent SHOW-COLUMNS / existence probe for the two NEW tables this ext needs:
     * supplier_chargebacks and supplier_release_runs. We do NOT create them here (the
     * lead owns schema) — this only tells a route whether the feature is DB-ready so it
     * can degrade honestly instead of fatally. DB-GATED.
     */
    function settlement_ext_tables_ready($db): bool
    {
        try {
            $db->query('SELECT 1 FROM supplier_chargebacks LIMIT 1')->fetch();
            $db->query('SELECT 1 FROM supplier_release_runs LIMIT 1')->fetch();
            return true;
        } catch (\Throwable $e) {
            return false; // table(s) not migrated yet
        }
    }
}

// ============================================================================
// 1. PAYOUT DESTINATION VERIFICATION  (DB-GATED — needs live Paystack gateway)
// ============================================================================
if (!function_exists('supplier_payout_destination')) {
    /**
     * The owner's saved payout destination + its verification state, read-only:
     *   ['bank_code'=>, 'account_number'=>, 'account_name'=>, 'recipient_code'=>,
     *    'verified'=>bool, 'verified_name'=>?, 'has_bank'=>bool]
     * 'verified' is true only when a prior gateway resolve matched the saved account.
     */
    function supplier_payout_destination($db, string $owner): array
    {
        $owner = trim($owner);
        $out = ['bank_code' => '', 'account_number' => '', 'account_name' => '',
                'recipient_code' => '', 'verified' => false, 'verified_name' => null, 'has_bank' => false];
        if ($owner === '') { return $out; }
        try {
            $u = $db->get('users',
                ['payout_bank_code', 'payout_account_number', 'payout_account_name',
                 'payout_recipient_code', 'payout_account_verified', 'payout_verified_name'],
                ['user_id' => $owner]);
            if ($u) {
                $out['bank_code']      = trim((string) ($u['payout_bank_code'] ?? ''));
                $out['account_number'] = trim((string) ($u['payout_account_number'] ?? ''));
                $out['account_name']   = trim((string) ($u['payout_account_name'] ?? ''));
                $out['recipient_code'] = trim((string) ($u['payout_recipient_code'] ?? ''));
                $out['verified']       = (string) ($u['payout_account_verified'] ?? '0') === '1';
                $out['verified_name']  = $u['payout_verified_name'] ?? null;
                $out['has_bank']       = $out['bank_code'] !== '' && $out['account_number'] !== '';
            }
        } catch (\Throwable $e) {
            // payout_account_verified / payout_verified_name columns may not be migrated
            // yet — fall back to the base columns only (feature degrades, never fatals).
            try {
                $u = $db->get('users',
                    ['payout_bank_code', 'payout_account_number', 'payout_account_name', 'payout_recipient_code'],
                    ['user_id' => $owner]);
                if ($u) {
                    $out['bank_code']      = trim((string) ($u['payout_bank_code'] ?? ''));
                    $out['account_number'] = trim((string) ($u['payout_account_number'] ?? ''));
                    $out['account_name']   = trim((string) ($u['payout_account_name'] ?? ''));
                    $out['recipient_code'] = trim((string) ($u['payout_recipient_code'] ?? ''));
                    $out['has_bank']       = $out['bank_code'] !== '' && $out['account_number'] !== '';
                }
            } catch (\Throwable $ignore) {}
        }
        return $out;
    }
}

if (!function_exists('supplier_payout_verify_destination')) {
    /**
     * Verify the owner's saved bank account with the gateway (Paystack
     * GET /bank/resolve?account_number=&bank_code=). On a match, persist
     * payout_account_verified='1' + payout_verified_name = the gateway-returned name.
     *
     * DB-GATED / HONEST STUB: if no Paystack NGN gateway is configured this returns
     *   ['ok'=>false, 'connected'=>false, 'message'=>'Bank verification is not connected…']
     * and changes NOTHING. It never fabricates a verified state.
     *
     * Returns ['ok'=>bool,'connected'=>bool,'matched'=>bool,'resolved_name'=>?,'message'=>].
     */
    function supplier_payout_verify_destination($db, string $owner): array
    {
        $owner = trim($owner);
        if ($owner === '') { return ['ok' => false, 'connected' => true, 'message' => 'No owner']; }

        $dest = supplier_payout_destination($db, $owner);
        if (!$dest['has_bank']) {
            return ['ok' => false, 'connected' => true, 'message' => 'Save your bank details before verifying.'];
        }

        // Gateway gate — honest "not connected" when Paystack is absent.
        $gw = function_exists('paystack_dva_gateway') ? paystack_dva_gateway($db) : null;
        $secret = $gw ? paystack_dva_secret($gw) : '';
        if ($secret === '' || !function_exists('paystack_dva_http')) {
            return ['ok' => false, 'connected' => false,
                    'message' => 'Bank verification is not connected (no Paystack gateway configured). Your details are saved; verification will run once the gateway is live.'];
        }

        $url = 'https://api.paystack.co/bank/resolve?account_number='
             . rawurlencode($dest['account_number']) . '&bank_code=' . rawurlencode($dest['bank_code']);
        $res = paystack_dva_http('GET', $url, $secret, null);
        $httpOk = ($res['http'] ?? 0) >= 200 && ($res['http'] ?? 0) < 300;
        $resolvedName = trim((string) ($res['json']['data']['account_name'] ?? ''));

        if (!$httpOk || $resolvedName === '') {
            $msg = $res['json']['message'] ?? ($res['error'] ?? 'Could not resolve the account.');
            return ['ok' => false, 'connected' => true, 'matched' => false,
                    'message' => 'Bank verification failed: ' . $msg];
        }

        // Persist the verified name. We store the gateway's authoritative name and flag
        // verified; the owner's typed account_name is advisory only (the gateway wins).
        try {
            $db->update('users',
                ['payout_account_verified' => '1', 'payout_verified_name' => substr($resolvedName, 0, 191)],
                ['user_id' => $owner]);
        } catch (\Throwable $e) {
            error_log('supplier_payout_verify_destination persist: ' . $e->getMessage());
            return ['ok' => false, 'connected' => true, 'matched' => true, 'resolved_name' => $resolvedName,
                    'message' => 'Account resolved to "' . $resolvedName . '" but could not be saved (DB not migrated).'];
        }
        return ['ok' => true, 'connected' => true, 'matched' => true, 'resolved_name' => $resolvedName,
                'message' => 'Account verified: ' . $resolvedName];
    }
}

// ============================================================================
// 2. PAYOUT STATEMENTS  (read-only period statement for one owner+currency)
// ============================================================================
if (!function_exists('supplier_settlement_statement')) {
    /**
     * Build a period statement for one owner+currency from the existing earnings +
     * payouts + chargebacks tables. Pure read; never mutates.
     *   $from,$to : 'Y-m-d' inclusive window (on the earning/payout timestamps).
     * Returns:
     *   ['currency'=>, 'from'=>, 'to'=>,
     *    'released'  => float,  // earnings that became available in-window (net)
     *    'paid_out'  => float,  // payouts marked paid in-window
     *    'charged_back'=>float, // chargebacks applied in-window (reduces net)
     *    'gross'     => float,  // gross booking value accrued in-window
     *    'commission'=> float,  // platform fee kept in-window
     *    'lines'     => [ ['date','type','reference','amount','note'] … ] ]
     */
    function supplier_settlement_statement($db, string $owner, string $currency, string $from, string $to): array
    {
        $owner = trim($owner);
        $currency = strtoupper(trim($currency)) ?: 'USD';
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : date('Y-m-01');
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : date('Y-m-d');
        $fromDt = $from . ' 00:00:00';
        $toDt   = $to . ' 23:59:59';

        $out = ['currency' => $currency, 'from' => $from, 'to' => $to,
                'released' => 0.0, 'paid_out' => 0.0, 'payout_fees' => 0.0, 'charged_back' => 0.0,
                'gross' => 0.0, 'commission' => 0.0, 'lines' => []];
        if ($owner === '') { return $out; }

        try {
            // Releases in-window: earnings that became 'available' (available_at in range),
            // plus gross/commission accrued in-window for the fee figures.
            $earn = $db->select('supplier_earnings',
                ['invoice_id', 'gross_amount', 'commission_amount', 'net_amount', 'state', 'available_at', 'created_at'],
                ['owner_user_id' => $owner, 'currency' => $currency, 'state[!]' => 'void',
                 'ORDER' => ['id' => 'ASC']]) ?: [];
            foreach ($earn as $e) {
                $createdIn = ((string) $e['created_at'] >= $fromDt && (string) $e['created_at'] <= $toDt);
                if ($createdIn) {
                    $out['gross']      = round($out['gross'] + (float) $e['gross_amount'], 2);
                    $out['commission'] = round($out['commission'] + (float) $e['commission_amount'], 2);
                }
                $availAt = (string) ($e['available_at'] ?? '');
                if ($availAt !== '' && $availAt >= $fromDt && $availAt <= $toDt) {
                    $out['released'] = round($out['released'] + (float) $e['net_amount'], 2);
                    $out['lines'][] = ['date' => substr($availAt, 0, 10), 'type' => 'release',
                        'reference' => (string) $e['invoice_id'], 'amount' => (float) $e['net_amount'],
                        'note' => 'Earning cleared'];
                }
            }
            // Payouts marked paid in-window. The payout 'amount' is the GROSS debited from
            // the supplier's balance; the bank receives amount - fee (the platform keeps
            // the fee). We surface both so the statement reconciles against the bank deposit
            // (previously the fee was invisible and looked like a shortfall). fee_amount may
            // be absent on legacy rows — tolerate it.
            $payCols = ['reference', 'amount', 'paid_at'];
            try {
                if ($db->query("SHOW COLUMNS FROM `supplier_payouts` LIKE 'fee_amount'")->fetch()) {
                    $payCols[] = 'fee_amount';
                }
            } catch (\Throwable $ignore) {}
            $pays = $db->select('supplier_payouts', $payCols,
                ['owner_user_id' => $owner, 'currency' => $currency, 'state' => 'paid',
                 'paid_at[<>]' => [$fromDt, $toDt], 'ORDER' => ['id' => 'ASC']]) ?: [];
            foreach ($pays as $p) {
                $gross = (float) $p['amount'];
                $fee   = round((float) ($p['fee_amount'] ?? 0), 2);
                $net   = round($gross - $fee, 2);
                $out['paid_out'] = round($out['paid_out'] + $gross, 2);
                $out['payout_fees'] = round($out['payout_fees'] + $fee, 2);
                $note = $fee > 0
                    ? ('Paid to bank ' . $currency . ' ' . number_format($net, 2) . ' (fee ' . $currency . ' ' . number_format($fee, 2) . ')')
                    : 'Paid to bank';
                $out['lines'][] = ['date' => substr((string) $p['paid_at'], 0, 10), 'type' => 'payout',
                    'reference' => (string) $p['reference'], 'amount' => -1 * $gross,
                    'fee' => $fee, 'net' => $net, 'note' => $note];
            }
            // Chargebacks applied in-window (new table; tolerate absence).
            try {
                $cbs = $db->select('supplier_chargebacks',
                    ['reference', 'invoice_id', 'amount', 'applied_at', 'reason'],
                    ['owner_user_id' => $owner, 'currency' => $currency, 'state' => 'applied',
                     'applied_at[<>]' => [$fromDt, $toDt], 'ORDER' => ['id' => 'ASC']]) ?: [];
                foreach ($cbs as $c) {
                    $out['charged_back'] = round($out['charged_back'] + (float) $c['amount'], 2);
                    $out['lines'][] = ['date' => substr((string) $c['applied_at'], 0, 10), 'type' => 'chargeback',
                        'reference' => (string) ($c['reference'] ?: $c['invoice_id']), 'amount' => -1 * (float) $c['amount'],
                        'note' => substr((string) ($c['reason'] ?? 'Chargeback'), 0, 80)];
                }
            } catch (\Throwable $ignore) {}

            usort($out['lines'], fn($a, $b) => strcmp((string) $a['date'], (string) $b['date']));
        } catch (\Throwable $e) {
            error_log('supplier_settlement_statement: ' . $e->getMessage());
        }
        return $out;
    }
}

// ============================================================================
// 3. RELEASE-DUE CRON SURFACE + RUN LOG
// ============================================================================
if (!function_exists('supplier_settlement_run_release')) {
    /**
     * Thin, loggable wrapper around the EXISTING supplier_earning_release_due(). The base
     * function already does the real (state-guarded, double-release-safe) work; this only
     * invokes it and records a run row for the ops audit trail (supplier_release_runs).
     * Safe to call from cron or the admin surface. Returns ['released'=>int,'logged'=>bool].
     */
    function supplier_settlement_run_release($db, int $limit = 500, string $trigger = 'cron'): array
    {
        $released = function_exists('supplier_earning_release_due')
            ? (int) supplier_earning_release_due($db, $limit) : 0;
        $logged = false;
        try {
            $db->insert('supplier_release_runs', [
                'released_count' => $released,
                'trigger_source' => substr($trigger, 0, 20),
                'run_by'         => substr((string) ($_SESSION['user_id'] ?? 'system'), 0, 155),
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
            $logged = true;
        } catch (\Throwable $e) {
            // Run log table not migrated — the release itself still happened; log only.
            error_log('supplier_settlement_run_release log: ' . $e->getMessage());
        }
        return ['released' => $released, 'logged' => $logged];
    }
}

if (!function_exists('supplier_settlement_recent_runs')) {
    /** Last N release-run log rows (read-only, for the ops surface). */
    function supplier_settlement_recent_runs($db, int $limit = 20): array
    {
        try {
            return $db->select('supplier_release_runs',
                ['id', 'released_count', 'trigger_source', 'run_by', 'created_at'],
                ['ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}

// ============================================================================
// 4. CHARGEBACK DEDUCTION  (clawback of a settled/available earning)
// ============================================================================
if (!function_exists('supplier_chargeback_reference')) {
    /** Unique idempotency key for a chargeback deduction. */
    function supplier_chargeback_reference(): string
    {
        return 'SCB-' . date('Ymd') . '-' . bin2hex(random_bytes(6));
    }
}

if (!function_exists('supplier_chargeback_apply')) {
    /**
     * Apply a chargeback deduction against one booking's supplier earning. The base
     * (supplier_earnings.php) explicitly DEFERS clawing back a 'paid' earning as "a
     * separate, manual process" — this is that process, done safely.
     *
     * Model:
     *   - Target the earning by invoice_id, locked FOR UPDATE inside $db->action().
     *   - If the earning is still 'pending'/'available' AND unreserved (payout_id NULL):
     *       we VOID it (mirrors the booking-cancel path) — the money never leaves.
     *   - If it is already 'paid' (the supplier was settled) OR reserved for a payout:
     *       we record a chargeback DEBT row (state='applied') that reduces the owner's
     *       future statement. We do NOT retro-edit a settled/paid earning or a reserved
     *       one (that would corrupt a pending payout's debit) — the debt is carried
     *       forward and nets against the next statement/payout window.
     *   - When the owner has GL books, post a BALANCED reversal via gl_post() (reduce
     *     revenue, raise a chargeback-clearing liability). Never unbalanced; if the GL
     *     post fails the chargeback record still stands (ops can repost manually).
     *
     * Amount is ALWAYS the server-stored earning net_amount — the client cannot set it.
     * Idempotent on (invoice_id): a second apply is a no-op returning the existing row.
     *
     * Returns ['ok'=>bool,'chargeback_id'=>?int,'mode'=>'voided'|'debt','message'=>].
     */
    function supplier_chargeback_apply($db, string $invoiceId, string $reason = '', string $actor = ''): array
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') { return ['ok' => false, 'message' => 'No invoice']; }
        $reason = substr(trim($reason), 0, 255);
        $actor = substr(trim($actor) ?: (string) ($_SESSION['user_id'] ?? ''), 0, 155);

        $result = ['ok' => false, 'message' => 'Chargeback failed'];
        try {
            $db->action(function ($db) use ($invoiceId, $reason, $actor, &$result) {
                // Lock the earning for this invoice.
                $locked = $db->query(
                    'SELECT id, owner_user_id, org_id, stay_id, currency, net_amount, commission_amount,
                            gross_amount, state, payout_id
                     FROM supplier_earnings WHERE invoice_id = :inv FOR UPDATE',
                    [':inv' => $invoiceId]);
                $e = $locked ? $locked->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$e) { $result = ['ok' => false, 'message' => 'No earning for this booking']; return false; }

                // Idempotency: existing applied chargeback for this invoice → no-op.
                $prior = $db->get('supplier_chargebacks', ['id', 'state'], ['invoice_id' => $invoiceId]);
                if ($prior && (string) $prior['state'] === 'applied') {
                    $result = ['ok' => true, 'chargeback_id' => (int) $prior['id'], 'mode' => 'debt',
                               'already' => true, 'message' => 'Chargeback already applied.'];
                    return true;
                }

                $owner    = (string) $e['owner_user_id'];
                $orgId    = ($e['org_id'] !== null) ? (int) $e['org_id'] : null;
                $currency = strtoupper((string) $e['currency']);
                $net      = round((float) $e['net_amount'], 2);      // server-stored, never client
                $state    = (string) $e['state'];
                $reserved = ($e['payout_id'] !== null);

                // Decide the mode.
                $canVoidNow = in_array($state, ['pending', 'available'], true) && !$reserved;
                $mode = $canVoidNow ? 'voided' : 'debt';

                if ($canVoidNow) {
                    // Money never left — void the earning (state-guarded, unreserved only).
                    $db->update('supplier_earnings',
                        ['state' => 'void', 'updated_at' => date('Y-m-d H:i:s')],
                        ['id' => (int) $e['id'], 'state' => ['pending', 'available'], 'payout_id' => null]);
                }

                $ref = supplier_chargeback_reference();
                $db->insert('supplier_chargebacks', [
                    'org_id'        => $orgId,
                    'owner_user_id' => $owner,
                    'invoice_id'    => $invoiceId,
                    'earning_id'    => (int) $e['id'],
                    'currency'      => $currency,
                    'amount'        => $net,
                    'mode'          => $mode,      // 'voided' = reversed before settlement; 'debt' = carried
                    'state'         => 'applied',
                    'reason'        => $reason ?: null,
                    'reference'     => $ref,
                    'applied_by'    => $actor ?: null,
                    'applied_at'    => date('Y-m-d H:i:s'),
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
                $cbId = (int) $db->id();
                if ($cbId <= 0) { $result = ['ok' => false, 'message' => 'Could not record chargeback']; return false; }

                $result = ['ok' => true, 'chargeback_id' => $cbId, 'mode' => $mode,
                           'owner' => $owner, 'org_id' => $orgId, 'currency' => $currency, 'amount' => $net,
                           'message' => $mode === 'voided'
                               ? 'Chargeback applied: the unsettled earning was reversed.'
                               : 'Chargeback recorded as a debt against future settlements (earning already settled/reserved).'];
                return true;
            });
        } catch (\Throwable $ex) {
            error_log('supplier_chargeback_apply (' . $invoiceId . '): ' . $ex->getMessage());
            return ['ok' => false, 'message' => 'Chargeback error'];
        }

        // Optional BALANCED GL reversal (outside the earnings txn; the chargeback record is
        // authoritative, the GL entry is a bookkeeping mirror). Only when the owner has an
        // org with books and gl_post is available. Never posts unbalanced.
        if (!empty($result['ok']) && !empty($result['org_id']) && function_exists('gl_post')) {
            $orgId = (int) $result['org_id'];
            $amt   = round((float) $result['amount'], 2);
            if ($orgId > 0 && $amt > 0) {
                try {
                    // Reverse recognised room revenue (debit 4000) and raise a clearing
                    // liability (credit 2000 Accounts Payable — the default COA has no
                    // dedicated chargeback account; the memo marks it). Balanced by design.
                    $post = gl_post($db, $orgId, [
                        ['code' => '4000', 'debit'  => $amt, 'memo' => 'Chargeback reversal ' . $invoiceId],
                        ['code' => '2000', 'credit' => $amt, 'memo' => 'Chargeback payable ' . $invoiceId],
                    ], [
                        'source' => 'chargeback', 'reference' => (string) $result['chargeback_id'],
                        'memo' => 'Supplier chargeback ' . $invoiceId, 'currency' => $result['currency'],
                    ]);
                    $result['gl'] = !empty($post['ok']);
                    if (!empty($post['ok'])) {
                        // Record that the GL reversal posted + its entry id on the
                        // chargeback row (audit #10), so an un-posted reversal is
                        // detectable/reconcilable instead of silently diverging. We do
                        // NOT roll the chargeback back on a GL failure — the chargeback
                        // record is authoritative; a transient GL error must not block a
                        // legitimate clawback. A reconcile job can repost where gl_entry_id
                        // is NULL.
                        try {
                            $glId = (int) ($post['entry_id'] ?? $post['id'] ?? 0);
                            $db->update('supplier_chargebacks',
                                ['gl_entry_id' => $glId > 0 ? $glId : null],
                                ['id' => (int) $result['chargeback_id']]);
                        } catch (\Throwable $e) { error_log('chargeback gl_entry_id persist: ' . $e->getMessage()); }
                    } else {
                        $result['gl_message'] = $post['message'] ?? 'GL not posted';
                        error_log('supplier_chargeback_apply GL not posted (' . $invoiceId . '): ' . ($post['message'] ?? '?') . ' — chargeback stands, gl_entry_id left NULL for reconcile');
                    }
                } catch (\Throwable $ex) {
                    error_log('supplier_chargeback_apply GL: ' . $ex->getMessage());
                    $result['gl'] = false;
                }
            }
        }
        return $result;
    }
}

if (!function_exists('supplier_chargebacks_for_owner')) {
    /** Read-only recent chargebacks for an owner (owner statement + admin surface). */
    function supplier_chargebacks_for_owner($db, string $owner, int $limit = 50): array
    {
        $owner = trim($owner);
        if ($owner === '') { return []; }
        try {
            return $db->select('supplier_chargebacks',
                ['id', 'invoice_id', 'currency', 'amount', 'mode', 'state', 'reason', 'reference', 'applied_at'],
                ['owner_user_id' => $owner, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}

// ============================================================================
// 5. MARKETPLACE-FEE REPORT  (platform commission kept, per owner+period)
// ============================================================================
if (!function_exists('supplier_marketplace_fee_report')) {
    /**
     * The commission the PLATFORM kept from an owner's bookings in a window, per currency.
     * Straight from the existing supplier_earnings columns (gross/commission/net), so it
     * is consistent with what the supplier was actually paid. Read-only.
     *   Returns [ currency => ['gross'=>, 'commission'=>, 'net'=>, 'bookings'=>int,
     *                          'take_rate'=>float] ]  (take_rate = commission/gross %).
     * Window is on created_at (accrual date). Excludes 'void' earnings.
     */
    function supplier_marketplace_fee_report($db, string $owner, string $from, string $to): array
    {
        $owner = trim($owner);
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : date('Y-m-01');
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : date('Y-m-d');
        $out = [];
        if ($owner === '') { return $out; }
        try {
            $rows = $db->select('supplier_earnings',
                ['currency', 'gross_amount', 'commission_amount', 'net_amount'],
                ['owner_user_id' => $owner, 'state[!]' => 'void',
                 'created_at[<>]' => [$from . ' 00:00:00', $to . ' 23:59:59']]) ?: [];
            foreach ($rows as $r) {
                $cur = strtoupper((string) ($r['currency'] ?? 'USD'));
                if (!isset($out[$cur])) { $out[$cur] = ['gross' => 0.0, 'commission' => 0.0, 'net' => 0.0, 'bookings' => 0, 'take_rate' => 0.0]; }
                $out[$cur]['gross']      += (float) $r['gross_amount'];
                $out[$cur]['commission'] += (float) $r['commission_amount'];
                $out[$cur]['net']        += (float) $r['net_amount'];
                $out[$cur]['bookings']++;
            }
            foreach ($out as $cur => $v) {
                $out[$cur]['gross']      = round($v['gross'], 2);
                $out[$cur]['commission'] = round($v['commission'], 2);
                $out[$cur]['net']        = round($v['net'], 2);
                $out[$cur]['take_rate']  = $v['gross'] > 0 ? round(($v['commission'] / $v['gross']) * 100, 2) : 0.0;
            }
        } catch (\Throwable $e) {
            error_log('supplier_marketplace_fee_report: ' . $e->getMessage());
        }
        return $out;
    }
}

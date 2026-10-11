<?php
// FILE: app/lib/supplier_lease_ext.php
// MODULE 49 DEEPEN — Lease & long-stay (billing lifecycle on top of the base register).
//
// The BASE already ships (do NOT rebuild) in app/lib/supplier_lease.php:
//   · lease_add / lease_list / lease_set_status  — the tenancy register
//   · lease_cycles / lease_statuses               — enums
//   · utility_* (#51)                             — meter readings
// and in the finance spine:
//   · gl_post()        (supplier_ledger.php) — the BALANCED double-entry poster
//   · chart of accounts 1000 Cash / 1100 Guest-Ledger-AR / 4000 Room-Revenue / 4900 Other
//
// This file builds the genuine lease BILLING gaps on NEW tables only, property-scoped
// (the CALLER authorizes the stay via supplier_can). Everything is org-scoped for the GL.
//
//   · ESCALATION — rent_escalate(): pure helper applying a per-anniversary % (or fixed)
//     bump to a base rent for a given period index. Harness-friendly, no DB.
//   · BILLING SCHEDULE — lease_ext_due_periods(): given a lease, compute the rent periods
//     (period start/end + due date + escalated amount) from start_date to today (or term
//     end). Pure projection; used to decide which periods still need a charge raised.
//   · RENT CHARGE run — lease_ext_raise_charge(): raise ONE period's rent charge:
//       (1) insert an idempotent charge row (UNIQUE lease+period_start), and
//       (2) post a BALANCED GL entry  Dr 1100 Guest-Ledger-AR / Cr 4000 Room-Revenue.
//     Wrapped in ONE $db->action(); the charge amount is SERVER-computed from the lease
//     (escalation applied) — NEVER taken from the client.
//   · ARREARS / LATE FEE — lease_ext_outstanding(): sum unpaid charges + days overdue;
//     lease_ext_apply_late_fee(): raise a late-fee charge (Dr 1100 / Cr 4900 Other-Revenue),
//     amount = server policy (% of arrears or flat), clamped, atomic + idempotent per period.
//   · RENT RECEIPT — lease_ext_receive(): record a tenant payment, allocate FIFO across
//     open charges, post Dr 1000 Cash / Cr 1100 AR. Atomic, FOR-UPDATE lock on the lease
//     row, never over-applies beyond the outstanding balance.
//   · INSPECTIONS — lease_ext_inspection_add()/list(): move-in / move-out / periodic
//     condition inspections with a JSON checklist + deposit-deduction note (no auto money;
//     a deduction that must hit the ledger is raised as an explicit charge by staff).
//   · TENANT STATEMENT — lease_ext_statement(): the full charge+receipt ledger for ONE
//     lease with a running balance (what the base called "tenant statements").
//
// Money rules honoured: client amounts are NEVER trusted for rent/late-fee (server
// recomputes from the lease + policy); receipts are clamped to the outstanding balance;
// GL is NEVER posted unbalanced (gl_post refuses it); multi-write money ops run inside
// one $db->action() with a FOR UPDATE lock so a half-apply / double-click can't persist.
// Charge + late-fee rows are idempotent (UNIQUE key) so a re-run can't double-bill.
// All functions are if_not_exists-guarded and defensive/non-fatal.
// NOT runtime-verified (no MySQL in the build env) — logic-reasoned + php -l + harness.

// ---------------------------------------------------------------------------
// Internal: resolve the org that owns a lease (for GL posting + IDOR re-checks).
// ---------------------------------------------------------------------------
if (!function_exists('lease_ext_row')) {
    /** Fetch one lease row scoped to a set of stay_ids (0/empty guard). null if not found. */
    function lease_ext_row($db, int $leaseId, array $stayIds): ?array
    {
        if ($leaseId <= 0 || empty($stayIds)) { return null; }
        try {
            $r = $db->get('stays_leases', '*', ['id' => $leaseId, 'stay_id' => $stayIds]);
            return $r ?: null;
        } catch (\Throwable $e) { error_log('lease_ext_row: ' . $e->getMessage()); return null; }
    }
}
if (!function_exists('lease_ext_org')) {
    /** Org that owns a lease's property (0 on miss). Prefers the lease's stored org_id. */
    function lease_ext_org($db, array $lease): int
    {
        $org = (int) ($lease['org_id'] ?? 0);
        if ($org > 0) { return $org; }
        $stayId = (int) ($lease['stay_id'] ?? 0);
        return ($stayId > 0 && function_exists('supplier_property_org')) ? (int) supplier_property_org($db, $stayId) : 0;
    }
}

// ---------------------------------------------------------------------------
// ESCALATION — pure helper (harness-friendly, no DB).
// ---------------------------------------------------------------------------
if (!function_exists('lease_ext_escalation_types')) {
    function lease_ext_escalation_types(): array { return ['none' => 'None', 'percent' => 'Percent / period', 'fixed' => 'Fixed amount / period']; }
}
if (!function_exists('rent_escalate')) {
    /**
     * Rent for a given zero-based period index after applying escalation.
     *   type 'none'    → base, always.
     *   type 'percent' → base * (1 + pct/100)^index   (compounding per escalation step)
     *   type 'fixed'   → base + (step * index)
     * $everyN = how many billing periods between escalation steps (default 12 = yearly on
     * a monthly cycle). index is floored by everyN so rent only steps on anniversaries.
     * Pure, rounded to 2dp, never below 0. $index/$everyN guarded to >= 0 / >= 1.
     */
    function rent_escalate(float $base, string $type, float $amount, int $index, int $everyN = 12): float
    {
        $base = max(0.0, $base);
        $index = max(0, $index);
        $everyN = max(1, $everyN);
        $steps = intdiv($index, $everyN);
        if ($steps <= 0 || $type === 'none' || $amount <= 0) { return round($base, 2); }
        if ($type === 'percent') {
            $rent = $base * pow(1 + ($amount / 100), $steps);
        } elseif ($type === 'fixed') {
            $rent = $base + ($amount * $steps);
        } else {
            $rent = $base;
        }
        return round(max(0.0, $rent), 2);
    }
}

// ---------------------------------------------------------------------------
// BILLING SCHEDULE — project the rent periods a lease should be billed for.
// ---------------------------------------------------------------------------
if (!function_exists('lease_ext_cycle_step')) {
    /** DateInterval modify-string for a rent cycle, and the escalation everyN default. */
    function lease_ext_cycle_step(string $cycle): array
    {
        switch ($cycle) {
            case 'weekly':    return ['+1 week',    52]; // yearly escalation ~ every 52 wk
            case 'quarterly': return ['+3 months',  4];
            case 'yearly':    return ['+1 year',    1];
            case 'monthly':
            default:          return ['+1 month',   12];
        }
    }
}
if (!function_exists('lease_ext_due_periods')) {
    /**
     * Compute the billing periods for a lease from start_date up to min(end_date, today),
     * inclusive of the period currently running. Each period:
     *   ['index','period_start','period_end','due_date','amount'] (amount = escalated rent).
     * Due date = period_start (rent due in advance — the usual long-stay convention).
     * Pure projection (no DB); a safety cap of 600 periods prevents runaway loops on
     * bad dates. Returns [] on a missing/invalid start_date.
     */
    function lease_ext_due_periods(array $lease, ?string $asOf = null): array
    {
        $start = (string) ($lease['start_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) { return []; }
        $cycle = (string) ($lease['rent_cycle'] ?? 'monthly');
        [$step, $everyN] = lease_ext_cycle_step($cycle);
        $base = max(0.0, (float) ($lease['rent_amount'] ?? 0));
        $escType = (string) ($lease['escalation_type'] ?? 'none');
        $escAmt  = (float) ($lease['escalation_amount'] ?? 0);
        $escEvery = (int) ($lease['escalation_every'] ?? 0); if ($escEvery <= 0) { $escEvery = $everyN; }

        $asOf = ($asOf !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) ? $asOf : date('Y-m-d');
        $end = (string) ($lease['end_date'] ?? '');
        $hardEnd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) ? min($end, $asOf) : $asOf;

        $periods = [];
        try {
            $cursor = new \DateTimeImmutable($start);
            $limit  = new \DateTimeImmutable($hardEnd);
            $i = 0;
            while ($cursor <= $limit && $i < 600) {
                $next = $cursor->modify($step);
                $periods[] = [
                    'index'        => $i,
                    'period_start' => $cursor->format('Y-m-d'),
                    'period_end'   => $next->modify('-1 day')->format('Y-m-d'),
                    'due_date'     => $cursor->format('Y-m-d'),
                    'amount'       => rent_escalate($base, $escType, $escAmt, $i, $escEvery),
                ];
                $cursor = $next;
                $i++;
            }
        } catch (\Throwable $e) { error_log('lease_ext_due_periods: ' . $e->getMessage()); return []; }
        return $periods;
    }
}

// ---------------------------------------------------------------------------
// CHARGE LEDGER — read helpers.
// ---------------------------------------------------------------------------
if (!function_exists('lease_ext_charge_kinds')) {
    function lease_ext_charge_kinds(): array { return ['rent' => 'Rent', 'late_fee' => 'Late fee', 'deposit' => 'Deposit', 'adjustment' => 'Adjustment']; }
}
if (!function_exists('lease_ext_charges')) {
    /** All charge rows for a lease, oldest-first (for FIFO allocation + statement). */
    function lease_ext_charges($db, int $leaseId): array
    {
        if ($leaseId <= 0) { return []; }
        try { return $db->select('stays_lease_charges', '*', ['lease_id' => $leaseId, 'ORDER' => ['due_date' => 'ASC', 'id' => 'ASC']]) ?: []; }
        catch (\Throwable $e) { error_log('lease_ext_charges: ' . $e->getMessage()); return []; }
    }
}
if (!function_exists('lease_ext_receipts')) {
    /** All receipt rows for a lease, oldest-first. */
    function lease_ext_receipts($db, int $leaseId): array
    {
        if ($leaseId <= 0) { return []; }
        try { return $db->select('stays_lease_receipts', '*', ['lease_id' => $leaseId, 'ORDER' => ['received_at' => 'ASC', 'id' => 'ASC']]) ?: []; }
        catch (\Throwable $e) { error_log('lease_ext_receipts: ' . $e->getMessage()); return []; }
    }
}
if (!function_exists('lease_ext_outstanding')) {
    /**
     * Outstanding position for a lease: total charged, total paid (allocated),
     * balance, oldest overdue due_date and worst days-overdue. Read-only.
     *   ['charged','paid','balance','overdue','oldest_due','days_overdue']
     * 'paid' per charge = min(amount, amount_paid). balance = sum(amount - amount_paid).
     */
    function lease_ext_outstanding($db, int $leaseId, ?string $asOf = null): array
    {
        $out = ['charged' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'overdue' => 0.0, 'oldest_due' => null, 'days_overdue' => 0];
        $charges = lease_ext_charges($db, $leaseId);
        $asOf = ($asOf !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) ? $asOf : date('Y-m-d');
        $today = strtotime($asOf);
        foreach ($charges as $c) {
            $amt  = round((float) $c['amount'], 2);
            $paid = round((float) ($c['amount_paid'] ?? 0), 2);
            $rem  = max(0.0, round($amt - $paid, 2));
            $out['charged'] = round($out['charged'] + $amt, 2);
            $out['paid']    = round($out['paid'] + min($amt, $paid), 2);
            $out['balance'] = round($out['balance'] + $rem, 2);
            if ($rem > 0 && !empty($c['due_date'])) {
                $due = strtotime((string) $c['due_date']);
                if ($due !== false && $due < $today) {
                    $out['overdue'] = round($out['overdue'] + $rem, 2);
                    if ($out['oldest_due'] === null || (string) $c['due_date'] < $out['oldest_due']) {
                        $out['oldest_due'] = (string) $c['due_date'];
                    }
                }
            }
        }
        if ($out['oldest_due'] !== null) {
            $d = strtotime($out['oldest_due']);
            if ($d !== false) { $out['days_overdue'] = max(0, (int) floor(($today - $d) / 86400)); }
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// RAISE A RENT CHARGE   [MONEY — DB-GATED]
// ---------------------------------------------------------------------------
if (!function_exists('lease_ext_raise_charge')) {
    /**
     * Raise ONE period's rent charge for a lease and post it to the GL. The amount is
     * SERVER-computed (escalated rent for that period) — the only client input is which
     * period_start to bill, and that must match a valid projected period. Idempotent:
     * the UNIQUE(lease_id, kind, period_start) key means a re-run returns the existing
     * charge instead of double-billing. Atomic ($db->action): charge row + GL entry.
     *   GL:  Dr 1100 Guest-Ledger-AR   Cr 4000 Room-Revenue
     * Returns ['ok'=>bool,'charge_id'=>?int,'amount'=>float,'duplicate'=>bool,'message'=>].
     */
    function lease_ext_raise_charge($db, int $orgId, array $lease, string $periodStart): array
    {
        $leaseId = (int) ($lease['id'] ?? 0);
        if ($orgId <= 0 || $leaseId <= 0) { return ['ok' => false, 'message' => 'Missing org/lease']; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart)) { return ['ok' => false, 'message' => 'Invalid period']; }

        // Server recomputes the amount for THIS period from the lease schedule.
        $periods = lease_ext_due_periods($lease);
        $match = null;
        foreach ($periods as $p) { if ($p['period_start'] === $periodStart) { $match = $p; break; } }
        if ($match === null) { return ['ok' => false, 'message' => 'That period is not yet due for this lease']; }
        $amount = round((float) $match['amount'], 2);
        if ($amount <= 0) { return ['ok' => false, 'message' => 'Rent for this period is zero']; }

        $currency = strtoupper(substr((string) ($lease['currency'] ?? 'USD'), 0, 3));
        $result = ['ok' => false, 'message' => 'Charge failed'];
        try {
            $db->action(function ($db) use ($orgId, $leaseId, $amount, $match, $currency, &$result) {
                // Idempotency guard inside the txn.
                if ($db->has('stays_lease_charges', ['lease_id' => $leaseId, 'kind' => 'rent', 'period_start' => $match['period_start']])) {
                    $ex = $db->get('stays_lease_charges', ['id'], ['lease_id' => $leaseId, 'kind' => 'rent', 'period_start' => $match['period_start']]);
                    $result = ['ok' => true, 'duplicate' => true, 'charge_id' => (int) ($ex['id'] ?? 0), 'amount' => $amount, 'message' => 'Already billed'];
                    return true;
                }
                $db->insert('stays_lease_charges', [
                    'org_id'       => $orgId,
                    'lease_id'     => $leaseId,
                    'kind'         => 'rent',
                    'period_start' => $match['period_start'],
                    'period_end'   => $match['period_end'],
                    'due_date'     => $match['due_date'],
                    'amount'       => $amount,
                    'amount_paid'  => 0,
                    'currency'     => $currency,
                    'description'  => 'Rent ' . $match['period_start'] . ' – ' . $match['period_end'],
                    'gl_entry_id'  => null,
                    'created_at'   => date('Y-m-d H:i:s'),
                ]);
                $chargeId = (int) $db->id();
                if ($chargeId <= 0) { $result = ['ok' => false, 'message' => 'Charge insert failed']; return false; }

                $entryId = null;
                if (function_exists('gl_post')) {
                    $gl = gl_post($db, $orgId, [
                        ['code' => '1100', 'debit'  => $amount], // Guest Ledger (AR)
                        ['code' => '4000', 'credit' => $amount], // Room Revenue
                    ], [
                        'source'    => 'lease_rent',
                        'reference' => 'lease:' . $leaseId . ':rent:' . $match['period_start'],
                        'memo'      => 'Lease rent ' . $match['period_start'],
                        'currency'  => $currency,
                    ]);
                    if (empty($gl['ok'])) { $result = ['ok' => false, 'message' => $gl['message'] ?? 'GL post failed']; return false; }
                    $entryId = (int) ($gl['entry_id'] ?? 0);
                    $db->update('stays_lease_charges', ['gl_entry_id' => $entryId], ['id' => $chargeId]);
                }
                $result = ['ok' => true, 'duplicate' => false, 'charge_id' => $chargeId, 'amount' => $amount, 'entry_id' => $entryId, 'message' => 'Rent charged'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('lease_ext_raise_charge: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Charge error'];
        }
        return $result;
    }
}
if (!function_exists('lease_ext_run_billing')) {
    /**
     * Raise charges for EVERY due period of a lease that has not yet been billed (a
     * catch-up run). Relies on lease_ext_raise_charge's idempotency, so running it twice
     * is safe. Returns ['raised'=>int,'skipped'=>int,'amount'=>float,'errors'=>[...]].
     */
    function lease_ext_run_billing($db, int $orgId, array $lease): array
    {
        $r = ['raised' => 0, 'skipped' => 0, 'amount' => 0.0, 'errors' => []];
        if ((string) ($lease['status'] ?? '') !== 'active') { $r['errors'][] = 'Lease not active'; return $r; }
        foreach (lease_ext_due_periods($lease) as $p) {
            $res = lease_ext_raise_charge($db, $orgId, $lease, $p['period_start']);
            if (!empty($res['ok']) && empty($res['duplicate'])) { $r['raised']++; $r['amount'] = round($r['amount'] + (float) ($res['amount'] ?? 0), 2); }
            elseif (!empty($res['duplicate'])) { $r['skipped']++; }
            else { $r['errors'][] = (string) ($res['message'] ?? 'error'); }
        }
        return $r;
    }
}

// ---------------------------------------------------------------------------
// LATE FEE   [MONEY — DB-GATED]
// ---------------------------------------------------------------------------
if (!function_exists('lease_ext_late_fee_amount')) {
    /**
     * Policy-driven late fee: pure helper. mode 'percent' → arrears * pct/100 (rounded);
     * mode 'fixed' → flat. Clamped to >= 0 and to a sane ceiling of the arrears itself for
     * percent, and never charged when arrears <= 0. Harness-friendly.
     */
    function lease_ext_late_fee_amount(float $arrears, string $mode, float $value): float
    {
        $arrears = round(max(0.0, $arrears), 2);
        if ($arrears <= 0 || $value <= 0) { return 0.0; }
        if ($mode === 'percent') { return round(min($arrears, $arrears * ($value / 100)), 2); }
        if ($mode === 'fixed')   { return round($value, 2); }
        return 0.0;
    }
}
if (!function_exists('lease_ext_apply_late_fee')) {
    /**
     * Raise a late-fee charge for the CURRENT arrears, posting Dr 1100 AR / Cr 4900 Other.
     * The fee is SERVER-computed from the lease's late-fee policy (stored on the lease)
     * and the live arrears — the client cannot set the amount. Idempotent per calendar
     * day (UNIQUE lease+kind+period_start where period_start = today) so you can't stack
     * multiple late fees in one day by double-clicking. Atomic. Only fires when the lease
     * is actually in arrears beyond its grace days.
     * Returns ['ok'=>bool,'charge_id'=>?int,'amount'=>float,'message'=>].
     */
    function lease_ext_apply_late_fee($db, int $orgId, array $lease): array
    {
        $leaseId = (int) ($lease['id'] ?? 0);
        if ($orgId <= 0 || $leaseId <= 0) { return ['ok' => false, 'message' => 'Missing org/lease']; }
        $mode  = in_array(($lease['late_fee_mode'] ?? 'none'), ['percent', 'fixed'], true) ? (string) $lease['late_fee_mode'] : 'none';
        $value = (float) ($lease['late_fee_value'] ?? 0);
        $grace = max(0, (int) ($lease['grace_days'] ?? 0));
        if ($mode === 'none' || $value <= 0) { return ['ok' => false, 'message' => 'No late-fee policy on this lease']; }

        $pos = lease_ext_outstanding($db, $leaseId);
        if ((float) $pos['overdue'] <= 0 || (int) $pos['days_overdue'] <= $grace) {
            return ['ok' => false, 'message' => 'Not in arrears beyond grace period'];
        }
        $fee = lease_ext_late_fee_amount((float) $pos['overdue'], $mode, $value);
        if ($fee <= 0) { return ['ok' => false, 'message' => 'Computed fee is zero']; }

        $today = date('Y-m-d');
        $currency = strtoupper(substr((string) ($lease['currency'] ?? 'USD'), 0, 3));
        $result = ['ok' => false, 'message' => 'Late fee failed'];
        try {
            $db->action(function ($db) use ($orgId, $leaseId, $fee, $today, $currency, $pos, &$result) {
                if ($db->has('stays_lease_charges', ['lease_id' => $leaseId, 'kind' => 'late_fee', 'period_start' => $today])) {
                    $result = ['ok' => false, 'message' => 'A late fee was already raised today'];
                    return false;
                }
                $db->insert('stays_lease_charges', [
                    'org_id'       => $orgId,
                    'lease_id'     => $leaseId,
                    'kind'         => 'late_fee',
                    'period_start' => $today,
                    'period_end'   => $today,
                    'due_date'     => $today,
                    'amount'       => $fee,
                    'amount_paid'  => 0,
                    'currency'     => $currency,
                    'description'  => 'Late fee on arrears ' . number_format((float) $pos['overdue'], 2),
                    'gl_entry_id'  => null,
                    'created_at'   => date('Y-m-d H:i:s'),
                ]);
                $chargeId = (int) $db->id();
                if ($chargeId <= 0) { $result = ['ok' => false, 'message' => 'Fee insert failed']; return false; }

                $entryId = null;
                if (function_exists('gl_post')) {
                    $gl = gl_post($db, $orgId, [
                        ['code' => '1100', 'debit'  => $fee], // Guest Ledger (AR)
                        ['code' => '4900', 'credit' => $fee], // Other Revenue
                    ], [
                        'source'    => 'lease_late_fee',
                        'reference' => 'lease:' . $leaseId . ':latefee:' . $today,
                        'memo'      => 'Lease late fee ' . $today,
                        'currency'  => $currency,
                    ]);
                    if (empty($gl['ok'])) { $result = ['ok' => false, 'message' => $gl['message'] ?? 'GL post failed']; return false; }
                    $entryId = (int) ($gl['entry_id'] ?? 0);
                    $db->update('stays_lease_charges', ['gl_entry_id' => $entryId], ['id' => $chargeId]);
                }
                $result = ['ok' => true, 'charge_id' => $chargeId, 'amount' => $fee, 'entry_id' => $entryId, 'message' => 'Late fee applied'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('lease_ext_apply_late_fee: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Late fee error'];
        }
        return $result;
    }
}

// ---------------------------------------------------------------------------
// RENT RECEIPT   [MONEY — DB-GATED]
// ---------------------------------------------------------------------------
if (!function_exists('lease_ext_methods')) {
    function lease_ext_methods(): array { return ['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'card_offline' => 'Card (offline)', 'other' => 'Other']; }
}
if (!function_exists('lease_ext_receive')) {
    /**
     * Record a tenant rent payment and allocate it FIFO across open charges, posting a
     * balanced GL entry  Dr 1000 Cash / Cr 1100 Guest-Ledger-AR. The amount is a positive
     * client figure but is CLAMPED to the current outstanding balance — never over-applies.
     * Atomic: a FOR-UPDATE lock on the lease row serialises concurrent receipts so two
     * can't both read the same balance and over-pay. On success, each touched charge's
     * amount_paid is advanced and a single receipt row records the lump sum.
     * Returns ['ok'=>bool,'applied'=>float,'receipt_id'=>?int,'entry_id'=>?int,'message'=>].
     */
    function lease_ext_receive($db, int $orgId, int $leaseId, array $stayIds, float $amount, array $opts = []): array
    {
        if ($orgId <= 0 || $leaseId <= 0) { return ['ok' => false, 'message' => 'Missing org/lease']; }
        $amount = round((float) $amount, 2);
        if ($amount <= 0) { return ['ok' => false, 'message' => 'Amount must be positive']; }
        $method = in_array(($opts['method'] ?? ''), array_keys(lease_ext_methods()), true) ? (string) $opts['method'] : 'cash';
        $ref    = substr(trim((string) ($opts['reference'] ?? '')), 0, 100);
        $actor  = (string) ($_SESSION['user_id'] ?? '');

        $result = ['ok' => false, 'message' => 'Receipt failed'];
        try {
            $db->action(function ($db) use ($orgId, $leaseId, $stayIds, $amount, $method, $ref, $actor, &$result) {
                // Lock the lease row to serialise receipts (Medoo get() has no lock option).
                $st = $db->query('SELECT id, currency FROM stays_leases WHERE id = ? AND org_id = ? FOR UPDATE',
                    [$leaseId, $orgId]);
                $lease = $st ? $st->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$lease) { $result = ['ok' => false, 'message' => 'Lease not found']; return false; }
                $currency = strtoupper(substr((string) ($lease['currency'] ?? 'USD'), 0, 3));

                $pos = lease_ext_outstanding($db, $leaseId);
                $bal = round((float) $pos['balance'], 2);
                if ($bal <= 0) { $result = ['ok' => false, 'message' => 'Nothing outstanding on this lease']; return false; }
                $applied = min($amount, $bal); // never over-apply

                // FIFO allocation across open charges.
                $remaining = $applied;
                $charges = $db->select('stays_lease_charges', ['id', 'amount', 'amount_paid'],
                    ['lease_id' => $leaseId, 'ORDER' => ['due_date' => 'ASC', 'id' => 'ASC']]) ?: [];
                foreach ($charges as $c) {
                    if ($remaining <= 0) { break; }
                    $rem = round((float) $c['amount'] - (float) ($c['amount_paid'] ?? 0), 2);
                    if ($rem <= 0) { continue; }
                    $take = min($rem, $remaining);
                    $db->update('stays_lease_charges',
                        ['amount_paid' => round((float) ($c['amount_paid'] ?? 0) + $take, 2)],
                        ['id' => (int) $c['id']]);
                    $remaining = round($remaining - $take, 2);
                }

                $db->insert('stays_lease_receipts', [
                    'org_id'      => $orgId,
                    'lease_id'    => $leaseId,
                    'amount'      => $applied,
                    'currency'    => $currency,
                    'method'      => $method,
                    'reference'   => $ref !== '' ? $ref : null,
                    'received_at' => date('Y-m-d H:i:s'),
                    'received_by' => $actor !== '' ? $actor : null,
                    'gl_entry_id' => null,
                ]);
                $receiptId = (int) $db->id();
                if ($receiptId <= 0) { $result = ['ok' => false, 'message' => 'Receipt insert failed']; return false; }

                $entryId = null;
                if (function_exists('gl_post')) {
                    $gl = gl_post($db, $orgId, [
                        ['code' => '1000', 'debit'  => $applied], // Cash / Bank
                        ['code' => '1100', 'credit' => $applied], // Guest Ledger (AR)
                    ], [
                        'source'    => 'lease_receipt',
                        'reference' => 'lease:' . $leaseId . ':receipt:' . $receiptId,
                        'memo'      => 'Lease rent receipt (' . $method . ')',
                        'currency'  => $currency,
                    ]);
                    if (empty($gl['ok'])) { $result = ['ok' => false, 'message' => $gl['message'] ?? 'GL post failed']; return false; }
                    $entryId = (int) ($gl['entry_id'] ?? 0);
                    $db->update('stays_lease_receipts', ['gl_entry_id' => $entryId], ['id' => $receiptId]);
                }
                $result = ['ok' => true, 'applied' => $applied, 'receipt_id' => $receiptId, 'entry_id' => $entryId, 'message' => 'Receipt applied'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('lease_ext_receive: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Receipt error'];
        }
        return $result;
    }
}

// ---------------------------------------------------------------------------
// INSPECTIONS (move-in / move-out / periodic)
// ---------------------------------------------------------------------------
if (!function_exists('lease_ext_inspection_kinds')) {
    function lease_ext_inspection_kinds(): array { return ['move_in' => 'Move-in', 'move_out' => 'Move-out', 'periodic' => 'Periodic']; }
}
if (!function_exists('lease_ext_inspection_add')) {
    /**
     * Record a condition inspection against a lease. A free-form checklist (sent as an
     * array of ['item','condition','note']) is stored as JSON. deposit_deduction is an
     * ADVISORY figure recorded for the report only — it does NOT move money. If a deduction
     * must hit the ledger, staff raise it explicitly as an 'adjustment' charge (so the GL
     * is never silently touched here). Returns the new id or 0.
     */
    function lease_ext_inspection_add($db, int $orgId, int $leaseId, array $d): int
    {
        if ($orgId <= 0 || $leaseId <= 0) { return 0; }
        $kind = isset(lease_ext_inspection_kinds()[$d['kind'] ?? '']) ? (string) $d['kind'] : 'periodic';
        $dt = trim((string) ($d['inspected_on'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) { $dt = date('Y-m-d'); }

        $checklist = [];
        $raw = $d['checklist'] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $row) {
                if (!is_array($row)) { continue; }
                $item = substr(trim((string) ($row['item'] ?? '')), 0, 120);
                if ($item === '') { continue; }
                $checklist[] = [
                    'item'      => $item,
                    'condition' => substr(trim((string) ($row['condition'] ?? '')), 0, 40),
                    'note'      => substr(trim((string) ($row['note'] ?? '')), 0, 200),
                ];
                if (count($checklist) >= 100) { break; }
            }
        }
        $ded = ($d['deposit_deduction'] ?? '') !== '' ? max(0.0, round((float) $d['deposit_deduction'], 2)) : null;
        try {
            $db->insert('stays_lease_inspections', [
                'org_id'            => $orgId,
                'lease_id'          => $leaseId,
                'kind'              => $kind,
                'inspected_on'      => $dt,
                'inspector'         => substr(trim((string) ($d['inspector'] ?? ($_SESSION['user_id'] ?? ''))), 0, 120) ?: null,
                'checklist'         => $checklist ? json_encode($checklist, JSON_UNESCAPED_UNICODE) : null,
                'summary'           => substr(trim((string) ($d['summary'] ?? '')), 0, 500) ?: null,
                'deposit_deduction' => $ded,
                'created_at'        => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('lease_ext_inspection_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('lease_ext_inspections')) {
    function lease_ext_inspections($db, int $leaseId): array
    {
        if ($leaseId <= 0) { return []; }
        try { return $db->select('stays_lease_inspections', '*', ['lease_id' => $leaseId, 'ORDER' => ['inspected_on' => 'DESC', 'id' => 'DESC']]) ?: []; }
        catch (\Throwable $e) { error_log('lease_ext_inspections: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// TENANT STATEMENT — merged charge + receipt ledger with running balance.
// ---------------------------------------------------------------------------
if (!function_exists('lease_ext_statement')) {
    /**
     * The full tenant statement for ONE lease: every charge (debit) and receipt (credit)
     * ordered by date, with a running balance. Read-only. Returns:
     *   ['lease'=>row|null,'lines'=>[['date','type','description','debit','credit','balance']],
     *    'totals'=>['charged','paid','balance'],'currency'=>str]
     */
    function lease_ext_statement($db, int $leaseId, array $stayIds): array
    {
        $out = ['lease' => null, 'lines' => [], 'totals' => ['charged' => 0.0, 'paid' => 0.0, 'balance' => 0.0], 'currency' => 'USD'];
        $lease = lease_ext_row($db, $leaseId, $stayIds);
        if ($lease === null) { return $out; }
        $out['lease'] = $lease;
        $out['currency'] = strtoupper(substr((string) ($lease['currency'] ?? 'USD'), 0, 3));

        $events = [];
        foreach (lease_ext_charges($db, $leaseId) as $c) {
            $events[] = [
                'date'        => (string) ($c['due_date'] ?: substr((string) $c['created_at'], 0, 10)),
                'seq'         => (int) $c['id'],
                'type'        => (string) $c['kind'],
                'description' => (string) ($c['description'] ?? $c['kind']),
                'debit'       => round((float) $c['amount'], 2),
                'credit'      => 0.0,
            ];
        }
        foreach (lease_ext_receipts($db, $leaseId) as $r) {
            $events[] = [
                'date'        => substr((string) $r['received_at'], 0, 10),
                'seq'         => 1000000 + (int) $r['id'],
                'type'        => 'receipt',
                'description' => 'Payment received (' . (string) ($r['method'] ?? 'cash') . ')' . (!empty($r['reference']) ? ' ref ' . (string) $r['reference'] : ''),
                'debit'       => 0.0,
                'credit'      => round((float) $r['amount'], 2),
            ];
        }
        usort($events, function ($a, $b) {
            if ($a['date'] === $b['date']) { return $a['seq'] <=> $b['seq']; }
            return strcmp($a['date'], $b['date']);
        });

        $bal = 0.0; $charged = 0.0; $paid = 0.0;
        foreach ($events as $e) {
            $bal = round($bal + $e['debit'] - $e['credit'], 2);
            $charged = round($charged + $e['debit'], 2);
            $paid = round($paid + $e['credit'], 2);
            $out['lines'][] = [
                'date'        => $e['date'],
                'type'        => $e['type'],
                'description' => $e['description'],
                'debit'       => $e['debit'],
                'credit'      => $e['credit'],
                'balance'     => $bal,
            ];
        }
        $out['totals'] = ['charged' => $charged, 'paid' => $paid, 'balance' => $bal];
        return $out;
    }
}

// ---------------------------------------------------------------------------
// STATEMENT DELIVERY — HONEST STUB (comms wiring is DB-GATED / not connected here).
// ---------------------------------------------------------------------------
if (!function_exists('lease_ext_send_statement')) {
    /**
     * Would email/SMS the tenant their statement. The delivery channel is NOT wired in
     * this extension (no comms transport is bound to leases yet), so this reports an
     * honest 'not connected' and records nothing. Return shape mirrors the other stubs:
     *   ['connected'=>false,'message'=>str].
     */
    function lease_ext_send_statement($db, int $orgId, int $leaseId): array
    {
        return ['connected' => false, 'message' => 'Statement delivery is not connected. Download / print the statement instead.'];
    }
}

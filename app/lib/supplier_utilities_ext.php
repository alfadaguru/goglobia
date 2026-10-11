<?php
// FILE: app/lib/supplier_utilities_ext.php
// UTILITIES & PROPERTY SERVICE CHARGES — DEEPENING (PMS #51; docs 01b §2 #51).
//
// The BASE (supplier_lease.php) already covers raw meter readings + the pure
// consumption→amount calc (utility_amount / utility_add / utility_list). This file
// builds the genuine GAPS on top of it, WITHOUT touching the base:
//   - utility ACCOUNTS       (provider/account registry per property)
//   - meter REGISTRY         (named physical meters: serial, unit, account link)
//   - utility RATES / tariffs(a rate card per account/utility, effective-dated)
//   - utility BILLING        (turn a period's consumption into a posted expense)
//   - SERVICE CHARGES        (recurring/one-off property service-charge definitions)
//   - EXPENSE ALLOCATION     (split a bill across leases/units by share — balanced)
//   - utility ANALYTICS      (consumption + amount aggregates)
//
// MONEY: a utility bill is the PROPERTY's own expense. When posted it recognises the
// expense against Accounts Payable via gl_post() (which REFUSES any unbalanced entry).
// Tenant RECHARGE (billing a lease) optionally posts a folio item via folio_add_item —
// never a hand-rolled ledger write. Amounts are ALWAYS server-computed from stored
// readings/rates; the client never supplies a money figure that is trusted.
//
// Multi-step money writes (post bill → mark billed; allocate → write shares) are wrapped
// in $db->action() with a FOR UPDATE re-check so a double-click can't double-post.
//
// EXTERNAL EDGE — live provider meter sync (smart-meter / utility-company API) is an
// HONEST STUB: utility_provider_probe() records nothing live and reports 'not connected'.
//
// DB-GATED: requires the additive tables stays_utility_accounts, stays_utility_meters,
// stays_utility_rates, stays_utility_bills, stays_utility_bill_allocations and
// stays_service_charges (see the patch spec schema_sql). Every function degrades to a
// safe empty/false when its table is absent. NOT runtime-verified (no MySQL) —
// logic-reasoned + php -l; the pure calcs are harnessable.

// ---------------------------------------------------------------------------
// Small shared helpers
// ---------------------------------------------------------------------------
if (!function_exists('util_utilities')) {
    /** Canonical utility kinds (mirrors the base enum). */
    function util_utilities(): array
    {
        return ['electricity' => 'Electricity', 'water' => 'Water', 'gas' => 'Gas', 'internet' => 'Internet', 'other' => 'Other'];
    }
}
if (!function_exists('util_table_exists')) {
    /** True if a table exists (cached per request). Lets every reader degrade safely. */
    function util_table_exists($db, string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) { return $cache[$table]; }
        try {
            $rows = $db->query("SHOW TABLES LIKE " . $db->quote($table))->fetchAll();
            return $cache[$table] = !empty($rows);
        } catch (\Throwable $e) {
            return $cache[$table] = false;
        }
    }
}

// ---------------------------------------------------------------------------
// UTILITY ACCOUNTS (provider / account registry)
// ---------------------------------------------------------------------------
if (!function_exists('util_account_add')) {
    /** Register a utility account for a property. Returns new id or 0. */
    function util_account_add($db, int $stayId, array $d): int
    {
        if ($stayId <= 0 || !util_table_exists($db, 'stays_utility_accounts')) { return 0; }
        $provider = trim((string) ($d['provider'] ?? ''));
        if ($provider === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $util = isset(util_utilities()[$d['utility'] ?? '']) ? $d['utility'] : 'electricity';
            $db->insert('stays_utility_accounts', [
                'org_id'        => $orgId > 0 ? $orgId : null,
                'stay_id'       => $stayId,
                'utility'       => $util,
                'provider'      => substr($provider, 0, 120),
                'account_no'    => substr(trim((string) ($d['account_no'] ?? '')), 0, 80) ?: null,
                'tariff_name'   => substr(trim((string) ($d['tariff_name'] ?? '')), 0, 80) ?: null,
                'currency'      => strtoupper(substr((string) ($d['currency'] ?? 'USD'), 0, 3)),
                'status'        => 'active',
                'notes'         => substr(trim((string) ($d['notes'] ?? '')), 0, 255) ?: null,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('util_account_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('util_account_set_status')) {
    function util_account_set_status($db, int $id, array $stayIds, string $status): bool
    {
        if (!in_array($status, ['active', 'closed'], true) || empty($stayIds) || !util_table_exists($db, 'stays_utility_accounts')) { return false; }
        try {
            if (!$db->has('stays_utility_accounts', ['id' => $id, 'stay_id' => $stayIds])) { return false; }
            $db->update('stays_utility_accounts', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id, 'stay_id' => $stayIds]);
            return true;
        } catch (\Throwable $e) { error_log('util_account_set_status: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('util_account_list')) {
    function util_account_list($db, array $stayIds, int $limit = 300): array
    {
        if (empty($stayIds) || !util_table_exists($db, 'stays_utility_accounts')) { return []; }
        try { return $db->select('stays_utility_accounts', '*', ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('util_account_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// METER REGISTRY (named physical meters)
// ---------------------------------------------------------------------------
if (!function_exists('util_meter_add')) {
    /** Register a physical meter (so readings reference a meter, not free text). */
    function util_meter_add($db, int $stayId, array $d): int
    {
        if ($stayId <= 0 || !util_table_exists($db, 'stays_utility_meters')) { return 0; }
        $label = trim((string) ($d['label'] ?? ''));
        if ($label === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $util = isset(util_utilities()[$d['utility'] ?? '']) ? $d['utility'] : 'electricity';
            $acctId = ($d['account_id'] ?? '') !== '' ? (int) $d['account_id'] : null;
            // Guard the account link stays within the same property set.
            if ($acctId !== null && util_table_exists($db, 'stays_utility_accounts')
                && !$db->has('stays_utility_accounts', ['id' => $acctId, 'stay_id' => $stayId])) {
                $acctId = null;
            }
            $db->insert('stays_utility_meters', [
                'org_id'     => $orgId > 0 ? $orgId : null,
                'stay_id'    => $stayId,
                'account_id' => $acctId,
                'utility'    => $util,
                'label'      => substr($label, 0, 80),
                'serial_no'  => substr(trim((string) ($d['serial_no'] ?? '')), 0, 80) ?: null,
                'unit'       => substr(trim((string) ($d['unit'] ?? '')), 0, 20) ?: null,
                'status'     => 'active',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('util_meter_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('util_meter_set_status')) {
    function util_meter_set_status($db, int $id, array $stayIds, string $status): bool
    {
        if (!in_array($status, ['active', 'retired'], true) || empty($stayIds) || !util_table_exists($db, 'stays_utility_meters')) { return false; }
        try {
            if (!$db->has('stays_utility_meters', ['id' => $id, 'stay_id' => $stayIds])) { return false; }
            $db->update('stays_utility_meters', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id, 'stay_id' => $stayIds]);
            return true;
        } catch (\Throwable $e) { error_log('util_meter_set_status: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('util_meter_list')) {
    function util_meter_list($db, array $stayIds, int $limit = 300): array
    {
        if (empty($stayIds) || !util_table_exists($db, 'stays_utility_meters')) { return []; }
        try { return $db->select('stays_utility_meters', '*', ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('util_meter_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// UTILITY RATES / TARIFFS (effective-dated rate card)
// ---------------------------------------------------------------------------
if (!function_exists('util_rate_add')) {
    /** Add an effective-dated unit rate for a property+utility (optionally per account). */
    function util_rate_add($db, int $stayId, array $d): int
    {
        if ($stayId <= 0 || !util_table_exists($db, 'stays_utility_rates')) { return 0; }
        $ef = trim((string) ($d['effective_from'] ?? ''));
        $rate = (float) ($d['unit_rate'] ?? 0);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ef) || $rate < 0) { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $util = isset(util_utilities()[$d['utility'] ?? '']) ? $d['utility'] : 'electricity';
            $acctId = ($d['account_id'] ?? '') !== '' ? (int) $d['account_id'] : null;
            if ($acctId !== null && util_table_exists($db, 'stays_utility_accounts')
                && !$db->has('stays_utility_accounts', ['id' => $acctId, 'stay_id' => $stayId])) {
                $acctId = null;
            }
            $db->insert('stays_utility_rates', [
                'org_id'         => $orgId > 0 ? $orgId : null,
                'stay_id'        => $stayId,
                'account_id'     => $acctId,
                'utility'        => $util,
                'unit_rate'      => round($rate, 4),
                'standing_charge'=> ($d['standing_charge'] ?? '') !== '' ? max(0, round((float) $d['standing_charge'], 2)) : null,
                'currency'       => strtoupper(substr((string) ($d['currency'] ?? 'USD'), 0, 3)),
                'effective_from' => $ef,
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('util_rate_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('util_rate_current')) {
    /** The most recent rate effective on-or-before $onDate for a property+utility.
     *  Returns the row or null. Account-specific rate wins over a generic one. */
    function util_rate_current($db, int $stayId, string $utility, string $onDate, ?int $acctId = null): ?array
    {
        if ($stayId <= 0 || !util_table_exists($db, 'stays_utility_rates')) { return null; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $onDate)) { $onDate = date('Y-m-d'); }
        try {
            $where = ['stay_id' => $stayId, 'utility' => $utility, 'effective_from[<=]' => $onDate];
            if ($acctId !== null && $acctId > 0) { $where['account_id'] = $acctId; }
            $r = $db->get('stays_utility_rates', '*', array_merge($where, ['ORDER' => ['effective_from' => 'DESC', 'id' => 'DESC']]));
            if (!$r && $acctId !== null) {
                // fall back to a generic (account-less) rate
                unset($where['account_id']);
                $r = $db->get('stays_utility_rates', '*', array_merge($where, ['ORDER' => ['effective_from' => 'DESC', 'id' => 'DESC']]));
            }
            return $r ?: null;
        } catch (\Throwable $e) { error_log('util_rate_current: ' . $e->getMessage()); return null; }
    }
}
if (!function_exists('util_rate_list')) {
    function util_rate_list($db, array $stayIds, int $limit = 300): array
    {
        if (empty($stayIds) || !util_table_exists($db, 'stays_utility_rates')) { return []; }
        try { return $db->select('stays_utility_rates', '*', ['stay_id' => $stayIds, 'ORDER' => ['effective_from' => 'DESC', 'id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('util_rate_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// UTILITY BILLING — compute a period bill from stored readings, then post it.
// ---------------------------------------------------------------------------
if (!function_exists('util_bill_compute')) {
    /**
     * SERVER-SIDE amount for a utility bill. The client NEVER supplies the money figure.
     * Consumption = sum over the base readings (stays_utility_readings) for this
     * property+utility in [from,to]; amount = consumption × resolved rate + standing
     * charge. If the period has reading rows that already carry an 'amount' (the base
     * stores per-reading amount), we trust that stored per-reading sum instead of
     * re-multiplying — this keeps it consistent with what the operator saw when logging.
     * Returns ['consumption'=>float,'amount'=>float,'rate'=>float,'readings'=>int,'currency'=>string].
     */
    function util_bill_compute($db, int $stayId, string $utility, string $from, string $to): array
    {
        $out = ['consumption' => 0.0, 'amount' => 0.0, 'rate' => 0.0, 'readings' => 0, 'currency' => 'USD'];
        if ($stayId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { return $out; }
        try {
            $rows = $db->select('stays_utility_readings', ['current_reading', 'previous_reading', 'amount'],
                ['stay_id' => $stayId, 'utility' => $utility, 'reading_date[<>]' => [$from, $to], 'ORDER' => ['reading_date' => 'ASC', 'id' => 'ASC']]) ?: [];
            $out['readings'] = count($rows);
            $consumption = 0.0; $storedAmount = 0.0; $haveStored = true;
            foreach ($rows as $r) {
                $prev = ($r['previous_reading'] === null || $r['previous_reading'] === '') ? 0.0 : (float) $r['previous_reading'];
                $consumption += max(0.0, (float) $r['current_reading'] - $prev);
                if ($r['amount'] === null || $r['amount'] === '') { $haveStored = false; }
                else { $storedAmount += (float) $r['amount']; }
            }
            $out['consumption'] = round($consumption, 3);
            $rate = util_rate_current($db, $stayId, $utility, $to);
            $unitRate = $rate ? (float) $rate['unit_rate'] : 0.0;
            $standing = $rate && $rate['standing_charge'] !== null ? (float) $rate['standing_charge'] : 0.0;
            $out['rate'] = $unitRate;
            if ($rate && isset($rate['currency'])) { $out['currency'] = (string) $rate['currency']; }
            // Prefer the stored per-reading amounts when every reading has one; else
            // derive from the rate card.
            if ($out['readings'] > 0 && $haveStored && $storedAmount > 0) {
                $out['amount'] = round($storedAmount, 2);
            } else {
                $out['amount'] = round($consumption * $unitRate + $standing, 2);
            }
            return $out;
        } catch (\Throwable $e) { error_log('util_bill_compute: ' . $e->getMessage()); return $out; }
    }
}
if (!function_exists('util_bill_statuses')) {
    function util_bill_statuses(): array { return ['draft' => 'Draft', 'posted' => 'Posted', 'void' => 'Void']; }
}
if (!function_exists('util_bill_create')) {
    /** Create a DRAFT utility bill whose amount is computed server-side (util_bill_compute).
     *  No GL yet — posting happens in util_bill_post(). Returns new id or 0. */
    function util_bill_create($db, int $stayId, array $d): int
    {
        if ($stayId <= 0 || !util_table_exists($db, 'stays_utility_bills')) { return 0; }
        $from = trim((string) ($d['period_from'] ?? '')); $to = trim((string) ($d['period_to'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $util = isset(util_utilities()[$d['utility'] ?? '']) ? $d['utility'] : 'electricity';
            $c = util_bill_compute($db, $stayId, $util, $from, $to);
            $db->insert('stays_utility_bills', [
                'org_id'      => $orgId > 0 ? $orgId : null,
                'stay_id'     => $stayId,
                'account_id'  => ($d['account_id'] ?? '') !== '' ? (int) $d['account_id'] : null,
                'utility'     => $util,
                'period_from' => $from,
                'period_to'   => $to,
                'consumption' => $c['consumption'],
                'unit_rate'   => $c['rate'],
                'amount'      => $c['amount'],
                'currency'    => $c['currency'],
                'status'      => 'draft',
                'notes'       => substr(trim((string) ($d['notes'] ?? '')), 0, 255) ?: null,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('util_bill_create: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('util_bill_post')) {
    /**
     * POST a draft bill to the property GL: recognise the utility expense against AP.
     *   DR 6000 Operating Expense   (the cost)
     *   CR 2000 Accounts Payable    (owed to the utility provider)
     * Atomic + idempotent: the row is re-read FOR UPDATE inside $db->action(); gl_post()
     * refuses an unbalanced entry and dedups on (source,reference). Returns ok/message.
     */
    function util_bill_post($db, int $billId, array $stayIds): array
    {
        if ($billId <= 0 || empty($stayIds) || !util_table_exists($db, 'stays_utility_bills')) {
            return ['ok' => false, 'message' => 'Bad request.'];
        }
        $result = ['ok' => false, 'message' => 'Post failed.'];
        try {
            $db->action(function ($db) use ($billId, $stayIds, &$result) {
                $bill = $db->get('stays_utility_bills', '*', ['id' => $billId, 'stay_id' => $stayIds, 'FOR UPDATE' => true]);
                if (!$bill) { $result = ['ok' => false, 'message' => 'Not your bill.']; return false; }
                if ((string) $bill['status'] !== 'draft') { $result = ['ok' => false, 'message' => 'Only a draft bill can be posted.']; return true; }
                $amount = round((float) $bill['amount'], 2);
                if ($amount <= 0) { $result = ['ok' => false, 'message' => 'Nothing to post (zero amount).']; return true; }
                $orgId = (int) ($bill['org_id'] ?? 0);
                if ($orgId <= 0 && function_exists('supplier_property_org')) { $orgId = supplier_property_org($db, (int) $bill['stay_id']); }
                if ($orgId <= 0) { $result = ['ok' => false, 'message' => 'No ledger org for this property.']; return false; }
                if (function_exists('gl_seed_accounts')) { gl_seed_accounts($db, $orgId); }
                $gl = gl_post($db, $orgId, [
                    ['code' => '6000', 'debit'  => $amount],
                    ['code' => '2000', 'credit' => $amount],
                ], ['source' => 'utilbill', 'reference' => 'UB-' . $billId, 'currency' => (string) $bill['currency'],
                    'date' => (string) $bill['period_to'], 'property_id' => (int) $bill['stay_id'],
                    'memo' => 'Utility bill ' . $bill['utility'] . ' ' . $bill['period_from'] . '..' . $bill['period_to']]);
                if (empty($gl['ok'])) {
                    $result = ['ok' => false, 'message' => 'Ledger rejected: ' . ($gl['message'] ?? 'error') . '. Nothing changed.'];
                    return false; // rollback
                }
                $db->update('stays_utility_bills',
                    ['status' => 'posted', 'gl_entry_id' => $gl['entry_id'] ?? null, 'posted_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => $billId, 'stay_id' => $stayIds, 'status' => 'draft']);
                $result = ['ok' => true, 'message' => 'Bill posted to the ledger.', 'entry_id' => $gl['entry_id'] ?? null];
                return true;
            });
        } catch (\Throwable $e) { error_log('util_bill_post: ' . $e->getMessage()); return ['ok' => false, 'message' => 'Post error.']; }
        return $result;
    }
}
if (!function_exists('util_bill_list')) {
    function util_bill_list($db, array $stayIds, int $limit = 300): array
    {
        if (empty($stayIds) || !util_table_exists($db, 'stays_utility_bills')) { return []; }
        try { return $db->select('stays_utility_bills', '*', ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('util_bill_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// EXPENSE ALLOCATION — split a POSTED bill across leases/units by share.
// ---------------------------------------------------------------------------
if (!function_exists('util_bill_allocate')) {
    /**
     * Split a posted bill's amount across one or more leases by WEIGHT. The split is
     * computed server-side from the weights and the bill's own amount — the client only
     * supplies weights and (optionally) which lease. The LAST share absorbs the rounding
     * remainder so the shares sum EXACTLY to the bill amount (no leak). Optionally
     * recharges each lease's folio via folio_add_item (DB-GATED on an OPEN folio).
     *
     *   $shares = [ ['lease_id'=>int, 'weight'=>float, 'folio_id'=>?int] , ... ]
     * Returns ['ok'=>bool,'message'=>string,'allocated'=>float].
     */
    function util_bill_allocate($db, int $billId, array $stayIds, array $shares): array
    {
        if ($billId <= 0 || empty($stayIds) || empty($shares) || !util_table_exists($db, 'stays_utility_bills') || !util_table_exists($db, 'stays_utility_bill_allocations')) {
            return ['ok' => false, 'message' => 'Bad request.'];
        }
        // Normalise + validate weights up front (reject non-positive totals).
        $clean = [];
        $totalWeight = 0.0;
        foreach ($shares as $s) {
            $leaseId = (int) ($s['lease_id'] ?? 0);
            $w = round((float) ($s['weight'] ?? 0), 6);
            if ($leaseId <= 0 || $w <= 0) { continue; }
            $clean[] = ['lease_id' => $leaseId, 'weight' => $w, 'folio_id' => (int) ($s['folio_id'] ?? 0)];
            $totalWeight += $w;
        }
        if (empty($clean) || $totalWeight <= 0) { return ['ok' => false, 'message' => 'Provide at least one lease with a positive share.']; }

        $result = ['ok' => false, 'message' => 'Allocation failed.', 'allocated' => 0.0];
        try {
            $db->action(function ($db) use ($billId, $stayIds, $clean, $totalWeight, &$result) {
                $bill = $db->get('stays_utility_bills', '*', ['id' => $billId, 'stay_id' => $stayIds, 'FOR UPDATE' => true]);
                if (!$bill) { $result = ['ok' => false, 'message' => 'Not your bill.']; return false; }
                if ((string) $bill['status'] !== 'posted') { $result = ['ok' => false, 'message' => 'Only a posted bill can be allocated.']; return true; }
                // Refuse double-allocation.
                $already = (int) $db->count('stays_utility_bill_allocations', ['bill_id' => $billId]);
                if ($already > 0) { $result = ['ok' => false, 'message' => 'This bill has already been allocated.']; return true; }

                $amount = round((float) $bill['amount'], 2);
                if ($amount <= 0) { $result = ['ok' => false, 'message' => 'Nothing to allocate.']; return true; }

                // Validate every lease belongs to this property set BEFORE writing.
                $leaseIds = array_column($clean, 'lease_id');
                $owned = [];
                if (util_table_exists($db, 'stays_leases')) {
                    foreach ($db->select('stays_leases', ['id'], ['id' => $leaseIds, 'stay_id' => $stayIds]) ?: [] as $lr) { $owned[(int) $lr['id']] = true; }
                }
                foreach ($clean as $c) {
                    if (empty($owned[$c['lease_id']])) { $result = ['ok' => false, 'message' => 'Lease #' . $c['lease_id'] . ' is not on your property.']; return false; }
                }

                // Compute shares; last one absorbs the rounding remainder → sums EXACTLY.
                $n = count($clean); $running = 0.0; $allocated = 0.0;
                foreach ($clean as $i => $c) {
                    if ($i === $n - 1) { $share = round($amount - $running, 2); }
                    else { $share = round($amount * ($c['weight'] / $totalWeight), 2); $running = round($running + $share, 2); }
                    if ($share < 0) { $share = 0.0; }
                    $db->insert('stays_utility_bill_allocations', [
                        'bill_id'    => $billId,
                        'org_id'     => $bill['org_id'] ?? null,
                        'stay_id'    => (int) $bill['stay_id'],
                        'lease_id'   => $c['lease_id'],
                        'weight'     => $c['weight'],
                        'amount'     => $share,
                        'folio_id'   => $c['folio_id'] > 0 ? $c['folio_id'] : null,
                        'recharged'  => 0,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $allocId = (int) $db->id();
                    $allocated = round($allocated + $share, 2);
                    // Optional tenant recharge via the folio spine (never a hand ledger write).
                    if ($c['folio_id'] > 0 && $share > 0 && function_exists('folio_add_item')) {
                        $ok = folio_add_item($db, $c['folio_id'], 'charge', $share,
                            'Utility recharge (' . $bill['utility'] . ' ' . $bill['period_from'] . '..' . $bill['period_to'] . ')',
                            (string) ($_SESSION['user_id'] ?? 'utilities'));
                        if ($ok) { $db->update('stays_utility_bill_allocations', ['recharged' => 1], ['id' => $allocId]); }
                    }
                }
                if (abs($allocated - $amount) > 0.001) {
                    $result = ['ok' => false, 'message' => 'Internal split mismatch; nothing saved.'];
                    return false; // rollback — shares must sum to the bill exactly
                }
                $db->update('stays_utility_bills', ['allocated' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $billId]);
                $result = ['ok' => true, 'message' => 'Allocated ' . number_format($allocated, 2) . ' across ' . $n . ' lease(s).', 'allocated' => $allocated];
                return true;
            });
        } catch (\Throwable $e) { error_log('util_bill_allocate: ' . $e->getMessage()); return ['ok' => false, 'message' => 'Allocation error.']; }
        return $result;
    }
}
if (!function_exists('util_allocation_list')) {
    function util_allocation_list($db, int $billId): array
    {
        if ($billId <= 0 || !util_table_exists($db, 'stays_utility_bill_allocations')) { return []; }
        try { return $db->select('stays_utility_bill_allocations', '*', ['bill_id' => $billId, 'ORDER' => ['id' => 'ASC']]) ?: []; }
        catch (\Throwable $e) { error_log('util_allocation_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// SERVICE CHARGES (recurring / one-off property service-charge definitions)
// ---------------------------------------------------------------------------
if (!function_exists('service_charge_cadences')) {
    function service_charge_cadences(): array { return ['one_off' => 'One-off', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly']; }
}
if (!function_exists('service_charge_add')) {
    /** Define a property service charge (e.g. common-area cleaning, security). */
    function service_charge_add($db, int $stayId, array $d): int
    {
        if ($stayId <= 0 || !util_table_exists($db, 'stays_service_charges')) { return 0; }
        $name = trim((string) ($d['name'] ?? ''));
        $amount = (float) ($d['amount'] ?? 0);
        if ($name === '' || $amount < 0) { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $cadence = isset(service_charge_cadences()[$d['cadence'] ?? '']) ? $d['cadence'] : 'monthly';
            $db->insert('stays_service_charges', [
                'org_id'     => $orgId > 0 ? $orgId : null,
                'stay_id'    => $stayId,
                'lease_id'   => ($d['lease_id'] ?? '') !== '' ? (int) $d['lease_id'] : null,
                'name'       => substr($name, 0, 120),
                'amount'     => round($amount, 2),
                'cadence'    => $cadence,
                'currency'   => strtoupper(substr((string) ($d['currency'] ?? 'USD'), 0, 3)),
                'status'     => 'active',
                'notes'      => substr(trim((string) ($d['notes'] ?? '')), 0, 255) ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('service_charge_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('service_charge_set_status')) {
    function service_charge_set_status($db, int $id, array $stayIds, string $status): bool
    {
        if (!in_array($status, ['active', 'archived'], true) || empty($stayIds) || !util_table_exists($db, 'stays_service_charges')) { return false; }
        try {
            if (!$db->has('stays_service_charges', ['id' => $id, 'stay_id' => $stayIds])) { return false; }
            $db->update('stays_service_charges', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id, 'stay_id' => $stayIds]);
            return true;
        } catch (\Throwable $e) { error_log('service_charge_set_status: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('service_charge_post')) {
    /**
     * Recognise a service-charge instance as property income against AR (money the
     * property is owed). SERVER-side amount = the stored definition amount; the client
     * only names which charge to run. Idempotent per (charge, period) via gl_post dedup.
     *   DR 1100 Guest Ledger (AR)
     *   CR 4900 Other Revenue
     * Returns ok/message. Optional folio recharge when a lease folio is supplied.
     */
    function service_charge_post($db, int $chargeId, array $stayIds, string $period, int $folioId = 0): array
    {
        if ($chargeId <= 0 || empty($stayIds) || !util_table_exists($db, 'stays_service_charges')) {
            return ['ok' => false, 'message' => 'Bad request.'];
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $period) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $period)) { $period = date('Y-m'); }
        $periodKey = substr($period, 0, 7);
        $result = ['ok' => false, 'message' => 'Post failed.'];
        try {
            $db->action(function ($db) use ($chargeId, $stayIds, $periodKey, $folioId, &$result) {
                $sc = $db->get('stays_service_charges', '*', ['id' => $chargeId, 'stay_id' => $stayIds, 'FOR UPDATE' => true]);
                if (!$sc) { $result = ['ok' => false, 'message' => 'Not your charge.']; return false; }
                if ((string) $sc['status'] !== 'active') { $result = ['ok' => false, 'message' => 'Charge is not active.']; return true; }
                $amount = round((float) $sc['amount'], 2);
                if ($amount <= 0) { $result = ['ok' => false, 'message' => 'Zero amount.']; return true; }
                $orgId = (int) ($sc['org_id'] ?? 0);
                if ($orgId <= 0 && function_exists('supplier_property_org')) { $orgId = supplier_property_org($db, (int) $sc['stay_id']); }
                if ($orgId <= 0) { $result = ['ok' => false, 'message' => 'No ledger org.']; return false; }
                if (function_exists('gl_seed_accounts')) { gl_seed_accounts($db, $orgId); }
                $gl = gl_post($db, $orgId, [
                    ['code' => '1100', 'debit'  => $amount],
                    ['code' => '4900', 'credit' => $amount],
                ], ['source' => 'svccharge', 'reference' => 'SC-' . $chargeId . '-' . $periodKey, 'currency' => (string) $sc['currency'],
                    'date' => $periodKey . '-01', 'property_id' => (int) $sc['stay_id'],
                    'memo' => 'Service charge ' . $sc['name'] . ' ' . $periodKey]);
                if (empty($gl['ok'])) {
                    $result = ['ok' => false, 'message' => 'Ledger rejected: ' . ($gl['message'] ?? 'error') . '. Nothing changed.'];
                    return false;
                }
                // Optional tenant recharge via the folio spine.
                if ($folioId > 0 && function_exists('folio_add_item')) {
                    folio_add_item($db, $folioId, 'charge', $amount, 'Service charge: ' . $sc['name'] . ' (' . $periodKey . ')', (string) ($_SESSION['user_id'] ?? 'utilities'));
                }
                $result = ['ok' => true, 'message' => !empty($gl['duplicate']) ? 'Already posted for ' . $periodKey . ' (no change).' : 'Service charge posted.', 'entry_id' => $gl['entry_id'] ?? null];
                return true;
            });
        } catch (\Throwable $e) { error_log('service_charge_post: ' . $e->getMessage()); return ['ok' => false, 'message' => 'Post error.']; }
        return $result;
    }
}
if (!function_exists('service_charge_list')) {
    function service_charge_list($db, array $stayIds, int $limit = 300): array
    {
        if (empty($stayIds) || !util_table_exists($db, 'stays_service_charges')) { return []; }
        try { return $db->select('stays_service_charges', '*', ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('service_charge_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// UTILITY ANALYTICS (consumption + amount aggregates)
// ---------------------------------------------------------------------------
if (!function_exists('util_analytics')) {
    /**
     * Aggregate consumption + billed amount from the BASE readings table, grouped by
     * utility, over an optional date window. Pure read; returns a safe default shape.
     * Returns ['by_utility'=>[util=>['consumption'=>,'amount'=>,'readings'=>]], 'total_amount'=>float].
     */
    function util_analytics($db, array $stayIds, ?string $from = null, ?string $to = null): array
    {
        $out = ['by_utility' => [], 'total_amount' => 0.0, 'total_consumption' => 0.0];
        if (empty($stayIds)) { return $out; }
        try {
            $where = ['stay_id' => $stayIds];
            if ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && $to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                $where['reading_date[<>]'] = [$from, $to];
            }
            $rows = $db->select('stays_utility_readings', ['utility', 'current_reading', 'previous_reading', 'amount'], $where) ?: [];
            foreach ($rows as $r) {
                $u = (string) $r['utility'];
                if (!isset($out['by_utility'][$u])) { $out['by_utility'][$u] = ['consumption' => 0.0, 'amount' => 0.0, 'readings' => 0]; }
                $prev = ($r['previous_reading'] === null || $r['previous_reading'] === '') ? 0.0 : (float) $r['previous_reading'];
                $cons = max(0.0, (float) $r['current_reading'] - $prev);
                $amt = ($r['amount'] === null || $r['amount'] === '') ? 0.0 : (float) $r['amount'];
                $out['by_utility'][$u]['consumption'] = round($out['by_utility'][$u]['consumption'] + $cons, 3);
                $out['by_utility'][$u]['amount'] = round($out['by_utility'][$u]['amount'] + $amt, 2);
                $out['by_utility'][$u]['readings']++;
                $out['total_amount'] = round($out['total_amount'] + $amt, 2);
                $out['total_consumption'] = round($out['total_consumption'] + $cons, 3);
            }
            return $out;
        } catch (\Throwable $e) { error_log('util_analytics: ' . $e->getMessage()); return $out; }
    }
}

// ---------------------------------------------------------------------------
// EXTERNAL EDGE — live provider / smart-meter sync. HONEST STUB.
// ---------------------------------------------------------------------------
if (!function_exists('util_provider_probe')) {
    /**
     * Placeholder for a live utility-company / smart-meter integration. There is NO
     * such integration wired. This returns a truthful 'not connected' result so the UI
     * can show the capability without faking data. When a real connector is built it
     * would authenticate against the account's provider API and pull readings into the
     * base stays_utility_readings table via utility_add().
     */
    function util_provider_probe($db, int $accountId): array
    {
        return [
            'connected' => false,
            'account_id' => $accountId,
            'message' => 'Live provider sync is not connected. Readings are entered manually; '
                . 'a smart-meter/utility-API connector can be added later to auto-pull readings.',
        ];
    }
}

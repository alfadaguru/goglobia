<?php
// FILE: app/lib/supplier_owners.php
// PROPERTY OWNERS + MANAGEMENT AGREEMENTS + OWNER STATEMENTS (Phase 1 inc S26;
// docs 01a §5). The apartment / property-manager model: a supplier (operator/org)
// may manage units owned by DIFFERENT people, and must pay each owner their share.
//
// operator (supplier org) ≠ owner (landlord). A management agreement links a
// property to an owner with a manager-commission % + a per-period fee. A statement
// for a period aggregates that property's EARNINGS (S18 supplier_earnings — the
// authoritative per-booking money: gross/commission/net) and deducts the manager
// commission + any recorded expenses → the owner's net payout.
//
// SCOPE of S26: owner records, agreements, statement generation + expenses. It does
// NOT move money (no owner bank payout) and does NOT post to the GL — it's the
// owner-accounting layer; a trust/client-money ledger + owner payout are later work
// (01a §5 trust accounting). Amounts are read server-side from supplier_earnings;
// never client-trusted. Org/property ownership is enforced by the caller routes.

if (!function_exists('owner_create')) {
    /** Create a property-owner record for the acting supplier's org. Returns id. */
    function owner_create($db, int $orgId, array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($orgId <= 0 || $name === '') { return 0; }
        try {
            $db->insert('stays_owners', [
                'org_id'         => $orgId,
                'name'           => substr($name, 0, 191),
                'email'          => trim((string) ($data['email'] ?? '')) ?: null,
                'phone'          => trim((string) ($data['phone'] ?? '')) ?: null,
                'bank_code'      => trim((string) ($data['bank_code'] ?? '')) ?: null,
                'account_number' => trim((string) ($data['account_number'] ?? '')) ?: null,
                'account_name'   => trim((string) ($data['account_name'] ?? '')) ?: null,
                'status'         => 1,
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) {
            error_log('owner_create: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('owner_agreement_set')) {
    /**
     * Link a property to an owner with commission terms (one active agreement per
     * property — a new one supersedes the old). manager_commission_pct is the % of
     * gross the MANAGER keeps (on top of the platform commission). Returns bool.
     */
    function owner_agreement_set($db, int $orgId, int $stayId, int $ownerId, float $managerPct, float $fixedFee = 0.0): bool
    {
        if ($orgId <= 0 || $stayId <= 0 || $ownerId <= 0) { return false; }
        $managerPct = max(0.0, min(100.0, round($managerPct, 2)));
        $fixedFee = max(0.0, round($fixedFee, 2));
        try {
            // Owner must belong to this org; property must belong to this org.
            if (!$db->has('stays_owners', ['id' => $ownerId, 'org_id' => $orgId])) { return false; }
            if (!$db->has('stays', ['id' => $stayId, 'org_id' => $orgId])) { return false; }
            // Supersede any existing active agreement for this property.
            $db->update('stays_management_agreements', ['active' => 0, 'updated_at' => date('Y-m-d H:i:s')],
                ['stay_id' => $stayId, 'active' => 1]);
            $db->insert('stays_management_agreements', [
                'org_id'                 => $orgId,
                'stay_id'                => $stayId,
                'owner_id'               => $ownerId,
                'manager_commission_pct' => $managerPct,
                'fixed_fee'              => $fixedFee,
                'active'                 => 1,
                'created_at'             => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('owner_agreement_set: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('owner_expense_add')) {
    /** Record an owner-charged expense against a property (cleaning/repairs/etc.) in a
     *  currency, dated. Deducted on the owner statement. Returns bool. */
    function owner_expense_add($db, int $orgId, int $stayId, float $amount, string $description, string $date = '', string $currency = 'USD'): bool
    {
        $amount = round($amount, 2);
        if ($orgId <= 0 || $stayId <= 0 || $amount <= 0) { return false; }
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d'); }
        try {
            if (!$db->has('stays', ['id' => $stayId, 'org_id' => $orgId])) { return false; }
            $db->insert('stays_owner_expenses', [
                'org_id'      => $orgId,
                'stay_id'     => $stayId,
                'description' => substr(trim($description), 0, 191) ?: 'Expense',
                'amount'      => $amount,
                'currency'    => strtoupper(substr($currency, 0, 3)),
                'expense_date'=> $date,
                'statement_id'=> null,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) {
            error_log('owner_expense_add: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('owner_statement_preview')) {
    /**
     * Compute (WITHOUT persisting) an owner statement for a property over
     * [from, to] (inclusive dates, by booking earning created_at). Returns:
     *   ['ok','currency','gross','platform_commission','operator_net','manager_commission',
     *    'expenses','owner_payout','bookings','agreement'].
     * Figures come from supplier_earnings (S18) for the property in the window;
     * manager commission = agreement.manager_commission_pct × gross + fixed_fee;
     * owner_payout = operator_net − manager_commission − expenses. (operator_net is
     * what the operator received after the PLATFORM commission; the manager's own cut
     * + expenses then come out, leaving the owner's share.)
     */
    function owner_statement_preview($db, int $orgId, int $stayId, string $from, string $to): array
    {
        $out = ['ok' => false];
        if ($orgId <= 0 || $stayId <= 0) { $out['message'] = 'Bad input'; return $out; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $out['message'] = 'Bad dates'; return $out; }
        try {
            $agr = $db->get('stays_management_agreements', '*', ['stay_id' => $stayId, 'org_id' => $orgId, 'active' => 1]);
            // Earnings in the window (exclude void). created_at range inclusive of the day.
            $rows = $db->select('supplier_earnings', ['currency', 'gross_amount', 'commission_amount', 'net_amount'],
                ['stay_id' => $stayId, 'state[!]' => 'void',
                 'created_at[>=]' => $from . ' 00:00:00', 'created_at[<=]' => $to . ' 23:59:59']) ?: [];
            $gross = 0.0; $platform = 0.0; $operatorNet = 0.0; $currency = null;
            foreach ($rows as $r) {
                $gross += (float) $r['gross_amount'];
                $platform += (float) $r['commission_amount'];
                $operatorNet += (float) $r['net_amount'];
                if ($currency === null) { $currency = strtoupper((string) $r['currency']); }
            }
            $currency = $currency ?: 'USD';
            $gross = round($gross, 2); $platform = round($platform, 2); $operatorNet = round($operatorNet, 2);

            $mgrPct = $agr ? (float) $agr['manager_commission_pct'] : 0.0;
            $fixed  = $agr ? (float) $agr['fixed_fee'] : 0.0;
            $managerCommission = round($gross * $mgrPct / 100 + $fixed, 2);

            // Expenses in the window not yet attached to a statement.
            $expenses = (float) $db->sum('stays_owner_expenses', 'amount',
                ['stay_id' => $stayId, 'statement_id' => null,
                 'expense_date[>=]' => $from, 'expense_date[<=]' => $to]);
            $expenses = round($expenses, 2);

            $ownerPayout = round($operatorNet - $managerCommission - $expenses, 2);
            return [
                'ok' => true, 'currency' => $currency, 'gross' => $gross,
                'platform_commission' => $platform, 'operator_net' => $operatorNet,
                'manager_commission' => $managerCommission, 'expenses' => $expenses,
                'owner_payout' => $ownerPayout, 'bookings' => count($rows),
                'agreement' => $agr,
            ];
        } catch (\Throwable $e) {
            error_log('owner_statement_preview: ' . $e->getMessage());
            $out['message'] = 'Preview error';
            return $out;
        }
    }
}

if (!function_exists('owner_statement_generate')) {
    /**
     * Persist a statement for a property/period (must have an active agreement with an
     * owner). Attaches in-window unattached expenses to the statement (so they aren't
     * double-counted next period). Returns ['ok','statement_id','message']. Does NOT
     * pay the owner (payout is later/trust-accounting work).
     */
    function owner_statement_generate($db, int $orgId, int $stayId, string $from, string $to): array
    {
        $p = owner_statement_preview($db, $orgId, $stayId, $from, $to);
        if (empty($p['ok'])) { return ['ok' => false, 'message' => $p['message'] ?? 'Preview failed']; }
        $agr = $p['agreement'] ?? null;
        if (!$agr) { return ['ok' => false, 'message' => 'No active management agreement for this property — link an owner first.']; }

        $result = ['ok' => false, 'message' => 'Generate failed'];
        try {
            $db->action(function ($db) use ($orgId, $stayId, $from, $to, $p, $agr, &$result) {
                $db->insert('stays_owner_statements', [
                    'org_id'              => $orgId,
                    'stay_id'             => $stayId,
                    'owner_id'            => (int) $agr['owner_id'],
                    'period_from'         => $from,
                    'period_to'           => $to,
                    'currency'            => $p['currency'],
                    'gross'               => $p['gross'],
                    'platform_commission' => $p['platform_commission'],
                    'operator_net'        => $p['operator_net'],
                    'manager_commission'  => $p['manager_commission'],
                    'expenses'            => $p['expenses'],
                    'owner_payout'        => $p['owner_payout'],
                    'status'              => 'generated',
                    'generated_by'        => (string) ($_SESSION['user_id'] ?? ''),
                    'created_at'          => date('Y-m-d H:i:s'),
                ]);
                $sid = (int) $db->id();
                if ($sid <= 0) { $result = ['ok' => false, 'message' => 'Insert failed']; return false; }
                // Attach the counted expenses so they don't recur on the next statement.
                $db->update('stays_owner_expenses', ['statement_id' => $sid],
                    ['stay_id' => $stayId, 'statement_id' => null,
                     'expense_date[>=]' => $from, 'expense_date[<=]' => $to]);
                $result = ['ok' => true, 'statement_id' => $sid];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('owner_statement_generate: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Generate error'];
        }
        return $result;
    }
}

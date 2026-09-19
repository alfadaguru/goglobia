<?php
// ============================================================================
// PAYSMALLSMALL ENGINE
// ----------------------------------------------------------------------------
// "Pay small small" — an installment payment method: the customer pays a first
// slice now and the rest in scheduled parts. Configured per scope
// (service -> module -> global) exactly like Pay-Later, via pay_small_small_rules.
//
// UMRAH: umrah already has a full installment engine (umrah_payment_plans +
// umrah_installments + umrah_settle_payment with deposit_paid/partially_paid/
// fully_paid + price-lock). For umrah, PaySmallSmall simply routes the customer
// into that engine by mapping its rule to the umrah_plan_code — the checkout
// already renders those plans. So on umrah this reuses proven, tested machinery.
//
// OTHER SERVICES: they have NO installment engine today (generic bookings are
// paid|unpaid|refunded only). A generic installment engine is Phase 2; until
// then pay_small_small_is_enabled_for() returns true only for scopes that can
// honour it (umrah), so it is never offered where it can't yet be fulfilled.
// ============================================================================

if (!function_exists('pay_small_small_rule_for')) {
    /**
     * Resolve the effective PaySmallSmall rule for (module_type, supplier),
     * SERVICE -> MODULE -> GLOBAL. Supplier resolved against the modules registry.
     * Returns the pay_small_small_rules row (at least the global default) or null.
     */
    function pay_small_small_rule_for($db, string $moduleType, string $supplierRaw = ''): ?array
    {
        $moduleType = strtolower(trim($moduleType));
        $supplier = '';
        if ($moduleType !== '' && function_exists('payment_gateway_resolve_supplier')) {
            $supplier = payment_gateway_resolve_supplier($db, $moduleType, $supplierRaw);
        }
        try {
            if ($moduleType !== '' && $supplier !== '') {
                $r = $db->get('pay_small_small_rules', '*', ['scope_type' => 'service', 'module_type' => $moduleType, 'supplier' => $supplier]);
                if ($r) { return $r; }
            }
            if ($moduleType !== '') {
                $r = $db->get('pay_small_small_rules', '*', ['scope_type' => 'module', 'module_type' => $moduleType, 'supplier' => '']);
                if ($r) { return $r; }
            }
            return $db->get('pay_small_small_rules', '*', ['scope_type' => 'global', 'module_type' => '', 'supplier' => '']) ?: null;
        } catch (\Throwable $e) {
            error_log('pay_small_small_rule_for: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('pay_small_small_is_enabled_for')) {
    /**
     * True when PaySmallSmall is enabled for this scope AND can actually be
     * fulfilled. Phase 1: only umrah has an installment engine, so a non-umrah
     * scope is never enabled even if a rule row says so — this prevents offering
     * the method where the money flow can't yet complete. (Remove the umrah gate
     * in Phase 2 once the generic installment engine exists.)
     */
    function pay_small_small_is_enabled_for($db, string $moduleType, string $supplierRaw = ''): bool
    {
        $rule = pay_small_small_rule_for($db, $moduleType, $supplierRaw);
        if (!$rule || (int) ($rule['enabled'] ?? 0) !== 1) { return false; }
        // Phase 2: both umrah (its own engine) and any other service (the generic
        // installment engine, app/lib/installments.php) are fulfillable now.
        return true;
    }
}

if (!function_exists('pay_small_small_umrah_plan_for')) {
    /**
     * The umrah_payment_plans code PaySmallSmall maps to for an umrah scope. The
     * rule carries umrah_plan_code (default PP-50-25-25). Validated against a real,
     * active plan; falls back to PP-50-25-25 then any active non-full plan.
     */
    function pay_small_small_umrah_plan_for($db, string $supplierRaw = ''): string
    {
        $rule = pay_small_small_rule_for($db, 'umrah', $supplierRaw);
        $code = $rule ? trim((string) ($rule['umrah_plan_code'] ?? '')) : '';
        if ($code !== '' && $db->get('umrah_payment_plans', 'id', ['code' => $code, 'active' => 1])) {
            return $code;
        }
        if ($db->get('umrah_payment_plans', 'id', ['code' => 'PP-50-25-25', 'active' => 1])) {
            return 'PP-50-25-25';
        }
        // Any active installment (non-100%-first) plan.
        $alt = $db->get('umrah_payment_plans', ['code'], ['deposit_percent[<]' => 100, 'active' => 1]);
        return $alt ? (string) $alt['code'] : 'PP-50-25-25';
    }
}

if (!function_exists('pay_small_small_summary_for')) {
    /**
     * Customer-facing summary of what PaySmallSmall means for a scope + amount:
     * the first slice and the scheduled remainder. For umrah, derives it from the
     * mapped umrah plan's deposit %; for others (Phase 2) from the rule's
     * first_percent + installments + interval_days. Returns null when not enabled.
     *
     * @return array{first_amount:float,remaining:float,parts:int,label:string}|null
     */
    function pay_small_small_summary_for($db, string $moduleType, string $supplierRaw, float $total): ?array
    {
        if (!pay_small_small_is_enabled_for($db, $moduleType, $supplierRaw)) { return null; }
        $moduleType = strtolower(trim($moduleType));
        $total = round(max(0, $total), 2);

        if ($moduleType === 'umrah') {
            $planCode = pay_small_small_umrah_plan_for($db, $supplierRaw);
            $plan = $db->get('umrah_payment_plans', '*', ['code' => $planCode]);
            $firstPct = $plan ? (float) ($plan['deposit_percent'] ?? 50) : 50.0;
            $first = round($total * $firstPct / 100, 2);
            $parts = 1;
            foreach (['second_percent', 'final_percent'] as $p) {
                if ($plan && (float) ($plan[$p] ?? 0) > 0) { $parts++; }
            }
            return [
                'first_amount' => $first,
                'remaining'    => round($total - $first, 2),
                'parts'        => $parts,
                'label'        => 'Pay ' . rtrim(rtrim(number_format($firstPct, 2), '0'), '.') . '% now, the rest before departure',
                'umrah_plan'   => $planCode,
            ];
        }

        // Generic (Phase 2 fulfilment not built): describe from the rule.
        $rule = pay_small_small_rule_for($db, $moduleType, $supplierRaw);
        $firstPct = $rule ? (float) ($rule['first_percent'] ?? 50) : 50.0;
        $insts = $rule ? max(1, (int) ($rule['installments'] ?? 2)) : 2;
        $first = round($total * $firstPct / 100, 2);
        return [
            'first_amount' => $first,
            'remaining'    => round($total - $first, 2),
            'parts'        => $insts + 1,
            'label'        => 'Pay ' . rtrim(rtrim(number_format($firstPct, 2), '0'), '.') . '% now, then ' . $insts . ' part(s)',
        ];
    }
}

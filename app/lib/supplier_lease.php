<?php
// FILE: app/lib/supplier_lease.php
// Lease & long-stay (#49) + Utilities / meter readings (#51). Per-property; caller
// authorizes the stay. Utilities compute amount = (current − previous) × rate.
// NOT runtime-verified (no MySQL) — logic-reasoned + php -l + harness for the math.

if (!function_exists('lease_cycles')) {
    function lease_cycles(): array { return ['monthly'=>'Monthly','weekly'=>'Weekly','quarterly'=>'Quarterly','yearly'=>'Yearly']; }
}
if (!function_exists('lease_statuses')) {
    function lease_statuses(): array { return ['active'=>'Active','ended'=>'Ended','terminated'=>'Terminated']; }
}
if (!function_exists('lease_add')) {
    function lease_add($db, int $stayId, array $d): int
    {
        $tenant = trim((string) ($d['tenant_name'] ?? ''));
        if ($stayId <= 0 || $tenant === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $cycle = isset(lease_cycles()[$d['rent_cycle'] ?? '']) ? $d['rent_cycle'] : 'monthly';
            $sd = trim((string) ($d['start_date'] ?? '')); $ed = trim((string) ($d['end_date'] ?? ''));
            $db->insert('stays_leases', [
                'org_id'       => $orgId > 0 ? $orgId : null,
                'stay_id'      => $stayId,
                'unit_label'   => substr(trim((string) ($d['unit_label'] ?? '')), 0, 80) ?: null,
                'tenant_name'  => substr($tenant, 0, 120),
                'tenant_email' => trim((string) ($d['tenant_email'] ?? '')) ?: null,
                'tenant_phone' => trim((string) ($d['tenant_phone'] ?? '')) ?: null,
                'rent_amount'  => max(0, round((float) ($d['rent_amount'] ?? 0), 2)),
                'rent_cycle'   => $cycle,
                'deposit'      => ($d['deposit'] ?? '') !== '' ? max(0, round((float) $d['deposit'], 2)) : null,
                'start_date'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd) ? $sd : null,
                'end_date'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed) ? $ed : null,
                'status'       => 'active',
                'currency'     => strtoupper(substr((string) ($d['currency'] ?? 'USD'), 0, 3)),
                'notes'        => substr(trim((string) ($d['notes'] ?? '')), 0, 500) ?: null,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('lease_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('lease_set_status')) {
    function lease_set_status($db, int $id, int $stayId, string $status): bool
    {
        if (!isset(lease_statuses()[$status])) { return false; }
        try {
            if (!$db->has('stays_leases', ['id' => $id, 'stay_id' => $stayId])) { return false; }
            $db->update('stays_leases', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('lease_set_status: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('lease_list')) {
    function lease_list($db, array $stayIds, int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        try { return $db->select('stays_leases', '*', ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('lease_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// UTILITIES / METER READINGS (#51)
// ---------------------------------------------------------------------------
if (!function_exists('utility_amount')) {
    /** Billed amount = max(0, current − previous) × rate, rounded. Pure helper
     *  (harnessed). previous null → consumption = current (first reading). */
    function utility_amount($current, $previous, $rate): float
    {
        $cur = (float) $current;
        $prev = ($previous === null || $previous === '') ? 0.0 : (float) $previous;
        $rt = ($rate === null || $rate === '') ? 0.0 : (float) $rate;
        $consumption = max(0.0, $cur - $prev);
        return round($consumption * $rt, 2);
    }
}
if (!function_exists('utility_add')) {
    function utility_add($db, int $stayId, array $d): int
    {
        $rd = trim((string) ($d['reading_date'] ?? ''));
        if ($stayId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $rd)) { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $util = in_array(($d['utility'] ?? ''), ['electricity','water','gas','internet','other'], true) ? $d['utility'] : 'electricity';
            $prev = ($d['previous_reading'] ?? '') !== '' ? (float) $d['previous_reading'] : null;
            $cur = (float) ($d['current_reading'] ?? 0);
            $rate = ($d['unit_rate'] ?? '') !== '' ? (float) $d['unit_rate'] : null;
            $amount = utility_amount($cur, $prev, $rate);
            $db->insert('stays_utility_readings', [
                'org_id'           => $orgId > 0 ? $orgId : null,
                'stay_id'          => $stayId,
                'lease_id'         => ($d['lease_id'] ?? '') !== '' ? (int) $d['lease_id'] : null,
                'utility'          => $util,
                'reading_date'     => $rd,
                'previous_reading' => $prev,
                'current_reading'  => round($cur, 3),
                'unit_rate'        => $rate,
                'amount'           => $amount,
                'notes'            => substr(trim((string) ($d['notes'] ?? '')), 0, 255) ?: null,
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('utility_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('utility_list')) {
    function utility_list($db, array $stayIds, int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        try { return $db->select('stays_utility_readings', '*', ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('utility_list: ' . $e->getMessage()); return []; }
    }
}

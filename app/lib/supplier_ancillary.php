<?php
// FILE: app/lib/supplier_ancillary.php
// Bookable services: spa/wellness (#32) + generic resources (#33) + recipes/food-cost
// (#31). Per-property; caller authorizes the stay. NOT runtime-verified — logic + php -l
// + a harness for the recipe margin math.

// ---------------------------------------------------------------------------
// SERVICES CATALOGUE + BOOKINGS (#32/#33)
// ---------------------------------------------------------------------------
if (!function_exists('service_kinds')) {
    function service_kinds(): array { return ['spa'=>'Spa','wellness'=>'Wellness','recreation'=>'Recreation','transfer'=>'Transfer','equipment'=>'Equipment','other'=>'Other']; }
}
if (!function_exists('service_add')) {
    function service_add($db, int $stayId, array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($stayId <= 0 || $name === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $kind = isset(service_kinds()[$d['kind'] ?? '']) ? $d['kind'] : 'other';
            $db->insert('stays_services', [
                'org_id' => $orgId > 0 ? $orgId : null, 'stay_id' => $stayId,
                'kind' => $kind, 'name' => substr($name, 0, 191),
                'duration_min' => ($d['duration_min'] ?? '') !== '' ? max(0, (int) $d['duration_min']) : null,
                'price' => max(0, round((float) ($d['price'] ?? 0), 2)),
                'active' => 1, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('service_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('service_list')) {
    function service_list($db, array $stayIds, int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        try { return $db->select('stays_services', '*', ['stay_id' => $stayIds, 'ORDER' => ['name' => 'ASC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('service_list: ' . $e->getMessage()); return []; }
    }
}
if (!function_exists('service_booking_add')) {
    /** Book a service for a guest. Snapshots the price from the service. Returns id. */
    function service_booking_add($db, int $stayId, int $serviceId, array $d): int
    {
        if ($stayId <= 0 || $serviceId <= 0) { return 0; }
        try {
            $svc = $db->get('stays_services', ['id', 'price', 'org_id'], ['id' => $serviceId, 'stay_id' => $stayId]);
            if (!$svc) { return 0; }
            $sched = trim((string) ($d['scheduled_at'] ?? ''));
            $db->insert('stays_service_bookings', [
                'org_id' => $svc['org_id'] !== null ? (int) $svc['org_id'] : null,
                'stay_id' => $stayId, 'service_id' => $serviceId,
                'guest_name' => substr(trim((string) ($d['guest_name'] ?? '')), 0, 120) ?: null,
                'invoice_id' => trim((string) ($d['invoice_id'] ?? '')) ?: null,
                'scheduled_at' => $sched !== '' ? $sched : null,
                'status' => 'booked',
                'price' => round((float) $svc['price'], 2),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('service_booking_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('service_booking_set_status')) {
    function service_booking_set_status($db, int $id, int $stayId, string $status): bool
    {
        if (!in_array($status, ['booked', 'completed', 'cancelled'], true)) { return false; }
        try {
            if (!$db->has('stays_service_bookings', ['id' => $id, 'stay_id' => $stayId])) { return false; }
            $db->update('stays_service_bookings', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('service_booking_set_status: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('service_booking_list')) {
    function service_booking_list($db, array $stayIds, int $limit = 200): array
    {
        if (empty($stayIds)) { return []; }
        try { return $db->select('stays_service_bookings', '*', ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('service_booking_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// RECIPES / FOOD-COST (#31)
// ---------------------------------------------------------------------------
if (!function_exists('recipe_margin')) {
    /** Margin helpers: cost-per-serve, and gross-margin % when a sell price is set.
     *  Pure (harnessed). Returns ['cost_per_serve','margin_pct'|null]. */
    function recipe_margin(float $foodCost, int $yield, $sellPrice): array
    {
        $yield = max(1, $yield);
        $cps = round($foodCost / $yield, 2);
        $marginPct = null;
        if ($sellPrice !== null && $sellPrice !== '' && (float) $sellPrice > 0) {
            $sp = (float) $sellPrice;
            $marginPct = round((($sp - $cps) / $sp) * 100, 1);
        }
        return ['cost_per_serve' => $cps, 'margin_pct' => $marginPct];
    }
}
if (!function_exists('recipe_add')) {
    function recipe_add($db, int $stayId, array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($stayId <= 0 || $name === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $db->insert('stays_recipes', [
                'org_id' => $orgId > 0 ? $orgId : null, 'stay_id' => $stayId,
                'name' => substr($name, 0, 191),
                'yield_qty' => max(1, (int) ($d['yield_qty'] ?? 1)),
                'food_cost' => max(0, round((float) ($d['food_cost'] ?? 0), 2)),
                'sell_price' => ($d['sell_price'] ?? '') !== '' ? max(0, round((float) $d['sell_price'], 2)) : null,
                'notes' => substr(trim((string) ($d['notes'] ?? '')), 0, 500) ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('recipe_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('recipe_list')) {
    function recipe_list($db, array $stayIds, int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        try { return $db->select('stays_recipes', '*', ['stay_id' => $stayIds, 'ORDER' => ['name' => 'ASC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('recipe_list: ' . $e->getMessage()); return []; }
    }
}

<?php
// FILE: app/lib/supplier_facilities.php
// Facility registers (PMS Phase 3): Lost & Found (#29) and Fixed Assets (#28). Simple
// per-property record registers. All functions are property-scoped (the CALLER has
// already authorized the stay via supplier_can) and defensive/non-fatal. NOT
// runtime-verified (no MySQL in the build env) — logic-reasoned + php -l.

// ---------------------------------------------------------------------------
// LOST & FOUND (#29)
// ---------------------------------------------------------------------------
if (!function_exists('lostfound_statuses')) {
    function lostfound_statuses(): array
    {
        return ['stored' => 'Stored', 'claimed' => 'Claimed', 'returned' => 'Returned', 'disposed' => 'Disposed'];
    }
}

if (!function_exists('lostfound_add')) {
    /** Log a found item. Returns id (0 on failure). */
    function lostfound_add($db, int $stayId, array $d): int
    {
        $item = trim((string) ($d['item'] ?? ''));
        if ($stayId <= 0 || $item === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $fd = trim((string) ($d['found_date'] ?? ''));
            $db->insert('stays_lost_found', [
                'org_id'         => $orgId > 0 ? $orgId : null,
                'stay_id'        => $stayId,
                'item'           => substr($item, 0, 191),
                'found_location' => substr(trim((string) ($d['found_location'] ?? '')), 0, 191) ?: null,
                'found_date'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', $fd) ? $fd : null,
                'guest_name'     => substr(trim((string) ($d['guest_name'] ?? '')), 0, 120) ?: null,
                'status'         => 'stored',
                'notes'          => substr(trim((string) ($d['notes'] ?? '')), 0, 500) ?: null,
                'logged_by'      => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('lostfound_add: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('lostfound_set_status')) {
    /** Transition a lost-found item's status. Property-scoped. Returns bool. */
    function lostfound_set_status($db, int $id, int $stayId, string $status): bool
    {
        if (!isset(lostfound_statuses()[$status])) { return false; }
        try {
            if (!$db->has('stays_lost_found', ['id' => $id, 'stay_id' => $stayId])) { return false; }
            $db->update('stays_lost_found', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('lostfound_set_status: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('lostfound_list')) {
    /** Items for a property's set of stays (owner-scoped by the caller). Read-only. */
    function lostfound_list($db, array $stayIds, int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        try {
            return $db->select('stays_lost_found', '*',
                ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) { error_log('lostfound_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// FIXED ASSETS (#28)
// ---------------------------------------------------------------------------
if (!function_exists('asset_conditions')) {
    function asset_conditions(): array
    {
        return ['in_service' => 'In service', 'maintenance' => 'In maintenance', 'retired' => 'Retired'];
    }
}

if (!function_exists('asset_add')) {
    /** Register a fixed asset. Returns id (0 on failure). */
    function asset_add($db, int $stayId, array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($stayId <= 0 || $name === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $pd = trim((string) ($d['purchase_date'] ?? ''));
            $db->insert('stays_assets', [
                'org_id'          => $orgId > 0 ? $orgId : null,
                'stay_id'         => $stayId,
                'name'            => substr($name, 0, 191),
                'category'        => substr(trim((string) ($d['category'] ?? '')), 0, 80) ?: null,
                'location'        => substr(trim((string) ($d['location'] ?? '')), 0, 120) ?: null,
                'serial_no'       => substr(trim((string) ($d['serial_no'] ?? '')), 0, 120) ?: null,
                'purchase_date'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $pd) ? $pd : null,
                'purchase_cost'   => ($d['purchase_cost'] ?? '') !== '' ? max(0, round((float) $d['purchase_cost'], 2)) : null,
                'condition_status'=> 'in_service',
                'notes'           => substr(trim((string) ($d['notes'] ?? '')), 0, 500) ?: null,
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('asset_add: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('asset_set_condition')) {
    /** Set an asset's condition (in_service|maintenance|retired). Property-scoped. */
    function asset_set_condition($db, int $id, int $stayId, string $condition): bool
    {
        if (!isset(asset_conditions()[$condition])) { return false; }
        try {
            if (!$db->has('stays_assets', ['id' => $id, 'stay_id' => $stayId])) { return false; }
            $db->update('stays_assets', ['condition_status' => $condition, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('asset_set_condition: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('asset_list')) {
    /** Assets for a property's set of stays. Read-only. */
    function asset_list($db, array $stayIds, int $limit = 500): array
    {
        if (empty($stayIds)) { return []; }
        try {
            return $db->select('stays_assets', '*',
                ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) { error_log('asset_list: ' . $e->getMessage()); return []; }
    }
}

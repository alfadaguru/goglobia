<?php
// FILE: app/lib/supplier_property.php
// MODULE 02 (property setup & configuration → 360): structured house rules,
// per-property timezone, and a first-class buildings/blocks + floors/wings registry.
// Org/owner scoped; additive to the existing stays property CRUD. NOT runtime-verified.

// ---------------------------------------------------------------------------
// HOUSE RULES (structured) — stored as JSON in stays.house_rules
// ---------------------------------------------------------------------------
if (!function_exists('house_rules_fields')) {
    /** The canonical structured house-rule fields (bool unless noted). */
    function house_rules_fields(): array
    {
        return [
            'smoking_allowed'   => 'Smoking allowed',
            'pets_allowed'      => 'Pets allowed',
            'parties_allowed'   => 'Parties / events allowed',
            'children_allowed'  => 'Children welcome',
            'quiet_hours'       => 'Quiet hours (e.g. 22:00–07:00)', // text
            'additional'        => 'Additional rules (free text)',    // text
        ];
    }
}
if (!function_exists('house_rules_normalize')) {
    /** Build the stored JSON from a POST array. Bools coerced; text trimmed+capped. */
    function house_rules_normalize(array $in): string
    {
        $out = [
            'smoking_allowed'  => !empty($in['smoking_allowed']),
            'pets_allowed'     => !empty($in['pets_allowed']),
            'parties_allowed'  => !empty($in['parties_allowed']),
            'children_allowed' => isset($in['children_allowed']) ? !empty($in['children_allowed']) : true,
            'quiet_hours'      => substr(trim((string) ($in['quiet_hours'] ?? '')), 0, 60),
            'additional'       => substr(trim((string) ($in['additional'] ?? '')), 0, 1000),
        ];
        return json_encode($out);
    }
}
if (!function_exists('house_rules_decode')) {
    /** Decode stored house_rules JSON to an array (defaults if empty/invalid). */
    function house_rules_decode(?string $json): array
    {
        $d = json_decode((string) $json, true);
        if (!is_array($d)) { $d = []; }
        return [
            'smoking_allowed'  => !empty($d['smoking_allowed']),
            'pets_allowed'     => !empty($d['pets_allowed']),
            'parties_allowed'  => !empty($d['parties_allowed']),
            'children_allowed' => array_key_exists('children_allowed', $d) ? !empty($d['children_allowed']) : true,
            'quiet_hours'      => (string) ($d['quiet_hours'] ?? ''),
            'additional'       => (string) ($d['additional'] ?? ''),
        ];
    }
}

// ---------------------------------------------------------------------------
// TIMEZONE
// ---------------------------------------------------------------------------
if (!function_exists('property_timezone_valid')) {
    /** True if $tz is a known IANA zone (or '' to clear). */
    function property_timezone_valid(string $tz): bool
    {
        $tz = trim($tz);
        if ($tz === '') { return true; }
        return in_array($tz, timezone_identifiers_list(), true);
    }
}

// ---------------------------------------------------------------------------
// BUILDINGS / BLOCKS / WINGS (stays_buildings)
// ---------------------------------------------------------------------------
if (!function_exists('building_kinds')) {
    function building_kinds(): array { return ['building', 'block', 'wing', 'tower']; }
}
if (!function_exists('building_add')) {
    /** Add a building/block/wing/tower to a property the owner owns. Returns id or 0. */
    function building_add($db, int $orgId, int $stayId, array $in): int
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($stayId <= 0 || $name === '') { return 0; }
        $kind = in_array(($in['kind'] ?? ''), building_kinds(), true) ? $in['kind'] : 'building';
        try {
            $db->insert('stays_buildings', [
                'org_id'  => $orgId > 0 ? $orgId : null,
                'stay_id' => $stayId,
                'name'    => substr($name, 0, 120),
                'kind'    => $kind,
                'floors'  => substr(trim((string) ($in['floors'] ?? '')), 0, 255) ?: null,
                'sort'    => (int) ($in['sort'] ?? 0),
                'active'  => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('building_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('building_list')) {
    function building_list($db, int $stayId): array
    {
        if ($stayId <= 0) { return []; }
        try { return $db->select('stays_buildings', '*', ['stay_id' => $stayId, 'ORDER' => ['sort' => 'ASC', 'id' => 'ASC']]) ?: []; }
        catch (\Throwable $e) { error_log('building_list: ' . $e->getMessage()); return []; }
    }
}
if (!function_exists('building_update')) {
    function building_update($db, int $stayId, int $buildingId, array $in): bool
    {
        if ($stayId <= 0 || $buildingId <= 0) { return false; }
        try {
            if (!$db->has('stays_buildings', ['id' => $buildingId, 'stay_id' => $stayId])) { return false; }
            $fields = ['updated_at' => date('Y-m-d H:i:s')];
            if (isset($in['name']) && trim((string) $in['name']) !== '') { $fields['name'] = substr(trim((string) $in['name']), 0, 120); }
            if (isset($in['kind']) && in_array($in['kind'], building_kinds(), true)) { $fields['kind'] = $in['kind']; }
            if (array_key_exists('floors', $in)) { $fields['floors'] = substr(trim((string) $in['floors']), 0, 255) ?: null; }
            if (isset($in['sort'])) { $fields['sort'] = (int) $in['sort']; }
            if (isset($in['active'])) { $fields['active'] = !empty($in['active']) ? 1 : 0; }
            $db->update('stays_buildings', $fields, ['id' => $buildingId, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('building_update: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('building_delete')) {
    /** Delete a building; detaches any physical rooms (building_id → null). */
    function building_delete($db, int $stayId, int $buildingId): bool
    {
        if ($stayId <= 0 || $buildingId <= 0) { return false; }
        try {
            if (!$db->has('stays_buildings', ['id' => $buildingId, 'stay_id' => $stayId])) { return false; }
            $db->action(function ($db) use ($stayId, $buildingId) {
                // Detach physical rooms from this building AND its floors.
                $floorIds = $db->select('stays_floors', ['id'], ['stay_id' => $stayId, 'building_id' => $buildingId]);
                $floorIds = is_array($floorIds) ? array_map('intval', array_column($floorIds, 'id')) : [];
                if (!empty($floorIds)) {
                    $db->update('stays_physical_rooms', ['floor_id' => null], ['stay_id' => $stayId, 'floor_id' => $floorIds]);
                }
                $db->update('stays_physical_rooms', ['building_id' => null], ['stay_id' => $stayId, 'building_id' => $buildingId]);
                $db->delete('stays_floors', ['stay_id' => $stayId, 'building_id' => $buildingId]);
                $db->delete('stays_buildings', ['id' => $buildingId, 'stay_id' => $stayId]);
            });
            return true;
        } catch (\Throwable $e) { error_log('building_delete: ' . $e->getMessage()); return false; }
    }
}

// ---------------------------------------------------------------------------
// FLOORS — first-class levels under a building. All ops scoped by stay_id (the
// route layer proves ownership via supplier_can before calling these).
// ---------------------------------------------------------------------------
if (!function_exists('floor_add')) {
    /** Add a floor under a building. Returns new id, or 0 on failure/validation. */
    function floor_add($db, int $orgId, int $stayId, int $buildingId, array $in): int
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($stayId <= 0 || $buildingId <= 0 || $name === '') { return 0; }
        try {
            // The building must belong to this property.
            if (!$db->has('stays_buildings', ['id' => $buildingId, 'stay_id' => $stayId])) { return 0; }
            $db->insert('stays_floors', [
                'org_id'      => $orgId > 0 ? $orgId : null,
                'stay_id'     => $stayId,
                'building_id' => $buildingId,
                'name'        => substr($name, 0, 60),
                'sort'        => (int) ($in['sort'] ?? 0),
                'active'      => 1,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('floor_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('floor_list')) {
    /** Floors for a property (optionally one building), ordered. */
    function floor_list($db, int $stayId, int $buildingId = 0): array
    {
        if ($stayId <= 0) { return []; }
        $where = ['stay_id' => $stayId];
        if ($buildingId > 0) { $where['building_id'] = $buildingId; }
        $where['ORDER'] = ['sort' => 'ASC', 'id' => 'ASC'];
        try { return $db->select('stays_floors', '*', $where) ?: []; }
        catch (\Throwable $e) { error_log('floor_list: ' . $e->getMessage()); return []; }
    }
}
if (!function_exists('floor_update')) {
    function floor_update($db, int $stayId, int $floorId, array $in): bool
    {
        if ($stayId <= 0 || $floorId <= 0) { return false; }
        try {
            if (!$db->has('stays_floors', ['id' => $floorId, 'stay_id' => $stayId])) { return false; }
            $fields = ['updated_at' => date('Y-m-d H:i:s')];
            if (isset($in['name']) && trim((string) $in['name']) !== '') { $fields['name'] = substr(trim((string) $in['name']), 0, 60); }
            if (isset($in['sort'])) { $fields['sort'] = (int) $in['sort']; }
            if (isset($in['active'])) { $fields['active'] = !empty($in['active']) ? 1 : 0; }
            $db->update('stays_floors', $fields, ['id' => $floorId, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('floor_update: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('floor_delete')) {
    /** Delete a floor; detaches any physical rooms (floor_id → null). */
    function floor_delete($db, int $stayId, int $floorId): bool
    {
        if ($stayId <= 0 || $floorId <= 0) { return false; }
        try {
            if (!$db->has('stays_floors', ['id' => $floorId, 'stay_id' => $stayId])) { return false; }
            $db->action(function ($db) use ($stayId, $floorId) {
                $db->update('stays_physical_rooms', ['floor_id' => null], ['stay_id' => $stayId, 'floor_id' => $floorId]);
                $db->delete('stays_floors', ['id' => $floorId, 'stay_id' => $stayId]);
            });
            return true;
        } catch (\Throwable $e) { error_log('floor_delete: ' . $e->getMessage()); return false; }
    }
}

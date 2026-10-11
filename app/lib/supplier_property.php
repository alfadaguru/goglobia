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
                $db->update('stays_physical_rooms', ['building_id' => null], ['stay_id' => $stayId, 'building_id' => $buildingId]);
                $db->delete('stays_buildings', ['id' => $buildingId, 'stay_id' => $stayId]);
            });
            return true;
        } catch (\Throwable $e) { error_log('building_delete: ' . $e->getMessage()); return false; }
    }
}

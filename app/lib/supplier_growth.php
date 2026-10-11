<?php
// FILE: app/lib/supplier_growth.php
// Advanced growth / external-edge modules (PMS Phase 3):
//   #03 Multi-property / brands  — REAL brand CRUD on the existing supplier_brands table.
//   #12 Channel manager, #14 RMS, #57 AI — EXTERNAL-DEPENDENCY: config is persisted
//        internally (stays_integration_config), but the vendor adapter is a STUB that
//        honestly reports "not connected". Never faked as working.
// Org-scoped (owner-level). NOT runtime-verified — logic + php -l.

// ---------------------------------------------------------------------------
// BRANDS (#03) — real
// ---------------------------------------------------------------------------
if (!function_exists('brand_add')) {
    function brand_add($db, int $orgId, string $name): int
    {
        $name = trim($name);
        if ($orgId <= 0 || $name === '') { return 0; }
        try {
            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
            $db->insert('supplier_brands', [
                'org_id' => $orgId, 'name' => substr($name, 0, 191),
                'slug' => $slug ?: null, 'status' => 1, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('brand_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('brand_list')) {
    function brand_list($db, int $orgId): array
    {
        if ($orgId <= 0) { return []; }
        try { return $db->select('supplier_brands', '*', ['org_id' => $orgId, 'ORDER' => ['name' => 'ASC']]) ?: []; }
        catch (\Throwable $e) { error_log('brand_list: ' . $e->getMessage()); return []; }
    }
}
if (!function_exists('brand_assign_property')) {
    /** Assign a property to a brand (or clear with brandId=0). Both must belong to $orgId. */
    function brand_assign_property($db, int $orgId, int $stayId, int $brandId): bool
    {
        if ($orgId <= 0 || $stayId <= 0) { return false; }
        try {
            // A property's stays.org_id may never have been backfilled (null). Resolve +
            // stamp it so the ownership check below matches, instead of silently no-op'ing.
            $propOrg = 0;
            if (function_exists('supplier_property_org_ensure')) {
                $propOrg = (int) supplier_property_org_ensure($db, $stayId);
            }
            if ($propOrg <= 0) {
                $r = $db->get('stays', ['org_id'], ['id' => $stayId]);
                $propOrg = $r ? (int) ($r['org_id'] ?? 0) : 0;
            }
            if ($propOrg !== $orgId) { return false; } // not this owner's property
            if ($brandId > 0 && !$db->has('supplier_brands', ['id' => $brandId, 'org_id' => $orgId])) { return false; }
            $db->update('stays', ['brand_id' => $brandId > 0 ? $brandId : null, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $stayId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('brand_assign_property: ' . $e->getMessage()); return false; }
    }
}

// ---------------------------------------------------------------------------
// INTEGRATION CONFIG (#12/#14/#57) — persisted internally; adapter is a STUB
// ---------------------------------------------------------------------------
if (!function_exists('integration_catalogue')) {
    /** The external integrations a supplier can configure. Each is 'stub' status until
     *  the platform wires a real adapter (needs vendor accounts / infra). */
    function integration_catalogue(): array
    {
        return [
            'channel_manager' => ['label' => 'Channel manager', 'note' => 'Sync rates & availability to OTAs (Booking.com, Expedia, …). Needs an OTA/channel-manager account configured by the platform administrator.'],
            'rms'             => ['label' => 'Revenue management', 'note' => 'Automated dynamic pricing from demand signals. Needs the revenue engine to be provisioned by the platform.'],
            'ai_assistant'    => ['label' => 'AI assistant', 'note' => 'AI concierge / revenue insights. Needs an AI provider key configured by the platform administrator.'],
        ];
    }
}
if (!function_exists('integration_config_get')) {
    function integration_config_get($db, int $orgId, string $integration): array
    {
        $out = ['enabled' => false, 'config' => []];
        if ($orgId <= 0) { return $out; }
        try {
            $row = $db->get('stays_integration_config', ['enabled', 'config_json'], ['org_id' => $orgId, 'integration' => $integration]);
            if ($row) {
                $out['enabled'] = (int) ($row['enabled'] ?? 0) === 1;
                $dec = json_decode((string) ($row['config_json'] ?? ''), true);
                $out['config'] = is_array($dec) ? $dec : [];
            }
        } catch (\Throwable $e) { error_log('integration_config_get: ' . $e->getMessage()); }
        return $out;
    }
}
if (!function_exists('integration_config_save')) {
    function integration_config_save($db, int $orgId, string $integration, bool $enabled, array $config): bool
    {
        if ($orgId <= 0 || !isset(integration_catalogue()[$integration])) { return false; }
        try {
            $fields = ['enabled' => $enabled ? 1 : 0, 'config_json' => json_encode($config), 'updated_at' => date('Y-m-d H:i:s')];
            if ($db->has('stays_integration_config', ['org_id' => $orgId, 'integration' => $integration])) {
                $db->update('stays_integration_config', $fields, ['org_id' => $orgId, 'integration' => $integration]);
            } else {
                $db->insert('stays_integration_config', array_merge($fields, ['org_id' => $orgId, 'integration' => $integration, 'created_at' => date('Y-m-d H:i:s')]));
            }
            return true;
        } catch (\Throwable $e) { error_log('integration_config_save: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('integration_status')) {
    /**
     * HONEST adapter status. An integration is 'connected' ONLY if a real adapter
     * function exists for it (none ship yet) — otherwise it is 'stub' (enabled in
     * config but not actually syncing). This never claims a stub is working.
     */
    function integration_status($db, int $orgId, string $integration): string
    {
        $cfg = integration_config_get($db, $orgId, $integration);
        if (!$cfg['enabled']) { return 'off'; }
        // A real adapter would be a function like channel_manager_sync(); none exist yet.
        $adapterFn = $integration . '_adapter';
        if (function_exists($adapterFn)) { return 'connected'; }
        return 'stub'; // enabled, but no vendor adapter wired → not actually syncing
    }
}

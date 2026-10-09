<?php
// FILE: app/lib/supplier_hierarchy.php
// ORG -> BRAND -> PROPERTY -> UNIT hierarchy helpers (Phase 1 inc S14; docs 01a §1).
//
// An ADDITIVE SEAM. Ownership still resolves on stays.user_id everywhere; these
// helpers let the FUTURE ERP domains (finance, housekeeping, channel manager, multi-
// property RBAC) attach to a stable hierarchy key (org_id / property_id / unit_id).
// Nothing in the live booking/detail/listing path depends on them. The tables +
// stays.org_id/brand_id/accommodation_type columns are created and backfilled by
// ensureSupplierStaysSchema(); these functions keep them populated going forward.
//
// Mapping in this phase:
//   org       = a supplier (users.user_id owner), promoted 1:1
//   brand     = optional grouping within an org (table ready; UI later)
//   property  = a stays row (stays.org_id -> supplier_orgs.id)
//   unit      = a stays_rooms row (room/apartment) — no new table needed yet
//
// All functions are defensive / non-fatal (mirror the other supplier helpers).

if (!function_exists('supplier_org_ensure')) {
    /**
     * Idempotently get (or create) the org for a supplier owner, returning its id
     * (0 on failure or when the owner is not a real user). Safe to call on every
     * property create so new suppliers always have an org.
     */
    function supplier_org_ensure($db, string $ownerUserId): int
    {
        $ownerUserId = trim($ownerUserId);
        if ($ownerUserId === '') { return 0; }
        try {
            $existing = $db->get('supplier_orgs', ['id'], ['owner_user_id' => $ownerUserId]);
            if ($existing) { return (int) $existing['id']; }

            // Only promote REAL users to an org (guards against legacy/seed owner ids).
            $u = $db->get('users', ['first_name', 'last_name', 'title', 'currency'],
                ['user_id' => $ownerUserId]);
            if (!$u) { return 0; }

            $orgName = trim((string) ($u['title'] ?? '')) !== ''
                ? (string) $u['title']
                : trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? ''));
            $db->insert('supplier_orgs', [
                'owner_user_id' => $ownerUserId,
                'name'          => $orgName !== '' ? $orgName : null,
                'base_currency' => $u['currency'] ?? null,
                'status'        => 1,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) {
            error_log('supplier_org_ensure: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('supplier_org_for_owner')) {
    /** The org id for an owner (0 if none / not yet created). Read-only. */
    function supplier_org_for_owner($db, string $ownerUserId): int
    {
        $ownerUserId = trim($ownerUserId);
        if ($ownerUserId === '') { return 0; }
        try {
            $row = $db->get('supplier_orgs', ['id'], ['owner_user_id' => $ownerUserId]);
            return $row ? (int) $row['id'] : 0;
        } catch (\Throwable $e) {
            error_log('supplier_org_for_owner: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('supplier_property_org')) {
    /** The org id stamped on a property (stays.org_id), or 0. Read-only resolver. */
    function supplier_property_org($db, int $stayId): int
    {
        if ($stayId <= 0) { return 0; }
        try {
            $row = $db->get('stays', ['org_id'], ['id' => $stayId]);
            return $row && $row['org_id'] !== null ? (int) $row['org_id'] : 0;
        } catch (\Throwable $e) {
            error_log('supplier_property_org: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('supplier_accommodation_types')) {
    /** The supported property operating models (value => label). Config, not DB. */
    function supplier_accommodation_types(): array
    {
        return [
            'hotel'      => 'Hotel',
            'resort'     => 'Resort',
            'aparthotel' => 'Aparthotel',
            'short_let'  => 'Short-let / Serviced apartment',
            'hostel'     => 'Hostel',
            'villa'      => 'Villa',
            'long_stay'  => 'Long-stay / Corporate',
        ];
    }
}

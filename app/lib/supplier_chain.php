<?php
// FILE: app/lib/supplier_chain.php
// MODULE 03 (multi-property / chain management → 360). A CORPORATE/PORTFOLIO layer
// that aggregates ACROSS all properties an owner's org controls — on top of the
// existing per-property data (stays, bookings, supplier_earnings, stays_guest_profiles,
// stays_holds) + brands (supplier_growth.php) + org (supplier_hierarchy.php).
//
// Everything here is READ-ONLY aggregation + brand/region grouping. It never bypasses
// per-property ownership: callers pass the owner's own stay-id set. NOT runtime-verified.

if (!function_exists('chain_property_rows')) {
    /** All properties in the owner's portfolio with brand + region + status. */
    function chain_property_rows($db, int $orgId, string $owner): array
    {
        try {
            $where = ['user_id' => $owner];
            $rows = $db->select('stays',
                ['id', 'name', 'location', 'status', 'listing_status', 'brand_id', 'accommodation_type', 'currency'],
                array_merge($where, ['ORDER' => ['name' => 'ASC']])) ?: [];
            // Attach brand names.
            $brands = [];
            if ($orgId > 0 && function_exists('brand_list')) {
                foreach (brand_list($db, $orgId) as $b) { $brands[(int) $b['id']] = (string) $b['name']; }
            }
            foreach ($rows as &$r) {
                $r['brand_name'] = $brands[(int) ($r['brand_id'] ?? 0)] ?? null;
                // "region" = the first token of location (city/area) — a pragmatic grouping
                // until a dedicated region field exists.
                $loc = (string) ($r['location'] ?? '');
                $r['region'] = $loc !== '' ? trim(explode(',', $loc)[0]) : '—';
            }
            unset($r);
            return $rows;
        } catch (\Throwable $e) { error_log('chain_property_rows: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('chain_portfolio_summary')) {
    /**
     * Consolidated portfolio KPIs across all owned properties:
     * counts by status, by brand, by region, by accommodation type, plus revenue
     * (from supplier_earnings, grouped by currency so currencies never net).
     */
    function chain_portfolio_summary($db, int $orgId, string $owner): array
    {
        $out = [
            'properties' => 0, 'live' => 0, 'draft' => 0,
            'by_brand' => [], 'by_region' => [], 'by_type' => [],
            'revenue_by_currency' => [], 'bookings' => 0,
        ];
        $rows = chain_property_rows($db, $orgId, $owner);
        $out['properties'] = count($rows);
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r['id'];
            if ((int) $r['status'] === 1 && (string) $r['listing_status'] === 'approved') { $out['live']++; }
            if ((string) $r['listing_status'] === 'draft') { $out['draft']++; }
            $bn = $r['brand_name'] ?: '— no brand —';
            $out['by_brand'][$bn] = ($out['by_brand'][$bn] ?? 0) + 1;
            $out['by_region'][$r['region']] = ($out['by_region'][$r['region']] ?? 0) + 1;
            $t = (string) ($r['accommodation_type'] ?? 'hotel');
            $out['by_type'][$t] = ($out['by_type'][$t] ?? 0) + 1;
        }
        if ($ids) {
            try {
                // Revenue across the portfolio, grouped by currency (never summed flat).
                $earn = $db->select('supplier_earnings', ['currency', 'net_amount'],
                    ['stay_id' => $ids, 'state[!]' => 'void']) ?: [];
                foreach ($earn as $e) {
                    $c = strtoupper((string) ($e['currency'] ?? 'USD')) ?: 'USD';
                    $out['revenue_by_currency'][$c] = round(($out['revenue_by_currency'][$c] ?? 0) + (float) $e['net_amount'], 2);
                }
                // Bookings in the portfolio = earning rows (one accrual per paid booking),
                // which are already scoped to the owner's stay ids above. (bookings has no
                // direct stay_id column — a global count would be wrong/misleading.)
                $out['bookings'] = count($earn);
            } catch (\Throwable $e) { error_log('chain_portfolio_summary rev: ' . $e->getMessage()); }
        }
        return $out;
    }
}

if (!function_exists('chain_cross_availability')) {
    /**
     * Cross-property availability for a date range: for each owned property, the number
     * of physical rooms NOT blocked by an active hold on the arrival night. A central-
     * reservations aid ("which of my hotels can take this guest?"). Read-only, best-effort.
     */
    function chain_cross_availability($db, string $owner, string $date): array
    {
        $out = [];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return $out; }
        try {
            $props = $db->select('stays', ['id', 'name', 'location'],
                ['user_id' => $owner, 'status' => '1', 'listing_status' => 'approved']) ?: [];
            foreach ($props as $p) {
                $sid = (int) $p['id'];
                $phys = (int) $db->count('stays_physical_rooms', ['stay_id' => $sid, 'active' => 1]);
                // Active committed holds covering that night (held+unexpired OR consumed).
                $held = 0;
                try {
                    $stmt = $db->query(
                        "SELECT COALESCE(SUM(qty),0) AS q FROM stays_holds WHERE stay_id=:s AND date_from<=:d AND date_to>:d AND (state='consumed' OR (state='held' AND expires_at>NOW()))",
                        [':s' => $sid, ':d' => $date]
                    );
                    $held = $stmt ? (int) ($stmt->fetch(\PDO::FETCH_ASSOC)['q'] ?? 0) : 0;
                } catch (\Throwable $e) {}
                $out[] = [
                    'stay_id' => $sid, 'name' => (string) $p['name'], 'location' => (string) $p['location'],
                    'physical_rooms' => $phys, 'held' => $held, 'free' => max(0, $phys - $held),
                ];
            }
        } catch (\Throwable $e) { error_log('chain_cross_availability: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('chain_guest_directory')) {
    /**
     * Centralized guest database across the portfolio. Delegates to guest_list()
     * (supplier_guests.php), which already aggregates unique guests by email from the
     * owner's bookings ACROSS all given stay ids — so passing the full owned-stay-id set
     * yields the cross-property guest directory (name, stays, total_spent, last_seen,
     * and the set of properties each guest has stayed at). Read-only.
     */
    function chain_guest_directory($db, array $stayIds, int $limit = 200): array
    {
        if (empty($stayIds) || !function_exists('guest_list')) { return []; }
        $list = guest_list($db, $stayIds);
        // Only the multi-property guests are "chain" guests, but return all (sorted by
        // recency already) capped at $limit; the view highlights multi-property ones.
        return array_slice($list, 0, max(1, $limit));
    }
}

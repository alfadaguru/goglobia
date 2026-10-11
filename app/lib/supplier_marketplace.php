<?php
// FILE: app/lib/supplier_marketplace.php
// MODULE 11 (GoGlobia marketplace management → 360). Supplier-facing MARKETPLACE
// PERFORMANCE + the platform COMMISSION the supplier is on. Read-only composition over
// existing data (supplier_earning_summary, supplier_service_quota, stays listing states
// + bookings) — no new tables. NOT runtime-verified.

if (!function_exists('marketplace_performance')) {
    /**
     * A marketplace scorecard for an owner across their stays:
     *   - listings: total / live (approved+status1) / pending-review / draft
     *   - earnings by currency (pending/available/paid/count) from supplier_earning_summary
     *   - commission_pct the stays service is on (platform rate) + quota used/max
     *   - bookings: paid own-inventory stays bookings (count) — a volume proxy
     * Returns a structured array for the view.
     */
    function marketplace_performance($db, string $owner): array
    {
        $out = [
            'listings' => ['total' => 0, 'live' => 0, 'pending' => 0, 'draft' => 0, 'rejected' => 0],
            'earnings' => [], 'commission_pct' => null, 'quota' => ['max' => null, 'used' => 0, 'approved' => false],
            'bookings_paid' => 0,
        ];
        if ($owner === '') { return $out; }
        try {
            $stays = $db->select('stays', ['id', 'status', 'listing_status'], ['user_id' => $owner]) ?: [];
            foreach ($stays as $s) {
                $out['listings']['total']++;
                $ls = (string) ($s['listing_status'] ?? 'draft');
                $live = (int) $s['status'] === 1 && $ls === 'approved';
                if ($live) { $out['listings']['live']++; }
                elseif ($ls === 'submitted' || $ls === 'queried') { $out['listings']['pending']++; }
                elseif ($ls === 'rejected') { $out['listings']['rejected']++; }
                else { $out['listings']['draft']++; }
            }
            if (function_exists('supplier_earning_summary')) { $out['earnings'] = supplier_earning_summary($db, $owner); }
            if (function_exists('supplier_service_quota')) {
                $q = supplier_service_quota($db, $owner, 'stays');
                $out['commission_pct'] = $q['commission_pct'] ?? null;
                $out['quota'] = ['max' => $q['max'] ?? null, 'used' => (int) ($q['used'] ?? 0), 'approved' => !empty($q['approved'])];
            }
            // Paid own-inventory stays bookings = earning rows (one per paid booking),
            // scoped to the owner — a volume proxy without a full bookings scan.
            try { $out['bookings_paid'] = (int) $db->count('supplier_earnings', ['owner_user_id' => $owner, 'state[!]' => 'void']); } catch (\Throwable $e) {}
        } catch (\Throwable $e) { error_log('marketplace_performance: ' . $e->getMessage()); }
        return $out;
    }
}

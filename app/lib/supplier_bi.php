<?php
// FILE: app/lib/supplier_bi.php
// BI / OPERATIONS DASHBOARD (Phase 1 inc S28; docs 01 §4.6). READ-ONLY.
//
// Surfaces what the prior increments already produce — no new facts, no writes. Every
// metric maps to a REAL table:
//   earnings (pending/available/paid)  → supplier_earnings            (S18)
//   occupancy / ADR / RevPAR trend     → stays_night_audits           (S25)
//   F&B sales (+ by tender)            → pos_orders + pos_payments     (S24)
//   channel mix                        → bookings.booking_data.source  (S27/live)
//   reservations by status            → bookings                      (S11)
//   housekeeping status counts        → stays_physical_rooms          (S22)
//   open work orders                  → stays_work_orders             (S23)
//
// All helpers are defensive/non-fatal and operate on an OWNER's stay-id set (resolved
// + ownership-checked by the caller route). Nothing here mutates data.

if (!function_exists('bi_reservations_by_status')) {
    /** Count own-inventory reservations for the owner's properties by booking_status,
     *  with a channel-mix breakdown from booking_data.source. Bounded scan + decoded
     *  verify (not just LIKE). Returns ['status'=>[...], 'channels'=>[...], 'total'=>int]. */
    function bi_reservations_by_status($db, array $stayIds, int $limit = 2000): array
    {
        $status = ['confirmed' => 0, 'pending' => 0, 'cancelled' => 0];
        $channels = ['marketplace' => 0, 'direct_site' => 0, 'walk_in' => 0, 'other' => 0];
        $total = 0;
        if (empty($stayIds)) { return ['status' => $status, 'channels' => $channels, 'total' => 0]; }
        $set = array_fill_keys(array_map('intval', $stayIds), true);
        $likeValues = [];
        foreach ($stayIds as $sid) { $likeValues[] = '"hotel_id":' . (int) $sid; }
        try {
            $rows = $db->select('bookings', ['booking_status', 'booking_data'],
                ['module_type' => ['stays', 'hotels'], 'booking_data[~]' => $likeValues,
                 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
            foreach ($rows as $r) {
                $bd = json_decode((string) ($r['booking_data'] ?? ''), true);
                if (!is_array($bd) || empty($set[(int) ($bd['hotel_id'] ?? 0)])) { continue; } // verify
                $total++;
                $st = strtolower((string) ($r['booking_status'] ?? ''));
                if (isset($status[$st])) { $status[$st]++; }
                $src = (string) ($bd['source'] ?? 'marketplace');
                if ($src === '' ) { $src = 'marketplace'; }
                if (isset($channels[$src])) { $channels[$src]++; } else { $channels['other']++; }
            }
        } catch (\Throwable $e) { error_log('bi_reservations_by_status: ' . $e->getMessage()); }
        return ['status' => $status, 'channels' => $channels, 'total' => $total];
    }
}

if (!function_exists('bi_audit_trend')) {
    /** The most recent night-audit snapshots for a property (chronological asc), for
     *  the occupancy/ADR/RevPAR trend. Read-only from stays_night_audits. */
    function bi_audit_trend($db, int $stayId, int $days = 30): array
    {
        if ($stayId <= 0) { return []; }
        try {
            $rows = $db->select('stays_night_audits',
                ['business_date', 'rooms_sold', 'rooms_available', 'room_revenue', 'adr', 'revpar', 'occupancy_pct'],
                ['stay_id' => $stayId, 'ORDER' => ['business_date' => 'DESC'], 'LIMIT' => max(1, $days)]) ?: [];
            return array_reverse($rows); // chronological for charting
        } catch (\Throwable $e) {
            error_log('bi_audit_trend: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('bi_fnb_sales')) {
    /** F&B sales across the owner's properties: total settled-order payment amount +
     *  split by tender (cash vs room-charge). Reads pos_orders (settled) + pos_payments. */
    function bi_fnb_sales($db, array $stayIds): array
    {
        $out = ['total' => 0.0, 'by_tender' => ['cash' => 0.0, 'room' => 0.0, 'card' => 0.0, 'wallet' => 0.0], 'orders' => 0];
        if (empty($stayIds)) { return $out; }
        try {
            // Settled orders for these properties.
            $orders = $db->select('pos_orders', ['id'], ['stay_id' => $stayIds, 'status' => 'settled']) ?: [];
            $out['orders'] = count($orders);
            if (empty($orders)) { return $out; }
            $orderIds = array_map(fn($o) => (int) $o['id'], $orders);
            $pays = $db->select('pos_payments', ['tender', 'amount'], ['order_id' => $orderIds]) ?: [];
            foreach ($pays as $p) {
                $amt = round((float) $p['amount'], 2);
                $t = (string) $p['tender'];
                $out['total'] += $amt;
                if (isset($out['by_tender'][$t])) { $out['by_tender'][$t] += $amt; }
            }
            $out['total'] = round($out['total'], 2);
            foreach ($out['by_tender'] as $k => $v) { $out['by_tender'][$k] = round($v, 2); }
        } catch (\Throwable $e) { error_log('bi_fnb_sales: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('bi_ops_counts')) {
    /** Operational counts for the owner's properties: housekeeping status breakdown +
     *  open/in-progress work orders. Read-only. */
    function bi_ops_counts($db, array $stayIds): array
    {
        $hk = ['clean' => 0, 'dirty' => 0, 'inspected' => 0, 'out_of_order' => 0];
        $wo = ['open' => 0, 'in_progress' => 0];
        if (empty($stayIds)) { return ['hk' => $hk, 'work_orders' => $wo]; }
        try {
            foreach ($db->select('stays_physical_rooms', ['hk_status'], ['stay_id' => $stayIds, 'active' => 1]) ?: [] as $r) {
                $s = (string) $r['hk_status'];
                if (isset($hk[$s])) { $hk[$s]++; }
            }
            $wo['open'] = (int) $db->count('stays_work_orders', ['stay_id' => $stayIds, 'status' => 'open']);
            $wo['in_progress'] = (int) $db->count('stays_work_orders', ['stay_id' => $stayIds, 'status' => 'in_progress']);
        } catch (\Throwable $e) { error_log('bi_ops_counts: ' . $e->getMessage()); }
        return ['hk' => $hk, 'work_orders' => $wo];
    }
}

if (!function_exists('bi_dashboard')) {
    /**
     * Assemble the whole read-only dashboard payload for an owner (+ optional single
     * property focus for the audit trend). Returns a structured array the view renders.
     */
    function bi_dashboard($db, string $owner, array $stayIds, int $focusStayId = 0): array
    {
        $earnings = function_exists('supplier_earning_summary') ? supplier_earning_summary($db, $owner) : [];
        $res = bi_reservations_by_status($db, $stayIds);
        $fnb = bi_fnb_sales($db, $stayIds);
        $ops = bi_ops_counts($db, $stayIds);
        // Trend: the focused property, else the first owned property with audits.
        $trendStay = $focusStayId > 0 && in_array($focusStayId, $stayIds, true) ? $focusStayId : (int) ($stayIds[0] ?? 0);
        $trend = $trendStay > 0 ? bi_audit_trend($db, $trendStay) : [];
        return [
            'earnings'   => $earnings,
            'reservations' => $res,
            'fnb'        => $fnb,
            'ops'        => $ops,
            'trend'      => $trend,
            'trend_stay' => $trendStay,
        ];
    }
}

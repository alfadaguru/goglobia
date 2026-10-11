<?php
// FILE: app/lib/supplier_rms.php
// MODULE 14 (revenue management & forecasting → 360). The REAL analytics are computed
// from our OWN data: historical night-audit snapshots (ADR/RevPAR/occupancy), forward
// holds (future occupancy forecast, booking pace, lead-time), and POS (F&B → TRevPAR).
// Automated yield rules are module 06's engine (already live). Competitor-rate
// monitoring needs an external rate-shopping vendor → honestly STUBBED ('not connected').
// NOT runtime-verified (pure metric helpers are harnessable).

if (!function_exists('rms_forward_occupancy')) {
    /**
     * Forward occupancy forecast for the next $days nights, from live holds vs capacity.
     * committed = held(unexpired) + consumed per night; occupancy = committed/capacity.
     * $todayYmd is passed in (never calls time()). Returns a list of
     * ['date','committed','capacity','occupancy_pct'].
     */
    function rms_forward_occupancy($db, int $stayId, string $todayYmd, int $days = 30): array
    {
        $out = [];
        if ($stayId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $todayYmd)) { return $out; }
        $days = max(1, min(120, $days));
        $capacity = function_exists('na_sellable_room_count') ? (int) na_sellable_room_count($db, $stayId) : 0;
        try {
            $holds = $db->select('stays_holds', ['date_from', 'date_to', 'qty', 'state', 'expires_at'], ['stay_id' => $stayId]) ?: [];
            $now = date('Y-m-d H:i:s');
            $byDate = [];
            foreach ($holds as $h) {
                $st = (string) $h['state'];
                $live = $st === 'consumed' || ($st === 'held' && (string) ($h['expires_at'] ?? '') > $now);
                if (!$live) { continue; }
                $qty = max(1, (int) $h['qty']);
                $d = strtotime((string) $h['date_from']); $end = strtotime((string) $h['date_to']);
                while ($d < $end) { $byDate[date('Y-m-d', $d)] = ($byDate[date('Y-m-d', $d)] ?? 0) + $qty; $d = strtotime('+1 day', $d); }
            }
            $t = strtotime($todayYmd);
            for ($i = 0; $i < $days; $i++) {
                $date = date('Y-m-d', $t); $t = strtotime('+1 day', $t);
                $committed = (int) ($byDate[$date] ?? 0);
                $out[] = [
                    'date' => $date, 'committed' => $committed, 'capacity' => $capacity,
                    'occupancy_pct' => $capacity > 0 ? round($committed / $capacity * 100, 1) : null,
                ];
            }
        } catch (\Throwable $e) { error_log('rms_forward_occupancy: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('rms_lead_time')) {
    /**
     * Booking PACE + LEAD-TIME from holds: for holds created in the last $window days,
     * the average lead time (days between created_at and arrival date_from) and the count
     * (pace). $todayYmd passed in. Returns ['bookings','avg_lead_days','pace_per_day'].
     */
    function rms_lead_time($db, int $stayId, string $todayYmd, int $window = 30): array
    {
        $out = ['bookings' => 0, 'avg_lead_days' => 0.0, 'pace_per_day' => 0.0];
        if ($stayId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $todayYmd)) { return $out; }
        $window = max(1, min(365, $window));
        try {
            $from = date('Y-m-d', strtotime($todayYmd . ' -' . $window . ' days'));
            $holds = $db->select('stays_holds', ['date_from', 'created_at', 'state'],
                ['stay_id' => $stayId, 'created_at[>=]' => $from . ' 00:00:00']) ?: [];
            $leadSum = 0; $n = 0;
            foreach ($holds as $h) {
                if (!in_array((string) $h['state'], ['held', 'consumed'], true)) { continue; }
                $arr = strtotime((string) $h['date_from']);
                $made = strtotime(substr((string) $h['created_at'], 0, 10));
                if ($arr === false || $made === false) { continue; }
                $lead = (int) floor(($arr - $made) / 86400);
                if ($lead < 0) { $lead = 0; }
                $leadSum += $lead; $n++;
            }
            $out['bookings'] = $n;
            $out['avg_lead_days'] = $n > 0 ? round($leadSum / $n, 1) : 0.0;
            $out['pace_per_day'] = round($n / $window, 2);
        } catch (\Throwable $e) { error_log('rms_lead_time: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('rms_revenue_kpis')) {
    /**
     * PURE-ish KPI composition for a period from a set of night-audit snapshot rows +
     * total F&B revenue + an optional operating-expense figure:
     *   RevPAR  = room_revenue / available-room-nights
     *   TRevPAR = (room_revenue + fnb) / available-room-nights
     *   GOPPAR  = (total_revenue - expenses) / available-room-nights
     *   ADR     = room_revenue / rooms_sold
     * Returns the four metrics + the inputs. Deterministic → harnessable.
     */
    function rms_revenue_kpis(array $snapshots, float $fnbRevenue = 0.0, float $expenses = 0.0): array
    {
        $roomRev = 0.0; $roomsSold = 0; $availNights = 0;
        foreach ($snapshots as $s) {
            $roomRev     += (float) ($s['room_revenue'] ?? 0);
            $roomsSold   += (int) ($s['rooms_sold'] ?? 0);
            $availNights += (int) ($s['rooms_available'] ?? 0);
        }
        $roomRev = round($roomRev, 2);
        $totalRev = round($roomRev + $fnbRevenue, 2);
        return [
            'room_revenue' => $roomRev,
            'fnb_revenue'  => round($fnbRevenue, 2),
            'total_revenue'=> $totalRev,
            'rooms_sold'   => $roomsSold,
            'avail_nights' => $availNights,
            'adr'    => $roomsSold > 0 ? round($roomRev / $roomsSold, 2) : 0.0,
            'revpar' => $availNights > 0 ? round($roomRev / $availNights, 2) : 0.0,
            'trevpar'=> $availNights > 0 ? round($totalRev / $availNights, 2) : 0.0,
            'goppar' => $availNights > 0 ? round(($totalRev - $expenses) / $availNights, 2) : 0.0,
        ];
    }
}

if (!function_exists('rms_competitor_status')) {
    /** HONEST: competitor-rate monitoring needs an external rate-shopping provider.
     *  Reports 'not connected' unless a rate_shop_adapter() ships (none does). */
    function rms_competitor_status(): string
    {
        return function_exists('rate_shop_adapter') ? 'connected' : 'not_connected';
    }
}

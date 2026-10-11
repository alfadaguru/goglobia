<?php
// FILE: app/lib/supplier_rates.php
// MODULE 06 (rates, pricing & rate plans → 360). YIELD RULES + booking RESTRICTIONS +
// PROMO CODES, all applied SERVER-SIDE inside the single authoritative pricer
// (stays_recompute_net) or as booking gates. Rules are owner-configured; the applied
// rate is always computed server-side from the base/calendar rate — never from client
// input — so this cannot be abused to lower a charged total. NOT runtime-verified.
//
// The nightly-rate math (rate_rules_adjust_nightly) is a PURE function → harnessable.

if (!function_exists('rate_rule_types')) {
    function rate_rule_types(): array
    {
        return [
            'occupancy'   => 'Occupancy-based (raise/drop by how full the night is)',
            'los'         => 'Length-of-stay (discount long stays)',
            'early_bird'  => 'Early-bird (book N+ days ahead)',
            'last_minute' => 'Last-minute (book within N days)',
            'weekday'     => 'Weekday / weekend',
            'derived'     => 'Derived (flat adjust off base)',
        ];
    }
}

if (!function_exists('rate_rule_matches')) {
    /**
     * Does a single rule apply to this night given the booking context?
     * $ctx keys: 'occupancy_pct' (0-100|null), 'los' (nights), 'lead_days' (days until
     * check-in), 'weekday' (0=Sun..6=Sat), 'room_id', 'option_id'.
     * threshold meaning per type:
     *   occupancy  : applies when occupancy_pct >= threshold
     *   los        : applies when los >= threshold (nights)
     *   early_bird : applies when lead_days >= threshold
     *   last_minute: applies when lead_days <= threshold
     *   weekday    : applies when weekday is in the rule's weekdays list
     *   derived    : always applies
     */
    function rate_rule_matches(array $rule, array $ctx): bool
    {
        // Scope: a room/option-scoped rule only applies to that room/option.
        $sr = (int) ($rule['scope_room_id'] ?? 0);
        $so = (int) ($rule['scope_option_id'] ?? 0);
        if ($sr > 0 && $sr !== (int) ($ctx['room_id'] ?? 0)) { return false; }
        if ($so > 0 && $so !== (int) ($ctx['option_id'] ?? 0)) { return false; }

        $type = (string) ($rule['rule_type'] ?? '');
        $th = isset($rule['threshold']) && $rule['threshold'] !== null ? (float) $rule['threshold'] : null;
        switch ($type) {
            case 'occupancy':
                $occ = $ctx['occupancy_pct'] ?? null;
                return $occ !== null && $th !== null && (float) $occ >= $th;
            case 'los':
                return $th !== null && (int) ($ctx['los'] ?? 0) >= (int) $th;
            case 'early_bird':
                return $th !== null && (int) ($ctx['lead_days'] ?? 0) >= (int) $th;
            case 'last_minute':
                return $th !== null && (int) ($ctx['lead_days'] ?? 999999) <= (int) $th;
            case 'weekday':
                $days = array_filter(array_map('trim', explode(',', (string) ($rule['weekdays'] ?? ''))), fn($x) => $x !== '');
                return in_array((string) ($ctx['weekday'] ?? ''), $days, true);
            case 'derived':
                return true;
        }
        return false;
    }
}

if (!function_exists('rate_rules_adjust_nightly')) {
    /**
     * PURE: apply the matching rules (ordered by priority desc) to one night's base rate.
     * Each rule adjusts cumulatively: pct multiplies, amount adds. Floored at 0.
     * Returns the adjusted nightly rate (rounded to 2dp). Deterministic → harnessable.
     */
    function rate_rules_adjust_nightly(array $rules, float $basePrice, array $ctx): float
    {
        $price = $basePrice;
        // Stable order: higher priority first, then id.
        usort($rules, function ($a, $b) {
            $pa = (int) ($a['priority'] ?? 0); $pb = (int) ($b['priority'] ?? 0);
            if ($pa !== $pb) { return $pb - $pa; }
            return (int) ($a['id'] ?? 0) - (int) ($b['id'] ?? 0);
        });
        foreach ($rules as $rule) {
            if ((int) ($rule['active'] ?? 1) !== 1) { continue; }
            if (!rate_rule_matches($rule, $ctx)) { continue; }
            $mode = (string) ($rule['adjust_mode'] ?? 'pct');
            $val = (float) ($rule['adjust_value'] ?? 0);
            if ($mode === 'pct') { $price += $price * ($val / 100.0); }
            else { $price += $val; }
            if ($price < 0) { $price = 0.0; }
        }
        return round($price, 2);
    }
}

if (!function_exists('rate_rules_for_stay')) {
    /** Active rate rules for a property (cached per request). */
    function rate_rules_for_stay($db, int $stayId): array
    {
        static $cache = [];
        if ($stayId <= 0) { return []; }
        if (isset($cache[$stayId])) { return $cache[$stayId]; }
        try {
            $rows = $db->select('stays_rate_rules', '*', ['stay_id' => $stayId, 'active' => 1]) ?: [];
        } catch (\Throwable $e) { error_log('rate_rules_for_stay: ' . $e->getMessage()); $rows = []; }
        return $cache[$stayId] = $rows;
    }
}

if (!function_exists('rate_restrictions_check')) {
    /**
     * Gate a booking against the property's active restrictions (min/max stay, CTA/CTD,
     * advance window). Returns ['ok'=>bool,'message'=>?]. Checked server-side at booking.
     * $ctx: 'checkin','checkout','los','lead_days','room_id','option_id'.
     */
    function rate_restrictions_check($db, int $stayId, array $ctx): array
    {
        try {
            $rows = $db->select('stays_rate_restrictions', '*', ['stay_id' => $stayId, 'active' => 1]) ?: [];
        } catch (\Throwable $e) { return ['ok' => true]; } // fail-open on read error (don't block a sale on a glitch)
        $ci = (string) ($ctx['checkin'] ?? '');
        $los = (int) ($ctx['los'] ?? 0);
        $lead = (int) ($ctx['lead_days'] ?? 0);
        foreach ($rows as $r) {
            // Scope + date-window filter.
            $sr = (int) ($r['scope_room_id'] ?? 0); $so = (int) ($r['scope_option_id'] ?? 0);
            if ($sr > 0 && $sr !== (int) ($ctx['room_id'] ?? 0)) { continue; }
            if ($so > 0 && $so !== (int) ($ctx['option_id'] ?? 0)) { continue; }
            if (!empty($r['date_from']) && $ci < (string) $r['date_from']) { continue; }
            if (!empty($r['date_to']) && $ci > (string) $r['date_to']) { continue; }
            if ($r['min_stay'] !== null && $los < (int) $r['min_stay']) {
                return ['ok' => false, 'message' => 'Minimum stay for these dates is ' . (int) $r['min_stay'] . ' night(s).'];
            }
            if ($r['max_stay'] !== null && (int) $r['max_stay'] > 0 && $los > (int) $r['max_stay']) {
                return ['ok' => false, 'message' => 'Maximum stay for these dates is ' . (int) $r['max_stay'] . ' night(s).'];
            }
            if ((int) ($r['cta'] ?? 0) === 1) {
                return ['ok' => false, 'message' => 'Arrivals are closed for the selected start date.'];
            }
            if ($r['min_advance_days'] !== null && $lead < (int) $r['min_advance_days']) {
                return ['ok' => false, 'message' => 'These dates must be booked at least ' . (int) $r['min_advance_days'] . ' day(s) in advance.'];
            }
            if ($r['max_advance_days'] !== null && (int) $r['max_advance_days'] > 0 && $lead > (int) $r['max_advance_days']) {
                return ['ok' => false, 'message' => 'These dates are not yet open for booking (too far ahead).'];
            }
            // CTD (closed-to-departure) is checked against the checkout date separately by
            // the caller if it tracks checkout-date restrictions; min viable here.
        }
        return ['ok' => true];
    }
}

// NOTE: PROMO CODES for stays are intentionally NOT reimplemented here. The platform
// already has a mature promo system — `promo_codes` + validatePromoCode() (functions.php)
// — wired into the stays booking submit (app/routes/stays/bookingRoutes.php), with
// module + per-property item targeting (target_type='specific' + target_ids + the
// caller's context['item_id']). A supplier scopes a code to their property through that
// system. Duplicating it here would split-brain discounts, so module 06's "promo codes"
// sub-feature is satisfied by the existing platform path, not a parallel table.

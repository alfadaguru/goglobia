<?php
// ============================================================================
// UMRAH REDESIGN — service layer (docs/UMRAH-PHASE1-BUILD-PLAN.md §2/§3).
// Plain PHP helpers. Reuses the shared platform (MARKUP, currency helpers). The
// SERVER is the only price authority — the browser references a quote_ref and
// the server recomputes/validates at hold, booking and payment.
// ============================================================================

// Max pilgrims a NON-AGENT customer may put on a single departure booking
// (owner rule, Phase B). Agents (umrah_is_agent) are exempt — they use groups.
if (!defined('UMRAH_CUSTOMER_MAX_PAX')) { define('UMRAH_CUSTOMER_MAX_PAX', 5); }

if (!function_exists('umrah_departure_bookable')) {
    /**
     * SECURITY GATE: a departure-tier is bookable only when the PARENT departure
     * is 'published' AND the tier is not draft/hidden/sold_out. Used by
     * quote/hold/booking so a crafted departure_tier_id cannot transact against a
     * draft/closed departure that the public list pages correctly hide.
     * @param array|null $dt optional already-loaded departure_tier row
     */
    function umrah_departure_bookable($db, int $departureId, ?array $dt = null): bool
    {
        $dep = $db->get('umrah_departures', ['status'], ['id' => $departureId]);
        if (!$dep || ($dep['status'] ?? '') !== 'published') { return false; }
        if ($dt !== null) {
            $ts = $dt['status'] ?? '';
            if (in_array($ts, ['draft', 'hidden', 'sold_out'], true)) { return false; }
        }
        return true;
    }
}

if (!function_exists('umrah_default_currency')) {
    function umrah_default_currency($db): string
    {
        try {
            $c = $db->get('currencies', ['name'], ['default' => 1]);
            return strtoupper((string) ($c['name'] ?? 'NGN')) ?: 'NGN';
        } catch (\Throwable $e) { return 'NGN'; }
    }
}

if (!function_exists('umrah_ref')) {
    /** Short, human-ish unique reference with a prefix (e.g. GGU-, GGQ-). */
    function umrah_ref(string $prefix): string
    {
        try { $rand = strtoupper(bin2hex(random_bytes(4))); }
        catch (\Throwable $e) { $rand = strtoupper(substr(md5(uniqid('', true)), 0, 8)); }
        return $prefix . '-' . $rand;
    }
}

if (!function_exists('umrah_is_agent')) {
    /** True when the current request actor is a logged-in agent (B2B). */
    function umrah_is_agent(): bool
    {
        return strtolower((string) ($_SESSION['user_role'] ?? '')) === 'agent';
    }
}

if (!function_exists('umrah_b2c_unit_price')) {
    /**
     * The B2C (customer-facing) unit sell price for a departure-tier, ignoring
     * any agent B2B rate. This is always the price a pilgrim pays and the basis
     * for the agent's earning (B2C − B2B net). Mirrors the direct-sell precedence
     * (promo-in-window → regular → 0).
     */
    function umrah_b2c_unit_price(array $dt): float
    {
        $now = time();
        $promoActive = (int) ($dt['promo_active'] ?? 0) === 1
            && $dt['promo_price'] !== null && (float) $dt['promo_price'] > 0;
        if ($promoActive) {
            $start = !empty($dt['promo_start']) ? strtotime((string) $dt['promo_start']) : null;
            $end   = !empty($dt['promo_end'])   ? strtotime((string) $dt['promo_end'])   : null;
            if (($start === null || $now >= $start) && ($end === null || $now <= $end)) {
                return (float) $dt['promo_price'];
            }
        }
        if ($dt['regular_price'] !== null && (float) $dt['regular_price'] > 0) {
            return (float) $dt['regular_price'];
        }
        return 0.0;
    }
}

if (!function_exists('umrah_price_resolve')) {
    /**
     * Resolve the UNIT price for a departure-tier by the spec §8.2 precedence:
     *   1. departure-tier direct PROMO price (if promo_active and within window)
     *   2. departure-tier direct REGULAR price
     *   3. tier/package direct price (Phase 1: none → skip)
     *   4. cost + package markup  (MARKUP applied ONLY here)
     *   5. module global markup fallback
     *
     * CRITICAL (§8.3): a direct sell price is used VERBATIM — MARKUP() is NOT
     * applied on top of it (that was the legacy double-markup bug).
     *
     * AGENT B2B (spec §35.1): when the actor is an agent AND the departure-tier
     * carries a B2B net rate (b2b_promo_price preferred, else b2b_net_price), the
     * agent transacts at that NET price. The B2C sell price is still surfaced
     * (as `b2c_unit`) so the booking can record agent_earning = (B2C − net) × pax.
     * Customer-facing receipts must never show the net/margin (spec §35).
     *
     * @param bool|null $forAgent  null → auto-detect via session role.
     * @return array{unit:float, currency:string, source:string, promo:array|null,
     *               b2c_unit:float, is_agent:bool}
     */
    function umrah_price_resolve($db, array $dt, ?bool $forAgent = null): array
    {
        $currency = strtoupper(trim((string) ($dt['currency'] ?? 'NGN'))) ?: 'NGN';
        $now = time();
        $isAgent = ($forAgent === null) ? umrah_is_agent() : $forAgent;
        $b2cUnit = umrah_b2c_unit_price($dt);

        // AGENT B2B net rate — takes precedence for agents when set. Prefer the
        // B2B promo net (only within the promo window — audit M2), else the B2B
        // net. 0/NULL means "no special agent rate" → agent pays B2C.
        if ($isAgent) {
            $b2bNet = null;
            // B2B promo shares the tier's promo_start/promo_end window; once the
            // window passes it must NOT keep applying (previously never expired).
            $bStart = !empty($dt['promo_start']) ? strtotime((string) $dt['promo_start']) : null;
            $bEnd   = !empty($dt['promo_end'])   ? strtotime((string) $dt['promo_end'])   : null;
            $b2bPromoInWindow = ($bStart === null || $now >= $bStart) && ($bEnd === null || $now <= $bEnd);
            if ($b2bPromoInWindow && isset($dt['b2b_promo_price']) && $dt['b2b_promo_price'] !== null && (float) $dt['b2b_promo_price'] > 0) {
                $b2bNet = (float) $dt['b2b_promo_price'];
            } elseif (isset($dt['b2b_net_price']) && $dt['b2b_net_price'] !== null && (float) $dt['b2b_net_price'] > 0) {
                $b2bNet = (float) $dt['b2b_net_price'];
            }
            if ($b2bNet !== null && $b2cUnit > 0) {
                return [
                    'unit'     => $b2bNet,
                    'currency' => $currency,
                    'source'   => 'departure_tier_b2b_net',
                    'promo'    => null,
                    'b2c_unit' => $b2cUnit,
                    'is_agent' => true,
                ];
            }
            // No B2B rate → fall through to B2C pricing (agent pays B2C).
        }

        $promoActive = (int) ($dt['promo_active'] ?? 0) === 1
            && $dt['promo_price'] !== null && (float) $dt['promo_price'] > 0;
        if ($promoActive) {
            // Respect an explicit promo window if set.
            $start = !empty($dt['promo_start']) ? strtotime((string) $dt['promo_start']) : null;
            $end   = !empty($dt['promo_end'])   ? strtotime((string) $dt['promo_end'])   : null;
            if (($start === null || $now >= $start) && ($end === null || $now <= $end)) {
                $regular = ($dt['regular_price'] !== null) ? (float) $dt['regular_price'] : null;
                $promo   = (float) $dt['promo_price'];
                return [
                    'unit'     => $promo,
                    'currency' => $currency,
                    'source'   => 'departure_tier_promo',
                    'promo'    => [
                        'regular'   => $regular,
                        'promo'     => $promo,
                        'savings'   => ($regular !== null) ? round($regular - $promo, 2) : null,
                        'savings_pct' => ($regular > 0) ? round((($regular - $promo) / $regular) * 100, 2) : null,
                    ],
                    'b2c_unit' => $b2cUnit,
                    'is_agent' => $isAgent,
                ];
            }
        }

        // 2. departure-tier regular direct price
        if ($dt['regular_price'] !== null && (float) $dt['regular_price'] > 0) {
            return ['unit' => (float) $dt['regular_price'], 'currency' => $currency, 'source' => 'departure_tier_regular', 'promo' => null, 'b2c_unit' => $b2cUnit, 'is_agent' => $isAgent];
        }

        // 4/5. cost + markup fallback (only when NO direct price is set). Uses the
        // shared MARKUP() against the umrah module — this is the ONLY branch that
        // applies markup (spec §8.2).
        //
        // NOTE: Phase 1 ships DIRECT-SELL pricing only, so umrah_departure_tiers
        // has no `cost_price` column yet — this fallback is intentionally inert
        // until that column (+ an admin UI to set it) ships. The null-coalesce
        // keeps it safe (no undefined-key notice) and yields $base=0 so the
        // branch is skipped rather than mispricing at 0. When cost pricing is
        // added, add the `cost_price` column and this branch activates unchanged.
        $base = (float) ($dt['cost_price'] ?? 0);
        if ($base > 0 && function_exists('MARKUP')) {
            $module = $db->get('modules', '*', ['name' => 'umrah', 'type' => 'umrah']);
            $marked = MARKUP($base, $module ?: null, $db, $currency, $currency);
            $unit = (!empty($marked['price']) && $marked['price'] > 0) ? (float) $marked['price'] : $base;
            return ['unit' => round($unit, 2), 'currency' => $currency, 'source' => 'cost_plus_markup', 'promo' => null, 'b2c_unit' => round($unit, 2), 'is_agent' => $isAgent];
        }

        // Nothing priced → 0 (caller treats as not-bookable/quote-required).
        return ['unit' => 0.0, 'currency' => $currency, 'source' => 'unpriced', 'promo' => null, 'b2c_unit' => 0.0, 'is_agent' => $isAgent];
    }
}

if (!function_exists('umrah_price_quote')) {
    /**
     * Create a server-side quote for a departure-tier + pax.
     * Writes a umrah_quotes row (default 20-minute expiry) and returns it.
     *
     * @return array  quote row-ish array incl. quote_ref, unit_price, total_price,
     *                amount_due_now (Phase 1 = full total; the payment-plan split is
     *                computed at booking time), promo snapshot, expires_at. On error
     *                returns ['ok'=>false,'message'=>...].
     */
    function umrah_price_quote($db, int $departureTierId, int $pax, int $holdMinutes = 20): array
    {
        if ($pax < 1) { $pax = 1; }
        // Phase B: cap non-agent customers at UMRAH_CUSTOMER_MAX_PAX per booking.
        // Agents have no cap here (they book via groups). This is the primary
        // server gate; umrah_booking_create re-checks as defense-in-depth.
        if (!umrah_is_agent() && $pax > UMRAH_CUSTOMER_MAX_PAX) {
            return ['ok' => false, 'message' => 'A single booking is limited to ' . UMRAH_CUSTOMER_MAX_PAX . ' pilgrims. For larger groups, please use an agent group or contact us.'];
        }
        $dt = $db->get('umrah_departure_tiers', '*', ['id' => $departureTierId]);
        if (!$dt) {
            return ['ok' => false, 'message' => 'Departure-tier not found'];
        }
        if (($dt['status'] ?? '') === 'hidden') {
            return ['ok' => false, 'message' => 'This option is not available'];
        }
        // SECURITY: the parent departure must be published + the tier active.
        // GET list pages filter to published, but this POST endpoint must not
        // let a crafted departure_tier_id quote a draft/closed departure.
        if (!umrah_departure_bookable($db, (int) $dt['departure_id'], $dt)) {
            return ['ok' => false, 'message' => 'This departure is not open for booking'];
        }

        $priced = umrah_price_resolve($db, $dt);
        if ($priced['unit'] <= 0) {
            return ['ok' => false, 'message' => 'This tier is not priced for instant booking (request a quote)', 'source' => $priced['source']];
        }

        $unit  = round($priced['unit'], 2);
        $total = round($unit * $pax, 2);
        $currency = $priced['currency'];

        $quoteRef = umrah_ref('GGQ');
        $expiresAt = date('Y-m-d H:i:s', time() + max(1, $holdMinutes) * 60);

        $tier = $db->get('umrah_tiers', ['code'], ['id' => $dt['tier_id']]);
        $tierCode = $tier['code'] ?? null;

        $db->insert('umrah_quotes', [
            'quote_ref'         => $quoteRef,
            'departure_tier_id' => $departureTierId,
            'tier_code'         => $tierCode,
            'pax'               => $pax,
            'unit_price'        => $unit,
            'total_price'       => $total,
            'amount_due_now'    => $total, // Phase 1: split resolved at booking per plan
            'promo_snapshot'    => json_encode($priced['promo']),
            'policy_version'    => 'v1',
            'currency'          => $currency,
            'expires_at'        => $expiresAt,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);

        return [
            'ok'                => true,
            'quote_ref'         => $quoteRef,
            'quote_id'          => (int) $db->id(),
            'departure_tier_id' => $departureTierId,
            'tier_code'         => $tierCode,
            'pax'               => $pax,
            'unit_price'        => $unit,
            'total_price'       => $total,
            'amount_due_now'    => $total,
            'currency'          => $currency,
            'price_source'      => $priced['source'],
            'promo'             => $priced['promo'],
            'expires_at'        => $expiresAt,
        ];
    }
}

if (!function_exists('umrah_capacity_for')) {
    /**
     * Remaining bookable seats for a departure-tier (Phase 1 formula):
     *   capacity − confirmed bookings − ACTIVE (non-expired) holds.
     * Phase 1 capacity source = tier_capacity if set, else the departure capacity.
     * NOTE: for the authoritative check use umrah_hold_create() which locks the
     * row; this helper is for display and is NOT concurrency-safe on its own.
     */
    function umrah_capacity_for($db, int $departureTierId): array
    {
        $dt = $db->get('umrah_departure_tiers', ['id', 'departure_id', 'tier_capacity'], ['id' => $departureTierId]);
        if (!$dt) { return ['ok' => false, 'capacity' => 0, 'remaining' => 0]; }
        $depId = (int) $dt['departure_id'];
        $cap = ($dt['tier_capacity'] !== null) ? (int) $dt['tier_capacity'] : 0;
        if ($cap <= 0) {
            $dep = $db->get('umrah_departures', ['capacity'], ['id' => $depId]);
            $cap = (int) ($dep['capacity'] ?? 0);
        }
        $now = date('Y-m-d H:i:s');
        $confirmed = (int) $db->sum('umrah_bookings', 'pax', [
            'departure_tier_id' => $departureTierId,
            'booking_status'    => ['held', 'confirmed', 'completed'],
        ]);
        $activeHolds = (int) $db->sum('umrah_inventory_holds', 'qty', [
            'departure_tier_id' => $departureTierId,
            'state'             => 'held',
            'expires_at[>]'     => $now,
        ]);
        $tierRemaining = max(0, $cap - $confirmed - $activeHolds);

        // DEPARTURE-LEVEL CAP (audit H4): tiers share the physical departure
        // seats, so the effective remaining is also bounded by the whole
        // departure's capacity minus ALL tiers' confirmed/held pax. Prevents
        // selling 50 on each of two tiers of a 50-seat departure.
        $depRemaining = umrah_departure_remaining($db, $depId, $now);
        $remaining = max(0, min($tierRemaining, $depRemaining));
        return ['ok' => true, 'capacity' => $cap, 'confirmed' => $confirmed, 'active_holds' => $activeHolds, 'remaining' => $remaining, 'departure_remaining' => $depRemaining];
    }
}

if (!function_exists('umrah_departure_remaining')) {
    /**
     * Remaining seats at the DEPARTURE level = departure capacity minus the pax
     * of all held/confirmed/completed bookings across ALL its tiers minus all
     * active (unexpired) inventory holds across ALL its tiers. Used to bound
     * per-tier availability so shared-capacity departures cannot oversell.
     */
    function umrah_departure_remaining($db, int $departureId, ?string $now = null): int
    {
        $now = $now ?: date('Y-m-d H:i:s');
        $dep = $db->get('umrah_departures', ['capacity'], ['id' => $departureId]);
        $cap = (int) ($dep['capacity'] ?? 0);
        if ($cap <= 0) { return 0; }
        $confirmed = (int) $db->sum('umrah_bookings', 'pax', [
            'departure_id'   => $departureId,
            'booking_status' => ['held', 'confirmed', 'completed'],
        ]);
        // Active holds across all tiers of this departure (join via tier rows).
        $holds = $db->query(
            "SELECT COALESCE(SUM(h.qty),0) AS q
             FROM umrah_inventory_holds h
             JOIN umrah_departure_tiers dt ON dt.id = h.departure_tier_id
             WHERE dt.departure_id = :dep AND h.state = 'held' AND h.expires_at > :now",
            [':dep' => $departureId, ':now' => $now]
        );
        $activeHolds = $holds ? (int) ($holds->fetch(\PDO::FETCH_ASSOC)['q'] ?? 0) : 0;
        return max(0, $cap - $confirmed - $activeHolds);
    }
}

if (!function_exists('umrah_hold_create')) {
    /**
     * Atomically create an inventory hold for `pax` seats on a departure-tier.
     * Concurrency-safe: runs in a transaction, locks the departure-tier row
     * (SELECT ... FOR UPDATE), and recomputes remaining INSIDE the lock so two
     * simultaneous buyers of the last seat cannot both succeed.
     *
     * @return array ['ok'=>true,'hold_id'=>..,'expires_at'=>..] or ['ok'=>false,'message'=>..,'remaining'=>..]
     */
    function umrah_hold_create($db, int $departureTierId, int $pax, ?int $quoteId = null, ?string $userId = null, ?string $sessionRef = null, int $holdMinutes = 20): array
    {
        if ($pax < 1) { $pax = 1; }
        $result = ['ok' => false, 'message' => 'Could not create hold'];

        try {
            $db->action(function ($db) use ($departureTierId, $pax, $quoteId, $userId, $sessionRef, $holdMinutes, &$result) {
                // Lock the departure-tier row for the duration of the transaction.
                // Include the parent departure status in the locked read so the
                // published-gate is enforced atomically (not TOCTOU-racy).
                $locked = $db->query(
                    "SELECT dt.id, dt.departure_id, dt.tier_capacity, dt.status, d.capacity AS dep_capacity, d.status AS dep_status
                     FROM umrah_departure_tiers dt
                     JOIN umrah_departures d ON d.id = dt.departure_id
                     WHERE dt.id = :id FOR UPDATE",
                    [':id' => $departureTierId]
                );
                $row = $locked ? $locked->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$row) {
                    $result = ['ok' => false, 'message' => 'Departure-tier not found'];
                    return false; // rollback
                }
                // DEPARTURE-LEVEL LOCK (audit MED: cross-tier oversell race). Two
                // holds on DIFFERENT tiers of the same departure lock different
                // tier rows, so the shared-seat (departure-wide) check below is not
                // serialized between them. Take an explicit lock on the parent
                // departure row so all holds on one departure contend on the SAME
                // lock before computing departure-wide remaining.
                $db->query('SELECT id FROM umrah_departures WHERE id = :dep FOR UPDATE', [':dep' => (int) $row['departure_id']]);
                // SECURITY: parent departure must be published; tier not draft/hidden/sold_out.
                if (($row['dep_status'] ?? '') !== 'published') {
                    $result = ['ok' => false, 'message' => 'This departure is not open for booking'];
                    return false;
                }
                if (in_array(($row['status'] ?? ''), ['sold_out', 'hidden', 'draft'], true)) {
                    $result = ['ok' => false, 'message' => 'This departure is not available'];
                    return false;
                }

                $cap = ($row['tier_capacity'] !== null) ? (int) $row['tier_capacity'] : (int) $row['dep_capacity'];
                $now = date('Y-m-d H:i:s');

                $confirmed = (int) $db->sum('umrah_bookings', 'pax', [
                    'departure_tier_id' => $departureTierId,
                    'booking_status'    => ['held', 'confirmed', 'completed'],
                ]);
                $activeHolds = (int) $db->sum('umrah_inventory_holds', 'qty', [
                    'departure_tier_id' => $departureTierId,
                    'state'             => 'held',
                    'expires_at[>]'     => $now,
                ]);
                $remaining = $cap - $confirmed - $activeHolds;

                // DEPARTURE-LEVEL CAP (audit H4): also bound by the whole
                // departure's remaining across ALL tiers, so shared-capacity
                // departures cannot oversell (e.g. 50 standard + 50 vip on a
                // 50-seat departure). Computed inside the same locked tx.
                $depRemaining = umrah_departure_remaining($db, (int) $row['departure_id'], $now);
                $remaining = min($remaining, $depRemaining);

                if ($pax > $remaining) {
                    $result = ['ok' => false, 'message' => 'Not enough seats available', 'remaining' => max(0, $remaining)];
                    return false; // rollback — no hold created
                }

                $expiresAt = date('Y-m-d H:i:s', time() + max(1, $holdMinutes) * 60);
                $db->insert('umrah_inventory_holds', [
                    'departure_tier_id' => $departureTierId,
                    'quote_id'          => $quoteId,
                    'session_ref'       => $sessionRef,
                    'user_id'           => $userId,
                    'qty'               => $pax,
                    'state'             => 'held',
                    'expires_at'        => $expiresAt,
                    'created_at'        => $now,
                ]);
                $holdId = (int) $db->id();
                $result = ['ok' => true, 'hold_id' => $holdId, 'qty' => $pax, 'expires_at' => $expiresAt, 'remaining_after' => $remaining - $pax];
                return true; // commit
            });
        } catch (\Throwable $e) {
            error_log('umrah_hold_create: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Hold failed'];
        }
        return $result;
    }
}

if (!function_exists('umrah_hold_release')) {
    /** Release a specific held hold (e.g. customer abandons). */
    function umrah_hold_release($db, int $holdId): bool
    {
        try {
            $db->update('umrah_inventory_holds',
                ['state' => 'released', 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $holdId, 'state' => 'held']);
            return true;
        } catch (\Throwable $e) { return false; }
    }
}

if (!function_exists('umrah_hold_expire_sweep')) {
    /**
     * Cron: mark all past-expiry 'held' rows as 'expired', returning capacity.
     * Returns the number of holds expired.
     */
    function umrah_hold_expire_sweep($db): int
    {
        try {
            $now = date('Y-m-d H:i:s');
            $stale = $db->select('umrah_inventory_holds', ['id'], [
                'state'         => 'held',
                'expires_at[<]' => $now,
            ]) ?: [];
            if (!$stale) { return 0; }
            $ids = array_map(fn($r) => (int) $r['id'], $stale);
            $db->update('umrah_inventory_holds', ['state' => 'expired', 'updated_at' => $now], ['id' => $ids]);
            return count($ids);
        } catch (\Throwable $e) {
            error_log('umrah_hold_expire_sweep: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('umrah_booking_expire_sweep')) {
    /**
     * Cron (audit H5): cancel abandoned unpaid 'held' umrah_bookings so they stop
     * consuming inventory forever. A held booking is abandoned when it has no
     * payment (amount_paid = 0) and its inventory hold is no longer active
     * (expired/released/consumed-elsewhere/missing) — i.e. the 20-minute hold
     * lapsed without a qualifying payment. These are moved to 'cancelled' so the
     * capacity sums (which count 'held') free the seats. A small grace window
     * past the hold expiry avoids racing an in-flight payment callback.
     * Returns the number of bookings cancelled.
     */
    function umrah_booking_expire_sweep($db, int $graceMinutes = 30): int
    {
        try {
            $now = time();
            $cutoff = date('Y-m-d H:i:s', $now - max(0, $graceMinutes) * 60);
            // Candidate abandoned bookings: held + unpaid + created before cutoff.
            $rows = $db->select('umrah_bookings', ['id', 'hold_id', 'invoice_id'], [
                'booking_status' => 'held',
                'payment_status' => 'unpaid',
                'amount_paid'    => 0,
                'created_at[<]'  => $cutoff,
            ]) ?: [];
            if (!$rows) { return 0; }

            $cancelIds = [];
            foreach ($rows as $r) {
                $holdId = (int) ($r['hold_id'] ?? 0);
                $holdActive = false;
                if ($holdId > 0) {
                    $h = $db->get('umrah_inventory_holds', ['state', 'expires_at'], ['id' => $holdId]);
                    if ($h && ($h['state'] ?? '') === 'held' && !empty($h['expires_at']) && strtotime((string) $h['expires_at']) > $now) {
                        $holdActive = true; // still within a live hold — leave it
                    }
                }
                if (!$holdActive) { $cancelIds[] = (int) $r['id']; }
            }
            if (!$cancelIds) { return 0; }

            $ts = date('Y-m-d H:i:s', $now);
            $db->update('umrah_bookings', [
                'booking_status' => 'cancelled',
                'updated_at'     => $ts,
            ], ['id' => $cancelIds]);

            // Reflect cancellation on the generic bookings row (best-effort).
            $invoices = array_values(array_filter(array_map(fn($r) => (string) ($r['invoice_id'] ?? ''), array_filter($rows, fn($r) => in_array((int) $r['id'], $cancelIds, true)))));
            if ($invoices) {
                try {
                    $db->update('bookings', ['booking_status' => 'cancelled'], [
                        'invoice_id' => $invoices,
                        'module_type' => 'umrah',
                        'payment_status' => 'unpaid',
                    ]);
                } catch (\Throwable $e) { /* non-fatal */ }
            }
            return count($cancelIds);
        } catch (\Throwable $e) {
            error_log('umrah_booking_expire_sweep: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('umrah_installment_reminder_sweep')) {
    /**
     * Email customers whose next umrah installment falls due soon.
     *
     * Umrah supports installment plans (e.g. PP-50-25-25) but nothing ever told a
     * customer their next payment was coming up — agents had a reminder cron, umrah
     * customers had none, so installments silently lapsed to 'overdue'. This sweep
     * (driven by the /umrah_expire_holds cron) finds pending installments with a
     * due date within $withinDays, emails a heads-up ONCE (stamping
     * reminder_sent_at so a daily run never re-mails the same installment), and
     * flips genuinely past-due pending rows to 'overdue'.
     *
     * Only installments with a real due_at are considered — single 100%-collapse
     * schedules (daysToDep <= final_due_days_before) carry a NULL due_at and are
     * skipped, which is correct (they're due immediately, handled at checkout).
     *
     * @return array{reminded:int,overdue:int}
     */
    function umrah_installment_reminder_sweep($db, int $withinDays = 3): int
    {
        $reminded = 0;
        try {
            $now = date('Y-m-d H:i:s');
            $soon = date('Y-m-d H:i:s', time() + max(1, $withinDays) * 86400);

            // Mark clearly past-due pending installments as overdue (housekeeping).
            try {
                $db->update('umrah_installments', ['status' => 'overdue'], [
                    'status'   => 'pending',
                    'due_at[<]' => $now,
                    'due_at[!]' => null,
                ]);
            } catch (\Throwable $e) { /* non-fatal */ }

            // Pending/overdue installments due within the window that we have NOT
            // yet reminded on. Join to the booking so we skip cancelled/fully-paid
            // bookings and can address the email.
            $rows = $db->select('umrah_installments', [
                '[>]umrah_bookings' => ['umrah_booking_id' => 'id'],
            ], [
                'umrah_installments.id',
                'umrah_installments.seq',
                'umrah_installments.amount',
                'umrah_installments.due_at',
                'umrah_bookings.id(ub_id)',
                'umrah_bookings.booking_ref',
                'umrah_bookings.invoice_id',
                'umrah_bookings.currency',
                'umrah_bookings.balance',
                'umrah_bookings.booking_status',
            ], [
                'umrah_installments.status'          => ['pending', 'overdue'],
                'umrah_installments.reminder_sent_at' => null,
                'umrah_installments.due_at[!]'        => null,
                'umrah_installments.due_at[<=]'       => $soon,
                'umrah_bookings.booking_status[!]'    => ['cancelled'],
            ]) ?: [];

            foreach ($rows as $r) {
                $ubId = (int) ($r['ub_id'] ?? 0);
                if ($ubId <= 0) { continue; }
                if ((float) ($r['balance'] ?? 0) < 0.01) {
                    // Booking already settled — nothing to chase; stamp so we skip it.
                    $db->update('umrah_installments', ['reminder_sent_at' => $now], ['id' => (int) $r['id']]);
                    continue;
                }
                $gen = $db->get('bookings', ['first_name', 'last_name', 'email'], ['invoice_id' => $r['invoice_id']]);
                if (function_exists('umrah_notify')) {
                    $ccy = (string) $r['currency'];
                    $amt = number_format((float) $r['amount'], 2) . ' ' . $ccy;
                    $due = date('D, d M Y', strtotime((string) $r['due_at']));
                    $ref = (string) $r['booking_ref'];
                    $body = "Assalamu Alaikum,\n\nThis is a friendly reminder that installment #{$r['seq']} for your GoGlobia Umrah booking {$ref} is due on {$due}.\n\n"
                        . "Amount due: {$amt}\nOutstanding balance: " . number_format((float) $r['balance'], 2) . " {$ccy}\n\n"
                        . "Please pay from your booking page to keep your seats and locked price.\n";
                    umrah_notify($db, $ubId, 'installment_reminder', 'Umrah payment due soon — ' . $ref, $body, 'email',
                        $gen ? ['email' => $gen['email'] ?? '', 'name' => trim(($gen['first_name'] ?? '') . ' ' . ($gen['last_name'] ?? ''))] : null);
                }
                $db->update('umrah_installments', ['reminder_sent_at' => $now], ['id' => (int) $r['id']]);
                $reminded++;
            }
        } catch (\Throwable $e) {
            error_log('umrah_installment_reminder_sweep: ' . $e->getMessage());
        }
        return $reminded;
    }
}

if (!function_exists('umrah_waitlist_notify_sweep')) {
    /**
     * Notify waitlisted customers when seats free up on their departure.
     *
     * Customers can join a departure's waitlist (POST /api/v1/umrah/waitlist) but
     * nothing ever told them when a seat opened — the join side worked, the
     * "notify when available" side didn't exist. This sweep (driven by the
     * /umrah_expire_holds cron, which itself releases expired holds and cancels
     * abandoned bookings — the two events that free capacity) walks each departure
     * that has WAITING rows, and if it now has spare capacity, emails the waiting
     * customers whose party fits (pax <= remaining), oldest-first, and flips them
     * to 'notified'. Capacity is decremented in-loop so we never over-notify past
     * the seats actually available in this run; the rest stay 'waiting' for the
     * next opening. A row is only ever processed while 'waiting', so a customer is
     * never emailed twice for the same seat.
     *
     * @return int number of waitlist rows notified
     */
    function umrah_waitlist_notify_sweep($db): int
    {
        $notified = 0;
        try {
            $now = date('Y-m-d H:i:s');
            // Departures that currently have someone waiting.
            $depRows = $db->select('umrah_waitlist', ['departure_id'], [
                'status'          => 'waiting',
                'departure_id[!]' => null,
                'GROUP'           => 'departure_id',
            ]) ?: [];

            foreach ($depRows as $dr) {
                $depId = (int) ($dr['departure_id'] ?? 0);
                if ($depId <= 0) { continue; }

                // Only notify for a departure still open for booking.
                $dep = $db->get('umrah_departures', ['id', 'code', 'status', 'departure_date'], ['id' => $depId]);
                if (!$dep || ($dep['status'] ?? '') !== 'published') { continue; }

                $remaining = umrah_departure_remaining($db, $depId, $now);
                if ($remaining <= 0) { continue; }

                // Oldest-first fairness; pull only what could plausibly fit.
                $waiters = $db->select('umrah_waitlist', ['id', 'name', 'email', 'phone', 'pax'], [
                    'departure_id' => $depId,
                    'status'       => 'waiting',
                    'ORDER'        => ['created_at' => 'ASC', 'id' => 'ASC'],
                ]) ?: [];

                foreach ($waiters as $w) {
                    $pax = max(1, (int) ($w['pax'] ?? 1));
                    if ($pax > $remaining) { continue; } // party can't fit yet — leave waiting
                    // Flip to 'notified' FIRST (idempotency guard): if the email path
                    // throws, we've still consumed the row and won't spam on re-run.
                    $db->update('umrah_waitlist', ['status' => 'notified'], ['id' => (int) $w['id']]);
                    $remaining -= $pax;

                    if (function_exists('umrah_notify') && !empty($w['email'])) {
                        $depLabel = $dep['code'] ?: ('departure #' . $depId);
                        $depDate  = !empty($dep['departure_date']) ? date('D, d M Y', strtotime((string) $dep['departure_date'])) : '';
                        $body = "Assalamu Alaikum " . trim((string) ($w['name'] ?? '')) . ",\n\n"
                            . "Good news — seats have opened up on the GoGlobia Umrah departure you were waiting for"
                            . ($depDate !== '' ? " ({$depLabel}, {$depDate})" : " ({$depLabel})") . ".\n\n"
                            . "Places are limited and offered first-come — please book as soon as you can from the Umrah page before they're taken again.\n";
                        // umrah_notify's first arg is a umrah_booking_id (none here) — pass null;
                        // it still queues + sends to the given customer contact.
                        umrah_notify($db, null, 'waitlist_available', 'Umrah seats available — ' . $depLabel, $body, 'email',
                            ['email' => (string) $w['email'], 'name' => (string) ($w['name'] ?? '')]);
                    }
                    $notified++;
                    if ($remaining <= 0) { break; } // seats for this run are spoken for
                }
            }
        } catch (\Throwable $e) {
            error_log('umrah_waitlist_notify_sweep: ' . $e->getMessage());
        }
        return $notified;
    }
}

if (!function_exists('umrah_payment_schedule')) {
    /**
     * Compute the installment schedule for a booking total, given the plan and
     * the days-to-departure dynamic rule (spec §15/§16):
     *   - PP-FULL (or ≤ final_due days): 100% now.
     *   - > second_due days before departure: deposit% now, second% at T-second,
     *     final% at T-final.
     *   - between final and second days: (deposit+second)% now, final% at T-final.
     *   - ≤ final days: 100% now.
     * Amounts use round-half + a remainder correction so the sum == total exactly.
     *
     * @return array{ok:bool, plan:string, installments:array<int,array{seq,percent,amount,due_at}>}
     */
    function umrah_payment_schedule($db, string $planCode, float $total, string $departureDate, ?int $nowTs = null): array
    {
        $plan = $db->get('umrah_payment_plans', '*', ['code' => $planCode, 'active' => 1]);
        if (!$plan) {
            // Fallback to full payment if the plan is missing/inactive.
            return ['ok' => true, 'plan' => 'PP-FULL', 'installments' => [
                ['seq' => 1, 'percent' => 100.0, 'amount' => round($total, 2), 'due_at' => null],
            ]];
        }

        $nowTs = $nowTs ?? time();
        $depTs = strtotime($departureDate . ' 00:00:00');
        $daysToDep = $depTs ? (int) floor(($depTs - $nowTs) / 86400) : 9999;

        $deposit = (float) $plan['deposit_percent'];
        $second  = (float) $plan['second_percent'];
        $final   = (float) $plan['final_percent'];
        $secondDays = $plan['second_due_days_before'] !== null ? (int) $plan['second_due_days_before'] : null;
        $finalDays  = $plan['final_due_days_before'] !== null ? (int) $plan['final_due_days_before'] : null;

        // Build the percent/timing rows per the dynamic rule.
        $rows = [];
        $isFullPlan = ($second <= 0 && $final <= 0);

        if ($isFullPlan || $finalDays === null || $daysToDep <= (int) $finalDays) {
            // 100% now (full plan, or too close to departure for installments).
            $rows[] = ['percent' => 100.0, 'due_days' => null];
        } elseif ($secondDays !== null && $daysToDep <= (int) $secondDays) {
            // Between final and second cutoffs: deposit+second now, final at T-final.
            $rows[] = ['percent' => $deposit + $second, 'due_days' => null];
            $rows[] = ['percent' => $final, 'due_days' => (int) $finalDays];
        } else {
            // Full 50/25/25 style: deposit now, second at T-second, final at T-final.
            $rows[] = ['percent' => $deposit, 'due_days' => null];
            $rows[] = ['percent' => $second, 'due_days' => (int) $secondDays];
            $rows[] = ['percent' => $final,  'due_days' => (int) $finalDays];
        }

        // Convert percents → amounts; correct the last row so sum == total exactly.
        $installments = [];
        $accum = 0.0;
        $n = count($rows);
        foreach ($rows as $i => $r) {
            $isLast = ($i === $n - 1);
            $amount = $isLast ? round($total - $accum, 2) : round($total * ($r['percent'] / 100.0), 2);
            $accum += $amount;
            $dueAt = null;
            if ($r['due_days'] !== null && $depTs) {
                $dueAt = date('Y-m-d H:i:s', $depTs - $r['due_days'] * 86400);
            }
            $installments[] = [
                'seq'     => $i + 1,
                'percent' => round($r['percent'], 2),
                'amount'  => $amount,
                'due_at'  => $dueAt,
            ];
        }

        return ['ok' => true, 'plan' => $planCode, 'days_to_departure' => $daysToDep, 'installments' => $installments];
    }
}

if (!function_exists('umrah_booking_create')) {
    /**
     * Create a booking from a valid quote + hold. Validates neither is expired,
     * then (in a transaction) writes:
     *   - the generic `bookings` row (module_type=umrah) — the invoice/payment of
     *     record, reused by the existing gateway/invoice flow;
     *   - the `umrah_bookings` row with the §57 commercial SNAPSHOT;
     *   - the `umrah_installments` rows from umrah_payment_schedule().
     * Booking starts held/unpaid; the hold stays 'held' until payment consumes it
     * (umrah_settle_payment, Step 5).
     *
     * @param array $lead ['first_name','last_name','email','phone','phone_country_code','user_id']
     * @return array ['ok'=>true,'booking_ref'=>..,'invoice_id'=>..,'umrah_booking_id'=>..,'installments'=>[...]] or ['ok'=>false,'message'=>..]
     */
    function umrah_booking_create($db, string $quoteRef, int $holdId, array $lead, string $planCode = 'PP-50-25-25'): array
    {
        $now = date('Y-m-d H:i:s');

        $quote = $db->get('umrah_quotes', '*', ['quote_ref' => $quoteRef]);
        if (!$quote) { return ['ok' => false, 'message' => 'Quote not found']; }
        if (strtotime($quote['expires_at']) < time()) { return ['ok' => false, 'message' => 'Quote expired — please start again']; }

        $hold = $db->get('umrah_inventory_holds', '*', ['id' => $holdId]);
        if (!$hold) { return ['ok' => false, 'message' => 'Hold not found']; }
        if ($hold['state'] !== 'held') { return ['ok' => false, 'message' => 'Hold no longer valid']; }
        if (strtotime($hold['expires_at']) < time()) { return ['ok' => false, 'message' => 'Hold expired — please start again']; }
        if ((int) $hold['departure_tier_id'] !== (int) $quote['departure_tier_id']) {
            return ['ok' => false, 'message' => 'Quote/hold mismatch']; }
        // Bind the quote pax to the hold qty (audit H): the price is computed from
        // the quote pax while seats are reserved by the hold qty, so a quote(pax=1)
        // paired with a hold(qty=5) — or the reverse — would charge for one and
        // reserve five (or reserve one and charge five). Require they match.
        if ((int) $hold['qty'] !== (int) $quote['pax']) {
            return ['ok' => false, 'message' => 'Quote/hold pax mismatch — please start again']; }

        $dt = $db->get('umrah_departure_tiers', '*', ['id' => $quote['departure_tier_id']]);
        if (!$dt) { return ['ok' => false, 'message' => 'Departure-tier not found']; }
        $departure = $db->get('umrah_departures', '*', ['id' => $dt['departure_id']]);
        // SECURITY: re-check the departure is still published + tier bookable at
        // commit time (it could have been unpublished between hold and booking).
        if (!$departure || !umrah_departure_bookable($db, (int) $dt['departure_id'], $dt)) {
            return ['ok' => false, 'message' => 'This departure is not open for booking'];
        }
        $template  = $departure ? $db->get('umrah_package_templates', '*', ['id' => $departure['template_id']]) : null;

        $pax      = (int) $quote['pax'];
        $userId   = (string) ($lead['user_id'] ?? ($_SESSION['user_id'] ?? ''));

        // Phase B defense-in-depth: re-enforce the customer pilgrim cap at commit
        // (agents exempt). Also covers a quote minted before the cap existed.
        if (!umrah_is_agent() && $pax > UMRAH_CUSTOMER_MAX_PAX) {
            return ['ok' => false, 'message' => 'A single booking is limited to ' . UMRAH_CUSTOMER_MAX_PAX . ' pilgrims.'];
        }

        // SECURITY (H1): the quote is NOT a price authority — it carries no owner
        // or pricing basis, so an agent-priced (B2B net) quote_ref could otherwise
        // be redeemed by a non-agent to underpay. Re-resolve the price for the
        // ACTUAL booker from the live departure-tier at commit time. umrah_price_
        // resolve is agent-aware (session/JWT), so a non-agent is charged B2C and
        // an agent with a B2B rate is charged the net.
        $pricedNow = umrah_price_resolve($db, $dt);
        $unitNow = (float) ($pricedNow['unit'] ?? 0);
        if ($unitNow <= 0) {
            return ['ok' => false, 'message' => 'This option is not currently priced for booking'];
        }
        $total    = round($unitNow * max(1, $pax), 2);
        $currency = (string) ($pricedNow['currency'] ?? $quote['currency']);

        // Agent earning (spec §35.1): agent_earning = (B2C_unit − paid_unit) × pax,
        // both recomputed from the live tier for the current actor (anti-tamper).
        $agentEarning = 0.0;
        if (umrah_is_agent()) {
            $b2cUnit  = umrah_b2c_unit_price($dt);
            $marginUnit = $b2cUnit - $unitNow;
            if ($marginUnit > 0) { $agentEarning = round($marginUnit * $pax, 2); }
        }

        $schedule = umrah_payment_schedule($db, $planCode, $total, (string) ($departure['departure_date'] ?? date('Y-m-d')));
        $amountDueNow = $schedule['installments'][0]['amount'] ?? $total;

        // Commercial snapshot (spec §57) — frozen at booking creation.
        $snapshot = [
            'quote_ref'    => $quoteRef,
            'unit_price'   => $unitNow, // actually-charged unit (re-resolved at commit)
            'total_price'  => $total,
            'currency'     => $currency,
            'pax'          => $pax,
            'promo'        => json_decode((string) $quote['promo_snapshot'], true),
            'tier_code'    => $quote['tier_code'],
            'departure'    => $departure ? [
                'code' => $departure['code'], 'departure_date' => $departure['departure_date'],
                'return_date' => $departure['return_date'], 'month_bucket' => $departure['month_bucket'],
            ] : null,
            'template'     => $template ? [
                'code' => $template['code'], 'name' => $template['name'],
                'madinah_nights' => (int) $template['madinah_nights'], 'makkah_nights' => (int) $template['makkah_nights'],
                'inclusions' => json_decode((string) $template['inclusions'], true),
                'rooming_note' => $template['rooming_note'],
            ] : null,
            'payment_plan' => $planCode,
            'policy_version' => $quote['policy_version'] ?? 'v1',
            'snapshotted_at' => $now,
        ];

        $invoiceId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $bookingRef = umrah_ref('GGU');
        $result = ['ok' => false, 'message' => 'Booking failed'];

        try {
            $db->action(function ($db) use (
                $invoiceId, $bookingRef, $userId, $departure, $dt, $pax, $currency, $total,
                $planCode, $snapshot, $schedule, $lead, $holdId, $now, $quote, $agentEarning, &$result
            ) {
                // $agentEarning computed above: (B2C − agent net) × pax for agent
                // bookings on tiers with a B2B rate; 0 otherwise (direct-sell).

                // 1. Generic bookings row (payment/invoice of record).
                $db->insert('bookings', [
                    'invoice_id'      => $invoiceId,
                    'booking_status'  => 'pending',
                    'payment_status'  => 'unpaid',
                    'price_original'  => $total,
                    'price_markup'    => $total,
                    'agent_earning'   => $agentEarning,
                    'currency_markup' => $currency,
                    'first_name'      => $lead['first_name'] ?? '',
                    'last_name'       => $lead['last_name'] ?? '',
                    'email'           => $lead['email'] ?? '',
                    'phone'           => $lead['phone'] ?? '',
                    'phone_country_code' => $lead['phone_country_code'] ?? '',
                    'adults'          => $pax,
                    'childs'          => 0,
                    'infants'         => '0',
                    'user_id'         => $userId,
                    'module_type'     => 'umrah',
                    'module'          => 'umrah',
                    'booking_data'    => json_encode(['umrah' => $snapshot], JSON_UNESCAPED_SLASHES),
                    'created_at'      => $now,
                    'booking_date'    => date('Y-m-d'),
                ]);
                $genericBookingId = (int) $db->id();

                // 2. umrah_bookings domain row.
                $db->insert('umrah_bookings', [
                    'booking_ref'       => $bookingRef,
                    'invoice_id'        => $invoiceId,
                    'user_id'           => $userId,
                    'departure_id'      => (int) ($departure['id'] ?? 0),
                    'departure_tier_id' => (int) $dt['id'],
                    'pax'               => $pax,
                    'currency'          => $currency,
                    'total_price'       => $total,
                    'amount_paid'       => 0,
                    'balance'           => $total,
                    'payment_plan_code' => $planCode,
                    'booking_status'    => 'held',
                    'payment_status'    => 'unpaid',
                    'snapshot'          => json_encode($snapshot, JSON_UNESCAPED_SLASHES),
                    'hold_id'           => $holdId,
                    'created_at'        => $now,
                ]);
                $umrahBookingId = (int) $db->id();

                // 3. Installments.
                foreach ($schedule['installments'] as $ins) {
                    $db->insert('umrah_installments', [
                        'umrah_booking_id' => $umrahBookingId,
                        'seq'              => $ins['seq'],
                        'percent'          => $ins['percent'],
                        'amount'           => $ins['amount'],
                        'due_at'           => $ins['due_at'],
                        'status'           => 'pending',
                        'created_at'       => $now,
                    ]);
                }

                // 4. Tie the hold to this quote (already held; consumption is at payment).
                $db->update('umrah_inventory_holds', ['quote_id' => (int) $quote['id'], 'user_id' => $userId, 'updated_at' => $now], ['id' => $holdId]);

                $result = [
                    'ok'               => true,
                    'booking_ref'      => $bookingRef,
                    'invoice_id'       => $invoiceId,
                    'booking_id'       => $genericBookingId, // generic bookings.id (for wallet settlement)
                    'umrah_booking_id' => $umrahBookingId,
                    'total_price'      => $total,
                    'amount_due_now'   => $schedule['installments'][0]['amount'] ?? $total,
                    'installments'     => $schedule['installments'],
                    'currency'         => $currency,
                ];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('umrah_booking_create: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Booking failed'];
        }

        if (!empty($result['ok']) && function_exists('umrah_audit')) {
            umrah_audit($db, 'umrah_booking', $result['booking_ref'], 'created', null, ['invoice_id' => $invoiceId, 'total' => $total, 'plan' => $planCode], $userId);
        }
        return $result;
    }
}

if (!function_exists('umrah_settle_payment')) {
    /**
     * Apply a cleared payment to an umrah booking (docs §5). IDEMPOTENT: keyed on
     * (invoice_id, transaction_id) — a duplicate webhook/callback with the same
     * txn is a no-op and cannot double-confirm or double-consume the seat.
     *
     * Behaviour:
     *   - find the umrah_booking by invoice_id;
     *   - if this txn already recorded on any installment → no-op (return already);
     *   - mark the next pending installment(s) paid up to `amount`; recompute
     *     amount_paid / balance; set payment_status
     *     (deposit_paid / partially_paid / fully_paid);
     *   - QUALIFYING payment (>= first installment amount, i.e. deposit cleared,
     *     for a plan with price_lock_on_cleared_deposit) → consume the hold,
     *     set price_locked_at + booking_status=confirmed, and reflect paid on the
     *     generic bookings row.
     *
     * @return array ['ok'=>bool,'status'=>'settled'|'already'|'error','confirmed'=>bool,'price_locked'=>bool,...]
     */
    function umrah_settle_payment($db, string $invoiceId, float $amount, ?string $currency = null, ?string $txnId = null): array
    {
        $now = date('Y-m-d H:i:s');
        $ub = $db->get('umrah_bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$ub) { return ['ok' => false, 'status' => 'error', 'message' => 'Umrah booking not found']; }
        $ubId = (int) $ub['id'];

        // Gateways that return no transaction id would otherwise bypass the
        // (invoice, txn) idempotency key and store an empty txn. Synthesize one —
        // but keying it on invoice+amount alone COLLIDES when two DISTINCT
        // payments share the same amount (e.g. the two equal 25% tail
        // installments of a 50-25-25 plan): the second real payment would be
        // mistaken for a duplicate and silently dropped (audit H). Suffix the key
        // with the count of settlements already recorded on this booking so
        // successive empty-txn payments get distinct keys, while a re-fired
        // callback for the SAME payment still needs a real txn id to dedupe.
        $txnId = (string) $txnId;
        if ($txnId === '') {
            // Number of settlements already recorded on this booking (any txn).
            // Suffixing with it makes each successive empty-txn payment unique.
            $prior = (int) $db->count('umrah_installments', [
                'umrah_booking_id' => $ubId,
                'transaction_id[!]' => null,
            ]);
            $txnId = 'AUTO-' . $invoiceId . '-' . number_format((float) $amount, 2, '', '') . '-' . ($prior + 1);
        }

        // Idempotency: if this txn is already recorded, do nothing.
        $seen = $db->get('umrah_installments', 'id', ['umrah_booking_id' => $ubId, 'transaction_id' => $txnId]);
        if ($seen) {
            return ['ok' => true, 'status' => 'already', 'confirmed' => ($ub['booking_status'] === 'confirmed'), 'price_locked' => !empty($ub['price_locked_at'])];
        }

        $result = ['ok' => false, 'status' => 'error'];
        try {
            $db->action(function ($db) use ($ub, $ubId, $amount, $txnId, $now, $invoiceId, &$result) {
                // Allocate the payment across pending installments (in seq order).
                $pending = $db->select('umrah_installments', '*', [
                    'umrah_booking_id' => $ubId,
                    'status'           => ['pending', 'overdue'],
                    'ORDER'            => ['seq' => 'ASC'],
                ]) ?: [];

                $remaining = round($amount, 2);
                $firstInstalmentAmount = null;
                foreach ($db->select('umrah_installments', ['seq', 'amount'], ['umrah_booking_id' => $ubId, 'ORDER' => ['seq' => 'ASC']]) as $r0) {
                    if ((int) $r0['seq'] === 1) { $firstInstalmentAmount = (float) $r0['amount']; break; }
                }

                foreach ($pending as $ins) {
                    if ($remaining < 0.01) { break; }
                    $due = (float) $ins['amount'];
                    // Phase 1: settle an installment when the payment covers it
                    // (>= its amount). Partial-of-an-installment is left pending.
                    if ($remaining + 0.01 >= $due) {
                        $db->update('umrah_installments', [
                            'status'         => 'paid',
                            'paid_at'        => $now,
                            'transaction_id' => $txnId,
                        ], ['id' => (int) $ins['id']]);
                        $remaining = round($remaining - $due, 2);
                    }
                }

                // Recompute totals from the installment ledger.
                $paid = (float) $db->sum('umrah_installments', 'amount', ['umrah_booking_id' => $ubId, 'status' => 'paid']);
                $total = (float) $ub['total_price'];
                $balance = round(max(0, $total - $paid), 2);

                $paymentStatus = 'unpaid';
                if ($paid <= 0) { $paymentStatus = 'unpaid'; }
                elseif ($balance < 0.01) { $paymentStatus = 'fully_paid'; }
                elseif ($firstInstalmentAmount !== null && $paid + 0.01 >= $firstInstalmentAmount) { $paymentStatus = 'deposit_paid'; }
                else { $paymentStatus = 'partially_paid'; }

                // Qualifying payment cleared → confirm + price-lock + consume hold.
                $qualifying = ($firstInstalmentAmount !== null) && ($paid + 0.01 >= $firstInstalmentAmount);
                $confirm = $qualifying && $ub['booking_status'] === 'held';
                $priceLockedAt = $ub['price_locked_at'];

                $ubUpdate = [
                    'amount_paid'    => $paid,
                    'balance'        => $balance,
                    'payment_status' => $paymentStatus,
                    'updated_at'     => $now,
                ];
                if ($confirm) {
                    $ubUpdate['booking_status'] = 'confirmed';
                    if (empty($priceLockedAt)) { $ubUpdate['price_locked_at'] = $now; $priceLockedAt = $now; }
                    // Consume the hold — seats are now reserved, not just held.
                    if (!empty($ub['hold_id'])) {
                        $db->update('umrah_inventory_holds', ['state' => 'consumed', 'updated_at' => $now], ['id' => (int) $ub['hold_id'], 'state' => 'held']);
                    }
                }
                $db->update('umrah_bookings', $ubUpdate, ['id' => $ubId]);

                // Reflect on the generic bookings row (payment of record).
                $genUpdate = [
                    'payment_status' => ($balance < 0.01) ? 'paid' : 'unpaid',
                ];
                if ($confirm) {
                    $genUpdate['booking_status'] = 'confirmed';
                    $genUpdate['paid_at'] = $now;
                    if ($txnId) { $genUpdate['transaction_id'] = $txnId; }
                }
                $db->update('bookings', $genUpdate, ['invoice_id' => $invoiceId]);

                $result = [
                    'ok'           => true,
                    'status'       => 'settled',
                    'amount_paid'  => $paid,
                    'balance'      => $balance,
                    'payment_status' => $paymentStatus,
                    'confirmed'    => $confirm || $ub['booking_status'] === 'confirmed',
                    'price_locked' => !empty($priceLockedAt),
                ];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('umrah_settle_payment: ' . $e->getMessage());
            return ['ok' => false, 'status' => 'error', 'message' => 'Settlement failed'];
        }

        if (!empty($result['ok']) && function_exists('umrah_audit')) {
            umrah_audit($db, 'umrah_booking', $ub['booking_ref'], 'payment_settled', null,
                ['amount' => $amount, 'txn' => $txnId, 'paid' => $result['amount_paid'] ?? null, 'confirmed' => $result['confirmed'] ?? null], $ub['user_id']);
        }
        // Queue a customer email for EVERY real settlement — not just the first.
        // Previously this fired only on the held→confirmed transition (the deposit),
        // so on an installment plan the middle payment(s) AND the final payoff sent
        // NOTHING: a customer who cleared their balance got silence. Notify on:
        //   - deposit/first qualifying payment  → "booking confirmed, price locked"
        //   - a later installment (balance > 0)  → "payment received, balance remaining"
        //   - the final payoff (balance < 0.01)  → "fully paid, booking complete"
        // 'already' (idempotent re-fire of the same txn) returns before here, so a
        // duplicate gateway callback never double-emails.
        if (!empty($result['ok']) && ($result['status'] ?? '') === 'settled' && function_exists('umrah_notify')) {
            $gen = $db->get('bookings', ['first_name', 'last_name', 'email'], ['invoice_id' => $invoiceId]);
            $recipient = $gen ? ['email' => $gen['email'] ?? '', 'name' => trim(($gen['first_name'] ?? '') . ' ' . ($gen['last_name'] ?? ''))] : null;
            $ref      = $ub['booking_ref'];
            $ccy      = $ub['currency'];
            $paidStr  = number_format((float) ($result['amount_paid'] ?? 0), 2) . ' ' . $ccy;
            $balNum   = (float) ($result['balance'] ?? 0);
            $balStr   = number_format($balNum, 2) . ' ' . $ccy;
            $justConfirmed = !empty($result['confirmed']) && $ub['booking_status'] === 'held';

            if ($balNum < 0.01) {
                // Final payoff — balance cleared.
                $subject  = 'Umrah booking fully paid — ' . $ref;
                $template = 'payment_completed';
                $body = "Assalamu Alaikum,\n\nWe've received your final payment for GoGlobia Umrah booking {$ref}. "
                    . "Your booking is now FULLY PAID.\n\n"
                    . "Total paid: {$paidStr}\nBalance: 0.00 {$ccy}\n\n"
                    . "Next: make sure every pilgrim's details and documents are complete in your dashboard.\n";
            } elseif ($justConfirmed) {
                // Deposit / first qualifying payment — booking just confirmed.
                $subject  = 'Umrah booking confirmed — ' . $ref;
                $template = 'payment_confirmed';
                $body = "Assalamu Alaikum,\n\nYour GoGlobia Umrah booking {$ref} is confirmed and your package price is locked.\n"
                    . "Paid: {$paidStr}\nBalance: {$balStr}\n\n"
                    . "Next: complete each pilgrim's details in your dashboard, then pay the remaining installments before they fall due.\n";
            } else {
                // A later installment — money received, balance still outstanding.
                $subject  = 'Umrah installment received — ' . $ref;
                $template = 'payment_installment';
                $body = "Assalamu Alaikum,\n\nWe've received your installment for GoGlobia Umrah booking {$ref}.\n\n"
                    . "Total paid so far: {$paidStr}\nBalance remaining: {$balStr}\n\n"
                    . "Please pay the remaining balance before your next installment falls due.\n";
            }
            umrah_notify($db, (int) $ub['id'], $template, $subject, $body, 'email', $recipient);
        }
        return $result;
    }
}

if (!function_exists('umrah_audit')) {
    /** Append an umrah audit-log row (best-effort). */
    function umrah_audit($db, string $entity, string $entityId, string $action, $old, $new, ?string $actor = null, ?string $role = null, ?string $reason = null): void
    {
        try {
            $db->insert('umrah_audit_log', [
                'actor'     => $actor ?? ($_SESSION['user_id'] ?? null),
                'role'      => $role ?? ($_SESSION['user_role'] ?? null),
                'entity'    => $entity,
                'entity_id' => $entityId,
                'action'    => $action,
                'old_value' => $old !== null ? json_encode($old, JSON_UNESCAPED_SLASHES) : null,
                'new_value' => $new !== null ? json_encode($new, JSON_UNESCAPED_SLASHES) : null,
                'reason'    => $reason,
                'ip'        => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) { /* non-fatal */ }
    }
}

if (!function_exists('umrah_quote_request_create')) {
    /**
     * Persist a Customize / Personalize-a-trip quote request (Phase 3). Pure
     * request — no price is computed; staff respond with a tailored quote from
     * the admin inbox. Returns ['ok'=>true,'request_ref'=>..,'id'=>..] or
     * ['ok'=>false,'message'=>..].
     *
     * @param array $in sanitised fields: template_id, departure_id, origin_city,
     *   preferred_month, preferred_date, tier_code, pax, madinah_nights,
     *   makkah_nights, total_weeks, ziyarah[], addons[], options[], notes,
     *   name, email, phone.
     */
    function umrah_quote_request_create($db, array $in): array
    {
        $name  = trim((string) ($in['name'] ?? ''));
        $email = trim((string) ($in['email'] ?? ''));
        $phone = trim((string) ($in['phone'] ?? ''));
        if ($name === '') { return ['ok' => false, 'message' => 'Please enter your name']; }
        if ($email === '' && $phone === '') { return ['ok' => false, 'message' => 'Please enter your email or phone']; }

        $pax = max(1, (int) ($in['pax'] ?? 1));
        $ref = umrah_ref('GGR');
        $now = date('Y-m-d H:i:s');

        // Free-form structured extras kept as JSON for staff review.
        $ziyarah = $in['ziyarah'] ?? null;
        $addons  = $in['addons'] ?? null;
        $options = $in['options'] ?? null;

        try {
            $db->insert('umrah_quote_requests', [
                'request_ref'    => $ref,
                'user_id'        => (string) ($in['user_id'] ?? ($_SESSION['user_id'] ?? '')) ?: null,
                'template_id'    => !empty($in['template_id']) ? (int) $in['template_id'] : null,
                'departure_id'   => !empty($in['departure_id']) ? (int) $in['departure_id'] : null,
                'origin_city'    => ($in['origin_city'] ?? '') !== '' ? (string) $in['origin_city'] : null,
                'preferred_month'=> ($in['preferred_month'] ?? '') !== '' ? (string) $in['preferred_month'] : null,
                'preferred_date' => !empty($in['preferred_date']) ? (string) $in['preferred_date'] : null,
                'tier_code'      => ($in['tier_code'] ?? '') !== '' ? (string) $in['tier_code'] : null,
                'pax'            => $pax,
                'madinah_nights' => isset($in['madinah_nights']) ? (int) $in['madinah_nights'] : null,
                'makkah_nights'  => isset($in['makkah_nights']) ? (int) $in['makkah_nights'] : null,
                'total_weeks'    => isset($in['total_weeks']) ? (int) $in['total_weeks'] : null,
                'ziyarah'        => $ziyarah !== null ? json_encode($ziyarah, JSON_UNESCAPED_SLASHES) : null,
                'addons'         => $addons !== null ? json_encode($addons, JSON_UNESCAPED_SLASHES) : null,
                'options'        => $options !== null ? json_encode($options, JSON_UNESCAPED_SLASHES) : null,
                'notes'          => ($in['notes'] ?? '') !== '' ? (string) $in['notes'] : null,
                'name'           => $name,
                'email'          => $email ?: null,
                'phone'          => $phone ?: null,
                'status'         => 'new',
                'created_at'     => $now,
            ]);
            $id = (int) $db->id();
        } catch (\Throwable $e) {
            error_log('umrah_quote_request_create: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not submit your request. Please try again.'];
        }

        umrah_audit($db, 'umrah_quote_request', $ref, 'created', null,
            ['pax' => $pax, 'tier' => $in['tier_code'] ?? null, 'weeks' => $in['total_weeks'] ?? null]);

        // Best-effort staff notification (non-fatal). Reuses the umrah notify
        // queue if present; otherwise silently skips.
        if (function_exists('umrah_notify')) {
            try {
                umrah_notify($db, null, 'umrah_quote_request',
                    'New Umrah customize request ' . $ref,
                    "New personalized Umrah request {$ref} from {$name} ({$email}{$phone}). Pax {$pax}.",
                    'email', false);
            } catch (\Throwable $e) { /* non-fatal */ }
        }

        return ['ok' => true, 'request_ref' => $ref, 'id' => $id];
    }
}

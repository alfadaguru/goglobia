<?php
// ============================================================================
// UMRAH REDESIGN — service layer (docs/UMRAH-PHASE1-BUILD-PLAN.md §2/§3).
// Plain PHP helpers. Reuses the shared platform (MARKUP, currency helpers). The
// SERVER is the only price authority — the browser references a quote_ref and
// the server recomputes/validates at hold, booking and payment.
// ============================================================================

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
     * @return array{unit:float, currency:string, source:string, promo:array|null}
     */
    function umrah_price_resolve($db, array $dt): array
    {
        $currency = strtoupper(trim((string) ($dt['currency'] ?? 'NGN'))) ?: 'NGN';
        $now = time();

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
                ];
            }
        }

        // 2. departure-tier regular direct price
        if ($dt['regular_price'] !== null && (float) $dt['regular_price'] > 0) {
            return ['unit' => (float) $dt['regular_price'], 'currency' => $currency, 'source' => 'departure_tier_regular', 'promo' => null];
        }

        // 4/5. cost + markup fallback (only when NO direct price is set). Uses the
        // shared MARKUP() against the umrah module — this is the ONLY branch that
        // applies markup.
        $base = (float) ($dt['cost_price'] ?? 0);
        if ($base > 0 && function_exists('MARKUP')) {
            $module = $db->get('modules', '*', ['name' => 'umrah', 'type' => 'umrah']);
            $marked = MARKUP($base, $module ?: null, $db, $currency, $currency);
            $unit = (!empty($marked['price']) && $marked['price'] > 0) ? (float) $marked['price'] : $base;
            return ['unit' => round($unit, 2), 'currency' => $currency, 'source' => 'cost_plus_markup', 'promo' => null];
        }

        // Nothing priced → 0 (caller treats as not-bookable/quote-required).
        return ['unit' => 0.0, 'currency' => $currency, 'source' => 'unpriced', 'promo' => null];
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
        $dt = $db->get('umrah_departure_tiers', '*', ['id' => $departureTierId]);
        if (!$dt) {
            return ['ok' => false, 'message' => 'Departure-tier not found'];
        }
        if (($dt['status'] ?? '') === 'hidden') {
            return ['ok' => false, 'message' => 'This option is not available'];
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
        $cap = ($dt['tier_capacity'] !== null) ? (int) $dt['tier_capacity'] : 0;
        if ($cap <= 0) {
            $dep = $db->get('umrah_departures', ['capacity'], ['id' => $dt['departure_id']]);
            $cap = (int) ($dep['capacity'] ?? 0);
        }
        $confirmed = (int) $db->sum('umrah_bookings', 'pax', [
            'departure_tier_id' => $departureTierId,
            'booking_status'    => ['held', 'confirmed', 'completed'],
        ]);
        $activeHolds = (int) $db->sum('umrah_inventory_holds', 'qty', [
            'departure_tier_id' => $departureTierId,
            'state'             => 'held',
            'expires_at[>]'     => date('Y-m-d H:i:s'),
        ]);
        $remaining = max(0, $cap - $confirmed - $activeHolds);
        return ['ok' => true, 'capacity' => $cap, 'confirmed' => $confirmed, 'active_holds' => $activeHolds, 'remaining' => $remaining];
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
                $locked = $db->query(
                    "SELECT dt.id, dt.departure_id, dt.tier_capacity, dt.status, d.capacity AS dep_capacity
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
                if (($row['status'] ?? '') === 'sold_out' || ($row['status'] ?? '') === 'hidden') {
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

        $dt = $db->get('umrah_departure_tiers', '*', ['id' => $quote['departure_tier_id']]);
        if (!$dt) { return ['ok' => false, 'message' => 'Departure-tier not found']; }
        $departure = $db->get('umrah_departures', '*', ['id' => $dt['departure_id']]);
        $template  = $departure ? $db->get('umrah_package_templates', '*', ['id' => $departure['template_id']]) : null;

        $total    = (float) $quote['total_price'];
        $currency = (string) $quote['currency'];
        $pax      = (int) $quote['pax'];
        $userId   = (string) ($lead['user_id'] ?? ($_SESSION['user_id'] ?? ''));

        $schedule = umrah_payment_schedule($db, $planCode, $total, (string) ($departure['departure_date'] ?? date('Y-m-d')));
        $amountDueNow = $schedule['installments'][0]['amount'] ?? $total;

        // Commercial snapshot (spec §57) — frozen at booking creation.
        $snapshot = [
            'quote_ref'    => $quoteRef,
            'unit_price'   => (float) $quote['unit_price'],
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
                $planCode, $snapshot, $schedule, $lead, $holdId, $now, $quote, &$result
            ) {
                // 1. Generic bookings row (payment/invoice of record).
                $db->insert('bookings', [
                    'invoice_id'      => $invoiceId,
                    'booking_status'  => 'pending',
                    'payment_status'  => 'unpaid',
                    'price_original'  => $total,
                    'price_markup'    => $total,
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

        // Idempotency: if this txn is already recorded, do nothing.
        if ($txnId) {
            $seen = $db->get('umrah_installments', 'id', ['umrah_booking_id' => $ubId, 'transaction_id' => $txnId]);
            if ($seen) {
                return ['ok' => true, 'status' => 'already', 'confirmed' => ($ub['booking_status'] === 'confirmed'), 'price_locked' => !empty($ub['price_locked_at'])];
            }
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

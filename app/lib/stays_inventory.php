<?php
// FILE: app/lib/stays_inventory.php
// Real, non-oversellable stays availability (Phase 1 inc 6). The no-oversell SPINE.
//
// Two problems this solves (see docs/supplier/ Phase-1 plan):
//   1. room_options had no STABLE id — it was a positional array index, so holds /
//      inventory keyed on position mis-associate when options are reordered/deleted.
//      stays_option_stable_id() lazily assigns a persistent `option_id` inside each
//      room_options entry (monotonic per room) without disturbing array order, so
//      the existing admin UI + the room_options compatibility bridge keep working.
//   2. available_quantity was never decremented. stays_inventory holds the real
//      per-(room,option,date) count; stays_hold_create() decrements it ATOMICALLY
//      with SELECT ... FOR UPDATE across EVERY night of the stay, so two concurrent
//      bookings for the last room can never both succeed.
//
// Modeled on umrah_hold_create() (app/lib/umrah/services.php:362) — the proven
// FOR-UPDATE capacity-hold precedent. All functions are defensive/non-fatal.

if (!function_exists('stays_room_option_ids')) {
    /**
     * Ensure every option in a room's room_options JSON has a stable integer
     * `option_id`, assigning + persisting any that are missing. Returns the decoded
     * options array (each entry guaranteed to have 'option_id'). Idempotent.
     *
     * Stable ids are monotonic per room: max existing id + 1, so deleting/reordering
     * never reuses or shifts an id.
     */
    function stays_room_option_ids($db, int $roomId, int $stayId): array
    {
        $room = $db->get('stays_rooms', ['room_options'], ['id' => $roomId, 'stay_id' => $stayId]);
        if (!$room) { return []; }
        $options = [];
        if (!empty($room['room_options'])) {
            $decoded = json_decode((string) $room['room_options'], true);
            if (is_array($decoded)) { $options = $decoded; }
        }
        if (empty($options)) { return []; }

        // Find the current max stable id so new assignments never collide.
        $maxId = 0;
        foreach ($options as $o) {
            if (isset($o['option_id']) && (int) $o['option_id'] > $maxId) {
                $maxId = (int) $o['option_id'];
            }
        }
        $changed = false;
        foreach ($options as $i => $o) {
            if (empty($o['option_id'])) {
                $options[$i]['option_id'] = ++$maxId;
                $changed = true;
            }
        }
        if ($changed) {
            try {
                $db->update('stays_rooms',
                    ['room_options' => json_encode($options), 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => $roomId, 'stay_id' => $stayId]);
            } catch (\Throwable $e) {
                error_log('stays_room_option_ids: ' . $e->getMessage());
            }
        }
        return $options;
    }
}

if (!function_exists('stays_option_default_quantity')) {
    /** The option's configured available_quantity (the per-night ceiling), or 0. */
    function stays_option_default_quantity(array $options, int $optionId): int
    {
        foreach ($options as $o) {
            if ((int) ($o['option_id'] ?? 0) === $optionId) {
                return max(0, (int) ($o['available_quantity'] ?? 0));
            }
        }
        return 0;
    }
}

if (!function_exists('stays_recompute_net')) {
    /**
     * SERVER-AUTHORITATIVE NET for an own-inventory ('hotels'/'stays') booking.
     *
     * The booking submit paths must NOT trust the client-supplied net
     * (actual_amount) — a client could post actual_amount=1 and pay ~1. This
     * recomputes the net from the single source of truth the public rooms API and
     * the hold engine already use: stays_rooms.room_options[].price, overlaid with
     * the per-night stays_rooms_calendar rate when present, summed across every
     * night in [checkin, checkout) and multiplied by the booked quantity, for each
     * selected room/option. The result is in the HOTEL'S BASE CURRENCY (same basis
     * as room_options.price) — exactly what the rooms API sums BEFORE markup.
     *
     * Mirrors modules/stays/hotels/rooms.php:216-230 (the $totalRaw accumulation)
     * so the figure equals what the customer was shown, pre-markup.
     *
     * @param array  $selectedRooms each: ['room_id'=>int, 'quantity'=>int,
     *               'option'=>['option_id'=>int], ...] (as stored in the draft)
     * @param string $toCurrency   convert the net INTO this currency (the booking's
     *               base currency). room_options.price is in the HOTEL's currency
     *               (stays.currency); when they differ we must convert, exactly as
     *               rooms.php does with CURRENCY_CONVERT($x,$db,$hotelCurrency,$display)
     *               — otherwise a EUR hotel's number would be charged as the base
     *               currency. '' = no conversion (assume already base).
     * @return array ['ok'=>bool, 'net'=>float, 'lines'=>array, 'message'=>?string]
     *               ok=false when a selected room/option cannot be resolved (caller
     *               should refuse rather than fall back to the client value).
     */
    function stays_recompute_net($db, int $stayId, array $selectedRooms, string $checkin, string $checkout, string $toCurrency = ''): array
    {
        if ($stayId <= 0) { return ['ok' => false, 'net' => 0.0, 'message' => 'No property']; }
        $nights = stays_date_range($checkin, $checkout);
        $nightCount = count($nights);
        if ($nightCount < 1) { return ['ok' => false, 'net' => 0.0, 'message' => 'Invalid dates']; }

        // The hotel's own currency — room_options.price and calendar rates are in it.
        $hotelCurrency = '';
        try {
            $hrow = $db->get('stays', ['currency'], ['id' => $stayId]);
            $hotelCurrency = strtoupper(trim((string) ($hrow['currency'] ?? '')));
        } catch (\Throwable $e) { $hotelCurrency = ''; }

        // Per-date calendar rates for this property, keyed room_option_date.
        $calendarRates = [];
        try {
            $calRows = $db->select('stays_rooms_calendar', ['room_id', 'option_id', 'date', 'price'],
                ['stay_id' => $stayId, 'date' => $nights]) ?: [];
            foreach ($calRows as $cr) {
                $calendarRates[$cr['room_id'] . '_' . $cr['option_id'] . '_' . $cr['date']] = (float) $cr['price'];
            }
        } catch (\Throwable $e) { $calendarRates = []; } // calendar optional → base price

        $net = 0.0;
        $lines = [];
        foreach ($selectedRooms as $sr) {
            $roomId   = (int) ($sr['room_id'] ?? 0);
            $optionId = (int) ($sr['option']['option_id'] ?? ($sr['option_id'] ?? 0));
            $qty      = max(1, (int) ($sr['quantity'] ?? ($sr['qty'] ?? ($sr['rooms'] ?? 1))));
            if ($roomId <= 0 || $optionId <= 0) {
                return ['ok' => false, 'net' => 0.0, 'message' => 'Unpriceable room selection'];
            }
            // Resolve the option's base price from the authoritative room_options JSON.
            $options = stays_room_option_ids($db, $roomId, $stayId);
            $basePrice = null;
            foreach ($options as $o) {
                if ((int) ($o['option_id'] ?? 0) === $optionId) {
                    $basePrice = isset($o['price']) ? (float) $o['price'] : null;
                    break;
                }
            }
            if ($basePrice === null || $basePrice <= 0) {
                return ['ok' => false, 'net' => 0.0, 'message' => 'Room option no longer available'];
            }
            // Sum per-night (calendar overrides base), × qty. Matches rooms.php $totalRaw.
            $roomNet = 0.0;
            foreach ($nights as $d) {
                $k = $roomId . '_' . $optionId . '_' . $d;
                $roomNet += isset($calendarRates[$k]) ? $calendarRates[$k] : $basePrice;
            }
            $roomNet *= $qty;
            $net += $roomNet;
            $lines[] = ['room_id' => $roomId, 'option_id' => $optionId, 'qty' => $qty, 'net' => round($roomNet, 2)];
        }

        // Convert from the HOTEL's currency into the booking's base currency when they
        // differ (same step rooms.php applies to what the customer was shown). Without
        // this a non-base-currency hotel's raw number would be charged as base currency.
        $converted = $net;
        $toCurrency = strtoupper(trim($toCurrency));
        if ($toCurrency !== '' && $hotelCurrency !== '' && $toCurrency !== $hotelCurrency && function_exists('CURRENCY_CONVERT')) {
            $c = CURRENCY_CONVERT($net, $db, $hotelCurrency, $toCurrency);
            // CURRENCY_CONVERT returns a float (or ['price'=>..] in some variants).
            if (is_array($c)) { $converted = (float) ($c['price'] ?? $net); }
            else { $converted = (float) $c; }
            if ($converted <= 0) { $converted = $net; } // never let a conversion glitch zero the net
        }

        return ['ok' => true, 'net' => round($converted, 2), 'net_hotel_currency' => round($net, 2),
                'hotel_currency' => $hotelCurrency, 'to_currency' => $toCurrency, 'lines' => $lines];
    }
}

if (!function_exists('stays_date_range')) {
    /** All occupied nights between check-in (inclusive) and check-out (exclusive). */
    function stays_date_range(string $checkin, string $checkout): array
    {
        $out = [];
        $start = strtotime($checkin);
        $end = strtotime($checkout);
        if ($start === false || $end === false || $end <= $start) { return []; }
        for ($t = $start; $t < $end; $t += 86400) {
            $out[] = date('Y-m-d', $t);
        }
        return $out;
    }
}

if (!function_exists('stays_available_on_date')) {
    /**
     * Remaining availability for (room, option, date), WITHOUT locking — for display.
     * remaining = base_count − confirmed/held usage. base_count comes from the
     * stays_inventory row if present, else the option's available_quantity (bridge).
     * Not authoritative for booking — stays_hold_create() re-checks under lock.
     */
    function stays_available_on_date($db, int $stayId, int $roomId, int $optionId, string $date, int $defaultQty, string $now = ''): int
    {
        if ($now === '') { $now = date('Y-m-d H:i:s'); }
        try {
            $inv = $db->get('stays_inventory', ['available_count', 'closed'],
                ['stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $optionId, 'date' => $date]);
        } catch (\Throwable $e) { $inv = null; }
        if ($inv && (int) ($inv['closed'] ?? 0) === 1) { return 0; }
        $base = ($inv && $inv['available_count'] !== null) ? (int) $inv['available_count'] : $defaultQty;
        try {
            // Committed = active held (unexpired) + consumed (permanent, see
            // stays_hold_create). Mirrors the authoritative in-lock check.
            $held = (int) $db->sum('stays_holds', 'qty', [
                'stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $optionId,
                'state' => 'held', 'expires_at[>]' => $now,
                'date_from[<=]' => $date, 'date_to[>]' => $date,
            ]);
            $consumed = (int) $db->sum('stays_holds', 'qty', [
                'stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $optionId,
                'state' => 'consumed',
                'date_from[<=]' => $date, 'date_to[>]' => $date,
            ]);
        } catch (\Throwable $e) { $held = 0; $consumed = 0; }
        return max(0, $base - $held - $consumed);
    }
}

if (!function_exists('stays_hold_create')) {
    /**
     * Atomically HOLD `qty` of (room, option) across every night in [checkin, checkout).
     * Returns ['ok'=>bool, 'hold_id'=>?int, 'message'=>?, 'date'=>?failing-date].
     *
     * The no-oversell guarantee: the whole operation runs in one transaction; every
     * night's inventory row is locked FOR UPDATE and re-checked inside the lock, so
     * two concurrent holds for the last unit serialize and the second fails cleanly.
     * Inventory rows are created on demand from the option's available_quantity
     * (the compatibility bridge) the first time a date is touched.
     */
    function stays_hold_create($db, int $stayId, int $roomId, int $optionId, string $checkin, string $checkout, int $qty = 1, ?string $ref = null, int $holdMinutes = 20): array
    {
        if ($qty < 1) { $qty = 1; }
        $nights = stays_date_range($checkin, $checkout);
        if (empty($nights)) {
            return ['ok' => false, 'message' => 'Invalid date range'];
        }

        // The per-night ceiling to seed new inventory rows from (bridge).
        $options = stays_room_option_ids($db, $roomId, $stayId);
        $defaultQty = stays_option_default_quantity($options, $optionId);

        $losLength = count($nights);
        $arrivalNight = $nights[0];

        $result = ['ok' => false, 'message' => 'Could not create hold'];
        try {
            $db->action(function ($db) use ($stayId, $roomId, $optionId, $nights, $qty, $ref, $holdMinutes, $defaultQty, $losLength, $arrivalNight, &$result) {
                $now = date('Y-m-d H:i:s');

                // 1) Lock + verify EVERY night first (all-or-nothing). Seed a row
                //    from the option default if a night has none yet.
                foreach ($nights as $date) {
                    $locked = $db->query(
                        "SELECT id, available_count, closed, min_stay FROM stays_inventory
                         WHERE stay_id = :s AND room_id = :r AND option_id = :o AND date = :d
                         FOR UPDATE",
                        [':s' => $stayId, ':r' => $roomId, ':o' => $optionId, ':d' => $date]
                    );
                    $invRow = $locked ? $locked->fetch(\PDO::FETCH_ASSOC) : null;

                    // MIN-STAY: a minimum length-of-stay set on the ARRIVAL night must
                    // be honoured — a guest cannot book fewer nights than the owner
                    // requires for that date. (Checked on check-in night only, the
                    // standard PMS rule.) Stored per-night in stays_inventory.min_stay;
                    // previously written by the calendar but NEVER enforced at booking.
                    if ($date === $arrivalNight && $invRow && $invRow['min_stay'] !== null) {
                        $minStay = (int) $invRow['min_stay'];
                        if ($minStay > 0 && $losLength < $minStay) {
                            $result = ['ok' => false, 'date' => $date, 'min_stay' => $minStay,
                                'message' => 'This date requires a minimum stay of ' . $minStay . ' night' . ($minStay === 1 ? '' : 's') . '.'];
                            return false; // rollback
                        }
                    }

                    if (!$invRow) {
                        // Seed from the option's available_quantity. If the option has
                        // no capacity configured, there is nothing to sell.
                        if ($defaultQty < 1) {
                            $result = ['ok' => false, 'message' => 'No availability configured', 'date' => $date];
                            return false; // rollback
                        }
                        $db->insert('stays_inventory', [
                            'stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $optionId,
                            'date' => $date, 'available_count' => $defaultQty, 'closed' => 0,
                            'created_at' => $now,
                        ]);
                        $baseCount = $defaultQty;
                    } else {
                        if ((int) ($invRow['closed'] ?? 0) === 1) {
                            $result = ['ok' => false, 'message' => 'Not available on ' . $date, 'date' => $date];
                            return false;
                        }
                        $baseCount = (int) $invRow['available_count'];
                    }

                    // Seats already committed on this night = active (unexpired) HELD
                    // + CONSUMED. Consumed = a confirmed/paid booking: it reduces
                    // availability PERMANENTLY (no expiry) — otherwise a paid booking's
                    // seat would be re-sellable the moment its 20-min hold expired
                    // (the long-term oversell gap). Matches stays_calendar_committed_on.
                    $held = (int) $db->sum('stays_holds', 'qty', [
                        'stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $optionId,
                        'state' => 'held', 'expires_at[>]' => $now,
                        'date_from[<=]' => $date, 'date_to[>]' => $date,
                    ]);
                    $consumed = (int) $db->sum('stays_holds', 'qty', [
                        'stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $optionId,
                        'state' => 'consumed',
                        'date_from[<=]' => $date, 'date_to[>]' => $date,
                    ]);
                    $remaining = $baseCount - $held - $consumed;
                    if ($qty > $remaining) {
                        $result = ['ok' => false, 'message' => 'Not enough availability on ' . $date,
                                   'date' => $date, 'remaining' => max(0, $remaining)];
                        return false; // rollback — no hold created on any night
                    }
                }

                // 2) All nights have room → record ONE hold spanning the range.
                $expiresAt = date('Y-m-d H:i:s', time() + max(1, $holdMinutes) * 60);
                $db->insert('stays_holds', [
                    'stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $optionId,
                    'date_from' => $nights[0], 'date_to' => $checkout,
                    'qty' => $qty, 'state' => 'held', 'ref' => $ref,
                    'expires_at' => $expiresAt, 'created_at' => $now,
                ]);
                $result = ['ok' => true, 'hold_id' => (int) $db->id()];
                return true; // commit
            });
        } catch (\Throwable $e) {
            error_log('stays_hold_create: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not create hold'];
        }
        return $result;
    }
}

if (!function_exists('stays_hold_consume')) {
    /** Mark a hold consumed (booking confirmed). Idempotent by hold id. */
    function stays_hold_consume($db, int $holdId): bool
    {
        try {
            $db->update('stays_holds', ['state' => 'consumed'], ['id' => $holdId, 'state' => 'held']);
            return true;
        } catch (\Throwable $e) {
            error_log('stays_hold_consume: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('stays_hold_consume_ref')) {
    /**
     * Consume EVERY still-held hold for a booking reference (invoice id) — called when
     * the booking is confirmed/paid. Flips state 'held'→'consumed' so the seats are
     * reserved PERMANENTLY (consumed holds have no expiry and are counted by the
     * availability checks), closing the long-term oversell gap where a paid booking's
     * seat freed up once its 20-minute hold expired. Idempotent: only touches rows
     * still in 'held' (re-running, or running after expiry, is a safe no-op — and if a
     * hold already expired before payment, see the caller note). Returns rows consumed.
     */
    function stays_hold_consume_ref($db, string $ref): int
    {
        if ($ref === '') { return 0; }
        try {
            $stmt = $db->update('stays_holds', ['state' => 'consumed'], ['ref' => $ref, 'state' => 'held']);
            return $stmt ? (int) $stmt->rowCount() : 0;
        } catch (\Throwable $e) {
            error_log('stays_hold_consume_ref: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('stays_hold_release')) {
    /** Release a hold (abandoned/failed). Idempotent; frees the seats immediately. */
    function stays_hold_release($db, $holdIdOrRef): bool
    {
        try {
            if (is_int($holdIdOrRef) || ctype_digit((string) $holdIdOrRef)) {
                $db->update('stays_holds', ['state' => 'released'], ['id' => (int) $holdIdOrRef, 'state' => 'held']);
            } else {
                $db->update('stays_holds', ['state' => 'released'], ['ref' => (string) $holdIdOrRef, 'state' => 'held']);
            }
            return true;
        } catch (\Throwable $e) {
            error_log('stays_hold_release: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('stays_bulk_price_set')) {
    /**
     * BULK / SEASONAL / WEEKDAY-WEEKEND pricing (PMS completion #06). Writes per-night
     * prices into stays_rooms_calendar — the SAME table the live booking path reads
     * (modules/stays/hotels/rooms.php) and the no-oversell net recompute uses — so the
     * rates actually take effect (unlike the dead stays_rate_plans mirror).
     *
     * Modes:
     *   'flat'     : set every night in [from,to] to $weekdayPrice.
     *   'split'    : weekdays (Mon–Thu) = $weekdayPrice, weekend (Fri–Sun) = $weekendPrice.
     *   'adjust'   : multiply the EXISTING per-night price (or the option base when no
     *                calendar row yet) by (1 + $percent/100) — seasonal uplift/discount.
     *
     * Only the given room+option is touched. Dates are inclusive. Returns
     * ['ok','updated','message'].
     */
    function stays_bulk_price_set($db, int $stayId, int $roomId, int $optionId, string $from, string $to, string $mode, float $weekdayPrice = 0.0, float $weekendPrice = 0.0, float $percent = 0.0): array
    {
        if ($stayId <= 0 || $roomId <= 0 || $optionId <= 0) { return ['ok' => false, 'message' => 'Bad input']; }
        $fts = strtotime($from); $tts = strtotime($to);
        if ($fts === false || $tts === false || $tts < $fts) { return ['ok' => false, 'message' => 'Invalid date range']; }
        if (($tts - $fts) / 86400 > 366) { return ['ok' => false, 'message' => 'Range too long (max 366 days)']; }
        if (!in_array($mode, ['flat', 'split', 'adjust'], true)) { return ['ok' => false, 'message' => 'Unknown mode']; }

        // Validate the option belongs to the room, and get its base price (for 'adjust'
        // on nights with no calendar row yet).
        $options = stays_room_option_ids($db, $roomId, $stayId);
        $base = null;
        foreach ($options as $o) {
            if ((int) ($o['option_id'] ?? 0) === $optionId) { $base = (float) ($o['price'] ?? 0); break; }
        }
        if ($base === null) { return ['ok' => false, 'message' => 'That rate is not on this room.']; }

        $now = date('Y-m-d H:i:s');
        $updated = 0;
        $result = ['ok' => false, 'message' => 'Could not apply prices'];
        try {
            $db->action(function ($db) use ($stayId, $roomId, $optionId, $fts, $tts, $mode, $weekdayPrice, $weekendPrice, $percent, $base, $now, &$updated, &$result) {
                for ($t = $fts; $t <= $tts; $t += 86400) {
                    $date = date('Y-m-d', $t);
                    $dow = (int) date('N', $t); // 1=Mon .. 7=Sun
                    $isWeekend = ($dow >= 5); // Fri/Sat/Sun

                    $existing = $db->get('stays_rooms_calendar', ['id', 'price'],
                        ['stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $optionId, 'date' => $date]);

                    if ($mode === 'flat') {
                        $price = $weekdayPrice;
                    } elseif ($mode === 'split') {
                        $price = $isWeekend ? $weekendPrice : $weekdayPrice;
                    } else { // adjust
                        $cur = $existing ? (float) $existing['price'] : $base;
                        $price = $cur * (1 + ($percent / 100));
                    }
                    $price = round(max(0, $price), 2);
                    if ($price <= 0) { continue; } // never write a zero/negative rate

                    if ($existing) {
                        $db->update('stays_rooms_calendar', ['price' => $price, 'updated_at' => $now], ['id' => (int) $existing['id']]);
                    } else {
                        $db->insert('stays_rooms_calendar', [
                            'stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $optionId,
                            'date' => $date, 'price' => $price, 'created_at' => $now,
                        ]);
                    }
                    $updated++;
                }
                $result = ['ok' => true, 'updated' => $updated, 'message' => $updated . ' night(s) priced.'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('stays_bulk_price_set: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not apply prices.'];
        }
        return $result;
    }
}

if (!function_exists('stays_hold_supersede_ref')) {
    /**
     * Release EVERY still-active hold (both 'held' AND 'consumed') for a booking
     * reference — used when a reservation's dates or room CHANGE, so the old nights
     * are freed before the new ones are held. Unlike stays_hold_release (which only
     * touches 'held', preserving a paid booking's permanent hold), this also frees a
     * 'consumed' hold because the booking is being re-held elsewhere in the SAME
     * transaction. MUST be called inside a transaction that then re-creates the hold,
     * or the seat is left un-held. Returns rows affected.
     */
    function stays_hold_supersede_ref($db, string $ref): int
    {
        if ($ref === '') { return 0; }
        try {
            $stmt = $db->update('stays_holds', ['state' => 'released'],
                ['ref' => $ref, 'state' => ['held', 'consumed']]);
            return $stmt ? (int) $stmt->rowCount() : 0;
        } catch (\Throwable $e) {
            error_log('stays_hold_supersede_ref: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('stays_holds_expire_sweep')) {
    /** Mark expired holds (state=held, past expires_at) as 'expired'. Cron-friendly. */
    function stays_holds_expire_sweep($db): int
    {
        try {
            $stmt = $db->update('stays_holds', ['state' => 'expired'],
                ['state' => 'held', 'expires_at[<]' => date('Y-m-d H:i:s')]);
            return $stmt ? (int) $stmt->rowCount() : 0;
        } catch (\Throwable $e) {
            error_log('stays_holds_expire_sweep: ' . $e->getMessage());
            return 0;
        }
    }
}

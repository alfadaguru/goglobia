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
            $held = (int) $db->sum('stays_holds', 'qty', [
                'stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $optionId,
                'state' => 'held', 'expires_at[>]' => $now,
                'date_from[<=]' => $date, 'date_to[>]' => $date,
            ]);
        } catch (\Throwable $e) { $held = 0; }
        return max(0, $base - $held);
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

        $result = ['ok' => false, 'message' => 'Could not create hold'];
        try {
            $db->action(function ($db) use ($stayId, $roomId, $optionId, $nights, $qty, $ref, $holdMinutes, $defaultQty, &$result) {
                $now = date('Y-m-d H:i:s');

                // 1) Lock + verify EVERY night first (all-or-nothing). Seed a row
                //    from the option default if a night has none yet.
                foreach ($nights as $date) {
                    $locked = $db->query(
                        "SELECT id, available_count, closed FROM stays_inventory
                         WHERE stay_id = :s AND room_id = :r AND option_id = :o AND date = :d
                         FOR UPDATE",
                        [':s' => $stayId, ':r' => $roomId, ':o' => $optionId, ':d' => $date]
                    );
                    $invRow = $locked ? $locked->fetch(\PDO::FETCH_ASSOC) : null;

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

                    // Active holds already placed on this night (excludes expired).
                    $held = (int) $db->sum('stays_holds', 'qty', [
                        'stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $optionId,
                        'state' => 'held', 'expires_at[>]' => $now,
                        'date_from[<=]' => $date, 'date_to[>]' => $date,
                    ]);
                    $remaining = $baseCount - $held;
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

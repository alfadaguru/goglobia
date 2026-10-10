<?php
// FILE: app/lib/stays_calendar.php
// RESERVATION CALENDAR / TAPE-CHART (Phase 1 inc S39; stays depth).
//
// A read model + a safe write for the no-oversell spine (stays_inventory + stays_holds,
// app/lib/stays_inventory.php). The grid shows, per (room, stable option_id) × night:
//   base      — the per-night ceiling (stays_inventory.available_count, else the
//               option's configured available_quantity — the compatibility bridge)
//   held      — SUM of active holds on that night (state='held', not expired) +
//               consumed holds (booked); group blocks (ref 'GRP:…') are counted and
//               flagged so the operator can see committed group inventory
//   closed    — stays_inventory.closed (stop-sell)
//   remaining — max(0, base − held)   (what stays_hold_create would still allow)
//
// Everything is keyed on the STABLE option_id (stays_room_option_ids), the SAME key the
// hold engine uses — never the positional index the admin rates-calendar uses. Reads are
// BULK (one inventory query + one holds query for the whole window), not per-cell.
//
// stays_inventory_set() writes availability/closed/min_stay across a date range, and
// REFUSES to drop available_count below the rooms already committed on any night (so the
// operator can't retro-create an oversell). All functions defensive/non-fatal.

if (!function_exists('stays_calendar_window')) {
    /** Normalize a calendar request → ['start'=>Y-m-d, 'nights'=>int, 'dates'=>[Y-m-d,…]]. */
    function stays_calendar_window(string $start, int $nights): array
    {
        $nights = max(1, min(60, $nights));           // clamp: 1..60 nights
        $ts = strtotime($start);
        if ($ts === false) { $ts = strtotime(date('Y-m-d')); }
        $start = date('Y-m-d', $ts);
        $dates = [];
        for ($i = 0; $i < $nights; $i++) { $dates[] = date('Y-m-d', $ts + $i * 86400); }
        return ['start' => $start, 'nights' => $nights, 'dates' => $dates];
    }
}

if (!function_exists('stays_calendar_grid')) {
    /**
     * Build the tape-chart for a property over a date window.
     * Returns:
     *   ['window'=>…, 'rooms'=>[ ['id','room_type_id','name','options'=>[
     *        ['option_id','name','price','default_qty','cells'=>[ date => [
     *           'base','held','group_held','consumed','closed','remaining','min_stay'] ] ] ] ] ] ]
     */
    function stays_calendar_grid($db, int $stayId, string $start, int $nights, array $taxonomy = []): array
    {
        $win = stays_calendar_window($start, $nights);
        $dates = $win['dates'];
        $end = $win['dates'][count($dates) - 1];          // last NIGHT (inclusive)
        $out = ['window' => $win, 'rooms' => []];
        if ($stayId <= 0) { return $out; }

        $roomTypeMap = $taxonomy['room_type'] ?? [];
        $boardMap    = $taxonomy['board'] ?? [];

        try {
            $roomsRaw = $db->select('stays_rooms', ['id', 'room_type_id', 'room_options', 'status'],
                ['stay_id' => $stayId, 'ORDER' => ['id' => 'ASC']]) ?: [];
        } catch (\Throwable $e) { error_log('stays_calendar_grid rooms: ' . $e->getMessage()); return $out; }
        if (empty($roomsRaw)) { return $out; }

        // --- BULK inventory read for the whole window -----------------------------
        // key: "roomId:optionId:date" => ['available_count','closed','min_stay']
        $inv = [];
        try {
            $invRows = $db->select('stays_inventory',
                ['room_id', 'option_id', 'date', 'available_count', 'closed', 'min_stay'],
                ['stay_id' => $stayId, 'date[>=]' => $dates[0], 'date[<=]' => $end]) ?: [];
            foreach ($invRows as $r) {
                $inv[(int) $r['room_id'] . ':' . (int) $r['option_id'] . ':' . $r['date']] = $r;
            }
        } catch (\Throwable $e) { error_log('stays_calendar_grid inv: ' . $e->getMessage()); }

        // --- BULK holds read: every hold overlapping the window -------------------
        // A hold [date_from, date_to) overlaps if date_from <= end AND date_to > start.
        $now = date('Y-m-d H:i:s');
        $holds = [];
        try {
            $holds = $db->select('stays_holds',
                ['room_id', 'option_id', 'date_from', 'date_to', 'qty', 'state', 'ref', 'expires_at'],
                ['stay_id' => $stayId, 'date_from[<=]' => $end, 'date_to[>]' => $dates[0]]) ?: [];
        } catch (\Throwable $e) { error_log('stays_calendar_grid holds: ' . $e->getMessage()); }

        // Pre-expand holds onto the nights they occupy within our window.
        // usage["roomId:optionId:date"] = ['held'=>,'group'=>,'consumed'=>]
        $usage = [];
        foreach ($holds as $h) {
            $state = (string) $h['state'];
            // Count committed usage only: active holds (not expired) + consumed (booked).
            if ($state === 'held') {
                if ($h['expires_at'] !== null && (string) $h['expires_at'] <= $now) { continue; } // expired-but-unswept
            } elseif ($state !== 'consumed') {
                continue; // released / expired → frees inventory, not shown as usage
            }
            $isGroup = strpos((string) $h['ref'], 'GRP:') === 0;
            $qty = max(0, (int) $h['qty']);
            $rid = (int) $h['room_id']; $oid = (int) $h['option_id'];
            // Nights this hold occupies that fall inside our window.
            foreach (stays_date_range((string) $h['date_from'], (string) $h['date_to']) as $d) {
                if ($d < $dates[0] || $d > $end) { continue; }
                $k = $rid . ':' . $oid . ':' . $d;
                if (!isset($usage[$k])) { $usage[$k] = ['held' => 0, 'group' => 0, 'consumed' => 0]; }
                if ($state === 'consumed') { $usage[$k]['consumed'] += $qty; }
                else { $usage[$k]['held'] += $qty; }
                if ($isGroup) { $usage[$k]['group'] += $qty; }
            }
        }

        // --- Assemble rows --------------------------------------------------------
        foreach ($roomsRaw as $room) {
            $rid = (int) $room['id'];
            $options = stays_room_option_ids($db, $rid, $stayId);     // stable ids (lazy-persist)
            if (empty($options)) { continue; }
            $roomName = $roomTypeMap[(int) $room['room_type_id']] ?? ('Room #' . $rid);

            $optOut = [];
            foreach ($options as $o) {
                $oid = (int) ($o['option_id'] ?? 0);
                if ($oid <= 0) { continue; }
                $optName = stays_calendar_option_name($o, $boardMap);
                $defQty = max(0, (int) ($o['available_quantity'] ?? 0));

                $cells = [];
                foreach ($dates as $d) {
                    $k = $rid . ':' . $oid . ':' . $d;
                    $invRow = $inv[$k] ?? null;
                    $base = ($invRow && $invRow['available_count'] !== null)
                        ? (int) $invRow['available_count'] : $defQty;
                    $closed = $invRow ? ((int) ($invRow['closed'] ?? 0) === 1) : false;
                    $minStay = ($invRow && $invRow['min_stay'] !== null) ? (int) $invRow['min_stay'] : null;
                    $u = $usage[$k] ?? ['held' => 0, 'group' => 0, 'consumed' => 0];
                    $usedTotal = $u['held'] + $u['consumed'];
                    $remaining = $closed ? 0 : max(0, $base - $usedTotal);
                    $cells[$d] = [
                        'base'       => $base,
                        'held'       => $u['held'],
                        'group_held' => $u['group'],
                        'consumed'   => $u['consumed'],
                        'closed'     => $closed,
                        'remaining'  => $remaining,
                        'min_stay'   => $minStay,
                    ];
                }
                $optOut[] = [
                    'option_id'   => $oid,
                    'name'        => $optName,
                    'price'       => round((float) ($o['price'] ?? 0), 2),
                    'default_qty' => $defQty,
                    'cells'       => $cells,
                ];
            }
            if (empty($optOut)) { continue; }
            $out['rooms'][] = [
                'id'           => $rid,
                'room_type_id' => (int) $room['room_type_id'],
                'name'         => $roomName,
                'status'       => (int) ($room['status'] ?? 1),
                'options'      => $optOut,
            ];
        }
        return $out;
    }
}

if (!function_exists('stays_calendar_option_name')) {
    /** Readable rate-option label from a room_options entry (board / breakfast / occupancy). */
    function stays_calendar_option_name(array $o, array $boardMap): string
    {
        if (!empty($o['board_id']) && isset($boardMap[(int) $o['board_id']])) {
            $name = $boardMap[(int) $o['board_id']];
        } elseif (!empty($o['breakfast_included'])) {
            $name = 'Bed & Breakfast';
        } else {
            $name = 'Room Only';
        }
        if (!empty($o['max_adults'])) { $name .= ' · ' . (int) $o['max_adults'] . ' ad'; }
        return $name;
    }
}

if (!function_exists('stays_inventory_set')) {
    /**
     * Upsert availability for (room, option) across [from, to] INCLUSIVE of both ends
     * (the operator picks start & end nights). $fields may set:
     *   'available_count' => int   (the per-night ceiling)
     *   'closed'          => 0|1
     *   'min_stay'        => int|null
     * Only the keys present are written. REFUSES to set available_count below the rooms
     * already committed (active held + consumed) on any night — returns an error naming
     * the first offending date — so no retro-oversell. Returns ['ok','updated','message'].
     */
    function stays_inventory_set($db, int $stayId, int $roomId, int $optionId, string $from, string $to, array $fields): array
    {
        if ($stayId <= 0 || $roomId <= 0 || $optionId <= 0) { return ['ok' => false, 'message' => 'Bad input']; }
        $fts = strtotime($from); $tts = strtotime($to);
        if ($fts === false || $tts === false || $tts < $fts) { return ['ok' => false, 'message' => 'Invalid date range']; }
        if (($tts - $fts) / 86400 > 366) { return ['ok' => false, 'message' => 'Range too long (max 366 days)']; }

        // Build the inclusive date list.
        $dates = [];
        for ($t = $fts; $t <= $tts; $t += 86400) { $dates[] = date('Y-m-d', $t); }

        $setCount  = array_key_exists('available_count', $fields);
        $newCount  = $setCount ? max(0, (int) $fields['available_count']) : null;
        $setClosed = array_key_exists('closed', $fields);
        $newClosed = $setClosed ? ((int) $fields['closed'] === 1 ? 1 : 0) : null;
        $setMin    = array_key_exists('min_stay', $fields);
        $newMin    = $setMin ? (($fields['min_stay'] === null || $fields['min_stay'] === '') ? null : max(1, (int) $fields['min_stay'])) : null;
        if (!$setCount && !$setClosed && !$setMin) { return ['ok' => false, 'message' => 'Nothing to change']; }

        // Seed ceiling for brand-new inventory rows from the option default (bridge).
        $options = stays_room_option_ids($db, $roomId, $stayId);
        $defQty = stays_option_default_quantity($options, $optionId);
        $now = date('Y-m-d H:i:s');

        $result = ['ok' => false, 'message' => 'Could not update'];
        try {
            $db->action(function ($db) use ($stayId, $roomId, $optionId, $dates, $setCount, $newCount,
                $setClosed, $newClosed, $setMin, $newMin, $defQty, $now, &$result) {

                $updated = 0;
                foreach ($dates as $d) {
                    // Lock the night's row (if any) for a consistent committed-usage read.
                    $locked = $db->query(
                        "SELECT id, available_count, closed, min_stay FROM stays_inventory
                         WHERE stay_id=:s AND room_id=:r AND option_id=:o AND date=:d FOR UPDATE",
                        [':s' => $stayId, ':r' => $roomId, ':o' => $optionId, ':d' => $d]
                    );
                    $row = $locked ? $locked->fetch(\PDO::FETCH_ASSOC) : null;
                    $curBase = $row ? (int) $row['available_count'] : $defQty;
                    $targetCount = $setCount ? $newCount : $curBase;

                    // Guard: never drop the ceiling below what is already committed
                    // tonight (active held + consumed) — no retro-oversell.
                    if ($setCount) {
                        $used = stays_calendar_committed_on($db, $stayId, $roomId, $optionId, $d, $now);
                        if ($newCount < $used) {
                            $result = ['ok' => false, 'message' => "Can't set " . $d . " to " . $newCount
                                . " — " . $used . " room(s) already committed that night."];
                            return false; // rollback the whole range
                        }
                    }

                    $payload = ['updated_at' => $now];
                    if ($setCount)  { $payload['available_count'] = $targetCount; }
                    if ($setClosed) { $payload['closed'] = $newClosed; }
                    if ($setMin)    { $payload['min_stay'] = $newMin; }

                    if ($row) {
                        $db->update('stays_inventory', $payload,
                            ['id' => (int) $row['id']]);
                    } else {
                        $db->insert('stays_inventory', array_merge([
                            'stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $optionId,
                            'date' => $d,
                            'available_count' => $setCount ? $targetCount : $defQty,
                            'closed' => $setClosed ? $newClosed : 0,
                            'min_stay' => $setMin ? $newMin : null,
                            'created_at' => $now,
                        ]));
                    }
                    $updated++;
                }
                $result = ['ok' => true, 'updated' => $updated, 'message' => $updated . ' night(s) updated.'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('stays_inventory_set: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not update availability.'];
        }
        return $result;
    }
}

if (!function_exists('stays_calendar_committed_on')) {
    /** Rooms committed on a night: active held (not expired) + consumed. Unlocked read. */
    function stays_calendar_committed_on($db, int $stayId, int $roomId, int $optionId, string $date, string $now = ''): int
    {
        if ($now === '') { $now = date('Y-m-d H:i:s'); }
        $held = 0; $consumed = 0;
        try {
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
        } catch (\Throwable $e) { error_log('stays_calendar_committed_on: ' . $e->getMessage()); }
        return $held + $consumed;
    }
}

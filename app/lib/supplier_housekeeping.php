<?php
// FILE: app/lib/supplier_housekeeping.php
// HOUSEKEEPING + PHYSICAL-ROOM ASSIGNMENT (Phase 1 inc S22; docs 01 §4.2).
//
// An OPERATIONAL overlay. Physical rooms (stays_physical_rooms) are individual units
// under a room TYPE (stays_rooms), each carrying a housekeeping status. They assist
// the front desk (assign a specific room at check-in; track clean/dirty).
//
// IMPORTANT boundary: the pooled `stays_inventory` (S6) REMAINS the sellability /
// anti-oversell authority. Housekeeping does NOT change marketplace availability —
// it's a desk-side layer. (Taking a room out of SELLABLE inventory on OOO is the
// maintenance increment's job, §4.3; S22 only tracks status + assignment.)
//
// Status machine (01 §4.2): clean → (assigned/occupied) → dirty (on check-out) →
// inspected → clean. Plus out_of_order (set/cleared manually). A room must be
// clean/inspected + active to be assignable at the desk.
//
// All functions defensive / non-fatal; ownership is enforced by the CALLER routes
// (supplier_can('rooms'|'reservations', …, $stayId)).

if (!function_exists('hk_statuses')) {
    function hk_statuses(): array
    {
        return [
            'clean'        => 'Clean',
            'dirty'        => 'Dirty',
            'inspected'    => 'Inspected',
            'out_of_order' => 'Out of order',
        ];
    }
}

if (!function_exists('hk_assignable_statuses')) {
    /** Statuses a room may be assigned to a guest from. */
    function hk_assignable_statuses(): array { return ['clean', 'inspected']; }
}

if (!function_exists('hk_status_transition_ok')) {
    /** Allowed manual transitions (the desk/housekeeping buttons). Check-out → dirty
     *  is automatic (not a manual option). */
    function hk_status_transition_ok(string $from, string $to): bool
    {
        $allowed = [
            'dirty'        => ['inspected', 'clean', 'out_of_order'],
            'inspected'    => ['clean', 'dirty', 'out_of_order'],
            'clean'        => ['dirty', 'out_of_order'],
            'out_of_order' => ['clean', 'dirty'],
        ];
        return in_array($to, $allowed[$from] ?? [], true);
    }
}

if (!function_exists('hk_room_set_status')) {
    /**
     * Set a physical room's housekeeping status (manual transition). Validates the
     * transition and that the room belongs to $stayId (defense in depth behind the
     * route's supplier_can). Returns bool.
     */
    function hk_room_set_status($db, int $physicalRoomId, int $stayId, string $to, string $actor = ''): bool
    {
        if ($physicalRoomId <= 0 || !isset(hk_statuses()[$to])) { return false; }
        try {
            $r = $db->get('stays_physical_rooms', ['id', 'hk_status'], ['id' => $physicalRoomId, 'stay_id' => $stayId]);
            if (!$r) { return false; }
            $from = (string) $r['hk_status'];
            if ($from === $to) { return true; } // no-op
            if (!hk_status_transition_ok($from, $to)) { return false; }
            $db->update('stays_physical_rooms', [
                'hk_status'   => $to,
                'hk_updated_by' => $actor !== '' ? substr($actor, 0, 155) : null,
                'updated_at'  => date('Y-m-d H:i:s'),
            ], ['id' => $physicalRoomId, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) {
            error_log('hk_room_set_status: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('hk_assign_attendant')) {
    /** Assign (or clear) a housekeeping attendant for a physical room (PMS #24).
     *  Owner-scoped by $stayId. Empty name clears the assignment. Returns bool. */
    function hk_assign_attendant($db, int $physicalRoomId, int $stayId, string $attendant): bool
    {
        if ($physicalRoomId <= 0) { return false; }
        try {
            $r = $db->get('stays_physical_rooms', ['id'], ['id' => $physicalRoomId, 'stay_id' => $stayId]);
            if (!$r) { return false; }
            $attendant = trim($attendant);
            $db->update('stays_physical_rooms', [
                'hk_assigned_to' => $attendant !== '' ? substr($attendant, 0, 155) : null,
                'updated_at'     => date('Y-m-d H:i:s'),
            ], ['id' => $physicalRoomId, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) {
            error_log('hk_assign_attendant: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('hk_assignable_rooms')) {
    /**
     * Physical rooms that can be assigned to a guest for a room type: active,
     * clean/inspected, and NOT currently assigned to an in-house (checked-in) guest.
     * Returns rows [id, room_number, floor, hk_status].
     */
    function hk_assignable_rooms($db, int $stayId, int $roomTypeId): array
    {
        try {
            $rooms = $db->select('stays_physical_rooms',
                ['id', 'room_number', 'floor', 'hk_status'],
                ['stay_id' => $stayId, 'room_id' => $roomTypeId, 'active' => 1,
                 'hk_status' => hk_assignable_statuses(), 'ORDER' => ['room_number' => 'ASC']]) ?: [];
            if (empty($rooms)) { return []; }
            // Exclude rooms already assigned to a checked-in guest.
            $taken = hk_rooms_in_use($db, $stayId);
            return array_values(array_filter($rooms, fn($r) => empty($taken[(int) $r['id']])));
        } catch (\Throwable $e) {
            error_log('hk_assignable_rooms: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('hk_rooms_in_use')) {
    /**
     * Map [physical_room_id => invoice_id] of rooms currently assigned to a
     * checked-in (not checked-out) reservation for this property. Derived from
     * bookings.booking_data (pms_physical_room_id + pms_stay_state). Bounded scan.
     */
    function hk_rooms_in_use($db, int $stayId): array
    {
        $out = [];
        try {
            // Candidate: own-inventory bookings for this property that are CHECKED IN.
            // Filter pms_stay_state in SQL (not just hotel_id), so a high-volume property's
            // currently in-house long-stay guest can't fall outside an arbitrary newest-N
            // window and be dropped from the in-use map — which used to free an occupied
            // room for reassignment (audit #11). The checked_in set is tiny (bounded by the
            // property's room count), so no LIMIT is needed; a generous safety cap remains.
            $rows = $db->select('bookings', ['invoice_id', 'booking_data'],
                ['module_type' => ['stays', 'hotels'],
                 'booking_data[~]' => ['AND' => ['%"hotel_id":' . (int) $stayId . '%', '%"pms_stay_state":"checked_in"%']], // AND (not Medoo's default OR) of two LIKEs; % wrapped ourselves (underscore/colon defeat Medoo auto-wrap)
                 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 5000]) ?: [];
            foreach ($rows as $r) {
                $bd = json_decode((string) ($r['booking_data'] ?? ''), true);
                if (!is_array($bd)) { continue; }
                if ((int) ($bd['hotel_id'] ?? 0) !== $stayId) { continue; } // verify, not just LIKE
                if (($bd['pms_stay_state'] ?? '') !== 'checked_in') { continue; } // verify, not just LIKE
                $prid = (int) ($bd['pms_physical_room_id'] ?? 0);
                if ($prid > 0) { $out[$prid] = (string) $r['invoice_id']; }
            }
        } catch (\Throwable $e) {
            error_log('hk_rooms_in_use: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('hk_rooms_blocking')) {
    /**
     * Map [physical_room_id => invoice_id] of rooms that are assigned to a reservation
     * which would CONFLICT with a stay over [$checkin,$checkout). This is the guard the
     * room-assignment path needs — unlike hk_rooms_in_use() (which only counts guests
     * already CHECKED IN), a room is blocked by ANY live reservation that has it assigned,
     * including ones still 'confirmed' (assigned pre-arrival, not yet checked in).
     *
     * A reservation blocks a room only when ALL of:
     *   - it is own-inventory for this property (hotel_id === $stayId),
     *   - it holds a pms_physical_room_id,
     *   - it is still LIVE: booking_status !== 'cancelled', not a no-show,
     *     and pms_stay_state !== 'checked_out' (a departed guest frees the room),
     *   - its date range OVERLAPS [$checkin,$checkout) — half-open, so a departure on
     *     the morning another guest arrives is NOT a conflict. When the subject booking's
     *     dates are unknown (empty), date filtering is skipped (every live assignment
     *     blocks — the safe, conservative default).
     *
     * $exceptInvoice lets a booking ignore its OWN existing assignment (re-assign/move).
     */
    function hk_rooms_blocking($db, int $stayId, string $checkin = '', string $checkout = '', string $exceptInvoice = ''): array
    {
        $out = [];
        $exceptInvoice = trim($exceptInvoice);
        $haveWindow = preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkin) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkout);
        $aStart = $haveWindow ? strtotime($checkin) : 0;
        $aEnd   = $haveWindow ? strtotime($checkout) : 0;
        try {
            $rows = $db->select('bookings', ['invoice_id', 'booking_status', 'booking_data'],
                ['module_type' => ['stays', 'hotels'],
                 'booking_data[~]' => '%"hotel_id":' . (int) $stayId . '%', // wrap % ourselves (underscore defeats Medoo auto-wrap)
                 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 2000]) ?: [];
            foreach ($rows as $r) {
                $inv = (string) $r['invoice_id'];
                if ($exceptInvoice !== '' && $inv === $exceptInvoice) { continue; } // ignore our own assignment
                $bd = json_decode((string) ($r['booking_data'] ?? ''), true);
                if (!is_array($bd)) { continue; }
                if ((int) ($bd['hotel_id'] ?? 0) !== $stayId) { continue; } // verify, not just LIKE
                $prid = (int) ($bd['pms_physical_room_id'] ?? 0);
                if ($prid <= 0) { continue; }                                             // no room assigned
                // LIVE filter: cancelled / no-show / checked-out reservations free the room.
                if (strtolower((string) ($r['booking_status'] ?? '')) === 'cancelled') { continue; }
                if (!empty($bd['supplier_no_show'])) { continue; }
                if (($bd['pms_stay_state'] ?? '') === 'checked_out') { continue; }
                // DATE OVERLAP (half-open): skip when the subject window is known and the
                // two ranges do not overlap. A booking with no dates stored is treated as
                // conflicting (conservative) when we DO have a window to compare against.
                if ($haveWindow) {
                    $bInRaw = (string) ($bd['checkin'] ?? '');
                    $bOutRaw = (string) ($bd['checkout'] ?? '');
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bInRaw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $bOutRaw)) {
                        $bStart = strtotime($bInRaw); $bEnd = strtotime($bOutRaw);
                        if (!($aStart < $bEnd && $bStart < $aEnd)) { continue; } // disjoint → no conflict
                    }
                    // else: the other booking has no/invalid dates → fall through (treat as conflict).
                }
                $out[$prid] = $inv;
            }
        } catch (\Throwable $e) {
            error_log('hk_rooms_blocking: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('hk_room_has_ooo_overlap')) {
    /**
     * True if the physical room has an ACTIVE out-of-order/out-of-service block overlapping
     * [$checkin,$checkout) (audit N10). The hk_status flag alone is insufficient: it is
     * date-insensitive and hk_room_set_status permits out_of_order -> clean WITHOUT clearing
     * an active OOO block, so a room can read 'clean' while still OOO for these dates. This
     * consults stays_ooo_blocks directly. Half-open overlap (a block ending the morning a
     * guest arrives does not conflict). Empty/invalid dates → treat any active block as a
     * conflict (conservative). Fail-closed on read error (don't assign into a possible OOO).
     */
    function hk_room_has_ooo_overlap($db, int $physicalRoomId, string $checkin, string $checkout): bool
    {
        if ($physicalRoomId <= 0) { return false; }
        try {
            $blocks = $db->select('stays_ooo_blocks', ['date_from', 'date_to'],
                ['physical_room_id' => $physicalRoomId, 'state' => 'active']) ?: [];
            if (empty($blocks)) { return false; }
            $haveWin = preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkin) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkout);
            if (!$haveWin) { return true; } // unknown stay window + an active block → conservative conflict
            $aStart = strtotime($checkin); $aEnd = strtotime($checkout);
            foreach ($blocks as $b) {
                $bStart = strtotime((string) $b['date_from']);
                // OOO blocks are inclusive date ranges [date_from, date_to]; a block on
                // date_to still makes that night unusable, so treat the block end as the
                // morning AFTER date_to for half-open comparison.
                $bEnd = strtotime((string) $b['date_to'] . ' +1 day');
                if ($aStart < $bEnd && $bStart < $aEnd) { return true; }
            }
            return false;
        } catch (\Throwable $e) {
            error_log('hk_room_has_ooo_overlap: ' . $e->getMessage());
            return true; // fail-closed: don't assign into a possible OOO window on a read error
        }
    }
}

if (!function_exists('hk_assign_room_to_booking')) {
    /**
     * Assign a physical room to a reservation (at/after check-in). Validates the room
     * belongs to $stayId, is assignable, and is not already in use by another in-house
     * guest. Stores pms_physical_room_id in booking_data. Returns bool.
     */
    function hk_assign_room_to_booking($db, string $invoiceId, int $physicalRoomId, int $stayId): bool
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '' || $physicalRoomId <= 0 || $stayId <= 0) { return false; }
        try {
            $room = $db->get('stays_physical_rooms', ['id', 'hk_status', 'active'],
                ['id' => $physicalRoomId, 'stay_id' => $stayId]);
            if (!$room || (int) $room['active'] !== 1) { return false; }
            if (!in_array((string) $room['hk_status'], hk_assignable_statuses(), true)) { return false; }

            // ATOMIC booking_data mutation with the conflict check re-run INSIDE the
            // transaction (audit #4). CONCURRENCY (audit #4 follow-up): booking_data_mutate
            // only locks the SUBJECT booking row, and hk_rooms_blocking is a non-locking read
            // of OTHER bookings — so two simultaneous assignments of the SAME physical room
            // to DIFFERENT invoices would lock different booking rows and, under REPEATABLE
            // READ, each miss the other's uncommitted assignment and both commit. To actually
            // serialize them we take a FOR UPDATE lock on the stays_physical_rooms ROW (the
            // one thing both assignments share) at the top of the mutation: the second assign
            // blocks until the first commits, then its hk_rooms_blocking read sees the first's
            // committed assignment and aborts. The guard itself: a room is taken by ANY live
            // reservation holding it over an OVERLAPPING date range (incl. not-yet-checked-in),
            // plus an active OOO block (N10); the booking's own assignment is excluded.
            if (function_exists('booking_data_mutate')) {
                $res = booking_data_mutate($db, $invoiceId, function (array &$bd, array $row, bool &$abort) use ($db, $stayId, $physicalRoomId, $invoiceId) {
                    // Serialize concurrent assigns of THIS physical room (shared lock point).
                    try { $db->query('SELECT id FROM stays_physical_rooms WHERE id = :id FOR UPDATE', [':id' => $physicalRoomId]); }
                    catch (\Throwable $e) { error_log('hk_assign lock: ' . $e->getMessage()); $abort = true; return false; }
                    $ci = (string) ($bd['checkin'] ?? ''); $co = (string) ($bd['checkout'] ?? '');
                    // Active OOO block overlapping the stay (audit N10) — the hk_status flag
                    // is bypassable, so check stays_ooo_blocks directly.
                    if (hk_room_has_ooo_overlap($db, $physicalRoomId, $ci, $co)) { $abort = true; return false; }
                    $blocking = function_exists('hk_rooms_blocking')
                        ? hk_rooms_blocking($db, $stayId, $ci, $co, $invoiceId)
                        : (function_exists('hk_rooms_in_use') ? hk_rooms_in_use($db, $stayId) : []);
                    if (isset($blocking[$physicalRoomId])) { $abort = true; return false; } // taken by an overlapping reservation
                    $bd['pms_physical_room_id'] = $physicalRoomId;
                    return true;
                });
                return !empty($res['ok']);
            }

            $b = $db->get('bookings', ['booking_data'], ['invoice_id' => $invoiceId]);
            if (!$b) { return false; }
            $bd = json_decode((string) ($b['booking_data'] ?? ''), true);
            if (!is_array($bd)) { $bd = []; }
            $ci = (string) ($bd['checkin'] ?? ''); $co = (string) ($bd['checkout'] ?? '');
            if (hk_room_has_ooo_overlap($db, $physicalRoomId, $ci, $co)) { return false; } // active OOO block (audit N10)
            $blocking = function_exists('hk_rooms_blocking')
                ? hk_rooms_blocking($db, $stayId, $ci, $co, $invoiceId)
                : hk_rooms_in_use($db, $stayId);
            if (isset($blocking[$physicalRoomId])) { return false; } // taken by an overlapping reservation
            $bd['pms_physical_room_id'] = $physicalRoomId;
            $db->update('bookings',
                ['booking_data' => json_encode($bd), 'updated_at' => date('Y-m-d H:i:s')],
                ['invoice_id' => $invoiceId]);
            return true;
        } catch (\Throwable $e) {
            error_log('hk_assign_room_to_booking: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('hk_on_checkout')) {
    /** Called from folio_checkout (inc S21): the physical room a departing guest
     *  occupied becomes 'dirty'. Non-fatal; no-op if no room was assigned. */
    function hk_on_checkout($db, string $invoiceId): void
    {
        try {
            $b = $db->get('bookings', ['booking_data'], ['invoice_id' => trim($invoiceId)]);
            if (!$b) { return; }
            $bd = json_decode((string) ($b['booking_data'] ?? ''), true);
            $prid = is_array($bd) ? (int) ($bd['pms_physical_room_id'] ?? 0) : 0;
            if ($prid <= 0) { return; }
            // Don't override an out_of_order room.
            $db->update('stays_physical_rooms',
                ['hk_status' => 'dirty', 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $prid, 'hk_status[!]' => 'out_of_order']);
        } catch (\Throwable $e) {
            error_log('hk_on_checkout: ' . $e->getMessage());
        }
    }
}

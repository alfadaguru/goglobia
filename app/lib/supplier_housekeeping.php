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
            // Candidate: own-inventory bookings for this property that are checked_in.
            $rows = $db->select('bookings', ['invoice_id', 'booking_data'],
                ['module_type' => ['stays', 'hotels'],
                 'booking_data[~]' => '"hotel_id":' . (int) $stayId,
                 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 500]) ?: [];
            foreach ($rows as $r) {
                $bd = json_decode((string) ($r['booking_data'] ?? ''), true);
                if (!is_array($bd)) { continue; }
                if ((int) ($bd['hotel_id'] ?? 0) !== $stayId) { continue; } // verify, not just LIKE
                if (($bd['pms_stay_state'] ?? '') !== 'checked_in') { continue; }
                $prid = (int) ($bd['pms_physical_room_id'] ?? 0);
                if ($prid > 0) { $out[$prid] = (string) $r['invoice_id']; }
            }
        } catch (\Throwable $e) {
            error_log('hk_rooms_in_use: ' . $e->getMessage());
        }
        return $out;
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

            $inUse = hk_rooms_in_use($db, $stayId);
            if (isset($inUse[$physicalRoomId]) && $inUse[$physicalRoomId] !== $invoiceId) { return false; } // taken

            $b = $db->get('bookings', ['booking_data'], ['invoice_id' => $invoiceId]);
            if (!$b) { return false; }
            $bd = json_decode((string) ($b['booking_data'] ?? ''), true);
            if (!is_array($bd)) { $bd = []; }
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

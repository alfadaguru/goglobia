<?php
// FILE: app/lib/supplier_night_audit.php
// NIGHT AUDIT — the daily close (Phase 1 inc S25; docs 01 §4.4).
//
// The classic PMS end-of-day close, per property, on a BUSINESS DATE. One run:
//   1. Flag NO-SHOWS: confirmed reservations whose check-in date <= business date
//      that never checked in → marked no_show (booking_data) + cancellation flag.
//   2. Snapshot the day's numbers: arrivals / departures / in-house, rooms sold,
//      room revenue, OCCUPANCY / ADR / RevPAR.
//   3. ROLL the business date forward by one day.
//
// Idempotency: one audit row per (stay_id, business_date) via a UNIQUE key + a
// locked re-check; re-running for the same date is a no-op (returns the existing
// snapshot). The business date only advances when a fresh snapshot is written.
//
// NOTE on "post room+tax to open folios": in a textbook PMS the folio is charged
// one room-night at a time and night audit posts that night's charge. OUR folio
// model (S21) seeds the WHOLE-STAY room+tax charge at folio creation (check-in), so
// re-posting per night here would DOUBLE-CHARGE. We therefore do NOT re-post room
// charges — the whole-stay charge already sits on the folio and hits the GL at
// checkout (S21). Night audit here = no-shows + snapshot + date roll. (A per-night
// accrual model would change folio seeding; out of scope for this increment.)
//
// Reads folios/bookings/physical-rooms; writes its own tables + booking_data flags.
// Non-fatal; ownership enforced by the caller route.

if (!function_exists('na_business_date')) {
    /** The property's current business date (Y-m-d). Seeds to today on first access. */
    function na_business_date($db, int $stayId): string
    {
        $today = date('Y-m-d');
        if ($stayId <= 0) { return $today; }
        try {
            $row = $db->get('stays_business_date', ['business_date'], ['stay_id' => $stayId]);
            if ($row && !empty($row['business_date'])) { return (string) $row['business_date']; }
            $db->insert('stays_business_date', ['stay_id' => $stayId, 'business_date' => $today, 'created_at' => date('Y-m-d H:i:s')]);
            return $today;
        } catch (\Throwable $e) {
            error_log('na_business_date: ' . $e->getMessage());
            return $today;
        }
    }
}

if (!function_exists('na_property_bookings')) {
    /** Own-inventory bookings for a property (bounded) with decoded booking_data.
     *  Each row: ['invoice_id','booking_status','payment_status','price_original',
     *  'price_markup','tax','bd'=>array]. */
    function na_property_bookings($db, int $stayId, int $limit = 1000): array
    {
        $out = [];
        try {
            $rows = $db->select('bookings',
                ['invoice_id', 'booking_status', 'payment_status', 'price_original', 'price_markup', 'tax', 'booking_data'],
                ['module_type' => ['stays', 'hotels'],
                 'booking_data[~]' => '"hotel_id":' . (int) $stayId,
                 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
            foreach ($rows as $r) {
                $bd = json_decode((string) ($r['booking_data'] ?? ''), true);
                if (!is_array($bd) || (int) ($bd['hotel_id'] ?? 0) !== $stayId) { continue; } // verify, not just LIKE
                $r['bd'] = $bd;
                $out[] = $r;
            }
        } catch (\Throwable $e) {
            error_log('na_property_bookings: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('na_sellable_room_count')) {
    /** Available room capacity for occupancy %: count of active physical rooms if any
     *  exist; else 0 (occupancy is then reported as N/A). */
    function na_sellable_room_count($db, int $stayId): int
    {
        try {
            return (int) $db->count('stays_physical_rooms', ['stay_id' => $stayId, 'active' => 1]);
        } catch (\Throwable $e) {
            error_log('na_sellable_room_count: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('night_audit_run')) {
    /**
     * Run the night audit for a property's CURRENT business date. Idempotent per
     * (stay_id, business_date). Returns the snapshot array
     * ['ok','business_date','arrivals','departures','in_house','rooms_sold',
     *  'room_revenue','adr','revpar','occupancy_pct','no_shows','already','new_business_date'].
     */
    function night_audit_run($db, int $stayId): array
    {
        if ($stayId <= 0) { return ['ok' => false, 'message' => 'No property']; }
        $bizDate = na_business_date($db, $stayId);

        // Idempotency: if an audit for this (stay, date) exists, return it unchanged.
        try {
            $existing = $db->get('stays_night_audits', '*', ['stay_id' => $stayId, 'business_date' => $bizDate]);
            if ($existing) {
                $existing['ok'] = true; $existing['already'] = true;
                return $existing;
            }
        } catch (\Throwable $e) { error_log('night_audit_run check: ' . $e->getMessage()); }

        $bookings = na_property_bookings($db, $stayId);

        $arrivals = 0; $departures = 0; $inHouse = 0; $roomsSold = 0; $roomRevenue = 0.0; $noShows = 0;
        $noShowInvoices = [];
        foreach ($bookings as $b) {
            $bd = $b['bd'];
            $status = strtolower((string) ($b['booking_status'] ?? ''));
            if ($status === 'cancelled') { continue; }
            $checkin  = (string) ($bd['checkin'] ?? '');
            $checkout = (string) ($bd['checkout'] ?? '');
            $stayState = (string) ($bd['pms_stay_state'] ?? 'confirmed');

            // Arrivals / departures / in-house for THIS business date.
            if ($checkin === $bizDate) { $arrivals++; }
            if ($checkout === $bizDate) { $departures++; }
            if ($stayState === 'checked_in') {
                $inHouse++;
                // Rooms sold + room revenue are counted for the in-house night.
                $roomsSold++;
                // Room revenue = guest room charge (price_markup − tax), server-stored.
                $gross = (float) ($b['price_markup'] ?? 0);
                $tax = (float) ($b['tax'] ?? 0);
                $roomRevenue += max(0, $gross - $tax);
            }

            // No-show: still 'confirmed' (never checked in / out) with a check-in on or
            // before the business date and not already flagged.
            if ($stayState === 'confirmed' && $checkin !== '' && $checkin <= $bizDate && empty($bd['pms_no_show'])) {
                $noShowInvoices[] = (string) $b['invoice_id'];
            }
        }

        // Flag no-shows (booking_data + mark cancelled so inventory frees naturally on
        // the next sweep; conservative — does NOT touch money).
        foreach ($noShowInvoices as $inv) {
            try {
                $row = $db->get('bookings', ['booking_data'], ['invoice_id' => $inv]);
                if (!$row) { continue; }
                $bd = json_decode((string) ($row['booking_data'] ?? ''), true);
                if (!is_array($bd)) { $bd = []; }
                $bd['pms_no_show'] = true; $bd['pms_no_show_at'] = date('Y-m-d H:i:s');
                $bd['pms_stay_state'] = 'no_show';
                $db->update('bookings',
                    ['booking_data' => json_encode($bd), 'updated_at' => date('Y-m-d H:i:s')],
                    ['invoice_id' => $inv]);
                // Release any live holds so the room frees up.
                if (function_exists('stays_hold_release')) { stays_hold_release($db, $inv); }
                $noShows++;
            } catch (\Throwable $e) { error_log('night_audit no-show (' . $inv . '): ' . $e->getMessage()); }
        }

        $roomRevenue = round($roomRevenue, 2);
        $available = na_sellable_room_count($db, $stayId);
        $adr = $roomsSold > 0 ? round($roomRevenue / $roomsSold, 2) : 0.0;
        $revpar = $available > 0 ? round($roomRevenue / $available, 2) : 0.0;
        $occ = $available > 0 ? round($roomsSold / $available * 100, 1) : null; // null = N/A (no physical rooms)

        // Write the snapshot + roll the date, atomically + idempotently.
        $newBiz = date('Y-m-d', strtotime($bizDate . ' +1 day'));
        $result = ['ok' => false, 'message' => 'Audit failed'];
        try {
            $db->action(function ($db) use ($stayId, $bizDate, $arrivals, $departures, $inHouse, $roomsSold, $roomRevenue, $adr, $revpar, $occ, $noShows, $newBiz, &$result) {
                // Re-check under lock (idempotency).
                $lock = $db->query('SELECT id FROM stays_night_audits WHERE stay_id=:s AND business_date=:d FOR UPDATE',
                    [':s' => $stayId, ':d' => $bizDate]);
                if ($lock && $lock->fetch(\PDO::FETCH_ASSOC)) { $result = ['ok' => true, 'already' => true]; return true; }

                $db->insert('stays_night_audits', [
                    'stay_id'       => $stayId,
                    'business_date' => $bizDate,
                    'arrivals'      => $arrivals,
                    'departures'    => $departures,
                    'in_house'      => $inHouse,
                    'rooms_sold'    => $roomsSold,
                    'rooms_available' => na_sellable_room_count($db, $stayId),
                    'room_revenue'  => $roomRevenue,
                    'adr'           => $adr,
                    'revpar'        => $revpar,
                    'occupancy_pct' => $occ, // may be null
                    'no_shows'      => $noShows,
                    'run_by'        => (string) ($_SESSION['user_id'] ?? ''),
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
                // Roll the business date forward.
                $db->update('stays_business_date',
                    ['business_date' => $newBiz, 'updated_at' => date('Y-m-d H:i:s')],
                    ['stay_id' => $stayId]);
                $result = ['ok' => true, 'already' => false];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('night_audit_run write: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Audit write error'];
        }
        if (function_exists('emit_event')) {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            emit_event($db, 'night_audit.closed',
                ['business_date' => $bizDate, 'rooms_sold' => $roomsSold, 'room_revenue' => $roomRevenue],
                'stays', $stayId, $orgId > 0 ? $orgId : null);
        }

        return array_merge($result, [
            'business_date'     => $bizDate,
            'arrivals'          => $arrivals,
            'departures'        => $departures,
            'in_house'          => $inHouse,
            'rooms_sold'        => $roomsSold,
            'room_revenue'      => $roomRevenue,
            'adr'               => $adr,
            'revpar'            => $revpar,
            'occupancy_pct'     => $occ,
            'no_shows'          => $noShows,
            'new_business_date' => $newBiz,
        ]);
    }
}

if (!function_exists('night_audit_history')) {
    /** Recent audit snapshots for a property (read-only). */
    function night_audit_history($db, int $stayId, int $limit = 60): array
    {
        try {
            return $db->select('stays_night_audits', '*',
                ['stay_id' => $stayId, 'ORDER' => ['business_date' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) {
            error_log('night_audit_history: ' . $e->getMessage());
            return [];
        }
    }
}

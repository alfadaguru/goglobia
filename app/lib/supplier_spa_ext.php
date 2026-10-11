<?php
// FILE: app/lib/supplier_spa_ext.php
// Spa, wellness & recreation — DEEPENING (#32). Builds the genuine spec gaps on top of
// the base service_* catalogue/booking engine in app/lib/supplier_ancillary.php:
//   therapist/resource roster · appointment scheduling WITH double-book conflict
//   detection (a resource cannot overlap itself) · memberships (session-pass balances
//   with decrement on use) · charge-to-room (post an appointment's price to the guest's
//   OPEN folio via the shared folio engine — never a separate money path).
// New parallel tables only — the base stays_services / stays_service_bookings rows are
// never altered here. Owner/staff authorizes the stay via supplier_can in the route
// layer. Defensive/non-fatal. NOT runtime-verified (no MySQL) — logic-reasoned + php -l
// + a pure-math harness for the overlap predicate.

// ---------------------------------------------------------------------------
// RESOURCES / THERAPISTS (the calendar's bookable lanes)
// ---------------------------------------------------------------------------
if (!function_exists('spa_resource_kinds')) {
    /** A calendar lane is either a person (therapist) or a physical asset (room/equipment). */
    function spa_resource_kinds(): array { return ['therapist' => 'Therapist', 'room' => 'Treatment room', 'equipment' => 'Equipment']; }
}
if (!function_exists('spa_resource_add')) {
    /** Create a bookable resource for a property. Returns new id or 0. */
    function spa_resource_add($db, int $stayId, array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($stayId <= 0 || $name === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $kind = isset(spa_resource_kinds()[$d['kind'] ?? '']) ? $d['kind'] : 'therapist';
            $db->insert('stays_spa_resources', [
                'org_id'     => $orgId > 0 ? $orgId : null,
                'stay_id'    => $stayId,
                'kind'       => $kind,
                'name'       => substr($name, 0, 150),
                'capacity'   => max(1, (int) ($d['capacity'] ?? 1)),
                'active'     => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('spa_resource_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('spa_resource_list')) {
    function spa_resource_list($db, array $stayIds, bool $activeOnly = false, int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        $where = ['stay_id' => $stayIds, 'ORDER' => ['name' => 'ASC'], 'LIMIT' => max(1, $limit)];
        if ($activeOnly) { $where['active'] = 1; }
        try { return $db->select('stays_spa_resources', '*', $where) ?: []; }
        catch (\Throwable $e) { error_log('spa_resource_list: ' . $e->getMessage()); return []; }
    }
}
if (!function_exists('spa_resource_set_active')) {
    function spa_resource_set_active($db, int $id, int $stayId, bool $active): bool
    {
        if ($id <= 0 || $stayId <= 0) { return false; }
        try {
            if (!$db->has('stays_spa_resources', ['id' => $id, 'stay_id' => $stayId])) { return false; }
            $db->update('stays_spa_resources', ['active' => $active ? 1 : 0], ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('spa_resource_set_active: ' . $e->getMessage()); return false; }
    }
}

// ---------------------------------------------------------------------------
// SCHEDULING MATH (pure, harnessed)
// ---------------------------------------------------------------------------
if (!function_exists('spa_slot_end')) {
    /** Given a start 'Y-m-d H:i:s' + a duration in minutes, return the end timestamp (or null). */
    function spa_slot_end(string $startAt, int $durationMin): ?string
    {
        $ts = strtotime($startAt);
        if ($ts === false || $durationMin <= 0) { return null; }
        return date('Y-m-d H:i:s', $ts + ($durationMin * 60));
    }
}
if (!function_exists('spa_intervals_overlap')) {
    /**
     * Half-open overlap predicate: [aStart,aEnd) intersects [bStart,bEnd)?
     * Adjacent slots that merely touch (aEnd === bStart) do NOT overlap — so a
     * resource can be booked back-to-back. Pure; unit-harnessable.
     */
    function spa_intervals_overlap(string $aStart, string $aEnd, string $bStart, string $bEnd): bool
    {
        $as = strtotime($aStart); $ae = strtotime($aEnd);
        $bs = strtotime($bStart); $be = strtotime($bEnd);
        if ($as === false || $ae === false || $bs === false || $be === false) { return false; }
        return ($as < $be) && ($bs < $ae);
    }
}
if (!function_exists('spa_resource_is_free')) {
    /**
     * Is $resourceId free for [$startAt, +$durationMin) on its property? Checks every
     * non-cancelled appointment already holding that resource for an overlap. Capacity>1
     * resources (e.g. a group room) allow up to `capacity` concurrent appointments.
     * $excludeId lets an edit ignore its own row. Returns bool (true = free). Fail-closed
     * on error (treats as NOT free) so we never silently double-book.
     */
    function spa_resource_is_free($db, int $stayId, int $resourceId, string $startAt, int $durationMin, int $excludeId = 0): bool
    {
        if ($stayId <= 0 || $resourceId <= 0) { return false; }
        $end = spa_slot_end($startAt, $durationMin);
        if ($end === null) { return false; }
        try {
            $res = $db->get('stays_spa_resources', ['capacity', 'active'], ['id' => $resourceId, 'stay_id' => $stayId]);
            if (!$res || (int) $res['active'] !== 1) { return false; }
            $cap = max(1, (int) $res['capacity']);
            $where = [
                'stay_id'     => $stayId,
                'resource_id' => $resourceId,
                'status[!]'   => 'cancelled',
            ];
            if ($excludeId > 0) { $where['id[!]'] = $excludeId; }
            $rows = $db->select('stays_spa_appointments', ['start_at', 'end_at'], $where) ?: [];
            $concurrent = 0;
            foreach ($rows as $r) {
                $rs = (string) ($r['start_at'] ?? '');
                $re = (string) ($r['end_at'] ?? '');
                if ($rs === '' || $re === '') { continue; }
                if (spa_intervals_overlap($startAt, $end, $rs, $re)) { $concurrent++; }
            }
            return $concurrent < $cap;
        } catch (\Throwable $e) { error_log('spa_resource_is_free: ' . $e->getMessage()); return false; }
    }
}

// ---------------------------------------------------------------------------
// APPOINTMENTS (the calendar). Price is ALWAYS snapshotted server-side from the
// service row — the client never supplies a price.
// ---------------------------------------------------------------------------
if (!function_exists('spa_appointment_book')) {
    /**
     * Book an appointment for a service against a resource at a start time.
     * - duration: the service's own duration_min (fallback 30) — not client-trusted.
     * - price:    snapshotted from the service row — not client-trusted.
     * - conflict: refuses if the resource is not free (double-book guard).
     * Returns ['ok'=>bool,'id'=>int,'error'=>string].
     */
    function spa_appointment_book($db, int $stayId, int $serviceId, int $resourceId, array $d): array
    {
        $out = ['ok' => false, 'id' => 0, 'error' => ''];
        if ($stayId <= 0 || $serviceId <= 0 || $resourceId <= 0) { $out['error'] = 'Missing service/resource.'; return $out; }
        $startAt = trim((string) ($d['start_at'] ?? ''));
        if ($startAt === '' || strtotime($startAt) === false) { $out['error'] = 'Invalid start time.'; return $out; }
        try {
            $svc = $db->get('stays_services', ['id', 'price', 'org_id', 'duration_min', 'name'], ['id' => $serviceId, 'stay_id' => $stayId]);
            if (!$svc) { $out['error'] = 'Service not found for this property.'; return $out; }
            $res = $db->get('stays_spa_resources', ['id', 'org_id'], ['id' => $resourceId, 'stay_id' => $stayId, 'active' => 1]);
            if (!$res) { $out['error'] = 'Resource not available.'; return $out; }

            $dur = ($svc['duration_min'] !== null && (int) $svc['duration_min'] > 0) ? (int) $svc['duration_min'] : 30;
            $end = spa_slot_end($startAt, $dur);
            if ($end === null) { $out['error'] = 'Could not compute end time.'; return $out; }

            if (!spa_resource_is_free($db, $stayId, $resourceId, $startAt, $dur)) {
                $out['error'] = 'That resource is already booked for an overlapping slot.';
                return $out;
            }

            $db->insert('stays_spa_appointments', [
                'org_id'       => ($svc['org_id'] !== null) ? (int) $svc['org_id'] : null,
                'stay_id'      => $stayId,
                'service_id'   => $serviceId,
                'resource_id'  => $resourceId,
                'membership_id'=> null,
                'guest_name'   => substr(trim((string) ($d['guest_name'] ?? '')), 0, 120) ?: null,
                'invoice_id'   => trim((string) ($d['invoice_id'] ?? '')) ?: null,
                'start_at'     => date('Y-m-d H:i:s', strtotime($startAt)),
                'end_at'       => $end,
                'duration_min' => $dur,
                'price'        => round((float) $svc['price'], 2),
                'status'       => 'booked',
                'charged'      => 0,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            $out['ok'] = true; $out['id'] = (int) $db->id();
            return $out;
        } catch (\Throwable $e) { error_log('spa_appointment_book: ' . $e->getMessage()); $out['error'] = 'Could not book.'; return $out; }
    }
}
if (!function_exists('spa_appointment_list')) {
    /** Appointments for the owned properties, optionally in a [from,to] window (dates). */
    function spa_appointment_list($db, array $stayIds, string $from = '', string $to = '', int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        $where = ['stay_id' => $stayIds];
        if ($from !== '' && strtotime($from) !== false) { $where['start_at[>=]'] = date('Y-m-d 00:00:00', strtotime($from)); }
        if ($to !== '' && strtotime($to) !== false)     { $where['start_at[<=]'] = date('Y-m-d 23:59:59', strtotime($to)); }
        $where['ORDER'] = ['start_at' => 'ASC'];
        $where['LIMIT'] = max(1, $limit);
        try { return $db->select('stays_spa_appointments', '*', $where) ?: []; }
        catch (\Throwable $e) { error_log('spa_appointment_list: ' . $e->getMessage()); return []; }
    }
}
if (!function_exists('spa_appointment_set_status')) {
    function spa_appointment_set_status($db, int $id, int $stayId, string $status): bool
    {
        if (!in_array($status, ['booked', 'completed', 'no_show', 'cancelled'], true)) { return false; }
        try {
            if (!$db->has('stays_spa_appointments', ['id' => $id, 'stay_id' => $stayId])) { return false; }
            $db->update('stays_spa_appointments', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('spa_appointment_set_status: ' . $e->getMessage()); return false; }
    }
}

// ---------------------------------------------------------------------------
// CHARGE-TO-ROOM (reuse the shared folio engine — NO separate money path)
// ---------------------------------------------------------------------------
if (!function_exists('spa_appointment_charge_to_room')) {
    /**
     * Post an appointment's snapshotted price to the guest's OPEN folio as an 'extra'
     * line, via the shared folio_add_item() engine. The folio is resolved from the
     * appointment's invoice_id (own-inventory stays only; a closed/missing folio is
     * refused by the engine). Idempotent: a row already 'charged' is refused. If a
     * membership pass covers the session the price is 0 and no folio line is posted.
     * Returns ['ok'=>bool,'error'=>string].
     *
     * DB-GATED: relies on app/lib/supplier_folio.php (folio_get_or_create/folio_add_item)
     * already being loaded by config.php. No external gateway — this is an internal
     * guest-folio posting, settled at checkout by the existing cashier path.
     */
    function spa_appointment_charge_to_room($db, int $id, int $stayId, string $actor = 'spa'): array
    {
        $out = ['ok' => false, 'error' => ''];
        if ($id <= 0 || $stayId <= 0) { $out['error'] = 'Bad appointment.'; return $out; }
        if (!function_exists('folio_get_or_create') || !function_exists('folio_add_item')) {
            $out['error'] = 'Folio engine unavailable.'; return $out;
        }
        try {
            $ap = $db->get('stays_spa_appointments',
                ['id', 'stay_id', 'invoice_id', 'price', 'charged', 'status', 'service_id', 'guest_name'],
                ['id' => $id, 'stay_id' => $stayId]);
            if (!$ap) { $out['error'] = 'Appointment not found.'; return $out; }
            if ((int) $ap['charged'] === 1) { $out['error'] = 'Already charged.'; return $out; }
            if ((string) $ap['status'] === 'cancelled') { $out['error'] = 'Appointment is cancelled.'; return $out; }

            $price = round((float) $ap['price'], 2);
            if ($price <= 0) {
                // Covered by a membership pass (or zero-priced) — nothing to post, just mark.
                $db->update('stays_spa_appointments', ['charged' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id, 'stay_id' => $stayId]);
                $out['ok'] = true; return $out;
            }

            $invoiceId = trim((string) ($ap['invoice_id'] ?? ''));
            if ($invoiceId === '') { $out['error'] = 'No reservation/invoice on this appointment.'; return $out; }
            $folioId = folio_get_or_create($db, $invoiceId);
            if ($folioId <= 0) { $out['error'] = 'No open folio for that reservation.'; return $out; }

            $svcName = '';
            try { $s = $db->get('stays_services', ['name'], ['id' => (int) $ap['service_id']]); if ($s) { $svcName = (string) $s['name']; } } catch (\Throwable $e) {}
            $desc = 'Spa: ' . ($svcName !== '' ? $svcName : 'service') . ($ap['guest_name'] ? ' — ' . $ap['guest_name'] : '');

            $posted = folio_add_item($db, $folioId, 'extra', $price, $desc, $actor !== '' ? $actor : 'spa', 1);
            if (!$posted) { $out['error'] = 'Folio is closed or the line was refused.'; return $out; }

            $db->update('stays_spa_appointments', ['charged' => 1, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id, 'stay_id' => $stayId]);
            $out['ok'] = true; return $out;
        } catch (\Throwable $e) { error_log('spa_appointment_charge_to_room: ' . $e->getMessage()); $out['error'] = 'Charge failed.'; return $out; }
    }
}

// ---------------------------------------------------------------------------
// MEMBERSHIPS / SESSION PASSES
// ---------------------------------------------------------------------------
if (!function_exists('spa_membership_add')) {
    /**
     * Sell a membership pass to a guest: N sessions, a flat price, optional expiry date.
     * Price/sessions are the product definition (owner-set), not client-trusted search.
     * Returns new id or 0.
     */
    function spa_membership_add($db, int $stayId, array $d): int
    {
        $holder = trim((string) ($d['holder_name'] ?? ''));
        if ($stayId <= 0 || $holder === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $sessions = max(1, (int) ($d['sessions_total'] ?? 1));
            $expiry = trim((string) ($d['expires_on'] ?? ''));
            $db->insert('stays_spa_memberships', [
                'org_id'         => $orgId > 0 ? $orgId : null,
                'stay_id'        => $stayId,
                'holder_name'    => substr($holder, 0, 120),
                'holder_email'   => substr(trim((string) ($d['holder_email'] ?? '')), 0, 150) ?: null,
                'plan_name'      => substr(trim((string) ($d['plan_name'] ?? 'Membership')), 0, 120) ?: 'Membership',
                'sessions_total' => $sessions,
                'sessions_used'  => 0,
                'price'          => max(0, round((float) ($d['price'] ?? 0), 2)),
                'expires_on'     => ($expiry !== '' && strtotime($expiry) !== false) ? date('Y-m-d', strtotime($expiry)) : null,
                'status'         => 'active',
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('spa_membership_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('spa_membership_list')) {
    function spa_membership_list($db, array $stayIds, int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        try { return $db->select('stays_spa_memberships', '*', ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('spa_membership_list: ' . $e->getMessage()); return []; }
    }
}
if (!function_exists('spa_membership_remaining')) {
    /** Sessions left on a membership (never negative). Pure over a row array. */
    function spa_membership_remaining(array $m): int
    {
        $total = (int) ($m['sessions_total'] ?? 0);
        $used  = (int) ($m['sessions_used'] ?? 0);
        return max(0, $total - $used);
    }
}
if (!function_exists('spa_membership_redeem')) {
    /**
     * Redeem ONE session against a membership for an appointment: decrements the balance
     * atomically (guarded on sessions_used < sessions_total so it never over-redeems),
     * links the appointment to the membership, and ZEROES the appointment price so
     * charge-to-room posts nothing (the pass already paid). Also refuses if expired or
     * exhausted. Returns ['ok'=>bool,'remaining'=>int,'error'=>string].
     */
    function spa_membership_redeem($db, int $membershipId, int $appointmentId, int $stayId): array
    {
        $out = ['ok' => false, 'remaining' => 0, 'error' => ''];
        if ($membershipId <= 0 || $appointmentId <= 0 || $stayId <= 0) { $out['error'] = 'Bad input.'; return $out; }
        try {
            $m = $db->get('stays_spa_memberships', '*', ['id' => $membershipId, 'stay_id' => $stayId]);
            if (!$m) { $out['error'] = 'Membership not found.'; return $out; }
            if ((string) $m['status'] !== 'active') { $out['error'] = 'Membership is not active.'; return $out; }
            if (!empty($m['expires_on']) && strtotime((string) $m['expires_on']) !== false
                && strtotime((string) $m['expires_on']) < strtotime(date('Y-m-d'))) {
                $out['error'] = 'Membership has expired.'; return $out;
            }
            if (spa_membership_remaining($m) <= 0) { $out['error'] = 'No sessions remaining.'; return $out; }

            $ap = $db->get('stays_spa_appointments', ['id', 'membership_id', 'charged', 'status'], ['id' => $appointmentId, 'stay_id' => $stayId]);
            if (!$ap) { $out['error'] = 'Appointment not found.'; return $out; }
            if ((int) $ap['membership_id'] > 0) { $out['error'] = 'Appointment already linked to a membership.'; return $out; }
            if ((int) $ap['charged'] === 1) { $out['error'] = 'Appointment already charged — cannot apply a pass.'; return $out; }

            // Guarded decrement: only succeeds while a session is still available.
            // The guard compares against the snapshot total read above; Medoo's update()
            // returns a PDOStatement whose rowCount() tells us whether the row moved.
            $upd = $db->update('stays_spa_memberships',
                ['sessions_used[+]' => 1, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $membershipId, 'stay_id' => $stayId, 'sessions_used[<]' => (int) $m['sessions_total']]);
            if (!$upd || $upd->rowCount() < 1) { $out['error'] = 'Could not redeem (no sessions left).'; return $out; }

            $db->update('stays_spa_appointments',
                ['membership_id' => $membershipId, 'price' => 0, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $appointmentId, 'stay_id' => $stayId]);

            // Auto-exhaust the membership when the last session is spent.
            $remaining = spa_membership_remaining($m) - 1;
            if ($remaining <= 0) {
                $db->update('stays_spa_memberships', ['status' => 'exhausted'], ['id' => $membershipId, 'stay_id' => $stayId]);
            }
            $out['ok'] = true; $out['remaining'] = max(0, $remaining);
            return $out;
        } catch (\Throwable $e) { error_log('spa_membership_redeem: ' . $e->getMessage()); $out['error'] = 'Redeem failed.'; return $out; }
    }
}

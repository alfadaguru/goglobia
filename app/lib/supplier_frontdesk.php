<?php
// FILE: app/lib/supplier_frontdesk.php
// MODULE 07 (front desk operations → 360). Ops on top of the existing check-in/out
// (folio_checkin/folio_checkout), traces, VIP, reg-card, and the #04 front-desk
// dashboard + tape chart: early/late flags, EXPRESS check-in/out, wake-up calls, shift
// handover log, and arrival/departure manifests. Caller authorizes the property/booking.
// NOT runtime-verified.

if (!function_exists('fd_set_early_late')) {
    /**
     * Record an early check-in or late checkout flag + optional fee note on a booking
     * (booking_data.fd_early_checkin / fd_late_checkout). Operational record — any fee is
     * posted to the folio separately via the normal folio_add charge. Returns bool.
     */
    function fd_set_early_late($db, string $invoiceId, string $which, bool $on, string $time = ''): bool
    {
        $which = $which === 'late_checkout' ? 'late_checkout' : 'early_checkin';
        try {
            $b = $db->get('bookings', ['booking_data'], ['invoice_id' => $invoiceId]);
            if (!$b) { return false; }
            $bd = json_decode((string) ($b['booking_data'] ?? ''), true);
            if (!is_array($bd)) { $bd = []; }
            $key = 'fd_' . $which;
            if ($on) { $bd[$key] = ['on' => true, 'time' => substr(trim($time), 0, 10), 'at' => date('Y-m-d H:i:s')]; }
            else { unset($bd[$key]); }
            $db->update('bookings', ['booking_data' => json_encode($bd), 'updated_at' => date('Y-m-d H:i:s')], ['invoice_id' => $invoiceId]);
            return true;
        } catch (\Throwable $e) { error_log('fd_set_early_late: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('fd_express_checkin')) {
    /**
     * EXPRESS check-in: one call → mark checked-in (folio_checkin) + ensure folio +
     * optionally assign a physical room (canonical hk_assign_room_to_booking). Returns
     * ['ok','message']. Caller authorized the booking's property ($stayId).
     */
    function fd_express_checkin($db, string $invoiceId, int $stayId, int $physicalRoomId = 0): array
    {
        try {
            if (function_exists('folio_checkin')) { folio_checkin($db, $invoiceId); }
            if (function_exists('folio_get_or_create')) { folio_get_or_create($db, $invoiceId); }
            $assignedMsg = '';
            if ($physicalRoomId > 0 && function_exists('hk_assign_room_to_booking')) {
                $ok = hk_assign_room_to_booking($db, $invoiceId, $physicalRoomId, $stayId);
                $assignedMsg = $ok ? ' Room assigned.' : ' (Room could not be assigned.)';
            }
            return ['ok' => true, 'message' => 'Express check-in complete.' . $assignedMsg];
        } catch (\Throwable $e) { error_log('fd_express_checkin: ' . $e->getMessage()); return ['ok' => false, 'message' => 'Express check-in failed.']; }
    }
}

if (!function_exists('fd_express_checkout')) {
    /** EXPRESS checkout: delegates to folio_checkout (finalizes folio → GL → earning). */
    function fd_express_checkout($db, string $invoiceId): array
    {
        if (!function_exists('folio_checkout')) { return ['ok' => false, 'message' => 'Folio unavailable.']; }
        return folio_checkout($db, $invoiceId);
    }
}

// ---------------------------------------------------------------------------
// WAKE-UP CALLS
// ---------------------------------------------------------------------------
if (!function_exists('wakeup_add')) {
    function wakeup_add($db, int $orgId, int $stayId, string $wakeAt, string $invoiceId = '', string $note = ''): int
    {
        $wakeAt = trim($wakeAt);
        if ($stayId <= 0 || $wakeAt === '') { return 0; }
        // Accept 'Y-m-d H:i' or 'Y-m-d\TH:i' (datetime-local) → normalise.
        $ts = strtotime(str_replace('T', ' ', $wakeAt));
        if ($ts === false) { return 0; }
        try {
            $db->insert('stays_wakeup_calls', [
                'org_id' => $orgId ?: null, 'stay_id' => $stayId,
                'invoice_id' => trim($invoiceId) ?: null,
                'wake_at' => date('Y-m-d H:i:s', $ts),
                'note' => substr(trim($note), 0, 255) ?: null,
                'status' => 'scheduled', 'created_by' => (string) ($_SESSION['user_id'] ?? ''),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('wakeup_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('wakeup_set_status')) {
    function wakeup_set_status($db, int $stayId, int $id, string $status): bool
    {
        if (!in_array($status, ['scheduled', 'done', 'cancelled'], true)) { return false; }
        try {
            if (!$db->has('stays_wakeup_calls', ['id' => $id, 'stay_id' => $stayId])) { return false; }
            $db->update('stays_wakeup_calls', ['status' => $status], ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('wakeup_set_status: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('wakeup_list')) {
    function wakeup_list($db, int $stayId, int $limit = 100): array
    {
        if ($stayId <= 0) { return []; }
        try { return $db->select('stays_wakeup_calls', '*', ['stay_id' => $stayId, 'ORDER' => ['wake_at' => 'ASC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('wakeup_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// SHIFT HANDOVER
// ---------------------------------------------------------------------------
if (!function_exists('handover_add')) {
    function handover_add($db, int $orgId, int $stayId, array $in): int
    {
        if ($stayId <= 0) { return 0; }
        $shift = in_array(($in['shift'] ?? ''), ['morning', 'evening', 'night'], true) ? $in['shift'] : 'morning';
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['handover_date'] ?? '')) ? $in['handover_date'] : date('Y-m-d');
        try {
            $db->insert('stays_shift_handover', [
                'org_id' => $orgId ?: null, 'stay_id' => $stayId, 'shift' => $shift,
                'handover_date' => $date, 'notes' => substr(trim((string) ($in['notes'] ?? '')), 0, 4000) ?: null,
                'cash_balance' => $in['cash_balance'] !== '' && $in['cash_balance'] !== null ? round((float) $in['cash_balance'], 2) : null,
                'created_by' => (string) ($_SESSION['user_id'] ?? ''), 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('handover_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('handover_list')) {
    function handover_list($db, int $stayId, int $limit = 50): array
    {
        if ($stayId <= 0) { return []; }
        try { return $db->select('stays_shift_handover', '*', ['stay_id' => $stayId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('handover_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// ARRIVAL / DEPARTURE MANIFESTS (built from the #04 dashboard buckets)
// ---------------------------------------------------------------------------
if (!function_exists('fd_manifest')) {
    /**
     * Arrival or departure manifest for a business date, as printable rows. Reuses
     * resv_dashboard_buckets over the already-ownership-filtered reservations.
     * $which = 'arrivals' | 'departures'. Returns the matching row list.
     */
    function fd_manifest(array $reservations, string $date, string $which): array
    {
        if (!function_exists('resv_dashboard_buckets')) { return []; }
        $b = resv_dashboard_buckets($reservations, $date);
        $which = $which === 'departures' ? 'departures' : 'arrivals';
        return $b[$which] ?? [];
    }
}

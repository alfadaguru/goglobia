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
        $key = 'fd_' . $which;
        // ATOMIC booking_data mutation (audit fix) — row-locked so a concurrent desk
        // write can't clobber this flag. Falls back only if the helper isn't loaded.
        if (function_exists('booking_data_mutate')) {
            $res = booking_data_mutate($db, $invoiceId, function (array &$bd) use ($key, $on, $time) {
                if ($on) { $bd[$key] = ['on' => true, 'time' => substr(trim($time), 0, 10), 'at' => date('Y-m-d H:i:s')]; }
                else { unset($bd[$key]); }
                return true;
            });
            return !empty($res['ok']);
        }
        try {
            $b = $db->get('bookings', ['booking_data'], ['invoice_id' => $invoiceId]);
            if (!$b) { return false; }
            $bd = json_decode((string) ($b['booking_data'] ?? ''), true);
            if (!is_array($bd)) { $bd = []; }
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

// ===========================================================================
// COMMAND CONSOLE (Front-desk overhaul Phase 2) — the run-the-day room/HK board
// + occupancy, so the front-desk screen surfaces room state + a live occupancy
// read, not just arrival/departure lists. Pure reads; org/property scoped by the
// $stayIds the caller already resolved from the staffer's permitted properties.
// ===========================================================================
if (!function_exists('fd_room_status')) {
    /**
     * Housekeeping/room-status board for the given stays. Counts physical rooms by
     * hk_status (clean|dirty|inspected|out_of_order — the enum on stays_physical_rooms)
     * exactly as the BI ops panel does (supplier_bi.php bi_ops_counts), plus the total
     * sellable (active, not out-of-order) count. Returns
     * ['clean'=>,'dirty'=>,'inspected'=>,'out_of_order'=>,'total'=>,'sellable'=>].
     */
    function fd_room_status($db, array $stayIds): array
    {
        $out = ['clean' => 0, 'dirty' => 0, 'inspected' => 0, 'out_of_order' => 0, 'total' => 0, 'sellable' => 0];
        if (empty($stayIds)) { return $out; }
        try {
            foreach ($db->select('stays_physical_rooms', ['hk_status'], ['stay_id' => $stayIds, 'active' => 1]) ?: [] as $r) {
                $s = (string) $r['hk_status'];
                if (isset($out[$s])) { $out[$s]++; }
                $out['total']++;
                if ($s !== 'out_of_order') { $out['sellable']++; }
            }
        } catch (\Throwable $e) { error_log('fd_room_status: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('fd_room_board')) {
    /**
     * PER-ROOM board for ONE property (the front-desk room grid). fd_room_status returns
     * only COUNTS; this returns a tile per physical room so the desk can SEE each room's
     * clean/dirty/inspected/out-of-order state at a glance, who is in it, and act on it.
     * Each row: id, room_number, floor, hk_status, occupant_name, occupant_invoice, notes.
     * Occupancy comes from hk_rooms_in_use (checked-in bookings → physical room). Sorted
     * by floor then room number. Read-only; the view posts status changes to the existing
     * /supplier/housekeeping/status endpoint.
     */
    function fd_room_board($db, int $stayId): array
    {
        if ($stayId <= 0) { return []; }
        try {
            $rooms = $db->select('stays_physical_rooms',
                ['id', 'room_number', 'floor', 'floor_id', 'hk_status', 'notes', 'room_id'],
                ['stay_id' => $stayId, 'active' => 1, 'ORDER' => ['floor' => 'ASC', 'room_number' => 'ASC']]) ?: [];
        } catch (\Throwable $e) { error_log('fd_room_board: ' . $e->getMessage()); return []; }
        if (empty($rooms)) { return []; }

        // Floor NAME from the structured floor_id (what the room-manager form sets); the
        // plain `floor` text is a legacy fallback.
        $floorNameById = [];
        try { foreach ($db->select('stays_floors', ['id', 'name'], ['stay_id' => $stayId]) ?: [] as $f) { $floorNameById[(int) $f['id']] = (string) $f['name']; } } catch (\Throwable $e) {}

        // Resolve each physical room's TYPE NAME (Deluxe / King / …) so the board can show
        // it alongside the number — the way front desks identify rooms ("Deluxe 101").
        // Path: stays_physical_rooms.room_id -> stays_rooms.room_type_id -> stays_settings.name.
        // Also resolve each physical room's OWN descriptive NAME (e.g. "Standard King",
        // "Deluxe Queen") — several rooms share one type, so the NAME is how the operator
        // tells them apart. Shown on the board so you know WHICH room, not just its category.
        $typeByRoomId = [];
        $nameByRoomId = [];
        try {
            $rtNames = [];
            foreach ($db->select('stays_settings', ['id', 'name'], ['setting_type' => 'room_type']) ?: [] as $rt) {
                $rtNames[(int) $rt['id']] = (string) $rt['name'];
            }
            $ridSet = array_values(array_unique(array_filter(array_map(fn($r) => (int) ($r['room_id'] ?? 0), $rooms))));
            if (!empty($ridSet)) {
                foreach ($db->select('stays_rooms', ['id', 'room_type_id', 'room_name'], ['id' => $ridSet]) ?: [] as $sr) {
                    $typeByRoomId[(int) $sr['id']] = $rtNames[(int) $sr['room_type_id']] ?? '';
                    $nameByRoomId[(int) $sr['id']] = trim((string) ($sr['room_name'] ?? ''));
                }
            }
        } catch (\Throwable $e) { error_log('fd_room_board types: ' . $e->getMessage()); }

        // Which rooms are OCCUPIED (checked-in) and which are RESERVED (assigned to a live
        // not-yet-arrived booking — audit M1: those must NOT read as vacant, or the desk
        // re-books the same room and creates an orphan). Resolve guest names for both.
        $inUse    = function_exists('hk_rooms_in_use') ? hk_rooms_in_use($db, $stayId) : [];
        $reserved = function_exists('hk_rooms_reserved') ? hk_rooms_reserved($db, $stayId) : [];
        $names = [];
        $allInvs = array_values(array_unique(array_merge(array_values($inUse), array_values($reserved))));
        if (!empty($allInvs)) {
            try {
                foreach ($db->select('bookings', ['invoice_id', 'first_name', 'last_name'], ['invoice_id' => $allInvs]) ?: [] as $b) {
                    $names[(string) $b['invoice_id']] = trim((string) ($b['first_name'] ?? '') . ' ' . (string) ($b['last_name'] ?? ''));
                }
            } catch (\Throwable $e) {}
        }
        $out = [];
        foreach ($rooms as $r) {
            $prid = (int) $r['id'];
            $inv  = $inUse[$prid] ?? '';
            $resInv = (!isset($inUse[$prid]) && isset($reserved[$prid])) ? $reserved[$prid] : '';
            $fid  = (int) ($r['floor_id'] ?? 0);
            $floorLabel = $fid > 0 && isset($floorNameById[$fid]) ? $floorNameById[$fid] : trim((string) ($r['floor'] ?? ''));
            $out[] = [
                'id'               => $prid,
                'room_number'      => (string) ($r['room_number'] ?? ''),
                'room_type'        => $typeByRoomId[(int) ($r['room_id'] ?? 0)] ?? '',
                'room_name'        => $nameByRoomId[(int) ($r['room_id'] ?? 0)] ?? '', // the room's OWN name (audit UX#4)
                'floor'            => $floorLabel,
                'hk_status'        => (string) ($r['hk_status'] ?? 'clean'),
                'notes'            => (string) ($r['notes'] ?? ''),
                'occupant_invoice' => (string) $inv,
                'occupant_name'    => $inv !== '' ? (string) ($names[$inv] ?? '') : '',
                'reserved_invoice' => (string) $resInv, // assigned but not yet checked in (audit M1)
                'reserved_name'    => $resInv !== '' ? (string) ($names[$resInv] ?? '') : '',
            ];
        }
        return $out;
    }
}

if (!function_exists('fd_occupancy')) {
    /**
     * Occupancy read for the business date: in-house room-nights vs sellable physical
     * rooms. in_house comes from the already-computed buckets (resv_dashboard_buckets)
     * so there is one source of truth for "who is in-house today". pct is in-house /
     * sellable, clamped 0..100 (0 when there are no sellable rooms). Returns
     * ['in_house'=>,'sellable'=>,'available'=>,'pct'=>].
     */
    function fd_occupancy(array $buckets, array $roomStatus): array
    {
        $inHouse  = (int) ($buckets['in_house_n'] ?? 0);
        $sellable = (int) ($roomStatus['sellable'] ?? 0);
        $available = max(0, $sellable - $inHouse);
        $pct = $sellable > 0 ? (int) round(min(100, max(0, $inHouse / $sellable * 100))) : 0;
        return ['in_house' => $inHouse, 'sellable' => $sellable, 'available' => $available, 'pct' => $pct];
    }
}

// ===========================================================================
// TYPED DESK TENDERS (Front-desk overhaul Phase 5) — when the desk takes a folio
// PAYMENT, record HOW it was paid (tender type) and, for CASH, feed the acting
// cashier's open shift so the drawer reconciles. The folio_items table has no
// tender column, so the tender is captured in the payment line's description
// (e.g. "Payment — Cash · ref123"); the cashier shift is the existing
// supplier_ledger engine (cashier_shift_open/cash_movement_add/close).
// ===========================================================================
if (!function_exists('desk_tender_types')) {
    /** The tender types a front desk handles. value => label. */
    function desk_tender_types(): array
    {
        return [
            'cash'            => 'Cash',
            'card'            => 'Card',
            'bank_transfer'   => 'Bank transfer',
            'wallet'          => 'Customer wallet',
            'company_account' => 'Company / NGO account',
        ];
    }
}

if (!function_exists('desk_tender_label')) {
    /** Human label for a tender value (falls back to the raw value titled). */
    function desk_tender_label(string $tender): string
    {
        $t = desk_tender_types();
        return $t[$tender] ?? ucfirst(str_replace('_', ' ', $tender));
    }
}

// =============================================================================
// PER-PROPERTY PAYMENT METHODS (money-IN at the desk) — table stays_payment_methods.
// DISTINCT from desk_tender_types() (generic labels for the post-booking mark-paid),
// from supplier_bank_accounts (money-OUT payouts), and from payment_gateways (GLOBAL
// admin gateways). Owner/admin configures these per property; the booking payment block
// reads the active ones. kind='goglobia_gateway' => per-booking DVA; kind='manual' =>
// owner-named (UBA transfer / Cash / Moniepoint POS …) with guest-facing instructions.
// =============================================================================

if (!function_exists('stays_payment_methods_list')) {
    /**
     * The configured payment methods for a property. $activeOnly hides disabled rows (what
     * the desk sees); the admin UI passes false to list everything. Ordered by sort_order
     * then id. Returns [] on any error (never throws into a page).
     */
    function stays_payment_methods_list($db, int $stayId, bool $activeOnly = true): array
    {
        if ($stayId <= 0) { return []; }
        $where = ['stay_id' => $stayId];
        if ($activeOnly) { $where['active'] = 1; }
        $where['ORDER'] = ['sort_order' => 'ASC', 'id' => 'ASC'];
        try {
            return $db->select('stays_payment_methods',
                ['id', 'stay_id', 'org_id', 'kind', 'label', 'instructions', 'bank_account_id', 'active', 'sort_order'],
                $where) ?: [];
        } catch (\Throwable $e) {
            error_log('stays_payment_methods_list: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('stays_payment_method_get')) {
    /**
     * Fetch ONE method and verify it belongs to $stayId (ownership guard — never trust a
     * client-posted method id). Returns the row or null. When $activeOnly, a disabled
     * method resolves to null so the desk can't pay into a turned-off method.
     */
    function stays_payment_method_get($db, int $methodId, int $stayId, bool $activeOnly = true): ?array
    {
        if ($methodId <= 0 || $stayId <= 0) { return null; }
        $where = ['id' => $methodId, 'stay_id' => $stayId];
        if ($activeOnly) { $where['active'] = 1; }
        try {
            $row = $db->get('stays_payment_methods',
                ['id', 'stay_id', 'org_id', 'kind', 'label', 'instructions', 'bank_account_id', 'active'],
                $where);
            return $row ?: null;
        } catch (\Throwable $e) {
            error_log('stays_payment_method_get: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('stays_payment_gateway_enabled')) {
    /**
     * True if this property has an ACTIVE GoGlobia-gateway method (per-booking DVA). At
     * most one such row exists per property (enforced by the add/toggle helpers).
     */
    function stays_payment_gateway_enabled($db, int $stayId): bool
    {
        if ($stayId <= 0) { return false; }
        try {
            return (bool) $db->has('stays_payment_methods',
                ['stay_id' => $stayId, 'kind' => 'goglobia_gateway', 'active' => 1]);
        } catch (\Throwable $e) {
            error_log('stays_payment_gateway_enabled: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('stays_payment_method_add')) {
    /**
     * Add a payment method to a property. For kind='goglobia_gateway' there is only ever
     * ONE row per property — if one exists it is reactivated/relabelled instead of
     * duplicated (idempotent toggle-on). For 'manual', a new named row is created.
     * Returns ['ok'=>bool,'id'=>int,'message'=>string]. Caller authorizes the property.
     */
    function stays_payment_method_add($db, int $stayId, array $in): array
    {
        if ($stayId <= 0) { return ['ok' => false, 'id' => 0, 'message' => 'No property.']; }
        $kind  = ($in['kind'] ?? 'manual') === 'goglobia_gateway' ? 'goglobia_gateway' : 'manual';
        $label = trim((string) ($in['label'] ?? ''));
        $instructions = trim((string) ($in['instructions'] ?? ''));
        $bankId = (int) ($in['bank_account_id'] ?? 0);
        $orgId  = (int) ($in['org_id'] ?? 0);
        $actor  = (string) ($in['actor'] ?? '');
        if ($kind === 'goglobia_gateway') {
            $label = $label !== '' ? $label : 'GoGlobia gateway (card / bank / DVA)';
        } elseif ($label === '') {
            return ['ok' => false, 'id' => 0, 'message' => 'A method name is required (e.g. "UBA bank transfer").'];
        }
        if (strlen($label) > 80) { $label = substr($label, 0, 80); }
        if (strlen($instructions) > 1000) { $instructions = substr($instructions, 0, 1000); }
        try {
            if ($kind === 'goglobia_gateway') {
                // Only one gateway row per property: reactivate/relabel if present.
                $existing = $db->get('stays_payment_methods', ['id'],
                    ['stay_id' => $stayId, 'kind' => 'goglobia_gateway']);
                if ($existing) {
                    $db->update('stays_payment_methods',
                        ['active' => 1, 'label' => $label, 'instructions' => $instructions ?: null, 'updated_at' => date('Y-m-d H:i:s')],
                        ['id' => (int) $existing['id']]);
                    return ['ok' => true, 'id' => (int) $existing['id'], 'message' => 'GoGlobia gateway enabled.'];
                }
            }
            $sort = (int) ($db->max('stays_payment_methods', 'sort_order', ['stay_id' => $stayId]) ?? 0) + 1;
            $db->insert('stays_payment_methods', [
                'stay_id'      => $stayId,
                'org_id'       => $orgId > 0 ? $orgId : null,
                'kind'         => $kind,
                'label'        => $label,
                'instructions' => $instructions !== '' ? $instructions : null,
                'bank_account_id' => $bankId > 0 ? $bankId : null,
                'active'       => 1,
                'sort_order'   => $sort,
                'created_by'   => $actor !== '' ? $actor : null,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            $id = (int) $db->id();
            return ['ok' => $id > 0, 'id' => $id, 'message' => $id > 0 ? 'Payment method added.' : 'Could not add the method.'];
        } catch (\Throwable $e) {
            error_log('stays_payment_method_add: ' . $e->getMessage());
            return ['ok' => false, 'id' => 0, 'message' => 'Could not add the method.'];
        }
    }
}

if (!function_exists('stays_payment_method_update')) {
    /** Edit a manual method's label/instructions/bank link. Verifies it belongs to $stayId. */
    function stays_payment_method_update($db, int $methodId, int $stayId, array $in): array
    {
        $m = stays_payment_method_get($db, $methodId, $stayId, false);
        if (!$m) { return ['ok' => false, 'message' => 'Method not found.']; }
        $label = trim((string) ($in['label'] ?? $m['label']));
        if ($m['kind'] === 'manual' && $label === '') { return ['ok' => false, 'message' => 'A method name is required.']; }
        $instructions = trim((string) ($in['instructions'] ?? (string) ($m['instructions'] ?? '')));
        $bankId = array_key_exists('bank_account_id', $in) ? (int) $in['bank_account_id'] : (int) ($m['bank_account_id'] ?? 0);
        try {
            $db->update('stays_payment_methods', [
                'label'        => substr($label, 0, 80),
                'instructions' => $instructions !== '' ? substr($instructions, 0, 1000) : null,
                'bank_account_id' => $bankId > 0 ? $bankId : null,
                'updated_at'   => date('Y-m-d H:i:s'),
            ], ['id' => $methodId, 'stay_id' => $stayId]);
            return ['ok' => true, 'message' => 'Method updated.'];
        } catch (\Throwable $e) {
            error_log('stays_payment_method_update: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not update the method.'];
        }
    }
}

if (!function_exists('stays_payment_method_set_active')) {
    /** Enable/disable a method (property-scoped). */
    function stays_payment_method_set_active($db, int $methodId, int $stayId, bool $active): bool
    {
        if ($methodId <= 0 || $stayId <= 0) { return false; }
        try {
            $db->update('stays_payment_methods',
                ['active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $methodId, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) {
            error_log('stays_payment_method_set_active: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('stays_payment_method_delete')) {
    /** Remove a method (property-scoped). Hard delete — config row, no money history on it. */
    function stays_payment_method_delete($db, int $methodId, int $stayId): bool
    {
        if ($methodId <= 0 || $stayId <= 0) { return false; }
        try {
            $db->delete('stays_payment_methods', ['id' => $methodId, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) {
            error_log('stays_payment_method_delete: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('stays_desk_dva_for_booking')) {
    /**
     * Generate (or reuse) a per-booking GoGlobia-gateway DVA for a DESK booking, so the
     * guest can pay by bank transfer and the booking auto-settles via the Paystack
     * webhook. REUSES gia_bp_create_dva in DESK MODE (no 20-min expiry, no GIA chat
     * reminders, no auto-cancel) — this is NOT a GIA chat booking, so the room must stay
     * held until the guest pays. No parallel DVA/settlement code: minting + the
     * gia_booking_payments row + webhook confirm are all the proven GIA path.
     *
     * Only proceeds when the property has an ACTIVE 'goglobia_gateway' method
     * (stays_payment_gateway_enabled). Returns the create result:
     *   ['ok'=>true, 'account_number','bank_name','account_name','amount','currency'] or
     *   ['ok'=>false,'message'=>…] (+ not_enabled / not_ngn / needs_bvn flags passed through).
     *
     * @param int    $stayId    the property (authorizes that the gateway is enabled on it)
     * @param string $invoiceId the just-created booking's invoice id
     */
    function stays_desk_dva_for_booking($db, int $stayId, string $invoiceId): array
    {
        if ($stayId <= 0 || trim($invoiceId) === '') {
            return ['ok' => false, 'message' => 'No booking to collect for.'];
        }
        if (!stays_payment_gateway_enabled($db, $stayId)) {
            return ['ok' => false, 'not_enabled' => true,
                'message' => 'The GoGlobia gateway is not enabled for this property.'];
        }
        // Load the GIA booking-payment engine (DVA minting + webhook settlement live here).
        if (!function_exists('gia_bp_create_dva')) {
            $lib = dirname(__DIR__) . '/lib/ai/gia_booking_payment.php';
            if (!is_file($lib)) { $lib = __DIR__ . '/ai/gia_booking_payment.php'; }
            if (is_file($lib)) { require_once $lib; }
        }
        if (!function_exists('gia_bp_create_dva')) {
            return ['ok' => false, 'message' => 'Bank-transfer payment is unavailable right now.'];
        }
        // channel/channel_user_id left blank: a desk booking has no chat channel, so
        // gia_bp_notify is a no-op for it (and desk mode pre-maxes reminders anyway).
        return gia_bp_create_dva($db, $invoiceId, '', '', true);
    }
}

if (!function_exists('desk_resolve_discount')) {
    /**
     * Resolve + authorize an operator-entered front-desk discount against the acting
     * staffer's ROLE CAP. ONE place both the walk-in create path and the group path call,
     * so the cap logic (amount|percent -> % of base -> supplier_can_approve('discount_percent'))
     * is not copy-pasted. This is the SAME rule the folio credit_note already enforces
     * (supplierReservationsRoutes.php) — extracted, not duplicated.
     *
     * NOTE: this is the STAFF-GRANTED manual discount (a one-off the operator types, capped
     * by role). It is DISTINCT from app/lib/discounts.php, which is the automatic rule-based
     * marketing-discount engine (admin-configured, scope-matched, margin-floored) — a
     * different concern, deliberately not reused here.
     *
     * @param float  $baseAmount the charge the discount reduces (room total, folio charges…)
     * @param float  $value      the number the operator typed
     * @param string $type       'amount' (currency off) | 'percent' (% off)
     * @param int    $stayId     property (for the ownership/scope check in supplier_can_approve)
     * @param bool   $ownerOverride owner ticked "override my cap" (ignored for non-owners)
     * @param string $invoiceId  optional, for the override audit event
     *
     * @return array [
     *   'ok'      => bool   (false only on bad input, e.g. negative/over-base),
     *   'amount'  => float  (the clamped currency discount, 0..baseAmount),
     *   'pct'     => float  (that amount as a % of base; 100 if base is 0),
     *   'allowed' => bool   (within the role cap, or an owner override),
     *   'message' => string (why it was blocked, for the operator),
     * ]
     * When allowed=false the caller MUST NOT apply the discount (escalation required).
     */
    function desk_resolve_discount($db, float $baseAmount, float $value, string $type, int $stayId, bool $ownerOverride = false, string $invoiceId = ''): array
    {
        $base = round(max(0.0, $baseAmount), 2);
        $type = $type === 'percent' ? 'percent' : 'amount';
        $value = max(0.0, $value);

        // No discount requested -> trivially ok, nothing to cap.
        if ($value <= 0.0) {
            return ['ok' => true, 'amount' => 0.0, 'pct' => 0.0, 'allowed' => true, 'message' => ''];
        }

        // Convert to a concrete currency amount, clamped to the base (can't discount more
        // than the charge). Percent is clamped to 100 first.
        if ($type === 'percent') {
            $pctIn = min(100.0, $value);
            $amount = round($base * $pctIn / 100.0, 2);
        } else {
            $amount = min($base, round($value, 2));
        }
        if ($amount <= 0.0) {
            return ['ok' => true, 'amount' => 0.0, 'pct' => 0.0, 'allowed' => true, 'message' => ''];
        }
        // Effective % of the base (what the cap is expressed in). If base is 0 there is no
        // charge to discount, so treat any discount as 100% so it still gates.
        $pct = $base > 0 ? round($amount / $base * 100.0, 2) : 100.0;

        // Authorize against the role cap (owner/admin unlimited; staff bound; unconfigured
        // denies -> escalate). Same key the folio credit_note uses.
        $allowed = !function_exists('supplier_can_approve')
            || supplier_can_approve($db, 'discount_percent', $pct, $stayId);

        if (!$allowed) {
            $ctx = function_exists('supplier_acting_context') ? supplier_acting_context($db) : null;
            $isOwner = is_array($ctx) && !empty($ctx['is_owner']);
            if ($isOwner && $ownerOverride) {
                // Owner override — allowed, logged to the audit trail like the folio path.
                $allowed = true;
                if (function_exists('emit_event')) {
                    $org = function_exists('supplier_property_org') ? (int) supplier_property_org($db, $stayId) : 0;
                    emit_event($db, 'discount.override',
                        ['invoice_id' => $invoiceId, 'amount' => $amount, 'pct' => $pct, 'source' => 'desk_create'],
                        'reservation', $invoiceId !== '' ? $invoiceId : null, $org > 0 ? $org : null);
                }
            } else {
                return ['ok' => true, 'amount' => $amount, 'pct' => $pct, 'allowed' => false,
                    'message' => 'That discount (' . rtrim(rtrim(number_format($pct, 2), '0'), '.') . '%) exceeds your role’s limit — a manager must approve it.'];
            }
        }

        return ['ok' => true, 'amount' => $amount, 'pct' => $pct, 'allowed' => true, 'message' => ''];
    }
}

if (!function_exists('desk_cash_to_shift')) {
    /**
     * Feed a CASH desk payment into the acting user's OPEN cashier shift as a 'received'
     * movement, so the drawer's expected total reconciles. Resolves the shift by
     * (org_id, user_id, status=open) — the same key the cashier screen uses. Returns
     * ['fed'=>bool,'shift_id'=>int,'message'=>]. Non-fatal: if there is no open shift,
     * the payment still stands (fed=false) — we never block taking the guest's money,
     * we just can't attribute it to a drawer that isn't open.
     */
    function desk_cash_to_shift($db, int $orgId, string $userId, float $amount, string $reference = ''): array
    {
        $out = ['fed' => false, 'shift_id' => 0, 'message' => ''];
        if ($orgId <= 0 || trim($userId) === '' || $amount <= 0) { return $out; }
        if (!function_exists('cash_movement_add')) { return $out; }
        try {
            $shift = $db->get('cashier_shifts', ['id'], ['org_id' => $orgId, 'user_id' => $userId, 'status' => 'open']);
            if (!$shift) { $out['message'] = 'no open cashier shift'; return $out; }
            $shiftId = (int) $shift['id'];
            $ok = cash_movement_add($db, $shiftId, 'received', round($amount, 2), [
                'reference' => $reference !== '' ? $reference : null,
                'memo'      => 'Desk payment (cash)',
            ]);
            $out['fed'] = (bool) $ok;
            $out['shift_id'] = $shiftId;
            if (!$ok) { $out['message'] = 'could not record to shift'; }
            return $out;
        } catch (\Throwable $e) { error_log('desk_cash_to_shift: ' . $e->getMessage()); return $out; }
    }
}

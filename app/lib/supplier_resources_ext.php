<?php
// FILE: app/lib/supplier_resources_ext.php
// MODULE 33 (Other hotel services — generic bookable RESOURCES) DEEPENING.
// The base (supplier_ancillary.php) already ships a flat SERVICES catalogue
// (service_add / service_list) + per-guest service bookings with a simple
// booked/completed/cancelled status (service_booking_*). That base has NO notion of
// FINITE CAPACITY, no overbooking guard, no fulfillment/dispatch lifecycle, and never
// touches the guest money spine. This file adds the genuine spec gaps on TOP of that,
// in NEW tables only:
//   • CAPACITY resources (shuttle vans, parking slot blocks, equipment pools)  → stays_resources
//   • capacity-aware RESERVATION with overlap guard (NO overbooking)           → stays_resource_slots
//   • fulfillment LIFECYCLE  reserved → dispatched → returned/completed/no_show/cancelled
//   • reservation charge → guest FOLIO posting (money spine; DB-GATED)         → reuses folio_add_item
//   • availability + utilisation read-models (read-only)
//
// Scope/auth: the CALLER authorizes the stay via supplier_can(...) BEFORE calling these;
// these functions STILL re-scope every write by stay_id (defence in depth / IDOR stop).
// Price is NEVER trusted from the client — the charge is always snapshot from the
// resource's unit_price × units server-side. All functions are defensive/non-fatal
// (try/catch + error_log), matching house style. NOT runtime-verified (no MySQL in the
// build env) — logic-reasoned + php -l + a pure-math harness for the capacity/charge math.

// ---------------------------------------------------------------------------
// RESOURCE CATALOGUE  (stays_resources)
// ---------------------------------------------------------------------------
if (!function_exists('resource_kinds')) {
    /** Generic bookable-resource kinds for #33. Distinct from service_kinds() (#32). */
    function resource_kinds(): array
    {
        return ['shuttle' => 'Shuttle / transfer', 'parking' => 'Parking', 'equipment' => 'Equipment rental',
                'bicycle' => 'Bicycle / mobility', 'cabana' => 'Cabana / sunbed', 'meeting' => 'Meeting kit', 'other' => 'Other'];
    }
}
if (!function_exists('resource_statuses')) {
    /** Reservation fulfillment lifecycle. reserved/dispatched/returned/completed are the
     *  happy path; no_show/cancelled release the held capacity. */
    function resource_statuses(): array
    {
        return ['reserved' => 'Reserved', 'dispatched' => 'Dispatched / issued', 'returned' => 'Returned',
                'completed' => 'Completed', 'no_show' => 'No-show', 'cancelled' => 'Cancelled'];
    }
}
if (!function_exists('resource_status_holds_capacity')) {
    /** Statuses that still CONSUME a unit of capacity for a window. Cancelled/no-show
     *  release it; returned/completed mean the window is over (still count while active). */
    function resource_status_holds_capacity(string $status): bool
    {
        return in_array($status, ['reserved', 'dispatched'], true);
    }
}

if (!function_exists('resource_add')) {
    /** Register a finite-capacity resource. Returns id (0 on failure). Property-scoped. */
    function resource_add($db, int $stayId, array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($stayId <= 0 || $name === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $kind  = isset(resource_kinds()[$d['kind'] ?? '']) ? $d['kind'] : 'other';
            $cap   = max(1, (int) ($d['capacity'] ?? 1));
            $price = max(0, round((float) ($d['unit_price'] ?? 0), 2));
            $db->insert('stays_resources', [
                'org_id'     => $orgId > 0 ? $orgId : null,
                'stay_id'    => $stayId,
                'kind'       => $kind,
                'name'       => substr($name, 0, 191),
                'location'   => substr(trim((string) ($d['location'] ?? '')), 0, 120) ?: null,
                'capacity'   => $cap,
                'unit_price' => $price,
                'unit_label' => substr(trim((string) ($d['unit_label'] ?? '')), 0, 40) ?: null,
                'active'     => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('resource_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('resource_set_active')) {
    /** Toggle a resource active/retired. Property-scoped (re-checks stay_id). */
    function resource_set_active($db, int $id, int $stayId, bool $active): bool
    {
        if ($id <= 0 || $stayId <= 0) { return false; }
        try {
            if (!$db->has('stays_resources', ['id' => $id, 'stay_id' => $stayId])) { return false; }
            $db->update('stays_resources', ['active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('resource_set_active: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('resource_list')) {
    function resource_list($db, array $stayIds, int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        try { return $db->select('stays_resources', '*', ['stay_id' => $stayIds, 'ORDER' => ['name' => 'ASC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('resource_list: ' . $e->getMessage()); return []; }
    }
}
if (!function_exists('resource_get')) {
    function resource_get($db, int $id, int $stayId): ?array
    {
        if ($id <= 0 || $stayId <= 0) { return null; }
        try { $r = $db->get('stays_resources', '*', ['id' => $id, 'stay_id' => $stayId]); return $r ?: null; }
        catch (\Throwable $e) { error_log('resource_get: ' . $e->getMessage()); return null; }
    }
}

// ---------------------------------------------------------------------------
// CAPACITY MATH  (pure — harnessed)
// ---------------------------------------------------------------------------
if (!function_exists('resource_units_held')) {
    /** Sum of units that overlap [$from,$to) for a resource among capacity-holding
     *  reservations, EXCLUDING $excludeSlotId (used when re-checking an existing row).
     *  Overlap rule: a.start < b.end && a.end > b.start. Pure DB read. */
    function resource_units_held($db, int $resourceId, string $from, string $to, int $excludeSlotId = 0): int
    {
        if ($resourceId <= 0 || $from === '' || $to === '') { return 0; }
        try {
            $rows = $db->select('stays_resource_slots', ['units', 'start_at', 'end_at', 'status'],
                ['resource_id' => $resourceId, 'status' => ['reserved', 'dispatched']]) ?: [];
            $held = 0;
            foreach ($rows as $r) {
                if ($excludeSlotId > 0 && (int) ($r['id'] ?? 0) === $excludeSlotId) { continue; }
                $s = (string) ($r['start_at'] ?? ''); $e = (string) ($r['end_at'] ?? '');
                if ($s === '' || $e === '') { continue; }
                // overlap test
                if ($s < $to && $e > $from) { $held += max(0, (int) ($r['units'] ?? 0)); }
            }
            return $held;
        } catch (\Throwable $e) { error_log('resource_units_held: ' . $e->getMessage()); return PHP_INT_MAX; }
    }
}
if (!function_exists('resource_can_allocate')) {
    /** Pure capacity check: does $capacity allow $wantUnits more on top of $held? */
    function resource_can_allocate(int $capacity, int $held, int $wantUnits): bool
    {
        $capacity = max(0, $capacity); $held = max(0, $held); $wantUnits = max(0, $wantUnits);
        return ($held + $wantUnits) <= $capacity;
    }
}
if (!function_exists('resource_charge_amount')) {
    /** Pure charge math: unit_price × units, 2dp, never negative. Client NEVER supplies
     *  a price — this is the single source of truth for what a reservation costs. */
    function resource_charge_amount(float $unitPrice, int $units): float
    {
        return round(max(0, $unitPrice) * max(0, $units), 2);
    }
}

// ---------------------------------------------------------------------------
// RESERVATION (capacity-guarded, transactional)
// ---------------------------------------------------------------------------
if (!function_exists('resource_reserve')) {
    /**
     * Reserve $units of a resource for [start_at, end_at). Snapshots the charge from the
     * resource unit_price (NOT the client). Rejects an overbooking inside a row-locking
     * transaction (re-counts held units FOR UPDATE). Returns ['ok'=>bool,'id'=>int,
     * 'error'=>?string,'charge'=>float]. Property-scoped by $stayId.
     */
    function resource_reserve($db, int $stayId, int $resourceId, array $d): array
    {
        $out = ['ok' => false, 'id' => 0, 'error' => null, 'charge' => 0.0];
        if ($stayId <= 0 || $resourceId <= 0) { $out['error'] = 'Missing property/resource.'; return $out; }
        $from  = trim((string) ($d['start_at'] ?? ''));
        $to    = trim((string) ($d['end_at'] ?? ''));
        $units = max(1, (int) ($d['units'] ?? 1));
        if ($from === '' || $to === '') { $out['error'] = 'Start and end time are required.'; return $out; }
        if ($to <= $from) { $out['error'] = 'End must be after start.'; return $out; }
        try {
            $res = $db->get('stays_resources', ['id', 'org_id', 'capacity', 'unit_price', 'active'],
                ['id' => $resourceId, 'stay_id' => $stayId]);
            if (!$res) { $out['error'] = 'Resource not found for this property.'; return $out; }
            if ((int) ($res['active'] ?? 0) !== 1) { $out['error'] = 'Resource is retired.'; return $out; }
            $capacity = max(1, (int) $res['capacity']);
            if ($units > $capacity) { $out['error'] = 'Requested units exceed total capacity.'; return $out; }
            $charge = resource_charge_amount((float) $res['unit_price'], $units);

            $newId = 0; $err = null;
            $db->action(function ($db) use ($resourceId, $stayId, $res, $from, $to, $units, $capacity, $charge, $d, &$newId, &$err) {
                // Lock capacity-holding overlapping rows for this resource, then count.
                $locked = $db->query(
                    'SELECT units, start_at, end_at FROM stays_resource_slots '
                    . 'WHERE resource_id = :rid AND status IN (:a,:b) AND start_at < :to AND end_at > :from FOR UPDATE',
                    [':rid' => $resourceId, ':a' => 'reserved', ':b' => 'dispatched', ':to' => $to, ':from' => $from]
                );
                $held = 0;
                if ($locked) { foreach ($locked->fetchAll(\PDO::FETCH_ASSOC) as $r) { $held += max(0, (int) ($r['units'] ?? 0)); } }
                if (!resource_can_allocate($capacity, $held, $units)) {
                    $err = 'Not enough capacity for that window (' . ($capacity - $held) . ' of ' . $capacity . ' free).';
                    return false; // rollback
                }
                $db->insert('stays_resource_slots', [
                    'org_id'      => $res['org_id'] !== null ? (int) $res['org_id'] : null,
                    'stay_id'     => $stayId,
                    'resource_id' => $resourceId,
                    'guest_name'  => substr(trim((string) ($d['guest_name'] ?? '')), 0, 120) ?: null,
                    'invoice_id'  => trim((string) ($d['invoice_id'] ?? '')) ?: null,
                    'units'       => $units,
                    'start_at'    => $from,
                    'end_at'      => $to,
                    'status'      => 'reserved',
                    'charge'      => $charge,
                    'folio_posted'=> 0,
                    'notes'       => substr(trim((string) ($d['notes'] ?? '')), 0, 255) ?: null,
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);
                $newId = (int) $db->id();
                return $newId > 0;
            });
            if ($err !== null) { $out['error'] = $err; return $out; }
            if ($newId <= 0) { $out['error'] = 'Could not reserve.'; return $out; }
            $out['ok'] = true; $out['id'] = $newId; $out['charge'] = $charge;
            return $out;
        } catch (\Throwable $e) {
            error_log('resource_reserve: ' . $e->getMessage());
            $out['error'] = 'Reservation failed.'; return $out;
        }
    }
}
if (!function_exists('resource_slot_set_status')) {
    /** Advance a reservation's fulfillment status. Releasing statuses (cancelled/no_show)
     *  free the held capacity automatically (they're excluded from resource_units_held).
     *  Property-scoped. Returns bool. */
    function resource_slot_set_status($db, int $id, int $stayId, string $status): bool
    {
        if (!isset(resource_statuses()[$status])) { return false; }
        if ($id <= 0 || $stayId <= 0) { return false; }
        try {
            if (!$db->has('stays_resource_slots', ['id' => $id, 'stay_id' => $stayId])) { return false; }
            $db->update('stays_resource_slots',
                ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $id, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) { error_log('resource_slot_set_status: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('resource_slot_list')) {
    function resource_slot_list($db, array $stayIds, int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        try { return $db->select('stays_resource_slots', '*', ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('resource_slot_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// FOLIO POSTING (money spine — DB-GATED)
// ---------------------------------------------------------------------------
if (!function_exists('resource_slot_post_folio')) {
    /**
     * Post a reservation's snapshot charge onto the guest FOLIO as an 'extra' line.
     * DB-GATED: only posts when (a) the slot carries an invoice_id, (b) that invoice
     * resolves to an OPEN own-inventory folio (folio_get_or_create), and (c) it has not
     * already been posted (folio_posted guard → idempotent). The amount is the
     * server-snapshotted slot charge — the client cannot influence it. Returns
     * ['ok'=>bool,'error'=>?string]. Reuses folio_get_or_create + folio_add_item; no new
     * money math is invented here.
     */
    function resource_slot_post_folio($db, int $id, int $stayId): array
    {
        $out = ['ok' => false, 'error' => null];
        if ($id <= 0 || $stayId <= 0) { $out['error'] = 'Bad slot.'; return $out; }
        if (!function_exists('folio_get_or_create') || !function_exists('folio_add_item')) {
            $out['error'] = 'Folio engine unavailable.'; return $out;
        }
        try {
            $slot = $db->get('stays_resource_slots',
                ['id', 'invoice_id', 'charge', 'units', 'folio_posted', 'resource_id'],
                ['id' => $id, 'stay_id' => $stayId]);
            if (!$slot) { $out['error'] = 'Reservation not found.'; return $out; }
            if ((int) ($slot['folio_posted'] ?? 0) === 1) { $out['error'] = 'Already posted to folio.'; return $out; }
            $invoice = trim((string) ($slot['invoice_id'] ?? ''));
            if ($invoice === '') { $out['error'] = 'No guest invoice linked to this reservation.'; return $out; }
            $amount = round((float) ($slot['charge'] ?? 0), 2);
            if ($amount <= 0) { $out['error'] = 'Nothing to charge (0.00).'; return $out; }

            $folioId = folio_get_or_create($db, $invoice);
            if ($folioId <= 0) { $out['error'] = 'No open own-inventory folio for that invoice.'; return $out; }

            $res = $db->get('stays_resources', ['name', 'kind'], ['id' => (int) $slot['resource_id']]);
            $label = 'Resource: ' . (string) ($res['name'] ?? ('#' . $slot['resource_id']))
                   . ' ×' . (int) ($slot['units'] ?? 1);
            $actor = (string) ($_SESSION['user_id'] ?? 'desk');

            // Flip the idempotency guard FIRST (conditioned on still being 0) so a double
            // submit cannot double-post even if folio_add_item is retried. The WHERE
            // includes folio_posted=0, so a concurrent winner makes rowCount()=0 → bail.
            $stmt = $db->update('stays_resource_slots',
                ['folio_posted' => 1, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $id, 'stay_id' => $stayId, 'folio_posted' => 0]);
            if (!$stmt || (int) $stmt->rowCount() === 0) {
                // another request already claimed the guard (race) — bail without posting
                $out['error'] = 'Reservation already being posted.'; return $out;
            }
            $ok = folio_add_item($db, $folioId, 'extra', $amount, $label, $actor);
            if (!$ok) {
                // roll the guard back so it can be retried
                $db->update('stays_resource_slots', ['folio_posted' => 0], ['id' => $id, 'stay_id' => $stayId]);
                $out['error'] = 'Folio rejected the charge (folio closed?).'; return $out;
            }
            $out['ok'] = true; return $out;
        } catch (\Throwable $e) {
            error_log('resource_slot_post_folio: ' . $e->getMessage());
            $out['error'] = 'Folio posting failed.'; return $out;
        }
    }
}

// ---------------------------------------------------------------------------
// READ-MODELS (availability + utilisation)
// ---------------------------------------------------------------------------
if (!function_exists('resource_availability')) {
    /** For a resource + window: ['capacity','held','free']. free never below 0. */
    function resource_availability($db, int $resourceId, int $stayId, string $from, string $to): array
    {
        $res = resource_get($db, $resourceId, $stayId);
        if (!$res) { return ['capacity' => 0, 'held' => 0, 'free' => 0]; }
        $cap = max(0, (int) $res['capacity']);
        $held = resource_units_held($db, $resourceId, $from, $to);
        if ($held === PHP_INT_MAX) { $held = $cap; } // read failed → assume full (safe)
        return ['capacity' => $cap, 'held' => $held, 'free' => max(0, $cap - $held)];
    }
}
if (!function_exists('resource_utilisation')) {
    /** Lightweight utilisation summary per resource over the whole open book:
     *  ['resource_id' => ['units'=>held-now-ish count, 'reservations'=>n]]. Counts only
     *  capacity-holding statuses. Read-only, best-effort. */
    function resource_utilisation($db, array $stayIds): array
    {
        if (empty($stayIds)) { return []; }
        try {
            $rows = $db->select('stays_resource_slots', ['resource_id', 'units', 'status'],
                ['stay_id' => $stayIds, 'status' => ['reserved', 'dispatched']]) ?: [];
            $out = [];
            foreach ($rows as $r) {
                $rid = (int) $r['resource_id'];
                if (!isset($out[$rid])) { $out[$rid] = ['units' => 0, 'reservations' => 0]; }
                $out[$rid]['units'] += max(0, (int) $r['units']);
                $out[$rid]['reservations']++;
            }
            return $out;
        } catch (\Throwable $e) { error_log('resource_utilisation: ' . $e->getMessage()); return []; }
    }
}

<?php
// FILE: app/lib/supplier_minibar_ext.php
// MINIBAR (#26) — DEEPENING EXTENSION. The base (app/lib/supplier_facilities.php)
// gives a per-property price-list catalogue (minibar_add / minibar_set_active /
// minibar_list). This file adds the genuine missing sub-features:
//   • Room assignment  — per-room par (expected stock) levels for a catalogue item.
//   • Consumption posting — records a consumed-item movement AND charges the guest's
//     OPEN folio via folio_add_item (price is ALWAYS taken from the catalogue row,
//     never the client; a closed/absent folio is refused).
//   • Replenishment     — records a restock movement that resets a room's on-hand.
//   • Shrinkage report  — derives, per room+item, restocked − sold − counted = loss.
//
// Scoping contract: the CALLER (route) has already run SUPPLIER_OR_STAFF_AUTH +
// supplier_can for the named stay. Every function here RE-VALIDATES that the target
// row (catalogue item / folio) belongs to the passed stay before acting, so it is
// safe even if a route forgets. All functions are defensive/non-fatal and guarded.
// NOT runtime-verified (no MySQL in the build env) — logic-reasoned + php -l.

// ---------------------------------------------------------------------------
// Movement kinds (the audit spine for consumption / replenishment / counts)
// ---------------------------------------------------------------------------
if (!function_exists('minibar_movement_kinds')) {
    function minibar_movement_kinds(): array
    {
        return ['consume' => 'Consumed', 'replenish' => 'Replenished', 'count' => 'Physical count'];
    }
}

// ---------------------------------------------------------------------------
// Internal: fetch an ACTIVE catalogue row scoped to a stay (price is trusted).
// ---------------------------------------------------------------------------
if (!function_exists('_minibar_item_for_stay')) {
    function _minibar_item_for_stay($db, int $itemId, int $stayId): ?array
    {
        if ($itemId <= 0 || $stayId <= 0) { return null; }
        try {
            $row = $db->get('stays_minibar_items', ['id', 'stay_id', 'name', 'price', 'active'],
                ['id' => $itemId, 'stay_id' => $stayId]);
            return $row ?: null;
        } catch (\Throwable $e) { error_log('_minibar_item_for_stay: ' . $e->getMessage()); return null; }
    }
}

// ---------------------------------------------------------------------------
// ROOM ASSIGNMENT — set a per-room par level for a catalogue item. Upsert keyed on
// (stay_id, room_number, item_id). par_qty is the expected full-stock count in the
// room. Returns bool.
// ---------------------------------------------------------------------------
if (!function_exists('minibar_set_par')) {
    function minibar_set_par($db, int $stayId, int $itemId, string $roomNumber, int $parQty): bool
    {
        $roomNumber = trim($roomNumber);
        if ($stayId <= 0 || $roomNumber === '') { return false; }
        if (!_minibar_item_for_stay($db, $itemId, $stayId)) { return false; } // item must belong to this stay
        $parQty = max(0, min(9999, $parQty));
        $roomNumber = substr($roomNumber, 0, 40);
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $existing = $db->get('stays_minibar_par', ['id'],
                ['stay_id' => $stayId, 'room_number' => $roomNumber, 'item_id' => $itemId]);
            if ($existing) {
                $db->update('stays_minibar_par',
                    ['par_qty' => $parQty, 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => (int) $existing['id']]);
            } else {
                $db->insert('stays_minibar_par', [
                    'org_id'      => $orgId > 0 ? $orgId : null,
                    'stay_id'     => $stayId,
                    'room_number' => $roomNumber,
                    'item_id'     => $itemId,
                    'par_qty'     => $parQty,
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);
            }
            return true;
        } catch (\Throwable $e) { error_log('minibar_set_par: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('minibar_par_list')) {
    /** Par levels for a set of owned stays (read-only, owner-scoped by caller). */
    function minibar_par_list($db, array $stayIds, int $limit = 1000): array
    {
        if (empty($stayIds)) { return []; }
        try {
            return $db->select('stays_minibar_par',
                ['id', 'stay_id', 'room_number', 'item_id', 'par_qty'],
                ['stay_id' => $stayIds, 'ORDER' => ['room_number' => 'ASC', 'item_id' => 'ASC'],
                 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) { error_log('minibar_par_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// CONSUMPTION POSTING — record a consumed-item movement and charge the guest folio.
// Price is read from the catalogue row (NEVER the client). We locate the OPEN folio
// for $invoiceId, re-confirm it belongs to $stayId, then folio_add_item('charge').
// Returns ['ok'=>bool,'message'=>string,'amount'=>float].
//
// DB-GATED on the folio engine: if folio_add_item / the folio for this booking does
// not exist (e.g. a booking with no stay folio), we refuse rather than silently
// recording an uncharged consumption — the two must stay in lock-step.
// ---------------------------------------------------------------------------
if (!function_exists('minibar_post_consumption')) {
    function minibar_post_consumption($db, int $stayId, int $itemId, string $invoiceId, int $qty,
                                      string $roomNumber = '', int $folioNo = 1): array
    {
        $invoiceId = trim($invoiceId);
        $qty = max(1, min(999, $qty));
        if ($stayId <= 0 || $invoiceId === '') { return ['ok' => false, 'message' => 'Missing reservation.', 'amount' => 0.0]; }

        $item = _minibar_item_for_stay($db, $itemId, $stayId);
        if (!$item) { return ['ok' => false, 'message' => 'Unknown minibar item for this property.', 'amount' => 0.0]; }
        if ((int) ($item['active'] ?? 0) !== 1) { return ['ok' => false, 'message' => 'Item is hidden; re-activate it first.', 'amount' => 0.0]; }

        $unit = round((float) $item['price'], 2);                 // trusted catalogue price
        $amount = round($unit * $qty, 2);
        if ($amount <= 0) { return ['ok' => false, 'message' => 'Item has no price set.', 'amount' => 0.0]; }

        if (!function_exists('folio_get_or_create') || !function_exists('folio_add_item')) {
            return ['ok' => false, 'message' => 'Folio engine unavailable.', 'amount' => 0.0];
        }

        try {
            // The folio is keyed on the booking invoice; confirm it is THIS property's.
            $folioId = folio_get_or_create($db, $invoiceId);
            if ($folioId <= 0) { return ['ok' => false, 'message' => 'No folio for this reservation.', 'amount' => 0.0]; }
            $folio = $db->get('stays_folios', ['id', 'stay_id', 'status'], ['id' => $folioId]);
            if (!$folio) { return ['ok' => false, 'message' => 'Folio missing.', 'amount' => 0.0]; }
            if ((int) ($folio['stay_id'] ?? 0) !== $stayId) {
                return ['ok' => false, 'message' => 'Reservation is not at this property.', 'amount' => 0.0];
            }
            if (($folio['status'] ?? '') !== 'open') {
                return ['ok' => false, 'message' => 'Folio is closed; cannot post.', 'amount' => 0.0];
            }

            $desc = 'Minibar: ' . $item['name'] . ($qty > 1 ? ' x' . $qty : '');
            $actor = (string) ($_SESSION['user_id'] ?? '') ?: 'minibar';
            $posted = folio_add_item($db, $folioId, 'charge', $amount, $desc, $actor, max(1, min(9, $folioNo)));
            if (!$posted) { return ['ok' => false, 'message' => 'Folio rejected the charge.', 'amount' => 0.0]; }

            // Record the consumption movement AFTER the charge succeeds, so the audit
            // spine never shows a consumption that was not billed.
            minibar_record_movement($db, $stayId, $itemId, 'consume', $qty, [
                'room_number' => $roomNumber !== '' ? $roomNumber : null,
                'invoice_id'  => $invoiceId,
                'folio_id'    => $folioId,
                'unit_price'  => $unit,
                'amount'      => $amount,
            ]);
            return ['ok' => true, 'message' => 'Charged ' . number_format($amount, 2) . ' to the folio.', 'amount' => $amount];
        } catch (\Throwable $e) {
            error_log('minibar_post_consumption: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not post consumption.', 'amount' => 0.0];
        }
    }
}

// ---------------------------------------------------------------------------
// REPLENISHMENT — record a restock movement for a room+item. No money effect
// (restocking is a cost of goods, not a guest charge). Returns bool.
// ---------------------------------------------------------------------------
if (!function_exists('minibar_replenish')) {
    function minibar_replenish($db, int $stayId, int $itemId, string $roomNumber, int $qty): bool
    {
        $roomNumber = trim($roomNumber);
        $qty = max(1, min(9999, $qty));
        if ($stayId <= 0 || $roomNumber === '') { return false; }
        if (!_minibar_item_for_stay($db, $itemId, $stayId)) { return false; }
        return minibar_record_movement($db, $stayId, $itemId, 'replenish', $qty, [
            'room_number' => substr($roomNumber, 0, 40),
        ]);
    }
}

// ---------------------------------------------------------------------------
// PHYSICAL COUNT — record a counted on-hand snapshot for a room+item. Used by the
// shrinkage report as the "actually on the shelf" figure. Returns bool.
// ---------------------------------------------------------------------------
if (!function_exists('minibar_record_count')) {
    function minibar_record_count($db, int $stayId, int $itemId, string $roomNumber, int $countedQty): bool
    {
        $roomNumber = trim($roomNumber);
        $countedQty = max(0, min(9999, $countedQty));
        if ($stayId <= 0 || $roomNumber === '') { return false; }
        if (!_minibar_item_for_stay($db, $itemId, $stayId)) { return false; }
        return minibar_record_movement($db, $stayId, $itemId, 'count', $countedQty, [
            'room_number' => substr($roomNumber, 0, 40),
        ]);
    }
}

// ---------------------------------------------------------------------------
// Internal movement writer (the single INSERT path for consume/replenish/count).
// ---------------------------------------------------------------------------
if (!function_exists('minibar_record_movement')) {
    function minibar_record_movement($db, int $stayId, int $itemId, string $kind, int $qty, array $extra = []): bool
    {
        if (!isset(minibar_movement_kinds()[$kind]) || $stayId <= 0 || $itemId <= 0) { return false; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $db->insert('stays_minibar_movements', [
                'org_id'      => $orgId > 0 ? $orgId : null,
                'stay_id'     => $stayId,
                'item_id'     => $itemId,
                'kind'        => $kind,
                'qty'         => max(0, $qty),
                'room_number' => isset($extra['room_number']) && $extra['room_number'] !== null
                    ? substr((string) $extra['room_number'], 0, 40) : null,
                'invoice_id'  => isset($extra['invoice_id']) ? substr((string) $extra['invoice_id'], 0, 191) : null,
                'folio_id'    => isset($extra['folio_id']) && $extra['folio_id'] ? (int) $extra['folio_id'] : null,
                'unit_price'  => isset($extra['unit_price']) ? round((float) $extra['unit_price'], 2) : null,
                'amount'      => isset($extra['amount']) ? round((float) $extra['amount'], 2) : null,
                'logged_by'   => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id() > 0;
        } catch (\Throwable $e) { error_log('minibar_record_movement: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('minibar_movements_list')) {
    /** Recent movements for owned stays (read-only, owner-scoped by caller). */
    function minibar_movements_list($db, array $stayIds, int $limit = 200): array
    {
        if (empty($stayIds)) { return []; }
        try {
            return $db->select('stays_minibar_movements', '*',
                ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) { error_log('minibar_movements_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// SHRINKAGE REPORT — per (stay_id, room_number, item_id):
//   replenished = Σ qty where kind='replenish'
//   sold        = Σ qty where kind='consume'  (these were charged to folios)
//   counted     = latest kind='count' snapshot (on-hand at last audit)
//   expected    = par_qty (if a par level exists for the room+item)
//   shrinkage   = replenished − sold − counted   (stock that left unaccounted-for)
// A positive shrinkage = loss/theft/breakage; negative = over-count / data error.
// Pure aggregation over movements + par; no money is moved here. Read-only.
// Returns a list of rows sorted by biggest shrinkage first.
// ---------------------------------------------------------------------------
if (!function_exists('minibar_shrinkage_report')) {
    function minibar_shrinkage_report($db, array $stayIds): array
    {
        if (empty($stayIds)) { return []; }
        $agg = []; // key "stay|room|item" => row
        $key = function ($s, $r, $i) { return $s . '|' . $r . '|' . $i; };
        try {
            $moves = $db->select('stays_minibar_movements',
                ['stay_id', 'item_id', 'kind', 'qty', 'room_number', 'id'],
                ['stay_id' => $stayIds, 'ORDER' => ['id' => 'ASC']]) ?: [];
            foreach ($moves as $m) {
                $s = (int) $m['stay_id']; $i = (int) $m['item_id'];
                $r = (string) ($m['room_number'] ?? '') !== '' ? (string) $m['room_number'] : '(unassigned)';
                $k = $key($s, $r, $i);
                if (!isset($agg[$k])) {
                    $agg[$k] = ['stay_id' => $s, 'room_number' => $r, 'item_id' => $i,
                        'replenished' => 0, 'sold' => 0, 'counted' => null, 'par_qty' => null];
                }
                $q = max(0, (int) $m['qty']);
                switch ((string) $m['kind']) {
                    case 'replenish': $agg[$k]['replenished'] += $q; break;
                    case 'consume':   $agg[$k]['sold'] += $q; break;
                    case 'count':     $agg[$k]['counted'] = $q; break; // ASC order ⇒ last wins = latest
                }
            }
            // Attach par levels where present.
            foreach ($db->select('stays_minibar_par',
                ['stay_id', 'room_number', 'item_id', 'par_qty'],
                ['stay_id' => $stayIds]) ?: [] as $p) {
                $k = $key((int) $p['stay_id'], (string) $p['room_number'], (int) $p['item_id']);
                if (isset($agg[$k])) { $agg[$k]['par_qty'] = (int) $p['par_qty']; }
            }
        } catch (\Throwable $e) { error_log('minibar_shrinkage_report: ' . $e->getMessage()); return []; }

        $rows = [];
        foreach ($agg as $r) {
            $counted = $r['counted'];
            // shrinkage only meaningful once a physical count exists for the room+item.
            $r['shrinkage'] = ($counted === null) ? null
                : (int) ($r['replenished'] - $r['sold'] - $counted);
            $rows[] = $r;
        }
        usort($rows, function ($a, $b) {
            $sa = $a['shrinkage'] === null ? -PHP_INT_MAX : $a['shrinkage'];
            $sb = $b['shrinkage'] === null ? -PHP_INT_MAX : $b['shrinkage'];
            return $sb <=> $sa;
        });
        return $rows;
    }
}

<?php
// FILE: app/lib/supplier_maintenance.php
// MAINTENANCE WORK-ORDERS + OUT-OF-ORDER (Phase 1 inc S23; docs 01 §4.3).
//
// Two things:
//   1. Work-order tickets (stays_work_orders): reported issue on a room/area,
//      priority, status open → in_progress → resolved. Pure operational records.
//   2. OUT-OF-ORDER (OOO): taking a PHYSICAL room out of service for a date range.
//      Per 01 §4.3 this REMOVES the unit from SELLABLE inventory — so, unlike
//      housekeeping (S22, overlay-only), this DOES touch stays_inventory. The model
//      must keep the anti-oversell math exact:
//
//      stays_inventory is pooled by (stay_id, room_id[type], option_id, date) with an
//      `available_count`. A physical room is ONE unit of its type. So OOO-ing one
//      physical room = decrement available_count by 1 for EACH of that room-type's
//      options on EACH affected date (floored so it never drops below what active
//      holds already consumed, and never below 0). Clearing restores +1.
//
//      We DO NOT use the `closed` flag for a single-room OOO (closed blanks the whole
//      option/type). closed is reserved for "the whole type is unsellable that night".
//
// Idempotency: an OOO block is recorded in stays_ooo_blocks with state active/cleared;
// the inventory delta is applied once on create and reversed once on clear (state
// guard). Re-applying or double-clearing is a no-op. All functions non-fatal; the
// inventory mutations run inside $db->action() with per-row FOR UPDATE.

if (!function_exists('wo_priorities')) {
    function wo_priorities(): array { return ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent']; }
}
if (!function_exists('wo_statuses')) {
    function wo_statuses(): array { return ['open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved']; }
}

if (!function_exists('wo_create')) {
    /** Create a work-order ticket. Returns id (0 on failure). */
    function wo_create($db, int $stayId, array $data): int
    {
        if ($stayId <= 0) { return 0; }
        $priority = isset(wo_priorities()[$data['priority'] ?? '']) ? $data['priority'] : 'normal';
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') { return 0; }
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $db->insert('stays_work_orders', [
                'org_id'          => $orgId > 0 ? $orgId : null,
                'stay_id'         => $stayId,
                'physical_room_id'=> ($pr = (int) ($data['physical_room_id'] ?? 0)) > 0 ? $pr : null,
                'area'            => trim((string) ($data['area'] ?? '')) ?: null,
                'title'           => substr($title, 0, 191),
                'description'     => trim((string) ($data['description'] ?? '')) ?: null,
                'priority'        => $priority,
                'status'          => 'open',
                'reported_by'     => (string) ($_SESSION['user_id'] ?? ''),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) {
            error_log('wo_create: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('wo_set_status')) {
    /** Advance a work-order's status (validated). Room/property-scoped by the caller. */
    function wo_set_status($db, int $woId, int $stayId, string $to): bool
    {
        if (!isset(wo_statuses()[$to])) { return false; }
        try {
            $wo = $db->get('stays_work_orders', ['id'], ['id' => $woId, 'stay_id' => $stayId]);
            if (!$wo) { return false; }
            $upd = ['status' => $to, 'updated_at' => date('Y-m-d H:i:s')];
            if ($to === 'resolved') { $upd['resolved_at'] = date('Y-m-d H:i:s'); }
            $db->update('stays_work_orders', $upd, ['id' => $woId, 'stay_id' => $stayId]);
            return true;
        } catch (\Throwable $e) {
            error_log('wo_set_status: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('_ooo_option_ids_for_room')) {
    /** The stable option_ids of a physical room's TYPE (so we know which pooled
     *  inventory rows to adjust). */
    function _ooo_option_ids_for_room($db, int $stayId, int $roomTypeId): array
    {
        if (!function_exists('stays_room_option_ids')) { return []; }
        $opts = stays_room_option_ids($db, $roomTypeId, $stayId);
        $ids = [];
        foreach ($opts as $o) { if ((int) ($o['option_id'] ?? 0) > 0) { $ids[] = (int) $o['option_id']; } }
        return $ids;
    }
}

if (!function_exists('ooo_block_create')) {
    /**
     * Put a physical room OUT OF ORDER for [from, to) (checkout-style: `to` exclusive).
     * Reduces pooled available_count by 1 for each of the room-type's options on each
     * night (never below active holds, never below 0). Records a stays_ooo_blocks row.
     * Returns ['ok'=>bool,'block_id'=>?,'message'=>?]. Also flips the physical room's
     * hk_status to out_of_order.
     */
    function ooo_block_create($db, int $physicalRoomId, int $stayId, string $from, string $to, string $reason = '', ?string $eta = null): array
    {
        if ($physicalRoomId <= 0 || $stayId <= 0) { return ['ok' => false, 'message' => 'Bad input']; }
        $nights = function_exists('stays_date_range') ? stays_date_range($from, $to) : [];
        if (empty($nights)) { return ['ok' => false, 'message' => 'Invalid date range']; }

        try {
            $pr = $db->get('stays_physical_rooms', ['id', 'room_id'], ['id' => $physicalRoomId, 'stay_id' => $stayId]);
            if (!$pr) { return ['ok' => false, 'message' => 'Room not found']; }
            $roomTypeId = (int) $pr['room_id'];
            $optionIds = _ooo_option_ids_for_room($db, $stayId, $roomTypeId);

            $result = ['ok' => false, 'message' => 'Could not create OOO block'];
            $db->action(function ($db) use ($physicalRoomId, $stayId, $roomTypeId, $optionIds, $nights, $from, $to, $reason, $eta, &$result) {
                $now = date('Y-m-d H:i:s');
                // Record the block FIRST (so the inventory delta is traceable/reversible).
                $db->insert('stays_ooo_blocks', [
                    'stay_id'          => $stayId,
                    'physical_room_id' => $physicalRoomId,
                    'room_id'          => $roomTypeId,
                    'date_from'        => $nights[0],
                    'date_to'          => $to,
                    'reason'           => $reason !== '' ? substr($reason, 0, 255) : null,
                    'eta'              => $eta ?: null,
                    'state'            => 'active',
                    'created_by'       => (string) ($_SESSION['user_id'] ?? ''),
                    'created_at'       => $now,
                ]);
                $blockId = (int) $db->id();
                if ($blockId <= 0) { $result = ['ok' => false, 'message' => 'Insert failed']; return false; }

                // Decrement pooled capacity by 1 per (option,date), floored at held usage.
                foreach ($nights as $date) {
                    foreach ($optionIds as $oid) {
                        $locked = $db->query(
                            "SELECT id, available_count FROM stays_inventory
                             WHERE stay_id=:s AND room_id=:r AND option_id=:o AND date=:d FOR UPDATE",
                            [':s' => $stayId, ':r' => $roomTypeId, ':o' => $oid, ':d' => $date]
                        );
                        $row = $locked ? $locked->fetch(\PDO::FETCH_ASSOC) : null;
                        if (!$row) {
                            // Seed from the option default (bridge, like S6), then decrement.
                            $opts = function_exists('stays_room_option_ids') ? stays_room_option_ids($db, $roomTypeId, $stayId) : [];
                            $def = function_exists('stays_option_default_quantity') ? stays_option_default_quantity($opts, $oid) : 0;
                            $newCount = max(0, $def - 1);
                            $db->insert('stays_inventory', [
                                'stay_id' => $stayId, 'room_id' => $roomTypeId, 'option_id' => $oid,
                                'date' => $date, 'available_count' => $newCount, 'closed' => 0, 'created_at' => $now,
                            ]);
                        } else {
                            // Never below 0; never below what active holds already consumed.
                            $held = (int) $db->sum('stays_holds', 'qty', [
                                'stay_id' => $stayId, 'room_id' => $roomTypeId, 'option_id' => $oid,
                                'state' => 'held', 'expires_at[>]' => $now,
                                'date_from[<=]' => $date, 'date_to[>]' => $date,
                            ]);
                            $floor = max(0, $held);
                            $newCount = max($floor, (int) $row['available_count'] - 1);
                            $db->update('stays_inventory',
                                ['available_count' => $newCount, 'updated_at' => $now],
                                ['id' => (int) $row['id']]);
                        }
                    }
                }
                // Mark the physical room out_of_order.
                $db->update('stays_physical_rooms',
                    ['hk_status' => 'out_of_order', 'updated_at' => $now],
                    ['id' => $physicalRoomId, 'stay_id' => $stayId]);
                $result = ['ok' => true, 'block_id' => $blockId];
                return true;
            });
            return $result;
        } catch (\Throwable $e) {
            error_log('ooo_block_create: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'OOO error'];
        }
    }
}

if (!function_exists('ooo_block_clear')) {
    /**
     * Clear an active OOO block: restore +1 pooled capacity per (option,date) and set
     * the physical room back to 'dirty' (needs inspection before resale). State-guarded
     * (only an 'active' block is reversed, exactly once). Returns bool.
     */
    function ooo_block_clear($db, int $blockId, int $stayId): bool
    {
        if ($blockId <= 0) { return false; }
        try {
            $ok = false;
            $db->action(function ($db) use ($blockId, $stayId, &$ok) {
                $locked = $db->query('SELECT * FROM stays_ooo_blocks WHERE id=:b AND stay_id=:s FOR UPDATE',
                    [':b' => $blockId, ':s' => $stayId]);
                $blk = $locked ? $locked->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$blk || $blk['state'] !== 'active') { $ok = false; return false; } // already cleared / missing
                $now = date('Y-m-d H:i:s');
                $roomTypeId = (int) $blk['room_id'];
                $optionIds = _ooo_option_ids_for_room($db, $stayId, $roomTypeId);
                $nights = function_exists('stays_date_range') ? stays_date_range((string) $blk['date_from'], (string) $blk['date_to']) : [];
                foreach ($nights as $date) {
                    foreach ($optionIds as $oid) {
                        $row = $db->query(
                            "SELECT id, available_count FROM stays_inventory
                             WHERE stay_id=:s AND room_id=:r AND option_id=:o AND date=:d FOR UPDATE",
                            [':s' => $stayId, ':r' => $roomTypeId, ':o' => $oid, ':d' => $date]
                        );
                        $inv = $row ? $row->fetch(\PDO::FETCH_ASSOC) : null;
                        if ($inv) {
                            $db->update('stays_inventory',
                                ['available_count' => ((int) $inv['available_count']) + 1, 'updated_at' => $now],
                                ['id' => (int) $inv['id']]);
                        }
                        // If the row vanished, we simply don't restore (the default seeds
                        // full capacity again on next read) — never over-restore.
                    }
                }
                $db->update('stays_ooo_blocks', ['state' => 'cleared', 'cleared_at' => $now], ['id' => $blockId, 'state' => 'active']);
                // Back to dirty (must be inspected before it's sellable/assignable again).
                $db->update('stays_physical_rooms',
                    ['hk_status' => 'dirty', 'updated_at' => $now],
                    ['id' => (int) $blk['physical_room_id'], 'stay_id' => $stayId, 'hk_status' => 'out_of_order']);
                $ok = true;
                return true;
            });
            return $ok;
        } catch (\Throwable $e) {
            error_log('ooo_block_clear: ' . $e->getMessage());
            return false;
        }
    }
}

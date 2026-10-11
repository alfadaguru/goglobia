<?php
// FILE: app/lib/supplier_stores_ext.php
// STORES & INVENTORY deepening (docs 01b module #42). Builds the genuine gaps on top
// of the base procurement layer (supplier_procurement.php: stays_stock_items / moves /
// POs / GRN). Per org.
//
// Base already ships: SKU catalogue, reorder level + low-stock alerts, GRN receipts,
// adjust/issue/wastage/count on a SINGLE org-wide on_hand number.
//
// This file adds the dimensions the base lacks:
//   * item categories (classification)                       -> stays_stock_categories
//   * a managed units-of-measure registry                    -> stays_stock_uoms
//   * MULTIPLE STORES (a warehouse/location dimension)        -> stays_stores
//   * per-store on-hand + WEIGHTED-AVERAGE moving cost        -> stays_store_stock
//   * batch / lot + expiry tracking                          -> stays_stock_batches
//   * inter-store transfers (atomic, stock-neutral)          -> stays_store_transfers
//   * physical counts per store feeding a variance report    -> stays_store_counts
//
// MONEY/GL: inter-store transfers are inventory-NEUTRAL (stock leaves one store and
// lands in another at the same moving-average cost) so they post NO GL — correct, not
// a shortcut. Receiving stock INTO a store records cost for weighted-average valuation
// only; the GL expense/AP posting for a purchase stays in the base proc_po_receive
// (DR 6000 / CR 2000). We never double-post and never trust a client-supplied value:
// valuation is always qty x server-held moving-avg unit cost. All multi-step stock
// writes run inside $db->action() with FOR UPDATE row locks where two actors can race.
//
// All functions non-fatal; org ownership is enforced by the caller routes (supplier_can).
// DB-GATED: the new tables must exist (see schema_sql in the deepen patch). Barcode/QR
// scanning is a DEVICE edge — see stores_barcode_status(): an honest 'not connected'
// stub, never a faked scanner.

// --------------------------------------------------------------------------------
// UNITS OF MEASURE (managed registry)
// --------------------------------------------------------------------------------
if (!function_exists('stores_uom_seed_defaults')) {
    /** Idempotently seed a sensible default UOM set for an org (first visit). */
    function stores_uom_seed_defaults($db, int $orgId): void
    {
        if ($orgId <= 0) { return; }
        try {
            if ((int) $db->count('stays_stock_uoms', ['org_id' => $orgId]) > 0) { return; }
            $defaults = [
                ['each', 'Each', 'ea'], ['kilogram', 'Kilogram', 'kg'], ['gram', 'Gram', 'g'],
                ['litre', 'Litre', 'L'], ['millilitre', 'Millilitre', 'ml'],
                ['box', 'Box', 'box'], ['case', 'Case', 'case'], ['bottle', 'Bottle', 'btl'],
            ];
            foreach ($defaults as $d) {
                $db->insert('stays_stock_uoms', [
                    'org_id' => $orgId, 'code' => $d[0], 'name' => $d[1], 'symbol' => $d[2],
                    'status' => 1, 'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) { error_log('stores_uom_seed_defaults: ' . $e->getMessage()); }
    }
}

if (!function_exists('stores_uom_create')) {
    function stores_uom_create($db, int $orgId, array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        $code = strtolower(trim((string) ($d['code'] ?? $name)));
        $code = preg_replace('/[^a-z0-9_]+/', '_', $code);
        if ($orgId <= 0 || $name === '' || $code === '') { return 0; }
        try {
            if ($db->has('stays_stock_uoms', ['org_id' => $orgId, 'code' => $code])) { return 0; }
            $db->insert('stays_stock_uoms', [
                'org_id' => $orgId, 'code' => substr($code, 0, 40), 'name' => substr($name, 0, 80),
                'symbol' => substr(trim((string) ($d['symbol'] ?? '')), 0, 16) ?: null,
                'status' => 1, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('stores_uom_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('stores_uom_list')) {
    function stores_uom_list($db, int $orgId): array
    {
        if ($orgId <= 0) { return []; }
        try {
            return $db->select('stays_stock_uoms', ['id', 'code', 'name', 'symbol', 'status'],
                ['org_id' => $orgId, 'ORDER' => ['name' => 'ASC']]) ?: [];
        } catch (\Throwable $e) { error_log('stores_uom_list: ' . $e->getMessage()); return []; }
    }
}

// --------------------------------------------------------------------------------
// ITEM CATEGORIES
// --------------------------------------------------------------------------------
if (!function_exists('stores_category_create')) {
    function stores_category_create($db, int $orgId, array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($orgId <= 0 || $name === '') { return 0; }
        try {
            if ($db->has('stays_stock_categories', ['org_id' => $orgId, 'name' => substr($name, 0, 120)])) { return 0; }
            $db->insert('stays_stock_categories', [
                'org_id' => $orgId, 'name' => substr($name, 0, 120),
                'notes' => substr(trim((string) ($d['notes'] ?? '')), 0, 255) ?: null,
                'status' => 1, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('stores_category_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('stores_category_list')) {
    function stores_category_list($db, int $orgId): array
    {
        if ($orgId <= 0) { return []; }
        try {
            return $db->select('stays_stock_categories', ['id', 'name', 'notes', 'status'],
                ['org_id' => $orgId, 'ORDER' => ['name' => 'ASC']]) ?: [];
        } catch (\Throwable $e) { error_log('stores_category_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('stores_item_set_category')) {
    /**
     * Tag an existing base stock item (stays_stock_items) with a category. Writes the
     * stays_stock_items.category_id column (additive — see schema ALTER). Both the item
     * and the category must belong to the org. Returns bool.
     */
    function stores_item_set_category($db, int $orgId, int $itemId, int $categoryId): bool
    {
        if ($orgId <= 0 || $itemId <= 0) { return false; }
        try {
            if (!$db->has('stays_stock_items', ['id' => $itemId, 'org_id' => $orgId])) { return false; }
            if ($categoryId > 0 && !$db->has('stays_stock_categories', ['id' => $categoryId, 'org_id' => $orgId])) { return false; }
            $db->update('stays_stock_items',
                ['category_id' => $categoryId > 0 ? $categoryId : null, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $itemId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('stores_item_set_category: ' . $e->getMessage()); return false; }
    }
}

// --------------------------------------------------------------------------------
// STORES (locations) + per-store on-hand with weighted-average valuation
// --------------------------------------------------------------------------------
if (!function_exists('stores_store_create')) {
    function stores_store_create($db, int $orgId, array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($orgId <= 0 || $name === '') { return 0; }
        try {
            $isDefault = !empty($d['is_default']) ? 1 : 0;
            if ($isDefault) { $db->update('stays_stores', ['is_default' => 0], ['org_id' => $orgId]); }
            $db->insert('stays_stores', [
                'org_id' => $orgId, 'name' => substr($name, 0, 120),
                'location' => substr(trim((string) ($d['location'] ?? '')), 0, 191) ?: null,
                'is_default' => $isDefault, 'status' => 1, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('stores_store_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('stores_store_list')) {
    function stores_store_list($db, int $orgId): array
    {
        if ($orgId <= 0) { return []; }
        try {
            return $db->select('stays_stores', ['id', 'name', 'location', 'is_default', 'status'],
                ['org_id' => $orgId, 'status' => 1, 'ORDER' => ['is_default' => 'DESC', 'name' => 'ASC']]) ?: [];
        } catch (\Throwable $e) { error_log('stores_store_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('stores_receive_into')) {
    /**
     * Receive (or open-balance) a quantity of an item INTO a store at a known unit cost,
     * updating the per-store on-hand AND the store's WEIGHTED-AVERAGE moving cost:
     *     new_avg = (old_qty*old_avg + recv_qty*recv_cost) / (old_qty + recv_qty)
     * This is the valuation engine; it records cost, it does NOT post GL (the purchase's
     * expense/AP posting lives in the base GRN). Use this for opening stock + optional
     * per-store receipts. Atomic with FOR UPDATE. Returns ['ok','on_hand','avg_cost','message'].
     *
     * $unitCost is server-trusted as the receipt cost for THIS lot only; it never leaves
     * the ledger side and is only used to roll the moving average.
     */
    function stores_receive_into($db, int $orgId, int $storeId, int $itemId, float $qty, float $unitCost, string $reason = 'opening'): array
    {
        if ($orgId <= 0 || $storeId <= 0 || $itemId <= 0) { return ['ok' => false, 'message' => 'Bad input']; }
        $qty = round($qty, 3); $unitCost = round(max(0.0, $unitCost), 4);
        if ($qty <= 0) { return ['ok' => false, 'message' => 'Enter a positive quantity.']; }
        $result = ['ok' => false, 'message' => 'Receive failed'];
        try {
            $db->action(function ($db) use ($orgId, $storeId, $itemId, $qty, $unitCost, $reason, &$result) {
                if (!$db->has('stays_stores', ['id' => $storeId, 'org_id' => $orgId])
                    || !$db->has('stays_stock_items', ['id' => $itemId, 'org_id' => $orgId])) {
                    $result = ['ok' => false, 'message' => 'Unknown store or item.']; return false;
                }
                $locked = $db->query('SELECT id, on_hand, avg_cost FROM stays_store_stock
                    WHERE org_id=:o AND store_id=:s AND item_id=:i FOR UPDATE',
                    [':o' => $orgId, ':s' => $storeId, ':i' => $itemId]);
                $row = $locked ? $locked->fetch(\PDO::FETCH_ASSOC) : null;
                $oldQty = $row ? (float) $row['on_hand'] : 0.0;
                $oldAvg = $row ? (float) $row['avg_cost'] : 0.0;
                $newQty = round($oldQty + $qty, 3);
                $newAvg = $newQty > 0 ? round((($oldQty * $oldAvg) + ($qty * $unitCost)) / $newQty, 4) : 0.0;
                if ($row) {
                    $db->update('stays_store_stock',
                        ['on_hand' => $newQty, 'avg_cost' => $newAvg, 'updated_at' => date('Y-m-d H:i:s')],
                        ['id' => (int) $row['id']]);
                } else {
                    $db->insert('stays_store_stock', [
                        'org_id' => $orgId, 'store_id' => $storeId, 'item_id' => $itemId,
                        'on_hand' => $newQty, 'avg_cost' => $newAvg,
                        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
                $result = ['ok' => true, 'on_hand' => $newQty, 'avg_cost' => $newAvg,
                    'message' => 'Received into store — on hand ' . rtrim(rtrim(number_format($newQty, 3), '0'), '.') . ' @ avg ' . number_format($newAvg, 2) . '.'];
                return true;
            });
        } catch (\Throwable $e) { error_log('stores_receive_into: ' . $e->getMessage()); return ['ok' => false, 'message' => 'Receive error']; }
        return $result;
    }
}

if (!function_exists('stores_stock_matrix')) {
    /**
     * Per-store on-hand + valuation for every item in the org. Returns
     * ['rows'=>[['item_id','name','unit','store_id','store','on_hand','avg_cost','value']...],
     *  'total_value'=>float]. value = on_hand x avg_cost (server valuation, weighted-avg).
     */
    function stores_stock_matrix($db, int $orgId): array
    {
        $out = ['rows' => [], 'total_value' => 0.0];
        if ($orgId <= 0) { return $out; }
        try {
            $rows = $db->query('SELECT ss.item_id, ss.store_id, ss.on_hand, ss.avg_cost,
                    i.name AS item_name, i.unit AS unit, st.name AS store_name
                 FROM stays_store_stock ss
                 JOIN stays_stock_items i ON i.id = ss.item_id
                 JOIN stays_stores st ON st.id = ss.store_id
                 WHERE ss.org_id = ' . (int) $orgId . '
                 ORDER BY i.name ASC, st.name ASC')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $value = round((float) $r['on_hand'] * (float) $r['avg_cost'], 2);
                $out['rows'][] = [
                    'item_id' => (int) $r['item_id'], 'name' => (string) $r['item_name'],
                    'unit' => (string) $r['unit'], 'store_id' => (int) $r['store_id'],
                    'store' => (string) $r['store_name'], 'on_hand' => (float) $r['on_hand'],
                    'avg_cost' => (float) $r['avg_cost'], 'value' => $value,
                ];
                $out['total_value'] += $value;
            }
            $out['total_value'] = round($out['total_value'], 2);
        } catch (\Throwable $e) { error_log('stores_stock_matrix: ' . $e->getMessage()); }
        return $out;
    }
}

// --------------------------------------------------------------------------------
// INTER-STORE TRANSFERS (atomic, stock-neutral, no GL)
// --------------------------------------------------------------------------------
if (!function_exists('stores_transfer')) {
    /**
     * Move $qty of an item FROM one store TO another in a single transaction. Stock-neutral
     * (total org on-hand unchanged) so it posts NO GL. The moving-average cost of the moved
     * quantity (the FROM store's avg) travels with it and rolls into the TO store's average.
     * FOR UPDATE on both store-stock rows (lock in a stable order to avoid deadlock). The
     * FROM store must have enough on-hand. Returns ['ok','message'].
     */
    function stores_transfer($db, int $orgId, int $fromStore, int $toStore, int $itemId, float $qty, string $note = ''): array
    {
        if ($orgId <= 0 || $itemId <= 0) { return ['ok' => false, 'message' => 'Bad input']; }
        if ($fromStore <= 0 || $toStore <= 0 || $fromStore === $toStore) { return ['ok' => false, 'message' => 'Pick two different stores.']; }
        $qty = round($qty, 3);
        if ($qty <= 0) { return ['ok' => false, 'message' => 'Enter a positive quantity.']; }
        $result = ['ok' => false, 'message' => 'Transfer failed'];
        try {
            $db->action(function ($db) use ($orgId, $fromStore, $toStore, $itemId, $qty, $note, &$result) {
                foreach ([$fromStore, $toStore] as $sid) {
                    if (!$db->has('stays_stores', ['id' => $sid, 'org_id' => $orgId])) {
                        $result = ['ok' => false, 'message' => 'Unknown store.']; return false;
                    }
                }
                if (!$db->has('stays_stock_items', ['id' => $itemId, 'org_id' => $orgId])) {
                    $result = ['ok' => false, 'message' => 'Unknown item.']; return false;
                }
                // Lock both rows in ascending store id order (deadlock-safe).
                $ids = [$fromStore, $toStore]; sort($ids);
                $locked = [];
                foreach ($ids as $sid) {
                    $q = $db->query('SELECT id, store_id, on_hand, avg_cost FROM stays_store_stock
                        WHERE org_id=:o AND store_id=:s AND item_id=:i FOR UPDATE',
                        [':o' => $orgId, ':s' => $sid, ':i' => $itemId]);
                    $locked[$sid] = $q ? $q->fetch(\PDO::FETCH_ASSOC) : null;
                }
                $src = $locked[$fromStore] ?? null;
                if (!$src || (float) $src['on_hand'] + 1e-9 < $qty) {
                    $result = ['ok' => false, 'message' => 'Not enough stock in the source store.']; return false;
                }
                $movedAvg = (float) $src['avg_cost'];
                // Decrement source.
                $db->update('stays_store_stock',
                    ['on_hand' => round((float) $src['on_hand'] - $qty, 3), 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => (int) $src['id']]);
                // Increment destination, rolling the moved cost into its average.
                $dst = $locked[$toStore] ?? null;
                if ($dst) {
                    $oldQty = (float) $dst['on_hand']; $oldAvg = (float) $dst['avg_cost'];
                    $newQty = round($oldQty + $qty, 3);
                    $newAvg = $newQty > 0 ? round((($oldQty * $oldAvg) + ($qty * $movedAvg)) / $newQty, 4) : 0.0;
                    $db->update('stays_store_stock',
                        ['on_hand' => $newQty, 'avg_cost' => $newAvg, 'updated_at' => date('Y-m-d H:i:s')],
                        ['id' => (int) $dst['id']]);
                } else {
                    $db->insert('stays_store_stock', [
                        'org_id' => $orgId, 'store_id' => $toStore, 'item_id' => $itemId,
                        'on_hand' => $qty, 'avg_cost' => round($movedAvg, 4),
                        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
                $ref = 'TRF-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
                $db->insert('stays_store_transfers', [
                    'org_id' => $orgId, 'reference' => $ref, 'from_store' => $fromStore, 'to_store' => $toStore,
                    'item_id' => $itemId, 'qty' => $qty, 'unit_cost' => round($movedAvg, 4),
                    'note' => substr(trim($note), 0, 255) ?: null,
                    'actor' => (string) ($_SESSION['user_id'] ?? '') ?: null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $result = ['ok' => true, 'message' => 'Transferred ' . rtrim(rtrim(number_format($qty, 3), '0'), '.') . ' unit(s).'];
                return true;
            });
        } catch (\Throwable $e) { error_log('stores_transfer: ' . $e->getMessage()); return ['ok' => false, 'message' => 'Transfer error']; }
        return $result;
    }
}

if (!function_exists('stores_transfer_list')) {
    function stores_transfer_list($db, int $orgId, int $limit = 50): array
    {
        if ($orgId <= 0) { return []; }
        try {
            return $db->query('SELECT t.reference, t.qty, t.unit_cost, t.note, t.created_at,
                    i.name AS item_name, f.name AS from_name, d.name AS to_name
                 FROM stays_store_transfers t
                 JOIN stays_stock_items i ON i.id = t.item_id
                 LEFT JOIN stays_stores f ON f.id = t.from_store
                 LEFT JOIN stays_stores d ON d.id = t.to_store
                 WHERE t.org_id = ' . (int) $orgId . '
                 ORDER BY t.id DESC LIMIT ' . max(1, min(200, $limit)))->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { error_log('stores_transfer_list: ' . $e->getMessage()); return []; }
    }
}

// --------------------------------------------------------------------------------
// BATCH / LOT + EXPIRY
// --------------------------------------------------------------------------------
if (!function_exists('stores_batch_create')) {
    /**
     * Register a batch/lot of an item in a store with an expiry date. Records the lot and
     * its received qty; this is the batch register (tracking/visibility), kept separate
     * from the per-store on-hand roll-up so existing stock maths is unaffected. Returns id.
     */
    function stores_batch_create($db, int $orgId, array $d): int
    {
        $storeId = (int) ($d['store_id'] ?? 0); $itemId = (int) ($d['item_id'] ?? 0);
        $lot = trim((string) ($d['lot_no'] ?? '')); $qty = round((float) ($d['qty'] ?? 0), 3);
        if ($orgId <= 0 || $storeId <= 0 || $itemId <= 0 || $lot === '' || $qty <= 0) { return 0; }
        $expiry = trim((string) ($d['expiry_date'] ?? ''));
        if ($expiry !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) { $expiry = ''; }
        try {
            if (!$db->has('stays_stores', ['id' => $storeId, 'org_id' => $orgId])
                || !$db->has('stays_stock_items', ['id' => $itemId, 'org_id' => $orgId])) { return 0; }
            $db->insert('stays_stock_batches', [
                'org_id' => $orgId, 'store_id' => $storeId, 'item_id' => $itemId,
                'lot_no' => substr($lot, 0, 80), 'qty' => $qty, 'qty_remaining' => $qty,
                'expiry_date' => $expiry ?: null, 'status' => 1, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('stores_batch_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('stores_expiring_batches')) {
    /** Batches expiring within $days (default 30) OR already expired. Read-only alert feed. */
    function stores_expiring_batches($db, int $orgId, int $days = 30): array
    {
        if ($orgId <= 0) { return []; }
        $cut = date('Y-m-d', strtotime('+' . max(0, $days) . ' days'));
        try {
            return $db->query('SELECT b.id, b.lot_no, b.qty_remaining, b.expiry_date,
                    i.name AS item_name, st.name AS store_name
                 FROM stays_stock_batches b
                 JOIN stays_stock_items i ON i.id = b.item_id
                 JOIN stays_stores st ON st.id = b.store_id
                 WHERE b.org_id = ' . (int) $orgId . ' AND b.status = 1 AND b.qty_remaining > 0
                   AND b.expiry_date IS NOT NULL AND b.expiry_date <= ' . $db->quote($cut) . '
                 ORDER BY b.expiry_date ASC')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { error_log('stores_expiring_batches: ' . $e->getMessage()); return []; }
    }
}

// --------------------------------------------------------------------------------
// PHYSICAL COUNT -> VARIANCE (per store)
// --------------------------------------------------------------------------------
if (!function_exists('stores_record_count')) {
    /**
     * Record a physical count for an item in a store and SET its per-store on-hand to the
     * counted figure, logging the variance (counted - system). Atomic + FOR UPDATE. The
     * variance x avg_cost is the valuation impact (stored for the report). Returns
     * ['ok','variance','message'].
     */
    function stores_record_count($db, int $orgId, int $storeId, int $itemId, float $counted, string $note = ''): array
    {
        if ($orgId <= 0 || $storeId <= 0 || $itemId <= 0) { return ['ok' => false, 'message' => 'Bad input']; }
        $counted = round(max(0.0, $counted), 3);
        $result = ['ok' => false, 'message' => 'Count failed'];
        try {
            $db->action(function ($db) use ($orgId, $storeId, $itemId, $counted, $note, &$result) {
                if (!$db->has('stays_stores', ['id' => $storeId, 'org_id' => $orgId])
                    || !$db->has('stays_stock_items', ['id' => $itemId, 'org_id' => $orgId])) {
                    $result = ['ok' => false, 'message' => 'Unknown store or item.']; return false;
                }
                $q = $db->query('SELECT id, on_hand, avg_cost FROM stays_store_stock
                    WHERE org_id=:o AND store_id=:s AND item_id=:i FOR UPDATE',
                    [':o' => $orgId, ':s' => $storeId, ':i' => $itemId]);
                $row = $q ? $q->fetch(\PDO::FETCH_ASSOC) : null;
                $system = $row ? (float) $row['on_hand'] : 0.0;
                $avg = $row ? (float) $row['avg_cost'] : 0.0;
                $variance = round($counted - $system, 3);
                if ($row) {
                    $db->update('stays_store_stock',
                        ['on_hand' => $counted, 'updated_at' => date('Y-m-d H:i:s')],
                        ['id' => (int) $row['id']]);
                } else {
                    $db->insert('stays_store_stock', [
                        'org_id' => $orgId, 'store_id' => $storeId, 'item_id' => $itemId,
                        'on_hand' => $counted, 'avg_cost' => 0,
                        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
                $db->insert('stays_store_counts', [
                    'org_id' => $orgId, 'store_id' => $storeId, 'item_id' => $itemId,
                    'system_qty' => $system, 'counted_qty' => $counted, 'variance' => $variance,
                    'avg_cost' => round($avg, 4), 'value_impact' => round($variance * $avg, 2),
                    'note' => substr(trim($note), 0, 255) ?: null,
                    'actor' => (string) ($_SESSION['user_id'] ?? '') ?: null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $result = ['ok' => true, 'variance' => $variance,
                    'message' => 'Count recorded — variance ' . ($variance >= 0 ? '+' : '') . rtrim(rtrim(number_format($variance, 3), '0'), '.') . '.'];
                return true;
            });
        } catch (\Throwable $e) { error_log('stores_record_count: ' . $e->getMessage()); return ['ok' => false, 'message' => 'Count error']; }
        return $result;
    }
}

if (!function_exists('stores_variance_report')) {
    /** Recent physical-count variances (count vs system). Read-only. */
    function stores_variance_report($db, int $orgId, int $limit = 50): array
    {
        if ($orgId <= 0) { return []; }
        try {
            return $db->query('SELECT c.system_qty, c.counted_qty, c.variance, c.value_impact, c.note, c.created_at,
                    i.name AS item_name, st.name AS store_name
                 FROM stays_store_counts c
                 JOIN stays_stock_items i ON i.id = c.item_id
                 JOIN stays_stores st ON st.id = c.store_id
                 WHERE c.org_id = ' . (int) $orgId . '
                 ORDER BY c.id DESC LIMIT ' . max(1, min(200, $limit)))->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { error_log('stores_variance_report: ' . $e->getMessage()); return []; }
    }
}

// --------------------------------------------------------------------------------
// BARCODE / QR SCANNING — DEVICE EDGE (honest stub, never faked)
// --------------------------------------------------------------------------------
if (!function_exists('stores_barcode_status')) {
    /**
     * Barcode/QR scanning integration status. There is NO scanner hardware/bridge wired
     * up in this build, so this always reports 'not connected'. The internal data model
     * supports it (stays_stock_items.barcode column — see schema ALTER): once a scanner
     * bridge or a camera-decode front-end posts a scanned code, look the item up by
     * barcode and reuse the existing receive/transfer/count flows. Returns a status array
     * the view renders honestly — it does NOT pretend to scan. DB-GATED / DEVICE-GATED.
     */
    function stores_barcode_status($db, int $orgId): array
    {
        return [
            'connected' => false,
            'provider'  => null,
            'message'   => 'Barcode/QR scanning is not connected. Item barcodes can be stored '
                         . 'now; wiring a scanner bridge or camera decoder is a later integration.',
        ];
    }
}

if (!function_exists('stores_item_lookup_by_barcode')) {
    /**
     * Resolve a scanned barcode to an org stock item (the lookup a scanner front-end would
     * call). Real + usable today even without a scanner: returns the item row or null.
     */
    function stores_item_lookup_by_barcode($db, int $orgId, string $code): ?array
    {
        $code = trim($code);
        if ($orgId <= 0 || $code === '') { return null; }
        try {
            $row = $db->get('stays_stock_items', ['id', 'name', 'unit', 'on_hand'],
                ['org_id' => $orgId, 'barcode' => substr($code, 0, 64)]);
            return $row ?: null;
        } catch (\Throwable $e) { error_log('stores_item_lookup_by_barcode: ' . $e->getMessage()); return null; }
    }
}

if (!function_exists('stores_item_set_barcode')) {
    /** Store a barcode on a base stock item (additive column). Org-scoped. Returns bool. */
    function stores_item_set_barcode($db, int $orgId, int $itemId, string $code): bool
    {
        if ($orgId <= 0 || $itemId <= 0) { return false; }
        $code = trim($code);
        try {
            if (!$db->has('stays_stock_items', ['id' => $itemId, 'org_id' => $orgId])) { return false; }
            $db->update('stays_stock_items',
                ['barcode' => $code !== '' ? substr($code, 0, 64) : null, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $itemId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('stores_item_set_barcode: ' . $e->getMessage()); return false; }
    }
}

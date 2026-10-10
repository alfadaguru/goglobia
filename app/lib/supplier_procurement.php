<?php
// FILE: app/lib/supplier_procurement.php
// PROCUREMENT + STORES (Phase 1 inc S32; docs 01b modules 41/42). Per org.
//
// vendor directory → purchase orders (lines) → goods-receipt (GRN) → stock on-hand.
// Receiving a PO (GRN) increments each stock item's on_hand AND posts ONE balanced GL
// entry: DR Operating Expense (6000) / CR Accounts Payable (2000) for the PO total
// (the property now owes the vendor; cost is recognised). Idempotent: a PO moves
// ordered→received exactly once (state guard), so stock + GL only move once.
//
// Scope: records + stock + the GL posting. It does NOT pay the vendor (settling AP is
// later finance work). Amounts are server-computed from PO line unit costs. All
// functions non-fatal; org ownership enforced by the caller routes.

if (!function_exists('proc_vendor_create')) {
    function proc_vendor_create($db, int $orgId, array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($orgId <= 0 || $name === '') { return 0; }
        try {
            $db->insert('stays_vendors', [
                'org_id' => $orgId, 'name' => substr($name, 0, 191),
                'email' => trim((string) ($d['email'] ?? '')) ?: null,
                'phone' => trim((string) ($d['phone'] ?? '')) ?: null,
                'bank_code' => trim((string) ($d['bank_code'] ?? '')) ?: null,
                'account_number' => trim((string) ($d['account_number'] ?? '')) ?: null,
                'status' => 1, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('proc_vendor_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('proc_item_create')) {
    function proc_item_create($db, int $orgId, array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($orgId <= 0 || $name === '') { return 0; }
        try {
            $db->insert('stays_stock_items', [
                'org_id' => $orgId, 'name' => substr($name, 0, 191),
                'unit' => trim((string) ($d['unit'] ?? '')) ?: 'unit',
                'on_hand' => max(0.0, round((float) ($d['on_hand'] ?? 0), 3)),
                'reorder_level' => max(0.0, round((float) ($d['reorder_level'] ?? 0), 3)),
                'status' => 1, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('proc_item_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('proc_po_total')) {
    /** Server-side PO total from its line unit costs × qty. */
    function proc_po_total($db, int $poId): float
    {
        try {
            $rows = $db->select('stays_po_lines', ['qty', 'unit_cost'], ['po_id' => $poId]) ?: [];
            $t = 0.0; foreach ($rows as $r) { $t += ((float) $r['qty']) * (float) $r['unit_cost']; }
            return round($t, 2);
        } catch (\Throwable $e) { error_log('proc_po_total: ' . $e->getMessage()); return 0.0; }
    }
}

if (!function_exists('proc_po_create')) {
    /** Open a draft PO to a vendor (validated owned). Returns po id. */
    function proc_po_create($db, int $orgId, int $vendorId, string $currency = 'USD'): int
    {
        if ($orgId <= 0 || $vendorId <= 0) { return 0; }
        try {
            if (!$db->has('stays_vendors', ['id' => $vendorId, 'org_id' => $orgId])) { return 0; }
            $ref = 'PO-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            $db->insert('stays_purchase_orders', [
                'org_id' => $orgId, 'vendor_id' => $vendorId, 'reference' => $ref,
                'currency' => strtoupper(substr($currency, 0, 3)), 'status' => 'draft',
                'total' => 0, 'created_by' => (string) ($_SESSION['user_id'] ?? ''), 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('proc_po_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('proc_po_add_line')) {
    /** Add a line to a DRAFT PO (item must belong to the org). Recomputes PO total. */
    function proc_po_add_line($db, int $orgId, int $poId, int $itemId, float $qty, float $unitCost): bool
    {
        if ($orgId <= 0 || $poId <= 0 || $itemId <= 0) { return false; }
        $qty = round($qty, 3); $unitCost = round($unitCost, 2);
        if ($qty <= 0 || $unitCost < 0) { return false; }
        try {
            $po = $db->get('stays_purchase_orders', ['id', 'status'], ['id' => $poId, 'org_id' => $orgId]);
            if (!$po || $po['status'] !== 'draft') { return false; }
            $item = $db->get('stays_stock_items', ['id', 'name'], ['id' => $itemId, 'org_id' => $orgId]);
            if (!$item) { return false; }
            $db->insert('stays_po_lines', [
                'po_id' => $poId, 'item_id' => $itemId, 'name' => substr((string) $item['name'], 0, 191),
                'qty' => $qty, 'unit_cost' => $unitCost, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            $db->update('stays_purchase_orders', ['total' => proc_po_total($db, $poId), 'updated_at' => date('Y-m-d H:i:s')], ['id' => $poId]);
            return true;
        } catch (\Throwable $e) { error_log('proc_po_add_line: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('proc_po_order')) {
    /** Move a draft PO (with ≥1 line) to 'ordered'. Returns bool. */
    function proc_po_order($db, int $orgId, int $poId): bool
    {
        try {
            $po = $db->get('stays_purchase_orders', ['id', 'status'], ['id' => $poId, 'org_id' => $orgId]);
            if (!$po || $po['status'] !== 'draft') { return false; }
            if ((int) $db->count('stays_po_lines', ['po_id' => $poId]) < 1) { return false; }
            $db->update('stays_purchase_orders',
                ['status' => 'ordered', 'total' => proc_po_total($db, $poId), 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $poId, 'status' => 'draft']);
            return true;
        } catch (\Throwable $e) { error_log('proc_po_order: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('proc_po_receive')) {
    /**
     * GOODS RECEIPT (GRN): receive an 'ordered' PO. Idempotently (status guard) —
     *   1) increment each stock item's on_hand by the line qty,
     *   2) post a balanced GL entry DR 6000 (expense) / CR 2000 (AP) for the total,
     *   3) mark the PO 'received'.
     * All in one transaction. Returns ['ok','total','message'].
     */
    function proc_po_receive($db, int $orgId, int $poId): array
    {
        $po = $db->get('stays_purchase_orders', '*', ['id' => $poId, 'org_id' => $orgId]);
        if (!$po) { return ['ok' => false, 'message' => 'PO not found']; }
        if ($po['status'] !== 'ordered') { return ['ok' => false, 'message' => 'PO is not in an ordered state (' . $po['status'] . ').']; }
        $total = proc_po_total($db, $poId);
        $currency = (string) $po['currency'];

        $result = ['ok' => false, 'message' => 'Receive failed'];
        try {
            $db->action(function ($db) use ($orgId, $poId, $po, $total, $currency, &$result) {
                // Re-lock + re-check status (idempotency under concurrency).
                $lock = $db->query('SELECT status FROM stays_purchase_orders WHERE id=:p FOR UPDATE', [':p' => $poId]);
                $cur = $lock ? $lock->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$cur || $cur['status'] !== 'ordered') { $result = ['ok' => false, 'message' => 'Already received']; return false; }

                // 1) Increment stock on_hand per line.
                foreach ($db->select('stays_po_lines', ['item_id', 'qty'], ['po_id' => $poId]) ?: [] as $ln) {
                    $itemId = (int) $ln['item_id']; $qty = (float) $ln['qty'];
                    if ($itemId <= 0 || $qty <= 0) { continue; }
                    $locked = $db->query('SELECT on_hand FROM stays_stock_items WHERE id=:i AND org_id=:o FOR UPDATE',
                        [':i' => $itemId, ':o' => $orgId]);
                    $it = $locked ? $locked->fetch(\PDO::FETCH_ASSOC) : null;
                    if ($it) {
                        $db->update('stays_stock_items',
                            ['on_hand' => round(((float) $it['on_hand']) + $qty, 3), 'updated_at' => date('Y-m-d H:i:s')],
                            ['id' => $itemId, 'org_id' => $orgId]);
                    }
                }

                // 2) Post the balanced GL entry (DR expense / CR AP) for the total.
                if ($total > 0 && function_exists('gl_post')) {
                    $gl = gl_post($db, $orgId, [
                        ['code' => '6000', 'debit' => $total],   // Operating expense (cost recognised)
                        ['code' => '2000', 'credit' => $total],  // Accounts payable (owed to vendor)
                    ], ['source' => 'grn', 'reference' => (string) $po['reference'], 'currency' => $currency,
                        'memo' => 'Goods received ' . $po['reference']]);
                    if (empty($gl['ok'])) { error_log('proc_po_receive GL: ' . ($gl['message'] ?? '?')); }
                }

                // 3) Mark received.
                $db->update('stays_purchase_orders',
                    ['status' => 'received', 'received_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => $poId, 'status' => 'ordered']);
                $result = ['ok' => true, 'total' => $total, 'message' => 'Goods received; stock updated.'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('proc_po_receive: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Receive error'];
        }
        return $result;
    }
}

if (!function_exists('proc_low_stock')) {
    /** Items at or below reorder level (org). Read-only. */
    function proc_low_stock($db, int $orgId): array
    {
        if ($orgId <= 0) { return []; }
        try {
            return $db->query(
                'SELECT id, name, unit, on_hand, reorder_level FROM stays_stock_items
                 WHERE org_id = ' . (int) $orgId . ' AND status = 1 AND reorder_level > 0 AND on_hand <= reorder_level
                 ORDER BY name ASC'
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { error_log('proc_low_stock: ' . $e->getMessage()); return []; }
    }
}

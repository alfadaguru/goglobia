<?php
// FILE: app/lib/supplier_procurement_ext.php
// PROCUREMENT — EXTENDED (docs 01b module 41). Builds the genuine gaps ON TOP of
// supplier_procurement.php (vendor/item/PO/GRN/stock). This file adds:
//
//   1. Purchase REQUISITIONS + an approval gate (requested → approved/rejected →
//      converted to a draft PO). Internal "ask to buy" step before a PO exists.
//   2. RFQ (request-for-quote) + supplier QUOTES + a side-by-side compare, with
//      "award → draft PO" from the chosen quote.
//   3. PARTIAL deliveries: a GRN model that receives *some* qty against a PO line,
//      tracking received_qty per line and flipping the PO ordered→partial→received.
//      Each GRN posts its OWN balanced GL entry for the value received (never the
//      whole PO twice).
//   4. RETURNS to vendor (debit note): reverse a received quantity — stock out + a
//      balanced reversing GL entry (DR AP / CR expense) for the returned value.
//   5. THREE-WAY MATCH: reconcile PO ↔ received (GRN) ↔ vendor invoice amount, with
//      a tolerance, surfacing over/under-billing before AP is settled.
//   6. Vendor CONTRACTS: a term/price-agreement record with start/end + optional
//      spend cap; a read helper that flags expiring/over-cap contracts.
//   7. BUDGET control: per-period procurement budget + a spend check that a new PO/
//      requisition can consult (soft gate — advisory; the owner may still proceed).
//
// RULES honoured:
//   • Every function if_not_exists-guarded. Non-fatal (try/catch + error_log).
//   • Org ownership enforced by the caller routes (SUPPLIER_OR_STAFF_AUTH + supplier_can);
//     these helpers additionally re-scope every read/write by org_id.
//   • MONEY: amounts are ALWAYS server-computed from stored line costs — client amounts
//     are never trusted. GL is posted via gl_post (balanced, idempotent on source+ref).
//     Multi-step money writes run inside $db->action() with FOR UPDATE on the PO row.
//   • No fake integrations. RFQ "send to vendor" is an INTERNAL record only + an honest
//     stub (proc_ext_rfq_dispatch) that reports 'not connected' — no email/EDI is faked.
//
// All new tables are DB-GATED (see schema_sql in the patch spec). Each function that
// touches a new table tolerates its absence (try/catch → safe empty/false).

// ---------------------------------------------------------------------------
// Small shared helpers
// ---------------------------------------------------------------------------

if (!function_exists('proc_ext_actor')) {
    /** Current acting user_id string (for audit columns). */
    function proc_ext_actor(): string
    {
        return (string) ($_SESSION['user_id'] ?? '');
    }
}

if (!function_exists('proc_ext_ref')) {
    /** A short unique-ish reference like RFQ-20261011-AB12C. */
    function proc_ext_ref(string $prefix): string
    {
        try { $r = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)); }
        catch (\Throwable $e) { $r = strtoupper(substr(md5((string) microtime(true)), 0, 5)); }
        return $prefix . '-' . date('Ymd') . '-' . $r;
    }
}

if (!function_exists('proc_ext_money')) {
    /** Round to money (2dp). */
    function proc_ext_money($n): float { return round((float) $n, 2); }
}

// ===========================================================================
// 1) PURCHASE REQUISITIONS + APPROVAL
// ===========================================================================

if (!function_exists('proc_req_statuses')) {
    function proc_req_statuses(): array
    {
        return ['requested' => 'Requested', 'approved' => 'Approved', 'rejected' => 'Rejected', 'converted' => 'Converted to PO'];
    }
}

if (!function_exists('proc_req_create')) {
    /**
     * Raise a purchase requisition (internal "ask to buy"). $d = [title, justification,
     * estimated_cost, currency, vendor_id?]. estimated_cost is advisory only (it never
     * posts to the GL). Returns the requisition id (0 on failure).
     */
    function proc_req_create($db, int $orgId, array $d): int
    {
        $title = trim((string) ($d['title'] ?? ''));
        if ($orgId <= 0 || $title === '') { return 0; }
        $vendorId = (int) ($d['vendor_id'] ?? 0);
        try {
            if ($vendorId > 0 && !$db->has('stays_vendors', ['id' => $vendorId, 'org_id' => $orgId])) { $vendorId = 0; }
            $db->insert('stays_proc_requisitions', [
                'org_id'         => $orgId,
                'reference'      => proc_ext_ref('REQ'),
                'title'          => substr($title, 0, 191),
                'justification'  => substr(trim((string) ($d['justification'] ?? '')), 0, 1000) ?: null,
                'estimated_cost' => max(0.0, proc_ext_money($d['estimated_cost'] ?? 0)),
                'currency'       => strtoupper(substr((string) ($d['currency'] ?? 'USD'), 0, 3)),
                'vendor_id'      => $vendorId ?: null,
                'status'         => 'requested',
                'requested_by'   => proc_ext_actor(),
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('proc_req_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('proc_req_decide')) {
    /**
     * Approve or reject a REQUESTED requisition. $decision ∈ {approved,rejected}.
     * State-guarded (only from 'requested'). Records the deciding actor + note.
     * Returns bool. Approval here is the APPROVAL GATE the module brief calls for —
     * a PO can only be converted from an APPROVED requisition (see proc_req_to_po).
     */
    function proc_req_decide($db, int $orgId, int $reqId, string $decision, string $note = ''): bool
    {
        if ($orgId <= 0 || $reqId <= 0) { return false; }
        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['approved', 'rejected'], true)) { return false; }
        try {
            $req = $db->get('stays_proc_requisitions', ['id', 'status'], ['id' => $reqId, 'org_id' => $orgId]);
            if (!$req || $req['status'] !== 'requested') { return false; }
            $upd = $db->update('stays_proc_requisitions', [
                'status'        => $decision,
                'decided_by'    => proc_ext_actor(),
                'decision_note' => substr(trim($note), 0, 500) ?: null,
                'decided_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ], ['id' => $reqId, 'org_id' => $orgId, 'status' => 'requested']);
            return $upd && $upd->rowCount() === 1;
        } catch (\Throwable $e) { error_log('proc_req_decide: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('proc_req_to_po')) {
    /**
     * Convert an APPROVED requisition into a fresh DRAFT PO (reusing the base
     * proc_po_create). A requisition WITHOUT a chosen vendor cannot be converted here
     * (a PO needs a vendor). Idempotent via status guard: marks the requisition
     * 'converted' + stores the new po_id. Returns the new po id (0 on failure).
     */
    function proc_req_to_po($db, int $orgId, int $reqId): int
    {
        if ($orgId <= 0 || $reqId <= 0 || !function_exists('proc_po_create')) { return 0; }
        try {
            $req = $db->get('stays_proc_requisitions', ['id', 'status', 'vendor_id', 'currency'], ['id' => $reqId, 'org_id' => $orgId]);
            if (!$req || $req['status'] !== 'approved') { return 0; }
            $vendorId = (int) ($req['vendor_id'] ?? 0);
            if ($vendorId <= 0) { return 0; }
            $poId = proc_po_create($db, $orgId, $vendorId, (string) ($req['currency'] ?? 'USD'));
            if ($poId <= 0) { return 0; }
            $db->update('stays_proc_requisitions', [
                'status' => 'converted', 'po_id' => $poId, 'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $reqId, 'org_id' => $orgId, 'status' => 'approved']);
            return $poId;
        } catch (\Throwable $e) { error_log('proc_req_to_po: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('proc_req_list')) {
    /** List requisitions (newest first). Read-only. */
    function proc_req_list($db, int $orgId, int $limit = 50): array
    {
        if ($orgId <= 0) { return []; }
        try {
            return $db->select('stays_proc_requisitions',
                ['id', 'reference', 'title', 'estimated_cost', 'currency', 'vendor_id', 'status', 'po_id', 'requested_by', 'created_at'],
                ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) { error_log('proc_req_list: ' . $e->getMessage()); return []; }
    }
}

// ===========================================================================
// 2) RFQ + QUOTES + COMPARE
// ===========================================================================

if (!function_exists('proc_rfq_create')) {
    /**
     * Open an RFQ (request for quotation) for an item + qty. Vendors you invite each
     * submit a quote (proc_quote_submit); compare them with proc_rfq_compare; award
     * the winner with proc_rfq_award. $d = [item_id, qty, needed_by?, notes?].
     * Returns rfq id (0 on failure).
     */
    function proc_rfq_create($db, int $orgId, array $d): int
    {
        $itemId = (int) ($d['item_id'] ?? 0);
        $qty = round((float) ($d['qty'] ?? 0), 3);
        if ($orgId <= 0 || $itemId <= 0 || $qty <= 0) { return 0; }
        try {
            $item = $db->get('stays_stock_items', ['id', 'name'], ['id' => $itemId, 'org_id' => $orgId]);
            if (!$item) { return 0; }
            $needed = trim((string) ($d['needed_by'] ?? ''));
            if ($needed !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $needed)) { $needed = ''; }
            $db->insert('stays_proc_rfqs', [
                'org_id'     => $orgId,
                'reference'  => proc_ext_ref('RFQ'),
                'item_id'    => $itemId,
                'item_name'  => substr((string) $item['name'], 0, 191),
                'qty'        => $qty,
                'needed_by'  => $needed ?: null,
                'notes'      => substr(trim((string) ($d['notes'] ?? '')), 0, 500) ?: null,
                'status'     => 'open',
                'created_by' => proc_ext_actor(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('proc_rfq_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('proc_quote_submit')) {
    /**
     * Record a vendor's quote against an OPEN RFQ. $unitPrice is per unit; the line
     * total is server-computed (rfq.qty × unit). lead_days/validity advisory. One row
     * per (rfq, vendor) — a resubmit updates the existing quote. Returns bool.
     */
    function proc_quote_submit($db, int $orgId, int $rfqId, int $vendorId, float $unitPrice, array $extra = []): bool
    {
        if ($orgId <= 0 || $rfqId <= 0 || $vendorId <= 0) { return false; }
        $unitPrice = proc_ext_money($unitPrice);
        if ($unitPrice < 0) { return false; }
        try {
            $rfq = $db->get('stays_proc_rfqs', ['id', 'qty', 'status'], ['id' => $rfqId, 'org_id' => $orgId]);
            if (!$rfq || $rfq['status'] !== 'open') { return false; }
            if (!$db->has('stays_vendors', ['id' => $vendorId, 'org_id' => $orgId])) { return false; }
            $lineTotal = proc_ext_money(((float) $rfq['qty']) * $unitPrice);
            $row = [
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'lead_days'  => max(0, (int) ($extra['lead_days'] ?? 0)),
                'notes'      => substr(trim((string) ($extra['notes'] ?? '')), 0, 300) ?: null,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $existing = $db->get('stays_proc_quotes', ['id'], ['rfq_id' => $rfqId, 'vendor_id' => $vendorId, 'org_id' => $orgId]);
            if ($existing) {
                $db->update('stays_proc_quotes', $row, ['id' => (int) $existing['id']]);
            } else {
                $db->insert('stays_proc_quotes', $row + [
                    'org_id' => $orgId, 'rfq_id' => $rfqId, 'vendor_id' => $vendorId,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
            return true;
        } catch (\Throwable $e) { error_log('proc_quote_submit: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('proc_rfq_compare')) {
    /**
     * Side-by-side quote comparison for an RFQ. Returns ['rfq'=>row,'quotes'=>[...]]
     * with each quote annotated with the vendor name + a 'is_lowest' flag on the
     * cheapest line_total. Read-only; cheapest first.
     */
    function proc_rfq_compare($db, int $orgId, int $rfqId): array
    {
        $out = ['rfq' => null, 'quotes' => []];
        if ($orgId <= 0 || $rfqId <= 0) { return $out; }
        try {
            $rfq = $db->get('stays_proc_rfqs', '*', ['id' => $rfqId, 'org_id' => $orgId]);
            if (!$rfq) { return $out; }
            $out['rfq'] = $rfq;
            $quotes = $db->select('stays_proc_quotes',
                ['id', 'vendor_id', 'unit_price', 'line_total', 'lead_days', 'notes'],
                ['rfq_id' => $rfqId, 'org_id' => $orgId, 'ORDER' => ['line_total' => 'ASC']]) ?: [];
            if (empty($quotes)) { return $out; }
            $names = [];
            foreach ($db->select('stays_vendors', ['id', 'name', 'verified'], ['org_id' => $orgId]) ?: [] as $v) {
                $names[(int) $v['id']] = ['name' => (string) $v['name'], 'verified' => !empty($v['verified'])];
            }
            $min = null;
            foreach ($quotes as $q) { $lt = (float) $q['line_total']; if ($min === null || $lt < $min) { $min = $lt; } }
            foreach ($quotes as &$q) {
                $vid = (int) $q['vendor_id'];
                $q['vendor_name'] = $names[$vid]['name'] ?? ('#' . $vid);
                $q['vendor_verified'] = $names[$vid]['verified'] ?? false;
                $q['is_lowest'] = ($min !== null && abs(((float) $q['line_total']) - $min) < 0.005);
            } unset($q);
            $out['quotes'] = $quotes;
        } catch (\Throwable $e) { error_log('proc_rfq_compare: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('proc_rfq_award')) {
    /**
     * Award an OPEN RFQ to one of its quotes: create a draft PO to that vendor and add
     * a single line (the RFQ item/qty at the QUOTED unit price — server-read, never a
     * client amount). Marks the RFQ 'awarded' with the winning vendor + po_id.
     * Returns the new po id (0 on failure). Runs inside a transaction.
     */
    function proc_rfq_award($db, int $orgId, int $rfqId, int $quoteId): int
    {
        if ($orgId <= 0 || $rfqId <= 0 || $quoteId <= 0) { return 0; }
        if (!function_exists('proc_po_create') || !function_exists('proc_po_add_line')) { return 0; }
        $poIdOut = 0;
        try {
            $db->action(function ($db) use ($orgId, $rfqId, $quoteId, &$poIdOut) {
                $rfq = $db->get('stays_proc_rfqs', ['id', 'item_id', 'qty', 'status', 'currency'], ['id' => $rfqId, 'org_id' => $orgId]);
                if (!$rfq || $rfq['status'] !== 'open') { return false; }
                $q = $db->get('stays_proc_quotes', ['id', 'vendor_id', 'unit_price'],
                    ['id' => $quoteId, 'rfq_id' => $rfqId, 'org_id' => $orgId]);
                if (!$q) { return false; }
                $vendorId = (int) $q['vendor_id'];
                $unit = proc_ext_money($q['unit_price']);          // server-read price
                $qty  = round((float) $rfq['qty'], 3);
                $poId = proc_po_create($db, $orgId, $vendorId, (string) ($rfq['currency'] ?? 'USD'));
                if ($poId <= 0) { return false; }
                if (!proc_po_add_line($db, $orgId, $poId, (int) $rfq['item_id'], $qty, $unit)) { return false; }
                $db->update('stays_proc_rfqs', [
                    'status' => 'awarded', 'awarded_vendor_id' => $vendorId, 'po_id' => $poId,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => $rfqId, 'org_id' => $orgId, 'status' => 'open']);
                $poIdOut = $poId;
                return true;
            });
        } catch (\Throwable $e) { error_log('proc_rfq_award: ' . $e->getMessage()); return 0; }
        return $poIdOut;
    }
}

if (!function_exists('proc_rfq_list')) {
    /** List RFQs (newest first) with a quote count. Read-only. */
    function proc_rfq_list($db, int $orgId, int $limit = 50): array
    {
        if ($orgId <= 0) { return []; }
        try {
            $rfqs = $db->select('stays_proc_rfqs',
                ['id', 'reference', 'item_name', 'qty', 'needed_by', 'status', 'awarded_vendor_id', 'po_id', 'created_at'],
                ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
            foreach ($rfqs as &$r) {
                $r['quote_count'] = (int) $db->count('stays_proc_quotes', ['rfq_id' => (int) $r['id'], 'org_id' => $orgId]);
            } unset($r);
            return $rfqs;
        } catch (\Throwable $e) { error_log('proc_rfq_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('proc_ext_rfq_dispatch')) {
    /**
     * HONEST STUB — "send this RFQ to the invited vendors" over email/EDI/portal.
     * No outbound channel is wired for procurement RFQs yet, so this reports
     * 'not connected' rather than faking a send. The RFQ + quotes live entirely as
     * INTERNAL records (owner keys in the vendor replies manually). DB-GATED/external.
     */
    function proc_ext_rfq_dispatch($db, int $orgId, int $rfqId): array
    {
        return [
            'ok'        => false,
            'connected' => false,
            'message'   => 'RFQ vendor dispatch is not connected — record quotes manually for now. '
                         . '(No email/EDI/portal channel is wired for procurement RFQs.)',
        ];
    }
}

// ===========================================================================
// 3) PARTIAL DELIVERIES (GRN against PO lines, received_qty tracking)
// ===========================================================================

if (!function_exists('proc_po_line_balances')) {
    /**
     * For an ORDERED/PARTIAL PO, per-line [line_id, item_id, name, qty, received_qty,
     * outstanding, unit_cost]. received_qty is read from the DB-GATED column
     * stays_po_lines.received_qty (0 if the column/feature is absent). Read-only.
     */
    function proc_po_line_balances($db, int $poId): array
    {
        if ($poId <= 0) { return []; }
        try {
            $rows = $db->select('stays_po_lines', '*', ['po_id' => $poId, 'ORDER' => ['id' => 'ASC']]) ?: [];
        } catch (\Throwable $e) { error_log('proc_po_line_balances: ' . $e->getMessage()); return []; }
        $out = [];
        foreach ($rows as $r) {
            $qty = round((float) $r['qty'], 3);
            $rcv = round((float) ($r['received_qty'] ?? 0), 3);
            $out[] = [
                'line_id'      => (int) $r['id'],
                'item_id'      => (int) $r['item_id'],
                'name'         => (string) $r['name'],
                'qty'          => $qty,
                'received_qty' => $rcv,
                'outstanding'  => round(max(0.0, $qty - $rcv), 3),
                'unit_cost'    => proc_ext_money($r['unit_cost']),
            ];
        }
        return $out;
    }
}

if (!function_exists('proc_grn_receive_partial')) {
    /**
     * Receive SOME quantities against an ordered/partial PO (partial delivery / GRN).
     * $deliveries = [line_id => qty_received_now, ...]. For each line it clamps the
     * received qty to the outstanding balance, bumps stock on_hand, and accumulates
     * stays_po_lines.received_qty. Posts ONE balanced GL entry for the VALUE received
     * in THIS GRN (DR 6000 expense / CR 2000 AP), with a unique source+reference per
     * GRN so repeated partials never double-post or re-post. Flips the PO status:
     * ordered → partial (some outstanding) → received (all lines fully received).
     *
     * All amounts are server-computed from stored unit_cost. Runs inside a transaction
     * with FOR UPDATE on the PO row. Returns ['ok','grn_ref','value','fully','message'].
     *
     * DB-GATED: needs stays_po_lines.received_qty + stays_proc_grns (see schema_sql).
     * NOTE: this partial GRN is an ALTERNATIVE to the base proc_po_receive (all-at-once).
     * Do not mix the two on the same PO — a route should offer one path per PO.
     */
    function proc_grn_receive_partial($db, int $orgId, int $poId, array $deliveries): array
    {
        if ($orgId <= 0 || $poId <= 0) { return ['ok' => false, 'message' => 'Bad input']; }
        $result = ['ok' => false, 'message' => 'Receive failed'];
        try {
            $db->action(function ($db) use ($orgId, $poId, $deliveries, &$result) {
                // Lock the PO; must be ordered or partial.
                $lock = $db->query('SELECT id, reference, currency, status FROM stays_purchase_orders WHERE id=:p AND org_id=:o FOR UPDATE',
                    [':p' => $poId, ':o' => $orgId]);
                $po = $lock ? $lock->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$po) { $result = ['ok' => false, 'message' => 'PO not found.']; return false; }
                if (!in_array($po['status'], ['ordered', 'partial'], true)) {
                    $result = ['ok' => false, 'message' => 'PO is not awaiting delivery (' . $po['status'] . ').']; return false;
                }

                $grnRef = proc_ext_ref('GRN');
                $value = 0.0;            // value received in THIS GRN
                $linesTouched = 0;

                foreach ($deliveries as $lineId => $qtyNow) {
                    $lineId = (int) $lineId;
                    $qtyNow = round((float) $qtyNow, 3);
                    if ($lineId <= 0 || $qtyNow <= 0) { continue; }

                    // Lock the line; it must belong to this PO.
                    $ll = $db->query('SELECT id, item_id, qty, unit_cost, received_qty FROM stays_po_lines WHERE id=:l AND po_id=:p FOR UPDATE',
                        [':l' => $lineId, ':p' => $poId]);
                    $line = $ll ? $ll->fetch(\PDO::FETCH_ASSOC) : null;
                    if (!$line) { continue; }

                    $ordered  = round((float) $line['qty'], 3);
                    $already   = round((float) ($line['received_qty'] ?? 0), 3);
                    $outstanding = round(max(0.0, $ordered - $already), 3);
                    if ($outstanding <= 0) { continue; }

                    $take = min($qtyNow, $outstanding);   // never over-receive a line
                    if ($take <= 0) { continue; }
                    $unit = proc_ext_money($line['unit_cost']);
                    $value = round($value + ($take * $unit), 2);

                    // Bump the stock item (locked).
                    $itemId = (int) $line['item_id'];
                    $si = $db->query('SELECT on_hand FROM stays_stock_items WHERE id=:i AND org_id=:o FOR UPDATE',
                        [':i' => $itemId, ':o' => $orgId]);
                    $item = $si ? $si->fetch(\PDO::FETCH_ASSOC) : null;
                    if ($item) {
                        $db->update('stays_stock_items',
                            ['on_hand' => round(((float) $item['on_hand']) + $take, 3), 'updated_at' => date('Y-m-d H:i:s')],
                            ['id' => $itemId, 'org_id' => $orgId]);
                    }
                    // Accumulate received_qty on the line.
                    $db->update('stays_po_lines', ['received_qty' => round($already + $take, 3)], ['id' => $lineId]);

                    // Record the GRN line.
                    $db->insert('stays_proc_grn_lines', [
                        'org_id' => $orgId, 'grn_ref' => $grnRef, 'po_id' => $poId, 'po_line_id' => $lineId,
                        'item_id' => $itemId, 'qty' => $take, 'unit_cost' => $unit,
                        'line_value' => round($take * $unit, 2), 'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $linesTouched++;
                }

                if ($linesTouched === 0 || $value <= 0) {
                    $result = ['ok' => false, 'message' => 'Nothing to receive (quantities are zero or already fully received).'];
                    return false; // rollback (no stock moved because we abort before commit)
                }

                // Post the GL for the value received in THIS GRN — balanced + idempotent.
                if (function_exists('gl_post')) {
                    $gl = gl_post($db, $orgId, [
                        ['code' => '6000', 'debit' => $value],
                        ['code' => '2000', 'credit' => $value],
                    ], ['source' => 'grn_partial', 'reference' => $grnRef, 'currency' => (string) $po['currency'],
                        'memo' => 'Goods received (partial) ' . $po['reference'] . ' / ' . $grnRef]);
                    if (empty($gl['ok'])) {
                        error_log('proc_grn_receive_partial GL: ' . ($gl['message'] ?? '?'));
                        $result = ['ok' => false, 'message' => 'Could not post the goods-received value to the ledger; nothing changed.'];
                        return false; // rollback the whole GRN (stock + received_qty)
                    }
                }

                // Header row for the GRN (one per receive event).
                $db->insert('stays_proc_grns', [
                    'org_id' => $orgId, 'reference' => $grnRef, 'po_id' => $poId,
                    'value' => $value, 'currency' => (string) $po['currency'],
                    'received_by' => proc_ext_actor(), 'created_at' => date('Y-m-d H:i:s'),
                ]);

                // Recompute PO completion: fully received iff every line's received_qty >= qty.
                $remaining = (int) $db->query(
                    'SELECT COUNT(*) FROM stays_po_lines WHERE po_id=' . (int) $poId . ' AND received_qty < qty'
                )->fetchColumn();
                $fully = ($remaining === 0);
                $newStatus = $fully ? 'received' : 'partial';
                $patch = ['status' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')];
                if ($fully) { $patch['received_at'] = date('Y-m-d H:i:s'); }
                $db->update('stays_purchase_orders', $patch, ['id' => $poId, 'org_id' => $orgId]);

                $result = ['ok' => true, 'grn_ref' => $grnRef, 'value' => $value, 'fully' => $fully,
                    'message' => $fully ? 'Final delivery received — PO fully received.' : 'Partial delivery received; PO still has outstanding lines.'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('proc_grn_receive_partial: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Receive error.'];
        }
        return $result;
    }
}

if (!function_exists('proc_grn_list')) {
    /** GRN events for a PO (newest first). Read-only. */
    function proc_grn_list($db, int $orgId, int $poId): array
    {
        if ($orgId <= 0 || $poId <= 0) { return []; }
        try {
            return $db->select('stays_proc_grns', ['reference', 'value', 'currency', 'received_by', 'created_at'],
                ['org_id' => $orgId, 'po_id' => $poId, 'ORDER' => ['id' => 'DESC']]) ?: [];
        } catch (\Throwable $e) { error_log('proc_grn_list: ' . $e->getMessage()); return []; }
    }
}

// ===========================================================================
// 4) RETURNS TO VENDOR (debit note)
// ===========================================================================

if (!function_exists('proc_return_to_vendor')) {
    /**
     * Return a received quantity of a PO line to the vendor (a debit note). Reduces the
     * line's received_qty + stock on_hand by the returned qty (clamped to what was
     * received AND to current stock on hand), and posts a REVERSING balanced GL entry
     * for the returned value: DR 2000 (AP — we owe the vendor less) / CR 6000 (expense
     * reversed). Server-computed value. Transactional, FOR UPDATE on line + item.
     * Returns ['ok','ref','value','message']. DB-GATED (received_qty + stays_proc_returns).
     */
    function proc_return_to_vendor($db, int $orgId, int $poId, int $lineId, float $qty, string $reason = ''): array
    {
        if ($orgId <= 0 || $poId <= 0 || $lineId <= 0) { return ['ok' => false, 'message' => 'Bad input']; }
        $qty = round($qty, 3);
        if ($qty <= 0) { return ['ok' => false, 'message' => 'Enter a quantity to return.']; }
        $result = ['ok' => false, 'message' => 'Return failed'];
        try {
            $db->action(function ($db) use ($orgId, $poId, $lineId, $qty, $reason, &$result) {
                $lock = $db->query('SELECT id, reference, currency FROM stays_purchase_orders WHERE id=:p AND org_id=:o FOR UPDATE',
                    [':p' => $poId, ':o' => $orgId]);
                $po = $lock ? $lock->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$po) { $result = ['ok' => false, 'message' => 'PO not found.']; return false; }

                $ll = $db->query('SELECT id, item_id, unit_cost, received_qty FROM stays_po_lines WHERE id=:l AND po_id=:p FOR UPDATE',
                    [':l' => $lineId, ':p' => $poId]);
                $line = $ll ? $ll->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$line) { $result = ['ok' => false, 'message' => 'Line not found.']; return false; }

                $received = round((float) ($line['received_qty'] ?? 0), 3);
                if ($received <= 0) { $result = ['ok' => false, 'message' => 'Nothing received on this line to return.']; return false; }

                $itemId = (int) $line['item_id'];
                $si = $db->query('SELECT on_hand FROM stays_stock_items WHERE id=:i AND org_id=:o FOR UPDATE',
                    [':i' => $itemId, ':o' => $orgId]);
                $item = $si ? $si->fetch(\PDO::FETCH_ASSOC) : null;
                $onHand = $item ? round((float) $item['on_hand'], 3) : 0.0;

                // Can only return what was received AND is still on hand.
                $take = min($qty, $received, $onHand);
                if ($take <= 0) {
                    $result = ['ok' => false, 'message' => 'Cannot return more than is received and still on hand (' . rtrim(rtrim((string) min($received, $onHand), '0'), '.') . ').'];
                    return false;
                }
                $unit = proc_ext_money($line['unit_cost']);
                $value = round($take * $unit, 2);

                // Stock out + reduce received_qty.
                if ($item) {
                    $db->update('stays_stock_items', ['on_hand' => round($onHand - $take, 3), 'updated_at' => date('Y-m-d H:i:s')],
                        ['id' => $itemId, 'org_id' => $orgId]);
                }
                $db->update('stays_po_lines', ['received_qty' => round($received - $take, 3)], ['id' => $lineId]);

                $ref = proc_ext_ref('RTN');
                // Reversing GL (DR AP / CR expense) — balanced + idempotent on source+ref.
                if ($value > 0 && function_exists('gl_post')) {
                    $gl = gl_post($db, $orgId, [
                        ['code' => '2000', 'debit' => $value],   // reduce what we owe
                        ['code' => '6000', 'credit' => $value],  // reverse the expense
                    ], ['source' => 'po_return', 'reference' => $ref, 'currency' => (string) $po['currency'],
                        'memo' => 'Return to vendor ' . $po['reference'] . ' / ' . $ref]);
                    if (empty($gl['ok'])) {
                        error_log('proc_return_to_vendor GL: ' . ($gl['message'] ?? '?'));
                        $result = ['ok' => false, 'message' => 'Could not post the return to the ledger; nothing changed.'];
                        return false;
                    }
                }

                $db->insert('stays_proc_returns', [
                    'org_id' => $orgId, 'reference' => $ref, 'po_id' => $poId, 'po_line_id' => $lineId,
                    'item_id' => $itemId, 'qty' => $take, 'unit_cost' => $unit, 'value' => $value,
                    'currency' => (string) $po['currency'], 'reason' => substr(trim($reason), 0, 300) ?: null,
                    'created_by' => proc_ext_actor(), 'created_at' => date('Y-m-d H:i:s'),
                ]);

                $result = ['ok' => true, 'ref' => $ref, 'value' => $value,
                    'message' => 'Returned ' . rtrim(rtrim((string) $take, '0'), '.') . ' to vendor (debit note ' . $ref . ').'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('proc_return_to_vendor: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Return error.'];
        }
        return $result;
    }
}

// ===========================================================================
// 5) THREE-WAY MATCH (PO ↔ GRN ↔ vendor invoice)
// ===========================================================================

if (!function_exists('proc_invoice_record')) {
    /**
     * Record a vendor INVOICE amount against a PO (for the three-way match). The amount
     * is the figure the vendor billed — it is stored for reconciliation ONLY and does
     * NOT post to the GL here (GRN already recognised expense/AP). Returns invoice id.
     */
    function proc_invoice_record($db, int $orgId, int $poId, float $amount, array $d = []): int
    {
        if ($orgId <= 0 || $poId <= 0) { return 0; }
        $amount = proc_ext_money($amount);
        if ($amount < 0) { return 0; }
        try {
            $po = $db->get('stays_purchase_orders', ['id', 'currency'], ['id' => $poId, 'org_id' => $orgId]);
            if (!$po) { return 0; }
            $db->insert('stays_proc_invoices', [
                'org_id'       => $orgId,
                'po_id'        => $poId,
                'invoice_no'   => substr(trim((string) ($d['invoice_no'] ?? '')), 0, 100) ?: null,
                'amount'       => $amount,
                'currency'     => (string) $po['currency'],
                'invoice_date' => (isset($d['invoice_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d['invoice_date'])) ? $d['invoice_date'] : null,
                'created_by'   => proc_ext_actor(),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('proc_invoice_record: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('proc_three_way_match')) {
    /**
     * Three-way match for a PO: compare
     *   - PO ordered value   (sum of line qty × unit_cost)
     *   - GRN received value  (sum of GRN line values — what actually arrived)
     *   - INVOICE total       (sum of recorded vendor invoices)
     * within $tolerance (absolute, PO currency). Returns
     * ['po_value','received_value','invoiced_value','matched'=>bool,'flags'=>[...],
     *  'currency','tolerance']. 'matched' iff all three agree within tolerance.
     * Read-only — surfaces over/under-billing BEFORE AP is settled.
     */
    function proc_three_way_match($db, int $orgId, int $poId, float $tolerance = 0.01): array
    {
        $out = ['po_value' => 0.0, 'received_value' => 0.0, 'invoiced_value' => 0.0,
                'matched' => false, 'flags' => [], 'currency' => 'USD', 'tolerance' => round($tolerance, 2)];
        if ($orgId <= 0 || $poId <= 0) { return $out; }
        try {
            $po = $db->get('stays_purchase_orders', ['id', 'currency'], ['id' => $poId, 'org_id' => $orgId]);
            if (!$po) { return $out; }
            $out['currency'] = (string) $po['currency'];

            // PO value = server sum of its lines.
            $poVal = function_exists('proc_po_total') ? proc_po_total($db, $poId) : 0.0;
            $out['po_value'] = round($poVal, 2);

            // Received value from GRN headers (falls back to 0 if the feature's unused).
            $rcv = 0.0;
            foreach ($db->select('stays_proc_grns', ['value'], ['org_id' => $orgId, 'po_id' => $poId]) ?: [] as $g) {
                $rcv += (float) $g['value'];
            }
            // Subtract any returns (debit notes) so received_value is net of returns.
            foreach ($db->select('stays_proc_returns', ['value'], ['org_id' => $orgId, 'po_id' => $poId]) ?: [] as $r) {
                $rcv -= (float) $r['value'];
            }
            $out['received_value'] = round($rcv, 2);

            // Invoiced value from recorded vendor invoices.
            $inv = 0.0;
            foreach ($db->select('stays_proc_invoices', ['amount'], ['org_id' => $orgId, 'po_id' => $poId]) ?: [] as $i) {
                $inv += (float) $i['amount'];
            }
            $out['invoiced_value'] = round($inv, 2);

            $tol = max(0.0, round($tolerance, 2));
            $flags = [];
            if (abs($out['invoiced_value'] - $out['received_value']) > $tol) {
                $flags[] = $out['invoiced_value'] > $out['received_value']
                    ? 'Invoice exceeds goods received — possible OVER-billing.'
                    : 'Invoice is below goods received — vendor may still bill the rest.';
            }
            if (abs($out['received_value'] - $out['po_value']) > $tol) {
                $flags[] = 'Received value differs from the PO (partial delivery, short-ship, or over-receipt).';
            }
            if (abs($out['invoiced_value'] - $out['po_value']) > $tol) {
                $flags[] = 'Invoice differs from the PO amount.';
            }
            $out['flags'] = $flags;
            $out['matched'] = empty($flags) && $out['invoiced_value'] > 0;
        } catch (\Throwable $e) { error_log('proc_three_way_match: ' . $e->getMessage()); }
        return $out;
    }
}

// ===========================================================================
// 6) VENDOR CONTRACTS
// ===========================================================================

if (!function_exists('proc_contract_create')) {
    /**
     * Record a vendor contract / price-agreement: $d = [vendor_id, title, start_date,
     * end_date, spend_cap?, currency?, terms?]. Dates are YYYY-MM-DD. spend_cap is an
     * advisory ceiling (0 = none). Returns contract id (0 on failure).
     */
    function proc_contract_create($db, int $orgId, array $d): int
    {
        $vendorId = (int) ($d['vendor_id'] ?? 0);
        $title = trim((string) ($d['title'] ?? ''));
        if ($orgId <= 0 || $vendorId <= 0 || $title === '') { return 0; }
        $start = (string) ($d['start_date'] ?? '');
        $end = (string) ($d['end_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) { return 0; }
        if ($end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) { $end = ''; }
        try {
            if (!$db->has('stays_vendors', ['id' => $vendorId, 'org_id' => $orgId])) { return 0; }
            $db->insert('stays_proc_contracts', [
                'org_id'     => $orgId, 'vendor_id' => $vendorId,
                'title'      => substr($title, 0, 191),
                'start_date' => $start, 'end_date' => $end ?: null,
                'spend_cap'  => max(0.0, proc_ext_money($d['spend_cap'] ?? 0)),
                'currency'   => strtoupper(substr((string) ($d['currency'] ?? 'USD'), 0, 3)),
                'terms'      => substr(trim((string) ($d['terms'] ?? '')), 0, 1000) ?: null,
                'status'     => 1, 'created_by' => proc_ext_actor(), 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('proc_contract_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('proc_contract_list')) {
    /**
     * Active contracts with a derived state flag: 'expired', 'expiring' (≤30 days),
     * 'over_cap' (received PO spend to that vendor ≥ spend_cap), or 'ok'. Spend is
     * server-derived from received POs (never a client figure). Read-only.
     */
    function proc_contract_list($db, int $orgId): array
    {
        if ($orgId <= 0) { return []; }
        try {
            $rows = $db->select('stays_proc_contracts', '*', ['org_id' => $orgId, 'status' => 1, 'ORDER' => ['id' => 'DESC']]) ?: [];
            if (empty($rows)) { return []; }
            $names = [];
            foreach ($db->select('stays_vendors', ['id', 'name'], ['org_id' => $orgId]) ?: [] as $v) {
                $names[(int) $v['id']] = (string) $v['name'];
            }
            $today = date('Y-m-d');
            foreach ($rows as &$c) {
                $vid = (int) $c['vendor_id'];
                $c['vendor_name'] = $names[$vid] ?? ('#' . $vid);
                $flag = 'ok';
                if (!empty($c['end_date'])) {
                    if ($c['end_date'] < $today) { $flag = 'expired'; }
                    elseif (strtotime($c['end_date']) <= strtotime('+30 days')) { $flag = 'expiring'; }
                }
                $cap = (float) ($c['spend_cap'] ?? 0);
                $spend = 0.0;
                if ($cap > 0) {
                    foreach ($db->select('stays_purchase_orders', ['total'],
                        ['org_id' => $orgId, 'vendor_id' => $vid, 'status' => 'received']) ?: [] as $p) {
                        $spend += (float) $p['total'];
                    }
                    if ($spend >= $cap && $flag === 'ok') { $flag = 'over_cap'; }
                }
                $c['spend_to_date'] = round($spend, 2);
                $c['flag'] = $flag;
            } unset($c);
            return $rows;
        } catch (\Throwable $e) { error_log('proc_contract_list: ' . $e->getMessage()); return []; }
    }
}

// ===========================================================================
// 7) BUDGET CONTROL
// ===========================================================================

if (!function_exists('proc_budget_period')) {
    /** Canonical period key for a date: 'YYYY-MM' (monthly budgeting). */
    function proc_budget_period(?string $date = null): string
    {
        $t = $date && preg_match('/^\d{4}-\d{2}/', $date) ? strtotime($date) : time();
        return date('Y-m', $t ?: time());
    }
}

if (!function_exists('proc_budget_set')) {
    /**
     * Set (upsert) the procurement budget for a period ('YYYY-MM'). $amount is the cap
     * for that month. Returns bool. One row per (org, period).
     */
    function proc_budget_set($db, int $orgId, string $period, float $amount, string $currency = 'USD'): bool
    {
        if ($orgId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $period)) { return false; }
        $amount = max(0.0, proc_ext_money($amount));
        try {
            $existing = $db->get('stays_proc_budgets', ['id'], ['org_id' => $orgId, 'period' => $period]);
            if ($existing) {
                $db->update('stays_proc_budgets',
                    ['amount' => $amount, 'currency' => strtoupper(substr($currency, 0, 3)), 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => (int) $existing['id']]);
            } else {
                $db->insert('stays_proc_budgets', [
                    'org_id' => $orgId, 'period' => $period, 'amount' => $amount,
                    'currency' => strtoupper(substr($currency, 0, 3)), 'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
            return true;
        } catch (\Throwable $e) { error_log('proc_budget_set: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('proc_budget_status')) {
    /**
     * Budget vs. committed/received spend for a period. Spend is server-derived:
     * committed = ordered+partial+received PO totals created in the period; actual =
     * received PO totals in the period. Returns ['period','budget','committed','actual',
     * 'remaining','over'=>bool,'currency']. Read-only, advisory.
     *
     * NOTE: PO.created_at is used as the period key (POs are timestamped at creation);
     * this is an approximation — a PO created in one month but received in the next is
     * counted in its creation month. Good enough for a soft spend gate.
     */
    function proc_budget_status($db, int $orgId, ?string $period = null): array
    {
        $period = $period && preg_match('/^\d{4}-\d{2}$/', $period) ? $period : proc_budget_period();
        $out = ['period' => $period, 'budget' => 0.0, 'committed' => 0.0, 'actual' => 0.0,
                'remaining' => 0.0, 'over' => false, 'currency' => 'USD'];
        if ($orgId <= 0) { return $out; }
        try {
            $b = $db->get('stays_proc_budgets', ['amount', 'currency'], ['org_id' => $orgId, 'period' => $period]);
            $budget = $b ? (float) $b['amount'] : 0.0;
            $out['budget'] = round($budget, 2);
            if ($b && !empty($b['currency'])) { $out['currency'] = strtoupper((string) $b['currency']); }

            $like = $period . '%'; // YYYY-MM% over created_at
            $committed = 0.0; $actual = 0.0;
            $rows = $db->query(
                'SELECT status, total FROM stays_purchase_orders
                 WHERE org_id=' . (int) $orgId . "
                   AND status IN ('ordered','partial','received')
                   AND created_at LIKE " . $db->quote($like)
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $committed += (float) $r['total'];
                if ($r['status'] === 'received') { $actual += (float) $r['total']; }
            }
            $out['committed'] = round($committed, 2);
            $out['actual'] = round($actual, 2);
            $out['remaining'] = round($budget - $committed, 2);
            $out['over'] = ($budget > 0 && $committed > $budget);
        } catch (\Throwable $e) { error_log('proc_budget_status: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('proc_budget_allows')) {
    /**
     * Soft budget gate: would committing $extra this period exceed the budget? Returns
     * ['allowed'=>bool,'status'=>proc_budget_status(),'message'=>...]. ADVISORY ONLY —
     * if no budget is set (budget<=0) it always allows. Callers decide whether to warn
     * or block; the base PO flow is not forced through this.
     */
    function proc_budget_allows($db, int $orgId, float $extra, ?string $period = null): array
    {
        $status = proc_budget_status($db, $orgId, $period);
        $extra = max(0.0, proc_ext_money($extra));
        if ($status['budget'] <= 0) {
            return ['allowed' => true, 'status' => $status, 'message' => 'No budget set for ' . $status['period'] . '.'];
        }
        $projected = round($status['committed'] + $extra, 2);
        $allowed = $projected <= $status['budget'];
        return [
            'allowed' => $allowed,
            'status'  => $status,
            'message' => $allowed
                ? ('Within budget (' . number_format($projected, 2) . ' / ' . number_format($status['budget'], 2) . ' ' . $status['currency'] . ').')
                : ('Over budget — ' . number_format($projected, 2) . ' would exceed the ' . number_format($status['budget'], 2) . ' ' . $status['currency'] . ' cap for ' . $status['period'] . '.'),
        ];
    }
}

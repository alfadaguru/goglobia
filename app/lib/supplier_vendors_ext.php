<?php
// FILE: app/lib/supplier_vendors_ext.php
// MODULE 43 (Vendor & contractor management) — DEEPENING on top of supplier_procurement.php.
// Base (already shipped): vendor registration (proc_vendor_create), verification
//   (proc_vendor_verify), payment_terms, and AP-owed derived from received POs (ap_summary).
// This file adds ONLY the genuine spec gaps (docs 01b #43):
//   - categories / vendor type                 -> vendor_set_category (+ vendor_categories vocab)
//   - contracts / price agreements              -> vendor_contract_add / _list / _set_status
//   - price agreements (negotiated unit prices) -> vendor_price_add / _list / _price_for_item
//   - job assignment (contractor → job record)  -> vendor_job_assign / _list / _set_status
//   - performance scoring (quality/delivery/..) -> vendor_score_add / vendor_scorecard
//   - compliance docs + expiry reminders        -> vendor_doc_add / _list / vendor_doc_reminders
//   - vendor invoices + payment (settles AP)     -> vendor_invoice_add / _list / vendor_invoice_pay
//
// MONEY (honest scope):
//   * A vendor INVOICE is a record against the vendor (what they are billing the
//     property). Recording it does NOT touch the GL by itself — a received PO already
//     booked DR 6000 / CR 2000 (AP) at goods-receipt, so booking the invoice again would
//     double-count the expense. The invoice is a tracked payable document only.
//   * PAYING a vendor invoice posts ONE balanced GL entry DR 2000 (Accounts Payable) /
//     CR 1000 (Cash/Bank) via gl_post — settling what the property owes. Idempotent on
//     (org,'vendorpay',reference). The invoice row is flipped unpaid→paid inside the SAME
//     $db->action() under a FOR UPDATE lock so a double-click cannot pay twice.
//   * The ACTUAL bank disbursement to the vendor (NIP/ACH transfer) is DB-GATED / NOT
//     connected — there is no payout gateway wired here. vendor_invoice_pay records the
//     internal payment + books the ledger only; vendor_disbursement_status() reports the
//     external transfer as 'not connected'. Never fake a live transfer.
//
// House rules: every fn if_not_exists-guarded; ORG-scoped (vendors live at org level, like
// the base procurement module); amounts server-recomputed, never trusted from the client;
// defensive/non-fatal. NOT runtime-verified (no MySQL in build env) — logic-reasoned + php -l.

// ---------------------------------------------------------------------------
// Vocabularies
// ---------------------------------------------------------------------------
if (!function_exists('vendor_categories')) {
    /** Vendor / contractor category taxonomy. key => label. */
    function vendor_categories(): array
    {
        return [
            'supplies'    => 'Supplies / goods',
            'fnb'         => 'Food & beverage',
            'maintenance' => 'Maintenance / engineering',
            'contractor'  => 'Contractor (works)',
            'laundry'     => 'Laundry',
            'it'          => 'IT / telecoms',
            'utilities'   => 'Utilities',
            'professional'=> 'Professional services',
            'logistics'   => 'Logistics / transport',
            'other'       => 'Other',
        ];
    }
}

if (!function_exists('vendor_contract_statuses')) {
    function vendor_contract_statuses(): array
    {
        return ['draft' => 'Draft', 'active' => 'Active', 'expired' => 'Expired', 'terminated' => 'Terminated'];
    }
}

if (!function_exists('vendor_job_statuses')) {
    function vendor_job_statuses(): array
    {
        return ['assigned' => 'Assigned', 'in_progress' => 'In progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
    }
}

if (!function_exists('vendor_doc_types')) {
    /** Compliance document types (insurance, licences, tax, etc). */
    function vendor_doc_types(): array
    {
        return [
            'insurance'   => 'Insurance certificate',
            'license'     => 'Trade licence',
            'tax'         => 'Tax clearance',
            'registration'=> 'Business registration',
            'certificate' => 'Certification',
            'contract'    => 'Signed contract',
            'other'       => 'Other',
        ];
    }
}

if (!function_exists('vendor_score_metrics')) {
    /** Performance metrics scored 1..5. key => label. */
    function vendor_score_metrics(): array
    {
        return ['quality' => 'Quality', 'delivery' => 'Delivery / timeliness', 'price' => 'Price / value', 'communication' => 'Communication'];
    }
}

// ---------------------------------------------------------------------------
// Internal: confirm a vendor belongs to the org (IDOR guard inside lib; route also gates).
// ---------------------------------------------------------------------------
if (!function_exists('_vendor_owned_row')) {
    function _vendor_owned_row($db, int $orgId, int $vendorId): ?array
    {
        if ($orgId <= 0 || $vendorId <= 0) { return null; }
        try {
            $row = $db->get('stays_vendors', '*', ['id' => $vendorId, 'org_id' => $orgId]);
            return $row ?: null;
        } catch (\Throwable $e) { error_log('_vendor_owned_row: ' . $e->getMessage()); return null; }
    }
}

// ---------------------------------------------------------------------------
// CATEGORIES — classify a vendor. Stored additively on stays_vendors.category.
// ---------------------------------------------------------------------------
if (!function_exists('vendor_set_category')) {
    /** Set a vendor's category (from vendor_categories). Returns bool. */
    function vendor_set_category($db, int $orgId, int $vendorId, string $category): bool
    {
        if (_vendor_owned_row($db, $orgId, $vendorId) === null) { return false; }
        if (!isset(vendor_categories()[$category])) { $category = 'other'; }
        try {
            $db->update('stays_vendors', ['category' => $category, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $vendorId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('vendor_set_category: ' . $e->getMessage()); return false; }
    }
}

// ---------------------------------------------------------------------------
// CONTRACTS — a service/supply agreement with the vendor: value, period, renewal flag.
// Record only; no money posted (payments flow through vendor invoices). Reminders for
// contracts nearing their end date are surfaced by vendor_doc_reminders() via end_date.
// ---------------------------------------------------------------------------
if (!function_exists('vendor_contract_add')) {
    /** Create a contract against a vendor. Returns contract id (0 on failure). */
    function vendor_contract_add($db, int $orgId, int $vendorId, array $d): int
    {
        if (_vendor_owned_row($db, $orgId, $vendorId) === null) { return 0; }
        $title = trim((string) ($d['title'] ?? ''));
        if ($title === '') { return 0; }
        $start = trim((string) ($d['start_date'] ?? ''));
        $end   = trim((string) ($d['end_date'] ?? ''));
        $status = isset(vendor_contract_statuses()[$d['status'] ?? '']) ? $d['status'] : 'active';
        try {
            $db->insert('stays_vendor_contracts', [
                'org_id'      => $orgId,
                'vendor_id'   => $vendorId,
                'title'       => substr($title, 0, 191),
                'reference'   => substr(trim((string) ($d['reference'] ?? '')), 0, 60) ?: null,
                'value'       => max(0.0, round((float) ($d['value'] ?? 0), 2)),
                'currency'    => strtoupper(substr((string) ($d['currency'] ?? 'USD'), 0, 3)),
                'start_date'  => preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? $start : null,
                'end_date'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) ? $end : null,
                'auto_renew'  => !empty($d['auto_renew']) ? 1 : 0,
                'status'      => $status,
                'notes'       => substr(trim((string) ($d['notes'] ?? '')), 0, 500) ?: null,
                'created_by'  => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('vendor_contract_add: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('vendor_contract_set_status')) {
    /** Transition a contract's status (org-scoped). Returns bool. */
    function vendor_contract_set_status($db, int $orgId, int $contractId, string $status): bool
    {
        if ($orgId <= 0 || $contractId <= 0) { return false; }
        if (!isset(vendor_contract_statuses()[$status])) { return false; }
        try {
            if (!$db->has('stays_vendor_contracts', ['id' => $contractId, 'org_id' => $orgId])) { return false; }
            $db->update('stays_vendor_contracts', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $contractId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('vendor_contract_set_status: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('vendor_contract_list')) {
    /** Contracts for the org (optionally one vendor). Read-only. */
    function vendor_contract_list($db, int $orgId, int $vendorId = 0): array
    {
        if ($orgId <= 0) { return []; }
        $where = ['org_id' => $orgId];
        if ($vendorId > 0) { $where['vendor_id'] = $vendorId; }
        $where['ORDER'] = ['id' => 'DESC'];
        try { return $db->select('stays_vendor_contracts', '*', $where) ?: []; }
        catch (\Throwable $e) { error_log('vendor_contract_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// PRICE AGREEMENTS — a negotiated unit price for a named item/service, valid between
// dates. Optionally references a stock item (stays_stock_items). vendor_price_for_item()
// resolves the CURRENT agreed price so purchasing can default a PO line to the contracted
// rate (the PO still stores its own server-computed total — this is a lookup helper).
// ---------------------------------------------------------------------------
if (!function_exists('vendor_price_add')) {
    /** Add a price agreement line. Returns id (0 on failure). */
    function vendor_price_add($db, int $orgId, int $vendorId, array $d): int
    {
        if (_vendor_owned_row($db, $orgId, $vendorId) === null) { return 0; }
        $label = trim((string) ($d['item_label'] ?? ''));
        $itemId = (int) ($d['item_id'] ?? 0);
        // Must name either a free-text label or a real owned stock item.
        if ($label === '' && $itemId <= 0) { return 0; }
        if ($itemId > 0) {
            try {
                $it = $db->get('stays_stock_items', ['id', 'name'], ['id' => $itemId, 'org_id' => $orgId]);
                if (!$it) { return 0; } // item not owned -> reject
                if ($label === '') { $label = (string) $it['name']; }
            } catch (\Throwable $e) { return 0; }
        }
        $price = round((float) ($d['unit_price'] ?? 0), 2);
        if ($price < 0) { return 0; }
        $vf = trim((string) ($d['valid_from'] ?? ''));
        $vt = trim((string) ($d['valid_to'] ?? ''));
        try {
            $db->insert('stays_vendor_prices', [
                'org_id'     => $orgId,
                'vendor_id'  => $vendorId,
                'item_id'    => $itemId > 0 ? $itemId : null,
                'item_label' => substr($label, 0, 191),
                'unit'       => substr(trim((string) ($d['unit'] ?? 'unit')), 0, 40) ?: 'unit',
                'unit_price' => $price,
                'currency'   => strtoupper(substr((string) ($d['currency'] ?? 'USD'), 0, 3)),
                'valid_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $vf) ? $vf : null,
                'valid_to'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $vt) ? $vt : null,
                'status'     => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('vendor_price_add: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('vendor_price_list')) {
    /** Price agreements for the org (optionally one vendor). Read-only. */
    function vendor_price_list($db, int $orgId, int $vendorId = 0): array
    {
        if ($orgId <= 0) { return []; }
        $where = ['org_id' => $orgId, 'status' => 1];
        if ($vendorId > 0) { $where['vendor_id'] = $vendorId; }
        $where['ORDER'] = ['id' => 'DESC'];
        try { return $db->select('stays_vendor_prices', '*', $where) ?: []; }
        catch (\Throwable $e) { error_log('vendor_price_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('vendor_price_deactivate')) {
    /** Retire a price agreement (soft). Returns bool. */
    function vendor_price_deactivate($db, int $orgId, int $priceId): bool
    {
        if ($orgId <= 0 || $priceId <= 0) { return false; }
        try {
            if (!$db->has('stays_vendor_prices', ['id' => $priceId, 'org_id' => $orgId])) { return false; }
            $db->update('stays_vendor_prices', ['status' => 0], ['id' => $priceId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('vendor_price_deactivate: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('vendor_price_for_item')) {
    /**
     * Resolve the current agreed unit price for a stock item from a vendor, as of a date
     * (defaults today). Returns float|null (null = no active in-window agreement). Picks
     * the most recently created matching active row whose validity window covers the date.
     */
    function vendor_price_for_item($db, int $orgId, int $vendorId, int $itemId, ?string $asOf = null): ?float
    {
        if ($orgId <= 0 || $vendorId <= 0 || $itemId <= 0) { return null; }
        $date = (string) ($asOf ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $date = date('Y-m-d'); }
        try {
            $rows = $db->select('stays_vendor_prices', ['unit_price', 'valid_from', 'valid_to'],
                ['AND' => ['org_id' => $orgId, 'vendor_id' => $vendorId, 'item_id' => $itemId, 'status' => 1],
                 'ORDER' => ['id' => 'DESC']]) ?: [];
            foreach ($rows as $r) {
                $from = (string) ($r['valid_from'] ?? '');
                $to   = (string) ($r['valid_to'] ?? '');
                if ($from !== '' && $date < $from) { continue; }
                if ($to !== '' && $date > $to) { continue; }
                return round((float) $r['unit_price'], 2);
            }
        } catch (\Throwable $e) { error_log('vendor_price_for_item: ' . $e->getMessage()); }
        return null;
    }
}

// ---------------------------------------------------------------------------
// JOB ASSIGNMENT — assign a contractor to a job (works order / task). This is an
// internal tracking record; it does NOT auto-create a maintenance work order (that lives
// in module 27). A job may optionally reference a work-order id for cross-linking, and a
// job's labour cost is recorded here as a figure only (NOT posted — real spend flows via
// a vendor invoice + payment, or via the maintenance WO cost ledger).
// ---------------------------------------------------------------------------
if (!function_exists('vendor_job_assign')) {
    /** Assign a job to a vendor/contractor. Returns job id (0 on failure). */
    function vendor_job_assign($db, int $orgId, int $vendorId, array $d): int
    {
        if (_vendor_owned_row($db, $orgId, $vendorId) === null) { return 0; }
        $title = trim((string) ($d['title'] ?? ''));
        if ($title === '') { return 0; }
        $due = trim((string) ($d['due_date'] ?? ''));
        try {
            $db->insert('stays_vendor_jobs', [
                'org_id'       => $orgId,
                'vendor_id'    => $vendorId,
                'title'        => substr($title, 0, 191),
                'description'  => substr(trim((string) ($d['description'] ?? '')), 0, 1000) ?: null,
                'stay_id'      => (int) ($d['stay_id'] ?? 0) ?: null,
                'wo_id'        => (int) ($d['wo_id'] ?? 0) ?: null,
                'est_cost'     => max(0.0, round((float) ($d['est_cost'] ?? 0), 2)),
                'currency'     => strtoupper(substr((string) ($d['currency'] ?? 'USD'), 0, 3)),
                'due_date'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null,
                'status'       => 'assigned',
                'assigned_by'  => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('vendor_job_assign: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('vendor_job_set_status')) {
    /** Transition a job's status. 'completed' stamps completed_at. Returns bool. */
    function vendor_job_set_status($db, int $orgId, int $jobId, string $status): bool
    {
        if ($orgId <= 0 || $jobId <= 0) { return false; }
        if (!isset(vendor_job_statuses()[$status])) { return false; }
        try {
            if (!$db->has('stays_vendor_jobs', ['id' => $jobId, 'org_id' => $orgId])) { return false; }
            $upd = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
            if ($status === 'completed') { $upd['completed_at'] = date('Y-m-d H:i:s'); }
            $db->update('stays_vendor_jobs', $upd, ['id' => $jobId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('vendor_job_set_status: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('vendor_job_list')) {
    /** Jobs for the org (optionally one vendor). Read-only. */
    function vendor_job_list($db, int $orgId, int $vendorId = 0): array
    {
        if ($orgId <= 0) { return []; }
        $where = ['org_id' => $orgId];
        if ($vendorId > 0) { $where['vendor_id'] = $vendorId; }
        $where['ORDER'] = ['id' => 'DESC'];
        $where['LIMIT'] = 200;
        try { return $db->select('stays_vendor_jobs', '*', $where) ?: []; }
        catch (\Throwable $e) { error_log('vendor_job_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// PERFORMANCE SCORING — append 1..5 ratings per metric; the scorecard is the average.
// Pure internal analytics (no money). Optionally tied to a job for context.
// ---------------------------------------------------------------------------
if (!function_exists('vendor_score_add')) {
    /** Record one performance review (several metric scores) for a vendor. Returns id. */
    function vendor_score_add($db, int $orgId, int $vendorId, array $scores, string $comment = '', int $jobId = 0): int
    {
        if (_vendor_owned_row($db, $orgId, $vendorId) === null) { return 0; }
        $metrics = vendor_score_metrics();
        $row = [
            'org_id'    => $orgId,
            'vendor_id' => $vendorId,
            'job_id'    => $jobId > 0 ? $jobId : null,
            'comment'   => substr(trim($comment), 0, 500) ?: null,
            'scored_by' => (string) ($_SESSION['user_id'] ?? '') ?: null,
            'created_at'=> date('Y-m-d H:i:s'),
        ];
        $any = false; $sum = 0.0; $n = 0;
        foreach ($metrics as $key => $_) {
            $v = isset($scores[$key]) ? (int) $scores[$key] : 0;
            if ($v < 1 || $v > 5) { $v = 0; }       // 0 = not scored
            $row['m_' . $key] = $v ?: null;
            if ($v > 0) { $any = true; $sum += $v; $n++; }
        }
        if (!$any) { return 0; }                     // at least one metric required
        $row['overall'] = round($sum / $n, 2);       // server-computed overall
        try { $db->insert('stays_vendor_scores', $row); return (int) $db->id(); }
        catch (\Throwable $e) { error_log('vendor_score_add: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('vendor_scorecard')) {
    /**
     * Aggregate scorecard for a vendor: per-metric averages + overall + review count.
     * Returns ['count'=>int,'overall'=>float|null,'metrics'=>[key=>avg|null]]. Read-only.
     */
    function vendor_scorecard($db, int $orgId, int $vendorId): array
    {
        $out = ['count' => 0, 'overall' => null, 'metrics' => []];
        foreach (array_keys(vendor_score_metrics()) as $k) { $out['metrics'][$k] = null; }
        if ($orgId <= 0 || $vendorId <= 0) { return $out; }
        try {
            $rows = $db->select('stays_vendor_scores', '*', ['org_id' => $orgId, 'vendor_id' => $vendorId]) ?: [];
        } catch (\Throwable $e) { error_log('vendor_scorecard: ' . $e->getMessage()); return $out; }
        $out['count'] = count($rows);
        if (empty($rows)) { return $out; }
        $overallSum = 0.0; $overallN = 0;
        foreach (vendor_score_metrics() as $key => $_) {
            $sum = 0.0; $n = 0;
            foreach ($rows as $r) {
                $v = (int) ($r['m_' . $key] ?? 0);
                if ($v >= 1 && $v <= 5) { $sum += $v; $n++; }
            }
            $out['metrics'][$key] = $n > 0 ? round($sum / $n, 2) : null;
        }
        foreach ($rows as $r) {
            if (($r['overall'] ?? null) !== null) { $overallSum += (float) $r['overall']; $overallN++; }
        }
        $out['overall'] = $overallN > 0 ? round($overallSum / $overallN, 2) : null;
        return $out;
    }
}

// ---------------------------------------------------------------------------
// COMPLIANCE DOCS + EXPIRY REMINDERS — store a document reference (insurance, licence,
// etc) with an expiry date. The file ITSELF is handled by the existing upload helper at
// the route (handleFileUpload) — here we store the stored path + metadata only.
// vendor_doc_reminders() surfaces docs expiring soon AND contracts ending soon.
// ---------------------------------------------------------------------------
if (!function_exists('vendor_doc_add')) {
    /** Record a compliance document for a vendor. $storedPath is a server-side path the
     *  route already validated/moved via handleFileUpload (may be empty = metadata only). */
    function vendor_doc_add($db, int $orgId, int $vendorId, array $d, string $storedPath = ''): int
    {
        if (_vendor_owned_row($db, $orgId, $vendorId) === null) { return 0; }
        $type = isset(vendor_doc_types()[$d['doc_type'] ?? '']) ? $d['doc_type'] : 'other';
        $title = trim((string) ($d['title'] ?? '')) ?: vendor_doc_types()[$type];
        $exp = trim((string) ($d['expiry_date'] ?? ''));
        try {
            $db->insert('stays_vendor_docs', [
                'org_id'      => $orgId,
                'vendor_id'   => $vendorId,
                'doc_type'    => $type,
                'title'       => substr($title, 0, 191),
                'reference'   => substr(trim((string) ($d['reference'] ?? '')), 0, 120) ?: null,
                'file_path'   => $storedPath !== '' ? substr($storedPath, 0, 255) : null,
                'issued_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) ($d['issued_date'] ?? ''))) ? trim((string) $d['issued_date']) : null,
                'expiry_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) ? $exp : null,
                'status'      => 1,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('vendor_doc_add: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('vendor_doc_list')) {
    /** Compliance docs for the org (optionally one vendor). Read-only. */
    function vendor_doc_list($db, int $orgId, int $vendorId = 0): array
    {
        if ($orgId <= 0) { return []; }
        $where = ['org_id' => $orgId, 'status' => 1];
        if ($vendorId > 0) { $where['vendor_id'] = $vendorId; }
        $where['ORDER'] = ['expiry_date' => 'ASC'];
        try { return $db->select('stays_vendor_docs', '*', $where) ?: []; }
        catch (\Throwable $e) { error_log('vendor_doc_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('vendor_doc_reminders')) {
    /**
     * Compliance reminders across the org: documents expiring within $days (default 60,
     * includes already-expired) AND contracts ending within the same window. Read-only.
     * Returns ['docs'=>[...], 'contracts'=>[...], 'as_of'=>'YYYY-MM-DD', 'window_days'=>int].
     */
    function vendor_doc_reminders($db, int $orgId, int $days = 60): array
    {
        $out = ['docs' => [], 'contracts' => [], 'as_of' => date('Y-m-d'), 'window_days' => max(1, $days)];
        if ($orgId <= 0) { return $out; }
        $soon = date('Y-m-d', strtotime('+' . max(1, $days) . ' days'));
        $vendorNames = [];
        try {
            foreach ($db->select('stays_vendors', ['id', 'name'], ['org_id' => $orgId]) ?: [] as $v) {
                $vendorNames[(int) $v['id']] = (string) $v['name'];
            }
            $docs = $db->select('stays_vendor_docs', '*',
                ['AND' => ['org_id' => $orgId, 'status' => 1, 'expiry_date[<=]' => $soon], 'expiry_date[!]' => null,
                 'ORDER' => ['expiry_date' => 'ASC']]) ?: [];
            foreach ($docs as $d) { $d['vendor_name'] = $vendorNames[(int) $d['vendor_id']] ?? ('#' . $d['vendor_id']); $out['docs'][] = $d; }

            $cons = $db->select('stays_vendor_contracts', '*',
                ['AND' => ['org_id' => $orgId, 'status' => 'active', 'end_date[<=]' => $soon], 'end_date[!]' => null,
                 'ORDER' => ['end_date' => 'ASC']]) ?: [];
            foreach ($cons as $c) { $c['vendor_name'] = $vendorNames[(int) $c['vendor_id']] ?? ('#' . $c['vendor_id']); $out['contracts'][] = $c; }
        } catch (\Throwable $e) { error_log('vendor_doc_reminders: ' . $e->getMessage()); }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// VENDOR INVOICES + PAYMENT — an invoice is a payable document the vendor raises. Recording
// it does NOT post to the GL (the PO's goods-receipt already booked AP). PAYING an invoice
// posts DR 2000 (AP) / CR 1000 (Cash) and flips the invoice paid, both inside one
// transaction under a row lock (idempotent on the gl reference). The external bank transfer
// is DB-GATED / not connected.
// ---------------------------------------------------------------------------
if (!function_exists('vendor_invoice_add')) {
    /** Record a vendor invoice. Amount is server-rounded. Optionally links a PO. Returns id. */
    function vendor_invoice_add($db, int $orgId, int $vendorId, array $d): int
    {
        if (_vendor_owned_row($db, $orgId, $vendorId) === null) { return 0; }
        $amount = round((float) ($d['amount'] ?? 0), 2);
        if ($amount <= 0) { return 0; }
        $poId = (int) ($d['po_id'] ?? 0);
        if ($poId > 0) {
            try { if (!$db->has('stays_purchase_orders', ['id' => $poId, 'org_id' => $orgId, 'vendor_id' => $vendorId])) { $poId = 0; } }
            catch (\Throwable $e) { $poId = 0; }
        }
        $inv = trim((string) ($d['invoice_no'] ?? ''));
        $due = trim((string) ($d['due_date'] ?? ''));
        try {
            $db->insert('stays_vendor_invoices', [
                'org_id'       => $orgId,
                'vendor_id'    => $vendorId,
                'po_id'        => $poId > 0 ? $poId : null,
                'invoice_no'   => substr($inv, 0, 80) ?: null,
                'amount'       => $amount,
                'currency'     => strtoupper(substr((string) ($d['currency'] ?? 'USD'), 0, 3)),
                'invoice_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) ($d['invoice_date'] ?? ''))) ? trim((string) $d['invoice_date']) : date('Y-m-d'),
                'due_date'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null,
                'status'       => 'unpaid',
                'notes'        => substr(trim((string) ($d['notes'] ?? '')), 0, 500) ?: null,
                'created_by'   => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('vendor_invoice_add: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('vendor_invoice_list')) {
    /** Vendor invoices for the org (optionally one vendor). Read-only. */
    function vendor_invoice_list($db, int $orgId, int $vendorId = 0): array
    {
        if ($orgId <= 0) { return []; }
        $where = ['org_id' => $orgId];
        if ($vendorId > 0) { $where['vendor_id'] = $vendorId; }
        $where['ORDER'] = ['id' => 'DESC'];
        $where['LIMIT'] = 200;
        try { return $db->select('stays_vendor_invoices', '*', $where) ?: []; }
        catch (\Throwable $e) { error_log('vendor_invoice_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('vendor_invoice_pay')) {
    /**
     * Mark a vendor invoice PAID and post the settlement to the GL:
     *   DR 2000 (Accounts Payable)  /  CR 1000 (Cash / Bank)   for the invoice amount.
     * Atomic + idempotent:
     *   - locks the invoice row FOR UPDATE inside $db->action();
     *   - refuses if already paid (so a double submit cannot post twice);
     *   - gl_post is idempotent on (org,'vendorpay',reference) as a second guard;
     *   - a failed GL post rolls the whole thing back (invoice stays unpaid).
     * The actual bank disbursement to the vendor is NOT performed here (DB-GATED).
     * Returns ['ok'=>bool,'message'=>string].
     */
    function vendor_invoice_pay($db, int $orgId, int $invoiceId, string $method = 'bank'): array
    {
        if ($orgId <= 0 || $invoiceId <= 0) { return ['ok' => false, 'message' => 'Bad input']; }
        if (!function_exists('gl_post')) { return ['ok' => false, 'message' => 'Ledger unavailable']; }
        $result = ['ok' => false, 'message' => 'Payment failed'];
        try {
            $db->action(function ($db) use ($orgId, $invoiceId, $method, &$result) {
                $locked = $db->query('SELECT * FROM stays_vendor_invoices WHERE id=:i AND org_id=:o FOR UPDATE',
                    [':i' => $invoiceId, ':o' => $orgId]);
                $inv = $locked ? $locked->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$inv) { $result = ['ok' => false, 'message' => 'Invoice not found.']; return false; }
                if (($inv['status'] ?? '') === 'paid') { $result = ['ok' => false, 'message' => 'Invoice already paid.']; return false; }
                $amount = round((float) $inv['amount'], 2);
                if ($amount <= 0) { $result = ['ok' => false, 'message' => 'Invoice amount is zero.']; return false; }
                $currency = (string) ($inv['currency'] ?? 'USD');
                $ref = 'VINV-' . $invoiceId;

                $gl = gl_post($db, $orgId, [
                    ['code' => '2000', 'debit' => $amount],   // settle Accounts Payable
                    ['code' => '1000', 'credit' => $amount],  // out of Cash / Bank
                ], ['source' => 'vendorpay', 'reference' => $ref, 'currency' => $currency,
                    'memo' => 'Vendor invoice payment ' . ($inv['invoice_no'] ?? ('#' . $invoiceId))]);
                if (empty($gl['ok'])) {
                    error_log('vendor_invoice_pay GL: ' . ($gl['message'] ?? '?'));
                    $result = ['ok' => false, 'message' => 'Could not post payment to the ledger; nothing changed.'];
                    return false; // rollback
                }

                $db->update('stays_vendor_invoices', [
                    'status'       => 'paid',
                    'paid_at'      => date('Y-m-d H:i:s'),
                    'pay_method'   => substr(trim($method), 0, 40) ?: 'bank',
                    'gl_reference' => $ref,
                    'updated_at'   => date('Y-m-d H:i:s'),
                ], ['id' => $invoiceId, 'org_id' => $orgId, 'status' => 'unpaid']);

                $result = ['ok' => true, 'message' => 'Invoice recorded as paid and posted to the ledger. '
                    . 'Note: the bank transfer to the vendor is not connected — disburse it in your bank.'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('vendor_invoice_pay: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Payment error.'];
        }
        return $result;
    }
}

if (!function_exists('vendor_disbursement_status')) {
    /**
     * HONEST STUB. There is no vendor-payout gateway wired (no NIP/ACH transfer provider),
     * so the platform cannot actually move money to the vendor's bank. vendor_invoice_pay()
     * records the payment + books the ledger; the real transfer is manual. This reports that
     * truthfully so the UI never implies a live integration. DB-GATED.
     */
    function vendor_disbursement_status(): array
    {
        return [
            'connected' => false,
            'provider'  => null,
            'message'   => 'Vendor bank disbursement is not connected. Invoice payments are recorded '
                         . 'and posted to the ledger only — transfer funds to the vendor via your bank.',
        ];
    }
}

<?php
// FILE: app/lib/supplier_ap_ext.php
// ACCOUNTS PAYABLE — DEEPENING (PMS #36). Builds the genuine gaps on top of the
// existing AP surface (ap_summary() in supplier_procurement.php, which only derives
// gross-owed from RECEIVED purchase orders and has no way to RECORD a vendor payment).
//
// What this adds (all per org, all reusing the GL spine gl_post / chart_of_accounts):
//   1. Standalone VENDOR INVOICES — bills NOT tied to a PO/GRN (utilities, services,
//      rent). A PO/GRN already books DR 6000 / CR 2000 on receipt, so to avoid
//      double-booking AP these invoices are deliberately PO-independent.
//   2. Invoice APPROVAL workflow — draft -> approved (posts DR 6000 / CR 2000) ->
//      (then pay). Posting is idempotent on (source='apinv', reference=invoice ref).
//   3. AP AGEING — buckets approved, not-fully-paid invoices by due date.
//   4. Payment SCHEDULING — a planned pay-date on an approved invoice (internal record).
//   5. Recorded VENDOR PAYMENTS — settling AP: DR 2000 / CR 1000, decrements the
//      invoice outstanding. Concurrency-safe (FOR UPDATE the invoice row) so a
//      double-click can never over-pay. Never pays more than the outstanding.
//   6. Per-vendor STATEMENT — invoices + payments + running balance.
//   7. Gateway DISBURSEMENT — an HONEST stub that reports 'not connected'. It records
//      the INTERNAL payment (same as a manual payment) and never pretends a bank
//      transfer happened.
//
// MONEY RULES honoured: amounts are server-computed (invoice.amount from its own row;
// a payment is clamped to the current outstanding); GL is posted ONLY via gl_post()
// (which refuses any unbalanced entry); the approve/pay writes that touch GL + status
// are wrapped in $db->action() with a FOR UPDATE re-check. All functions non-fatal and
// org-scoped; ownership is enforced by the caller routes.
//
// DB-GATED: requires the additive tables stays_vendor_invoices + stays_vendor_payments
// (see the patch spec schema_sql). Every function degrades to a safe empty/false when
// the tables are absent.

if (!function_exists('ap_inv_statuses')) {
    /** Lifecycle of a vendor invoice. */
    function ap_inv_statuses(): array
    {
        return ['draft', 'approved', 'paid', 'void'];
    }
}

if (!function_exists('ap_invoice_create')) {
    /**
     * Record a DRAFT vendor invoice (a bill the property owes). No GL yet — posting
     * happens at approval. Amount is taken from the server-side $d, rounded to 2dp;
     * never trusts a client "outstanding". Vendor must belong to the org.
     * Returns the new invoice id (0 on failure).
     */
    function ap_invoice_create($db, int $orgId, array $d): int
    {
        if ($orgId <= 0) { return 0; }
        $vendorId = (int) ($d['vendor_id'] ?? 0);
        $amount   = round((float) ($d['amount'] ?? 0), 2);
        if ($vendorId <= 0 || $amount <= 0) { return 0; }
        try {
            if (!$db->has('stays_vendors', ['id' => $vendorId, 'org_id' => $orgId])) { return 0; }
            $issue = (string) ($d['issue_date'] ?? date('Y-m-d'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue)) { $issue = date('Y-m-d'); }
            $due = (string) ($d['due_date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) { $due = $issue; }
            $ref = 'AP-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            $db->insert('stays_vendor_invoices', [
                'org_id'       => $orgId,
                'vendor_id'    => $vendorId,
                'reference'    => $ref,
                'vendor_ref'   => substr(trim((string) ($d['vendor_ref'] ?? '')), 0, 100) ?: null,
                'currency'     => strtoupper(substr((string) ($d['currency'] ?? 'USD'), 0, 3)),
                'amount'       => $amount,
                'paid'         => 0,
                'issue_date'   => $issue,
                'due_date'     => $due,
                'scheduled_pay_date' => null,
                'status'       => 'draft',
                'memo'         => substr(trim((string) ($d['memo'] ?? '')), 0, 255) ?: null,
                'created_by'   => (string) ($_SESSION['user_id'] ?? ''),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('ap_invoice_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('ap_invoice_approve')) {
    /**
     * Approve a DRAFT invoice: post the expense + payable to the GL and flip status to
     * 'approved'. Balanced entry DR 6000 (Operating Expense) / CR 2000 (Accounts
     * Payable) for the invoice amount. Atomic + state-guarded (FOR UPDATE re-check so
     * two approvers can't both post). gl_post is idempotent on (apinv, reference).
     * Returns ['ok','message','entry_id'?].
     */
    function ap_invoice_approve($db, int $orgId, int $invoiceId): array
    {
        if ($orgId <= 0 || $invoiceId <= 0) { return ['ok' => false, 'message' => 'Bad request.']; }
        if (!function_exists('gl_post')) { return ['ok' => false, 'message' => 'Ledger unavailable.']; }
        $result = ['ok' => false, 'message' => 'Approve failed.'];
        try {
            $db->action(function ($db) use ($orgId, $invoiceId, &$result) {
                $lock = $db->query('SELECT id, reference, amount, currency, issue_date, status
                                    FROM stays_vendor_invoices WHERE id = :i AND org_id = :o FOR UPDATE',
                    [':i' => $invoiceId, ':o' => $orgId]);
                $inv = $lock ? $lock->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$inv) { $result = ['ok' => false, 'message' => 'Invoice not found.']; return false; }
                if ($inv['status'] !== 'draft') {
                    $result = ['ok' => false, 'message' => 'Only a draft invoice can be approved (this is ' . $inv['status'] . ').'];
                    return false;
                }
                $amount = round((float) $inv['amount'], 2);
                if ($amount <= 0) { $result = ['ok' => false, 'message' => 'Invoice amount must be positive.']; return false; }

                $gl = gl_post($db, $orgId, [
                    ['code' => '6000', 'debit'  => $amount],   // expense recognised
                    ['code' => '2000', 'credit' => $amount],   // accounts payable (owed to vendor)
                ], ['source' => 'apinv', 'reference' => (string) $inv['reference'], 'currency' => (string) $inv['currency'],
                    'date' => (string) $inv['issue_date'], 'memo' => 'Vendor invoice ' . $inv['reference']]);
                if (empty($gl['ok'])) {
                    error_log('ap_invoice_approve GL: ' . ($gl['message'] ?? '?'));
                    $result = ['ok' => false, 'message' => 'Could not post to the ledger: ' . ($gl['message'] ?? 'error') . '. Nothing changed.'];
                    return false; // rolls back
                }
                $db->update('stays_vendor_invoices',
                    ['status' => 'approved', 'approved_by' => (string) ($_SESSION['user_id'] ?? ''),
                     'approved_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => $invoiceId, 'org_id' => $orgId, 'status' => 'draft']);
                $result = ['ok' => true, 'message' => 'Invoice approved and posted to the ledger.', 'entry_id' => $gl['entry_id'] ?? null];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('ap_invoice_approve: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Approve error.'];
        }
        return $result;
    }
}

if (!function_exists('ap_invoice_void')) {
    /**
     * Void a DRAFT invoice only (no GL has been posted yet, so nothing to reverse).
     * An approved/paid invoice has ledger postings and must be reversed with a credit
     * note / payment flow — out of scope here. State-guarded. Returns bool.
     */
    function ap_invoice_void($db, int $orgId, int $invoiceId): bool
    {
        if ($orgId <= 0 || $invoiceId <= 0) { return false; }
        try {
            $inv = $db->get('stays_vendor_invoices', ['id', 'status'], ['id' => $invoiceId, 'org_id' => $orgId]);
            if (!$inv || $inv['status'] !== 'draft') { return false; }
            $upd = $db->update('stays_vendor_invoices',
                ['status' => 'void', 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $invoiceId, 'org_id' => $orgId, 'status' => 'draft']);
            return $upd && $upd->rowCount() === 1;
        } catch (\Throwable $e) { error_log('ap_invoice_void: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('ap_invoice_schedule')) {
    /**
     * Set / clear the planned payment date on an APPROVED, not-fully-paid invoice
     * (payment scheduling — an internal planning record, no gateway). Pass '' to clear.
     * Returns bool.
     */
    function ap_invoice_schedule($db, int $orgId, int $invoiceId, string $date): bool
    {
        if ($orgId <= 0 || $invoiceId <= 0) { return false; }
        $val = null;
        if ($date !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return false; }
            $val = $date;
        }
        try {
            $inv = $db->get('stays_vendor_invoices', ['id', 'status'], ['id' => $invoiceId, 'org_id' => $orgId]);
            if (!$inv || $inv['status'] !== 'approved') { return false; }
            $db->update('stays_vendor_invoices',
                ['scheduled_pay_date' => $val, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $invoiceId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('ap_invoice_schedule: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('ap_invoice_pay')) {
    /**
     * Record a vendor PAYMENT against an APPROVED invoice and post it to the GL:
     * DR 2000 (Accounts Payable) / CR 1000 (Cash / Bank). The paid amount is CLAMPED to
     * the current outstanding (amount - paid) — a client can never over-pay or set a
     * negative amount. Concurrency-safe: the invoice row is locked FOR UPDATE, the
     * outstanding re-read inside the transaction, GL posted, invoice.paid incremented,
     * and status flipped to 'paid' when fully settled — all atomically. Idempotency key
     * for the GL is the generated payment reference.
     *
     *   $method  internal label: 'manual' | 'bank' | 'cash' | 'gateway'
     * Returns ['ok','message','payment_id'?,'outstanding'?].
     */
    function ap_invoice_pay($db, int $orgId, int $invoiceId, float $amount, string $method = 'manual', string $note = ''): array
    {
        if ($orgId <= 0 || $invoiceId <= 0) { return ['ok' => false, 'message' => 'Bad request.']; }
        if (!function_exists('gl_post')) { return ['ok' => false, 'message' => 'Ledger unavailable.']; }
        $method = in_array($method, ['manual', 'bank', 'cash', 'gateway'], true) ? $method : 'manual';
        $want = round($amount, 2);
        if ($want <= 0) { return ['ok' => false, 'message' => 'Payment amount must be positive.']; }

        $result = ['ok' => false, 'message' => 'Payment failed.'];
        try {
            $db->action(function ($db) use ($orgId, $invoiceId, $want, $method, $note, &$result) {
                $lock = $db->query('SELECT id, vendor_id, reference, currency, amount, paid, status
                                    FROM stays_vendor_invoices WHERE id = :i AND org_id = :o FOR UPDATE',
                    [':i' => $invoiceId, ':o' => $orgId]);
                $inv = $lock ? $lock->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$inv) { $result = ['ok' => false, 'message' => 'Invoice not found.']; return false; }
                if ($inv['status'] !== 'approved') {
                    $result = ['ok' => false, 'message' => 'Only an approved invoice can be paid (this is ' . $inv['status'] . ').'];
                    return false;
                }
                $outstanding = round((float) $inv['amount'] - (float) $inv['paid'], 2);
                if ($outstanding <= 0) { $result = ['ok' => false, 'message' => 'Nothing outstanding.']; return false; }
                // CLAMP: never pay more than owed — the server decides the amount.
                $pay = min($want, $outstanding);
                $pay = round($pay, 2);
                if ($pay <= 0) { $result = ['ok' => false, 'message' => 'Nothing to pay.']; return false; }

                $payRef = 'APP-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 7));
                $gl = gl_post($db, $orgId, [
                    ['code' => '2000', 'debit'  => $pay],   // reduce accounts payable
                    ['code' => '1000', 'credit' => $pay],   // cash / bank out
                ], ['source' => 'appay', 'reference' => $payRef, 'currency' => (string) $inv['currency'],
                    'memo' => 'Vendor payment ' . $inv['reference'] . ' (' . $method . ')']);
                if (empty($gl['ok'])) {
                    error_log('ap_invoice_pay GL: ' . ($gl['message'] ?? '?'));
                    $result = ['ok' => false, 'message' => 'Could not post the payment to the ledger. Nothing changed.'];
                    return false;
                }

                $db->insert('stays_vendor_payments', [
                    'org_id'      => $orgId,
                    'invoice_id'  => $invoiceId,
                    'vendor_id'   => (int) $inv['vendor_id'],
                    'reference'   => $payRef,
                    'currency'    => (string) $inv['currency'],
                    'amount'      => $pay,
                    'method'      => $method,
                    'status'      => 'recorded', // internal; a real disbursement is DB-GATED (see ap_gateway_disburse)
                    'note'        => substr(trim($note), 0, 255) ?: null,
                    'gl_entry_id' => $gl['entry_id'] ?? null,
                    'paid_by'     => (string) ($_SESSION['user_id'] ?? ''),
                    'created_at'  => date('Y-m-d H:i:s'),
                ]);
                $paymentId = (int) $db->id();

                $newPaid = round((float) $inv['paid'] + $pay, 2);
                $fully = ($newPaid + 0.001) >= (float) $inv['amount'];
                $db->update('stays_vendor_invoices',
                    ['paid' => $newPaid, 'status' => $fully ? 'paid' : 'approved',
                     'updated_at' => date('Y-m-d H:i:s')],
                    ['id' => $invoiceId, 'org_id' => $orgId]);

                $result = ['ok' => true, 'payment_id' => $paymentId,
                    'outstanding' => round((float) $inv['amount'] - $newPaid, 2),
                    'message' => 'Payment of ' . number_format($pay, 2) . ' recorded' . ($fully ? ' — invoice fully paid.' : '.')];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('ap_invoice_pay: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Payment error.'];
        }
        return $result;
    }
}

if (!function_exists('ap_gateway_disburse')) {
    /**
     * HONEST STUB — vendor bank disbursement via a payout gateway (e.g. Paystack
     * Transfers). No bank/transfer integration is wired here, so this NEVER contacts a
     * gateway and NEVER fabricates a transfer. It reports 'not connected' and does
     * nothing. When a real disbursement rail is added, this should: resolve the vendor
     * bank_code/account_number, create the transfer, and on gateway confirmation call
     * ap_invoice_pay(...,'gateway') to post the GL + record the payment.
     * Returns ['ok'=>false,'connected'=>false,'message'=>...].
     */
    function ap_gateway_disburse($db, int $orgId, int $invoiceId, float $amount): array
    {
        return [
            'ok' => false,
            'connected' => false,
            'message' => 'Bank disbursement is not connected. Record the payment manually once the transfer clears, or wire a payout gateway first.',
        ];
    }
}

if (!function_exists('ap_invoices_list')) {
    /**
     * Invoices for an org (optionally one vendor / one status), newest first, with a
     * derived 'outstanding'. Read-only. Returns rows (possibly empty).
     */
    function ap_invoices_list($db, int $orgId, array $filter = [], int $limit = 200): array
    {
        if ($orgId <= 0) { return []; }
        $where = ['org_id' => $orgId];
        if (!empty($filter['vendor_id'])) { $where['vendor_id'] = (int) $filter['vendor_id']; }
        if (!empty($filter['status']) && in_array($filter['status'], ap_inv_statuses(), true)) { $where['status'] = $filter['status']; }
        $where['ORDER'] = ['id' => 'DESC'];
        $where['LIMIT'] = max(1, min(1000, $limit));
        try {
            $rows = $db->select('stays_vendor_invoices',
                ['id', 'vendor_id', 'reference', 'vendor_ref', 'currency', 'amount', 'paid',
                 'issue_date', 'due_date', 'scheduled_pay_date', 'status', 'memo', 'created_at'],
                $where) ?: [];
            foreach ($rows as &$r) { $r['outstanding'] = round((float) $r['amount'] - (float) $r['paid'], 2); }
            unset($r);
            return $rows;
        } catch (\Throwable $e) { error_log('ap_invoices_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('ap_ageing')) {
    /**
     * AP AGEING (PMS #36): outstanding on APPROVED (not fully paid) vendor invoices,
     * bucketed by days-overdue relative to due_date. Buckets: current (not yet due),
     * d1_30, d31_60, d61_90, d90_plus. Read-only. Returns
     *   ['buckets'=>[...=>float], 'total'=>float, 'currency'=>str, 'rows'=>[per-invoice]].
     */
    function ap_ageing($db, int $orgId): array
    {
        $out = ['buckets' => ['current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0],
                'total' => 0.0, 'currency' => 'USD', 'rows' => []];
        if ($orgId <= 0) { return $out; }
        try {
            $today = new \DateTimeImmutable(date('Y-m-d'));
            $invs = $db->select('stays_vendor_invoices',
                ['id', 'vendor_id', 'reference', 'currency', 'amount', 'paid', 'due_date'],
                ['org_id' => $orgId, 'status' => 'approved']) ?: [];
            if (empty($invs)) { return $out; }
            $vendorNames = [];
            foreach ($db->select('stays_vendors', ['id', 'name'], ['org_id' => $orgId]) ?: [] as $v) {
                $vendorNames[(int) $v['id']] = (string) $v['name'];
            }
            foreach ($invs as $inv) {
                $outstanding = round((float) $inv['amount'] - (float) $inv['paid'], 2);
                if ($outstanding <= 0) { continue; }
                $due = (string) ($inv['due_date'] ?? '');
                $daysOver = 0;
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
                    $d = new \DateTimeImmutable($due);
                    $daysOver = (int) $today->diff($d)->format('%r%a');
                    $daysOver = -$daysOver; // positive = overdue
                }
                if ($daysOver <= 0) { $bucket = 'current'; }
                elseif ($daysOver <= 30) { $bucket = 'd1_30'; }
                elseif ($daysOver <= 60) { $bucket = 'd31_60'; }
                elseif ($daysOver <= 90) { $bucket = 'd61_90'; }
                else { $bucket = 'd90_plus'; }
                $out['buckets'][$bucket] = round($out['buckets'][$bucket] + $outstanding, 2);
                $out['total'] = round($out['total'] + $outstanding, 2);
                if (!empty($inv['currency'])) { $out['currency'] = strtoupper((string) $inv['currency']); }
                $out['rows'][] = [
                    'invoice_id' => (int) $inv['id'],
                    'reference'  => (string) $inv['reference'],
                    'vendor'     => $vendorNames[(int) $inv['vendor_id']] ?? ('#' . (int) $inv['vendor_id']),
                    'due_date'   => $due,
                    'days_over'  => max(0, $daysOver),
                    'bucket'     => $bucket,
                    'outstanding' => $outstanding,
                    'currency'   => (string) ($inv['currency'] ?? 'USD'),
                ];
            }
            usort($out['rows'], fn($a, $b) => $b['days_over'] <=> $a['days_over']);
        } catch (\Throwable $e) { error_log('ap_ageing: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('ap_vendor_statement')) {
    /**
     * Per-vendor STATEMENT: every invoice (approved/paid) and payment for a vendor,
     * merged into a date-ordered ledger with a running balance (invoice +, payment -).
     * Read-only, org-scoped. Returns
     *   ['vendor'=>[id,name], 'lines'=>[{date,type,reference,debit,credit,balance}],
     *    'opening'=>0, 'closing'=>float, 'currency'=>str].
     */
    function ap_vendor_statement($db, int $orgId, int $vendorId): array
    {
        $out = ['vendor' => null, 'lines' => [], 'opening' => 0.0, 'closing' => 0.0, 'currency' => 'USD'];
        if ($orgId <= 0 || $vendorId <= 0) { return $out; }
        try {
            $v = $db->get('stays_vendors', ['id', 'name'], ['id' => $vendorId, 'org_id' => $orgId]);
            if (!$v) { return $out; }
            $out['vendor'] = ['id' => (int) $v['id'], 'name' => (string) $v['name']];
            $events = [];
            // Invoices that hit AP (approved + paid). A draft/void invoice has no AP impact.
            foreach ($db->select('stays_vendor_invoices',
                ['reference', 'currency', 'amount', 'issue_date', 'status'],
                ['org_id' => $orgId, 'vendor_id' => $vendorId, 'status' => ['approved', 'paid']]) ?: [] as $inv) {
                $events[] = ['date' => (string) $inv['issue_date'], 'type' => 'invoice',
                    'reference' => (string) $inv['reference'], 'amount' => round((float) $inv['amount'], 2),
                    'currency' => (string) $inv['currency']];
            }
            foreach ($db->select('stays_vendor_payments',
                ['reference', 'currency', 'amount', 'created_at'],
                ['org_id' => $orgId, 'vendor_id' => $vendorId]) ?: [] as $pay) {
                $events[] = ['date' => substr((string) $pay['created_at'], 0, 10), 'type' => 'payment',
                    'reference' => (string) $pay['reference'], 'amount' => -round((float) $pay['amount'], 2),
                    'currency' => (string) $pay['currency']];
            }
            usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));
            $bal = 0.0;
            foreach ($events as $e) {
                $bal = round($bal + $e['amount'], 2);
                if (!empty($e['currency'])) { $out['currency'] = strtoupper($e['currency']); }
                $out['lines'][] = [
                    'date'      => $e['date'],
                    'type'      => $e['type'],
                    'reference' => $e['reference'],
                    'debit'     => $e['type'] === 'invoice' ? round($e['amount'], 2) : 0.0,  // owed
                    'credit'    => $e['type'] === 'payment' ? round(-$e['amount'], 2) : 0.0, // paid
                    'balance'   => $bal,
                ];
            }
            $out['closing'] = $bal;
        } catch (\Throwable $e) { error_log('ap_vendor_statement: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('ap_dashboard')) {
    /**
     * One call for the AP ext page: ageing + full invoice list + vendor directory +
     * the gateway connection flag. Read-only aggregator.
     */
    function ap_dashboard($db, int $orgId): array
    {
        return [
            'ageing'   => function_exists('ap_ageing') ? ap_ageing($db, $orgId) : ['buckets' => [], 'total' => 0, 'rows' => []],
            'invoices' => function_exists('ap_invoices_list') ? ap_invoices_list($db, $orgId) : [],
            'vendors'  => $orgId > 0 ? ($db->select('stays_vendors', ['id', 'name', 'payment_terms'], ['org_id' => $orgId, 'status' => 1, 'ORDER' => ['name' => 'ASC']]) ?: []) : [],
            'summary'  => function_exists('ap_summary') ? ap_summary($db, $orgId) : ['vendors' => [], 'total' => 0, 'currency' => 'USD'],
            'gateway_connected' => false, // DB-GATED: no disbursement rail wired (see ap_gateway_disburse)
        ];
    }
}

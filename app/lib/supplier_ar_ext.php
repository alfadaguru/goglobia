<?php
// FILE: app/lib/supplier_ar_ext.php
// MODULE 35 DEEPEN — Accounts receivable (city ledger).
//
// The BASE already ships (do NOT rebuild):
//   - ar_subledger($db,$orgId)   (supplier_ledger.php) — open folios carrying a balance
//   - gl_post()                  (supplier_ledger.php) — the double-entry poster
//   - folio_add_item()           (supplier_folio.php)  — folio charge/payment/credit lines
//   - corp_* / credit_limit      (supplier_corporate.php) — corporate/agent accounts
//
// This file builds the genuine AR GAPS on TOP of those primitives, org-scoped
// (owner-level, same as finance/corporate/trust):
//   - AR AGEING buckets (current / 1-30 / 31-60 / 61-90 / 90+) over open folios
//   - CUSTOMER STATEMENTS — open items + ageing for ONE corporate (city-ledger) account
//   - A folio↔corporate LINK so city-ledger receivables roll up to the account
//   - PAYMENT ALLOCATION — record a receipt and allocate it across open folios, posting
//     BOTH a folio `payment` line (so folio_totals drops) AND a balanced GL entry
//     (Dr Cash 1000 / Cr City-Ledger-AR 1200) atomically
//   - WRITE-OFF — write a bad debt off: folio `credit_note` + GL (Dr Op-Expense 6000 /
//     Cr AR 1200) atomically, so the receivable AND the GL both clear
//   - COLLECTIONS — a per-account follow-up/promise-to-pay log; the "send reminder"
//     edge is an HONEST stub that reports 'not connected' (comms wiring is DB-GATED).
//
// Money rules honoured: amounts are NEVER trusted from the client beyond being a
// positive number; GL is NEVER posted unbalanced (gl_post refuses it); the two writes
// per receipt/write-off are wrapped in one $db->action() so a half-apply can't persist.
// All functions are defensive/non-fatal and if_not_exists-guarded.

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------
if (!function_exists('ar_ext_age_bucket')) {
    /** Map an age in whole days to a bucket key. Pure helper (harness-friendly). */
    function ar_ext_age_bucket(int $days): string
    {
        if ($days <= 0)  { return 'current'; }
        if ($days <= 30) { return 'd1_30'; }
        if ($days <= 60) { return 'd31_60'; }
        if ($days <= 90) { return 'd61_90'; }
        return 'd90_plus';
    }
}

if (!function_exists('ar_ext_bucket_labels')) {
    /** Ordered bucket key => display label. */
    function ar_ext_bucket_labels(): array
    {
        return [
            'current'  => 'Current',
            'd1_30'    => '1–30 days',
            'd31_60'   => '31–60 days',
            'd61_90'   => '61–90 days',
            'd90_plus' => '90+ days',
        ];
    }
}

if (!function_exists('ar_ext_open_folios')) {
    /**
     * The org's OPEN folios that still carry a positive balance, with their age and
     * (if linked) their corporate account. Read-only. This is the shared primitive the
     * ageing + statements both build on. Age is measured from the folio's created_at
     * (fallback: today → age 0). Returns rows:
     *   ['folio_id','invoice_id','guest','currency','balance','age_days','bucket',
     *    'account_id'(int|0),'account_name'(string|'')]
     */
    function ar_ext_open_folios($db, int $orgId, int $limit = 1000): array
    {
        $rows = [];
        if ($orgId <= 0) { return $rows; }
        try {
            $folios = $db->select('stays_folios',
                ['id', 'invoice_id', 'guest_name', 'currency', 'created_at'],
                ['org_id' => $orgId, 'status' => 'open', 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
            // Pre-load folio→account links for this org in one pass (table is DB-GATED;
            // absent on an un-migrated install → treat everything as unlinked/guest AR).
            $links = [];
            try {
                $lk = $db->select('stays_ar_folio_account', ['folio_id', 'account_id'], ['org_id' => $orgId]) ?: [];
                foreach ($lk as $l) { $links[(int) $l['folio_id']] = (int) $l['account_id']; }
            } catch (\Throwable $e) { /* table not migrated yet — fine */ }
            $acctNames = [];
            $now = new DateTimeImmutable('today');
            foreach ($folios as $f) {
                $fid = (int) $f['id'];
                $tot = function_exists('folio_totals') ? folio_totals($db, $fid) : ['balance' => 0];
                $bal = round((float) ($tot['balance'] ?? 0), 2);
                if ($bal <= 0) { continue; }
                $created = (string) ($f['created_at'] ?? '');
                $age = 0;
                if ($created !== '') {
                    try { $age = max(0, (int) $now->diff(new DateTimeImmutable(substr($created, 0, 10)))->format('%r%a')); }
                    catch (\Throwable $e) { $age = 0; }
                }
                $acctId = (int) ($links[$fid] ?? 0);
                $acctName = '';
                if ($acctId > 0) {
                    if (!array_key_exists($acctId, $acctNames)) {
                        try {
                            $a = $db->get('stays_corporate_accounts', ['name'], ['id' => $acctId, 'org_id' => $orgId]);
                            $acctNames[$acctId] = $a ? (string) $a['name'] : '';
                        } catch (\Throwable $e) { $acctNames[$acctId] = ''; }
                    }
                    $acctName = (string) $acctNames[$acctId];
                }
                $rows[] = [
                    'folio_id'     => $fid,
                    'invoice_id'   => (string) $f['invoice_id'],
                    'guest'        => (string) ($f['guest_name'] ?? '') ?: (string) $f['invoice_id'],
                    'currency'     => (string) ($f['currency'] ?? 'USD'),
                    'balance'      => $bal,
                    'age_days'     => $age,
                    'bucket'       => ar_ext_age_bucket($age),
                    'account_id'   => $acctId,
                    'account_name' => $acctName,
                ];
            }
        } catch (\Throwable $e) { error_log('ar_ext_open_folios: ' . $e->getMessage()); }
        return $rows;
    }
}

// ---------------------------------------------------------------------------
// AR AGEING
// ---------------------------------------------------------------------------
if (!function_exists('ar_ext_ageing')) {
    /**
     * AR ageing summary for the org, SEGREGATED BY CURRENCY (never net two currencies).
     * Read-only. Returns:
     *   ['by_currency' => [ 'USD' => ['current'=>f,'d1_30'=>f,...,'d90_plus'=>f,'total'=>f], ...],
     *    'grand_total' => [ 'USD'=>f, ... ] ,
     *    'folios' => [ open-folio rows from ar_ext_open_folios ] ]
     */
    function ar_ext_ageing($db, int $orgId): array
    {
        $out = ['by_currency' => [], 'grand_total' => [], 'folios' => []];
        if ($orgId <= 0) { return $out; }
        $folios = ar_ext_open_folios($db, $orgId);
        $out['folios'] = $folios;
        foreach ($folios as $r) {
            $cur = $r['currency'] ?: 'USD';
            if (!isset($out['by_currency'][$cur])) {
                $out['by_currency'][$cur] = ['current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0, 'total' => 0.0];
            }
            $out['by_currency'][$cur][$r['bucket']] = round($out['by_currency'][$cur][$r['bucket']] + $r['balance'], 2);
            $out['by_currency'][$cur]['total'] = round($out['by_currency'][$cur]['total'] + $r['balance'], 2);
            $out['grand_total'][$cur] = round(($out['grand_total'][$cur] ?? 0) + $r['balance'], 2);
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// FOLIO ↔ CORPORATE ACCOUNT LINK (city ledger assignment)   [DB-GATED table]
// ---------------------------------------------------------------------------
if (!function_exists('ar_ext_assign_account')) {
    /**
     * Assign (or clear) a folio's receivable to a corporate/city-ledger account.
     * $accountId 0 clears the link. Validates folio + account both belong to the org
     * (IDOR guard). Returns bool. Idempotent upsert on (org_id, folio_id).
     */
    function ar_ext_assign_account($db, int $orgId, int $folioId, int $accountId): bool
    {
        if ($orgId <= 0 || $folioId <= 0) { return false; }
        try {
            if (!$db->has('stays_folios', ['id' => $folioId, 'org_id' => $orgId])) { return false; }
            if ($accountId > 0 && !$db->has('stays_corporate_accounts', ['id' => $accountId, 'org_id' => $orgId])) { return false; }
            $existing = $db->get('stays_ar_folio_account', ['id'], ['org_id' => $orgId, 'folio_id' => $folioId]);
            if ($accountId <= 0) {
                if ($existing) { $db->delete('stays_ar_folio_account', ['id' => (int) $existing['id']]); }
                return true;
            }
            if ($existing) {
                $db->update('stays_ar_folio_account', ['account_id' => $accountId, 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int) $existing['id']]);
            } else {
                $db->insert('stays_ar_folio_account', [
                    'org_id' => $orgId, 'folio_id' => $folioId, 'account_id' => $accountId,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
            return true;
        } catch (\Throwable $e) { error_log('ar_ext_assign_account: ' . $e->getMessage()); return false; }
    }
}

// ---------------------------------------------------------------------------
// CUSTOMER STATEMENTS (per corporate / city-ledger account)
// ---------------------------------------------------------------------------
if (!function_exists('ar_ext_statement')) {
    /**
     * Statement of account for ONE corporate account: every OPEN folio linked to it,
     * its balance + age, and a per-currency ageing roll-up plus running total.
     * Read-only. Returns ['account'=>row|null,'lines'=>[open folio rows],
     *   'ageing'=>['USD'=>buckets,...],'total'=>['USD'=>f,...],'credit_limit'=>?f,
     *   'credit_available'=>['USD'=>f,...]  (limit minus owed; only meaningful if
     *   the account trades in one currency — presented per-currency defensively)].
     */
    function ar_ext_statement($db, int $orgId, int $accountId): array
    {
        $out = ['account' => null, 'lines' => [], 'ageing' => [], 'total' => [], 'credit_limit' => null, 'credit_available' => []];
        if ($orgId <= 0 || $accountId <= 0) { return $out; }
        try {
            $acct = $db->get('stays_corporate_accounts', '*', ['id' => $accountId, 'org_id' => $orgId]);
            if (!$acct) { return $out; }
            $out['account'] = $acct;
            $out['credit_limit'] = $acct['credit_limit'] !== null ? round((float) $acct['credit_limit'], 2) : null;
            $labels = ar_ext_bucket_labels();
            foreach (ar_ext_open_folios($db, $orgId) as $r) {
                if ($r['account_id'] !== $accountId) { continue; }
                $out['lines'][] = $r;
                $cur = $r['currency'] ?: 'USD';
                if (!isset($out['ageing'][$cur])) {
                    $out['ageing'][$cur] = array_fill_keys(array_keys($labels), 0.0);
                }
                $out['ageing'][$cur][$r['bucket']] = round($out['ageing'][$cur][$r['bucket']] + $r['balance'], 2);
                $out['total'][$cur] = round(($out['total'][$cur] ?? 0) + $r['balance'], 2);
            }
            if ($out['credit_limit'] !== null) {
                foreach ($out['total'] as $cur => $owed) {
                    $out['credit_available'][$cur] = round((float) $out['credit_limit'] - $owed, 2);
                }
            }
        } catch (\Throwable $e) { error_log('ar_ext_statement: ' . $e->getMessage()); }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// PAYMENT ALLOCATION (receive + apply cash against open folios)   [MONEY — DB-GATED]
// ---------------------------------------------------------------------------
if (!function_exists('ar_ext_receive_payment')) {
    /**
     * Record a cash/transfer RECEIPT against ONE open folio and allocate it, posting:
     *   1) a folio `payment` line  (so folio_totals / ar_subledger drop by $amount), and
     *   2) a BALANCED GL entry      Dr 1000 Cash/Bank  /  Cr 1200 City-Ledger AR.
     * Both writes are wrapped in ONE $db->action() — if the GL post fails the folio
     * payment line is rolled back too, so AR can never half-move. The amount is clamped
     * to the folio's current balance (never over-apply, never trust a client over-amount)
     * and must be > 0. Returns ['ok'=>bool,'applied'=>float,'entry_id'=>?int,'message'=>].
     *
     * Honesty note: this records cash ALREADY RECEIVED at the desk/bank — it is NOT a
     * gateway charge. No external payment processor is contacted (that lives in the
     * platform wallet spine, deliberately separate from these property books).
     */
    function ar_ext_receive_payment($db, int $orgId, int $folioId, float $amount, array $opts = []): array
    {
        if ($orgId <= 0 || $folioId <= 0) { return ['ok' => false, 'message' => 'Missing org/folio']; }
        $amount = round((float) $amount, 2);
        if ($amount <= 0) { return ['ok' => false, 'message' => 'Amount must be positive']; }

        $method = in_array(($opts['method'] ?? ''), ['cash', 'bank_transfer', 'cheque', 'card_offline', 'other'], true) ? $opts['method'] : 'cash';
        $ref    = substr(trim((string) ($opts['reference'] ?? '')), 0, 100);
        $actor  = (string) ($_SESSION['user_id'] ?? '');

        $result = ['ok' => false, 'message' => 'Receipt failed'];
        try {
            $db->action(function ($db) use ($orgId, $folioId, $amount, $method, $ref, $actor, &$result) {
                // Lock + validate the folio inside the txn (FOR UPDATE: block a racing
                // second receipt from both reading the same balance and over-applying).
                // Raw query — Medoo get() has no row-lock option (matches supplier_folio.php).
                $st = $db->query('SELECT id, invoice_id, currency, status FROM stays_folios WHERE id = :id AND org_id = :org FOR UPDATE',
                    [':id' => $folioId, ':org' => $orgId]);
                $f = $st ? $st->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$f) { $result = ['ok' => false, 'message' => 'Folio not found']; return false; }
                if ((string) $f['status'] !== 'open') { $result = ['ok' => false, 'message' => 'Folio is closed']; return false; }

                $tot = function_exists('folio_totals') ? folio_totals($db, $folioId) : ['balance' => 0];
                $bal = round((float) ($tot['balance'] ?? 0), 2);
                if ($bal <= 0) { $result = ['ok' => false, 'message' => 'Nothing outstanding on this folio']; return false; }
                $applied = min($amount, $bal); // never over-apply

                $desc = 'AR receipt (' . $method . ')' . ($ref !== '' ? ' ref ' . $ref : '');
                // Tag the folio line with an 'ar_receipt:' ref so checkout can tell this
                // payment ALREADY posted its own cash GL entry below (DR 1000 / CR 1200)
                // and must NOT re-post it to cash at checkout (audit #8 double-count).
                $arRef = 'ar_receipt:' . $folioId . ':' . date('YmdHis') . ':' . substr(bin2hex(random_bytes(4)), 0, 8);
                $okItem = function_exists('folio_add_item')
                    ? folio_add_item($db, $folioId, 'payment', $applied, $desc, $actor, 1, $arRef)
                    : false;
                if (!$okItem) { $result = ['ok' => false, 'message' => 'Could not post folio payment']; return false; }

                // Balanced GL: cash in, city-ledger AR down. gl_post JOINs this open txn
                // (it detects inTransaction) and THROWS on failure → rolls the folio line back.
                $entryId = null;
                if (function_exists('gl_post')) {
                    $gl = gl_post($db, $orgId, [
                        ['code' => '1000', 'debit'  => $applied], // Cash / Bank
                        ['code' => '1200', 'credit' => $applied], // City Ledger (AR)
                    ], [
                        'source'    => 'ar_receipt',
                        'reference' => 'folio:' . $folioId . ':' . (string) $f['invoice_id'] . ':' . date('YmdHis'),
                        'memo'      => 'AR receipt for ' . (string) $f['invoice_id'],
                        'currency'  => (string) ($f['currency'] ?? 'USD'),
                    ]);
                    if (empty($gl['ok'])) { $result = ['ok' => false, 'message' => $gl['message'] ?? 'GL post failed']; return false; }
                    $entryId = (int) ($gl['entry_id'] ?? 0);
                }
                $result = ['ok' => true, 'applied' => $applied, 'entry_id' => $entryId, 'message' => 'Receipt applied'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('ar_ext_receive_payment: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Receipt error'];
        }
        return $result;
    }
}

// ---------------------------------------------------------------------------
// WRITE-OFF (bad debt)   [MONEY — DB-GATED]
// ---------------------------------------------------------------------------
if (!function_exists('ar_ext_write_off')) {
    /**
     * Write off an outstanding folio balance as bad debt, posting:
     *   1) a folio `credit_note` line (reduces what the folio shows as owed), and
     *   2) a BALANCED GL entry        Dr 6000 Operating Expense / Cr 1200 City-Ledger AR.
     * Atomic (one $db->action) with the same FOR-UPDATE lock + clamp as a receipt —
     * you can only write off up to the current balance. A reason is required and stored
     * on the folio line. Returns ['ok'=>bool,'written_off'=>float,'entry_id'=>?int,'message'=>].
     */
    function ar_ext_write_off($db, int $orgId, int $folioId, float $amount, string $reason, array $opts = []): array
    {
        if ($orgId <= 0 || $folioId <= 0) { return ['ok' => false, 'message' => 'Missing org/folio']; }
        $amount = round((float) $amount, 2);
        if ($amount <= 0) { return ['ok' => false, 'message' => 'Amount must be positive']; }
        $reason = substr(trim($reason), 0, 180);
        if ($reason === '') { return ['ok' => false, 'message' => 'A write-off reason is required']; }
        $actor = (string) ($_SESSION['user_id'] ?? '');

        $result = ['ok' => false, 'message' => 'Write-off failed'];
        try {
            $db->action(function ($db) use ($orgId, $folioId, $amount, $reason, $actor, &$result) {
                $st = $db->query('SELECT id, invoice_id, currency, status FROM stays_folios WHERE id = :id AND org_id = :org FOR UPDATE',
                    [':id' => $folioId, ':org' => $orgId]);
                $f = $st ? $st->fetch(\PDO::FETCH_ASSOC) : null;
                if (!$f) { $result = ['ok' => false, 'message' => 'Folio not found']; return false; }
                if ((string) $f['status'] !== 'open') { $result = ['ok' => false, 'message' => 'Folio is closed']; return false; }

                $tot = function_exists('folio_totals') ? folio_totals($db, $folioId) : ['balance' => 0];
                $bal = round((float) ($tot['balance'] ?? 0), 2);
                if ($bal <= 0) { $result = ['ok' => false, 'message' => 'Nothing outstanding to write off']; return false; }
                $wo = min($amount, $bal);

                $okItem = function_exists('folio_add_item')
                    ? folio_add_item($db, $folioId, 'credit_note', $wo, 'Bad-debt write-off: ' . $reason, $actor, 1)
                    : false;
                if (!$okItem) { $result = ['ok' => false, 'message' => 'Could not post folio credit note']; return false; }

                $entryId = null;
                if (function_exists('gl_post')) {
                    $gl = gl_post($db, $orgId, [
                        ['code' => '6000', 'debit'  => $wo], // Operating Expense (bad debt)
                        ['code' => '1200', 'credit' => $wo], // City Ledger (AR)
                    ], [
                        'source'    => 'ar_writeoff',
                        'reference' => 'folio:' . $folioId . ':wo:' . date('YmdHis'),
                        'memo'      => 'Bad-debt write-off for ' . (string) $f['invoice_id'],
                        'currency'  => (string) ($f['currency'] ?? 'USD'),
                    ]);
                    if (empty($gl['ok'])) { $result = ['ok' => false, 'message' => $gl['message'] ?? 'GL post failed']; return false; }
                    $entryId = (int) ($gl['entry_id'] ?? 0);
                }
                $result = ['ok' => true, 'written_off' => $wo, 'entry_id' => $entryId, 'message' => 'Written off'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('ar_ext_write_off: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Write-off error'];
        }
        return $result;
    }
}

// ---------------------------------------------------------------------------
// COLLECTIONS (follow-up / promise-to-pay log)
// ---------------------------------------------------------------------------
if (!function_exists('ar_ext_collection_add')) {
    /**
     * Log a collections activity against a corporate account: a call/email/letter, a
     * promise-to-pay (with optional promised date + amount), or a dispute note. Pure
     * record-keeping — it does NOT move money. Validates the account belongs to the org.
     * Returns the new row id (0 on failure).
     */
    function ar_ext_collection_add($db, int $orgId, int $accountId, array $d): int
    {
        if ($orgId <= 0 || $accountId <= 0) { return 0; }
        $kind = in_array(($d['kind'] ?? ''), ['call', 'email', 'letter', 'promise', 'dispute', 'note'], true) ? $d['kind'] : 'note';
        try {
            if (!$db->has('stays_corporate_accounts', ['id' => $accountId, 'org_id' => $orgId])) { return 0; }
            $promiseDate = null;
            if (!empty($d['promise_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d['promise_date'])) {
                $promiseDate = (string) $d['promise_date'];
            }
            $promiseAmt = ($d['promise_amount'] ?? '') !== '' ? max(0, round((float) $d['promise_amount'], 2)) : null;
            $db->insert('stays_ar_collections', [
                'org_id'         => $orgId,
                'account_id'     => $accountId,
                'kind'           => $kind,
                'detail'         => substr(trim((string) ($d['detail'] ?? '')), 0, 500) ?: null,
                'promise_date'   => $promiseDate,
                'promise_amount' => $promiseAmt,
                'status'         => $kind === 'promise' ? 'open' : 'logged',
                'created_by'     => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('ar_ext_collection_add: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('ar_ext_collection_list')) {
    /** Recent collections log for an account (or whole org if $accountId<=0). Read-only. */
    function ar_ext_collection_list($db, int $orgId, int $accountId = 0, int $limit = 100): array
    {
        if ($orgId <= 0) { return []; }
        try {
            $where = ['org_id' => $orgId];
            if ($accountId > 0) { $where['account_id'] = $accountId; }
            $where['ORDER'] = ['id' => 'DESC'];
            $where['LIMIT'] = max(1, $limit);
            return $db->select('stays_ar_collections', '*', $where) ?: [];
        } catch (\Throwable $e) { error_log('ar_ext_collection_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('ar_ext_send_reminder')) {
    /**
     * HONEST STUB — statement/dunning reminder delivery is NOT connected.
     *
     * The collections record is real (logged via ar_ext_collection_add), but actually
     * EMAILING a statement to the customer would route through the platform messaging
     * providers (settings.*_providers_config) which are not wired into the property AR
     * module. Rather than fake a "sent" status, this logs the intent and returns
     * connected=false so the UI can tell the operator to send it manually. When comms
     * wiring lands, replace the body with a real SENDEMAIL()/NOTIFY call.
     */
    function ar_ext_send_reminder($db, int $orgId, int $accountId): array
    {
        $logged = ar_ext_collection_add($db, $orgId, $accountId, [
            'kind'   => 'email',
            'detail' => 'Statement reminder queued (delivery not connected — send manually).',
        ]);
        return [
            'connected' => false,
            'logged'    => $logged > 0,
            'message'   => 'Reminder logged. Automated statement delivery is not connected yet — email the statement manually.',
        ];
    }
}

<?php
// FILE: app/lib/supplier_corporate.php
// Corporate / agent accounts (#15) + Trust / client-money ledger (#50). Org-scoped
// (owner-level). Trust is a signed append-only ledger SEPARATE from platform funds.
// NOT runtime-verified (no MySQL) — logic-reasoned + php -l + harness for trust balance.

// ---------------------------------------------------------------------------
// CORPORATE / AGENT ACCOUNTS (#15)
// ---------------------------------------------------------------------------
if (!function_exists('corp_add')) {
    function corp_add($db, int $orgId, array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($orgId <= 0 || $name === '') { return 0; }
        try {
            $type = in_array(($d['type'] ?? ''), ['company', 'agent'], true) ? $d['type'] : 'company';
            $disc = ($d['negotiated_discount_pct'] ?? '') !== '' ? max(0, min(100, round((float) $d['negotiated_discount_pct'], 2))) : null;
            $db->insert('stays_corporate_accounts', [
                'org_id'       => $orgId, 'type' => $type,
                'name'         => substr($name, 0, 191),
                'contact_name' => substr(trim((string) ($d['contact_name'] ?? '')), 0, 120) ?: null,
                'email'        => trim((string) ($d['email'] ?? '')) ?: null,
                'phone'        => trim((string) ($d['phone'] ?? '')) ?: null,
                'negotiated_discount_pct' => $disc,
                'credit_limit' => ($d['credit_limit'] ?? '') !== '' ? max(0, round((float) $d['credit_limit'], 2)) : null,
                'status'       => 'active',
                'notes'        => substr(trim((string) ($d['notes'] ?? '')), 0, 500) ?: null,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('corp_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('corp_set_status')) {
    function corp_set_status($db, int $orgId, int $id, string $status): bool
    {
        if (!in_array($status, ['active', 'suspended'], true)) { return false; }
        try {
            if (!$db->has('stays_corporate_accounts', ['id' => $id, 'org_id' => $orgId])) { return false; }
            $db->update('stays_corporate_accounts', ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('corp_set_status: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('corp_list')) {
    function corp_list($db, int $orgId, int $limit = 500): array
    {
        if ($orgId <= 0) { return []; }
        try { return $db->select('stays_corporate_accounts', '*', ['org_id' => $orgId, 'ORDER' => ['name' => 'ASC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('corp_list: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// TRUST / CLIENT-MONEY LEDGER (#50)
// ---------------------------------------------------------------------------
if (!function_exists('trust_entry_signed')) {
    /** The signed effect of an entry on the trust balance: receipt +, disbursement −,
     *  adjustment as-entered (may be + or −). Pure helper (harnessed). */
    function trust_entry_signed(string $type, float $amount): float
    {
        if ($type === 'receipt') { return round(abs($amount), 2); }
        if ($type === 'disbursement') { return round(-abs($amount), 2); }
        return round($amount, 2); // adjustment: signed as entered
    }
}
if (!function_exists('trust_add')) {
    function trust_add($db, int $orgId, array $d): int
    {
        $party = trim((string) ($d['party'] ?? ''));
        $type = in_array(($d['entry_type'] ?? ''), ['receipt', 'disbursement', 'adjustment'], true) ? $d['entry_type'] : 'receipt';
        $amount = (float) ($d['amount'] ?? 0);
        if ($orgId <= 0 || $party === '' || $amount == 0.0) { return 0; }
        try {
            $signed = trust_entry_signed($type, $amount);
            $db->insert('stays_trust_ledger', [
                'org_id'     => $orgId, 'party' => substr($party, 0, 191),
                'entry_type' => $type, 'amount' => $signed,
                'currency'   => strtoupper(substr((string) ($d['currency'] ?? 'USD'), 0, 3)),
                'reference'  => substr(trim((string) ($d['reference'] ?? '')), 0, 120) ?: null,
                'memo'       => substr(trim((string) ($d['memo'] ?? '')), 0, 255) ?: null,
                'created_by' => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('trust_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('trust_balances')) {
    /**
     * Trust balance per party, SEGREGATED BY CURRENCY (client money must never net
     * one currency against another). Each ledger entry carries its own currency
     * (stays_trust_ledger.currency, default 'USD'). Previously this summed amount per
     * party ignoring currency and reported a single unlabelled 'total', so a +100 USD
     * receipt and a -100 EUR disbursement for one party netted to a misleading 0.
     *
     * Returns:
     *   'parties' => [ ['party','currency','balance'], ... ]  (one row per party×currency)
     *   'totals'  => [ 'USD' => float, 'EUR' => float, ... ]  (held per currency)
     *   'total'   => float   (kept for back-compat: the single-currency total when the
     *                         org holds exactly one currency, else 0.0 — do not use it
     *                         for a mixed-currency org; read 'totals' instead)
     */
    function trust_balances($db, int $orgId): array
    {
        $out = ['parties' => [], 'totals' => [], 'total' => 0.0];
        if ($orgId <= 0) { return $out; }
        try {
            $rows = $db->select('stays_trust_ledger', ['party', 'currency', 'amount'], ['org_id' => $orgId]) ?: [];
            $by = [];      // "party\0CUR" => balance
            $totals = [];  // CUR => sum
            foreach ($rows as $r) {
                $p = (string) $r['party'];
                $cur = strtoupper(trim((string) ($r['currency'] ?? ''))) ?: 'USD';
                $amt = (float) $r['amount'];
                $key = $p . "\0" . $cur;
                $by[$key] = round(($by[$key] ?? 0) + $amt, 2);
                $totals[$cur] = round(($totals[$cur] ?? 0) + $amt, 2);
            }
            foreach ($by as $key => $bal) {
                [$p, $cur] = explode("\0", $key, 2);
                $out['parties'][] = ['party' => $p, 'currency' => $cur, 'balance' => $bal];
            }
            $out['totals'] = $totals;
            $out['total'] = count($totals) === 1 ? (float) reset($totals) : 0.0;
        } catch (\Throwable $e) { error_log('trust_balances: ' . $e->getMessage()); }
        return $out;
    }
}
if (!function_exists('trust_list')) {
    function trust_list($db, int $orgId, int $limit = 300): array
    {
        if ($orgId <= 0) { return []; }
        try { return $db->select('stays_trust_ledger', '*', ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('trust_list: ' . $e->getMessage()); return []; }
    }
}

// ===========================================================================
// MODULE 15 DEEPEN: sales pipeline stage + activity log + account performance.
// ===========================================================================
if (!function_exists('corp_set_pipeline')) {
    function corp_set_pipeline($db, int $orgId, int $accountId, string $stage): bool
    {
        if (!in_array($stage, ['lead', 'prospect', 'negotiation', 'won', 'lost'], true)) { return false; }
        try {
            if (!$db->has('stays_corporate_accounts', ['id' => $accountId, 'org_id' => $orgId])) { return false; }
            $db->update('stays_corporate_accounts', ['pipeline_stage' => $stage, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $accountId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) { error_log('corp_set_pipeline: ' . $e->getMessage()); return false; }
    }
}
if (!function_exists('corp_activity_add')) {
    function corp_activity_add($db, int $orgId, int $accountId, string $kind, string $detail): int
    {
        if ($orgId <= 0 || $accountId <= 0) { return 0; }
        $kind = in_array($kind, ['note', 'call', 'email', 'meeting', 'quote'], true) ? $kind : 'note';
        try {
            if (!$db->has('stays_corporate_accounts', ['id' => $accountId, 'org_id' => $orgId])) { return 0; }
            $db->insert('stays_sales_activities', [
                'org_id' => $orgId, 'account_id' => $accountId, 'kind' => $kind,
                'detail' => substr(trim($detail), 0, 500) ?: null,
                'created_by' => (string) ($_SESSION['user_id'] ?? '') ?: null, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('corp_activity_add: ' . $e->getMessage()); return 0; }
    }
}
if (!function_exists('corp_activity_list')) {
    function corp_activity_list($db, int $accountId, int $limit = 50): array
    {
        if ($accountId <= 0) { return []; }
        try { return $db->select('stays_sales_activities', '*', ['account_id' => $accountId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: []; }
        catch (\Throwable $e) { error_log('corp_activity_list: ' . $e->getMessage()); return []; }
    }
}

<?php
// FILE: app/lib/supplier_tax_ext.php
// TAX & FISCAL MANAGEMENT — DEEPENING (PMS #38; docs 01b §2.38, 01a §9).
//
// The BASE already covers:
//   - the platform's calculateTax() (booking-time tax on the public funnel), and
//   - tax_summary() in supplier_ledger.php, which reads the live Tax Payable
//     balance from GL account 2100 off the trial balance.
//
// What was MISSING — and what this file adds, as org-scoped CONFIG + read-only
// reports on top of the existing GL (it NEVER posts its own journals; tax lands
// in GL 2100 through the folio/booking paths that already exist):
//   - tax_profiles        per-org tax rate definitions (VAT/GST/city/tourism/
//                         service charge/withholding), each flagged
//                         inclusive|exclusive, with an active window + default flag
//   - tax_exemptions      per-org exemption registry (a guest / company / booking
//                         reference exempt from one profile or all), with a reason
//                         and validity window — consulted at quote time
//   - a pure tax CALCULATOR (tax_ext_compute) that applies a profile to a base
//     amount honouring inclusive vs exclusive, returning {base, tax, gross} — the
//     one piece of real money logic, and it NEVER trusts a client-supplied tax
//     figure (it is always derived from the stored rate)
//   - tax_ext_report: output tax over a date range, derived from the GL journal
//     lines hitting the org's tax account(s) — the number you file — plus a
//     per-profile configured-rate breakdown for reconciliation
//   - tax_ext_einvoice_status: an HONEST stub. Fiscal/e-invoicing device
//     clearance (ZATCA/KSeF/SAT/FIRS/etc.) is a per-jurisdiction external
//     integration that is NOT connected here; this reports 'not_connected' and
//     the internal record state, never a faked clearance.
//
// All functions if_not_exists-guarded, org-scoped, defensive/non-fatal. DB-GATED:
// the three tables below must be created (see the deepen patch spec schema_sql).

if (!function_exists('tax_ext_kinds')) {
    /** Supported tax kinds (value => label). Config, not DB. Mirrors 01b §2.38. */
    function tax_ext_kinds(): array
    {
        return [
            'vat'         => 'VAT',
            'gst'         => 'GST',
            'sales'       => 'Sales tax',
            'city'        => 'City / municipal tax',
            'tourism'     => 'Tourism / occupancy tax',
            'service'     => 'Service charge',
            'withholding' => 'Withholding tax',
            'other'       => 'Other',
        ];
    }
}

if (!function_exists('tax_ext_valid_kind')) {
    /** True if $kind is a recognised tax kind. */
    function tax_ext_valid_kind(string $kind): bool
    {
        return array_key_exists($kind, tax_ext_kinds());
    }
}

// ---------------------------------------------------------------------------
// TAX PROFILES — per-org rate definitions.
// ---------------------------------------------------------------------------
if (!function_exists('tax_ext_profiles')) {
    /** All tax profiles for an org, newest first. Read-only; [] on error. */
    function tax_ext_profiles($db, int $orgId, bool $activeOnly = false): array
    {
        if ($orgId <= 0) { return []; }
        try {
            $where = ['org_id' => $orgId];
            if ($activeOnly) { $where['active'] = 1; }
            $where['ORDER'] = ['id' => 'DESC'];
            return $db->select('tax_profiles',
                ['id', 'org_id', 'name', 'kind', 'rate_pct', 'mode', 'is_default',
                 'active', 'applies_to', 'start_date', 'end_date', 'created_at'],
                $where) ?: [];
        } catch (\Throwable $e) {
            error_log('tax_ext_profiles: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('tax_ext_profile_get')) {
    /** A single org-scoped profile row, or null. Ownership-safe (org_id in key). */
    function tax_ext_profile_get($db, int $orgId, int $profileId): ?array
    {
        if ($orgId <= 0 || $profileId <= 0) { return null; }
        try {
            $r = $db->get('tax_profiles', '*', ['id' => $profileId, 'org_id' => $orgId]);
            return $r ?: null;
        } catch (\Throwable $e) {
            error_log('tax_ext_profile_get: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('tax_ext_profile_save')) {
    /**
     * Create or update a tax profile. Server-validated — the RATE is stored as a
     * clamped percentage (0..100) and NEVER echoed back from the client at
     * calc-time. mode ∈ inclusive|exclusive. When is_default=1, any other default
     * of the same kind for this org is demoted (one default per kind), atomically.
     * $data: name, kind, rate_pct, mode, applies_to('all'|module), is_default,
     *        active, start_date, end_date. $profileId>0 = update.
     * Returns ['ok'=>bool,'id'=>?int,'message'=>?].
     */
    function tax_ext_profile_save($db, int $orgId, array $data, int $profileId = 0): array
    {
        if ($orgId <= 0) { return ['ok' => false, 'message' => 'No org']; }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') { return ['ok' => false, 'message' => 'A profile name is required']; }
        $kind = (string) ($data['kind'] ?? 'vat');
        if (!tax_ext_valid_kind($kind)) { return ['ok' => false, 'message' => 'Unknown tax kind']; }
        $rate = round((float) ($data['rate_pct'] ?? 0), 4);
        if ($rate < 0 || $rate > 100) { return ['ok' => false, 'message' => 'Rate must be between 0 and 100']; }
        $mode = ((string) ($data['mode'] ?? 'exclusive')) === 'inclusive' ? 'inclusive' : 'exclusive';
        $appliesTo = substr((string) ($data['applies_to'] ?? 'all'), 0, 40) ?: 'all';
        $isDefault = !empty($data['is_default']) ? 1 : 0;
        $active    = array_key_exists('active', $data) ? (!empty($data['active']) ? 1 : 0) : 1;
        $start = (string) ($data['start_date'] ?? '');
        $end   = (string) ($data['end_date'] ?? '');
        $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? $start : null;
        $end   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) ? $end : null;

        $fields = [
            'org_id'     => $orgId,
            'name'       => substr($name, 0, 120),
            'kind'       => $kind,
            'rate_pct'   => $rate,
            'mode'       => $mode,
            'applies_to' => $appliesTo,
            'is_default' => $isDefault,
            'active'     => $active,
            'start_date' => $start,
            'end_date'   => $end,
        ];

        try {
            $result = ['ok' => false, 'message' => 'Save failed'];
            $db->action(function ($db) use ($orgId, $kind, $isDefault, $profileId, $fields, &$result) {
                // One default per kind per org: demote the others first.
                if ($isDefault) {
                    $db->update('tax_profiles', ['is_default' => 0],
                        ['org_id' => $orgId, 'kind' => $kind, 'is_default' => 1]);
                }
                if ($profileId > 0) {
                    // Scope the update to this org — a staffer cannot touch another org's row.
                    $owned = $db->get('tax_profiles', ['id'], ['id' => $profileId, 'org_id' => $orgId]);
                    if (!$owned) { $result = ['ok' => false, 'message' => 'Profile not found']; return false; }
                    $fields['updated_at'] = date('Y-m-d H:i:s');
                    unset($fields['org_id']); // never reassign org
                    $db->update('tax_profiles', $fields, ['id' => $profileId, 'org_id' => $orgId]);
                    $result = ['ok' => true, 'id' => $profileId];
                    return true;
                }
                $fields['created_at'] = date('Y-m-d H:i:s');
                $db->insert('tax_profiles', $fields);
                $newId = (int) $db->id();
                if ($newId <= 0) { $result = ['ok' => false, 'message' => 'Insert failed']; return false; }
                $result = ['ok' => true, 'id' => $newId];
                return true;
            });
            return $result;
        } catch (\Throwable $e) {
            error_log('tax_ext_profile_save: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Save error'];
        }
    }
}

if (!function_exists('tax_ext_profile_set_active')) {
    /** Toggle a profile active/inactive (org-scoped). Returns bool. */
    function tax_ext_profile_set_active($db, int $orgId, int $profileId, bool $active): bool
    {
        if ($orgId <= 0 || $profileId <= 0) { return false; }
        try {
            $db->update('tax_profiles',
                ['active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $profileId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) {
            error_log('tax_ext_profile_set_active: ' . $e->getMessage());
            return false;
        }
    }
}

// ---------------------------------------------------------------------------
// THE CALCULATOR — inclusive vs exclusive. Pure + never trusts a client figure.
// ---------------------------------------------------------------------------
if (!function_exists('tax_ext_compute')) {
    /**
     * Apply a tax RATE to a base amount, honouring inclusive vs exclusive.
     *   exclusive: tax is ADDED on top     → tax = base * r,  gross = base + tax
     *   inclusive: tax is already WITHIN   → net  = amount/(1+r), tax = amount - net
     *
     * $amount is the figure the operator entered (for inclusive that is the
     * gross/shelf price; for exclusive it is the net). The tax is ALWAYS derived
     * from $ratePct — a caller can never pass a tax amount in.
     *
     * Returns ['net'=>float,'tax'=>float,'gross'=>float,'rate_pct'=>float,'mode'=>str].
     */
    function tax_ext_compute(float $amount, float $ratePct, string $mode = 'exclusive'): array
    {
        $amount = round($amount, 2);
        $r = max(0.0, $ratePct) / 100.0;
        if ($mode === 'inclusive') {
            $net   = $r > 0 ? round($amount / (1 + $r), 2) : $amount;
            $tax   = round($amount - $net, 2);
            $gross = $amount;
        } else {
            $net   = $amount;
            $tax   = round($amount * $r, 2);
            $gross = round($amount + $tax, 2);
        }
        return ['net' => $net, 'tax' => $tax, 'gross' => $gross, 'rate_pct' => round($ratePct, 4), 'mode' => $mode];
    }
}

if (!function_exists('tax_ext_quote')) {
    /**
     * Quote the tax for an org using a stored profile (by id), honouring any
     * matching exemption. This is the funnel-safe entry point: the RATE comes from
     * the DB, the exemption from the DB, and the result from tax_ext_compute().
     *
     * $ref is an optional guest/company/booking reference that exemptions key on.
     * Returns the tax_ext_compute() shape plus:
     *   'exempt'=>bool, 'exempt_reason'=>?str, 'profile_id'=>int, 'kind'=>str.
     * On a missing/inactive profile returns a zero-tax passthrough (honest).
     */
    function tax_ext_quote($db, int $orgId, int $profileId, float $amount, string $ref = ''): array
    {
        $amount = round($amount, 2);
        $zero = ['net' => $amount, 'tax' => 0.0, 'gross' => $amount, 'rate_pct' => 0.0,
                 'mode' => 'exclusive', 'exempt' => false, 'exempt_reason' => null,
                 'profile_id' => $profileId, 'kind' => ''];
        $p = tax_ext_profile_get($db, $orgId, $profileId);
        if (!$p || (int) $p['active'] !== 1) { return $zero; }

        // Exemption check first — a matched exemption zeroes the tax but keeps the
        // profile reference so the line is auditable.
        $ex = tax_ext_exemption_match($db, $orgId, $profileId, $ref);
        if ($ex !== null) {
            return array_merge($zero, [
                'mode' => (string) $p['mode'], 'kind' => (string) $p['kind'],
                'exempt' => true, 'exempt_reason' => (string) ($ex['reason'] ?? 'Exempt'),
            ]);
        }

        $c = tax_ext_compute($amount, (float) $p['rate_pct'], (string) $p['mode']);
        return array_merge($c, [
            'exempt' => false, 'exempt_reason' => null,
            'profile_id' => (int) $p['id'], 'kind' => (string) $p['kind'],
        ]);
    }
}

// ---------------------------------------------------------------------------
// EXEMPTIONS — per-org registry consulted at quote time.
// ---------------------------------------------------------------------------
if (!function_exists('tax_ext_exemptions')) {
    /** All exemptions for an org, newest first. Read-only; [] on error. */
    function tax_ext_exemptions($db, int $orgId): array
    {
        if ($orgId <= 0) { return []; }
        try {
            return $db->select('tax_exemptions',
                ['id', 'org_id', 'profile_id', 'ref', 'label', 'reason',
                 'start_date', 'end_date', 'active', 'created_at'],
                ['org_id' => $orgId, 'ORDER' => ['id' => 'DESC']]) ?: [];
        } catch (\Throwable $e) {
            error_log('tax_ext_exemptions: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('tax_ext_exemption_save')) {
    /**
     * Create an exemption. profile_id=0 means "all taxes" for this ref. The ref is
     * the matching key (guest id / company id / booking reference) and is stored
     * trimmed+lowercased so matching is stable. Validity window optional.
     * Returns ['ok'=>bool,'id'=>?int,'message'=>?].
     */
    function tax_ext_exemption_save($db, int $orgId, array $data): array
    {
        if ($orgId <= 0) { return ['ok' => false, 'message' => 'No org']; }
        $ref = strtolower(trim((string) ($data['ref'] ?? '')));
        if ($ref === '') { return ['ok' => false, 'message' => 'An exemption needs a reference (guest / company / booking)']; }
        $profileId = (int) ($data['profile_id'] ?? 0);
        // If scoped to a profile, that profile must belong to this org.
        if ($profileId > 0 && tax_ext_profile_get($db, $orgId, $profileId) === null) {
            return ['ok' => false, 'message' => 'That tax profile is not yours'];
        }
        $start = (string) ($data['start_date'] ?? '');
        $end   = (string) ($data['end_date'] ?? '');
        try {
            $db->insert('tax_exemptions', [
                'org_id'     => $orgId,
                'profile_id' => $profileId,
                'ref'        => substr($ref, 0, 120),
                'label'      => substr((string) ($data['label'] ?? ''), 0, 160),
                'reason'     => substr((string) ($data['reason'] ?? ''), 0, 255),
                'start_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? $start : null,
                'end_date'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) ? $end : null,
                'active'     => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $id = (int) $db->id();
            return $id > 0 ? ['ok' => true, 'id' => $id] : ['ok' => false, 'message' => 'Insert failed'];
        } catch (\Throwable $e) {
            error_log('tax_ext_exemption_save: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Save error'];
        }
    }
}

if (!function_exists('tax_ext_exemption_set_active')) {
    /** Toggle an exemption active/inactive (org-scoped). Returns bool. */
    function tax_ext_exemption_set_active($db, int $orgId, int $exemptId, bool $active): bool
    {
        if ($orgId <= 0 || $exemptId <= 0) { return false; }
        try {
            $db->update('tax_exemptions',
                ['active' => $active ? 1 : 0],
                ['id' => $exemptId, 'org_id' => $orgId]);
            return true;
        } catch (\Throwable $e) {
            error_log('tax_ext_exemption_set_active: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('tax_ext_exemption_match')) {
    /**
     * Find the active exemption (if any) covering $ref for $profileId today. An
     * exemption with profile_id=0 covers ALL profiles. Validity window honoured.
     * Returns the row or null. Read-only.
     */
    function tax_ext_exemption_match($db, int $orgId, int $profileId, string $ref): ?array
    {
        $ref = strtolower(trim($ref));
        if ($orgId <= 0 || $ref === '') { return null; }
        $today = date('Y-m-d');
        try {
            $rows = $db->select('tax_exemptions',
                ['id', 'profile_id', 'reason', 'start_date', 'end_date'],
                ['org_id' => $orgId, 'ref' => $ref, 'active' => 1,
                 'profile_id' => [0, $profileId]]) ?: [];
            foreach ($rows as $r) {
                $s = (string) ($r['start_date'] ?? '');
                $e = (string) ($r['end_date'] ?? '');
                if ($s !== '' && $today < $s) { continue; }
                if ($e !== '' && $today > $e) { continue; }
                return $r;
            }
        } catch (\Throwable $e) {
            error_log('tax_ext_exemption_match: ' . $e->getMessage());
        }
        return null;
    }
}

// ---------------------------------------------------------------------------
// TAX REPORT — the number you file. Derived from the GL, not re-computed.
// ---------------------------------------------------------------------------
if (!function_exists('tax_ext_report')) {
    /**
     * Output-tax report for a date range, derived from the GL. Tax collected lands
     * as CREDITS to the org's Tax Payable account (GL 2100) via the folio/booking
     * paths; tax remitted to the authority lands as DEBITS. So for the window:
     *   output_tax  = sum(credit)  on 2100 journal lines whose entry_date in range
     *   remitted    = sum(debit)   on 2100 journal lines in range
     *   net_payable = output_tax - remitted
     * This is READ-ONLY — it never posts. It also returns the configured-profile
     * breakdown so the operator can reconcile "what we charge" vs "what the GL holds".
     *
     * Returns ['from','to','output_tax','remitted','net_payable','payable_now',
     *          'currency','profiles'=>[{name,kind,rate_pct,mode,active}...]].
     */
    function tax_ext_report($db, int $orgId, string $from, string $to): array
    {
        $out = ['from' => $from, 'to' => $to, 'output_tax' => 0.0, 'remitted' => 0.0,
                'net_payable' => 0.0, 'payable_now' => 0.0, 'currency' => 'USD', 'profiles' => []];
        if ($orgId <= 0) { return $out; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $from = date('Y-m-01'); $out['from'] = $from; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $to = date('Y-m-d');   $out['to'] = $to; }
        try {
            $taxAcct = function_exists('gl_account_id') ? gl_account_id($db, $orgId, '2100') : 0;
            if ($taxAcct > 0) {
                // Join lines to their entry to filter by entry_date within range.
                $rows = $db->select('journal_lines',
                    ['[>]journal_entries' => ['entry_id' => 'id']],
                    ['journal_lines.debit (debit)', 'journal_lines.credit (credit)'],
                    ['journal_lines.org_id' => $orgId,
                     'journal_lines.account_id' => $taxAcct,
                     'journal_entries.entry_date[<>]' => [$from, $to]]) ?: [];
                foreach ($rows as $r) {
                    $out['output_tax'] = round($out['output_tax'] + (float) $r['credit'], 2);
                    $out['remitted']   = round($out['remitted'] + (float) $r['debit'], 2);
                }
                $out['net_payable'] = round($out['output_tax'] - $out['remitted'], 2);
            }
            // Live standing balance on 2100 (all-time), for the headline figure.
            if (function_exists('tax_summary')) {
                $out['payable_now'] = (float) (tax_summary($db, $orgId)['payable'] ?? 0);
            }
            foreach (tax_ext_profiles($db, $orgId) as $p) {
                $out['profiles'][] = [
                    'name' => (string) $p['name'], 'kind' => (string) $p['kind'],
                    'rate_pct' => (float) $p['rate_pct'], 'mode' => (string) $p['mode'],
                    'active' => (int) $p['active'],
                ];
            }
        } catch (\Throwable $e) {
            error_log('tax_ext_report: ' . $e->getMessage());
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// E-INVOICING / FISCAL DEVICE — HONEST STUB (DB-GATED, not connected).
// ---------------------------------------------------------------------------
if (!function_exists('tax_ext_einvoice_status')) {
    /**
     * Fiscal / e-invoicing clearance status for the org. This is DELIBERATELY a
     * stub: real clearance (ZATCA Phase-2, Poland KSeF, Mexico SAT/CFDI, Nigeria
     * FIRS, India IRP, etc.) is a per-jurisdiction external integration requiring
     * signed device credentials, country endpoints and offline queueing — none of
     * which is wired here. We report the TRUTH: not connected. When a real
     * integration lands it will replace this with a provider-config read + a
     * submission log; the internal tax records above are already the source data
     * such a submitter would send.
     *
     * Returns ['connected'=>false,'provider'=>null,'message'=>str].
     */
    function tax_ext_einvoice_status($db, int $orgId): array
    {
        return [
            'connected' => false,
            'provider'  => null,
            'message'   => 'No fiscal/e-invoicing device is connected. '
                         . 'Tax is recorded internally and can be filed manually from the report; '
                         . 'automated clearance is a per-country integration and is not enabled.',
        ];
    }
}

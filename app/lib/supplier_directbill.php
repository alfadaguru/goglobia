<?php
// FILE: app/lib/supplier_directbill.php
// FRONT-DESK OVERHAUL — Phase 3: ACCOUNT ARRANGEMENTS + DIRECT-BILL AT THE DESK.
//
// Lets a front-desk staffer bill a guest's stay (its folio) to a company/NGO
// Customer-Book contact's city-ledger account, so the balance sits on that account's
// AR statement instead of being collected from the guest. This WIRES the existing,
// verified engines — it does not re-implement them:
//   - the folio↔account link is the real ar_ext_assign_account (supplier_ar_ext.php);
//     an open folio linked to an account is exactly what the city-ledger statement +
//     ageing already read. folio_checkout does NOT auto-settle a linked folio, so the
//     receivable correctly remains open for AR to collect later (ar_ext_receive_payment).
//   - credit headroom = the account's credit_limit minus what it already owes
//     (ar_ext_statement) minus THIS folio's balance.
//   - a company/NGO Customer-Book contact carries corp_account_id; if it has none yet
//     (e.g. a freshly-created NGO), db_enable_account_billing creates the corporate
//     account FROM the contact and links it (owner's one-click decision).
//
// OWNER DECISIONS honoured:
//   - over-limit: BLOCK for staff; the OWNER (manager tier) may override, and the
//     override is LOGGED to the AR collections log (who/amount). (Finer per-role
//     override permission is Phase 6.)
//   - no account yet: one-click enable-billing creates + links it.
//
// Money safety: the only money-state change here is the folio→account LINK (and, on
// enable-billing, creating a corporate account row). No charge is posted, no gateway
// is contacted. All org-scoped + non-fatal.

if (!function_exists('db_eligible_contacts')) {
    /**
     * Company/NGO/agent Customer-Book contacts for the org — the ones a stay can be
     * direct-billed to. (Individuals pay their own folio, so they're excluded.) Returns
     * rows incl. id, type, display_name, corp_account_id, credit_limit.
     */
    function db_eligible_contacts($db, int $orgId): array
    {
        if ($orgId <= 0) { return []; }
        try {
            return $db->select('stays_contacts',
                ['id', 'type', 'display_name', 'corp_account_id', 'credit_limit', 'tax_exempt'],
                ['org_id' => $orgId, 'type' => ['company', 'ngo', 'agent'], 'status' => 'active',
                 'ORDER' => ['display_name' => 'ASC'], 'LIMIT' => 500]) ?: [];
        } catch (\Throwable $e) { error_log('db_eligible_contacts: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('db_contact')) {
    /** One contact, org-scoped, or null. */
    function db_contact($db, int $orgId, int $contactId): ?array
    {
        if ($orgId <= 0 || $contactId <= 0) { return null; }
        try { return $db->get('stays_contacts', '*', ['id' => $contactId, 'org_id' => $orgId]) ?: null; }
        catch (\Throwable $e) { error_log('db_contact: ' . $e->getMessage()); return null; }
    }
}

if (!function_exists('db_enable_account_billing')) {
    /**
     * Ensure a company/NGO/agent contact has a city-ledger (corporate) account, creating
     * one FROM the contact (name, credit_limit, discount, type) and linking it back via
     * stays_contacts.corp_account_id. Returns the account id (0 on failure / ineligible).
     * Idempotent: returns the existing corp_account_id if already linked + valid.
     */
    function db_enable_account_billing($db, int $orgId, int $contactId): int
    {
        $c = db_contact($db, $orgId, $contactId);
        if (!$c) { return 0; }
        if (!in_array((string) $c['type'], ['company', 'ngo', 'agent'], true)) { return 0; } // individuals can't direct-bill
        $existing = (int) ($c['corp_account_id'] ?? 0);
        if ($existing > 0 && $db->has('stays_corporate_accounts', ['id' => $existing, 'org_id' => $orgId])) {
            return $existing; // already enabled
        }
        if (!function_exists('corp_add')) { return 0; }
        try {
            // corp_add's type enum is company|agent; an NGO contact maps to a 'company'
            // account record (the NGO distinction lives on the Customer-Book contact +
            // its tax_exempt flag, not the AR account).
            $acctType = ((string) $c['type'] === 'agent') ? 'agent' : 'company';
            $acctId = corp_add($db, $orgId, [
                'type'         => $acctType,
                'name'         => (string) $c['display_name'],
                'contact_name' => (string) ($c['display_name'] ?? ''),
                'email'        => (string) ($c['email'] ?? ''),
                'phone'        => (string) ($c['phone'] ?? ''),
                'negotiated_discount_pct' => $c['negotiated_discount_pct'] ?? '',
                'credit_limit' => $c['credit_limit'] ?? '',
            ]);
            if ($acctId <= 0) { return 0; }
            $db->update('stays_contacts',
                ['corp_account_id' => $acctId, 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $contactId, 'org_id' => $orgId]);
            return $acctId;
        } catch (\Throwable $e) { error_log('db_enable_account_billing: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('db_credit_check')) {
    /**
     * Would billing $folioBalance to $accountId breach its credit limit? Returns
     * ['limit'=>?f,'owed'=>f,'available'=>?f,'folio'=>f,'would_owe'=>f,'over'=>bool,
     *  'unlimited'=>bool]. owed = what the account already owes (this folio's own balance
     * is excluded if it's already linked, so re-checking an assigned folio is stable).
     * unlimited when the account has no credit_limit set (null) → never "over".
     */
    function db_credit_check($db, int $orgId, int $accountId, int $folioId, float $folioBalance): array
    {
        $out = ['limit' => null, 'owed' => 0.0, 'available' => null, 'folio' => round($folioBalance, 2),
                'would_owe' => round($folioBalance, 2), 'over' => false, 'unlimited' => true];
        if ($orgId <= 0 || $accountId <= 0) { return $out; }
        if (!function_exists('ar_ext_statement')) { return $out; }
        $st = ar_ext_statement($db, $orgId, $accountId);
        $limit = $st['credit_limit'] ?? null;
        // Sum what the account already owes across its open folios — EXCLUDING this folio
        // (so a re-check of an already-linked folio doesn't double-count it).
        $owed = 0.0;
        foreach (($st['lines'] ?? []) as $ln) {
            if ((int) ($ln['folio_id'] ?? 0) === $folioId) { continue; }
            $owed += (float) ($ln['balance'] ?? 0);
        }
        $owed = round($owed, 2);
        $out['owed'] = $owed;
        $out['would_owe'] = round($owed + $folioBalance, 2);
        if ($limit === null) { return $out; } // no limit = unlimited
        $out['unlimited'] = false;
        $out['limit'] = round((float) $limit, 2);
        $out['available'] = round((float) $limit - $owed, 2);
        $out['over'] = ($out['would_owe'] > $out['limit'] + 0.001);
        return $out;
    }
}

if (!function_exists('db_assign')) {
    /**
     * Direct-bill a folio to a contact's account (or clear it when $contactId<=0).
     * Enforces the credit rule: if billing would exceed the limit, BLOCK unless
     * $isOwner AND $override (owner/manager override), which is then LOGGED to the AR
     * collections log. Reuses ar_ext_assign_account for the actual link. Returns
     * ['ok'=>bool,'message'=>,'over'=>bool,'needs_override'=>bool].
     */
    function db_assign($db, int $orgId, int $folioId, int $contactId, bool $isOwner, bool $override): array
    {
        if ($orgId <= 0 || $folioId <= 0) { return ['ok' => false, 'message' => 'Bad request.']; }
        if (!function_exists('ar_ext_assign_account')) { return ['ok' => false, 'message' => 'Receivables engine unavailable.']; }

        // Clear the assignment (bill the guest directly again).
        if ($contactId <= 0) {
            $ok = ar_ext_assign_account($db, $orgId, $folioId, 0);
            return ['ok' => $ok, 'message' => $ok ? 'Direct-bill removed — the guest settles this folio.' : 'Could not update.', 'over' => false, 'needs_override' => false];
        }

        $c = db_contact($db, $orgId, $contactId);
        if (!$c || !in_array((string) $c['type'], ['company', 'ngo', 'agent'], true)) {
            return ['ok' => false, 'message' => 'Pick a company or NGO customer to bill.', 'over' => false, 'needs_override' => false];
        }
        $acctId = (int) ($c['corp_account_id'] ?? 0);
        if ($acctId <= 0 || !$db->has('stays_corporate_accounts', ['id' => $acctId, 'org_id' => $orgId])) {
            return ['ok' => false, 'message' => 'This customer has no billing account yet — enable billing first.', 'over' => false, 'needs_override' => false, 'needs_enable' => true];
        }

        // Credit check against this folio's current balance.
        $bal = 0.0;
        if (function_exists('folio_totals')) { $bal = round((float) (folio_totals($db, $folioId)['balance'] ?? 0), 2); }
        $chk = db_credit_check($db, $orgId, $acctId, $folioId, $bal);

        if ($chk['over']) {
            if (!($isOwner && $override)) {
                return ['ok' => false, 'over' => true, 'needs_override' => true,
                    'message' => 'Over credit limit — ' . number_format((float) $chk['available'], 2) . ' available, this stay is ' . number_format($bal, 2) . '.'];
            }
            // Owner override — proceed, and LOG it to the AR collections log (audit).
            if (function_exists('ar_ext_collection_add')) {
                ar_ext_collection_add($db, $orgId, $acctId, [
                    'kind'   => 'note',
                    'detail' => 'Credit-limit OVERRIDE on direct-bill by ' . ((string) ($_SESSION['user_id'] ?? '?'))
                        . ': billed ' . number_format($bal, 2) . ' (limit ' . number_format((float) $chk['limit'], 2)
                        . ', would owe ' . number_format((float) $chk['would_owe'], 2) . ').',
                ]);
            }
        }

        $ok = ar_ext_assign_account($db, $orgId, $folioId, $acctId);
        if (!$ok) { return ['ok' => false, 'message' => 'Could not assign (check folio/account).', 'over' => $chk['over'], 'needs_override' => false]; }
        $msg = 'Billed to ' . (string) $c['display_name'] . '.';
        if ($chk['over']) { $msg .= ' (credit limit overridden)'; }
        return ['ok' => true, 'message' => $msg, 'over' => $chk['over'], 'needs_override' => false];
    }
}

if (!function_exists('db_current_assignment')) {
    /**
     * The contact currently billed for a folio (resolved via the corporate account on
     * stays_ar_folio_account → back to the Customer-Book contact), or null. For showing
     * "currently billed to X" on the reservation screen.
     */
    function db_current_assignment($db, int $orgId, int $folioId): ?array
    {
        if ($orgId <= 0 || $folioId <= 0) { return null; }
        try {
            $link = $db->get('stays_ar_folio_account', ['account_id'], ['org_id' => $orgId, 'folio_id' => $folioId]);
            $acctId = $link ? (int) $link['account_id'] : 0;
            if ($acctId <= 0) { return null; }
            // Prefer the Customer-Book contact that owns this account; fall back to the
            // corporate account name if no contact links to it.
            $contact = $db->get('stays_contacts', ['id', 'display_name', 'type'],
                ['org_id' => $orgId, 'corp_account_id' => $acctId]);
            if ($contact) { return ['account_id' => $acctId, 'contact_id' => (int) $contact['id'], 'name' => (string) $contact['display_name'], 'type' => (string) $contact['type']]; }
            $acct = $db->get('stays_corporate_accounts', ['name'], ['id' => $acctId, 'org_id' => $orgId]);
            return ['account_id' => $acctId, 'contact_id' => 0, 'name' => $acct ? (string) $acct['name'] : ('Account #' . $acctId), 'type' => 'company'];
        } catch (\Throwable $e) { error_log('db_current_assignment: ' . $e->getMessage()); return null; }
    }
}

if (!function_exists('db_enforce_post')) {
    /**
     * Phase 4a — CREDIT ENFORCEMENT at posting/checkout time. Given a folio and an extra
     * amount about to be added to what it owes ($addAmount; 0 at checkout = "is the
     * CURRENT balance already over?"), decide whether it is allowed when that folio is
     * DIRECT-BILLED to a city-ledger account:
     *   - folio NOT linked to an account  -> always ALLOW (a normal guest folio; the guest
     *     settles it, so there is no account credit limit to breach).
     *   - linked + within limit            -> ALLOW.
     *   - linked + would exceed the limit  -> BLOCK, unless $isOwner && $override (logged).
     * This is the SAME rule as Phase-3 direct-bill, now applied when charges are posted
     * so a stay can't quietly run a company past its limit after assignment. It is called
     * from the DESK action handlers (where auth + override live), NOT from the shared
     * folio_add_item primitive (which has ~18 callers and must stay side-effect-free).
     * Returns ['ok'=>bool,'message'=>,'over'=>bool,'needs_override'=>bool].
     */
    function db_enforce_post($db, int $orgId, int $folioId, float $addAmount, bool $isOwner, bool $override): array
    {
        $out = ['ok' => true, 'message' => '', 'over' => false, 'needs_override' => false];
        if ($orgId <= 0 || $folioId <= 0) { return $out; }
        // Only enforce when this folio is direct-billed to an account.
        $acct = db_current_assignment($db, $orgId, $folioId);
        if (!$acct || (int) ($acct['account_id'] ?? 0) <= 0) { return $out; } // guest folio → no limit
        $acctId = (int) $acct['account_id'];

        $bal = 0.0;
        if (function_exists('folio_totals')) { $bal = round((float) (folio_totals($db, $folioId)['balance'] ?? 0), 2); }
        // The balance AFTER this post is what we must fit under the limit.
        $effective = round($bal + round($addAmount, 2), 2);
        $chk = db_credit_check($db, $orgId, $acctId, $folioId, $effective);
        if (!$chk['over']) { return $out; }

        if (!($isOwner && $override)) {
            return ['ok' => false, 'over' => true, 'needs_override' => true,
                'message' => 'This charge would put ' . (string) ($acct['name'] ?? 'the account')
                    . ' over its credit limit (' . number_format((float) $chk['available'], 2) . ' available). A manager can override.'];
        }
        // Owner override — allow + log to the AR trail (same place Phase-3 logs overrides).
        if (function_exists('ar_ext_collection_add')) {
            ar_ext_collection_add($db, $orgId, $acctId, [
                'kind'   => 'note',
                'detail' => 'Credit-limit OVERRIDE on posting by ' . ((string) ($_SESSION['user_id'] ?? '?'))
                    . ': +' . number_format(round($addAmount, 2), 2) . ' (would owe '
                    . number_format((float) $chk['would_owe'], 2) . ' vs limit ' . number_format((float) $chk['limit'], 2) . ').',
            ]);
        }
        return ['ok' => true, 'over' => true, 'needs_override' => false, 'message' => 'Posted (credit limit overridden).'];
    }
}

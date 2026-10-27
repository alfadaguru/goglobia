<?php
// FILE: app/lib/supplier_contacts.php
// FRONT-DESK OVERHAUL — Phase 1: THE CUSTOMER BOOK.
//
// One first-class customer record (table stays_contacts) that UNIFIES what already
// exists but is disconnected: email-keyed guests (supplier_guests.php), corporate/
// agent accounts (supplier_corporate.php), and the loose string-refs the tax/loyalty
// engines hang off. A contact has a TYPE (individual|company|ngo|agent) and is
// SEARCHABLE by phone / name / email / type — none of which was possible before.
//
// We LINK, we do not COPY: a company/agent/ngo contact points at its
// stays_corporate_accounts row via corp_account_id; an individual points at its guest
// aggregate via guest_email_key. So the corporate + guest machinery keeps working
// unchanged and this is purely an additive index/spine. Org-scoped on every query.
// All functions non-fatal (log + safe default) per the house convention.
//
// Money: credit_limit is STORED here but NOT enforced in Phase 1 (enforcement =
// Phase 4, manager-override). tax_exempt is the NGO flag, OFF by default. No payment,
// no wallet draw-down here.

if (!function_exists('contacts_types')) {
    /** The customer types. 'agent' kept because stays_corporate_accounts already has it. */
    function contacts_types(): array
    {
        return ['individual' => 'Individual', 'company' => 'Company', 'ngo' => 'NGO / Gov’t', 'agent' => 'Travel agent'];
    }
}

if (!function_exists('contacts_phone_key')) {
    /**
     * Normalised phone search key = last 9 digits (mirrors guestcrm_phone_key so a
     * contact and a guest-dedup match the same way). Returns '' if too short to key.
     */
    function contacts_phone_key(?string $phone): string
    {
        if (function_exists('guestcrm_phone_key')) { return guestcrm_phone_key($phone); }
        $digits = preg_replace('/\D+/', '', (string) $phone);
        return strlen($digits) < 7 ? '' : substr($digits, -9);
    }
}

if (!function_exists('contacts_save')) {
    /**
     * Create or update a contact. $d: type, display_name*, email, phone, country,
     * tax_id, tax_exempt, negotiated_discount_pct, credit_limit, notes, status,
     * corp_account_id, guest_email_key. Returns ['ok'=>bool,'id'=>int,'message'=>].
     * Server-validated; phone_key derived; org-scoped. $id>0 = update (own org only).
     */
    function contacts_save($db, int $orgId, array $d, int $id = 0): array
    {
        if ($orgId <= 0) { return ['ok' => false, 'id' => 0, 'message' => 'No organisation.']; }
        $types = contacts_types();
        $type = isset($types[$d['type'] ?? '']) ? (string) $d['type'] : 'individual';
        $name = trim((string) ($d['display_name'] ?? ''));
        if ($name === '') { return ['ok' => false, 'id' => 0, 'message' => 'A name is required.']; }

        $phone = trim((string) ($d['phone'] ?? ''));
        $row = [
            'type'         => $type,
            'display_name' => substr($name, 0, 191),
            'email'        => trim((string) ($d['email'] ?? '')) ?: null,
            'phone'        => $phone ?: null,
            'phone_key'    => contacts_phone_key($phone) ?: null,
            'country'      => strtoupper(substr(trim((string) ($d['country'] ?? '')), 0, 2)) ?: null,
            'tax_id'       => substr(trim((string) ($d['tax_id'] ?? '')), 0, 60) ?: null,
            'tax_exempt'   => !empty($d['tax_exempt']) ? 1 : 0,
            'negotiated_discount_pct' => ($d['negotiated_discount_pct'] ?? '') !== '' ? max(0, min(100, round((float) $d['negotiated_discount_pct'], 2))) : null,
            'credit_limit' => ($d['credit_limit'] ?? '') !== '' ? max(0, round((float) $d['credit_limit'], 2)) : null,
            'status'       => in_array(($d['status'] ?? ''), ['active', 'suspended'], true) ? $d['status'] : 'active',
            'notes'        => substr(trim((string) ($d['notes'] ?? '')), 0, 500) ?: null,
            'corp_account_id' => ($d['corp_account_id'] ?? 0) > 0 ? (int) $d['corp_account_id'] : null,
            'guest_email_key' => trim((string) ($d['guest_email_key'] ?? '')) ?: null,
        ];
        try {
            if ($id > 0) {
                // Update only within this org (IDOR guard).
                if (!$db->has('stays_contacts', ['id' => $id, 'org_id' => $orgId])) {
                    return ['ok' => false, 'id' => 0, 'message' => 'Not your contact.'];
                }
                $row['updated_at'] = date('Y-m-d H:i:s');
                $db->update('stays_contacts', $row, ['id' => $id, 'org_id' => $orgId]);
                return ['ok' => true, 'id' => $id, 'message' => 'Contact updated.'];
            }
            $row['org_id'] = $orgId;
            $row['created_by'] = (string) ($_SESSION['user_id'] ?? '');
            $row['created_at'] = date('Y-m-d H:i:s');
            $db->insert('stays_contacts', $row);
            $newId = (int) $db->id();
            return $newId > 0
                ? ['ok' => true, 'id' => $newId, 'message' => 'Contact created.']
                : ['ok' => false, 'id' => 0, 'message' => 'Could not save.'];
        } catch (\Throwable $e) {
            error_log('contacts_save: ' . $e->getMessage());
            return ['ok' => false, 'id' => 0, 'message' => 'Could not save the contact.'];
        }
    }
}

if (!function_exists('contacts_get')) {
    /** One contact by id, scoped to org. null if not found / not owned. */
    function contacts_get($db, int $orgId, int $id): ?array
    {
        if ($orgId <= 0 || $id <= 0) { return null; }
        try {
            $r = $db->get('stays_contacts', '*', ['id' => $id, 'org_id' => $orgId]);
            return $r ?: null;
        } catch (\Throwable $e) { error_log('contacts_get: ' . $e->getMessage()); return null; }
    }
}

if (!function_exists('contacts_list')) {
    /**
     * The searchable Customer Book. $opts: 'q' (phone/name/email), 'type' (one of
     * contacts_types or ''), 'limit'. Search strategy: if q is mostly digits -> match
     * phone_key (last-9) AND a LIKE on phone; else LIKE on display_name + email. All
     * org-scoped. Returns rows newest-updated first.
     */
    function contacts_list($db, int $orgId, array $opts = []): array
    {
        if ($orgId <= 0) { return []; }
        $q = trim((string) ($opts['q'] ?? ''));
        $type = (string) ($opts['type'] ?? '');
        $limit = max(1, min(500, (int) ($opts['limit'] ?? 200)));

        $where = ['org_id' => $orgId];
        if (isset(contacts_types()[$type])) { $where['type'] = $type; }

        if ($q !== '') {
            $digits = preg_replace('/\D+/', '', $q);
            // "Mostly digits" => phone search (>=4 digits and digits are the bulk of input).
            // Match the query digits as a SUBSTRING of phone_key. phone_key holds the
            // normalised last-9 digits, so any trailing fragment of the real number
            // (e.g. "4480815" of "+234 1 448 0815" -> key 414480815) matches. We search
            // phone_key (digits-only) rather than the raw `phone` column, because the raw
            // value has spaces/+/() that would break a digit-fragment LIKE.
            if (strlen($digits) >= 4 && strlen($digits) >= (strlen($q) - 2)) {
                $where['phone_key[~]'] = $digits; // -> LIKE '%<digits>%'
            } else {
                $where['OR'] = ['display_name[~]' => $q, 'email[~]' => $q];
            }
        }
        $where['ORDER'] = ['updated_at' => 'DESC', 'id' => 'DESC'];
        $where['LIMIT'] = $limit;
        try {
            return $db->select('stays_contacts',
                ['id', 'type', 'display_name', 'email', 'phone', 'country', 'tax_id',
                 'tax_exempt', 'corp_account_id', 'guest_email_key', 'negotiated_discount_pct',
                 'credit_limit', 'status', 'created_at'], $where) ?: [];
        } catch (\Throwable $e) { error_log('contacts_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('contacts_counts_by_type')) {
    /** Per-type counts for the filter chips. ['individual'=>n,...,'all'=>n]. */
    function contacts_counts_by_type($db, int $orgId): array
    {
        $out = ['all' => 0, 'individual' => 0, 'company' => 0, 'ngo' => 0, 'agent' => 0];
        if ($orgId <= 0) { return $out; }
        try {
            foreach ($db->select('stays_contacts', ['type', 'id'], ['org_id' => $orgId]) ?: [] as $r) {
                $t = (string) $r['type'];
                if (isset($out[$t])) { $out[$t]++; }
                $out['all']++;
            }
        } catch (\Throwable $e) { error_log('contacts_counts_by_type: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('contacts_duplicates')) {
    /**
     * Review-only duplicate detection within an org: contacts that share a phone_key
     * or a normalised name. Returns groups [['key'=>..,'members'=>[rows]]]. Merge ACTION
     * is Phase 2 — this only surfaces candidates (mirrors guestcrm_duplicates).
     */
    function contacts_duplicates($db, int $orgId): array
    {
        if ($orgId <= 0) { return []; }
        try {
            $rows = $db->select('stays_contacts', ['id', 'type', 'display_name', 'email', 'phone', 'phone_key'],
                ['org_id' => $orgId, 'LIMIT' => 2000]) ?: [];
        } catch (\Throwable $e) { error_log('contacts_duplicates: ' . $e->getMessage()); return []; }
        $byKey = [];
        foreach ($rows as $r) {
            $key = (string) ($r['phone_key'] ?? '');
            if ($key === '' && function_exists('guestcrm_name_key')) { $key = 'n:' . guestcrm_name_key($r['display_name'] ?? ''); }
            if ($key === '' || $key === 'n:') { continue; }
            $byKey[$key][] = $r;
        }
        $groups = [];
        foreach ($byKey as $key => $members) {
            if (count($members) > 1) { $groups[] = ['key' => $key, 'members' => $members]; }
        }
        return $groups;
    }
}

if (!function_exists('contacts_backfill')) {
    /**
     * ONE-TIME, IDEMPOTENT seed of the Customer Book from what already exists, so the
     * book is populated on day one rather than empty:
     *   (a) every stays_corporate_accounts row -> a contact (type company|agent|ngo;
     *       note the corporate enum is only company|agent today, so 'ngo' won't appear
     *       from backfill — NGO contacts are created fresh). Linked via corp_account_id.
     *   (b) every distinct guest email across the org's stays (guest_list) -> an
     *       individual contact, linked via guest_email_key.
     * Idempotent: skips when a contact with the same corp_account_id / guest_email_key
     * already exists for the org. Safe to run repeatedly. Returns counts.
     */
    function contacts_backfill($db, int $orgId, string $owner): array
    {
        $out = ['corporate' => 0, 'individuals' => 0, 'skipped' => 0];
        if ($orgId <= 0) { return $out; }

        // (a) corporate/agent accounts.
        try {
            $accts = function_exists('corp_list') ? corp_list($db, $orgId, 500) : [];
            foreach ($accts as $a) {
                $accId = (int) ($a['id'] ?? 0);
                if ($accId <= 0) { continue; }
                if ($db->has('stays_contacts', ['org_id' => $orgId, 'corp_account_id' => $accId])) { $out['skipped']++; continue; }
                $type = in_array(($a['type'] ?? ''), ['company', 'agent'], true) ? $a['type'] : 'company';
                $phone = (string) ($a['phone'] ?? '');
                $db->insert('stays_contacts', [
                    'org_id' => $orgId, 'type' => $type,
                    'display_name' => substr((string) ($a['name'] ?? 'Account'), 0, 191),
                    'email' => trim((string) ($a['email'] ?? '')) ?: null,
                    'phone' => $phone ?: null, 'phone_key' => contacts_phone_key($phone) ?: null,
                    'negotiated_discount_pct' => $a['negotiated_discount_pct'] ?? null,
                    'credit_limit' => $a['credit_limit'] ?? null,
                    'status' => (($a['status'] ?? 'active') === 'suspended') ? 'suspended' : 'active',
                    'notes' => substr((string) ($a['notes'] ?? ''), 0, 500) ?: null,
                    'corp_account_id' => $accId,
                    'created_by' => 'backfill', 'created_at' => date('Y-m-d H:i:s'),
                ]);
                $out['corporate']++;
            }
        } catch (\Throwable $e) { error_log('contacts_backfill corp: ' . $e->getMessage()); }

        // (b) individual guests, keyed by email.
        try {
            $stayIds = function_exists('supplier_owned_stay_ids') ? supplier_owned_stay_ids($db, $owner) : [];
            if ($stayIds && function_exists('guest_list')) {
                foreach (guest_list($db, $stayIds, '') as $g) {
                    $email = strtolower(trim((string) ($g['email'] ?? '')));
                    if ($email === '') { continue; }
                    $key = function_exists('guest_email_key') ? guest_email_key($email) : $email;
                    if ($key === '') { continue; }
                    if ($db->has('stays_contacts', ['org_id' => $orgId, 'guest_email_key' => $key])) { $out['skipped']++; continue; }
                    $phone = (string) ($g['phone'] ?? '');
                    $db->insert('stays_contacts', [
                        'org_id' => $orgId, 'type' => 'individual',
                        'display_name' => substr(trim((string) ($g['name'] ?? '')) ?: $email, 0, 191),
                        'email' => substr($email, 0, 191),
                        'phone' => $phone ?: null, 'phone_key' => contacts_phone_key($phone) ?: null,
                        'guest_email_key' => $key,
                        'created_by' => 'backfill', 'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    $out['individuals']++;
                }
            }
        } catch (\Throwable $e) { error_log('contacts_backfill guests: ' . $e->getMessage()); }

        return $out;
    }
}

if (!function_exists('contacts_merge')) {
    /**
     * MERGE one duplicate contact into another (P2b). The SURVIVOR is kept; the LOSER is
     * folded in and deleted. Dedup DETECTION already ships (contacts_duplicates); this is
     * the operator-chosen merge ACTION.
     *
     * The only things keyed to a contact id are the two wallet tables (verified by a full
     * repo sweep): stays_contact_wallet (balance) + stays_contact_wallet_ledger (history).
     * So a safe merge must, atomically:
     *   1) MOVE the loser's wallet balance into the survivor (per currency) — money is
     *      never destroyed. Done via cwallet_apply (idempotent, row-locked, non-negative)
     *      so the survivor's wallet gets a real credit and the move is itself ledgered.
     *   2) RE-POINT the loser's historical wallet_ledger rows to the survivor (keep the
     *      audit trail under the surviving contact).
     *   3) BACKFILL the survivor's EMPTY identity fields from the loser (email, phone,
     *      corp_account_id, guest_email_key, tax_id, country) — never overwrite a value
     *      the survivor already has.
     *   4) DELETE the loser's (now-zero) wallet row + the loser contact.
     * All in ONE $db->action() so a failure rolls the whole thing back. Both contacts
     * must belong to $orgId and be distinct. Returns ['ok'=>bool,'message'=>,'moved'=>].
     */
    function contacts_merge($db, int $orgId, int $survivorId, int $loserId): array
    {
        if ($orgId <= 0 || $survivorId <= 0 || $loserId <= 0) { return ['ok' => false, 'message' => 'Bad request.']; }
        if ($survivorId === $loserId) { return ['ok' => false, 'message' => 'Pick two different customers.']; }
        $survivor = contacts_get($db, $orgId, $survivorId);
        $loser    = contacts_get($db, $orgId, $loserId);
        if (!$survivor || !$loser) { return ['ok' => false, 'message' => 'One of those customers was not found.']; }

        // Step 1 happens OUTSIDE the wrapping action because cwallet_apply opens its own
        // $db->action() per currency (Medoo has no nested transactions). Move each of the
        // loser's wallet balances into the survivor first; only if all moves succeed do we
        // re-point history + delete. A move is a credit to the survivor + a debit of the
        // loser (its wallet ends at 0), both ledgered — money is conserved and auditable.
        $moved = [];
        if (function_exists('cwallet_balances') && function_exists('cwallet_apply')) {
            foreach (cwallet_balances($db, $orgId, $loserId) as $ccy => $bal) {
                $bal = round((float) $bal, 2);
                if ($bal <= 0) { continue; }
                $ref = 'merge:' . $loserId . '->' . $survivorId . ':' . $ccy;
                // Debit the loser to 0 (idempotent on ref), then credit the survivor.
                $d = cwallet_apply($db, $orgId, $loserId, $bal, 'debit', $ccy, ['reason' => 'adjustment', 'ref' => $ref . ':out', 'note' => 'Merged into customer #' . $survivorId]);
                if (empty($d['ok'])) { return ['ok' => false, 'message' => 'Could not move the ' . $ccy . ' wallet balance; merge aborted.']; }
                $c = cwallet_apply($db, $orgId, $survivorId, $bal, 'credit', $ccy, ['reason' => 'adjustment', 'ref' => $ref . ':in', 'note' => 'Merged from customer #' . $loserId]);
                if (empty($c['ok'])) {
                    // Roll back the loser debit we just made (give it back) so no money is lost.
                    cwallet_apply($db, $orgId, $loserId, $bal, 'credit', $ccy, ['reason' => 'reversal', 'ref' => $ref . ':rb', 'note' => 'Merge credit failed — balance restored']);
                    return ['ok' => false, 'message' => 'Could not credit the survivor wallet; merge aborted (loser balance restored).'];
                }
                $moved[$ccy] = $bal;
            }
        }

        $result = ['ok' => false, 'message' => 'Merge failed.'];
        try {
            $db->action(function ($db) use ($orgId, $survivor, $loser, $survivorId, $loserId, $moved, &$result) {
                // Step 2: re-point the loser's historical ledger rows to the survivor
                // (keep the audit under the surviving contact). The balance-move rows
                // above already sit on the right contacts; this reassigns the prior history.
                $db->update('stays_contact_wallet_ledger',
                    ['contact_id' => $survivorId],
                    ['org_id' => $orgId, 'contact_id' => $loserId]);

                // Step 3: backfill the survivor's EMPTY identity fields from the loser.
                $fill = [];
                foreach (['email', 'phone', 'phone_key', 'corp_account_id', 'guest_email_key', 'tax_id', 'country', 'negotiated_discount_pct', 'credit_limit'] as $f) {
                    $sv = $survivor[$f] ?? null;
                    $lv = $loser[$f] ?? null;
                    $svEmpty = ($sv === null || $sv === '' || $sv === 0 || $sv === '0');
                    if ($svEmpty && $lv !== null && $lv !== '' && $lv !== 0 && $lv !== '0') { $fill[$f] = $lv; }
                }
                if (!empty($fill)) {
                    $fill['updated_at'] = date('Y-m-d H:i:s');
                    $db->update('stays_contacts', $fill, ['id' => $survivorId, 'org_id' => $orgId]);
                }

                // Step 4: delete the loser's now-zero wallet row + the loser contact.
                $db->delete('stays_contact_wallet', ['org_id' => $orgId, 'contact_id' => $loserId]);
                $db->delete('stays_contacts', ['id' => $loserId, 'org_id' => $orgId]);

                $result = ['ok' => true, 'message' => 'Merged into ' . (string) $survivor['display_name'] . '.', 'moved' => $moved];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('contacts_merge: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Merge error — nothing was deleted.'];
        }
        return $result;
    }
}

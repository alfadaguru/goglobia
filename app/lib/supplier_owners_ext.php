<?php
// FILE: app/lib/supplier_owners_ext.php
// PROPERTY OWNER & LANDLORD — EXTENSION (PMS module #48 deepening; docs 01b §48,
// architecture 01a §5). Builds the genuine gaps on top of app/lib/supplier_owners.php
// (which already does: owner records, management agreements + commissions, owner
// expenses, statement preview/generate, mark-paid). This file adds ONLY what was
// missing from the #48 spec:
//
//   - owner VERIFICATION / KYC            owner_kyc_set / owner_kyc_badge
//   - CO-OWNERSHIP / ownership mapping    owner_coowner_set / owner_coowners_list /
//                                         owner_coowner_remove / owner_split_for
//   - owner DOCUMENTS (metadata)          owner_document_add / owner_documents_list /
//                                         owner_document_remove
//   - owner-USAGE reservations            owner_usage_add / owner_usage_list /
//     (owner blocks a unit for own use —  owner_usage_cancel
//      a NON-revenue internal block)
//   - owner running BALANCE               owner_balance / owner_balances_all
//   - owner PORTFOLIO report              owner_portfolio
//   - owner COMMUNICATIONS (logged)       owner_comm_log / owner_comms_list
//   - owner PORTAL                        owner_portal_status (HONEST STUB)
//
// MONEY/GL POLICY (unchanged from the base): owner payout is the OWNER-MONEY layer,
// deliberately NOT posted to the operator GL here (the base module header + 01a §5
// place a trust/client-money ledger + real owner bank payout in later work, #50).
// So owner_balance is a RUNNING LEDGER derived from the already-persisted
// stays_owner_statements (generated − paid) — it never invents figures and never
// trusts a client amount. Anything that would move real money or post double-entry
// is labelled DB-GATED / stubbed below, never faked.
//
// All amounts are read server-side from the base tables; org ownership is enforced
// by the caller routes (supplier_can + org scoping). Every fn if_not_exists-guarded.

if (!function_exists('owner_ext_owner_in_org')) {
    /** Guard: owner record $ownerId belongs to $orgId (and is readable). Returns bool. */
    function owner_ext_owner_in_org($db, int $orgId, int $ownerId): bool
    {
        if ($orgId <= 0 || $ownerId <= 0) { return false; }
        try { return (bool) $db->has('stays_owners', ['id' => $ownerId, 'org_id' => $orgId]); }
        catch (\Throwable $e) { error_log('owner_ext_owner_in_org: ' . $e->getMessage()); return false; }
    }
}

// =============================================================================
// VERIFICATION / KYC  (DB-GATED columns: stays_owners.kyc_status/national_id/
// verified_at/verified_by — added by the ALTER in the patch spec. Guarded reads.)
// =============================================================================

if (!function_exists('owner_kyc_statuses')) {
    /** KYC lifecycle states (config, not DB). */
    function owner_kyc_statuses(): array
    {
        return ['unverified' => 'Unverified', 'pending' => 'Pending review', 'verified' => 'Verified', 'rejected' => 'Rejected'];
    }
}

if (!function_exists('owner_kyc_set')) {
    /**
     * Set an owner's KYC status (operator-side verification; there is NO external
     * identity provider wired here — verification is a manual operator decision, so
     * this records the DECISION, not a live KYC check). 'verified'/'rejected' stamp
     * who+when. national_id is stored as a reference string only. Org-scoped.
     * Returns ['ok','message'].
     */
    function owner_kyc_set($db, int $orgId, int $ownerId, string $status, string $nationalId = ''): array
    {
        $status = strtolower(trim($status));
        if (!array_key_exists($status, owner_kyc_statuses())) { return ['ok' => false, 'message' => 'Unknown KYC status.']; }
        if (!owner_ext_owner_in_org($db, $orgId, $ownerId)) { return ['ok' => false, 'message' => 'Owner not found.']; }
        try {
            $data = ['kyc_status' => $status];
            if ($nationalId !== '') { $data['national_id'] = substr(trim($nationalId), 0, 120); }
            if ($status === 'verified' || $status === 'rejected') {
                $data['verified_at'] = date('Y-m-d H:i:s');
                $data['verified_by'] = substr((string) ($_SESSION['user_id'] ?? ''), 0, 64);
            }
            $db->update('stays_owners', $data, ['id' => $ownerId, 'org_id' => $orgId]);
            return ['ok' => true, 'message' => 'KYC status updated to ' . owner_kyc_statuses()[$status] . '.'];
        } catch (\Throwable $e) {
            error_log('owner_kyc_set: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not update KYC (column may be missing — run the DB update).'];
        }
    }
}

if (!function_exists('owner_kyc_badge')) {
    /** A small label+tone for an owner row's KYC state (falls back to unverified). */
    function owner_kyc_badge(?string $status): array
    {
        $status = strtolower((string) $status) ?: 'unverified';
        $map = [
            'verified'   => ['Verified', 'badge-success'],
            'pending'    => ['Pending', 'badge-warning'],
            'rejected'   => ['Rejected', 'badge-error'],
            'unverified' => ['Unverified', 'badge-info'],
        ];
        return $map[$status] ?? $map['unverified'];
    }
}

// =============================================================================
// CO-OWNERSHIP / OWNERSHIP MAPPING  (new table: stays_owner_coowners)
// A property may have MULTIPLE owners splitting the payout by % share. This is the
// "ownership mapping / co-ownership" sub-feature. The primary agreement owner
// (stays_management_agreements.owner_id) is treated as the lead; co-owners refine
// how the owner_payout is DIVIDED. We never let shares exceed 100%.
// =============================================================================

if (!function_exists('owner_coowners_list')) {
    /** List co-ownership shares for a property (each: owner_id, share_pct, owner name). */
    function owner_coowners_list($db, int $orgId, int $stayId): array
    {
        if ($orgId <= 0 || $stayId <= 0) { return []; }
        try {
            $rows = $db->select('stays_owner_coowners', ['id', 'owner_id', 'share_pct'],
                ['org_id' => $orgId, 'stay_id' => $stayId, 'ORDER' => ['share_pct' => 'DESC']]) ?: [];
            if (!$rows) { return []; }
            $ids = array_values(array_unique(array_map(fn($r) => (int) $r['owner_id'], $rows)));
            $names = [];
            foreach ($db->select('stays_owners', ['id', 'name'], ['id' => $ids, 'org_id' => $orgId]) ?: [] as $o) {
                $names[(int) $o['id']] = (string) $o['name'];
            }
            foreach ($rows as &$r) { $r['owner_name'] = $names[(int) $r['owner_id']] ?? ('Owner #' . (int) $r['owner_id']); }
            return $rows;
        } catch (\Throwable $e) { error_log('owner_coowners_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('owner_coowner_set')) {
    /**
     * Upsert a co-owner share for a property. Both the property and the owner must
     * belong to $orgId. The combined shares (including this one) may not exceed 100%
     * — checked atomically under a row-lock so two concurrent writers can't push the
     * total over 100. Returns ['ok','message']. 0 < share_pct <= 100.
     */
    function owner_coowner_set($db, int $orgId, int $stayId, int $ownerId, float $sharePct): array
    {
        $sharePct = round($sharePct, 2);
        if ($orgId <= 0 || $stayId <= 0 || $ownerId <= 0) { return ['ok' => false, 'message' => 'Bad input.']; }
        if ($sharePct <= 0 || $sharePct > 100) { return ['ok' => false, 'message' => 'Share must be between 0 and 100%.']; }
        if (!owner_ext_owner_in_org($db, $orgId, $ownerId)) { return ['ok' => false, 'message' => 'Owner not found.']; }
        try {
            if (!$db->has('stays', ['id' => $stayId, 'org_id' => $orgId])) { return ['ok' => false, 'message' => 'Not your property.']; }
        } catch (\Throwable $e) { return ['ok' => false, 'message' => 'Not your property.']; }

        $result = ['ok' => false, 'message' => 'Could not save share.'];
        try {
            $db->action(function ($db) use ($orgId, $stayId, $ownerId, $sharePct, &$result) {
                // Lock the property's existing co-owner rows; sum everyone EXCEPT the one
                // we're about to (re)write so an edit replaces rather than double-counts.
                $existing = $db->select('stays_owner_coowners', ['id', 'owner_id', 'share_pct'],
                    ['org_id' => $orgId, 'stay_id' => $stayId, 'FOR UPDATE' => true]) ?: [];
                $otherTotal = 0.0; $mineId = 0;
                foreach ($existing as $r) {
                    if ((int) $r['owner_id'] === $ownerId) { $mineId = (int) $r['id']; continue; }
                    $otherTotal += (float) $r['share_pct'];
                }
                if (round($otherTotal + $sharePct, 2) > 100.0) {
                    $result = ['ok' => false, 'message' => 'Shares would exceed 100% (others already hold ' . round($otherTotal, 2) . '%).'];
                    return false; // rolls back
                }
                if ($mineId > 0) {
                    $db->update('stays_owner_coowners', ['share_pct' => $sharePct, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $mineId]);
                } else {
                    $db->insert('stays_owner_coowners', [
                        'org_id' => $orgId, 'stay_id' => $stayId, 'owner_id' => $ownerId,
                        'share_pct' => $sharePct, 'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
                $result = ['ok' => true, 'message' => 'Co-owner share saved.'];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('owner_coowner_set: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not save share.'];
        }
        return $result;
    }
}

if (!function_exists('owner_coowner_remove')) {
    /** Remove a co-owner share row (org-scoped). Returns bool. */
    function owner_coowner_remove($db, int $orgId, int $rowId): bool
    {
        if ($orgId <= 0 || $rowId <= 0) { return false; }
        try {
            $r = $db->delete('stays_owner_coowners', ['id' => $rowId, 'org_id' => $orgId]);
            return $r && $r->rowCount() > 0;
        } catch (\Throwable $e) { error_log('owner_coowner_remove: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('owner_split_for')) {
    /**
     * Resolve how an owner_payout should be DIVIDED among co-owners for a property.
     * Returns [ ['owner_id','owner_name','share_pct','amount'], ... ].
     *   - If co-owner rows exist and sum to 100%, split by those shares.
     *   - If they exist but sum < 100%, the REMAINDER goes to the lead agreement owner
     *     (so the full payout is always allocated, never lost).
     *   - If none exist, 100% to the lead agreement owner.
     * Pure arithmetic over the supplied payout — never reads/trusts a client amount.
     */
    function owner_split_for($db, int $orgId, int $stayId, float $ownerPayout, int $leadOwnerId): array
    {
        $ownerPayout = round($ownerPayout, 2);
        $rows = owner_coowners_list($db, $orgId, $stayId);
        $out = [];
        $allocated = 0.0; $sharesTotal = 0.0; $leadSeen = false;
        foreach ($rows as $r) {
            $pct = (float) $r['share_pct']; $sharesTotal += $pct;
        }
        foreach ($rows as $r) {
            $pct = (float) $r['share_pct'];
            $amt = round($ownerPayout * $pct / 100, 2);
            $allocated = round($allocated + $amt, 2);
            if ((int) $r['owner_id'] === $leadOwnerId) { $leadSeen = true; }
            $out[] = ['owner_id' => (int) $r['owner_id'], 'owner_name' => $r['owner_name'], 'share_pct' => $pct, 'amount' => $amt];
        }
        $remainder = round($ownerPayout - $allocated, 2);
        if ($remainder !== 0.0 || empty($out)) {
            $leadName = '';
            try { $ln = $db->get('stays_owners', ['name'], ['id' => $leadOwnerId, 'org_id' => $orgId]); $leadName = $ln ? (string) $ln['name'] : 'Lead owner'; }
            catch (\Throwable $e) { $leadName = 'Lead owner'; }
            if ($leadSeen) {
                // Add the unallocated remainder to the lead's existing line.
                foreach ($out as &$o) { if ($o['owner_id'] === $leadOwnerId) { $o['amount'] = round($o['amount'] + $remainder, 2); break; } }
            } else {
                $out[] = ['owner_id' => $leadOwnerId, 'owner_name' => $leadName,
                          'share_pct' => round(100 - $sharesTotal, 2), 'amount' => $remainder];
            }
        }
        return $out;
    }
}

// =============================================================================
// OWNER DOCUMENTS  (new table: stays_owner_documents — metadata only)
// Mirrors stays_documents (#48 "owner documents"): title, type, reference, expiry,
// notes. This stores the RECORD of a document, not the binary (file upload is the
// shared uploadFile path and out of scope for this deepening — see deferred[]).
// =============================================================================

if (!function_exists('owner_document_types')) {
    function owner_document_types(): array
    {
        return ['agreement' => 'Management agreement', 'id' => 'Owner ID', 'tax' => 'Tax / W-form',
                'bank' => 'Bank mandate', 'insurance' => 'Insurance', 'other' => 'Other'];
    }
}

if (!function_exists('owner_document_add')) {
    /** Record an owner document (metadata). Org-scoped; owner must belong to org. */
    function owner_document_add($db, int $orgId, int $ownerId, array $data): array
    {
        if (!owner_ext_owner_in_org($db, $orgId, $ownerId)) { return ['ok' => false, 'message' => 'Owner not found.']; }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') { return ['ok' => false, 'message' => 'Document title is required.']; }
        $type = strtolower(trim((string) ($data['doc_type'] ?? 'other')));
        if (!array_key_exists($type, owner_document_types())) { $type = 'other'; }
        $expires = trim((string) ($data['expires_on'] ?? ''));
        if ($expires !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) { $expires = ''; }
        try {
            $db->insert('stays_owner_documents', [
                'org_id'     => $orgId,
                'owner_id'   => $ownerId,
                'title'      => substr($title, 0, 191),
                'doc_type'   => $type,
                'reference'  => substr(trim((string) ($data['reference'] ?? '')), 0, 120) ?: null,
                'expires_on' => $expires ?: null,
                'notes'      => substr(trim((string) ($data['notes'] ?? '')), 0, 500) ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return ['ok' => true, 'message' => 'Document recorded.'];
        } catch (\Throwable $e) {
            error_log('owner_document_add: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not record document.'];
        }
    }
}

if (!function_exists('owner_documents_list')) {
    function owner_documents_list($db, int $orgId, int $ownerId): array
    {
        if ($orgId <= 0 || $ownerId <= 0) { return []; }
        try {
            return $db->select('stays_owner_documents',
                ['id', 'title', 'doc_type', 'reference', 'expires_on', 'notes', 'created_at'],
                ['org_id' => $orgId, 'owner_id' => $ownerId, 'ORDER' => ['id' => 'DESC']]) ?: [];
        } catch (\Throwable $e) { error_log('owner_documents_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('owner_document_remove')) {
    function owner_document_remove($db, int $orgId, int $docId): bool
    {
        if ($orgId <= 0 || $docId <= 0) { return false; }
        try {
            $r = $db->delete('stays_owner_documents', ['id' => $docId, 'org_id' => $orgId]);
            return $r && $r->rowCount() > 0;
        } catch (\Throwable $e) { error_log('owner_document_remove: ' . $e->getMessage()); return false; }
    }
}

// =============================================================================
// OWNER-USAGE RESERVATIONS  (new table: stays_owner_usage)
// The owner blocks their own unit for personal use over a date range. This is a
// NON-revenue internal block (no folio, no GL, no earnings). We overlap-guard it
// per property so two owner-usage blocks can't collide. NOTE: this writes an
// owner-usage RECORD only; wiring it into the live availability/hold engine so it
// actually blocks guest bookings is DB-GATED follow-on (see deferred[]).
// =============================================================================

if (!function_exists('owner_usage_add')) {
    /**
     * Record an owner-usage block [date_from, date_to) for a property. Overlap-checked
     * under a lock. date_to is the checkout (exclusive). Returns ['ok','message','id'].
     */
    function owner_usage_add($db, int $orgId, int $stayId, int $ownerId, string $from, string $to, string $note = ''): array
    {
        if ($orgId <= 0 || $stayId <= 0 || $ownerId <= 0) { return ['ok' => false, 'message' => 'Bad input.']; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { return ['ok' => false, 'message' => 'Bad dates.']; }
        if ($to <= $from) { return ['ok' => false, 'message' => 'Check-out must be after check-in.']; }
        if (!owner_ext_owner_in_org($db, $orgId, $ownerId)) { return ['ok' => false, 'message' => 'Owner not found.']; }
        try {
            if (!$db->has('stays', ['id' => $stayId, 'org_id' => $orgId])) { return ['ok' => false, 'message' => 'Not your property.']; }
        } catch (\Throwable $e) { return ['ok' => false, 'message' => 'Not your property.']; }

        $result = ['ok' => false, 'message' => 'Could not save owner usage.'];
        try {
            $db->action(function ($db) use ($orgId, $stayId, $ownerId, $from, $to, $note, &$result) {
                // Half-open overlap test: existing.from < new.to AND existing.to > new.from.
                $clash = $db->get('stays_owner_usage', ['id', 'date_from', 'date_to'],
                    ['org_id' => $orgId, 'stay_id' => $stayId, 'status' => 'booked',
                     'date_from[<]' => $to, 'date_to[>]' => $from, 'FOR UPDATE' => true]);
                if ($clash) {
                    $result = ['ok' => false, 'message' => 'Overlaps an existing owner block (' . $clash['date_from'] . ' → ' . $clash['date_to'] . ').'];
                    return false;
                }
                $db->insert('stays_owner_usage', [
                    'org_id' => $orgId, 'stay_id' => $stayId, 'owner_id' => $ownerId,
                    'date_from' => $from, 'date_to' => $to, 'status' => 'booked',
                    'note' => substr(trim($note), 0, 255) ?: null, 'created_at' => date('Y-m-d H:i:s'),
                ]);
                $result = ['ok' => true, 'message' => 'Owner-usage block recorded.', 'id' => (int) $db->id()];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('owner_usage_add: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not save owner usage.'];
        }
        return $result;
    }
}

if (!function_exists('owner_usage_list')) {
    /** Upcoming + recent owner-usage blocks for the org (optionally one property). */
    function owner_usage_list($db, int $orgId, int $stayId = 0): array
    {
        if ($orgId <= 0) { return []; }
        $where = ['org_id' => $orgId, 'ORDER' => ['date_from' => 'DESC'], 'LIMIT' => 100];
        if ($stayId > 0) { $where['stay_id'] = $stayId; }
        try { return $db->select('stays_owner_usage', ['id', 'stay_id', 'owner_id', 'date_from', 'date_to', 'status', 'note'], $where) ?: []; }
        catch (\Throwable $e) { error_log('owner_usage_list: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('owner_usage_cancel')) {
    /** Cancel an owner-usage block (state-guarded booked→cancelled). Org-scoped. */
    function owner_usage_cancel($db, int $orgId, int $usageId): bool
    {
        if ($orgId <= 0 || $usageId <= 0) { return false; }
        try {
            $r = $db->update('stays_owner_usage', ['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $usageId, 'org_id' => $orgId, 'status' => 'booked']);
            return $r && $r->rowCount() === 1;
        } catch (\Throwable $e) { error_log('owner_usage_cancel: ' . $e->getMessage()); return false; }
    }
}

// =============================================================================
// OWNER RUNNING BALANCE + PORTFOLIO REPORT
// Owner balance = money the operator OWES the owner = Σ(generated statements
// owner_payout) − Σ(paid statements owner_payout), per owner per currency. Derived
// entirely from the already-persisted stays_owner_statements — no new money moves,
// no client amounts. (Real payout settlement = #50 trust accounting — DB-GATED.)
// =============================================================================

if (!function_exists('owner_balance')) {
    /**
     * Running balance for ONE owner: per-currency ['outstanding','paid','total'].
     *   outstanding = Σ owner_payout WHERE status='generated'
     *   paid        = Σ owner_payout WHERE status='paid'
     *   total       = outstanding + paid (lifetime earned for the owner)
     * Returns ['USD'=>['outstanding'=>..,'paid'=>..,'total'=>..], ...].
     */
    function owner_balance($db, int $orgId, int $ownerId): array
    {
        $out = [];
        if ($orgId <= 0 || $ownerId <= 0) { return $out; }
        try {
            $rows = $db->select('stays_owner_statements', ['currency', 'owner_payout', 'status'],
                ['org_id' => $orgId, 'owner_id' => $ownerId]) ?: [];
            foreach ($rows as $r) {
                $c = strtoupper((string) ($r['currency'] ?? 'USD')) ?: 'USD';
                if (!isset($out[$c])) { $out[$c] = ['outstanding' => 0.0, 'paid' => 0.0, 'total' => 0.0]; }
                $amt = (float) $r['owner_payout'];
                if (($r['status'] ?? '') === 'paid') { $out[$c]['paid'] += $amt; }
                else { $out[$c]['outstanding'] += $amt; }
                $out[$c]['total'] += $amt;
            }
            foreach ($out as $c => $v) {
                $out[$c] = ['outstanding' => round($v['outstanding'], 2), 'paid' => round($v['paid'], 2), 'total' => round($v['total'], 2)];
            }
        } catch (\Throwable $e) { error_log('owner_balance: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('owner_balances_all')) {
    /** Balance per owner for the whole org: [ownerId => (owner_balance shape)]. */
    function owner_balances_all($db, int $orgId): array
    {
        $out = [];
        if ($orgId <= 0) { return $out; }
        try {
            foreach ($db->select('stays_owners', ['id'], ['org_id' => $orgId]) ?: [] as $o) {
                $out[(int) $o['id']] = owner_balance($db, $orgId, (int) $o['id']);
            }
        } catch (\Throwable $e) { error_log('owner_balances_all: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('owner_portfolio')) {
    /**
     * Portfolio report for ONE owner: the properties they are the LEAD agreement owner
     * of (plus any co-owned), each with its active commission terms and lifetime
     * statement totals. Read-only aggregation over the base tables. Returns:
     *   ['properties'=>[ ['stay_id','name','manager_pct','fixed_fee','is_coowner',
     *                      'share_pct','currency','gross','owner_payout'] ], 'balance'=>...]
     */
    function owner_portfolio($db, int $orgId, int $ownerId): array
    {
        $res = ['properties' => [], 'balance' => []];
        if ($orgId <= 0 || $ownerId <= 0) { return $res; }
        try {
            // Properties where this owner is the LEAD (active agreement).
            $lead = $db->select('stays_management_agreements',
                ['stay_id', 'manager_commission_pct', 'fixed_fee'],
                ['org_id' => $orgId, 'owner_id' => $ownerId, 'active' => 1]) ?: [];
            $byStay = [];
            foreach ($lead as $a) {
                $byStay[(int) $a['stay_id']] = [
                    'stay_id' => (int) $a['stay_id'], 'manager_pct' => (float) $a['manager_commission_pct'],
                    'fixed_fee' => (float) $a['fixed_fee'], 'is_coowner' => false, 'share_pct' => 100.0,
                ];
            }
            // Co-owned properties (may or may not also be lead).
            foreach ($db->select('stays_owner_coowners', ['stay_id', 'share_pct'],
                        ['org_id' => $orgId, 'owner_id' => $ownerId]) ?: [] as $c) {
                $sid = (int) $c['stay_id'];
                if (!isset($byStay[$sid])) {
                    $byStay[$sid] = ['stay_id' => $sid, 'manager_pct' => 0.0, 'fixed_fee' => 0.0, 'is_coowner' => true, 'share_pct' => (float) $c['share_pct']];
                } else {
                    $byStay[$sid]['is_coowner'] = true;
                    $byStay[$sid]['share_pct'] = (float) $c['share_pct'];
                }
            }
            if (!$byStay) { $res['balance'] = owner_balance($db, $orgId, $ownerId); return $res; }
            $ids = array_keys($byStay);
            // Property names.
            foreach ($db->select('stays', ['id', 'name'], ['id' => $ids]) ?: [] as $p) {
                if (isset($byStay[(int) $p['id']])) { $byStay[(int) $p['id']]['name'] = (string) $p['name']; }
            }
            // Lifetime statement totals per property (this owner's statements).
            $stmts = $db->select('stays_owner_statements', ['stay_id', 'currency', 'gross', 'owner_payout'],
                ['org_id' => $orgId, 'owner_id' => $ownerId]) ?: [];
            $tot = [];
            foreach ($stmts as $s) {
                $sid = (int) $s['stay_id'];
                if (!isset($tot[$sid])) { $tot[$sid] = ['gross' => 0.0, 'owner_payout' => 0.0, 'currency' => strtoupper((string) ($s['currency'] ?? 'USD'))]; }
                $tot[$sid]['gross'] += (float) $s['gross'];
                $tot[$sid]['owner_payout'] += (float) $s['owner_payout'];
            }
            foreach ($byStay as $sid => &$row) {
                $row['name'] = $row['name'] ?? ('Property #' . $sid);
                $row['currency'] = $tot[$sid]['currency'] ?? '';
                $row['gross'] = round($tot[$sid]['gross'] ?? 0.0, 2);
                $row['owner_payout'] = round($tot[$sid]['owner_payout'] ?? 0.0, 2);
            }
            $res['properties'] = array_values($byStay);
            $res['balance'] = owner_balance($db, $orgId, $ownerId);
        } catch (\Throwable $e) { error_log('owner_portfolio: ' . $e->getMessage()); }
        return $res;
    }
}

// =============================================================================
// OWNER COMMUNICATIONS  (new table: stays_owner_comms — logged)
// Records a communication with an owner (note / email intent). ACTUAL email send is
// DB-GATED: there is no owner-portal mailing pipeline wired here, so owner_comm_log
// stores the record and (optionally) attempts a send via the existing SENDEMAIL
// helper ONLY if present and the owner has an email — otherwise it is logged as a
// note. It NEVER claims a message was delivered that wasn't.
// =============================================================================

if (!function_exists('owner_comm_log')) {
    /**
     * Log a communication to an owner. channel ∈ {note,email}. For 'email', if the
     * owner has an address AND SENDEMAIL() exists, we attempt a real send and record
     * the actual outcome in `delivery`; otherwise delivery='logged' (not sent).
     * Returns ['ok','message','delivery'].
     */
    function owner_comm_log($db, int $orgId, int $ownerId, string $channel, string $subject, string $body): array
    {
        if (!owner_ext_owner_in_org($db, $orgId, $ownerId)) { return ['ok' => false, 'message' => 'Owner not found.']; }
        $channel = in_array($channel, ['note', 'email'], true) ? $channel : 'note';
        $subject = substr(trim($subject), 0, 191);
        $body = trim($body);
        if ($subject === '' && $body === '') { return ['ok' => false, 'message' => 'Nothing to send.']; }

        $delivery = 'logged';
        if ($channel === 'email') {
            $delivery = 'not_sent';
            try {
                $o = $db->get('stays_owners', ['email', 'name'], ['id' => $ownerId, 'org_id' => $orgId]);
                $email = $o ? trim((string) ($o['email'] ?? '')) : '';
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && function_exists('SENDEMAIL')) {
                    // Honest: reuse the platform mailer (sig: toEmail,toName,subject,htmlBody).
                    // If it throws / returns falsy we mark not_sent — we never record 'sent'
                    // optimistically.
                    $sent = @SENDEMAIL($email, (string) ($o['name'] ?? ''), $subject ?: 'Message from your property manager', nl2br(htmlspecialchars($body)));
                    $delivery = $sent ? 'sent' : 'not_sent';
                }
            } catch (\Throwable $e) { error_log('owner_comm_log send: ' . $e->getMessage()); $delivery = 'not_sent'; }
        }
        try {
            $db->insert('stays_owner_comms', [
                'org_id'     => $orgId,
                'owner_id'   => $ownerId,
                'channel'    => $channel,
                'subject'    => $subject ?: null,
                'body'       => $body ?: null,
                'delivery'   => $delivery,
                'created_by' => substr((string) ($_SESSION['user_id'] ?? ''), 0, 64),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('owner_comm_log insert: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not log communication.'];
        }
        $msg = $channel === 'email'
            ? ($delivery === 'sent' ? 'Email sent and logged.' : 'Logged (email NOT sent — no deliverable address or mailer unavailable).')
            : 'Note logged.';
        return ['ok' => true, 'message' => $msg, 'delivery' => $delivery];
    }
}

if (!function_exists('owner_comms_list')) {
    function owner_comms_list($db, int $orgId, int $ownerId): array
    {
        if ($orgId <= 0 || $ownerId <= 0) { return []; }
        try {
            return $db->select('stays_owner_comms', ['id', 'channel', 'subject', 'body', 'delivery', 'created_at'],
                ['org_id' => $orgId, 'owner_id' => $ownerId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 50]) ?: [];
        } catch (\Throwable $e) { error_log('owner_comms_list: ' . $e->getMessage()); return []; }
    }
}

// =============================================================================
// OWNER PORTAL  (HONEST STUB)
// The #48 spec asks for an "owner portal" — a separate login where a landlord sees
// their own statements/balances/documents. There is NO owner-user auth in this
// platform (owners are records, not users). Building a real portal means minting
// owner login accounts + a scoped session — out of scope for this deepening and a
// cross-cutting auth change. This reports that status honestly so the UI can show
// a truthful "not connected" state and NOT pretend a portal exists.
// =============================================================================

if (!function_exists('owner_portal_status')) {
    /** Honest portal readiness: always 'not_connected' here (no owner-login subsystem). */
    function owner_portal_status($db, int $orgId, int $ownerId): array
    {
        return [
            'connected' => false,
            'status'    => 'not_connected',
            'message'   => 'Owner self-service portal is not connected. Owners do not yet have login accounts; '
                         . 'share statements by exporting / emailing them from here. A real owner portal '
                         . '(owner login + scoped session) is planned alongside trust accounting (#50).',
        ];
    }
}

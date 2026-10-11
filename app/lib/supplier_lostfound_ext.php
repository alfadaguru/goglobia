<?php
// FILE: app/lib/supplier_lostfound_ext.php
// MODULE 29 (Lost & found → 360): the genuine sub-features missing from the base
// register (supplier_facilities.php: lostfound_add/set_status/list on stays_lost_found).
// Adds, on NEW tables only, the spec gaps:
//   · guest/reservation association + storage location + description (stays_lost_found_detail)
//   · claim verification (claimant + proof + who verified)
//   · return/shipping (method, carrier, tracking, recipient)
//   · disposal policy (per-org retention window) + "due for disposal" report
//   · audit history (append-only trail of every event)
// Base rows stay the system of record for item + status; this extends them 1:1.
// All functions are property-scoped — the CALLER authorizes the stay via supplier_can.
// Defensive/non-fatal. NOT runtime-verified (no MySQL in the build env) — php -l only.

// ---------------------------------------------------------------------------
// Internal: resolve a lost-found item's owning stay_id (for IDOR re-checks in routes).
// ---------------------------------------------------------------------------
if (!function_exists('lostfound_item_stay_id')) {
    /** Return the stay_id that owns a lost-found item id, or 0 if not found. */
    function lostfound_item_stay_id($db, int $itemId): int
    {
        if ($itemId <= 0) { return 0; }
        try {
            $row = $db->get('stays_lost_found', ['stay_id'], ['id' => $itemId]);
            return $row ? (int) $row['stay_id'] : 0;
        } catch (\Throwable $e) { error_log('lostfound_item_stay_id: ' . $e->getMessage()); return 0; }
    }
}

// ---------------------------------------------------------------------------
// AUDIT HISTORY — append-only. Never updated/deleted. Every meaningful change writes one row.
// ---------------------------------------------------------------------------
if (!function_exists('lostfound_audit')) {
    /**
     * Append an audit entry for a lost-found item. $event is a short verb
     * (e.g. 'created','status','claim','return','dispose','detail'); $detail is free text.
     * Actor is resolved from the session. Returns bool; swallows failures (audit must
     * never break the primary action).
     */
    function lostfound_audit($db, int $itemId, int $stayId, string $event, string $detail = ''): bool
    {
        if ($itemId <= 0) { return false; }
        try {
            $actor = (string) ($_SESSION['first_name'] ?? '') . ' ' . (string) ($_SESSION['last_name'] ?? '');
            $actor = trim($actor);
            if ($actor === '') { $actor = (string) ($_SESSION['email'] ?? 'staff'); }
            $db->insert('stays_lost_found_audit', [
                'item_id'    => $itemId,
                'stay_id'    => $stayId,
                'event'      => substr($event, 0, 40),
                'detail'     => substr($detail, 0, 500) ?: null,
                'actor'      => substr($actor, 0, 155) ?: null,
                'actor_id'   => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) { error_log('lostfound_audit: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('lostfound_audit_trail')) {
    /** Read the audit trail for one item (newest first). Read-only. */
    function lostfound_audit_trail($db, int $itemId, int $limit = 100): array
    {
        if ($itemId <= 0) { return []; }
        try {
            return $db->select('stays_lost_found_audit', '*',
                ['item_id' => $itemId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) { error_log('lostfound_audit_trail: ' . $e->getMessage()); return []; }
    }
}

// ---------------------------------------------------------------------------
// DETAIL ROW — the 1:1 extension of a base item (upserted; never money).
// Holds: description, storage location, guest/reservation association,
// claim-verification fields, return/shipping fields, disposal fields.
// ---------------------------------------------------------------------------
if (!function_exists('lostfound_detail_get')) {
    /** The detail row for an item (or [] if none yet). Read-only. */
    function lostfound_detail_get($db, int $itemId): array
    {
        if ($itemId <= 0) { return []; }
        try {
            return $db->get('stays_lost_found_detail', '*', ['item_id' => $itemId]) ?: [];
        } catch (\Throwable $e) { error_log('lostfound_detail_get: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('lostfound_detail_map')) {
    /** item_id => detail-row map for a set of items (for list rendering). Read-only. */
    function lostfound_detail_map($db, array $itemIds): array
    {
        $out = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if (empty($ids)) { return $out; }
        try {
            foreach ($db->select('stays_lost_found_detail', '*', ['item_id' => $ids]) ?: [] as $r) {
                $out[(int) $r['item_id']] = $r;
            }
        } catch (\Throwable $e) { error_log('lostfound_detail_map: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('lostfound_detail_save')) {
    /**
     * Upsert the extended detail for a base item. Property-scoped: $stayId MUST be the
     * item's owning stay (the route resolves it from the item, not from client input).
     * Associates a reservation only if the booking belongs to the same stay (IDOR guard).
     * Returns bool. Writes an audit entry on success.
     */
    function lostfound_detail_save($db, int $itemId, int $stayId, array $d): bool
    {
        if ($itemId <= 0 || $stayId <= 0) { return false; }
        try {
            // The base item must exist AND belong to $stayId (defence in depth).
            if (!$db->has('stays_lost_found', ['id' => $itemId, 'stay_id' => $stayId])) { return false; }

            // Reservation association — only accept a booking_id that belongs to THIS stay.
            // The `bookings` table has NO stay_id column; a stays booking carries the
            // property in booking_data.hotel_id (JSON). Decode + compare in PHP (the proven
            // path, notify.php:297-312) — never trust the raw booking_id (IDOR guard).
            $bookingId = (int) ($d['booking_id'] ?? 0);
            if ($bookingId > 0) {
                $own = false;
                try {
                    $raw = $db->get('bookings', 'booking_data', ['id' => $bookingId]);
                    if ($raw) {
                        $bData = json_decode((string) $raw, true);
                        $hotelId = is_array($bData) ? (int) ($bData['hotel_id'] ?? 0) : 0;
                        $own = ($hotelId === $stayId);
                    }
                } catch (\Throwable $e) { $own = false; }
                if (!$own) { $bookingId = 0; }
            }

            $fields = [
                'description'      => substr(trim((string) ($d['description'] ?? '')), 0, 500) ?: null,
                'storage_location' => substr(trim((string) ($d['storage_location'] ?? '')), 0, 191) ?: null,
                'booking_id'       => $bookingId > 0 ? $bookingId : null,
                'guest_contact'    => substr(trim((string) ($d['guest_contact'] ?? '')), 0, 191) ?: null,
                'updated_at'       => date('Y-m-d H:i:s'),
            ];
            if ($db->has('stays_lost_found_detail', ['item_id' => $itemId])) {
                $db->update('stays_lost_found_detail', $fields, ['item_id' => $itemId]);
            } else {
                $fields['item_id'] = $itemId;
                $fields['stay_id'] = $stayId;
                $fields['created_at'] = date('Y-m-d H:i:s');
                $db->insert('stays_lost_found_detail', $fields);
            }
            lostfound_audit($db, $itemId, $stayId, 'detail',
                'Detail saved' . ($bookingId > 0 ? (' · reservation #' . $bookingId) : ''));
            return true;
        } catch (\Throwable $e) { error_log('lostfound_detail_save: ' . $e->getMessage()); return false; }
    }
}

// ---------------------------------------------------------------------------
// CLAIM VERIFICATION — record who claimed, the proof shown, and the verifier.
// Transitions the base status to 'claimed'. No money.
// ---------------------------------------------------------------------------
if (!function_exists('lostfound_claim')) {
    /**
     * Record a verified claim. $stayId MUST be the item's owning stay. Requires a
     * claimant name + a verification note (the proof of ownership shown). Sets base
     * status→claimed and stamps verifier/time on the detail row. Returns bool.
     */
    function lostfound_claim($db, int $itemId, int $stayId, array $d): bool
    {
        if ($itemId <= 0 || $stayId <= 0) { return false; }
        $claimant = trim((string) ($d['claimant_name'] ?? ''));
        $proof    = trim((string) ($d['claim_proof'] ?? ''));
        if ($claimant === '' || $proof === '') { return false; }
        try {
            if (!$db->has('stays_lost_found', ['id' => $itemId, 'stay_id' => $stayId])) { return false; }
            $verifier = trim((string) ($_SESSION['first_name'] ?? '') . ' ' . (string) ($_SESSION['last_name'] ?? ''));
            if ($verifier === '') { $verifier = (string) ($_SESSION['email'] ?? 'staff'); }
            $fields = [
                'claimant_name'     => substr($claimant, 0, 155),
                'claimant_contact'  => substr(trim((string) ($d['claimant_contact'] ?? '')), 0, 191) ?: null,
                'claim_proof'       => substr($proof, 0, 500),
                'claim_verified_by' => substr($verifier, 0, 155),
                'claim_verified_at' => date('Y-m-d H:i:s'),
                'updated_at'        => date('Y-m-d H:i:s'),
            ];
            if ($db->has('stays_lost_found_detail', ['item_id' => $itemId])) {
                $db->update('stays_lost_found_detail', $fields, ['item_id' => $itemId]);
            } else {
                $fields['item_id'] = $itemId; $fields['stay_id'] = $stayId; $fields['created_at'] = date('Y-m-d H:i:s');
                $db->insert('stays_lost_found_detail', $fields);
            }
            $db->update('stays_lost_found', ['status' => 'claimed', 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $itemId, 'stay_id' => $stayId]);
            lostfound_audit($db, $itemId, $stayId, 'claim',
                'Claimed by ' . $claimant . ' · verified by ' . $verifier);
            return true;
        } catch (\Throwable $e) { error_log('lostfound_claim: ' . $e->getMessage()); return false; }
    }
}

// ---------------------------------------------------------------------------
// RETURN / SHIPPING — hand back in person or ship. Transitions base status→returned.
// Shipping is RECORD-KEEPING ONLY (carrier + tracking entered by staff). No carrier API
// is integrated — see deferred[]. No money is posted here.
// ---------------------------------------------------------------------------
if (!function_exists('lostfound_return_methods')) {
    function lostfound_return_methods(): array
    {
        return ['pickup' => 'Collected in person', 'shipped' => 'Shipped to guest'];
    }
}

if (!function_exists('lostfound_return')) {
    /**
     * Record a return. $stayId MUST be the item's owning stay. For 'shipped', a carrier
     * is required (tracking optional, since not all couriers issue one). Sets base
     * status→returned. Returns bool.
     */
    function lostfound_return($db, int $itemId, int $stayId, array $d): bool
    {
        if ($itemId <= 0 || $stayId <= 0) { return false; }
        $method = (string) ($d['return_method'] ?? '');
        if (!isset(lostfound_return_methods()[$method])) { return false; }
        $carrier = trim((string) ($d['return_carrier'] ?? ''));
        if ($method === 'shipped' && $carrier === '') { return false; }
        try {
            if (!$db->has('stays_lost_found', ['id' => $itemId, 'stay_id' => $stayId])) { return false; }
            $rd = trim((string) ($d['return_date'] ?? ''));
            $fields = [
                'return_method'    => $method,
                'return_recipient' => substr(trim((string) ($d['return_recipient'] ?? '')), 0, 155) ?: null,
                'return_carrier'   => $method === 'shipped' ? substr($carrier, 0, 120) : null,
                'return_tracking'  => $method === 'shipped' ? (substr(trim((string) ($d['return_tracking'] ?? '')), 0, 120) ?: null) : null,
                'return_date'      => preg_match('/^\d{4}-\d{2}-\d{2}$/', $rd) ? $rd : date('Y-m-d'),
                'updated_at'       => date('Y-m-d H:i:s'),
            ];
            if ($db->has('stays_lost_found_detail', ['item_id' => $itemId])) {
                $db->update('stays_lost_found_detail', $fields, ['item_id' => $itemId]);
            } else {
                $fields['item_id'] = $itemId; $fields['stay_id'] = $stayId; $fields['created_at'] = date('Y-m-d H:i:s');
                $db->insert('stays_lost_found_detail', $fields);
            }
            $db->update('stays_lost_found', ['status' => 'returned', 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $itemId, 'stay_id' => $stayId]);
            $via = $method === 'shipped' ? ('shipped via ' . $carrier . (($t = trim((string) ($d['return_tracking'] ?? ''))) !== '' ? (' (' . $t . ')') : '')) : 'collected in person';
            lostfound_audit($db, $itemId, $stayId, 'return', 'Returned — ' . $via);
            return true;
        } catch (\Throwable $e) { error_log('lostfound_return: ' . $e->getMessage()); return false; }
    }
}

// ---------------------------------------------------------------------------
// DISPOSAL POLICY (per-org retention window) + disposal action + due report.
// Policy is a simple retention period in days; the "due" report lists stored items
// older than the window so staff can dispose per policy. No money.
// ---------------------------------------------------------------------------
if (!function_exists('lostfound_policy_get')) {
    /** The org's retention policy ['retention_days'=>int,'disposal_method'=>string]. Defaults 90d. */
    function lostfound_policy_get($db, int $orgId): array
    {
        $out = ['retention_days' => 90, 'disposal_method' => ''];
        if ($orgId <= 0) { return $out; }
        try {
            $row = $db->get('stays_lost_found_policy', '*', ['org_id' => $orgId]);
            if ($row) {
                $out['retention_days'] = max(1, (int) ($row['retention_days'] ?? 90));
                $out['disposal_method'] = (string) ($row['disposal_method'] ?? '');
            }
        } catch (\Throwable $e) { error_log('lostfound_policy_get: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('lostfound_policy_save')) {
    /** Upsert the org retention policy. Caller must be org-authorized. Returns bool. */
    function lostfound_policy_save($db, int $orgId, array $d): bool
    {
        if ($orgId <= 0) { return false; }
        $days = (int) ($d['retention_days'] ?? 0);
        if ($days < 1) { $days = 90; }
        if ($days > 3650) { $days = 3650; }
        try {
            $fields = [
                'retention_days'  => $days,
                'disposal_method' => substr(trim((string) ($d['disposal_method'] ?? '')), 0, 191) ?: null,
                'updated_at'      => date('Y-m-d H:i:s'),
            ];
            if ($db->has('stays_lost_found_policy', ['org_id' => $orgId])) {
                $db->update('stays_lost_found_policy', $fields, ['org_id' => $orgId]);
            } else {
                $fields['org_id'] = $orgId; $fields['created_at'] = date('Y-m-d H:i:s');
                $db->insert('stays_lost_found_policy', $fields);
            }
            return true;
        } catch (\Throwable $e) { error_log('lostfound_policy_save: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('lostfound_dispose')) {
    /**
     * Dispose an item per policy. $stayId MUST be the item's owning stay. Records the
     * reason/method and transitions base status→disposed. Returns bool.
     */
    function lostfound_dispose($db, int $itemId, int $stayId, array $d): bool
    {
        if ($itemId <= 0 || $stayId <= 0) { return false; }
        try {
            if (!$db->has('stays_lost_found', ['id' => $itemId, 'stay_id' => $stayId])) { return false; }
            $actor = trim((string) ($_SESSION['first_name'] ?? '') . ' ' . (string) ($_SESSION['last_name'] ?? ''));
            if ($actor === '') { $actor = (string) ($_SESSION['email'] ?? 'staff'); }
            $reason = substr(trim((string) ($d['disposal_reason'] ?? '')), 0, 255);
            $fields = [
                'disposed_method' => substr(trim((string) ($d['disposal_method'] ?? '')), 0, 191) ?: null,
                'disposed_reason' => $reason ?: null,
                'disposed_by'     => substr($actor, 0, 155),
                'disposed_at'     => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ];
            if ($db->has('stays_lost_found_detail', ['item_id' => $itemId])) {
                $db->update('stays_lost_found_detail', $fields, ['item_id' => $itemId]);
            } else {
                $fields['item_id'] = $itemId; $fields['stay_id'] = $stayId; $fields['created_at'] = date('Y-m-d H:i:s');
                $db->insert('stays_lost_found_detail', $fields);
            }
            $db->update('stays_lost_found', ['status' => 'disposed', 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $itemId, 'stay_id' => $stayId]);
            lostfound_audit($db, $itemId, $stayId, 'dispose', 'Disposed' . ($reason !== '' ? (' — ' . $reason) : '') . ' · by ' . $actor);
            return true;
        } catch (\Throwable $e) { error_log('lostfound_dispose: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('lostfound_due_for_disposal')) {
    /**
     * Stored items across $stayIds whose found/created age exceeds the org retention
     * window. Read-only report. Each row carries 'age_days'. $orgId supplies the policy.
     */
    function lostfound_due_for_disposal($db, array $stayIds, int $orgId, int $limit = 300): array
    {
        if (empty($stayIds)) { return []; }
        $policy = lostfound_policy_get($db, $orgId);
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . (int) $policy['retention_days'] . ' days'));
        try {
            $rows = $db->select('stays_lost_found', '*', [
                'stay_id' => $stayIds,
                'status'  => 'stored',
                'created_at[<]' => $cutoff,
                'ORDER' => ['created_at' => 'ASC'],
                'LIMIT' => max(1, $limit),
            ]) ?: [];
            foreach ($rows as &$r) {
                $ts = strtotime((string) ($r['found_date'] ?: $r['created_at']));
                $r['age_days'] = $ts ? (int) floor((time() - $ts) / 86400) : null;
            }
            unset($r);
            return $rows;
        } catch (\Throwable $e) { error_log('lostfound_due_for_disposal: ' . $e->getMessage()); return []; }
    }
}

<?php
// FILE: app/lib/supplier_guestcrm_ext.php
// GUEST CRM — deepening (PMS #18). Builds the genuine spec gaps ON TOP of the
// read-only aggregate CRM in supplier_guests.php (profiles/history/notes/VIP/tags/
// preferences). NOTHING here is rebuilt from that file — it is consumed.
//
// Gaps covered here (docs 01b §2.18):
//   • Segmentation        — derived segments from the existing aggregate (New, Repeat,
//                           VIP, High-value, Lapsed) — pure function of guest_list().
//   • Guest lifetime value — explicit GLV = sum of PAID non-cancelled spend (the
//                           aggregate already computes total_spent; we surface + rank it).
//   • Tags surfacing       — a tag catalogue (distinct tags across org profiles) + a
//                           client-side filter; tags themselves are already stored/saved
//                           by guest_profile_save (we DO NOT re-implement that).
//   • Communication history — guestmsg for THIS guest's email across the owner's stays
//                           (existing guestmsg_list keys by stay/invoice; we add an
//                           email filter over the owner-scoped set).
//   • Complaints history    — ticket_list rows whose invoice belongs to this guest, OR
//                           whose guest_name matches — surfaced read-only.
//   • Consent management    — marketing/email/sms consent flags + source/timestamp on the
//                           existing stays_guest_profiles row. WRITABLE (the only new write).
//   • Duplicate detection   — same person under different emails, matched by normalized
//                           phone or normalized name, within the owner's own guest set.
//
// NO MONEY, NO GATEWAY, NO EXTERNAL CALLS. All reads are scoped to the owner's stay-id
// set (resolved + checked by the caller route). Every fn is function_exists-guarded and
// defensive (swallows DB errors to error_log). NOT runtime-verified (no MySQL) —
// logic-reasoned + php -l.

if (!function_exists('guestcrm_glv')) {
    /** Guest lifetime value = the aggregate's PAID, non-cancelled spend. Thin, explicit
     *  accessor so callers don't reach into profile internals. */
    function guestcrm_glv(array $profile): float
    {
        return round((float) ($profile['total_spent'] ?? 0), 2);
    }
}

if (!function_exists('guestcrm_segment')) {
    /**
     * Derive a guest's segment from an aggregated profile row (as produced by guest_list).
     * Returns ['key'=>..,'label'=>..,'tone'=>..] where tone is a Tailwind colour token.
     * $vip = owner's VIP flag for this guest (from stays_guest_profiles). $lapseDays = the
     * no-return threshold (days since last stay) that marks a guest "Lapsed".
     * Priority: VIP > High-value > Lapsed > Repeat > New. One primary segment per guest.
     */
    function guestcrm_segment(array $profile, bool $vip = false, float $highValue = 1000.0, int $lapseDays = 365): array
    {
        $stays = (int) ($profile['stays'] ?? 0);
        $spent = guestcrm_glv($profile);
        $last  = (string) ($profile['last_seen'] ?? '');
        $lapsed = false;
        if ($last !== '') {
            $ts = strtotime(substr($last, 0, 10));
            if ($ts !== false) { $lapsed = (time() - $ts) > ($lapseDays * 86400); }
        }
        if ($vip)                        { return ['key' => 'vip',     'label' => 'VIP',        'tone' => 'amber']; }
        if ($spent >= $highValue)        { return ['key' => 'high',    'label' => 'High value', 'tone' => 'violet']; }
        if ($lapsed && $stays > 0)       { return ['key' => 'lapsed',  'label' => 'Lapsed',     'tone' => 'rose']; }
        if ($stays >= 2)                 { return ['key' => 'repeat',  'label' => 'Repeat',     'tone' => 'emerald']; }
        return ['key' => 'new', 'label' => 'New', 'tone' => 'sky'];
    }
}

if (!function_exists('guestcrm_segment_counts')) {
    /** Tally segments over a guest list (for the list-page summary chips). $vipSet is a
     *  [email=>true] map of VIP guests (the route already builds it). */
    function guestcrm_segment_counts(array $guests, array $vipSet = [], float $highValue = 1000.0, int $lapseDays = 365): array
    {
        $counts = ['vip' => 0, 'high' => 0, 'lapsed' => 0, 'repeat' => 0, 'new' => 0];
        foreach ($guests as $g) {
            $seg = guestcrm_segment($g, !empty($vipSet[$g['email'] ?? '']), $highValue, $lapseDays);
            $counts[$seg['key']] = ($counts[$seg['key']] ?? 0) + 1;
        }
        return $counts;
    }
}

if (!function_exists('guestcrm_tag_split')) {
    /** Normalize a stored comma/space tag string into a clean lowercase token array. */
    function guestcrm_tag_split(?string $tags): array
    {
        $parts = preg_split('/[,\n]+/', strtolower((string) $tags)) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && !in_array($p, $out, true)) { $out[] = $p; }
        }
        return $out;
    }
}

if (!function_exists('guestcrm_tag_catalogue')) {
    /** Distinct tags across the org's saved guest profiles, with a usage count, sorted by
     *  frequency desc. Read-only. Bounded by the profiles table (owner-written, small). */
    function guestcrm_tag_catalogue($db, int $orgId, int $limit = 2000): array
    {
        if ($orgId <= 0) { return []; }
        $tally = [];
        try {
            $rows = $db->select('stays_guest_profiles', ['tags'], ['org_id' => $orgId, 'tags[!]' => null, 'LIMIT' => max(1, $limit)]) ?: [];
            foreach ($rows as $r) {
                foreach (guestcrm_tag_split($r['tags'] ?? '') as $t) { $tally[$t] = ($tally[$t] ?? 0) + 1; }
            }
        } catch (\Throwable $e) { error_log('guestcrm_tag_catalogue: ' . $e->getMessage()); }
        arsort($tally);
        $out = [];
        foreach ($tally as $tag => $n) { $out[] = ['tag' => $tag, 'count' => $n]; }
        return $out;
    }
}

if (!function_exists('guestcrm_consent_defaults')) {
    /** Default consent shape. consent_* are 0/1; source/updated are provenance. */
    function guestcrm_consent_defaults(): array
    {
        return ['consent_marketing' => 0, 'consent_email' => 0, 'consent_sms' => 0, 'consent_source' => '', 'consent_updated_at' => ''];
    }
}

if (!function_exists('guestcrm_consent_get')) {
    /** Read the consent block for (org, email) from stays_guest_profiles. Read-only.
     *  Columns are added by the ext schema (guarded ALTER) — missing columns → defaults. */
    function guestcrm_consent_get($db, int $orgId, string $email): array
    {
        $out = guestcrm_consent_defaults();
        $email = function_exists('guest_email_key') ? guest_email_key($email) : strtolower(trim($email));
        if ($orgId <= 0 || $email === '') { return $out; }
        try {
            $row = $db->get('stays_guest_profiles',
                ['consent_marketing', 'consent_email', 'consent_sms', 'consent_source', 'consent_updated_at'],
                ['org_id' => $orgId, 'email' => $email]);
            if ($row) {
                $out = [
                    'consent_marketing'  => (int) ($row['consent_marketing'] ?? 0),
                    'consent_email'      => (int) ($row['consent_email'] ?? 0),
                    'consent_sms'        => (int) ($row['consent_sms'] ?? 0),
                    'consent_source'     => (string) ($row['consent_source'] ?? ''),
                    'consent_updated_at' => (string) ($row['consent_updated_at'] ?? ''),
                ];
            }
        } catch (\Throwable $e) { error_log('guestcrm_consent_get: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('guestcrm_consent_save')) {
    /**
     * Upsert the consent block for (org, email). The ONLY new writable path in this ext.
     * Mirrors guest_profile_save's upsert discipline so the row stays a single (org,email)
     * record. $source records WHO/HOW consent was captured (e.g. 'staff:checkin'). Returns
     * bool. Caller MUST have already confirmed the guest booked with this owner.
     */
    function guestcrm_consent_save($db, int $orgId, string $email, array $data, string $source = 'staff'): bool
    {
        $email = function_exists('guest_email_key') ? guest_email_key($email) : strtolower(trim($email));
        if ($orgId <= 0 || $email === '') { return false; }
        try {
            $fields = [
                'consent_marketing'  => !empty($data['consent_marketing']) ? 1 : 0,
                'consent_email'      => !empty($data['consent_email']) ? 1 : 0,
                'consent_sms'        => !empty($data['consent_sms']) ? 1 : 0,
                'consent_source'     => substr(trim($source), 0, 60) ?: 'staff',
                'consent_updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($db->has('stays_guest_profiles', ['org_id' => $orgId, 'email' => $email])) {
                $db->update('stays_guest_profiles', $fields, ['org_id' => $orgId, 'email' => $email]);
            } else {
                $db->insert('stays_guest_profiles', array_merge($fields, [
                    'org_id' => $orgId, 'email' => $email, 'created_at' => date('Y-m-d H:i:s'),
                ]));
            }
            return true;
        } catch (\Throwable $e) { error_log('guestcrm_consent_save: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('guestcrm_comm_history')) {
    /**
     * Communication log for ONE guest email across the owner's stays. The existing
     * guestmsg_list keys by stay/invoice; here we scope to the owner's stays AND filter to
     * the guest's email (stays_guest_messages stores guest_email). Read-only. Bounded.
     */
    function guestcrm_comm_history($db, array $stayIds, string $email, int $limit = 200): array
    {
        $email = function_exists('guest_email_key') ? guest_email_key($email) : strtolower(trim($email));
        if (empty($stayIds) || $email === '') { return []; }
        try {
            return $db->select('stays_guest_messages', '*',
                ['stay_id' => array_map('intval', $stayIds), 'guest_email' => $email,
                 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) { error_log('guestcrm_comm_history: ' . $e->getMessage()); return []; }
    }
}

if (!function_exists('guestcrm_complaint_history')) {
    /**
     * Complaints/service tickets attached to THIS guest across the owner's stays. Tickets
     * carry invoice_id and guest_name (not email), so we match on the guest's own invoice
     * set (from guest_history stays) first, then fall back to a case-insensitive name match.
     * $stays = the rows from guest_history()['stays'] for this guest (already owner-scoped).
     * Read-only.
     */
    function guestcrm_complaint_history($db, array $stayIds, array $stays, string $guestName = '', int $limit = 200): array
    {
        if (empty($stayIds)) { return []; }
        $invoices = [];
        foreach ($stays as $s) { $inv = trim((string) ($s['invoice_id'] ?? '')); if ($inv !== '') { $invoices[$inv] = true; } }
        $where = ['stay_id' => array_map('intval', $stayIds), 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)];
        $out = [];
        try {
            $rows = $db->select('stays_service_tickets', '*', $where) ?: [];
            $gn = strtolower(trim($guestName));
            foreach ($rows as $r) {
                $inv = trim((string) ($r['invoice_id'] ?? ''));
                $rn  = strtolower(trim((string) ($r['guest_name'] ?? '')));
                $matchInv  = $inv !== '' && isset($invoices[$inv]);
                $matchName = $gn !== '' && $rn !== '' && $rn === $gn;
                if ($matchInv || $matchName) { $out[] = $r; }
            }
        } catch (\Throwable $e) { error_log('guestcrm_complaint_history: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('guestcrm_phone_key')) {
    /** Last-9-digits phone key for fuzzy duplicate matching (ignores +, spaces, country
     *  prefix noise). '' if fewer than 7 digits (too weak to match on). */
    function guestcrm_phone_key(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($digits) < 7) { return ''; }
        return substr($digits, -9);
    }
}

if (!function_exists('guestcrm_name_key')) {
    /** Normalized name key (lowercase, collapsed spaces, no punctuation) for dup matching. */
    function guestcrm_name_key(?string $name): string
    {
        $n = strtolower(trim((string) $name));
        $n = preg_replace('/[^a-z0-9 ]+/', '', $n);
        $n = preg_replace('/\s+/', ' ', (string) $n);
        return trim((string) $n);
    }
}

if (!function_exists('guestcrm_duplicates')) {
    /**
     * Detect likely-duplicate guests (same person under different emails) WITHIN the owner's
     * own aggregated guest list. Groups by normalized phone first (strong), then by
     * normalized full name (weaker). Returns only groups with >1 distinct email:
     *   [ ['match'=>'phone'|'name','value'=>..,'members'=>[guestProfile,...]], ... ]
     * Pure function of guest_list() output — no new queries. Bounded by the guest list size.
     */
    function guestcrm_duplicates(array $guests, int $maxGroups = 100): array
    {
        $byPhone = [];
        $byName  = [];
        foreach ($guests as $g) {
            $pk = guestcrm_phone_key($g['phone'] ?? '');
            $nk = guestcrm_name_key($g['name'] ?? '');
            if ($pk !== '') { $byPhone[$pk][] = $g; }
            if ($nk !== '') { $byName[$nk][]  = $g; }
        }
        $groups = [];
        $seenEmailsInPhone = [];
        foreach ($byPhone as $pk => $members) {
            $emails = array_unique(array_map(fn($m) => $m['email'] ?? '', $members));
            if (count($emails) > 1) {
                $groups[] = ['match' => 'phone', 'value' => $pk, 'members' => $members];
                foreach ($emails as $e) { $seenEmailsInPhone[$e] = true; }
            }
        }
        foreach ($byName as $nk => $members) {
            $emails = array_unique(array_map(fn($m) => $m['email'] ?? '', $members));
            if (count($emails) <= 1) { continue; }
            // Skip name-groups already fully explained by a phone group (avoid noise).
            $allSeen = true;
            foreach ($emails as $e) { if (empty($seenEmailsInPhone[$e])) { $allSeen = false; break; } }
            if ($allSeen) { continue; }
            $groups[] = ['match' => 'name', 'value' => $nk, 'members' => $members];
        }
        return array_slice($groups, 0, max(1, $maxGroups));
    }
}

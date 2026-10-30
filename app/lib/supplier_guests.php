<?php
// FILE: app/lib/supplier_guests.php
// GUEST CRM (Phase 1 inc S29; docs 01 §4.1 guest profile). Read-mostly.
//
// A returning-guest view aggregated from an ORG's bookings (across its owned
// properties) BY EMAIL — the stable guest key (a booking may have an empty user_id
// for guests/walk-ins). Produces a profile (name, phone, stays, total spent, first/
// last stay, properties) + per-guest stay history. The only WRITABLE part is an
// owner-added note/VIP flag per (org, email) in stays_guest_profiles.
//
// Scoping: everything is computed over the owner's stay-id set (resolved + checked
// by the caller route). Bookings are decoded + verified against that set (not just a
// LIKE). Email is matched case-insensitively (stored lowercased for the key).

if (!function_exists('guest_email_key')) {
    /** Canonical guest key = lowercased, trimmed email. '' if not a valid email. */
    function guest_email_key(?string $email): string
    {
        $e = strtolower(trim((string) $email));
        return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
    }
}

if (!function_exists('guests_phone_key')) {
    /** Normalised phone identity = last 9 digits (Nigeria-appropriate: a phone is the
     *  reliable key; local vs +234 forms converge on the same trailing digits). '' if too short. */
    function guests_phone_key(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        return strlen($digits) >= 7 ? substr($digits, -9) : '';
    }
}

if (!function_exists('guests_resolve_for_booking')) {
    /**
     * Resolve (or CREATE) the first-class GUEST record (the stayer) for a booking, and
     * return its stays_guests.id. The guest is ALWAYS stored when we have at least a name
     * or a phone — email is optional (a walk-in often has none). Dedupe order is
     * PHONE-FIRST, email-second (Nigeria: phone is the reliable identity):
     *   1) explicit guest_id, validated in-org.
     *   2) phone_key match + NAME match (phone alone collides across different people —
     *      shared lines, placeholders; the name guard stops a stranger-merge).
     *   3) email_key match.
     *   4) create a fresh guest.
     * Returns 0 only if there's nothing to key on (no name/phone/email) or org invalid —
     * caller leaves bookings.guest_id NULL (never fatal). Non-fatal + logged.
     */
    function guests_resolve_for_booking($db, int $orgId, array $g): int
    {
        if ($orgId <= 0) { return 0; }
        $picked = (int) ($g['guest_id'] ?? 0);
        if ($picked > 0 && $db->has('stays_guests', ['id' => $picked, 'org_id' => $orgId])) { return $picked; }

        $name  = trim(((string) ($g['first_name'] ?? '')) . ' ' . ((string) ($g['last_name'] ?? '')));
        if ($name === '' && !empty($g['display_name'])) { $name = trim((string) $g['display_name']); }
        $phone = trim((string) ($g['phone'] ?? ''));
        $email = strtolower(trim((string) ($g['email'] ?? '')));
        $pk    = guests_phone_key($phone);
        $ek    = function_exists('guest_email_key') ? guest_email_key($email) : ($email ?: '');
        $nameKey = function_exists('guestcrm_name_key') ? guestcrm_name_key($name) : strtolower($name);

        try {
            // 2) phone-first dedupe, guarded by a name match (unless we have no name to compare).
            if ($pk !== '') {
                $cands = $db->select('stays_guests', ['id', 'display_name'], ['org_id' => $orgId, 'phone_key' => $pk, 'LIMIT' => 20]) ?: [];
                foreach ($cands as $c) {
                    $cKey = function_exists('guestcrm_name_key') ? guestcrm_name_key($c['display_name'] ?? '') : strtolower((string) ($c['display_name'] ?? ''));
                    if ($nameKey === '' || $cKey === $nameKey) { return (int) $c['id']; }
                }
            }
            // 3) email dedupe (secondary).
            if ($ek !== '') {
                $hit = $db->get('stays_guests', ['id'], ['org_id' => $orgId, 'email_key' => $ek, 'LIMIT' => 1]);
                if ($hit) { return (int) $hit['id']; }
            }
            // 3b) RAW-PHONE + NAME fallback (audit N11). A guest who shares a phone with a
            //     different person was stored with phone_key = NULL (step 4 below, the #14
            //     fix) and, with no email, step 2's phone_key match never finds them again —
            //     so every repeat stay created a NEW duplicate record, fragmenting their
            //     history. Match on the RAW phone column + the same name to recover that
            //     prior null-key record. Name-guarded so it never merges two different people
            //     who share the number (that's exactly why the key was nulled).
            if ($pk !== '' && $phone !== '' && $nameKey !== '') {
                $byPhone = $db->select('stays_guests', ['id', 'display_name'],
                    ['org_id' => $orgId, 'phone' => $phone, 'LIMIT' => 20]) ?: [];
                foreach ($byPhone as $c) {
                    $cKey = function_exists('guestcrm_name_key') ? guestcrm_name_key($c['display_name'] ?? '') : strtolower((string) ($c['display_name'] ?? ''));
                    if ($cKey === $nameKey) { return (int) $c['id']; } // same person, recovered
                }
            }
            // 4) create — need at least a name or a phone.
            if ($name === '' && $pk === '') { return 0; }
            // PHONE-KEY COLLISION (audit #14): the name guard at (2) already declined to
            // merge into a same-phone_key DIFFERENT-named guest, so we're about to create a
            // genuinely distinct person. But UNIQUE(org,phone_key) would then throw and the
            // old catch resolved ANY phone_key hit back to the stranger — collapsing two
            // different people onto one record. Instead: if a same-phone_key guest with a
            // DIFFERENT name already exists, store this new guest with phone_key = NULL (the
            // raw phone is still saved + searchable); only the dedupe key is dropped for the
            // shared-number case. Same name → that's a real match and (2) already returned it.
            $pkForRow = $pk;
            if ($pk !== '') {
                $clash = $db->select('stays_guests', ['display_name'], ['org_id' => $orgId, 'phone_key' => $pk, 'LIMIT' => 20]) ?: [];
                if (!empty($clash)) {
                    // (2) didn't return → none of these share this name → all are different people.
                    $pkForRow = null;
                }
            }
            $row = [
                'org_id'       => $orgId,
                'display_name' => substr($name !== '' ? $name : $phone, 0, 191),
                'phone'        => $phone ?: null,
                'phone_key'    => $pkForRow ?: null,
                'email'        => $email ?: null,
                'email_key'    => $ek ?: null,
                'id_doc'       => isset($g['id_doc']) ? substr(trim((string) $g['id_doc']), 0, 80) : null,
                'nationality'  => isset($g['nationality']) ? substr(trim((string) $g['nationality']), 0, 80) : null,
                'contact_id'   => ((int) ($g['customer_id'] ?? 0)) > 0 ? (int) $g['customer_id'] : null,
                'created_by'   => (string) ($_SESSION['user_id'] ?? '') ?: null,
                'created_at'   => date('Y-m-d H:i:s'),
            ];
            $db->insert('stays_guests', $row);
            $id = (int) $db->id();
            return $id > 0 ? $id : 0;
        } catch (\Throwable $e) {
            // UNIQUE(org,phone_key) hit. Only resolve to the existing guest if the NAME also
            // matches (a true duplicate / concurrent race for the SAME person). If the name
            // differs, this is a shared-phone collision between two DIFFERENT people — create
            // a distinct guest with phone_key = NULL rather than returning the stranger
            // (audit #14: the old catch returned ANY phone_key hit, merging two people).
            if ($pk !== '' && (strpos((string) $e->getCode(), '23000') !== false || stripos($e->getMessage(), 'duplicate') !== false)) {
                try {
                    $cands = $db->select('stays_guests', ['id', 'display_name'], ['org_id' => $orgId, 'phone_key' => $pk, 'LIMIT' => 20]) ?: [];
                    foreach ($cands as $c) {
                        $cKey = function_exists('guestcrm_name_key') ? guestcrm_name_key($c['display_name'] ?? '') : strtolower((string) ($c['display_name'] ?? ''));
                        if ($nameKey === '' || $cKey === $nameKey) { return (int) $c['id']; } // true match
                    }
                    // No name match → different person sharing the number. Create with NULL key.
                    if ($name !== '') {
                        $db->insert('stays_guests', [
                            'org_id' => $orgId, 'display_name' => substr($name, 0, 191),
                            'phone' => $phone ?: null, 'phone_key' => null,
                            'email' => $email ?: null, 'email_key' => $ek ?: null,
                            'id_doc' => isset($g['id_doc']) ? substr(trim((string) $g['id_doc']), 0, 80) : null,
                            'nationality' => isset($g['nationality']) ? substr(trim((string) $g['nationality']), 0, 80) : null,
                            'contact_id' => ((int) ($g['customer_id'] ?? 0)) > 0 ? (int) $g['customer_id'] : null,
                            'created_by' => (string) ($_SESSION['user_id'] ?? '') ?: null,
                            'created_at' => date('Y-m-d H:i:s'),
                        ]);
                        $nid = (int) $db->id();
                        if ($nid > 0) { return $nid; }
                    }
                }
                catch (\Throwable $e2) { error_log('guests_resolve dup: ' . $e2->getMessage()); }
            }
            error_log('guests_resolve_for_booking: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('guests_backfill_from_bookings')) {
    /**
     * ONE-TIME, IDEMPOTENT: materialise a stored guest (stays_guests) for every existing
     * booking across the owner's stays, and link bookings.guest_id. This is how the
     * pre-existing book becomes first-class guest records (incl. the no-email walk-ins the
     * derived guest_list never showed). Reuses guests_resolve_for_booking so backfilled and
     * new guests dedupe identically (phone-first). Only touches bookings with NULL guest_id;
     * capped per call; non-fatal. Returns ['linked'=>int,'scanned'=>int].
     */
    function guests_backfill_from_bookings($db, int $orgId, array $stayIds, int $limit = 1000): array
    {
        $out = ['linked' => 0, 'scanned' => 0];
        if ($orgId <= 0 || empty($stayIds) || !function_exists('guests_resolve_for_booking')) { return $out; }
        $set = array_fill_keys(array_map('intval', $stayIds), true);
        $likeValues = [];
        foreach ($stayIds as $sid) { $likeValues[] = '%"hotel_id":' . (int) $sid . '%'; }
        try {
            $rows = $db->select('bookings',
                ['id', 'first_name', 'last_name', 'email', 'phone', 'customer_id', 'booking_data'],
                ['module_type' => ['stays', 'hotels'], 'guest_id' => null,
                 'booking_data[~]' => $likeValues, 'ORDER' => ['id' => 'ASC'], 'LIMIT' => $limit]) ?: [];
        } catch (\Throwable $e) { error_log('guests_backfill select: ' . $e->getMessage()); return $out; }
        foreach ($rows as $r) {
            $out['scanned']++;
            $bd = json_decode((string) ($r['booking_data'] ?? ''), true);
            $hid = is_array($bd) ? (int) ($bd['hotel_id'] ?? 0) : 0;
            if ($hid <= 0 || empty($set[$hid])) { continue; } // verify ownership (not just LIKE)
            try {
                $gid = (int) guests_resolve_for_booking($db, $orgId, [
                    'first_name' => (string) ($r['first_name'] ?? ''), 'last_name' => (string) ($r['last_name'] ?? ''),
                    'email' => (string) ($r['email'] ?? ''), 'phone' => (string) ($r['phone'] ?? ''),
                    'customer_id' => (int) ($r['customer_id'] ?? 0),
                ]);
                if ($gid > 0) {
                    $db->update('bookings', ['guest_id' => $gid], ['id' => (int) $r['id'], 'guest_id' => null]);
                    $out['linked']++;
                }
            } catch (\Throwable $e) { error_log('guests_backfill row ' . (int) $r['id'] . ': ' . $e->getMessage()); }
        }
        return $out;
    }
}

if (!function_exists('guests_stored_list')) {
    /**
     * The STORED Customer-facing guest list — reads stays_guests (the first-class record),
     * enriched with booking stats (stays/last-seen) joined by guest_id. This is the source
     * of truth for "who are my guests" (incl. no-email walk-ins). $opts: 'q' (name/phone/
     * email), 'limit'. Org-scoped. Phone search matches phone_key (last-9 digits).
     */
    function guests_stored_list($db, int $orgId, array $opts = []): array
    {
        if ($orgId <= 0) { return []; }
        $q = trim((string) ($opts['q'] ?? ''));
        $limit = max(1, min(1000, (int) ($opts['limit'] ?? 300)));
        $where = ['org_id' => $orgId];
        if ($q !== '') {
            $digits = preg_replace('/\D+/', '', $q);
            if (strlen($digits) >= 4 && strlen($digits) >= (strlen($q) - 2)) {
                $where['phone_key[~]'] = $digits; // phone search
            } else {
                $where['OR'] = ['display_name[~]' => $q, 'email[~]' => $q];
            }
        }
        $where['ORDER'] = ['updated_at' => 'DESC', 'id' => 'DESC'];
        $where['LIMIT'] = $limit;
        try {
            $rows = $db->select('stays_guests',
                ['id', 'display_name', 'phone', 'email', 'id_doc', 'nationality', 'contact_id', 'created_at'], $where) ?: [];
        } catch (\Throwable $e) { error_log('guests_stored_list: ' . $e->getMessage()); return []; }
        // Cheap per-guest booking stats (count + last-seen) by guest_id.
        foreach ($rows as &$g) {
            try {
                $g['stays'] = (int) $db->count('bookings', ['guest_id' => (int) $g['id'], 'booking_status[!]' => 'cancelled']);
                $last = $db->get('bookings', ['created_at'], ['guest_id' => (int) $g['id'], 'ORDER' => ['id' => 'DESC']]);
                $g['last_seen'] = $last ? (string) $last['created_at'] : '';
            } catch (\Throwable $e) { $g['stays'] = 0; $g['last_seen'] = ''; }
        }
        unset($g);
        return $rows;
    }
}

if (!function_exists('guest_token')) {
    /** URL-safe token for an email (so raw emails aren't in URLs). Reversible via
     *  guest_token_decode. Not secret — scoping still enforces ownership. */
    function guest_token(string $email): string { return rtrim(strtr(base64_encode($email), '+/', '-_'), '='); }
}
if (!function_exists('guest_token_decode')) {
    function guest_token_decode(string $token): string
    {
        $b64 = strtr($token, '-_', '+/');
        $pad = strlen($b64) % 4; if ($pad) { $b64 .= str_repeat('=', 4 - $pad); }
        $out = base64_decode($b64, true);
        return is_string($out) ? $out : '';
    }
}

if (!function_exists('guest_scan_bookings')) {
    /** Own-inventory bookings for the owner's properties, decoded + verified, newest
     *  first. Each row carries ['_hotel_id','_checkin','_checkout','_email']. Bounded. */
    function guest_scan_bookings($db, array $stayIds, int $limit = 5000): array
    {
        if (empty($stayIds)) { return []; }
        $set = array_fill_keys(array_map('intval', $stayIds), true);
        $likeValues = [];
        foreach ($stayIds as $sid) { $likeValues[] = '%"hotel_id":' . (int) $sid . '%'; } // wrap % ourselves: the _ in hotel_id defeats Medoo auto-wrap (Medoo.php:1163)
        $out = [];
        try {
            $rows = $db->select('bookings',
                ['invoice_id', 'first_name', 'last_name', 'email', 'phone', 'booking_status',
                 'payment_status', 'price_markup', 'currency_markup', 'booking_data', 'created_at'],
                ['module_type' => ['stays', 'hotels'], 'booking_data[~]' => $likeValues,
                 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
            foreach ($rows as $r) {
                $bd = json_decode((string) ($r['booking_data'] ?? ''), true);
                $hid = is_array($bd) ? (int) ($bd['hotel_id'] ?? 0) : 0;
                if ($hid <= 0 || empty($set[$hid])) { continue; } // verify
                $r['_hotel_id'] = $hid;
                $r['_checkin']  = is_array($bd) ? (string) ($bd['checkin'] ?? '') : '';
                $r['_checkout'] = is_array($bd) ? (string) ($bd['checkout'] ?? '') : '';
                $r['_email']    = guest_email_key($r['email'] ?? '');
                $out[] = $r;
            }
        } catch (\Throwable $e) { error_log('guest_scan_bookings: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('guest_list')) {
    /**
     * Aggregate guests from the owner's bookings. Returns a list of profiles sorted by
     * last-stay desc:
     *   ['email','name','phone','stays','cancelled','total_spent','currency',
     *    'first_seen','last_seen','properties'=>[hid,...], 'token'].
     * total_spent counts PAID, non-cancelled bookings only. $search filters by name/email.
     */
    function guest_list($db, array $stayIds, string $search = ''): array
    {
        $rows = guest_scan_bookings($db, $stayIds);
        $search = strtolower(trim($search));
        $by = [];
        foreach ($rows as $r) {
            $email = $r['_email'];
            if ($email === '') { continue; } // can't key a guest without an email
            if (!isset($by[$email])) {
                $by[$email] = [
                    'email' => $email, 'name' => trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? '')),
                    'phone' => (string) ($r['phone'] ?? ''), 'stays' => 0, 'cancelled' => 0,
                    'total_spent' => 0.0, 'currency' => strtoupper((string) ($r['currency_markup'] ?? '')) ?: 'USD',
                    'first_seen' => (string) ($r['created_at'] ?? ''), 'last_seen' => (string) ($r['created_at'] ?? ''),
                    'properties' => [],
                ];
            }
            $g = &$by[$email];
            $status = strtolower((string) ($r['booking_status'] ?? ''));
            if ($status === 'cancelled') { $g['cancelled']++; }
            else {
                $g['stays']++;
                if (strtolower((string) ($r['payment_status'] ?? '')) === 'paid') {
                    $g['total_spent'] += (float) ($r['price_markup'] ?? 0);
                }
            }
            $g['properties'][(int) $r['_hotel_id']] = true;
            $c = (string) ($r['created_at'] ?? '');
            if ($c !== '' && $c > $g['last_seen']) { $g['last_seen'] = $c; }
            if ($c !== '' && ($g['first_seen'] === '' || $c < $g['first_seen'])) { $g['first_seen'] = $c; }
            if (empty($g['phone']) && !empty($r['phone'])) { $g['phone'] = (string) $r['phone']; }
            unset($g);
        }
        $list = [];
        foreach ($by as $email => $g) {
            if ($search !== '' && strpos(strtolower($g['name'] . ' ' . $email), $search) === false) { continue; }
            $g['total_spent'] = round($g['total_spent'], 2);
            $g['properties'] = array_keys($g['properties']);
            $g['token'] = guest_token($email);
            $list[] = $g;
        }
        usort($list, fn($a, $b) => strcmp($b['last_seen'], $a['last_seen']));
        return $list;
    }
}

if (!function_exists('guest_history')) {
    /** All stays for one guest email across the owner's properties (newest first),
     *  + the aggregated profile. Returns ['profile'=>?array,'stays'=>[...]] (profile
     *  null if the guest has no bookings in scope). */
    function guest_history($db, array $stayIds, string $email): array
    {
        $email = guest_email_key($email);
        if ($email === '') { return ['profile' => null, 'stays' => []]; }
        $rows = guest_scan_bookings($db, $stayIds);
        $stays = [];
        foreach ($rows as $r) { if ($r['_email'] === $email) { $stays[] = $r; } }
        if (empty($stays)) { return ['profile' => null, 'stays' => []]; }
        // Reuse guest_list's aggregation for the single profile (filter to this email).
        $profile = null;
        foreach (guest_list($db, $stayIds) as $g) { if ($g['email'] === $email) { $profile = $g; break; } }
        return ['profile' => $profile, 'stays' => $stays];
    }
}

if (!function_exists('guest_profile_meta')) {
    /** The owner-added note/VIP/tags for (org, email), or defaults. Read-only. */
    function guest_profile_meta($db, int $orgId, string $email): array
    {
        $out = ['vip' => 0, 'note' => '', 'tags' => '', 'preferences' => ''];
        $email = guest_email_key($email);
        if ($orgId <= 0 || $email === '') { return $out; }
        try {
            $row = $db->get('stays_guest_profiles', ['vip', 'note', 'tags', 'preferences'], ['org_id' => $orgId, 'email' => $email]);
            if ($row) { $out = ['vip' => (int) ($row['vip'] ?? 0), 'note' => (string) ($row['note'] ?? ''), 'tags' => (string) ($row['tags'] ?? ''), 'preferences' => (string) ($row['preferences'] ?? '')]; }
        } catch (\Throwable $e) { error_log('guest_profile_meta: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('guest_profile_save')) {
    /** Upsert the owner-added note/VIP/tags for (org, email). The ONLY writable path
     *  in the guest CRM. Returns bool. */
    function guest_profile_save($db, int $orgId, string $email, array $data): bool
    {
        $email = guest_email_key($email);
        if ($orgId <= 0 || $email === '') { return false; }
        try {
            $fields = [
                'vip'         => !empty($data['vip']) ? 1 : 0,
                'note'        => substr(trim((string) ($data['note'] ?? '')), 0, 2000),
                'tags'        => substr(trim((string) ($data['tags'] ?? '')), 0, 191),
                'preferences' => substr(trim((string) ($data['preferences'] ?? '')), 0, 500) ?: null,
                'updated_at'  => date('Y-m-d H:i:s'),
            ];
            if ($db->has('stays_guest_profiles', ['org_id' => $orgId, 'email' => $email])) {
                $db->update('stays_guest_profiles', $fields, ['org_id' => $orgId, 'email' => $email]);
            } else {
                $db->insert('stays_guest_profiles', array_merge($fields, [
                    'org_id' => $orgId, 'email' => $email, 'created_at' => date('Y-m-d H:i:s'),
                ]));
            }
            return true;
        } catch (\Throwable $e) {
            error_log('guest_profile_save: ' . $e->getMessage());
            return false;
        }
    }
}

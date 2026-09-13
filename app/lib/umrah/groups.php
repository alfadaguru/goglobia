<?php
// ============================================================================
// UMRAH AGENT GROUPS (Phase C, Nusuk-style). docs/UMRAH-REBUILD-PLAN.md §C.
// Lifecycle: agent creates a DRAFT (no pilgrims needed), adds pilgrims + passports
// anytime, then SUBMITS for operator review (NO money). Operator accept/query/
// reject (each w/ comment); a queried group is editable + re-submittable. On
// ACCEPT the agent CONFIRMS — that is the ONLY point the wallet is debited and
// the booking is materialized (-> processing). Operator then sets per-pilgrim
// visa outcomes; non-approved pilgrims are auto-refunded to the wallet (unit +
// fee share) -> approved / partially_approved / rejected. All pricing is server-
// authoritative (agent B2B net when set); charge is atomic + idempotent.
// ============================================================================

if (!function_exists('umrah_group_ref')) {
    function umrah_group_ref(): string
    {
        try { $r = strtoupper(bin2hex(random_bytes(4))); }
        catch (\Throwable $e) { $r = strtoupper(substr(md5(uniqid('', true)), 0, 8)); }
        return 'GGG-' . $r;
    }
}

if (!function_exists('umrah_group_min_same_gender')) {
    /** Per-tier minimum same-gender count (0 = no minimum, e.g. private tier). */
    function umrah_group_min_same_gender($db, int $tierId): int
    {
        $t = $db->get('umrah_tiers', ['min_group_same_gender'], ['id' => $tierId]);
        return (int) ($t['min_group_same_gender'] ?? 0);
    }
}

if (!function_exists('umrah_group_actor')) {
    /** The agent user_id acting; '' if not an agent session. */
    function umrah_group_actor(): string
    {
        if (function_exists('umrah_is_agent') && umrah_is_agent()) {
            return (string) ($_SESSION['user_id'] ?? '');
        }
        return '';
    }
}

if (!function_exists('umrah_group_recount')) {
    /**
     * Recompute a group's pax_count + total_price from the live members and the
     * agent-net unit price of its departure-tier. Server-authoritative — never
     * trusts a client total. Returns the fresh totals (and persists them).
     */
    function umrah_group_recount($db, int $groupId): array
    {
        $g = $db->get('umrah_groups', '*', ['id' => $groupId]);
        if (!$g) { return ['ok' => false, 'message' => 'Group not found']; }
        $dt = $db->get('umrah_departure_tiers', '*', ['id' => (int) $g['departure_tier_id']]);
        if (!$dt) { return ['ok' => false, 'message' => 'Departure-tier not found']; }

        // Pax = max(declared counts total, actual member rows) so pricing covers
        // whichever is larger while the agent is still staging.
        $memberCount = (int) $db->count('umrah_group_members', ['group_id' => $groupId]);
        $declared = (int) $g['declared_male'] + (int) $g['declared_female'];
        $pax = max($declared, $memberCount);

        // Agent-net unit (agent session => B2B net when set, else B2C).
        $priced = umrah_price_resolve($db, $dt);
        $unit = (float) ($priced['unit'] ?? 0);
        $total = round($unit * $pax, 2);

        $db->update('umrah_groups', [
            'pax_count' => $pax, 'unit_net' => $unit, 'total_price' => $total,
            'currency' => $priced['currency'] ?? ($g['currency'] ?? 'NGN'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $groupId]);

        return ['ok' => true, 'pax' => $pax, 'unit' => $unit, 'total' => $total, 'members' => $memberCount, 'declared' => $declared];
    }
}

if (!function_exists('umrah_group_create')) {
    /**
     * Create a group (status 'draft') for an agent on a departure-tier, with
     * declared male/female counts (staged — no member data required yet).
     * @return array ['ok'=>true,'group_ref'=>..,'group_id'=>..] | ['ok'=>false,'message'=>..]
     */
    function umrah_group_create($db, array $in): array
    {
        $agent = umrah_group_actor();
        if ($agent === '') { return ['ok' => false, 'message' => 'Only agents can create groups']; }

        $dtId = (int) ($in['departure_tier_id'] ?? 0);
        $dt = $db->get('umrah_departure_tiers', '*', ['id' => $dtId]);
        if (!$dt || !umrah_departure_bookable($db, (int) $dt['departure_id'], $dt)) {
            return ['ok' => false, 'message' => 'This departure is not open for booking'];
        }
        $tier = $db->get('umrah_tiers', ['code'], ['id' => (int) $dt['tier_id']]);
        $male = max(0, (int) ($in['declared_male'] ?? 0));
        $female = max(0, (int) ($in['declared_female'] ?? 0));

        $ref = umrah_group_ref();
        $db->insert('umrah_groups', [
            'group_ref'         => $ref,
            'agent_user_id'     => $agent,
            'name'              => trim((string) ($in['name'] ?? '')) ?: null,
            'departure_id'      => (int) $dt['departure_id'],
            'departure_tier_id' => $dtId,
            'tier_code'         => $tier['code'] ?? null,
            'declared_male'     => $male,
            'declared_female'   => $female,
            'currency'          => (string) ($dt['currency'] ?? 'NGN'),
            'status'            => 'draft',
            'notes'             => trim((string) ($in['notes'] ?? '')) ?: null,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);
        $gid = (int) $db->id();
        umrah_group_recount($db, $gid);
        if (function_exists('umrah_audit')) { umrah_audit($db, 'umrah_group', $ref, 'created', null, ['dt' => $dtId, 'm' => $male, 'f' => $female], $agent, 'agent'); }
        return ['ok' => true, 'group_ref' => $ref, 'group_id' => $gid];
    }
}

if (!function_exists('umrah_group_owned')) {
    /** Fetch a group only if owned by the current agent (or admin). */
    function umrah_group_owned($db, int $groupId)
    {
        $g = $db->get('umrah_groups', '*', ['id' => $groupId]);
        if (!$g) { return null; }
        if (($_SESSION['user_role'] ?? '') === 'admin') { return $g; }
        $agent = umrah_group_actor();
        return ($agent !== '' && (string) $g['agent_user_id'] === $agent) ? $g : null;
    }
}

if (!function_exists('umrah_group_set_counts')) {
    /** Update declared male/female counts (only while editable). */
    function umrah_group_set_counts($db, int $groupId, int $male, int $female): array
    {
        $g = umrah_group_owned($db, $groupId);
        if (!$g) { return ['ok' => false, 'message' => 'Group not found']; }
        if (!in_array($g['status'], ['draft', 'pending', 'queried'], true)) {
            return ['ok' => false, 'message' => 'Counts can only change before submission'];
        }
        $db->update('umrah_groups', ['declared_male' => max(0, $male), 'declared_female' => max(0, $female), 'updated_at' => date('Y-m-d H:i:s')], ['id' => $groupId]);
        return umrah_group_recount($db, $groupId) + ['ok' => true];
    }
}

if (!function_exists('umrah_group_add_member')) {
    /**
     * Add or update a member (pilgrim) on a group. Staged: name/gender may be
     * partial early; full identity is completed before/at submit. Add/drop is
     * allowed while draft/pending/submitted/processing (Nusuk-style self-manage).
     */
    function umrah_group_add_member($db, int $groupId, array $m): array
    {
        $g = umrah_group_owned($db, $groupId);
        if (!$g) { return ['ok' => false, 'message' => 'Group not found']; }
        if (in_array($g['status'], ['cancelled'], true)) { return ['ok' => false, 'message' => 'Group is cancelled']; }
        // Once submitted/paid the group total is locked against a paid booking;
        // editing the roster here would re-run umrah_group_recount and rewrite
        // pax_count/total_price out from under the paid booking (audit H: total
        // desync). Staff adjust post-submit groups through the admin lifecycle.
        if (!in_array($g['status'], ['draft', 'pending', 'queried'], true)) {
            return ['ok' => false, 'message' => 'This group is already submitted; roster changes need staff.'];
        }

        $fields = [
            'title'           => $m['title'] ?? null,
            'first_name'      => trim((string) ($m['first_name'] ?? '')) ?: null,
            'middle_name'     => $m['middle_name'] ?? null,
            'last_name'       => trim((string) ($m['last_name'] ?? '')) ?: null,
            'gender'          => in_array(($m['gender'] ?? ''), ['male', 'female'], true) ? $m['gender'] : null,
            'dob'             => !empty($m['dob']) ? date('Y-m-d', strtotime((string) $m['dob'])) : null,
            'nationality'     => $m['nationality'] ?? null,
            'passport_number' => $m['passport_number'] ?? null,
            'passport_issue'  => !empty($m['passport_issue']) ? date('Y-m-d', strtotime((string) $m['passport_issue'])) : null,
            'passport_expiry' => !empty($m['passport_expiry']) ? date('Y-m-d', strtotime((string) $m['passport_expiry'])) : null,
            'mobile'          => $m['mobile'] ?? null,
            'email'           => $m['email'] ?? null,
            'room_group'      => $m['room_group'] ?? null,
        ];
        // doc_status from identity completeness (reuse the traveller helper).
        $fields['doc_status'] = function_exists('umrah_traveller_doc_status_value')
            ? umrah_traveller_doc_status_value($fields) : 'incomplete';

        $existingId = (int) ($m['id'] ?? 0);
        if ($existingId > 0) {
            $owned = $db->get('umrah_group_members', 'id', ['id' => $existingId, 'group_id' => $groupId]);
            if (!$owned) { return ['ok' => false, 'message' => 'Member not found in this group']; }
            $fields['updated_at'] = date('Y-m-d H:i:s');
            $db->update('umrah_group_members', $fields, ['id' => $existingId]);
            $mid = $existingId;
        } else {
            $fields['group_id'] = $groupId;
            $fields['created_at'] = date('Y-m-d H:i:s');
            $db->insert('umrah_group_members', $fields);
            $mid = (int) $db->id();
        }
        umrah_group_recount($db, $groupId);
        return ['ok' => true, 'member_id' => $mid];
    }
}

if (!function_exists('umrah_group_member_passport_upload')) {
    /**
     * Store an uploaded passport FILE for a group member (before the booking /
     * traveller exists). Secure: MIME + size + php-injection checked; stored
     * privately under uploads/umrah/groups/{groupId}/. Records the relative path
     * on umrah_group_members.passport_doc and marks doc readiness. On submit the
     * file is copied to the traveller's umrah_documents (for the visa).
     * @return array ['ok'=>bool,'path'=>?,'message'=>?]
     */
    function umrah_group_member_passport_upload($db, int $groupId, int $memberId, string $fileKey, int $maxBytes = 8388608): array
    {
        $g = umrah_group_owned($db, $groupId);
        if (!$g) { return ['ok' => false, 'message' => 'Group not found']; }
        if (!in_array($g['status'], ['draft', 'pending', 'queried'], true)) {
            return ['ok' => false, 'message' => 'This group is already submitted; document changes need staff.'];
        }
        $mem = $db->get('umrah_group_members', ['id', 'passport_doc'], ['id' => $memberId, 'group_id' => $groupId]);
        if (!$mem) { return ['ok' => false, 'message' => 'Member not found in this group']; }
        if (!isset($_FILES[$fileKey]) || ($_FILES[$fileKey]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'No file uploaded'];
        }
        $file = $_FILES[$fileKey];
        if (!function_exists('finfo_open')) { return ['ok' => false, 'message' => 'Server fileinfo missing']; }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
        if (!isset($allowed[$mime])) { return ['ok' => false, 'message' => 'Only JPG, PNG, WEBP or PDF allowed']; }
        if ((int) $file['size'] > $maxBytes) { return ['ok' => false, 'message' => 'File too large (max ' . (int) ($maxBytes / 1048576) . 'MB)']; }
        if ($mime !== 'application/pdf') {
            $head = (string) file_get_contents($file['tmp_name']);
            if (preg_match('/<\?(php|=)?\s/i', $head) || preg_match('/<script[^>]*language\s*=\s*["\']?php/i', $head)) {
                return ['ok' => false, 'message' => 'Invalid file content'];
            }
        }
        $uploadsBase = defined('uploads') ? rtrim(uploads, '/') : dirname(__DIR__, 3) . '/uploads';
        $dir = $uploadsBase . '/umrah/groups/' . $groupId;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return ['ok' => false, 'message' => 'Storage unavailable'];
        }
        $ht = $uploadsBase . '/umrah/groups/.htaccess';
        if (!file_exists($ht)) { @file_put_contents($ht, "Require all denied\nDeny from all\n"); }
        // Remove a previous file for this member if it lived in our folder.
        $prev = (string) ($mem['passport_doc'] ?? '');
        if ($prev !== '' && strpos($prev, 'uploads/umrah/groups/') !== false) {
            $pp = $uploadsBase . '/' . preg_replace('#^.*uploads/#', 'uploads/', $prev);
            $pp = $uploadsBase . '/umrah/groups/' . $groupId . '/' . basename($prev);
            if (is_file($pp)) { @unlink($pp); }
        }
        $rand = bin2hex(random_bytes(6));
        $fname = 'gm_' . $memberId . '_' . $rand . '.' . $allowed[$mime];
        $dest = $dir . '/' . $fname;
        if (!@move_uploaded_file($file['tmp_name'], $dest) && !@rename($file['tmp_name'], $dest)) {
            return ['ok' => false, 'message' => 'Could not store the file'];
        }
        $rel = 'uploads/umrah/groups/' . $groupId . '/' . $fname;
        $db->update('umrah_group_members', ['passport_doc' => $rel, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $memberId]);
        return ['ok' => true, 'path' => $rel];
    }
}

if (!function_exists('umrah_group_drop_member')) {
    function umrah_group_drop_member($db, int $groupId, int $memberId): array
    {
        $g = umrah_group_owned($db, $groupId);
        if (!$g) { return ['ok' => false, 'message' => 'Group not found']; }
        // Same lock as add_member: do not mutate a submitted/paid group's roster
        // (would desync the paid total via recount — audit H).
        if (!in_array($g['status'], ['draft', 'pending', 'queried'], true)) {
            return ['ok' => false, 'message' => 'This group is already submitted; roster changes need staff.'];
        }
        $db->delete('umrah_group_members', ['id' => $memberId, 'group_id' => $groupId]);
        umrah_group_recount($db, $groupId);
        return ['ok' => true];
    }
}

if (!function_exists('umrah_group_validate_rules')) {
    /**
     * Validate the tier group rule against the group's gender composition.
     * Uses declared counts when no members yet, else actual member genders.
     * Rule: a group needs at least `min_group_same_gender` of ONE gender
     * (Standard=3, VIP/VVIP/VVVIP=2, VVVVIP=0). Returns ['ok'=>bool,'message'=>..].
     */
    function umrah_group_validate_rules($db, array $g): array
    {
        $min = umrah_group_min_same_gender($db, (int) ($db->get('umrah_departure_tiers', ['tier_id'], ['id' => (int) $g['departure_tier_id']])['tier_id'] ?? 0));
        if ($min <= 0) { return ['ok' => true]; } // private tier — no minimum

        // Prefer actual member genders when members exist; else declared counts.
        $memberCount = (int) $db->count('umrah_group_members', ['group_id' => (int) $g['id']]);
        if ($memberCount > 0) {
            $male = (int) $db->count('umrah_group_members', ['group_id' => (int) $g['id'], 'gender' => 'male']);
            $female = (int) $db->count('umrah_group_members', ['group_id' => (int) $g['id'], 'gender' => 'female']);
        } else {
            $male = (int) $g['declared_male'];
            $female = (int) $g['declared_female'];
        }
        if ($male >= $min || $female >= $min) { return ['ok' => true]; }
        return ['ok' => false, 'message' => "This tier requires at least {$min} pilgrims of the same gender (e.g. {$min} males or {$min} females)."];
    }
}

if (!function_exists('umrah_group_members_ready')) {
    /**
     * Validate that a group has at least 1 pilgrim and EVERY member has full
     * identity details + an uploaded passport. Shared by submit and confirm.
     * @return array ['ok'=>bool,'message'=>?]
     */
    function umrah_group_members_ready($db, array $g): array
    {
        $groupId = (int) $g['id'];
        $members = $db->select('umrah_group_members', ['id', 'first_name', 'last_name', 'gender', 'dob', 'nationality', 'passport_number', 'passport_expiry', 'passport_doc'], ['group_id' => $groupId]) ?: [];
        if (count($members) < 1) { return ['ok' => false, 'message' => 'Add at least one pilgrim before submitting']; }
        foreach ($members as $mi => $m) {
            $label = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?: ('Pilgrim ' . ($mi + 1));
            foreach (['first_name', 'last_name', 'gender', 'dob', 'nationality', 'passport_number', 'passport_expiry'] as $req) {
                if (trim((string) ($m[$req] ?? '')) === '') {
                    return ['ok' => false, 'message' => $label . ': ' . str_replace('_', ' ', $req) . ' is required'];
                }
            }
            if (trim((string) ($m['passport_doc'] ?? '')) === '') {
                return ['ok' => false, 'message' => $label . ': passport upload is required'];
            }
        }
        return ['ok' => true, 'count' => count($members)];
    }
}

if (!function_exists('umrah_group_submit')) {
    /**
     * SUBMIT a group to the operator for review. NO MONEY MOVES HERE. The agent
     * builds a draft (optionally requesting custom dates), adds pilgrims with
     * passports, then submits. Requires >=1 pilgrim, the tier rule, and every
     * pilgrim complete + passport uploaded. Sets status -> 'submitted'. The
     * operator then accepts / queries / rejects; the wallet is debited only when
     * the agent CONFIRMS an accepted group (umrah_group_confirm).
     * @return array ['ok'=>true,'status'=>'submitted'] | ['ok'=>false,'message'=>..]
     */
    function umrah_group_submit($db, int $groupId, array $lead = []): array
    {
        $g = umrah_group_owned($db, $groupId);
        if (!$g) { return ['ok' => false, 'message' => 'Group not found']; }
        // Only a draft or a queried (sent-back) group may be submitted.
        if (!in_array($g['status'], ['draft', 'queried'], true)) {
            if (in_array($g['status'], ['submitted', 'accepted', 'processing', 'approved', 'partially_approved', 'completed'], true)) {
                return ['ok' => true, 'already' => true, 'status' => $g['status']];
            }
            if ($g['status'] === 'rejected') { return ['ok' => false, 'message' => 'This group was rejected. Create a new group or contact us.']; }
            if ($g['status'] === 'cancelled') { return ['ok' => false, 'message' => 'Group is cancelled']; }
            return ['ok' => false, 'message' => 'This group cannot be submitted from its current state.'];
        }

        // Re-price + validate the tier group rule + full pilgrim/passport data.
        umrah_group_recount($db, $groupId);
        $g = $db->get('umrah_groups', '*', ['id' => $groupId]);
        $rule = umrah_group_validate_rules($db, $g);
        if (empty($rule['ok'])) { return ['ok' => false, 'message' => $rule['message']]; }
        $ready = umrah_group_members_ready($db, $g);
        if (empty($ready['ok'])) { return $ready; }

        $db->update('umrah_groups', [
            'status' => 'submitted', 'submitted_at' => date('Y-m-d H:i:s'),
            'review_comment' => null, 'reviewed_by' => null, 'reviewed_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $groupId]);
        if (function_exists('umrah_audit')) { umrah_audit($db, 'umrah_group', $g['group_ref'], 'submitted', null, ['pax' => (int) $g['pax_count']], (string) $g['agent_user_id'], 'agent'); }
        return ['ok' => true, 'status' => 'submitted'];
    }
}

if (!function_exists('umrah_group_review')) {
    /**
     * OPERATOR review of a submitted group: accept | query | reject, each with an
     * optional comment. 'query' sends it back to the agent (editable again).
     * Admin only. No money moves here.
     */
    function umrah_group_review($db, int $groupId, string $decision, string $comment = ''): array
    {
        if (($_SESSION['user_role'] ?? '') !== 'admin') { return ['ok' => false, 'message' => 'Staff only']; }
        $g = $db->get('umrah_groups', '*', ['id' => $groupId]);
        if (!$g) { return ['ok' => false, 'message' => 'Group not found']; }
        if ($g['status'] !== 'submitted') { return ['ok' => false, 'message' => 'Only a submitted group can be reviewed (current: ' . $g['status'] . ')']; }
        $map = ['accept' => 'accepted', 'query' => 'queried', 'reject' => 'rejected'];
        if (!isset($map[$decision])) { return ['ok' => false, 'message' => 'Invalid decision']; }
        $db->update('umrah_groups', [
            'status' => $map[$decision],
            'review_comment' => trim($comment) !== '' ? trim($comment) : null,
            'reviewed_by' => (string) ($_SESSION['user_id'] ?? ''), 'reviewed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $groupId]);
        if (function_exists('umrah_audit')) { umrah_audit($db, 'umrah_group', $g['group_ref'], 'review_' . $decision, null, ['comment' => $comment], (string) ($_SESSION['user_id'] ?? ''), 'admin'); }
        return ['ok' => true, 'status' => $map[$decision]];
    }
}

if (!function_exists('umrah_group_confirm')) {
    /**
     * AGENT confirms an ACCEPTED group. THIS is the only point money moves: the
     * wallet is debited and the booking is materialized. Atomic CAS claim
     * (accepted -> processing) prevents a double-charge; on any failure the
     * charge is refunded and the group returns to 'accepted'.
     * @return array ['ok'=>true,'booking_ref'=>..,'invoice_id'=>..] | ['ok'=>false,..]
     */
    function umrah_group_confirm($db, int $groupId, array $lead = []): array
    {
        $g = umrah_group_owned($db, $groupId);
        if (!$g) { return ['ok' => false, 'message' => 'Group not found']; }
        if (in_array($g['status'], ['processing', 'confirmed', 'approved', 'partially_approved', 'completed'], true)) {
            return ['ok' => true, 'already' => true, 'invoice_id' => $g['invoice_id']];
        }
        if ($g['status'] !== 'accepted') {
            return ['ok' => false, 'message' => 'This group must be accepted by our team before you can confirm & pay.'];
        }

        // Re-price + re-validate everything at confirm time.
        umrah_group_recount($db, $groupId);
        $g = $db->get('umrah_groups', '*', ['id' => $groupId]);
        $rule = umrah_group_validate_rules($db, $g);
        if (empty($rule['ok'])) { return ['ok' => false, 'message' => $rule['message']]; }
        $ready = umrah_group_members_ready($db, $g);
        if (empty($ready['ok'])) { return $ready; }

        $dt = $db->get('umrah_departure_tiers', '*', ['id' => (int) $g['departure_tier_id']]);
        if (!$dt || !umrah_departure_bookable($db, (int) $dt['departure_id'], $dt)) {
            return ['ok' => false, 'message' => 'This departure is no longer open for booking'];
        }

        // ── CONCURRENCY GUARD (double-charge race) ──────────────────
        // Atomically CLAIM this group for submission with a compare-and-swap:
        // flip draft/pending -> processing only if it is still draft/pending.
        // If 0 rows change, another request already claimed it (or it advanced)
        // -> we MUST NOT charge again. This is the single serialization point;
        // every money movement below happens only for the request that won.
        $prevStatus = (string) $g['status'];
        $claim = $db->update('umrah_groups',
            ['status' => 'processing', 'updated_at' => date('Y-m-d H:i:s')],
            ['id' => $groupId, 'status' => [$prevStatus]]
        );
        if (!$claim || $claim->rowCount() < 1) {
            // Lost the race or status moved under us — report the live state.
            $live = $db->get('umrah_groups', ['status', 'invoice_id'], ['id' => $groupId]);
            if ($live && in_array($live['status'], ['processing', 'confirmed', 'approved', 'partially_approved', 'completed'], true)) {
                return ['ok' => true, 'already' => true, 'booking_ref' => null, 'invoice_id' => $live['invoice_id']];
            }
            return ['ok' => false, 'message' => 'This group is already being confirmed — please wait a moment.'];
        }
        // From here, on ANY failure we MUST release the claim back to $prevStatus.

        // ── CAPACITY GUARD (audit H: oversell) ───────────────────────────────
        // The customer path holds seats atomically; the group path charged the
        // wallet with NO capacity check, so an agent could submit more pilgrims
        // than remaining seats. Reserve an inventory hold for the whole group
        // BEFORE charging; if seats aren't available, release the claim + abort.
        $pax = (int) $g['pax_count'];
        if (function_exists('umrah_capacity_for')) {
            $cap = umrah_capacity_for($db, (int) $dt['id']);
            $remaining = (int) ($cap['remaining'] ?? 0);
            if ($remaining < $pax) {
                $db->update('umrah_groups', ['status' => $prevStatus, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $groupId]);
                return ['ok' => false, 'code' => 'insufficient_capacity', 'message' => 'Only ' . $remaining . ' seat(s) remain on this departure-tier; your group needs ' . $pax . '.', 'remaining' => $remaining];
            }
        }
        $departure = $db->get('umrah_departures', '*', ['id' => (int) $dt['departure_id']]);
        $template  = $departure ? $db->get('umrah_package_templates', '*', ['id' => (int) $departure['template_id']]) : null;

        $agent    = (string) $g['agent_user_id'];
        $total    = (float) $g['total_price'];
        $currency = (string) $g['currency'];
        $invoiceId = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $bookingRef = umrah_ref('GGU');
        $walletTxn  = 'WALLET-GROUP-' . $g['group_ref'];

        // Agent earning = (B2C − net) × pax (recomputed from live tier).
        $b2cUnit = function_exists('umrah_b2c_unit_price') ? umrah_b2c_unit_price($dt) : (float) $g['unit_net'];
        $marginUnit = $b2cUnit - (float) $g['unit_net'];
        $agentEarning = $marginUnit > 0 ? round($marginUnit * $pax, 2) : 0.0;

        // 1) CHARGE THE WALLET (only money movement). Atomic + idempotent on the
        //    invoice description (audit H3). Insufficient funds => abort, nothing
        //    materialized, group stays editable.
        if (!function_exists('agent_api_charge_wallet')) {
            $db->update('umrah_groups', ['status' => $prevStatus, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $groupId]);
            return ['ok' => false, 'message' => 'Wallet unavailable'];
        }
        $charge = agent_api_charge_wallet($db, $agent, 'umrah', $total, $invoiceId, (string) $currency);
        if (empty($charge['ok'])) {
            // Release the claim so the agent can top up and retry.
            $db->update('umrah_groups', ['status' => $prevStatus, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $groupId]);
            return ['ok' => false, 'code' => 'insufficient_funds', 'message' => $charge['message'] ?? 'Insufficient wallet balance', 'required' => $charge['required'] ?? $total, 'balance' => $charge['balance'] ?? null];
        }
        // The amount ACTUALLY debited (booking + service fee) — this, not $total,
        // is what a rollback must refund (audit H: under-refund).
        $chargedTotal = (float) ($charge['charged'] ?? $total);

        // 2) Materialize the agent-owned, PAID booking + members->travellers.
        $now = date('Y-m-d H:i:s');
        $snapshot = [
            'group_ref' => $g['group_ref'], 'unit_price' => (float) $g['unit_net'],
            'total_price' => $total, 'currency' => $currency, 'pax' => $pax,
            'tier_code' => $g['tier_code'],
            'departure' => $departure ? ['code' => $departure['code'], 'departure_date' => $departure['departure_date'], 'return_date' => $departure['return_date'], 'month_bucket' => $departure['month_bucket']] : null,
            'template' => $template ? ['code' => $template['code'], 'name' => $template['name']] : null,
            'agent_group' => true, 'snapshotted_at' => $now,
        ];
        $result = ['ok' => false, 'message' => 'Group submit failed'];
        try {
            $db->action(function ($db) use ($g, $groupId, $invoiceId, $bookingRef, $agent, $departure, $dt, $pax, $currency, $total, $agentEarning, $snapshot, $now, $walletTxn, &$result) {
                // FINAL capacity re-check INSIDE the transaction (audit H: oversell
                // across concurrent groups on the same departure-tier). The pre-
                // charge check serializes a single group; this catches two
                // different groups racing the last seats. Throwing here aborts the
                // transaction and triggers the outer refund + claim release.
                if (function_exists('umrah_capacity_for')) {
                    $capTx = umrah_capacity_for($db, (int) $dt['id']);
                    if ((int) ($capTx['remaining'] ?? 0) < $pax) {
                        throw new \RuntimeException('insufficient_capacity_at_commit');
                    }
                }
                $lead0 = $db->get('umrah_group_members', '*', ['group_id' => $groupId, 'ORDER' => ['id' => 'ASC']]);
                // bookings.adults is tinyint(4) (max 127); the authoritative pax
                // count lives in umrah_bookings.pax + the snapshot. Clamp so a
                // large agent group cannot overflow the legacy column (audit H).
                $adultsCol = min($pax, 127);
                $db->insert('bookings', [
                    'invoice_id' => $invoiceId, 'booking_status' => 'confirmed', 'payment_status' => 'paid',
                    'price_original' => $total, 'price_markup' => $total, 'agent_earning' => $agentEarning,
                    'currency_markup' => $currency, 'paid_at' => $now, 'transaction_id' => $walletTxn,
                    'payment_gateway' => 'Wallet',
                    'first_name' => $lead0['first_name'] ?? ($g['name'] ?? 'Group'), 'last_name' => $lead0['last_name'] ?? '',
                    'email' => $lead0['email'] ?? '', 'phone' => $lead0['mobile'] ?? '',
                    'adults' => $adultsCol, 'childs' => 0, 'infants' => '0',
                    'user_id' => $agent, 'module_type' => 'umrah', 'module' => 'umrah',
                    'booking_data' => json_encode(['umrah' => $snapshot], JSON_UNESCAPED_SLASHES),
                    'created_at' => $now, 'booking_date' => date('Y-m-d'),
                ]);
                $genericId = (int) $db->id();

                $db->insert('umrah_bookings', [
                    'booking_ref' => $bookingRef, 'invoice_id' => $invoiceId, 'user_id' => $agent,
                    'departure_id' => (int) $departure['id'], 'departure_tier_id' => (int) $dt['id'],
                    'pax' => $pax, 'currency' => $currency, 'total_price' => $total,
                    'amount_paid' => $total, 'balance' => 0, 'payment_plan_code' => 'PP-FULL',
                    'price_locked_at' => $now, 'booking_status' => 'confirmed', 'payment_status' => 'fully_paid',
                    'snapshot' => json_encode($snapshot, JSON_UNESCAPED_SLASHES), 'created_at' => $now,
                ]);
                $ubId = (int) $db->id();

                // Copy members -> umrah_booking_travellers (link back on the member).
                $members = $db->select('umrah_group_members', '*', ['group_id' => $groupId, 'ORDER' => ['id' => 'ASC']]) ?: [];
                foreach ($members as $mi => $mem) {
                    $db->insert('umrah_booking_travellers', [
                        'umrah_booking_id' => $ubId,
                        'title' => $mem['title'] ?? null,
                        'first_name' => $mem['first_name'] ?? '', 'middle_name' => $mem['middle_name'] ?? null,
                        'last_name' => $mem['last_name'] ?? '',
                        'gender' => $mem['gender'] ?? null, 'dob' => $mem['dob'] ?? null,
                        'nationality' => $mem['nationality'] ?? null,
                        'passport_number' => $mem['passport_number'] ?? null,
                        'passport_issue' => $mem['passport_issue'] ?? null,
                        'passport_expiry' => $mem['passport_expiry'] ?? null,
                        'room_group' => $mem['room_group'] ?? null,
                        'is_lead' => $mi === 0 ? 1 : 0,
                        'doc_status' => $mem['doc_status'] ?? 'not_started',
                        'created_at' => $now,
                    ]);
                    $tid = (int) $db->id();
                    $db->update('umrah_group_members', ['traveller_id' => $tid, 'updated_at' => $now], ['id' => (int) $mem['id']]);

                    // Carry the member's uploaded passport onto the traveller as a
                    // umrah_documents row so the visa flow finds it exactly where
                    // customer uploads land. The file already lives privately under
                    // uploads/umrah/groups/{groupId}/ — reference it in place.
                    $pdoc = trim((string) ($mem['passport_doc'] ?? ''));
                    if ($pdoc !== '') {
                        $ext = strtolower(pathinfo($pdoc, PATHINFO_EXTENSION));
                        $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
                        $db->insert('umrah_documents', [
                            'traveller_id'     => $tid,
                            'umrah_booking_id' => $ubId,
                            'doc_type'         => 'passport',
                            'file_path'        => $pdoc,
                            'original_name'    => basename($pdoc),
                            'mime'             => $mimeMap[$ext] ?? 'application/octet-stream',
                            'verify_status'    => 'pending',
                            'created_at'       => $now,
                        ]);
                    }
                }

                // Link + advance the group to PROCESSING (paid, materialized).
                $db->update('umrah_groups', [
                    'status' => 'processing', 'paid' => 1, 'invoice_id' => $invoiceId,
                    'umrah_booking_id' => $ubId, 'wallet_txn' => $walletTxn,
                    'confirmed_at' => $now, 'updated_at' => $now,
                ], ['id' => $groupId]);

                $result = ['ok' => true, 'booking_ref' => $bookingRef, 'invoice_id' => $invoiceId, 'umrah_booking_id' => $ubId, 'genericBookingId' => $genericId];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('umrah_group_submit materialize: ' . $e->getMessage());
            // The wallet was charged but materialize failed — refund the FULL
            // amount actually debited (booking + service fee), not just the
            // booking total (audit H: under-refund), and release the claim so the
            // group returns to its editable pre-submit state.
            try {
                $db->insert('credits', ['user_id' => $agent, 'type' => 'credit', 'credits' => round($chargedTotal, 2), 'currency' => $currency, 'description' => 'Group submit refund ' . $g['group_ref'] . ' (' . $invoiceId . ')', 'created_at' => date('Y-m-d H:i:s')]);
            } catch (\Throwable $e2) { error_log('umrah_group_submit refund failed: ' . $e2->getMessage()); }
            $db->update('umrah_groups', ['status' => $prevStatus, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $groupId]);
            return ['ok' => false, 'message' => 'Could not finalize the group; your wallet charge has been reversed. Please try again.'];
        }

        if (function_exists('umrah_audit')) { umrah_audit($db, 'umrah_group', $g['group_ref'], 'confirmed_paid', null, ['pax' => $pax, 'total' => $total], $agent, 'agent'); }
        return $result;
    }
}

if (!function_exists('umrah_group_set_visa')) {
    /**
     * OPERATOR sets each member's visa outcome, then FINALIZES: non-approved
     * pilgrims are auto-refunded to the agent wallet (their unit price + a
     * proportional share of the group service fee), the group total is reduced,
     * and the group status becomes approved / partially_approved / rejected(full
     * refund). Admin only. Idempotent per member (a refunded member is not
     * refunded twice). $outcomes = [member_id => 'approved'|'rejected'].
     */
    function umrah_group_set_visa($db, int $groupId, array $outcomes): array
    {
        if (($_SESSION['user_role'] ?? '') !== 'admin') { return ['ok' => false, 'message' => 'Staff only']; }
        $g = $db->get('umrah_groups', '*', ['id' => $groupId]);
        if (!$g) { return ['ok' => false, 'message' => 'Group not found']; }
        if (!in_array($g['status'], ['processing', 'partially_approved', 'approved'], true)) {
            return ['ok' => false, 'message' => 'Visa outcomes can only be set once the group is processing/paid.'];
        }
        $agent = (string) $g['agent_user_id'];
        $currency = (string) $g['currency'];
        $unit = (float) $g['unit_net'];

        // Proportional fee share per pilgrim = total fee charged / pax.
        $pax = max(1, (int) $g['pax_count']);
        $feeCharged = 0.0;
        try {
            $feeRow = $db->get('credits', 'credits', ['user_id' => $agent, 'type' => 'debit', 'description[~]' => 'service fee ' . $g['invoice_id']]);
            $feeCharged = (float) ($feeRow ?? 0);
        } catch (\Throwable $e) { /* no fee */ }
        $feePerPax = round($feeCharged / $pax, 2);
        $refundPerPax = round($unit + $feePerPax, 2);

        $members = $db->select('umrah_group_members', ['id', 'first_name', 'last_name', 'visa_status', 'traveller_id'], ['group_id' => $groupId]) ?: [];
        $now = date('Y-m-d H:i:s');
        $refundedNow = 0.0; $approved = 0; $rejected = 0;

        foreach ($members as $m) {
            $mid = (int) $m['id'];
            $want = ($outcomes[$mid] ?? null);
            if (!in_array($want, ['approved', 'rejected'], true)) {
                // Not decided this round — keep whatever it is; count current state.
                if ($m['visa_status'] === 'approved') { $approved++; }
                if (in_array($m['visa_status'], ['rejected', 'refunded'], true)) { $rejected++; }
                continue;
            }
            if ($want === 'approved') {
                if ($m['visa_status'] !== 'approved') {
                    $db->update('umrah_group_members', ['visa_status' => 'approved', 'updated_at' => $now], ['id' => $mid]);
                    if ((int) $m['traveller_id']) { $db->update('umrah_booking_travellers', ['visa_status' => 'approved'], ['id' => (int) $m['traveller_id']]); }
                }
                $approved++;
            } else { // rejected -> auto-refund once
                $alreadyRefunded = in_array($m['visa_status'], ['refunded'], true);
                if (!$alreadyRefunded) {
                    $label = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) ?: ('Pilgrim #' . $mid);
                    try {
                        $db->insert('credits', ['user_id' => $agent, 'type' => 'credit', 'credits' => $refundPerPax, 'currency' => $currency, 'description' => 'Umrah visa refund ' . $g['group_ref'] . ' — ' . $label . ' (' . $g['invoice_id'] . ')', 'created_at' => $now]);
                        $db->update('umrah_group_members', ['visa_status' => 'refunded', 'refund_amount' => $refundPerPax, 'refunded_at' => $now, 'updated_at' => $now], ['id' => $mid]);
                        if ((int) $m['traveller_id']) { $db->update('umrah_booking_travellers', ['visa_status' => 'rejected'], ['id' => (int) $m['traveller_id']]); }
                        $refundedNow += $refundPerPax;
                    } catch (\Throwable $e) { error_log('umrah_group_set_visa refund: ' . $e->getMessage()); }
                }
                $rejected++;
            }
        }

        // Group status from the tally.
        $newStatus = $g['status'];
        if ($approved > 0 && $rejected === 0) { $newStatus = 'approved'; }
        elseif ($approved > 0 && $rejected > 0) { $newStatus = 'partially_approved'; }
        elseif ($approved === 0 && $rejected > 0) { $newStatus = 'rejected'; }
        $newRefundTotal = round((float) $g['refunded_total'] + $refundedNow, 2);
        $db->update('umrah_groups', [
            'status' => $newStatus,
            'refunded_total' => $newRefundTotal,
            'visa_status' => $rejected === 0 ? 'all' : ($approved === 0 ? 'rejected' : 'partial'),
            'updated_at' => $now,
        ], ['id' => $groupId]);
        if (function_exists('umrah_audit')) { umrah_audit($db, 'umrah_group', $g['group_ref'], 'visa_' . $newStatus, null, ['approved' => $approved, 'rejected' => $rejected, 'refunded' => $refundedNow], (string) ($_SESSION['user_id'] ?? ''), 'admin'); }
        return ['ok' => true, 'status' => $newStatus, 'approved' => $approved, 'rejected' => $rejected, 'refunded' => $refundedNow];
    }
}

if (!function_exists('umrah_group_set_status')) {
    /**
     * Admin/agent lifecycle transition. Agents may cancel a draft/pending group.
     * Admin may drive any transition incl. visa_status (none/partial/all/rejected)
     * and processing/confirmed. Returns ['ok'=>bool,'message'=>..].
     */
    function umrah_group_set_status($db, int $groupId, string $status, ?string $visaStatus = null): array
    {
        $isAdmin = (($_SESSION['user_role'] ?? '') === 'admin');
        $g = umrah_group_owned($db, $groupId);
        if (!$g) { return ['ok' => false, 'message' => 'Group not found']; }

        $valid = ['draft', 'pending', 'paid', 'submitted', 'processing', 'confirmed', 'cancelled'];
        $u = ['updated_at' => date('Y-m-d H:i:s')];
        if ($status !== '') {
            if (!in_array($status, $valid, true)) { return ['ok' => false, 'message' => 'Invalid status']; }
            // Agents (non-admin) may only cancel a not-yet-paid group or move
            // draft<->pending. Everything post-payment is admin-driven.
            if (!$isAdmin) {
                if (!($status === 'cancelled' && in_array($g['status'], ['draft', 'pending', 'queried'], true))
                    && !(in_array($status, ['draft', 'pending'], true) && in_array($g['status'], ['draft', 'pending', 'queried'], true))) {
                    return ['ok' => false, 'message' => 'This status change requires staff'];
                }
            }
            $u['status'] = $status;
        }
        if ($visaStatus !== null && $visaStatus !== '') {
            if (!$isAdmin) { return ['ok' => false, 'message' => 'Visa status is staff-managed']; }
            if (!in_array($visaStatus, ['none', 'partial', 'all', 'rejected'], true)) { return ['ok' => false, 'message' => 'Invalid visa status']; }
            $u['visa_status'] = $visaStatus;
        }
        $db->update('umrah_groups', $u, ['id' => $groupId]);
        if (function_exists('umrah_audit')) { umrah_audit($db, 'umrah_group', (string) $g['group_ref'], 'status_' . ($status ?: $visaStatus), null, $u, $_SESSION['user_id'] ?? null, $_SESSION['user_role'] ?? null); }
        return ['ok' => true];
    }
}

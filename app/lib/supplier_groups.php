<?php
// FILE: app/lib/supplier_groups.php
// GROUP & BLOCK RESERVATIONS (Phase 1 inc S38; catalogue module 08). Stays depth.
//
// A GROUP is a profile (company/organiser, arrival/departure, status). A BLOCK holds
// N rooms of a type for a date range against the SAME pooled stays_inventory via
// stays_hold_create (so a block can't oversell vs marketplace/walk-in/direct). A
// ROOMING LIST names the guests in the block. A group MASTER FOLIO routes charges
// (room/F&B/extras) + payments, posting a balanced GL entry on close.
//
// Block holds are long-lived: we pass a far-future hold window keyed to the block's
// cutoff, and the hold ref = "GRP:{blockId}" so the availability sweep + release
// target it precisely. Releasing a block frees the rooms immediately.
//
// Scoping enforced by the caller routes (supplier_can('reservations', …, $stayId)).
// Amounts server-tallied. Non-fatal throughout.

if (!function_exists('group_create')) {
    /** Create a group profile (enquiry) for a property. Returns id (0 on failure). */
    function group_create($db, int $stayId, array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($stayId <= 0 || $name === '') { return 0; }
        $arr = (string) ($d['arrival'] ?? '');
        $dep = (string) ($d['departure'] ?? '');
        try {
            $orgId = function_exists('supplier_property_org') ? supplier_property_org($db, $stayId) : 0;
            $ref = 'GRP-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            $db->insert('stays_groups', [
                'org_id'       => $orgId > 0 ? $orgId : null,
                'stay_id'      => $stayId,
                'reference'    => $ref,
                'name'         => substr($name, 0, 191),
                'company'      => trim((string) ($d['company'] ?? '')) ?: null,
                'contact_name' => trim((string) ($d['contact_name'] ?? '')) ?: null,
                'contact_email'=> trim((string) ($d['contact_email'] ?? '')) ?: null,
                'arrival'      => preg_match('/^\d{4}-\d{2}-\d{2}$/', $arr) ? $arr : null,
                'departure'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $dep) ? $dep : null,
                'currency'     => strtoupper(substr((string) ($d['currency'] ?? 'USD'), 0, 3)),
                'status'       => 'enquiry',
                'created_by'   => (string) ($_SESSION['user_id'] ?? ''),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
            return (int) $db->id();
        } catch (\Throwable $e) { error_log('group_create: ' . $e->getMessage()); return 0; }
    }
}

if (!function_exists('group_block_add')) {
    /**
     * Add a room block to a group: HOLD $qty of (room,option) across [from,to) via
     * stays_hold_create (the shared no-oversell gate), with a long window. On a
     * successful hold, records a stays_group_blocks row carrying the hold ref. If the
     * hold fails (not enough rooms), returns the failure — nothing is recorded.
     * Returns ['ok','block_id','message'].
     */
    function group_block_add($db, int $groupId, int $stayId, int $roomId, int $optionId, string $from, string $to, int $qty): array
    {
        if ($groupId <= 0 || $stayId <= 0 || $roomId <= 0 || $optionId <= 0) { return ['ok' => false, 'message' => 'Bad input']; }
        $qty = max(1, (int) $qty);
        $g = $db->get('stays_groups', ['id', 'status'], ['id' => $groupId, 'stay_id' => $stayId]);
        if (!$g || in_array($g['status'], ['cancelled'], true)) { return ['ok' => false, 'message' => 'Group not open']; }
        if (!function_exists('stays_hold_create')) { return ['ok' => false, 'message' => 'Inventory engine unavailable']; }

        // Long hold window (keeps the rooms blocked until released/cutoff). ~1 year.
        $ref = 'GRP:' . $groupId . ':' . $roomId . ':' . $optionId . ':' . bin2hex(random_bytes(3));
        $hold = stays_hold_create($db, $stayId, $roomId, $optionId, $from, $to, $qty, $ref, 525600);
        if (empty($hold['ok'])) { return ['ok' => false, 'message' => $hold['message'] ?? 'Not enough rooms available for the block.']; }

        try {
            $db->insert('stays_group_blocks', [
                'group_id'   => $groupId, 'stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $optionId,
                'date_from'  => $from, 'date_to' => $to, 'qty' => $qty,
                'hold_id'    => (int) ($hold['hold_id'] ?? 0), 'hold_ref' => $ref,
                'state'      => 'held', 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return ['ok' => true, 'block_id' => (int) $db->id(), 'message' => 'Block held (' . $qty . ' room(s)).'];
        } catch (\Throwable $e) {
            // Roll back the hold if the row insert fails.
            if (function_exists('stays_hold_release')) { stays_hold_release($db, $ref); }
            error_log('group_block_add: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not record the block.'];
        }
    }
}

if (!function_exists('group_block_release')) {
    /** Release a block's hold (frees the rooms). State-guarded; idempotent. */
    function group_block_release($db, int $blockId, int $stayId): bool
    {
        try {
            $b = $db->get('stays_group_blocks', ['id', 'hold_ref', 'state'], ['id' => $blockId, 'stay_id' => $stayId]);
            if (!$b || $b['state'] !== 'held') { return false; }
            if (function_exists('stays_hold_release')) { stays_hold_release($db, (string) $b['hold_ref']); }
            $db->update('stays_group_blocks', ['state' => 'released', 'updated_at' => date('Y-m-d H:i:s')],
                ['id' => $blockId, 'state' => 'held']);
            return true;
        } catch (\Throwable $e) { error_log('group_block_release: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('group_member_add')) {
    /** Add a guest to the rooming list of a block. Returns bool. */
    function group_member_add($db, int $groupId, int $stayId, int $blockId, string $guestName, array $opts = []): bool
    {
        $guestName = trim($guestName);
        if ($groupId <= 0 || $blockId <= 0 || $guestName === '') { return false; }
        try {
            $blk = $db->get('stays_group_blocks', ['id'], ['id' => $blockId, 'group_id' => $groupId, 'stay_id' => $stayId]);
            if (!$blk) { return false; }
            $db->insert('stays_group_members', [
                'group_id'  => $groupId, 'block_id' => $blockId,
                'guest_name'=> substr($guestName, 0, 120),
                'email'     => trim((string) ($opts['email'] ?? '')) ?: null,
                'created_at'=> date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) { error_log('group_member_add: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('group_rooming_import')) {
    /**
     * BULK ROOMING LIST IMPORT (PMS completion #08). Add many guests to a block at once
     * from a newline/comma-separated list. Each line is "Name" or "Name, email". Skips
     * blanks; caps at 500 per import. Returns ['ok','added','skipped','message'].
     */
    function group_rooming_import($db, int $groupId, int $stayId, int $blockId, string $raw): array
    {
        if ($groupId <= 0 || $blockId <= 0) { return ['ok' => false, 'message' => 'Bad input']; }
        $blk = $db->get('stays_group_blocks', ['id'], ['id' => $blockId, 'group_id' => $groupId, 'stay_id' => $stayId]);
        if (!$blk) { return ['ok' => false, 'message' => 'Block not found for this group.']; }

        $lines = preg_split('/\r\n|\r|\n/', (string) $raw) ?: [];
        $added = 0; $skipped = 0; $count = 0;
        try {
            foreach ($lines as $line) {
                if ($count >= 500) { break; }
                $line = trim($line);
                if ($line === '') { continue; }
                $count++;
                // "Name, email" or "Name <email>" or just "Name".
                $email = '';
                if (strpos($line, ',') !== false) {
                    $parts = array_map('trim', explode(',', $line, 2));
                    $name = $parts[0]; $email = $parts[1] ?? '';
                } elseif (preg_match('/^(.*)<([^>]+)>\s*$/', $line, $m)) {
                    $name = trim($m[1]); $email = trim($m[2]);
                } else {
                    $name = $line;
                }
                if ($name === '') { $skipped++; continue; }
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $email = ''; }
                $ok = group_member_add($db, $groupId, $stayId, $blockId, $name, ['email' => $email]);
                if ($ok) { $added++; } else { $skipped++; }
            }
        } catch (\Throwable $e) {
            error_log('group_rooming_import: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Import failed.'];
        }
        return ['ok' => true, 'added' => $added, 'skipped' => $skipped,
                'message' => $added . ' guest(s) added' . ($skipped ? ', ' . $skipped . ' skipped' : '') . '.'];
    }
}

if (!function_exists('group_pickup_summary')) {
    /**
     * PICKUP REPORT (PMS completion #08). For each block: rooms held (qty) vs rooming
     * list filled (members), and the pickup gap. Plus group totals. Read-only.
     * Returns ['blocks'=>[['block_id','room_id','option_id','qty','members','gap']...],
     *          'held_total','members_total','pickup_pct'].
     */
    function group_pickup_summary($db, int $groupId, int $stayId): array
    {
        $out = ['blocks' => [], 'held_total' => 0, 'members_total' => 0, 'pickup_pct' => 0.0];
        try {
            $blocks = $db->select('stays_group_blocks',
                ['id', 'room_id', 'option_id', 'qty', 'state'],
                ['group_id' => $groupId, 'stay_id' => $stayId]) ?: [];
            foreach ($blocks as $b) {
                $bid = (int) $b['id'];
                $members = (int) $db->count('stays_group_members', ['group_id' => $groupId, 'block_id' => $bid]);
                $qty = (int) $b['qty'];
                $active = in_array((string) ($b['state'] ?? ''), ['held', 'released'], true);
                if ((string) ($b['state'] ?? '') !== 'released') { $out['held_total'] += $qty; }
                $out['members_total'] += $members;
                $out['blocks'][] = [
                    'block_id' => $bid, 'room_id' => (int) $b['room_id'], 'option_id' => (int) $b['option_id'],
                    'qty' => $qty, 'state' => (string) ($b['state'] ?? ''), 'members' => $members,
                    'gap' => max(0, $qty - $members),
                ];
            }
            $out['pickup_pct'] = $out['held_total'] > 0
                ? round($out['members_total'] / $out['held_total'] * 100, 1) : 0.0;
        } catch (\Throwable $e) { error_log('group_pickup_summary: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('group_folio_add')) {
    /** Add a master-folio line to an open group (room/charge/extra/payment/refund). */
    function group_folio_add($db, int $groupId, int $stayId, string $type, float $amount, string $desc = ''): bool
    {
        $type = in_array($type, ['room', 'charge', 'extra', 'payment', 'refund'], true) ? $type : '';
        $amount = round($amount, 2);
        if ($groupId <= 0 || $type === '' || $amount <= 0) { return false; }
        try {
            $g = $db->get('stays_groups', ['id', 'status'], ['id' => $groupId, 'stay_id' => $stayId]);
            if (!$g || in_array($g['status'], ['completed', 'cancelled'], true)) { return false; }
            $db->insert('stays_group_items', [
                'group_id'   => $groupId, 'type' => $type,
                'description'=> substr(trim($desc), 0, 191) ?: ucfirst($type),
                'amount'     => $amount, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            return true;
        } catch (\Throwable $e) { error_log('group_folio_add: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('group_totals')) {
    /** ['charges','payments','balance'] for a group master folio. */
    function group_totals($db, int $groupId): array
    {
        $out = ['charges' => 0.0, 'payments' => 0.0, 'balance' => 0.0];
        try {
            foreach ($db->select('stays_group_items', ['type', 'amount'], ['group_id' => $groupId]) ?: [] as $it) {
                $amt = round((float) $it['amount'], 2);
                if ((string) $it['type'] === 'payment') { $out['payments'] += $amt; }
                elseif ((string) $it['type'] === 'refund') { $out['payments'] -= $amt; }
                else { $out['charges'] += $amt; }
            }
            $out['charges'] = round($out['charges'], 2);
            $out['payments'] = round($out['payments'], 2);
            $out['balance'] = round($out['charges'] - $out['payments'], 2);
        } catch (\Throwable $e) { error_log('group_totals: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('group_set_status')) {
    /**
     * Transition a group: enquiry→confirmed→completed | cancelled. On COMPLETE, post
     * the master-folio charges to the GL (DR 1200 City-AR / CR 4000 revenue), once,
     * atomically. On CANCEL, release all its held blocks. Returns bool.
     */
    function group_set_status($db, int $groupId, int $stayId, string $to): bool
    {
        $allowed = ['enquiry' => ['confirmed', 'cancelled'], 'confirmed' => ['completed', 'cancelled'], 'completed' => [], 'cancelled' => []];
        try {
            $g = $db->get('stays_groups', '*', ['id' => $groupId, 'stay_id' => $stayId]);
            if (!$g) { return false; }
            $from = (string) $g['status'];
            if (!in_array($to, $allowed[$from] ?? [], true)) { return false; }

            if ($to === 'cancelled') {
                // Free every held block.
                foreach ($db->select('stays_group_blocks', ['id'], ['group_id' => $groupId, 'state' => 'held']) ?: [] as $b) {
                    group_block_release($db, (int) $b['id'], $stayId);
                }
                $db->update('stays_groups', ['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $groupId, 'status' => $from]);
                return true;
            }

            if ($to === 'completed') {
                $totals = group_totals($db, $groupId);
                // Ensure a real org so completion POSTS to the books rather than silently
                // skipping the GL when stays.org_id was never backfilled (MEDIUM audit).
                $orgId = ($g['org_id'] !== null && (int) $g['org_id'] > 0) ? (int) $g['org_id'] : 0;
                if ($orgId <= 0 && function_exists('supplier_property_org_ensure')) {
                    $orgId = supplier_property_org_ensure($db, $stayId);
                }
                $ok = false;
                $db->action(function ($db) use ($groupId, $stayId, $g, $orgId, $totals, &$ok) {
                    $lock = $db->query('SELECT status FROM stays_groups WHERE id=:x FOR UPDATE', [':x' => $groupId]);
                    $cur = $lock ? $lock->fetch(\PDO::FETCH_ASSOC) : null;
                    if (!$cur || $cur['status'] !== 'confirmed') { $ok = false; return false; }
                    if ($orgId > 0 && $totals['charges'] > 0 && function_exists('gl_post')) {
                        // Charges: DR City-AR / CR Revenue.
                        $res = gl_post($db, $orgId, [
                            ['code' => '1200', 'debit' => $totals['charges']],
                            ['code' => '4000', 'credit' => $totals['charges']],
                        ], ['source' => 'group', 'reference' => (string) $g['reference'], 'currency' => (string) $g['currency'],
                            'property_id' => $stayId, 'memo' => 'Group ' . $g['reference']]);
                        // FAIL-HARD: a failed GL post must ROLL BACK the whole completion
                        // (mirrors the folio-checkout fix). Previously the error was only
                        // logged and the group was still marked 'completed' below, leaving
                        // a completed group with no/partial books. gl_post is idempotent on
                        // (org,source,reference), so a retry re-posts cleanly.
                        if (empty($res['ok'])) { error_log('group complete GL: ' . ($res['message'] ?? '?')); $ok = false; return false; }
                        // AR RELIEF: payments collected against the master folio must
                        // relieve City-AR (DR Cash / CR City-AR), else AR stays overstated
                        // forever. Net of refunds; only when positive. Separate source so
                        // the idempotency key doesn't collide with the charges entry.
                        $netPaid = round((float) ($totals['payments'] ?? 0), 2);
                        if ($netPaid > 0) {
                            $pres = gl_post($db, $orgId, [
                                ['code' => '1000', 'debit' => $netPaid],
                                ['code' => '1200', 'credit' => $netPaid],
                            ], ['source' => 'group_payment', 'reference' => (string) $g['reference'], 'currency' => (string) $g['currency'],
                                'property_id' => $stayId, 'memo' => 'Group payment ' . $g['reference']]);
                            if (empty($pres['ok'])) { error_log('group payment GL: ' . ($pres['message'] ?? '?')); $ok = false; return false; }
                        }
                    }
                    $db->update('stays_groups', ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
                        ['id' => $groupId, 'status' => 'confirmed']);
                    $ok = true; return true;
                });
                return !empty($ok);
            }

            $db->update('stays_groups', ['status' => $to, 'updated_at' => date('Y-m-d H:i:s')], ['id' => $groupId, 'status' => $from]);
            return true;
        } catch (\Throwable $e) { error_log('group_set_status: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('groups_for_property')) {
    /** Groups for a property (read-only). */
    function groups_for_property($db, int $stayId, int $limit = 100): array
    {
        if ($stayId <= 0) { return []; }
        try {
            return $db->select('stays_groups',
                ['id', 'reference', 'name', 'company', 'arrival', 'departure', 'currency', 'status'],
                ['stay_id' => $stayId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => max(1, $limit)]) ?: [];
        } catch (\Throwable $e) { error_log('groups_for_property: ' . $e->getMessage()); return []; }
    }
}

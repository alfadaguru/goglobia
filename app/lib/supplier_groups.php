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
    function group_block_add($db, int $groupId, int $stayId, int $roomId, int $optionId, string $from, string $to, int $qty, string $cutoff = '', ?float $rate = null): array
    {
        if ($groupId <= 0 || $stayId <= 0 || $roomId <= 0 || $optionId <= 0) { return ['ok' => false, 'message' => 'Bad input']; }
        $qty = max(1, (int) $qty);
        $cutoff = preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($cutoff)) ? trim($cutoff) : null;
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
                'cutoff_date'=> $cutoff,
                'rate'       => ($rate !== null && $rate > 0) ? round($rate, 2) : null,
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

if (!function_exists('group_member_assign_room')) {
    /**
     * Assign a real physical room NUMBER to a group member (same capability a walk-in has).
     * Validation REUSES the one canonical set the booking path uses — no parallel rules:
     *   - the member exists and belongs to a group owned by $stayId;
     *   - the room belongs to $stayId, is active, and in an assignable hk_status;
     *   - the room is NOT taken for the member's BLOCK dates by any live booking OR any
     *     other group member — via hk_rooms_blocking (which now unions in group members),
     *     excluding THIS member's own current assignment;
     *   - the room has no active OOO block over those dates.
     * Serialized by a FOR UPDATE lock on the stays_physical_rooms row (same shared lock
     * point hk_assign_room_to_booking uses) so two concurrent assigns of the same room
     * can't both win. Returns ['ok'=>bool,'message'=>string].
     */
    function group_member_assign_room($db, int $memberId, int $stayId, int $physicalRoomId): array
    {
        if ($memberId <= 0 || $stayId <= 0 || $physicalRoomId <= 0) { return ['ok' => false, 'message' => 'Bad input']; }
        try {
            // Member + its group (ownership) + its block (dates).
            $m = $db->get('stays_group_members', ['id', 'group_id', 'block_id'], ['id' => $memberId]);
            if (!$m) { return ['ok' => false, 'message' => 'Member not found']; }
            $blk = $db->get('stays_group_blocks', ['id', 'stay_id', 'date_from', 'date_to', 'room_id', 'state'],
                ['id' => (int) $m['block_id'], 'group_id' => (int) $m['group_id'], 'stay_id' => $stayId]);
            if (!$blk) { return ['ok' => false, 'message' => 'This member is not part of a block for your property.']; }
            if (($blk['state'] ?? '') !== 'held') { return ['ok' => false, 'message' => 'That block is no longer held.']; }

            // Room must be this property's, active, assignable status.
            $room = $db->get('stays_physical_rooms', ['id', 'hk_status', 'active', 'room_id'],
                ['id' => $physicalRoomId, 'stay_id' => $stayId]);
            if (!$room || (int) $room['active'] !== 1) { return ['ok' => false, 'message' => 'That room is not available for this property.']; }
            $assignable = function_exists('hk_assignable_statuses') ? hk_assignable_statuses() : ['clean', 'inspected'];
            if (!in_array((string) ($room['hk_status'] ?? ''), $assignable, true)) {
                return ['ok' => false, 'message' => 'That room is occupied or not ready. Pick an available room.'];
            }

            $from = (string) ($blk['date_from'] ?? ''); $to = (string) ($blk['date_to'] ?? '');

            $result = ['ok' => false, 'message' => 'Could not assign the room.'];
            $db->action(function ($db) use ($memberId, $stayId, $physicalRoomId, $from, $to, &$result) {
                // Shared lock point: serialize concurrent assigns of THIS physical room.
                try { $db->query('SELECT id FROM stays_physical_rooms WHERE id = :id FOR UPDATE', [':id' => $physicalRoomId]); }
                catch (\Throwable $e) { error_log('group_member_assign_room lock: ' . $e->getMessage()); $result = ['ok' => false, 'message' => 'Lock error']; return false; }

                // Active OOO block over the member's dates?
                if (function_exists('hk_room_has_ooo_overlap') && hk_room_has_ooo_overlap($db, $physicalRoomId, $from, $to)) {
                    $result = ['ok' => false, 'message' => 'That room is out of order for those dates.']; return false;
                }
                // Taken by any live booking OR another group member over the member's dates?
                // (hk_rooms_blocking now unions in group members.) Exclude THIS member's own
                // current assignment so re-saving the same room is a no-op, not a self-conflict.
                $blocking = function_exists('hk_rooms_blocking') ? hk_rooms_blocking($db, $stayId, $from, $to, '') : [];
                if (isset($blocking[$physicalRoomId]) && $blocking[$physicalRoomId] !== ('GRP:member:' . $memberId)) {
                    $result = ['ok' => false, 'message' => 'That room is already taken for those dates. Pick another.']; return false;
                }

                $db->update('stays_group_members',
                    ['physical_room_id' => $physicalRoomId],
                    ['id' => $memberId]);
                $result = ['ok' => true, 'message' => 'Room assigned.'];
                return true;
            });
            return $result;
        } catch (\Throwable $e) {
            error_log('group_member_assign_room: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Assign error'];
        }
    }
}

if (!function_exists('group_member_unassign_room')) {
    /** Clear a group member's physical-room assignment (frees the room). Owner-scoped via
     *  the member's group/block belonging to $stayId. Returns bool. */
    function group_member_unassign_room($db, int $memberId, int $stayId): bool
    {
        if ($memberId <= 0 || $stayId <= 0) { return false; }
        try {
            $m = $db->get('stays_group_members', ['id', 'group_id', 'block_id'], ['id' => $memberId]);
            if (!$m) { return false; }
            $blk = $db->get('stays_group_blocks', ['id'],
                ['id' => (int) $m['block_id'], 'group_id' => (int) $m['group_id'], 'stay_id' => $stayId]);
            if (!$blk) { return false; } // not this property's member
            $db->update('stays_group_members', ['physical_room_id' => null], ['id' => $memberId]);
            return true;
        } catch (\Throwable $e) { error_log('group_member_unassign_room: ' . $e->getMessage()); return false; }
    }
}

if (!function_exists('group_member_book')) {
    /**
     * MATERIALISE a group member into a REAL confirmed reservation (report 11.13 #3c:
     * "group booking doesn't save as a reservation — not in room status / reservations").
     *
     * A group block HOLDS N rooms as a single stays_holds row (state='held', qty=N). This
     * converts ONE of those held rooms into an individual booking so the guest shows on the
     * front-desk room board + the reservations page — WITHOUT re-holding (no oversell window).
     * Inventory invariant (agent-verified option B): in ONE $db->action(), decrement the
     * block's held qty by 1, decrement stays_group_blocks.qty by 1, insert a 'consumed'
     * stays_holds row (qty=1, ref=invoice) for the new booking, and insert the bookings row
     * (confirmed), mirroring resv_create_walkin's insert. Committed total stays constant at
     * every instant, so no night ever looks free mid-operation.
     *
     * The member must already have a guest_name; a physical room is assigned onto the
     * booking's booking_data so it lands on the board. Idempotent: a member already booked
     * (invoice_id set + live) is a no-op success. Returns ['ok','invoice_id','message'].
     */
    function group_member_book($db, int $memberId, int $stayId): array
    {
        if ($memberId <= 0 || $stayId <= 0) { return ['ok' => false, 'message' => 'Bad input']; }
        try {
            $m = $db->get('stays_group_members',
                ['id', 'group_id', 'block_id', 'guest_name', 'email', 'phone', 'customer_id', 'physical_room_id', 'invoice_id'],
                ['id' => $memberId]);
            if (!$m) { return ['ok' => false, 'message' => 'Member not found']; }

            // Idempotency: already materialised into a LIVE booking → no-op.
            $existingInv = trim((string) ($m['invoice_id'] ?? ''));
            if ($existingInv !== '') {
                $b = $db->get('bookings', ['invoice_id', 'booking_status'], ['invoice_id' => $existingInv]);
                if ($b && strtolower((string) ($b['booking_status'] ?? '')) !== 'cancelled') {
                    return ['ok' => true, 'invoice_id' => $existingInv, 'already' => true, 'message' => 'Already booked.'];
                }
            }

            $guestName = trim((string) ($m['guest_name'] ?? ''));
            if ($guestName === '') { return ['ok' => false, 'message' => 'Add the guest name first.']; }

            $blk = $db->get('stays_group_blocks',
                ['id', 'stay_id', 'group_id', 'room_id', 'option_id', 'date_from', 'date_to', 'qty', 'hold_id', 'rate', 'state'],
                ['id' => (int) $m['block_id'], 'group_id' => (int) $m['group_id'], 'stay_id' => $stayId]);
            if (!$blk) { return ['ok' => false, 'message' => 'This member is not part of a block for your property.']; }
            if (($blk['state'] ?? '') !== 'held') { return ['ok' => false, 'message' => 'That block is no longer held.']; }

            $roomId   = (int) $blk['room_id'];
            $optionId = (int) $blk['option_id'];
            $checkin  = (string) $blk['date_from'];
            $checkout = (string) $blk['date_to'];
            $holdId   = (int) ($blk['hold_id'] ?? 0);

            // Price the single room (server-authoritative; same helper the walk-in uses).
            $stay = $db->get('stays', ['currency'], ['id' => $stayId]);
            $currency = strtoupper(trim((string) ($stay['currency'] ?? 'USD'))) ?: 'USD';
            $net = null;
            if (function_exists('stays_recompute_net')) {
                $r = stays_recompute_net($db, $stayId,
                    [['room_id' => $roomId, 'quantity' => 1, 'option' => ['option_id' => $optionId]]],
                    $checkin, $checkout, $currency);
                if (!empty($r['ok'])) { $net = (float) $r['net']; }
            }
            // A negotiated group rate (per night) overrides the computed net when set.
            $nights = max(1, (int) round((strtotime($checkout) - strtotime($checkin)) / 86400));
            if ($blk['rate'] !== null && (float) $blk['rate'] > 0) { $net = round((float) $blk['rate'] * $nights, 2); }
            if ($net === null) { return ['ok' => false, 'message' => 'Could not price the room for those dates.']; }

            $markup = $net;
            if (function_exists('MARKUP')) {
                $moduleRow = $db->get('modules', '*', ['name' => 'hotels', 'type' => 'stays', 'status' => '1'])
                    ?: $db->get('modules', '*', ['type' => 'stays', 'status' => '1']);
                $mk = MARKUP($net, $moduleRow ?: null, $db, $currency, $currency);
                if (!empty($mk['price']) && (float) $mk['price'] > 0) { $markup = round((float) $mk['price'], 2); }
            }
            $taxAmount = function_exists('calculateTax') ? round((float) (calculateTax($markup, 'hotels', $db)['tax_amount'] ?? 0), 2) : 0.0;
            $finalTotal = round($markup + $taxAmount, 2);

            $invoiceId = function_exists('resv_generate_invoice_id') ? resv_generate_invoice_id() : ('GRPB-' . bin2hex(random_bytes(6)));
            $now = date('Y-m-d H:i:s');
            [$firstName, $lastName] = array_pad(explode(' ', $guestName, 2), 2, '');

            // Resolve the payer (customer) + stayer (guest) from the MEMBER's own details —
            // NOT auto-attached (report 11.13 #3a). customer_id on the member wins if set.
            $orgId = function_exists('supplier_property_org') ? (int) supplier_property_org($db, $stayId) : 0;
            $custId = (int) ($m['customer_id'] ?? 0);
            $guestId = 0;
            if ($orgId > 0 && function_exists('contacts_resolve_for_booking')) {
                $custId = (int) contacts_resolve_for_booking($db, $orgId, [
                    'customer_id' => $custId, 'first_name' => $firstName, 'last_name' => $lastName,
                    'email' => (string) ($m['email'] ?? ''), 'phone' => (string) ($m['phone'] ?? ''),
                ]);
            }
            if ($orgId > 0 && function_exists('guests_resolve_for_booking')) {
                $guestId = (int) guests_resolve_for_booking($db, $orgId, [
                    'first_name' => $firstName, 'last_name' => $lastName,
                    'email' => (string) ($m['email'] ?? ''), 'phone' => (string) ($m['phone'] ?? ''),
                    'customer_id' => $custId,
                ]);
            }

            $bookingData = [
                'hotel_id'        => $stayId,
                'supplier'        => 'hotels',
                'checkin'         => $checkin,
                'checkout'        => $checkout,
                'currency'        => $currency,
                'booking_source'  => 'group',
                'created_by_desk' => (string) ($_SESSION['user_id'] ?? ''),
                'group_id'        => (int) $m['group_id'],
                'group_member_id' => $memberId,
                'rooms_data'      => [['adults' => 1, 'children' => 0]],
                'selected_rooms'  => [['room_id' => $roomId, 'quantity' => 1, 'option' => ['option_id' => $optionId]]],
            ];
            if ((int) ($m['physical_room_id'] ?? 0) > 0) {
                $bookingData['pms_physical_room_id'] = (int) $m['physical_room_id']; // so it shows on the board
            }

            $result = ['ok' => false, 'message' => 'Could not create the reservation.'];
            $db->action(function ($db) use (
                $blk, $holdId, $memberId, $stayId, $roomId, $optionId, $checkin, $checkout,
                $invoiceId, $net, $finalTotal, $taxAmount, $currency, $firstName, $lastName,
                $m, $bookingData, $custId, $guestId, $now, &$result
            ) {
                // 1) Lock the block's held hold row, verify it still has a room to give.
                $hold = null;
                if ($holdId > 0) {
                    $st = $db->query('SELECT id, qty, state FROM stays_holds WHERE id = :id FOR UPDATE', [':id' => $holdId]);
                    $hold = $st ? $st->fetch(\PDO::FETCH_ASSOC) : null;
                }
                if (!$hold || (string) $hold['state'] !== 'held' || (int) $hold['qty'] < 1) {
                    $result = ['ok' => false, 'message' => 'The block hold has no room left to book.']; return false;
                }
                // 2) Move 1 unit from the block's HELD row to a new CONSUMED row keyed to this
                //    invoice. Committed total (held+consumed) is unchanged → no oversell window.
                $db->query('UPDATE stays_holds SET qty = qty - 1 WHERE id = :id AND qty >= 1', [':id' => (int) $hold['id']]);
                $db->insert('stays_holds', [
                    'stay_id' => $stayId, 'room_id' => $roomId, 'option_id' => $optionId,
                    'date_from' => $checkin, 'date_to' => $checkout, 'qty' => 1,
                    'state' => 'consumed', 'ref' => $invoiceId, 'created_at' => $now,
                ]);
                // 3) Decrement the block's advertised qty (0 = fully picked up).
                $db->update('stays_group_blocks', ['qty' => max(0, (int) $blk['qty'] - 1), 'updated_at' => $now], ['id' => (int) $blk['id']]);
                // 4) Insert the real reservation (confirmed), mirroring resv_create_walkin.
                $db->insert('bookings', [
                    'invoice_id' => $invoiceId, 'module_type' => 'stays', 'module' => 'hotels',
                    'booking_date' => $now, 'booking_status' => 'confirmed', 'payment_status' => 'unpaid',
                    'paid_at' => null, 'price_original' => round($net, 2), 'price_markup' => $finalTotal,
                    'currency_markup' => $currency, 'tax' => $taxAmount,
                    'first_name' => $firstName, 'last_name' => $lastName,
                    'email' => trim((string) ($m['email'] ?? '')), 'phone' => trim((string) ($m['phone'] ?? '')),
                    'phone_country_code' => '', 'country' => '', 'address' => '', 'child_ages' => '', 'travellers' => '',
                    'adults' => 1, 'childs' => 0, 'booking_data' => json_encode($bookingData),
                    'customer_id' => $custId > 0 ? $custId : null, 'guest_id' => $guestId > 0 ? $guestId : null,
                    'payment_gateway' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
                if ((int) $db->id() <= 0) { $result = ['ok' => false, 'message' => 'Could not save the reservation.']; return false; }
                // 5) Link the member → its booking.
                $db->update('stays_group_members', ['invoice_id' => $invoiceId, 'customer_id' => $custId > 0 ? $custId : null], ['id' => $memberId]);
                $result = ['ok' => true, 'invoice_id' => $invoiceId, 'message' => 'Reservation ' . $invoiceId . ' created from the group block.'];
                return true;
            });
            return $result;
        } catch (\Throwable $e) {
            error_log('group_member_book (' . $memberId . '): ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not create the reservation.'];
        }
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

// ===========================================================================
// MODULE 08 DEEPEN: cutoff auto-release, group check-in, master invoice.
// ===========================================================================

if (!function_exists('group_cutoff_sweep')) {
    /**
     * CUTOFF / RELEASE: auto-release any held block whose cutoff_date has passed (rooms
     * not picked up by the cutoff return to general sale). Idempotent — releases via the
     * state-guarded group_block_release. Scope to one property or (stayId=0) all.
     * Returns the number of blocks released. Call from a cron or the groups page load.
     */
    function group_cutoff_sweep($db, int $stayId = 0): int
    {
        $today = date('Y-m-d');
        $released = 0;
        try {
            $where = ['state' => 'held', 'cutoff_date[<]' => $today, 'cutoff_date[!]' => null];
            if ($stayId > 0) { $where['stay_id'] = $stayId; }
            $blocks = $db->select('stays_group_blocks', ['id', 'stay_id'], $where) ?: [];
            foreach ($blocks as $b) {
                if (group_block_release($db, (int) $b['id'], (int) $b['stay_id'])) { $released++; }
            }
        } catch (\Throwable $e) { error_log('group_cutoff_sweep: ' . $e->getMessage()); }
        return $released;
    }
}

if (!function_exists('group_checkin_all')) {
    /**
     * GROUP CHECK-IN: bulk check-in every rooming-list member of a group that has a linked
     * booking (members may carry an invoice_id once individual bookings are created).
     * Delegates to the canonical folio_checkin per booking. Members without a booking are
     * skipped (a group block holds inventory but a member needs a booking to check in).
     * Returns ['checked_in'=>int,'skipped'=>int].
     */
    function group_checkin_all($db, int $groupId, int $stayId): array
    {
        $out = ['checked_in' => 0, 'skipped' => 0];
        try {
            // SCOPE THE GROUP to $stayId (audit #15): every sibling group function keys
            // stays_groups by id AND stay_id, but this one didn't — so a caller could bulk
            // check-in members of a group belonging to another property/org. Verify first.
            if (!$db->has('stays_groups', ['id' => $groupId, 'stay_id' => $stayId])) {
                return $out; // not this property's group → do nothing
            }
            $members = $db->select('stays_group_members', ['id', 'email', 'guest_name'],
                ['group_id' => $groupId]) ?: [];
            foreach ($members as $m) {
                // Link a member to a booking by email match — SCOPED to THIS property
                // (audit #15: an unscoped email+module_type match across the whole bookings
                // table could resolve to, and then check in, a foreign property's/org's
                // booking). Filter booking_data.hotel_id to this stay; verify after decode.
                $inv = null;
                $email = trim((string) ($m['email'] ?? ''));
                if ($email !== '') {
                    $cands = $db->select('bookings', ['invoice_id', 'booking_data'],
                        ['email' => $email, 'module_type' => ['stays', 'hotels'],
                         'booking_data[~]' => '%"hotel_id":' . (int) $stayId . '%', // % wrapped (underscore defeats Medoo auto-wrap)
                         'ORDER' => ['id' => 'DESC'], 'LIMIT' => 20]) ?: [];
                    foreach ($cands as $cb) {
                        $bd = json_decode((string) ($cb['booking_data'] ?? ''), true);
                        if (is_array($bd) && (int) ($bd['hotel_id'] ?? 0) === $stayId) { // verify, not just LIKE
                            $inv = (string) $cb['invoice_id'];
                            break;
                        }
                    }
                }
                if ($inv !== null && function_exists('folio_checkin') && folio_checkin($db, $inv)) {
                    if (function_exists('folio_get_or_create')) { folio_get_or_create($db, $inv); }
                    $out['checked_in']++;
                } else {
                    $out['skipped']++;
                }
            }
        } catch (\Throwable $e) { error_log('group_checkin_all: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('group_invoice_data')) {
    /**
     * GROUP INVOICE: assemble a printable master-folio invoice for a group — the group
     * header, its blocks (rooms/nights/rate), the master-folio line items and the totals.
     * Read-only. Returns ['group','blocks','items','totals'] or null.
     */
    function group_invoice_data($db, int $groupId, int $stayId): ?array
    {
        try {
            $g = $db->get('stays_groups', '*', ['id' => $groupId, 'stay_id' => $stayId]);
            if (!$g) { return null; }
            $blocks = $db->select('stays_group_blocks', '*', ['group_id' => $groupId]) ?: [];
            $items = $db->select('stays_group_items', '*', ['group_id' => $groupId, 'ORDER' => ['id' => 'ASC']]) ?: [];
            $totals = function_exists('group_totals') ? group_totals($db, $groupId) : ['charges' => 0, 'payments' => 0, 'balance' => 0];
            return ['group' => $g, 'blocks' => $blocks, 'items' => $items, 'totals' => $totals];
        } catch (\Throwable $e) { error_log('group_invoice_data: ' . $e->getMessage()); return null; }
    }
}

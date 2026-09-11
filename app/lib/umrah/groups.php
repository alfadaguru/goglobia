<?php
// ============================================================================
// UMRAH AGENT GROUPS (Phase C, Nusuk-style). docs/UMRAH-REBUILD-PLAN.md §C.
// An agent builds a group on a package: staged (declares counts first), the
// wallet is debited only on SUBMIT, then add/drop members + upload documents
// while pending/processing. All pricing is server-authoritative (agent B2B net
// when set). Reuses the atomic+idempotent agent wallet (agent_api_charge_wallet)
// and the verified booking engine (umrah_booking_create) to materialize.
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
        if (!in_array($g['status'], ['draft', 'pending'], true)) {
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

if (!function_exists('umrah_group_drop_member')) {
    function umrah_group_drop_member($db, int $groupId, int $memberId): array
    {
        $g = umrah_group_owned($db, $groupId);
        if (!$g) { return ['ok' => false, 'message' => 'Group not found']; }
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

if (!function_exists('umrah_group_submit')) {
    /**
     * Submit a group: validate the tier rule, charge the agent WALLET for the
     * whole group (the ONLY point money moves — staged before this), then
     * materialize an agent-owned, PAID umrah booking with the members as
     * travellers. Sets status draft/pending -> paid -> submitted.
     * Idempotent: a group already paid/submitted is not charged again.
     * @return array ['ok'=>true,'booking_ref'=>..,'invoice_id'=>..] | ['ok'=>false,'message'=>..,'code'=>..]
     */
    function umrah_group_submit($db, int $groupId, array $lead = []): array
    {
        $g = umrah_group_owned($db, $groupId);
        if (!$g) { return ['ok' => false, 'message' => 'Group not found']; }
        if (in_array($g['status'], ['paid', 'submitted', 'processing', 'confirmed'], true)) {
            return ['ok' => true, 'already' => true, 'booking_ref' => null, 'invoice_id' => $g['invoice_id']];
        }
        if ($g['status'] === 'cancelled') { return ['ok' => false, 'message' => 'Group is cancelled']; }

        // Re-price + validate the tier group rule.
        umrah_group_recount($db, $groupId);
        $g = $db->get('umrah_groups', '*', ['id' => $groupId]);
        if ((int) $g['pax_count'] < 1) { return ['ok' => false, 'message' => 'Add pilgrims (or declare counts) before submitting']; }
        $rule = umrah_group_validate_rules($db, $g);
        if (empty($rule['ok'])) { return ['ok' => false, 'message' => $rule['message']]; }

        $dt = $db->get('umrah_departure_tiers', '*', ['id' => (int) $g['departure_tier_id']]);
        if (!$dt || !umrah_departure_bookable($db, (int) $dt['departure_id'], $dt)) {
            return ['ok' => false, 'message' => 'This departure is no longer open for booking'];
        }
        $departure = $db->get('umrah_departures', '*', ['id' => (int) $dt['departure_id']]);
        $template  = $departure ? $db->get('umrah_package_templates', '*', ['id' => (int) $departure['template_id']]) : null;

        $agent    = (string) $g['agent_user_id'];
        $pax      = (int) $g['pax_count'];
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
            return ['ok' => false, 'message' => 'Wallet unavailable'];
        }
        $charge = agent_api_charge_wallet($db, $agent, 'umrah', $total, $invoiceId);
        if (empty($charge['ok'])) {
            return ['ok' => false, 'code' => 'insufficient_funds', 'message' => $charge['message'] ?? 'Insufficient wallet balance', 'required' => $charge['required'] ?? $total, 'balance' => $charge['balance'] ?? null];
        }

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
                $lead0 = $db->get('umrah_group_members', '*', ['group_id' => $groupId, 'ORDER' => ['id' => 'ASC']]);
                $db->insert('bookings', [
                    'invoice_id' => $invoiceId, 'booking_status' => 'confirmed', 'payment_status' => 'paid',
                    'price_original' => $total, 'price_markup' => $total, 'agent_earning' => $agentEarning,
                    'currency_markup' => $currency, 'paid_at' => $now, 'transaction_id' => $walletTxn,
                    'payment_gateway' => 'Wallet',
                    'first_name' => $lead0['first_name'] ?? ($g['name'] ?? 'Group'), 'last_name' => $lead0['last_name'] ?? '',
                    'email' => $lead0['email'] ?? '', 'phone' => $lead0['mobile'] ?? '',
                    'adults' => $pax, 'childs' => 0, 'infants' => '0',
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
                }

                // Link + advance the group to submitted.
                $db->update('umrah_groups', [
                    'status' => 'submitted', 'paid' => 1, 'invoice_id' => $invoiceId,
                    'umrah_booking_id' => $ubId, 'wallet_txn' => $walletTxn,
                    'submitted_at' => $now, 'updated_at' => $now,
                ], ['id' => $groupId]);

                $result = ['ok' => true, 'booking_ref' => $bookingRef, 'invoice_id' => $invoiceId, 'umrah_booking_id' => $ubId, 'genericBookingId' => $genericId];
                return true;
            });
        } catch (\Throwable $e) {
            error_log('umrah_group_submit materialize: ' . $e->getMessage());
            // The wallet was charged but materialize failed — refund the wallet.
            try {
                $db->insert('credits', ['user_id' => $agent, 'type' => 'credit', 'credits' => round($total, 2), 'currency' => $currency, 'description' => 'Group submit refund ' . $g['group_ref'], 'created_at' => date('Y-m-d H:i:s')]);
            } catch (\Throwable $e2) { /* logged */ }
            return ['ok' => false, 'message' => 'Could not finalize the group; your wallet was not charged.'];
        }

        if (function_exists('umrah_audit')) { umrah_audit($db, 'umrah_group', $g['group_ref'], 'submitted_paid', null, ['pax' => $pax, 'total' => $total], $agent, 'agent'); }
        return $result;
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
                if (!($status === 'cancelled' && in_array($g['status'], ['draft', 'pending'], true))
                    && !(in_array($status, ['draft', 'pending'], true) && in_array($g['status'], ['draft', 'pending'], true))) {
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

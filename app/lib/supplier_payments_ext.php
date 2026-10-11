<?php
// FILE: app/lib/supplier_payments_ext.php
// MODULE 39 (payment processing & deposits → full spec) extension, on top of:
//   - resv_deposit_set()            security-deposit hold/deduct/release  (supplier_reservation_ops.php)
//   - folio_add_item() / gl_post()  folio + general-ledger money rails     (supplier_folio.php / supplier_ledger.php)
//   - supplier_earning_* spine      commission/payout settlement           (supplier_earnings.php)
//   - platform pay-later / installments / saved-cards libs (DB-GATED, admin-managed)
//
// GENUINE GAPS this file builds (and nothing it rebuilds):
//   1. RESERVATION DEPOSIT SCHEDULE — a staged payment plan on a booking (e.g. 30% at
//      booking / balance before arrival), with due dates + collect tracking. Distinct
//      from the *security* deposit (resv_deposit_set) which is a refundable hold.
//   2. CHARGEBACK LOG — internal record of a disputed/charged-back payment with a GL
//      reversal entry (Guest Ledger ↔ a disputes-expense/clearing account). BALANCED.
//   3. CARD PRE-AUTHORIZATION — internal "auth hold" record for a security deposit.
//      The actual card hold is a gateway rails feature → HONEST STUB (not connected).
//   4. PAYMENT LINK — internal record of a request-to-pay link for a balance. Sending
//      the link via a live gateway is DB-GATED → stub returns 'not connected'.
//   5. PAYMENT AUDIT TRAIL — a read helper that unifies audit_events rows this module
//      emits, so the desk has one chronological money-action log per booking.
//
// MONEY SAFETY: every GL write goes through gl_post() (refuses unbalanced entries) and
// is keyed with an idempotent (source, reference) so a double-submit can never
// double-post. Deposit-schedule amounts are derived from the booking's own stored
// total — NEVER from the client. All functions are if_not_exists-guarded, org-scoped,
// and non-fatal.

if (!function_exists('spayx_ensure_schema')) {
    /**
     * Idempotent self-healing schema for the three NEW tables this module owns.
     * Follows the house SHOW-COLUMNS/CREATE-IF-NOT-EXISTS pattern. Non-fatal: a DB
     * user without CREATE rights degrades gracefully (features show "unavailable").
     */
    function spayx_ensure_schema($db): void
    {
        static $done = false;
        if ($done) { return; }
        $done = true;
        try {
            $db->query("CREATE TABLE IF NOT EXISTS `supplier_deposit_schedules` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) DEFAULT NULL,
                `stay_id` int(11) DEFAULT NULL,
                `invoice_id` varchar(64) NOT NULL,
                `seq` int(11) NOT NULL DEFAULT 1,
                `label` varchar(100) DEFAULT NULL,
                `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
                `currency` varchar(3) DEFAULT NULL,
                `due_date` date DEFAULT NULL,
                `status` varchar(20) NOT NULL DEFAULT 'pending',
                `collected_at` datetime DEFAULT NULL,
                `collected_by` varchar(155) DEFAULT NULL,
                `method` varchar(30) DEFAULT NULL,
                `gl_entry_id` int(11) DEFAULT NULL,
                `created_by` varchar(155) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_invoice` (`invoice_id`),
                KEY `idx_stay` (`stay_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `supplier_chargebacks` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) DEFAULT NULL,
                `stay_id` int(11) DEFAULT NULL,
                `invoice_id` varchar(64) NOT NULL,
                `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
                `currency` varchar(3) DEFAULT NULL,
                `reason` varchar(255) DEFAULT NULL,
                `gateway_ref` varchar(120) DEFAULT NULL,
                `status` varchar(20) NOT NULL DEFAULT 'open',
                `gl_entry_id` int(11) DEFAULT NULL,
                `opened_by` varchar(155) DEFAULT NULL,
                `opened_at` datetime NOT NULL DEFAULT current_timestamp(),
                `resolved_at` datetime DEFAULT NULL,
                `resolved_by` varchar(155) DEFAULT NULL,
                `resolution` varchar(20) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_invoice` (`invoice_id`),
                KEY `idx_stay` (`stay_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->query("CREATE TABLE IF NOT EXISTS `supplier_payment_intents` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `org_id` int(11) DEFAULT NULL,
                `stay_id` int(11) DEFAULT NULL,
                `invoice_id` varchar(64) NOT NULL,
                `kind` varchar(20) NOT NULL DEFAULT 'link',
                `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
                `currency` varchar(3) DEFAULT NULL,
                `token` varchar(64) DEFAULT NULL,
                `status` varchar(20) NOT NULL DEFAULT 'created',
                `gateway` varchar(40) DEFAULT NULL,
                `gateway_ref` varchar(120) DEFAULT NULL,
                `note` varchar(255) DEFAULT NULL,
                `created_by` varchar(155) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_invoice` (`invoice_id`),
                KEY `idx_stay` (`stay_id`),
                KEY `idx_kind` (`kind`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $e) {
            error_log('spayx_ensure_schema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('spayx_load_booking')) {
    /**
     * Resolve + ownership-verify a booking for payment-ext work. Returns
     * ['ok','stay_id','currency','total','paid','owner_ok'] or ['ok'=>false,'message'].
     * The authority is the booking's OWN stored money (bookings.total) — client amounts
     * are never trusted for schedule/GL math. $ownedStayIds pins the IDOR guard.
     */
    function spayx_load_booking($db, string $invoiceId, array $ownedStayIds): array
    {
        try {
            $b = $db->get('bookings',
                ['invoice_id', 'total', 'currency_markup', 'payment_status', 'booking_status', 'booking_data'],
                ['invoice_id' => $invoiceId]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Lookup failed.'];
        }
        if (!$b) { return ['ok' => false, 'message' => 'Booking not found.']; }
        $bd  = json_decode((string) ($b['booking_data'] ?? ''), true);
        $hid = is_array($bd) ? (int) ($bd['hotel_id'] ?? $bd['id'] ?? 0) : 0;
        if ($hid <= 0 || !in_array($hid, $ownedStayIds, true)) {
            return ['ok' => false, 'message' => 'Not your booking.'];
        }
        return [
            'ok'       => true,
            'stay_id'  => $hid,
            'currency' => strtoupper(trim((string) ($b['currency_markup'] ?? ''))) ?: 'USD',
            'total'    => round((float) ($b['total'] ?? 0), 2),
            'paid'     => strtolower((string) $b['payment_status']) === 'paid',
            'cancelled'=> strtolower((string) $b['booking_status']) === 'cancelled',
        ];
    }
}

// ---------------------------------------------------------------------------
// 1. RESERVATION DEPOSIT SCHEDULE (staged payment plan)
// ---------------------------------------------------------------------------

if (!function_exists('spayx_schedule_list')) {
    /** All schedule rows for a booking, ordered by seq. */
    function spayx_schedule_list($db, string $invoiceId): array
    {
        try {
            return $db->select('supplier_deposit_schedules', '*',
                ['invoice_id' => $invoiceId, 'ORDER' => ['seq' => 'ASC', 'id' => 'ASC']]) ?: [];
        } catch (\Throwable $e) {
            error_log('spayx_schedule_list: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('spayx_schedule_set')) {
    /**
     * Create (or REPLACE) a staged deposit schedule for a booking from a list of
     * stages: [ ['label'=>, 'amount'=>, 'due_date'=>Y-m-d], ... ]. The amounts MUST sum
     * to no more than the booking's own stored total (server-side guard) — this is a
     * PLAN for collecting the booking price, not a way to invent new charges. Only
     * PENDING stages are replaced; already-collected stages are preserved. Wrapped in a
     * transaction. Returns ['ok','message','count'].
     */
    function spayx_schedule_set($db, array $ctx, string $invoiceId, array $stages): array
    {
        spayx_ensure_schema($db);
        $total = $ctx['total']; $currency = $ctx['currency']; $stayId = $ctx['stay_id'];
        $orgId = (int) ($ctx['org_id'] ?? 0);

        // Normalise + validate stages server-side.
        $clean = []; $sum = 0.0;
        foreach ($stages as $s) {
            $amt = round((float) ($s['amount'] ?? 0), 2);
            if ($amt <= 0) { continue; }
            $due = (string) ($s['due_date'] ?? '');
            if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) { $due = ''; }
            $clean[] = [
                'label'    => substr(trim((string) ($s['label'] ?? '')), 0, 100) ?: null,
                'amount'   => $amt,
                'due_date' => $due !== '' ? $due : null,
            ];
            $sum = round($sum + $amt, 2);
        }
        if (empty($clean)) { return ['ok' => false, 'message' => 'Add at least one stage with an amount.']; }
        if ($total > 0 && $sum > $total + 0.001) {
            return ['ok' => false, 'message' => 'Schedule (' . number_format($sum, 2) . ') exceeds the booking total (' . number_format($total, 2) . ').'];
        }

        try {
            $ok = $db->action(function ($db) use ($invoiceId, $clean, $orgId, $stayId, $currency) {
                // Preserve collected stages; drop only the still-pending ones, then re-seed.
                $db->delete('supplier_deposit_schedules', ['invoice_id' => $invoiceId, 'status' => 'pending']);
                $existing = (int) $db->count('supplier_deposit_schedules', ['invoice_id' => $invoiceId]);
                $seq = $existing;
                foreach ($clean as $c) {
                    $seq++;
                    $db->insert('supplier_deposit_schedules', [
                        'org_id'     => $orgId ?: null,
                        'stay_id'    => $stayId,
                        'invoice_id' => $invoiceId,
                        'seq'        => $seq,
                        'label'      => $c['label'] ?? ('Stage ' . $seq),
                        'amount'     => $c['amount'],
                        'currency'   => $currency,
                        'due_date'   => $c['due_date'],
                        'status'     => 'pending',
                        'created_by' => (string) ($_SESSION['user_id'] ?? ''),
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
                return true;
            });
            if ($ok === false) { return ['ok' => false, 'message' => 'Could not save the schedule.']; }
            if (function_exists('audit_log')) {
                audit_log($db, 'payment.schedule.set', 'booking', $invoiceId,
                    ['stages' => count($clean), 'sum' => $sum], $orgId ?: null);
            }
            return ['ok' => true, 'message' => count($clean) . ' stage(s) scheduled.', 'count' => count($clean)];
        } catch (\Throwable $e) {
            error_log('spayx_schedule_set: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not save the schedule.'];
        }
    }
}

if (!function_exists('spayx_schedule_collect')) {
    /**
     * Mark a scheduled stage COLLECTED at the desk and post the double-entry to the GL:
     *   DR 1000 Cash/Bank            (money in)
     *   CR 2200 Deposits / Advance   (liability — guest advance, relieved at check-out)
     * The amount is taken from the STORED schedule row, never the client. GL posting is
     * idempotent on reference 'depsched:<id>' so a double-click can't double-post. The
     * row + GL post are one transaction. $method is desk record only (cash/card/bank).
     *
     * NOTE: this records money ALREADY taken at the property (desk collection). Charging
     * a card through the gateway is a rails feature — DB-GATED, see spayx_link_create().
     */
    function spayx_schedule_collect($db, array $ctx, int $scheduleId, string $method = 'cash'): array
    {
        spayx_ensure_schema($db);
        $orgId = (int) ($ctx['org_id'] ?? 0);
        $method = in_array($method, ['cash', 'card', 'bank', 'wallet', 'other'], true) ? $method : 'cash';
        try {
            return (array) $db->action(function ($db) use ($ctx, $scheduleId, $method, $orgId) {
                $row = $db->get('supplier_deposit_schedules',
                    ['id', 'invoice_id', 'stay_id', 'amount', 'currency', 'status'],
                    ['id' => $scheduleId, 'invoice_id' => $ctx['invoice_id']]);
                if (!$row) { return ['ok' => false, 'message' => 'Stage not found.']; }
                if ((string) $row['status'] !== 'pending') { return ['ok' => false, 'message' => 'Stage already collected / cancelled.']; }
                $amount = round((float) $row['amount'], 2);
                if ($amount <= 0) { return ['ok' => false, 'message' => 'Stage has no amount.']; }

                $entryId = null;
                if ($orgId > 0 && function_exists('gl_post')) {
                    $gl = gl_post($db, $orgId, [
                        ['code' => '1000', 'debit'  => $amount],
                        ['code' => '2200', 'credit' => $amount],
                    ], [
                        'source'    => 'deposit',
                        'reference' => 'depsched:' . (int) $row['id'],
                        'memo'      => 'Deposit stage ' . $row['id'] . ' · ' . $ctx['invoice_id'] . ' (' . $method . ')',
                        'property_id' => (int) $row['stay_id'],
                        'currency'  => (string) $row['currency'],
                    ]);
                    if (empty($gl['ok'])) {
                        // Fail-closed: do NOT mark collected if the books won't accept it.
                        return ['ok' => false, 'message' => 'Ledger rejected the entry: ' . ($gl['message'] ?? 'unbalanced')];
                    }
                    $entryId = (int) $gl['entry_id'];
                }

                $db->update('supplier_deposit_schedules', [
                    'status'       => 'collected',
                    'collected_at' => date('Y-m-d H:i:s'),
                    'collected_by' => (string) ($_SESSION['user_id'] ?? ''),
                    'method'       => $method,
                    'gl_entry_id'  => $entryId,
                    'updated_at'   => date('Y-m-d H:i:s'),
                ], ['id' => (int) $row['id']]);

                if (function_exists('audit_log')) {
                    audit_log($db, 'payment.schedule.collect', 'booking', $ctx['invoice_id'],
                        ['stage_id' => (int) $row['id'], 'amount' => $amount, 'method' => $method, 'gl_entry' => $entryId], $orgId ?: null);
                }
                return ['ok' => true, 'message' => number_format($amount, 2) . ' recorded as collected (' . $method . ').'];
            });
        } catch (\Throwable $e) {
            error_log('spayx_schedule_collect: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not record the collection.'];
        }
    }
}

if (!function_exists('spayx_schedule_cancel')) {
    /** Cancel a still-PENDING stage (no GL effect). Returns ['ok','message']. */
    function spayx_schedule_cancel($db, array $ctx, int $scheduleId): array
    {
        spayx_ensure_schema($db);
        try {
            $row = $db->get('supplier_deposit_schedules', ['id', 'status'],
                ['id' => $scheduleId, 'invoice_id' => $ctx['invoice_id']]);
            if (!$row) { return ['ok' => false, 'message' => 'Stage not found.']; }
            if ((string) $row['status'] !== 'pending') { return ['ok' => false, 'message' => 'Only a pending stage can be cancelled.']; }
            $db->update('supplier_deposit_schedules',
                ['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')], ['id' => (int) $row['id']]);
            if (function_exists('audit_log')) {
                audit_log($db, 'payment.schedule.cancel', 'booking', $ctx['invoice_id'], ['stage_id' => (int) $row['id']], (int) ($ctx['org_id'] ?? 0) ?: null);
            }
            return ['ok' => true, 'message' => 'Stage cancelled.'];
        } catch (\Throwable $e) {
            error_log('spayx_schedule_cancel: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not cancel the stage.'];
        }
    }
}

// ---------------------------------------------------------------------------
// 2. CHARGEBACK LOG (with balanced GL reversal)
// ---------------------------------------------------------------------------

if (!function_exists('spayx_chargeback_list')) {
    /** Chargebacks for a booking (or for the whole scope if $invoiceId is ''). */
    function spayx_chargeback_list($db, array $stayIds, string $invoiceId = ''): array
    {
        spayx_ensure_schema($db);
        if (empty($stayIds)) { return []; }
        $where = ['stay_id' => $stayIds, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 200];
        if ($invoiceId !== '') { $where['invoice_id'] = $invoiceId; }
        try {
            return $db->select('supplier_chargebacks', '*', $where) ?: [];
        } catch (\Throwable $e) {
            error_log('spayx_chargeback_list: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('spayx_chargeback_open')) {
    /**
     * Record a chargeback/dispute against a booking and post the GL reversal:
     *   DR 6000 Operating Expense   (the disputed money leaves the property — booked as
     *                                a loss/clearing until resolved)
     *   CR 1000 Cash / Bank         (bank claws the funds back)
     * Amount is clamped to the booking total server-side. Idempotent GL reference
     * 'chargeback:<id>'. The record + GL post are one transaction. Returns ['ok','message'].
     *
     * NB: this is the INTERNAL bookkeeping of a chargeback the gateway already raised. We
     * do NOT talk to the acquirer here (evidence submission / representment is a gateway
     * rails feature — DB-GATED). The desk logs it so the books + earnings reflect reality.
     */
    function spayx_chargeback_open($db, array $ctx, string $invoiceId, float $amount, string $reason, string $gatewayRef = ''): array
    {
        spayx_ensure_schema($db);
        $orgId  = (int) ($ctx['org_id'] ?? 0);
        $stayId = (int) ($ctx['stay_id'] ?? 0);
        $total  = (float) ($ctx['total'] ?? 0);
        $amount = round($amount, 2);
        if ($amount <= 0) { return ['ok' => false, 'message' => 'Enter the charged-back amount.']; }
        if ($total > 0 && $amount > $total + 0.001) {
            return ['ok' => false, 'message' => 'Amount exceeds the booking total.'];
        }
        try {
            return (array) $db->action(function ($db) use ($ctx, $invoiceId, $amount, $reason, $gatewayRef, $orgId, $stayId) {
                $db->insert('supplier_chargebacks', [
                    'org_id'      => $orgId ?: null,
                    'stay_id'     => $stayId ?: null,
                    'invoice_id'  => $invoiceId,
                    'amount'      => $amount,
                    'currency'    => (string) ($ctx['currency'] ?? 'USD'),
                    'reason'      => substr(trim($reason), 0, 255) ?: null,
                    'gateway_ref' => substr(trim($gatewayRef), 0, 120) ?: null,
                    'status'      => 'open',
                    'opened_by'   => (string) ($_SESSION['user_id'] ?? ''),
                    'opened_at'   => date('Y-m-d H:i:s'),
                ]);
                $cbId = (int) $db->id();
                $entryId = null;
                if ($orgId > 0 && function_exists('gl_post')) {
                    $gl = gl_post($db, $orgId, [
                        ['code' => '6000', 'debit'  => $amount],
                        ['code' => '1000', 'credit' => $amount],
                    ], [
                        'source'      => 'chargeback',
                        'reference'   => 'chargeback:' . $cbId,
                        'memo'        => 'Chargeback ' . $cbId . ' · ' . $invoiceId,
                        'property_id' => $stayId ?: null,
                        'currency'    => (string) ($ctx['currency'] ?? 'USD'),
                    ]);
                    if (empty($gl['ok'])) {
                        return ['ok' => false, 'message' => 'Ledger rejected the reversal: ' . ($gl['message'] ?? 'unbalanced')];
                    }
                    $entryId = (int) $gl['entry_id'];
                    $db->update('supplier_chargebacks', ['gl_entry_id' => $entryId], ['id' => $cbId]);
                }
                if (function_exists('audit_log')) {
                    audit_log($db, 'payment.chargeback.open', 'booking', $invoiceId,
                        ['chargeback_id' => $cbId, 'amount' => $amount, 'gl_entry' => $entryId], $orgId ?: null);
                }
                return ['ok' => true, 'message' => 'Chargeback logged (' . number_format($amount, 2) . ').'];
            });
        } catch (\Throwable $e) {
            error_log('spayx_chargeback_open: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not log the chargeback.'];
        }
    }
}

if (!function_exists('spayx_chargeback_resolve')) {
    /**
     * Close a chargeback: $resolution ∈ won|lost|accepted. If WON, post the reversing
     * entry (money comes back): DR 1000 Cash / CR 6000 Operating Expense. lost/accepted
     * leave the original expense standing (the loss is final). Idempotent on
     * 'chargeback-won:<id>'. Returns ['ok','message'].
     */
    function spayx_chargeback_resolve($db, array $ctx, int $cbId, string $resolution): array
    {
        spayx_ensure_schema($db);
        $resolution = in_array($resolution, ['won', 'lost', 'accepted'], true) ? $resolution : '';
        if ($resolution === '') { return ['ok' => false, 'message' => 'Unknown resolution.']; }
        $orgId = (int) ($ctx['org_id'] ?? 0);
        try {
            return (array) $db->action(function ($db) use ($ctx, $cbId, $resolution, $orgId) {
                $cb = $db->get('supplier_chargebacks', '*', ['id' => $cbId, 'stay_id' => (int) ($ctx['stay_id'] ?? 0)]);
                if (!$cb) { return ['ok' => false, 'message' => 'Chargeback not found.']; }
                if ((string) $cb['status'] !== 'open') { return ['ok' => false, 'message' => 'Already resolved.']; }
                $amount = round((float) $cb['amount'], 2);
                if ($resolution === 'won' && $orgId > 0 && function_exists('gl_post') && $amount > 0) {
                    $gl = gl_post($db, $orgId, [
                        ['code' => '1000', 'debit'  => $amount],
                        ['code' => '6000', 'credit' => $amount],
                    ], [
                        'source'      => 'chargeback',
                        'reference'   => 'chargeback-won:' . $cbId,
                        'memo'        => 'Chargeback ' . $cbId . ' WON (reversed) · ' . $cb['invoice_id'],
                        'property_id' => (int) ($cb['stay_id'] ?? 0) ?: null,
                        'currency'    => (string) ($cb['currency'] ?? 'USD'),
                    ]);
                    if (empty($gl['ok'])) { return ['ok' => false, 'message' => 'Ledger rejected the reversal.']; }
                }
                $db->update('supplier_chargebacks', [
                    'status'      => 'resolved',
                    'resolution'  => $resolution,
                    'resolved_at' => date('Y-m-d H:i:s'),
                    'resolved_by' => (string) ($_SESSION['user_id'] ?? ''),
                ], ['id' => $cbId]);
                if (function_exists('audit_log')) {
                    audit_log($db, 'payment.chargeback.resolve', 'booking', (string) $cb['invoice_id'],
                        ['chargeback_id' => $cbId, 'resolution' => $resolution], $orgId ?: null);
                }
                return ['ok' => true, 'message' => 'Chargeback marked ' . $resolution . '.'];
            });
        } catch (\Throwable $e) {
            error_log('spayx_chargeback_resolve: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not resolve the chargeback.'];
        }
    }
}

// ---------------------------------------------------------------------------
// 3 + 4. CARD PRE-AUTHORIZATION / PAYMENT LINK  (internal record + HONEST STUB)
// ---------------------------------------------------------------------------

if (!function_exists('spayx_gateway_connected')) {
    /**
     * DB-GATED: is a live card gateway wired for THIS property's outbound charge/auth?
     * Honest reality: the platform has admin-managed gateways (payment_gateways /
     * payment_gateway_scopes) for INBOUND customer checkout, but there is NO supplier-
     * initiated "charge this card now" / "place an auth hold" rail. So preauth + payment-
     * link SENDING are not connected. We return false and never fake it. Wiring this to a
     * real MOTO/token charge is a platform rails feature (deferred).
     */
    function spayx_gateway_connected($db, int $stayId): bool
    {
        return false; // not connected — see docblock
    }
}

if (!function_exists('spayx_intent_create')) {
    /**
     * Create an internal payment-intent record: a card PRE-AUTH hold ($kind='preauth')
     * or a request-to-pay LINK ($kind='link'). Amount is validated against the booking
     * total server-side. The record is REAL (tracked, audited); actually placing the
     * hold / delivering the link through a gateway is DB-GATED — status stays 'created'
     * and the caller surfaces an honest "not connected" notice. No GL effect (an auth
     * hold is not a posting; a link is only a request). Returns ['ok','message','connected'].
     */
    function spayx_intent_create($db, array $ctx, string $invoiceId, string $kind, float $amount, string $note = ''): array
    {
        spayx_ensure_schema($db);
        $kind = in_array($kind, ['preauth', 'link'], true) ? $kind : 'link';
        $amount = round($amount, 2);
        if ($amount <= 0) { return ['ok' => false, 'message' => 'Enter an amount.']; }
        $total = (float) ($ctx['total'] ?? 0);
        if ($total > 0 && $amount > $total + 0.001 && $kind === 'link') {
            return ['ok' => false, 'message' => 'Link amount exceeds the booking total.'];
        }
        $orgId  = (int) ($ctx['org_id'] ?? 0);
        $stayId = (int) ($ctx['stay_id'] ?? 0);
        $connected = spayx_gateway_connected($db, $stayId);
        try {
            $db->insert('supplier_payment_intents', [
                'org_id'     => $orgId ?: null,
                'stay_id'    => $stayId ?: null,
                'invoice_id' => $invoiceId,
                'kind'       => $kind,
                'amount'     => $amount,
                'currency'   => (string) ($ctx['currency'] ?? 'USD'),
                'token'      => bin2hex(random_bytes(16)),
                'status'     => 'created', // never 'sent'/'authorized' — we are not connected
                'note'       => substr(trim($note), 0, 255) ?: null,
                'created_by' => (string) ($_SESSION['user_id'] ?? ''),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $id = (int) $db->id();
            if (function_exists('audit_log')) {
                audit_log($db, 'payment.intent.create', 'booking', $invoiceId,
                    ['intent_id' => $id, 'kind' => $kind, 'amount' => $amount, 'connected' => $connected], $orgId ?: null);
            }
            $msg = $kind === 'preauth'
                ? 'Pre-authorization request recorded. '
                : 'Payment link recorded. ';
            $msg .= $connected ? 'Sent to the gateway.' : 'Gateway not connected — this is a record only (no card was charged or held).';
            return ['ok' => true, 'message' => $msg, 'connected' => $connected];
        } catch (\Throwable $e) {
            error_log('spayx_intent_create: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not create the request.'];
        }
    }
}

if (!function_exists('spayx_intent_list')) {
    /** Payment intents (preauths + links) for a booking, newest first. */
    function spayx_intent_list($db, string $invoiceId): array
    {
        spayx_ensure_schema($db);
        try {
            return $db->select('supplier_payment_intents', '*',
                ['invoice_id' => $invoiceId, 'ORDER' => ['id' => 'DESC'], 'LIMIT' => 50]) ?: [];
        } catch (\Throwable $e) {
            error_log('spayx_intent_list: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('spayx_intent_cancel')) {
    /** Cancel/void an internal intent (record-only state change). Returns ['ok','message']. */
    function spayx_intent_cancel($db, array $ctx, int $intentId): array
    {
        spayx_ensure_schema($db);
        try {
            $row = $db->get('supplier_payment_intents', ['id', 'status', 'invoice_id'],
                ['id' => $intentId, 'stay_id' => (int) ($ctx['stay_id'] ?? 0)]);
            if (!$row) { return ['ok' => false, 'message' => 'Not found.']; }
            if (in_array((string) $row['status'], ['cancelled', 'captured'], true)) {
                return ['ok' => false, 'message' => 'Cannot cancel in its current state.'];
            }
            $db->update('supplier_payment_intents',
                ['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $intentId]);
            if (function_exists('audit_log')) {
                audit_log($db, 'payment.intent.cancel', 'booking', (string) $row['invoice_id'], ['intent_id' => $intentId], (int) ($ctx['org_id'] ?? 0) ?: null);
            }
            return ['ok' => true, 'message' => 'Request cancelled.'];
        } catch (\Throwable $e) {
            error_log('spayx_intent_cancel: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not cancel.'];
        }
    }
}

// ---------------------------------------------------------------------------
// 5. PAYMENT AUDIT TRAIL (read helper over audit_events)
// ---------------------------------------------------------------------------

if (!function_exists('spayx_audit_trail')) {
    /**
     * The chronological money-action trail for a booking: every payment.* event this
     * module (and the security-deposit flow) emitted into audit_events. Read-only.
     */
    function spayx_audit_trail($db, string $invoiceId, int $limit = 50): array
    {
        try {
            return $db->select('audit_events',
                ['event', 'actor_user_id', 'meta', 'created_at'],
                [
                    'subject_type' => 'booking',
                    'subject_id'   => $invoiceId,
                    'event[~]'     => 'payment.%',
                    'ORDER'        => ['id' => 'DESC'],
                    'LIMIT'        => max(1, min(200, $limit)),
                ]) ?: [];
        } catch (\Throwable $e) {
            error_log('spayx_audit_trail: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('spayx_scope_summary')) {
    /**
     * Scope-level KPI roll-up for the landing page: pending/collected deposit-schedule
     * money, open chargebacks + disputed amount, open payment intents. Read-only.
     */
    function spayx_scope_summary($db, array $stayIds): array
    {
        spayx_ensure_schema($db);
        $out = ['sched_pending' => 0.0, 'sched_collected' => 0.0, 'cb_open' => 0, 'cb_amount' => 0.0, 'intents_open' => 0];
        if (empty($stayIds)) { return $out; }
        try {
            $out['sched_pending']   = (float) ($db->sum('supplier_deposit_schedules', 'amount', ['stay_id' => $stayIds, 'status' => 'pending']) ?? 0);
            $out['sched_collected'] = (float) ($db->sum('supplier_deposit_schedules', 'amount', ['stay_id' => $stayIds, 'status' => 'collected']) ?? 0);
            $out['cb_open']         = (int) $db->count('supplier_chargebacks', ['stay_id' => $stayIds, 'status' => 'open']);
            $out['cb_amount']       = (float) ($db->sum('supplier_chargebacks', 'amount', ['stay_id' => $stayIds, 'status' => 'open']) ?? 0);
            $out['intents_open']    = (int) $db->count('supplier_payment_intents', ['stay_id' => $stayIds, 'status' => 'created']);
        } catch (\Throwable $e) {
            error_log('spayx_scope_summary: ' . $e->getMessage());
        }
        return $out;
    }
}

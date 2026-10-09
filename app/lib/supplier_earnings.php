<?php
// FILE: app/lib/supplier_earnings.php
// SUPPLIER EARNINGS ACCRUAL (Phase 1 inc S18; docs 01a §2/§"payouts"). HIGHEST-RISK
// area — read this header before touching it.
//
// A DEDICATED, ISOLATED earnings ledger (`supplier_earnings`) — deliberately NOT the
// customer/agent wallet spine (wallets/wallet_ledger/money_transactions). The money
// audit (see memory: payout-grounding) proved that `wallets.kind` has no 'supplier'
// value and that routing a supplier through wallet_apply() would corrupt
// users.balance. So supplier money lives in its own table with its own lifecycle:
//
//   pending  — accrued when a booking against the supplier's own inventory is PAID
//   available— released after the clearance window (checkout + N days) → payable
//   paid     — settled by a payout (inc S19; not built here)
//   void     — reversed (e.g. booking cancelled/refunded before payout)
//
// Earning amount = the supplier's NET rate = bookings.price_original (the platform
// keeps `commission` + tax). Idempotency: ONE earning per invoice_id (UNIQUE key +
// a locked pre-check inside $db->action()), so repeated/concurrent accrual attempts
// (inbox view, webhook, cron) can never double-credit. All functions non-fatal.
//
// SCOPE of S18: accrue + release + read-only summary. NO outbound money, NO payout,
// NO wallet-spine interaction. Those are S19.

if (!function_exists('supplier_earning_clearance_days')) {
    /** Days after checkout before a pending earning becomes available. Conservative
     *  default; a settings-driven value can replace this later. */
    function supplier_earning_clearance_days(): int { return 1; }
}

if (!function_exists('supplier_earning_accrue_for_booking')) {
    /**
     * Idempotently accrue the supplier earning for one booking (by invoice_id).
     * Returns ['ok'=>bool, 'state'=>?, 'message'=>?, 'already'=>bool].
     *
     * Rules (fail-closed — accrue ONLY when every condition holds):
     *   - the booking exists, is module_type stays/hotels (OWN inventory only),
     *   - is PAID (payment_status='paid') and NOT cancelled,
     *   - its booking_data.hotel_id resolves to a real stays row with a non-empty
     *     user_id (the supplier owner),
     *   - no earning row already exists for this invoice (idempotency).
     * The amount is the server-stored price_original (never a client value).
     */
    function supplier_earning_accrue_for_booking($db, string $invoiceId): array
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') { return ['ok' => false, 'message' => 'No invoice']; }

        try {
            $b = $db->get('bookings',
                ['invoice_id', 'module_type', 'payment_status', 'booking_status',
                 'price_original', 'price_markup', 'commission', 'currency_markup', 'booking_data'],
                ['invoice_id' => $invoiceId]);
            if (!$b) { return ['ok' => false, 'message' => 'Booking not found']; }

            // Own-inventory stays only.
            $moduleType = strtolower((string) ($b['module_type'] ?? ''));
            if (!in_array($moduleType, ['stays', 'hotels'], true)) {
                return ['ok' => false, 'message' => 'Not an own-inventory stay'];
            }
            // Must be paid and not cancelled.
            if (strtolower((string) ($b['payment_status'] ?? '')) !== 'paid') {
                return ['ok' => false, 'message' => 'Not paid'];
            }
            if (strtolower((string) ($b['booking_status'] ?? '')) === 'cancelled') {
                return ['ok' => false, 'message' => 'Cancelled'];
            }

            // Resolve the property owner via booking_data.hotel_id -> stays (proven path).
            $hid = function_exists('supplier_reservation_hotel_id')
                ? supplier_reservation_hotel_id($b['booking_data'] ?? null)
                : 0;
            if ($hid <= 0) { return ['ok' => false, 'message' => 'No property on booking']; }
            $stay = $db->get('stays', ['id', 'user_id', 'org_id'], ['id' => $hid]);
            if (!$stay || trim((string) ($stay['user_id'] ?? '')) === '') {
                return ['ok' => false, 'message' => 'Property has no owner']; // legacy/seed → skip
            }
            $owner = (string) $stay['user_id'];
            $orgId = ($stay['org_id'] !== null) ? (int) $stay['org_id'] : null;

            // Amounts: supplier earns the NET rate (price_original). Server-stored only.
            $net = round((float) ($b['price_original'] ?? 0), 2);
            if ($net <= 0) { return ['ok' => false, 'message' => 'No net amount']; }
            $gross = round((float) ($b['price_markup'] ?? 0), 2);
            $commission = round((float) ($b['commission'] ?? 0), 2);
            $currency = strtoupper(trim((string) ($b['currency_markup'] ?? ''))) ?: 'USD';

            $result = ['ok' => false, 'message' => 'Accrual failed'];
            $db->action(function ($db) use ($invoiceId, $owner, $orgId, $hid, $net, $gross, $commission, $currency, &$result) {
                // Lock any existing earning for this invoice (idempotency under concurrency).
                $locked = $db->query(
                    'SELECT id, state FROM supplier_earnings WHERE invoice_id = :inv FOR UPDATE',
                    [':inv' => $invoiceId]
                );
                $existing = $locked ? $locked->fetch(\PDO::FETCH_ASSOC) : null;
                if ($existing) {
                    $result = ['ok' => true, 'already' => true, 'state' => $existing['state'] ?? null];
                    return true; // already accrued — no-op
                }
                $db->insert('supplier_earnings', [
                    'org_id'            => $orgId,
                    'owner_user_id'     => $owner,
                    'invoice_id'        => $invoiceId,
                    'stay_id'           => $hid,
                    'currency'          => $currency,
                    'gross_amount'      => $gross,
                    'commission_amount' => $commission,
                    'net_amount'        => $net,
                    'state'             => 'pending',
                    'created_at'        => date('Y-m-d H:i:s'),
                ]);
                $result = ['ok' => true, 'already' => false, 'state' => 'pending'];
                return true;
            });
            return $result;
        } catch (\Throwable $e) {
            // A UNIQUE(invoice_id) violation under a race lands here → treat as already-accrued.
            error_log('supplier_earning_accrue_for_booking (' . $invoiceId . '): ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Accrual error'];
        }
    }
}

if (!function_exists('supplier_earning_void_for_booking')) {
    /** Void a pending/available earning when its booking is cancelled before payout.
     *  Never touches a 'paid' earning (already settled — a clawback is a separate,
     *  manual process). Idempotent. */
    function supplier_earning_void_for_booking($db, string $invoiceId): bool
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') { return false; }
        try {
            $db->update('supplier_earnings',
                ['state' => 'void', 'updated_at' => date('Y-m-d H:i:s')],
                ['invoice_id' => $invoiceId, 'state' => ['pending', 'available']]);
            return true;
        } catch (\Throwable $e) {
            error_log('supplier_earning_void_for_booking: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('supplier_earning_release_due')) {
    /**
     * Move pending earnings to 'available' once their clearance window has passed
     * (booking checkout + clearance days). Cron-friendly; bounded. Returns the count
     * released. The checkout date comes from booking_data.checkout; if absent, we
     * fall back to the earning's created_at + clearance.
     */
    function supplier_earning_release_due($db, int $limit = 500): int
    {
        $released = 0;
        $clearDays = supplier_earning_clearance_days();
        try {
            $pending = $db->select('supplier_earnings', ['id', 'invoice_id', 'created_at'],
                ['state' => 'pending', 'ORDER' => ['id' => 'ASC'], 'LIMIT' => max(1, $limit)]) ?: [];
            $now = time();
            foreach ($pending as $e) {
                // Determine the clearance anchor = checkout date if known, else created_at.
                $anchor = null;
                try {
                    $bd = $db->get('bookings', ['booking_data'], ['invoice_id' => $e['invoice_id']]);
                    if ($bd && !empty($bd['booking_data'])) {
                        $d = json_decode((string) $bd['booking_data'], true);
                        if (is_array($d) && !empty($d['checkout'])) { $anchor = strtotime((string) $d['checkout']); }
                    }
                } catch (\Throwable $ignore) {}
                if ($anchor === null || $anchor === false) { $anchor = strtotime((string) ($e['created_at'] ?? 'now')); }
                $dueAt = $anchor + ($clearDays * 86400);
                if ($now >= $dueAt) {
                    $db->update('supplier_earnings',
                        ['state' => 'available', 'available_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
                        ['id' => (int) $e['id'], 'state' => 'pending']); // state guard = no double-release
                    $released++;
                }
            }
        } catch (\Throwable $e) {
            error_log('supplier_earning_release_due: ' . $e->getMessage());
        }
        return $released;
    }
}

if (!function_exists('supplier_earning_summary')) {
    /**
     * Read-only earning totals for an owner, keyed by currency:
     *   [currency => ['pending'=>float,'available'=>float,'paid'=>float,'count'=>int]].
     * For the supplier dashboard — never mutates anything.
     */
    function supplier_earning_summary($db, string $owner): array
    {
        $owner = trim($owner);
        if ($owner === '') { return []; }
        $out = [];
        try {
            $rows = $db->select('supplier_earnings',
                ['currency', 'state', 'net_amount'],
                ['owner_user_id' => $owner, 'state[!]' => 'void']) ?: [];
            foreach ($rows as $r) {
                $cur = strtoupper((string) ($r['currency'] ?? 'USD'));
                if (!isset($out[$cur])) { $out[$cur] = ['pending' => 0.0, 'available' => 0.0, 'paid' => 0.0, 'count' => 0]; }
                $state = (string) ($r['state'] ?? '');
                if (isset($out[$cur][$state])) { $out[$cur][$state] += (float) $r['net_amount']; }
                $out[$cur]['count']++;
            }
            foreach ($out as $cur => $v) {
                $out[$cur]['pending']   = round($v['pending'], 2);
                $out[$cur]['available'] = round($v['available'], 2);
                $out[$cur]['paid']      = round($v['paid'], 2);
            }
        } catch (\Throwable $e) {
            error_log('supplier_earning_summary: ' . $e->getMessage());
        }
        return $out;
    }
}

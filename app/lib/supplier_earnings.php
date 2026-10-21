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
     *  default (1). When $db is given AND the admin has configured a per-currency
     *  hold in settings.supplier_payout_settings (via supplier_payouts_ext.php), that
     *  value is used; otherwise the default stands. Backward compatible: called with
     *  no args it still returns 1, so existing callers are unaffected. */
    function supplier_earning_clearance_days($db = null, string $currency = 'NGN'): int
    {
        if ($db !== null && function_exists('supplier_payout_rule')) {
            $v = supplier_payout_rule($db, $currency, 'hold_days', null);
            if ($v !== null && $v !== '' && is_numeric($v)) {
                $d = (int) $v;
                if ($d >= 0 && $d <= 365) { return $d; }
            }
        }
        return 1;
    }
}

if (!function_exists('supplier_earning_is_real_supplier')) {
    /**
     * TRUE only when $ownerUserId is a real supplier account (users.role = 'supplier').
     * This is the safety gate that keeps earnings from accruing to the admin-seeded
     * owner id (e.g. the demo user_id '1' on seed tours/cars, whose role is NOT
     * 'supplier'). Fail-closed: any doubt (missing row / read error) → false (no accrual).
     */
    function supplier_earning_is_real_supplier($db, string $ownerUserId): bool
    {
        $ownerUserId = trim($ownerUserId);
        if ($ownerUserId === '') { return false; }
        try {
            $u = $db->get('users', ['role'], ['user_id' => $ownerUserId]);
            return $u && strtolower((string) ($u['role'] ?? '')) === 'supplier';
        } catch (\Throwable $e) {
            error_log('supplier_earning_is_real_supplier: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('supplier_earning_resolve_owner')) {
    /**
     * Resolve the own-inventory OWNER for a PAID booking, per module. Returns
     *   ['owner'=>string, 'org_id'=>?int, 'listing_id'=>int, 'module'=>string]
     * or null when this booking is NOT own-inventory supplier revenue (aggregator
     * module, legacy/seed listing, or an owner that isn't a real supplier account).
     *
     * Supported own-inventory modules + their resolution path (all VERIFIED against
     * the schema + booking_data shapes):
     *   - stays/hotels : booking_data.hotel_id -> stays.id (user_id owner, org_id)
     *   - tours        : booking_data.tour_id  -> tours.id (user_id owner, supplier_id=org)
     *   - cars         : booking_data.car_data.id -> cars.id (user_id owner, no org)
     * Deliberately NOT supported (returns null):
     *   - flights      : aggregator-only (Duffel/Amadeus); no supplier ownership, no
     *                    local flights.id on the booking.
     *   - bus          : admin/operator-managed, NOT supplier-owned (verified: bus has
     *                    admin-only CRUD, bus.user_id is the creating admin, and bus
     *                    links to bus_operators which are not user accounts). Crediting
     *                    it would pay the admin, so bus is never accrued.
     * Every resolved owner is validated as a real role='supplier' account, so even a
     * listing with an admin/seed user_id (e.g. '' or '1') is skipped, not mis-credited.
     */
    function supplier_earning_resolve_owner($db, string $moduleType, $bookingData): ?array
    {
        $moduleType = strtolower(trim($moduleType));
        $bd = is_array($bookingData) ? $bookingData : json_decode((string) $bookingData, true);
        if (!is_array($bd)) { $bd = []; }

        if (in_array($moduleType, ['stays', 'hotels'], true)) {
            $hid = function_exists('supplier_reservation_hotel_id')
                ? (int) supplier_reservation_hotel_id($bookingData)
                : (int) ($bd['hotel_id'] ?? 0);
            if ($hid <= 0) { return null; }
            $row = $db->get('stays', ['id', 'user_id', 'org_id'], ['id' => $hid]);
            if (!$row || trim((string) ($row['user_id'] ?? '')) === '') { return null; }
            $owner = (string) $row['user_id'];
            if (!supplier_earning_is_real_supplier($db, $owner)) { return null; }
            return ['owner' => $owner, 'org_id' => ($row['org_id'] !== null ? (int) $row['org_id'] : null),
                    'listing_id' => $hid, 'module' => 'stays'];
        }

        if ($moduleType === 'tours') {
            $tid = (int) ($bd['tour_id'] ?? 0);
            if ($tid <= 0) { return null; }
            $row = $db->get('tours', ['id', 'user_id', 'supplier_id'], ['id' => $tid]);
            if (!$row || trim((string) ($row['user_id'] ?? '')) === '') { return null; }
            $owner = (string) $row['user_id'];
            if (!supplier_earning_is_real_supplier($db, $owner)) { return null; }
            return ['owner' => $owner, 'org_id' => ($row['supplier_id'] !== null ? (int) $row['supplier_id'] : null),
                    'listing_id' => $tid, 'module' => 'tours'];
        }

        if ($moduleType === 'cars') {
            // booking_data.car_data.id (nested) is the local cars.id.
            $cid = (int) ($bd['car_data']['id'] ?? ($bd['car_id'] ?? 0));
            if ($cid <= 0) { return null; }
            $row = $db->get('cars', ['id', 'user_id'], ['id' => $cid]); // cars has no org column
            if (!$row || trim((string) ($row['user_id'] ?? '')) === '') { return null; }
            $owner = (string) $row['user_id'];
            if (!supplier_earning_is_real_supplier($db, $owner)) { return null; }
            return ['owner' => $owner, 'org_id' => null, 'listing_id' => $cid, 'module' => 'cars'];
        }

        // flights, bus, and everything else → not own-inventory supplier revenue.
        return null;
    }
}

if (!function_exists('supplier_earning_accrue_for_booking')) {
    /**
     * Idempotently accrue the supplier earning for one booking (by invoice_id).
     * Returns ['ok'=>bool, 'state'=>?, 'message'=>?, 'already'=>bool].
     *
     * Rules (fail-closed — accrue ONLY when every condition holds):
     *   - the booking exists and is an OWN-INVENTORY supplier module whose listing
     *     resolves to a REAL supplier owner (see supplier_earning_resolve_owner):
     *     stays/hotels, tours, cars. Aggregator/unowned modules are skipped.
     *   - is PAID (payment_status='paid') and NOT cancelled,
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

            // Own-inventory modules only (stays/hotels, tours, cars). Must be paid and
            // not cancelled BEFORE we resolve ownership.
            $moduleType = strtolower((string) ($b['module_type'] ?? ''));
            if (strtolower((string) ($b['payment_status'] ?? '')) !== 'paid') {
                return ['ok' => false, 'message' => 'Not paid'];
            }
            if (strtolower((string) ($b['booking_status'] ?? '')) === 'cancelled') {
                return ['ok' => false, 'message' => 'Cancelled'];
            }

            // Resolve the own-inventory owner per module. Returns null for aggregator
            // modules (flights/bus) or any listing without a REAL supplier owner (incl.
            // the admin-seeded demo owner) — in which case we skip accrual entirely.
            $res = supplier_earning_resolve_owner($db, $moduleType, $b['booking_data'] ?? null);
            if ($res === null) {
                return ['ok' => false, 'message' => 'Not an own-inventory supplier booking'];
            }
            $owner = (string) $res['owner'];
            $orgId = $res['org_id'];
            // stay_id column is stays-specific; only set it for stays/hotels. tours/cars
            // store NULL (the listing is identified by the booking, not this column).
            $stayId = ($res['module'] === 'stays') ? (int) $res['listing_id'] : null;

            // Amounts: supplier earns the NET rate (price_original). Server-stored only.
            $net = round((float) ($b['price_original'] ?? 0), 2);
            if ($net <= 0) { return ['ok' => false, 'message' => 'No net amount']; }
            $gross = round((float) ($b['price_markup'] ?? 0), 2);
            $commission = round((float) ($b['commission'] ?? 0), 2);
            $currency = strtoupper(trim((string) ($b['currency_markup'] ?? ''))) ?: 'USD';

            $result = ['ok' => false, 'message' => 'Accrual failed'];
            $db->action(function ($db) use ($invoiceId, $owner, $orgId, $stayId, $net, $gross, $commission, $currency, &$result) {
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
                    'stay_id'           => $stayId,
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
     *  manual process). Idempotent.
     *
     *  CRITICAL guard: only voids earnings NOT already reserved for a payout
     *  (payout_id IS NULL). supplier_payout_request() stamps payout_id on an
     *  'available' earning while leaving its state 'available'; voiding such a row
     *  out from under a pending payout would let the payout's debit
     *  (WHERE payout_id=? AND state='available') match zero rows at transfer time —
     *  money would leave the float with no earning backing it. Cancelling a booking
     *  whose earning is already reserved therefore does NOT void it here; the covering
     *  payout must be rejected/settled first. Returns true iff a row was voided. */
    function supplier_earning_void_for_booking($db, string $invoiceId): bool
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') { return false; }
        try {
            $stmt = $db->update('supplier_earnings',
                ['state' => 'void', 'updated_at' => date('Y-m-d H:i:s')],
                ['invoice_id' => $invoiceId, 'state' => ['pending', 'available'], 'payout_id' => null]);
            return $stmt ? $stmt->rowCount() > 0 : false;
        } catch (\Throwable $e) {
            error_log('supplier_earning_void_for_booking: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('supplier_earning_release_for_invoice')) {
    /** Release a single booking's PENDING earning to 'available' immediately — the
     *  checkout trigger (inc S21). State-guarded (only pending→available), so a double
     *  checkout can't double-release, and a voided/paid earning is untouched. */
    function supplier_earning_release_for_invoice($db, string $invoiceId): bool
    {
        $invoiceId = trim($invoiceId);
        if ($invoiceId === '') { return false; }
        try {
            $db->update('supplier_earnings',
                ['state' => 'available', 'available_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')],
                ['invoice_id' => $invoiceId, 'state' => 'pending']);
            return true;
        } catch (\Throwable $e) {
            error_log('supplier_earning_release_for_invoice: ' . $e->getMessage());
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
        $clearCache = []; // currency => clearance days (admin-configurable, memoized)
        try {
            $pending = $db->select('supplier_earnings', ['id', 'invoice_id', 'currency', 'created_at'],
                ['state' => 'pending', 'ORDER' => ['id' => 'ASC'], 'LIMIT' => max(1, $limit)]) ?: [];
            $now = time();

            // PERF (audit #33): batch-fetch booking_data for ALL pending invoices in ONE
            // query, indexed by invoice_id, instead of a per-row bookings->get() inside
            // the loop (N+1 on the clearance-cron hot path).
            $bdByInvoice = [];
            $invIds = array_values(array_filter(array_map(fn($e) => (string) ($e['invoice_id'] ?? ''), $pending)));
            if ($invIds) {
                try {
                    $bRows = $db->select('bookings', ['invoice_id', 'booking_data'], ['invoice_id' => $invIds]) ?: [];
                    foreach ($bRows as $br) { $bdByInvoice[(string) $br['invoice_id']] = $br['booking_data'] ?? null; }
                } catch (\Throwable $ignore) {}
            }

            foreach ($pending as $e) {
                $cur = strtoupper((string) ($e['currency'] ?? 'NGN')) ?: 'NGN';
                if (!isset($clearCache[$cur])) { $clearCache[$cur] = supplier_earning_clearance_days($db, $cur); }
                $clearDays = $clearCache[$cur];
                // Determine the clearance anchor = checkout date if known, else created_at.
                $anchor = null;
                $bdRaw = $bdByInvoice[(string) ($e['invoice_id'] ?? '')] ?? null;
                if (!empty($bdRaw)) {
                    $d = json_decode((string) $bdRaw, true);
                    if (is_array($d) && !empty($d['checkout'])) { $anchor = strtotime((string) $d['checkout']); }
                }
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

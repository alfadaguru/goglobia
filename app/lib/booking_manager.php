<?php
// ============================================================================
// app/lib/booking_manager.php
// Shared foundations for the admin Booking Manager (flights + hotels):
//   • admin tier helpers (admin_level: 0 staff / 1 admin / 2 super-admin)
//   • bm_settle_booking_payment() — the ONE safe money primitive that records a
//     booking payment, stamps the gateway + txn, fires the GUARDED supplier
//     issue loopback for LIVE bookings (and skips it for manual/offline ones),
//     is idempotent, and NEVER credits a wallet/credits ledger.
//
// Why a primitive: the web paid->issue logic lives inline inside the 900-line
// handle_payment_callback() and is only reachable with a session payment token.
// The admin flow has no such token, and the existing admin "mark_paid" only flips
// the flag (it does NOT issue). This primitive is the single, audited place that
// gets admin bookings paid + issued correctly, so no screen re-implements it.
// ============================================================================

if (!function_exists('bm_current_admin_level')) {
    /**
     * Admin tier of the logged-in user, read fresh from the DB by user_id (the
     * session stores user_role but not the level, and a level change must take
     * effect without re-login). Returns 0 for a non-admin / unknown user.
     *   0 = staff, 1 = admin, 2 = super-admin.
     */
    function bm_current_admin_level($db): int
    {
        if (($_SESSION['user_role'] ?? '') !== 'admin') return 0;
        $uid = $_SESSION['user_id'] ?? '';
        if ($uid === '') return 0;
        try {
            $row = $db->get('users', ['admin_level'], ['user_id' => $uid]);
            return $row ? max(0, min(2, (int) ($row['admin_level'] ?? 0))) : 0;
        } catch (\Throwable $e) {
            // Column may not exist yet on an un-migrated DB — treat as staff.
            error_log('bm_current_admin_level: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('bm_is_senior_admin')) {
    /**
     * True when the logged-in admin may use the senior-only features — seeing the
     * company manual-bank-transfer account and verifying manual payments. Level
     * >= 1 (admin or super-admin). A level-0 staffer can still create bookings and
     * take DVA payment, but a manual-transfer booking they make lands in
     * 'awaiting_verification' for a senior admin to confirm.
     */
    function bm_is_senior_admin($db): bool
    {
        return bm_current_admin_level($db) >= 1;
    }
}

if (!function_exists('bm_module_has_issue_endpoint')) {
    /**
     * True when (module_type, module) names a real supplier with an issue action
     * on disk — i.e. a LIVE booking that must be issued at the supplier. A
     * manual/offline booking uses a sentinel module ('manual'/'offline'/'') with
     * no such file, so this returns false and the issue step is skipped. This is
     * the exact signal the gateway issue-gate keys on (payment-gateway.php).
     */
    function bm_module_has_issue_endpoint(string $moduleType, string $module): bool
    {
        $moduleType = strtolower(trim($moduleType));
        $module     = strtolower(trim($module));
        if ($moduleType === '' || $module === '') return false;
        if (in_array($module, ['manual', 'offline', 'flight', 'flights', 'hotels', 'stays', 'visas', ''], true)) {
            // sentinels + the bare service names (which are not real suppliers)
            // never have an issue endpoint of their own.
            return false;
        }
        // Suppliers put issue.php in one of two places: most under /actions/issue.php
        // (flights/stays/tours/umrah/rail/ferries), but cars (mozio/cartrawler) and
        // esim (airalo) register it at the module ROOT (/issue.php). Detect BOTH, or
        // a live cars/esim booking would be mis-flagged as non-issuable and get
        // confirmed WITHOUT ever booking at the supplier (money owed, nothing bought).
        $root = dirname(__DIR__, 2) . '/modules/' . $moduleType . '/' . $module;
        return is_file($root . '/actions/issue.php') || is_file($root . '/issue.php');
    }
}

if (!function_exists('bm_fire_supplier_issue')) {
    /**
     * Fire the GUARDED supplier issue loopback for a live booking — the exact
     * mechanism the payment gateway uses after a web payment (HTTP POST to
     * root/modules/{type}/{module}/issue with the invoice_id + a signed
     * _internal_token so the module's supplier_action_guard accepts it). Returns
     * the decoded module response, or ['status'=>false,'message'=>...] on transport
     * failure. The module's own issue.php owns idempotency (pnr + issue_claimed_at)
     * and post-payment price reconciliation, so a retry here is safe.
     */
    function bm_fire_supplier_issue($db, string $invoiceId, string $moduleType, string $module, int $timeout = 300): array
    {
        $url = rtrim(root, '/') . '/modules/' . $moduleType . '/' . $module . '/issue';
        $token = function_exists('supplier_internal_token') ? supplier_internal_token($invoiceId) : '';
        $post = ['invoice_id' => $invoiceId, '_internal_token' => $token];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($post),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false, // internal loopback to our own host
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err || $code === 0) {
            return ['status' => false, 'message' => 'Could not reach the issue service: ' . ($err ?: 'no response'), 'http' => $code];
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json)) {
            return ['status' => false, 'message' => 'Invalid issue response', 'http' => $code, 'raw' => substr((string) $raw, 0, 500)];
        }
        return $json;
    }
}

if (!function_exists('bm_settle_booking_payment')) {
    /**
     * Mark an admin-created booking PAID and (for a live supplier booking) fire the
     * supplier issue — the single safe money path for the Booking Manager.
     *
     * $method:
     *   'dva'            — paid into a per-booking Paystack DVA (already confirmed
     *                      by the webhook; here we just finalise booking + issue).
     *   'manual_verified'— a manual bank transfer an admin has VERIFIED received.
     *   'offline'        — a manual/offline (booked-elsewhere) record; money handled
     *                      outside; mark paid + confirmed, NEVER call a supplier.
     * $meta: ['transaction_id'=>, 'gateway_id'=>, 'gateway_name'=>, 'verified_by'=>, 'amount'=>, 'currency'=>]
     *
     * Money-safety invariants enforced here (from the deep audit):
     *   - NEVER touch a wallet/credits ledger (a booking payment funds the booking
     *     directly, not a spendable balance).
     *   - Stamp payment_gateway + transaction_id so a later auto-refund can find
     *     the gateway.
     *   - Idempotent: a booking already 'paid' (or with a PNR) is a no-op success.
     *   - Issue fires ONLY through the guarded loopback, ONLY for a live module
     *     that has an issue endpoint; manual/offline is confirmed without a
     *     supplier call.
     *
     * @return array ['ok'=>bool, 'issued'=>bool, 'status'=>booking_status, 'message'=>..., 'pnr'=>?]
     */
    function bm_settle_booking_payment($db, string $invoiceId, string $method, array $meta = []): array
    {
        // record_transaction + refund helpers live in payment-gateway.php, which is
        // NOT in config's global require list — load it on demand (idempotent).
        if (!function_exists('record_transaction')) {
            $pg = dirname(__DIR__) . '/lib/payment-gateway.php';
            if (is_file($pg)) { require_once $pg; }
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) return ['ok' => false, 'message' => 'Booking not found'];

        if (in_array(strtolower((string) ($booking['booking_status'] ?? '')), ['cancelled', 'voided'], true)) {
            return ['ok' => false, 'message' => 'Booking is ' . $booking['booking_status'] . ' and cannot be settled.'];
        }

        $moduleType = (string) ($booking['module_type'] ?? '');
        $module     = (string) ($booking['module'] ?? '');
        $isLive     = bm_module_has_issue_endpoint($moduleType, $module);
        $alreadyPaid = strtolower((string) ($booking['payment_status'] ?? '')) === 'paid';

        // Idempotency. Already paid is a no-op EXCEPT for the one case that must
        // still act: a LIVE supplier booking that was marked paid but never issued
        // (no PNR) — e.g. the DVA webhook stamped it paid then delegated here for
        // issue. In that case we skip the re-payment write/audit (already recorded)
        // and fall through to the issue loopback below (recovery). A paid booking
        // that is non-live, or already has a PNR, is a clean no-op — which also
        // makes a webhook-after-manual-verify (or vice-versa) a safe no second
        // charge / no second audit row / no second issue.
        $needsIssueOnly = $alreadyPaid && $isLive && empty($booking['pnr']) && $method !== 'offline';
        if ($alreadyPaid && !$needsIssueOnly) {
            return ['ok' => true, 'issued' => !empty($booking['pnr']), 'status' => $booking['booking_status'] ?? 'confirmed',
                    'pnr' => $booking['pnr'] ?? '', 'message' => 'Booking is already paid.'];
        }

        // 1. Record the payment on the booking (NO wallet/credits write). Stamp the
        //    gateway + txn so a later refund can locate the gateway. Skipped when we
        //    are only recovering the issue of an already-paid booking.
        if (!$needsIssueOnly) {
            // Preserve an existing dedup/reference when no new txn ref is supplied —
            // an empty meta['transaction_id'] must NOT blank the stored value (which
            // may be the 'BMCT-<client_token>' create-dedup key).
            $newTxn = isset($meta['transaction_id']) && $meta['transaction_id'] !== ''
                ? (string) $meta['transaction_id']
                : (string) ($booking['transaction_id'] ?? '');
            $paid = [
                'payment_status'      => 'paid',
                'paid_at'             => date('Y-m-d H:i:s'),
                'transaction_id'      => $newTxn,
                'payment_verified_by' => $meta['verified_by'] ?? ($booking['payment_verified_by'] ?? null),
                'payment_verified_at' => date('Y-m-d H:i:s'),
            ];
            if (!empty($meta['gateway_id']))  { $paid['payment_gateway'] = (string) $meta['gateway_id']; }
            $db->update('bookings', $paid, ['invoice_id' => $invoiceId]);
        }

        // 2. Audit row on the money spine. Best effort — never let an audit-write
        //    failure strand a paid booking. Skipped on the issue-only recovery path
        //    (the payment was already audited when it was first recorded), so a
        //    DVA-webhook -> delegate here never writes a second transactions row.
        if (!$needsIssueOnly && function_exists('record_transaction')) {
            try {
                record_transaction([
                    'invoice_id'   => $invoiceId,
                    'amount'       => (float) ($meta['amount'] ?? ($booking['price_markup'] ?? 0)),
                    'currency'     => (string) ($meta['currency'] ?? ($booking['currency_markup'] ?? 'NGN')),
                    'user_id'      => $booking['user_id'] ?? null,
                    'gateway_id'   => $meta['gateway_id'] ?? null,
                    'gateway_name' => $meta['gateway_name'] ?? ($method === 'dva' ? 'Paystack' : 'manual_payment'),
                    'booking_id'   => $booking['id'] ?? null,
                    'client_email' => $booking['email'] ?? null,
                ], (string) ($meta['transaction_id'] ?? ''), 'success', ['source' => 'booking_manager', 'method' => $method]);
            } catch (\Throwable $e) { error_log('bm_settle record_transaction: ' . $e->getMessage()); }
        }

        // 3a. MANUAL / OFFLINE booking (no supplier) → confirm without any issue call.
        if (!$isLive || $method === 'offline') {
            $db->update('bookings', ['booking_status' => 'confirmed'], ['invoice_id' => $invoiceId]);
            bm_notify_paid($db, $invoiceId);
            return ['ok' => true, 'issued' => false, 'status' => 'confirmed', 'pnr' => $booking['pnr'] ?? '',
                    'message' => 'Booking confirmed (manual/offline — no supplier issue).'];
        }

        // 3b. LIVE booking → fire the guarded supplier issue loopback.
        $res = bm_fire_supplier_issue($db, $invoiceId, $moduleType, $module);
        $issuedOk = !empty($res['status']) || !empty($res['success']);

        if ($issuedOk) {
            // issue.php already wrote pnr + booking_status; re-read for the result.
            $after = $db->get('bookings', ['pnr', 'booking_status'], ['invoice_id' => $invoiceId]);
            bm_notify_paid($db, $invoiceId);
            return ['ok' => true, 'issued' => true, 'status' => $after['booking_status'] ?? 'confirmed',
                    'pnr' => $after['pnr'] ?? '', 'message' => 'Paid and issued at the supplier.'];
        }

        // Issue failed AFTER payment. The module's issue.php records the error; the
        // booking is paid-but-not-issued and must be surfaced for ops (the module's
        // own _auto_refund_on_issue_failure, where present, handles the refund). We
        // do NOT flip to confirmed.
        return ['ok' => false, 'issued' => false, 'status' => $booking['booking_status'] ?? 'pending',
                'message' => 'Payment recorded but the supplier could not issue: ' . (string) ($res['message'] ?? 'unknown') . ' — flagged for review.',
                'issue_response' => $res];
    }
}

if (!function_exists('bm_notify_paid')) {
    /** Fire the paid notification + generate the branded PDF, once, best effort. */
    function bm_notify_paid($db, string $invoiceId): void
    {
        try {
            $b = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
            if (!$b) return;
            $pdf = function_exists('GENERATE_BOOKING_PDF') ? GENERATE_BOOKING_PDF($invoiceId) : null;
            if (class_exists('NOTIFY')) {
                // NOTIFY::payment resolves the email template from its first arg as
                // emails/{arg}/booking.php, so it MUST be the SERVICE name
                // (flights/stays), NOT the module column — which here is a supplier
                // ('duffel') or the sentinel 'manual', neither of which has a
                // template dir, so the customer email + PDF would be silently
                // skipped. Derive the service from module_type (NOTIFY maps
                // hotels->stays itself, but module_type is already 'stays').
                $svc = strtolower((string) ($b['module_type'] ?? 'flights'));
                if ($svc === '' || $svc === 'manual' || $svc === 'offline') { $svc = 'flights'; }
                NOTIFY::payment($svc, $b, [
                    'invoice_id'     => $invoiceId,
                    'amount'         => $b['price_markup'] ?? 0,
                    'currency'       => $b['currency_markup'] ?? '',
                    'payment_status' => 'paid',
                    'module_type'    => ucfirst((string) ($b['module_type'] ?? '')),
                ], $pdf);
            }
        } catch (\Throwable $e) { error_log('bm_notify_paid: ' . $e->getMessage()); }
    }
}

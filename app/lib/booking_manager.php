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
        // A genuine role='admin' IS an admin (senior) — floor the level at 1. The
        // admin_level column (default 0) only ELEVATES to super-admin (2); it must
        // never DEMOTE a real admin below senior. Without this floor, the main
        // admin (admin_level=0 on every existing install) was treated as "staff"
        // and the Payment Verification queue + company-bank features were hidden
        // from them — so a manual-transfer booking parked in awaiting_verification
        // was invisible to everyone. (Staff proper are role='employee' → return 0
        // at the guard above, so this does not over-promote them.)
        try {
            $row = $db->get('users', ['admin_level'], ['user_id' => $uid]);
            if (!$row) return 1;                       // admin session, row missing → still admin
            $lvl = max(0, min(2, (int) ($row['admin_level'] ?? 0)));
            return max(1, $lvl);                        // admin floor = 1 (senior)
        } catch (\Throwable $e) {
            // Column may not exist yet on an un-migrated DB — a real admin is still
            // senior (don't lock admins out of verification on an un-migrated DB).
            error_log('bm_current_admin_level: ' . $e->getMessage());
            return 1;
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

if (!function_exists('bm_flight_offer_has_handle')) {
    /**
     * True when a LIVE-flight offer card carries a usable supplier booking handle,
     * so the issue loopback can actually book it AFTER the customer has paid.
     *
     * WHY: flights were the one live service whose create path accepted ANY
     * non-empty offer blob (bookingManagerRoutes.php, the generic live branch)
     * while hotels/tours/cars/esim each required a concrete handle. A flight offer
     * with no handle settles payment then hard-fails at issue ("no offer id") →
     * paid-but-unissued / stranded. This gate rejects that at create time.
     *
     * The handle is NOT one universal key — it is supplier-specific. Every bookable
     * flight supplier's search.php emits its handle INSIDE the offer card's
     * `booking_data`, VERIFIED (search.php → issue.php):
     *   duffel           booking_data.booking_token         (search:353  / issue:170-184)
     *   adivaha          booking_data.booking_token+trace_id(search:415-417 / issue:172-175)
     *   amadeus          booking_data.key                   (search:420  / issue:173-183)
     *   amadeus_enterprise booking_data.key                 (search:653  / issue:111-115)
     *   googleflights    booking_data.booking_token         (search:262  / issue:43)
     *   kiwi             booking_data.booking_token+session_id(search:456 / issue:136-141)
     *   mystifly         booking_data.fare_source_code/token(search:324-326 / issue:98-106)
     *   pkfare           booking_data.solutionId            (search:293  / issue:160)
     *   sabre            booking_data.booking_token/offer_id(search:519-520 / issue:168,278)
     *   seeru            booking_data.flight                (search:218  / issue:123)
     *   tbo              booking_data.ResultId + TokenId    (search:380-382 / issue:75-82)
     *   tiqwa            booking_data.booking_token         (search:252  / issue:131)
     *   travelport       booking_data.session_id            (search:748-751 / issue:59-74)
     * So the safe, non-breaking rule: the offer card must carry a `booking_data`
     * sub-array containing at least one of these known handle keys. This accepts
     * EVERY real supplier (no false reject) and rejects only a handle-less card.
     *
     * $offer is the offer card the console sends as offer_booking_data (= the
     * module search result's raw offer); its handle lives under $offer['booking_data'].
     */
    function bm_flight_offer_has_handle($offer): bool
    {
        if (!is_array($offer)) return false;
        // The known handle keys across all bookable flight suppliers (verified above).
        $handleKeys = ['booking_token', 'key', 'solutionId', 'session_id', 'ResultId',
                       'offer_id', 'fare_source_code', 'trace_id', 'flight_id', 'flight'];
        // Look where every supplier actually puts it (booking_data), and also at the
        // card top level + a flight_data.booking_data nesting, so a supplier that
        // flattens the card still passes.
        $candidates = [];
        if (isset($offer['booking_data']) && is_array($offer['booking_data'])) { $candidates[] = $offer['booking_data']; }
        if (isset($offer['flight_data']['booking_data']) && is_array($offer['flight_data']['booking_data'])) { $candidates[] = $offer['flight_data']['booking_data']; }
        $candidates[] = $offer; // top-level fallback
        foreach ($candidates as $bag) {
            foreach ($handleKeys as $k) {
                if (isset($bag[$k]) && $bag[$k] !== '' && $bag[$k] !== null
                    && !(is_array($bag[$k]) && count($bag[$k]) === 0)) {
                    return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists('bm_flight_is_international')) {
    /**
     * Audit finding M1 (2026-11-11): a LIVE flight could be created + PAID with no
     * passport, even when the route is INTERNATIONAL — then hard-fail at the
     * supplier issue AFTER payment (stranded). The console's "International trip"
     * checkbox was MANUAL (nothing set it from the actual route) and even then the
     * passport field was optional. This resolves "is this route international?"
     * authoritatively, server-side, from the origin/destination IATA codes by
     * looking up each airport's country in flights_airports.
     *
     * Returns:
     *   true  — origin + destination resolve to DIFFERENT countries (international)
     *   false — same country (domestic)
     *   null  — cannot determine (codes missing or not found) → caller must not
     *           hard-block on an UNKNOWN (fail-open on detection, honest about it);
     *           it falls back to the explicit UI hint.
     *
     * $origin / $destination are IATA codes (airport or city). The offer's
     * departure_code / arrival_code are used when explicit codes aren't passed.
     */
    function bm_flight_is_international($db, string $origin, string $destination, array $offer = []): ?bool
    {
        $origin      = strtoupper(trim($origin));
        $destination = strtoupper(trim($destination));
        // Fall back to the offer's own codes when the form didn't carry them.
        if ($origin === '' || $destination === '') {
            $bd = (isset($offer['flight_data']) && is_array($offer['flight_data'])) ? $offer['flight_data'] : $offer;
            if ($origin === '')      { $origin      = strtoupper(trim((string) ($bd['departure_code'] ?? $offer['departure_code'] ?? ''))); }
            if ($destination === '') { $destination = strtoupper(trim((string) ($bd['arrival_code']   ?? $offer['arrival_code']   ?? ''))); }
        }
        if ($origin === '' || $destination === '') return null;

        $countryOf = function (string $code) use ($db): ?string {
            if ($code === '') return null;
            try {
                // Match the airport IATA code OR the city code column, whichever exists.
                $row = $db->get('flights_airports', ['country'], ['code' => $code]);
                $c = $row['country'] ?? '';
                return $c !== '' ? strtolower(trim($c)) : null;
            } catch (\Throwable $e) { return null; }
        };
        $co = $countryOf($origin);
        $cd = $countryOf($destination);
        if ($co === null || $cd === null) return null; // unknown → let caller fall back to the hint
        return $co !== $cd;
    }
}

if (!function_exists('bm_flight_passengers_missing_passport')) {
    /**
     * Audit finding M1: for an international live flight every PASSENGER who needs
     * a travel document (adult + child; infants travel on a parent doc for many
     * carriers but most suppliers still want one — we require adults & children and
     * treat infants as recommended-not-required to avoid false blocks) must carry a
     * passport number. Returns the list of passenger labels MISSING a passport, so
     * the caller can reject with a precise message. $travellers is the assembled
     * adult_N/child_N/infant_N map (primary_guest excluded).
     */
    function bm_flight_passengers_missing_passport(array $travellers): array
    {
        $missing = [];
        foreach ($travellers as $key => $p) {
            if ($key === 'primary_guest' || !is_array($p)) continue;
            // infants: recommended but not hard-required (parent-doc carriers).
            if (strpos((string) $key, 'infant_') === 0) continue;
            $passport = trim((string) ($p['passport_number'] ?? ''));
            if ($passport === '') {
                $name = trim((string) ($p['first_name'] ?? '') . ' ' . (string) ($p['last_name'] ?? ''));
                $missing[] = $name !== '' ? $name : $key;
            }
        }
        return $missing;
    }
}

if (!function_exists('bm_module_is_issue_stub')) {
    /**
     * True when a supplier's issue.php exists but is a NON-functional stub (an empty
     * TODO placeholder), so a LIVE booking against it would settle payment then do
     * nothing (never write a PNR) → stranded. bm_module_has_issue_endpoint() only
     * checks the FILE EXISTS; this catches the file-exists-but-does-nothing case.
     *
     * VERIFIED stub: modules/flights/travelpayouts/actions/issue.php is 7 lines,
     * body "// TODO: Implement issue action logic" (it is an affiliate/redirect
     * supplier — its search.php emits redirect_url, not a bookable handle). Detect
     * generically (a tiny issue.php that never does the supplier booking work) so a
     * future stub is caught too, without hardcoding one supplier name.
     *
     * ALSO catches the FAKE-ISSUE / AFFILIATE-REDIRECT pattern (audit finding H1,
     * 2026-11-11): modules/flights/googleflights/actions/issue.php is NOT a TODO
     * stub — it *writes* booking_status='confirmed' + a fabricated md5 PNR and
     * returns status:true WITHOUT ever calling the supplier, then hands back a
     * redirect_url telling the customer to "complete the purchase" on the airline
     * site. Because it contains the strings "'pnr'"/"booking_status", the old
     * looksReal heuristic classified it as a real supplier, so a BM LIVE booking
     * there would settle payment and email a fake-PNR ticket for a flight that was
     * never booked. We now additionally treat an issue.php that (a) makes NO
     * outbound supplier HTTP call AND (b) returns a redirect_url / tells the
     * customer to complete the purchase elsewhere as a non-bookable stub — it
     * cannot actually issue a ticket, so it must not be live-bookable.
     */
    function bm_module_is_issue_stub(string $moduleType, string $module): bool
    {
        $moduleType = strtolower(trim($moduleType));
        $module     = strtolower(trim($module));
        if ($moduleType === '' || $module === '') return false;
        $base = dirname(__DIR__, 2) . '/modules/' . $moduleType . '/' . $module;
        $file = is_file($base . '/actions/issue.php') ? $base . '/actions/issue.php'
              : (is_file($base . '/issue.php') ? $base . '/issue.php' : '');
        if ($file === '') return false;
        $src = (string) @file_get_contents($file);
        if ($src === '') return true; // unreadable/empty → treat as non-functional
        // DISPATCHER PATTERN: a real supplier may keep its booking logic in a lib
        // the issue.php require()s (e.g. modules/cars/mozio/issue.php delegates to
        // lib.php's mozioIssueReservation, which does the real cURL booking). The
        // thin issue.php then has no curl/pnr/booking_status of its own and would be
        // mis-flagged as a stub. So fold in the source of any local PHP file this
        // issue.php requires (same module dir only — never follow arbitrary paths),
        // so "does it book?" is judged on the WHOLE handler, not just the entry file.
        if (preg_match_all('/(?:require|include)(?:_once)?\s*[^;]*?[\'"]([^\'"]+\.php)[\'"]/i', $src, $mm)) {
            foreach ($mm[1] as $rel) {
                $cand = @realpath(dirname($file) . '/' . $rel);
                // Only pull in files that live under THIS module's directory.
                if ($cand && strpos($cand, @realpath($base) . DIRECTORY_SEPARATOR) === 0 && is_file($cand)) {
                    $src .= "\n" . (string) @file_get_contents($cand);
                }
            }
        }
        $lc  = strtolower($src);

        // Does it actually CALL the supplier? (a real issue books over HTTP.)
        $makesSupplierCall = (stripos($src, 'curl_') !== false)
                  || (stripos($src, 'file_get_contents(') !== false && stripos($lc, 'http') !== false)
                  || (stripos($src, 'stream_context_create') !== false)
                  || (stripos($src, '->request(') !== false)   // Guzzle/HTTP client
                  || (stripos($lc, 'fsockopen') !== false);

        // Is it an AFFILIATE REDIRECT that only sends the customer elsewhere?
        // (returns a redirect_url and/or says "complete the purchase"/"no booking
        //  api" — i.e. it never issues a real ticket itself).
        $isRedirectAffiliate = (stripos($src, 'redirect_url') !== false)
                  || (stripos($lc, 'complete the purchase') !== false)
                  || (stripos($lc, 'no booking api') !== false)
                  || (stripos($lc, 'does not provide a direct booking') !== false)
                  || (stripos($lc, 'no direct booking') !== false);

        // A real issue handler books at the supplier: it will make a supplier HTTP
        // call AND/OR write a PNR/booking_status. A pure TODO stub has neither.
        $looksReal = $makesSupplierCall
                  || (stripos($src, "'pnr'") !== false)
                  || (stripos($src, 'booking_status') !== false);

        // Fake-issue override: even if it writes pnr/booking_status, a provider that
        // makes NO supplier call AND is a redirect affiliate cannot truly issue →
        // treat as a (non-bookable) stub so BM live rejects it.
        if (!$makesSupplierCall && $isRedirectAffiliate) {
            return true;
        }

        return !$looksReal;
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

        // DEFERRED NOTIFY. bm_notify_paid generates a PDF (mPDF) and sends email +
        // (optionally) WhatsApp/SMS — all SYNCHRONOUS outbound work that can add many
        // seconds to the request. An INTERACTIVE caller (the one-click /pay, a browser
        // waiting on the socket) sets $meta['defer_notify'] so we SKIP the inline send
        // and instead return notify_due=true; the caller flushes the HTTP response to
        // the browser FIRST, then runs bm_notify_paid after the socket closes. Every
        // other caller (crons, callbacks) omits the flag and notifies inline as before.
        $deferNotify = !empty($meta['defer_notify']);

        // 3a. MANUAL / OFFLINE booking (no supplier) → confirm without any issue call.
        if (!$isLive || $method === 'offline') {
            $db->update('bookings', ['booking_status' => 'confirmed'], ['invoice_id' => $invoiceId]);
            if ($deferNotify) {
                return ['ok' => true, 'issued' => false, 'notify_due' => true, 'status' => 'confirmed', 'pnr' => $booking['pnr'] ?? '',
                        'message' => 'Booking confirmed (manual/offline — no supplier issue).'];
            }
            bm_notify_paid($db, $invoiceId);
            return ['ok' => true, 'issued' => false, 'status' => 'confirmed', 'pnr' => $booking['pnr'] ?? '',
                    'message' => 'Booking confirmed (manual/offline — no supplier issue).'];
        }

        // 3b. LIVE booking → fire the guarded supplier issue loopback.
        // The INTERACTIVE one-click path (a browser waiting on this request) passes a
        // tighter issue_timeout so the server gives up roughly when the client does,
        // instead of holding the socket for the full 300s default. Background callers
        // (the stranded cron) omit it and keep the generous ceiling — a long wait is
        // fine there because nobody is watching a spinner. Clamp to a sane range.
        $issueTimeout = 300;
        if (isset($meta['issue_timeout']) && (int) $meta['issue_timeout'] > 0) {
            $issueTimeout = max(15, min(300, (int) $meta['issue_timeout']));
        }
        $res = bm_fire_supplier_issue($db, $invoiceId, $moduleType, $module, $issueTimeout);
        $callOk = !empty($res['status']) || !empty($res['success']);

        if ($callOk) {
            // issue.php already wrote pnr + booking_status; re-read the AUTHORITATIVE
            // state. CRITICAL: a supplier returns status:true even when the ticket is
            // only ON-HOLD / awaiting airline confirmation (Adivaha does this so the
            // payment gateway does NOT auto-refund). "status:true" ≠ "ticketed", so we
            // must NOT claim issued / send a ticket until the booking is really
            // CONFIRMED with a real PNR. Otherwise the customer gets a "ticket issued"
            // notice (and a bogus PNR) for a flight that was never ticketed.
            $after    = $db->get('bookings', ['pnr', 'booking_status'], ['invoice_id' => $invoiceId]);
            $afterStat = strtolower((string) ($after['booking_status'] ?? ''));
            $realPnr  = trim((string) ($after['pnr'] ?? ''));
            $supplierSaysTicketed = !empty($res['is_ticketed'])
                || strtolower((string) ($res['booking_status'] ?? '')) === 'confirmed';
            $trulyIssued = ($afterStat === 'confirmed') && ($realPnr !== '') && $supplierSaysTicketed;

            if ($trulyIssued) {
                if ($deferNotify) {
                    return ['ok' => true, 'issued' => true, 'notify_due' => true, 'status' => $afterStat,
                            'pnr' => $realPnr, 'message' => 'Paid and issued at the supplier.'];
                }
                bm_notify_paid($db, $invoiceId);  // real ticket → send it
                return ['ok' => true, 'issued' => true, 'status' => $afterStat,
                        'pnr' => $realPnr, 'message' => 'Paid and issued at the supplier.'];
            }

            // Paid + supplier accepted, but NOT yet ticketed (on-hold / pending /
            // held-for-review). Do NOT notify a ticket. Leave it pending so it shows
            // in the ops/verify "needs attention" view; the admin can re-check the
            // supplier (retry-issue re-polls) until the airline confirms the PNR.
            return ['ok' => true, 'issued' => false, 'pending_issue' => true,
                    'status' => $afterStat ?: 'pending', 'pnr' => $realPnr,
                    'message' => trim((string) ($res['message'] ?? ''))
                        ?: 'Paid. The supplier has the booking on hold — not yet ticketed. Re-check / retry issue to confirm the airline PNR before sending the ticket.'];
        }

        // Issue failed AFTER payment → paid-but-not-issued. Whether to auto-refund
        // now depends on the CALLER's intent, because bm_settle is used both for the
        // automatic RETRY path (the stranded cron / a transient glitch — must NOT
        // refund, or a later retry could never complete the ticket) and for a human
        // GIVING UP on a booking. The caller passes meta['refund_on_fail']=true when
        // a permanent failure should be refunded (e.g. a senior's explicit "refund"
        // decision). The cron does NOT set it, so transient failures keep retrying.
        // When set, _auto_refund_on_issue_failure is idempotent + GATEWAY-ONLY (skips
        // internal wallet/credits, installments, umrah) — a wallet-paid agent booking
        // is untouched here and still recovered by _agent_issue_with_recovery.
        $__refunded = false;
        if (!empty($meta['refund_on_fail']) && (string) ($method ?? '') !== 'offline') {
            try {
                if (!function_exists('_auto_refund_on_issue_failure')) {
                    $pg = dirname(__DIR__) . '/lib/payment-gateway.php';
                    if (is_file($pg)) { require_once $pg; }
                }
                if (function_exists('_auto_refund_on_issue_failure')) {
                    _auto_refund_on_issue_failure(
                        $db,
                        ['invoice_id' => $invoiceId],
                        $db->get('bookings', '*', ['invoice_id' => $invoiceId]) ?: $booking,
                        (string) ($res['message'] ?? 'supplier issue failed')
                    );
                    $__chk = $db->get('bookings', ['payment_status'], ['invoice_id' => $invoiceId]);
                    $__refunded = $__chk && strtolower((string) ($__chk['payment_status'] ?? '')) === 'refunded';
                }
            } catch (\Throwable $e) { error_log('bm_settle auto-refund ' . $invoiceId . ': ' . $e->getMessage()); }
        }
        // CUSTOMER NOTIFY ON ISSUE-FAIL (Fix 4): previously a paid LIVE booking whose
        // supplier issue failed went SILENT — the customer paid and heard nothing.
        //   - refunded → a brief "we refunded, couldn't confirm" note (handled by the
        //     refund path's own notify where present; we only log here to avoid a
        //     wrong 'ticket coming' message on a refunded booking).
        //   - NOT refunded (paid, retrying/flagged) → reuse the idempotent
        //     pending-verification notice: "payment received, your ticket/confirmation
        //     is being finalized" — NO ticket/PDF, NO 'confirmed' claim. Idempotent
        //     (pending_notified_at), so the stranded-issue cron retrying this path
        //     every few minutes sends it at most once.
        // INTERACTIVE path defers this notice too (it does SMTP + WhatsApp HTTP): signal
        // notify_pending_due and let the route run it AFTER the response flushes, so the
        // operator isn't held on the failure branch either. Background callers (no flag)
        // notify inline exactly as before.
        $__notifyPendingDue = false;
        if (!$__refunded && function_exists('bm_notify_pending_verification')) {
            if ($deferNotify) {
                $__notifyPendingDue = true;
            } else {
                try { bm_notify_pending_verification($db, $invoiceId); } catch (\Throwable $e) { error_log('bm_settle issue-fail notify ' . $invoiceId . ': ' . $e->getMessage()); }
            }
        }
        return ['ok' => false, 'issued' => false, 'refunded' => $__refunded,
                'notify_pending_due' => $__notifyPendingDue,
                'status' => $booking['booking_status'] ?? 'pending',
                'message' => $__refunded
                    ? ('Payment recorded but the supplier could not issue — automatically refunded. (' . (string) ($res['message'] ?? 'unknown') . ')')
                    : ('Payment recorded but the supplier could not issue: ' . (string) ($res['message'] ?? 'unknown') . ' — flagged for review.'),
                'issue_response' => $res];
    }
}

if (!function_exists('bm_notify_paid')) {
    /** Fire the paid notification + generate the branded PDF, ONCE, best effort. */
    function bm_notify_paid($db, string $invoiceId): void
    {
        try {
            $b = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
            if (!$b) return;

            // FULFILMENT GUARD (customer-journey audit #15). This sends the customer a
            // no-price TICKET (a travel document implying the booking is fulfilled).
            // For a LIVE-supplier booking whose ticketing HARD-FAILED (e.g. rail: the
            // supplier poll returned a failure so booking_status was left 'pending'
            // with an issue error), emailing a "ticket" misrepresents fulfilment. Do
            // NOT notify while the booking is in that failed/unfulfilled state —
            // CRUCIALLY, do this BEFORE the idempotency claim below so a later
            // successful (re)issue can still notify. Manual/offline BM bookings are
            // legitimately 'confirmed' (no supplier issue) and pass; services that
            // confirm-on-payment pass; only a paid booking still 'pending' WITH an
            // issue error is held back.
            $__bStatus = strtolower((string) ($b['booking_status'] ?? ''));
            $__issueErr = trim((string) ($b['booking_payment_issue'] ?? '')) !== ''
                || trim((string) ($b['error_response'] ?? '')) !== '';
            if ($__bStatus === 'pending' && $__issueErr) {
                error_log('bm_notify_paid: SKIP ticket for paid-but-unissued booking ' . $invoiceId . ' (status=pending, issue error present) — will notify once issued.');
                return;
            }

            // IDEMPOTENCY GUARD. bm_notify_paid is now called from several paths
            // (BM pay, agent settle, admin mark_paid, the payment-callback fallback,
            // the ferries webhook). Without a guard, two of them firing for the same
            // booking would send the customer TWO tickets. Stamp a one-time
            // `notified_paid_at` and no-op on a second call. Self-healing column.
            if (empty($b['notified_paid_at'])) {
                static $ensured = false;
                if (!$ensured) {
                    try { $db->query("ALTER TABLE `bookings` ADD COLUMN `notified_paid_at` DATETIME DEFAULT NULL"); } catch (\Throwable $e) { /* exists */ }
                    $ensured = true;
                }
                // Claim atomically: only the FIRST caller flips NULL -> now and proceeds.
                // This serializes concurrent callers (two can't both send). BUT the
                // claim must NOT permanently burn if the send then FAILS — otherwise a
                // crash mid-PDF (mPDF OOM, an FPM worker killed by a deploy right after
                // the response flushed) would leave the claim set and NO retry would
                // ever re-notify (the stranded cron only re-drives non-confirmed live
                // bookings, so a confirmed/manual one is invisible to it). So we arm a
                // shutdown guard that RELEASES the claim unless we reach the success
                // marker below. A normal failure is also released in the catch.
                // Stamp a claim value we own, so any later release clears ONLY our
                // claim — never a fresh claim another process set in between (a bare
                // NOW() is owner-blind). The safety does NOT rest on the stamp being
                // globally unique (it is second-granular, DATETIME column): it rests on
                // the atomic `WHERE notified_paid_at IS NULL` claim — only one caller
                // per row wins — AND the invoice_id filter on BOTH releases, so an
                // identical stamp on two different invoices can never cross-release.
                // Use app-local date() (NOT gmdate) so notified_paid_at stays on the
                // same clock as every other DATETIME on the row (config.php pins the
                // MySQL session tz to the app offset); the exact string still round-
                // trips for the = :stamp match.
                $claimStamp = date('Y-m-d H:i:s');
                $claim = $db->query(
                    "UPDATE bookings SET notified_paid_at = :stamp WHERE invoice_id = :inv AND notified_paid_at IS NULL",
                    [':stamp' => $claimStamp, ':inv' => $invoiceId]
                );
                if (!$claim || $claim->rowCount() < 1) { return; } // another path already notified

                // Release-on-crash guard. $notifyDone is flipped true after the send
                // succeeds; if a FATAL kills the request before that, this shutdown
                // handler clears the claim so a later call (or cron) can retry. The
                // release is OWNER-AWARE: it only nulls the row if it STILL holds the
                // exact value we wrote — so a legitimate re-notify that stamped a fresh
                // value in between is never clobbered (closes the TOCTOU window).
                $notifyDone = false;
                register_shutdown_function(function () use ($db, $invoiceId, $claimStamp, &$notifyDone) {
                    if ($notifyDone) { return; }
                    try {
                        $db->query(
                            "UPDATE bookings SET notified_paid_at = NULL WHERE invoice_id = :inv AND notified_paid_at = :stamp",
                            [':inv' => $invoiceId, ':stamp' => $claimStamp]
                        );
                    } catch (\Throwable $e) { error_log('bm_notify_paid claim-release (shutdown): ' . $e->getMessage()); }
                });
            } else {
                return; // already notified
            }
            // TWO documents (3-document model): the priced INVOICE for the payer/
            // owner/admin, and the no-price TICKET (travel document) for the
            // TRAVELLER. NOTIFY sends $ticketPdf to the customer and $invoicePdf to
            // owner/admin. Previously only the invoice was generated and it went to
            // everyone — so the traveller received the payer's priced invoice.
            $invoicePdf = function_exists('GENERATE_BOOKING_PDF') ? GENERATE_BOOKING_PDF($invoiceId) : null;
            $ticketPdf  = function_exists('GENERATE_BOOKING_PDF') ? GENERATE_BOOKING_PDF($invoiceId, 'ticket') : null;
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
                ], $invoicePdf, $ticketPdf);
            }

            // Primary customer notification (ticket/invoice email) is DONE. Disarm the
            // release-on-crash guard here — not after the eSIM phone step below, which
            // is idempotent best-effort and must NOT gate the claim (releasing after a
            // partial failure there would double-send the email on retry). A fatal
            // BEFORE this point leaves the guard armed → claim released → safe retry.
            if (isset($notifyDone)) { $notifyDone = true; }

            // eSIM: also deliver the activation to the customer's PHONE (WhatsApp /
            // GIA channel) — a warm message + the BARE QR image + the voucher PDF.
            // eSIM collects phone (now required), and the QR belongs on the phone the
            // SIM installs on. Idempotent + best-effort; no-ops if Green API isn't
            // configured or the eSIM isn't provisioned yet (a later retry delivers).
            if (strtolower((string) ($b['module_type'] ?? '')) === 'esim'
                && function_exists('esim_deliver_to_phone')) {
                esim_deliver_to_phone($db, $invoiceId);
            }
        } catch (\Throwable $e) {
            error_log('bm_notify_paid: ' . $e->getMessage());
            // If we FAILED before the primary email went out ($notifyDone still false),
            // release the claim so a retry can re-notify. If the email already sent
            // ($notifyDone true), KEEP the claim — a partial failure in a later
            // best-effort step (eSIM phone) must not trigger a duplicate email.
            if (isset($notifyDone) && $notifyDone === false && !empty($invoiceId) && isset($claimStamp)) {
                try {
                    // Owner-aware release: only our exact claim (never a fresh one).
                    $db->query(
                        "UPDATE bookings SET notified_paid_at = NULL WHERE invoice_id = :inv AND notified_paid_at = :stamp",
                        [':inv' => $invoiceId, ':stamp' => $claimStamp]
                    );
                } catch (\Throwable $e2) { error_log('bm_notify_paid claim-release (catch): ' . $e2->getMessage()); }
                // DISARM the shutdown closure — the catch already released the claim, so
                // a second (owner-aware, now no-op) release at request end is wasteful.
                $notifyDone = true;
            }
        }
    }
}

if (!function_exists('bm_notify_pending_verification')) {
    /**
     * Tell the customer their booking was RECEIVED and is awaiting payment
     * verification — so a manual-transfer booking is no longer a silent dead-zone
     * between "created" and "verified". This is NOT a confirmation and NOT a ticket:
     *   - no PDF is attached,
     *   - the wording never claims paid/confirmed,
     *   - it explicitly says the ticket/confirmation comes AFTER the transfer is
     *     verified.
     * Email + WhatsApp (both best-effort, logged via notify_log). Idempotent: a
     * one-time `pending_notified_at` stamp means it fires at most once per booking.
     * Never throws, never blocks the pay response.
     */
    function bm_notify_pending_verification($db, string $invoiceId): void
    {
        try {
            $b = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
            if (!$b) return;

            // Idempotency — only the FIRST call notifies (self-healing column).
            if (empty($b['pending_notified_at'])) {
                static $ensured = false;
                if (!$ensured) {
                    $ensured = true;
                    try { $db->query("ALTER TABLE `bookings` ADD COLUMN `pending_notified_at` DATETIME DEFAULT NULL"); } catch (\Throwable $e) { /* exists */ }
                }
                $claim = $db->query(
                    "UPDATE bookings SET pending_notified_at = NOW() WHERE invoice_id = :inv AND pending_notified_at IS NULL",
                    [':inv' => $invoiceId]
                );
                if (!$claim || $claim->rowCount() < 1) { return; } // already notified
            } else {
                return;
            }

            $settings  = $db->get('settings', '*') ?: [];
            $brand     = (string) ($settings['business_name'] ?? 'GoGlobia');
            $supportEmail = (string) ($settings['contact_email'] ?? 'hi@goglobia.com');
            $name      = trim((string) (($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')));
            $svcLabel  = ucfirst(strtolower((string) ($b['module_type'] ?? 'booking')));
            $amount    = (string) ($b['price_markup'] ?? '');
            $currency  = (string) ($b['currency_markup'] ?? '');
            $email     = trim((string) ($b['email'] ?? ''));
            $phone     = trim((string) ($b['phone'] ?? ''));
            $cc        = trim((string) ($b['phone_country_code'] ?? ''));

            $lineAmount = ($currency !== '' && $amount !== '') ? (' of ' . $currency . ' ' . $amount) : '';

            // ---- EMAIL (plain, branded header/footer, NO price-paid claim, NO pdf) ----
            if ($email !== '' && function_exists('SENDEMAIL')) {
                $subject = $brand . ' — booking received, awaiting payment confirmation (#' . $invoiceId . ')';
                $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
                $inner = '<div style="padding:28px 32px;background:#f8fafc;font-size:15px;color:#2d3748;line-height:1.65;">'
                    . '<p>Hi ' . $e($name ?: 'there') . ',</p>'
                    . '<p>We\'ve received your <strong>' . $e($svcLabel) . '</strong> booking <strong>#' . $e($invoiceId) . '</strong>'
                    . $e($lineAmount) . '. Your bank transfer is <strong>being confirmed</strong> by our team.</p>'
                    . '<p>Once the payment is verified, we\'ll email your <strong>ticket / confirmation</strong> and send it to your WhatsApp. '
                    . 'This usually takes a short while during business hours.</p>'
                    . '<p>You don\'t need to do anything else. If you have questions, reply to this email or contact us at '
                    . $e($supportEmail) . ', quoting booking #' . $e($invoiceId) . '.</p>'
                    . '<p>Thank you,<br>' . $e($brand) . '</p></div>';
                try {
                    ob_start();
                    $SECURE = true;
                    @include __DIR__ . '/../views/notifications/emails/header.php';
                    echo $inner;
                    @include __DIR__ . '/../views/notifications/emails/footer.php';
                    $body = ob_get_clean();
                    $ok = (bool) SENDEMAIL($email, $name, $subject, $body);
                    if (function_exists('notify_log')) { notify_log($invoiceId, 'email', 'booking_pending_verification', $email, $ok, $ok ? null : 'provider returned false'); }
                } catch (\Throwable $e2) {
                    if (ob_get_level()) { ob_end_clean(); }
                    error_log('bm_notify_pending_verification email: ' . $e2->getMessage());
                }
            }

            // ---- WHATSAPP (plain text, NO pdf) ----
            if ($phone !== '' && function_exists('SENDWHATSAPP')) {
                $full = '';
                if (function_exists('getPhoneCode') && function_exists('formatPhoneNumber')) {
                    // Reuse the same formatter NOTIFY uses (global helpers).
                    $num = function_exists('getPhoneCode') ? getPhoneCode($cc, $db) : $cc;
                    $full = $cc !== '' || $num !== '' ? (preg_replace('/[^\d]/', '', (string) $num) . preg_replace('/[^\d]/', '', $phone)) : $phone;
                } else {
                    $full = ($cc !== '' ? preg_replace('/[^\d]/', '', $cc) : '') . preg_replace('/[^\d]/', '', $phone);
                }
                $msg = 'Hi ' . ($name ?: 'there') . "! We've received your " . $svcLabel . ' booking #' . $invoiceId
                    . '. Your bank transfer is being confirmed — once verified we\'ll send your ticket/confirmation here and by email. '
                    . 'Thank you for choosing ' . $brand . '.';
                if (trim((string) $full) !== '') {
                    $ok = (bool) SENDWHATSAPP($full, $name, $msg);
                    if (function_exists('notify_log')) { notify_log($invoiceId, 'whatsapp', 'booking_pending_verification', (string) $full, $ok, $ok ? null : 'green api send failed (or provider not greenapi)'); }
                } elseif (function_exists('notify_log')) {
                    notify_log($invoiceId, 'whatsapp', 'booking_pending_verification', $phone, false, 'no usable phone after formatting — skipped');
                }
            }
        } catch (\Throwable $e) { error_log('bm_notify_pending_verification: ' . $e->getMessage()); }
    }
}

if (!function_exists('bm_apply_booking_adjustment')) {
    /**
     * Apply a MONEY ADJUSTMENT to an existing booking — the single primitive behind
     * flight CHANGE / RESCHEDULE / cancel+rebook reconciliation. There was previously
     * NO helper for "customer owes a fare difference" or "refund the balance, keep a
     * change fee"; bm_settle_booking_payment only settles a FULL first payment. This
     * fills that gap and is the only new money code the change feature needs.
     *
     * The adjustment is a signed money delta the customer owes (+) or is owed (−),
     * NET of any change fee:
     *   $delta  > 0  → CHARGE: customer owes a fare difference (+ fee). We DO NOT
     *                  invent a new charge rail — we record the amount owed on the
     *                  booking (bumps price_original/price_markup + flips payment_status
     *                  to 'partially_paid' when it was 'paid'), write a ledger audit row,
     *                  and return payment_due so the caller surfaces the existing /pay
     *                  (or offline-mark) flow to actually collect it.
     *   $delta  < 0  → REFUND: customer is owed |delta|. For a gateway-paid booking we
     *                  wrap the EXISTING refund_gateway_payment($db,$booking,$amount,...)
     *                  with the exact partial amount. For a manual/offline booking (no
     *                  gateway txn) we record a refund audit row only and surface it for
     *                  a manual payout. The change fee is already baked into $delta by
     *                  the caller (refund = paid_back_fare − fee), so NOTHING here keeps
     *                  a fee separately — $feeAmount is recorded for the audit trail only.
     *   $delta == 0  → no money moves; we still write an audit row (fee-only / zero-sum
     *                  change) so the change is traceable.
     *
     * Idempotent per adjustment ref: pass $meta['adjustment_ref'] (else one is derived
     * from invoice + a monotonic suffix in booking_data.meta.adjustments) — a repeated
     * call with the same ref is a no-op. All writes are best-effort-logged, never fatal.
     *
     * @param  object $db
     * @param  string $invoiceId
     * @param  float  $delta       signed net amount owed(+)/owed-to-customer(−), in the booking currency
     * @param  float  $feeAmount   the change fee component (audit only; already inside $delta)
     * @param  string $reason      human reason, stored on the audit row + booking meta
     * @param  array  $meta        optional: adjustment_ref, actor (user_id), gateway_id, no_gateway(bool)
     * @return array  ['ok'=>bool,'direction'=>'charge'|'refund'|'none','amount'=>float,
     *                 'payment_due'=>float,'refunded'=>float,'ref'=>string,'message'=>string]
     */
    function bm_apply_booking_adjustment($db, string $invoiceId, float $delta, float $feeAmount, string $reason, array $meta = []): array
    {
        // record_transaction + refund_gateway_payment live in payment-gateway.php,
        // NOT in config's global require list — load on demand (idempotent), same as
        // bm_settle_booking_payment does.
        if (!function_exists('record_transaction') || !function_exists('refund_gateway_payment')) {
            $pg = dirname(__DIR__) . '/lib/payment-gateway.php';
            if (is_file($pg)) { require_once $pg; }
        }

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) return ['ok' => false, 'direction' => 'none', 'amount' => 0.0, 'message' => 'Booking not found'];

        $currency = strtoupper(trim((string) ($booking['currency_markup'] ?? 'NGN'))) ?: 'NGN';
        $actor    = $meta['actor'] ?? ($_SESSION['user_id'] ?? null);

        // ---- Idempotency: derive/validate a stable adjustment ref -------------------
        $bd = json_decode((string) ($booking['booking_data'] ?? '{}'), true);
        if (!is_array($bd)) { $bd = []; }
        $applied = $bd['meta']['adjustments'] ?? [];
        if (!is_array($applied)) { $applied = []; }
        $ref = trim((string) ($meta['adjustment_ref'] ?? ''));
        if ($ref === '') {
            $ref = 'CHGADJ-' . $invoiceId . '-' . (count($applied) + 1);
        }
        foreach ($applied as $a) {
            if (isset($a['ref']) && (string) $a['ref'] === $ref) {
                return ['ok' => true, 'direction' => $a['direction'] ?? 'none', 'amount' => (float) ($a['amount'] ?? 0),
                        'payment_due' => (float) ($a['payment_due'] ?? 0), 'refunded' => (float) ($a['refunded'] ?? 0),
                        'ref' => $ref, 'message' => 'Adjustment already applied (idempotent no-op).'];
            }
        }

        $direction   = $delta > 0 ? 'charge' : ($delta < 0 ? 'refund' : 'none');
        $paymentDue  = 0.0;
        $refunded    = 0.0;
        $message     = '';

        // ---- CHARGE: customer owes more (fare difference + fee) ----------------------
        if ($direction === 'charge') {
            $amt = round((float) $delta, 2);
            // Bump the booking's priced totals so the invoice/ledger reflect the new fare.
            // price_original/price_markup are varchar on this schema (legacy) — keep numeric.
            $newOriginal = round((float) ($booking['price_original'] ?? 0) + $amt, 2);
            $newMarkup   = round((float) ($booking['price_markup'] ?? 0) + $amt, 2);
            $update = [
                'price_original' => $newOriginal,
                'price_markup'   => (string) $newMarkup,
            ];
            // A fully-paid booking now has an outstanding balance → partially_paid so the
            // existing pay/collect UI shows an amount due (never downgrade an unpaid one).
            if (strtolower((string) ($booking['payment_status'] ?? '')) === 'paid') {
                $update['payment_status'] = 'partially_paid';
            }
            try { $db->update('bookings', $update, ['invoice_id' => $invoiceId]); }
            catch (\Throwable $e) { error_log('bm_adjust charge update ' . $invoiceId . ': ' . $e->getMessage()); }

            $paymentDue = $amt;
            // Audit row (type 'purchase' — matches the transactions enum for a charge).
            _bm_adjustment_audit_row($db, $booking, $amt, $currency, 'purchase', $ref, $reason, $feeAmount, $actor, $meta);
            $message = 'Fare difference of ' . $amt . ' ' . $currency . ' recorded as due — collect via the normal payment flow.';
        }
        // ---- REFUND: customer is owed |delta| (net of fee, baked in by caller) -------
        elseif ($direction === 'refund') {
            $amt = round(abs((float) $delta), 2);
            $noGateway = !empty($meta['no_gateway'])
                || trim((string) ($booking['payment_gateway'] ?? '')) === ''
                || trim((string) ($booking['transaction_id'] ?? '')) === '';

            if (!$noGateway && function_exists('refund_gateway_payment')) {
                try {
                    $r = refund_gateway_payment($db, $booking, $amt, $reason !== '' ? $reason : 'Flight change refund');
                    // refund_gateway_payment returns status 'refunded' on success (NOT
                    // 'success') — see its docblock + Paystack/Stripe/wallet success paths
                    // (payment-gateway.php:3117/3165/3206) and the other correct callers
                    // (bus action, refund-retry cron). Checking 'success' here was a bug:
                    // a REAL gateway refund (money already moved) was treated as failed and
                    // reported as a "manual payout", inviting a double refund. NOTE: this is
                    // a PARTIAL change-refund (fare-drop minus fee), so we do NOT flip
                    // payment_status to 'refunded' — the booking is still a valid paid
                    // booking at the new lower price; only the delta was returned.
                    $okRefund = is_array($r) && (string) ($r['status'] ?? '') === 'refunded';
                    if ($okRefund) {
                        $refunded = $amt;
                        $message  = 'Refunded ' . $amt . ' ' . $currency . ' to the original payment method.';
                    } else {
                        // Gateway could not refund (unsupported/failed) → fall back to an
                        // audit-only record so staff can pay out manually. Do NOT claim success.
                        _bm_adjustment_audit_row($db, $booking, $amt, $currency, 'refund', $ref, $reason . ' [manual payout — gateway: ' . (string) ($r['message'] ?? 'n/a') . ']', $feeAmount, $actor, $meta);
                        return _bm_adjustment_record_and_return($db, $invoiceId, $bd, $applied, [
                            'ok' => true, 'direction' => 'refund', 'amount' => $amt, 'payment_due' => 0.0,
                            'refunded' => 0.0, 'ref' => $ref,
                            'message' => 'Gateway refund unavailable (' . (string) ($r['message'] ?? 'n/a') . ') — recorded ' . $amt . ' ' . $currency . ' as a manual payout.',
                        ], $reason, $feeAmount, $actor);
                    }
                } catch (\Throwable $e) {
                    error_log('bm_adjust refund ' . $invoiceId . ': ' . $e->getMessage());
                    _bm_adjustment_audit_row($db, $booking, $amt, $currency, 'refund', $ref, $reason . ' [manual payout — refund error]', $feeAmount, $actor, $meta);
                    $message = 'Refund could not be processed automatically — recorded ' . $amt . ' ' . $currency . ' as a manual payout.';
                    $refunded = 0.0;
                }
            } else {
                // Manual/offline booking (no gateway txn): audit-only, manual payout.
                _bm_adjustment_audit_row($db, $booking, $amt, $currency, 'refund', $ref, $reason . ' [manual payout — no gateway txn]', $feeAmount, $actor, $meta);
                $message = 'Recorded ' . $amt . ' ' . $currency . ' as a manual payout (no gateway payment to reverse).';
                $refunded = 0.0;
            }
            // Successful gateway refund already wrote its own transactions row inside
            // refund_gateway_payment; add our tagged change-adjustment audit row too so
            // the change is attributable (idempotent via the ref).
            if ($refunded > 0) {
                _bm_adjustment_audit_row($db, $booking, $amt, $currency, 'refund', $ref, $reason, $feeAmount, $actor, $meta);
            }
        }
        // ---- ZERO: fee-only / zero-sum change (still auditable) ----------------------
        else {
            _bm_adjustment_audit_row($db, $booking, round((float) $feeAmount, 2), $currency, 'purchase', $ref, $reason . ' [fee-only / zero-sum change]', $feeAmount, $actor, $meta);
            $message = 'Change recorded (no net money movement).';
        }

        return _bm_adjustment_record_and_return($db, $invoiceId, $bd, $applied, [
            'ok' => true, 'direction' => $direction, 'amount' => round(abs((float) $delta), 2),
            'payment_due' => $paymentDue, 'refunded' => $refunded, 'ref' => $ref, 'message' => $message,
        ], $reason, $feeAmount, $actor);
    }
}

if (!function_exists('_bm_adjustment_audit_row')) {
    /** Write ONE tagged change-adjustment row to the legacy `transactions` ledger.
     *  Mirrors record_transaction's insert shape but sets `type` explicitly and tags
     *  the change in gateway_response so the row is attributable/filterable. Idempotent
     *  on trx_id (the adjustment ref). Best-effort — never throws. */
    function _bm_adjustment_audit_row($db, array $booking, float $amount, string $currency, string $type, string $ref, string $reason, float $feeAmount, $actor, array $meta): void
    {
        try {
            if ($db->get('transactions', 'id', ['trx_id' => $ref])) { return; } // idempotent
        } catch (\Throwable $e) { /* col variance — fall through */ }
        try {
            $db->insert('transactions', [
                'invoice_id'       => $booking['invoice_id'] ?? null,
                'amount'           => (string) $amount,
                'currency'         => $currency,
                'gateway_id'       => $meta['gateway_id'] ?? ($booking['payment_gateway'] ?? null),
                'trx_id'           => $ref,
                'type'             => in_array($type, ['credit', 'purchase', 'refund', 'debit'], true) ? $type : null,
                'date'             => date('Y-m-d H:i:s'),
                'status'           => 'success',
                'description'      => 'Change adjustment for Invoice ' . ($booking['invoice_id'] ?? ''),
                'gateway_response' => json_encode(['source' => 'change_adjustment', 'reason' => $reason, 'fee' => $feeAmount]),
                'client_email'     => $booking['email'] ?? null,
                'user_id'          => $booking['user_id'] ?? null,
                'created_by'       => $actor,
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) { error_log('_bm_adjustment_audit_row ' . ($booking['invoice_id'] ?? '') . ': ' . $e->getMessage()); }
    }
}

if (!function_exists('_bm_adjustment_record_and_return')) {
    /** Append the applied adjustment to booking_data.meta.adjustments (idempotency log)
     *  and return the result array. Best-effort persist. */
    function _bm_adjustment_record_and_return($db, string $invoiceId, array $bd, array $applied, array $result, string $reason, float $feeAmount, $actor): array
    {
        try {
            $applied[] = [
                'ref'         => $result['ref'],
                'direction'   => $result['direction'],
                'amount'      => $result['amount'],
                'payment_due' => $result['payment_due'] ?? 0,
                'refunded'    => $result['refunded'] ?? 0,
                'fee'         => round((float) $feeAmount, 2),
                'reason'      => $reason,
                'actor'       => $actor,
                'ts'          => date('Y-m-d H:i:s'),
            ];
            if (!isset($bd['meta']) || !is_array($bd['meta'])) { $bd['meta'] = []; }
            $bd['meta']['adjustments'] = $applied;
            $db->update('bookings', ['booking_data' => json_encode($bd)], ['invoice_id' => $invoiceId]);
        } catch (\Throwable $e) { error_log('_bm_adjustment_record_and_return ' . $invoiceId . ': ' . $e->getMessage()); }
        return $result;
    }
}

if (!function_exists('bm_apply_agent_booking_change')) {
    /**
     * WALLET-AWARE post-booking change/reschedule money adjustment for an AGENT
     * booking. Mirrors the admin change money model (bm_apply_booking_adjustment)
     * EXACTLY for the CHARGE direction, but routes any REFUND back to the AGENCY
     * WALLET (agent bookings are wallet-paid: payment_gateway='Wallet'), not through
     * refund_gateway_payment — which explicitly skips wallet/credits gateways and
     * would otherwise record an uncredited "manual payout", stranding the agent's
     * money (that mismatch is why we do NOT call the admin primitive for refunds).
     *
     * Reuses the admin primitives (bm_apply_booking_adjustment for charge;
     * _bm_adjustment_audit_row + _bm_adjustment_record_and_return for the refund's
     * attributable, idempotent record) — NO parallel ledger, NO duplicated math.
     *
     * Delta model (identical to the admin change handler):
     *   $delta = fareDiff + changeFee          (charge: fare up, or flat-fee-only)
     *   $delta = fareDiff + changeFee (<0)      (refund: fare down, fee retained out of refund)
     * The change fee is always RETAINED by the platform (added on a charge, netted
     * out of a refund), matching admin semantics.
     *
     * Idempotent: one stable $ref (adjustment_ref) gates BOTH the wallet_refund
     * (idempotency_key) AND the booking_data.meta.adjustments log, so a repeat is a
     * no-op that never double-moves money.
     *
     * @param string $owner  the AGENCY OWNER user_id (wallet owner / refund target)
     * @return array ['ok','direction'=>'charge|refund|none','amount','payment_due',
     *                'refunded','ref','message']
     */
    function bm_apply_agent_booking_change($db, string $invoiceId, string $owner, float $fareDiff, float $changeFee, string $reason, array $meta = []): array
    {
        $changeFee = max(0.0, round((float) $changeFee, 2));
        $fareDiff  = round((float) $fareDiff, 2);
        // Net the customer owes(+) / is owed(−): fee is added on a charge, retained
        // out of a refund (fee offsets the refund) — same rule as admin.
        $delta = round($fareDiff + $changeFee, 2);
        if ($fareDiff === 0.0) { $delta = $changeFee; } // flat fee only

        $booking = $db->get('bookings', '*', ['invoice_id' => $invoiceId]);
        if (!$booking) { return ['ok' => false, 'direction' => 'none', 'amount' => 0.0, 'message' => 'Booking not found']; }
        $currency = strtoupper(trim((string) ($booking['currency_markup'] ?? 'NGN'))) ?: 'NGN';
        $actor    = $meta['actor'] ?? ($_SESSION['user_id'] ?? null);

        // Stable idempotency ref shared by the wallet refund AND the audit log.
        $bd = json_decode((string) ($booking['booking_data'] ?? '{}'), true);
        if (!is_array($bd)) { $bd = []; }
        $applied = $bd['meta']['adjustments'] ?? [];
        if (!is_array($applied)) { $applied = []; }
        $ref = trim((string) ($meta['adjustment_ref'] ?? ''));
        if ($ref === '') { $ref = 'AGTCHG-' . $invoiceId . '-' . (count($applied) + 1); }
        foreach ($applied as $a) {
            if (isset($a['ref']) && (string) $a['ref'] === $ref) {
                return ['ok' => true, 'direction' => $a['direction'] ?? 'none', 'amount' => (float) ($a['amount'] ?? 0),
                        'payment_due' => (float) ($a['payment_due'] ?? 0), 'refunded' => (float) ($a['refunded'] ?? 0),
                        'ref' => $ref, 'message' => 'Change already applied (idempotent no-op).'];
            }
        }

        // ---- CHARGE: fare went up → reuse the admin primitive verbatim. It records
        //      the amount as due (bumps price totals, flips paid→partially_paid) so
        //      the agent collects it via the normal /pay-from-wallet flow. ----------
        if ($delta > 0.001) {
            return bm_apply_booking_adjustment($db, $invoiceId, $delta, $changeFee, $reason,
                ['actor' => $actor, 'adjustment_ref' => $ref] + $meta);
        }

        // ---- REFUND: fare dropped (net of fee) → credit the AGENCY WALLET. --------
        if ($delta < -0.001) {
            $amt = round(abs($delta), 2);
            $refunded = 0.0; $message = '';
            $paidFromWallet = strcasecmp((string) ($booking['payment_gateway'] ?? ''), 'Wallet') === 0;

            if ($paidFromWallet && $owner !== '' && function_exists('wallet_refund')) {
                try {
                    $r = wallet_refund($db, $owner, $amt, $currency, [
                        'reason'          => 'refund',
                        'invoice_id'      => $invoiceId,
                        'ref_type'        => 'invoice',
                        'ref_id'          => $invoiceId,
                        'idempotency_key' => $ref,   // same ref → never double-refunds
                        'note'            => 'Agent booking change refund (fare drop net of fee) ' . $invoiceId,
                    ]);
                    if (!empty($r['ok'])) {
                        $refunded = $amt;
                        $message  = 'Refunded ' . $amt . ' ' . $currency . ' to your agency wallet.';
                    } else {
                        $message = 'Change recorded; wallet refund of ' . $amt . ' ' . $currency . ' could not be applied (' . (string) ($r['message'] ?? 'error') . ') — contact support.';
                    }
                } catch (\Throwable $e) {
                    error_log('bm_apply_agent_booking_change refund ' . $invoiceId . ': ' . $e->getMessage());
                    $message = 'Change recorded; wallet refund could not be applied automatically.';
                }
            } else {
                // Not wallet-paid (e.g. an admin-marked paid, legacy) → do NOT mint
                // wallet credit that was never debited. Record audit-only.
                $message = 'Change recorded (' . $amt . ' ' . $currency . ' owed) — not wallet-paid, so no automatic wallet refund.';
            }

            // Lower the booking's priced totals to the new (reduced) fare so the
            // invoice/ledger reflect it. The fare drop is (amt + fee) off the fare;
            // the fee is retained, so the net total reduction is $amt. (Mirrors the
            // charge path's total bump, in reverse, without going below zero.)
            try {
                $newOriginal = max(0.0, round((float) ($booking['price_original'] ?? 0) - $amt, 2));
                $newMarkup   = max(0.0, round((float) ($booking['price_markup'] ?? 0) - $amt, 2));
                $db->update('bookings', ['price_original' => $newOriginal, 'price_markup' => (string) $newMarkup], ['invoice_id' => $invoiceId]);
            } catch (\Throwable $e) { error_log('bm_apply_agent_booking_change total ' . $invoiceId . ': ' . $e->getMessage()); }

            _bm_adjustment_audit_row($db, $booking, $amt, $currency, 'refund', $ref, $reason, $changeFee, $actor, $meta);
            return _bm_adjustment_record_and_return($db, $invoiceId, $bd, $applied, [
                'ok' => true, 'direction' => 'refund', 'amount' => $amt, 'payment_due' => 0.0,
                'refunded' => $refunded, 'ref' => $ref, 'message' => $message,
            ], $reason, $changeFee, $actor);
        }

        // ---- ZERO (should be unreachable: fee-only is a charge) — audit-only. -----
        _bm_adjustment_audit_row($db, $booking, 0.0, $currency, 'purchase', $ref, $reason . ' [zero-sum change]', $changeFee, $actor, $meta);
        return _bm_adjustment_record_and_return($db, $invoiceId, $bd, $applied, [
            'ok' => true, 'direction' => 'none', 'amount' => 0.0, 'payment_due' => 0.0,
            'refunded' => 0.0, 'ref' => $ref, 'message' => 'Change recorded (no net money movement).',
        ], $reason, $changeFee, $actor);
    }
}

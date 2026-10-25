<?php
// app/lib/ai/gia_account.php
//
// Full-account awareness for GIA: read EVERYTHING the assistant may legitimately
// know about an identified traveller — all their bookings (across the central
// `bookings` table AND the separate `umrah_bookings` v2 table), wallet balance,
// loyalty points and profile basics — as one compact, model-safe snapshot.
// GIA phone-auth/personalisation workstream, Phase 5 (owner priority: "GIA
// should have access to all bookings of the user, be fully aware of the user").
//
// Scoped strictly to ONE user_id (the trusted account_id the route resolved).
// Never returns another user's data, internal numeric ids, passwords or tokens.
// Read-only.

if (!function_exists('gia_user_snapshot')) {
    /**
     * Build a compact account snapshot for the given user.
     *
     * @param object $db
     * @param string $userId  users.user_id (custom string id).
     * @return array {
     *   found:bool, name:string, currency:string, wallet:float, loyalty:int,
     *   upcoming:array<booking>, past:array<booking>, counts:array,
     * } where each booking = {service, ref, status, payment, amount, currency, date}
     */
    function gia_user_snapshot($db, string $userId): array
    {
        $userId = trim($userId);
        $empty = ['found' => false, 'name' => '', 'currency' => '', 'wallet' => 0.0, 'loyalty' => 0,
                  'upcoming' => [], 'past' => [], 'counts' => ['total' => 0, 'upcoming' => 0, 'past' => 0]];
        if ($userId === '') {
            return $empty;
        }

        $u = $db->get('users', ['first_name', 'last_name', 'currency'], ['user_id' => $userId]);
        if (!is_array($u) || $u === []) {
            return $empty;
        }

        $name     = trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? ''));
        $currency = strtoupper(trim((string) ($u['currency'] ?? '')));

        // --- Wallet + loyalty (reuse the money spine; never users.balance). ---
        $wallet  = function_exists('wallet_balance') ? (float) wallet_balance($db, $userId, $currency) : 0.0;
        $loyalty = function_exists('loyalty_balance') ? (int) loyalty_balance($db, $userId) : 0;

        $bookings = [];

        // --- 1) Central bookings table (9 services via module_type). ---
        try {
            $rows = $db->select('bookings', [
                'invoice_id', 'module_type', 'module', 'booking_status', 'payment_status',
                'pnr', 'price_markup', 'currency_markup', 'created_at', 'booking_date',
            ], ['user_id' => $userId, 'ORDER' => ['id' => 'DESC']]) ?: [];
            foreach ($rows as $r) {
                $svc  = strtolower(trim((string) ($r['module_type'] ?? '')));
                $when = gia__booking_when($r['booking_date'] ?? '', $r['created_at'] ?? '');
                $bookings[] = [
                    'service'  => $svc !== '' ? $svc : 'booking',
                    'ref'      => (string) ($r['invoice_id'] ?? ''),
                    'status'   => (string) ($r['booking_status'] ?? ''),
                    'payment'  => (string) ($r['payment_status'] ?? ''),
                    'pnr'      => (string) ($r['pnr'] ?? ''),
                    'amount'   => (float) ($r['price_markup'] ?? 0),
                    'currency' => (string) ($r['currency_markup'] ?? $currency),
                    'date'     => $when,
                ];
            }
        } catch (\Throwable $e) {
            error_log('gia_user_snapshot bookings: ' . $e->getMessage());
        }

        // --- 2) Umrah v2 (separate table, different status vocabulary). ---
        try {
            $ur = $db->select('umrah_bookings', [
                'invoice_id', 'booking_ref', 'departure_id', 'booking_status', 'payment_status',
                'total_price', 'amount_paid', 'balance', 'currency', 'created_at',
            ], ['user_id' => $userId, 'ORDER' => ['id' => 'DESC']]) ?: [];
            foreach ($ur as $r) {
                $date = '';
                if (!empty($r['departure_id'])) {
                    $dep = $db->get('umrah_departures', ['departure_date'], ['id' => (int) $r['departure_id']]);
                    $date = (string) ($dep['departure_date'] ?? '');
                }
                if ($date === '') { $date = gia__booking_when('', $r['created_at'] ?? ''); }
                $bookings[] = [
                    'service'  => 'umrah',
                    'ref'      => (string) ($r['invoice_id'] ?: $r['booking_ref'] ?? ''),
                    'status'   => (string) ($r['booking_status'] ?? ''),
                    'payment'  => (string) ($r['payment_status'] ?? ''),
                    'pnr'      => '',
                    'amount'   => (float) ($r['total_price'] ?? 0),
                    'paid'     => (float) ($r['amount_paid'] ?? 0),
                    'balance'  => (float) ($r['balance'] ?? 0),
                    'currency' => (string) ($r['currency'] ?? $currency),
                    'date'     => $date,
                ];
            }
        } catch (\Throwable $e) {
            error_log('gia_user_snapshot umrah: ' . $e->getMessage());
        }

        // --- Split upcoming vs past by date (undated -> treated as upcoming so
        //     it is never hidden). ---
        $today = date('Y-m-d');
        $upcoming = [];
        $past = [];
        foreach ($bookings as $b) {
            $d = (string) ($b['date'] ?? '');
            if ($d === '' || $d >= $today) {
                $upcoming[] = $b;
            } else {
                $past[] = $b;
            }
        }

        return [
            'found'    => true,
            'name'     => $name,
            'currency' => $currency,
            'wallet'   => round($wallet, 2),
            'loyalty'  => $loyalty,
            'upcoming' => $upcoming,
            'past'     => $past,
            'counts'   => [
                'total'    => count($bookings),
                'upcoming' => count($upcoming),
                'past'     => count($past),
            ],
        ];
    }
}

if (!function_exists('gia__booking_when')) {
    /** Pick a YYYY-MM-DD date from booking_date (free text) or created_at. Internal. */
    function gia__booking_when($bookingDate, $createdAt): string
    {
        $bd = trim((string) $bookingDate);
        if ($bd !== '') {
            $ts = strtotime($bd);
            if ($ts !== false) { return date('Y-m-d', $ts); }
        }
        $ca = trim((string) $createdAt);
        if ($ca !== '') {
            $ts = strtotime($ca);
            if ($ts !== false) { return date('Y-m-d', $ts); }
        }
        return '';
    }
}

if (!function_exists('gia_user_summary_line')) {
    /**
     * One short line GIA can open a conversation with, e.g.
     * "You have 2 upcoming trips; the next is a flights booking on 2026-11-03."
     * Empty string if nothing to say.
     */
    function gia_user_summary_line(array $snap): string
    {
        if (empty($snap['found'])) { return ''; }
        $up = $snap['upcoming'] ?? [];
        if (count($up) === 0) { return ''; }
        $next = null;
        foreach ($up as $b) {
            if (($b['date'] ?? '') === '') { continue; }
            if ($next === null || $b['date'] < $next['date']) { $next = $b; }
        }
        $n = count($up);
        $plural = $n === 1 ? 'trip' : 'trips';
        if ($next) {
            return "You have {$n} upcoming {$plural}; the next is a {$next['service']} booking on {$next['date']}.";
        }
        return "You have {$n} upcoming {$plural} on your account.";
    }
}

if (!function_exists('gia_user_proactive_note')) {
    /**
     * Pick the SINGLE most relevant, time-aware nudge for a returning traveller
     * so GIA can open proactively — not a generic trip count (GA6). Priority:
     *   1. an UNPAID/pending booking (money left on the table) -> gentle complete nudge
     *   2. an IMMINENT upcoming trip (<= 7 days) -> offer check-in / add hotel
     *   3. a cancellation already requested -> reassure it's in review
     *   4. otherwise the plain summary line.
     * Returns a short suggestion for GIA to WEAVE IN naturally (not recite), or ''.
     * All suggestions are grounded in the real snapshot — GIA must not invent.
     */
    function gia_user_proactive_note(array $snap): string
    {
        if (empty($snap['found'])) { return ''; }
        $up = $snap['upcoming'] ?? [];
        if (count($up) === 0) { return ''; }

        $today = date('Y-m-d');

        // 1) Unpaid / pending-payment upcoming booking.
        foreach ($up as $b) {
            $pay = strtolower((string) ($b['payment'] ?? ''));
            if (in_array($pay, ['unpaid', 'awaiting_verification', 'partially_paid'], true)) {
                $svc = (string) ($b['service'] ?? 'booking');
                return "The traveller has a {$svc} booking (" . (string) ($b['ref'] ?? '') . ") that isn't fully paid. "
                    . "If it fits the conversation, gently offer to help them complete payment — don't nag.";
            }
        }

        // 2) Imminent upcoming trip (within 7 days).
        $soon = null; $soonDays = null;
        foreach ($up as $b) {
            $d = (string) ($b['date'] ?? '');
            if ($d === '' || $d < $today) { continue; }
            $days = (int) floor((strtotime($d) - strtotime($today)) / 86400);
            if ($days >= 0 && $days <= 7 && ($soonDays === null || $days < $soonDays)) {
                $soon = $b; $soonDays = $days;
            }
        }
        if ($soon !== null) {
            $svc = (string) ($soon['service'] ?? 'trip');
            $when = $soonDays === 0 ? 'today' : ($soonDays === 1 ? 'tomorrow' : "in {$soonDays} days");
            $extra = $svc === 'flights' ? ' (e.g. check-in, baggage, or a hotel/transfer at the destination)'
                : ($svc === 'stays' ? ' (e.g. a transfer or a tour at the destination)' : '');
            return "The traveller's {$svc} trip is {$when}. If it fits, be proactively helpful{$extra} — "
                . "but only offer, never push.";
        }

        // 3) Cancellation pending (reassure, don't re-request).
        // 4) Fall back to the plain summary.
        return gia_user_summary_line($snap);
    }
}

if (!function_exists('gia_find_user_by_phone')) {
    /**
     * Find a single account by phone (digits / last-10). Returns the users row or
     * null; '' user_id signals none. Used by registration to block duplicates and
     * offer "log in instead". Returns the FIRST match (shared numbers are rare and
     * registration simply refuses to create a second account on the same number).
     */
    function gia_find_user_by_phone($db, string $phone): ?array
    {
        $digits = preg_replace('/\D/', '', trim($phone));
        if ($digits === '') { return null; }
        try {
            $hit = $db->get('users', ['id', 'user_id', 'status', 'email', 'phone', 'phone_verified'], ['phone' => $digits]);
            if (!$hit && strlen($digits) >= 10) {
                $rows = $db->select('users', ['id', 'user_id', 'status', 'email', 'phone', 'phone_verified'], ['phone[~]' => '%' . substr($digits, -10)]) ?: [];
                $hit = $rows[0] ?? null;
            }
            return is_array($hit) && $hit !== [] ? $hit : null;
        } catch (\Throwable $e) {
            error_log('gia_find_user_by_phone: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('gia_register_user')) {
    /**
     * Create a passwordless, phone-first customer account (status inactive,
     * phone_verified 0) ready for a 'signup' OTP. Reuses an earlier ABANDONED
     * attempt for the same phone (inactive + unverified + no email) instead of
     * spawning a second phantom row. Returns ['ok','user_id','reuse'] or
     * ['ok'=>false,'error'] ('exists' when a real account already owns the phone).
     *
     * @param string $countryCode ISO or numeric; phone stored national-digits.
     */
    function gia_register_user($db, string $firstName, string $lastName, string $phone, string $countryCode): array
    {
        $firstName = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $firstName));
        $lastName  = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $lastName));
        if ($firstName === '') {
            return ['ok' => false, 'error' => 'name_required', 'message' => 'I need your name to set up the account.'];
        }
        if (trim($phone) === '') {
            return ['ok' => false, 'error' => 'phone_required', 'message' => 'I need a phone number to set up the account.'];
        }
        if (function_exists('validatePhoneForCountry') && !validatePhoneForCountry($phone, $countryCode, $db)) {
            return ['ok' => false, 'error' => 'phone_invalid', 'message' => 'That phone number does not look valid.'];
        }
        $national = function_exists('normalizePhoneForStorage')
            ? normalizePhoneForStorage($phone, $countryCode, $db)
            : preg_replace('/\D/', '', $phone);
        $code = function_exists('getPhoneCode') ? getPhoneCode($countryCode, $db) : $countryCode;

        // Already an account on this number?
        $existing = gia_find_user_by_phone($db, $national);
        if ($existing) {
            // "Abandoned" = an inactive, unverified, passwordless shell from a prior
            // attempt — recognised by the placeholder email (or an empty one from
            // older rows). A real account (verified, or with a real email) is NEVER
            // reused; registration refuses it below.
            $exEmail = trim((string) ($existing['email'] ?? ''));
            $isPlaceholderEmail = $exEmail === '' || substr($exEmail, -strlen('@no-email.goglobia.local')) === '@no-email.goglobia.local';
            $isAbandoned = (string) ($existing['status'] ?? '') === 'inactive'
                && (int) ($existing['phone_verified'] ?? 0) === 0
                && $isPlaceholderEmail;
            if (!$isAbandoned) {
                return ['ok' => false, 'error' => 'exists', 'message' => 'There is already an account on that number — I can help you log in instead.'];
            }
            // Reuse the abandoned shell; refresh the name.
            try {
                $db->update('users', [
                    'first_name' => $firstName,
                    'last_name'  => $lastName,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], ['id' => (int) $existing['id']]);
            } catch (\Throwable $e) {
                error_log('gia_register_user reuse: ' . $e->getMessage());
            }
            return ['ok' => true, 'user_id' => (string) $existing['user_id'], 'reuse' => true];
        }

        if (!function_exists('generateUserId')) {
            return ['ok' => false, 'error' => 'db', 'message' => 'Account setup is unavailable right now.'];
        }
        $curr = $db->get('currencies', 'name', ['default' => 1]) ?: 'USD';

        // Mint a unique user_id.
        $uid = generateUserId();
        for ($i = 0; $i < 5 && $db->has('users', ['user_id' => $uid]); $i++) { $uid = generateUserId(); }

        // users.email is NOT NULL + UNIQUE, so a passwordless account can't use ''
        // (the 2nd one would collide). Synthesise a unique, non-routable placeholder
        // tied to the user_id; it's clearly internal and can be replaced when the
        // traveller later adds a real email.
        $placeholderEmail = 'phone-' . $uid . '@no-email.goglobia.local';

        try {
            $db->insert('users', [
                'user_id'            => $uid,
                'first_name'         => $firstName,
                'last_name'          => $lastName,
                'email'              => $placeholderEmail, // unique placeholder (see note); real email added later
                'phone'              => $national,
                'phone_country_code' => $code,
                'password'           => '',              // passwordless — login is via phone OTP
                'role'               => 'customer',
                'status'             => 'inactive',      // flips to active on OTP verify
                'banned'             => 0,
                'email_verified'     => 0,
                'phone_verified'     => 0,
                'currency'           => $curr,
                'login_attempts'     => 0,
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('gia_register_user insert: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'db', 'message' => 'Could not set up the account just now.'];
        }
        return ['ok' => true, 'user_id' => $uid, 'reuse' => false];
    }
}

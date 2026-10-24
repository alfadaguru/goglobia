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

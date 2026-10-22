<?php
// ============================================================================
// FILE: app/lib/ai/gia_umrah.php
// GIA's OWN dedicated umrah flow. GIA is NOT the web and NOT the mobile app —
// so umrah on GIA does NOT ride the shared web search service (aiSearchService)
// or the shared reprice helper (tripRevalidateHelper). This module is the single
// home for everything GIA does with umrah: list departures, pick a tier, quote,
// book, collect pilgrims, pay.
//
// It CALLS the shared read-only v2 service/business functions (the data + booking
// engine that the website also uses) but NEVER edits them and NEVER touches any
// web route/view. Those functions live in app/lib/umrah/services.php +
// operations.php and are loaded unconditionally by config.php.
//
//   service  = umrah
//   provider = the operator's own inventory (local; no external API)
//   product  = a departure + tier  ->  departure_tier_id  (priced per pilgrim)
//
// Reading departures via umrahV2PublishedDepartures() DIRECTLY (the exact function
// the website /umrah page uses) is deliberate: it bypasses the shared
// attachLocalCards/umrahListingCards wrapper entirely, so GIA shows the same real
// inventory the website shows and cannot be broken by web-pipeline assumptions.
// ============================================================================

namespace App\lib\ai;

if (!function_exists('App\\lib\\ai\\gia_umrah_boot')) {
    /** Ensure the shared v2 umrah engine is loaded (it normally is, via config.php). */
    function gia_umrah_boot($db): bool
    {
        if (!\function_exists('umrahV2PublishedDepartures')) {
            $svc = \dirname(__DIR__) . '/umrah/services.php';
            if (\is_file($svc)) { require_once $svc; }
        }
        if (!\function_exists('umrah_traveller_add')) {
            $op = \dirname(__DIR__) . '/umrah/operations.php';
            if (\is_file($op)) { require_once $op; }
        }
        return \function_exists('umrahV2PublishedDepartures');
    }
}

if (!function_exists('App\\lib\\ai\\gia_umrah_abs_image')) {
    /** Absolute-ise a (possibly relative) umrah image path for chat display. */
    function gia_umrah_abs_image(string $img): string
    {
        $img = \trim($img);
        if ($img === '') { return ''; }
        if (\stripos($img, 'http') === 0) { return $img; }
        $base = \defined('root') ? \str_replace('modules/', '', (string) \root) : '/';
        return \rtrim($base, '/') . '/' . \ltrim($img, '/');
    }
}

if (!function_exists('App\\lib\\ai\\gia_umrah_search')) {
    /**
     * List the REAL published umrah departures for GIA, as chat-ready cards.
     * Optional, conservative filters (never hide real inventory on a vague ask):
     *   - city:  match origin_city (e.g. "Kano"); '' or 'any' = all
     *   - month: ONLY filter when the traveller explicitly named a FUTURE month
     *            ("December 2026"); a defaulted/past month is ignored (show all).
     *   - pax:   total pilgrims; price shown = unit x pax when given, else the
     *            per-pilgrim "from" price.
     *
     * Card id = departure_tier_id (the bookable product). Reads
     * umrahV2PublishedDepartures($db) DIRECTLY — the same source the website uses.
     *
     * @return array{ok:bool, cards:array<int,array<string,mixed>>, message?:string}
     */
    function gia_umrah_search($db, array $opts = []): array
    {
        if (!gia_umrah_boot($db)) {
            \error_log('[gia_umrah] v2 engine not loadable');
            return ['ok' => false, 'cards' => [], 'message' => 'Umrah is temporarily unavailable.'];
        }

        $city       = \strtolower(\trim((string) ($opts['city'] ?? '')));
        $monthYmd   = \trim((string) ($opts['month_ymd'] ?? ''));   // YYYY-MM-DD if the user named a month
        $monthGiven = !empty($opts['month_explicit']);
        $pax        = \max(0, (int) ($opts['pax'] ?? 0));
        $limit      = (int) ($opts['limit'] ?? 0); // 0 = no cap

        try {
            $departures = \umrahV2PublishedDepartures($db);
        } catch (\Throwable $e) {
            \error_log('[gia_umrah] departures threw: ' . $e->getMessage());
            return ['ok' => false, 'cards' => [], 'message' => 'Could not load umrah departures.'];
        }
        if (!\is_array($departures) || $departures === []) {
            // Honest empty — never fabricate. (Diagnostic so an operator can see why.)
            \error_log('[gia_umrah] umrahV2PublishedDepartures returned empty');
            return ['ok' => true, 'cards' => [], 'message' => 'No umrah departures are open right now.'];
        }

        // Month needle only when the traveller explicitly named a future month.
        $monthNeedle = '';
        if ($monthGiven && $monthYmd !== '' && \strtotime($monthYmd) !== false) {
            if (\strtotime(\date('Y-m-01', \strtotime($monthYmd))) >= \strtotime(\date('Y-m-01'))) {
                $monthNeedle = \strtolower(\date('F Y', \strtotime($monthYmd)));
            }
        }

        $cards = [];
        foreach ($departures as $d) {
            $origin = (string) ($d['origin_city'] ?? '');
            $bucket = (string) ($d['month_bucket'] ?? '');

            if ($city !== '' && $city !== 'any' && \stripos($origin, $city) === false) { continue; }
            if ($monthNeedle !== '' && \stripos($bucket, $monthNeedle) === false) { continue; }

            $dep = (string) ($d['departure_date'] ?? '');
            $ret = (string) ($d['return_date'] ?? '');
            $days = 0;
            if ($dep !== '' && $ret !== '' && \strtotime($dep) && \strtotime($ret)) {
                $days = \max(0, (int) \round((\strtotime($ret) - \strtotime($dep)) / 86400));
            }
            $unit = \round((float) ($d['unit_price'] ?? 0), 2);
            $currency = \strtoupper((string) ($d['currency'] ?? 'NGN'));
            $total = $pax > 0 ? \round($unit * $pax, 2) : $unit;

            $cityLabel = $origin !== '' ? $origin : 'Kano';
            $when = ($dep !== '' && \strtotime($dep))
                ? \date('j M', \strtotime($dep)) . ($ret && \strtotime($ret) ? ' → ' . \date('j M Y', \strtotime($ret)) : '')
                : $bucket;
            $name = $cityLabel . ($days > 0 ? ' · ' . $days . '-day Umrah' : ' Umrah');

            $cards[] = [
                // The bookable unit: a departure+tier. GIA picks the departure first
                // (this card), then its tiers (gia_umrah_tiers).
                'id'                => (string) ($d['departure_id'] ?? ''),
                'departure_id'      => (int) ($d['departure_id'] ?? 0),
                'departure_tier_id' => (int) ($d['departure_tier_id'] ?? 0), // cheapest "from" tier
                'kind'              => 'umrah',
                'name'              => $name,
                'title'             => $name,
                'subtitle'          => \trim($cityLabel . ' · ' . $when . ' · from ' . (string) ($d['tier_label'] ?? 'Standard')),
                'location'          => $cityLabel,
                'days'              => $days,
                'departure_date'    => $dep,
                'return_date'       => $ret,
                'month_bucket'      => $bucket,
                'tier_label'        => (string) ($d['tier_label'] ?? ''),
                'tier_count'        => (int) ($d['tier_count'] ?? 1),
                'pax'               => $pax,
                'price'             => $total,
                'unit_price'        => $unit,
                'price_min'         => \round((float) ($d['price_min'] ?? $unit), 2),
                'price_max'         => \round((float) ($d['price_max'] ?? $unit), 2),
                'currency'          => $currency,
                'availability'      => (string) ($d['availability'] ?? 'available'),
                'image'             => gia_umrah_abs_image((string) ($d['hero_image'] ?? '')),
                'slug'              => (string) ($d['slug'] ?? ''),
            ];

            if ($limit > 0 && \count($cards) >= $limit) { break; }
        }

        return ['ok' => true, 'cards' => $cards];
    }
}

if (!function_exists('App\\lib\\ai\\gia_umrah_tiers')) {
    /**
     * The bookable tiers (Standard / VIP / Deluxe …) + real prices for ONE
     * departure. GIA shows these after the traveller picks a departure. Reads the
     * SAME v2 tables the web detail page reads (umrah_departure_tiers ->
     * umrah_price_resolve -> umrah_tiers label), returning ONLY priced + bookable
     * tiers. Each returned row's departure_tier_id is the real product to quote/book.
     *
     * @param int $pax total pilgrims; row 'price' = unit x pax when given, else unit.
     * @return array{ok:bool, departure:array, tiers:array<int,array<string,mixed>>, message?:string}
     */
    function gia_umrah_tiers($db, int $departureId, int $pax = 0): array
    {
        if (!gia_umrah_boot($db)) {
            return ['ok' => false, 'tiers' => [], 'departure' => [], 'message' => 'Umrah is temporarily unavailable.'];
        }
        if ($departureId <= 0) {
            return ['ok' => false, 'tiers' => [], 'departure' => [], 'message' => 'Which departure would you like?'];
        }

        try {
            // The departure must be published + not archived (security: never price a
            // draft/closed one). Mirrors the public gate.
            $dep = $db->get('umrah_departures', '*', ['id' => $departureId, 'status' => 'published', 'archived' => 0]);
            if (!\is_array($dep)) {
                return ['ok' => false, 'tiers' => [], 'departure' => [], 'message' => 'That departure is not open for booking.'];
            }
            $rows = $db->select('umrah_departure_tiers', '*', [
                'departure_id' => $departureId, 'status' => 'active',
            ]) ?: [];

            // Build the tier list EXACTLY as the working web detail page does
            // (app/routes/umrah/umrahV2Routes.php:150-168): a tier is shown as long
            // as it is priced (unit > 0). We DON'T add a per-tier bookable gate (the
            // departure-level published+not-archived check already ran above), and we
            // DON'T drop a tier on zero remaining — we mark it sold_out, like the web.
            // (The earlier version dropped every tier when capacity wasn't seeded,
            //  which is why "no tiers came through" for a real, priced departure.)
            $tiers = [];
            foreach ($rows as $dt) {
                $priced = \function_exists('umrah_price_resolve') ? \umrah_price_resolve($db, $dt) : ['unit' => 0];
                $unit = (float) ($priced['unit'] ?? 0);
                if ($unit <= 0) { continue; } // not priced for instant booking -> skip
                $tier = $db->get('umrah_tiers', ['code', 'name', 'public_label', 'room_sharing', 'sort_order'], ['id' => (int) $dt['tier_id']]);
                $cap  = \function_exists('umrah_capacity_for') ? \umrah_capacity_for($db, (int) $dt['id']) : ['remaining' => 0];
                $remaining = (int) ($cap['remaining'] ?? 0);

                $incl = \json_decode((string) ($dt['inclusions'] ?? ''), true);
                $tiers[] = [
                    'departure_tier_id' => (int) $dt['id'],          // THE bookable product
                    'tier_code'         => (string) ($tier['code'] ?? 'standard'),
                    'tier_label'        => (string) ($tier['public_label'] ?: ($tier['name'] ?? 'Standard')),
                    'room_sharing'      => (string) ($tier['room_sharing'] ?? ''),
                    'sort_order'        => (int) ($tier['sort_order'] ?? 0),
                    'unit_price'        => \round($unit, 2),
                    'price'             => $pax > 0 ? \round($unit * $pax, 2) : \round($unit, 2),
                    'currency'          => (string) ($priced['currency'] ?? 'NGN'),
                    'regular'           => $priced['promo']['regular'] ?? null,
                    'availability'      => ($remaining <= 0) ? 'sold_out'
                                            : (($remaining <= 10) ? 'limited' : 'available'),
                    'inclusions'        => \is_array($incl) ? $incl : [],
                ];
            }
            \usort($tiers, fn($a, $b) => $a['sort_order'] <=> $b['sort_order']);

            $dep0 = (string) ($dep['departure_date'] ?? '');
            $ret0 = (string) ($dep['return_date'] ?? '');
            $days = ($dep0 && $ret0 && \strtotime($dep0) && \strtotime($ret0))
                ? \max(0, (int) \round((\strtotime($ret0) - \strtotime($dep0)) / 86400)) : 0;

            return [
                'ok' => true,
                'departure' => [
                    'departure_id' => $departureId,
                    'origin_city'  => (string) ($dep['origin_city'] ?: 'Kano'),
                    'departure_date' => $dep0,
                    'return_date'  => $ret0,
                    'days'         => $days,
                    'pax'          => $pax,
                ],
                'tiers' => $tiers,
            ];
        } catch (\Throwable $e) {
            \error_log('[gia_umrah] tiers threw: ' . $e->getMessage());
            return ['ok' => false, 'tiers' => [], 'departure' => [], 'message' => 'Could not load the tiers for that departure.'];
        }
    }
}

if (!function_exists('App\\lib\\ai\\gia_umrah_quote')) {
    /**
     * Freeze a server-authoritative price for a chosen tier + pilgrim count. Thin,
     * honest wrapper over the shared umrah_price_quote (the booking engine the web
     * also uses). Returns the quote (quote_ref + totals + expiry) exactly as the
     * engine produced it — GIA never computes or trusts its own price.
     *
     * @return array umrah_price_quote's result verbatim
     *               (ok, quote_ref, quote_id, pax, unit_price, total_price,
     *                amount_due_now, currency, expires_at) or ['ok'=>false,message].
     */
    function gia_umrah_quote($db, int $departureTierId, int $pax): array
    {
        if (!gia_umrah_boot($db) || !\function_exists('umrah_price_quote')) {
            return ['ok' => false, 'message' => 'Umrah pricing is temporarily unavailable.'];
        }
        if ($departureTierId <= 0) { return ['ok' => false, 'message' => 'Please choose a tier first.']; }
        if ($pax < 1) { $pax = 1; }
        try {
            return \umrah_price_quote($db, $departureTierId, $pax, gia_umrah_expiry_minutes($db));
        } catch (\Throwable $e) {
            \error_log('[gia_umrah] quote threw: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not price that option. Please try again.'];
        }
    }
}

if (!function_exists('App\\lib\\ai\\gia_umrah_book')) {
    /**
     * Create a REAL held (unpaid) umrah booking for a chosen tier + pilgrims + lead
     * contact. Runs the engine's own sequence: fresh quote -> hold -> booking_create.
     * The booking starts held/unpaid with an invoice; pilgrim passports are then
     * collected, and only then is payment offered. GIA never moves money here.
     *
     * @param array $lead ['first_name','last_name','email','phone','user_id'?]
     * @param string $planCode payment plan (umrah_payment_plans.code); '' = default
     * @return array ok + invoice_id/booking_ref/umrah_booking_id/pax/total_price/
     *               amount_due_now/currency/installments, or ['ok'=>false,message].
     */
    function gia_umrah_book($db, int $departureTierId, int $pax, array $lead, string $planCode = ''): array
    {
        if (!gia_umrah_boot($db)
            || !\function_exists('umrah_price_quote')
            || !\function_exists('umrah_hold_create')
            || !\function_exists('umrah_booking_create')) {
            return ['ok' => false, 'message' => 'Umrah booking is temporarily unavailable.'];
        }
        if ($departureTierId <= 0) { return ['ok' => false, 'message' => 'Please choose a tier first.']; }
        if ($pax < 1) { $pax = 1; }

        try {
            $mins = gia_umrah_expiry_minutes($db);
            // 1) fresh quote (server-authoritative; re-checks the tier is bookable).
            $q = \umrah_price_quote($db, $departureTierId, $pax, $mins);
            if (empty($q['ok'])) {
                return ['ok' => false, 'message' => (string) ($q['message'] ?? 'This departure is no longer available.')];
            }
            // 2) reserve seats atomically.
            $userId = (string) ($lead['user_id'] ?? '');
            $hold = \umrah_hold_create($db, $departureTierId, $pax, (int) ($q['quote_id'] ?? 0), $userId !== '' ? $userId : null, null, $mins);
            if (empty($hold['ok'])) {
                return ['ok' => false, 'message' => (string) ($hold['message'] ?? 'Those seats are no longer available.')];
            }
            // 3) choose a REAL, active payment plan (never invent one).
            $planCode = \trim($planCode);
            if ($planCode === '' || !$db->get('umrah_payment_plans', 'id', ['code' => $planCode, 'active' => 1])) {
                $planCode = (string) ($db->get('umrah_payment_plans', 'code', ['active' => 1, 'ORDER' => ['deposit_percent' => 'DESC']]) ?: 'PP-50-25-25');
            }
            // 4) create the real booking (writes the booking + installments; re-prices
            //    the actual booker server-side; returns the invoice).
            $leadArr = [
                'first_name' => (string) ($lead['first_name'] ?? ''),
                'last_name'  => (string) ($lead['last_name'] ?? ''),
                'email'      => (string) ($lead['email'] ?? ''),
                'phone'      => (string) ($lead['phone'] ?? ''),
                'user_id'    => $userId,
            ];
            $res = \umrah_booking_create($db, (string) $q['quote_ref'], (int) $hold['hold_id'], $leadArr, $planCode);
            if (empty($res['ok'])) {
                return ['ok' => false, 'message' => (string) ($res['message'] ?? 'Could not create the umrah booking.')];
            }
            return [
                'ok'               => true,
                'invoice_id'       => (string) ($res['invoice_id'] ?? ''),
                'booking_ref'      => (string) ($res['booking_ref'] ?? ''),
                'umrah_booking_id' => (int) ($res['umrah_booking_id'] ?? 0),
                'pax'              => $pax,
                'total_price'      => (float) ($res['total_price'] ?? 0),
                'amount_due_now'   => (float) ($res['amount_due_now'] ?? ($res['total_price'] ?? 0)),
                'currency'         => (string) ($q['currency'] ?? 'NGN'),
                'installments'     => \is_array($res['installments'] ?? null) ? $res['installments'] : [],
                'plan_code'        => $planCode,
                'needs_travellers' => true, // collect pilgrim passports before pay
            ];
        } catch (\Throwable $e) {
            \error_log('[gia_umrah] book threw: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not create the umrah booking. Please try again.'];
        }
    }
}

if (!function_exists('App\\lib\\ai\\gia_umrah_payment_plans')) {
    /**
     * The REAL active payment plans a traveller can choose (umrah_payment_plans).
     * Returned for GIA to present; never invented.
     * @return array<int,array{code:string,name:string,deposit_percent:float}>
     */
    function gia_umrah_payment_plans($db): array
    {
        try {
            $rows = $db->select('umrah_payment_plans', ['code', 'name', 'deposit_percent'],
                ['active' => 1, 'ORDER' => ['deposit_percent' => 'DESC']]) ?: [];
            $out = [];
            foreach ($rows as $p) {
                $out[] = [
                    'code' => (string) ($p['code'] ?? ''),
                    'name' => (string) ($p['name'] ?? ''),
                    'deposit_percent' => (float) ($p['deposit_percent'] ?? 100),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            \error_log('[gia_umrah] payment_plans: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('App\\lib\\ai\\gia_umrah_expiry_minutes')) {
    /**
     * The booking-expiry window for umrah, in minutes. Default 20, but the admin
     * can set a per-service expiry in GIA settings (settings.gia_channels_config
     * or a dedicated gia settings blob). Reads it if present; else 20.
     */
    function gia_umrah_expiry_minutes($db): int
    {
        $default = 20;
        try {
            if (\function_exists('settingsHasColumn') && \settingsHasColumn($db, 'gia_service_expiry')) {
                $raw = $db->get('settings', 'gia_service_expiry', ['id' => 1]);
                $cfg = \is_string($raw) ? \json_decode($raw, true) : (\is_array($raw) ? $raw : null);
                if (\is_array($cfg) && isset($cfg['umrah']) && (int) $cfg['umrah'] > 0) {
                    return \max(1, \min(1440, (int) $cfg['umrah']));
                }
            }
        } catch (\Throwable $e) { /* fall back to default */ }
        return $default;
    }
}

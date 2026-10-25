<?php
// ============================================================================
// FILE: app/lib/ai/gia_stays.php
// GIA's OWN dedicated STAYS (hotels) flow. GIA is NOT the website and NOT the
// mobile app — so stays on GIA do NOT ride the shared web search service
// (aiSearchService) with its browser assumptions (silent default check-in/out
// dates, $_SESSION hotel_nationality). This module is the single home for
// everything GIA does with stays: find properties, show real priced rooms for
// the traveller's dates, hold + book, pay.
//
//   service  = stays
//   provider = the operator's OWN hotel inventory (local stays tables)
//   product  = a room-option for specific dates (stayroom:<stay>:<room>:<option>)
//
// It CALLS the shared stays business/inventory engine READ-ONLY (the same
// functions the website uses) but NEVER edits them and NEVER touches any web
// route/view. Verified contracts (read from real code, app/lib/stays_inventory.php):
//   - stays_list_room_options($db,$stayId,$checkin,$checkout,$pax) -> rooms+prices
//   - stays_recompute_net(...) -> authoritative net price
//   - stays_hold_create(...,$ref) -> all-or-nothing per-night hold (ref=invoiceId)
//   - stays_on_paid($db,$invoiceId) -> consumes the hold + accrues (GIA settle calls it)
//   - stays_on_cancelled($db,$invoiceId) -> releases the hold (GIA cancel/expiry calls it)
// DATES ARE REQUIRED (not defaulted) — the chat collects them, like flights
// collect passengers, before any booking.
// ============================================================================

namespace App\lib\ai;

if (!function_exists('App\\lib\\ai\\gia_stays_boot')) {
    /** Ensure the shared stays inventory engine is loaded. */
    function gia_stays_boot($db): bool
    {
        if (!\function_exists('stays_list_room_options')) {
            $f = \dirname(__DIR__, 2) . '/lib/stays_inventory.php';
            if (\is_file($f)) { require_once $f; }
        }
        return \function_exists('stays_list_room_options');
    }
}

if (!function_exists('App\\lib\\ai\\gia_stays_abs_image')) {
    /** Absolute-ise a (possibly relative) stays image path for chat display. */
    function gia_stays_abs_image(string $img): string
    {
        $img = \trim($img);
        if ($img === '') { return ''; }
        if (\stripos($img, 'http') === 0) { return $img; }
        $base = \defined('root') ? \rtrim((string) \root, '/') : '';
        return $base . '/' . \ltrim($img, '/');
    }
}

if (!function_exists('App\\lib\\ai\\gia_stays_search')) {
    /**
     * Find the operator's OWN published properties in a city, as chat-ready cards.
     * Queries the `stays` table directly (status=1, location LIKE city) — this
     * deliberately AVOIDS aiSearchService so no browser-session nationality/currency
     * assumption leaks in. Rooms + prices come at the next step (gia_stays_rooms),
     * which needs the traveller's real dates.
     *
     * @param array $opts city (required to narrow), limit
     * @return array{ok:bool, cards:array<int,array<string,mixed>>, message?:string}
     */
    function gia_stays_search($db, array $opts = []): array
    {
        if (!gia_stays_boot($db)) {
            return ['ok' => false, 'cards' => [], 'message' => 'Hotel search is temporarily unavailable.'];
        }
        $city  = \trim((string) ($opts['city'] ?? ''));
        $limit = (int) ($opts['limit'] ?? 8); if ($limit <= 0) { $limit = 8; }

        $where = ['status' => 1, 'ORDER' => ['featured' => 'DESC', 'hotel_order' => 'ASC', 'rating' => 'DESC'], 'LIMIT' => $limit];
        if ($city !== '') {
            // Match the city against the free-text location/address. '[~]' = LIKE.
            $where['OR'] = ['location[~]' => $city, 'address[~]' => $city, 'name[~]' => $city];
        }
        try {
            $rows = $db->select('stays', ['id', 'name', 'slug', 'stars', 'rating', 'location', 'img', 'currency'], $where) ?: [];
        } catch (\Throwable $e) {
            \error_log('[gia_stays] search: ' . $e->getMessage());
            return ['ok' => false, 'cards' => [], 'message' => 'Could not search hotels right now.'];
        }
        if ($rows === []) {
            return ['ok' => true, 'cards' => [], 'message' => $city !== ''
                ? 'No properties found in ' . $city . ' right now.'
                : 'No properties are published right now.'];
        }

        $cards = [];
        foreach ($rows as $r) {
            $stayId = (int) ($r['id'] ?? 0);
            if ($stayId <= 0) { continue; }
            $name  = (string) ($r['name'] ?? 'Hotel');
            $stars = (int) ($r['stars'] ?? 0);
            $loc   = (string) ($r['location'] ?? '');
            $sub   = \trim(($stars > 0 ? $stars . '★ · ' : '') . $loc);
            $cards[] = [
                'id'       => (string) $stayId,
                'stay_id'  => $stayId,
                'kind'     => 'stays',
                'module'   => 'stays',
                'name'     => $name,
                'title'    => $name,
                'subtitle' => $sub,
                'location' => $loc,
                'stars'    => $stars,
                'rating'   => (float) ($r['rating'] ?? 0),
                'currency' => \strtoupper((string) ($r['currency'] ?? '')),
                'image'    => gia_stays_abs_image((string) ($r['img'] ?? '')),
                'slug'     => (string) ($r['slug'] ?? ''),
                // No price here — rooms are priced per the traveller's dates next.
                'needs_dates' => true,
            ];
        }
        return ['ok' => true, 'cards' => $cards];
    }
}

if (!function_exists('App\\lib\\ai\\gia_stays_rooms')) {
    /**
     * Show the bookable ROOMS + REAL prices for ONE property over the traveller's
     * dates. REQUIRES real check-in/check-out (and pax) — unlike the shared
     * stays_list_room_options, which silently defaults dates for the web form, GIA
     * must have asked for them, so we refuse rather than price arbitrary dates.
     *
     * @return array{ok:bool, stay_id:int, currency:string, nights:int,
     *                rooms:array<int,array<string,mixed>>, message?:string, need_dates?:bool}
     */
    function gia_stays_rooms($db, int $stayId, string $checkin, string $checkout, int $pax = 0): array
    {
        if (!gia_stays_boot($db)) {
            return ['ok' => false, 'rooms' => [], 'message' => 'Hotel rooms are temporarily unavailable.'];
        }
        if ($stayId <= 0) {
            return ['ok' => false, 'rooms' => [], 'message' => 'Which property would you like?'];
        }
        // ENFORCE real dates (kill the web silent-default coupling).
        $checkin  = \trim($checkin);
        $checkout = \trim($checkout);
        if ($checkin === '' || \strtotime($checkin) === false
            || $checkout === '' || \strtotime($checkout) === false
            || \strtotime($checkout) <= \strtotime($checkin)) {
            return ['ok' => false, 'rooms' => [], 'need_dates' => true,
                'message' => 'What are your check-in and check-out dates? I need them to show real room prices.'];
        }

        try {
            $res = \stays_list_room_options($db, $stayId, $checkin, $checkout, \max(0, $pax));
        } catch (\Throwable $e) {
            \error_log('[gia_stays] rooms: ' . $e->getMessage());
            return ['ok' => false, 'rooms' => [], 'message' => 'Could not load rooms for that property.'];
        }
        if (empty($res['ok'])) {
            return ['ok' => false, 'rooms' => [], 'message' => (string) ($res['message'] ?? 'That property is not available.')];
        }

        // Flatten rooms -> a list of pickable room-options, each carrying the canonical
        // stayroom:<stay>:<room>:<option> ref (the same ref the web/channel use).
        $rooms = [];
        foreach (($res['rooms'] ?? []) as $rm) {
            if (!\is_array($rm)) { continue; }
            $roomId   = (int) ($rm['room_id'] ?? 0);
            $roomName = (string) ($rm['room_name'] ?? 'Room');
            $roomImg  = (string) ($rm['image'] ?? '');
            foreach (($rm['options'] ?? []) as $o) {
                if (!\is_array($o)) { continue; }
                $optId = (int) ($o['option_id'] ?? 0);
                if ($optId <= 0) { continue; }
                $rooms[] = [
                    'ref'               => 'stayroom:' . $stayId . ':' . $roomId . ':' . $optId,
                    'stay_id'           => $stayId,
                    'room_id'           => $roomId,
                    'option_id'         => $optId,
                    'room_name'         => $roomName,
                    'board'             => (string) ($o['board'] ?? ''),
                    'breakfast'         => !empty($o['breakfast']),
                    'refundable'        => !empty($o['refundable']),
                    'free_cancellation' => !empty($o['free_cancellation']),
                    'available'         => (int) ($o['available'] ?? 0),
                    'price'             => \round((float) ($o['price'] ?? 0), 2),
                    'currency'          => \strtoupper((string) ($o['currency'] ?? ($res['currency'] ?? ''))),
                    'image'             => $roomImg,
                ];
            }
        }

        return [
            'ok'       => true,
            'stay_id'  => $stayId,
            'currency' => \strtoupper((string) ($res['currency'] ?? '')),
            'nights'   => (int) ($res['nights'] ?? 0),
            'checkin'  => $checkin,
            'checkout' => $checkout,
            'rooms'    => $rooms,
        ];
    }
}

if (!function_exists('App\\lib\\ai\\gia_stays_expiry_minutes')) {
    /** Per-service booking-expiry window for stays (settings.gia_service_expiry; default 20). */
    function gia_stays_expiry_minutes($db): int
    {
        $default = 20;
        try {
            if (\function_exists('settingsHasColumn') && \settingsHasColumn($db, 'gia_service_expiry')) {
                $raw = $db->get('settings', 'gia_service_expiry', ['id' => 1]);
                $cfg = \is_string($raw) ? \json_decode($raw, true) : (\is_array($raw) ? $raw : null);
                if (\is_array($cfg) && isset($cfg['stays']) && (int) $cfg['stays'] > 0) {
                    return \max(1, \min(1440, (int) $cfg['stays']));
                }
            }
        } catch (\Throwable $e) { /* default */ }
        return $default;
    }
}

if (!function_exists('App\\lib\\ai\\gia_stays_book')) {
    /**
     * Create a HELD, UNPAID stays booking for a chosen room-option + real dates.
     * REQUIRES dates (refuses otherwise). Runs: authoritative reprice ->
     * stays_hold_create(ref = invoiceId) so the EXISTING settle path
     * (stays_on_paid -> stays_hold_consume_or_reserve) consumes the exact hold on
     * payment -> insert the ai_trip booking the web stays invoice/settle reads.
     * No guest passport/issue step — payment confirms a stay.
     *
     * @param array $sel  ['stay_id','room_id','option_id','qty'?]
     * @param array $lead ['first_name','last_name','email','phone','user_id'?,'conv_key'?]
     * @return array ok + invoice_id/stay_id/amount/currency/nights/checkin/checkout, or ['ok'=>false,message]
     */
    function gia_stays_book($db, array $sel, string $checkin, string $checkout, array $lead, int $pax = 1): array
    {
        if (!gia_stays_boot($db) || !\function_exists('stays_recompute_net') || !\function_exists('stays_hold_create')) {
            return ['ok' => false, 'message' => 'Hotel booking is temporarily unavailable.'];
        }
        $stayId = (int) ($sel['stay_id'] ?? 0);
        $roomId = (int) ($sel['room_id'] ?? 0);
        $optId  = (int) ($sel['option_id'] ?? 0);
        $qty    = \max(1, (int) ($sel['qty'] ?? 1));
        if ($stayId <= 0 || $roomId <= 0 || $optId <= 0) {
            return ['ok' => false, 'message' => 'Please pick a room first.'];
        }
        $checkin  = \trim($checkin);
        $checkout = \trim($checkout);
        if ($checkin === '' || \strtotime($checkin) === false
            || $checkout === '' || \strtotime($checkout) === false
            || \strtotime($checkout) <= \strtotime($checkin)) {
            return ['ok' => false, 'need_dates' => true, 'message' => 'I need valid check-in and check-out dates to book.'];
        }

        $selectedRooms = [['room_id' => $roomId, 'option_id' => $optId, 'quantity' => $qty]];

        // 1) Authoritative price (server truth — GIA never trusts a model price).
        try {
            $price = \stays_recompute_net($db, $stayId, $selectedRooms, $checkin, $checkout, '');
        } catch (\Throwable $e) {
            \error_log('[gia_stays] reprice: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not price that room. Please try again.'];
        }
        if (empty($price['ok']) || (float) ($price['net'] ?? 0) <= 0) {
            return ['ok' => false, 'message' => 'That room is no longer available for those dates. Please pick another.'];
        }
        $amount   = \round((float) $price['net'], 2);
        $currency = \strtoupper((string) ($price['hotel_currency'] ?? ''));

        // 2) Invoice id (AITP convention, same as the web stays cart booking).
        try { $invoiceId = 'AITP' . \strtoupper(\bin2hex(\random_bytes(5))); }
        catch (\Throwable $e) { $invoiceId = 'AITP' . \strtoupper(\substr(\md5((string) \mt_rand()), 0, 10)); }

        // 3) Hold the inventory with ref = invoiceId, so stays_on_paid consumes THIS
        //    exact hold at settle (and stays_on_cancelled releases it on expiry).
        try {
            $hold = \stays_hold_create($db, $stayId, $roomId, $optId, $checkin, $checkout, $qty, $invoiceId, gia_stays_expiry_minutes($db));
        } catch (\Throwable $e) {
            \error_log('[gia_stays] hold: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Could not hold that room. Please try again.'];
        }
        if (empty($hold['ok'])) {
            return ['ok' => false, 'message' => (string) ($hold['message'] ?? 'That room just sold out for those dates. Please pick another.')];
        }

        // 4) Insert the ai_trip booking in the exact shape the web stays invoice +
        //    stays_on_paid read (booking_data.selected_rooms + stay_id + dates).
        $firstName = (string) ($lead['first_name'] ?? '');
        $lastName  = (string) ($lead['last_name'] ?? '');
        $email     = (string) ($lead['email'] ?? '');
        $phone     = (string) ($lead['phone'] ?? '');
        $userId    = (string) ($lead['user_id'] ?? '');

        // GA3: prefill blank lead contact from the signed-in user's record.
        if ($userId !== '' && ($email === '' || $phone === '')) {
            if (!function_exists('gia_contact_prefill')) { require_once __DIR__ . '/gia_travellers.php'; }
            if (function_exists('gia_contact_prefill')) { [$email, $phone] = gia_contact_prefill($db, $userId, $email, $phone); }
        }

        $bookingData = [
            'source'           => 'gia_chat',
            'gia_service'      => 'stays',
            'gia_conv_key'     => (string) ($lead['conv_key'] ?? ''),
            'package_id'       => $invoiceId,
            'items'            => [[
                'module' => 'stays', 'kind' => 'stays',
                'price'  => $amount, 'currency' => $currency,
                'item'   => ['stay_id' => $stayId, 'selected_rooms' => $selectedRooms, 'checkin' => $checkin, 'checkout' => $checkout],
            ]],
            'items_count'      => 1,
            'payable_total'    => $amount,
            'final_total'      => $amount,
            'display_total'    => $amount,
            'base_currency'    => $currency,
            'display_currency' => $currency,
            // Top-level where the web stays invoice + stays_on_paid read them.
            'selected_rooms'   => $selectedRooms,
            'hotel_id'         => $stayId,
            'stay_id'          => $stayId,
            'checkin'          => $checkin,
            'checkout'         => $checkout,
            'guest'            => ['first_name' => $firstName, 'last_name' => $lastName, 'email' => $email, 'phone' => $phone],
        ];

        try {
            $ok = $db->insert('bookings', [
                'invoice_id'      => $invoiceId,
                'language'        => \function_exists('getCurrentLanguage') ? \getCurrentLanguage() : 'en',
                'booking_status'  => 'pending',
                'payment_status'  => 'unpaid',
                'price_original'  => $amount,
                'price_markup'    => $amount,
                'agent_earning'   => '0',
                'tax'             => '0',
                'first_name'      => $firstName,
                'last_name'       => $lastName,
                'email'           => $email,
                'phone'           => $phone,
                'adults'          => (string) \max(1, $pax),
                'childs'          => '0',
                'infants'         => '0',
                'child_ages'      => '[]',
                'currency_markup' => $currency,
                'booking_data'    => \json_encode($bookingData),
                'transaction_id'  => '',
                'user_id'         => $userId,
                'travellers'      => \json_encode(['primary_guest' => $bookingData['guest']]),
                'nationality'     => '',
                'payment_gateway' => '',
                'module_type'     => 'ai_trip',
                'module'          => 'ai_trip',
                'pnr'             => '',
                'booking_date'    => \date('Y-m-d H:i:s'),
                'created_at'      => \date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            \error_log('[gia_stays] book insert: ' . $e->getMessage());
            // Release the hold we just took so the night isn't stranded.
            if (\function_exists('stays_hold_release')) { try { \stays_hold_release($db, $invoiceId); } catch (\Throwable $e2) {} }
            return ['ok' => false, 'message' => 'Could not create the booking. Please try again.'];
        }
        if (!$ok) {
            if (\function_exists('stays_hold_release')) { try { \stays_hold_release($db, $invoiceId); } catch (\Throwable $e2) {} }
            return ['ok' => false, 'message' => 'Could not create the booking. Please try again.'];
        }

        $nights = \function_exists('stays_date_range') ? \count(\stays_date_range($checkin, $checkout)) : \max(1, (int) \round((\strtotime($checkout) - \strtotime($checkin)) / 86400));
        return [
            'ok'             => true,
            'invoice_id'     => $invoiceId,
            'module'         => 'stays',
            'stay_id'        => $stayId,
            'amount'         => $amount,
            'amount_due_now' => $amount, // stays pay in full
            'currency'       => $currency,
            'nights'         => $nights,
            'checkin'        => $checkin,
            'checkout'       => $checkout,
            'needs_payment'  => true, // no passengers/passports — go straight to pay
        ];
    }
}

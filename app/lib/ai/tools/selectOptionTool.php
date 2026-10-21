<?php

namespace App\lib\ai;

/**
 * GIA tool: turn a chosen search option (a real card the traveller picked) into a
 * saved trip DRAFT that the existing checkout chain consumes.
 *
 * This is the missing link between search_trip (which shows real cards) and
 * reprice_trip → create_checkout → request_booking_approval → initiate_payment
 * (which all operate on a draft_hash in `logs_bookings`). Without it GIA could
 * show packages but never start a booking.
 *
 * SAFETY — no model-supplied pricing ever enters the money path. The tool stores
 * ONLY the chosen option's module + authoritative id (+ pax). The very next step,
 * reprice_trip, re-fetches the live price from the DB (aiTripRevalidate*Item), so
 * the quote the traveller approves is always the platform's own number, never a
 * value the model typed. Risk R2 (reversible controlled-write: it writes a draft
 * row; it holds/charges nothing).
 *
 * Input: { module, option_id, adults?, children?, infants? }. option_id is the
 * `ref`/`id` GIA received in the search options for that card.
 *
 * Draft shape matches POST /api/ai/trip/save-draft exactly (tripBookingRoutes.php)
 * so the rest of the chain is unchanged.
 */
class selectOptionTool implements giaToolInterface
{
    public function name(): string
    {
        return 'select_option';
    }

    public function description(): string
    {
        return 'Select a specific option the traveler chose from the search results '
            . '(by its module and id) and start a booking draft for it. Call this when '
            . 'the traveler says they want a particular package/stay/tour/etc. Returns a '
            . 'draft reference; then call reprice_trip to confirm the live price before '
            . 'creating a checkout. It does not book or charge anything.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'module' => [
                    'type' => 'string',
                    'description' => 'The service of the chosen option: umrah, stays, tours, bus, visa '
                        . '(from search_trip), or flights, cars, rail, ferries, esim (from fetch_live_offers).',
                ],
                'option_id' => [
                    'type' => 'string',
                    'description' => 'The id/ref of the chosen option exactly as shown in search_trip '
                        . 'results (local modules) or fetch_live_offers results (live-supplier modules).',
                ],
                'adults' => ['type' => 'integer', 'description' => 'Number of adults (default 1).'],
                'children' => ['type' => 'integer', 'description' => 'Number of children (default 0).'],
                'infants' => ['type' => 'integer', 'description' => 'Number of infants (default 0).'],
                'checkin' => ['type' => 'string', 'description' => 'Stays only: check-in date YYYY-MM-DD (from the conversation). Needed to price rooms and book.'],
                'checkout' => ['type' => 'string', 'description' => 'Stays only: check-out date YYYY-MM-DD.'],
            ],
            'required' => ['module', 'option_id'],
        ];
    }

    public function riskClass(): string
    {
        return 'R2'; // reversible: creates a draft row; no money moves
    }

    public function run(array $input, array $context): array
    {
        $db = $context['db'] ?? null;
        if ($db === null) {
            return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Selection is unavailable.'];
        }

        $module = strtolower(trim((string) ($input['module'] ?? '')));
        $optionId = trim((string) ($input['option_id'] ?? ''));
        if ($optionId === '') {
            return ['ok' => false, 'error_code' => 'AI_BAD_OPTION', 'message' => 'Tell me which option to set up.'];
        }

        $localModules = ['umrah', 'stays', 'tours', 'bus', 'visa'];
        $liveModules  = ['flights', 'cars', 'rail', 'ferries', 'esim'];
        if (!in_array($module, $localModules, true) && !in_array($module, $liveModules, true)) {
            return ['ok' => false, 'error_code' => 'AI_MODULE_UNKNOWN', 'message' => "I can't set that up here."];
        }

        $adults = max(0, (int) ($input['adults'] ?? 0));
        $children = max(0, (int) ($input['children'] ?? 0));
        $infants = max(0, (int) ($input['infants'] ?? 0));
        if ($adults <= 0) {
            $adults = 1; // sensible default; reprice caps to the option's own max_*
        }
        $currency = (string) ($context['currency'] ?? ($_SESSION['app_currency'] ?? 'USD'));
        if ($currency === '') {
            $currency = 'USD';
        }

        // ============ STAYS: two-step room selection ==========================
        // A hotel has many ROOM TYPES, so a stays selection is 2 steps:
        //  (A) pick a PROPERTY  -> we return its real bookable rooms (so GIA can
        //      show them instead of one shallow card), each with a pick ref
        //      "stayroom|<stay>|<room>|<option>".
        //  (B) pick a ROOM via that ref -> we build the draft with selected_rooms.
        if ($module === 'stays') {
            $checkin  = trim((string) ($input['checkin'] ?? ''));
            $checkout = trim((string) ($input['checkout'] ?? ''));
            $pax = $adults + $children;

            if (!function_exists('stays_list_room_options')) {
                $inv = dirname(__DIR__, 2) . '/stays_inventory.php';
                if (is_file($inv)) { require_once $inv; }
            }

            // (B) A specific room was chosen: "stayroom:<stay>:<room>:<option>[:<qty>]".
            // Colon-delimited (not '|') so it survives as a single select_option ref
            // through the 'pick|<module>|<ref>' tap convention.
            if (preg_match('/^stayroom:(\d+):(\d+):(\d+)(?::(\d+))?$/', $optionId, $sm)) {
                $stayId = (int) $sm[1]; $roomId = (int) $sm[2]; $roomOpt = (int) $sm[3];
                $qty = max(1, (int) ($sm[4] ?? 1));
                // Validate the room/option really exists + is priceable for the dates.
                if (function_exists('stays_list_room_options')) {
                    $rooms = stays_list_room_options($db, $stayId, $checkin, $checkout, $pax);
                    $found = null; $propName = (string) ($rooms['property'] ?? '');
                    foreach (($rooms['rooms'] ?? []) as $rm) {
                        foreach (($rm['options'] ?? []) as $o) {
                            if ((int) $rm['room_id'] === $roomId && (int) $o['option_id'] === $roomOpt) { $found = ['room' => $rm, 'opt' => $o]; break 2; }
                        }
                    }
                    if ($found === null) {
                        return ['ok' => false, 'error_code' => 'AI_ROOM_UNAVAILABLE', 'message' => "That room isn't available for those dates — let me show the current rooms."];
                    }
                    $title = trim($propName . ' — ' . (string) $found['room']['room_name']);
                    $item = [
                        'module' => 'stays', 'kind' => 'stays', 'supplier' => 'stays',
                        'title' => $title, 'price' => 0.0, 'currency' => $currency,
                        'item' => [
                            'id' => (string) $stayId, 'stay_id' => $stayId, 'hotel_id' => $stayId,
                            'checkin' => $rooms['checkin'] ?? $checkin, 'checkout' => $rooms['checkout'] ?? $checkout,
                            'adults' => $adults, 'children' => $children, 'infants' => $infants,
                            'selected_rooms' => [[
                                'room_id' => $roomId, 'option_id' => $roomOpt, 'quantity' => $qty,
                                'option' => ['option_id' => $roomOpt],
                            ]],
                        ],
                        'params' => ['adults' => (string) $adults, 'children' => (string) $children, 'infants' => (string) $infants, 'currency' => $currency],
                    ];
                    $verifiedStay = ['ok' => true, 'id' => $stayId, 'title' => $title, '__item' => $item];
                    return $this->saveDraftAndRespond($db, $module, $verifiedStay, $item, $adults, $children, $infants, $currency, $context);
                }
                return ['ok' => false, 'error_code' => 'AI_NO_ROOMS', 'message' => 'Room selection is unavailable right now.'];
            }

            // (A) A property id was chosen: return its real rooms for the dates.
            if (ctype_digit($optionId) && function_exists('stays_list_room_options')) {
                $rooms = stays_list_room_options($db, (int) $optionId, $checkin, $checkout, $pax);
                if (empty($rooms['ok'])) {
                    return ['ok' => false, 'error_code' => 'AI_NO_ROOMS', 'message' => (string) ($rooms['message'] ?? "I couldn't load rooms for that property.")];
                }
                if (($rooms['rooms'] ?? []) === []) {
                    return ['ok' => true, 'data' => ['stays_rooms' => [], 'property' => $rooms['property'] ?? ''],
                            'message' => 'That property has no rooms available for those dates. Want me to try different dates?'];
                }
                $needDates = ($checkin === '' || $checkout === '');
                return [
                    'ok' => true,
                    'data' => [
                        'stays_rooms' => $rooms['rooms'],
                        'stay_id'     => (int) $optionId,
                        'property'    => $rooms['property'] ?? '',
                        'currency'    => $rooms['currency'] ?? '',
                        'checkin'     => $rooms['checkin'] ?? '',
                        'checkout'    => $rooms['checkout'] ?? '',
                        'nights'      => $rooms['nights'] ?? 1,
                        'need_dates'  => $needDates,
                        'next'        => 'pick_room',
                    ],
                    'message' => 'Here are the room types at ' . ($rooms['property'] ?? 'this property')
                        . ($needDates ? ' (showing a sample 1-night price — give me your check-in/out dates for the exact total). ' : '. ')
                        . 'Ask the traveller which room they want.',
                ];
            }
        }
        // ======================================================================

        $expires = false;
        if (in_array($module, $liveModules, true)) {
            // LIVE-SUPPLIER: the traveller picked an offer fetch_live_offers returned.
            // Its authoritative stable fields were cached server-side by ref — the
            // model never handled the pricing/token. Build the draft item from that.
            // Read the offer fetch_live_offers cached for THIS conversation from the
            // DB (not $_SESSION) so it works on stateless messaging webhooks too.
            $cacheKey = $module . ':' . $optionId;
            $convId = (int) ($context['conv_id'] ?? 0);
            if ($convId > 0 && !function_exists('gia_offer_cache_get')) {
                $crm = dirname(__DIR__) . '/gia_crm.php';
                if (is_file($crm)) { require_once $crm; }
            }
            $cached = ($convId > 0 && function_exists('gia_offer_cache_get'))
                ? gia_offer_cache_get($db, $convId, $cacheKey) : null;
            if ($cached === null) {
                return ['ok' => false, 'error_code' => 'AI_OFFER_EXPIRED', 'message' => "That option isn't in view anymore — let me pull up live options again."];
            }
            $stable = is_array($cached['stable'] ?? null) ? $cached['stable'] : [];
            $expires = (bool) ($cached['expires'] ?? false);
            $title = (string) ($cached['title'] ?? ucfirst($module));
            $supplier = (string) ($cached['supplier'] ?? $module);

            $item = [
                'module'   => $module,
                'kind'     => $module,
                'supplier' => $supplier !== '' ? $supplier : $module,
                'title'    => $title,
                'price'    => 0.0, // reprice re-derives the authoritative price
                'currency' => $currency,
                // The revalidators read ids from item + item.detail + params; we store
                // the full stable set under all three so whichever a module reads is present.
                'item'     => array_merge($stable, ['detail' => $stable]),
                'params'   => array_merge($stable, [
                    'adults'   => (string) $adults,
                    'children' => (string) $children,
                    'infants'  => (string) $infants,
                    'currency' => $currency,
                ]),
            ];
            $verified = ['ok' => true, 'id' => $optionId, 'title' => $title];
        } else {
            // --- LOCAL-DB: verify the option EXISTS + is live; build id-only draft. ---
            $verified = $this->verifyOption($db, $module, $optionId);
            if (!$verified['ok']) {
                return ['ok' => false, 'error_code' => 'AI_OPTION_NOT_FOUND', 'message' => "I couldn't find that option anymore — it may have sold out. Want me to search again?"];
            }
            $item = $this->buildDraftItem($module, $verified, $adults, $children, $infants, $currency);
        }

        return $this->saveDraftAndRespond($db, $module, $verified, $item, $adults, $children, $infants, $currency, $context, $expires);
    }

    /**
     * Persist the single-item draft to logs_bookings and return the standard
     * select_option response (draft_hash + next=reprice_trip). Shared by the
     * generic path and the stays room-pick path so the save logic lives once.
     */
    private function saveDraftAndRespond($db, string $module, array $verified, array $item,
                                         int $adults, int $children, int $infants, string $currency,
                                         array $context, bool $expires = false): array
    {
        $payload = [
            'type'         => 'ai_trip',
            'query'        => '',
            'currency'     => $currency,
            'items'        => [$item],
            'module_order' => [$module],
            'total'        => 0.0, // unknown until reprice; never a model-supplied figure
            'created_at'   => date('Y-m-d H:i:s'),
            'source'       => 'gia_select',
        ];
        $encoded = json_encode($payload);
        if ($encoded === false) {
            return ['ok' => false, 'error_code' => 'AI_DRAFT_ENCODE', 'message' => 'Could not prepare that selection. Please try again.'];
        }

        try {
            $hash = bin2hex(random_bytes(8));
            $ok = $db->insert('logs_bookings', [
                'hash'       => $hash,
                'data'       => $encoded,
                'user_id'    => (string) ($context['account_id'] ?? '') ?: null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            if (!$ok) {
                return ['ok' => false, 'error_code' => 'AI_DRAFT_SAVE', 'message' => 'Could not save your selection. Please try again.'];
            }
        } catch (\Throwable $e) {
            error_log('[gia] select_option save: ' . $e->getMessage());
            return ['ok' => false, 'error_code' => 'AI_DRAFT_SAVE', 'message' => 'Could not save your selection. Please try again.'];
        }

        return [
            'ok' => true,
            'data' => [
                'draft_hash' => $hash,
                'module'     => $module,
                'title'      => (string) $verified['title'],
                'adults'     => $adults,
                'children'   => $children,
                'infants'    => $infants,
                'currency'   => $currency,
                // Explicit signal to the model: price is NOT known yet; reprice next.
                'next'       => 'reprice_trip',
                // Flight offers carry a time-sensitive token — reprice right before approval.
                'time_sensitive' => $expires,
            ],
            'message' => 'Selected "' . $verified['title'] . '". Next: confirm the live price (reprice_trip) before checkout.'
                . ($expires ? ' This fare is time-sensitive, so reprice just before approving.' : ''),
        ];
    }

    /**
     * Confirm the chosen option exists + is live, returning its authoritative id
     * and a display title. Price is deliberately NOT returned/trusted here.
     *
     * @return array{ok:bool, id:int|string, title:string, extra?:array}
     */
    private function verifyOption($db, string $module, string $optionId): array
    {
        try {
            switch ($module) {
                case 'umrah': {
                    // v2: the option id is a departure_tier_id (the bookable unit).
                    // Validate the tier is active AND its departure is open for
                    // booking — never accept a draft/closed/hidden one.
                    $id = (int) $optionId;
                    if ($id <= 0) { return ['ok' => false]; }
                    if (!function_exists('umrah_departure_bookable')) {
                        $svc = dirname(__DIR__, 2) . '/umrah/services.php';
                        if (is_file($svc)) { require_once $svc; }
                    }
                    $dt = $db->get('umrah_departure_tiers', ['id', 'departure_id', 'tier_id', 'status'], ['id' => $id]);
                    if (!$dt || ($dt['status'] ?? '') !== 'active') { return ['ok' => false]; }
                    if (function_exists('umrah_departure_bookable')
                        && !umrah_departure_bookable($db, (int) $dt['departure_id'], $dt)) {
                        return ['ok' => false];
                    }
                    // Build a human title from the real departure + tier.
                    $dep = $db->get('umrah_departures', ['origin_city', 'departure_date'], ['id' => (int) $dt['departure_id']]);
                    $tier = $db->get('umrah_tiers', ['public_label'], ['id' => (int) $dt['tier_id']]);
                    $city = (string) ($dep['origin_city'] ?? 'Kano');
                    $when = (!empty($dep['departure_date']) && strtotime((string) $dep['departure_date']))
                        ? date('j M Y', strtotime((string) $dep['departure_date'])) : '';
                    $title = trim($city . ' Umrah' . ($when !== '' ? ' · ' . $when : '')
                        . (!empty($tier['public_label']) ? ' · ' . $tier['public_label'] : ''));
                    return ['ok' => true, 'id' => $id, 'title' => $title ?: 'Umrah package',
                        'extra' => ['departure_id' => (int) $dt['departure_id'], 'tier_id' => (int) $dt['tier_id']]];
                }
                case 'tours': {
                    $id = (int) $optionId;
                    if ($id <= 0) { return ['ok' => false]; }
                    $row = $db->get('tours', ['id', 'name'], ['id' => $id, 'status' => 1]);
                    if (!$row) { return ['ok' => false]; }
                    return ['ok' => true, 'id' => $id, 'title' => (string) ($row['name'] ?? 'Tour')];
                }
                case 'stays': {
                    $id = (int) $optionId;
                    if ($id <= 0) { return ['ok' => false]; }
                    $row = $db->get('stays', ['id', 'name'], ['id' => $id, 'status' => 1, 'listing_status' => 'approved']);
                    if (!$row) { return ['ok' => false]; }
                    return ['ok' => true, 'id' => $id, 'title' => (string) ($row['name'] ?? 'Hotel')];
                }
                case 'bus': {
                    $id = (int) $optionId;
                    if ($id <= 0) { return ['ok' => false]; }
                    $row = $db->get('bus_routes', ['id', 'bus_id'], ['id' => $id, 'status' => '1']);
                    if (!$row) { return ['ok' => false]; }
                    return ['ok' => true, 'id' => $id, 'title' => 'Bus trip', 'extra' => ['bus_id' => (int) ($row['bus_id'] ?? 0)]];
                }
                case 'visa': {
                    // Visa option_id is a composite (visaId-type-speed) or inquiry; we
                    // cannot cheaply re-verify a numeric row, so accept a non-empty id
                    // and let reprice re-derive (it fails closed if it can't price).
                    return ['ok' => true, 'id' => $optionId, 'title' => 'Visa'];
                }
            }
        } catch (\Throwable $e) {
            error_log('[gia] verifyOption ' . $module . ': ' . $e->getMessage());
        }
        return ['ok' => false];
    }

    /**
     * Build the draft item in the shape each module's revalidator reads: the
     * identifier lives under `item` (umrah_id/id/tour_id/...) plus pax; `params`
     * carries pax too (some revalidators read it there). No price is set.
     */
    private function buildDraftItem(string $module, array $verified, int $adults, int $children, int $infants, string $currency): array
    {
        $id = $verified['id'];
        $extra = is_array($verified['extra'] ?? null) ? $verified['extra'] : [];

        $itemPayload = [
            'id'       => (string) $id,
            'adults'   => $adults,
            'children' => $children,
            'infants'  => $infants,
        ];
        // Module-specific id key the revalidator expects.
        if ($module === 'umrah') {
            // v2: the bookable unit is the departure_tier_id; pax = total pilgrims
            // (v2 prices flat per pilgrim, no A/C/I split). Carry departure_id/tier_id
            // for the revalidator/booking path.
            $itemPayload['departure_tier_id'] = (int) $id;
            $itemPayload['pax'] = max(1, $adults + $children + $infants);
            if (isset($extra['departure_id'])) { $itemPayload['departure_id'] = (int) $extra['departure_id']; }
            if (isset($extra['tier_id'])) { $itemPayload['tier_id'] = (int) $extra['tier_id']; }
        } elseif ($module === 'tours') {
            $itemPayload['tour_id'] = (string) $id;
        } elseif ($module === 'bus') {
            $itemPayload['route_id'] = (int) $id;
            if (!empty($extra['bus_id'])) { $itemPayload['bus_id'] = (int) $extra['bus_id']; }
        }

        return [
            'module'   => $module,
            'kind'     => $module,
            'supplier' => $module,
            'title'    => (string) $verified['title'],
            'price'    => 0.0, // placeholder; reprice_trip fills the authoritative price
            'currency' => $currency,
            'item'     => $itemPayload,
            'params'   => [
                'adults'   => (string) $adults,
                'children' => (string) $children,
                'infants'  => (string) $infants,
                'currency' => $currency,
            ],
        ];
    }
}

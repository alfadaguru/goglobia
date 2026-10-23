<?php
// ============================================================================
// FILE: app/lib/ai/tools/staysTools.php
// GIA's DEDICATED stays (hotels) tools. These are what the GIA agent calls for
// stays — so stays NEVER go through the shared search_trip/aiSearchService web
// pipeline (which silently defaults dates and reads $_SESSION). Each tool is a
// thin, governed wrapper over app/lib/ai/gia_stays.php.
//
// Tools: stays_search (properties in a city) · stays_rooms (real priced rooms for
//        the traveller's DATES) · stays_book (hold + held unpaid booking).
// Dates are REQUIRED before rooms/booking — the chat collects them (the stays
// analogue of umrah passports / flight passengers). No passport/issue step.
//
// The room ref is self-describing: stayroom:<stay>:<room>:<option> — the same ref
// the web/channel use — so no offer cache is needed; stays_book parses it.
//
// Touches NO web/mobile stays code. Reuses the shared stays inventory engine
// READ-ONLY (like a database).
// ============================================================================

namespace App\lib\ai;

require_once __DIR__ . '/../giaToolInterface.php';
require_once __DIR__ . '/../gia_stays.php';

if (!class_exists('App\\lib\\ai\\staysSearchTool')) {
    /** Find the operator's REAL properties in a city (rooms + prices come next). */
    class staysSearchTool implements giaToolInterface
    {
        public function name(): string { return 'stays_search'; }

        public function description(): string
        {
            return 'Find our REAL hotels/properties in a city. Use this when the traveller wants a '
                . 'place to stay. Pass the city they named. Returns property cards (each with a '
                . 'stay_id). Room types and prices come next via stays_rooms, which needs their '
                . 'check-in and check-out dates. Only ever describe properties this tool returns.';
        }

        public function inputSchema(): array
        {
            return [
                'type' => 'object',
                'properties' => [
                    'city' => ['type' => 'string', 'description' => 'The city/area the traveller wants to stay in (e.g. "Dubai", "Lagos").'],
                ],
                'required' => [],
            ];
        }

        public function riskClass(): string { return 'R0'; }

        public function run(array $input, array $context): array
        {
            $db = $context['db'] ?? null;
            if ($db === null) { return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Hotel search is unavailable.']; }
            if (function_exists('gia_trace')) { \gia_trace('stays_search', __FILE__, 'city=' . (string) ($input['city'] ?? '')); }

            $res = gia_stays_search($db, ['city' => (string) ($input['city'] ?? '')]);
            if (empty($res['ok'])) {
                return ['ok' => false, 'error_code' => 'AI_STAYS_SEARCH', 'message' => (string) ($res['message'] ?? 'Could not search hotels.')];
            }
            return [
                'ok' => true,
                'data' => [
                    'module'   => 'stays',
                    'searches' => [['module' => 'stays', 'kind' => 'stays', 'title' => 'Hotels', 'cards' => $res['cards']]],
                    'stays'    => $res['cards'],
                ],
                'message' => $res['cards'] === []
                    ? (string) ($res['message'] ?? 'No properties found there.')
                    : 'Showing ' . count($res['cards']) . ' propert(ies). Ask the traveller which one, AND their check-in + check-out dates, then call stays_rooms.',
            ];
        }
    }
}

if (!class_exists('App\\lib\\ai\\staysRoomsTool')) {
    /** Show real priced rooms for a chosen property over the traveller's dates. */
    class staysRoomsTool implements giaToolInterface
    {
        public function name(): string { return 'stays_rooms'; }

        public function description(): string
        {
            return 'After the traveller picks a property AND gives check-in + check-out dates, show '
                . 'its real rooms with live prices for those dates. Pass stay_id, checkin, checkout '
                . '(YYYY-MM-DD) and the guest count. If dates are missing, ASK for them — never guess '
                . 'dates. Each room carries a ref (stayroom:...) to pass to stays_book.';
        }

        public function inputSchema(): array
        {
            return [
                'type' => 'object',
                'properties' => [
                    'stay_id'  => ['type' => 'integer', 'description' => 'The chosen property id from stays_search.'],
                    'checkin'  => ['type' => 'string', 'description' => 'Check-in date YYYY-MM-DD (required — never invent it).'],
                    'checkout' => ['type' => 'string', 'description' => 'Check-out date YYYY-MM-DD (required).'],
                    'guests'   => ['type' => 'integer', 'description' => 'Total guests (adults + children).'],
                ],
                'required' => ['stay_id'],
            ];
        }

        public function riskClass(): string { return 'R0'; }

        public function run(array $input, array $context): array
        {
            $db = $context['db'] ?? null;
            if ($db === null) { return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Hotel rooms are unavailable.']; }
            if (function_exists('gia_trace')) { \gia_trace('stays_rooms', __FILE__, 'stay_id=' . (int) ($input['stay_id'] ?? 0) . ' ci=' . (string) ($input['checkin'] ?? '') . ' co=' . (string) ($input['checkout'] ?? '')); }

            $res = gia_stays_rooms($db,
                (int) ($input['stay_id'] ?? 0),
                trim((string) ($input['checkin'] ?? '')),
                trim((string) ($input['checkout'] ?? '')),
                (int) ($input['guests'] ?? 0));

            if (empty($res['ok'])) {
                // need_dates is a soft, friendly ask, not a hard error.
                return [
                    'ok' => false,
                    'error_code' => !empty($res['need_dates']) ? 'AI_STAYS_NEED_DATES' : 'AI_STAYS_ROOMS',
                    'message' => (string) ($res['message'] ?? 'Could not load rooms.'),
                ];
            }

            // Present rooms as pickable cards carrying the stayroom: ref.
            $cards = [];
            foreach (($res['rooms'] ?? []) as $rm) {
                if (!is_array($rm)) { continue; }
                $bits = [];
                if (!empty($rm['board'])) { $bits[] = (string) $rm['board']; }
                if (!empty($rm['free_cancellation'])) { $bits[] = 'Free cancellation'; }
                if (($rm['available'] ?? 0) > 0 && (int) $rm['available'] <= 5) { $bits[] = 'Only ' . (int) $rm['available'] . ' left'; }
                $cards[] = [
                    'id'        => (string) ($rm['ref'] ?? ''),
                    'ref'       => (string) ($rm['ref'] ?? ''),
                    'kind'      => 'stays',
                    'module'    => 'stays',
                    'name'      => (string) ($rm['room_name'] ?? 'Room'),
                    'title'     => (string) ($rm['room_name'] ?? 'Room'),
                    'subtitle'  => trim(implode(' · ', $bits)),
                    'price'     => $rm['price'] ?? null,
                    'currency'  => (string) ($rm['currency'] ?? ($res['currency'] ?? '')),
                    'image'     => (string) ($rm['image'] ?? ''),
                ];
            }

            return [
                'ok' => true,
                'data' => [
                    'module'      => 'stays',
                    'stay_id'     => (int) ($res['stay_id'] ?? 0),
                    'checkin'     => (string) ($res['checkin'] ?? ''),
                    'checkout'    => (string) ($res['checkout'] ?? ''),
                    'nights'      => (int) ($res['nights'] ?? 0),
                    'stays_rooms' => $cards,
                    // also render as a pickable 'searches' block for the UIs.
                    'searches'    => [['module' => 'stays', 'kind' => 'stays', 'title' => 'Rooms', 'cards' => $cards]],
                ],
                'message' => $cards === []
                    ? 'No rooms are available for those dates. Offer to try different dates.'
                    : 'Showing ' . count($cards) . ' room(s) for ' . (int) ($res['nights'] ?? 0) . ' night(s). Each has a ref — pass the chosen ref to stays_book with the dates.',
            ];
        }
    }
}

if (!class_exists('App\\lib\\ai\\staysBookTool')) {
    /** Create a held, UNPAID stays booking for a chosen room + dates. */
    class staysBookTool implements giaToolInterface
    {
        public function name(): string { return 'stays_book'; }

        public function description(): string
        {
            return 'Create a hotel booking (held, UNPAID — no charge) for a chosen room over the '
                . 'traveller\'s dates, once they agree to the price. Pass the room ref (stayroom:...) '
                . 'from stays_rooms and the check-in/check-out dates. After this, payment is offered; '
                . 'paying confirms the stay (no ticket to issue).';
        }

        public function inputSchema(): array
        {
            return [
                'type' => 'object',
                'properties' => [
                    'ref'        => ['type' => 'string', 'description' => 'The chosen room ref: stayroom:<stay>:<room>:<option>.'],
                    'checkin'    => ['type' => 'string', 'description' => 'Check-in YYYY-MM-DD.'],
                    'checkout'   => ['type' => 'string', 'description' => 'Check-out YYYY-MM-DD.'],
                    'guests'     => ['type' => 'integer', 'description' => 'Total guests.'],
                    'rooms'      => ['type' => 'integer', 'description' => 'Number of rooms of this type (default 1).'],
                    'first_name' => ['type' => 'string', 'description' => 'Lead guest first name if known.'],
                    'last_name'  => ['type' => 'string', 'description' => 'Lead guest last name if known.'],
                    'email'      => ['type' => 'string', 'description' => 'Lead guest email if known.'],
                    'phone'      => ['type' => 'string', 'description' => 'Lead guest phone if known.'],
                ],
                'required' => ['ref', 'checkin', 'checkout'],
            ];
        }

        public function riskClass(): string { return 'R2'; } // held unpaid booking; reversible; no money

        public function run(array $input, array $context): array
        {
            $db = $context['db'] ?? null;
            if ($db === null) { return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Hotel booking is unavailable.']; }
            if (function_exists('gia_trace')) { \gia_trace('stays_book', __FILE__, 'ref=' . (string) ($input['ref'] ?? '')); }

            // Parse the self-describing room ref: stayroom:<stay>:<room>:<option>.
            $ref = trim((string) ($input['ref'] ?? ''));
            if (!preg_match('/^stayroom:(\d+):(\d+):(\d+)$/', $ref, $m)) {
                return ['ok' => false, 'error_code' => 'AI_STAYS_REF', 'message' => 'Please pick a room from the list first.'];
            }
            $sel = ['stay_id' => (int) $m[1], 'room_id' => (int) $m[2], 'option_id' => (int) $m[3], 'qty' => max(1, (int) ($input['rooms'] ?? 1))];

            $lead = [
                'first_name' => (string) ($input['first_name'] ?? ''),
                'last_name'  => (string) ($input['last_name'] ?? ''),
                'email'      => (string) ($input['email'] ?? ''),
                'phone'      => (string) ($input['phone'] ?? ''),
                'user_id'    => (string) ($context['account_id'] ?? ''),
                'conv_key'   => (string) ($context['conv_key'] ?? ''), // pay-ownership anchor
            ];
            $res = gia_stays_book($db, $sel,
                trim((string) ($input['checkin'] ?? '')),
                trim((string) ($input['checkout'] ?? '')),
                $lead,
                (int) ($input['guests'] ?? 1));

            if (empty($res['ok'])) {
                return [
                    'ok' => false,
                    'error_code' => !empty($res['need_dates']) ? 'AI_STAYS_NEED_DATES' : 'AI_STAYS_BOOK',
                    'message' => (string) ($res['message'] ?? 'Could not create the booking.'),
                ];
            }
            return [
                'ok' => true,
                'data' => [
                    'invoice_id'     => $res['invoice_id'],
                    'module'         => 'stays',
                    'stay_id'        => $res['stay_id'],
                    'amount'         => $res['amount'],
                    'amount_due_now' => $res['amount_due_now'],
                    'currency'       => $res['currency'],
                    'nights'         => $res['nights'],
                    'checkin'        => $res['checkin'],
                    'checkout'       => $res['checkout'],
                    'needs_payment'  => true,
                ],
                'message' => 'Room held (unpaid) for ' . (int) $res['nights'] . ' night(s). Now offer the payment options; paying confirms the stay.',
            ];
        }
    }
}

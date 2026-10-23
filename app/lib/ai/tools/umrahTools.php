<?php
// ============================================================================
// FILE: app/lib/ai/tools/umrahTools.php
// GIA's DEDICATED umrah tools. These are what the GIA agent calls for umrah — so
// umrah NEVER goes through the shared search_trip/select_option/aiSearchService
// web pipeline. Each tool is a thin, governed wrapper over app/lib/ai/gia_umrah.php
// (which reads the operator's own umrah inventory + the booking engine directly).
//
// Tools: umrah_search (list departures) · umrah_tiers (a departure's tiers+prices)
//        · umrah_book (create a held, unpaid booking + invoice).
// Pilgrim collection + payment happen deterministically after booking (not via the
// model) — the same gated passport-then-pay flow.
//
// Touches NO web/mobile umrah code. Reuses the shared umrah service/business
// functions read-only (like a database).
// ============================================================================

namespace App\lib\ai;

require_once __DIR__ . '/../giaToolInterface.php';
require_once __DIR__ . '/../gia_umrah.php';

if (!class_exists('App\\lib\\ai\\umrahSearchTool')) {
    /** List the operator's REAL published umrah departures (optionally by city/month/pax). */
    class umrahSearchTool implements giaToolInterface
    {
        public function name(): string { return 'umrah_search'; }

        public function description(): string
        {
            return 'List our REAL available Umrah departures (packages) with live prices. '
                . 'Use this whenever the traveller is interested in Umrah. You may pass a '
                . 'city and/or a specific month only if the traveller named them; otherwise '
                . 'omit them to show all departures. Never invent an Umrah package — only '
                . 'speak about what this tool returns.';
        }

        public function inputSchema(): array
        {
            return [
                'type' => 'object',
                'properties' => [
                    'city'           => ['type' => 'string', 'description' => 'Departure city if the traveller named one (e.g. "Kano"). Omit for all.'],
                    'month'          => ['type' => 'string', 'description' => 'A month the traveller explicitly named, e.g. "December 2026" or "2026-12". Omit if not given.'],
                    'adults'         => ['type' => 'integer', 'description' => 'Number of adult pilgrims if known.'],
                    'children'       => ['type' => 'integer', 'description' => 'Number of child pilgrims if known.'],
                    'infants'        => ['type' => 'integer', 'description' => 'Number of infant pilgrims if known.'],
                ],
                'required' => [],
            ];
        }

        public function riskClass(): string { return 'R0'; }

        public function run(array $input, array $context): array
        {
            $db = $context['db'] ?? null;
            if ($db === null) { return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Umrah search is unavailable.']; }
            if (function_exists('gia_trace')) { \gia_trace('umrah_search', __FILE__, 'city=' . (string) ($input['city'] ?? '') . ' month=' . (string) ($input['month'] ?? '')); }

            $pax = max(0, (int) ($input['adults'] ?? 0)) + max(0, (int) ($input['children'] ?? 0)) + max(0, (int) ($input['infants'] ?? 0));
            $monthRaw = trim((string) ($input['month'] ?? ''));
            $monthExplicit = $monthRaw !== '';
            $monthYmd = '';
            if ($monthExplicit) {
                $ts = strtotime($monthRaw) ?: strtotime('1 ' . $monthRaw);
                if ($ts !== false) { $monthYmd = date('Y-m-d', $ts); }
            }

            $res = gia_umrah_search($db, [
                'city'           => trim((string) ($input['city'] ?? '')),
                'month_ymd'      => $monthYmd,
                'month_explicit' => $monthExplicit ? 1 : 0,
                'pax'            => $pax,
            ]);
            if (empty($res['ok'])) {
                return ['ok' => false, 'error_code' => 'AI_UMRAH_UNAVAILABLE', 'message' => (string) ($res['message'] ?? 'Umrah is unavailable right now.')];
            }

            // Surface the cards as a 'searches' block so the UI renders rich cards the
            // same way it does for other services. Each card id is a departure_id
            // (the traveller picks a departure, then its tiers).
            return [
                'ok' => true,
                'data' => [
                    'searches' => [[
                        'module'   => 'umrah',
                        'title'    => 'Umrah departures',
                        'cards'    => $res['cards'],
                        'result_count' => count($res['cards']),
                    ]],
                ],
                'message' => $res['cards'] === []
                    ? 'No Umrah departures are open right now. Offer to note the traveller\'s interest.'
                    : 'Showing ' . count($res['cards']) . ' real Umrah departure(s). Ask which departure they like; do not invent any.',
            ];
        }
    }
}

if (!class_exists('App\\lib\\ai\\umrahTiersTool')) {
    /** The bookable tiers (Standard/VIP/…) + real prices for a chosen departure. */
    class umrahTiersTool implements giaToolInterface
    {
        public function name(): string { return 'umrah_tiers'; }

        public function description(): string
        {
            return 'After the traveller picks an Umrah departure, show its comfort tiers '
                . '(e.g. Standard, VIP) with real prices and what\'s included. Pass the '
                . 'departure_id from umrah_search and the pilgrim count. Only describe '
                . 'tiers this tool returns.';
        }

        public function inputSchema(): array
        {
            return [
                'type' => 'object',
                'properties' => [
                    'departure_id' => ['type' => 'integer', 'description' => 'The chosen departure\'s departure_id EXACTLY as given in the umrah_search result. If you are not 100% sure of the number, omit it and pass "when" instead.'],
                    'when'     => ['type' => 'string', 'description' => 'What the traveller said about which departure, e.g. "16th December" or "25 Dec". Always include this when they named a date/month, so the system can resolve the exact departure even without the id.'],
                    'adults'   => ['type' => 'integer', 'description' => 'Adult pilgrims.'],
                    'children' => ['type' => 'integer', 'description' => 'Child pilgrims.'],
                    'infants'  => ['type' => 'integer', 'description' => 'Infant pilgrims.'],
                ],
                'required' => [],
            ];
        }

        public function riskClass(): string { return 'R0'; }

        public function run(array $input, array $context): array
        {
            $db = $context['db'] ?? null;
            if ($db === null) { return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Umrah tiers are unavailable.']; }
            if (function_exists('gia_trace')) { \gia_trace('umrah_tiers', __FILE__, 'departure_id=' . (int) ($input['departure_id'] ?? 0) . ' when=' . (string) ($input['when'] ?? '')); }
            $pax = max(0, (int) ($input['adults'] ?? 0)) + max(0, (int) ($input['children'] ?? 0)) + max(0, (int) ($input['infants'] ?? 0));
            $res = gia_umrah_tiers($db, (int) ($input['departure_id'] ?? 0), $pax, trim((string) ($input['when'] ?? '')));
            if (empty($res['ok'])) {
                return ['ok' => false, 'error_code' => 'AI_UMRAH_TIERS', 'message' => (string) ($res['message'] ?? 'Could not load tiers.')];
            }
            return [
                'ok' => true,
                'data' => ['umrah_tiers' => $res['tiers'], 'departure' => $res['departure']],
                'message' => $res['tiers'] === []
                    ? 'That departure has no bookable tiers right now.'
                    : 'Showing ' . count($res['tiers']) . ' tier(s), each with a departure_tier_id. '
                        . 'Ask the traveller to confirm the tier + pilgrim count ONCE. The moment they '
                        . 'confirm (yes / the tier name / a number), call umrah_book with that '
                        . 'departure_tier_id — do NOT call umrah_tiers again.',
            ];
        }
    }
}

if (!class_exists('App\\lib\\ai\\umrahBookTool')) {
    /** Create a REAL held (unpaid) umrah booking for a chosen tier + pilgrims. */
    class umrahBookTool implements giaToolInterface
    {
        public function name(): string { return 'umrah_book'; }

        public function description(): string
        {
            return 'Create an Umrah booking (held, UNPAID — no charge) for a chosen tier '
                . 'and pilgrim count, once the traveller has agreed to a specific tier and '
                . 'price. After this, the traveller\'s passports are collected and then '
                . 'payment is offered. Pass the departure_tier_id from umrah_tiers.';
        }

        public function inputSchema(): array
        {
            return [
                'type' => 'object',
                'properties' => [
                    'departure_tier_id' => ['type' => 'integer', 'description' => 'The chosen tier\'s departure_tier_id (from umrah_tiers).'],
                    'adults'    => ['type' => 'integer', 'description' => 'Adult pilgrims.'],
                    'children'  => ['type' => 'integer', 'description' => 'Child pilgrims.'],
                    'infants'   => ['type' => 'integer', 'description' => 'Infant pilgrims.'],
                    'plan_code' => ['type' => 'string', 'description' => 'Chosen payment plan code if the traveller picked one; else omit for the default.'],
                    'first_name'=> ['type' => 'string', 'description' => 'Lead contact first name if known.'],
                    'last_name' => ['type' => 'string', 'description' => 'Lead contact last name if known.'],
                    'email'     => ['type' => 'string', 'description' => 'Lead contact email if known.'],
                    'phone'     => ['type' => 'string', 'description' => 'Lead contact phone if known.'],
                ],
                'required' => ['departure_tier_id'],
            ];
        }

        public function riskClass(): string { return 'R2'; } // held unpaid booking; reversible; no money

        public function run(array $input, array $context): array
        {
            $db = $context['db'] ?? null;
            if ($db === null) { return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Umrah booking is unavailable.']; }
            if (function_exists('gia_trace')) { \gia_trace('umrah_book', __FILE__, 'departure_tier_id=' . (int) ($input['departure_tier_id'] ?? 0)); }
            $pax = max(0, (int) ($input['adults'] ?? 0)) + max(0, (int) ($input['children'] ?? 0)) + max(0, (int) ($input['infants'] ?? 0));
            if ($pax < 1) { $pax = 1; }

            $lead = [
                'first_name' => (string) ($input['first_name'] ?? ''),
                'last_name'  => (string) ($input['last_name'] ?? ''),
                'email'      => (string) ($input['email'] ?? ''),
                'phone'      => (string) ($input['phone'] ?? ''),
                'user_id'    => (string) ($context['account_id'] ?? ''),
            ];

            // IDEMPOTENCY — don't double-book. The model may call umrah_book twice for
            // the same pick (once on the choice, again on a trailing "yes"). If THIS
            // conversation already has an ACTIVE pilgrim-collection (started right after
            // the first book), that held booking IS the one being confirmed — return it
            // instead of creating a second booking (which would also reset passport
            // progress via gia_pt_start). Re-booking is only allowed once collection is
            // gone (expired/superseded) or the booking is paid.
            $convKey = (string) ($context['conv_key'] ?? '');
            // The pilgrim-collection engine may not be loaded yet on the channel path
            // (it loads after agent->run there), so pull it in idempotently — the guard
            // must work on web AND WhatsApp/Telegram/Messenger.
            if ($convKey !== '' && !function_exists('gia_pt_active')) {
                $ptFile = __DIR__ . '/../gia_pending_travellers.php';
                if (is_file($ptFile)) { require_once $ptFile; }
            }
            if ($convKey !== '' && function_exists('gia_pt_active')) {
                $active = \gia_pt_active($db, $convKey);
                if (is_array($active) && (int) ($active['umrah_booking_id'] ?? 0) > 0
                    && (string) ($active['invoice_id'] ?? '') !== '') {
                    return [
                        'ok' => true,
                        'reused' => true,
                        'data' => [
                            'invoice_id'       => (string) $active['invoice_id'],
                            'umrah_booking_id' => (int) $active['umrah_booking_id'],
                            'module'           => 'umrah',
                            'pax'              => (int) ($active['pax'] ?? $pax),
                            'needs_travellers' => true,
                        ],
                        'message' => 'This umrah booking is already held and awaiting pilgrim passports — continue with the passports, then payment.',
                    ];
                }
            }

            $res = gia_umrah_book($db, (int) ($input['departure_tier_id'] ?? 0), $pax, $lead, (string) ($input['plan_code'] ?? ''));
            if (empty($res['ok'])) {
                return ['ok' => false, 'error_code' => 'AI_UMRAH_BOOK', 'message' => (string) ($res['message'] ?? 'Could not create the umrah booking.')];
            }
            // Signal the channel to start pilgrim collection + (later) show pay options.
            return [
                'ok' => true,
                'data' => [
                    'invoice_id'       => $res['invoice_id'],
                    'booking_ref'      => $res['booking_ref'],
                    'umrah_booking_id' => $res['umrah_booking_id'],
                    'module'           => 'umrah',
                    'pax'              => $res['pax'],
                    'needs_travellers' => true,
                    'amount'           => $res['total_price'],
                    'amount_due_now'   => $res['amount_due_now'],
                    'currency'         => $res['currency'],
                    'installments'     => $res['installments'],
                    'plan_code'        => $res['plan_code'],
                ],
                'message' => 'Umrah booking created (unpaid). Next: collect each pilgrim\'s passport, then payment.',
            ];
        }
    }
}

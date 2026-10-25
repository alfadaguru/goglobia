<?php
// ============================================================================
// FILE: app/lib/ai/tools/flightsTools.php
// GIA's DEDICATED flights tools. These are what the GIA agent calls for flights —
// so flights NEVER go through the shared search_trip/aiSearchService web pipeline
// (which carries browser assumptions: default travel date, geolocation-only
// origin, $_SESSION currency). Each tool is a thin, governed wrapper over
// app/lib/ai/gia_flights.php.
//
// Tools: flights_search (real fares, supplier-agnostic) · flights_select
//        (price-lock a chosen fare, fail-closed) · flights_book (held, unpaid
//        booking once passenger count is known). Passenger details are collected
//        and payment is offered AFTER booking, BEFORE issue — the owner's rule.
//
// The stable supplier payload (whole offer + short-lived token) is too big to
// round-trip through the model, so each offer is cached server-side by its `ref`
// (gia_offer_cache, keyed on conv_id); select/book look it up by ref.
//
// Touches NO web/mobile flight code. Reuses the shared flight gateways +
// revalidate helper READ-ONLY (like a database).
// ============================================================================

namespace App\lib\ai;

require_once __DIR__ . '/../giaToolInterface.php';
require_once __DIR__ . '/../gia_flights.php';

if (!class_exists('App\\lib\\ai\\flightsSearchTool')) {
    /** Search REAL bookable flight fares. Requires explicit origin, destination, date. */
    class flightsSearchTool implements giaToolInterface
    {
        public function name(): string { return 'flights_search'; }

        public function description(): string
        {
            return 'Search REAL, bookable flight fares. Use this for ANY flight request. '
                . 'ONE-WAY / ROUND-TRIP: give origin + destination + departure_date (+ return_date for '
                . 'a round trip). MULTI-CITY (3+ stops, e.g. Lagos→Dubai→London→Lagos): instead give a '
                . 'segments array of at least 2 legs, each {origin, destination, date}; omit the top-level '
                . 'origin/destination/departure_date. NEVER guess a date or a city — ask for whatever is '
                . 'missing. Returns live fare cards (each carries a ref to select). Only ever describe '
                . 'fares this tool returns.';
        }

        public function inputSchema(): array
        {
            return [
                'type' => 'object',
                'properties' => [
                    'origin'         => ['type' => 'string', 'description' => 'One-way/round-trip: departure city or 3-letter IATA code (e.g. "Lagos"/"LOS"). Omit for multi-city.'],
                    'destination'    => ['type' => 'string', 'description' => 'One-way/round-trip: destination city or IATA code (e.g. "Dubai"/"DXB"). Omit for multi-city.'],
                    'departure_date' => ['type' => 'string', 'description' => 'One-way/round-trip: departure date YYYY-MM-DD. Omit for multi-city. Never invent it.'],
                    'return_date'    => ['type' => 'string', 'description' => 'Return date YYYY-MM-DD for a round trip; omit for one-way/multi-city.'],
                    'segments'       => [
                        'type' => 'array',
                        'description' => 'MULTI-CITY ONLY: the legs in order, at least 2. Each is a stop of the journey. Use this INSTEAD of origin/destination/departure_date.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'origin'      => ['type' => 'string', 'description' => 'This leg\'s departure city or IATA code.'],
                                'destination' => ['type' => 'string', 'description' => 'This leg\'s arrival city or IATA code.'],
                                'date'        => ['type' => 'string', 'description' => 'This leg\'s departure date YYYY-MM-DD.'],
                            ],
                            'required' => ['origin', 'destination', 'date'],
                        ],
                    ],
                    'class'          => ['type' => 'string', 'description' => 'Cabin: economy, premium_economy, business, or first. Default economy (do not ask unless they want a higher cabin).'],
                    'adults'         => ['type' => 'integer', 'description' => 'Adult passengers (12+). Default 1.'],
                    'children'       => ['type' => 'integer', 'description' => 'Child passengers (2-11).'],
                    'infants'        => ['type' => 'integer', 'description' => 'Infant passengers (under 2).'],
                ],
                // No static `required`: the engine enforces per-trip-type (origin/dest/date
                // for one-way/round, >=2 valid segments for multi-city) and returns a clear
                // "I need …" message, so a multi-city-only call isn't rejected by the schema.
                'required' => [],
            ];
        }

        public function riskClass(): string { return 'R0'; }

        public function run(array $input, array $context): array
        {
            $db = $context['db'] ?? null;
            if ($db === null) { return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Flight search is unavailable.']; }
            $segments = (isset($input['segments']) && is_array($input['segments'])) ? $input['segments'] : [];
            $isMulti = count($segments) >= 2;
            if (function_exists('gia_trace')) {
                \gia_trace('flights_search', __FILE__, $isMulti
                    ? ('multicity legs=' . count($segments))
                    : ('origin=' . (string) ($input['origin'] ?? '') . ' dest=' . (string) ($input['destination'] ?? '') . ' date=' . (string) ($input['departure_date'] ?? '')));
            }
            $convId = (int) ($context['conv_id'] ?? 0);
            $currency = strtoupper(trim((string) ($context['currency'] ?? ''))) ?: 'USD';

            $res = gia_flights_search($db, [
                'origin'         => (string) ($input['origin'] ?? ''),
                'destination'    => (string) ($input['destination'] ?? ''),
                'departure_date' => (string) ($input['departure_date'] ?? ''),
                'return_date'    => (string) ($input['return_date'] ?? ''),
                'segments'       => $segments,
                'type'           => $isMulti ? 'multicity' : '',
                'class'          => (string) ($input['class'] ?? 'economy'),
                'adults'         => (int) ($input['adults'] ?? 1),
                'children'       => (int) ($input['children'] ?? 0),
                'infants'        => (int) ($input['infants'] ?? 0),
                'currency'       => $currency,
            ]);
            if (empty($res['ok'])) {
                return ['ok' => false, 'error_code' => 'AI_FLIGHTS_SEARCH', 'message' => (string) ($res['message'] ?? 'Could not search flights.')];
            }

            // Cache each offer's heavy stable payload by ref (so select/book can
            // retrieve it without the model carrying it), and hand the model/UI a
            // trimmed card. gia_offer_cache keys on conv_id.
            $cards = [];
            foreach (($res['offers'] ?? []) as $o) {
                if (!is_array($o)) { continue; }
                $ref = (string) ($o['ref'] ?? '');
                if ($ref !== '' && $convId > 0 && function_exists('gia_offer_cache_put')) {
                    gia_offer_cache_put($db, $convId, 'flt:' . $ref, [
                        'stable'   => $o['stable'] ?? [],
                        'pax'      => $o['pax'] ?? [],
                        'currency' => $o['currency'] ?? $currency,
                        'supplier' => $o['supplier'] ?? '',
                    ]);
                }
                $cards[] = [
                    'id'              => $ref,
                    'ref'             => $ref,
                    'kind'            => 'flights',
                    'module'          => 'flights',
                    'supplier'        => (string) ($o['supplier'] ?? ''),
                    'name'            => (string) ($o['name'] ?? 'Flight'),
                    'title'           => (string) ($o['title'] ?? 'Flight'),
                    'subtitle'        => (string) ($o['subtitle'] ?? ''),
                    // Structured detail the supplier returns — renderers show these.
                    'stops'           => (int) ($o['stops'] ?? 0),
                    'duration'        => (string) ($o['duration'] ?? ''),
                    'arrival_time'    => (string) ($o['arrival_time'] ?? ''),
                    'cabin'           => (string) ($o['cabin'] ?? ''),
                    'baggage'         => (string) ($o['baggage'] ?? ''),
                    'refundable'      => (string) ($o['refundable'] ?? ''),
                    'seats_left'      => (int) ($o['seats_left'] ?? 0),
                    'trip_type'       => (string) ($o['trip_type'] ?? 'oneway'),
                    'return_line'     => (string) ($o['return_line'] ?? ''),
                    'price'           => $o['price'] ?? null,
                    'currency'        => (string) ($o['currency'] ?? $currency),
                    'fare_expires_at' => (string) ($o['fare_expires_at'] ?? ''),
                    'live'            => true, // real-time fare
                ];
            }

            // Honest overflow disclosure (no silent caps): tell the model when there
            // were MORE fares than we showed, so it can offer to refine (cheapest,
            // earliest, nonstop, a specific airline) instead of implying 8 is all.
            $totalFound = (int) ($res['total_found'] ?? count($cards));
            $shown = count($cards);
            $more = max(0, $totalFound - $shown);
            $msg = $shown === 0
                ? 'No bookable fares came back for that route and date. Offer to try nearby dates.'
                : 'Showing the ' . $shown . ' cheapest real fare(s)'
                    . ($more > 0 ? ' of ' . $totalFound . ' found — there are ' . $more . ' more; if none suit, offer to narrow by time of day, nonstop only, or a specific airline, then re-search.' : '.')
                    . ' Each card has a ref — pass the chosen ref to flights_select. Fares expire fast; reconfirm the price before payment.';

            return [
                'ok' => true,
                'data' => [
                    'module'      => 'flights',
                    'searches'    => [['module' => 'flights', 'kind' => 'flights', 'title' => 'Flights', 'cards' => $cards, 'live' => true]],
                    'flights'     => $cards, // also surfaced directly for the model
                    'total_found' => $totalFound,
                    'shown'       => $shown,
                ],
                'message' => $msg,
            ];
        }
    }
}

if (!class_exists('App\\lib\\ai\\flightsSelectTool')) {
    /** Price-lock a chosen fare (fail-closed) + report the passenger fields needed. */
    class flightsSelectTool implements giaToolInterface
    {
        public function name(): string { return 'flights_select'; }

        public function description(): string
        {
            return 'After the traveller picks a fare, re-confirm its live price (it may change or '
                . 'expire) and find out which passenger details this fare needs. Pass the ref from a '
                . 'flights_search card. If the fare is gone, I will tell you to search again.';
        }

        public function inputSchema(): array
        {
            return [
                'type' => 'object',
                'properties' => [
                    'ref' => ['type' => 'string', 'description' => 'The chosen fare ref from flights_search.'],
                ],
                'required' => ['ref'],
            ];
        }

        public function riskClass(): string { return 'R0'; }

        public function run(array $input, array $context): array
        {
            $db = $context['db'] ?? null;
            if ($db === null) { return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Flight pricing is unavailable.']; }
            if (function_exists('gia_trace')) { \gia_trace('flights_select', __FILE__, 'ref=' . (string) ($input['ref'] ?? '')); }
            $convId = (int) ($context['conv_id'] ?? 0);
            $ref = trim((string) ($input['ref'] ?? ''));
            if ($ref === '' || $convId <= 0 || !function_exists('gia_offer_cache_get')) {
                return ['ok' => false, 'error_code' => 'AI_FLIGHTS_REF', 'message' => 'Please pick a fare from the results first.'];
            }
            $cached = gia_offer_cache_get($db, $convId, 'flt:' . $ref);
            if (!is_array($cached) || empty($cached['stable'])) {
                return ['ok' => false, 'error_code' => 'AI_FLIGHTS_REF', 'message' => 'That fare is no longer in this conversation — please search again.'];
            }
            $currency = strtoupper(trim((string) ($cached['currency'] ?? ($context['currency'] ?? 'USD')))) ?: 'USD';
            $sel = gia_flights_select($db, (array) $cached['stable'], (array) ($cached['pax'] ?? []), $currency);
            if (empty($sel['ok'])) {
                return ['ok' => false, 'error_code' => 'AI_FLIGHTS_FARE_GONE', 'message' => (string) ($sel['message'] ?? 'That fare is no longer available. Please search again.')];
            }
            $supplier = (string) $sel['supplier'];
            $fields = gia_flights_pax_fields($supplier, true);

            return [
                'ok' => true,
                'data' => [
                    'module'            => 'flights',
                    'ref'               => $ref,
                    'supplier'          => $supplier,
                    'amount'            => $sel['price'],
                    'currency'          => $sel['currency'],
                    'price_changed'     => !empty($sel['price_changed']),
                    'passenger_fields'  => $fields['required'],
                ],
                'message' => (!empty($sel['price_changed']) ? 'Heads up — the price updated to ' . $sel['currency'] . ' ' . $sel['price'] . '. ' : '')
                    . 'To book I need these details for EACH passenger: ' . implode(', ', $fields['required'])
                    . '. Collect them, then call flights_book with the ref.',
            ];
        }
    }
}

if (!class_exists('App\\lib\\ai\\flightsBookTool')) {
    /** Create a held, UNPAID flight booking for a price-locked fare + passenger count. */
    class flightsBookTool implements giaToolInterface
    {
        public function name(): string { return 'flights_book'; }

        public function description(): string
        {
            return 'Create a flight booking (held, UNPAID — no charge) for a chosen fare, once the '
                . 'traveller has agreed to the price. Pass the fare ref and the passenger counts. '
                . 'After this, each passenger\'s details are collected and then payment is offered; '
                . 'the ticket is only issued after payment.';
        }

        public function inputSchema(): array
        {
            return [
                'type' => 'object',
                'properties' => [
                    'ref'        => ['type' => 'string', 'description' => 'The chosen fare ref (from flights_search / flights_select).'],
                    'adults'     => ['type' => 'integer', 'description' => 'Adult passengers. Default 1.'],
                    'children'   => ['type' => 'integer', 'description' => 'Child passengers.'],
                    'infants'    => ['type' => 'integer', 'description' => 'Infant passengers.'],
                    'first_name' => ['type' => 'string', 'description' => 'Lead contact first name if known.'],
                    'last_name'  => ['type' => 'string', 'description' => 'Lead contact last name if known.'],
                    'email'      => ['type' => 'string', 'description' => 'Lead contact email if known.'],
                    'phone'      => ['type' => 'string', 'description' => 'Lead contact phone if known.'],
                ],
                'required' => ['ref'],
            ];
        }

        public function riskClass(): string { return 'R2'; } // held unpaid booking; reversible; no money

        public function run(array $input, array $context): array
        {
            $db = $context['db'] ?? null;
            if ($db === null) { return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Flight booking is unavailable.']; }
            if (function_exists('gia_trace')) { \gia_trace('flights_book', __FILE__, 'ref=' . (string) ($input['ref'] ?? '')); }
            $convId = (int) ($context['conv_id'] ?? 0);
            $ref = trim((string) ($input['ref'] ?? ''));
            if ($ref === '' || $convId <= 0 || !function_exists('gia_offer_cache_get')) {
                return ['ok' => false, 'error_code' => 'AI_FLIGHTS_REF', 'message' => 'Please pick a fare from the results first.'];
            }
            $cached = gia_offer_cache_get($db, $convId, 'flt:' . $ref);
            if (!is_array($cached) || empty($cached['stable'])) {
                return ['ok' => false, 'error_code' => 'AI_FLIGHTS_REF', 'message' => 'That fare is no longer in this conversation — please search again.'];
            }
            $currency = strtoupper(trim((string) ($cached['currency'] ?? ($context['currency'] ?? 'USD')))) ?: 'USD';

            $pax = [
                'adults'   => max(1, (int) ($input['adults'] ?? ($cached['pax']['adults'] ?? 1))),
                'children' => max(0, (int) ($input['children'] ?? ($cached['pax']['children'] ?? 0))),
                'infants'  => max(0, (int) ($input['infants'] ?? ($cached['pax']['infants'] ?? 0))),
            ];
            $lead = [
                'first_name' => (string) ($input['first_name'] ?? ''),
                'last_name'  => (string) ($input['last_name'] ?? ''),
                'email'      => (string) ($input['email'] ?? ''),
                'phone'      => (string) ($input['phone'] ?? ''),
                'user_id'    => (string) ($context['account_id'] ?? ''),
                // Bind the booking to THIS conversation so the pay endpoint can prove
                // ownership (a stranger can't pay an invoice that isn't on their chat).
                'conv_key'   => (string) ($context['conv_key'] ?? ''),
            ];
            $res = gia_flights_book($db, (array) $cached['stable'], $pax, $lead, $currency);
            if (empty($res['ok'])) {
                return ['ok' => false, 'error_code' => 'AI_FLIGHTS_BOOK', 'message' => (string) ($res['message'] ?? 'Could not create the flight booking.')];
            }
            return [
                'ok' => true,
                'data' => [
                    'invoice_id'       => $res['invoice_id'],
                    'module'           => 'flights',
                    'supplier'         => $res['supplier'],
                    'pax'              => $res['pax'],
                    'pax_total'        => $res['pax_total'],
                    'needs_travellers' => true,
                    'amount'           => $res['amount'],
                    'amount_due_now'   => $res['amount_due_now'],
                    'currency'         => $res['currency'],
                    'price_changed'    => $res['price_changed'],
                ],
                'message' => 'Flight held (unpaid). Next: collect each passenger\'s details, then payment. The ticket issues only after payment.',
            ];
        }
    }
}

if (!class_exists('App\\lib\\ai\\flightsAddPassengerTool')) {
    /** Save ONE passenger's details onto a held flight booking (before payment). */
    class flightsAddPassengerTool implements giaToolInterface
    {
        public function name(): string { return 'flights_add_passenger'; }

        public function description(): string
        {
            return 'Save ONE passenger onto a held flight booking. Call once per passenger, after '
                . 'flights_book, collecting the fields flights_select said this fare needs. When the '
                . 'returned data says done=true, the manifest is complete and you should offer payment. '
                . 'Dates of birth must match the passenger type (adult 12+, child 2-11, infant under 2).';
        }

        public function inputSchema(): array
        {
            return [
                'type' => 'object',
                'properties' => [
                    'invoice_id'      => ['type' => 'string', 'description' => 'The held flight booking invoice_id from flights_book.'],
                    'saved_traveller_id' => ['type' => 'integer', 'description' => 'Reuse a SAVED traveller from get_my_travellers by their id — fills name/DOB/passport/nationality automatically. The traveller still confirms. Omit to enter a new person.'],
                    'type'            => ['type' => 'string', 'description' => 'Passenger type: adult, child, or infant.'],
                    'title'           => ['type' => 'string', 'description' => 'Mr, Ms, Mrs, Miss or Dr.'],
                    'first_name'      => ['type' => 'string', 'description' => 'Given name, as in the passport.'],
                    'last_name'       => ['type' => 'string', 'description' => 'Family name, as in the passport.'],
                    'dob'             => ['type' => 'string', 'description' => 'Date of birth YYYY-MM-DD.'],
                    'gender'          => ['type' => 'string', 'description' => 'm or f (optional; inferred from title if omitted).'],
                    'nationality'     => ['type' => 'string', 'description' => 'Nationality / passport country (when the fare needs passport details).'],
                    'passport_number' => ['type' => 'string', 'description' => 'Passport number (when required).'],
                    'passport_expiry' => ['type' => 'string', 'description' => 'Passport expiry YYYY-MM-DD (when required).'],
                ],
                'required' => ['invoice_id', 'first_name', 'last_name', 'dob'],
            ];
        }

        public function riskClass(): string { return 'R2'; } // mutates a held booking; no money

        public function run(array $input, array $context): array
        {
            $db = $context['db'] ?? null;
            if ($db === null) { return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Unavailable.']; }
            if (function_exists('gia_trace')) { \gia_trace('flights_add_passenger', __FILE__, 'invoice=' . (string) ($input['invoice_id'] ?? '')); }
            $invoiceId = trim((string) ($input['invoice_id'] ?? ''));
            if ($invoiceId === '') { return ['ok' => false, 'error_code' => 'AI_FLIGHTS_PAX', 'message' => 'Which booking is this passenger for?']; }

            $acct = (string) ($context['account_id'] ?? '');

            // REUSE: if the traveller picked a saved person, load it (scoped to the
            // signed-in user) and use those details — the model can still override
            // any field it passes alongside. Never trust the id without account scope.
            $savedId = (int) ($input['saved_traveller_id'] ?? 0);
            if ($savedId > 0 && $acct !== '') {
                require_once dirname(__DIR__) . '/gia_travellers.php';
                $saved = \gia_travellers_get($db, $acct, $savedId);
                if ($saved) {
                    foreach (['title', 'first_name', 'last_name', 'dob', 'gender', 'nationality', 'passport_number', 'passport_expiry'] as $f) {
                        // Explicit input wins; otherwise fill from the saved record.
                        if (trim((string) ($input[$f] ?? '')) === '' && trim((string) ($saved[$f] ?? '')) !== '') {
                            $input[$f] = $saved[$f];
                        }
                    }
                } else {
                    return ['ok' => false, 'error_code' => 'AI_SAVED_NOT_FOUND', 'message' => 'I couldn\'t find that saved traveller — let\'s enter the details instead.'];
                }
            }

            $res = gia_flights_add_passenger($db, $invoiceId, $input);
            if (empty($res['ok'])) {
                return ['ok' => false, 'error_code' => 'AI_FLIGHTS_PAX', 'message' => (string) ($res['message'] ?? 'Could not save that passenger.')];
            }

            // CAPTURE (going forward): remember this passenger on the signed-in
            // user's roster so the next booking can reuse them. Fail-soft.
            if ($acct !== '') {
                require_once dirname(__DIR__) . '/gia_travellers.php';
                try { \gia_traveller_upsert($db, $acct, $input, 'flights'); } catch (\Throwable $e) { error_log('[gia] traveller capture: ' . $e->getMessage()); }
            }
            return [
                'ok' => true,
                'data' => [
                    'invoice_id' => $invoiceId,
                    'module'     => 'flights',
                    'collected'  => (int) ($res['collected'] ?? 0),
                    'pax_total'  => (int) ($res['pax_total'] ?? 0),
                    'done'       => !empty($res['done']),
                    // When done, signal the route/channel to show the 2 pay options.
                    'needs_payment' => !empty($res['done']),
                ],
                'message' => !empty($res['done'])
                    ? 'All ' . (int) ($res['pax_total'] ?? 0) . ' passengers are saved. Now offer the payment options.'
                    : 'Saved passenger ' . (int) ($res['collected'] ?? 0) . ' of ' . (int) ($res['pax_total'] ?? 0) . '. Collect the next one.',
            ];
        }
    }
}

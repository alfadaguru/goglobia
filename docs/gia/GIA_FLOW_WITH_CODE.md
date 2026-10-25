# GIA — step-by-step flow with the REAL code at each step

Generated 2026-10-23 02:30 directly from the committed files. Each step shows the exact FILE and LINE numbers so you can open them and verify. Nothing here is paraphrased — it is the source.

> HONEST STATUS: only **umrah step 1 (search)** has been seen working on a real chat (your Kano Dec departures on WhatsApp). Everything else is written + logic-tested but has NOT run end-to-end live.

---
## UMRAH

### U1 — Search departures
**Tool the model calls:** `app/lib/ai/tools/umrahTools.php` — class `umrahSearchTool`, name `umrah_search`
```php
55	        public function run(array $input, array $context): array
56	        {
57	            $db = $context['db'] ?? null;
58	            if ($db === null) { return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Umrah search is unavailable.']; }
59	
60	            $pax = max(0, (int) ($input['adults'] ?? 0)) + max(0, (int) ($input['children'] ?? 0)) + max(0, (int) ($input['infants'] ?? 0));
61	            $monthRaw = trim((string) ($input['month'] ?? ''));
62	            $monthExplicit = $monthRaw !== '';
63	            $monthYmd = '';
64	            if ($monthExplicit) {
65	                $ts = strtotime($monthRaw) ?: strtotime('1 ' . $monthRaw);
66	                if ($ts !== false) { $monthYmd = date('Y-m-d', $ts); }
67	            }
68	
69	            $res = gia_umrah_search($db, [
70	                'city'           => trim((string) ($input['city'] ?? '')),
71	                'month_ymd'      => $monthYmd,
72	                'month_explicit' => $monthExplicit ? 1 : 0,
73	                'pax'            => $pax,
74	            ]);
75	            if (empty($res['ok'])) {
76	                return ['ok' => false, 'error_code' => 'AI_UMRAH_UNAVAILABLE', 'message' => (string) ($res['message'] ?? 'Umrah is unavailable right now.')];
77	            }
78	
79	            // Surface the cards as a 'searches' block so the UI renders rich cards the
80	            // same way it does for other services. Each card id is a departure_id
81	            // (the traveller picks a departure, then its tiers).
82	            return [
83	                'ok' => true,
84	                'data' => [
85	                    'searches' => [[
86	                        'module'   => 'umrah',
87	                        'title'    => 'Umrah departures',
88	                        'cards'    => $res['cards'],
89	                        'result_count' => count($res['cards']),
90	                    ]],
91	                ],
92	                'message' => $res['cards'] === []
93	                    ? 'No Umrah departures are open right now. Offer to note the traveller\'s interest.'
94	                    : 'Showing ' . count($res['cards']) . ' real Umrah departure(s). Ask which departure they like; do not invent any.',
95	            ];
96	        }
97	    }
```
**Engine:** `app/lib/ai/gia_umrah.php` — `gia_umrah_search()` reads YOUR real data via `umrahV2PublishedDepartures` (same fn the website uses):
```php
70	    function gia_umrah_search($db, array $opts = []): array
71	    {
72	        if (!gia_umrah_boot($db)) {
73	            \error_log('[gia_umrah] v2 engine not loadable');
74	            return ['ok' => false, 'cards' => [], 'message' => 'Umrah is temporarily unavailable.'];
75	        }
76	
77	        $city       = \strtolower(\trim((string) ($opts['city'] ?? '')));
78	        $monthYmd   = \trim((string) ($opts['month_ymd'] ?? ''));   // YYYY-MM-DD if the user named a month
79	        $monthGiven = !empty($opts['month_explicit']);
80	        $pax        = \max(0, (int) ($opts['pax'] ?? 0));
81	        $limit      = (int) ($opts['limit'] ?? 0); // 0 = no cap
82	
83	        try {
84	            $departures = \umrahV2PublishedDepartures($db);
85	        } catch (\Throwable $e) {
86	            \error_log('[gia_umrah] departures threw: ' . $e->getMessage());
87	            return ['ok' => false, 'cards' => [], 'message' => 'Could not load umrah departures.'];
88	        }
89	        if (!\is_array($departures) || $departures === []) {
90	            // Honest empty — never fabricate. (Diagnostic so an operator can see why.)
91	            \error_log('[gia_umrah] umrahV2PublishedDepartures returned empty');
92	            return ['ok' => true, 'cards' => [], 'message' => 'No umrah departures are open right now.'];
93	        }
```

### U2 — Pick departure -> tiers + price
**Tool:** `umrahTools.php` — `umrahTiersTool`, name `umrah_tiers`
```php
131	        public function run(array $input, array $context): array
132	        {
133	            $db = $context['db'] ?? null;
134	            if ($db === null) { return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Umrah tiers are unavailable.']; }
135	            $pax = max(0, (int) ($input['adults'] ?? 0)) + max(0, (int) ($input['children'] ?? 0)) + max(0, (int) ($input['infants'] ?? 0));
136	            $res = gia_umrah_tiers($db, (int) ($input['departure_id'] ?? 0), $pax, trim((string) ($input['when'] ?? '')));
137	            if (empty($res['ok'])) {
138	                return ['ok' => false, 'error_code' => 'AI_UMRAH_TIERS', 'message' => (string) ($res['message'] ?? 'Could not load tiers.')];
139	            }
140	            return [
141	                'ok' => true,
142	                'data' => ['umrah_tiers' => $res['tiers'], 'departure' => $res['departure']],
143	                'message' => $res['tiers'] === []
144	                    ? 'That departure has no bookable tiers right now.'
145	                    : 'Showing ' . count($res['tiers']) . ' tier(s). Each carries a departure_tier_id — pass the chosen one to umrah_book.',
146	            ];
147	        }
```
**Engine:** `gia_umrah.php` — `gia_umrah_tiers()` (date/id resolution + the real tier query):
```php
282	    function gia_umrah_tiers($db, int $departureId, int $pax = 0, string $when = ''): array
283	    {
284	        // TRACE (temporary): write the raw inputs + what the DB actually returns to
285	        // the SAME system error log the rest of the app uses (not a separate file),
286	        // so a live "no tiers" leaves an unambiguous fingerprint under [gia_umrah].
287	        $trace = function (string $m) {
288	            \error_log('[gia_umrah] ' . $m);
289	        };
290	        $trace('tiers() CALLED departure_id=' . $departureId . ' pax=' . $pax . ' when="' . $when . '"');
291	
292	        if (!gia_umrah_boot($db)) {
293	            return ['ok' => false, 'tiers' => [], 'departure' => [], 'message' => 'Umrah is temporarily unavailable.'];
294	        }
295	
296	        try {
297	            // The departure must be published + not archived (security: never price a
298	            // draft/closed one). Mirrors the public gate.
299	            $dep = ($departureId > 0)
300	                ? $db->get('umrah_departures', '*', ['id' => $departureId, 'status' => 'published', 'archived' => 0])
```

### U3 — Book (held, unpaid)
**Tool:** `umrahTools.php` — `umrahBookTool`, name `umrah_book`
```php
185	
186	        public function run(array $input, array $context): array
187	        {
188	            $db = $context['db'] ?? null;
189	            if ($db === null) { return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Umrah booking is unavailable.']; }
190	            $pax = max(0, (int) ($input['adults'] ?? 0)) + max(0, (int) ($input['children'] ?? 0)) + max(0, (int) ($input['infants'] ?? 0));
191	            if ($pax < 1) { $pax = 1; }
192	
193	            $lead = [
194	                'first_name' => (string) ($input['first_name'] ?? ''),
195	                'last_name'  => (string) ($input['last_name'] ?? ''),
196	                'email'      => (string) ($input['email'] ?? ''),
197	                'phone'      => (string) ($input['phone'] ?? ''),
198	                'user_id'    => (string) ($context['account_id'] ?? ''),
199	            ];
200	            $res = gia_umrah_book($db, (int) ($input['departure_tier_id'] ?? 0), $pax, $lead, (string) ($input['plan_code'] ?? ''));
201	            if (empty($res['ok'])) {
202	                return ['ok' => false, 'error_code' => 'AI_UMRAH_BOOK', 'message' => (string) ($res['message'] ?? 'Could not create the umrah booking.')];
```
**Engine:** `gia_umrah.php` — `gia_umrah_book()` quote -> hold -> booking_create:
```php
493	        }
494	    }
495	}
496	
497	if (!function_exists('App\\lib\\ai\\gia_umrah_book')) {
498	    /**
499	     * Create a REAL held (unpaid) umrah booking for a chosen tier + pilgrims + lead
500	     * contact. Runs the engine's own sequence: fresh quote -> hold -> booking_create.
501	     * The booking starts held/unpaid with an invoice; pilgrim passports are then
502	     * collected, and only then is payment offered. GIA never moves money here.
503	     *
504	     * @param array $lead ['first_name','last_name','email','phone','user_id'?]
505	     * @param string $planCode payment plan (umrah_payment_plans.code); '' = default
506	     * @return array ok + invoice_id/booking_ref/umrah_booking_id/pax/total_price/
507	     *               amount_due_now/currency/installments, or ['ok'=>false,message].
508	     */
509	    function gia_umrah_book($db, int $departureTierId, int $pax, array $lead, string $planCode = ''): array
510	    {
511	        if (!gia_umrah_boot($db)
512	            || !\function_exists('umrah_price_quote')
513	            || !\function_exists('umrah_hold_create')
514	            || !\function_exists('umrah_booking_create')) {
515	            return ['ok' => false, 'message' => 'Umrah booking is temporarily unavailable.'];
516	        }
517	        if ($departureTierId <= 0) { return ['ok' => false, 'message' => 'Please choose a tier first.']; }
518	        if ($pax < 1) { $pax = 1; }
519	
520	        try {
```

### U4 — Pilgrim passports (web). Photo -> OCR -> confirm, before pay
**File:** `app/lib/ai/gia_pt_web.php` — `gia_pt_web_handle_image()` (OCR an uploaded passport):
```php
128	    function gia_pt_web_handle_image($db, string $convKey, string $bytes, string $mime): array
129	    {
130	        if ($convKey === '' || !gia_pt_web_boot()) {
131	            return ['ok' => false, 'reply' => 'Passport upload is unavailable right now.', 'done' => false, 'error' => 'AI_PT_UNAVAILABLE'];
132	        }
133	        $row = \gia_pt_active($db, $convKey);
134	        if ($row === null) {
135	            return ['ok' => false, 'reply' => "There's no booking waiting for passports right now.", 'done' => false, 'error' => 'AI_PT_NONE'];
136	        }
137	
138	        $base = [
139	            'invoice_id'       => (string) ($row['invoice_id'] ?? ''),
140	            'umrah_booking_id' => (int) ($row['umrah_booking_id'] ?? 0),
141	            'done'             => false,
142	        ];
143	
144	        $parsed = \gia_pt_ocr_passport($db, $bytes, $mime);
145	        if ($parsed === null) {
146	            return $base + ['ok' => true, 'reply' =>
147	                "I couldn't read that passport photo clearly. Please upload a sharper photo of the data page, "
148	                . "or type the details: full name, gender, date of birth, nationality, passport number and expiry."];
149	        }
150	
151	        // MERGE onto anything already typed for this pilgrim (same as the channel
152	        // path), so an OCR pass that misses a field doesn't wipe a typed one.
153	        $prior = \gia_pt_get_pending_confirm($row);
154	        if (\is_array($prior) && $prior !== []) {
155	            $merged = $prior;
156	            foreach ($parsed as $k => $v) { if ($v !== '' && $v !== null) { $merged[$k] = $v; } }
157	            $parsed = $merged;
158	        }
159	        $missing = \gia_pt_missing_required($parsed);
160	        \gia_pt_set_pending_confirm($db, (int) $row['id'], $parsed);
```
Typed 'YES' is handled by `gia_pt_web_handle_text()` (same file, line 63).

### U5/U6 — Pay (2 options) + settle
**File:** `app/lib/ai/gia_booking_payment.php` — `gia_bp_create_dva()` (Paystack bank-transfer VA):
```php
189	    function gia_bp_create_dva($db, string $invoiceId, string $channel = '', string $channelUserId = ''): array
190	    {
191	        gia_bp_ensure_schema($db);
192	        require_once dirname(__DIR__) . '/wallet.php';
193	
194	        $facts = gia_bp_booking_facts($db, $invoiceId);
195	        if ($facts === []) { return ['ok' => false, 'message' => 'That booking could not be found.']; }
196	        if (!empty($facts['paid'])) { return ['ok' => false, 'message' => 'This booking is already paid.']; }
197	
198	        // Reuse an existing pending DVA for this invoice (don't mint a 2nd account).
199	        $existing = gia_bp_get($db, $invoiceId);
200	        if ($existing && $existing['method'] === 'dva' && $existing['status'] === 'pending'
201	            && $existing['dva_account_number'] !== '') {
202	            return [
203	                'ok' => true, 'reused' => true,
204	                'account_number' => $existing['dva_account_number'],
205	                'bank_name'      => $existing['dva_bank_name'],
206	                'account_name'   => $existing['dva_account_name'],
207	                'amount'         => (float) $existing['amount'],
208	                'currency'       => $existing['currency'],
209	                'expires_at'     => $existing['expires_at'],
210	            ];
211	        }
212	
```
**Settle:** `gia_bp_confirm()` — umrah branch calls `umrah_settle_payment`:
```php
426	            if ($facts['module'] === 'umrah') {
427	                require_once dirname(__DIR__) . '/umrah/services.php';
428	                if (function_exists('umrah_settle_payment')) {
429	                    $res = umrah_settle_payment($db, $invoiceId, $amount, $currency, $paystackRef);
430	                    $ok = !empty($res['ok']);
431	                }
432	                // umrah_settle_payment updates the umrah_bookings + installments; the
433	                // generic bookings row still needs the gateway/txn for refunds.
434	                if ($ok) {
435	                    try { $db->update('bookings', $gwStamp, ['invoice_id' => $invoiceId]); }
436	                    catch (\Throwable $e) { error_log('[gia_bp] confirm umrah gw-stamp: ' . $e->getMessage()); }
437	                }
```

---
## FLIGHTS

### F1 — Search fares (supplier-agnostic, requires origin+dest+date)
**Tool:** `app/lib/ai/tools/flightsTools.php` — `flightsSearchTool`, name `flights_search`:
```php
63	        public function run(array $input, array $context): array
64	        {
65	            $db = $context['db'] ?? null;
66	            if ($db === null) { return ['ok' => false, 'error_code' => 'AI_NO_DB', 'message' => 'Flight search is unavailable.']; }
67	            $convId = (int) ($context['conv_id'] ?? 0);
68	            $currency = strtoupper(trim((string) ($context['currency'] ?? ''))) ?: 'USD';
69	
70	            $res = gia_flights_search($db, [
71	                'origin'         => (string) ($input['origin'] ?? ''),
72	                'destination'    => (string) ($input['destination'] ?? ''),
73	                'departure_date' => (string) ($input['departure_date'] ?? ''),
74	                'return_date'    => (string) ($input['return_date'] ?? ''),
75	                'class'          => (string) ($input['class'] ?? 'economy'),
76	                'adults'         => (int) ($input['adults'] ?? 1),
77	                'children'       => (int) ($input['children'] ?? 0),
78	                'infants'        => (int) ($input['infants'] ?? 0),
79	                'currency'       => $currency,
80	            ]);
81	            if (empty($res['ok'])) {
82	                return ['ok' => false, 'error_code' => 'AI_FLIGHTS_SEARCH', 'message' => (string) ($res['message'] ?? 'Could not search flights.')];
83	            }
84	
85	            // Cache each offer's heavy stable payload by ref (so select/book can
86	            // retrieve it without the model carrying it), and hand the model/UI a
87	            // trimmed card. gia_offer_cache keys on conv_id.
88	            $cards = [];
89	            foreach (($res['offers'] ?? []) as $o) {
90	                if (!is_array($o)) { continue; }
```
**Engine:** `app/lib/ai/gia_flights.php` — `gia_flights_search()` (date guard + supplier gateway call):
```php
148	    function gia_flights_search($db, array $opts = []): array
149	    {
150	        if (!gia_flights_boot()) {
151	            \error_log('[gia_flights] trip helpers not loadable');
152	            return ['ok' => false, 'offers' => [], 'message' => 'Flight search is temporarily unavailable.'];
153	        }
154	
155	        $origin      = \strtoupper(\trim((string) ($opts['origin'] ?? '')));
156	        $destination = \strtoupper(\trim((string) ($opts['destination'] ?? '')));
157	        $departDate  = \trim((string) ($opts['departure_date'] ?? ''));
158	        $returnDate  = \trim((string) ($opts['return_date'] ?? ''));
159	        $type        = \strtolower(\trim((string) ($opts['type'] ?? ''))) ?: ($returnDate !== '' ? 'return' : 'oneway');
160	        $class       = \strtolower(\trim((string) ($opts['class'] ?? 'economy'))) ?: 'economy';
161	        $adults      = \max(1, (int) ($opts['adults'] ?? 1));
162	        $children    = \max(0, (int) ($opts['children'] ?? 0));
163	        $infants     = \max(0, (int) ($opts['infants'] ?? 0));
164	        $currency    = \strtoupper(\trim((string) ($opts['currency'] ?? ''))) ?: 'USD';
165	        $limit       = (int) ($opts['limit'] ?? 6); if ($limit <= 0) { $limit = 6; }
166	
167	        // HARD requirement — never guess a route or a date (the web-coupling that
168	        // broke umrah). If any is missing, ask; do not invent one.
169	        $missing = [];
170	        if ($origin === '')      { $missing[] = 'departure city/airport'; }
171	        if ($destination === '') { $missing[] = 'destination'; }
172	        if ($departDate === '')  { $missing[] = 'departure date'; }
173	        if ($missing !== []) {
174	            return ['ok' => false, 'offers' => [], 'need' => $missing,
175	                'message' => 'I need the ' . \implode(', ', $missing) . ' to search flights.'];
```

### F2 — Select -> price-lock (fail-closed)
**Engine:** `gia_flights.php` — `gia_flights_select()`:
```php
295	    function gia_flights_select($db, array $stable, array $pax = [], string $currency = 'USD'): array
296	    {
297	        if (!gia_flights_boot() || !\function_exists('aiTripRevalidatePackage')) {
298	            return ['ok' => false, 'message' => 'Flight pricing is temporarily unavailable.'];
299	        }
300	        $supplier = \strtolower(\trim((string) ($stable['supplier'] ?? '')));
301	        $raw      = \is_array($stable['raw'] ?? null) ? $stable['raw'] : [];
302	        if ($supplier === '' || $raw === []) {
303	            return ['ok' => false, 'message' => 'Please pick a flight from the results first.'];
304	        }
305	        $currency = \strtoupper(\trim($currency)) ?: 'USD';
306	
307	        // Build the draft item in the exact shape aiTripBuildFlightData expects
308	        // (item.raw = the whole supplier offer; supplier + token alongside).
309	        $item = [
310	            'module'   => 'flights',
311	            'kind'     => 'flights',
312	            'supplier' => $supplier,
313	            'price'    => 0.0, // reprice re-derives the authoritative price
314	            'currency' => $currency,
315	            'item'     => [
316	                'supplier'    => $supplier,
317	                'raw'         => $raw,
318	                'offer_token' => (string) ($stable['offer_token'] ?? ''),
319	            ],
320	        ];
321	        $draft = ['currency' => $currency, 'items' => [$item]];
322	
323	        try {
324	            $res = \aiTripRevalidatePackage($db, $draft);
325	        } catch (\Throwable $e) {
326	            \error_log('[gia_flights] select revalidate threw: ' . $e->getMessage());
327	            return ['ok' => false, 'message' => 'Could not re-check that fare. Please try again.'];
328	        }
329	
330	        if (empty($res['status'])) {
331	            return ['ok' => false,
332	                'message' => (string) ($res['message'] ?? 'That fare is no longer available. Please search again.')];
333	        }
334	        // The revalidated item (with refreshed token/price) is draft.items[0].
335	        $revItem = \is_array($draft['items'][0] ?? null) ? $draft['items'][0] : $item;
```

### F3 — Book (held, unpaid) + F4 passengers
**Engine:** `gia_flights.php` — `gia_flights_book()` (re-price-lock before money):
```php
400	    function gia_flights_book($db, array $stable, array $pax, array $lead, string $currency = 'USD'): array
401	    {
402	        if (!gia_flights_boot()) {
403	            return ['ok' => false, 'message' => 'Flight booking is temporarily unavailable.'];
404	        }
405	        $adults   = \max(1, (int) ($pax['adults'] ?? 1));
406	        $children = \max(0, (int) ($pax['children'] ?? 0));
407	        $infants  = \max(0, (int) ($pax['infants'] ?? 0));
408	
409	        // 1) RE-PRICE-LOCK now (fail-closed). Catches a fare that expired since pick,
410	        //    so we never create a booking the supplier can no longer honour.
411	        $sel = gia_flights_select($db, $stable, $pax, $currency);
412	        if (empty($sel['ok'])) {
413	            return ['ok' => false, 'message' => (string) ($sel['message'] ?? 'That fare is no longer available. Please search again.')];
414	        }
415	        $supplier = (string) $sel['supplier'];
416	        $amount   = (float) $sel['price'];
417	        $currency = (string) ($sel['currency'] ?? $currency);
418	        if ($amount <= 0) {
419	            return ['ok' => false, 'message' => 'That fare could not be priced. Please search again.'];
420	        }
421	        $item = \is_array($sel['item'] ?? null) ? $sel['item'] : [];
422	        $raw  = \is_array($item['item']['raw'] ?? null) ? $item['item']['raw'] : (\is_array($stable['raw'] ?? null) ? $stable['raw'] : []);
423	
424	        // 2) Build the AITP invoice + ai_trip booking_data (cart convention).
425	        try { $invoiceId = 'AITP' . \strtoupper(\bin2hex(\random_bytes(5))); }
426	        catch (\Throwable $e) { $invoiceId = 'AITP' . \strtoupper(\substr(\md5((string) \mt_rand()), 0, 10)); }
427	
428	        $firstName = (string) ($lead['first_name'] ?? '');
429	        $lastName  = (string) ($lead['last_name'] ?? '');
430	        $email     = (string) ($lead['email'] ?? '');
```
**Passengers:** `gia_flights_add_passenger()` writes flat adult_N/child_N keys:
```php
536	    function gia_flights_add_passenger($db, string $invoiceId, array $p): array
537	    {
538	        $b = $db->get('bookings', ['adults', 'childs', 'infants', 'travellers', 'email', 'phone'],
539	            ['invoice_id' => $invoiceId, 'module_type' => 'ai_trip']);
540	        if (!\is_array($b)) {
541	            return ['ok' => false, 'collected' => 0, 'pax_total' => 0, 'done' => false, 'message' => 'That flight booking was not found.'];
542	        }
543	        $need = ['adult' => \max(1, (int) $b['adults']), 'child' => \max(0, (int) $b['childs']), 'infant' => \max(0, (int) $b['infants'])];
544	        $paxTotal = $need['adult'] + $need['child'] + $need['infant'];
545	
546	        $trav = \json_decode((string) ($b['travellers'] ?? ''), true);
547	        if (!\is_array($trav)) { $trav = []; }
548	
549	        $type = \strtolower(\trim((string) ($p['type'] ?? 'adult')));
550	        if (!\in_array($type, ['adult', 'child', 'infant'], true)) { $type = 'adult'; }
551	        // Next free index for this type (count existing adult_/child_/infant_ keys).
552	        $idx = 0;
553	        foreach ($trav as $k => $_) { if (\is_string($k) && \strpos($k, $type . '_') === 0) { $idx++; } }
554	        if ($idx >= $need[$type]) {
555	            return ['ok' => false, 'collected' => gia_flights_collected($trav), 'pax_total' => $paxTotal, 'done' => false,
556	                'message' => 'All ' . $type . ' passengers are already added.'];
557	        }
558	
559	        $title = \strtolower(\trim((string) ($p['title'] ?? '')));
560	        if (!\in_array($title, ['mr', 'ms', 'mrs', 'miss', 'dr'], true)) { $title = ''; }
```

### F5/F6 — Pay + issue PNR + auto-refund on fail
**File:** `gia_booking_payment.php` — `gia_bp_confirm()` flights branch triggers the real issue path:
```php
468	                    }
469	                } elseif ($mt === 'ai_trip') {
470	                    // FLIGHTS / ai_trip: a ticket can only ISSUE after payment. Trigger
471	                    // the SAME proven issue path the web gateway callback uses
472	                    // (modules/ai_trip/ai_trip/issue), signed with the internal token.
473	                    // issue.php issues each PNR and, on failure, runs the existing
474	                    // auto-refund — so a paid-but-unissuable flight is refunded, never
475	                    // stranded. booking_status becomes 'confirmed' only when issue succeeds.
476	                    try {
477	                        gia_bp_issue_ai_trip($db, $invoiceId);
478	                    } catch (\Throwable $e) {
479	                        error_log('[gia_bp] ai_trip issue trigger ' . $invoiceId . ': ' . $e->getMessage());
480	                    }
481	                } elseif (function_exists('bm_module_has_issue_endpoint')
482	                          && function_exists('bm_settle_booking_payment')
```

---
## STAYS (hotels)

### S1 — Search city
**Tool:** `app/lib/ai/tools/staysTools.php` — `staysSearchTool`, name `stays_search`.
**Engine:** `app/lib/ai/gia_stays.php` — `gia_stays_search()` queries YOUR stays table directly:
```php
64	    function gia_stays_search($db, array $opts = []): array
65	    {
66	        if (!gia_stays_boot($db)) {
67	            return ['ok' => false, 'cards' => [], 'message' => 'Hotel search is temporarily unavailable.'];
68	        }
69	        $city  = \trim((string) ($opts['city'] ?? ''));
70	        $limit = (int) ($opts['limit'] ?? 8); if ($limit <= 0) { $limit = 8; }
71	
72	        $where = ['status' => 1, 'ORDER' => ['featured' => 'DESC', 'hotel_order' => 'ASC', 'rating' => 'DESC'], 'LIMIT' => $limit];
73	        if ($city !== '') {
74	            // Match the city against the free-text location/address. '[~]' = LIKE.
75	            $where['OR'] = ['location[~]' => $city, 'address[~]' => $city, 'name[~]' => $city];
76	        }
77	        try {
78	            $rows = $db->select('stays', ['id', 'name', 'slug', 'stars', 'rating', 'location', 'img', 'currency'], $where) ?: [];
79	        } catch (\Throwable $e) {
80	            \error_log('[gia_stays] search: ' . $e->getMessage());
81	            return ['ok' => false, 'cards' => [], 'message' => 'Could not search hotels right now.'];
82	        }
83	        if ($rows === []) {
84	            return ['ok' => true, 'cards' => [], 'message' => $city !== ''
85	                ? 'No properties found in ' . $city . ' right now.'
86	                : 'No properties are published right now.'];
87	        }
88	
89	        $cards = [];
90	        foreach ($rows as $r) {
```

### S2 — Rooms for dates (dates REQUIRED — no silent default)
**Engine:** `gia_stays.php` — `gia_stays_rooms()` enforces real dates:
```php
129	    function gia_stays_rooms($db, int $stayId, string $checkin, string $checkout, int $pax = 0): array
130	    {
131	        if (!gia_stays_boot($db)) {
132	            return ['ok' => false, 'rooms' => [], 'message' => 'Hotel rooms are temporarily unavailable.'];
133	        }
134	        if ($stayId <= 0) {
135	            return ['ok' => false, 'rooms' => [], 'message' => 'Which property would you like?'];
136	        }
137	        // ENFORCE real dates (kill the web silent-default coupling).
138	        $checkin  = \trim($checkin);
139	        $checkout = \trim($checkout);
140	        if ($checkin === '' || \strtotime($checkin) === false
141	            || $checkout === '' || \strtotime($checkout) === false
142	            || \strtotime($checkout) <= \strtotime($checkin)) {
143	            return ['ok' => false, 'rooms' => [], 'need_dates' => true,
144	                'message' => 'What are your check-in and check-out dates? I need them to show real room prices.'];
145	        }
146	
147	        try {
148	            $res = \stays_list_room_options($db, $stayId, $checkin, $checkout, \max(0, $pax));
149	        } catch (\Throwable $e) {
150	            \error_log('[gia_stays] rooms: ' . $e->getMessage());
151	            return ['ok' => false, 'rooms' => [], 'message' => 'Could not load rooms for that property.'];
152	        }
153	        if (empty($res['ok'])) {
154	            return ['ok' => false, 'rooms' => [], 'message' => (string) ($res['message'] ?? 'That property is not available.')];
155	        }
156	
157	        // Flatten rooms -> a list of pickable room-options, each carrying the canonical
158	        // stayroom:<stay>:<room>:<option> ref (the same ref the web/channel use).
159	        $rooms = [];
160	        foreach (($res['rooms'] ?? []) as $rm) {
```

### S3 — Book (held, unpaid) via the real hold engine
**Engine:** `gia_stays.php` — `gia_stays_book()` reprice -> hold(ref=invoice) -> ai_trip booking:
```php
230	    function gia_stays_book($db, array $sel, string $checkin, string $checkout, array $lead, int $pax = 1): array
231	    {
232	        if (!gia_stays_boot($db) || !\function_exists('stays_recompute_net') || !\function_exists('stays_hold_create')) {
233	            return ['ok' => false, 'message' => 'Hotel booking is temporarily unavailable.'];
234	        }
235	        $stayId = (int) ($sel['stay_id'] ?? 0);
236	        $roomId = (int) ($sel['room_id'] ?? 0);
237	        $optId  = (int) ($sel['option_id'] ?? 0);
238	        $qty    = \max(1, (int) ($sel['qty'] ?? 1));
239	        if ($stayId <= 0 || $roomId <= 0 || $optId <= 0) {
240	            return ['ok' => false, 'message' => 'Please pick a room first.'];
241	        }
242	        $checkin  = \trim($checkin);
243	        $checkout = \trim($checkout);
244	        if ($checkin === '' || \strtotime($checkin) === false
245	            || $checkout === '' || \strtotime($checkout) === false
246	            || \strtotime($checkout) <= \strtotime($checkin)) {
247	            return ['ok' => false, 'need_dates' => true, 'message' => 'I need valid check-in and check-out dates to book.'];
248	        }
249	
250	        $selectedRooms = [['room_id' => $roomId, 'option_id' => $optId, 'quantity' => $qty]];
251	
252	        // 1) Authoritative price (server truth — GIA never trusts a model price).
253	        try {
254	            $price = \stays_recompute_net($db, $stayId, $selectedRooms, $checkin, $checkout, '');
255	        } catch (\Throwable $e) {
256	            \error_log('[gia_stays] reprice: ' . $e->getMessage());
257	            return ['ok' => false, 'message' => 'Could not price that room. Please try again.'];
258	        }
259	        if (empty($price['ok']) || (float) ($price['net'] ?? 0) <= 0) {
260	            return ['ok' => false, 'message' => 'That room is no longer available for those dates. Please pick another.'];
261	        }
262	        $amount   = \round((float) $price['net'], 2);
263	        $currency = \strtoupper((string) ($price['hotel_currency'] ?? ''));
264	
265	        // 2) Invoice id (AITP convention, same as the web stays cart booking).
```

### S4/S5 — Pay + settle (consume the hold)
**File:** `gia_booking_payment.php` — `gia_bp_confirm()` stays branch calls `stays_on_paid` (consumes the hold + accrues):
```php
456	                    // supplier earning via the SAME hook the web callback uses. The GIA
457	                    // Paystack path otherwise never calls stays_on_paid. Paying confirms
458	                    // a stay (no ticket to issue).
459	                    try {
460	                        if (!function_exists('stays_on_paid')) {
461	                            $slib = dirname(__DIR__) . '/stays_inventory.php';
462	                            if (is_file($slib)) { require_once $slib; }
463	                        }
464	                        if (function_exists('stays_on_paid')) { stays_on_paid($db, $invoiceId); }
465	                        $db->update('bookings', ['booking_status' => 'confirmed'], ['invoice_id' => $invoiceId]);
466	                    } catch (\Throwable $e) {
467	                        error_log('[gia_bp] stays settle ' . $invoiceId . ': ' . $e->getMessage());
```

---
## HONEST STATUS PER STEP

| Service | Step | Proven live? |
|---|---|---|
| Umrah | U1 search | **YES** — your Kano Dec departures showed on WhatsApp |
| Umrah | U2 tiers / U3 book / U4 passports / U5 pay / U6 settle | NO — code + logic-tests only |
| Flights | F1 search … F6 issue | NO — code + logic-tests only; the 'no fares' you saw was the OLD generic path, not this |
| Stays | S1 search … S5 settle | NO — code + logic-tests only |

**No full booking (search->pay->confirm) has run end-to-end on any GIA chat.** The website working is NOT evidence — the website is not GIA.

## The files (so you can confirm I am on the RIGHT ones)
- Umrah: `app/lib/ai/gia_umrah.php`, `app/lib/ai/tools/umrahTools.php`, `app/lib/ai/gia_pt_web.php`, `app/lib/ai/gia_pending_travellers.php`
- Flights: `app/lib/ai/gia_flights.php`, `app/lib/ai/tools/flightsTools.php`
- Stays: `app/lib/ai/gia_stays.php`, `app/lib/ai/tools/staysTools.php`
- Payment (all 3): `app/lib/ai/gia_booking_payment.php`
- Wiring: `app/routes/ai/giaRoutes.php` (web), `app/lib/ai/gia_channel.php` (WhatsApp/TG/Messenger), `app/lib/ai/giaAgent.php` (prompt + tool loop)
- Shared engines CALLED read-only (never edited): `app/lib/umrah/services.php`, `app/lib/stays_inventory.php`, `modules/flights/<supplier>/*`

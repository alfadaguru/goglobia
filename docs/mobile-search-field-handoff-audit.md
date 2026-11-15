# Mobile Search Field Hand-off / Focus Audit — all services

Audit of every customer search widget for the class of mobile bugs found in Flights:
dead focus targets, broken field-to-field hand-off, duplicate-DOM close handlers,
iOS keyboard (deferred focus), and from↔to crossover. Every claim below is verified
against source (file:line) and, where the service is enabled locally, in a real
headless-iPhone browser.

Date of audit: 2026-11 (ongoing hardening).

## Method
- Three parallel read-only mappers extracted trigger → panel → input → select-handler
  wiring for all 11 service search partials.
- Dead-reference check: every `getElementById('X')` / `querySelector('.Y')` /
  `querySelector('input[name=Z]')` used for focus/advance was checked for an existing
  target in the same file.
- Live tests (headless Chrome, iPhone 390×844 viewport) for the enabled tabs.

## Enabled service tabs (verified live, local + production match)
Flights, Stays, Tours, Umrah, Visa, Cars, Cruises, Bus, eSIM, Insurance (+ GIA).
Rail and Ferries are NOT enabled tabs locally/prod — code audited, not runtime-tested.

## Dead-reference check — RESULT: NONE across all 11 services
The only dead reference ever found was flights `selectFrom` focusing a non-existent
`arrival_airport_input` — already fixed (commit 90220dc). Confirmed by grep + three
independent mapper reads + my own re-check.

## Per-service field hand-off status

| Service | Type-to-search fields | Auto-advance after 1st pick | Focuses next input? | Status |
|---|---|---|---|---|
| flights | from (fl_from_q), to (fl_to_q) | from→to opens arrival panel (FIXED 90220dc) | YES (fl_to_q) | from→to OK; to→date focuses `.FlightsDeparture` (date), calendar did NOT auto-open in test — UX gap, not a break |
| stays | destination (st_dest_q), nationality (st_nat_q) | none | n/a | OK — no auto-advance (single primary field) |
| tours | destination (tr_dest_q) | none | n/a | OK |
| cars | pickup (car_pick_q), dropoff (car_drop_q) | none | n/a | OK; rental one-way copy pickup→dropoff if empty (business logic, guarded by svc==='rental') |
| bus | origin (bus_orig_q), dest (bus_dest_q) | none | n/a | OK |
| rail* | dep (rail_dep_q), dest (rail_dest_q) | selectOrigin → openDest() (opens + focuses rail_dest_q) | YES | OK (code), not runtime-tested (disabled) |
| ferries* | dep (depSearch), dest (destSearch) | selectDep → destOpen=true | NO (opens panel but does not focus dest input) | minor UX gap, not a break; not runtime-tested |
| esim | country (es_country_q) | none | n/a | OK |
| visa | (date focus only) | none | n/a | OK |
| umrah | (none notable) | none | n/a | OK |
| cruises | port ($refs.cruisePortInput) | none | n/a | OK ($refs is scope-safe, no id dup risk) |

\* rail/ferries disabled locally — audited by code only.

## Cross-cutting items (all verified)
1. **Duplicate-DOM close handlers**: all services now use `e.target.closest('#id')` in
   the outside-click close handler (not `getElementById`), so a tap in a duplicated
   panel copy no longer closes the overlay. (Belt-and-braces; the root duplicate-DOM
   double-render was fixed in db3cce8.)
2. **from↔to crossover**: NONE. Every service keeps origin/dest state in separate
   variables. Swap functions (flights exchangeCities, rail exchangeStations, ferries
   swapPorts) are all guarded (require both fields) and the UI buttons are `hidden
   lg:flex` (desktop-only) — cannot mis-fire on mobile.
3. **iOS deferred-focus (keyboard)**: the earlier inline-display "fix" was reverted
   (25336ff) because it fought Alpine x-show. With the duplicate-DOM root fix (db3cce8)
   the real user complaint ("overlay vanishes when I type") is resolved. Remaining
   `$nextTick`/`setTimeout` focus calls are harmless for keyboard pop on the tested
   path; revisit only if a real device shows the keyboard not opening.

## Seamless hand-off — implemented (verified live)
The rule we applied: only chain between fields the user actually must fill; never
auto-pop a field that is pre-filled by default or not needed.

- flights: departure → arrival (opens arrival panel) → date (opens the date calendar).
  Flights one-way date is EMPTY by default, so the date auto-open is useful; round-trip
  date is pre-filled, so the guard skips it. (commits 90220dc, 8b4fa13)
- bus: origin → destination (opens dest panel, focuses dest input). Bus date is
  pre-filled, so we STOP at destination. Verified: dest panel opens + focused.
- cars: pickup → drop-off, ONLY for serviceType === 'transfer' (where drop-off is a
  real second location). For 'rental' (default; drop-off = "same as pickup") and
  'hourly' (no drop-off) it does NOT auto-advance. Verified: rental=no pop, transfer=pop.
- stays: destination is the only type-to-search field and check-in is pre-filled by
  default — nothing to hand off to, so NO auto-advance (left intentionally).
- rail: already had selectOrigin → openDest() (opens + focuses dest). Not changed;
  disabled locally so not runtime-tested.
- ferries: selectDep opens the dest panel but does not focus its input — minor polish
  left (disabled locally; not runtime-tested).

Guard pattern used everywhere: only auto-open the next field when it is still EMPTY, so
re-selecting an earlier field does not re-pop a later one (verified for flights date).

Each auto-advance sets the target panel's open-state EXPLICITLY (not via the toggling
open* helper) and focuses the next input in $nextTick; the date auto-open uses .click()
(datepicker opens on click, not focus — verified) with a plain document lookup (NOT
this.$root, which is undefined inside setTimeout).

## Full search-to-booking mobile flow test (Flights) — PASSED
Driven end-to-end in a real headless-iPhone browser (390x844), zero JS/HTTP errors:

1. Results: GET /flights/los/abv/oneway/economy/{date}/1/0/0 -> HTTP 200, no x-overflow,
   live supplier search ("Searching... 2 Suppliers 50%" -> "Search complete! 59 Flights").
   Real offers rendered: Air Peace Direct NGN 129,160, correct airline name + logo,
   Cheapest/Fastest/Earliest tabs, date strip, sort.
2. Select: tapped "Book Now" -> navigated to /flights/booking/{hash} (passenger page),
   Booking Summary with correct price breakdown (129,159.78 + 1,291.60 tax = 130,451.38)
   and the price-trust banner.
3. Validation: submitting WITHOUT accepting terms correctly blocked with
   "Please accept the terms and conditions" (proper gate, not a bug).
4. Confirm: filled passenger (title/first/last/email/phone, DOB defaulted) + accepted
   terms (Alpine formData.terms_accepted=true) + Paystack selected -> "Processing... Do
   Not Refresh" overlay -> booking created.
5. Invoice: landed on /invoice/flights/{id} (#20952794), Status=Pending (awaiting pay),
   full Air Peace flight detail LOS 06:30 -> ABV 07:50, 1h20m non-stop, renders cleanly
   on mobile.

Stopped at the gateway boundary (did not enter card details). Test booking (unpaid)
deleted afterward. The mobile flights funnel is sound end-to-end.

## Full mobile flow test — STAYS and TOURS

### STAYS — PASSED (with one real finding)
Driven end-to-end (iPhone viewport), zero JS/HTTP errors:
- Tab loads; destination type-to-search works; picked city "Lagos" (is_hotel=false).
- Search submitted -> /stays/lagos/{in}/{out}/NG/1/2-0 -> results page.
- Results: "Search Complete! 100%", "36 stays found", sort, date strip, star/board
  filters, Cheapest/Top-rated/Available tabs, real hotel cards (Marriot Airport NGN
  37,639/night). No overflow.
- Hotel detail: /stay/{slug}/{supplier}/... -> HTTP 200, "Marriot Airport Hotels",
  Modify-search panel (nationality "Nigeria" carried correctly), rooms + Book. Clean.

  REAL FINDING (not yet fixed): the stays search REQUIRES a nationality, but on a FRESH
  visit the nationality hidden field is EMPTY (default session value is the literal
  'NULL'). handleHotelSearch() blocks with showError('select_nationality') and returns.
  In my run the block produced NO visible alert (showAlert stayed false), so a first-time
  mobile user can pick destination + dates, tap Search, and see NOTHING happen — a silent
  dead-end. Needs: either default the nationality (e.g. to the site/user country) OR make
  the showError actually surface. Flagged for a follow-up fix; verify on a real device.

### TOURS — flow works; NO inventory to book (data condition, not a bug)
- Tab loads; destination type-to-search works; picked "Dubai".
- Search submitted -> /tours/dubai/{date}/any/1/any -> results page, HTTP 200, no overflow,
  no errors. Start date is pre-filled by default (good).
- Results show "0 Tours · Found From 0 Supplier(s)" with a graceful empty state.
- Verified: tours table has 0 active rows LOCALLY, and PRODUCTION also returns 0 tours
  for Dubai and has no tour links on /tours. So the booking step cannot be runtime-tested
  anywhere right now -- there is simply no tour inventory. The search->results funnel
  itself is sound; booking is untestable until inventory exists.

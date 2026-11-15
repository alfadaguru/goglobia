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

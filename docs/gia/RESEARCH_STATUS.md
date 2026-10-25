# GIA Dedicated Build — Deep Research Status

Owner order: build GIA COMPLETE + DEDICATED, service by service (service → provider → product),
all 10 bookable services, with the 2-option Paystack payment flow. Umrah first. No drift.

## 10 bookable services + providers (verified from modules/)
1. umrah    — LOCAL own inventory (umrah_departures v2)
2. stays    — LOCAL (hotels) + EXTERNAL (hotelbeds, ratehawk, agoda, stuba, hotelston, travelport, amadeus, wanderbeds, tbo-holidays, booking)
3. flights  — EXTERNAL only (amadeus, duffel, kiwi, mystifly, seeru, tbo, travelport, sabre, pkfare, + affiliate: kayak, googleflights, travelpayouts)
4. tours    — LOCAL (tours) + EXTERNAL (viator, tiqets, toursbms; viator_merchant disabled)
5. cars     — LOCAL (cars) + EXTERNAL rental (cartrawler[issue], discover_cars) + EXTERNAL transfer (mozio[issue], kiwitaxi)
6. visa     — (research pending)
7. esim     — EXTERNAL (airalo)
8. ferries  — EXTERNAL (kikoto)
9. rail     — (train)
10. bus     — LOCAL (bus)
11. insurance — (airhelp) research pending — may be affiliate/claims not a sellable product

## Research reports: 3 / 7 complete
- [x] UMRAH  — DONE (own inventory; product=departure_tier_id; list→quote→hold→book→settle; collects per-pilgrim passport/gender/dob/nationality/expiry + payment plan)
- [x] STAYS  — DONE (property→room type→rate option; local=room_options JSON, external=opaque rateKey; stays_list_room_options + stays_recompute_net + stays_hold_create)
- [x] TOURS+CARS — DONE (tours local, prices fixed at search, no revalidate; cars has service_type rental vs transfer; cartrawler/mozio have issue.php; local cars = routes[] JSON)
- [ ] FLIGHTS — running (+ critical: post-pay issue trigger? auto-refund?)
- [ ] VISA+ESIM+FERRIES+RAIL+BUS — running
- [ ] INSURANCE — running

## KEY LESSON confirmed by research
Earlier GIA umrah build SKIPPED: per-pilgrim passport/gender/DOB/nationality/expiry, payment-plan choice, traveller roster.
A COMPLETE chat booking per service must collect everything the web checkout collects. That is the whole point of the dedicated build.

## UPDATE: 4/7 reports in
- [x] FLIGHTS — DONE. External-only. **[CORRECTED after file verification]** SIX (not 7) providers have revalidate.php: amadeus, duffel, mystifly, seeru, tbo, travelport. (The earlier report wrongly listed amadeus_enterprise as revalidate-capable — it has issue.php but NO revalidate.php; verified by file check.) NO-revalidate: amadeus_enterprise, kiwi, pkfare, sabre, travelpayouts, googleflights (have issue.php); kayak + flights have neither.
  Product = fare offer w/ SHORT-LIVED supplier token (expires ~15-30min), cached in gia_offer_cache.
  *** CRITICAL GAPS (must build BEFORE flights can book in chat) ***
  1. NO post-payment ISSUE trigger — flights excluded from auto-issue (payment-gateway.php:601); gia_bp_confirm just marks paid (gia_booking_payment.php:376). Ticket never created.
  2. NO auto-refund if paid-but-not-issued -> customer charged, no ticket.
  3. Validate passenger data (name/DOB/passport) BEFORE checkout, not at issue.
  4. Re-revalidate token right before pay.
  5. Use issue_claimed_at as idempotency lock.
  => FLIGHTS is NOT safe for chat-booking until 1+2 built. Do flights LAST.

## Still running: 2/7
- [ ] VISA + ESIM + FERRIES + RAIL + BUS
- [ ] INSURANCE (airhelp)

## UPDATE: 7/7 reports DONE — all 10 services mapped
- [x] BUS — LOCAL (bus_routes). Product=route_id. Revalidate YES (re-query listing).
- [x] VISA — LOCAL (visa table, prices JSON). Product={visa_id}-{type}-{speed} OR inquiry. Revalidate YES. NOTE: can be inquiry-only (no instant price) -> needs quote path.
- [x] ESIM — EXTERNAL (airalo). Product=Airalo package_id. Revalidate YES (re-fetch Airalo). pax=1 per SIM.
- [x] RAIL — EXTERNAL (train; China/Laos/Indonesia regional). Product={traffic_no,from,to,seat_class,date}. Revalidate YES.
- [x] FERRIES — EXTERNAL (kikoto). Product={sailing,accommodation,pax,vehicles,pets}. Revalidate YES.
- [x] INSURANCE (airhelp) — NOT a bookable product. Post-flight compensation CLAIM (free, $0). NOT provisioned (no creds). NOT in GIA. => GIA should EXCLUDE or deep-link only, NEVER "book".

## FINAL SERVICE CLASSIFICATION for GIA build
LOCAL inventory (own DB, instant price, book directly): UMRAH, STAYS(local hotels), TOURS(local), CARS(local routes), BUS, VISA
EXTERNAL live-supplier (API, token/offer, revalidate before book): STAYS(hotelbeds/ratehawk/agoda/...), FLIGHTS, TOURS(viator/tiqets/toursbms), CARS(cartrawler/mozio/kiwitaxi/discover_cars), ESIM(airalo), RAIL(train), FERRIES(kikoto)
NOT bookable in GIA: INSURANCE (claim handoff / exclude)

## BUILD READINESS per service (owner order: umrah->stays->flights->rest)
- UMRAH: service layer complete + reusable (quote/hold/book/settle). READY to build dedicated flow. Must collect per-pilgrim passport/gender/dob/nationality/expiry + payment plan.
- STAYS: local ready (stays_list_room_options built). External = needs per-supplier rate re-check. 
- FLIGHTS: BLOCKED until post-pay issue-trigger + auto-refund built (critical gaps). Do LAST or after fixing.
- TOURS/CARS/BUS/VISA: local = straightforward. External tours NOT yet in GIA. Cars transfer needs flight-no for mozio.
- ESIM/RAIL/FERRIES: external, fetch_live_offers + revalidate already exist; dedicated flow wraps them.
- INSURANCE: exclude / deep-link.

ALL payment via the GIA payment engine (per-booking Paystack DVA + checkout + 20min expiry + 5 reminders + auto-cancel + auto-refund) — already built.

## ===== VERIFICATION PASS (I re-checked every factual claim against the real code, not the agents) =====
Owner ordered a re-verify so nothing is falsely reported. Results:

CORRECTED (false claim found + fixed):
- FLIGHTS revalidate count was WRONG: said 7 incl. amadeus_enterprise. TRUTH = 6 (amadeus, duffel, mystifly, seeru, tbo, travelport). amadeus_enterprise has NO revalidate.php. FIXED above.

CLARIFIED (was ambiguous, now exact):
- cars issue.php is at modules/cars/<p>/issue.php (FLAT), NOT modules/cars/<p>/actions/. flights issue.php IS at modules/flights/<p>/actions/issue.php. Different layout per service. cartrawler + mozio issue.php DO exist (claim was TRUE).

VERIFIED TRUE (checked against source):
- Flights excluded from auto-issue: payment-gateway.php:601 `!in_array(strtolower($module),['flight','visas'],true)` — CONFIRMED.
- gia_bp_confirm non-umrah just marks paid: gia_booking_payment.php:379 (comment :376 "stays/flights phases extend this") — CONFIRMED; no issue/refund for non-umrah.
- All 14 build-critical DB tables EXIST (umrah_departures/departure_tiers/bookings/quotes/inventory_holds/payment_plans/booking_travellers, stays_rooms/holds/rooms_calendar, bus_routes, visa, gia_booking_payments, gia_offer_cache).
- All 10 service fns EXIST: umrahV2PublishedDepartures, umrah_price_quote/hold_create/booking_create/settle_payment/payment_schedule (app/lib/umrah/services.php); stays_list_room_options/recompute_net/hold_create/room_option_ids (app/lib/stays_inventory.php).
- aiSearchService has a build fn for EVERY service (buildUmrah/Stay/Flight/Tour/Car/Bus/Visa/Esim/Rail/Ferry Search).
- Provider dirs match: stays(agoda,amadeus,booking,hotelbeds,hotels,hotelston,ratehawk,stuba,tbo-holidays,travelport,wanderbeds), tours(tours,viator,tiqets,toursbms,viator_merchant), cars(cars,cartrawler,discover_cars,kiwitaxi,mozio), esim(airalo), rail(train), ferries(kikoto), bus(bus).
- viator_merchant disabled: modules row active='0' — CONFIRMED.
- tours: NO revalidate.php for any provider — CONFIRMED (prices fixed at search).
- visa: NO modules/visa dir; handled via app/routes/api/visa/ + visaListingRoutes.php — CONFIRMED.
- insurance(airhelp): free compensation CLAIM not a policy; ABSENT from aiSearchService::allKnownModuleTypes() — CONFIRMED NOT in GIA.

NEW NOTE (found during verify, not in original reports):
- allKnownModuleTypes() ALSO lists 'cruises' — but there is NO modules/cruises dir. It's a declared-but-unbuilt module type. Not one of the 10 buildable services; ignore for GIA build unless owner says otherwise.

Everything else on this file verified accurate against the source as of this pass.

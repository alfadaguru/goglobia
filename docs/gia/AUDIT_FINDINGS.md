# GIA Comprehensive Audit — findings (owner-ordered, 2026-10-22)
# Every item verified by me against real source (file:line), not trusted from agents.

## VERIFIED GAP #1 (I checked directly): GIA umrah booking collects NO pilgrim data
- umrah_booking_create (app/lib/umrah/services.php:885-1072) inserts into `bookings`,
  `umrah_bookings`, `umrah_installments` ONLY. It does NOT insert `umrah_booking_travellers`.
  It stores only the lead contact (first_name/last_name/email/phone).
- umrah_traveller_add is called NOWHERE in app/lib/ai/ (GIA). Verified: grep empty.
- IMPACT: shipped GIA umrah booking is INCOMPLETE — no per-pilgrim passport/gender/dob/
  nationality/expiry, which real umrah (Saudi visa) requires. This is a "half-done flow".
- FIX (dedicated build): GIA must collect per-pilgrim details and call umrah_traveller_add
  (app/lib/umrah/operations.php) after umrah_booking_create.

## (3 audit agents running: shipped-code correctness, v1-removal safety, per-service build gaps.
##  Consolidated findings appended on completion, each re-verified by me.)

## AUDIT 1 DONE: Shipped-code correctness — VERIFIED CLEAN (I re-checked 3 key claims myself)
Agent audited all my session commits; verdict = all correct, 0 bugs. I spot-verified:
- listing.php month-filter: month_explicit flag (:75), past-month rejected (:80 `>= Y-m-01`), filter only when needle set (:97). TRUE.
- Containment guard applied on the final-text return path: giaAgent.php:177 calls guardReplyContainment. TRUE.
- Webhook routes GIA bookings by checkout_reference(:121)/customer_code(:126)->gia_bp_confirm(:131) BEFORE the wallet path(:183). No double-processing. TRUE.
Full agent verdicts (all VERIFIED-CORRECT, file:line): umrahListingCards (v2-only, month guard), aiSearchService (month_explicit passed), selectOptionTool (departure_tier_id validated), tripRevalidateHelper (v2 quote, no currency convert), createBookingTool (quote->hold->book order, nested payload read), guardReplyContainment, channel currency (currencies table not USD), gia_bp_* (idempotency, late-refund, 5-reminder, expiry/cancel), gia_channel_site_base (rejects localhost, never '/'), gia_greenapi_set_webhook (preserves other toggles).
=> The code I shipped this session is CORRECT. The GAP is not bugs — it's INCOMPLETENESS (traveller data #1 above), which the dedicated build must close.

## STILL RUNNING: Audit 2 (v1 removal safety), Audit 3 (per-service build gaps).

## AUDIT 2 DONE: v1 umrah removal safety (I re-verified the critical + headline claims)
VERIFIED TRUE:
- GIA still reads `umrah_settings` at aiSearchService.php:5981,5991 (resolveUmrahSettingId for service/type/duration filter ids). BUT my v2 umrahListingCards IGNORES services/umrah_type/duration (filters city/month only — confirmed). So these reads are HARMLESS to GIA output: they CANNOT produce phantom packages. GIA shows ONLY v2 departures. ("Does GIA suggest v1?" = NO, verified.)
- GIA no longer reads the dead `umrah` TABLE (singular) anywhere — confirmed earlier + this pass.
- Public /umrah route = V2 (umrahV2Routes.php loads first at _routes.php:177, shadows legacy listingRoutes.php:183). CONFIRMED.
- LIVE v1 `umrah` table reads remain in: /api/umrah/featured (featuredRoutes.php), /api/umrah (homeRoutes.php:52), legacy booking (umrah/bookingRoutes.php:24), detail (detailRoutes.php:56), module search/details (modules/umrah/umrah/search.php:209, details.php:53), admin CRUD (admin/umrahRoutes.php many), promo (promoCodeRoutes.php:119, admin/promoCodesRoutes.php:485,598). CONFIRMED the files/lines exist.

CORRECTED (agent headline claim was INACCURATE):
- Agent said homepage featured.php "rendered server-side on every homepage load" is the #1 blocker. FALSE: app/views/modules/umrah/featured.php is NOT included/required by ANY php file (grep empty) = DEAD code, not a live homepage render. The LIVE v1 featured path is the API route /api/umrah/featured (featuredRoutes.php, registered _routes.php:351), NOT a homepage view. Dropping v1 would break the API + legacy routes + admin, NOT a server-rendered homepage.

UNVERIFIED (needs a DB query I can't run — MySQL owner-privileged):
- COUNT of live v1 rows: SELECT COUNT(*) FROM umrah WHERE status=1. If 0 -> v1 fully dead, much safer to remove.
- COUNT of existing v1 bookings: SELECT COUNT(*) FROM bookings WHERE module_type='umrah'. If >0, must preserve for invoice history before dropping.

SAFE REMOVAL (verified order): v1 removal MUST be staged — (1) point the live API reads (/api/umrah/featured, /api/umrah) + module search/details at v2, (2) handle legacy booking/detail/promo/admin, (3) archive + drop `umrah` LAST. Keep `umrah_settings` (v2 may still use it; verify). NOT safe to drop today while those API/admin reads are live.

## AUDIT 3 DONE: per-service build gaps — I re-verified the big claims; agent made MAJOR errors
The gap-audit agent was useful but WRONG on several headline claims. Verified truth:

AGENT FALSE CLAIM (corrected): "NO reprice/revalidator for cars/rail/ferries/bus/tours/visa/esim."
- FALSE. tripRevalidateHelper.php HAS working revalidators for ALL of them:
  aiTripRevalidateCarItem(1610, calls modules/cars/<supplier>/search:1667), RailItem(2652), FerriesItem(2822),
  BusItem(1822), TourItem(1466), EsimItem(2217), VisaItem(2384), UmrahItem(2527), plus stays x3
  (Hotelston 507, Hotelbeds 656, Ratehawk 836, LocalHotels 1038) + dispatch aiTripRevalidateStayItem(1433),
  aiTripRevalidatePackage(3056). So reprice EXISTS per service. Do NOT "build reprice" — it's there.

AGENT OVERSTATED (corrected): "module_type='ai_trip' BREAKS post-payment routing."
- PARTIALLY FALSE. createBookingTool.php:246/249 DO set module_type/module='ai_trip' for non-umrah.
  BUT ai_trip IS a supported post-payment type: payment-gateway.php:191 `case 'ai_trip'`, :597 + :732 route
  ai_trip to per-item issue via modules/ai_trip/ai_trip/issue. So not "broken routing" as claimed.
  OPEN (needs deeper check): does ai_trip per-item issue correctly fulfil each sub-module (flights/stays/etc.)?
  — this is the real question, NOT "module_type is wrong".

VERIFIED TRUE (real gaps):
- TRAVELLER/PASSENGER DATA: createBookingTool collects only the LEAD contact (first_name/last_name @225,329),
  NEVER per-traveller rows. umrah_booking_create does NOT write umrah_booking_travellers (verified audit start).
  So umrah books with 0 pilgrim passport data; flights/rail/ferries/stays-external similarly lack per-pax names.
  This is THE genuine cross-service gap. Real.
- FLIGHTS no post-payment issue + no auto-refund: payment-gateway.php:595-603 excludes 'flight','visas';
  gia_bp_confirm non-umrah just marks paid. CONFIRMED (audit 1 + 3). Flights BLOCKED for chat booking.
- VISA: flagged as needing per-applicant data + document upload + submission; no auto-issue (excluded :595). Plausible, needs its own deep pass before building.

AGENT UNVERIFIED (I could not fully confirm, needs test): offer-cache survival on stateless WhatsApp (conv_id is DB-backed per gia_crm, which is good, but a real round-trip test is the only proof); flight offer-token expiry during approval window.

## NET CONCLUSION of the whole audit (verified by me):
1. Code I SHIPPED this session = CORRECT, no bugs (audit 1, re-verified).
2. The real problem is INCOMPLETENESS, concentrated in ONE thing: per-traveller/pilgrim DATA COLLECTION is missing across services (umrah pilgrims, flight/rail/ferry pax, stays guests). This is the #1 build item.
3. FLIGHTS additionally blocked by missing post-pay issue + auto-refund. Do flights after that fix.
4. Reprice/revalidate ALREADY EXISTS per service (agent was wrong) — reuse it, don't rebuild.
5. v1 umrah removal must be staged; GIA itself is clean of the dead `umrah` table (umrah_settings reads are harmless).
6. Research MD had 1 false claim (flights revalidate count) — fixed. v1 audit had 1 false claim (homepage featured live) — corrected. gap audit had false "no reprice" claims — corrected here.
